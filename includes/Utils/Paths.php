<?php
/**
 * Where WordPress keeps its files on this server.
 *
 * Every location comes from WordPress itself, so custom layouts work
 * (wp-content moved out of the WordPress folder, a renamed plugins or
 * uploads folder, extra theme directories, Bedrock-style installs):
 *
 * - plugins:     WP_PLUGIN_DIR
 * - mu-plugins:  WPMU_PLUGIN_DIR
 * - themes:      get_theme_root() and every directory registered with register_theme_directory()
 * - uploads:     wp_upload_dir()
 * - wp-includes: WPINC
 *
 * A backup plugin also has to archive the WordPress installation itself
 * and its content folder. WordPress has no function that returns those two
 * folders, so ABSPATH and WP_CONTENT_DIR are read here, and only here.
 *
 * Every directory is returned normalized (wp_normalize_path()) and without
 * a trailing slash.
 *
 * @package WPBP\Utils
 */

namespace WPBP\Utils;

defined( 'ABSPATH' ) || exit;

final class Paths {

	/** Names of the authentication keys and salts (constants and their option fallbacks). */
	public const SECRET_KEY_NAMES = array(
		'auth_key',
		'auth_salt',
		'secure_auth_key',
		'secure_auth_salt',
		'logged_in_key',
		'logged_in_salt',
		'nonce_key',
		'nonce_salt',
	);

	/** The folder WordPress is installed in (where wp-load.php lives). */
	public static function root(): string {
		return self::clean( ABSPATH );
	}

	/** The content folder (wp-content, or wherever WP_CONTENT_DIR points). */
	public static function content_dir(): string {
		return self::clean( WP_CONTENT_DIR );
	}

	/** The plugins folder. */
	public static function plugins_dir(): string {
		return self::clean( WP_PLUGIN_DIR );
	}

	/** The must-use plugins folder. */
	public static function mu_plugins_dir(): string {
		return self::clean( WPMU_PLUGIN_DIR );
	}

	/** The default themes folder. */
	public static function themes_dir(): string {
		return self::clean( get_theme_root() );
	}

	/**
	 * Every themes folder: the default one plus any registered with
	 * register_theme_directory().
	 *
	 * @return string[]
	 */
	public static function theme_dirs(): array {
		global $wp_theme_directories;

		$dirs = array( self::themes_dir() );

		foreach ( (array) $wp_theme_directories as $dir ) {
			if ( is_string( $dir ) && '' !== $dir ) {
				$dirs[] = self::clean( $dir );
			}
		}

		return array_values( array_unique( $dirs ) );
	}

	/** The uploads folder (the base folder, without the year/month part). */
	public static function uploads_dir(): string {
		$uploads = wp_upload_dir( null, false );

		return self::clean( (string) ( $uploads['basedir'] ?? '' ) );
	}

	/** The wp-includes folder. */
	public static function includes_dir(): string {
		return self::root() . '/' . WPINC;
	}

	/** The wp-admin folder (WordPress always keeps it in its install folder). */
	public static function admin_dir(): string {
		return self::root() . '/wp-admin';
	}

	/**
	 * The wp-config.php this site loaded: in the WordPress folder, or one
	 * level above it (the same lookup as wp-load.php). '' when not found.
	 */
	public static function config_file(): string {
		$root = self::root();

		if ( file_exists( $root . '/wp-config.php' ) ) {
			return $root . '/wp-config.php';
		}

		$parent = self::clean( dirname( $root ) );

		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- open_basedir can forbid the parent folder; wp-load.php checks it the same way.
		if ( @file_exists( $parent . '/wp-config.php' ) && ! @file_exists( $parent . '/wp-settings.php' ) ) {
			return $parent . '/wp-config.php';
		}

		return '';
	}

	/**
	 * Whether a file holds this site's database password, keys and salts:
	 * any file named wp-config.php (the site's own, and the copies staging
	 * sites keep), except inside a plugin or theme folder.
	 */
	public static function is_config_file( string $path ): bool {
		$path = self::clean( $path );

		if ( 'wp-config.php' !== strtolower( basename( $path ) ) ) {
			return false;
		}

		foreach ( array_merge( array( self::plugins_dir(), self::mu_plugins_dir() ), self::theme_dirs() ) as $dir ) {
			if ( self::is_within( $path, $dir ) ) {
				return false;
			}
		}

		return true;
	}

	/** Whether $path is $dir itself or inside it. */
	public static function is_within( string $path, string $dir ): bool {
		$path = self::clean( $path );
		$dir  = self::clean( $dir );

		return '' !== $dir && ( $path === $dir || 0 === strpos( $path . '/', $dir . '/' ) );
	}

	/**
	 * $path relative to the WordPress folder ("wp-content/uploads"), or ''
	 * when it is outside it.
	 */
	public static function relative_to_root( string $path ): string {
		$path = self::clean( $path );
		$root = self::root();

		return 0 === strpos( $path, $root . '/' ) ? substr( $path, strlen( $root ) + 1 ) : '';
	}

	/**
	 * A short form of $path for messages: relative to the WordPress folder
	 * when it is inside it, the full path otherwise.
	 */
	public static function display( string $path ): string {
		$relative = self::relative_to_root( $path );

		return '' !== $relative ? $relative : self::clean( $path );
	}

	/** Normalize a path and drop its trailing slash. */
	private static function clean( string $path ): string {
		return untrailingslashit( wp_normalize_path( $path ) );
	}
}
