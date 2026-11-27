<?php
/**
 * Main plugin bootstrap.
 *
 * @package WPBP\Core
 */

namespace WPBP\Core;

use WPBP\Security\CapabilityManager;
use WPBP\Admin\AdminMenu;
use WPBP\Admin\Assets;
use WPBP\Admin\LogsExport;
use WPBP\Admin\Notices;
use WPBP\Ajax\AjaxRouter;
use WPBP\Ajax\DownloadHandler;
use WPBP\Core\Database\Schema;
use WPBP\Migration\TransferServer;
use WPBP\Restore\RestoreSiteMaintenance;
use WPBP\Scheduler\CronHandler;
use WPBP\Updates\BackupBeforeUpdates;
use WPBP\WooCommerce\CheckoutMaintenance;

defined( 'ABSPATH' ) || exit;

class Plugin {

	private static ?Plugin $instance = null;

	private ServiceContainer $container;

	private bool $booted = false;

	public static function instance(): Plugin {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	private function __construct() {
		$this->container = new ServiceContainer();
	}

	public function container(): ServiceContainer {
		return $this->container;
	}

	/**
	 * Wire up the plugin. Safe to call only once, on `plugins_loaded`.
	 */
	public function boot(): void {
		if ( $this->booted ) {
			return;
		}

		$this->booted = true;

		if ( ! Compatibility::is_compatible() ) {
			add_action( 'admin_notices', array( $this, 'render_incompatibility_notice' ) );
			return;
		}

		// Translations are loaded just-in-time by WordPress (4.6+) from
		// wp-content/languages/plugins/, so no load_plugin_textdomain() call.

		Schema::maybe_upgrade();
		\WPBP\Utils\FileSystem::adopt_legacy_storage();

		// After an update: staging sites get the current password guard
		// (and those made before 1.2.1 move off .htaccess, see StagingProtection).
		if ( is_admin() || wp_doing_cron() ) {
			\WPBP\Staging\StagingProtection::maybe_upgrade_all();
		}
		CapabilityManager::register();
		Notifier::register();

		/**
		 * Fires once Wrensafe is loaded and before it registers its hooks.
		 * Add-ons attach their filters and actions here, so
		 * they work regardless of plugin load order.
		 */
		do_action( 'wpbp_init' );

		$this->container->get( Settings::class )->register_hooks();
		$this->container->get( CronHandler::class )->register_hooks();
		$this->container->get( TransferServer::class )->register_hooks();
		$this->container->get( CheckoutMaintenance::class )->register_hooks();
		$this->container->get( RestoreSiteMaintenance::class )->register_hooks();
		$this->container->get( BackupBeforeUpdates::class )->register_hooks();
		// Safe Updates: the update window, the site check's ping and WP-Cron continuation.
		( new \WPBP\Updates\SafeUpdates() )->register_hooks();
		// Site Health also runs its checks from WP-Cron, outside wp-admin.
		( new \WPBP\Admin\SiteHealth() )->register_hooks();
		// After a restore that moved the site: a link check waits for the next administrator screen.
		\WPBP\Restore\LinkCheck::register();
		// AI assistants (MCP): abilities are asked for over REST and WP-CLI, and WP-Cron finishes their jobs.
		\WPBP\Mcp\Abilities::register();
		\WPBP\Admin\MenuBadges::register();
		\WPBP\Admin\ReviewRequest::register();
		\WPBP\Admin\GuidedTours::register();

		if ( is_admin() ) {
			$this->container->get( AdminMenu::class )->register_hooks();
			$this->container->get( Assets::class )->register_hooks();
			$this->container->get( Notices::class )->register_hooks();
			( new \WPBP\Admin\Onboarding() )->register_hooks();
			\WPBP\Admin\SupportUs::register();
			\WPBP\Admin\Changelog::register();
			\WPBP\Admin\ActivityTimeline::register();
			\WPBP\Admin\RestoreTestScreen::register();
			\WPBP\Mcp\Requests::register();
			\WPBP\Admin\McpSettings::register();
			\WPBP\Admin\WhatsNew::register();
			( new \WPBP\Admin\DashboardWidget() )->register_hooks();
			$this->container->get( AjaxRouter::class )->register_hooks();
			$this->container->get( DownloadHandler::class )->register_hooks();
			$this->container->get( LogsExport::class )->register_hooks();
		}
	}

	/**
	 * Why Wrensafe isn't running, for the people who can do something about
	 * it (those who can manage plugins).
	 */
	public function render_incompatibility_notice(): void {
		if ( ! current_user_can( 'activate_plugins' ) ) {
			return;
		}

		printf(
			'<div class="notice notice-error wpbp-notice"><p>%s</p></div>',
			esc_html( Compatibility::get_incompatibility_message() )
		);
	}
}
