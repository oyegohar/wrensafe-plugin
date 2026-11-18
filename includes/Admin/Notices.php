<?php
/**
 * Admin notices shown on Wrensafe screens.
 *
 * @package WPBP\Admin
 */

namespace WPBP\Admin;

use WPBP\Restore\PostRestoreIntegrityChecker;
use WPBP\Security\CapabilityManager;
use WPBP\Security\NonceManager;

defined( 'ABSPATH' ) || exit;

class Notices {

	private const RESTORE_NOTICE_DISMISS_ACTION = 'dismiss_restore_notice';

	public function register_hooks(): void {
		add_action( 'current_screen', array( $this, 'maybe_handle_dismiss' ) );
		// The post-restore plugin-deactivation warning: on the Dashboard, the
		// Plugins screen and Wrensafe's pages, until dismissed (a week at most).
		add_action( 'admin_notices', array( $this, 'render_restore_integrity_notice' ) );
		add_action( 'admin_init', array( $this, 'register_privacy_policy_content' ) );
	}

	/**
	 * Warn the admin that one or more plugins were deactivated after a restore
	 * because their files were incomplete (Issue 11). Dismissible; dismissing
	 * clears the stored payload. Shown where an administrator lands after a
	 * restore (Dashboard, Plugins, Wrensafe's pages), not on every screen.
	 */
	public function render_restore_integrity_notice(): void {
		if ( ! CapabilityManager::current_user_can( CapabilityManager::RESTORE_BACKUPS ) ) {
			return;
		}

		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;

		if ( ! $screen || ! ( in_array( $screen->id, array( 'dashboard', 'plugins' ), true ) || AdminMenu::is_plugin_screen( (string) $screen->id ) ) ) {
			return;
		}

		$items = get_transient( PostRestoreIntegrityChecker::NOTICE_TRANSIENT );

		if ( empty( $items ) || ! is_array( $items ) ) {
			return;
		}

		$dismiss_url = wp_nonce_url(
			add_query_arg( 'wpbp_dismiss_restore_notice', '1' ),
			'wpbp_' . self::RESTORE_NOTICE_DISMISS_ACTION,
			'_wpbp_nonce'
		);

		echo '<div class="notice notice-warning wpbp-notice">';
		echo '<p><strong>' . esc_html__( 'Wrensafe: some plugins were turned off after your restore.', 'wrensafe' ) . '</strong></p>';
		echo '<p>' . esc_html__( 'They were turned off so the site keeps working. Fix the reason, then activate them again under Plugins:', 'wrensafe' ) . '</p>';
		echo '<ul style="list-style:disc;margin-left:20px;">';

		$installed = function_exists( 'get_plugins' ) ? get_plugins() : array();

		foreach ( array_slice( $items, 0, 20 ) as $item ) {
			$plugin = isset( $item['plugin'] ) ? (string) $item['plugin'] : '';
			$reason = isset( $item['reason'] ) ? (string) $item['reason'] : '';
			$name   = (string) ( $installed[ $plugin ]['Name'] ?? '' );
			echo '<li><strong>' . esc_html( '' !== $name ? $name : $plugin ) . '</strong>: ' . esc_html( $reason ) . '.</li>';
		}

		echo '</ul>';
		printf(
			'<p><a href="%s" class="button button-secondary">%s</a></p>',
			esc_url( $dismiss_url ),
			esc_html__( 'Dismiss', 'wrensafe' )
		);
		echo '</div>';
	}

	/**
	 * Suggested privacy policy text, surfaced via WordPress's own Privacy
	 * Policy Guide (Settings → Privacy) for the site admin to copy into
	 * their actual policy — the standard WP mechanism for this rather than
	 * a notice of our own.
	 */
	public function register_privacy_policy_content(): void {
		if ( ! function_exists( 'wp_add_privacy_policy_content' ) ) {
			return;
		}

		wp_add_privacy_policy_content(
			__( 'Wrensafe', 'wrensafe' ),
			wp_kses_post(
				__( 'Wrensafe keeps backups on this website\'s own server. A backup contains this site\'s database and files, which can include personal data of users, customers and commenters. Wrensafe sends no data to its developers or to other services. A backup leaves this server only when an administrator downloads it, sends it to another site with Direct Migration, or imports a backup from a URL they enter.', 'wrensafe' )
			)
		);
	}

	public function maybe_handle_dismiss(): void {
		// The dismiss links are built with NonceManager's "wpbp_"-prefixed
		// actions, so verify against those same prefixed actions.
		// Post-restore plugin-integrity notice: clear the shared transient so it
		// stops showing for everyone once acknowledged.
		if ( isset( $_GET['wpbp_dismiss_restore_notice'], $_GET['_wpbp_nonce'] ) && wp_verify_nonce( sanitize_text_field( wp_unslash( $_GET['_wpbp_nonce'] ) ), 'wpbp_' . self::RESTORE_NOTICE_DISMISS_ACTION ) ) {
			delete_transient( PostRestoreIntegrityChecker::NOTICE_TRANSIENT );
		}
	}
}
