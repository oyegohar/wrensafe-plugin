<?php
/**
 * Plugin Name:       Wrensafe – WP Backup, Restore & Migration
 * Description:       Back up, restore and migrate WordPress with confidence: full or partial backups, encryption, schedules, staging, rollback, and restores of backups made by other plugins.
 * Version:           1.1.1
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Author:            Husnain Gohar
 * Author URI:        https://profiles.wordpress.org/oyegohar/
 * License:           GPL v2 or later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       wrensafe
 *
 * @package WPBP
 */

defined( 'ABSPATH' ) || exit;

// An earlier version of this plugin, released under its previous name, is
// still active: the two can't run together (they share their code and data),
// so ask for the old one to be deactivated first. Settings and backups carry over.
if ( defined( 'WPBP_VERSION' ) ) {
	add_action(
		'admin_notices',
		static function (): void {
			echo '<div class="notice notice-error"><p>' . esc_html__( 'An earlier version of this plugin, released under its previous name, is still active. Deactivate it to use Wrensafe. Your settings and backups are kept.', 'wrensafe' ) . '</p></div>';
		}
	);
	return;
}

// ---------------------------------------------------------------------------
// Core plugin constants.
// ---------------------------------------------------------------------------

define( 'WPBP_VERSION', '1.1.1' );
define( 'WPBP_PLUGIN_FILE', __FILE__ );
define( 'WPBP_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'WPBP_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'WPBP_PLUGIN_BASENAME', plugin_basename( __FILE__ ) );
define( 'WPBP_STORAGE_DIR_NAME', 'wrensafe-storage' );
// The folder name used before the plugin was renamed (1.0.2). Backups
// found there are moved to the new folder (FileSystem::adopt_legacy_storage()).
define( 'WPBP_LEGACY_STORAGE_DIR_NAME', 'wp-backup-pilot-storage' );

// Support Us links. Blank by default — no link/button is rendered until a
// real URL is set (constant, or the wpbp_support_link filter; see SupportUs::link()).
defined( 'WPBP_SUPPORT_URL' ) || define( 'WPBP_SUPPORT_URL', '' );
defined( 'WPBP_REVIEW_URL' ) || define( 'WPBP_REVIEW_URL', 'https://wordpress.org/support/plugin/wrensafe/reviews/' );
defined( 'WPBP_FEATURE_REQUEST_URL' ) || define( 'WPBP_FEATURE_REQUEST_URL', '' );
defined( 'WPBP_REPORT_ISSUE_URL' ) || define( 'WPBP_REPORT_ISSUE_URL', '' );
defined( 'WPBP_COMMUNITY_URL' ) || define( 'WPBP_COMMUNITY_URL', '' );

// ---------------------------------------------------------------------------
// Autoloader.
// ---------------------------------------------------------------------------

spl_autoload_register(
	static function ( string $class_name ): void {
		$prefix = 'WPBP\\';

		if ( ! str_starts_with( $class_name, $prefix ) ) {
			return;
		}

		$relative_path = str_replace( '\\', '/', substr( $class_name, strlen( $prefix ) ) );
		$file          = WPBP_PLUGIN_DIR . 'includes/' . $relative_path . '.php';

		if ( file_exists( $file ) ) {
			require $file;
		}
	}
);

// ---------------------------------------------------------------------------
// Activation / deactivation / uninstall.
// ---------------------------------------------------------------------------

register_activation_hook( __FILE__, array( \WPBP\Core\Activator::class, 'activate' ) );
register_deactivation_hook( __FILE__, array( \WPBP\Core\Deactivator::class, 'deactivate' ) );

// ---------------------------------------------------------------------------
// Boot the plugin once all plugins are loaded.
// ---------------------------------------------------------------------------

add_action(
	'plugins_loaded',
	static function (): void {
		\WPBP\Core\Plugin::instance()->boot();
	}
);
