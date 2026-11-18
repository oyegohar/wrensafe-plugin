<?php
/**
 * AJAX endpoints for the Scheduled Backups page: save, delete, toggle, and
 * run now. Running a schedule immediately reuses the same BackupJob the
 * Create Backup wizard uses, so the client polls wpbp_backup_process exactly
 * like it does there.
 *
 * @package WPBP\Ajax
 */

namespace WPBP\Ajax;

use WPBP\Core\Database\SchedulesRepository;
use WPBP\Scheduler\ScheduleManager;
use WPBP\Security\CapabilityManager;

defined( 'ABSPATH' ) || exit;

class ScheduleAjax {

	private const ALLOWED_TYPES = array( 'full', 'database', 'files', 'media', 'themes', 'plugins', 'uploads' );

	private static function require_capability(): void {
		if ( ! CapabilityManager::current_user_can( CapabilityManager::MANAGE_SCHEDULES ) ) {
			wp_send_json_error( array( 'message' => __( 'You do not have permission to manage schedules.', 'wrensafe' ) ), 403 );
		}
	}

	public static function save(): void {
		check_ajax_referer( 'wpbp_admin_ajax', '_wpbp_nonce' );
		self::require_capability();

		$id        = isset( $_POST['id'] ) ? absint( $_POST['id'] ) : 0;
		$frequency = isset( $_POST['frequency'] ) ? sanitize_key( wp_unslash( $_POST['frequency'] ) ) : 'daily';
		$type      = isset( $_POST['backup_type'] ) ? sanitize_key( wp_unslash( $_POST['backup_type'] ) ) : 'full';

		if ( ! in_array( $frequency, ScheduleManager::FREQUENCIES, true ) ) {
			$frequency = 'daily';
		}
		if ( ! in_array( $type, self::ALLOWED_TYPES, true ) ) {
			$type = 'full';
		}

		$destination = isset( $_POST['destination'] ) ? sanitize_text_field( wp_unslash( $_POST['destination'] ) ) : 'local';
		if ( 'local' !== $destination && ! ctype_digit( $destination ) ) {
			$destination = 'local';
		}

		$data = array(
			'title'                   => isset( $_POST['title'] ) ? sanitize_text_field( wp_unslash( $_POST['title'] ) ) : __( 'Untitled schedule', 'wrensafe' ),
			'frequency'               => $frequency,
			'custom_interval_seconds' => isset( $_POST['custom_interval_seconds'] ) ? absint( $_POST['custom_interval_seconds'] ) : null,
			'run_time'                => isset( $_POST['run_time'] ) ? sanitize_text_field( wp_unslash( $_POST['run_time'] ) ) : '00:00',
			'backup_type'             => $type,
			'destination'             => $destination,
			'retention_count'         => isset( $_POST['retention_count'] ) ? absint( $_POST['retention_count'] ) : 5,
			'enabled'                 => empty( $_POST['enabled'] ) ? 0 : 1,
		);

		$manager = new ScheduleManager();

		if ( $id ) {
			$ok = $manager->update( $id, $data );
			if ( ! $ok ) {
				wp_send_json_error( array( 'message' => sprintf( /* translators: %d: maximum number of active schedules */ __( 'This site allows at most %d active schedules. Disable another schedule first.', 'wrensafe' ), \WPBP\Core\LimitsManager::get_limit( 'scheduled_backups_count' ) ) ), 400 );
			}
		} else {
			if ( ! empty( $data['enabled'] ) && ! $manager->can_enable_another() ) {
				wp_send_json_error( array( 'message' => sprintf( /* translators: %d: maximum number of active schedules */ __( 'This site allows at most %d active schedules. Disable another schedule first.', 'wrensafe' ), \WPBP\Core\LimitsManager::get_limit( 'scheduled_backups_count' ) ) ), 400 );
			}
			$id = $manager->create( $data );
		}

		wp_send_json_success( array( 'id' => $id ) );
	}

	public static function delete(): void {
		check_ajax_referer( 'wpbp_admin_ajax', '_wpbp_nonce' );
		self::require_capability();

		$id = isset( $_POST['id'] ) ? absint( $_POST['id'] ) : 0;

		if ( ! $id || null === SchedulesRepository::find( $id ) ) {
			wp_send_json_error( array( 'message' => __( 'Schedule not found.', 'wrensafe' ) ), 404 );
		}

		( new ScheduleManager() )->delete( $id );

		wp_send_json_success();
	}

	public static function toggle(): void {
		check_ajax_referer( 'wpbp_admin_ajax', '_wpbp_nonce' );
		self::require_capability();

		$id       = isset( $_POST['id'] ) ? absint( $_POST['id'] ) : 0;
		$schedule = $id ? SchedulesRepository::find( $id ) : null;

		if ( null === $schedule ) {
			wp_send_json_error( array( 'message' => __( 'Schedule not found.', 'wrensafe' ) ), 404 );
		}

		$enabled = empty( $schedule['enabled'] ) ? 1 : 0;
		$manager = new ScheduleManager();

		if ( $enabled && ! $manager->can_enable_another() ) {
			wp_send_json_error( array( 'message' => sprintf( /* translators: %d: maximum number of active schedules */ __( 'This site allows at most %d active schedules. Disable another schedule first.', 'wrensafe' ), \WPBP\Core\LimitsManager::get_limit( 'scheduled_backups_count' ) ) ), 400 );
		}

		SchedulesRepository::update( $id, array( 'enabled' => $enabled ) );

		wp_send_json_success( array( 'enabled' => (bool) $enabled ) );
	}

	public static function run_now(): void {
		check_ajax_referer( 'wpbp_admin_ajax', '_wpbp_nonce' );
		self::require_capability();

		$id       = isset( $_POST['id'] ) ? absint( $_POST['id'] ) : 0;
		$schedule = $id ? SchedulesRepository::find( $id ) : null;

		if ( null === $schedule ) {
			wp_send_json_error( array( 'message' => __( 'Schedule not found.', 'wrensafe' ) ), 404 );
		}

		$job = ( new ScheduleManager() )->run_now( $schedule );

		wp_send_json_success( $job->to_array() );
	}
}
