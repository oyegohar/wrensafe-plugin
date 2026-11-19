<?php
/**
 * S3-compatible object storage (Amazon S3, Backblaze B2, Wasabi,
 * DigitalOcean Spaces, Cloudflare R2, MinIO, …) over its REST API, signed
 * with AWS Signature Version 4 and sent with the WordPress HTTP API.
 *
 * Backups of 5 MB and more use a multipart upload: 8 MB parts, with the
 * upload id and each part's ETag kept in the upload state, so it continues
 * on the next request and is completed when every part is there.
 *
 * @package WPBP\Remote
 */

namespace WPBP\Remote;

use WPBP\Utils\Str;

defined( 'ABSPATH' ) || exit;

// Backup archives are read part by part with native file handles
// (fopen/fseek/fread): WP_Filesystem has no streaming API.
// phpcs:disable WordPress.WP.AlternativeFunctions

final class S3Target implements RemoteTarget {

	private const PART         = 8388608;        // 8 MB parts (S3 allows 10,000 parts: 80 GB).
	private const SINGLE_LIMIT = 5242880; // Below 5 MB: one PUT.

	/** @var array<string,mixed> */
	private array $config;

	/**
	 * @param array<string,mixed> $config endpoint, region, bucket, access_key, secret_key, folder, path_style.
	 */
	public function __construct( array $config ) {
		$this->config = $config;
	}

	public static function unavailable_reason(): string {
		return function_exists( 'simplexml_load_string' ) ? '' : __( 'This server’s PHP can’t read XML (the SimpleXML extension), which S3 storage needs.', 'wrensafe' );
	}

	private function key( string $name ): string {
		$folder = trim( str_replace( '\\', '/', (string) ( $this->config['folder'] ?? '' ) ), '/' );

		return '' === $folder ? $name : $folder . '/' . $name;
	}

	/**
	 * Send a signed request. $key '' addresses the bucket itself.
	 *
	 * @param array<string,string> $query
	 * @return array{code:int,body:string,headers:array}
	 */
	private function request( string $method, string $key, array $query = array(), string $body = '' ): array {
		$endpoint = trim( (string) ( $this->config['endpoint'] ?? '' ) );

		if ( '' === $endpoint ) {
			throw new \RuntimeException( esc_html__( 'Enter the storage service’s endpoint (its S3 address).', 'wrensafe' ) );
		}

		$endpoint = preg_match( '#^https?://#i', $endpoint ) ? $endpoint : 'https://' . $endpoint;
		$parts    = wp_parse_url( $endpoint );
		$scheme   = strtolower( (string) ( $parts['scheme'] ?? 'https' ) );
		$host     = (string) ( $parts['host'] ?? '' );
		$host    .= isset( $parts['port'] ) ? ':' . (int) $parts['port'] : '';
		$bucket   = (string) ( $this->config['bucket'] ?? '' );
		$region   = (string) ( $this->config['region'] ?? '' );
		$region   = '' !== $region ? $region : 'us-east-1';

		$encoded_key = implode( '/', array_map( 'rawurlencode', explode( '/', $key ) ) );

		if ( ! empty( $this->config['path_style'] ) ) {
			$path = '/' . rawurlencode( $bucket ) . ( '' !== $key ? '/' . $encoded_key : '/' );
		} else {
			$host = $bucket . '.' . $host;
			$path = '/' . $encoded_key;
		}

		$amz_date     = gmdate( 'Ymd\THis\Z' );
		$date         = gmdate( 'Ymd' );
		$payload_hash = hash( 'sha256', $body );

		ksort( $query );
		// RFC 3986 encoding; a key without a value ("uploads") becomes "uploads=", as SigV4 expects.
		$canonical_query = str_replace( '%7E', '~', http_build_query( $query, '', '&', PHP_QUERY_RFC3986 ) );

		$headers           = "host:{$host}\nx-amz-content-sha256:{$payload_hash}\nx-amz-date:{$amz_date}\n";
		$signed_headers    = 'host;x-amz-content-sha256;x-amz-date';
		$canonical_request = implode( "\n", array( $method, $path, $canonical_query, $headers, $signed_headers, $payload_hash ) );
		$scope             = "{$date}/{$region}/s3/aws4_request";
		$string_to_sign    = implode( "\n", array( 'AWS4-HMAC-SHA256', $amz_date, $scope, hash( 'sha256', $canonical_request ) ) );
		$signing_key       = hash_hmac( 'sha256', 'aws4_request', hash_hmac( 'sha256', 's3', hash_hmac( 'sha256', $region, hash_hmac( 'sha256', $date, 'AWS4' . (string) ( $this->config['secret_key'] ?? '' ), true ), true ), true ), true );
		$signature         = hash_hmac( 'sha256', $string_to_sign, $signing_key );

		$url = $scheme . '://' . $host . $path . ( '' !== $canonical_query ? '?' . $canonical_query : '' );

		$response = wp_remote_request(
			$url,
			array(
				'method'  => $method,
				'timeout' => 120,
				'headers' => array(
					'x-amz-date'           => $amz_date,
					'x-amz-content-sha256' => $payload_hash,
					'Authorization'        => 'AWS4-HMAC-SHA256 Credential=' . (string) ( $this->config['access_key'] ?? '' ) . "/{$scope}, SignedHeaders={$signed_headers}, Signature={$signature}",
					'Content-Type'         => 'application/octet-stream',
				),
				'body'    => '' !== $body ? $body : null,
			)
		);

		if ( is_wp_error( $response ) ) {
			/* translators: %s: error message */
			throw new \RuntimeException( esc_html( sprintf( __( 'Couldn’t reach the storage service: %s', 'wrensafe' ), $response->get_error_message() ) ) );
		}

		// A CaseInsensitiveDictionary, not an array: (array) would expose its
		// internals instead of the headers.
		$headers = wp_remote_retrieve_headers( $response );
		$headers = is_object( $headers ) && method_exists( $headers, 'getAll' ) ? $headers->getAll() : (array) $headers;

		return array(
			'code'    => (int) wp_remote_retrieve_response_code( $response ),
			'body'    => (string) wp_remote_retrieve_body( $response ),
			'headers' => array_change_key_case( $headers, CASE_LOWER ),
		);
	}

	/**
	 * Throw a readable error for a failed response (S3 can also answer 200
	 * with an <Error> body).
	 *
	 * @param array{code:int,body:string} $response
	 */
	private function check( array $response, string $what ): \SimpleXMLElement {
		$xml = '' !== $response['body'] ? @simplexml_load_string( $response['body'] ) : false; // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- an empty or non-XML body is handled below.

		if ( $response['code'] >= 300 || ( $xml && 'Error' === $xml->getName() ) ) {
			$code    = $xml ? (string) $xml->Code : '';    // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- S3 XML element.
			$message = $xml ? (string) $xml->Message : ''; // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- S3 XML element.
			$hints   = array(
				'SignatureDoesNotMatch'        => __( 'The secret key is wrong, or the region doesn’t match the bucket.', 'wrensafe' ),
				'InvalidAccessKeyId'           => __( 'The access key isn’t known to this storage service.', 'wrensafe' ),
				'NoSuchBucket'                 => __( 'The bucket doesn’t exist at this endpoint.', 'wrensafe' ),
				'AccessDenied'                 => __( 'The key isn’t allowed to do this in the bucket.', 'wrensafe' ),
				'AuthorizationHeaderMalformed' => __( 'The region doesn’t match the bucket.', 'wrensafe' ),
				'PermanentRedirect'            => __( 'The bucket is in another region or needs another endpoint.', 'wrensafe' ),
			);
			$detail  = $hints[ $code ] ?? ( '' !== $message ? $message : sprintf( 'HTTP %d', $response['code'] ) );

			/* translators: 1: what was being done, 2: reason */
			throw new \RuntimeException( esc_html( sprintf( __( '%1$s failed: %2$s', 'wrensafe' ), $what, $detail ) ) );
		}

		return $xml ? $xml : new \SimpleXMLElement( '<ok/>' );
	}

	public function test(): array {
		try {
			$key = $this->key( '.wrensafe-test-' . wp_generate_password( 8, false ) . '.txt' );
			$this->check( $this->request( 'PUT', $key, array(), 'Wrensafe connection test' ), __( 'Writing a test file', 'wrensafe' ) );
			$this->request( 'DELETE', $key );

			return array(
				'ok'      => true,
				'message' => __( 'Connected. A test file was written to the bucket and removed.', 'wrensafe' ),
			);
		} catch ( \RuntimeException $e ) {
			return array(
				'ok'      => false,
				'message' => RemoteStorage::error_text( $e ),
			);
		}
	}

	public function begin( string $remote_name, int $size ): array {
		$state = array(
			'name'      => $remote_name,
			'size'      => $size,
			'offset'    => 0,
			'upload_id' => '',
			'parts'     => array(),
		);

		if ( $size >= self::SINGLE_LIMIT ) {
			$xml                = $this->check( $this->request( 'POST', $this->key( $remote_name ), array( 'uploads' => '' ) ), __( 'Starting the upload', 'wrensafe' ) );
			$state['upload_id'] = (string) $xml->UploadId; // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- S3 XML element.

			if ( '' === $state['upload_id'] ) {
				throw new \RuntimeException( esc_html__( 'The storage service didn’t start the upload.', 'wrensafe' ) );
			}
		}

		return $state;
	}

	public function send( string $local_path, array &$state, float $deadline ): bool {
		$size = (int) $state['size'];
		$in   = fopen( $local_path, 'rb' );

		if ( false === $in ) {
			throw new \RuntimeException( esc_html__( 'The backup file couldn’t be read.', 'wrensafe' ) );
		}

		if ( '' === (string) $state['upload_id'] ) {
			$body = $size > 0 ? (string) fread( $in, $size ) : '';
			fclose( $in );
			$this->check( $this->request( 'PUT', $this->key( $state['name'] ), array(), $body ), __( 'Uploading the backup', 'wrensafe' ) );
			$state['offset'] = $size;

			return true;
		}

		fseek( $in, (int) $state['offset'] );

		while ( $state['offset'] < $size ) {
			$number   = count( $state['parts'] ) + 1;
			$body     = (string) fread( $in, self::PART );
			$response = $this->request(
				'PUT',
				$this->key( $state['name'] ),
				array(
					'partNumber' => (string) $number,
					'uploadId'   => (string) $state['upload_id'],
				),
				$body
			);

			/* translators: %d: part number */
			$this->check( $response, sprintf( __( 'Uploading part %d', 'wrensafe' ), $number ) );

			$etag = $response['headers']['etag'] ?? '';
			$etag = trim( is_array( $etag ) ? (string) reset( $etag ) : (string) $etag, '"' );

			if ( '' === $etag ) {
				/* translators: %d: part number */
				throw new \RuntimeException( esc_html( sprintf( __( 'The storage service didn’t confirm part %d of the upload.', 'wrensafe' ), $number ) ) );
			}

			$state['parts'][] = $etag;
			$state['offset'] += strlen( $body );

			if ( microtime( true ) >= $deadline ) {
				break;
			}
		}

		fclose( $in );

		return $state['offset'] >= $size;
	}

	public function finish( array &$state ): void {
		if ( '' === (string) $state['upload_id'] ) {
			return;
		}

		$xml = '<CompleteMultipartUpload>';
		foreach ( $state['parts'] as $i => $etag ) {
			$xml .= '<Part><PartNumber>' . ( (int) $i + 1 ) . '</PartNumber><ETag>"' . htmlspecialchars( (string) $etag, ENT_XML1 ) . '"</ETag></Part>';
		}
		$xml .= '</CompleteMultipartUpload>';

		$this->check( $this->request( 'POST', $this->key( $state['name'] ), array( 'uploadId' => (string) $state['upload_id'] ), $xml ), __( 'Completing the upload', 'wrensafe' ) );
	}

	public function abort( array $state ): void {
		if ( '' === (string) ( $state['upload_id'] ?? '' ) ) {
			return;
		}

		try {
			$this->request( 'DELETE', $this->key( (string) $state['name'] ), array( 'uploadId' => (string) $state['upload_id'] ) );
		} catch ( \RuntimeException $e ) {
			unset( $e ); // Best effort.
		}
	}

	public function list_backups(): array {
		$prefix = $this->key( '' );
		$items  = array();
		$token  = '';

		for ( $page = 0; $page < 20; $page++ ) {
			$query = array(
				'list-type' => '2',
				'prefix'    => $prefix,
			);

			if ( '' !== $token ) {
				$query['continuation-token'] = $token;
			}

			$xml = $this->check( $this->request( 'GET', '', $query ), __( 'Listing the backups', 'wrensafe' ) );

			foreach ( $xml->Contents as $object ) { // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- S3 XML element.
				$key  = (string) $object->Key;  // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- S3 XML element.
				$name = substr( $key, strlen( $prefix ) );

				if ( '' === $name || false !== strpos( $name, '/' ) || ! Str::ends_with( $name, '.wbp' ) ) {
					continue;
				}

				$items[] = array(
					'name' => $name,
					'time' => (int) strtotime( (string) $object->LastModified ), // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- S3 XML element.
					'size' => (int) $object->Size, // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- S3 XML element.
				);
			}

			$token = (string) $xml->NextContinuationToken; // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- S3 XML element.

			if ( 'true' !== (string) $xml->IsTruncated || '' === $token ) { // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- S3 XML element.
				break;
			}
		}

		return RemoteStorage::newest_first( $items );
	}

	public function delete( string $name ): bool {
		try {
			$this->check( $this->request( 'DELETE', $this->key( $name ) ), __( 'Deleting an old copy', 'wrensafe' ) );

			return true;
		} catch ( \RuntimeException $e ) {
			return false;
		}
	}
}

// phpcs:enable WordPress.WP.AlternativeFunctions
