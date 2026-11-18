<?php
/**
 * Registers Wrensafe's WP-Cron tick: a single recurring event that
 * checks every few minutes for schedules that are due to run.
 *
 * @package WPBP\Scheduler
 */

namespace WPBP\Scheduler;

use WPBP\Core\Database\BackupsRepository;
use WPBP\Core\LimitsManager;
use WPBP\Migration\TransferServer;
use WPBP\Utils\Logger;

defined( 'ABSPATH' ) || exit;

class CronHandler {

	const HOOK              = 'wpbp_cron_tick';
	const SCHEDULE_INTERVAL = 'wpbp_five_minutes';

	public function register_hooks(): void {
		add_filter( 'cron_schedules', array( $this, 'register_interval' ) ); // phpcs:ignore WordPress.WP.CronInterval.CronSchedulesInterval -- a 5-minute tick advances queued backup jobs in small steps; each tick exits immediately when nothing is due.
		add_action( self::HOOK, array( $this, 'run_tick' ) );
		add_action( 'init', array( $this, 'ensure_scheduled' ) );
	}

	/**
	 * @param mixed $schedules Existing schedules (another plugin's filter may hand over a non-array).
	 */
	public function register_interval( $schedules ): array {
		$schedules = is_array( $schedules ) ? $schedules : array();

		$schedules[ self::SCHEDULE_INTERVAL ] = array(
			'interval' => 5 * MINUTE_IN_SECONDS,
			'display'  => __( 'Every 5 minutes (Wrensafe)', 'wrensafe' ),
		);

		return $schedules;
	}

	public function ensure_scheduled(): void {
		if ( ! wp_next_scheduled( self::HOOK ) ) {
			wp_schedule_event( time(), self::SCHEDULE_INTERVAL, self::HOOK );
		}
	}

	public function run_tick(): void {
		( new ScheduleManager() )->process_due_schedules();
		$this->cleanup_old_incrementals();
		TransferServer::cleanup_abandoned_sessions();
	}

	/**
	 * Optional retention for incremental backups. Unlimited by default (nothing
	 * is deleted); an integrator can set a number of days via the
	 * wpbp_limit_value filter ('incremental_retention_days').
	 */
	private function cleanup_old_incrementals(): void {
		$days = LimitsManager::get_limit( 'incremental_retention_days' );
		$old  = LimitsManager::is_unlimited( 'incremental_retention_days' ) ? array() : BackupsRepository::list_old_incrementals( $days );

		foreach ( $old as $backup ) {
			if ( file_exists( $backup['file_path'] ) ) {
				wp_delete_file( $backup['file_path'] );
			}
			BackupsRepository::delete( (int) $backup['id'] );
		}

		if ( ! empty( $old ) ) {
			Logger::info( sprintf( 'Removed %d old incremental backup(s) from superseded chains.', count( $old ) ) );
		}
	}

	public static function unschedule(): void {
		$timestamp = wp_next_scheduled( self::HOOK );

		if ( $timestamp ) {
			wp_unschedule_event( $timestamp, self::HOOK );
		}
	}
}
