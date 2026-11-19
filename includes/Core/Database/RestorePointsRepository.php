<?php
/**
 * Low-level CRUD access to the wp_wpbp_restore_points table.
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

class RestorePointsRepository {

	private static function table(): string {
		global $wpdb;

		return $wpdb->prefix . 'wpbp_restore_points';
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

	public static function delete( int $id ): void {
		global $wpdb;

		$wpdb->delete( self::table(), array( 'id' => $id ) );
	}

	public static function list_all( int $limit = 50 ): array {
		global $wpdb;

		return $wpdb->get_results(
			$wpdb->prepare( 'SELECT * FROM %i ORDER BY id DESC LIMIT %d', self::table(), $limit ),
			ARRAY_A
		);
	}
}
