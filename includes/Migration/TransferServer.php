<?php
/**
 * Destination-side REST API that receives a direct migration transfer.
 *
 * Authentication is the migration key itself (a one-time, expiring,
 * HMAC-signed token) rather than WordPress cookies/application passwords,
 * since the source site has no account on the destination. Each route's
 * permission callback checks the key, with throttling, before the endpoint
 * runs; each route declares its fields (args) with their types and formats.
 *
 * Each session is tracked by a JSON metadata sidecar (token hash, expiry,
 * declared size, and the byte offset/chunk index actually received so
 * far), which is what makes it possible to reject a chunk that's out of
 * order, duplicated, or larger than this server is willing to buffer —
 * none of which the per-chunk HMAC signature alone protects against,
 * since a signature only proves authenticity, not sequencing.
 *
 * Chunks (since 1.2.1) arrive as the raw request body
 * (application/octet-stream), with the key in the X-Wpbp-Migration-Token
 * header and the chunk index, offset and signature in the query string.
 * Senders up to 1.2.0 send each chunk base64-encoded in a form field
 * ("chunk"); that is still accepted, and a 1.2.1 sender uses it for a
 * receiving site that doesn't list "raw" in its chunk_formats. The base64
 * text is read with sodium_base642bin() (PHP's sodium extension, or the
 * copy bundled with WordPress), the same bytes as before.
 *
 * @package WPBP\Migration
 */

namespace WPBP\Migration;

use WPBP\Backup\ArchiveChecksum;
use WPBP\Backup\BackupEncryption;
use WPBP\Backup\IntegrityChecker;
use WPBP\Backup\SliceHash;
use WPBP\Core\Database\BackupsRepository;
use WPBP\Core\Database\MigrationKeysRepository;
use WPBP\Core\Settings;
use WPBP\Restore\ArchiveReader;
use WPBP\Utils\FileSystem;
use WPBP\Utils\Logger;
use WPBP\Utils\Str;

defined( 'ABSPATH' ) || exit;

// Backup archives and upload chunks are streamed in bounded blocks with native
// file handles (fopen/fread/fwrite/rename): WP_Filesystem has no streaming API.
// Every path is inside Wrensafe's protected storage directory, a PHP upload
// temp file, or validated by WPBP\Security\PathValidator.
// phpcs:disable WordPress.WP.AlternativeFunctions

class TransferServer {

	private const SESSION_LIFETIME  = 7200; // 2 hours, renewed by every chunk.
	private const MAX_TOTAL_BYTES   = 20 * 1024 * 1024 * 1024; // 20GB.
	private const RATE_LIMIT_WINDOW = 60;
	private const RATE_LIMIT_MAX    = 30; // check/init requests per minute per IP. Chunks of a session with a valid key aren't counted.
	private const THROTTLE_WINDOW   = 900; // 15 minutes.
	private const THROTTLE_MAX      = 5; // failed key verifications per IP (or per key id, see authenticate()).
	private const LOCK_TRANSIENT    = 'wpbp_migration_lock';
	private const ROUTE_PREFIX      = '/wpbp/v1/migration/';
	private const BASE64_CHARS      = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789+/=';

	/** Header with the migration key on a raw chunk request. */
	const TOKEN_HEADER = 'X-Wpbp-Migration-Token';

	/** How this site accepts chunks, newest first (see handle_check()). */
	const CHUNK_FORMATS = array( 'raw', 'base64' );

	/**
	 * Keys accepted by this request's permission check, by token hash, for
	 * the endpoint callback that follows.
	 *
	 * @var array<string,array>
	 */
	private array $accepted = array();

	/**
	 * Permission results of this request. WordPress runs a route's
	 * permission callback a second time for the Allow header
	 * (rest_send_allow_header()); that run must not count again.
	 *
	 * @var array<string,true|\WP_Error>
	 */
	private array $decided = array();

	public function register_hooks(): void {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
		add_filter( 'rest_pre_dispatch', array( $this, 'token_from_header' ), 10, 3 );
		add_filter( 'rest_request_after_callbacks', array( $this, 'slow_down_response' ), 10, 3 );
	}

	/**
	 * A raw chunk request carries the key in a header (its body is the
	 * chunk itself, and a key in the address would end up in server logs).
	 * Copy it into the "token" field before WordPress checks the fields and
	 * runs the permission check, so both apply exactly as for a form request.
	 *
	 * @param mixed            $result  Response to use instead, or null.
	 * @param \WP_REST_Server  $server  Server instance.
	 * @param \WP_REST_Request $request The request.
	 * @return mixed
	 */
	public function token_from_header( $result, $server, $request ) {
		unset( $server );

		if ( null !== $result || ! $request instanceof \WP_REST_Request || self::ROUTE_PREFIX . 'chunk' !== (string) $request->get_route() ) {
			return $result;
		}

		$header = (string) $request->get_header( self::TOKEN_HEADER );

		if ( '' !== $header && null === $request->get_param( 'token' ) ) {
			$request->set_param( 'token', $header );
		}

		return $result;
	}

	/**
	 * The endpoints. A permission callback checks the migration key (with
	 * throttling) before an endpoint runs; the request fields are the ones
	 * Wrensafe 1.1.3 and earlier send, so older sites can still send here.
	 */
	public function register_routes(): void {
		$token = array(
			'description'       => __( 'The migration key generated on this site.', 'wrensafe' ),
			'type'              => 'string',
			'required'          => true,
			'validate_callback' => array( $this, 'validate_token' ),
			'sanitize_callback' => array( $this, 'sanitize_token' ),
		);

		register_rest_route(
			'wpbp/v1',
			'/migration/check',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'handle_check' ),
				'permission_callback' => array( $this, 'permit_check' ),
				'args'                => array( 'token' => $token ),
			)
		);

		register_rest_route(
			'wpbp/v1',
			'/migration/init',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'handle_init' ),
				'permission_callback' => array( $this, 'permit_init' ),
				'args'                => array(
					'token'       => $token,
					'filename'    => array(
						'type'              => 'string',
						'required'          => true,
						'maxLength'         => 255,
						'validate_callback' => 'rest_validate_request_arg',
						'sanitize_callback' => array( $this, 'sanitize_file_name_arg' ),
					),
					'total_size'  => array(
						'type'              => 'integer',
						'required'          => true,
						'minimum'           => 1,
						'maximum'           => self::MAX_TOTAL_BYTES,
						'validate_callback' => 'rest_validate_request_arg',
						'sanitize_callback' => 'rest_sanitize_request_arg',
					),
					'backup_type' => array(
						'type'              => 'string',
						'default'           => '',
						'validate_callback' => 'rest_validate_request_arg',
						'sanitize_callback' => array( $this, 'sanitize_key_arg' ),
					),
					'source_url'  => array(
						'type'              => 'string',
						'default'           => '',
						'validate_callback' => 'rest_validate_request_arg',
						'sanitize_callback' => array( $this, 'sanitize_url_arg' ),
					),
					// 1.1.3+ senders continue an unfinished transfer.
					'resume'      => array(
						'type'              => 'string',
						'default'           => '0',
						'enum'              => array( '0', '1' ),
						'validate_callback' => 'rest_validate_request_arg',
						'sanitize_callback' => 'rest_sanitize_request_arg',
					),
				),
			)
		);

		register_rest_route(
			'wpbp/v1',
			'/migration/chunk',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'handle_chunk' ),
				'permission_callback' => array( $this, 'permit_chunk' ),
				'args'                => array(
					'token'       => $token,
					'chunk_index' => array(
						'type'              => 'integer',
						'required'          => true,
						'minimum'           => 0,
						'validate_callback' => 'rest_validate_request_arg',
						'sanitize_callback' => 'rest_sanitize_request_arg',
					),
					'signature'   => array(
						'type'              => 'string',
						'required'          => true,
						'pattern'           => '^[a-f0-9]{64}$',
						'validate_callback' => 'rest_validate_request_arg',
						'sanitize_callback' => 'rest_sanitize_request_arg',
					),
					// 1.2.1+ (raw body): where the chunk starts in the archive.
					'offset'      => array(
						'type'              => 'integer',
						'minimum'           => 0,
						'maximum'           => self::MAX_TOTAL_BYTES,
						'validate_callback' => 'rest_validate_request_arg',
						'sanitize_callback' => 'rest_sanitize_request_arg',
					),
					// Base64 in a form field (senders up to 1.2.0); a 1.2.1 sender
					// sends the chunk as the raw body instead. Decoded and
					// size-checked by handle_chunk().
					'chunk'       => array(
						'type'              => 'string',
						'required'          => false,
						'validate_callback' => array( $this, 'validate_chunk' ),
						'sanitize_callback' => 'rest_sanitize_request_arg',
					),
				),
			)
		);

		register_rest_route(
			'wpbp/v1',
			'/migration/finalize',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'handle_finalize' ),
				'permission_callback' => array( $this, 'permit_finalize' ),
				'args'                => array(
					'token'    => $token,
					'checksum' => array(
						'type'              => 'string',
						'required'          => true,
						'pattern'           => '^[a-f0-9]{64}$',
						'validate_callback' => 'rest_validate_request_arg',
						'sanitize_callback' => 'rest_sanitize_request_arg',
					),
					// 1.2.1+ senders: check in bounded steps (202 until done).
					'stepped'  => array(
						'type'              => 'string',
						'default'           => '0',
						'enum'              => array( '0', '1' ),
						'validate_callback' => 'rest_validate_request_arg',
						'sanitize_callback' => 'rest_sanitize_request_arg',
					),
				),
			)
		);
	}

	// -- Request fields ---------------------------------------------------------

	/** A key: 48 hex characters (a pasted key may have capitals or spaces around it). */
	public function validate_token( $value ): bool {
		return is_string( $value ) && 1 === preg_match( '/^[a-fA-F0-9]{48}$/', trim( $value ) );
	}

	public function sanitize_token( $value ): string {
		return strtolower( trim( (string) $value ) );
	}

	/** Base64 text no longer than this site's largest chunk. */
	public function validate_chunk( $value ): bool {
		if ( ! is_string( $value ) || '' === $value ) {
			return false;
		}

		$length = strlen( $value );

		return $length <= 4 * (int) ceil( $this->max_chunk_bytes() / 3 ) && strspn( $value, self::BASE64_CHARS ) === $length;
	}

	public function sanitize_file_name_arg( $value ): string {
		return sanitize_file_name( (string) $value );
	}

	public function sanitize_key_arg( $value ): string {
		return sanitize_key( (string) $value );
	}

	public function sanitize_url_arg( $value ): string {
		return esc_url_raw( (string) $value );
	}

	private function session_path( string $token ): string {
		return FileSystem::uploads_dir() . 'migration-' . hash( 'sha256', $token ) . '.part';
	}

	private function meta_path( string $token ): string {
		return FileSystem::uploads_dir() . 'migration-' . hash( 'sha256', $token ) . '.meta.json';
	}

	private function remote_ip(): string {
		$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';

		return false !== filter_var( $ip, FILTER_VALIDATE_IP ) ? $ip : '';
	}

	private function max_chunk_bytes(): int {
		return max( 1, (int) Settings::get( 'migration_chunk_size_mb', 2 ) ) * 1024 * 1024;
	}

	// -- Permission checks ------------------------------------------------------

	public function permit_check( \WP_REST_Request $request ) {
		/* translators: %s: site address */
		return $this->authenticate( $request, true, sprintf( __( 'The receiving site (%s) didn’t accept the migration key: it is wrong, expired or already used. Generate a new key on that site.', 'wrensafe' ), home_url() ) );
	}

	public function permit_init( \WP_REST_Request $request ) {
		return $this->authenticate( $request, true, __( 'Invalid, expired, or already used migration key.', 'wrensafe' ) );
	}

	public function permit_chunk( \WP_REST_Request $request ) {
		return $this->authenticate( $request, false, __( 'Invalid, expired, or already used migration key.', 'wrensafe' ) );
	}

	public function permit_finalize( \WP_REST_Request $request ) {
		return $this->authenticate( $request, false, __( 'Invalid, expired, or already used migration key.', 'wrensafe' ) );
	}

	/**
	 * Whether the request carries a valid migration key: true, or a
	 * WP_Error with status 403 (wrong, expired or used key) or 429 (too
	 * many requests or wrong keys; the response says how long to wait).
	 *
	 * Throttling has two scopes. A request whose key starts with the id of
	 * one of this site's keys (MigrationKey::is_known_key_id()) is counted
	 * against that key id; anything else against the sender's IP address.
	 * So wrong guesses from an address that several senders share (a proxy,
	 * a NAT) lock out the guesser, not the sender that has the real key,
	 * while a guesser still gets only 5 tries per address: a guess counts
	 * against a key id only when it starts with one, and a wrong key that
	 * does is counted against both.
	 *
	 * @param bool $counts Count towards the request limit (check and init; a
	 *                     session's chunks are not counted).
	 * @return true|\WP_Error
	 */
	private function authenticate( \WP_REST_Request $request, bool $counts, string $denied ) {
		$id = spl_object_hash( $request );

		if ( ! isset( $this->decided[ $id ] ) ) {
			$this->decided[ $id ] = $this->check_key( $request, $counts, $denied );
		}

		return $this->decided[ $id ];
	}

	/**
	 * @return true|\WP_Error
	 */
	private function check_key( \WP_REST_Request $request, bool $counts, string $denied ) {
		$ip    = $this->remote_ip();
		$token = (string) $request->get_param( 'token' );

		// No key at all, or not in a key's form: the field check (args)
		// already answered 400, and WordPress only runs this for the Allow
		// header. Not counted as a wrong key.
		if ( ! preg_match( MigrationKey::PATTERN, $token ) ) {
			return new \WP_Error( 'wpbp_migration_key_rejected', $denied, array( 'status' => 403 ) );
		}

		$ip_scope = 'ip_' . $ip;
		$scope    = MigrationKey::is_known_key_id( $token ) ? 'key_' . MigrationKey::key_id( $token ) : $ip_scope;

		if ( $this->is_throttled( $scope ) || ( $counts && $this->is_rate_limited( $scope ) ) ) {
			return $this->too_many_requests( $scope );
		}

		if ( $counts ) {
			$this->record_request( $scope );
		}

		$key = ( new MigrationKey() )->verify( $token, $ip );

		if ( null === $key ) {
			$this->record_failed_attempt( $ip_scope );

			if ( $scope !== $ip_scope ) {
				$this->record_failed_attempt( $scope );
			}

			return new \WP_Error( 'wpbp_migration_key_rejected', $denied, array( 'status' => 403 ) );
		}

		$this->clear_throttle( $scope );
		$this->accepted[ hash( 'sha256', $token ) ] = $key;

		return true;
	}

	/** The key the permission check accepted for this request. */
	private function accepted_key( string $token ): ?array {
		$hash = hash( 'sha256', $token );

		if ( ! isset( $this->accepted[ $hash ] ) ) {
			$key = ( new MigrationKey() )->verify( $token, $this->remote_ip() );

			if ( null === $key ) {
				return null;
			}

			$this->accepted[ $hash ] = $key;
		}

		return $this->accepted[ $hash ];
	}

	/**
	 * A 429 from a permission check, in the form senders since 1.1.0 read:
	 * "message" and "retry_after" at the top level, and a Retry-After
	 * header (a WP_Error response has neither).
	 *
	 * @param mixed            $response
	 * @param array            $handler
	 * @param \WP_REST_Request $request
	 * @return mixed
	 */
	public function slow_down_response( $response, $handler, $request ) {
		unset( $handler );

		if ( ! is_wp_error( $response ) || ! $request instanceof \WP_REST_Request || ! Str::starts_with( (string) $request->get_route(), self::ROUTE_PREFIX ) ) {
			return $response;
		}

		$data = $response->get_error_data();

		if ( ! is_array( $data ) || 429 !== (int) ( $data['status'] ?? 0 ) ) {
			return $response;
		}

		$wait = max( 1, (int) ( $data['retry_after'] ?? 60 ) );
		$out  = new \WP_REST_Response(
			array(
				'code'        => $response->get_error_code(),
				'message'     => $response->get_error_message(),
				'retry_after' => $wait,
			),
			429
		);
		$out->header( 'Retry-After', (string) $wait );

		return $out;
	}

	// -- Rate limiting & failed-attempt throttling -----------------------------
	//
	// Fixed windows: a window starts with its first request and ends
	// $window seconds later, however many requests arrive meanwhile (an
	// earlier version renewed the window on every request, so retries kept
	// a sender locked out). Only "check" and "init" count towards the
	// request limit; the chunks of a session whose key verifies don't (a fast
	// link sends far more than 30 a minute). Failed key checks are limited
	// on every endpoint. $scope is "ip_<address>" or "key_<key id>".

	/** @return array{count:int,reset:int} */
	private function window( string $name, string $scope ): array {
		$value = get_transient( 'wpbp_migration_' . $name . '_' . md5( $scope ) );

		if ( ! is_array( $value ) || (int) ( $value['reset'] ?? 0 ) <= time() ) {
			return array(
				'count' => 0,
				'reset' => 0,
			);
		}

		return array(
			'count' => (int) $value['count'],
			'reset' => (int) $value['reset'],
		);
	}

	private function hit( string $name, string $scope, int $length ): void {
		$window = $this->window( $name, $scope );
		$reset  = $window['reset'] > 0 ? $window['reset'] : time() + $length;

		set_transient(
			'wpbp_migration_' . $name . '_' . md5( $scope ),
			array(
				'count' => $window['count'] + 1,
				'reset' => $reset,
			),
			max( 1, $reset - time() )
		);
	}

	private function is_rate_limited( string $scope ): bool {
		return $this->window( 'rate', $scope )['count'] >= self::RATE_LIMIT_MAX;
	}

	private function record_request( string $scope ): void {
		$this->hit( 'rate', $scope, self::RATE_LIMIT_WINDOW );
	}

	private function is_throttled( string $scope ): bool {
		return $this->window( 'throttle', $scope )['count'] >= self::THROTTLE_MAX;
	}

	private function record_failed_attempt( string $scope ): void {
		$this->hit( 'throttle', $scope, self::THROTTLE_WINDOW );
	}

	private function clear_throttle( string $scope ): void {
		delete_transient( 'wpbp_migration_throttle_' . md5( $scope ) );
	}

	/**
	 * 429 with the seconds until the window ends, so the sending site waits
	 * and continues instead of failing (see slow_down_response()).
	 */
	private function too_many_requests( string $scope ): \WP_Error {
		$reset = max( $this->window( 'rate', $scope )['reset'], $this->is_throttled( $scope ) ? $this->window( 'throttle', $scope )['reset'] : 0 );
		$wait  = max( 1, $reset - time() );

		$minutes = (int) ceil( $wait / 60 );
		$when    = $wait < 120
			/* translators: %d: number of seconds */
			? sprintf( _n( '%d second', '%d seconds', $wait, 'wrensafe' ), $wait )
			/* translators: %d: number of minutes */
			: sprintf( _n( '%d minute', '%d minutes', $minutes, 'wrensafe' ), $minutes );

		return new \WP_Error(
			'wpbp_migration_slow_down',
			sprintf(
				/* translators: 1: site address, 2: time to wait, e.g. "15 minutes" */
				__( 'The receiving site (%1$s) is limiting requests from this server, for example after several wrong migration keys. Try again in %2$s.', 'wrensafe' ),
				home_url(),
				$when
			),
			array(
				'status'      => 429,
				'retry_after' => $wait,
			)
		);
	}

	// -- Single-session binding ----------------------------------------------

	/**
	 * Only one incoming migration may be active at a time — a different
	 * token can't start a competing session while one is in progress. The
	 * same token re-acquiring its own lock (resuming/restarting) is always
	 * allowed, and the lock auto-expires if a session is ever abandoned
	 * without finalizing.
	 */
	private function acquire_lock( string $token ): bool {
		$current = get_transient( self::LOCK_TRANSIENT );

		if ( false !== $current && $current !== $token ) {
			return false;
		}

		set_transient( self::LOCK_TRANSIENT, $token, self::SESSION_LIFETIME );

		return true;
	}

	private function release_lock( string $token ): void {
		if ( get_transient( self::LOCK_TRANSIENT ) === $token ) {
			delete_transient( self::LOCK_TRANSIENT );
		}
	}

	// -- Session metadata -----------------------------------------------------

	private function truncate( string $path, int $size ): void {
		$handle = fopen( $path, 'r+b' );

		if ( false !== $handle ) {
			ftruncate( $handle, $size );
			fclose( $handle );
		}

		clearstatcache( true, $path );
	}

	private function read_meta( string $token ): ?array {
		$path = $this->meta_path( $token );

		if ( ! file_exists( $path ) ) {
			return null;
		}

		$meta = json_decode( (string) file_get_contents( $path ), true ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_read_file_get_contents

		if ( ! is_array( $meta ) ) {
			return null;
		}

		if ( empty( $meta['expires_at'] ) || time() > (int) $meta['expires_at'] ) {
			$this->discard_session( $token );
			return null;
		}

		return $meta;
	}

	private function write_meta( string $token, array $meta ): void {
		file_put_contents( $this->meta_path( $token ), wp_json_encode( $meta ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
	}

	/**
	 * Remove a session's working files (but never the final renamed .wbp
	 * archive — handle_finalize() renames the .part file away before this
	 * would ever be called) and release the migration lock if held by it.
	 */
	private function discard_session( string $token ): void {
		$part = $this->session_path( $token );
		$meta = $this->meta_path( $token );

		if ( file_exists( $part ) ) {
			wp_delete_file( $part );
		}
		if ( file_exists( $meta ) ) {
			wp_delete_file( $meta );
		}

		$this->release_lock( $token );
	}

	private function accepts_this_connection(): bool {
		return ! Settings::get( 'migration_require_https', true ) || is_ssl();
	}

	private function require_https_error(): ?\WP_REST_Response {
		if ( ! $this->accepts_this_connection() ) {
			return new \WP_REST_Response(
				array(
					'message' => sprintf(
						/* translators: %s: site address */
						__( 'The receiving site (%s) accepts direct migrations only over HTTPS. Use its https:// address, or, on that site, turn off “Require HTTPS” in Wrensafe → Settings → Direct Migration (for example for local or test sites).', 'wrensafe' ),
						home_url()
					),
				),
				400
			);
		}

		return null;
	}

	/**
	 * Before a transfer: confirm the key and say what this site accepts, so
	 * the sending site can explain any problem before it sends anything.
	 * The key was checked by permit_check().
	 */
	public function handle_check( \WP_REST_Request $request ) {
		unset( $request );

		return new \WP_REST_Response(
			array(
				'success'        => true,
				'version'        => WPBP_VERSION,
				'site_url'       => home_url(),
				'https'          => is_ssl(),
				'require_https'  => (bool) Settings::get( 'migration_require_https', true ),
				'accepts'        => $this->accepts_this_connection(),
				'max_chunk_size' => $this->max_chunk_bytes(),
				// 1.2.1+: senders send chunks as the raw body when "raw" is listed.
				'chunk_formats'  => self::CHUNK_FORMATS,
			),
			200
		);
	}

	/** Start (or continue) a session. The key was checked by permit_init(). */
	public function handle_init( \WP_REST_Request $request ) {
		$https_error = $this->require_https_error();
		if ( null !== $https_error ) {
			return $https_error;
		}

		$token = (string) $request->get_param( 'token' );
		$key   = $this->accepted_key( $token );

		if ( null === $key ) {
			return new \WP_REST_Response( array( 'message' => __( 'Invalid, expired, or already used migration key.', 'wrensafe' ) ), 403 );
		}

		if ( ! $this->acquire_lock( $token ) ) {
			return new \WP_REST_Response( array( 'message' => __( 'Another migration is currently in progress on this site. Please try again once it finishes.', 'wrensafe' ) ), 409 );
		}

		$filename   = (string) $request->get_param( 'filename' );
		$total_size = (int) $request->get_param( 'total_size' );

		if ( '' === $filename || ! Str::ends_with( strtolower( $filename ), '.wbp' ) || $total_size <= 0 || $total_size > self::MAX_TOTAL_BYTES ) {
			$this->release_lock( $token );
			return new \WP_REST_Response( array( 'message' => __( 'Invalid filename or declared size.', 'wrensafe' ) ), 400 );
		}

		// The key stays valid while its transfer runs (renewed by every chunk).
		( new MigrationKey() )->extend( $key, $token, time() + self::SESSION_LIFETIME );

		// The same key starting again for the same backup: continue the
		// unfinished session where it stopped instead of starting over.
		$existing = $this->read_meta( $token );
		$part     = $this->session_path( $token );

		// Only for senders that continue from the returned position (1.1.3+
		// send resume=1); an older sender always starts at the first chunk.
		if ( '1' === (string) $request->get_param( 'resume' ) && null !== $existing && (string) $existing['filename'] === $filename && (int) $existing['total_size'] === $total_size && file_exists( $part ) ) {
			clearstatcache( true, $part );

			if ( (int) filesize( $part ) === (int) $existing['received_bytes'] ) {
				$existing['expires_at'] = time() + self::SESSION_LIFETIME;
				$this->write_meta( $token, $existing );

				Logger::info( 'Incoming direct migration resumed at ' . size_format( (int) $existing['received_bytes'] ) . '.' );

				return new \WP_REST_Response(
					array(
						'success'          => true,
						'max_chunk_size'   => $this->max_chunk_bytes(),
						'chunk_formats'    => self::CHUNK_FORMATS,
						'received_bytes'   => (int) $existing['received_bytes'],
						'next_chunk_index' => (int) $existing['next_chunk_index'],
					),
					200
				);
			}
		}

		FileSystem::ensure_protected_directory( FileSystem::uploads_dir() );

		if ( false === file_put_contents( $part, '' ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
			$this->release_lock( $token );
			return new \WP_REST_Response( array( 'message' => __( 'The receiving site couldn’t create the file for the backup. Check that its Wrensafe storage folder is writable.', 'wrensafe' ) ), 500 );
		}

		$this->write_meta(
			$token,
			array(
				'token_hash'       => hash( 'sha256', $token ),
				'created_at'       => time(),
				'expires_at'       => time() + self::SESSION_LIFETIME,
				'filename'         => $filename,
				'total_size'       => $total_size,
				'backup_type'      => (string) $request->get_param( 'backup_type' ),
				'source_url'       => (string) $request->get_param( 'source_url' ),
				'expected_chunks'  => (int) ceil( $total_size / $this->max_chunk_bytes() ),
				'received_chunks'  => 0,
				'next_chunk_index' => 0,
				'received_bytes'   => 0,
				'remote_ip'        => $this->remote_ip(),
			)
		);

		Logger::info( 'Incoming direct migration started.' );

		return new \WP_REST_Response(
			array(
				'success'          => true,
				'max_chunk_size'   => $this->max_chunk_bytes(),
				'chunk_formats'    => self::CHUNK_FORMATS,
				'received_bytes'   => 0,
				'next_chunk_index' => 0,
			),
			200
		);
	}

	/** Append one chunk. The key was checked by permit_chunk(). */
	public function handle_chunk( \WP_REST_Request $request ) {
		$token = (string) $request->get_param( 'token' );
		$key   = $this->accepted_key( $token );

		if ( null === $key ) {
			return new \WP_REST_Response( array( 'message' => __( 'Invalid, expired, or already used migration key.', 'wrensafe' ) ), 403 );
		}

		$meta = $this->read_meta( $token );

		if ( null === $meta ) {
			return new \WP_REST_Response( array( 'message' => __( 'Migration session not found or expired. Please restart the migration.', 'wrensafe' ) ), 410 );
		}

		$chunk_index = (int) $request->get_param( 'chunk_index' );
		$signature   = (string) $request->get_param( 'signature' );
		$chunk       = $this->chunk_bytes( $request );
		$chunk_len   = strlen( $chunk );

		if ( $chunk_len <= 0 || $chunk_len > $this->max_chunk_bytes() ) {
			return new \WP_REST_Response( array( 'message' => __( 'Chunk exceeds the maximum allowed size.', 'wrensafe' ) ), 400 );
		}

		$offset = $request->get_param( 'offset' );

		if ( $chunk_index !== (int) $meta['next_chunk_index'] || ( null !== $offset && (int) $offset !== (int) $meta['received_bytes'] ) ) {
			return new \WP_REST_Response(
				array(
					'message'          => __( 'Chunk out of order. Please restart the migration.', 'wrensafe' ),
					'next_chunk_index' => (int) $meta['next_chunk_index'],
					'received_bytes'   => (int) $meta['received_bytes'],
				),
				409
			);
		}

		if ( (int) $meta['received_bytes'] + $chunk_len > (int) $meta['total_size'] ) {
			return new \WP_REST_Response( array( 'message' => __( 'Transfer exceeds the declared archive size.', 'wrensafe' ) ), 400 );
		}

		$expected_signature = ( new MigrationKey() )->sign_chunk( $token, $chunk_index, $chunk );

		if ( ! hash_equals( $expected_signature, $signature ) ) {
			return new \WP_REST_Response( array( 'message' => __( 'Chunk signature verification failed.', 'wrensafe' ) ), 400 );
		}

		$part = $this->session_path( $token );

		// Append right after the recorded bytes: a request that stopped after
		// writing but before recording it left bytes the sender sends again.
		clearstatcache( true, $part );
		$on_disk = file_exists( $part ) ? (int) filesize( $part ) : -1;

		if ( $on_disk < (int) $meta['received_bytes'] ) {
			$this->discard_session( $token );
			return new \WP_REST_Response( array( 'message' => __( 'Migration session not found or expired. Please restart the migration.', 'wrensafe' ) ), 410 );
		}

		if ( $on_disk > (int) $meta['received_bytes'] ) {
			$this->truncate( $part, (int) $meta['received_bytes'] );
		}

		$handle  = fopen( $part, 'ab' );
		$written = false !== $handle ? fwrite( $handle, $chunk ) : false;

		if ( false !== $handle ) {
			fclose( $handle );
		}

		if ( $written !== $chunk_len ) {
			// Cut back to the last whole chunk, so the sender can retry it.
			$this->truncate( $part, (int) $meta['received_bytes'] );

			return new \WP_REST_Response( array( 'message' => __( 'The receiving site couldn’t save the chunk. Check the free disk space there.', 'wrensafe' ) ), 507 );
		}

		$meta['received_bytes']   = (int) $meta['received_bytes'] + $chunk_len;
		$meta['received_chunks']  = (int) $meta['received_chunks'] + 1;
		$meta['next_chunk_index'] = $chunk_index + 1;
		// A large backup can take longer than the first two hours.
		$meta['expires_at'] = time() + self::SESSION_LIFETIME;
		$this->write_meta( $token, $meta );
		$this->acquire_lock( $token );
		( new MigrationKey() )->extend( $key, $token, (int) $meta['expires_at'] );

		return new \WP_REST_Response(
			array(
				'success'        => true,
				'received_bytes' => $meta['received_bytes'],
			),
			200
		);
	}

	/**
	 * The chunk's bytes: the raw body (1.2.1+ senders), or the base64 form
	 * field of earlier senders. '' when there is none or it isn't valid.
	 */
	private function chunk_bytes( \WP_REST_Request $request ): string {
		if ( self::is_raw_body( $request ) ) {
			return (string) $request->get_body();
		}

		$encoded = (string) $request->get_param( 'chunk' );

		if ( '' === $encoded || ! function_exists( 'sodium_base642bin' ) ) {
			return '';
		}

		try {
			return (string) sodium_base642bin( $encoded, SODIUM_BASE64_VARIANT_ORIGINAL );
		} catch ( \Throwable $e ) {
			return '';
		}
	}

	private static function is_raw_body( \WP_REST_Request $request ): bool {
		$type = $request->get_content_type();

		return is_array( $type ) && 'application/octet-stream' === strtolower( (string) ( $type['value'] ?? '' ) );
	}

	/** Check the archive and keep it. The key was checked by permit_finalize(). */
	public function handle_finalize( \WP_REST_Request $request ) {
		$token = (string) $request->get_param( 'token' );
		$key   = $this->accepted_key( $token );

		if ( null === $key ) {
			return new \WP_REST_Response( array( 'message' => __( 'Invalid, expired, or already used migration key.', 'wrensafe' ) ), 403 );
		}

		$meta = $this->read_meta( $token );

		if ( null === $meta ) {
			return new \WP_REST_Response( array( 'message' => __( 'Migration session not found or expired. Please restart the migration.', 'wrensafe' ) ), 410 );
		}

		if ( (int) $meta['received_bytes'] !== (int) $meta['total_size'] ) {
			return new \WP_REST_Response( array( 'message' => __( 'Transfer incomplete — received and declared sizes do not match.', 'wrensafe' ) ), 400 );
		}

		$checksum = (string) $request->get_param( 'checksum' );
		$session  = $this->session_path( $token );

		// 1.2.1+ senders ask for the check in bounded steps: until it is done
		// this answers 202 and the sender asks again. Earlier senders wait
		// for one answer: they get as much as PHP's time limit allows, and if
		// that isn't enough, a 503 they answer by trying again (5 times,
		// waiting longer each time), each try continuing the check.
		$stepped  = '1' === (string) $request->get_param( 'stepped' );
		$max      = (int) ini_get( 'max_execution_time' );
		$deadline = $stepped ? self::step_deadline() : ( $max > 0 ? microtime( true ) + max( 4, $max - 8 ) : null );
		$checking = $this->check_archive( $token, $meta, $checksum, $session, $deadline );

		if ( null !== $checking && ! $stepped && 202 === $checking->get_status() ) {
			$checking = new \WP_REST_Response( array( 'message' => __( 'The receiving site is still checking the backup. The sending site tries again shortly.', 'wrensafe' ) ), 503 );
			$checking->header( 'Retry-After', '2' );
		}

		if ( null !== $checking ) {
			return $checking;
		}

		// An encrypted backup can't be opened without its password; the
		// checksum above already proves it arrived intact, and the restore
		// asks for the password and checks the contents.
		$encrypted = ( new BackupEncryption() )->is_encrypted( $session );

		// What the sending site said about the backup; the archive's own
		// details replace it when the archive can be read.
		$metadata = array(
			'backup_type' => (string) ( $meta['backup_type'] ?? '' ),
			'site_url'    => (string) ( $meta['source_url'] ?? '' ),
		);

		if ( ! $encrypted ) {
			$result = ( new ArchiveReader( $session ) )->validate();

			if ( ! $result['valid'] ) {
				$this->discard_session( $token );
				return new \WP_REST_Response( array( 'message' => $result['message'] ), 400 );
			}

			$metadata = array_merge( $metadata, array_filter( (array) ( $result['metadata'] ?? array() ) ) );
			$type     = (string) ( $result['manifest']['backup_type'] ?? '' );

			if ( '' !== $type ) {
				$metadata['backup_type'] = $type;
			}
		}

		$valid_types = array( 'full', 'database', 'files', 'media', 'themes', 'plugins' );

		if ( ! in_array( $metadata['backup_type'], $valid_types, true ) ) {
			$metadata['backup_type'] = 'full';
		}

		// Keep it as a normal backup on this site: it shows in the Backup
		// Browser and under Import / Restore → Local backup, where it is
		// reviewed and restored like any other backup.
		$source_url = (string) ( $metadata['site_url'] ?? '' );
		$host       = (string) wp_parse_url( $source_url, PHP_URL_HOST );
		$slug       = trim( (string) preg_replace( '/[^a-z0-9]+/', '-', strtolower( '' !== $host ? $host : 'migration' ) ), '-' );
		$file_name  = sanitize_file_name( (string) ( $meta['filename'] ?? '' ) );

		if ( '' === $file_name || ! Str::ends_with( strtolower( $file_name ), '.wbp' ) || file_exists( FileSystem::backups_dir() . $file_name ) ) {
			$file_name = sprintf( '%s-%s-%s.wbp', $slug, gmdate( 'Ymd-His' ), strtolower( wp_generate_password( 12, false, false ) ) );
		}

		$final_path = FileSystem::backups_dir() . $file_name;

		wp_mkdir_p( FileSystem::backups_dir() );

		if ( ! @rename( $session, $final_path ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged,WordPress.WP.AlternativeFunctions.rename_rename -- falls back to an error response.
			$this->discard_session( $token );
			return new \WP_REST_Response( array( 'message' => __( 'The backup arrived, but it could not be saved in this site’s backup folder. Check that the folder is writable and the disk has free space.', 'wrensafe' ) ), 500 );
		}

		wp_delete_file( $this->meta_path( $token ) );
		$this->release_lock( $token );

		$backup_id = BackupsRepository::create(
			array(
				'file_name'    => $file_name,
				'file_path'    => $final_path,
				'backup_type'  => sanitize_key( (string) ( $metadata['backup_type'] ?? 'full' ) ),
				'size'         => (int) filesize( $final_path ),
				'status'       => 'completed',
				'source_url'   => esc_url_raw( $source_url ),
				'wp_version'   => sanitize_text_field( (string) ( $metadata['wp_version'] ?? '' ) ),
				'php_version'  => sanitize_text_field( (string) ( $metadata['php_version'] ?? '' ) ),
				'checksum'     => $checksum,
				'note'         => '' !== $source_url
					/* translators: %s: address of the site the backup came from */
					? sprintf( __( 'Received by Direct Migration from %s', 'wrensafe' ), $source_url )
					: __( 'Received by Direct Migration', 'wrensafe' ),
				'destination'  => 'local',
				'is_encrypted' => $encrypted ? 1 : 0,
			)
		);

		MigrationKeysRepository::mark_used( (int) $key['id'] );

		Logger::success( 'direct_migration_received: backup #' . $backup_id . ' received' . ( '' !== $source_url ? ' from ' . $source_url : '' ) . ' (' . size_format( (int) filesize( $final_path ) ) . ( $encrypted ? ', encrypted' : '' ) . '). It is ready to restore under Import / Restore → Local backup.' );

		/**
		 * Fires when a backup sent by Direct Migration has arrived and been saved.
		 *
		 * @param int    $backup_id  The new backup's ID.
		 * @param string $source_url The site it came from ('' if unknown).
		 */
		do_action( 'wpbp_direct_migration_received', $backup_id, $source_url );

		return new \WP_REST_Response( array( 'success' => true ), 200 );
	}

	/** Time for one bounded finalize step, inside PHP's own limit. */
	private static function step_deadline(): float {
		$max = (int) ini_get( 'max_execution_time' );

		return microtime( true ) + ( ( $max > 0 && $max < 20 ) ? max( 4, $max - 6 ) : 10 );
	}

	/**
	 * Check the received archive: the whole-file SHA-256 the sender sent,
	 * then (unless it is encrypted) its structure and every entry's
	 * checksum. Returns null once everything passed; a 400 answer (and the
	 * session discarded) when something didn't; or, with a $deadline that
	 * arrives first, a 202 answer with the progress kept in the session's
	 * metadata for the next request.
	 */
	private function check_archive( string $token, array $meta, string $checksum, string $session, ?float $deadline ): ?\WP_REST_Response {
		$state = is_array( $meta['finalize'] ?? null ) && ( $meta['finalize']['checksum'] ?? '' ) === $checksum ? $meta['finalize'] : array(
			'checksum' => $checksum,
			'stage'    => 'hash',
			'hash'     => array(),
			'cursor'   => array(),
		);

		if ( 'hash' === $state['stage'] ) {
			try {
				$actual = ArchiveChecksum::step( $session, $checksum, $state['hash'], $deadline );
			} catch ( \Throwable $e ) {
				$actual = '';
			}

			if ( null === $actual ) {
				return $this->still_checking( $token, $meta, $state, 0 );
			}

			if ( ! SliceHash::equals( $checksum, $actual ) ) {
				$this->discard_session( $token );
				return new \WP_REST_Response( array( 'message' => __( 'Archive checksum mismatch — the transfer may have been corrupted.', 'wrensafe' ) ), 400 );
			}

			$state['stage'] = 'verify';
			$state['hash']  = array();
		}

		if ( 'verify' === $state['stage'] && ! ( new BackupEncryption() )->is_encrypted( $session ) ) {
			$reader = new ArchiveReader( $session );
			$result = $reader->validate();

			if ( ! $result['valid'] ) {
				$this->discard_session( $token );
				return new \WP_REST_Response( array( 'message' => $result['message'] ), 400 );
			}

			$cursor    = (array) $state['cursor'];
			$integrity = ( new IntegrityChecker() )->verify_archive_contents_step( $reader, $result['manifest'], $cursor, $deadline );

			if ( null === $integrity ) {
				$state['cursor'] = $cursor;
				$in_files        = 'files' === (string) ( $cursor['stage'] ?? '' );
				$percent         = $in_files ? (int) ( 100 * (int) $cursor['file_index'] / max( 1, (int) ( $cursor['files_total'] ?? 0 ) ) ) : 0;

				return $this->still_checking( $token, $meta, $state, $percent );
			}

			if ( ! $integrity['valid'] ) {
				$this->discard_session( $token );
				return new \WP_REST_Response( array( 'message' => $integrity['message'] ), 400 );
			}
		}

		return null;
	}

	/**
	 * 202: the check continues in the sender's next finalize request.
	 *
	 * @param array<string,mixed> $state
	 */
	private function still_checking( string $token, array $meta, array $state, int $percent ): \WP_REST_Response {
		$meta['finalize']   = $state;
		$meta['expires_at'] = time() + self::SESSION_LIFETIME;
		$this->write_meta( $token, $meta );
		$this->acquire_lock( $token );

		return new \WP_REST_Response(
			array(
				'success'  => true,
				'checking' => true,
				'progress' => max( 0, min( 99, $percent ) ),
			),
			202
		);
	}

	/**
	 * Delete any session whose metadata has expired without finalizing —
	 * called from CronHandler's existing recurring tick rather than a
	 * dedicated cron event.
	 */
	public static function cleanup_abandoned_sessions(): void {
		$dir = FileSystem::uploads_dir();

		$meta_files = glob( $dir . 'migration-*.meta.json' );

		foreach ( is_array( $meta_files ) ? $meta_files : array() as $meta_path ) {
			$meta = json_decode( (string) file_get_contents( $meta_path ), true ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_read_file_get_contents

			if ( ! is_array( $meta ) || empty( $meta['expires_at'] ) || time() <= (int) $meta['expires_at'] ) {
				continue;
			}

			$part_path = substr( $meta_path, 0, -strlen( '.meta.json' ) ) . '.part';

			if ( file_exists( $part_path ) ) {
				wp_delete_file( $part_path );
			}
			wp_delete_file( $meta_path );
		}
	}
}

// phpcs:enable WordPress.WP.AlternativeFunctions
