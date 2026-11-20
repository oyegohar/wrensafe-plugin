<?php
/**
 * Plugin Name: Wrensafe staging protection
 * Description: Asks for the staging site's password before WordPress shows anything. Added by Wrensafe on the live site; acts only on the staging site that has its settings file.
 *
 * Wrensafe copies this file into a password-protected staging site's
 * must-use plugins folder (as wrensafe-staging-guard.php). It works on any
 * web server (Apache, LiteSpeed, nginx), unlike the .htaccess rules
 * earlier versions used.
 *
 * Before WordPress serves anything, a request must have one of:
 *
 *  - the access cookie this file sets after the staging password was
 *    entered in its form (signed, valid for a day, only for the staging
 *    site's path);
 *  - the staging user name and password as HTTP Basic credentials;
 *  - the signature this file adds to the staging site's own requests to
 *    itself (WP-Cron, Site Health, background jobs).
 *
 * All three are checked in full among the must-use plugins, so a request
 * without one never reaches the site's plugins. WP-CLI and WP-Cron are let
 * through. Everything else gets the password form (HTTP 401). Wrong
 * passwords, from the form or as Basic credentials, are counted per
 * address (an IPv6 /64 counts as one) and for the whole site; the form
 * pauses for 15 minutes when either limit is reached. Pages let through
 * are marked as not cacheable, so a page cache never shows them to someone
 * without the password. Files the web server sends directly (images in the
 * uploads folder, for example) are not covered.
 *
 * The user name and a hash of the password are kept in the staging site's
 * own folder (next to its wp-config.php), in wrensafe-staging-access.php,
 * which shows nothing when requested over the web. Without that file this
 * one does nothing, so it never applies to the live site: Push to Live
 * copies the content folder and the database, never the staging site's own
 * folder (and it leaves this file out). It changes nothing that is saved
 * apart from a short-lived count of wrong passwords.
 *
 * @package WPBP\Staging
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'WPBP_Staging_Guard', false ) ) {

	/**
	 * See the file header.
	 */
	final class WPBP_Staging_Guard {

		/** Name of the access cookie. */
		const COOKIE = 'wpbp_staging_access';

		/** Request header on the staging site's requests to itself. */
		const LOOPBACK_HEADER = 'X-Wrensafe-Staging';

		/** Login form fields. */
		const FIELD_USER     = 'wpbp_staging_user';
		const FIELD_PASSWORD = 'wpbp_staging_password';

		/** Seconds the access cookie is valid. */
		const LIFETIME = 86400;

		/** Seconds a loopback signature is valid. */
		const LOOPBACK_TTL = 600;

		/** Wrong passwords per address and quarter hour before the form pauses. */
		const MAX_FAILURES = 10;

		/** Wrong passwords from all addresses together per quarter hour before the form pauses. */
		const MAX_FAILURES_ALL = 100;

		/** @var array<string,mixed>|null */
		private static $config = null;

		/** @var bool */
		private static $passed = false;

		/** @var bool|null Result of the Basic credentials check for this request. */
		private static $basic = null;

		/** Called when WordPress loads the must-use plugins. */
		public static function boot(): void {
			$config = self::config();

			if ( null === $config ) {
				return;
			}

			self::$config = $config;

			// Also under WP-CLI: what it starts may request the site itself.
			add_filter( 'http_request_args', array( self::class, 'sign_loopback' ), 10, 2 );

			if ( self::exempt() ) {
				return;
			}

			if ( did_action( 'sanitize_comment_cookies' ) ) {
				// Loaded late (not as a must-use plugin): check right away.
				self::check();
				return;
			}

			// The full check once the plugins are loaded and the request is
			// read, before the theme and `init`.
			add_action( 'sanitize_comment_cookies', array( self::class, 'check' ), -9999, 0 );

			self::early();
		}

		/**
		 * Among the must-use plugins, before the site's plugins: the full
		 * check of the access cookie, Basic credentials and loopback
		 * signature (none of them needs the site's plugins), and the login
		 * form. Anything else gets the form now, so the site's plugins never
		 * run for it.
		 */
		public static function early(): void {
			if ( self::exempt() ) {
				self::$passed = true;
				return;
			}

			if ( self::valid_loopback() || self::valid_cookie() || self::valid_basic() ) {
				self::pass();
				return;
			}

			if ( isset( $_POST[ self::FIELD_PASSWORD ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing -- a login form for visitors who have no session yet; the password is the check.
				self::log_in();
			}

			self::deny( '' );
		}

		/**
		 * After the plugins are loaded and before the theme and `init`. A
		 * request early() let in passes; this file loaded late (not as a
		 * must-use plugin) gets the full check here, where a login to the
		 * staging site as an administrator also counts.
		 */
		public static function check(): void {
			if ( self::$passed || self::exempt() ) {
				self::$passed = true;
				return;
			}

			if ( self::valid_loopback() || self::valid_cookie() || self::valid_basic() || self::is_administrator() ) {
				self::pass();
				return;
			}

			if ( isset( $_POST[ self::FIELD_PASSWORD ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing -- a login form for visitors who have no session yet; the password is the check.
				self::log_in();
			}

			self::deny( '' );
		}

		/**
		 * Add the loopback signature to the staging site's own requests to
		 * itself (wp_remote_*() to its own address).
		 *
		 * @param mixed  $args Request arguments.
		 * @param string $url  Request address.
		 * @return mixed
		 */
		public static function sign_loopback( $args, $url ) {
			if ( ! is_array( $args ) || ! is_string( $url ) || null === self::$config || ! self::is_own_url( $url ) ) {
				return $args;
			}

			$headers = isset( $args['headers'] ) ? $args['headers'] : array();

			if ( ! is_array( $headers ) ) {
				return $args;
			}

			$time                             = (string) time();
			$headers[ self::LOOPBACK_HEADER ] = $time . '.' . self::sign( 'loopback|' . $time );
			$args['headers']                  = $headers;

			return $args;
		}

		// -- Checks ---------------------------------------------------------------

		/** WP-CLI and WP-Cron always pass. */
		private static function exempt(): bool {
			return ( defined( 'WP_CLI' ) && WP_CLI ) || ( defined( 'DOING_CRON' ) && DOING_CRON );
		}

		/**
		 * Let the request in, marked as not cacheable: the staging copy keeps
		 * the live site's page cache, and a cached page would be shown to
		 * visitors without the password.
		 */
		private static function pass(): void {
			self::$passed = true;

			if ( ! defined( 'DONOTCACHEPAGE' ) ) {
				define( 'DONOTCACHEPAGE', true ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound -- the constant page-cache plugins read.
			}

			if ( ! headers_sent() ) {
				header( 'Cache-Control: no-store, no-cache, must-revalidate, max-age=0, private' );
				header( 'X-LiteSpeed-Cache-Control: no-cache' );
				header( 'X-Robots-Tag: noindex, nofollow' );
			}

			add_filter( 'wp_headers', array( self::class, 'no_cache_headers' ), PHP_INT_MAX );
		}

		/**
		 * Keep the page uncacheable when WordPress sends its headers.
		 *
		 * @param mixed $headers Headers WordPress is about to send.
		 * @return mixed
		 */
		public static function no_cache_headers( $headers ) {
			if ( is_array( $headers ) ) {
				$headers['Cache-Control']             = 'no-store, no-cache, must-revalidate, max-age=0, private';
				$headers['X-LiteSpeed-Cache-Control'] = 'no-cache';
			}

			return $headers;
		}

		private static function valid_cookie(): bool {
			$value = isset( $_COOKIE[ self::COOKIE ] ) ? sanitize_text_field( wp_unslash( $_COOKIE[ self::COOKIE ] ) ) : '';
			$parts = explode( '.', $value, 2 );

			if ( 2 !== count( $parts ) || ! ctype_digit( $parts[0] ) || (int) $parts[0] < time() ) {
				return false;
			}

			return hash_equals( self::sign( 'cookie|' . self::$config['user'] . '|' . $parts[0] ), $parts[1] );
		}

		/** HTTP Basic credentials (curl -u), counted against the same limits as the form. */
		private static function valid_basic(): bool {
			if ( null !== self::$basic ) {
				return self::$basic;
			}

			if ( ! isset( $_SERVER['PHP_AUTH_USER'], $_SERVER['PHP_AUTH_PW'] ) ) {
				self::$basic = false;
				return false;
			}

			if ( self::throttled() ) {
				self::deny( (string) self::$config['labels']['many'], 429 );
			}

			// The exact values, from the Authorization header itself:
			// wp_magic_quotes() hasn't run yet among the must-use plugins, so
			// wp_unslash() on $_SERVER would drop real backslashes. Where the
			// header can't be read, $_SERVER is read the usual way.
			list( $user, $password ) = self::basic_from_header();

			if ( is_string( $user ) && is_string( $password ) ) {
				$user     = sanitize_user( $user );
				$password = sanitize_text_field( $password );
			} else {
				$user     = sanitize_user( wp_unslash( $_SERVER['PHP_AUTH_USER'] ) );
				$password = sanitize_text_field( wp_unslash( $_SERVER['PHP_AUTH_PW'] ) );
			}

			self::$basic = self::credentials_match( $user, $password );

			if ( ! self::$basic ) {
				self::record_failure();
			}

			return self::$basic;
		}

		private static function valid_loopback(): bool {
			$key   = self::server_key();
			$value = isset( $_SERVER[ $key ] ) ? sanitize_text_field( wp_unslash( $_SERVER[ $key ] ) ) : '';
			$parts = explode( '.', $value, 2 );

			if ( 2 !== count( $parts ) || ! ctype_digit( $parts[0] ) || abs( time() - (int) $parts[0] ) > self::LOOPBACK_TTL ) {
				return false;
			}

			return hash_equals( self::sign( 'loopback|' . $parts[0] ), $parts[1] );
		}

		/** Logged in to the staging site as someone who can manage it. */
		private static function is_administrator(): bool {
			if ( ! function_exists( 'wp_validate_auth_cookie' ) ) {
				return false;
			}

			$user_id = wp_validate_auth_cookie( '', 'logged_in' );

			return false !== $user_id && user_can( (int) $user_id, 'manage_options' );
		}

		/**
		 * The login form was sent: let in with a cookie, or show the form
		 * again. Runs among the must-use plugins, so it uses nothing from
		 * pluggable.php.
		 */
		private static function log_in(): void {
			if ( self::throttled() ) {
				self::deny( (string) self::$config['labels']['many'], 429 );
			}

			// The exact values sent: wp_magic_quotes() hasn't run yet among the
			// must-use plugins, so wp_unslash() would drop real backslashes.
			$user     = sanitize_user( (string) filter_input( INPUT_POST, self::FIELD_USER, FILTER_UNSAFE_RAW ) );
			$password = sanitize_text_field( (string) filter_input( INPUT_POST, self::FIELD_PASSWORD, FILTER_UNSAFE_RAW ) );

			if ( ! self::credentials_match( $user, $password ) ) {
				self::record_failure();
				self::deny( (string) self::$config['labels']['wrong'] );
			}

			delete_transient( self::throttle_key() );

			$expires = time() + self::LIFETIME;

			setcookie(
				self::COOKIE,
				$expires . '.' . self::sign( 'cookie|' . self::$config['user'] . '|' . $expires ),
				array(
					'expires'  => $expires,
					'path'     => self::cookie_path(),
					'secure'   => is_ssl(),
					'httponly' => true,
					'samesite' => 'Lax',
				)
			);

			// The same address again, now as a normal page view. Only a path
			// on this site (wp_safe_redirect() isn't loaded yet).
			$target = isset( $_SERVER['REQUEST_URI'] ) ? (string) esc_url_raw( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '';

			if ( '' === $target || '/' !== $target[0] || 0 === strpos( $target, '//' ) ) {
				$target = self::cookie_path();
			}

			nocache_headers();
			header( 'Location: ' . $target, true, 303 );
			exit;
		}

		/**
		 * The staging user name and password, the way Wrensafe hashed them.
		 * The password is always checked, so a wrong user name takes as long
		 * as a wrong password.
		 */
		private static function credentials_match( string $user, string $password ): bool {
			$hash          = (string) self::$config['hash'];
			$user_ok       = hash_equals( (string) self::$config['user'], $user );
			$password_ok   = '' !== $hash && password_verify( $password, $hash );
			$password_sent = '' !== $password;

			return $user_ok && $password_ok && $password_sent;
		}

		/** Whether wrong passwords from this address, or from all, reached the limit. */
		private static function throttled(): bool {
			return (int) get_transient( self::throttle_key() ) >= self::MAX_FAILURES
				|| (int) get_transient( 'wpbp_staging_guard_all' ) >= self::MAX_FAILURES_ALL;
		}

		/** Count a wrong password for this address and for all of them (15 minutes). */
		private static function record_failure(): void {
			set_transient( self::throttle_key(), (int) get_transient( self::throttle_key() ) + 1, 900 );
			set_transient( 'wpbp_staging_guard_all', (int) get_transient( 'wpbp_staging_guard_all' ) + 1, 900 );
		}

		/**
		 * User name and password from the request's own "Authorization: Basic"
		 * header, exactly as sent, or nulls when it can't be read.
		 *
		 * @return array{0:?string,1:?string}
		 */
		private static function basic_from_header(): array {
			if ( ! function_exists( 'getallheaders' ) || ! function_exists( 'sodium_base642bin' ) ) {
				return array( null, null );
			}

			foreach ( (array) getallheaders() as $name => $value ) {
				if ( 'authorization' !== strtolower( (string) $name ) || 0 !== stripos( (string) $value, 'basic ' ) ) {
					continue;
				}

				try {
					$pair = sodium_base642bin( trim( substr( (string) $value, 6 ) ), SODIUM_BASE64_VARIANT_ORIGINAL );
				} catch ( \Throwable $e ) {
					return array( null, null );
				}

				$parts = explode( ':', $pair, 2 );

				return 2 === count( $parts ) ? array( $parts[0], $parts[1] ) : array( null, null );
			}

			return array( null, null );
		}

		/** Transient name for this address; an IPv6 address counts by its /64 network. */
		private static function throttle_key(): string {
			$ip = self::remote_ip();

			// An IPv4 address seen through an IPv6 socket (::ffff:203.0.113.5) counts as itself.
			if ( 0 === stripos( $ip, '::ffff:' ) && false !== filter_var( substr( $ip, 7 ), FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 ) ) {
				$ip = substr( $ip, 7 );
			}

			if ( false !== filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6 ) && function_exists( 'inet_pton' ) ) {
				$packed = inet_pton( $ip );

				if ( false !== $packed ) {
					$ip = bin2hex( substr( $packed, 0, 8 ) ) . '/64';
				}
			}

			return 'wpbp_staging_guard_' . md5( $ip );
		}

		/**
		 * Answer with the password form and stop.
		 *
		 * @param string $error Message shown above the form ('' for none).
		 */
		private static function deny( string $error, int $status = 401 ): void {
			$labels = (array) self::$config['labels'];

			if ( ! defined( 'DONOTCACHEPAGE' ) ) {
				define( 'DONOTCACHEPAGE', true ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound -- the constant page-cache plugins read.
			}

			nocache_headers();
			header( 'X-LiteSpeed-Cache-Control: no-cache' );
			status_header( $status );
			header( 'Content-Type: text/html; charset=utf-8' );
			header( 'X-Robots-Tag: noindex, nofollow' );

			// PHP turns any response with this header into a 401, so not for the 429 pause.
			if ( 401 === $status ) {
				header( 'WWW-Authenticate: Form realm="' . str_replace( '"', '', (string) $labels['title'] ) . '"' );
			}

			?>
<!DOCTYPE html>
<html lang="<?php echo esc_attr( (string) ( self::$config['lang'] ?? 'en' ) ); ?>">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title><?php echo esc_html( (string) $labels['title'] ); ?></title>
<style>
body{margin:0;min-height:100vh;display:flex;align-items:center;justify-content:center;background:#f3f5f9;color:#1d2433;font:16px/1.5 -apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,sans-serif}
main{width:100%;max-width:360px;margin:16px;padding:28px;background:#fff;border-radius:12px;box-shadow:0 4px 24px rgba(16,24,40,.08)}
h1{margin:0 0 8px;font-size:20px}p{margin:0 0 16px;color:#4a5468}
label{display:block;margin:12px 0 4px;font-weight:600;font-size:14px}
input{box-sizing:border-box;width:100%;padding:10px 12px;border:1px solid #c5ccd8;border-radius:8px;font:inherit}
button{margin-top:18px;width:100%;padding:11px;border:0;border-radius:8px;background:#2f5bea;color:#fff;font:inherit;font-weight:600;cursor:pointer}
.error{padding:10px 12px;border-radius:8px;background:#fdecec;color:#a11b1b}
</style>
</head>
<body>
<main>
<h1><?php echo esc_html( (string) $labels['title'] ); ?></h1>
<p><?php echo esc_html( (string) $labels['intro'] ); ?></p>
			<?php if ( '' !== $error ) : ?>
<p class="error" role="alert"><?php echo esc_html( $error ); ?></p>
			<?php endif; ?>
			<?php if ( 429 !== $status ) : ?>
<form method="post">
<label for="wpbp-sg-user"><?php echo esc_html( (string) $labels['user'] ); ?></label>
<input id="wpbp-sg-user" name="<?php echo esc_attr( self::FIELD_USER ); ?>" type="text" autocomplete="username" required>
<label for="wpbp-sg-password"><?php echo esc_html( (string) $labels['password'] ); ?></label>
<input id="wpbp-sg-password" name="<?php echo esc_attr( self::FIELD_PASSWORD ); ?>" type="password" autocomplete="current-password" required>
<button type="submit"><?php echo esc_html( (string) $labels['submit'] ); ?></button>
</form>
			<?php endif; ?>
</main>
</body>
</html>
			<?php
			exit;
		}

		// -- Helpers --------------------------------------------------------------

		private static function normalize( string $path ): string {
			$real = realpath( $path );

			return rtrim( str_replace( '\\', '/', false !== $real ? $real : $path ), '/' );
		}

		/**
		 * The settings Wrensafe wrote into the staging site's folder, or null
		 * when there are none (not a protected staging site, or protection
		 * turned off). An unreadable file locks the site rather than opening it.
		 *
		 * @return array<string,mixed>|null
		 */
		private static function config(): ?array {
			$path = ABSPATH . 'wrensafe-staging-access.php';

			if ( ! is_file( $path ) ) {
				return null;
			}

			// The first line stops PHP if the file is requested over the web;
			// the settings are the JSON after it.
			$raw    = (string) file_get_contents( $path );
			$break  = strpos( $raw, "\n" );
			$config = false !== $break ? json_decode( substr( $raw, $break + 1 ), true ) : null;

			$defaults = array(
				'title'    => 'Staging site',
				'intro'    => 'Enter the staging site password to continue.',
				'user'     => 'User name',
				'password' => 'Password',
				'submit'   => 'Continue',
				'wrong'    => 'The user name or password is wrong.',
				'many'     => 'Too many wrong passwords. Try again in 15 minutes.',
			);

			if ( ! is_array( $config ) || empty( $config['hash'] ) || empty( $config['secret'] ) || ! isset( $config['user'] ) ) {
				// Unreadable: nobody can enter (a password can't be checked).
				return array(
					'user'   => '',
					'hash'   => '',
					'secret' => '',
					'home'   => '',
					'labels' => $defaults,
				);
			}

			// Written for the staging site in this folder only.
			if ( isset( $config['path'] ) && self::normalize( (string) $config['path'] ) !== self::normalize( ABSPATH ) ) {
				return null;
			}

			$config['labels'] = array_merge( $defaults, array_filter( (array) ( $config['labels'] ?? array() ), 'is_string' ) );

			return $config;
		}

		private static function sign( string $data ): string {
			$secret = (string) self::$config['secret'];

			// No secret (unreadable settings): a value nothing can match.
			return '' !== $secret ? hash_hmac( 'sha256', $data, $secret . '|' . (string) self::$config['hash'] ) : hash( 'sha256', uniqid( '', true ) );
		}

		/** $_SERVER key of the loopback header. */
		private static function server_key(): string {
			return 'HTTP_' . strtoupper( str_replace( '-', '_', self::LOOPBACK_HEADER ) );
		}

		/** The staging site's path, for the cookie. */
		private static function cookie_path(): string {
			$path = (string) wp_parse_url( (string) self::$config['home'], PHP_URL_PATH );

			return '' !== $path ? trailingslashit( $path ) : '/';
		}

		/** Whether $url is an address of this staging site. */
		private static function is_own_url( string $url ): bool {
			$home = wp_parse_url( (string) self::$config['home'] );
			$url  = wp_parse_url( $url );

			if ( ! is_array( $home ) || ! is_array( $url ) || empty( $home['host'] ) || empty( $url['host'] ) ) {
				return false;
			}

			$port = static function ( array $parts ): int {
				return isset( $parts['port'] ) ? (int) $parts['port'] : ( 'https' === strtolower( (string) ( $parts['scheme'] ?? '' ) ) ? 443 : 80 );
			};

			return strtolower( $home['host'] ) === strtolower( $url['host'] )
				&& $port( $home ) === $port( $url )
				&& 0 === strpos( trailingslashit( (string) ( $url['path'] ?? '/' ) ), trailingslashit( (string) ( $home['path'] ?? '/' ) ) );
		}

		private static function remote_ip(): string {
			$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';

			return false !== filter_var( $ip, FILTER_VALIDATE_IP ) ? $ip : '';
		}
	}

	WPBP_Staging_Guard::boot();
}
