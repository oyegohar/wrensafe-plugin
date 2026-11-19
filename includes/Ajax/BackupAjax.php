<?php
/**
 * AJAX endpoints for creating and polling backup jobs.
 *
 * Creating a job here only records intent; the Backup Engine and Archive
 * Writer development phases wire in the actual scanning/export/zip work
 * that advances a job's progress.
 *
 * @package WPBP\Ajax
 */

namespace WPBP\Ajax;

use WPBP\Backup\BackupEncryption;
use WPBP\Backup\BackupJob;
use WPBP\Backup\BackupManager;
use WPBP\Core\Database\BackupsRepository;
use WPBP\Security\CapabilityManager;

defined( 'ABSPATH' ) || exit;

class BackupAjax {

	private const ALLOWED_TYPES = array( 'full', 'database', 'files', 'media', 'themes', 'plugins', 'uploads', 'custom' );

	public static function start(): void {
		check_ajax_referer( 'wpbp_admin_ajax', '_wpbp_nonce' );

		if ( ! CapabilityManager::current_user_can( CapabilityManager::CREATE_BACKUPS ) ) {
			wp_send_json_error( array( 'message' => __( 'You do not have permission to create backups.', 'wrensafe' ) ), 403 );
		}

		// Sweep any previous job the server killed mid-step, so it can never
		// linger as "running 90%" while a new backup starts (Issue 09).
		BackupManager::fail_stale_jobs();

		$type = isset( $_POST['backup_type'] ) ? sanitize_key( wp_unslash( $_POST['backup_type'] ) ) : 'full';

		if ( ! in_array( $type, self::ALLOWED_TYPES, true ) ) {
			wp_send_json_error( array( 'message' => __( 'Invalid backup type.', 'wrensafe' ) ), 400 );
		}

		$encrypt = ! empty( $_POST['encrypt'] );

		// Say so now, before anything is packed, when this server can't encrypt.
		$no_encryption = $encrypt ? BackupEncryption::unavailable_reason() : '';

		if ( '' !== $no_encryption ) {
			wp_send_json_error( array( 'message' => $no_encryption ), 400 );
		}

		$note       = isset( $_POST['note'] ) ? sanitize_text_field( wp_unslash( $_POST['note'] ) ) : '';
		$tag        = isset( $_POST['tag'] ) ? sanitize_text_field( wp_unslash( $_POST['tag'] ) ) : '';
		$chain_mode = 'full'; // Incremental/differential chains are chosen by an add-on (wpbp_backup_chain).

		// Optional: the browser's own timezone, captured for live-server
		// debugging of "wrong backup time" reports. The canonical created_at is
		// always stored in the DB and displayed in the viewer's local timezone
		// (data-utc), so this is informational only and never a source of truth.
		$browser_tz = isset( $_POST['browser_timezone'] ) ? sanitize_text_field( wp_unslash( $_POST['browser_timezone'] ) ) : '';

		// Encryption is enabled here as a flag only — the password itself is
		// never stored and is supplied fresh on the process() call that
		// reaches the archive step (see process() below).
		/**
		 * Filters the payload of a backup job started from the admin, so an
		 * add-on can record its own options.
		 * The request's nonce and capability have already been checked.
		 *
		 * @param array $payload
		 */
		$payload = (array) apply_filters(
			'wpbp_backup_job_payload',
			array(
				'backup_type' => $type,
				'note'        => $note,
				'tag'         => $tag,
				'chain_mode'  => $chain_mode,
				'encrypt'     => $encrypt,
			)
		);

		$job = BackupJob::create( $payload );

		\WPBP\Utils\Logger::info(
			'backup_created_time_recorded: job #' . $job->id() . ' started at ' . current_time( 'mysql' ) . ' (server)'
				. ( '' !== $browser_tz ? ', viewer timezone ' . $browser_tz : '' ) . '.',
			array(),
			$job->id()
		);

		wp_send_json_success( $job->to_array() );
	}

	public static function poll(): void {
		check_ajax_referer( 'wpbp_admin_ajax', '_wpbp_nonce' );

		if ( ! CapabilityManager::current_user_can( CapabilityManager::VIEW_BACKUPS ) ) {
			wp_send_json_error( array( 'message' => __( 'You do not have permission to view this job.', 'wrensafe' ) ), 403 );
		}

		$job_id = isset( $_POST['job_id'] ) ? absint( $_POST['job_id'] ) : 0;
		$job    = $job_id ? BackupJob::get( $job_id ) : null;

		if ( null === $job ) {
			wp_send_json_error( array( 'message' => __( 'Backup job not found.', 'wrensafe' ) ), 404 );
		}

		wp_send_json_success( $job->to_array() );
	}

	/**
	 * Advance a backup job by exactly one bounded unit of work and return
	 * its updated status. The browser calls this repeatedly until the job
	 * reaches a terminal status, which keeps each request short enough to
	 * avoid PHP execution time limits regardless of site size.
	 */
	public static function process(): void {
		check_ajax_referer( 'wpbp_admin_ajax', '_wpbp_nonce' );

		if ( ! CapabilityManager::current_user_can( CapabilityManager::CREATE_BACKUPS ) ) {
			wp_send_json_error( array( 'message' => __( 'You do not have permission to run backups.', 'wrensafe' ) ), 403 );
		}

		$job_id = isset( $_POST['job_id'] ) ? absint( $_POST['job_id'] ) : 0;
		$job    = $job_id ? BackupJob::get( $job_id ) : null;

		if ( null === $job ) {
			wp_send_json_error( array( 'message' => __( 'Backup job not found.', 'wrensafe' ) ), 404 );
		}

		if ( in_array( $job->status(), array( BackupJob::STATUS_COMPLETED, BackupJob::STATUS_FAILED, BackupJob::STATUS_CANCELLED ), true ) ) {
			wp_send_json_success( $job->to_array() );
		}

		self::register_fatal_error_guard( $job_id );

		// Only used transiently if this step happens to be the archive step
		// of an encryption-enabled backup — never stored.
		// It is used exactly as typed: it only feeds the key derivation
		// (PBKDF2) and is never stored, logged, output or used in a query. Any
		// other filter would change some passwords and lock the owner out of
		// their backup; FILTER_UNSAFE_RAW only rejects non-strings.
		$encryption_password = isset( $_POST['encryption_password'] ) ? (string) filter_var( wp_unslash( $_POST['encryption_password'] ), FILTER_UNSAFE_RAW ) : '';
		$was_running         = ! in_array( $job->status(), array( BackupJob::STATUS_COMPLETED, BackupJob::STATUS_FAILED, BackupJob::STATUS_CANCELLED ), true );

		( new BackupManager() )->process_step( $job, $encryption_password );

		// Tell add-ons (reports, notifications) the first time the job
		// reaches completed status. backup_id/cloud_upload are written via
		// mark_completed(), so they live in the job's result.
		if ( $was_running && BackupJob::STATUS_COMPLETED === $job->status() ) {
			$result    = $job->result();
			$backup_id = (int) ( $result['backup_id'] ?? 0 );
			$backup    = $backup_id ? BackupsRepository::find( $backup_id ) : null;

			if ( $backup ) {
				/**
				 * Fires once when a backup started from the admin finishes.
				 *
				 * @param array     $backup Backup record.
				 * @param array     $result Job result (backup_id, cloud_upload, …).
				 * @param BackupJob $job
				 */
				do_action( 'wpbp_backup_completed', $backup, $result, $job );
			}
		}

		wp_send_json_success( $job->to_array() );
	}

	/**
	 * Request cancellation of a running backup job. The job is flagged and
	 * stops at its next safe checkpoint (before the archive is finalized),
	 * cleaning up its temporary files. If the job is still in an early phase we
	 * also clean up immediately so no partial output lingers.
	 */
	public static function cancel(): void {
		check_ajax_referer( 'wpbp_admin_ajax', '_wpbp_nonce' );

		if ( ! CapabilityManager::current_user_can( CapabilityManager::CREATE_BACKUPS ) ) {
			wp_send_json_error( array( 'message' => __( 'You do not have permission to cancel backups.', 'wrensafe' ) ), 403 );
		}

		$job_id = isset( $_POST['job_id'] ) ? absint( $_POST['job_id'] ) : 0;
		$job    = $job_id ? BackupJob::get( $job_id ) : null;

		if ( null === $job ) {
			wp_send_json_error( array( 'message' => __( 'Backup job not found.', 'wrensafe' ) ), 404 );
		}

		if ( in_array( $job->status(), array( BackupJob::STATUS_COMPLETED, BackupJob::STATUS_FAILED, BackupJob::STATUS_CANCELLED ), true ) ) {
			wp_send_json_success( $job->to_array() );
		}

		BackupManager::request_cancel( $job_id );
		\WPBP\Utils\Logger::warning( 'backup_cancel_requested: job #' . $job_id . ' cancellation requested by user.', array(), $job_id );

		wp_send_json_success( $job->to_array() );
	}

	public static function pause(): void {
		check_ajax_referer( 'wpbp_admin_ajax', '_wpbp_nonce' );

		if ( ! CapabilityManager::current_user_can( CapabilityManager::CREATE_BACKUPS ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'wrensafe' ) ), 403 );
		}

		$job_id = isset( $_POST['job_id'] ) ? absint( $_POST['job_id'] ) : 0;
		$job    = $job_id ? BackupJob::get( $job_id ) : null;

		if ( null === $job ) {
			wp_send_json_error( array( 'message' => __( 'Backup job not found.', 'wrensafe' ) ), 404 );
		}

		$job->pause();
		wp_send_json_success( $job->to_array() );
	}

	public static function resume(): void {
		check_ajax_referer( 'wpbp_admin_ajax', '_wpbp_nonce' );

		if ( ! CapabilityManager::current_user_can( CapabilityManager::CREATE_BACKUPS ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'wrensafe' ) ), 403 );
		}

		$job_id = isset( $_POST['job_id'] ) ? absint( $_POST['job_id'] ) : 0;
		$job    = $job_id ? BackupJob::get( $job_id ) : null;

		if ( null === $job ) {
			wp_send_json_error( array( 'message' => __( 'Backup job not found.', 'wrensafe' ) ), 404 );
		}

		$job->resume();
		wp_send_json_success( $job->to_array() );
	}

	/**
	 * Safety net for the one failure mode try/catch can never intercept: a
	 * true PHP fatal error or a hard max_execution_time kill mid-step. This
	 * is exactly how live jobs ended up stuck "running" at 90% with no log —
	 * the request died during archive/checksum work and nothing recorded it.
	 * Runs on every shutdown but only acts when a fatal error happened while
	 * this backup job was still active.
	 */
	private static function register_fatal_error_guard( int $job_id ): void {
		register_shutdown_function(
			static function () use ( $job_id ) {
				$error = error_get_last();

				if ( null === $error || ! in_array( $error['type'], array( E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR ), true ) ) {
					return;
				}

				$job = BackupJob::get( $job_id );

				if ( null === $job || in_array( $job->status(), array( BackupJob::STATUS_COMPLETED, BackupJob::STATUS_FAILED, BackupJob::STATUS_CANCELLED ), true ) ) {
					return;
				}

				$phase = (string) ( $job->payload()['phase'] ?? 'unknown' );
				$step  = $job->current_step();

				\WPBP\Utils\Logger::failed(
					sprintf( 'backup_failed: job #%d killed by a fatal PHP error/timeout during %s (%s) — %s in %s:%d', $job_id, $phase, $step, $error['message'], basename( $error['file'] ), $error['line'] ),
					array(),
					$job_id
				);

				$job->mark_failed(
					sprintf(
						/* translators: %s: backup step label */
						__( 'Backup failed during %s: the server stopped the request (fatal error or timeout).', 'wrensafe' ),
						'' !== $step ? $step : $phase
					)
				);
			}
		);
	}
}
