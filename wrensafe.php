<?php
/**
 * Plugin Name:       Wrensafe – Backup, Restore & Migration
 * Description:       Back up, restore and migrate WordPress with confidence: full or partial backups, encryption, schedules, staging, rollback, and restores of backups made by other plugins.
 * Version:           1.1.4
 * Requires at least: 6.2
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

// This file must parse on any PHP version WordPress can run on (5.6+), so it
// shows a notice instead of a fatal error when the site's PHP or WordPress is
// older than Wrensafe needs (for example after a site was moved to an older
// server while the plugin stayed active). No PHP 7 syntax until the check
// below has passed, and no class file is loaded before it.
$wpbp_wp_version = isset( $GLOBALS['wp_version'] ) ? (string) $GLOBALS['wp_version'] : '';

if ( version_compare( PHP_VERSION, '7.4', '<' ) || ( '' !== $wpbp_wp_version && version_compare( $wpbp_wp_version, '6.2', '<' ) ) ) {
	// One notice, also when a second copy of the plugin is active.
	if ( ! defined( 'WPBP_REQUIREMENTS_NOT_MET' ) ) {
		define( 'WPBP_REQUIREMENTS_NOT_MET', true );

		add_action(
			'admin_notices',
			static function () use ( $wpbp_wp_version ) {
				if ( ! current_user_can( 'activate_plugins' ) ) {
					return;
				}

				printf(
					'<div class="notice notice-error wpbp-notice"><p><strong>%1$s</strong> %2$s</p></div>',
					esc_html__( 'Wrensafe is not running.', 'wrensafe' ),
					esc_html(
						sprintf(
							/* translators: 1: PHP version on this server, 2: WordPress version of this site */
							__( 'It needs PHP 7.4 or newer and WordPress 6.2 or newer; this site has PHP %1$s and WordPress %2$s. Update WordPress, or ask your host to switch the site to a newer PHP version, and Wrensafe starts again by itself. Your settings and backups are kept.', 'wrensafe' ),
							PHP_VERSION,
							$wpbp_wp_version
						)
					)
				);
			}
		);
	}

	return;
}

unset( $wpbp_wp_version );

// Another copy of this plugin is already running: a second Wrensafe folder
// (for example one installed from GitHub's "Source code" download, which
// WordPress treats as a separate plugin) or an earlier version under the
// plugin's previous name. Two copies can't run together (they share their code
// and data), so this one stays idle and says which copy runs, with a link to
// deactivate the older one. Settings and backups are shared and kept.
if ( defined( 'WPBP_VERSION' ) ) {
	$wpbp_idle_copy = plugin_basename( __FILE__ );
	add_action(
		'admin_notices',
		static function () use ( $wpbp_idle_copy ) {
			if ( ! current_user_can( 'activate_plugins' ) ) {
				return;
			}

			$running         = defined( 'WPBP_PLUGIN_BASENAME' ) ? (string) WPBP_PLUGIN_BASENAME : '';
			$running_version = (string) WPBP_VERSION;
			$idle_data       = get_file_data( WP_PLUGIN_DIR . '/' . $wpbp_idle_copy, array( 'Version' => 'Version' ) );
			$idle_version    = isset( $idle_data['Version'] ) ? (string) $idle_data['Version'] : '';

			// Offer to deactivate the older copy. With the same version, keep the
			// one in the "wrensafe" folder: uploads of new versions replace it.
			$compare = version_compare( $running_version, $idle_version );
			if ( '' === $running ) {
				$older = $wpbp_idle_copy;
			} elseif ( 0 !== $compare ) {
				$older = $compare < 0 ? $running : $wpbp_idle_copy;
			} else {
				$older = 'wrensafe' === dirname( $running ) ? $wpbp_idle_copy : $running;
			}
			$link = wp_nonce_url( self_admin_url( 'plugins.php?action=deactivate&plugin=' . rawurlencode( $older ) ), 'deactivate-plugin_' . $older );

			printf(
				'<div class="notice notice-error wpbp-notice"><p><strong>%1$s</strong> %2$s</p><p><a class="button" href="%3$s">%4$s</a></p></div>',
				esc_html__( 'Two copies of Wrensafe are active, and only one can run.', 'wrensafe' ),
				esc_html(
					sprintf(
						/* translators: 1: version, 2: folder name, 3: version, 4: folder name */
						__( 'Version %1$s in the folder “%2$s” is running; version %3$s in the folder “%4$s” is not. Deactivate the extra copy with the button below, then delete it on this screen. Your settings and backups are kept.', 'wrensafe' ),
						$running_version,
						'' !== $running ? dirname( $running ) : '?',
						$idle_version,
						dirname( $wpbp_idle_copy )
					)
				),
				esc_url( $link ),
				esc_html(
					sprintf(
						/* translators: %s: folder name */
						__( 'Deactivate the copy in “%s”', 'wrensafe' ),
						dirname( $older )
					)
				)
			);
		}
	);
	return;
}

// ---------------------------------------------------------------------------
// Core plugin constants.
// ---------------------------------------------------------------------------

define( 'WPBP_VERSION', '1.1.4' );
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
	static function ( $class_name ) {
		$prefix = 'WPBP\\';

		// Not Str::starts_with(): the autoloader can't load its own helpers.
		if ( 0 !== strncmp( $class_name, $prefix, strlen( $prefix ) ) ) {
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
	static function () {
		\WPBP\Core\Plugin::instance()->boot();
	}
);
