<?php
/**
 * Dashboard page.
 *
 * @package WPBP\Admin\Pages
 */

namespace WPBP\Admin\Pages;

use WPBP\Admin\SupportUs;
use WPBP\Core\Database\BackupsRepository;
use WPBP\Core\Database\RestorePointsRepository;
use WPBP\Core\Settings;
use WPBP\Security\CapabilityManager;
use WPBP\Utils\Time;
use WPBP\Core\Database\SchedulesRepository;
use WPBP\Migration\HealthCheck;

defined( 'ABSPATH' ) || exit;

class DashboardPage extends AbstractPage {

	public function get_title(): string {
		return __( 'Dashboard', 'wrensafe' );
	}

	protected function get_subtitle(): string {
		return __( 'Backup, migrate, and restore WordPress with confidence.', 'wrensafe' );
	}

	protected function get_header_actions(): array {
		return array(
			array(
				'label'   => __( 'Restore a backup', 'wrensafe' ),
				'url'     => self::page_url( 'wrensafe-import-restore' ),
				'variant' => 'secondary',
				'icon'    => 'dashicons-database-import',
				'cap'     => \WPBP\Security\CapabilityManager::RESTORE_BACKUPS,
			),
			array(
				'label'   => __( 'Create backup', 'wrensafe' ),
				'url'     => self::page_url( 'wrensafe-backup' ),
				'variant' => 'cta',
				'cap'     => \WPBP\Security\CapabilityManager::CREATE_BACKUPS,
			),
		);
	}

	/**
	 * Relative time for a site-local MySQL datetime: "2 hours ago" / "in 9 hours".
	 */
	private static function relative( string $mysql ): string {
		$ts = Time::to_timestamp( $mysql );

		if ( 0 === $ts ) {
			return '';
		}

		if ( $ts > time() ) {
			/* translators: %s: human-readable time difference, e.g. "9 hours" */
			return sprintf( __( 'in %s', 'wrensafe' ), human_time_diff( time(), $ts ) );
		}

		return Time::human_diff( $ts );
	}

	/**
	 * Label for a backup type key.
	 */
	private static function type_label( string $type ): string {
		return \WPBP\Admin\Labels::backup_type( $type );
	}

	/**
	 * Same nonce-protected, scheme-relative URL the Backup Browser uses.
	 */
	private static function download_url( int $backup_id ): string {
		return set_url_scheme(
			wp_nonce_url(
				add_query_arg(
					array(
						'action'    => 'wpbp_download_backup',
						'backup_id' => $backup_id,
					),
					admin_url( 'admin-post.php' )
				),
				'wpbp_download_backup',
				'_wpbp_nonce'
			),
			'relative'
		);
	}

	/**
	 * Work out the protection state shown in the hero (first match wins).
	 *
	 * @return array{state:string,tone:string,icon:string,title:string,facts:string[],cta:string}
	 */
	private function protection_state( int $completed, ?array $latest_full, ?array $latest_any, int $enabled, ?array $next_schedule ): array {
		$next_run = $next_schedule['next_run_at'] ?? '';
		$overdue  = '' !== $next_run && Time::to_timestamp( $next_run ) < time() - 10 * MINUTE_IN_SECONDS;
		$stale    = (int) apply_filters( 'wpbp_dashboard_stale_days', 7 ) * DAY_IN_SECONDS;
		$full_age = $latest_full ? time() - Time::to_timestamp( $latest_full['created_at'] ) : null;

		$last_full_fact = $latest_full
			? sprintf(
				/* translators: 1: relative time, 2: archive size */
				__( 'Last full backup %1$s · %2$s · Completed', 'wrensafe' ),
				self::relative( $latest_full['created_at'] ),
				size_format( (int) $latest_full['size'] )
			)
			: '';

		$next_fact = $next_schedule
			? sprintf(
				/* translators: 1: schedule name, 2: relative time, 3: number of active schedules */
				_n( 'Next: %1$s %2$s · %3$d schedule active', 'Next: %1$s %2$s · %3$d schedules active', $enabled, 'wrensafe' ),
				$next_schedule['title'],
				self::relative( $next_run ),
				$enabled
			)
			: '';

		if ( 0 === $completed ) {
			return array(
				'state' => 'unprotected',
				'icon'  => 'dashicons-backup',
				'title' => __( 'No backups yet', 'wrensafe' ),
				'facts' => array( __( 'Create a full backup to protect this site.', 'wrensafe' ) ),
				'cta'   => __( 'Create first backup', 'wrensafe' ),
			);
		}

		if ( ! $latest_full ) {
			return array(
				'state' => 'attention',
				'icon'  => 'dashicons-warning',
				'title' => __( 'No full-site backup yet', 'wrensafe' ),
				'facts' => array(
					$latest_any ? sprintf(
						/* translators: 1: backup type, 2: relative time */
						__( 'Last backup: %1$s, %2$s', 'wrensafe' ),
						self::type_label( (string) $latest_any['backup_type'] ),
						self::relative( $latest_any['created_at'] )
					) : '',
					__( 'Partial backups cannot restore the whole site.', 'wrensafe' ),
				),
				'cta'   => __( 'Create full backup', 'wrensafe' ),
			);
		}

		if ( $overdue ) {
			return array(
				'state' => 'attention',
				'icon'  => 'dashicons-warning',
				'title' => __( 'Scheduled backups are overdue', 'wrensafe' ),
				'facts' => array(
					/* translators: %s: relative time */
					sprintf( __( 'The next run was due %s.', 'wrensafe' ), self::relative( $next_run ) ),
					__( 'WP-Cron only runs when someone visits the site. On low-traffic sites, set up a server cron job.', 'wrensafe' ),
				),
				'cta'   => __( 'Back up now', 'wrensafe' ),
			);
		}

		if ( null !== $full_age && $full_age > $stale ) {
			return array(
				'state' => 'attention',
				'icon'  => 'dashicons-warning',
				/* translators: %d: number of days */
				'title' => sprintf( _n( 'Your last full backup is %d day old', 'Your last full backup is %d days old', (int) floor( $full_age / DAY_IN_SECONDS ), 'wrensafe' ), (int) floor( $full_age / DAY_IN_SECONDS ) ),
				'facts' => array( $last_full_fact, $next_fact ),
				'cta'   => __( 'Back up now', 'wrensafe' ),
			);
		}

		if ( 0 === $enabled ) {
			return array(
				'state' => 'attention',
				'icon'  => 'dashicons-calendar-alt',
				'title' => __( 'Automatic backups are off', 'wrensafe' ),
				'facts' => array( $last_full_fact, __( 'Add a schedule so you do not have to remember.', 'wrensafe' ) ),
				'cta'   => __( 'Back up now', 'wrensafe' ),
			);
		}

		return array(
			'state' => 'protected',
			'icon'  => 'dashicons-yes-alt',
			'title' => __( 'Your site is backed up', 'wrensafe' ),
			'facts' => array( $last_full_fact, $next_fact ),
			'cta'   => __( 'Back up now', 'wrensafe' ),
		);
	}

	protected function render_content(): void {
		// Sweep stale (server-killed) backup jobs on dashboard load (throttled).
		\WPBP\Backup\BackupManager::fail_stale_jobs();

		// Same count logic as the Backup Browser menu badge.
		$completed   = BackupsRepository::count_completed();
		$latest_full = BackupsRepository::find_latest_of_type( 'full' );
		$recent      = BackupsRepository::list_all( 5 );
		$latest_any  = $recent[0] ?? null;
		$enabled     = SchedulesRepository::count_enabled();
		$health      = ( new HealthCheck() )->run();
		$warnings    = count( array_filter( $health['checks'], static fn( $c ) => 'pass' !== $c['status'] ) );
		$points      = count( RestorePointsRepository::list_all( 200 ) );

		$next_schedule = null;
		foreach ( SchedulesRepository::list_all() as $schedule ) {
			if ( ! empty( $schedule['enabled'] ) && ! empty( $schedule['next_run_at'] ) && ( null === $next_schedule || $schedule['next_run_at'] < $next_schedule['next_run_at'] ) ) {
				$next_schedule = $schedule;
			}
		}

		$hero          = $this->protection_state( $completed, $latest_full, $latest_any, $enabled, $next_schedule );
		$tone          = 'protected' === $hero['state'] ? 'is-protected' : ( 'unprotected' === $hero['state'] ? 'is-unprotected' : 'is-attention' );
		$downloadable  = $latest_full && ( empty( $latest_full['is_locked'] ) || Settings::get( 'allow_locked_backup_downloads', false ) );
		$can_create    = CapabilityManager::current_user_can( CapabilityManager::CREATE_BACKUPS );
		$can_download  = CapabilityManager::current_user_can( CapabilityManager::DOWNLOAD_BACKUPS );
		$schedules_due = $next_schedule ? self::relative( $next_schedule['next_run_at'] ) : '';
		?>
		<section class="wpbp-hero <?php echo esc_attr( $tone ); ?>" aria-labelledby="wpbp-hero-title">
			<span class="wpbp-hero-icon" aria-hidden="true"><span class="dashicons <?php echo esc_attr( $hero['icon'] ); ?>"></span></span>
			<div class="wpbp-hero-main">
				<p class="wpbp-hero-eyebrow"><?php esc_html_e( 'Protection status', 'wrensafe' ); ?></p>
				<h2 class="wpbp-hero-title" id="wpbp-hero-title"><?php echo esc_html( $hero['title'] ); ?></h2>
				<ul class="wpbp-hero-facts">
					<?php foreach ( array_values( array_filter( $hero['facts'] ) ) as $i => $fact ) : ?>
						<li><span class="dashicons <?php echo esc_attr( 0 === $i ? 'dashicons-backup' : 'dashicons-calendar-alt' ); ?>" aria-hidden="true"></span><?php echo esc_html( $fact ); ?></li>
					<?php endforeach; ?>
				</ul>
			</div>
			<div class="wpbp-hero-actions">
				<?php if ( $completed > 0 ) : ?>
					<a class="button button-hero" href="<?php echo esc_url( self::page_url( 'wrensafe-browser' ) ); ?>"><?php esc_html_e( 'View backups', 'wrensafe' ); ?></a>
				<?php endif; ?>
				<?php if ( $can_create ) : ?>
					<a class="button button-primary button-hero wpbp-button-cta" href="<?php echo esc_url( self::page_url( 'wrensafe-backup' ) ); ?>"><?php echo esc_html( $hero['cta'] ); ?></a>
				<?php endif; ?>
			</div>
			<div class="wpbp-hero-footer">
				<span class="dashicons dashicons-info-outline" aria-hidden="true"></span>
				<span><?php esc_html_e( 'Backups are stored on this server. Download a copy now and then for off-site safety.', 'wrensafe' ); ?></span>
				<?php if ( $downloadable && $can_download ) : ?>
					<a class="wpbp-hero-footer-link" href="<?php echo esc_url( self::download_url( (int) $latest_full['id'] ) ); ?>"><?php esc_html_e( 'Download latest', 'wrensafe' ); ?></a>
				<?php endif; ?>
			</div>
		</section>

		<?php \WPBP\Admin\Onboarding::render_checklist(); ?>

		<div class="wpbp-stat-grid">
			<div class="wpbp-stat">
				<p class="wpbp-stat-label"><span class="dashicons dashicons-archive" aria-hidden="true"></span><?php esc_html_e( 'Backups', 'wrensafe' ); ?></p>
				<p class="wpbp-stat-value"><?php echo esc_html( number_format_i18n( $completed ) ); ?></p>
				<p class="wpbp-stat-meta">
					<?php
					echo $completed > 0
						/* translators: %s: total size of all backups */
						? esc_html( sprintf( __( '%s on this server', 'wrensafe' ), size_format( BackupsRepository::sum_size() ) ) )
						: esc_html__( 'Nothing stored yet', 'wrensafe' );
					?>
				</p>
				<a class="wpbp-stat-link" href="<?php echo esc_url( self::page_url( 'wrensafe-browser' ) ); ?>"><?php esc_html_e( 'Browse backups', 'wrensafe' ); ?></a>
			</div>
			<div class="wpbp-stat">
				<p class="wpbp-stat-label"><span class="dashicons dashicons-calendar-alt" aria-hidden="true"></span><?php esc_html_e( 'Schedules', 'wrensafe' ); ?></p>
				<p class="wpbp-stat-value">
					<?php
					echo $enabled > 0
						/* translators: %d: number of active schedules */
						? esc_html( sprintf( __( '%d active', 'wrensafe' ), $enabled ) )
						: esc_html__( 'None', 'wrensafe' );
					?>
				</p>
				<?php if ( $next_schedule && Time::to_timestamp( $next_schedule['next_run_at'] ) < time() - 10 * MINUTE_IN_SECONDS ) : ?>
					<p class="wpbp-stat-meta is-warning"><span class="dashicons dashicons-warning" aria-hidden="true"></span><?php /* translators: %s: relative time */ echo esc_html( sprintf( __( 'Overdue since %s', 'wrensafe' ), $schedules_due ) ); ?></p>
				<?php elseif ( $next_schedule ) : ?>
					<p class="wpbp-stat-meta"><?php /* translators: %s: relative time, e.g. "in 9 hours" */ echo esc_html( sprintf( __( 'Next run %s', 'wrensafe' ), $schedules_due ) ); ?></p>
				<?php else : ?>
					<p class="wpbp-stat-meta"><?php esc_html_e( 'Backups run only when you start them', 'wrensafe' ); ?></p>
				<?php endif; ?>
				<a class="wpbp-stat-link" href="<?php echo esc_url( self::page_url( 'wrensafe-schedule' ) ); ?>"><?php echo $enabled > 0 ? esc_html__( 'Manage schedules', 'wrensafe' ) : esc_html__( 'Add a schedule', 'wrensafe' ); ?></a>
			</div>
			<div class="wpbp-stat">
				<p class="wpbp-stat-label"><span class="dashicons dashicons-search" aria-hidden="true"></span><?php esc_html_e( 'Migration readiness', 'wrensafe' ); ?></p>
				<p class="wpbp-stat-value"><?php echo esc_html( (string) (int) $health['score'] ); ?><small>/100</small></p>
				<?php if ( 0 === $warnings ) : ?>
					<p class="wpbp-stat-meta is-success"><span class="dashicons dashicons-yes-alt" aria-hidden="true"></span><?php esc_html_e( 'All checks passed', 'wrensafe' ); ?></p>
				<?php else : ?>
					<p class="wpbp-stat-meta is-warning"><span class="dashicons dashicons-warning" aria-hidden="true"></span><?php /* translators: %d: number of warnings */ echo esc_html( sprintf( _n( '%d warning', '%d warnings', $warnings, 'wrensafe' ), $warnings ) ); ?></p>
				<?php endif; ?>
				<a class="wpbp-stat-link" href="<?php echo esc_url( self::page_url( 'wrensafe-system-info' ) ); ?>"><?php esc_html_e( 'View checks', 'wrensafe' ); ?></a>
			</div>
			<div class="wpbp-stat">
				<p class="wpbp-stat-label"><span class="dashicons dashicons-undo" aria-hidden="true"></span><?php esc_html_e( 'Rollback points', 'wrensafe' ); ?></p>
				<p class="wpbp-stat-value"><?php echo esc_html( number_format_i18n( $points ) ); ?></p>
				<p class="wpbp-stat-meta"><?php esc_html_e( 'Created automatically before each restore', 'wrensafe' ); ?></p>
				<a class="wpbp-stat-link" href="<?php echo esc_url( self::page_url( 'wrensafe-rollback' ) ); ?>"><?php esc_html_e( 'View rollback points', 'wrensafe' ); ?></a>
			</div>
		</div>

		<div class="wpbp-dashboard-columns">
			<div class="wpbp-dashboard-main">
				<?php $this->render_recent_backups( $recent, $completed ); ?>
				<?php $this->render_recent_activity(); ?>
			</div>
			<?php $this->render_tools(); ?>
		</div>

		<?php SupportUs::render_card(); ?>
		<?php
	}

	/**
	 * @param array<int,array<string,mixed>> $recent Latest backups.
	 */
	private function render_recent_backups( array $recent, int $completed ): void {
		$downloads_allowed = (bool) Settings::get( 'allow_locked_backup_downloads', false );
		unset( $downloads_allowed );
		?>
		<div class="wpbp-card wpbp-recent-backups">
			<div class="wpbp-card-header">
				<h2 class="wpbp-card-title"><?php esc_html_e( 'Recent backups', 'wrensafe' ); ?></h2>
				<?php if ( $completed > 0 ) : ?>
					<a class="button wpbp-button-ghost button-small" href="<?php echo esc_url( self::page_url( 'wrensafe-browser' ) ); ?>">
						<?php /* translators: %d: number of backups */ echo esc_html( sprintf( __( 'View all (%d)', 'wrensafe' ), $completed ) ); ?>
					</a>
				<?php endif; ?>
			</div>
			<?php if ( empty( $recent ) ) : ?>
				<div class="wpbp-empty">
					<span class="dashicons dashicons-archive" aria-hidden="true"></span>
					<p class="wpbp-empty-title"><?php esc_html_e( 'No backups yet', 'wrensafe' ); ?></p>
					<p class="wpbp-empty-text"><?php esc_html_e( 'Your backups will be listed here.', 'wrensafe' ); ?></p>
					<a class="button button-primary" href="<?php echo esc_url( self::page_url( 'wrensafe-backup' ) ); ?>"><?php esc_html_e( 'Create backup', 'wrensafe' ); ?></a>
				</div>
			<?php else : ?>
				<ul class="wpbp-list">
					<?php foreach ( $recent as $backup ) : ?>
						<?php
						$type   = (string) $backup['backup_type'];
						$locked = ! empty( $backup['is_locked'] );
						$status = (string) $backup['status'];
						$tone   = 'completed' === $status ? 'is-success' : ( in_array( $status, array( 'failed', 'corrupted' ), true ) ? 'is-danger' : 'is-info' );
						$title  = '' !== (string) $backup['note'] ? (string) $backup['note'] : sprintf( /* translators: %s: backup type */ __( '%s backup', 'wrensafe' ), self::type_label( $type ) );
						?>
						<li class="wpbp-list-item">
							<span class="wpbp-list-icon" aria-hidden="true"><span class="dashicons <?php echo esc_attr( 'database' === $type ? 'dashicons-database' : ( 'full' === $type ? 'dashicons-admin-site-alt3' : 'dashicons-portfolio' ) ); ?>"></span></span>
							<div class="wpbp-list-body">
								<p class="wpbp-list-title">
									<?php echo esc_html( $title ); ?>
									<span class="wpbp-pill has-dot <?php echo esc_attr( $tone ); ?>"><?php echo esc_html( \WPBP\Admin\Labels::status( $status ) ); ?></span>
									<?php if ( $locked ) : ?>
										<span class="wpbp-pill"><span class="dashicons dashicons-lock" aria-hidden="true"></span><?php esc_html_e( 'Locked', 'wrensafe' ); ?></span>
									<?php endif; ?>
								</p>
								<p class="wpbp-list-meta">
									<?php echo esc_html( self::type_label( $type ) . ' · ' . size_format( (int) $backup['size'] ) . ' · ' ); ?>
									<?php echo wp_kses( Time::local_time_markup( (string) $backup['created_at'] ), \WPBP\Admin\Kses::allowed() ); ?>
								</p>
							</div>
							<a class="button button-small wpbp-button-tonal<?php echo $locked ? ' disabled' : ''; ?>" <?php echo $locked ? 'aria-disabled="true"' : ''; ?> href="<?php echo esc_url( self::page_url( 'wrensafe-import-restore', array( 'backup_id' => (int) $backup['id'] ) ) ); ?>"><?php esc_html_e( 'Restore', 'wrensafe' ); ?></a>
						</li>
					<?php endforeach; ?>
				</ul>
			<?php endif; ?>
		</div>
		<?php
	}

	private function render_recent_activity(): void {
		$entries = \WPBP\Core\Database\LogsRepository::get_recent_levels( 6, array( 'success', 'warning', 'error', 'failed', 'critical' ) );
		$icons   = array(
			'success'  => array( 'is-success', 'dashicons-yes-alt' ),
			'warning'  => array( 'is-warning', 'dashicons-warning' ),
			'error'    => array( 'is-danger', 'dashicons-dismiss' ),
			'failed'   => array( 'is-danger', 'dashicons-dismiss' ),
			'critical' => array( 'is-danger', 'dashicons-dismiss' ),
		);
		?>
		<div class="wpbp-card wpbp-recent-activity">
			<div class="wpbp-card-header">
				<h2 class="wpbp-card-title"><?php esc_html_e( 'Recent activity', 'wrensafe' ); ?></h2>
				<?php if ( CapabilityManager::current_user_can( CapabilityManager::VIEW_LOGS ) ) : ?>
					<a class="button wpbp-button-ghost button-small" href="<?php echo esc_url( self::page_url( 'wrensafe-logs' ) ); ?>"><?php esc_html_e( 'View logs', 'wrensafe' ); ?></a>
				<?php endif; ?>
			</div>
			<?php if ( empty( $entries ) ) : ?>
				<p class="wpbp-empty-text"><?php esc_html_e( 'No activity yet.', 'wrensafe' ); ?></p>
			<?php else : ?>
				<ul class="wpbp-list wpbp-list-compact">
					<?php foreach ( $entries as $entry ) : ?>
						<?php
						list( $tone, $icon ) = $icons[ (string) $entry['level'] ] ?? array( 'is-neutral', 'dashicons-marker' );
						$message             = ucfirst( (string) preg_replace( '/^[a-z0-9_]+:\s+/', '', (string) $entry['message'] ) );
						$when                = (string) $entry['created_at'];
						?>
						<li class="wpbp-list-item">
							<span class="wpbp-list-state <?php echo esc_attr( $tone ); ?>" aria-hidden="true"><span class="dashicons <?php echo esc_attr( $icon ); ?>"></span></span>
							<p class="wpbp-list-title"><?php echo esc_html( wp_trim_words( $message, 18 ) ); ?></p>
							<span class="wpbp-list-time" title="<?php echo esc_attr( $when ); ?>"><?php echo esc_html( self::relative( $when ) ); ?></span>
						</li>
					<?php endforeach; ?>
				</ul>
			<?php endif; ?>
		</div>
		<?php
	}

	private function render_tools(): void {
		$tools = array(
			array( __( 'Import a backup', 'wrensafe' ), 'wrensafe-import-restore', 'dashicons-database-import', CapabilityManager::RESTORE_BACKUPS, false ),
			array( __( 'Migrate to another site', 'wrensafe' ), 'wrensafe-direct-migration', 'dashicons-migrate', CapabilityManager::MANAGE_DIRECT_MIGRATION, false ),
			array( __( 'Scan before migrating', 'wrensafe' ), 'wrensafe-migration-assistant', 'dashicons-search', CapabilityManager::RESTORE_BACKUPS, false ),
			array( __( 'Create a staging site', 'wrensafe' ), 'wrensafe-staging', 'dashicons-admin-multisite', CapabilityManager::MANAGE_STAGING, false ),
			array( __( 'Activity logs', 'wrensafe' ), 'wrensafe-logs', 'dashicons-list-view', CapabilityManager::VIEW_LOGS, false ),
		);

		/**
		 * Filters the Dashboard's Tools list: array( label, page slug,
		 * dashicon, capability, coming-soon flag ).
		 */
		$tools = (array) apply_filters( 'wpbp_dashboard_tools', $tools );
		?>
		<div class="wpbp-card wpbp-tools">
			<h2 class="wpbp-card-title"><?php esc_html_e( 'Tools', 'wrensafe' ); ?></h2>
			<ul class="wpbp-list wpbp-list-links">
				<?php foreach ( $tools as list( $label, $slug, $icon, $cap, $soon ) ) : ?>
					<?php
					if ( ! CapabilityManager::current_user_can( $cap ) ) {
						continue;
					}
					?>
					<li class="wpbp-list-item<?php echo $soon ? ' is-muted' : ''; ?>">
						<a class="wpbp-list-link" href="<?php echo esc_url( self::page_url( $slug ) ); ?>">
							<span class="dashicons <?php echo esc_attr( $icon ); ?>" aria-hidden="true"></span>
							<span class="wpbp-list-link-label"><?php echo esc_html( $label ); ?></span>
							<?php if ( $soon ) : ?>
								<span class="wpbp-pill is-soon"><?php esc_html_e( 'Coming soon', 'wrensafe' ); ?></span>
							<?php endif; ?>
							<span class="dashicons dashicons-arrow-right-alt2 wpbp-list-chevron" aria-hidden="true"></span>
						</a>
					</li>
				<?php endforeach; ?>
			</ul>
		</div>
		<?php
	}
}
