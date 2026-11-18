<?php
/**
 * Scheduled Backups page — create/edit schedules and review their last/next
 * run, backed by WP-Cron (see WPBP\Scheduler\CronHandler).
 *
 * @package WPBP\Admin\Pages
 */

namespace WPBP\Admin\Pages;

use WPBP\Core\Database\SchedulesRepository;
use WPBP\Core\LimitsManager;
use WPBP\Security\CapabilityManager;

defined( 'ABSPATH' ) || exit;

class SchedulePage extends AbstractPage {

	public function get_title(): string {
		return __( 'Scheduled Backups', 'wrensafe' );
	}

	protected function get_subtitle(): string {
		return __( 'Back up automatically on a schedule and keep only the newest copies.', 'wrensafe' );
	}

	protected function required_capability(): string {
		return CapabilityManager::MANAGE_SCHEDULES;
	}

	protected function render_content(): void {
		/**
		 * Replaces this page with an add-on's schedule manager (Wrensafe
		 * Pro: several schedules, custom intervals, cloud destinations).
		 */
		if ( has_action( 'wpbp_schedules_page' ) ) {
			do_action( 'wpbp_schedules_page' );
			return;
		}

		$schedules = SchedulesRepository::list_all();
		$date_time = get_option( 'date_format' ) . ' ' . get_option( 'time_format' );

		// Two schedules: the whole site (or files) and, separately, the
		// database, which changes far more often. Existing schedules keep
		// their place by type.
		$site_schedule = null;
		$db_schedule   = null;

		foreach ( $schedules as $row ) {
			if ( 'database' === $row['backup_type'] ) {
				$db_schedule = $db_schedule ?? $row;
			} else {
				$site_schedule = $site_schedule ?? $row;
			}
		}

		$this->render_schedule_form(
			'site',
			$site_schedule,
			__( 'Full website backups', 'wrensafe' ),
			__( 'Back up the whole site on a regular schedule. Older automatic backups are deleted so only the newest copies are kept.', 'wrensafe' ),
			__( 'Automatic backup', 'wrensafe' ),
			null === $db_schedule || null !== $site_schedule
		);

		$this->render_schedule_form(
			'database',
			$db_schedule,
			__( 'Database backups', 'wrensafe' ),
			__( 'Posts, orders and settings change more often than files. A separate, more frequent database backup is small and quick, for example every day while the full site is backed up every week.', 'wrensafe' ),
			__( 'Automatic database backup', 'wrensafe' ),
			null !== $db_schedule && ! empty( $db_schedule['enabled'] )
		);
		$schedule = $site_schedule ?? $db_schedule;
		?>

		<?php if ( null !== $schedule ) : ?>
			<div class="wpbp-card">
				<h2><?php esc_html_e( 'Schedule status', 'wrensafe' ); ?></h2>
				<table class="widefat striped wpbp-schedules-table">
					<thead>
					<tr>
						<th><?php esc_html_e( 'Backup', 'wrensafe' ); ?></th>
						<th><?php esc_html_e( 'Last run', 'wrensafe' ); ?></th>
						<th><?php esc_html_e( 'Next run', 'wrensafe' ); ?></th>
						<th><?php esc_html_e( 'Last result', 'wrensafe' ); ?></th>
						<th><?php esc_html_e( 'Actions', 'wrensafe' ); ?></th>
					</tr>
					</thead>
					<tbody>
					<?php foreach ( $schedules as $row ) : ?>
						<tr data-schedule-id="<?php echo esc_attr( $row['id'] ); ?>">
							<td><?php echo esc_html( \WPBP\Admin\Labels::backup_type( (string) $row['backup_type'] ) ); ?></td>
							<td><?php echo esc_html( $row['last_run_at'] ? mysql2date( $date_time, $row['last_run_at'] ) : '—' ); ?></td>
							<td><?php echo esc_html( $row['enabled'] && $row['next_run_at'] ? mysql2date( $date_time, $row['next_run_at'] ) : __( 'Paused', 'wrensafe' ) ); ?></td>
							<td><?php echo esc_html( $row['last_status'] ? \WPBP\Admin\Labels::status( (string) $row['last_status'] ) : '—' ); ?></td>
							<td class="wpbp-backup-actions">
								<button type="button" class="button button-small wpbp-sch-run-now"><?php esc_html_e( 'Run now', 'wrensafe' ); ?></button>
								<?php if ( $row !== $site_schedule && $row !== $db_schedule ) : ?>
									<button type="button" class="button button-small button-link-delete wpbp-sch-delete"><?php esc_html_e( 'Delete', 'wrensafe' ); ?></button>
								<?php endif; ?>
							</td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
			</div>
		<?php endif; ?>

		<div class="wpbp-card wpbp-backup-progress" id="wpbp-schedule-progress" hidden>
			<div class="wpbp-progress-bar"><div class="wpbp-progress-bar-fill" style="width:0%"></div></div>
			<p class="wpbp-progress-status"></p>
		</div>
		<?php
	}

	/**
	 * One schedule card. $slot 'site' lets the user choose full website or
	 * files only; 'database' backs up the database only.
	 *
	 * @param array<string,mixed>|null $schedule Saved schedule, or null.
	 */
	private function render_schedule_form( string $slot, ?array $schedule, string $heading, string $intro, string $title, bool $enabled_by_default ): void {
		$value     = static function ( string $key, $fallback ) use ( $schedule ) {
			return null !== $schedule && isset( $schedule[ $key ] ) && '' !== (string) $schedule[ $key ] ? $schedule[ $key ] : $fallback;
		};
		$is_db     = 'database' === $slot;
		$frequency = (string) $value( 'frequency', $is_db ? 'daily' : 'weekly' );
		$type      = $is_db ? 'database' : (string) $value( 'backup_type', 'full' );
		$enabled   = null === $schedule ? $enabled_by_default : ! empty( $schedule['enabled'] );
		$id        = 'wpbp-sch-' . $slot;
		?>
		<div class="wpbp-card wpbp-schedule-form" data-slot="<?php echo esc_attr( $slot ); ?>"<?php echo $is_db ? '' : ' id="wpbp-schedule-form"'; ?>>
			<h2><?php echo esc_html( $heading ); ?></h2>
			<p class="description"><?php echo esc_html( $intro ); ?></p>

			<input type="hidden" class="wpbp-sch-id" value="<?php echo esc_attr( (string) ( $schedule['id'] ?? 0 ) ); ?>" />
			<input type="hidden" class="wpbp-sch-title" value="<?php echo esc_attr( (string) $value( 'title', $title ) ); ?>" />
			<input type="hidden" class="wpbp-sch-destination" value="local" />

			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><?php esc_html_e( 'Status', 'wrensafe' ); ?></th>
					<td><label><input type="checkbox" class="wpbp-sch-enabled" id="<?php echo esc_attr( $id ); ?>-enabled" <?php checked( $enabled ); ?> /> <?php echo esc_html( $is_db ? __( 'Back up the database automatically', 'wrensafe' ) : __( 'Back up automatically', 'wrensafe' ) ); ?></label></td>
				</tr>
				<tr>
					<th scope="row"><label for="<?php echo esc_attr( $id ); ?>-frequency"><?php esc_html_e( 'How often', 'wrensafe' ); ?></label></th>
					<td>
						<select class="wpbp-sch-frequency" id="<?php echo esc_attr( $id ); ?>-frequency">
							<option value="daily" <?php selected( $frequency, 'daily' ); ?>><?php esc_html_e( 'Every day', 'wrensafe' ); ?></option>
							<option value="weekly" <?php selected( $frequency, 'weekly' ); ?>><?php esc_html_e( 'Every week', 'wrensafe' ); ?></option>
							<option value="monthly" <?php selected( $frequency, 'monthly' ); ?>><?php esc_html_e( 'Every month', 'wrensafe' ); ?></option>
						</select>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="<?php echo esc_attr( $id ); ?>-time"><?php esc_html_e( 'At', 'wrensafe' ); ?></label></th>
					<td>
						<input type="time" class="wpbp-sch-time" id="<?php echo esc_attr( $id ); ?>-time" value="<?php echo esc_attr( substr( (string) $value( 'run_time', $is_db ? '03:00' : '02:00' ), 0, 5 ) ); ?>" />
						<p class="description"><?php esc_html_e( 'Site time. A quiet hour is best.', 'wrensafe' ); ?></p>
					</td>
				</tr>
				<?php if ( $is_db ) : ?>
					<input type="hidden" class="wpbp-sch-type" value="database" />
				<?php else : ?>
					<tr>
						<th scope="row"><label for="<?php echo esc_attr( $id ); ?>-type"><?php esc_html_e( 'What to back up', 'wrensafe' ); ?></label></th>
						<td>
							<select class="wpbp-sch-type" id="<?php echo esc_attr( $id ); ?>-type">
								<option value="full" <?php selected( $type, 'full' ); ?>><?php esc_html_e( 'Full website', 'wrensafe' ); ?></option>
								<option value="files" <?php selected( $type, 'files' ); ?>><?php esc_html_e( 'Files only', 'wrensafe' ); ?></option>
							</select>
						</td>
					</tr>
				<?php endif; ?>
				<tr>
					<th scope="row"><label for="<?php echo esc_attr( $id ); ?>-retention"><?php esc_html_e( 'Keep', 'wrensafe' ); ?></label></th>
					<td><input type="number" class="small-text wpbp-sch-retention" id="<?php echo esc_attr( $id ); ?>-retention" value="<?php echo esc_attr( (string) $value( 'retention_count', $is_db ? 14 : 5 ) ); ?>" min="1" /> <?php echo esc_html( $is_db ? __( 'newest automatic database backups', 'wrensafe' ) : __( 'newest automatic backups', 'wrensafe' ) ); ?></td>
				</tr>
				<?php if ( ! $is_db ) : ?>
					<tr>
						<th scope="row"><?php esc_html_e( 'Email', 'wrensafe' ); ?></th>
						<td>
							<?php
							printf(
								/* translators: %s: link to Settings → Notifications */
								esc_html__( 'Emails about backups, including automatic ones, are set up under %s.', 'wrensafe' ),
								'<a href="' . esc_url( self::page_url( 'wrensafe-settings', array( 'tab' => 'notifications' ) ) ) . '">' . esc_html__( 'Settings → Notifications', 'wrensafe' ) . '</a>'
							);
							?>
						</td>
					</tr>
				<?php endif; ?>
			</table>

			<button type="button" class="button button-primary wpbp-sch-save"<?php echo $is_db ? '' : ' id="wpbp-sch-save"'; ?>><?php esc_html_e( 'Save schedule', 'wrensafe' ); ?></button>
		</div>
		<?php
	}
}
