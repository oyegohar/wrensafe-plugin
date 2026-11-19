<?php
/**
 * Time formatting helpers.
 *
 * @package WPBP\Utils
 */

namespace WPBP\Utils;

defined( 'ABSPATH' ) || exit;

class Time {

	/**
	 * Convert a duration in seconds to a human-readable string.
	 * e.g. 3723 → "1h 2m 3s"
	 */
	public static function format_duration( int $seconds ): string {
		if ( $seconds < 60 ) {
			return sprintf( '%ds', $seconds );
		}

		$hours   = (int) floor( $seconds / 3600 );
		$minutes = (int) floor( ( $seconds % 3600 ) / 60 );
		$secs    = $seconds % 60;

		if ( $hours > 0 ) {
			return sprintf( '%dh %dm %ds', $hours, $minutes, $secs );
		}

		return sprintf( '%dm %ds', $minutes, $secs );
	}

	/**
	 * Human-readable "time since" a UNIX timestamp.
	 * e.g. 120 seconds ago → "2 minutes ago"
	 */
	public static function human_diff( int $timestamp ): string {
		$diff = max( 0, time() - $timestamp );

		if ( $diff < 60 ) {
			return __( 'Just now', 'wrensafe' );
		}

		return sprintf(
			/* translators: %s: human time diff */
			__( '%s ago', 'wrensafe' ),
			human_time_diff( $timestamp, time() )
		);
	}

	/**
	 * Convert a stored datetime ("Y-m-d H:i:s", as written by
	 * current_time( 'mysql' )) to a UNIX timestamp.
	 * Returns 0 if the string is empty or unparseable.
	 */
	public static function to_timestamp( string $mysql_datetime ): int {
		if ( '' === $mysql_datetime || '0000-00-00 00:00:00' === $mysql_datetime ) {
			return 0;
		}

		$ts = strtotime( $mysql_datetime );
		return false === $ts ? 0 : $ts;
	}

	/**
	 * Format a MySQL datetime for display using the site's date/time format.
	 */
	public static function format_mysql( string $mysql_datetime ): string {
		$ts = self::to_timestamp( $mysql_datetime );

		if ( 0 === $ts ) {
			return '—';
		}

		return wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $ts );
	}

	/**
	 * Convert a stored MySQL datetime (site-local, as written by
	 * current_time('mysql')) to a canonical UTC ISO-8601 string, e.g.
	 * "2026-06-30T14:34:00Z". Returns '' when the datetime is empty/unparseable.
	 *
	 * This is the canonical value the browser uses to render the time in the
	 * viewer's own timezone, so a client in another timezone sees the correct
	 * local time rather than the server's.
	 */
	public static function utc_iso_from_mysql( string $mysql_datetime ): string {
		if ( '' === $mysql_datetime || '0000-00-00 00:00:00' === $mysql_datetime ) {
			return '';
		}

		// get_gmt_from_date() interprets the input in the site timezone and
		// returns the equivalent UTC time, so the ISO string is unambiguous.
		$utc = get_gmt_from_date( $mysql_datetime, 'Y-m-d\TH:i:s\Z' );

		return is_string( $utc ) ? $utc : '';
	}

	/**
	 * A <span class="wpbp-local-time"> element whose data-utc attribute is the
	 * canonical UTC time. admin.js rewrites the text to the viewer's local
	 * timezone (Intl.DateTimeFormat); the server-rendered fallback text (site
	 * timezone) is shown if JavaScript is unavailable. Returns HTML built
	 * from escaped parts; print it with
	 * wp_kses( Time::local_time_markup( … ), \WPBP\Admin\Kses::allowed() ).
	 */
	public static function local_time_markup( string $mysql_datetime, bool $with_time = true ): string {
		$utc = self::utc_iso_from_mysql( $mysql_datetime );

		$format   = get_option( 'date_format' ) . ( $with_time ? ' ' . get_option( 'time_format' ) : '' );
		$fallback = '' !== $utc ? mysql2date( $format, $mysql_datetime ) : '—';

		if ( '' === $utc ) {
			return esc_html( $fallback );
		}

		return sprintf(
			'<span class="wpbp-local-time" data-utc="%1$s" data-with-time="%2$s" title="%3$s">%4$s</span>',
			esc_attr( $utc ),
			$with_time ? '1' : '0',
			/* translators: %s: backup time in UTC */
			esc_attr( sprintf( __( 'Stored (UTC): %s', 'wrensafe' ), $utc ) ),
			esc_html( $fallback )
		);
	}

	/**
	 * Next scheduled cron recurrence time from now.
	 */
	public static function next_cron_time( string $recurrence ): ?int {
		$schedules = wp_get_schedules();

		if ( ! isset( $schedules[ $recurrence ] ) ) {
			return null;
		}

		return time() + (int) $schedules[ $recurrence ]['interval'];
	}
}
