<?php
/**
 * AJAX endpoints for creating and running restore jobs.
 *
 * For an uploaded archive, the job is already created by
 * UploadAjax::finalize(); start() here is used for the "local backup"
 * source (restoring straight from the Backup Browser).
 *
 * @package WPBP\Ajax
 */

namespace WPBP\Ajax;

use WPBP\Core\Database\BackupsRepository;
use WPBP\Restore\ArchiveReader;
use WPBP\Restore\RestoreJob;
use WPBP\Restore\RestoreManager;
use WPBP\Security\CapabilityManager;
use WPBP\Utils\Logger;

defined( 'ABSPATH' ) || exit;

// Database access in this file is direct by design: Wrensafe's own tables (or,
// in the backup/restore engine, the site's tables being exported, imported or
// swapped) have no WordPress API, and the data must be read fresh, so it is not
// object-cached. Values go through $wpdb->prepare(); table names come from
// $wpdb->prefix with fixed suffixes and are escaped or quoted as identifiers.
// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

class RestoreAjax {

	private const ALLOWED_MODES = array( 'full', 'database', 'files', 'uploads', 'themes', 'plugins', 'plugin_single', 'theme_single', 'selected_tables' );

	private static function require_capability(): void {
		if ( ! CapabilityManager::current_user_can( CapabilityManager::RESTORE_BACKUPS ) ) {
			wp_send_json_error( array( 'message' => __( 'You do not have permission to restore backups.', 'wrensafe' ) ), 403 );
		}
	}

	/**
	 * Blocks restoring a locked backup — a locked backup is meant to be
	 * read-only (verify still works), so both the preview and the actual
	 * start of a restore must refuse it here. Frontend button-disabling
	 * (Backup Browser) is a convenience only; this is the real enforcement.
	 */
	private static function assert_not_locked( array $backup ): void {
		if ( ! empty( $backup['is_locked'] ) ) {
			wp_send_json_error( array( 'message' => __( 'This backup is locked. Unlock it before restoring.', 'wrensafe' ) ), 400 );
		}
	}

	/**
	 * Refuses to start a new restore while another one is still marked as
	 * running, unless that lock is stale (its job already reached a
	 * terminal status — e.g. it was cleared by the admin recovery action).
	 */
	private static function assert_not_already_restoring(): void {
		if ( ! RestoreManager::is_locked() ) {
			return;
		}

		$locked_job = RestoreJob::get( RestoreManager::locked_job_id() );

		if ( null !== $locked_job && RestoreJob::STATUS_RUNNING === $locked_job->status() ) {
			wp_send_json_error(
				array(
					'message' => __( 'A restore is already running. Wait for it to finish. If it seems stuck, use Wrensafe → Settings → Advanced → Clear restore lock.', 'wrensafe' ),
				),
				409
			);
		}
	}

	/**
	 * Read and sanitize the selective-restore params shared by start() and set_mode().
	 */
	private static function read_mode_params(): array {
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- only called by handlers after check_ajax_referer().
		$mode = isset( $_POST['restore_mode'] ) ? sanitize_key( wp_unslash( $_POST['restore_mode'] ) ) : 'full';

		if ( ! in_array( $mode, self::ALLOWED_MODES, true ) ) {
			$mode = 'full';
		}

		$target = isset( $_POST['restore_target'] ) ? sanitize_text_field( wp_unslash( $_POST['restore_target'] ) ) : '';

		$tables = array();
		if ( isset( $_POST['selected_tables'] ) && is_array( $_POST['selected_tables'] ) ) {
			$tables = array_map( 'sanitize_text_field', wp_unslash( $_POST['selected_tables'] ) );
		}

		return array(
			'restore_mode'    => $mode,
			'restore_target'  => $target,
			'selected_tables' => $tables,
			'woo_safe_mode'   => ! isset( $_POST['woo_safe_mode'] ) || ! empty( $_POST['woo_safe_mode'] ),
		);
		// phpcs:enable WordPress.Security.NonceVerification.Missing
	}

	/**
	 * Validate a local backup and return its manifest/metadata/change
	 * summary for the Visual Restore Preview, without creating a job yet.
	 */
	public static function preview(): void {
		check_ajax_referer( 'wpbp_admin_ajax', '_wpbp_nonce' );
		self::require_capability();

		$backup_id = isset( $_POST['backup_id'] ) ? absint( $_POST['backup_id'] ) : 0;
		$backup    = $backup_id ? BackupsRepository::find( $backup_id ) : null;

		if ( null === $backup || ! file_exists( $backup['file_path'] ) ) {
			wp_send_json_error( array( 'message' => __( 'Backup file not found.', 'wrensafe' ) ), 404 );
		}

		self::assert_not_locked( $backup );

		$password = isset( $_POST['encryption_password'] ) ? (string) wp_unslash( $_POST['encryption_password'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- password used verbatim; never stored, logged or output.
		$reader   = ( new ArchiveReader( $backup['file_path'], $password ) )->preview_only();
		$result   = $reader->validate();

		if ( ! $result['valid'] ) {
			wp_send_json_error(
				array(
					'message'           => $result['message'],
					'requires_password' => ! empty( $result['requires_password'] ),
				)
			);
		}

		global $wpdb;

		wp_send_json_success(
			array(
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

	public static function start(): void {
		check_ajax_referer( 'wpbp_admin_ajax', '_wpbp_nonce' );
		self::require_capability();
		self::assert_not_already_restoring();

		$source    = isset( $_POST['source'] ) ? sanitize_key( wp_unslash( $_POST['source'] ) ) : 'upload';
		$backup_id = isset( $_POST['backup_id'] ) ? absint( $_POST['backup_id'] ) : 0;

		if ( $backup_id ) {
			$backup = BackupsRepository::find( $backup_id );

			if ( null === $backup ) {
				wp_send_json_error( array( 'message' => __( 'Backup file not found.', 'wrensafe' ) ), 404 );
			}

			self::assert_not_locked( $backup );
		}

		$payload = array_merge( array( 'source' => $source ), self::read_mode_params() );
		if ( $backup_id ) {
			$payload['backup_id'] = $backup_id;
		}

		$job = RestoreJob::create( $payload );

		Logger::info( 'Restore job #' . $job->id() . ' created (source: ' . $source . ').', array(), $job->id() );

		wp_send_json_success( $job->to_array() );
	}

	/**
	 * Request cancellation of an in-progress restore. The actual stop happens
	 * server-side only at a safe checkpoint (see RestoreManager::process_step),
	 * never mid-SQL or mid-file, so a cancel can't corrupt the restore.
	 */
	public static function cancel(): void {
		check_ajax_referer( 'wpbp_admin_ajax', '_wpbp_nonce' );
		self::require_capability();

		$job_id = isset( $_POST['job_id'] ) ? absint( $_POST['job_id'] ) : 0;

		if ( $job_id ) {
			RestoreManager::request_cancel( $job_id );
			Logger::info( 'Restore job #' . $job_id . ' cancellation requested by user.', array(), $job_id );
		}

		wp_send_json_success(
			array(
				'message' => __( 'Cancel requested — the restore will stop at the next safe point.', 'wrensafe' ),
			)
		);
	}

	/**
	 * Admin recovery action: clears WordPress core maintenance mode, WP
	 * Wrensafe's own checkout-maintenance state, the restore lock, and
	 * marks any stuck restore job as failed. Surfaced as the "Clear Restore
	 * Lock / Disable Maintenance Mode" button (Settings → Advanced).
	 */
	public static function clear_locks(): void {
		check_ajax_referer( 'wpbp_admin_ajax', '_wpbp_nonce' );
		self::require_capability();

		$result = RestoreManager::clear_restore_locks_and_maintenance();

		Logger::info(
			'Restore lock / maintenance cleanup triggered manually by user #' . get_current_user_id() . '.',
			array(),
			null
		);

		wp_send_json_success(
			array(
				'message' => __( 'Restore lock and maintenance mode cleared.', 'wrensafe' ),
				'details' => $result,
			)
		);
	}

	/**
	 * Apply selective-restore settings to a job that already exists (the
	 * uploaded-archive flow creates its job during UploadAjax::finalize()).
	 */
	public static function set_mode(): void {
		check_ajax_referer( 'wpbp_admin_ajax', '_wpbp_nonce' );
		self::require_capability();

		$job_id = isset( $_POST['job_id'] ) ? absint( $_POST['job_id'] ) : 0;
		$job    = $job_id ? RestoreJob::get( $job_id ) : null;

		if ( null === $job ) {
			wp_send_json_error( array( 'message' => __( 'Restore job not found.', 'wrensafe' ) ), 404 );
		}

		$job->set_payload( array_merge( $job->payload(), self::read_mode_params() ) );

		wp_send_json_success();
	}

	public static function poll(): void {
		check_ajax_referer( 'wpbp_admin_ajax', '_wpbp_nonce' );
		self::require_capability();

		$job_id = isset( $_POST['job_id'] ) ? absint( $_POST['job_id'] ) : 0;
		$job    = $job_id ? RestoreJob::get( $job_id ) : null;

		if ( null === $job ) {
			wp_send_json_error( array( 'message' => __( 'Restore job not found.', 'wrensafe' ) ), 404 );
		}

		wp_send_json_success( $job->to_array() );
	}

	/**
	 * Advance a restore job by exactly one bounded unit of work, mirroring
	 * BackupAjax::process(). The browser calls this repeatedly until the
	 * job reaches a terminal status.
	 */
	/**
	 * Also registered for logged-out requests (wp_ajax_nopriv_), but then only
	 * a valid resume key for a still-running job is accepted: a database
	 * restore swaps in the backup's users table, which ends the operator's
	 * login session part-way through the restore (see
	 * RestoreManager::issue_resume_key()). Every other request needs the
	 * usual nonce + capability.
	 */
	public static function process(): void {
		$job_id     = isset( $_POST['job_id'] ) ? absint( $_POST['job_id'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- authorized below by nonce+capability or by the job's resume key.
		$resume_key = isset( $_POST['resume_key'] ) ? sanitize_text_field( wp_unslash( $_POST['resume_key'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- see above.
		$by_key     = '' !== $resume_key && RestoreManager::verify_resume_key( $job_id, $resume_key );

		if ( ! $by_key ) {
			check_ajax_referer( 'wpbp_admin_ajax', '_wpbp_nonce' );
			self::require_capability();
		}

		$job = $job_id ? RestoreJob::get( $job_id ) : null;

		if ( null === $job ) {
			wp_send_json_error( array( 'message' => __( 'Restore job not found.', 'wrensafe' ) ), 404 );
		}

		if ( in_array( $job->status(), array( RestoreJob::STATUS_COMPLETED, RestoreJob::STATUS_FAILED, RestoreJob::STATUS_CANCELLED ), true ) ) {
			wp_send_json_success( $job->to_array() );
		}

		// Issued once, on the first (authenticated) step of the job.
		$new_resume_key = $by_key ? '' : RestoreManager::issue_resume_key( $job );

		self::register_fatal_error_guard( $job_id );

		// Only used transiently if this step happens to be the init step of
		// an encrypted backup's restore — never stored.
		$encryption_password = isset( $_POST['encryption_password'] ) ? (string) wp_unslash( $_POST['encryption_password'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- password used verbatim; never stored, logged or output.

		( new RestoreManager() )->process_step( $job, $encryption_password );

		// A successful restore that replaced the database (and therefore the
		// users table) leaves the current auth cookie/session potentially out
		// of sync with the restored data. Now that the restore has fully
		// finished and its cleanup has run (maintenance disabled, lock
		// released — see RestoreManager::step_finalize), log the user out and
		// send the browser to the login screen for a clean re-auth. Only on
		// success, and only for restores that touched the database — a failed
		// restore keeps the user logged in so they can read the logs.
		if ( RestoreJob::STATUS_COMPLETED === $job->status() && ! empty( $job->payload()['needs_db'] ) ) {
			if ( is_user_logged_in() ) {
				wp_destroy_current_session();
				wp_clear_auth_cookie();
				wp_set_current_user( 0 );
			}

			Logger::info( 'Restore job #' . $job->id() . ' completed; current user logged out for a clean re-login.', array(), $job->id() );

			wp_send_json_success(
				array_merge(
					$job->to_array(),
					array(
						'force_logout' => true,
						'redirect_url' => wp_login_url( admin_url( 'admin.php?page=wrensafe-browser&restore=success' ) ),
						'message'      => __( 'For security, please log in again. Use a username and password from the site the backup came from.', 'wrensafe' ),
						'deactivated'  => (array) ( $job->result()['deactivated'] ?? array() ),
					)
				)
			);
		}

		$response = $job->to_array();

		if ( '' !== $new_resume_key ) {
			$response['resume_key'] = $new_resume_key;
		}

		wp_send_json_success( $response );
	}

	/**
	 * Safety net for the one failure mode try/catch can never intercept: a
	 * true PHP fatal error or a max_execution_time timeout, which simply
	 * kills the request mid-step. Without this, maintenance mode and the
	 * restore lock would stay on indefinitely with nothing logged. Runs on
	 * every shutdown but only acts if a fatal error actually happened while
	 * this job was still active.
	 */
	private static function register_fatal_error_guard( int $job_id ): void {
		register_shutdown_function(
			static function () use ( $job_id ) {
				$error = error_get_last();

				if ( null === $error || ! in_array( $error['type'], array( E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR ), true ) ) {
					return;
				}

				$job = RestoreJob::get( $job_id );

				if ( null === $job || in_array( $job->status(), array( RestoreJob::STATUS_COMPLETED, RestoreJob::STATUS_FAILED, RestoreJob::STATUS_CANCELLED ), true ) ) {
					return;
				}

				$payload = $job->payload();
				$phase   = (string) ( $payload['phase'] ?? 'unknown' );

				Logger::failed(
					sprintf( 'restore_failed: restore job #%d killed by a fatal PHP error during %s in %s:%d — %s', $job_id, $phase, basename( $error['file'] ), $error['line'], $error['message'] ),
					array(),
					$job_id
				);

				$job->mark_failed(
					sprintf(
						/* translators: %s: restore phase */
						__( 'Restore failed because PHP encountered a fatal error during %s. Wrensafe attempted safety recovery — view Activity Logs for details.', 'wrensafe' ),
						$phase
					)
				);

				// Full safety recovery: destination home/siteurl back (if the
				// swap had run), broken plugins deactivated, boot guard removed,
				// maintenance and locks cleared — the site must stay reachable
				// even after a hard mid-restore fatal (Issue 10).
				Logger::warning( 'restore_failure_recovery_started: recovering after a fatal PHP error.', array(), $job_id );

				if ( ! empty( $payload['db_swapped'] ) && ! empty( $payload['dest_home_url'] ) ) {
					global $wpdb;
					$dest_home = (string) $payload['dest_home_url'];
					$dest_site = (string) ( $payload['dest_site_url'] ? $payload['dest_site_url'] : $dest_home );
					$wpdb->query( $wpdb->prepare( "UPDATE `{$wpdb->options}` SET option_value = %s WHERE option_name = 'home'", $dest_home ) );
					$wpdb->query( $wpdb->prepare( "UPDATE `{$wpdb->options}` SET option_value = %s WHERE option_name = 'siteurl'", $dest_site ) );
					wp_cache_delete( 'alloptions', 'options' );
					Logger::info( 'restore_home_siteurl_forced: home/siteurl recovered to ' . $dest_home . ' after fatal error.', array(), $job_id );
				}

				\WPBP\Restore\PluginSafety::filter_active_plugins( $job_id );
				RestoreManager::clear_restore_locks_and_maintenance();
				Logger::info( 'restore_failure_recovery_completed: locks, maintenance, plugin safety, and destination URLs handled.', array(), $job_id );
			}
		);
	}
}
