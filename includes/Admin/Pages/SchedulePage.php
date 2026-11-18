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
		$schedule  = $schedules[0] ?? null;
		$value     = static function ( string $key, $fallback ) use ( $schedule ) {
			return null !== $schedule && isset( $schedule[ $key ] ) && '' !== (string) $schedule[ $key ] ? $schedule[ $key ] : $fallback;
		};
		$frequency = (string) $value( 'frequency', 'daily' );
		$type      = (string) $value( 'backup_type', 'full' );
		$date_time = get_option( 'date_format' ) . ' ' . get_option( 'time_format' );
		?>
		<div class="wpbp-card" id="wpbp-schedule-form">
			<h2><?php esc_html_e( 'Automatic backups', 'wrensafe' ); ?></h2>
			<p class="description"><?php esc_html_e( 'Back up this site on a regular schedule. Older automatic backups are deleted so only the newest copies are kept.', 'wrensafe' ); ?></p>

			<input type="hidden" id="wpbp-sch-id" value="<?php echo esc_attr( (string) ( $schedule['id'] ?? 0 ) ); ?>" />
			<input type="hidden" id="wpbp-sch-title" value="<?php echo esc_attr( (string) $value( 'title', __( 'Automatic backup', 'wrensafe' ) ) ); ?>" />
			<input type="hidden" id="wpbp-sch-destination" value="local" />

			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><?php esc_html_e( 'Status', 'wrensafe' ); ?></th>
					<td><label><input type="checkbox" id="wpbp-sch-enabled" <?php checked( null === $schedule || ! empty( $schedule['enabled'] ) ); ?> /> <?php esc_html_e( 'Back up automatically', 'wrensafe' ); ?></label></td>
				</tr>
				<tr>
					<th scope="row"><label for="wpbp-sch-frequency"><?php esc_html_e( 'How often', 'wrensafe' ); ?></label></th>
					<td>
						<select id="wpbp-sch-frequency">
							<option value="daily" <?php selected( $frequency, 'daily' ); ?>><?php esc_html_e( 'Every day', 'wrensafe' ); ?></option>
							<option value="weekly" <?php selected( $frequency, 'weekly' ); ?>><?php esc_html_e( 'Every week', 'wrensafe' ); ?></option>
							<option value="monthly" <?php selected( $frequency, 'monthly' ); ?>><?php esc_html_e( 'Every month', 'wrensafe' ); ?></option>
						</select>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="wpbp-sch-time"><?php esc_html_e( 'At', 'wrensafe' ); ?></label></th>
					<td>
						<input type="time" id="wpbp-sch-time" value="<?php echo esc_attr( substr( (string) $value( 'run_time', '02:00' ), 0, 5 ) ); ?>" />
						<p class="description"><?php esc_html_e( 'Site time. A quiet hour is best.', 'wrensafe' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="wpbp-sch-type"><?php esc_html_e( 'What to back up', 'wrensafe' ); ?></label></th>
					<td>
						<select id="wpbp-sch-type">
							<option value="full" <?php selected( $type, 'full' ); ?>><?php esc_html_e( 'Full website', 'wrensafe' ); ?></option>
							<option value="database" <?php selected( $type, 'database' ); ?>><?php esc_html_e( 'Database only', 'wrensafe' ); ?></option>
							<option value="files" <?php selected( $type, 'files' ); ?>><?php esc_html_e( 'Files only', 'wrensafe' ); ?></option>
						</select>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="wpbp-sch-retention"><?php esc_html_e( 'Keep', 'wrensafe' ); ?></label></th>
					<td><input type="number" id="wpbp-sch-retention" class="small-text" value="<?php echo esc_attr( (string) $value( 'retention_count', 5 ) ); ?>" min="1" /> <?php esc_html_e( 'newest automatic backups', 'wrensafe' ); ?></td>
				</tr>
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
			</table>

			<button type="button" id="wpbp-sch-save" class="button button-primary"><?php esc_html_e( 'Save schedule', 'wrensafe' ); ?></button>
		</div>

		<?php if ( null !== $schedule ) : ?>
			<div class="wpbp-card">
				<h2><?php esc_html_e( 'Schedule status', 'wrensafe' ); ?></h2>
				<table class="widefat striped wpbp-schedules-table">
					<thead>
					<tr>
						<th><?php esc_html_e( 'Last run', 'wrensafe' ); ?></th>
						<th><?php esc_html_e( 'Next run', 'wrensafe' ); ?></th>
						<th><?php esc_html_e( 'Last result', 'wrensafe' ); ?></th>
						<th><?php esc_html_e( 'Actions', 'wrensafe' ); ?></th>
					</tr>
					</thead>
					<tbody>
					<?php foreach ( $schedules as $row ) : ?>
						<tr data-schedule-id="<?php echo esc_attr( $row['id'] ); ?>">
							<td><?php echo esc_html( $row['last_run_at'] ? mysql2date( $date_time, $row['last_run_at'] ) : '—' ); ?></td>
							<td><?php echo esc_html( $row['enabled'] && $row['next_run_at'] ? mysql2date( $date_time, $row['next_run_at'] ) : __( 'Paused', 'wrensafe' ) ); ?></td>
							<td><?php echo esc_html( $row['last_status'] ? \WPBP\Admin\Labels::status( (string) $row['last_status'] ) : '—' ); ?></td>
							<td class="wpbp-backup-actions">
								<button type="button" class="button button-small wpbp-sch-run-now"><?php esc_html_e( 'Run now', 'wrensafe' ); ?></button>
								<?php if ( $row !== $schedule ) : ?>
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
}
