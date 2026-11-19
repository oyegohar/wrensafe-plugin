<?php
/**
 * Plugin-activation safety for restores (Issue 10).
 *
 * The reported production failure: a cross-site restore swapped the SOURCE
 * database live (active_plugins included WooCommerce) while the destination
 * didn't have WooCommerce's files yet. WordPress skips an active plugin whose
 * main file is missing — but the moment the file restore extracted
 * woocommerce.php (before src/Autoloader.php), the next admin-ajax poll
 * loaded the half-restored plugin and fataled, killing the restore and the
 * whole destination site.
 *
 * Three layers of defence live here:
 *  1. plugin_is_loadable()  — main file exists + known-critical companion
 *     files exist (WooCommerce's src/Autoloader.php).
 *  2. filter_active_plugins() — rewrite the live active_plugins option to
 *     only loadable plugins (Wrensafe itself always kept), used right
 *     after the database swap, at finalize, and during failure recovery.
 *  3. A temporary MU-plugin boot guard installed for the duration of a
 *     restore: mu-plugins load before active plugins are resolved, so every
 *     poll request during the restore filters out not-yet-loadable plugins
 *     at boot — no window where a partial extraction can fatal the site.
 *
 * @package WPBP\Restore
 */

namespace WPBP\Restore;

use WPBP\Utils\Logger;
use WPBP\Utils\Paths;
use WPBP\Utils\Serializer;

defined( 'ABSPATH' ) || exit;

// Database access in this file is direct by design: Wrensafe's own tables (or,
// in the backup/restore engine, the site's tables being exported, imported or
// swapped) have no WordPress API, and the data must be read fresh, so it is not
// object-cached. Every query goes through $wpdb->prepare() or $wpdb->update() /
// $wpdb->insert().
// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

class PluginSafety {

	/** File name of the temporary MU-plugin boot guard. */
	const GUARD_FILE_NAME = 'wpbp-restore-plugin-guard.php';

	/**
	 * Transient naming the key of the health check's own request (see
	 * RestoreHealthCheck::probe()): the only request the boot guard lets
	 * load the restored site's plugins and theme.
	 */
	const SCRAPE_TRANSIENT = 'wpbp_restore_scrape_key';

	/**
	 * The active_plugins option as stored in the database (null when the
	 * row doesn't exist). Read directly: right after the table swap the
	 * option cache is stale, and while a restore runs get_option() returns
	 * the boot guard's filtered list.
	 */
	private static function raw_active_plugins(): ?string {
		global $wpdb;

		$raw = $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s LIMIT 1", 'active_plugins' ) );

		return null === $raw ? null : (string) $raw;
	}

	/**
	 * The stored active_plugins list (never creates PHP objects: the row may
	 * come from a backup).
	 *
	 * @return array<int|string,mixed>|null Null when the value isn't a list.
	 */
	private static function decode_plugins( ?string $raw ): ?array {
		if ( null === $raw ) {
			return array();
		}

		$plugins = Serializer::safe_unserialize( $raw );

		return is_array( $plugins ) ? $plugins : null;
	}

	/** Write the active_plugins option directly, then clear its caches. */
	private static function save_active_plugins( array $plugins, bool $exists ): void {
		global $wpdb;

		$value = maybe_serialize( array_values( $plugins ) );

		if ( $exists ) {
			$wpdb->update( $wpdb->options, array( 'option_value' => $value ), array( 'option_name' => 'active_plugins' ) );
		} else {
			$wpdb->insert(
				$wpdb->options,
				array(
					'option_name'  => 'active_plugins',
					'option_value' => $value,
					'autoload'     => 'yes',
				)
			);
		}

		wp_cache_delete( 'alloptions', 'options' );
		wp_cache_delete( 'active_plugins', 'options' );
		wp_cache_delete( 'notoptions', 'options' );
	}

	/**
	 * Whether an active_plugins entry can be loaded without fataling.
	 * Checks the plugin main file, plus known-critical companion files for
	 * plugins that hard-require() them on load (WooCommerce's autoloader —
	 * exactly the file missing in the reported crash).
	 */
	public static function plugin_is_loadable( string $plugin ): bool {
		if ( '' === $plugin || ! is_string( $plugin ) ) {
			return false;
		}

		$main = Paths::plugins_dir() . '/' . $plugin;

		if ( ! file_exists( $main ) ) {
			return false;
		}

		if ( 0 === strpos( $plugin, 'woocommerce/' ) && ! file_exists( Paths::plugins_dir() . '/woocommerce/src/Autoloader.php' ) ) {
			return false;
		}

		return true;
	}

	/**
	 * The stored active_plugins list, read from the database. While a restore
	 * runs, get_option() returns the boot guard's filtered list instead.
	 *
	 * @return string[]
	 */
	public static function stored_active_plugins(): array {
		$plugins = self::decode_plugins( self::raw_active_plugins() );

		return array_values( array_filter( is_array( $plugins ) ? $plugins : array(), 'is_string' ) );
	}

	/**
	 * Plugins that must stay active through a restore: Wrensafe itself
	 * (the restore's remaining steps and its UI run through it) and any
	 * add-on that asks to. A backup made by another plugin — or on a site
	 * without Wrensafe — has an active_plugins list without them.
	 *
	 * @return string[]
	 */
	public static function always_active(): array {
		$keep = defined( 'WPBP_PLUGIN_BASENAME' ) ? array( WPBP_PLUGIN_BASENAME ) : array();

		/**
		 * Filters the plugins kept active when a restore swaps in the
		 * backup's database (plugin basenames, e.g. "my-addon/my-addon.php").
		 *
		 * @param string[] $keep
		 */
		$keep = (array) apply_filters( 'wpbp_restore_keep_active_plugins', $keep );

		return array_values(
			array_unique(
				array_filter(
					array_map( 'strval', $keep ),
					static function ( string $plugin ): bool {
						return '' !== $plugin && file_exists( Paths::plugins_dir() . '/' . $plugin );
					}
				)
			)
		);
	}

	/**
	 * Rewrite the LIVE active_plugins option so it only contains loadable
	 * plugins, and always contains Wrensafe (see always_active()) so the
	 * restore and its UI keep working. Uses direct SQL + cache purge because
	 * it runs right after the atomic table swap, when option caches can be
	 * stale.
	 *
	 * @return string[] The plugin entries that were removed.
	 */
	public static function filter_active_plugins( ?int $job_id = null ): array {
		$raw     = self::raw_active_plugins();
		$plugins = self::decode_plugins( $raw );
		$plugins = is_array( $plugins ) ? $plugins : array();
		$always  = self::always_active();
		$kept    = array();
		$removed = array();

		foreach ( $plugins as $plugin ) {
			$plugin = (string) $plugin;

			if ( in_array( $plugin, $always, true ) || self::plugin_is_loadable( $plugin ) ) {
				$kept[] = $plugin;
				continue;
			}

			$removed[] = $plugin;

			if ( 0 === strpos( $plugin, 'woocommerce/' ) ) {
				Logger::warning( 'restore_woocommerce_safety_deactivation: WooCommerce was deactivated because required files (src/Autoloader.php) are missing — this prevents a fatal error. Reinstall or restore WooCommerce files, then reactivate it.', array(), $job_id );
			} else {
				Logger::warning( 'restore_missing_plugin_deactivated: ' . $plugin . ' was deactivated because its files are missing or incomplete on this site.', array(), $job_id );
			}
		}

		$added = array_values( array_diff( $always, $kept ) );
		$kept  = array_merge( $kept, $added );

		if ( ! empty( $removed ) || ! empty( $added ) ) {
			self::save_active_plugins( $kept, null !== $raw );
		}

		if ( ! empty( $added ) ) {
			Logger::info( 'restore_kept_active: ' . implode( ', ', $added ) . ' kept active (the backup\'s plugin list did not include it).', array(), $job_id );
		}

		if ( ! empty( $removed ) ) {
			Logger::info(
				'restore_active_plugins_filtered: ' . count( $kept ) . ' plugins kept, ' . count( $removed ) . ' deactivated for safety (' . implode( ', ', $removed ) . ').',
				array(),
				$job_id
			);
		}

		return $removed;
	}

	/**
	 * A hosting company's own plugin from the backup (Bluehost, HostGator,
	 * Hostinger, …) is deactivated when the site moves to a different site —
	 * unless this site already had it active (it's on the same host). Those
	 * plugins expect their host's services; the Bluehost plugin, for one,
	 * starts updating every plugin the moment the moved site loads. The files
	 * stay, so it can be activated again under Plugins.
	 *
	 * @param string[] $site_plugins This site's active plugins before the restore.
	 * @return string[] The plugins deactivated.
	 */
	public static function deactivate_host_plugins( array $site_plugins, ?int $job_id = null ): array {
		/**
		 * Filters the pattern of hosting-company plugins (matched against the
		 * plugin's folder name) deactivated when a site moves elsewhere.
		 *
		 * @param string $pattern Regular expression.
		 */
		$pattern = (string) apply_filters( 'wpbp_host_plugins_pattern', '/^(?:bluehost-wordpress-plugin|wp-plugin-bluehost|mojo-marketplace-wp-plugin|wp-plugin-hostgator|wp-plugin-web|wp-plugin-crazy-domains|hostinger|hostinger-tools)$/i' );
		$active  = self::decode_plugins( self::raw_active_plugins() );
		$host    = array();

		foreach ( is_array( $active ) ? $active : array() as $plugin ) {
			$plugin = (string) $plugin;
			$folder = explode( '/', $plugin, 2 )[0];

			if ( false !== strpos( $plugin, '/' ) && 1 === preg_match( $pattern, $folder ) && ! in_array( $plugin, $site_plugins, true ) ) {
				$host[] = $plugin;
			}
		}

		$removed = empty( $host ) ? array() : self::deactivate( $host, $job_id );

		if ( ! empty( $removed ) ) {
			Logger::warning( 'restore_host_plugin_deactivated: ' . implode( ', ', $removed ) . ' deactivated — it belongs to the hosting company the site came from. Activate it again under Plugins if this site is still hosted there.', array(), $job_id );
		}

		return $removed;
	}

	/**
	 * Remove a specific set of plugins from the LIVE active_plugins option
	 * (direct SQL + cache purge, so it's correct right after the atomic table
	 * swap). Wrensafe itself is always kept. Returns the plugins that
	 * were actually removed. Shared by the post-swap filter and the deeper
	 * post-restore integrity checker.
	 *
	 * @param string[] $plugins Plugin entries to deactivate.
	 * @return string[]
	 */
	public static function deactivate( array $plugins, ?int $job_id = null ): array {
		$to_remove = array();
		foreach ( $plugins as $plugin ) {
			$plugin = (string) $plugin;
			if ( '' !== $plugin && ! ( defined( 'WPBP_PLUGIN_BASENAME' ) && WPBP_PLUGIN_BASENAME === $plugin ) ) {
				$to_remove[ $plugin ] = true;
			}
		}

		if ( empty( $to_remove ) ) {
			return array();
		}

		$raw     = self::raw_active_plugins();
		$current = self::decode_plugins( $raw );

		if ( null === $raw || ! is_array( $current ) ) {
			return array();
		}

		$kept    = array();
		$removed = array();

		foreach ( $current as $plugin ) {
			$plugin = (string) $plugin;
			if ( isset( $to_remove[ $plugin ] ) ) {
				$removed[] = $plugin;
			} else {
				$kept[] = $plugin;
			}
		}

		if ( ! empty( $removed ) ) {
			self::save_active_plugins( $kept, true );
			Logger::info( 'restore_plugins_deactivated: ' . implode( ', ', $removed ) . '.', array(), $job_id );
		}

		return $removed;
	}

	private static function guard_path(): string {
		return Paths::mu_plugins_dir() . '/' . self::GUARD_FILE_NAME;
	}

	/**
	 * Install the temporary MU-plugin boot guard for the duration of a
	 * restore. MU-plugins load before active plugins are resolved, so this
	 * filter protects EVERY request while files are being extracted — a
	 * half-restored plugin simply isn't loaded instead of fataling the site.
	 * Best effort: a host that blocks mu-plugins writes only loses this layer
	 * (the post-swap filter still applies), and the failure is logged.
	 *
	 * While the restore runs, every request loads only the plugins this site
	 * had active before the restore, plus Wrensafe, and no theme; and no
	 * scheduled (cron) task runs.
	 * Plugins that only the backup activates stay off until the restore is
	 * done: some redirect the first admin request after activation to a
	 * welcome screen (WPvivid does), which would cut the restore off.
	 *
	 * @param string[] $site_plugins The destination's active plugins before the restore.
	 */
	public static function install_boot_guard( int $job_id, array $site_plugins = array() ): bool {
		$path   = self::guard_path();
		$mu_dir = dirname( $path );

		if ( ! is_dir( $mu_dir ) && ! wp_mkdir_p( $mu_dir ) ) {
			Logger::warning( 'Restore plugin boot guard could not be installed (mu-plugins directory is not writable). Continuing without it.', array(), $job_id );
			return false;
		}

		$step_plugins = array_values( array_unique( array_merge( self::always_active(), array_filter( $site_plugins, 'is_string' ) ) ) );

		$code = <<<'PHP'
<?php
/**
 * Wrensafe temporary restore safety guard.
 *
 * Present only while a restore is running (removed automatically when it
 * finishes, fails, or is cleared). Prevents a half-restored plugin from
 * fataling the site: any active plugin whose main file is missing, or whose
 * known-required companion file (WooCommerce's src/Autoloader.php) is
 * missing, is skipped at boot instead of being loaded.
 *
 * Safe to delete manually if found after a crash.
 */

defined( 'ABSPATH' ) || exit;

// The restore's last step loads the site once as visitors will see it
// (RestoreHealthCheck, through WordPress's own fatal-error check request).
// Only that request is let through: its key must be the one the health check
// stored, and its nonce the one WordPress's scrape transient holds.
if ( isset( $_GET['wp_scrape_key'], $_GET['wp_scrape_nonce'] ) ) {
	$wpbp_scrape_key   = sanitize_key( wp_unslash( $_GET['wp_scrape_key'] ) );
	$wpbp_scrape_nonce = sanitize_text_field( wp_unslash( $_GET['wp_scrape_nonce'] ) );
	$wpbp_scrape_ours  = get_transient( /*WPBP_SCRAPE_TRANSIENT*/ );
	$wpbp_scrape_saved = '' !== $wpbp_scrape_key ? get_transient( 'scrape_key_' . $wpbp_scrape_key ) : false;

	if ( is_string( $wpbp_scrape_ours ) && '' !== $wpbp_scrape_ours && hash_equals( $wpbp_scrape_ours, $wpbp_scrape_key )
		&& is_string( $wpbp_scrape_saved ) && '' !== $wpbp_scrape_nonce && hash_equals( $wpbp_scrape_saved, $wpbp_scrape_nonce ) ) {
		return;
	}
}

// No scheduled tasks run while the restore is in progress: the restored
// database brings its own, overdue schedule (plugin and core updates among
// them), which would start in wp-cron.php in the middle of the restore.
add_filter(
	'pre_get_ready_cron_jobs',
	static function () {
		return array();
	}
);

// Until the restore is done, every request runs with this site's own
// plugins only (plus Wrensafe) and no theme. Once the backup's database
// is in, its plugins and theme would otherwise load half-migrated: a hosting
// plugin starting updates (WordPress's .maintenance file then blocks the
// restore's own requests), a plugin redirecting to its welcome screen, a
// theme calling functions of a plugin that isn't loaded. Visitors see
// Wrensafe's maintenance page meanwhile.
// Not on Appearance > Themes or in the Customizer: they would "repair" the
// missing theme by switching the site to a default one for good.
$wpbp_script = isset( $_SERVER['SCRIPT_NAME'] ) ? basename( sanitize_text_field( wp_unslash( $_SERVER['SCRIPT_NAME'] ) ) ) : '';

if ( ! in_array( $wpbp_script, array( 'themes.php', 'customize.php' ), true ) ) {
	$wpbp_no_theme = static function () {
		return 'wpbp-restore-no-theme';
	};
	add_filter( 'template', $wpbp_no_theme, PHP_INT_MAX );
	add_filter( 'stylesheet', $wpbp_no_theme, PHP_INT_MAX );
}

add_filter(
	'option_active_plugins',
	static function ( $plugins ) {
		if ( ! is_array( $plugins ) ) {
			return $plugins;
		}

		// Only this site's own plugins and Wrensafe (see above).
		$plugins = array_values( array_intersect( $plugins, /*WPBP_STEP_PLUGINS*/ ) );

		return array_values(
			array_filter(
				$plugins,
				static function ( $plugin ) {
					if ( ! is_string( $plugin ) || '' === $plugin ) {
						return false;
					}

					if ( ! file_exists( WP_PLUGIN_DIR . '/' . $plugin ) ) {
						return false;
					}

					if ( 0 === strpos( $plugin, 'woocommerce/' ) && ! file_exists( WP_PLUGIN_DIR . '/woocommerce/src/Autoloader.php' ) ) {
						return false;
					}

					return true;
				}
			)
		);
	}
);
PHP;

		// var_export() writes the values as PHP literals (strings and an
		// array of strings only): nothing from them can become code.
		$code    = str_replace(
			array( '/*WPBP_STEP_PLUGINS*/', '/*WPBP_SCRAPE_TRANSIENT*/' ),
			array( var_export( $step_plugins, true ), var_export( self::SCRAPE_TRANSIENT, true ) ), // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_var_export -- writes PHP literals, see above.
			$code
		);
		$written = self::write_file( $path, $code );

		if ( $written ) {
			Logger::info( 'Restore plugin boot guard installed (temporary mu-plugin; auto-removed when the restore finishes).', array(), $job_id );
		} else {
			Logger::warning( 'Restore plugin boot guard could not be written. Continuing without it.', array(), $job_id );
		}

		return $written;
	}

	/**
	 * Write the guard file: with WP_Filesystem where WordPress writes files
	 * itself (the "direct" method), otherwise as the rest of the restore
	 * writes files (a restore can't ask for FTP credentials mid-way).
	 */
	private static function write_file( string $path, string $code ): bool {
		if ( ! function_exists( 'get_filesystem_method' ) ) {
			require_once Paths::admin_dir() . '/includes/file.php';
		}

		if ( 'direct' === get_filesystem_method( array(), dirname( $path ) ) && WP_Filesystem( false, dirname( $path ) ) ) {
			global $wp_filesystem;

			if ( $wp_filesystem instanceof \WP_Filesystem_Base ) {
				return (bool) $wp_filesystem->put_contents( $path, $code, defined( 'FS_CHMOD_FILE' ) ? FS_CHMOD_FILE : 0644 );
			}
		}

		return false !== file_put_contents( $path, $code ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
	}

	/** Whether the boot guard is in place. */
	public static function has_boot_guard(): bool {
		return file_exists( self::guard_path() );
	}

	/**
	 * Remove the boot guard. Called on finalize, failure, cancel, by the
	 * admin "Clear Restore Lock" recovery action, on deactivation and on
	 * uninstall — idempotent. The guard changes nothing it would need to
	 * undo: it only filters the active plugins and the theme at run time
	 * and pauses cron while the file exists, so removing the file restores
	 * the site's own plugins, theme and scheduled tasks.
	 */
	public static function remove_boot_guard(): void {
		$path = self::guard_path();

		if ( file_exists( $path ) ) {
			wp_delete_file( $path );
		}

		delete_transient( self::SCRAPE_TRANSIENT );
	}
}
