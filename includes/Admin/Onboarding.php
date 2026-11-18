<?php
/**
 * First-run guide: a short welcome tour on the first visit, a "Get started"
 * checklist on the Dashboard, and a one-time pointer on the Plugins screen.
 *
 * @package Wrensafe
 */

namespace WPBP\Admin;

use WPBP\Core\Database\BackupsRepository;
use WPBP\Core\Database\SchedulesRepository;
use WPBP\Core\Settings;
use WPBP\Security\CapabilityManager;

defined( 'ABSPATH' ) || exit;

/**
 * Per-user onboarding state and its screens.
 */
class Onboarding {

	/** User meta: array( welcome_seen, dismissed, visited[] ). */
	const META = 'wpbp_onboarding';

	public function register_hooks(): void {
		add_action( 'admin_notices', array( $this, 'plugins_screen_notice' ) );
		add_action( 'wp_ajax_wpbp_onboarding', array( $this, 'ajax' ) );
		add_action( 'admin_post_wpbp_dismiss_ready_notice', array( $this, 'dismiss_ready_notice' ) );
	}

	/**
	 * This user's onboarding state.
	 *
	 * @return array{welcome_seen:bool,dismissed:bool,notice_dismissed:bool,visited:string[]}
	 */
	public static function state(): array {
		$state = get_user_meta( get_current_user_id(), self::META, true );
		$state = is_array( $state ) ? $state : array();

		return array(
			'welcome_seen'     => ! empty( $state['welcome_seen'] ),
			'dismissed'        => ! empty( $state['dismissed'] ),
			'notice_dismissed' => ! empty( $state['notice_dismissed'] ),
			'visited'          => array_values( array_filter( (array) ( $state['visited'] ?? array() ), 'is_string' ) ),
		);
	}

	private static function save( array $state ): void {
		update_user_meta( get_current_user_id(), self::META, $state );
	}

	/**
	 * Whether the welcome tour opens by itself on this page view: only on a
	 * first run (this user hasn't seen it and the site has no backups yet).
	 * A restore brings back user data from before the tour, but keeps the
	 * backup list, so the tour doesn't come back after a restore.
	 */
	public static function show_welcome(): bool {
		return ! self::state()['welcome_seen'] && 0 === BackupsRepository::count_completed();
	}

	/**
	 * Remember that a step's page was opened ("See how a restore works").
	 */
	public static function page_viewed( string $key ): void {
		$state = self::state();

		if ( in_array( $key, $state['visited'], true ) ) {
			return;
		}

		$state['visited'][] = sanitize_key( $key );
		self::save( $state );
	}

	/**
	 * The checklist, in order. Each step knows when it's done.
	 *
	 * @return array<int,array{key:string,title:string,text:string,url:string,cta:string,done:bool}>
	 */
	public static function steps(): array {
		$state    = self::state();
		$settings = Settings::get_all();

		return array(
			array(
				'key'   => 'backup',
				'title' => __( 'Create your first backup', 'wrensafe' ),
				'text'  => __( 'A full backup of the database and files, saved as one checked .wbp file.', 'wrensafe' ),
				'url'   => admin_url( 'admin.php?page=wrensafe-backup' ),
				'cta'   => __( 'Create backup', 'wrensafe' ),
				'done'  => BackupsRepository::count_completed() > 0,
			),
			array(
				'key'   => 'schedule',
				'title' => __( 'Turn on automatic backups', 'wrensafe' ),
				'text'  => __( 'Every day, week or month, keeping only the newest copies.', 'wrensafe' ),
				'url'   => admin_url( 'admin.php?page=wrensafe-schedule' ),
				'cta'   => __( 'Set a schedule', 'wrensafe' ),
				'done'  => SchedulesRepository::count_enabled() > 0,
			),
			array(
				'key'   => 'alerts',
				'title' => __( 'Get an email if a backup fails', 'wrensafe' ),
				'text'  => __( 'So a failed automatic backup never goes unnoticed.', 'wrensafe' ),
				'url'   => admin_url( 'admin.php?page=wrensafe-settings&tab=notifications' ),
				'cta'   => __( 'Set up emails', 'wrensafe' ),
				'done'  => ! empty( $settings['notify_on_failure'] ),
			),
			array(
				'key'   => 'folder',
				'title' => __( 'Choose where backups are kept', 'wrensafe' ),
				'text'  => __( 'The default folder works; a folder outside the website folder is safest.', 'wrensafe' ),
				'url'   => admin_url( 'admin.php?page=wrensafe-settings&tab=backup' ),
				'cta'   => __( 'Review folder', 'wrensafe' ),
				'done'  => '' !== (string) ( $settings['backup_dir'] ?? '' ) || in_array( 'folder', $state['visited'], true ),
			),
			array(
				'key'   => 'restore',
				'title' => __( 'See how a restore works', 'wrensafe' ),
				'text'  => __( 'You review a backup before anything changes, and a rollback point lets you undo it.', 'wrensafe' ),
				'url'   => admin_url( 'admin.php?page=wrensafe-import-restore' ),
				'cta'   => __( 'Open Import / Restore', 'wrensafe' ),
				'done'  => in_array( 'restore', $state['visited'], true ),
			),
		);
	}

	/**
	 * The Dashboard's "Get started" card (until it is hidden or complete).
	 */
	public static function render_checklist(): void {
		if ( self::state()['dismissed'] ) {
			return;
		}

		$steps = self::steps();
		$done  = count( array_filter( wp_list_pluck( $steps, 'done' ) ) );
		$total = count( $steps );

		if ( $done === $total ) {
			return;
		}

		$next = null;
		foreach ( $steps as $i => $step ) {
			if ( ! $step['done'] ) {
				$next = $i;
				break;
			}
		}
		?>
		<section class="wpbp-card wpbp-onboarding" id="wpbp-onboarding" aria-labelledby="wpbp-onboarding-title">
			<div class="wpbp-onboarding-head">
				<div>
					<h2 id="wpbp-onboarding-title"><?php esc_html_e( 'Get started with Wrensafe', 'wrensafe' ); ?></h2>
					<p class="description"><?php esc_html_e( 'A few steps to make sure this site is protected. Each one ticks itself off when it’s done.', 'wrensafe' ); ?></p>
				</div>
				<div class="wpbp-onboarding-progress">
					<span>
						<?php
						/* translators: 1: steps done, 2: total steps */
						echo esc_html( sprintf( __( '%1$d of %2$d done', 'wrensafe' ), $done, $total ) );
						?>
					</span>
					<div class="wpbp-progress-bar" aria-hidden="true"><div class="wpbp-progress-bar-fill" style="width:<?php echo esc_attr( (string) round( $done / $total * 100 ) ); ?>%"></div></div>
				</div>
			</div>

			<ol class="wpbp-onboarding-steps">
				<?php foreach ( $steps as $i => $step ) : ?>
					<li class="<?php echo $step['done'] ? 'is-done' : ( $i === $next ? 'is-next' : '' ); ?>">
						<span class="wpbp-onboarding-step-num" aria-hidden="true"><?php echo esc_html( (string) ( $i + 1 ) ); ?></span>
						<span class="wpbp-onboarding-step-text">
							<strong><?php echo esc_html( $step['title'] ); ?></strong>
							<span><?php echo esc_html( $step['text'] ); ?></span>
						</span>
						<?php if ( $step['done'] ) : ?>
							<span class="wpbp-pill is-success"><?php esc_html_e( 'Done', 'wrensafe' ); ?></span>
						<?php else : ?>
							<a class="button button-small<?php echo $i === $next ? ' button-primary' : ''; ?>" href="<?php echo esc_url( $step['url'] ); ?>"><?php echo esc_html( $step['cta'] ); ?></a>
						<?php endif; ?>
					</li>
				<?php endforeach; ?>
			</ol>

			<div class="wpbp-onboarding-foot">
				<button type="button" class="button-link" data-wpbp-welcome-open><?php esc_html_e( 'Show the welcome tour', 'wrensafe' ); ?></button>
				<button type="button" class="button-link" id="wpbp-onboarding-dismiss"><?php esc_html_e( 'Hide this guide', 'wrensafe' ); ?></button>
			</div>
		</section>
		<?php
	}

	/**
	 * The welcome tour's slides, printed on every Wrensafe page and
	 * opened by admin.js (on the first visit, or from "Show the welcome tour").
	 */
	public static function render_welcome_template(): void {
		$slides = array(
			array( 'shield', __( 'Welcome to Wrensafe', 'wrensafe' ), __( 'Back up, restore and move this WordPress site, with checks at every step. Nothing on the site changes until you have reviewed it.', 'wrensafe' ) ),
			array( 'archive', __( 'Back up', 'wrensafe' ), __( 'Back up the whole site, or only the database, files, media, plugins or themes. Each backup is one .wbp file with checksums, and can be encrypted with a password.', 'wrensafe' ) ),
			array( 'restore', __( 'Restore safely', 'wrensafe' ), __( 'Before a restore you see what’s inside and what will change. A rollback point is saved first, so you can undo it. Backups made by other plugins can be restored too.', 'wrensafe' ) ),
			array( 'globe', __( 'Move and test', 'wrensafe' ), __( 'Send a backup straight to another site with Direct Migration, let Migration Assistant check the server first, or try changes on a private staging copy.', 'wrensafe' ) ),
			array( 'calendar', __( 'Stay protected automatically', 'wrensafe' ), __( 'Schedule daily, weekly or monthly backups, back up before updates, and get an email if something fails.', 'wrensafe' ) ),
		);
		?>
		<template id="wpbp-welcome-template"
			data-skip="<?php esc_attr_e( 'Skip tour', 'wrensafe' ); ?>"
			data-back="<?php esc_attr_e( 'Back', 'wrensafe' ); ?>"
			data-next="<?php esc_attr_e( 'Next', 'wrensafe' ); ?>"
			data-finish="<?php esc_attr_e( 'Get started', 'wrensafe' ); ?>"
			data-finish-url="<?php echo esc_url( admin_url( 'admin.php?page=wrensafe#wpbp-onboarding' ) ); ?>"
			<?php /* translators: 1: slide number, 2: number of slides */ ?>
			data-counter="<?php esc_attr_e( 'Step %1$d of %2$d', 'wrensafe' ); ?>">
			<div class="wpbp-welcome">
				<?php foreach ( $slides as $i => list( $icon, $title, $text ) ) : ?>
					<div class="wpbp-welcome-slide" data-title="<?php echo esc_attr( $title ); ?>"<?php echo 0 === $i ? '' : ' hidden'; ?>>
						<span class="wpbp-welcome-icon" aria-hidden="true"><?php Icons::render( $icon, 28 ); ?></span>
						<p><?php echo esc_html( $text ); ?></p>
					</div>
				<?php endforeach; ?>
				<div class="wpbp-welcome-dots" aria-hidden="true">
					<?php foreach ( $slides as $i => $slide ) : ?>
						<span class="<?php echo 0 === $i ? 'is-active' : ''; ?>"></span>
					<?php endforeach; ?>
				</div>
			</div>
		</template>
		<?php
	}

	/**
	 * After install, a one-time pointer on the Plugins screen until this user
	 * opens Wrensafe (no redirect on activation).
	 */
	public function plugins_screen_notice(): void {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;

		if ( ! $screen || 'plugins' !== $screen->id || ! CapabilityManager::current_user_can( CapabilityManager::VIEW_DASHBOARD ) || ! self::show_welcome() || self::state()['notice_dismissed'] ) {
			return;
		}

		$dismiss = wp_nonce_url( admin_url( 'admin-post.php?action=wpbp_dismiss_ready_notice' ), 'wpbp_dismiss_ready_notice' );
		?>
		<div class="notice notice-info wpbp-notice">
			<p>
				<strong><?php esc_html_e( 'Wrensafe is ready.', 'wrensafe' ); ?></strong>
				<?php esc_html_e( 'Take the one-minute tour and create your first backup.', 'wrensafe' ); ?>
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=wrensafe' ) ); ?>"><?php esc_html_e( 'Get started', 'wrensafe' ); ?></a>
				&nbsp;·&nbsp;
				<a href="<?php echo esc_url( $dismiss ); ?>"><?php esc_html_e( 'Dismiss', 'wrensafe' ); ?></a>
			</p>
		</div>
		<?php
	}

	/**
	 * "Dismiss" on the Plugins-screen pointer (works without JavaScript).
	 */
	public function dismiss_ready_notice(): void {
		check_admin_referer( 'wpbp_dismiss_ready_notice' );

		$state                     = self::state();
		$state['notice_dismissed'] = true;
		self::save( $state );

		wp_safe_redirect( admin_url( 'plugins.php' ) );
		exit;
	}

	/**
	 * Save the tour and checklist state: welcome_seen, dismiss, restart.
	 */
	public function ajax(): void {
		check_ajax_referer( 'wpbp_admin_ajax', '_wpbp_nonce' );

		if ( ! CapabilityManager::current_user_can( CapabilityManager::VIEW_DASHBOARD ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'wrensafe' ) ), 403 );
		}

		$state = self::state();
		$do    = isset( $_POST['do'] ) ? sanitize_key( wp_unslash( $_POST['do'] ) ) : '';

		if ( 'welcome_seen' === $do ) {
			$state['welcome_seen'] = true;
		} elseif ( 'dismiss' === $do ) {
			$state['dismissed'] = true;
		} elseif ( 'restart' === $do ) {
			$state['dismissed'] = false;
		} else {
			wp_send_json_error( array( 'message' => __( 'Unknown request.', 'wrensafe' ) ), 400 );
		}

		self::save( $state );
		wp_send_json_success();
	}
}
