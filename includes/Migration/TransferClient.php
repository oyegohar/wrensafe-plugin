<?php
/**
 * Source-side driver that pushes a local backup to a destination site's
 * migration REST API, one bounded chunk per call (timeout-safe, mirrors
 * BackupManager/RestoreManager's step pattern).
 *
 * Before a transfer, preflight() asks the receiving site whether the key is
 * valid and whether it accepts this connection (HTTP or HTTPS), so problems
 * are explained before anything is sent. During the transfer, a "slow down"
 * answer (429) or a passing connection problem pauses and continues instead
 * of failing, and starting again with the same key continues where the
 * last attempt stopped.
 *
 * Chunks go as the raw request body (application/octet-stream) to a
 * receiving site that lists "raw" in its chunk_formats (1.2.1+), and
 * base64-encoded in a form field, as before, to older ones (and when
 * something in front of the receiving site, such as a firewall, refuses
 * the raw body). The signature is the same HMAC over the chunk's bytes.
 *
 * Requests use wp_remote_post(), not wp_safe_remote_post(): the receiving
 * site is the administrator's own other site, which can legitimately be on
 * the local network or this server (a staging copy, a site moving between
 * two machines in one office), and wp_safe_remote_post() refuses such
 * addresses. Only users who may manage migrations start a transfer (nonce
 * and capability checked in MigrationAjax), redirects are not followed,
 * and nothing from the response is used but the receiving site's JSON
 * answer.
 *
 * @package WPBP\Migration
 */

namespace WPBP\Migration;

use WPBP\Core\Database\BackupsRepository;
use WPBP\Core\Settings;
use WPBP\Utils\Errors;
use WPBP\Utils\Logger;

defined( 'ABSPATH' ) || exit;

// Backup archives and upload chunks are streamed in bounded blocks with native
// file handles (fopen/fread/fwrite/rename): WP_Filesystem has no streaming API.
// Every path is inside Wrensafe's protected storage directory, a PHP upload
// temp file, or validated by WPBP\Security\PathValidator.
// phpcs:disable WordPress.WP.AlternativeFunctions

class TransferClient {

	const DEFAULT_CHUNK_SIZE = 1048576; // 1MB — used until the destination advertises its own ceiling.

	/** Connection problems in a row before the transfer stops. */
	private const MAX_RETRIES = 5;

	/** Longest wait the receiving site can ask for, in seconds. */
	private const MAX_WAIT = 120;

	public function process_step( MigrationTransferJob $job ): void {
		$payload = $job->payload();
		$phase   = $payload['phase'] ?? 'init';

		// Waiting before a retry: the browser asks again straight away, so
		// pause a little here instead of answering at once.
		$wait = (int) ( $payload['retry_at'] ?? 0 ) - time();

		if ( $wait > 0 ) {
			sleep( min( 2, $wait ) );
			return;
		}

		try {
			switch ( $phase ) {
				case 'init':
					$this->step_init( $job, $payload );
					break;

				case 'transfer':
					$this->step_transfer( $job, $payload );
					break;

				case 'finalize':
					$this->step_finalize( $job, $payload );
					break;

				default:
					$job->mark_failed( __( 'Unknown migration phase.', 'wrensafe' ) );
			}
		} catch ( \Throwable $e ) {
			$message = Errors::text( $e );
			Logger::error( 'Migration transfer #' . $job->id() . ' failed: ' . $message, array(), $job->id() );
			$job->mark_failed( $message );
		}
	}

	/**
	 * The destination's REST address. /wp-json/ needs pretty permalinks; a
	 * site on "Plain" permalinks answers ?rest_route= instead (the first
	 * request switches to that form when /wp-json/ returns 404).
	 */
	private function endpoint( array $payload, string $path ): string {
		$base  = untrailingslashit( (string) $payload['destination_url'] );
		$route = '/wpbp/v1/migration/' . $path;

		return 'plain' === ( $payload['rest_style'] ?? '' ) ? add_query_arg( 'rest_route', $route, $base . '/' ) : $base . '/wp-json' . $route;
	}

	private static function is_https( string $url ): bool {
		return 0 === strpos( strtolower( $url ), 'https://' );
	}

	/** Why this site won't send to an http:// address ('' when it will). */
	private static function own_https_rule( string $destination_url ): string {
		if ( ! Settings::get( 'migration_require_https', true ) || self::is_https( $destination_url ) ) {
			return '';
		}

		return sprintf(
			/* translators: 1: this site's address, 2: the destination address */
			__( 'This site (%1$s) sends direct migrations only over HTTPS, and %2$s is not an https:// address. For local or test sites, turn off “Require HTTPS” in Wrensafe → Settings → Direct Migration on this site, and on the receiving site too.', 'wrensafe' ),
			home_url(),
			untrailingslashit( $destination_url )
		);
	}

	/**
	 * A readable reason for a failed connection, with the usual cause of
	 * HTTPS failures between local sites.
	 */
	private static function connection_error( \WP_Error $error, string $destination_url ): string {
		$detail = $error->get_error_message();
		$site   = untrailingslashit( $destination_url );

		if ( preg_match( '/SSL|certificate|cURL error (35|51|60)\b/i', $detail ) ) {
			return sprintf(
				/* translators: 1: site address, 2: technical detail */
				__( 'Couldn’t make a secure (HTTPS) connection to %1$s: this server doesn’t trust its certificate. That’s normal for local sites with a self-made certificate. Use the site’s http:// address and turn off “Require HTTPS” in Wrensafe → Settings → Direct Migration on both sites, or give the site a trusted certificate. (%2$s)', 'wrensafe' ),
				$site,
				$detail
			);
		}

		if ( preg_match( '/resolve host|cURL error 6\b/i', $detail ) ) {
			/* translators: 1: site address, 2: technical detail */
			return sprintf( __( 'This server couldn’t find %1$s. Check the address, and that this server can reach that site. (%2$s)', 'wrensafe' ), $site, $detail );
		}

		/* translators: 1: site address, 2: technical detail */
		return sprintf( __( 'Couldn’t connect to %1$s. (%2$s)', 'wrensafe' ), $site, $detail );
	}

	/**
	 * The site address a redirect points to ('' when it isn't a redirect),
	 * without the REST route.
	 *
	 * @param array|\WP_Error $response
	 */
	private static function redirect_target( $response ): string {
		$code = is_wp_error( $response ) ? 0 : (int) wp_remote_retrieve_response_code( $response );

		if ( ! in_array( $code, array( 301, 302, 303, 307, 308 ), true ) ) {
			return '';
		}

		$location = (string) wp_remote_retrieve_header( $response, 'location' );
		$parts    = wp_parse_url( $location );

		if ( empty( $parts['scheme'] ) || empty( $parts['host'] ) ) {
			return '';
		}

		$path = (string) ( $parts['path'] ?? '' );
		$cut  = strpos( $path, '/wp-json' );
		$path = false === $cut ? $path : substr( $path, 0, $cut );

		return untrailingslashit( $parts['scheme'] . '://' . $parts['host'] . ( isset( $parts['port'] ) ? ':' . (int) $parts['port'] : '' ) . $path );
	}

	/**
	 * The route isn't there in this form: a 404, or a page that isn't the
	 * plugin's JSON (on "Plain" permalinks /wp-json/ shows the home page).
	 *
	 * @param array|\WP_Error $response
	 */
	private static function wrong_route( $response ): bool {
		if ( is_wp_error( $response ) ) {
			return false;
		}

		$code = (int) wp_remote_retrieve_response_code( $response );

		return 404 === $code || ( 200 === $code && ! array_key_exists( 'success', self::body( $response ) ) );
	}

	private static function body( $response ): array {
		$body = is_wp_error( $response ) ? null : json_decode( (string) wp_remote_retrieve_body( $response ), true );

		return is_array( $body ) ? $body : array();
	}

	/**
	 * Before a transfer starts: this site's own HTTPS rule, then ask the
	 * receiving site whether the key is valid and what it accepts.
	 *
	 * @return array{ok:bool,message:string,rest_style:string}
	 */
	public function preflight( string $destination_url, string $token ): array {
		$own = self::own_https_rule( $destination_url );

		if ( '' !== $own ) {
			return array(
				'ok'         => false,
				'message'    => $own,
				'rest_style' => '',
			);
		}

		$payload = array( 'destination_url' => $destination_url );
		$args    = array(
			'body'        => array( 'token' => $token ),
			'timeout'     => 20,
			'sslverify'   => self::is_https( $destination_url ),
			// A redirect means the site lives at another address (often
			// http:// ↔ https://); following it would turn the request into
			// a GET, so say which address to use instead.
			'redirection' => 0,
		);
		$response = wp_remote_post( $this->endpoint( $payload, 'check' ), $args );
		$style    = '';

		$moved = self::redirect_target( $response );

		if ( '' !== $moved ) {
			return array(
				'ok'         => false,
				'message'    => sprintf(
					/* translators: 1: address entered, 2: address the site redirects to */
					__( '%1$s redirects to %2$s. Enter %2$s as the destination site address (as shown on the receiving site next to its key).', 'wrensafe' ),
					untrailingslashit( $destination_url ),
					$moved
				),
				'rest_style' => '',
			);
		}

		if ( self::wrong_route( $response ) ) {
			$payload['rest_style'] = 'plain';
			$plain                 = wp_remote_post( $this->endpoint( $payload, 'check' ), $args );

			if ( is_wp_error( $plain ) || self::wrong_route( $plain ) ) {
				// No check on the other side (an older Wrensafe, or none at
				// all): the first transfer step reports what's wrong.
				return array(
					'ok'         => true,
					'message'    => '',
					'rest_style' => '',
				);
			}

			$response = $plain;
			$style    = 'plain';
		}

		if ( is_wp_error( $response ) ) {
			return array(
				'ok'         => false,
				'message'    => self::connection_error( $response, $destination_url ),
				'rest_style' => $style,
			);
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		$body = self::body( $response );

		if ( 200 !== $code ) {
			return array(
				'ok'         => false,
				'message'    => (string) ( $body['message'] ?? sprintf(
					/* translators: 1: site address, 2: HTTP status code */
					__( '%1$s didn’t answer like a WordPress site with Wrensafe active (HTTP %2$d). Check the address, and that Wrensafe is installed and active there.', 'wrensafe' ),
					untrailingslashit( $destination_url ),
					$code
				) ),
				'rest_style' => $style,
			);
		}

		if ( empty( $body['accepts'] ) ) {
			return array(
				'ok'         => false,
				'message'    => sprintf(
					/* translators: %s: site address */
					__( 'The receiving site (%s) accepts direct migrations only over HTTPS. Use its https:// address, or, on that site, turn off “Require HTTPS” in Wrensafe → Settings → Direct Migration (for example for local or test sites).', 'wrensafe' ),
					untrailingslashit( $destination_url )
				),
				'rest_style' => $style,
			);
		}

		return array(
			'ok'         => true,
			'message'    => sprintf(
				/* translators: 1: site address, 2: version number */
				__( 'Connected to %1$s (Wrensafe %2$s). The key is valid.', 'wrensafe' ),
				(string) ( $body['site_url'] ?? untrailingslashit( $destination_url ) ),
				(string) ( $body['version'] ?? '' )
			),
			'rest_style' => $style,
			'raw_chunks' => self::accepts_raw( $body ),
		);
	}

	/** Whether the receiving site's answer says it takes chunks as the raw body (1.2.1+). */
	private static function accepts_raw( array $body ): bool {
		return in_array( 'raw', (array) ( $body['chunk_formats'] ?? array() ), true );
	}

	/**
	 * A request that didn't succeed: wait and try again when the receiving
	 * site asked to slow down or the connection failed for a moment; stop
	 * with its reason otherwise.
	 *
	 * @param array|\WP_Error $response
	 */
	private function handle_failure( MigrationTransferJob $job, array $payload, $response, string $fallback ): void {
		$code = is_wp_error( $response ) ? 0 : (int) wp_remote_retrieve_response_code( $response );
		$body = self::body( $response );

		if ( 429 === $code ) {
			$wait  = (int) wp_remote_retrieve_header( $response, 'retry-after' );
			$wait  = $wait > 0 ? $wait : (int) ( $body['retry_after'] ?? 60 );
			$waits = (int) ( $payload['waits'] ?? 0 ) + 1;

			// A long block (for example after wrong keys) or a site that keeps
			// refusing: stop with its reason instead of waiting indefinitely.
			if ( $wait > self::MAX_WAIT || $waits > 30 ) {
				$job->mark_failed( (string) ( $body['message'] ?? $fallback ) );
				return;
			}

			$wait             = max( 1, $wait );
			$payload['waits'] = $waits;

			$payload['retry_at'] = time() + $wait;
			$job->set_payload( $payload );
			/* translators: %d: number of seconds */
			$job->update_progress( $job->progress(), sprintf( __( 'The receiving site asked this server to slow down. Continuing in %d seconds…', 'wrensafe' ), $wait ) );
			return;
		}

		if ( 0 === $code || $code >= 500 ) {
			$attempts = (int) ( $payload['attempts'] ?? 0 ) + 1;
			$reason   = is_wp_error( $response ) ? self::connection_error( $response, (string) $payload['destination_url'] ) : (string) ( $body['message'] ?? sprintf( /* translators: %d: HTTP status code */ __( 'The receiving site had an error (HTTP %d).', 'wrensafe' ), $code ) );

			if ( $attempts <= self::MAX_RETRIES ) {
				$payload['attempts'] = $attempts;
				$payload['retry_at'] = time() + min( 30, 2 ** $attempts );
				$job->set_payload( $payload );
				/* translators: 1: attempt number, 2: number of attempts */
				$job->update_progress( $job->progress(), sprintf( __( 'Connection problem. Trying again (%1$d of %2$d)…', 'wrensafe' ), $attempts, self::MAX_RETRIES ) );
				return;
			}

			$job->mark_failed( $reason . ' ' . __( 'Start the transfer again with the same key to continue where it stopped.', 'wrensafe' ) );
			return;
		}

		$job->mark_failed( (string) ( $body['message'] ?? $fallback ) );
	}

	private function step_init( MigrationTransferJob $job, array $payload ): void {
		$backup = BackupsRepository::find( (int) $payload['backup_id'] );

		if ( null === $backup || ! file_exists( $backup['file_path'] ) ) {
			$job->mark_failed( __( 'Source backup file not found.', 'wrensafe' ) );
			return;
		}

		$own = self::own_https_rule( (string) $payload['destination_url'] );

		if ( '' !== $own ) {
			$job->mark_failed( $own );
			return;
		}

		$init = array(
			'body'        => array(
				'token'       => $payload['token'],
				'filename'    => $backup['file_name'],
				'total_size'  => (string) $backup['size'],
				// Shown on the destination's backup list. An encrypted backup
				// can't be read there without its password.
				'backup_type' => (string) ( $backup['backup_type'] ?? '' ),
				'source_url'  => home_url(),
				// Continue an unfinished transfer of this backup with this key.
				'resume'      => '1',
			),
			'timeout'     => 30,
			'sslverify'   => ! empty( $payload['verify_ssl'] ),
			'redirection' => 0,
		);

		$response = wp_remote_post( $this->endpoint( $payload, 'init' ), $init );

		// No /wp-json/ on the destination (Plain permalinks): use ?rest_route=.
		if ( self::wrong_route( $response ) && 'plain' !== ( $payload['rest_style'] ?? '' ) ) {
			$payload['rest_style'] = 'plain';
			$response              = wp_remote_post( $this->endpoint( $payload, 'init' ), $init );
		}

		if ( self::wrong_route( $response ) ) {
			/* translators: %s: site address */
			$job->mark_failed( sprintf( __( 'Wrensafe isn’t active on the receiving site (%s), or the address is wrong.', 'wrensafe' ), untrailingslashit( (string) $payload['destination_url'] ) ) );
			return;
		}

		if ( is_wp_error( $response ) || 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
			$this->handle_failure( $job, $payload, $response, __( 'Destination site rejected the migration key.', 'wrensafe' ) );
			return;
		}

		// The destination advertises the largest chunk it's willing to
		// buffer — use whichever is smaller of that and our own local
		// preference, since the two sites are independently configured.
		$body                  = self::body( $response );
		$server_max            = (int) ( $body['max_chunk_size'] ?? 0 );
		$local_preference      = max( 1, (int) Settings::get( 'migration_chunk_size_mb', 2 ) ) * 1024 * 1024;
		$payload['chunk_size'] = $server_max > 0 ? min( $server_max, $local_preference ) : $local_preference;

		// How to send the chunks: as the raw body when the receiving site
		// says it takes them (as its pre-flight check did), else as before.
		$payload['raw_chunks'] = self::accepts_raw( $body );

		$payload['file_path']   = $backup['file_path'];
		$payload['total_size']  = (int) $backup['size'];
		$payload['offset']      = max( 0, min( (int) ( $body['received_bytes'] ?? 0 ), (int) $backup['size'] ) );
		$payload['chunk_index'] = $payload['offset'] > 0 ? (int) ( $body['next_chunk_index'] ?? 0 ) : 0;
		$payload['checksum']    = hash_file( 'sha256', $backup['file_path'] );
		$payload['attempts']    = 0;
		$payload['phase']       = $payload['offset'] >= $payload['total_size'] ? 'finalize' : 'transfer';

		$job->set_payload( $payload );
		$job->mark_running();

		if ( $payload['offset'] > 0 ) {
			Logger::info( 'Migration transfer #' . $job->id() . ' continues at ' . size_format( $payload['offset'] ) . ' of ' . size_format( $payload['total_size'] ) . '.', array(), $job->id() );
			/* translators: 1: size sent so far, 2: total size */
			$job->update_progress( 5 + (int) ( ( $payload['offset'] / max( 1, $payload['total_size'] ) ) * 90 ), sprintf( __( 'Continuing where the last transfer stopped (%1$s of %2$s)…', 'wrensafe' ), size_format( $payload['offset'] ), size_format( $payload['total_size'] ) ) );
			return;
		}

		$job->update_progress( 5, __( 'Connected to destination site.', 'wrensafe' ) );
	}

	private function step_transfer( MigrationTransferJob $job, array $payload ): void {
		$chunk_size  = max( 1, (int) ( $payload['chunk_size'] ?? self::DEFAULT_CHUNK_SIZE ) );
		$chunk_index = isset( $payload['chunk_index'] ) ? (int) $payload['chunk_index'] : (int) ( $payload['offset'] / $chunk_size );

		$handle = file_exists( (string) $payload['file_path'] ) ? fopen( (string) $payload['file_path'], 'rb' ) : false;
		$chunk  = '';

		if ( false !== $handle ) {
			fseek( $handle, (int) $payload['offset'] );
			$chunk = (string) fread( $handle, $chunk_size );
			fclose( $handle );
		}

		if ( '' === $chunk ) {
			$job->mark_failed( __( 'The backup file couldn’t be read any more (was it deleted or moved?). Start the transfer again.', 'wrensafe' ) );
			return;
		}

		$signature = ( new MigrationKey() )->sign_chunk( $payload['token'], $chunk_index, $chunk );
		$raw       = ! empty( $payload['raw_chunks'] );
		$response  = $raw ? $this->send_raw( $payload, $chunk_index, $signature, $chunk ) : $this->send_encoded( $payload, $chunk_index, $signature, $chunk );

		if ( null === $response ) {
			$job->mark_failed( __( 'This server can’t send the backup to a receiving site with Wrensafe 1.2.0 or older: PHP’s sodium functions are missing. Update Wrensafe on the receiving site and try again.', 'wrensafe' ) );
			return;
		}

		$code = is_wp_error( $response ) ? 0 : (int) wp_remote_retrieve_response_code( $response );

		// Something in front of the receiving site refused the raw body
		// before WordPress saw it (a firewall that only allows form posts),
		// or dropped the key's header (a proxy): send this and the next
		// chunks as a form instead.
		$answer = $raw ? self::body( $response ) : array();

		if ( $raw && in_array( $code, array( 400, 403, 406, 411, 415, 501 ), true ) && ( ! array_key_exists( 'message', $answer ) || 'rest_missing_callback_param' === ( $answer['code'] ?? '' ) ) ) {
			$payload['raw_chunks'] = false;
			$job->set_payload( $payload );
			Logger::warning( 'Migration transfer #' . $job->id() . ': the receiving site refused a chunk sent as the raw request body (HTTP ' . $code . '); sending the chunks as a form instead.', array(), $job->id() );
			return;
		}

		// Out of step with the receiving site (a request was retried after
		// it had already arrived): continue from where that site is.
		if ( 409 === $code ) {
			$body     = self::body( $response );
			$attempts = (int) ( $payload['attempts'] ?? 0 ) + 1;

			if ( isset( $body['next_chunk_index'], $body['received_bytes'] ) && $attempts <= self::MAX_RETRIES ) {
				$payload['chunk_index'] = (int) $body['next_chunk_index'];
				$payload['offset']      = (int) $body['received_bytes'];
				$payload['attempts']    = $attempts;
				$job->set_payload( $payload );
				return;
			}
		}

		if ( 200 !== $code ) {
			$this->handle_failure( $job, $payload, $response, __( 'Chunk transfer failed. Start the transfer again with the same key to continue where it stopped.', 'wrensafe' ) );
			return;
		}

		$payload['offset']     += strlen( $chunk );
		$payload['chunk_index'] = $chunk_index + 1;
		$payload['attempts']    = 0;

		if ( $payload['offset'] >= $payload['total_size'] ) {
			$payload['phase'] = 'finalize';
			$job->update_progress( 95, __( 'Verifying transfer…', 'wrensafe' ) );
		} else {
			$progress = 5 + (int) ( ( $payload['offset'] / max( 1, $payload['total_size'] ) ) * 90 );
			$job->update_progress( $progress, __( 'Transferring backup…', 'wrensafe' ) );
		}

		$job->set_payload( $payload );
	}

	/**
	 * Send a chunk as the raw request body (to 1.2.1+). The key goes in a
	 * header, the rest in the query string.
	 *
	 * @return array|\WP_Error
	 */
	private function send_raw( array $payload, int $chunk_index, string $signature, string $chunk ) {
		$url = add_query_arg(
			array(
				'chunk_index' => $chunk_index,
				'offset'      => (int) $payload['offset'],
				'signature'   => $signature,
			),
			$this->endpoint( $payload, 'chunk' )
		);

		return wp_remote_post(
			$url,
			array(
				'body'        => $chunk,
				'headers'     => array(
					'Content-Type'               => 'application/octet-stream',
					TransferServer::TOKEN_HEADER => (string) $payload['token'],
				),
				'timeout'     => 60,
				'sslverify'   => ! empty( $payload['verify_ssl'] ),
				'redirection' => 0,
			)
		);
	}

	/**
	 * Send a chunk base64-encoded in a form field, the way receiving sites
	 * up to 1.2.0 expect it. Null when this server can't encode it.
	 *
	 * @return array|\WP_Error|null
	 */
	private function send_encoded( array $payload, int $chunk_index, string $signature, string $chunk ) {
		if ( ! function_exists( 'sodium_bin2base64' ) ) {
			return null;
		}

		try {
			$encoded = sodium_bin2base64( $chunk, SODIUM_BASE64_VARIANT_ORIGINAL );
		} catch ( \Throwable $e ) {
			return null;
		}

		return wp_remote_post(
			$this->endpoint( $payload, 'chunk' ),
			array(
				'body'        => array(
					'token'       => $payload['token'],
					'chunk_index' => (string) $chunk_index,
					'signature'   => $signature,
					'chunk'       => $encoded,
				),
				'timeout'     => 60,
				'sslverify'   => ! empty( $payload['verify_ssl'] ),
				'redirection' => 0,
			)
		);
	}

	private function step_finalize( MigrationTransferJob $job, array $payload ): void {
		$response = wp_remote_post(
			$this->endpoint( $payload, 'finalize' ),
			array(
				'body'        => array(
					'token'    => $payload['token'],
					'checksum' => $payload['checksum'],
					// 1.2.1+ receiving sites check the archive in bounded steps
					// and answer 202 until they are done; older ones ignore this
					// and check it all before answering.
					'stepped'  => '1',
				),
				'timeout'     => 300,
				'sslverify'   => ! empty( $payload['verify_ssl'] ),
				'redirection' => 0,
			)
		);

		$code = is_wp_error( $response ) ? 0 : (int) wp_remote_retrieve_response_code( $response );

		// Still checking the archive: ask again with the next step.
		if ( 202 === $code ) {
			$body                = self::body( $response );
			$payload['attempts'] = 0;
			$job->set_payload( $payload );
			/* translators: %d: percent of the backup the receiving site has checked */
			$job->update_progress( 96, sprintf( __( 'The receiving site is checking the backup (%d%%)…', 'wrensafe' ), (int) ( $body['progress'] ?? 0 ) ) );
			return;
		}

		// A retry after the receiving site had already saved the backup (the
		// first answer was lost): its session is gone.
		if ( 410 === $code && (int) ( $payload['attempts'] ?? 0 ) > 0 ) {
			$job->mark_failed( __( 'The receiving site may already have saved the backup: the first answer was lost. Look for it in Wrensafe → Backup Browser on that site before sending it again.', 'wrensafe' ) );
			return;
		}

		if ( 200 !== $code ) {
			$this->handle_failure( $job, $payload, $response, __( 'The destination site could not verify the transferred archive.', 'wrensafe' ) );
			return;
		}

		$job->mark_completed( array( 'backup_id' => $payload['backup_id'] ) );

		Logger::info( 'Migration transfer #' . $job->id() . ' completed.', array(), $job->id() );
	}
}

// phpcs:enable WordPress.WP.AlternativeFunctions
