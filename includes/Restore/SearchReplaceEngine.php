<?php
/**
 * Serialized-data-safe and JSON-safe search/replace across the database,
 * used to rewrite the old site URL to the new one after a migration.
 *
 * Processes rows in bounded batches via a stored (table, row offset)
 * cursor, so it stays timeout-safe regardless of database size.
 *
 * @package WPBP\Restore
 */

namespace WPBP\Restore;

use WPBP\Utils\Serializer;

defined( 'ABSPATH' ) || exit;

// Database access in this file is direct by design: Wrensafe's own tables (or,
// in the backup/restore engine, the site's tables being exported, imported or
// swapped) have no WordPress API, and the data must be read fresh, so it is not
// object-cached. Every query goes through $wpdb->prepare(), with table and
// column names as identifier placeholders (%i).
// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

class SearchReplaceEngine {

	const ROWS_PER_CHUNK = 200;

	/** @var array<string,string|null> Primary key per table, looked up once. */
	private array $primary_keys = array();

	/**
	 * Process one bounded batch of rows for a table.
	 *
	 * @return array{next_offset:int,done:bool,rows_changed:int}
	 */
	public function process_table_chunk( string $table, int $offset, string $search, string $replace ): array {
		return $this->process_table_chunk_multi( $table, $offset, array( array( $search, $replace ) ) );
	}

	/**
	 * Apply several search → replace pairs to one bounded batch of rows. All
	 * pairs are applied simultaneously in a single pass per value (see
	 * Serializer::replace()), so a replacement is never re-replaced by
	 * another pair and every row is read and written at most once.
	 *
	 * @param array<int,array{0:string,1:string}> $pairs [search, replace] pairs.
	 * @return array{next_offset:int,done:bool,rows_changed:int}
	 */
	public function process_table_chunk_multi( string $table, int $offset, array $pairs ): array {
		global $wpdb;

		$map = array();

		foreach ( $pairs as $pair ) {
			if ( is_array( $pair ) && isset( $pair[0], $pair[1] ) && '' !== (string) $pair[0] && (string) $pair[0] !== (string) $pair[1] ) {
				$map[ (string) $pair[0] ] = (string) $pair[1];
			}
		}

		if ( empty( $map ) ) {
			return array(
				'next_offset'  => $offset,
				'done'         => true,
				'rows_changed' => 0,
			);
		}

		$primary_key = $this->get_primary_key( $table );

		if ( null === $primary_key ) {
			return array(
				'next_offset'  => $offset,
				'done'         => true,
				'rows_changed' => 0,
			);
		}

		// Stable order, so updating rows mid-scan can't make a later chunk
		// skip or revisit rows.
		$rows = $wpdb->get_results(
			$wpdb->prepare( 'SELECT * FROM %i ORDER BY %i LIMIT %d, %d', $table, $primary_key, $offset, self::ROWS_PER_CHUNK ),
			ARRAY_A
		);

		if ( empty( $rows ) ) {
			return array(
				'next_offset'  => $offset,
				'done'         => true,
				'rows_changed' => 0,
			);
		}

		$rows_changed = $this->apply_to_rows( $table, $rows, $primary_key, $map );

		return array(
			'next_offset'  => $offset + count( $rows ),
			'done'         => count( $rows ) < self::ROWS_PER_CHUNK,
			'rows_changed' => $rows_changed,
		);
	}

	/**
	 * Keyset-paginated variant of process_table_chunk_multi(): reads the
	 * next rows whose primary key is greater than $after, so every batch
	 * costs the same no matter how deep into a large table it is (LIMIT
	 * offset re-scans every skipped row). Pass null to start a table.
	 *
	 * @param array<int,array{0:string,1:string}> $pairs        [search, replace] pairs.
	 * @param bool                                $dry_run      Count the rows that would change without saving them.
	 * @param string[]                            $skip_columns Columns left as they are (e.g. post GUIDs).
	 * @return array{cursor:?string,done:bool,rows_changed:int,rows_seen?:int}
	 */
	public function process_table_after( string $table, ?string $after, array $pairs, bool $dry_run = false, array $skip_columns = array() ): array {
		global $wpdb;

		$map = array();

		foreach ( $pairs as $pair ) {
			if ( is_array( $pair ) && isset( $pair[0], $pair[1] ) && '' !== (string) $pair[0] && (string) $pair[0] !== (string) $pair[1] ) {
				$map[ (string) $pair[0] ] = (string) $pair[1];
			}
		}

		$primary_key = empty( $map ) ? null : $this->get_primary_key( $table );

		if ( null === $primary_key ) {
			return array(
				'cursor'       => $after,
				'done'         => true,
				'rows_changed' => 0,
			);
		}

		$rows = null === $after
			? $wpdb->get_results(
				$wpdb->prepare( 'SELECT * FROM %i ORDER BY %i LIMIT %d', $table, $primary_key, self::ROWS_PER_CHUNK ),
				ARRAY_A
			)
			: $wpdb->get_results(
				$wpdb->prepare( 'SELECT * FROM %i WHERE %i > %s ORDER BY %i LIMIT %d', $table, $primary_key, $after, $primary_key, self::ROWS_PER_CHUNK ),
				ARRAY_A
			);

		if ( empty( $rows ) ) {
			return array(
				'cursor'       => $after,
				'done'         => true,
				'rows_changed' => 0,
			);
		}

		$rows_changed = $this->apply_to_rows( $table, $rows, $primary_key, $map, $dry_run, $skip_columns );
		$last         = end( $rows );

		return array(
			'cursor'       => (string) $last[ $primary_key ],
			'done'         => count( $rows ) < self::ROWS_PER_CHUNK,
			'rows_changed' => $rows_changed,
			'rows_seen'    => count( $rows ),
		);
	}

	/**
	 * Rewrite every string column of $rows through the replacement map and
	 * save the rows that changed. Returns how many rows were updated.
	 *
	 * @param array<int,array<string,mixed>> $rows
	 * @param array<string,string>           $map
	 * @param string[]                       $skip_columns
	 */
	private function apply_to_rows( string $table, array $rows, string $primary_key, array $map, bool $dry_run = false, array $skip_columns = array() ): int {
		global $wpdb;

		$rows_changed = 0;

		foreach ( $rows as $row ) {
			$updates = array();

			foreach ( $row as $column => $value ) {
				if ( $column === $primary_key || ! is_string( $value ) || in_array( $column, $skip_columns, true ) ) {
					continue;
				}

				$new_value = Serializer::replace( $value, $map );

				if ( $new_value !== $value ) {
					$updates[ $column ] = $new_value;
				}
			}

			if ( ! empty( $updates ) ) {
				if ( ! $dry_run ) {
					$wpdb->update( $table, $updates, array( $primary_key => $row[ $primary_key ] ) );
				}
				++$rows_changed;
			}
		}

		return $rows_changed;
	}

	/**
	 * Replace a search term inside a value, preserving serialized PHP data
	 * and JSON integrity (see Serializer::replace()).
	 */
	public function replace_in_value( string $value, string $search, string $replace ): string {
		return (string) Serializer::deep_replace( $value, $search, $replace );
	}

	/**
	 * The table's single-column primary key, or null when it has none (or a
	 * composite one, which a single-column WHERE can't address safely).
	 */
	public function get_primary_key( string $table ): ?string {
		global $wpdb;

		if ( array_key_exists( $table, $this->primary_keys ) ) {
			return $this->primary_keys[ $table ];
		}

		$keys = $wpdb->get_results( $wpdb->prepare( 'SHOW KEYS FROM %i WHERE Key_name = %s', $table, 'PRIMARY' ), ARRAY_A );

		$this->primary_keys[ $table ] = ( is_array( $keys ) && 1 === count( $keys ) ) ? (string) $keys[0]['Column_name'] : null;

		return $this->primary_keys[ $table ];
	}
}
