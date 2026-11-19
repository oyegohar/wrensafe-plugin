<?php
/**
 * AJAX endpoints backing the Backup Browser page: delete, verify, lock, and
 * note/tag editing. Downloads are handled separately by DownloadHandler
 * since they stream a binary file rather than return JSON.
 *
 * @package WPBP\Ajax
 */

namespace WPBP\Ajax;

use WPBP\Backup\IntegrityChecker;
use WPBP\Backup\VerifyManager;
use WPBP\Core\Database\BackupsRepository;
use WPBP\Security\CapabilityManager;
use WPBP\Utils\FileSystem;
use WPBP\Utils\Logger;

defined( 'ABSPATH' ) || exit;

class BackupBrowserAjax {

	/**
	 * The backup with this ID, or a 404 JSON error. Each handler reads the
	 * ID from the request after checking its nonce.
	 */
	private static function get_backup_or_die( int $id ): array {
		$backup = $id ? BackupsRepository::find( $id ) : null;

		if ( null === $backup ) {
			wp_send_json_error( array( 'message' => __( 'Backup not found.', 'wrensafe' ) ), 404 );
		}

		return $backup;
	}

	public static function delete(): void {
		check_ajax_referer( 'wpbp_admin_ajax', '_wpbp_nonce' );

		if ( ! CapabilityManager::current_user_can( CapabilityManager::DELETE_BACKUPS ) ) {
			wp_send_json_error( array( 'message' => __( 'You do not have permission to delete backups.', 'wrensafe' ) ), 403 );
		}

		$backup = self::get_backup_or_die( isset( $_POST['backup_id'] ) ? absint( $_POST['backup_id'] ) : 0 );

		if ( ! empty( $backup['is_locked'] ) ) {
			wp_send_json_error( array( 'message' => __( 'This backup is locked and cannot be deleted.', 'wrensafe' ) ), 400 );
		}

		// The path comes from the database: only a file inside a backup
		// folder is ever deleted (the same check as the bulk delete).
		if ( ! empty( $backup['file_path'] ) && ! FileSystem::is_backup_file_path( (string) $backup['file_path'] ) ) {
			Logger::error( 'Blocked delete of backup #' . $backup['id'] . ': file path resolves outside the backups directory.' );
			wp_send_json_error( array( 'message' => __( 'This backup’s file is outside the backup folder, so it wasn’t deleted.', 'wrensafe' ) ), 400 );
		}

		if ( ! empty( $backup['file_path'] ) && file_exists( $backup['file_path'] ) ) {
			wp_delete_file( $backup['file_path'] );
		}

		BackupsRepository::delete( (int) $backup['id'] );
		Logger::info( 'Backup #' . $backup['id'] . ' (' . $backup['file_name'] . ') deleted.' );

		wp_send_json_success( array( 'total_completed' => BackupsRepository::count_completed() ) );
	}

	/**
	 * Delete several backups in one request. Locked backups are skipped (never
	 * deleted), and every file path is validated to be inside the backups
	 * directory before unlinking — so a tampered ID can't delete an arbitrary
	 * file. Returns per-outcome counts for a clear user-facing summary.
	 */
	public static function bulk_delete(): void {
		check_ajax_referer( 'wpbp_admin_ajax', '_wpbp_nonce' );

		if ( ! CapabilityManager::current_user_can( CapabilityManager::DELETE_BACKUPS ) ) {
			wp_send_json_error( array( 'message' => __( 'You do not have permission to delete backups.', 'wrensafe' ) ), 403 );
		}

		$ids = isset( $_POST['backup_ids'] ) ? array_map( 'absint', (array) wp_unslash( $_POST['backup_ids'] ) ) : array();
		$ids = array_values( array_unique( array_filter( array_map( 'absint', $ids ) ) ) );

		if ( empty( $ids ) ) {
			wp_send_json_error( array( 'message' => __( 'No backups were selected.', 'wrensafe' ) ), 400 );
		}

		$deleted        = array();
		$skipped_locked = 0;
		$failed         = 0;

		foreach ( $ids as $id ) {
			$backup = BackupsRepository::find( $id );

			if ( null === $backup ) {
				++$failed;
				continue;
			}

			if ( ! empty( $backup['is_locked'] ) ) {
				++$skipped_locked;
				continue;
			}

			if ( ! empty( $backup['file_path'] ) && ! FileSystem::is_backup_file_path( (string) $backup['file_path'] ) ) {
				Logger::error( 'Blocked bulk delete of backup #' . $backup['id'] . ': file path resolves outside the backups directory.' );
				++$failed;
				continue;
			}

			if ( ! empty( $backup['file_path'] ) && file_exists( $backup['file_path'] ) ) {
				wp_delete_file( $backup['file_path'] );
			}

			BackupsRepository::delete( (int) $backup['id'] );
			Logger::info( 'Backup #' . $backup['id'] . ' (' . $backup['file_name'] . ') deleted (bulk).' );
			$deleted[] = (int) $backup['id'];
		}

		$deleted_count = count( $deleted );

		if ( 0 === $deleted_count && $skipped_locked > 0 && 0 === $failed ) {
			$message = __( 'No backups were deleted because selected backups are locked.', 'wrensafe' );
		} else {
			$message = sprintf(
				/* translators: 1: number deleted, 2: number skipped because locked */
				_n( 'Deleted %1$d backup. Skipped %2$d locked.', 'Deleted %1$d backups. Skipped %2$d locked.', $deleted_count, 'wrensafe' ),
				$deleted_count,
				$skipped_locked
			);

			if ( $failed > 0 ) {
				$message .= ' ' . sprintf(
					/* translators: %d: number of backups that could not be deleted */
					_n( '%d could not be deleted.', '%d could not be deleted.', $failed, 'wrensafe' ),
					$failed
				);
			}
		}

		wp_send_json_success(
			array(
				'deleted'         => $deleted_count,
				'deleted_ids'     => $deleted,
				'skipped_locked'  => $skipped_locked,
				'failed'          => $failed,
				'message'         => $message,
				'total_completed' => BackupsRepository::count_completed(),
			)
		);
	}

	public static function verify(): void {
		check_ajax_referer( 'wpbp_admin_ajax', '_wpbp_nonce' );

		if ( ! CapabilityManager::current_user_can( CapabilityManager::VIEW_BACKUPS ) ) {
			wp_send_json_error( array( 'message' => __( 'You do not have permission to verify backups.', 'wrensafe' ) ), 403 );
		}

		$backup = self::get_backup_or_die( isset( $_POST['backup_id'] ) ? absint( $_POST['backup_id'] ) : 0 );

		if ( ! FileSystem::is_backup_file_path( (string) $backup['file_path'] ) ) {
			Logger::error( 'Blocked verify of backup #' . $backup['id'] . ': file path resolves outside the backups directory.' );
			wp_send_json_error( array( 'message' => __( 'Backup file not found.', 'wrensafe' ) ), 404 );
		}

		if ( ! file_exists( $backup['file_path'] ) ) {
			BackupsRepository::update( (int) $backup['id'], array( 'status' => 'corrupted' ) );
			Logger::warning( 'Verify of backup #' . $backup['id'] . ' (' . $backup['file_name'] . ') failed: file is missing from disk.' );
			wp_send_json_success(
				array(
					'valid'   => false,
					'status'  => 'corrupted',
					'message' => __( 'Backup file is missing from disk.', 'wrensafe' ),
				)
			);
		}

		$checker  = new IntegrityChecker();
		$password = isset( $_POST['encryption_password'] ) ? (string) filter_var( wp_unslash( $_POST['encryption_password'] ), FILTER_UNSAFE_RAW ) : ''; // Kept exactly as typed (never sanitize_text_field()); never stored, logged or output.
		$result   = $checker->verify_archive( $backup['file_path'], $password );

		// An encrypted backup's contents can't be inspected without its
		// password (never stored), so fall back to the whole-file checksum
		// recorded when the backup was written — still a genuine integrity
		// signal, just not one that can name a specific corrupted entry.
		if ( ! $result['valid'] && ! empty( $result['requires_password'] ) ) {
			$is_valid = $checker->verify( $backup['file_path'], $backup['checksum'] );

			$result = array(
				'valid'   => $is_valid,
				'message' => $is_valid
					? __( 'Whole-file checksum passed (archive is encrypted; contents were not individually inspected).', 'wrensafe' )
					: __( 'Whole-file checksum failed — the backup file has changed since it was created.', 'wrensafe' ),
			);
		}

		BackupsRepository::update( (int) $backup['id'], array( 'status' => $result['valid'] ? 'completed' : 'corrupted' ) );

		Logger::log(
			$result['valid'] ? Logger::LEVEL_INFO : Logger::LEVEL_WARNING,
			'Verify of backup #' . $backup['id'] . ' (' . $backup['file_name'] . '): ' . ( $result['valid'] ? 'Integrity check passed.' : 'Integrity check failed: ' . $result['message'] )
		);

		wp_send_json_success(
			array(
				'valid'                  => $result['valid'],
				'status'                 => $result['valid'] ? 'completed' : 'corrupted',
				'message'                => $result['valid']
					? __( 'Integrity check passed.', 'wrensafe' )
					/* translators: %s: exact integrity failure reason */
					: sprintf( __( 'Integrity check failed: %s', 'wrensafe' ), $result['message'] ),
				'checked_files'          => $result['checked_files'] ?? 0,
				'checked_database_files' => $result['checked_database_files'] ?? 0,
			)
		);
	}

	/**
	 * Shared capability gate for the step-based verify endpoints — verify is
	 * read-only, so VIEW_BACKUPS is enough and locked backups are allowed.
	 */
	private static function require_view_capability(): void {
		if ( ! CapabilityManager::current_user_can( CapabilityManager::VIEW_BACKUPS ) ) {
			wp_send_json_error( array( 'message' => __( 'You do not have permission to verify backups.', 'wrensafe' ) ), 403 );
		}
	}

	/**
	 * Begin a step-based verification job and return its initial state (the
	 * job_id the browser then polls with verify_step). Read-only, so it works
	 * on locked backups.
	 */
	public static function verify_start(): void {
		check_ajax_referer( 'wpbp_admin_ajax', '_wpbp_nonce' );
		self::require_view_capability();

		$backup   = self::get_backup_or_die( isset( $_POST['backup_id'] ) ? absint( $_POST['backup_id'] ) : 0 );
		$password = isset( $_POST['encryption_password'] ) ? (string) filter_var( wp_unslash( $_POST['encryption_password'] ), FILTER_UNSAFE_RAW ) : ''; // Kept exactly as typed (never sanitize_text_field()); never stored, logged or output.

		$state = VerifyManager::start( (int) $backup['id'], $password );

		wp_send_json_success( $state );
	}

	/**
	 * Advance a verification job by one bounded step and return its state.
	 */
	public static function verify_step(): void {
		check_ajax_referer( 'wpbp_admin_ajax', '_wpbp_nonce' );
		self::require_view_capability();

		// The password is only needed to start (VerifyManager::start()).
		$job_id = isset( $_POST['job_id'] ) ? sanitize_key( wp_unslash( $_POST['job_id'] ) ) : '';
		$state  = '' !== $job_id ? VerifyManager::step( $job_id ) : null;

		if ( null === $state ) {
			wp_send_json_error( array( 'message' => __( 'The verification session expired. Select Verify again.', 'wrensafe' ) ), 404 );
		}

		wp_send_json_success( $state );
	}

	/**
	 * Return a verification job's current/final state without advancing it.
	 */
	public static function verify_result(): void {
		check_ajax_referer( 'wpbp_admin_ajax', '_wpbp_nonce' );
		self::require_view_capability();

		$job_id = isset( $_POST['job_id'] ) ? sanitize_key( wp_unslash( $_POST['job_id'] ) ) : '';
		$state  = '' !== $job_id ? VerifyManager::get( $job_id ) : null;

		if ( null === $state ) {
			wp_send_json_error( array( 'message' => __( 'The verification session expired. Select Verify again.', 'wrensafe' ) ), 404 );
		}

		wp_send_json_success( $state );
	}

	/**
	 * Lock/unlock a backup. Verify remains allowed on a locked backup
	 * (read-only check); Restore, Delete, and Download are enforced
	 * server-side in RestoreAjax, this class's delete(), and
	 * DownloadHandler respectively — never just on the frontend.
	 */
	public static function toggle_lock(): void {
		check_ajax_referer( 'wpbp_admin_ajax', '_wpbp_nonce' );

		// Administrators are covered by CapabilityManager::current_user_can()
		// (on multisite: super admins only).
		if ( ! CapabilityManager::current_user_can( CapabilityManager::DELETE_BACKUPS )
			&& ! CapabilityManager::current_user_can( CapabilityManager::MANAGE_SETTINGS ) ) {
			wp_send_json_error( array( 'message' => __( 'You do not have permission to lock backups.', 'wrensafe' ) ), 403 );
		}

		$backup    = self::get_backup_or_die( isset( $_POST['backup_id'] ) ? absint( $_POST['backup_id'] ) : 0 );
		$is_locked = empty( $backup['is_locked'] ) ? 1 : 0;

		BackupsRepository::update( (int) $backup['id'], array( 'is_locked' => $is_locked ) );
		Logger::info( 'Backup #' . $backup['id'] . ' (' . $backup['file_name'] . ') ' . ( $is_locked ? 'locked' : 'unlocked' ) . '.' );

		wp_send_json_success(
			array(
				'is_locked' => (bool) $is_locked,
				'message'   => $is_locked
					? __( 'Backup locked.', 'wrensafe' )
					: __( 'Backup unlocked.', 'wrensafe' ),
			)
		);
	}

	/**
	 * Save a backup's note and tags. Changing a backup needs the same
	 * capability as locking it (managing backups), not just viewing them.
	 */
	public static function update_meta(): void {
		check_ajax_referer( 'wpbp_admin_ajax', '_wpbp_nonce' );

		if ( ! CapabilityManager::current_user_can( CapabilityManager::DELETE_BACKUPS )
			&& ! CapabilityManager::current_user_can( CapabilityManager::MANAGE_SETTINGS ) ) {
			wp_send_json_error( array( 'message' => __( 'You do not have permission to edit backups.', 'wrensafe' ) ), 403 );
		}

		$backup = self::get_backup_or_die( isset( $_POST['backup_id'] ) ? absint( $_POST['backup_id'] ) : 0 );

		$note = isset( $_POST['note'] ) ? sanitize_text_field( wp_unslash( $_POST['note'] ) ) : '';

		// Support multiple comma-separated tags: split, sanitize each, drop
		// blanks/duplicates, and store as a normalized comma-separated string in
		// the existing schema column. Per-tag sanitize_text_field prevents XSS.
		$raw_tags = isset( $_POST['tags'] ) ? sanitize_text_field( wp_unslash( $_POST['tags'] ) ) : '';
		$tag_list = array_filter( array_map( 'sanitize_text_field', array_map( 'trim', explode( ',', $raw_tags ) ) ) );
		$tag_list = array_values( array_unique( $tag_list ) );
		$tags     = implode( ', ', $tag_list );

		BackupsRepository::update(
			(int) $backup['id'],
			array(
				'note' => $note,
				'tags' => $tags,
			)
		);

		Logger::info( 'backup_note_tags_updated: backup #' . $backup['id'] . ' (' . $backup['file_name'] . ') note/tags updated.' );

		wp_send_json_success(
			array(
				'note'     => $note,
				'tags'     => $tags,
				'tag_list' => $tag_list,
			)
		);
	}
}
