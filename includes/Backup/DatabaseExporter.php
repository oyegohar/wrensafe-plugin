<?php
/**
 * Exports the site's own database tables to a portable SQL dump in chunks.
 *
 * @package WPBP\Backup
 */

namespace WPBP\Backup;

use WPBP\Core\Database\StagingSitesRepository;
use WPBP\Core\Settings;
use WPBP\Utils\ChunkSizeCalculator;
use WPBP\Utils\Paths;
use WPBP\Utils\Str;

defined( 'ABSPATH' ) || exit;

// Database access in this file is direct by design: Wrensafe's own tables (or,
// in the backup/restore engine, the site's tables being exported, imported or
// swapped) have no WordPress API, and the data must be read fresh, so it is not
// object-cached. Every query goes through $wpdb->prepare(), with table and
// column names as identifier placeholders (%i).
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
	 * Tables left out under Settings → Backup are skipped too, unless $all.
	 * Staging sites' tables ({prefix}stg_{slug}_…) are never included: they
	 * belong to the staging copies, not to this site.
	 */
	public function get_tables( bool $all = false ): array {
		global $wpdb;

		// Base tables only: a VIEW has no rows of its own and its CREATE
		// statement (with DEFINER) cannot be replayed into a staging table.
		$rows = $wpdb->get_results(
			$wpdb->prepare( 'SHOW FULL TABLES WHERE `Table_type` = %s', 'BASE TABLE' ),
			ARRAY_N
		);

		$tables  = array();
		$staging = self::staging_prefixes();

		foreach ( (array) $rows as $row ) {
			$table = (string) ( $row[0] ?? '' );

			if ( '' !== $table && 0 === strpos( $table, $wpdb->prefix ) && ! self::is_restore_work_table( $table ) && ! self::has_prefix( $table, $staging ) ) {
				$tables[] = $table;
			}
		}

		// Tables left out under Settings → Backup → Exclusions (names saved
		// without the prefix, so they survive a prefix change).
		$excluded = $all ? array() : (array) Settings::get( 'excluded_tables', array() );

		if ( $excluded ) {
			$tables = array_values(
				array_filter(
					$tables,
					static function ( $table ) use ( $excluded, $wpdb ) {
						return ! in_array( substr( (string) $table, strlen( $wpdb->prefix ) ), $excluded, true );
					}
				)
			);
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
	 * The table prefixes of this site's staging copies (see StagingManager:
	 * {prefix}stg_{slug}_).
	 *
	 * @return string[]
	 */
	private static function staging_prefixes(): array {
		global $wpdb;

		$prefixes = array();

		foreach ( StagingSitesRepository::list_all() as $site ) {
			$prefix = (string) ( $site['table_prefix'] ?? '' );

			if ( Str::starts_with( $prefix, $wpdb->prefix . 'stg_' ) && strlen( $prefix ) > strlen( $wpdb->prefix . 'stg_' ) ) {
				$prefixes[] = $prefix;
			}
		}

		return $prefixes;
	}

	/** @param string[] $prefixes */
	private static function has_prefix( string $table, array $prefixes ): bool {
		foreach ( $prefixes as $prefix ) {
			if ( Str::starts_with( $table, $prefix ) ) {
				return true;
			}
		}

		return false;
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

		return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i', $table ) );
	}

	/**
	 * Append a table's CREATE TABLE statement to an open file handle.
	 *
	 * @param string   $table  Table name.
	 * @param resource $handle Open, writable file handle.
	 */
	public function export_create_statement( string $table, $handle ): void {
		global $wpdb;

		$row = $wpdb->get_row( $wpdb->prepare( 'SHOW CREATE TABLE %i', $table ), ARRAY_N ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- reads a table definition for the backup; it changes nothing.

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

		$keys    = $wpdb->get_results( $wpdb->prepare( 'SHOW KEYS FROM %i WHERE Key_name = %s', $table, 'PRIMARY' ), ARRAY_A );
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
	public function export_data_chunk( string $table, int $offset, $handle, ?array &$cursor = null ): int {
		global $wpdb;

		$pk = $this->primary_key_columns( $table );

		if ( empty( $pk ) ) {
			// No primary key (rare): page by position.
			$rows = $wpdb->get_results(
				$wpdb->prepare( 'SELECT * FROM %i LIMIT %d, %d', $table, $offset, self::rows_per_chunk() ),
				ARRAY_A
			);
		} elseif ( is_array( $cursor ) && count( $cursor ) === count( $pk ) ) {
			// The rows after the last one exported, by primary key. Paging by
			// position would skip rows whenever the site deletes some while
			// the backup runs (WP-Cron removing _pingme meta, expired
			// sessions…): every later row moves up and the next page starts
			// past it.
			$rows = $wpdb->get_results(
				$wpdb->prepare( // phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- one %i and one %s per primary-key column; the sniff can't count array_fill() placeholders.
					'SELECT * FROM %i WHERE (' . implode( ', ', array_fill( 0, count( $pk ), '%i' ) ) . ') > (' . implode( ', ', array_fill( 0, count( $pk ), '%s' ) ) . ') ORDER BY ' . implode( ', ', array_fill( 0, count( $pk ), '%i' ) ) . ' LIMIT %d',
					array_merge( array( $table ), $pk, array_map( 'strval', array_values( $cursor ) ), $pk, array( self::rows_per_chunk() ) )
				),
				ARRAY_A
			);
		} else {
			// The first page, or a job started by an older version part-way
			// through this table (it kept only a position).
			$rows = $wpdb->get_results(
				$wpdb->prepare( // phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- one %i per primary-key column; the sniff can't count array_fill() placeholders.
					'SELECT * FROM %i ORDER BY ' . implode( ', ', array_fill( 0, count( $pk ), '%i' ) ) . ' LIMIT %d, %d',
					array_merge( array( $table ), $pk, array( $offset, self::rows_per_chunk() ) )
				),
				ARRAY_A
			);
		}

		if ( empty( $rows ) ) {
			return 0;
		}

		if ( ! empty( $pk ) ) {
			$last   = $rows[ count( $rows ) - 1 ];
			$cursor = array();

			foreach ( $pk as $column ) {
				$cursor[] = null === $last[ $column ] ? '' : (string) $last[ $column ];
			}
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
