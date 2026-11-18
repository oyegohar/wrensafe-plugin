<?php
/**
 * Exports the site's own database tables to a portable SQL dump in chunks.
 *
 * @package WPBP\Backup
 */

namespace WPBP\Backup;

use WPBP\Core\Settings;
use WPBP\Utils\ChunkSizeCalculator;
use WPBP\Utils\Paths;

defined( 'ABSPATH' ) || exit;

// Database access in this file is direct by design: Wrensafe's own tables (or,
// in the backup/restore engine, the site's tables being exported, imported or
// swapped) have no WordPress API, and the data must be read fresh, so it is not
// object-cached. Values go through $wpdb->prepare(); table names come from
// $wpdb->prefix with fixed suffixes and are escaped or quoted as identifiers.
// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

class DatabaseExporter {

	/**
	 * Rows exported per chunk — scaled down from the 500-row default by
	 * ChunkSizeCalculator on memory/time-constrained hosting.
	 */
	public static function rows_per_chunk(): int {
		return ChunkSizeCalculator::rows_per_chunk( 500 );
	}

	/**
	 * List every table belonging to this site (matches $wpdb->prefix).
	 *
	 * Wrensafe's own internal tables (jobs, logs, schedules, cloud
	 * accounts, etc.) are excluded unless the Advanced setting opts in,
	 * since they describe this installation rather than site content and
	 * are never restored anyway (see DatabaseImporter/RestoreManager).
	 */
	public function get_tables(): array {
		global $wpdb;

		// Base tables only: a VIEW has no rows of its own and its CREATE
		// statement (with DEFINER) cannot be replayed into a staging table.
		$rows = $wpdb->get_results(
			$wpdb->prepare( 'SHOW FULL TABLES WHERE `Table_type` = %s', 'BASE TABLE' ),
			ARRAY_N
		);

		$tables = array();

		foreach ( (array) $rows as $row ) {
			$table = (string) ( $row[0] ?? '' );

			if ( '' !== $table && 0 === strpos( $table, $wpdb->prefix ) && ! self::is_restore_work_table( $table ) ) {
				$tables[] = $table;
			}
		}

		if ( ! Settings::get( 'include_wpbp_internal_tables', false ) ) {
			$internal_prefix = $wpdb->prefix . 'wpbp_';
			$tables          = array_values(
				array_filter(
					$tables,
					static function ( $table ) use ( $internal_prefix ) {
						return 0 !== strpos( $table, $internal_prefix );
					}
				)
			);
		}

		return $tables;
	}

	/**
	 * Staging/old tables a restore creates temporarily (see RestoreManager)
	 * must never be exported as site content.
	 */
	private static function is_restore_work_table( string $table ): bool {
		foreach ( array( '_wpbptmp', '_wpbpold' ) as $suffix ) {
			if ( substr( $table, -strlen( $suffix ) ) === $suffix ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Backtick-quote a table/column identifier for a SQL dump.
	 */
	private static function quote_identifier( string $identifier ): string {
		return '`' . str_replace( '`', '``', $identifier ) . '`';
	}

	/**
	 * Total row count for a table.
	 */
	public function get_row_count( string $table ): int {
		global $wpdb;

		return (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . self::quote_identifier( $table ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- identifier quoted above.
	}

	/**
	 * Append a table's CREATE TABLE statement to an open file handle.
	 *
	 * @param string   $table  Table name.
	 * @param resource $handle Open, writable file handle.
	 */
	public function export_create_statement( string $table, $handle ): void {
		global $wpdb;

		$row = $wpdb->get_row( 'SHOW CREATE TABLE ' . self::quote_identifier( $table ), ARRAY_N ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.DirectDatabaseQuery.SchemaChange -- identifier quoted above.

		if ( ! $row || empty( $row[1] ) ) {
			return;
		}

		fwrite( $handle, "\nDROP TABLE IF EXISTS " . self::quote_identifier( $table ) . ";\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite
		fwrite( $handle, $row[1] . ";\n\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite
	}

	/**
	 * Primary-key column list for a table (empty when it has none), used to
	 * give chunked LIMIT/OFFSET reads a stable order so no row is skipped or
	 * duplicated between chunks.
	 *
	 * @return string[]
	 */
	private function primary_key_columns( string $table ): array {
		global $wpdb;

		$keys    = $wpdb->get_results( 'SHOW KEYS FROM ' . self::quote_identifier( $table ) . " WHERE Key_name = 'PRIMARY'", ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- identifier quoted above.
		$columns = array();

		foreach ( (array) $keys as $key ) {
			$columns[ (int) $key['Seq_in_index'] ] = (string) $key['Column_name'];
		}

		ksort( $columns );

		return array_values( $columns );
	}

	/**
	 * Export one chunk of rows (starting at $offset) for a table, appended to
	 * an open file handle. Returns the number of rows written, so the caller
	 * can detect completion (fewer rows than rows_per_chunk() means done).
	 *
	 * Values are escaped individually instead of through $wpdb->prepare():
	 * prepare() turns NULL into '' and replaces every "%" with a random
	 * per-request placeholder token that only $wpdb->query() in the SAME
	 * request can turn back into "%". Written to a dump file, that token
	 * survives into the restore and corrupts every "%" in the database
	 * (permalink structures, CSS widths, URL-encoded text, serialized data).
	 *
	 * @param string   $table  Table name.
	 * @param int      $offset Row offset to start at.
	 * @param resource $handle Open, writable file handle.
	 */
	public function export_data_chunk( string $table, int $offset, $handle ): int {
		global $wpdb;

		$order_by = '';
		$pk       = $this->primary_key_columns( $table );

		if ( ! empty( $pk ) ) {
			$order_by = ' ORDER BY ' . implode( ', ', array_map( array( self::class, 'quote_identifier' ), $pk ) );
		}

		$rows = $wpdb->get_results( // phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter -- table name quoted as an identifier by quote_identifier(); identifiers can't be placeholders before WordPress 6.2.
			'SELECT * FROM ' . self::quote_identifier( $table ) . $order_by . ' LIMIT ' . (int) $offset . ', ' . self::rows_per_chunk(), // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- identifiers quoted, integers cast.
			ARRAY_A
		);

		if ( empty( $rows ) ) {
			return 0;
		}

		$column_list = implode( ', ', array_map( array( self::class, 'quote_identifier' ), array_keys( $rows[0] ) ) );
		$insert      = 'INSERT INTO ' . self::quote_identifier( $table ) . ' (' . $column_list . ') VALUES (';
		$secret_key  = self::secret_key_column( $table );

		foreach ( $rows as $row ) {
			// Authentication keys and salts WordPress stored in the database
			// (when wp-config.php doesn't define them) never leave this site.
			if ( '' !== $secret_key && in_array( strtolower( (string) ( $row[ $secret_key ] ?? '' ) ), Paths::SECRET_KEY_NAMES, true ) ) {
				continue;
			}

			$values = array();

			foreach ( $row as $value ) {
				$values[] = null === $value ? 'NULL' : "'" . esc_sql( (string) $value ) . "'";
			}

			// esc_sql() adds the same placeholder token as prepare(); strip it
			// so the dump holds the literal "%" characters.
			fwrite( $handle, $wpdb->remove_placeholder_escape( $insert . implode( ', ', $values ) . ");\n" ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite
		}

		// Rows read, not rows written: the caller pages by this number.
		return count( $rows );
	}

	/**
	 * The column naming each row in the table where WordPress keeps its
	 * fallback authentication keys and salts (wp_salt()): options on a
	 * single site, sitemeta on a network. '' for every other table.
	 */
	private static function secret_key_column( string $table ): string {
		global $wpdb;

		if ( is_multisite() ) {
			return $table === $wpdb->sitemeta ? 'meta_key' : '';
		}

		return $table === $wpdb->options ? 'option_name' : '';
	}
}
