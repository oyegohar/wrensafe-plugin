<?php
/**
 * Low-level CRUD access to the wp_wpbp_migration_keys table.
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

class MigrationKeysRepository {

	private static function table(): string {
		global $wpdb;

		return $wpdb->prefix . 'wpbp_migration_keys';
	}

	public static function create( array $data ): int {
		global $wpdb;

		$data['created_at'] = $data['created_at'] ?? current_time( 'mysql' );

		$wpdb->insert( self::table(), $data );

		return (int) $wpdb->insert_id;
	}

	public static function find_by_token_hash( string $token_hash ): ?array {
		global $wpdb;

		$row = $wpdb->get_row(
			$wpdb->prepare( 'SELECT * FROM %i WHERE token_hash = %s', self::table(), $token_hash ),
			ARRAY_A
		);

		return $row ? $row : null;
	}

	public static function mark_used( int $id ): void {
		global $wpdb;

		$wpdb->update( self::table(), array( 'used' => 1 ), array( 'id' => $id ) );
	}

	public static function delete_expired(): void {
		global $wpdb;

		// expires_at is written in UTC (MigrationKey::generate() uses gmdate()),
		// so it is compared with the current UTC time, not the site's time.
		$wpdb->query(
			$wpdb->prepare( 'DELETE FROM %i WHERE expires_at < %s', self::table(), current_time( 'mysql', true ) )
		);
	}
}
