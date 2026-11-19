<?php
/**
 * CRUD and execution logic for scheduled backups.
 *
 * @package WPBP\Scheduler
 */

namespace WPBP\Scheduler;

use WPBP\Backup\BackupJob;
use WPBP\Backup\BackupManager;
use WPBP\Core\Database\BackupsRepository;
use WPBP\Core\Database\SchedulesRepository;
use WPBP\Core\LimitsManager;
use WPBP\Utils\FileSystem;
use WPBP\Utils\Logger;

defined( 'ABSPATH' ) || exit;

class ScheduleManager {

	const FREQUENCIES = array( 'daily', 'weekly', 'monthly', 'custom' );

	/**
	 * Whether a new schedule can be enabled under the current limit (none in the free plugin).
	 */
	public function can_enable_another(): bool {
		return LimitsManager::is_within_limit( 'scheduled_backups_count', SchedulesRepository::count_enabled() );
	}

	public function create( array $data ): int {
		if ( ! empty( $data['enabled'] ) && ! $this->can_enable_another() ) {
			$data['enabled'] = 0;
		}

		$data['next_run_at'] = $this->calculate_first_run( $data['run_time'] ?? '00:00' );

		$id = SchedulesRepository::create( $data );
		CronHandler::reschedule();

		return $id;
	}

	public function update( int $id, array $data ): bool {
		if ( ! empty( $data['enabled'] ) && ! $this->can_enable_another() ) {
			$existing = SchedulesRepository::find( $id );
			if ( empty( $existing['enabled'] ) ) {
				return false;
			}
		}

		// A changed run time or frequency takes effect from the next
		// occurrence of the new time, not the old schedule's slot.
		if ( isset( $data['run_time'] ) || isset( $data['frequency'] ) ) {
			$data['next_run_at'] = $this->calculate_first_run( $data['run_time'] ?? '00:00' );
		}

		SchedulesRepository::update( $id, $data );
		CronHandler::reschedule();

		return true;
	}

	/** Turn a schedule on or off. */
	public function set_enabled( int $id, bool $enabled ): void {
		SchedulesRepository::update( $id, array( 'enabled' => $enabled ? 1 : 0 ) );
		CronHandler::reschedule();
	}

	public function delete( int $id ): void {
		SchedulesRepository::delete( $id );
		CronHandler::reschedule();
	}

	/**
	 * Create and return a BackupJob for a schedule, to be driven to
	 * completion by the caller (AJAX polling for "Run Now", or the cron
	 * tick for scheduled execution).
	 */
	public function run_now( array $schedule ): BackupJob {
		return BackupJob::create(
			array(
				'backup_type' => $schedule['backup_type'],
				'note'        => sprintf( 'Scheduled backup: %s', $schedule['title'] ),
				'schedule_id' => $schedule['id'],
			)
		);
	}

	/**
	 * Process every schedule that's currently due, driving each one's backup
	 * job to completion synchronously (cron requests aren't browser-polled).
	 */
	public function process_due_schedules(): void {
		foreach ( SchedulesRepository::get_due() as $schedule ) {
			$this->run_schedule_to_completion( $schedule );
		}
	}

	/**
	 * Run a schedule immediately and drive it to completion synchronously,
	 * for callers that need a result back rather than browser-polled
	 * progress — e.g. WP-CLI's `wp wpbp schedule_run`.
	 *
	 * @return array{status:string,backup_id:?int,error_message:string}
	 */
	public function run_schedule_now_to_completion( int $schedule_id ): array {
		$schedule = SchedulesRepository::find( $schedule_id );

		if ( null === $schedule ) {
			return array(
				'status'        => BackupJob::STATUS_FAILED,
				'backup_id'     => null,
				'error_message' => __( 'Schedule not found.', 'wrensafe' ),
			);
		}

		$job = $this->run_schedule_to_completion( $schedule );

		return array(
			'status'        => $job->status(),
			'backup_id'     => $job->result()['backup_id'] ?? null,
			'error_message' => $job->error_message(),
		);
	}

	private function run_schedule_to_completion( array $schedule ): BackupJob {
		$job     = $this->run_now( $schedule );
		$manager = new BackupManager();

		$max_steps = 2000; // safety cap against runaway loops.
		$steps     = 0;

		while ( ! in_array( $job->status(), array( BackupJob::STATUS_COMPLETED, BackupJob::STATUS_FAILED, BackupJob::STATUS_CANCELLED ), true ) && $steps < $max_steps ) {
			$manager->process_step( $job );
			++$steps;
		}

		SchedulesRepository::update(
			(int) $schedule['id'],
			array(
				'last_run_at' => current_time( 'mysql' ),
				'last_status' => $job->status(),
				'next_run_at' => $this->calculate_next_run( $schedule ),
			)
		);

		if ( BackupJob::STATUS_COMPLETED === $job->status() ) {
			$this->apply_retention( $schedule );
		} else {
			// The email (Settings → Notifications) is sent by Notifier.
			Logger::error( 'Scheduled backup "' . $schedule['title'] . '" failed: ' . $job->error_message() );
		}

		return $job;
	}

	/**
	 * The site's local time as a number to compare with the stored schedule
	 * times. Those are site-local "Y-m-d H:i:s" strings, read with
	 * strtotime() (UTC in WordPress), so "now" is read the same way.
	 */
	private static function local_now(): int {
		return (int) strtotime( current_time( 'mysql' ) );
	}

	/**
	 * First run time: today at run_time, or tomorrow if that has already passed.
	 */
	public function calculate_first_run( string $run_time ): string {
		$today_at_time = current_time( 'Y-m-d' ) . ' ' . $run_time . ':00';

		if ( strtotime( $today_at_time ) <= self::local_now() ) {
			$today_at_time = gmdate( 'Y-m-d H:i:s', strtotime( $today_at_time . ' +1 day' ) );
		}

		return $today_at_time;
	}

	/**
	 * Next run time: previous next_run_at plus the schedule's interval, to
	 * avoid time-of-day drift. Runs missed while the site was idle (WP-Cron
	 * only runs on visits) are skipped, not caught up one after another: the
	 * result is always the first slot after now.
	 */
	public function calculate_next_run( array $schedule ): string {
		$base = $schedule['next_run_at'] ? $schedule['next_run_at'] : current_time( 'mysql' );

		switch ( $schedule['frequency'] ) {
			case 'weekly':
				$interval = '+7 days';
				break;

			case 'monthly':
				$interval = '+1 month';
				break;

			case 'custom':
				$seconds  = (int) ( $schedule['custom_interval_seconds'] ?? DAY_IN_SECONDS );
				$interval = '+' . $seconds . ' seconds';
				break;

			case 'daily':
			default:
				$interval = '+1 day';
				break;
		}

		$now  = self::local_now();
		$next = strtotime( $base . ' ' . $interval );

		for ( $i = 0; false !== $next && $next <= $now && $i < 1000; $i++ ) {
			$next = strtotime( gmdate( 'Y-m-d H:i:s', $next ) . ' ' . $interval );
		}

		if ( false === $next || $next <= $now ) {
			$next = strtotime( current_time( 'mysql' ) . ' ' . $interval );
		}

		// Daily, weekly and monthly backups run at the schedule's time of day,
		// even if an earlier run (or an older version) drifted from it.
		$run_time = (string) ( $schedule['run_time'] ?? '' );

		if ( 'custom' !== $schedule['frequency'] && preg_match( '/^\d{2}:\d{2}$/', $run_time ) ) {
			$aligned = strtotime( gmdate( 'Y-m-d', $next ) . ' ' . $run_time . ':00' );
			$next    = ( false !== $aligned && $aligned <= $now ) ? strtotime( gmdate( 'Y-m-d H:i:s', $aligned ) . ' ' . $interval ) : ( false !== $aligned ? $aligned : $next );
		}

		return gmdate( 'Y-m-d H:i:s', $next );
	}

	/**
	 * Delete a backup record and its file. The path comes from the database,
	 * so a file outside Wrensafe's backup folders is never deleted: the
	 * backup is then kept and false returned.
	 *
	 * @param array<string,mixed> $backup A backups table row.
	 */
	public static function delete_backup( array $backup ): bool {
		$path = (string) ( $backup['file_path'] ?? '' );

		if ( '' !== $path && ! FileSystem::is_backup_file_path( $path ) ) {
			return false;
		}

		if ( '' !== $path && file_exists( $path ) ) {
			wp_delete_file( $path );
		}

		BackupsRepository::delete( (int) $backup['id'] );

		return true;
	}

	/**
	 * Delete this schedule's oldest backups beyond its retention count.
	 * Locked backups are never auto-deleted.
	 */
	private function apply_retention( array $schedule ): void {
		$retention = (int) ( $schedule['retention_count'] ?? 0 );

		if ( $retention <= 0 ) {
			return;
		}

		$backups   = BackupsRepository::list_for_schedule( (int) $schedule['id'] );
		$unlocked  = array_values( array_filter( $backups, static fn( $b ) => empty( $b['is_locked'] ) ) );
		$to_delete = array_slice( $unlocked, $retention );

		$removed = 0;

		foreach ( $to_delete as $backup ) {
			if ( ! self::delete_backup( $backup ) ) {
				Logger::error( 'Retention cleanup skipped backup #' . $backup['id'] . ': file path resolves outside the backups directory.' );
				continue;
			}
			++$removed;
		}

		if ( $removed > 0 ) {
			Logger::info( sprintf( 'Retention cleanup removed %d old backup(s) for schedule "%s".', $removed, $schedule['title'] ) );
		}
	}
}
