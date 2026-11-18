<?php
/**
 * WordPress Dashboard widget: the newest backup, the next scheduled one, the
 * off-site copy status, and a "Back up now" button.
 *
 * "Back up now" posts to admin-post.php, which starts a full backup and
 * opens it on the Create Backup page, where the usual progress window
 * drives it to the end.
 *
 * @package WPBP\Admin
 */

namespace WPBP\Admin;

use WPBP\Backup\BackupJob;
use WPBP\Backup\BackupManager;
use WPBP\Core\Database\BackupsRepository;
use WPBP\Core\Database\JobsRepository;
use WPBP\Core\Database\SchedulesRepository;
use WPBP\Remote\RemoteStorage;
use WPBP\Security\CapabilityManager;
use WPBP\Utils\Logger;
use WPBP\Utils\Time;

defined( 'ABSPATH' ) || exit;

class DashboardWidget {

	private const ACTION = 'wpbp_dashboard_backup';

	public function register_hooks(): void {
		add_action( 'wp_dashboard_setup', array( $this, 'register_widget' ) );
		add_action( 'admin_post_' . self::ACTION, array( $this, 'start_backup' ) );
	}

	public function register_widget(): void {
		if ( ! CapabilityManager::current_user_can( CapabilityManager::VIEW_DASHBOARD ) ) {
			return;
		}

		wp_add_dashboard_widget( 'wrensafe_status', __( 'Wrensafe backups', 'wrensafe' ), array( $this, 'render' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_styles' ) );
	}

	public function enqueue_styles( string $hook_suffix ): void {
		if ( 'index.php' !== $hook_suffix ) {
			return;
		}

		wp_register_style( 'wpbp-dashboard-widget', false, array(), WPBP_VERSION );
		wp_enqueue_style( 'wpbp-dashboard-widget' );
		wp_add_inline_style(
			'wpbp-dashboard-widget',
			'#wrensafe_status .wpbp-dw-list{margin:0 0 12px}#wrensafe_status .wpbp-dw-list li{display:flex;gap:8px;align-items:flex-start;margin:0 0 8px}#wrensafe_status .wpbp-dw-list .dashicons{flex:0 0 20px;color:#50575e}#wrensafe_status .wpbp-dw-list .is-good .dashicons{color:#00a32a}#wrensafe_status .wpbp-dw-list .is-warning .dashicons{color:#dba617}#wrensafe_status .wpbp-dw-list .is-error .dashicons{color:#d63638}#wrensafe_status .wpbp-dw-label{display:block;color:#50575e;font-size:12px}#wrensafe_status .wpbp-dw-actions{display:flex;flex-wrap:wrap;gap:8px;align-items:center;margin:0;padding-top:12px;border-top:1px solid #f0f0f1}#wrensafe_status .wpbp-dw-actions form{margin:0}'
		);
	}

	private static function page( string $slug ): string {
		return add_query_arg( 'page', $slug, admin_url( 'admin.php' ) );
	}

	/**
	 * One line of the widget.
	 *
	 * @param string $tone good | warning | error | neutral
	 */
	private function item( string $icon, string $tone, string $label, string $value, string $link_url = '', string $link_text = '' ): void {
		?>
		<li class="is-<?php echo esc_attr( $tone ); ?>">
			<span class="dashicons <?php echo esc_attr( $icon ); ?>" aria-hidden="true"></span>
			<span>
				<span class="wpbp-dw-label"><?php echo esc_html( $label ); ?></span>
				<?php echo esc_html( $value ); ?>
				<?php if ( '' !== $link_url ) : ?>
					<a href="<?php echo esc_url( $link_url ); ?>"><?php echo esc_html( $link_text ); ?></a>
				<?php endif; ?>
			</span>
		</li>
		<?php
	}

	public function render(): void {
		$latest  = BackupsRepository::find_latest_completed();
		$jobs    = JobsRepository::list_by_type( 'backup', 1 );
		$last    = $jobs ? $jobs[0] : null;
		$next    = SchedulesRepository::get_next_run_at();
		$created = $latest ? Time::mysql_to_timestamp( (string) $latest['created_at'] ) : 0;
		$failed  = $last && 'failed' === $last['status'] && ( ! $latest || Time::mysql_to_timestamp( (string) $last['created_at'] ) > $created );

		echo '<ul class="wpbp-dw-list">';

		if ( $latest ) {
			$stale = time() - $created > 7 * DAY_IN_SECONDS;
			$this->item(
				'dashicons-backup',
				$stale ? 'warning' : 'good',
				__( 'Newest backup', 'wrensafe' ),
				/* translators: 1: how long ago, e.g. "2 hours", 2: file size */
				sprintf( __( '%1$s ago · %2$s', 'wrensafe' ), human_time_diff( $created ), size_format( (int) $latest['size'] ) )
			);
		} else {
			$this->item( 'dashicons-backup', 'warning', __( 'Newest backup', 'wrensafe' ), __( 'No backup yet.', 'wrensafe' ) );
		}

		if ( $failed ) {
			$this->item( 'dashicons-warning', 'error', __( 'Last attempt', 'wrensafe' ), __( 'The last backup failed.', 'wrensafe' ), self::page( 'wrensafe-logs' ), __( 'See why', 'wrensafe' ) );
		}

		if ( $next ) {
			$this->item( 'dashicons-calendar-alt', 'neutral', __( 'Next scheduled backup', 'wrensafe' ), Time::format_mysql( $next ) );
		} else {
			$this->item( 'dashicons-calendar-alt', 'warning', __( 'Next scheduled backup', 'wrensafe' ), __( 'Scheduled backups are off.', 'wrensafe' ), self::page( 'wrensafe-schedule' ), __( 'Turn on', 'wrensafe' ) );
		}

		if ( RemoteStorage::is_enabled() ) {
			$types       = RemoteStorage::types();
			$destination = $latest ? (string) $latest['destination'] : '';

			if ( 0 === strpos( $destination, 'remote-failed:' ) ) {
				$this->item( 'dashicons-cloud', 'error', __( 'Off-site copy', 'wrensafe' ), __( 'Copying the newest backup failed.', 'wrensafe' ), self::page( 'wrensafe-logs' ), __( 'See why', 'wrensafe' ) );
			} elseif ( 0 === strpos( $destination, 'remote:' ) ) {
				/* translators: %s: storage type, e.g. "SFTP" */
				$this->item( 'dashicons-cloud-saved', 'good', __( 'Off-site copy', 'wrensafe' ), sprintf( __( 'The newest backup is copied to %s.', 'wrensafe' ), $types[ substr( $destination, 7 ) ] ?? substr( $destination, 7 ) ) );
			} else {
				/* translators: %s: storage type, e.g. "SFTP" */
				$this->item( 'dashicons-cloud', 'neutral', __( 'Off-site copy', 'wrensafe' ), sprintf( __( 'Set up: %s.', 'wrensafe' ), $types[ RemoteStorage::get()['type'] ] ?? '' ) );
			}
		}

		echo '</ul>';
		?>
		<div class="wpbp-dw-actions">
			<?php if ( CapabilityManager::current_user_can( CapabilityManager::CREATE_BACKUPS ) ) : ?>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<input type="hidden" name="action" value="<?php echo esc_attr( self::ACTION ); ?>" />
					<?php wp_nonce_field( self::ACTION ); ?>
					<button type="submit" class="button button-primary"><?php esc_html_e( 'Back up now', 'wrensafe' ); ?></button>
				</form>
			<?php endif; ?>
			<?php if ( CapabilityManager::current_user_can( CapabilityManager::VIEW_BACKUPS ) ) : ?>
				<a class="button" href="<?php echo esc_url( self::page( 'wrensafe-browser' ) ); ?>"><?php esc_html_e( 'Backup Browser', 'wrensafe' ); ?></a>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * "Back up now": start a full backup and open it on the Create Backup
	 * page. A manual backup that is already running is opened instead of
	 * starting a second one; any other running job (a scheduled backup, a
	 * restore) is reported.
	 */
	public function start_backup(): void {
		check_admin_referer( self::ACTION );

		if ( ! CapabilityManager::current_user_can( CapabilityManager::CREATE_BACKUPS ) ) {
			wp_die( esc_html__( 'You do not have permission to create backups.', 'wrensafe' ), '', array( 'response' => 403 ) );
		}

		BackupManager::fail_stale_jobs();

		foreach ( JobsRepository::list_by_type( 'backup', 5 ) as $row ) {
			if ( ! in_array( (string) $row['status'], array( 'running', 'pending' ), true ) ) {
				continue;
			}

			$running = json_decode( (string) $row['payload'], true );
			$running = is_array( $running ) ? $running : array();

			// Only a backup started by hand is driven by the browser; open it.
			if ( empty( $running['schedule_id'] ) && 0 !== strpos( (string) ( $running['note'] ?? '' ), 'Automatic rollback point' ) ) {
				self::open_job( (int) $row['id'], (string) ( $running['backup_type'] ?? 'full' ) );
			}
		}

		if ( JobsRepository::count_active() > 0 ) {
			wp_die(
				esc_html__( 'Another backup or a restore is running right now. Try again when it has finished.', 'wrensafe' ),
				esc_html__( 'Wrensafe is busy', 'wrensafe' ),
				array(
					'response'  => 409,
					'back_link' => true,
				)
			);
		}

		/** This filter is documented in includes/Ajax/BackupAjax.php */
		$payload = (array) apply_filters(
			'wpbp_backup_job_payload',
			array(
				'backup_type' => 'full',
				'note'        => '',
				'tag'         => '',
				'chain_mode'  => 'full',
				'encrypt'     => false,
			)
		);

		$job = BackupJob::create( $payload );

		Logger::info( 'backup_started_from_dashboard: job #' . $job->id() . ' started from the WordPress Dashboard widget.', array(), $job->id() );

		self::open_job( $job->id(), 'full' );
	}

	/**
	 * Open a backup job in the Create Backup page's progress window, which
	 * runs it to the end.
	 *
	 * @return never
	 */
	private static function open_job( int $job_id, string $type ): void {
		wp_safe_redirect(
			add_query_arg(
				array(
					'page'      => 'wrensafe-backup',
					'wpbp_job'  => $job_id,
					'wpbp_type' => sanitize_key( $type ),
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}
}
