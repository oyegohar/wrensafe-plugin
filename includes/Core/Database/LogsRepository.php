<?php
/**
 * Low-level CRUD access to the wp_wpbp_logs table.
 *
 * @package WPBP\Core\Database
 */

namespace WPBP\Core\Database;

defined( 'ABSPATH' ) || exit;

// Database access in this file is direct by design: Wrensafe's own tables (or,
// in the backup/restore engine, the site's tables being exported, imported or
// swapped) have no WordPress API, and the data must be read fresh, so it is not
// object-cached. Every query goes through $wpdb->prepare(), with the table
// name as an identifier placeholder (%i).
// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

class LogsRepository {

	private const MAX_ROWS = 5000;

	private static function table(): string {
		global $wpdb;

		return $wpdb->prefix . 'wpbp_logs';
	}

	public static function insert( string $level, string $message, array $context = array(), ?int $job_id = null ): void {
		global $wpdb;

		$wpdb->insert(
			self::table(),
			array(
				'level'      => $level,
				'message'    => $message,
				'context'    => $context ? wp_json_encode( $context ) : null,
				'job_id'     => $job_id,
				'created_at' => current_time( 'mysql' ),
			),
			array( '%s', '%s', '%s', '%d', '%s' )
		);

		self::trim_old();
	}

	/**
	 * Return the most recent log entries, newest first.
	 */
	public static function get_recent( int $limit = 100 ): array {
		global $wpdb;

		return $wpdb->get_results(
			$wpdb->prepare(
				'SELECT * FROM %i ORDER BY id DESC LIMIT %d',
				self::table(),
				$limit
			),
			ARRAY_A
		);
	}

	/**
	 * The most recent entries of the given levels, newest first (the
	 * Dashboard shows outcomes, not every step).
	 *
	 * @param string[] $levels
	 */
	public static function get_recent_levels( int $limit, array $levels ): array {
		global $wpdb;

		$levels = array_values( array_filter( array_map( 'sanitize_key', $levels ) ) );

		if ( ! $levels ) {
			return array();
		}

		return (array) $wpdb->get_results(
			$wpdb->prepare(
				'SELECT * FROM %i WHERE level IN (' . implode( ', ', array_fill( 0, count( $levels ), '%s' ) ) . ') ORDER BY id DESC LIMIT %d',
				array_merge( array( self::table() ), $levels, array( $limit ) )
			),
			ARRAY_A
		);
	}

	/**
	 * Recent entries, optionally filtered by level and/or a message search
	 * term (Activity Logs filters, Issue 09).
	 */
	public static function get_filtered( int $limit = 100, string $level = '', string $search = '', int $offset = 0 ): array {
		global $wpdb;

		$like = '' !== $search ? '%' . $wpdb->esc_like( $search ) . '%' : '';

		// One fixed, fully prepared query: an empty filter matches every row.
		return (array) $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM %i WHERE ( %s = '' OR level = %s ) AND ( %s = '' OR message LIKE %s ) ORDER BY id DESC LIMIT %d OFFSET %d",
				self::table(),
				$level,
				$level,
				$like,
				$like,
				$limit,
				max( 0, $offset )
			),
			ARRAY_A
		);
	}

	/**
	 * How many entries match the Activity Logs filters (for pagination).
	 */
	public static function count_filtered( string $level = '', string $search = '' ): int {
		global $wpdb;

		$like = '' !== $search ? '%' . $wpdb->esc_like( $search ) . '%' : '';

		return (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM %i WHERE ( %s = '' OR level = %s ) AND ( %s = '' OR message LIKE %s )",
				self::table(),
				$level,
				$level,
				$like,
				$like
			)
		);
	}

	/**
	 * Delete every log entry (Activity Logs -> Clear Logs).
	 */
	public static function clear(): void {
		global $wpdb;

		$wpdb->query( $wpdb->prepare( 'TRUNCATE TABLE %i', self::table() ) );
	}

	/**
	 * Keep the table from growing without bound.
	 */
	private static function trim_old(): void {
		global $wpdb;

		$count = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i', self::table() ) );

		if ( $count <= self::MAX_ROWS ) {
			return;
		}

		$wpdb->query(
			$wpdb->prepare(
				'DELETE FROM %i ORDER BY id ASC LIMIT %d',
				self::table(),
				$count - self::MAX_ROWS
			)
		);
	}
}
