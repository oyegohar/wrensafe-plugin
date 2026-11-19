<?php
/**
 * After a restore: does the site actually load?
 *
 * Loads the home page once through a loopback request, using WordPress's own
 * fatal-error check (the "scrape" request the plugin and theme editors use:
 * wp_start_scraping_edited_file_errors() reports any fatal error raised while
 * plugins and the theme load). When a restored plugin stops the site — for
 * example one that needs a newer PHP than this server's — it is deactivated
 * and the site is checked again, so a restore never ends on a white screen.
 *
 * Best effort: when the loopback request isn't possible on this server, the
 * restore simply completes as before.
 *
 * @package WPBP\Restore
 */

namespace WPBP\Restore;

defined( 'ABSPATH' ) || exit;

// Database access in this file is direct by design: Wrensafe's own tables (or,
// in the backup/restore engine, the site's tables being exported, imported or
// swapped) have no WordPress API, and the data must be read fresh, so it is not
// object-cached. Every query goes through $wpdb->prepare().
// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

class RestoreHealthCheck {

	/**
	 * Load the home page and report what happened.
	 *
	 * @return array{status:string,file?:string,message?:string} status: ok | fatal | unavailable.
	 */
	public static function probe(): array {
		$key   = md5( wp_generate_password( 32, false ) );
		$nonce = wp_generate_password( 32, false );

		set_transient( 'scrape_key_' . $key, $nonce, 2 * MINUTE_IN_SECONDS );
		// Marks this request as the health check's own, for the boot guard.
		set_transient( PluginSafety::SCRAPE_TRANSIENT, $key, 2 * MINUTE_IN_SECONDS );

		$response = wp_remote_get(
			add_query_arg(
				array(
					'wp_scrape_key'   => $key,
					'wp_scrape_nonce' => $nonce,
				),
				home_url( '/' )
			),
			array(
				'timeout'   => 25,
				'headers'   => array( 'Cache-Control' => 'no-cache' ),
				/** This filter is documented in wp-includes/class-wp-http-streams.php */
				'sslverify' => apply_filters( 'https_local_ssl_verify', false ), // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WordPress core filter.
			)
		);

		delete_transient( 'scrape_key_' . $key );
		delete_transient( PluginSafety::SCRAPE_TRANSIENT );

		if ( is_wp_error( $response ) ) {
			return array( 'status' => 'unavailable' );
		}

		$body  = (string) wp_remote_retrieve_body( $response );
		$start = "###### wp_scraping_result_start:$key ######";
		$end   = "###### wp_scraping_result_end:$key ######";
		$from  = strpos( $body, $start );
		$to    = false === $from ? false : strpos( $body, $end, $from );

		if ( false === $from || false === $to ) {
			// No report in the page (cached, redirected elsewhere, …): nothing to act on.
			return array( 'status' => 'ok' );
		}

		$result = json_decode( trim( substr( $body, $from + strlen( $start ), $to - $from - strlen( $start ) ) ), true );

		if ( ! is_array( $result ) || empty( $result['file'] ) ) {
			return array( 'status' => 'ok' );
		}

		return array(
			'status'  => 'fatal',
			'file'    => wp_normalize_path( (string) $result['file'] ),
			'message' => (string) ( $result['message'] ?? '' ),
		);
	}

	/**
	 * The active plugin (basename) a file belongs to, or ''.
	 */
	public static function plugin_for_file( string $file ): string {
		$file = self::absolute( $file );
		$root = \WPBP\Utils\Paths::plugins_dir() . '/';

		if ( 0 !== strpos( $file, $root ) ) {
			return '';
		}

		$relative = substr( $file, strlen( $root ) );
		$folder   = explode( '/', $relative, 2 )[0];

		foreach ( PluginSafety::stored_active_plugins() as $plugin ) {
			$plugin = (string) $plugin;

			if ( $plugin === $relative || 0 === strpos( $plugin, $folder . '/' ) ) {
				return $plugin;
			}
		}

		return '';
	}

	/**
	 * The active theme (stylesheet or template) a file belongs to, or ''.
	 */
	public static function theme_for_file( string $file ): string {
		$file = self::absolute( $file );
		$root = trailingslashit( wp_normalize_path( get_theme_root() ) );

		if ( 0 !== strpos( $file, $root ) ) {
			return '';
		}

		$slug = explode( '/', substr( $file, strlen( $root ) ), 2 )[0];

		return in_array( $slug, array( self::stored_option( 'stylesheet' ), self::stored_option( 'template' ) ), true ) ? $slug : '';
	}

	/** An option read from the database (the boot guard filters the theme options). */
	private static function stored_option( string $name ): string {
		global $wpdb;

		return (string) $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s LIMIT 1", $name ) );
	}

	/** WordPress reports the file relative to the WordPress folder. */
	private static function absolute( string $file ): string {
		$file = wp_normalize_path( $file );

		return ( '' !== $file && '/' !== $file[0] && ! preg_match( '#^[A-Za-z]:/#', $file ) ) ? \WPBP\Utils\Paths::root() . '/' . ltrim( $file, '/' ) : $file;
	}

	/**
	 * A default theme installed on this site to fall back on, or ''.
	 */
	public static function fallback_theme( string $avoid ): string {
		$candidates = array();

		if ( defined( 'WP_DEFAULT_THEME' ) ) {
			$candidates[] = (string) WP_DEFAULT_THEME;
		}

		$themes = array_keys( wp_get_themes() );
		rsort( $themes );

		foreach ( $themes as $slug ) {
			if ( 0 === strpos( (string) $slug, 'twenty' ) ) {
				$candidates[] = (string) $slug;
			}
		}

		foreach ( $candidates as $slug ) {
			if ( $slug !== $avoid && wp_get_theme( $slug )->exists() ) {
				return $slug;
			}
		}

		return '';
	}
}
