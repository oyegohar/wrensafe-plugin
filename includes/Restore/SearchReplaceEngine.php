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

	/** Keys a step of the address replacement covers when MySQL picks the rows (see core_terms()). */
	const RANGE_ROWS = 20000;

	/** From this many rows on, MySQL picks the rows to read; smaller tables are read in full, as before. */
	const LARGE_TABLE_ROWS = 50000;

	/** @var array<string,string|null> Primary key per table, looked up once. */
	private array $primary_keys = array();

	/** @var array<string,string[]> Column names per table, looked up once. */
	private array $columns = array();

	/** @var array<string,bool> Whether each table is large (see is_large()). */
	private array $large = array();

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
	public function process_table_after( string $table, ?string $after, array $pairs, bool $dry_run = false, array $skip_columns = array(), bool $whole_addresses = false ): array {
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

		$cores = $whole_addresses && $this->is_large( $table ) ? self::core_terms( $map ) : null;

		if ( null !== $cores ) {
			return $this->process_matching_rows( $table, $after, $primary_key, $map, $cores, $dry_run, $skip_columns );
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

		$rows_changed = $this->apply_to_rows( $table, $rows, $primary_key, $map, $dry_run, $skip_columns, $whole_addresses );
		$last         = end( $rows );

		return array(
			'cursor'       => (string) $last[ $primary_key ],
			'done'         => count( $rows ) < self::ROWS_PER_CHUNK,
			'rows_changed' => $rows_changed,
			'rows_seen'    => count( $rows ),
		);
	}

	/**
	 * What a value must contain before any replacement can touch it: for
	 * each search term, its longest run of letters, digits, ".", "-" and
	 * "_". The JSON-escaped and URL-encoded forms the replacement also
	 * looks for (Serializer::expand_map()) keep such a run as it is, so a
	 * row without one in any column can't change. Null when a term has no
	 * run of 4 characters or more: then every row is read.
	 *
	 * @param array<string,string> $map Search => replace.
	 * @return string[]|null
	 */
	public static function core_terms( array $map ): ?array {
		$cores = array();

		foreach ( array_keys( $map ) as $search ) {
			$best = '';

			if ( preg_match_all( '/[A-Za-z0-9._-]+/', (string) $search, $runs ) ) {
				foreach ( $runs[0] as $run ) {
					if ( strlen( $run ) > strlen( $best ) ) {
						$best = $run;
					}
				}
			}

			if ( strlen( $best ) < 4 ) {
				return null;
			}

			$cores[] = $best;
		}

		// A run that contains another adds nothing: the shorter one finds its rows too.
		$cores = array_values( array_unique( $cores ) );
		usort(
			$cores,
			static function ( string $a, string $b ): int {
				return strlen( $a ) <=> strlen( $b );
			}
		);

		$kept = array();

		foreach ( $cores as $core ) {
			foreach ( $kept as $shorter ) {
				if ( false !== strpos( $core, $shorter ) ) {
					continue 2;
				}
			}

			$kept[] = $core;
		}

		return $kept;
	}

	/**
	 * The address replacement of a restore or Fix Links, with MySQL picking
	 * the rows: of the next RANGE_ROWS keys after $after, only rows where a
	 * column contains one of $cores are read and checked, so a table of
	 * millions of rows without the old address is passed over in a few
	 * queries instead of read row by row. Every step stays bounded.
	 *
	 * @param array<string,string> $map
	 * @param string[]             $cores        See core_terms().
	 * @param string[]             $skip_columns
	 * @return array{cursor:?string,done:bool,rows_changed:int,rows_seen?:int}
	 */
	private function process_matching_rows( string $table, ?string $after, string $primary_key, array $map, array $cores, bool $dry_run, array $skip_columns ): array {
		global $wpdb;

		$lower = null === $after
			? $wpdb->get_var( $wpdb->prepare( 'SELECT %i FROM %i ORDER BY %i LIMIT 1', $primary_key, $table, $primary_key ) )
			: $wpdb->get_var( $wpdb->prepare( 'SELECT %i FROM %i WHERE %i > %s ORDER BY %i LIMIT 1', $primary_key, $table, $primary_key, $after, $primary_key ) );

		$columns = array_values( array_diff( $this->get_columns( $table ), array_merge( array( $primary_key ), $skip_columns ) ) );

		if ( null === $lower || empty( $columns ) ) {
			return array(
				'cursor'       => $after,
				'done'         => true,
				'rows_changed' => 0,
			);
		}

		$upper = $wpdb->get_var( $wpdb->prepare( 'SELECT %i FROM %i WHERE %i >= %s ORDER BY %i LIMIT 1 OFFSET %d', $primary_key, $table, $primary_key, $lower, $primary_key, self::RANGE_ROWS - 1 ) );
		$last  = null === $upper;

		if ( $last ) {
			$upper = $wpdb->get_var( $wpdb->prepare( 'SELECT %i FROM %i ORDER BY %i DESC LIMIT 1', $primary_key, $table, $primary_key ) );
		}

		// The keys of the first matching rows, column by column. A row among
		// the first ROWS_PER_CHUNK matches of the range is among the first
		// ROWS_PER_CHUNK of a column it matches in, so the union holds them.
		$keys = array();

		foreach ( $columns as $column ) {
			foreach ( $cores as $core ) {
				$found = $wpdb->get_col( $wpdb->prepare( 'SELECT %i FROM %i WHERE %i >= %s AND %i <= %s AND INSTR(%i, %s) > 0 ORDER BY %i LIMIT %d', $primary_key, $table, $primary_key, $lower, $primary_key, (string) $upper, $column, $core, $primary_key, self::ROWS_PER_CHUNK ) );

				foreach ( (array) $found as $key ) {
					$keys[ (string) $key ] = true;
				}
			}
		}

		$rows = array();

		if ( $keys ) {
			// Keys in the table's own order (numeric or text), then the first batch of them.
			$order = $wpdb->get_col(
				$wpdb->prepare(
					'SELECT %i FROM %i WHERE %i IN (' . implode( ', ', array_fill( 0, count( $keys ), '%s' ) ) . ') ORDER BY %i LIMIT %d',
					array_merge( array( $primary_key, $table, $primary_key ), array_map( 'strval', array_keys( $keys ) ), array( $primary_key, self::ROWS_PER_CHUNK ) )
				)
			);

			if ( $order ) {
				$rows = $wpdb->get_results(
					$wpdb->prepare(
						'SELECT * FROM %i WHERE %i IN (' . implode( ', ', array_fill( 0, count( $order ), '%s' ) ) . ') ORDER BY %i',
						array_merge( array( $table, $primary_key ), array_map( 'strval', $order ), array( $primary_key ) )
					),
					ARRAY_A
				);
			}
		}

		$rows = is_array( $rows ) ? $rows : array();

		$rows_changed = $rows ? $this->apply_to_rows( $table, $rows, $primary_key, $map, $dry_run, $skip_columns, true ) : 0;

		// A full batch can leave more matching rows in this range: continue after the last one.
		if ( count( $rows ) === self::ROWS_PER_CHUNK ) {
			$last_row = end( $rows );

			return array(
				'cursor'       => (string) $last_row[ $primary_key ],
				'done'         => false,
				'rows_changed' => $rows_changed,
				'rows_seen'    => count( $rows ),
			);
		}

		return array(
			'cursor'       => (string) $upper,
			'done'         => $last,
			'rows_changed' => $rows_changed,
			'rows_seen'    => count( $rows ),
		);
	}

	/** Whether the table holds LARGE_TABLE_ROWS rows or more (MySQL's estimate, looked up once). */
	private function is_large( string $table ): bool {
		global $wpdb;

		if ( ! isset( $this->large[ $table ] ) ) {
			$rows                  = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT TABLE_ROWS FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s', $table ) );
			$this->large[ $table ] = $rows >= self::LARGE_TABLE_ROWS;
		}

		return $this->large[ $table ];
	}

	/**
	 * The table's column names.
	 *
	 * @return string[]
	 */
	private function get_columns( string $table ): array {
		global $wpdb;

		if ( ! isset( $this->columns[ $table ] ) ) {
			$this->columns[ $table ] = array_map( 'strval', (array) $wpdb->get_col( $wpdb->prepare( 'SHOW COLUMNS FROM %i', $table ) ) );
		}

		return $this->columns[ $table ];
	}

	/**
	 * Rewrite every string column of $rows through the replacement map and
	 * save the rows that changed. Returns how many rows were updated.
	 *
	 * @param array<int,array<string,mixed>> $rows
	 * @param array<string,string>           $map
	 * @param string[]                       $skip_columns
	 * @param bool                           $whole_addresses The pairs are site addresses: a longer address that
	 *                                                        only starts like one stays (Serializer::replace_addresses()).
	 */
	private function apply_to_rows( string $table, array $rows, string $primary_key, array $map, bool $dry_run = false, array $skip_columns = array(), bool $whole_addresses = false ): int {
		global $wpdb;

		$rows_changed = 0;

		foreach ( $rows as $row ) {
			$updates = array();

			foreach ( $row as $column => $value ) {
				if ( $column === $primary_key || ! is_string( $value ) || in_array( $column, $skip_columns, true ) ) {
					continue;
				}

				$new_value = $whole_addresses ? Serializer::replace_addresses( $value, $map ) : Serializer::replace( $value, $map );

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
