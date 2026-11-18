<?php
/**
 * Destination-side REST API that receives a direct migration transfer.
 *
 * Authentication is the migration key itself (a one-time, expiring,
 * HMAC-signed token) rather than WordPress cookies/application passwords,
 * since the source site has no account on the destination.
 *
 * Each session is tracked by a JSON metadata sidecar (token hash, expiry,
 * declared size, and the byte offset/chunk index actually received so
 * far), which is what makes it possible to reject a chunk that's out of
 * order, duplicated, or larger than this server is willing to buffer —
 * none of which the per-chunk HMAC signature alone protects against,
 * since a signature only proves authenticity, not sequencing.
 *
 * @package WPBP\Migration
 */

namespace WPBP\Migration;

use WPBP\Backup\BackupEncryption;
use WPBP\Backup\IntegrityChecker;
use WPBP\Core\Database\BackupsRepository;
use WPBP\Core\Database\MigrationKeysRepository;
use WPBP\Core\Settings;
use WPBP\Restore\ArchiveReader;
use WPBP\Utils\FileSystem;
use WPBP\Utils\Logger;

defined( 'ABSPATH' ) || exit;

// Backup archives and upload chunks are streamed in bounded blocks with native
// file handles (fopen/fread/fwrite/rename): WP_Filesystem has no streaming API.
// Every path is inside Wrensafe's protected storage directory, a PHP upload
// temp file, or validated by WPBP\Security\PathValidator.
// phpcs:disable WordPress.WP.AlternativeFunctions

class TransferServer {

	private const SESSION_LIFETIME  = 7200; // 2 hours.
	private const MAX_TOTAL_BYTES   = 20 * 1024 * 1024 * 1024; // 20GB.
	private const RATE_LIMIT_WINDOW = 60;
	private const RATE_LIMIT_MAX    = 30; // check/init requests per minute per IP. Chunks of a session with a valid key aren't counted.
	private const THROTTLE_WINDOW   = 900; // 15 minutes.
	private const THROTTLE_MAX      = 5; // failed key verifications per IP.
	private const LOCK_TRANSIENT    = 'wpbp_migration_lock';

	public function register_hooks(): void {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	public function register_routes(): void {
		register_rest_route(
			'wpbp/v1',
			'/migration/check',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'handle_check' ),
				'permission_callback' => '__return_true',
			)
		);

		register_rest_route(
			'wpbp/v1',
			'/migration/init',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'handle_init' ),
				'permission_callback' => '__return_true',
			)
		);

		register_rest_route(
			'wpbp/v1',
			'/migration/chunk',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'handle_chunk' ),
				'permission_callback' => '__return_true',
			)
		);

		register_rest_route(
			'wpbp/v1',
			'/migration/finalize',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'handle_finalize' ),
				'permission_callback' => '__return_true',
			)
		);
	}

	private function session_path( string $token ): string {
		return FileSystem::uploads_dir() . 'migration-' . hash( 'sha256', $token ) . '.part';
	}

	private function meta_path( string $token ): string {
		return FileSystem::uploads_dir() . 'migration-' . hash( 'sha256', $token ) . '.meta.json';
	}

	private function remote_ip(): string {
		return isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
	}

	private function max_chunk_bytes(): int {
		return max( 1, (int) Settings::get( 'migration_chunk_size_mb', 2 ) ) * 1024 * 1024;
	}

	private function verify_key( \WP_REST_Request $request ): ?array {
		$token = (string) $request->get_param( 'token' );

		return ( new MigrationKey() )->verify( $token, $this->remote_ip() );
	}

	// -- Rate limiting & failed-attempt throttling (per source IP) ----------
	//
	// Fixed windows: a window starts with its first request and ends
	// $window seconds later, however many requests arrive meanwhile (an
	// earlier version renewed the window on every request, so retries kept
	// a sender locked out). Only "check" and "init" count towards the
	// request limit; the chunks of a session whose key verifies don't (a fast
	// link sends far more than 30 a minute). Failed key checks are limited
	// on every endpoint.

	/** @return array{count:int,reset:int} */
	private function window( string $name, string $ip ): array {
		$value = get_transient( 'wpbp_migration_' . $name . '_' . md5( $ip ) );

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

	private function hit( string $name, string $ip, int $length ): void {
		$window = $this->window( $name, $ip );
		$reset  = $window['reset'] > 0 ? $window['reset'] : time() + $length;

		set_transient(
			'wpbp_migration_' . $name . '_' . md5( $ip ),
			array(
				'count' => $window['count'] + 1,
				'reset' => $reset,
			),
			max( 1, $reset - time() )
		);
	}

	private function is_rate_limited( string $ip ): bool {
		return $this->window( 'rate', $ip )['count'] >= self::RATE_LIMIT_MAX;
	}

	private function record_request( string $ip ): void {
		$this->hit( 'rate', $ip, self::RATE_LIMIT_WINDOW );
	}

	private function is_throttled( string $ip ): bool {
		return $this->window( 'throttle', $ip )['count'] >= self::THROTTLE_MAX;
	}

	private function record_failed_attempt( string $ip ): void {
		$this->hit( 'throttle', $ip, self::THROTTLE_WINDOW );
	}

	private function clear_throttle( string $ip ): void {
		delete_transient( 'wpbp_migration_throttle_' . md5( $ip ) );
	}

	/**
	 * 429 with Retry-After (seconds until the window ends), so the sending
	 * site waits and continues instead of failing.
	 */
	private function too_many_requests( string $ip ): \WP_REST_Response {
		$reset = max( $this->window( 'rate', $ip )['reset'], $this->is_throttled( $ip ) ? $this->window( 'throttle', $ip )['reset'] : 0 );
		$wait  = max( 1, $reset - time() );

		$minutes = (int) ceil( $wait / 60 );
		$when    = $wait < 120
			/* translators: %d: number of seconds */
			? sprintf( _n( '%d second', '%d seconds', $wait, 'wrensafe' ), $wait )
			/* translators: %d: number of minutes */
			: sprintf( _n( '%d minute', '%d minutes', $minutes, 'wrensafe' ), $minutes );
		$response = new \WP_REST_Response(
			array(
				'message'     => sprintf(
					/* translators: 1: site address, 2: time to wait, e.g. "15 minutes" */
					__( 'The receiving site (%1$s) is limiting requests from this server, for example after several wrong migration keys. Try again in %2$s.', 'wrensafe' ),
					home_url(),
					$when
				),
				'retry_after' => $wait,
			),
			429
		);
		$response->header( 'Retry-After', (string) $wait );

		return $response;
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
	 */
	public function handle_check( \WP_REST_Request $request ) {
		$ip = $this->remote_ip();

		if ( $this->is_rate_limited( $ip ) || $this->is_throttled( $ip ) ) {
			return $this->too_many_requests( $ip );
		}

		$this->record_request( $ip );

		if ( null === $this->verify_key( $request ) ) {
			$this->record_failed_attempt( $ip );
			/* translators: %s: site address */
			return new \WP_REST_Response( array( 'message' => sprintf( __( 'The receiving site (%s) didn’t accept the migration key: it is wrong, expired or already used. Generate a new key on that site.', 'wrensafe' ), home_url() ) ), 403 );
		}

		$this->clear_throttle( $ip );

		return new \WP_REST_Response(
			array(
				'success'        => true,
				'version'        => WPBP_VERSION,
				'site_url'       => home_url(),
				'https'          => is_ssl(),
				'require_https'  => (bool) Settings::get( 'migration_require_https', true ),
				'accepts'        => $this->accepts_this_connection(),
				'max_chunk_size' => $this->max_chunk_bytes(),
			),
			200
		);
	}

	public function handle_init( \WP_REST_Request $request ) {
		$ip = $this->remote_ip();

		if ( $this->is_rate_limited( $ip ) || $this->is_throttled( $ip ) ) {
			return $this->too_many_requests( $ip );
		}

		$this->record_request( $ip );

		$https_error = $this->require_https_error();
		if ( null !== $https_error ) {
			return $https_error;
		}

		$key = $this->verify_key( $request );

		if ( null === $key ) {
			$this->record_failed_attempt( $ip );
			return new \WP_REST_Response( array( 'message' => __( 'Invalid, expired, or already used migration key.', 'wrensafe' ) ), 403 );
		}

		$this->clear_throttle( $ip );

		$token = (string) $request->get_param( 'token' );

		if ( ! $this->acquire_lock( $token ) ) {
			return new \WP_REST_Response( array( 'message' => __( 'Another migration is currently in progress on this site. Please try again once it finishes.', 'wrensafe' ) ), 409 );
		}

		$filename   = sanitize_file_name( (string) $request->get_param( 'filename' ) );
		$total_size = (int) $request->get_param( 'total_size' );

		if ( '' === $filename || ! str_ends_with( strtolower( $filename ), '.wbp' ) || $total_size <= 0 || $total_size > self::MAX_TOTAL_BYTES ) {
			$this->release_lock( $token );
			return new \WP_REST_Response( array( 'message' => __( 'Invalid filename or declared size.', 'wrensafe' ) ), 400 );
		}

		// The same key starting again for the same backup: continue the
		// unfinished session where it stopped instead of starting over.
		$existing = $this->read_meta( $token );
		$part     = $this->session_path( $token );

		// Only for senders that continue from the returned position (1.1.3+
		// send resume=1); an older sender always starts at the first chunk.
		if ( '1' === (string) $request->get_param( 'resume' ) && null !== $existing && (string) $existing['filename'] === $filename && (int) $existing['total_size'] === $total_size && file_exists( $part ) ) {
			clearstatcache( true, $part );

			if ( (int) filesize( $part ) === (int) $existing['received_bytes'] ) {
				Logger::info( 'Incoming direct migration resumed at ' . size_format( (int) $existing['received_bytes'] ) . '.' );

				return new \WP_REST_Response(
					array(
						'success'          => true,
						'max_chunk_size'   => $this->max_chunk_bytes(),
						'received_bytes'   => (int) $existing['received_bytes'],
						'next_chunk_index' => (int) $existing['next_chunk_index'],
					),
					200
				);
			}
		}

		FileSystem::ensure_protected_directory( FileSystem::uploads_dir() );
		file_put_contents( $part, '' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents

		$this->write_meta(
			$token,
			array(
				'token_hash'       => hash( 'sha256', $token ),
				'created_at'       => time(),
				'expires_at'       => time() + self::SESSION_LIFETIME,
				'filename'         => $filename,
				'total_size'       => $total_size,
				'backup_type'      => sanitize_key( (string) $request->get_param( 'backup_type' ) ),
				'source_url'       => esc_url_raw( (string) $request->get_param( 'source_url' ) ),
				'expected_chunks'  => (int) ceil( $total_size / $this->max_chunk_bytes() ),
				'received_chunks'  => 0,
				'next_chunk_index' => 0,
				'received_bytes'   => 0,
				'remote_ip'        => $ip,
			)
		);

		Logger::info( 'Incoming direct migration started.' );

		return new \WP_REST_Response(
			array(
				'success'          => true,
				'max_chunk_size'   => $this->max_chunk_bytes(),
				'received_bytes'   => 0,
				'next_chunk_index' => 0,
			),
			200
		);
	}

	public function handle_chunk( \WP_REST_Request $request ) {
		$ip = $this->remote_ip();

		// Only failed key checks are limited here (see the rate-limit note).
		if ( $this->is_throttled( $ip ) ) {
			return $this->too_many_requests( $ip );
		}

		$key = $this->verify_key( $request );

		if ( null === $key ) {
			$this->record_failed_attempt( $ip );
			return new \WP_REST_Response( array( 'message' => __( 'Invalid, expired, or already used migration key.', 'wrensafe' ) ), 403 );
		}

		$token = (string) $request->get_param( 'token' );
		$meta  = $this->read_meta( $token );

		if ( null === $meta ) {
			return new \WP_REST_Response( array( 'message' => __( 'Migration session not found or expired. Please restart the migration.', 'wrensafe' ) ), 410 );
		}

		$chunk_index = (int) $request->get_param( 'chunk_index' );
		$signature   = (string) $request->get_param( 'signature' );
		$chunk       = base64_decode( (string) $request->get_param( 'chunk' ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode
		$chunk_len   = strlen( $chunk );

		if ( $chunk_len <= 0 || $chunk_len > $this->max_chunk_bytes() ) {
			return new \WP_REST_Response( array( 'message' => __( 'Chunk exceeds the maximum allowed size.', 'wrensafe' ) ), 400 );
		}

		if ( $chunk_index !== (int) $meta['next_chunk_index'] ) {
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

		$handle = fopen( $this->session_path( $token ), 'ab' );
		fwrite( $handle, $chunk );
		fclose( $handle );

		$meta['received_bytes']   = (int) $meta['received_bytes'] + $chunk_len;
		$meta['received_chunks']  = (int) $meta['received_chunks'] + 1;
		$meta['next_chunk_index'] = $chunk_index + 1;
		$this->write_meta( $token, $meta );

		return new \WP_REST_Response(
			array(
				'success'        => true,
				'received_bytes' => $meta['received_bytes'],
			),
			200
		);
	}

	public function handle_finalize( \WP_REST_Request $request ) {
		$ip = $this->remote_ip();

		if ( $this->is_throttled( $ip ) ) {
			return $this->too_many_requests( $ip );
		}

		$key = $this->verify_key( $request );

		if ( null === $key ) {
			$this->record_failed_attempt( $ip );
			return new \WP_REST_Response( array( 'message' => __( 'Invalid, expired, or already used migration key.', 'wrensafe' ) ), 403 );
		}

		$token = (string) $request->get_param( 'token' );
		$meta  = $this->read_meta( $token );

		if ( null === $meta ) {
			return new \WP_REST_Response( array( 'message' => __( 'Migration session not found or expired. Please restart the migration.', 'wrensafe' ) ), 410 );
		}

		if ( (int) $meta['received_bytes'] !== (int) $meta['total_size'] ) {
			return new \WP_REST_Response( array( 'message' => __( 'Transfer incomplete — received and declared sizes do not match.', 'wrensafe' ) ), 400 );
		}

		$checksum = (string) $request->get_param( 'checksum' );
		$session  = $this->session_path( $token );

		if ( ! hash_equals( $checksum, (string) hash_file( 'sha256', $session ) ) ) {
			$this->discard_session( $token );
			return new \WP_REST_Response( array( 'message' => __( 'Archive checksum mismatch — the transfer may have been corrupted.', 'wrensafe' ) ), 400 );
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
			$reader = new ArchiveReader( $session );
			$result = $reader->validate();

			if ( ! $result['valid'] ) {
				$this->discard_session( $token );
				return new \WP_REST_Response( array( 'message' => $result['message'] ), 400 );
			}

			$integrity = ( new IntegrityChecker() )->verify_archive_contents( $reader, $result['manifest'] );

			if ( ! $integrity['valid'] ) {
				$this->discard_session( $token );
				return new \WP_REST_Response( array( 'message' => $integrity['message'] ), 400 );
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

		if ( '' === $file_name || ! str_ends_with( strtolower( $file_name ), '.wbp' ) || file_exists( FileSystem::backups_dir() . $file_name ) ) {
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
