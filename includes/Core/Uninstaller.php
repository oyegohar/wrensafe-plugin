<?php
/**
 * Runs on plugin uninstall, invoked from uninstall.php.
 *
 * The temporary restore guard an interrupted restore may have left in
 * mu-plugins is always removed. Data is only removed when the user has
 * explicitly opted in via the "delete_data_on_uninstall" setting. When they
 * have, everything Wrensafe stored is removed: options, transients, its
 * custom tables, user meta, granted capabilities, scheduled events, its
 * staging sites (their folders and tables) and the work folders in its
 * storage folder (tmp/, uploads/, logs/).
 *
 * Backup archive files on disk (wp-content/wrensafe-storage/backups) are
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

	/** Text domains this plugin has had (its current and earlier names). */
	private const TEXT_DOMAINS = array( 'wrensafe', 'backuppilot', 'wp-backup-pilot' );

	/** File name of the temporary restore guard (PluginSafety::GUARD_FILE_NAME). */
	private const GUARD_FILE_NAME = 'wpbp-restore-plugin-guard.php';

	/** Folder name of the default storage folder (WPBP_STORAGE_DIR_NAME). */
	private const STORAGE_DIR_NAME = 'wrensafe-storage';

	/**
	 * Run the uninstall.
	 *
	 * @param string $own_folder The folder of the copy being deleted (from
	 *                           uninstall.php); this file's own folder when empty.
	 */
	public static function uninstall( string $own_folder = '' ): void {
		// Without Wrensafe the guard would keep filtering the site's plugins
		// and theme and pausing cron, whatever the data setting says.
		self::remove_restore_guard();

		// A Safe Updates run's progress file, left by a run that was cut off: temporary, not the site's data.
		$up = wp_upload_dir( null, false );

		if ( ! empty( $up['basedir'] ) ) {
			self::delete_folder( trailingslashit( wp_normalize_path( (string) $up['basedir'] ) ) . 'wrensafe-live' );
		}

		$settings = get_option( Settings::OPTION_NAME, array() );

		if ( ! is_array( $settings ) || empty( $settings['delete_data_on_uninstall'] ) ) {
			return;
		}

		// Deleting one of two copies (a second folder from a GitHub "Source
		// code" download, or the plugin under an earlier name) must not remove
		// the data the remaining copy uses.
		if ( self::another_copy_installed( '' !== $own_folder ? $own_folder : dirname( plugin_basename( dirname( __DIR__, 2 ) . '/wrensafe.php' ) ) ) ) {
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
		self::delete_work_folders( (string) ( $settings['backup_dir'] ?? '' ) );
	}

	/**
	 * The temporary must-use guards an interrupted restore or Safe Updates
	 * run may have left behind. Always removed: without Wrensafe they would
	 * keep filtering the site's plugins and theme.
	 */
	private static function remove_restore_guard(): void {
		foreach ( array( self::GUARD_FILE_NAME, 'wpbp-safe-update-guard.php' ) as $file ) {
			$guard = trailingslashit( WPMU_PLUGIN_DIR ) . $file;

			if ( file_exists( $guard ) ) {
				wp_delete_file( $guard );
			}
		}
	}

	/**
	 * Hooked to "pre_uninstall_plugin" by versions up to 1.1.3, which removed
	 * the uninstall.php of another copy being deleted. It no longer changes
	 * another plugin's files: uninstall.php itself stops when another copy
	 * is running in the same request (see uninstall.php and
	 * another_copy_installed()). Kept, doing nothing, so the hook stays valid.
	 *
	 * @param string $plugin Plugin file being uninstalled.
	 */
	public static function before_plugin_uninstall( $plugin ): void { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- the hook's argument; see above.
	}

	/**
	 * Whether another copy of this plugin is still installed besides the one
	 * being deleted.
	 *
	 * The copy being deleted is named by its folder (uninstall.php passes its
	 * own): WordPress defines WP_UNINSTALL_PLUGIN once per request, so when
	 * two copies are deleted together it still names the first one while the
	 * second uninstalls. Only copies whose main file is still on disk count:
	 * the plugin list WordPress keeps in memory can still name a copy deleted
	 * earlier in this request.
	 *
	 * @param string $own_folder The folder of the copy being deleted.
	 */
	private static function another_copy_installed( string $own_folder ): bool {
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		foreach ( get_plugins() as $file => $data ) {
			if ( dirname( $file ) === $own_folder || ! file_exists( WP_PLUGIN_DIR . '/' . $file ) ) {
				continue;
			}

			if ( in_array( strtolower( (string) ( $data['TextDomain'] ?? '' ) ), self::TEXT_DOMAINS, true ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Remove the current site's options, transients, tables, cron events and
	 * role capabilities.
	 */
	private static function delete_site_data(): void {
		global $wpdb;

		// Before the staging registry table is dropped below.
		self::delete_staging_sites();

		foreach ( self::CRON_HOOKS as $hook ) {
			wp_clear_scheduled_hook( $hook );
		}

		// Every option and transient the plugin creates is "wpbp_"-prefixed.
		$like = $wpdb->esc_like( 'wpbp_' ) . '%';
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s", $like ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s", $wpdb->esc_like( '_transient_wpbp_' ) . '%', $wpdb->esc_like( '_transient_timeout_wpbp_' ) . '%' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

		foreach ( self::TABLES as $table ) {
			$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', $wpdb->prefix . 'wpbp_' . $table ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- the plugin's own tables.
		}

		// A Restore Test's copy left by a test that was cut off (its tables end
		// in RestoreTest::SUFFIX). Done here with WordPress core only: uninstall
		// runs without the plugin's autoloader.
		$test_tables = (array) $wpdb->get_col( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $wpdb->prefix ) . '%' . $wpdb->esc_like( '_wpbptest' ) ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- the plugin's own temporary tables.

		if ( $test_tables ) {
			$wpdb->query( 'SET FOREIGN_KEY_CHECKS = 0' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- see above.

			foreach ( $test_tables as $test_table ) {
				$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', (string) $test_table ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- see above.
			}

			$wpdb->query( 'SET FOREIGN_KEY_CHECKS = 1' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- see above.
		}

		foreach ( wp_roles()->role_objects as $role ) {
			foreach ( self::CAPABILITIES as $cap ) {
				$role->remove_cap( $cap );
			}
		}

		wp_unschedule_hook( 'wpbp_safe_update_continue' );

		wp_cache_flush();
	}

	/**
	 * Remove this site's staging sites: their folders ({WordPress folder}/
	 * staging-{slug}) and tables ({prefix}stg_{slug}_…). Only rows whose
	 * folder and prefix are exactly what Wrensafe creates are acted on.
	 */
	private static function delete_staging_sites(): void {
		global $wpdb;

		$registry = $wpdb->prefix . 'wpbp_staging_sites';

		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $registry ) ) ) !== $registry ) { // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- the plugin's own table.
			return;
		}

		$root = untrailingslashit( wp_normalize_path( ABSPATH ) );
		$rows = (array) $wpdb->get_results( $wpdb->prepare( 'SELECT slug, path, table_prefix FROM %i', $registry ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- the plugin's own table.

		foreach ( $rows as $row ) {
			$slug = (string) ( $row['slug'] ?? '' );

			if ( 1 !== preg_match( '/^[a-z0-9]{1,32}$/', $slug ) ) {
				continue;
			}

			$prefix = $wpdb->prefix . 'stg_' . $slug . '_';

			if ( (string) ( $row['table_prefix'] ?? '' ) === $prefix ) {
				$tables = (array) $wpdb->get_col( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $prefix ) . '%' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- the staging site's own tables.

				foreach ( $tables as $table ) {
					$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', $table ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- the staging site's own tables.
				}
			}

			$folder = $root . '/staging-' . $slug;

			if ( untrailingslashit( wp_normalize_path( (string) ( $row['path'] ?? '' ) ) ) === $folder ) {
				self::delete_folder( $folder );
			}
		}
	}

	/**
	 * Remove the work folders in Wrensafe's storage folders (the default one
	 * and the one chosen in Settings → Backup): tmp/ (restore and backup
	 * scratch files, database dumps among them), uploads/ (unfinished
	 * uploads), logs/ and safe-updates/ (the plugin copies Safe Updates saved
	 * before updates, and the folders add-ons keep there with a run). They
	 * aren't backups: backups/ is kept.
	 */
	private static function delete_work_folders( string $custom_dir ): void {
		$bases = array( trailingslashit( wp_normalize_path( WP_CONTENT_DIR ) ) . self::STORAGE_DIR_NAME );
		$up    = wp_upload_dir( null, false );

		if ( ! empty( $up['basedir'] ) ) {
			$bases[] = trailingslashit( wp_normalize_path( (string) $up['basedir'] ) ) . self::STORAGE_DIR_NAME;
		}

		if ( '' !== $custom_dir ) {
			$bases[] = untrailingslashit( wp_normalize_path( $custom_dir ) );
		}

		foreach ( array_unique( $bases ) as $base ) {
			foreach ( array( 'tmp', 'uploads', 'logs', 'safe-updates' ) as $folder ) {
				self::delete_folder( $base . '/' . $folder );
			}
		}
	}

	/**
	 * Delete a folder and everything in it. Symbolic links are removed, never
	 * followed, and a folder that is itself a link is left alone.
	 */
	private static function delete_folder( string $path ): void {
		global $wp_filesystem;

		if ( '' === $path || ! is_dir( $path ) || is_link( $path ) ) {
			return;
		}

		if ( ! function_exists( 'WP_Filesystem' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}

		// Folders are removed through WordPress's filesystem API; without
		// direct access (FTP credentials needed) they are left in place.
		if ( 'direct' !== get_filesystem_method( array(), $path ) || ! WP_Filesystem() || ! $wp_filesystem ) {
			return;
		}

		$items = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator( $path, \FilesystemIterator::SKIP_DOTS ),
			\RecursiveIteratorIterator::CHILD_FIRST
		);

		foreach ( $items as $item ) {
			if ( $item->isDir() && ! $item->isLink() ) {
				$wp_filesystem->rmdir( $item->getPathname() );
			} else {
				wp_delete_file( $item->getPathname() );
			}
		}

		$wp_filesystem->rmdir( $path );
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
