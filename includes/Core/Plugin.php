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
		CapabilityManager::register();
		Notifier::register();
		// Deleting a second copy of this plugin must not run its uninstall
		// routine (see Uninstaller::before_plugin_uninstall()). Also in WP-CLI.
		add_action( 'pre_uninstall_plugin', array( \WPBP\Core\Uninstaller::class, 'before_plugin_uninstall' ) );

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
		// Site Health also runs its checks from WP-Cron, outside wp-admin.
		( new \WPBP\Admin\SiteHealth() )->register_hooks();

		if ( is_admin() ) {
			$this->container->get( AdminMenu::class )->register_hooks();
			$this->container->get( Assets::class )->register_hooks();
			$this->container->get( Notices::class )->register_hooks();
			( new \WPBP\Admin\Onboarding() )->register_hooks();
			( new \WPBP\Admin\DashboardWidget() )->register_hooks();
			$this->container->get( AjaxRouter::class )->register_hooks();
			$this->container->get( DownloadHandler::class )->register_hooks();
			$this->container->get( LogsExport::class )->register_hooks();
		}
	}

	public function render_incompatibility_notice(): void {
		printf(
			'<div class="notice notice-error wpbp-notice"><p>%s</p></div>',
			esc_html( Compatibility::get_incompatibility_message() )
		);
	}
}
