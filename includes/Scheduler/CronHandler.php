<?php
/**
 * Registers Wrensafe's WP-Cron tick.
 *
 * The tick runs the scheduled backups that are due, then some housekeeping
 * (old incremental backups when a retention limit is set, unfinished Direct
 * Migration uploads that expired). Backups started by hand, restores and
 * remote copies are driven by the browser or by the backup itself, not by
 * the tick.
 *
 * While at least one schedule is enabled the tick runs every 5 minutes, so
 * a backup starts close to its set time (schedules are set to the minute).
 * Without enabled schedules it only does housekeeping, once a day. Saving,
 * turning on or off and deleting a schedule switch between the two
 * (reschedule()), and every tick checks again.
 *
 * @package WPBP\Scheduler
 */

namespace WPBP\Scheduler;

use WPBP\Core\Database\BackupsRepository;
use WPBP\Core\Database\SchedulesRepository;
use WPBP\Core\LimitsManager;
use WPBP\Migration\TransferServer;
use WPBP\Utils\Logger;

defined( 'ABSPATH' ) || exit;

class CronHandler {

	const HOOK              = 'wpbp_cron_tick';
	const SCHEDULE_INTERVAL = 'wpbp_five_minutes';

	/** Recurrence of the tick when no schedule is enabled. */
	const IDLE_INTERVAL = 'daily';

	public function register_hooks(): void {
		// phpcs:ignore WordPress.WP.CronInterval.CronSchedulesInterval -- used only while a backup schedule is enabled, so a backup starts within 5 minutes of its set time; otherwise the tick runs daily.
		add_filter( 'cron_schedules', array( $this, 'register_interval' ) );
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

	/**
	 * Schedule the tick if it isn't (a new install, or a restored database
	 * without it). Reads only the cron option when the tick exists.
	 */
	public function ensure_scheduled(): void {
		if ( ! wp_next_scheduled( self::HOOK ) ) {
			self::reschedule();
		}
	}

	/**
	 * Run the tick every 5 minutes while a schedule is enabled, otherwise
	 * once a day.
	 */
	public static function reschedule(): void {
		$wanted = SchedulesRepository::count_enabled() > 0 ? self::SCHEDULE_INTERVAL : self::IDLE_INTERVAL;
		$event  = wp_get_scheduled_event( self::HOOK );

		if ( $event && $wanted === $event->schedule ) {
			return;
		}

		wp_clear_scheduled_hook( self::HOOK );
		wp_schedule_event( self::IDLE_INTERVAL === $wanted ? time() + HOUR_IN_SECONDS : time(), $wanted, self::HOOK );
	}

	public function run_tick(): void {
		( new ScheduleManager() )->process_due_schedules();
		$this->cleanup_old_incrementals();
		TransferServer::cleanup_abandoned_sessions();

		// Schedules can also change without Wrensafe's screens (a restored
		// database, an add-on), so check the recurrence on every tick.
		self::reschedule();
	}

	/**
	 * Optional retention for incremental backups. Unlimited by default (nothing
	 * is deleted); an integrator can set a number of days via the
	 * wpbp_limit_value filter ('incremental_retention_days').
	 */
	private function cleanup_old_incrementals(): void {
		$days    = LimitsManager::get_limit( 'incremental_retention_days' );
		$old     = LimitsManager::is_unlimited( 'incremental_retention_days' ) ? array() : BackupsRepository::list_old_incrementals( $days );
		$removed = 0;

		foreach ( $old as $backup ) {
			if ( ! ScheduleManager::delete_backup( $backup ) ) {
				Logger::error( 'Incremental cleanup skipped backup #' . $backup['id'] . ': file path resolves outside the backups directory.' );
				continue;
			}
			++$removed;
		}

		if ( $removed > 0 ) {
			Logger::info( sprintf( 'Removed %d old incremental backup(s) from superseded chains.', $removed ) );
		}
	}

	public static function unschedule(): void {
		wp_clear_scheduled_hook( self::HOOK );
	}
}
