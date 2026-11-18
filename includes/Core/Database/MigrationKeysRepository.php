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
// object-cached. Values go through $wpdb->prepare(); table names come from
// $wpdb->prefix with fixed suffixes and are escaped or quoted as identifiers.
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
			$wpdb->prepare( 'SELECT * FROM ' . esc_sql( self::table() ) . ' WHERE token_hash = %s', $token_hash ), // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- table name is $wpdb->prefix plus a fixed internal suffix; values use placeholders.
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

		$wpdb->query(
			$wpdb->prepare( 'DELETE FROM ' . esc_sql( self::table() ) . ' WHERE expires_at < %s', current_time( 'mysql' ) ) // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- table name is $wpdb->prefix plus a fixed internal suffix; values use placeholders.
		);
	}
}
