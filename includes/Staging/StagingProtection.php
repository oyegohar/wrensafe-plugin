<?php
/**
 * Password protection and no-index enforcement for a staging site.
 *
 * Password protection is a small PHP guard inside the staging site (see
 * includes/Staging/guard/staging-guard.php), copied into the staging site's
 * must-use plugins folder, so it works on every web server, nginx included.
 *
 * The user name and a bcrypt hash of the password are kept in
 * wrensafe-staging-access.php in the staging site's own folder (next to its
 * wp-config.php): a PHP file that stops as soon as it is requested over the
 * web, so the hash can't be downloaded. The guard does nothing without that
 * file, and Push to Live never copies it (it copies the database and the
 * content folder, and leaves the guard out too), so the live site is never
 * affected.
 *
 * Until 1.2.1 the protection was HTTP Basic Auth in the staging site's
 * .htaccess, with the hash in .htpasswd: it did nothing on nginx (or Apache
 * without AllowOverride), where the .htpasswd file could even be
 * downloaded. upgrade() moves such a staging site to the guard, keeping
 * its user name and password.
 *
 * @package WPBP\Staging
 */

namespace WPBP\Staging;

use WPBP\Core\Database\StagingSitesRepository;
use WPBP\Utils\Logger;
use WPBP\Utils\Paths;

defined( 'ABSPATH' ) || exit;

// Database access in this file is direct by design: Wrensafe's own tables (or,
// in the backup/restore engine, the site's tables being exported, imported or
// swapped) have no WordPress API, and the data must be read fresh, so it is not
// object-cached. Every query goes through $wpdb->prepare(), with the table
// name as an identifier placeholder (%i).
// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

class StagingProtection {

	/** The guard, in the staging site's must-use plugins folder. */
	const GUARD_FILE = 'wrensafe-staging-guard.php';

	/** User name, password hash and cookie secret, in the staging site's own folder. */
	const ACCESS_FILE = 'wrensafe-staging-access.php';

	/** Option: the guard version the staging sites were last brought up to. */
	private const UPGRADED_OPTION = 'wpbp_staging_guard_version';

	/** The block earlier versions put at the top of the staging site's .htaccess. */
	private const LEGACY_HTACCESS_BLOCK = "/AuthType Basic\\nAuthName \"Staging Site\"\\nAuthUserFile [^\\n]*\\nRequire valid-user\\n\\n?/";

	/**
	 * Protect a staging site with a user name and password.
	 *
	 * @return bool Whether the protection is in place.
	 */
	public function password_protect( string $staging_path, string $username, string $password ): bool {
		return $this->install( $staging_path, $username, password_hash( $password, PASSWORD_BCRYPT ) );
	}

	/**
	 * Turn password protection off (the guard does nothing without its
	 * settings file).
	 */
	public function remove_password_protection( string $staging_path ): void {
		$staging_path = trailingslashit( $staging_path );

		foreach ( array( self::ACCESS_FILE, '.htpasswd' ) as $file ) {
			if ( file_exists( $staging_path . $file ) ) {
				wp_delete_file( $staging_path . $file );
			}
		}

		$this->remove_legacy_htaccess( $staging_path );
	}

	/**
	 * Bring a password-protected staging site's protection up to date: a
	 * newer guard after a Wrensafe update, or the guard instead of the
	 * .htaccess rules of a staging site made before 1.2.1.
	 *
	 * @param array<string,mixed> $site Staging site row.
	 * @return bool Whether the site is protected by the current guard now.
	 */
	public function upgrade( array $site ): bool {
		global $wpdb;

		// Made before 1.2.1: the copied roles and capabilities still had the
		// live site's prefix, so nobody could manage the staging site.
		$prefix = \WPBP\Ajax\StagingAjax::staging_prefix( $site );

		if ( '' !== $prefix ) {
			( new StagingCreator() )->repair_prefixed_keys( $prefix, $wpdb->prefix );
		}

		if ( empty( $site['password_protected'] ) ) {
			return true;
		}

		$path = \WPBP\Ajax\StagingAjax::staging_folder( $site );

		if ( '' === $path || ! is_dir( $path ) ) {
			return false;
		}

		$path = trailingslashit( $path );

		if ( is_file( $path . self::ACCESS_FILE ) ) {
			if ( ! $this->install_guard( $path ) ) {
				return false;
			}

			// Guarded since 1.2.1–1.2.3, when pages could still be cached.
			$this->disable_page_cache( $path );

			return true;
		}

		// A staging site made before 1.2.1: its user name and password hash
		// are in .htpasswd ("user:$2y$…", bcrypt since 1.0.x).
		$line  = is_file( $path . '.htpasswd' ) ? trim( (string) file_get_contents( $path . '.htpasswd' ) ) : '';
		$parts = explode( ':', (string) strtok( $line, "\n" ), 2 );

		if ( 2 !== count( $parts ) || '' === $parts[0] || 0 !== strpos( $parts[1], '$2' ) ) {
			Logger::warning( 'staging_protection_not_upgraded: staging site #' . (int) ( $site['id'] ?? 0 ) . ' is marked password-protected, but its password couldn’t be found to move it to the protection that works on every server. Delete the staging site and create it again with a password.' );
			return false;
		}

		if ( ! $this->install( $path, $parts[0], $parts[1] ) ) {
			return false;
		}

		Logger::info( 'staging_protection_upgraded: staging site #' . (int) ( $site['id'] ?? 0 ) . ' is now protected by Wrensafe’s password guard, which works on every web server (it used .htaccess, which nginx ignores). Its user name and password are unchanged.' );

		return true;
	}

	/**
	 * Upgrade every staging site once after Wrensafe itself was updated
	 * (cheap otherwise: one option read).
	 */
	public static function maybe_upgrade_all(): void {
		$version = self::guard_version();

		if ( '' === $version || get_option( self::UPGRADED_OPTION ) === $version || false !== get_transient( 'wpbp_staging_guard_retry' ) ) {
			return;
		}

		$all_done = true;

		foreach ( StagingSitesRepository::list_all() as $site ) {
			$all_done = ( new self() )->upgrade( (array) $site ) && $all_done;
		}

		if ( $all_done ) {
			update_option( self::UPGRADED_OPTION, $version );
		} else {
			// A site that couldn't be upgraded yet (a folder that isn't
			// writable, say) is tried again an hour later, and whenever the
			// Staging page is opened.
			set_transient( 'wpbp_staging_guard_retry', 1, HOUR_IN_SECONDS );
		}
	}

	/**
	 * Whether a staging site's protection is the current guard (shown on
	 * the Staging page).
	 *
	 * @param array<string,mixed> $site Staging site row.
	 */
	public static function is_guarded( array $site ): bool {
		$path = \WPBP\Ajax\StagingAjax::staging_folder( $site );

		return '' !== $path && is_file( trailingslashit( $path ) . self::ACCESS_FILE ) && is_file( self::guard_path( trailingslashit( $path ) ) );
	}

	/**
	 * The guard's place in a staging site: its must-use plugins folder, at
	 * the same place inside its content folder as this site's.
	 */
	public static function guard_path( string $staging_path ): string {
		$content = Paths::content_dir();
		$mu      = Paths::mu_plugins_dir();
		$inside  = Paths::is_within( $mu, $content ) ? ltrim( substr( $mu, strlen( $content ) ), '/' ) : 'mu-plugins';

		return untrailingslashit( StagingCreator::staging_content_dir( $staging_path ) ) . '/' . ( '' !== $inside ? $inside . '/' : '' ) . self::GUARD_FILE;
	}

	/**
	 * Disable search engine indexing on the staging copy by setting
	 * blog_public = 0 in its own (already-cloned) options table — WordPress
	 * core then automatically emits the noindex meta tag.
	 */
	public function disable_indexing( string $staging_prefix ): void {
		global $wpdb;

		$wpdb->query(
			$wpdb->prepare(
				'UPDATE %i SET option_value = %s WHERE option_name = %s',
				$staging_prefix . 'options',
				'0',
				'blog_public'
			)
		);
	}

	// -- Installing -------------------------------------------------------------

	/**
	 * Write the settings file, copy the guard into the staging site's
	 * mu-plugins folder and remove the old .htaccess protection.
	 */
	private function install( string $staging_path, string $username, string $hash ): bool {
		$staging_path = trailingslashit( $staging_path );
		$site_url     = $this->staging_url( $staging_path );

		$settings = array(
			'version' => 1,
			'path'    => wp_normalize_path( $staging_path ),
			'user'    => $username,
			'hash'    => $hash,
			'secret'  => bin2hex( random_bytes( 32 ) ),
			'home'    => $site_url,
			'lang'    => (string) get_bloginfo( 'language' ),
			'labels'  => array(
				'title'    => __( 'Staging site', 'wrensafe' ),
				'intro'    => __( 'This staging site is password protected. Enter its user name and password to continue.', 'wrensafe' ),
				'user'     => __( 'User name', 'wrensafe' ),
				'password' => __( 'Password', 'wrensafe' ),
				'submit'   => __( 'Continue', 'wrensafe' ),
				'wrong'    => __( 'The user name or password is wrong.', 'wrensafe' ),
				'many'     => __( 'Too many wrong passwords. Try again in 15 minutes.', 'wrensafe' ),
			),
		);

		// Keep the cookie secret of a guard being upgraded, so nobody has to
		// enter the password again.
		$current = $this->read_settings( $staging_path );

		if ( null !== $current && ! empty( $current['secret'] ) && (string) $current['hash'] === $hash ) {
			$settings['secret'] = (string) $current['secret'];
		}

		// The first line stops PHP when the file is requested over the web;
		// the guard reads the JSON after it.
		$contents = "<?php exit; ?>\n" . wp_json_encode( $settings ) . "\n";

		if ( false === file_put_contents( $staging_path . self::ACCESS_FILE, $contents ) || ! $this->install_guard( $staging_path ) ) {
			Logger::error( 'staging_protection_failed: the password guard couldn’t be written into the staging site’s folder (' . basename( untrailingslashit( $staging_path ) ) . '). Check that the folder is writable.' );
			return false;
		}

		// Before the old .htaccess login goes, so no cached page is reachable without a password.
		$this->disable_page_cache( $staging_path );

		$this->remove_legacy_htaccess( $staging_path );

		if ( file_exists( $staging_path . '.htpasswd' ) ) {
			wp_delete_file( $staging_path . '.htpasswd' );
		}

		return true;
	}

	/**
	 * A password-protected staging site doesn't cache pages. A page cache
	 * (advanced-cache.php, which WordPress loads before must-use plugins when
	 * WP_CACHE is on) would show a page saved for someone who entered the
	 * password to visitors who didn't. Turns WP_CACHE off in the staging
	 * site's own wp-config.php and, when it was on, empties the staging site's
	 * page cache folder. When WP_CACHE may still be on but can't be changed
	 * here, the folder is emptied and a warning logged once per Wrensafe
	 * version (the Staging page calls this on every visit).
	 */
	private function disable_page_cache( string $staging_path ): void {
		$config = $staging_path . 'wp-config.php';

		if ( ! is_file( $config ) ) {
			return;
		}

		$php  = (string) file_get_contents( $config );
		$new  = preg_replace( '/define\s*\(\s*([\'"])WP_CACHE\1\s*,\s*(?:true|1|([\'"])(?:1|true)\2)\s*\)/i', "define( 'WP_CACHE', false )", $php );
		$off  = is_string( $new ) && $new !== $php && wp_is_writable( $config ) && false !== file_put_contents( $config, $new );
		$name = basename( untrailingslashit( $staging_path ) );

		if ( $off ) {
			Logger::info( 'staging_page_cache_off: page caching turned off on the password-protected staging site ' . $name . ' (WP_CACHE in its wp-config.php) and its cached pages deleted.' );
		} elseif ( preg_match( '/define\s*\(\s*([\'"])WP_CACHE\1\s*,\s*false\s*\)/i', $php )
			|| ( false === stripos( $php, 'WP_CACHE' ) && ! is_file( untrailingslashit( StagingCreator::staging_content_dir( $staging_path ) ) . '/advanced-cache.php' ) ) ) {
			// Page caching is off, or never set and no page-cache drop-in: nothing to delete.
			return;
		}

		$cache = untrailingslashit( StagingCreator::staging_content_dir( $staging_path ) ) . '/cache';

		if ( ! is_dir( $cache ) || is_link( $cache ) ) {
			return;
		}

		// When WP_CACHE couldn't be changed: once per Wrensafe version, not on every visit of the Staging page.
		$checked = (array) get_option( 'wpbp_staging_cache_checked', array() );

		if ( ! $off && WPBP_VERSION === ( $checked[ $name ] ?? '' ) ) {
			return;
		}

		\WPBP\Utils\FileSystem::delete_directory( $cache );

		if ( ! $off ) {
			$checked[ $name ] = WPBP_VERSION;
			update_option( 'wpbp_staging_cache_checked', $checked, false );
			// WP_CACHE is set in a way this can't change (another file, getenv(), …).
			Logger::warning( 'staging_page_cache_on: page caching may still be on for the password-protected staging site ' . $name . '. Its cached pages were deleted; set WP_CACHE to false in its wp-config.php so pages aren\'t cached again.' );
		}
	}

	/** Copy the current guard into the staging site's must-use plugins folder. */
	private function install_guard( string $staging_path ): bool {
		$source = WPBP_PLUGIN_DIR . 'includes/Staging/guard/staging-guard.php';
		$target = self::guard_path( $staging_path );

		if ( is_file( $target ) && md5_file( $source ) === md5_file( $target ) ) {
			return true;
		}

		return wp_mkdir_p( dirname( $target ) ) && copy( $source, $target );
	}

	/** Take the Basic Auth block of earlier versions out of .htaccess. */
	private function remove_legacy_htaccess( string $staging_path ): void {
		$htaccess = $staging_path . '.htaccess';

		if ( ! is_file( $htaccess ) ) {
			return;
		}

		$contents = (string) file_get_contents( $htaccess );
		$cleaned  = (string) preg_replace( self::LEGACY_HTACCESS_BLOCK, '', $contents );

		if ( $cleaned !== $contents ) {
			file_put_contents( $htaccess, $cleaned );
		}
	}

	/**
	 * The guard's settings in a staging folder, or null.
	 *
	 * @return array<string,mixed>|null
	 */
	private function read_settings( string $staging_path ): ?array {
		$file = $staging_path . self::ACCESS_FILE;

		if ( ! is_file( $file ) ) {
			return null;
		}

		$raw      = (string) file_get_contents( $file );
		$break    = strpos( $raw, "\n" );
		$settings = false !== $break ? json_decode( substr( $raw, $break + 1 ), true ) : null;

		return is_array( $settings ) ? $settings : null;
	}

	/** The staging site's address, from its row (by folder). */
	private function staging_url( string $staging_path ): string {
		foreach ( StagingSitesRepository::list_all() as $site ) {
			if ( trailingslashit( wp_normalize_path( (string) $site['path'] ) ) === trailingslashit( wp_normalize_path( $staging_path ) ) ) {
				return (string) $site['url'];
			}
		}

		return trailingslashit( home_url() ) . basename( untrailingslashit( $staging_path ) ) . '/';
	}

	/** Version of the bundled guard (changes whenever the file does). */
	private static function guard_version(): string {
		$file = WPBP_PLUGIN_DIR . 'includes/Staging/guard/staging-guard.php';

		return is_file( $file ) ? WPBP_VERSION . '-' . substr( (string) md5_file( $file ), 0, 8 ) : '';
	}
}
