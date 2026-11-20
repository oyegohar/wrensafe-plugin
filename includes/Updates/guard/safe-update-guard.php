<?php
/**
 * Plugin Name: Wrensafe Safe Updates guard (temporary)
 * Description: Present only while Wrensafe updates plugins with Safe Updates; removed automatically when the updates finish.
 *
 * If an update stops the site from loading and the request that was
 * checking it is cut off, Wrensafe's own next requests load without the
 * update being checked, so the previous version can still be put back.
 * Wrensafe's own requests are:
 *
 *  - its AJAX requests (admin-ajax.php?action=wpbp_safe_update_…) and the
 *    Safe Updates page, when they carry the run's secret, which only the
 *    Safe Updates script of a user who can update plugins is given;
 *  - WP-Cron, when wp-cron.php was started by WordPress itself (its
 *    doing_wp_cron value is the current cron lock) or by WP-CLI.
 *
 * Visitors and every other request load everything as usual, unless the
 * run has stalled: when an update installed by the run hasn't been checked
 * and the run has shown no sign of life for six minutes (the request
 * updating it was cut off), WP-Cron and visitors' page views load without
 * that update. Visitors then get a working site instead of its error, and
 * WP-Cron runs again, so the run continues: it checks the update and puts
 * the previous version back if needed. Admin screens, WP-CLI and form posts
 * still load everything (so the list of active plugins is never saved
 * without it), and so do the site checks themselves.
 *
 * An update type added by another plugin can name PHP files of its own,
 * inside the plugins folder, that are loaded here on every request while
 * the guard is on ('files' in the guard option): $wpbp_su_own_request tells
 * them whether this is one of Wrensafe's own requests, and $wpbp_su_guard
 * holds the guard option ('data' has what each type asked to keep).
 *
 * Wrensafe copies this file into wp-content/mu-plugins/ for the duration of
 * a run. It is safe to delete if you find it there after a crash.
 *
 * It only reads the request to decide what this request loads; it changes
 * nothing that is saved.
 *
 * @package WPBP\Updates
 */

defined( 'ABSPATH' ) || exit;

// The site checks load the site exactly as visitors see it, with the
// files just updated or rolled back read from disk: the request that
// changed them may run under another PHP (WP-CLI, a system cron) whose
// opcode cache is separate, so it couldn't clear this one. Only a real
// check (its one-time scrape key and nonce are stored) may do that.
// phpcs:disable WordPress.Security.NonceVerification.Recommended, WordPress.Security.NonceVerification.Missing -- read-only: the values only decide what this request loads, and are compared with secrets stored by Wrensafe or WordPress.
if ( isset( $_GET['wp_scrape_key'] ) ) {
	$wpbp_su_key    = substr( sanitize_key( wp_unslash( $_GET['wp_scrape_key'] ) ), 0, 32 );
	$wpbp_su_nonce  = isset( $_GET['wp_scrape_nonce'] ) ? sanitize_text_field( wp_unslash( $_GET['wp_scrape_nonce'] ) ) : '';
	$wpbp_su_stored = '' !== $wpbp_su_key ? get_transient( 'scrape_key_' . $wpbp_su_key ) : false;
	$wpbp_su_fresh  = get_option( 'wpbp_safe_update_fresh' );

	// PHP refuses opcache_invalidate() (with a warning) when opcache.restrict_api names another folder.
	$wpbp_su_restrict = function_exists( 'opcache_invalidate' ) ? (string) ini_get( 'opcache.restrict_api' ) : '';
	$wpbp_su_script   = isset( $_SERVER['SCRIPT_FILENAME'] ) ? realpath( sanitize_text_field( wp_unslash( $_SERVER['SCRIPT_FILENAME'] ) ) ) : false;
	$wpbp_su_can      = function_exists( 'opcache_invalidate' ) && ( '' === $wpbp_su_restrict || ( false !== $wpbp_su_script && 0 === stripos( $wpbp_su_script, $wpbp_su_restrict ) ) );

	if ( $wpbp_su_can && is_string( $wpbp_su_stored ) && '' !== $wpbp_su_nonce && hash_equals( $wpbp_su_stored, $wpbp_su_nonce )
		&& is_array( $wpbp_su_fresh ) && (int) ( $wpbp_su_fresh['expires'] ?? 0 ) >= time() ) {
		$wpbp_su_roots = array( wp_normalize_path( WP_CONTENT_DIR ) . '/', wp_normalize_path( WP_PLUGIN_DIR ) . '/' );

		foreach ( (array) ( $wpbp_su_fresh['paths'] ?? array() ) as $wpbp_su_path ) {
			$wpbp_su_path = wp_normalize_path( (string) $wpbp_su_path );

			// Paths saved by Wrensafe 1.2.0 before update types were relative to the plugins folder.
			if ( '' !== $wpbp_su_path && '/' !== $wpbp_su_path[0] && ! preg_match( '#^[A-Za-z]:/#', $wpbp_su_path ) ) {
				$wpbp_su_path = wp_normalize_path( WP_PLUGIN_DIR ) . '/' . ltrim( $wpbp_su_path, '/' );
			}

			if ( false !== strpos( $wpbp_su_path, '..' ) || ( 0 !== strpos( $wpbp_su_path, $wpbp_su_roots[0] ) && 0 !== strpos( $wpbp_su_path, $wpbp_su_roots[1] ) ) ) {
				continue;
			}

			if ( is_file( $wpbp_su_path ) ) {
				if ( 'php' === strtolower( pathinfo( $wpbp_su_path, PATHINFO_EXTENSION ) ) ) {
					opcache_invalidate( $wpbp_su_path, true );
				}
			} elseif ( is_dir( $wpbp_su_path ) ) {
				foreach ( new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $wpbp_su_path, FilesystemIterator::SKIP_DOTS ) ) as $wpbp_su_file ) {
					if ( 'php' === strtolower( $wpbp_su_file->getExtension() ) ) {
						opcache_invalidate( $wpbp_su_file->getPathname(), true );
					}
				}
			}
		}
	}

	return;
}

$wpbp_su_guard = get_option( 'wpbp_safe_update_guard' );

if ( ! is_array( $wpbp_su_guard ) || (int) ( $wpbp_su_guard['expires'] ?? 0 ) < time() || ( empty( $wpbp_su_guard['plugins'] ) && empty( $wpbp_su_guard['files'] ) ) ) {
	return;
}

// Is this one of Wrensafe's own requests?
$wpbp_su_secret = (string) ( $wpbp_su_guard['key'] ?? '' );
$wpbp_su_sent   = '';

if ( isset( $_POST['_wpbp_su_guard'] ) ) {
	$wpbp_su_sent = sanitize_key( wp_unslash( $_POST['_wpbp_su_guard'] ) );
} elseif ( isset( $_GET['_wpbp_su_guard'] ) ) {
	$wpbp_su_sent = sanitize_key( wp_unslash( $_GET['_wpbp_su_guard'] ) );
}

$wpbp_su_keyed       = strlen( $wpbp_su_secret ) >= 20 && '' !== $wpbp_su_sent && hash_equals( $wpbp_su_secret, $wpbp_su_sent );
$wpbp_su_own_request = false;

if ( defined( 'WP_CLI' ) && WP_CLI ) {
	// "wp cron event run …": run on the server by someone who can run WP-CLI
	// (checked first: WP-CLI's cron commands also count as WP-Cron).
	$wpbp_su_cli         = class_exists( 'WP_CLI' ) && is_callable( array( 'WP_CLI', 'get_runner' ) ) ? (array) WP_CLI::get_runner()->arguments : array();
	$wpbp_su_own_request = isset( $wpbp_su_cli[0] ) && 'cron' === $wpbp_su_cli[0];
} elseif ( wp_doing_ajax() ) {
	$wpbp_su_action      = isset( $_REQUEST['action'] ) ? sanitize_key( wp_unslash( $_REQUEST['action'] ) ) : '';
	$wpbp_su_own_request = $wpbp_su_keyed && 0 === strpos( $wpbp_su_action, 'wpbp_safe_update_' );
} elseif ( wp_doing_cron() ) {
	// spawn_cron() stores its lock in the doing_cron transient and passes the same value.
	$wpbp_su_cron        = isset( $_GET['doing_wp_cron'] ) ? sanitize_text_field( wp_unslash( $_GET['doing_wp_cron'] ) ) : '';
	$wpbp_su_lock        = get_transient( 'doing_cron' );
	$wpbp_su_own_request = '' !== $wpbp_su_cron && is_scalar( $wpbp_su_lock ) && '' !== (string) $wpbp_su_lock && hash_equals( (string) $wpbp_su_lock, $wpbp_su_cron );
} elseif ( is_admin() ) {
	$wpbp_su_page        = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';
	$wpbp_su_own_request = $wpbp_su_keyed && 'wrensafe-safe-updates' === $wpbp_su_page;
}
// phpcs:enable WordPress.Security.NonceVerification.Recommended, WordPress.Security.NonceVerification.Missing

// Guard code of other update types (only PHP files inside the plugins folder).
$wpbp_su_plugins_root = wp_normalize_path( (string) realpath( WP_PLUGIN_DIR ) ) . '/';

foreach ( (array) ( $wpbp_su_guard['files'] ?? array() ) as $wpbp_su_file ) {
	$wpbp_su_file = realpath( (string) $wpbp_su_file );

	if ( false !== $wpbp_su_file && '.php' === substr( $wpbp_su_file, -4 ) && 0 === strpos( wp_normalize_path( $wpbp_su_file ), $wpbp_su_plugins_root ) && is_readable( $wpbp_su_file ) ) {
		include_once $wpbp_su_file;
	}
}

unset( $wpbp_su_file, $wpbp_su_plugins_root );

// A stalled run (see the file header): six minutes without a heartbeat, above
// the longest step (PHP may run one for up to five minutes on a slow host).
// Only for WP-Cron and visitors' page views (GET/HEAD): admin screens, WP-CLI
// and form posts load everything, so nothing that saves the list of active
// plugins can save it without the plugin being updated.
// Only plugin updates not checked yet count ('stall'; empty while a run waits
// for a decision), never what other update types list.
$wpbp_su_method  = isset( $_SERVER['REQUEST_METHOD'] ) ? strtoupper( sanitize_key( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) ) : 'GET';
$wpbp_su_stall   = array_values( array_map( 'strval', (array) ( $wpbp_su_guard['stall'] ?? array() ) ) );
$wpbp_su_stalled = ! empty( $wpbp_su_stall ) && (int) ( $wpbp_su_guard['touched'] ?? 0 ) > 0 && time() - (int) $wpbp_su_guard['touched'] > 360
	&& ( wp_doing_cron() || ( ! is_admin() && ! ( defined( 'WP_CLI' ) && WP_CLI ) && in_array( $wpbp_su_method, array( 'GET', 'HEAD' ), true ) ) );

// Wrensafe's own requests skip everything the run named; a stalled run's
// other requests skip only its unchecked plugin updates.
$wpbp_su_hide = $wpbp_su_own_request ? array_values( array_map( 'strval', (array) ( $wpbp_su_guard['plugins'] ?? array() ) ) ) : ( $wpbp_su_stalled ? $wpbp_su_stall : array() );

if ( empty( $wpbp_su_hide ) ) {
	return;
}

add_filter(
	'option_active_plugins',
	static function ( $plugins ) use ( $wpbp_su_hide ) {
		return is_array( $plugins ) ? array_values( array_diff( $plugins, $wpbp_su_hide ) ) : $plugins;
	},
	PHP_INT_MAX
);
