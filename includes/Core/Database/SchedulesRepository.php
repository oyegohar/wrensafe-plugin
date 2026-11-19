<?php
/**
 * Low-level CRUD access to the wp_wpbp_schedules table.
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

class SchedulesRepository {

	private static function table(): string {
		global $wpdb;

		return $wpdb->prefix . 'wpbp_schedules';
	}

	public static function create( array $data ): int {
		global $wpdb;

		$data['created_at'] = $data['created_at'] ?? current_time( 'mysql' );

		$wpdb->insert( self::table(), $data );

		return (int) $wpdb->insert_id;
	}

	public static function find( int $id ): ?array {
		global $wpdb;

		$row = $wpdb->get_row(
			$wpdb->prepare( 'SELECT * FROM %i WHERE id = %d', self::table(), $id ),
			ARRAY_A
		);

		return $row ? $row : null;
	}

	public static function update( int $id, array $data ): void {
		global $wpdb;

		$wpdb->update( self::table(), $data, array( 'id' => $id ) );
	}

	public static function delete( int $id ): void {
		global $wpdb;

		$wpdb->delete( self::table(), array( 'id' => $id ) );
	}

	public static function list_all(): array {
		global $wpdb;

		return $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i ORDER BY id DESC', self::table() ), ARRAY_A );
	}

	public static function count_enabled(): int {
		global $wpdb;

		return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE enabled = %d', self::table(), 1 ) );
	}

	/**
	 * The soonest upcoming run time among enabled schedules, or null if none.
	 */
	public static function get_next_run_at(): ?string {
		global $wpdb;

		$value = $wpdb->get_var( $wpdb->prepare( 'SELECT MIN(next_run_at) FROM %i WHERE enabled = %d', self::table(), 1 ) );

		return $value ? $value : null;
	}

	/**
	 * Schedules that are enabled and due to run (next_run_at in the past, or never run).
	 */
	public static function get_due(): array {
		global $wpdb;

		return $wpdb->get_results(
			$wpdb->prepare(
				'SELECT * FROM %i WHERE enabled = %d AND (next_run_at IS NULL OR next_run_at <= %s) ORDER BY id ASC LIMIT 5',
				self::table(),
				1,
				current_time( 'mysql' )
			),
			ARRAY_A
		);
	}
}
