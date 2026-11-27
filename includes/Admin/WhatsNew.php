<?php
/**
 * After an update: "What's new" once, on the first Wrensafe screen the user opens (never on a new install, where the
 * welcome shows instead). Each new feature with a link to try it, a tour of them, and the full Changelog. Seen once,
 * it also clears the header's Changelog dot (Changelog::mark_seen()).
 *
 * @package WPBP\Admin
 */

namespace WPBP\Admin;

use WPBP\Admin\Pages\ChangelogPage;

defined( 'ABSPATH' ) || exit;

final class WhatsNew {

	public static function register(): void {
		add_action( 'admin_menu', array( self::class, 'note_visits' ), 1 ); // Before Wrensafe's menu is built (admin_init comes after it).
		add_action( 'admin_enqueue_scripts', array( self::class, 'enqueue' ) );
		add_action( 'admin_footer', array( self::class, 'render' ) );
	}

	/**
	 * A new feature's screen is open: its "New" label goes for this user. On admin_menu, before Wrensafe's menu items
	 * are added, so the menu loses the label on this same page view.
	 */
	public static function note_visits(): void {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only: which screen is open.
		$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';
		$tab  = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : '';
		// phpcs:enable

		if ( 'wrensafe-settings' === $page && 'ai' === $tab ) {
			NewFeatures::seen_now( 'ai' );
		} elseif ( 'wrensafe-logs' === $page ) {
			NewFeatures::seen_now( 'timeline' );
		} elseif ( 'wrensafe-import-restore' === $page ) {
			NewFeatures::seen_now( 'found' );
		} elseif ( 'wrensafe-browser' === $page ) {
			NewFeatures::seen_now( 'restore-test' );
		}
	}

	/** The highlights of the installed release (none: no window). @return array<int,array<string,string>> */
	public static function highlights(): array {
		$releases = array(
			'1.3.0' => array(
				array(
					'icon' => 'lightbulb',
					'tag'  => __( 'New', 'wrensafe' ),
					'name' => __( 'AI assistants', 'wrensafe' ),
					'text' => __( 'Connect Claude, Cursor, Codex or Gemini in four steps. They check, make and test your backups; you approve every restore.', 'wrensafe' ),
					'try'  => __( 'Connect an assistant', 'wrensafe' ),
					'url'  => admin_url( 'admin.php?page=wrensafe-settings&tab=ai' ),
				),
				array(
					'icon' => 'shield',
					'tag'  => __( 'New', 'wrensafe' ),
					'name' => __( 'Restore Test', 'wrensafe' ),
					'text' => __( 'Prove a backup restores: it goes into a temporary copy and is checked, and your site isn’t changed.', 'wrensafe' ),
					'try'  => __( 'Test a backup', 'wrensafe' ),
					'url'  => admin_url( 'admin.php?page=wrensafe-browser' ),
				),
				array(
					'icon' => 'list-checks',
					'tag'  => __( 'Improved', 'wrensafe' ),
					'name' => __( 'Activity Logs, as a timeline', 'wrensafe' ),
					'text' => __( 'Each backup, restore or update is one card, with the reason when something went wrong and every step one click away.', 'wrensafe' ),
					'try'  => __( 'See the timeline', 'wrensafe' ),
					'url'  => admin_url( 'admin.php?page=wrensafe-logs' ),
				),
				array(
					'icon' => 'search',
					'tag'  => __( 'New', 'wrensafe' ),
					'name' => __( 'Backups from other plugins', 'wrensafe' ),
					'text' => __( 'Import / Restore finds UpdraftPlus, Duplicator and other backups on the server and restores one without uploading it.', 'wrensafe' ),
					'try'  => __( 'Open Import / Restore', 'wrensafe' ),
					'url'  => admin_url( 'admin.php?page=wrensafe-import-restore' ),
				),
			),
		);

		$items = $releases[ WPBP_VERSION ] ?? array();

		// AI assistants need WordPress 6.9 (the Abilities API): not offered on an older site.
		if ( ! \WPBP\Mcp\Abilities::supported() ) {
			$items = array_values(
				array_filter(
					$items,
					static function ( array $item ): bool {
						return 'lightbulb' !== $item['icon'];
					}
				)
			);
		}

		return $items;
	}

	/** Shown on this page view: after an update, on a Wrensafe screen, not during a tour or the welcome. */
	private static function shows(): bool {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only: a tour is crossing pages.
		if ( ! $screen || ! AdminMenu::is_plugin_screen( (string) $screen->id ) || false !== strpos( (string) $screen->id, ChangelogPage::SLUG ) || isset( $_GET['wpbp_tour'] ) ) {
			return false;
		}

		return Changelog::available() && Changelog::has_news() && ! Onboarding::show_welcome() && array() !== self::highlights();
	}

	public static function enqueue(): void {
		if ( self::shows() ) {
			wp_enqueue_script( 'wpbp-whats-new', WPBP_PLUGIN_URL . 'assets/admin/js/whats-new.js', array( 'jquery', 'wpbp-admin' ), WPBP_VERSION, true );
		}
	}

	public static function render(): void {
		if ( ! self::shows() ) {
			return;
		}
		?>
		<template id="wpbp-whats-new" data-tour="<?php echo esc_attr( GuidedTours::available() ? 'whatsnew' : '' ); ?>">
			<div class="wpbp-wn">
				<div class="wpbp-wn-hero">
					<span class="wpbp-wn-version"><?php echo esc_html( 'Wrensafe ' . WPBP_VERSION ); ?></span>
					<p class="wpbp-wn-lead"><?php esc_html_e( 'Wrensafe was updated. Here is what you can do now.', 'wrensafe' ); ?></p>
				</div>
				<ul class="wpbp-wn-list">
					<?php foreach ( self::highlights() as $item ) : ?>
						<li>
							<span class="wpbp-wn-icon" aria-hidden="true"><?php Icons::render( $item['icon'], 20 ); ?></span>
							<span class="wpbp-wn-text">
								<strong><?php echo esc_html( $item['name'] ); ?> <span class="wpbp-new-badge<?php echo __( 'New', 'wrensafe' ) === $item['tag'] ? '' : ' is-improved'; ?>"><?php echo esc_html( $item['tag'] ); ?></span></strong>
								<span><?php echo esc_html( $item['text'] ); ?></span>
								<a href="<?php echo esc_url( $item['url'] ); ?>"><?php echo esc_html( $item['try'] ); ?> →</a>
							</span>
						</li>
					<?php endforeach; ?>
				</ul>
				<p class="wpbp-wn-more"><a href="<?php echo esc_url( admin_url( 'admin.php?page=' . ChangelogPage::SLUG ) ); ?>"><?php esc_html_e( 'Everything that changed, in the Changelog', 'wrensafe' ); ?></a></p>
			</div>
			<span class="wpbp-wn-labels" data-title="<?php esc_attr_e( 'What’s new', 'wrensafe' ); ?>" data-tour-label="<?php esc_attr_e( 'Show me around', 'wrensafe' ); ?>" data-close="<?php esc_attr_e( 'Close', 'wrensafe' ); ?>" hidden></span>
		</template>
		<?php
	}
}
