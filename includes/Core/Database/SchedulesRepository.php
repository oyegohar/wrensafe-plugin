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
// object-cached. Values go through $wpdb->prepare(); table names come from
// $wpdb->prefix with fixed suffixes and are escaped or quoted as identifiers.
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
			$wpdb->prepare( 'SELECT * FROM ' . esc_sql( self::table() ) . ' WHERE id = %d', $id ), // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- table name is $wpdb->prefix plus a fixed internal suffix; values use placeholders.
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

		return $wpdb->get_results( 'SELECT * FROM ' . esc_sql( self::table() ) . ' ORDER BY id DESC', ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- table name is $wpdb->prefix plus a fixed internal suffix; values use placeholders.
	}

	public static function count_enabled(): int {
		global $wpdb;

		return (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . esc_sql( self::table() ) . ' WHERE enabled = 1' ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- table name is $wpdb->prefix plus a fixed internal suffix; values use placeholders.
	}

	/**
	 * The soonest upcoming run time among enabled schedules, or null if none.
	 */
	public static function get_next_run_at(): ?string {
		global $wpdb;

		$value = $wpdb->get_var( 'SELECT MIN(next_run_at) FROM ' . esc_sql( self::table() ) . ' WHERE enabled = 1' ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- table name is $wpdb->prefix plus a fixed internal suffix; values use placeholders.

		return $value ? $value : null;
	}

	/**
	 * Schedules that are enabled and due to run (next_run_at in the past, or never run).
	 */
	public static function get_due(): array {
		global $wpdb;

		return $wpdb->get_results(
			$wpdb->prepare(
				'SELECT * FROM ' . esc_sql( self::table() ) . ' WHERE enabled = 1 AND (next_run_at IS NULL OR next_run_at <= %s) ORDER BY id ASC LIMIT 5', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- table name is $wpdb->prefix plus a fixed internal suffix; values use placeholders.
				current_time( 'mysql' )
			),
			ARRAY_A
		);
	}
}
