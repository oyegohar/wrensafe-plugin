<?php
/**
 * Admin notices shown on Wrensafe screens.
 *
 * @package WPBP\Admin
 */

namespace WPBP\Admin;

use WPBP\Admin\Pages\FixLinksPage;
use WPBP\Restore\LinkCheck;
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
		add_action( 'admin_notices', array( $this, 'render_folder_notice' ) );
		add_action( 'network_admin_notices', array( $this, 'render_folder_notice' ) );
		// After a restore that moved the site: the link check, then what it found, with "Fix links".
		add_action( 'admin_notices', array( $this, 'render_link_check_notice' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_link_check' ) );
	}

	/** Where the after-restore notices appear: the Dashboard, Plugins and Wrensafe's pages. */
	private static function restore_notice_screen( string $id ): bool {
		return in_array( $id, array( 'dashboard', 'plugins' ), true ) || AdminMenu::is_plugin_screen( $id );
	}

	/** Whether the link-check notice shows on this screen (not on Fix Links itself, which shows the same). */
	private static function shows_link_check( string $id ): bool {
		return self::restore_notice_screen( $id ) && false === strpos( $id, FixLinksPage::SLUG ) && CapabilityManager::current_user_can( CapabilityManager::RESTORE_BACKUPS ) && LinkCheck::needs_notice();
	}

	/**
	 * After a restore that moved the site: Wrensafe checks the links (the screen runs the check in
	 * steps), then says what it found, with Fix links, Details and Dismiss. Never a pop-up.
	 */
	public function render_link_check_notice(): void {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;

		if ( ! $screen || ! self::shows_link_check( (string) $screen->id ) ) {
			return;
		}

		$report = LinkCheck::report( LinkCheck::state() );
		$type   = 'checked' === $report['status'] && 0 === (int) $report['fixable'] && 0 === (int) $report['found']['missing'] && '' === $report['error'] ? 'notice-success' : 'notice-warning';
		?>
		<div class="notice <?php echo esc_attr( $type ); ?> wpbp-notice wpbp-lc-notice" id="wpbp-lc-box" data-token="<?php echo esc_attr( (string) $report['token'] ); ?>" data-status="<?php echo esc_attr( (string) $report['status'] ); ?>" data-compact="1" aria-live="polite">
			<p class="wpbp-lc-title"><strong><?php esc_html_e( 'Wrensafe: links after the restore', 'wrensafe' ); ?></strong></p>
			<div class="wpbp-lc-body"><?php echo wp_kses_post( FixLinksPage::results_html( $report, true ) ); ?></div>
		</div>
		<?php
	}

	public function enqueue_link_check( string $hook_suffix ): void {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		$id     = $screen ? (string) $screen->id : $hook_suffix;

		if ( false === strpos( $id, FixLinksPage::SLUG ) && ! self::shows_link_check( $id ) ) {
			return;
		}

		wp_enqueue_style( 'wpbp-link-check', WPBP_PLUGIN_URL . 'assets/admin/css/link-check.css', array(), WPBP_VERSION );
		wp_style_add_data( 'wpbp-link-check', 'rtl', 'replace' );
		wp_enqueue_script( 'wpbp-link-check', WPBP_PLUGIN_URL . 'assets/admin/js/link-check.js', array( 'jquery' ), WPBP_VERSION, true );
		wp_localize_script(
			'wpbp-link-check',
			'wpbpLinkCheck',
			array(
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'nonce'   => NonceManager::create( 'admin_ajax' ),
				'i18n'    => array(
					'fixConfirm' => __( 'Fix these links now? A database backup is made first, so the change can be undone from the Backup Browser.', 'wrensafe' ),
					'failed'     => __( 'The link check stopped. Reload the page to see where it got to, or start it again on Wrensafe → Fix Links.', 'wrensafe' ),
				),
			)
		);
	}

	/**
	 * Wrensafe runs from a folder other than "wrensafe" — it was installed from
	 * GitHub's "Source code" download (folder "wrensafe-plugin-1.1.0" and so
	 * on) or the folder was renamed. WordPress recognises a plugin by its
	 * folder, so a new version uploaded later would be added as a second
	 * plugin instead of replacing this one. Explain the one-time fix, on the
	 * Plugins screen and Wrensafe's pages only.
	 */
	public function render_folder_notice(): void {
		$folder = dirname( WPBP_PLUGIN_BASENAME );

		if ( 'wrensafe' === $folder || '.' === $folder || ! current_user_can( 'install_plugins' ) ) {
			return;
		}

		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;

		if ( ! $screen || ! ( in_array( $screen->id, array( 'plugins', 'plugins-network' ), true ) || false !== strpos( (string) $screen->id, 'wrensafe' ) ) ) {
			return;
		}

		printf(
			'<div class="notice notice-warning wpbp-notice"><p><strong>%1$s</strong></p><p>%2$s</p></div>',
			esc_html(
				sprintf(
					/* translators: %s: folder name */
					__( 'Wrensafe is installed in the folder “%s” instead of “wrensafe”, so a new version you upload will be added as a second plugin instead of replacing this one.', 'wrensafe' ),
					$folder
				)
			),
			esc_html(
				sprintf(
					/* translators: %s: plugin zip file name */
					__( 'This happens when Wrensafe is installed from GitHub’s “Source code” download. To fix it once: upload %s (the file under Assets on the release page), activate it, then deactivate and delete this copy. Your settings and backups are kept. After that, every new version you upload replaces the old one.', 'wrensafe' ),
					'wrensafe-' . WPBP_VERSION . '.zip'
				)
			)
		);
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

		$paragraphs = array(
			__( 'This site uses Wrensafe to make backups. A backup is a copy of the whole site: its database and its files, including any personal data they hold, such as user accounts, comments, orders and form entries. Backups are kept on this site\'s server, in a storage folder that is protected from web access, and are deleted when an administrator deletes them or when a schedule\'s retention limit removes older ones.', 'wrensafe' ),
			__( 'Wrensafe sends data away from this server only in these cases. If an administrator sets up remote storage (FTP/FTPS or S3-compatible storage), a copy of each backup is sent to that server. If an administrator moves the site with Direct Migration, a backup is sent to the other site they choose. The security scan asks WordPress.org (api.wordpress.org) for the checksums of the WordPress core files; like every request WordPress makes, it includes this site\'s address. If email notifications are turned on, emails about backups and restores are sent to the addresses set in Wrensafe\'s settings.', 'wrensafe' ),
			__( 'Wrensafe sends no data to its developers. An administrator can also download a backup or import a backup from another address; importing only downloads the file to this site.', 'wrensafe' ),
		);

		wp_add_privacy_policy_content(
			__( 'Wrensafe', 'wrensafe' ),
			'<p>' . implode( '</p><p>', array_map( 'esc_html', $paragraphs ) ) . '</p>'
		);
	}

	public function maybe_handle_dismiss(): void {
		// The dismiss links are built with NonceManager's "wpbp_"-prefixed
		// actions, so verify against those same prefixed actions.
		// Post-restore plugin-integrity notice: clear the shared transient so it
		// stops showing for everyone once acknowledged. Only someone who is
		// shown the notice (it needs the restore capability) can dismiss it.
		if ( ! isset( $_GET['wpbp_dismiss_restore_notice'], $_GET['_wpbp_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_GET['_wpbp_nonce'] ) ), 'wpbp_' . self::RESTORE_NOTICE_DISMISS_ACTION ) ) {
			return;
		}

		if ( ! CapabilityManager::current_user_can( CapabilityManager::RESTORE_BACKUPS ) ) {
			return;
		}

		delete_transient( PostRestoreIntegrityChecker::NOTICE_TRANSIENT );
	}
}
