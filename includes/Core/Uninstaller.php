<?php
/**
 * Runs on plugin uninstall, invoked from uninstall.php.
 *
 * Data is only removed when the user has explicitly opted in via the
 * "delete_data_on_uninstall" setting. When they have, everything WP
 * Wrensafe stored in the DATABASE is removed: options, transients, its
 * custom tables, user meta, granted capabilities and scheduled events.
 *
 * Backup archive files on disk (wp-content/wrensafe-storage) are
 * deliberately kept — an uninstall must never destroy the user's backups.
 *
 * uninstall.php loads this file without the plugin's autoloader or constants,
 * so this class only depends on WordPress core and Settings::OPTION_NAME.
 *
 * @package WPBP\Core
 */

namespace WPBP\Core;

defined( 'ABSPATH' ) || exit;

class Uninstaller {

	/** Wrensafe's custom tables (without the "{prefix}wpbp_" part). */
	private const TABLES = array( 'jobs', 'backups', 'schedules', 'logs', 'cloud_accounts', 'restore_points', 'migration_keys', 'reports', 'staging_sites' );

	/** Scheduled event hooks registered by the plugin. */
	private const CRON_HOOKS = array( 'wpbp_cron_tick' );

	/** Capabilities granted by CapabilityManager (kept in sync with it). */
	private const CAPABILITIES = array(
		'wpbp_view_backups',
		'wpbp_create_backups',
		'wpbp_download_backups',
		'wpbp_delete_backups',
		'wpbp_restore_backups',
		'wpbp_manage_cloud',
		'wpbp_manage_schedules',
		'wpbp_manage_settings',
		'wpbp_view_dashboard',
		'wpbp_view_logs',
		'wpbp_manage_staging',
		'wpbp_manage_direct_migration',
		'wpbp_manage_reports',
		'wpbp_manage_team_access',
		'wpbp_manage_security',
	);

	public static function uninstall(): void {
		$settings = get_option( Settings::OPTION_NAME, array() );

		if ( ! is_array( $settings ) || empty( $settings['delete_data_on_uninstall'] ) ) {
			return;
		}

		if ( is_multisite() && function_exists( 'get_sites' ) ) {
			foreach ( get_sites(
				array(
					'fields' => 'ids',
					'number' => 0,
				)
			) as $site_id ) {
				switch_to_blog( (int) $site_id );
				self::delete_site_data();
				restore_current_blog();
			}
		} else {
			self::delete_site_data();
		}

		self::delete_user_data();
	}

	/**
	 * Remove the current site's options, transients, tables, cron events and
	 * role capabilities.
	 */
	private static function delete_site_data(): void {
		global $wpdb;

		foreach ( self::CRON_HOOKS as $hook ) {
			wp_clear_scheduled_hook( $hook );
		}

		// Every option and transient the plugin creates is "wpbp_"-prefixed.
		$like = $wpdb->esc_like( 'wpbp_' ) . '%';
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s", $like ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s", $wpdb->esc_like( '_transient_wpbp_' ) . '%', $wpdb->esc_like( '_transient_timeout_wpbp_' ) . '%' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

		foreach ( self::TABLES as $table ) {
			$wpdb->query( 'DROP TABLE IF EXISTS `' . $wpdb->prefix . 'wpbp_' . $table . '`' ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery -- fixed internal table names.
		}

		foreach ( wp_roles()->role_objects as $role ) {
			foreach ( self::CAPABILITIES as $cap ) {
				$role->remove_cap( $cap );
			}
		}

		// Temporary restore boot guard, if an interrupted restore left it behind.
		$guard = trailingslashit( WPMU_PLUGIN_DIR ) . 'wpbp-restore-plugin-guard.php';

		if ( file_exists( $guard ) ) {
			wp_delete_file( $guard );
		}

		wp_cache_flush();
	}

	/**
	 * Remove plugin user meta (notice dismissals) and capabilities granted to
	 * individual users through Team Access.
	 */
	private static function delete_user_data(): void {
		global $wpdb;

		$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->usermeta} WHERE meta_key LIKE %s", $wpdb->esc_like( 'wpbp_' ) . '%' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

		foreach ( get_users(
			array(
				'fields'         => 'ID',
				'capability__in' => self::CAPABILITIES,
			)
		) as $user_id ) {
			$user = get_user_by( 'id', (int) $user_id );

			if ( ! $user ) {
				continue;
			}

			foreach ( self::CAPABILITIES as $cap ) {
				$user->remove_cap( $cap );
			}
		}
	}
}
