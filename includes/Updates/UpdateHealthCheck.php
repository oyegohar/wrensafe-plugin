<?php
/**
 * Does the site still work after an update?
 *
 * Loads the site through loopback requests, the way WordPress checks a
 * plugin edited in the plugin editor: each request carries a one-time
 * "scrape" key, so WordPress reports any fatal error raised while plugins
 * and the theme load (wp_start_scraping_edited_file_errors()). Three pages
 * are loaded:
 *
 *  - home:      the home page, as a visitor sees it;
 *  - admin:     admin-ajax.php with Wrensafe's ping, which runs every
 *               plugin's admin code (admin_init) without a login;
 *  - dashboard: the Plugins screen as the current administrator, when a
 *               browser drives the run (its login cookies are passed on).
 *
 * The same checks run before the first update (the baseline) and after
 * each one, and compare() reports only what got worse, so a site that
 * already had a problem, or that can't load its own pages, never causes a
 * rollback on its own.
 *
 * @package WPBP\Updates
 */

namespace WPBP\Updates;

defined( 'ABSPATH' ) || exit;

class UpdateHealthCheck {

	/** Answer of the admin-ajax ping (see SafeUpdates::ping()). */
	const PING_ANSWER = 'wrensafe-update-ping-ok';

	/** Seconds a page may take. */
	const TIMEOUT = 30;

	/** The longest a page may take on a second try. */
	const PATIENT_TIMEOUT = 90;

	/** Whether a page in these results didn't answer in time (as opposed to failing). */
	public static function timed_out( array $results ): bool {
		foreach ( $results as $result ) {
			if ( self::page_timed_out( (array) $result ) ) {
				return true;
			}
		}

		return false;
	}

	/** A page that didn't answer in time: the request timed out, or a proxy in front gave up (504, Cloudflare's 524). */
	public static function page_timed_out( array $result ): bool {
		$error = (string) ( $result['error'] ?? '' );

		if ( in_array( (int) ( $result['code'] ?? 0 ), array( 504, 524 ), true ) ) {
			return true;
		}

		return '' !== $error && ( false !== stripos( $error, 'timed out' ) || false !== stripos( $error, 'cURL error 28' ) );
	}

	/**
	 * A second, longer try for the pages that didn't answer in time. Only those are loaded again, and
	 * it stops at the first that still doesn't answer, so that one request stays short enough for a
	 * host or proxy not to cut it off.
	 *
	 * @param array<string,array<string,mixed>> $results  The check that timed out.
	 * @param array<string,array<string,mixed>> $baseline The checks before any update.
	 * @return array<string,array<string,mixed>>
	 */
	public static function retry_timed_out( array $results, bool $as_user, array $baseline ): array {
		$pages   = self::pages();
		$cookies = $as_user ? self::login_cookies() : array();
		$timeout = self::patient_timeout( $baseline );

		if ( ! empty( $cookies ) ) {
			$pages['dashboard'] = admin_url( 'plugins.php' );
		}

		foreach ( $results as $key => $result ) {
			if ( ! isset( $pages[ $key ] ) || ! self::page_timed_out( (array) $result ) ) {
				continue;
			}

			$results[ $key ] = self::load( $pages[ $key ], 'dashboard' === $key ? $cookies : array(), $timeout );

			if ( self::page_timed_out( $results[ $key ] ) ) {
				break;
			}
		}

		return $results;
	}

	/** @return array<string,string> The pages every check loads. */
	private static function pages(): array {
		return array(
			'home'  => add_query_arg( 'wpbp_check', wp_generate_password( 8, false ), home_url( '/' ) ),
			'admin' => add_query_arg( 'action', 'wpbp_update_ping', admin_url( 'admin-ajax.php' ) ),
		);
	}

	/**
	 * How long to wait on a second try after a page didn't answer in time: three times the slowest page
	 * of the run's first check, at least a minute, at most 90 seconds.
	 *
	 * @param array<string,array<string,mixed>> $baseline The checks before any update.
	 */
	public static function patient_timeout( array $baseline ): int {
		$slowest = 0.0;

		foreach ( $baseline as $result ) {
			$slowest = max( $slowest, (float) ( $result['seconds'] ?? 0 ) );
		}

		return (int) min( self::PATIENT_TIMEOUT, max( 60, (int) ceil( 3 * $slowest ) ) );
	}

	/**
	 * Run the checks.
	 *
	 * @param bool $as_user Also load the Plugins screen as the current user.
	 * @return array<string,array<string,mixed>> Check key => result.
	 */
	public static function run( bool $as_user ): array {
		$pages   = self::pages();
		$cookies = $as_user ? self::login_cookies() : array();

		if ( ! empty( $cookies ) ) {
			$pages['dashboard'] = admin_url( 'plugins.php' );
		}

		$results = array();

		foreach ( $pages as $key => $url ) {
			$results[ $key ] = self::load( $url, 'dashboard' === $key ? $cookies : array() );
		}

		return $results;
	}

	/**
	 * Whether at least one page could be loaded at all (the server allows
	 * loopback requests).
	 */
	public static function any_reachable( array $results ): bool {
		foreach ( $results as $result ) {
			if ( '' === (string) ( $result['error'] ?? '' ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * What got worse between two runs of the checks.
	 *
	 * @return array<string,string>|null Null when the site works as well as before; otherwise
	 *                                   reason (fatal | server_error | unreachable | blank),
	 *                                   check, message, and for a fatal error the file.
	 */
	public static function compare( array $before, array $after ): ?array {
		foreach ( $after as $key => $now ) {
			if ( ! isset( $before[ $key ] ) ) {
				continue;
			}

			$was = $before[ $key ];

			if ( ! empty( $now['fatal'] ) ) {
				$same = ! empty( $was['fatal'] ) && $was['fatal']['file'] === $now['fatal']['file'] && $was['fatal']['message'] === $now['fatal']['message'];

				if ( ! $same ) {
					return array(
						'reason'  => 'fatal',
						'check'   => $key,
						'file'    => (string) $now['fatal']['file'],
						'message' => self::fatal_message( $key, $now['fatal'] ),
					);
				}

				continue;
			}

			// The page couldn't be loaded before either (loopback blocked,
			// basic authentication, a firewall): nothing to compare.
			if ( '' !== (string) $was['error'] ) {
				continue;
			}

			if ( '' !== (string) $now['error'] ) {
				return array(
					'reason'  => 'unreachable',
					'check'   => $key,
					'message' => sprintf(
						/* translators: 1: page name, e.g. "the home page", 2: error message */
						__( '%1$s didn’t load after the update (%2$s).', 'wrensafe' ),
						self::page_name( $key ),
						$now['error']
					),
				);
			}

			if ( (int) $now['code'] >= 500 && (int) $was['code'] < 500 ) {
				return array(
					'reason'  => 'server_error',
					'check'   => $key,
					'message' => sprintf(
						/* translators: 1: page name, e.g. "the home page", 2: HTTP status code */
						__( '%1$s answered with server error %2$d after the update.', 'wrensafe' ),
						self::page_name( $key ),
						(int) $now['code']
					),
				);
			}

			// A white screen: the page used to have content and now has (almost) none.
			if ( 'home' === $key && (int) $was['length'] > 500 && (int) $now['length'] < 50 && (int) $now['code'] < 300 ) {
				return array(
					'reason'  => 'blank',
					'check'   => $key,
					'message' => __( 'The home page came back empty after the update.', 'wrensafe' ),
				);
			}

			if ( 'admin' === $key && ! empty( $was['ping'] ) && empty( $now['ping'] ) && (int) $now['code'] >= 400 && (int) $was['code'] < 400 ) {
				return array(
					'reason'  => 'server_error',
					'check'   => $key,
					'message' => sprintf(
						/* translators: %d: HTTP status code */
						__( 'The admin area stopped answering after the update (error %d).', 'wrensafe' ),
						(int) $now['code']
					),
				);
			}
		}

		return null;
	}

	/**
	 * Load one page with a scrape key and report what happened.
	 *
	 * @param array<string,string> $cookies Login cookies to send, or none.
	 * @return array{code:int,error:string,fatal:array|null,scraped:bool,length:int,ping:bool,seconds:float}
	 */
	private static function load( string $url, array $cookies, int $timeout = self::TIMEOUT ): array {
		$key   = md5( wp_generate_password( 32, false ) );
		$nonce = wp_generate_password( 32, false );

		set_transient( 'scrape_key_' . $key, $nonce, 2 * MINUTE_IN_SECONDS );

		// A check can take a while (up to 30 seconds per page, longer on a second try): the run is alive.
		SafeUpdates::touch_guard();

		$started  = microtime( true );
		$response = wp_remote_get(
			add_query_arg(
				array(
					'wp_scrape_key'   => $key,
					'wp_scrape_nonce' => $nonce,
				),
				$url
			),
			array(
				'timeout'   => max( 5, $timeout ),
				'cookies'   => $cookies,
				'headers'   => array( 'Cache-Control' => 'no-cache' ),
				/** This filter is documented in wp-includes/class-wp-http-streams.php */
				'sslverify' => apply_filters( 'https_local_ssl_verify', false ), // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WordPress core filter.
			)
		);

		delete_transient( 'scrape_key_' . $key );

		$result = array(
			'code'    => 0,
			'error'   => '',
			'fatal'   => null,
			'scraped' => false,
			'length'  => 0,
			'ping'    => false,
			'seconds' => round( microtime( true ) - $started, 2 ),
		);

		if ( is_wp_error( $response ) ) {
			$result['error'] = $response->get_error_message();
			return $result;
		}

		$body           = (string) wp_remote_retrieve_body( $response );
		$result['code'] = (int) wp_remote_retrieve_response_code( $response );
		$result['ping'] = false !== strpos( $body, self::PING_ANSWER );

		$start = "###### wp_scraping_result_start:$key ######";
		$end   = "###### wp_scraping_result_end:$key ######";
		$from  = strpos( $body, $start );
		$to    = false === $from ? false : strpos( $body, $end, $from );

		if ( false === $from || false === $to ) {
			// Redirected elsewhere or served from a cache: only the status and size count.
			$result['length'] = strlen( trim( $body ) );
			return $result;
		}

		$result['scraped'] = true;
		$result['length']  = strlen( trim( substr( $body, 0, $from ) . substr( $body, $to + strlen( $end ) ) ) );
		$report            = json_decode( trim( substr( $body, $from + strlen( $start ), $to - $from - strlen( $start ) ) ), true );

		if ( is_array( $report ) && ! empty( $report['file'] ) ) {
			$result['fatal'] = array(
				'file'    => wp_normalize_path( (string) $report['file'] ),
				'line'    => (int) ( $report['line'] ?? 0 ),
				'message' => (string) ( $report['message'] ?? '' ),
			);
		}

		return $result;
	}

	/**
	 * The current administrator's login cookies (only WordPress's own: a PHP
	 * session cookie would make the check wait for this request's session lock).
	 *
	 * @return array<string,string>
	 */
	private static function login_cookies(): array {
		$cookies = array();

		foreach ( array( AUTH_COOKIE, SECURE_AUTH_COOKIE, LOGGED_IN_COOKIE ) as $name ) {
			if ( isset( $_COOKIE[ $name ] ) && is_string( $_COOKIE[ $name ] ) ) {
				$cookies[ $name ] = sanitize_text_field( wp_unslash( $_COOKIE[ $name ] ) );
			}
		}

		return isset( $cookies[ LOGGED_IN_COOKIE ] ) ? $cookies : array();
	}

	private static function fatal_message( string $key, array $fatal ): string {
		// The first line: PHP's message without the stack trace.
		$message = wp_strip_all_tags( (string) $fatal['message'] );
		$message = trim( (string) preg_replace( '/\s*Stack trace:.*$/s', '', $message ) );
		$message = preg_replace( '/ in \S+:\d+$/', '', $message );
		$message = strlen( $message ) > 240 ? substr( $message, 0, 240 ) . '…' : $message;

		return sprintf(
			/* translators: 1: page name, e.g. "the home page", 2: file, 3: line number, 4: PHP error message */
			__( '%1$s showed a fatal error after the update, in %2$s on line %3$d: %4$s', 'wrensafe' ),
			self::page_name( $key ),
			(string) $fatal['file'],
			(int) $fatal['line'],
			$message
		);
	}

	private static function page_name( string $key ): string {
		$names = array(
			'home'      => __( 'The home page', 'wrensafe' ),
			'admin'     => __( 'The admin area', 'wrensafe' ),
			'dashboard' => __( 'The Plugins screen', 'wrensafe' ),
		);

		return $names[ $key ] ?? $key;
	}
}
