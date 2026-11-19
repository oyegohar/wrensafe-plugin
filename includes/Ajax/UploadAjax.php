<?php
/**
 * Chunked upload endpoints for importing a backup from the browser.
 *
 * The browser slices the file into chunks (see admin.js), uploads them
 * sequentially, and can resume after a dropped connection by asking
 * "status" how many bytes the server already has.
 *
 * An upload either becomes a .wbp restore job, or — with a set_id, or for a
 * .wpress file — joins an import set of another plugin's backup files,
 * which ForeignImportAjax analyses and converts.
 *
 * @package WPBP\Ajax
 */

namespace WPBP\Ajax;

use WPBP\Import\Foreign\ImportSet;
use WPBP\Restore\ArchiveReader;
use WPBP\Restore\RestoreJob;
use WPBP\Security\CapabilityManager;
use WPBP\Utils\ChunkSizeCalculator;
use WPBP\Utils\Errors;
use WPBP\Utils\FileSystem;
use WPBP\Utils\Logger;

defined( 'ABSPATH' ) || exit;

// Backup archives and upload chunks are streamed in bounded blocks with native
// file handles (fopen/fread/fwrite/rename): WP_Filesystem has no streaming API.
// Every path is inside Wrensafe's protected storage directory, a PHP upload
// temp file, or validated by WPBP\Security\PathValidator.
// phpcs:disable WordPress.WP.AlternativeFunctions

class UploadAjax {

	private const TOKEN_PATTERN    = '/^[a-f0-9]{32}$/';
	private const MAX_UPLOAD_BYTES = 5368709120; // 5GB.
	private const SESSION_LIFETIME = 7200; // 2 hours.

	/**
	 * Server-aware ceiling for a single chunk — scales with memory_limit
	 * rather than a fixed constant, so a chunk this server genuinely can't
	 * buffer safely is rejected, while a beefier server can accept bigger
	 * chunks than the 1MB client default.
	 */
	private static function max_chunk_bytes(): int {
		// A chunk is one multipart POST, so it must also fit PHP's
		// upload_max_filesize and post_max_size (wp_max_upload_size() is the
		// smaller of the two), with room for the other form fields.
		$post_limit = (int) ( wp_max_upload_size() * 0.9 );

		return max( 262144, $post_limit > 0 ? min( ChunkSizeCalculator::bytes_per_chunk(), $post_limit ) : ChunkSizeCalculator::bytes_per_chunk() );
	}

	private static function require_capability(): void {
		if ( ! CapabilityManager::current_user_can( CapabilityManager::RESTORE_BACKUPS ) ) {
			wp_send_json_error( array( 'message' => __( 'You do not have permission to import backups.', 'wrensafe' ) ), 403 );
		}
	}

	private static function meta_path( string $upload_id ): string {
		return FileSystem::uploads_dir() . $upload_id . '.meta.json';
	}

	private static function part_path( string $upload_id ): string {
		return FileSystem::uploads_dir() . $upload_id . '.part';
	}

	private static function discard_session( string $upload_id ): void {
		$part = self::part_path( $upload_id );
		$meta = self::meta_path( $upload_id );

		if ( file_exists( $part ) ) {
			wp_delete_file( $part );
		}
		if ( file_exists( $meta ) ) {
			wp_delete_file( $meta );
		}
	}

	private static function read_meta_or_die( string $upload_id ): array {
		if ( ! preg_match( self::TOKEN_PATTERN, $upload_id ) ) {
			wp_send_json_error( array( 'message' => __( 'Invalid upload session.', 'wrensafe' ) ), 400 );
		}

		$path = self::meta_path( $upload_id );

		if ( ! file_exists( $path ) ) {
			wp_send_json_error( array( 'message' => __( 'Upload session not found. Please start again.', 'wrensafe' ) ), 404 );
		}

		$meta = json_decode( (string) file_get_contents( $path ), true );
		$meta = is_array( $meta ) ? $meta : array();

		if ( empty( $meta['expires_at'] ) || time() > (int) $meta['expires_at'] ) {
			self::discard_session( $upload_id );
			wp_send_json_error( array( 'message' => __( 'Upload session expired. Please start again.', 'wrensafe' ) ), 410 );
		}

		return $meta;
	}

	private static function write_meta( string $upload_id, array $meta ): void {
		file_put_contents( self::meta_path( $upload_id ), wp_json_encode( $meta ) );
	}

	public static function init(): void {
		check_ajax_referer( 'wpbp_admin_ajax', '_wpbp_nonce' );
		self::require_capability();

		$set_id     = isset( $_POST['set_id'] ) ? sanitize_key( wp_unslash( $_POST['set_id'] ) ) : '';
		$set_id     = 'new' === $set_id ? ImportSet::new_id() : $set_id; // The first file of a new import set.
		$filename   = isset( $_POST['filename'] ) ? ImportSet::clean_name( sanitize_text_field( wp_unslash( $_POST['filename'] ) ) ) : '';
		$total_size = isset( $_POST['total_size'] ) ? absint( $_POST['total_size'] ) : 0;

		$extension = strtolower( pathinfo( $filename, PATHINFO_EXTENSION ) );

		if ( '' !== $set_id ) {
			// One file of another plugin's backup (Import / Restore → Other backup plugins).
			if ( ! ImportSet::valid_id( $set_id ) ) {
				wp_send_json_error( array( 'message' => __( 'Invalid upload session.', 'wrensafe' ) ), 400 );
			}

			if ( '' === $filename || ! ImportSet::allowed( $filename ) ) {
				wp_send_json_error(
					array(
						/* translators: %s: list of file extensions */
						'message' => sprintf( __( 'This file type is not a backup Wrensafe can read. Supported: %s.', 'wrensafe' ), '.' . implode( ', .', ImportSet::EXTENSIONS ) ),
					),
					400
				);
			}
		} elseif ( '' === $filename || ! in_array( $extension, \WPBP\Import\ImportAdapterManager::allowed_extensions(), true ) ) {
			wp_send_json_error( array( 'message' => __( 'Only .wbp and .wpress files can be imported.', 'wrensafe' ) ), 400 );
		}

		if ( $total_size <= 0 || $total_size > self::MAX_UPLOAD_BYTES ) {
			wp_send_json_error( array( 'message' => __( 'Invalid or oversized file.', 'wrensafe' ) ), 400 );
		}

		FileSystem::ensure_protected_directory( FileSystem::uploads_dir() );

		self::sweep_stale_imports();

		// Say so now, not after a 2 GB upload fails at 97 %.
		$disk_free = function_exists( 'disk_free_space' ) ? @disk_free_space( FileSystem::uploads_dir() ) : false; // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged

		if ( false !== $disk_free && $disk_free < $total_size + 52428800 ) {
			wp_send_json_error(
				array(
					'message' => sprintf(
						/* translators: 1: file size, 2: free disk space */
						__( 'Not enough free disk space for this upload: the file is %1$s but the server has %2$s free. Free up space and try again.', 'wrensafe' ),
						size_format( $total_size ),
						size_format( (int) $disk_free )
					),
				),
				400
			);
		}

		$upload_id = bin2hex( random_bytes( 16 ) );
		file_put_contents( self::part_path( $upload_id ), '' );

		self::write_meta(
			$upload_id,
			array(
				'filename'       => $filename,
				'total_size'     => $total_size,
				'received_bytes' => 0,
				'expires_at'     => time() + self::SESSION_LIFETIME,
				'set_id'         => $set_id,
			)
		);

		Logger::info( 'Import upload started: ' . $filename . ' (' . size_format( $total_size ) . ').' );

		wp_send_json_success(
			array(
				'upload_id'      => $upload_id,
				'max_chunk_size' => self::max_chunk_bytes(),
				'set_id'         => $set_id,
			)
		);
	}

	public static function status(): void {
		check_ajax_referer( 'wpbp_admin_ajax', '_wpbp_nonce' );
		self::require_capability();

		$upload_id = isset( $_POST['upload_id'] ) ? sanitize_text_field( wp_unslash( $_POST['upload_id'] ) ) : '';
		$meta      = self::read_meta_or_die( $upload_id );

		wp_send_json_success( array( 'received_bytes' => $meta['received_bytes'] ?? 0 ) );
	}

	/**
	 * Remove import files (finished archives and partial uploads) that are
	 * more than a day old: abandoned uploads, previews never restored, and
	 * failed restores. Restored imports are deleted right away by the restore.
	 */
	private static function sweep_stale_imports(): void {
		$files = glob( FileSystem::uploads_dir() . '*' ); // No GLOB_BRACE: unavailable on some (musl) builds.

		foreach ( is_array( $files ) ? $files : array() as $file ) {
			if ( is_file( $file ) && preg_match( '/\.(wbp|wpress|part|json|lock)$/', $file ) && filemtime( $file ) < time() - DAY_IN_SECONDS ) {
				wp_delete_file( $file );
			} elseif ( is_dir( $file ) && preg_match( '/import-set-([a-f0-9]{32})$/', $file, $m ) && filemtime( $file ) < time() - DAY_IN_SECONDS ) {
				ImportSet::delete( $m[1] ); // Other-plugin backup files never converted.
			}
		}
	}

	/**
	 * The user cancelled an upload: delete the partial file right away
	 * instead of leaving gigabytes behind until the session expires.
	 */
	public static function cancel(): void {
		check_ajax_referer( 'wpbp_admin_ajax', '_wpbp_nonce' );
		self::require_capability();

		$upload_id = isset( $_POST['upload_id'] ) ? sanitize_text_field( wp_unslash( $_POST['upload_id'] ) ) : '';

		if ( preg_match( self::TOKEN_PATTERN, $upload_id ) ) {
			self::discard_session( $upload_id );
			Logger::info( 'Import upload cancelled by the user; partial file removed.' );
		}

		wp_send_json_success();
	}

	public static function chunk(): void {
		check_ajax_referer( 'wpbp_admin_ajax', '_wpbp_nonce' );
		self::require_capability();

		$upload_id = isset( $_POST['upload_id'] ) ? sanitize_text_field( wp_unslash( $_POST['upload_id'] ) ) : '';
		$meta      = self::read_meta_or_die( $upload_id );

		// tmp_name is a path PHP generated for this request; it is only used
		// after is_uploaded_file() confirms PHP itself received this upload.
		$chunk_tmp   = isset( $_FILES['chunk']['tmp_name'] ) && is_string( $_FILES['chunk']['tmp_name'] ) ? sanitize_text_field( $_FILES['chunk']['tmp_name'] ) : '';
		$chunk_error = isset( $_FILES['chunk']['error'] ) && is_scalar( $_FILES['chunk']['error'] ) ? (int) $_FILES['chunk']['error'] : UPLOAD_ERR_NO_FILE;
		$chunk_size  = isset( $_FILES['chunk']['size'] ) && is_scalar( $_FILES['chunk']['size'] ) ? absint( $_FILES['chunk']['size'] ) : 0;

		if ( UPLOAD_ERR_OK !== $chunk_error || '' === $chunk_tmp || ! is_uploaded_file( $chunk_tmp ) ) {
			wp_send_json_error( array( 'message' => __( 'Chunk upload failed.', 'wrensafe' ) ), 400 );
		}

		if ( $chunk_size <= 0 || $chunk_size > self::max_chunk_bytes() ) {
			wp_send_json_error( array( 'message' => __( 'Chunk exceeds the maximum allowed size.', 'wrensafe' ) ), 400 );
		}

		// The chunk's declared offset must exactly match how many bytes the
		// server has already appended — rejects duplicate, dropped, or
		// reordered chunks instead of silently corrupting the assembled file.
		$expected_offset = (int) $meta['received_bytes'];
		$offset          = isset( $_POST['offset'] ) ? absint( $_POST['offset'] ) : -1;

		if ( $offset !== $expected_offset ) {
			wp_send_json_error( array( 'message' => __( 'Chunk out of order. Please restart the upload.', 'wrensafe' ) ), 409 );
		}

		if ( $expected_offset + $chunk_size > (int) $meta['total_size'] ) {
			wp_send_json_error( array( 'message' => __( 'Upload exceeds the declared file size.', 'wrensafe' ) ), 400 );
		}

		// The browser sends a per-chunk SHA-256 only when Web Crypto is
		// available (a secure context: HTTPS or localhost). On a plain-HTTP
		// local site SubtleCrypto is absent, so the checksum is omitted — that
		// is fine: the whole assembled archive is fully validated at finalize()
		// (structure + per-entry checksums), so corruption is still caught.
		$expected_checksum = isset( $_POST['chunk_checksum'] ) ? sanitize_text_field( wp_unslash( $_POST['chunk_checksum'] ) ) : '';

		if ( '' !== $expected_checksum ) {
			$actual_checksum = (string) hash_file( 'sha256', $chunk_tmp );

			if ( ! hash_equals( $expected_checksum, $actual_checksum ) ) {
				Logger::error(
					sprintf(
						'import_upload_failed: chunk checksum mismatch for %s at offset %d (user #%d).',
						$meta['filename'] ?? '(unknown)',
						$expected_offset,
						get_current_user_id()
					)
				);
				wp_send_json_error( array( 'message' => __( 'Upload failed: chunk checksum mismatch.', 'wrensafe' ) ), 400 );
			}
		}

		$in  = fopen( $chunk_tmp, 'rb' );
		$out = fopen( self::part_path( $upload_id ), 'ab' );

		if ( $in && $out ) {
			stream_copy_to_stream( $in, $out );
		}
		if ( $in ) {
			fclose( $in );
		}
		if ( $out ) {
			fclose( $out );
		}

		$meta['received_bytes'] = $expected_offset + $chunk_size;
		self::write_meta( $upload_id, $meta );

		wp_send_json_success( array( 'received_bytes' => $meta['received_bytes'] ) );
	}

	public static function finalize(): void {
		check_ajax_referer( 'wpbp_admin_ajax', '_wpbp_nonce' );
		self::require_capability();

		$upload_id = isset( $_POST['upload_id'] ) ? sanitize_text_field( wp_unslash( $_POST['upload_id'] ) ) : '';
		$meta      = self::read_meta_or_die( $upload_id );
		$part_path = self::part_path( $upload_id );

		$filename = $meta['filename'] ?? '(unknown)';
		$user     = get_current_user_id();

		Logger::info( 'upload_finalize_started: assembling and validating ' . $filename . ' (user #' . $user . ').' );

		// 1) Every chunk must have arrived.
		if ( (int) $meta['received_bytes'] !== (int) $meta['total_size'] ) {
			Logger::failed( 'upload_finalize_failed: incomplete upload of ' . $filename . ' — received ' . size_format( (int) $meta['received_bytes'] ) . ' of ' . size_format( (int) $meta['total_size'] ) . ' (user #' . $user . ').' );
			wp_send_json_error( array( 'message' => __( 'Upload failed during final assembly: not all of the file was received. Please try uploading again.', 'wrensafe' ) ), 400 );
		}

		// 2) The assembled file must actually be on disk and readable — the
		// server's own temp GC (or an open_basedir/permission issue) can remove
		// or lock it between the last chunk and finalize.
		clearstatcache( true, $part_path );

		if ( ! file_exists( $part_path ) || ! is_readable( $part_path ) ) {
			self::discard_session( $upload_id );
			Logger::failed( 'upload_finalize_failed: assembled file for ' . $filename . ' is missing or not readable (user #' . $user . ').' );
			wp_send_json_error( array( 'message' => __( 'Upload failed: the assembled file is missing or not readable on the server.', 'wrensafe' ) ), 500 );
		}

		if ( (int) filesize( $part_path ) !== (int) $meta['total_size'] ) {
			self::discard_session( $upload_id );
			Logger::failed( 'upload_finalize_failed: assembled size mismatch for ' . $filename . ' (' . size_format( (int) filesize( $part_path ) ) . ' on disk vs ' . size_format( (int) $meta['total_size'] ) . ' expected).' );
			wp_send_json_error( array( 'message' => __( 'Upload failed during final assembly: the assembled file size does not match. Please try again.', 'wrensafe' ) ), 400 );
		}

		Logger::info( 'upload_file_assembled: ' . $filename . ' assembled (' . size_format( (int) filesize( $part_path ) ) . ').' );

		// One file of another plugin's backup: it joins its import set.
		if ( ! empty( $meta['set_id'] ) ) {
			self::finalize_into_set( (string) $meta['set_id'], $upload_id, $part_path, (string) $filename );
			return;
		}

		// A .wpress dropped on the .wbp uploader: start an import set with
		// it, and the browser carries on in the "Other backup plugins" flow.
		$adapter = \WPBP\Import\ImportAdapterManager::detect( $part_path, (string) $filename );

		if ( null !== $adapter && 'wbp' !== $adapter->key() ) {
			self::finalize_into_set( ImportSet::new_id(), $upload_id, $part_path, (string) $filename );
			return;
		}

		// 3) Structure validation only (fast). The full per-entry checksum of a
		// large multi-thousand-file archive is deferred to the restore preflight
		// (RestoreManager::step_init) and the explicit Verify button — running it
		// here previously reopened the ZIP once per entry and timed the finalize
		// out at 100% on big WooCommerce backups. Structure validation confirms
		// the archive opens and carries a valid Wrensafe manifest/metadata,
		// which is what the preview needs.
		Logger::info( 'upload_archive_validation_started: ' . $filename . '.' );

		$reader = new ArchiveReader( $part_path );
		$result = $reader->validate();

		// An encrypted .wbp can't be inspected without its password. Keep the
		// upload and create its restore job, then ask the browser for the
		// password (upload_unlock) — previously the file was discarded here,
		// so an encrypted backup could never be imported onto another site.
		if ( ! $result['valid'] && ! empty( $result['requires_password'] ) ) {
			$final_path = FileSystem::uploads_dir() . $upload_id . '.wbp';

			if ( ! rename( $part_path, $final_path ) ) {
				self::discard_session( $upload_id );
				Logger::failed( 'upload_finalize_failed: could not move the assembled file into the import directory for ' . $filename . ' (check permissions).' );
				wp_send_json_error( array( 'message' => __( 'Upload failed: the server could not move the finished file into place (check file permissions).', 'wrensafe' ) ), 500 );
			}

			wp_delete_file( self::meta_path( $upload_id ) );

			$job = RestoreJob::create(
				array(
					'source'        => 'upload',
					'uploaded_file' => $final_path,
					'encrypted'     => true,
				)
			);

			Logger::info( 'upload_completed: ' . $filename . ' is encrypted — waiting for its password before preview (job #' . $job->id() . ', user #' . $user . ').' );

			wp_send_json_error(
				array(
					'message'           => __( 'This backup is encrypted. Enter its password to continue.', 'wrensafe' ),
					'requires_password' => true,
					'job_id'            => $job->id(),
				)
			);
		}

		if ( ! $result['valid'] ) {
			self::discard_session( $upload_id );

			Logger::failed( 'upload_archive_validation_failed: ' . $filename . ' — ' . $result['message'] . ' (user #' . $user . ').' );
			/* translators: %s: why the uploaded file is not a usable backup */
			wp_send_json_error( array( 'message' => sprintf( __( 'The upload finished, but the file isn’t a usable backup: %s', 'wrensafe' ), $result['message'] ) ) );
		}

		// Structural completeness: the entries the preview and restore rely on
		// must be present as valid JSON (fast reads, not per-entry hashing).
		$missing_entry = self::first_missing_structure_entry( $reader, $result['manifest'] );

		if ( null !== $missing_entry ) {
			self::discard_session( $upload_id );
			Logger::failed( 'upload_archive_validation_failed: ' . $filename . ' — ' . $missing_entry . ' (user #' . $user . ').' );
			/* translators: %s: what is missing from the backup */
			wp_send_json_error( array( 'message' => sprintf( __( 'The file isn’t a usable backup: %s', 'wrensafe' ), $missing_entry ) ) );
		}

		$changes = $reader->get_change_summary();

		$final_path = FileSystem::uploads_dir() . $upload_id . '.wbp';

		if ( ! rename( $part_path, $final_path ) ) {
			self::discard_session( $upload_id );
			Logger::failed( 'upload_finalize_failed: could not move the assembled file into the import directory for ' . $filename . ' (check permissions).' );
			wp_send_json_error( array( 'message' => __( 'Upload failed: the server could not move the finished file into place (check file permissions).', 'wrensafe' ) ), 500 );
		}

		wp_delete_file( self::meta_path( $upload_id ) );

		$job = RestoreJob::create(
			array(
				'source'        => 'upload',
				'uploaded_file' => $final_path,
				'manifest'      => $result['manifest'],
				'metadata'      => $result['metadata'],
			)
		);

		Logger::success( 'upload_completed: ' . $filename . ' — ready to preview/restore (job #' . $job->id() . ').' );

		global $wpdb;

		wp_send_json_success(
			array(
				'job_id'             => $job->id(),
				'manifest'           => $result['manifest'],
				'metadata'           => $result['metadata'],
				'changes'            => $changes,
				'site_url'           => untrailingslashit( home_url() ),
				'destination_prefix' => $wpdb->prefix,
				'destination_wp'     => get_bloginfo( 'version' ),
				'destination_php'    => PHP_VERSION,
			)
		);
	}

	/**
	 * Unlock an uploaded ENCRYPTED archive for preview: verify the password by
	 * decrypting it (to a temp file that ArchiveReader deletes again) and
	 * return the same preview data as finalize(). The password is never
	 * stored; the browser sends it again with the restore steps.
	 */
	public static function unlock(): void {
		check_ajax_referer( 'wpbp_admin_ajax', '_wpbp_nonce' );
		self::require_capability();

		$job_id = isset( $_POST['job_id'] ) ? absint( $_POST['job_id'] ) : 0;

		// The password is used exactly as typed: it only feeds the key
		// derivation (PBKDF2) and is never stored, logged, output or used in
		// a query. Any other filter would change some passwords and lock the
		// owner out of their backup; FILTER_UNSAFE_RAW only rejects non-strings.
		$password = isset( $_POST['encryption_password'] ) ? (string) filter_var( wp_unslash( $_POST['encryption_password'] ), FILTER_UNSAFE_RAW ) : '';
		$job      = $job_id ? RestoreJob::get( $job_id ) : null;
		$payload  = $job ? $job->payload() : array();
		$path     = (string) ( $payload['uploaded_file'] ?? '' );

		if ( null === $job || RestoreJob::STATUS_PENDING !== $job->status() || empty( $payload['encrypted'] ) || '' === $path || ! file_exists( $path ) ) {
			wp_send_json_error( array( 'message' => __( 'Upload session not found. Please upload the backup again.', 'wrensafe' ) ), 404 );
		}

		if ( '' === $password ) {
			wp_send_json_error(
				array(
					'message'           => __( 'Please enter the backup password.', 'wrensafe' ),
					'requires_password' => true,
				)
			);
		}

		$reader = ( new ArchiveReader( $path, $password ) )->preview_only();
		$result = $reader->validate();

		if ( ! $result['valid'] ) {
			Logger::warning( 'upload_unlock_failed: job #' . $job_id . ' — ' . $result['message'] );
			wp_send_json_error(
				array(
					'message'           => $result['message'],
					'requires_password' => ! empty( $result['requires_password'] ),
				)
			);
		}

		$missing_entry = self::first_missing_structure_entry( $reader, $result['manifest'] );

		if ( null !== $missing_entry ) {
			Logger::failed( 'upload_archive_validation_failed: job #' . $job_id . ' — ' . $missing_entry );
			/* translators: %s: what is missing from the backup */
			wp_send_json_error( array( 'message' => sprintf( __( 'The file isn’t a usable backup: %s', 'wrensafe' ), $missing_entry ) ) );
		}

		$payload['manifest'] = $result['manifest'];
		$payload['metadata'] = $result['metadata'];
		$job->set_payload( $payload );

		Logger::info( 'upload_unlocked: encrypted upload for job #' . $job_id . ' unlocked for preview.' );

		global $wpdb;

		wp_send_json_success(
			array(
				'job_id'             => $job_id,
				'manifest'           => $result['manifest'],
				'metadata'           => $result['metadata'],
				'changes'            => $reader->get_change_summary(),
				'site_url'           => untrailingslashit( home_url() ),
				'destination_prefix' => $wpdb->prefix,
				'destination_wp'     => get_bloginfo( 'version' ),
				'destination_php'    => PHP_VERSION,
			)
		);
	}

	/**
	 * Confirm the structural JSON entries the preview/restore depend on are
	 * present and valid (fast reads — no per-entry hashing). Returns null when
	 * everything is present, or a precise, user-facing reason for the first
	 * missing/invalid entry.
	 */
	private static function first_missing_structure_entry( ArchiveReader $reader, array $manifest ): ?string {
		if ( null === $reader->read_json( 'metadata.json' ) ) {
			return __( 'metadata.json was not found inside the archive.', 'wrensafe' );
		}

		if ( ! empty( $manifest['contains_database'] ) && null === $reader->read_json( 'database/manifest.json' ) ) {
			return __( 'the backup is marked as containing a database, but database/manifest.json was not found inside the archive.', 'wrensafe' );
		}

		if ( ! empty( $manifest['contains_files'] ) && null === $reader->read_json( 'checksums.json' ) ) {
			return __( 'the backup is marked as containing files, but checksums.json was not found inside the archive.', 'wrensafe' );
		}

		return null;
	}

	/**
	 * Move a finished upload into an import set (another plugin's backup).
	 * Nothing is read yet: ForeignImportAjax::analyze() looks at the whole
	 * set once every file has arrived.
	 */
	private static function finalize_into_set( string $set_id, string $upload_id, string $part_path, string $filename ): void {
		$message = __( 'The uploaded file could not be moved into place (check file permissions).', 'wrensafe' );

		try {
			$name = ImportSet::add( $set_id, $part_path, $filename );
		} catch ( \RuntimeException $e ) {
			$name    = '';
			$message = Errors::text( $e );
		}

		if ( '' === $name ) {
			self::discard_session( $upload_id );
			Logger::failed( 'upload_finalize_failed: ' . $filename . ' — ' . $message );
			wp_send_json_error( array( 'message' => $message ), 500 );
		}

		wp_delete_file( self::meta_path( $upload_id ) );

		Logger::info( 'upload_completed: ' . $filename . ' added to an import of another plugin\'s backup (user #' . get_current_user_id() . ').' );

		wp_send_json_success(
			array(
				'foreign_set' => $set_id,
				'file'        => $name,
				'size'        => (int) filesize( ImportSet::dir( $set_id ) . $name ),
			)
		);
	}
}

// phpcs:enable WordPress.WP.AlternativeFunctions
