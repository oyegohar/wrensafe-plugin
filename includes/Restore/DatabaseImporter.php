<?php
/**
 * Replays a single table's schema or data SQL file produced by
 * DatabaseExporter, one bounded batch of statements at a time.
 *
 * Reads statements with SqlStatementReader rather than fgets(), so a
 * multi-line CREATE TABLE or a value containing a newline never causes a
 * statement to be split incorrectly. Each file belongs to exactly one
 * source table, so the table name is rewritten to the destination prefix
 * without needing a SQL parser — only the backtick-quoted identifier
 * immediately after DROP TABLE IF EXISTS / CREATE TABLE / INSERT INTO is
 * touched, so row data is never altered even if it happens to contain text
 * that looks like a table name.
 *
 * Foreign keys: a CREATE TABLE's REFERENCES are pointed at the staging
 * tables of this restore (see foreign_key_map()), and explicit constraint
 * names are dropped so the staging table can't clash with the live table's
 * constraints. InnoDB then names them {table}_ibfk_N and renames them with
 * the table at the atomic swap, so the restored site ends up with the
 * usual names. Foreign key checks are off while importing: tables arrive
 * in any order.
 *
 * @package WPBP\Restore
 */

namespace WPBP\Restore;

use WPBP\Utils\ChunkSizeCalculator;
use WPBP\Utils\SqlStatementReader;

defined( 'ABSPATH' ) || exit;

// Database access in this file is direct by design: Wrensafe's own tables (or,
// in the backup/restore engine, the site's tables being exported, imported or
// swapped) have no WordPress API, and the data must be read fresh, so it is not
// object-cached. Values go through $wpdb->prepare(); table names come from
// $wpdb->prefix with fixed suffixes and are escaped or quoted as identifiers.
// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

class DatabaseImporter {

	/**
	 * Statements imported per chunk — scaled down from the 100-statement
	 * default by ChunkSizeCalculator on memory/time-constrained hosting.
	 */
	private static function statements_per_chunk(): int {
		return ChunkSizeCalculator::statements_per_chunk( 100 );
	}

	/**
	 * Execute one chunk of statements from a schema or data SQL file,
	 * starting at a byte offset, rewriting the source table's identifier to
	 * the destination table name.
	 *
	 * @return array{offset:int,done:bool,statements_run:int,error:?string}
	 */
	/**
	 * @param array{exact:array<string,string>,suffix:array<string,string>} $table_map
	 *        Foreign-key targets: table name in the backup (or name without
	 *        prefix) → staging table. See foreign_key_map().
	 */
	public function import_chunk( string $sql_file, int $offset, string $source_table, string $dest_table, bool $repair_legacy_escapes = false, array $table_map = array() ): array {
		$reader = new SqlStatementReader();

		if ( ! $reader->open( $sql_file, $offset ) ) {
			return array(
				'offset'         => $offset,
				'done'           => true,
				'statements_run' => 0,
				'error'          => null,
			);
		}

		global $wpdb;

		$wpdb->query( 'SET FOREIGN_KEY_CHECKS = 0' ); // This connection only; tables arrive in any order.

		// Keep rows whose AUTO_INCREMENT id is 0 as 0 (mysqldump does the same).
		$mode = (string) $wpdb->get_var( 'SELECT @@SESSION.sql_mode' );

		if ( false === stripos( $mode, 'NO_AUTO_VALUE_ON_ZERO' ) ) {
			$wpdb->query( $wpdb->prepare( 'SET SESSION sql_mode = %s', ltrim( $mode . ',NO_AUTO_VALUE_ON_ZERO', ',' ) ) );
		}

		$count   = 0;
		$error   = null;
		$started = microtime( true );

		// Bounded by statement count AND time, so rows with very large
		// values (page-builder content, big option blobs) can't stretch one
		// chunk past the request budget.
		while ( $count < self::statements_per_chunk() && ( 0 === $count || microtime( true ) - $started < 3 ) ) {
			$statement = $reader->read_statement();

			if ( null === $statement ) {
				break;
			}

			$statement = $this->rewrite_table_identifier( $statement, $source_table, $dest_table );

			if ( ! empty( $table_map ) && 0 === stripos( $statement, 'CREATE TABLE' ) && false !== stripos( $statement, 'REFERENCES' ) ) {
				$statement = self::rewrite_foreign_keys( $statement, $table_map );
			}

			if ( $repair_legacy_escapes ) {
				$statement = self::repair_placeholder_escapes( $statement );
			}

			$wpdb->query( $statement ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- runs the SQL statements of a checksum-verified backup during a restore an administrator started; that is what a database restore is.

			// wpdb refuses a query whose text doesn't fit the TABLE's default
			// charset, even when the column is utf8mb4 (emoji in a utf8 table's
			// utf8mb4 column: "contains invalid data"). Retry once with the
			// non-ASCII strings as _utf8mb4 hex literals: the same text for
			// MySQL, but nothing for wpdb to strip.
			if ( '' !== (string) $wpdb->last_error && 1 === preg_match( '/[\x80-\xFF]/', $statement ) ) {
				$wpdb->query( self::ascii_literals( $statement ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- runs the SQL statements of a checksum-verified backup during a restore an administrator started; that is what a database restore is.
			}

			++$count;

			if ( '' !== (string) $wpdb->last_error ) {
				$error = $wpdb->last_error;
				break;
			}
		}

		$done       = null === $error && $reader->is_eof();
		$new_offset = $reader->last_stmt_end();
		$reader->close();

		return array(
			'offset'         => $new_offset,
			'done'           => $done,
			'statements_run' => $count,
			'error'          => $error,
		);
	}

	/**
	 * The statement with every quoted string that holds non-ASCII bytes
	 * written as a _utf8mb4 hex literal (MySQL escapes decoded first).
	 * Identifiers in backticks and ASCII strings are left as they are.
	 */
	public static function ascii_literals( string $sql ): string {
		$out    = '';
		$length = strlen( $sql );
		$i      = 0;
		$map    = array(
			'0' => "\0",
			'b' => "\x08",
			'n' => "\n",
			'r' => "\r",
			't' => "\t",
			'Z' => "\x1a",
		);

		while ( $i < $length ) {
			$ch = $sql[ $i ];

			if ( '`' === $ch ) {
				$end  = strpos( $sql, '`', $i + 1 );
				$end  = false === $end ? $length - 1 : $end;
				$out .= substr( $sql, $i, $end - $i + 1 );
				$i    = $end + 1;
				continue;
			}

			if ( "'" !== $ch && '"' !== $ch ) {
				$out .= $ch;
				++$i;
				continue;
			}

			// A quoted string: decode it to its bytes.
			$quote = $ch;
			$start = $i;
			$raw   = '';
			++$i;

			while ( $i < $length ) {
				$c = $sql[ $i ];

				if ( '\\' === $c && $i + 1 < $length ) {
					$next = $sql[ $i + 1 ];
					$raw .= isset( $map[ $next ] ) ? $map[ $next ] : ( '%' === $next || '_' === $next ? '\\' . $next : $next );
					$i   += 2;
					continue;
				}

				if ( $c === $quote ) {
					if ( $i + 1 < $length && $sql[ $i + 1 ] === $quote ) {
						$raw .= $quote;
						$i   += 2;
						continue;
					}
					break;
				}

				$raw .= $c;
				++$i;
			}

			$literal = substr( $sql, $start, $i - $start + 1 );
			$out    .= ( '' !== $raw && 1 === preg_match( '/[\x80-\xFF]/', $raw ) ) ? '_utf8mb4 0x' . bin2hex( $raw ) : $literal;
			++$i;
		}

		return $out;
	}

	/**
	 * Foreign-key targets for a restore. A REFERENCES clause names the table
	 * as the source site did: with the archive's source table name, or with
	 * the source site's real prefix (All-in-One WP Migration writes
	 * SERVMASK_PREFIX_ table names but keeps the real prefix in REFERENCES).
	 *
	 * @param array<int,array{source_table:string,dest_table:string,tmp_table:string}> $tables
	 * @return array{exact:array<string,string>,suffix:array<string,string>}
	 */
	public static function foreign_key_map( array $tables, string $dest_prefix ): array {
		$map = array(
			'exact'  => array(),
			'suffix' => array(),
		);

		foreach ( $tables as $entry ) {
			$tmp = (string) $entry['tmp_table'];

			$map['exact'][ (string) $entry['source_table'] ] = $tmp;
			$map['exact'][ (string) $entry['dest_table'] ]   = $tmp;

			if ( '' !== $dest_prefix && 0 === strpos( (string) $entry['dest_table'], $dest_prefix ) ) {
				$map['suffix'][ substr( (string) $entry['dest_table'], strlen( $dest_prefix ) ) ] = $tmp;
			}
		}

		// Longest first, so "wp_kirki_comments" matches kirki_comments, not comments.
		uksort(
			$map['suffix'],
			static function ( $a, $b ) {
				return strlen( (string) $b ) - strlen( (string) $a );
			}
		);

		return $map;
	}

	/**
	 * Point a CREATE TABLE's foreign keys at this restore's staging tables
	 * and drop explicit FOREIGN KEY constraint names (CHECK constraints are
	 * left alone).
	 *
	 * @param array{exact:array<string,string>,suffix:array<string,string>} $map
	 */
	public static function rewrite_foreign_keys( string $statement, array $map ): string {
		$statement = (string) preg_replace( '/\bCONSTRAINT\s+`(?:[^`]|``)+`\s+(FOREIGN\s+KEY)/i', '$1', $statement );

		return (string) preg_replace_callback(
			'/\bREFERENCES\s+`((?:[^`]|``)+)`/i',
			static function ( array $m ) use ( $map ): string {
				$name = str_replace( '``', '`', $m[1] );

				return 'REFERENCES `' . str_replace( '`', '``', self::foreign_key_target( $name, $map ) ) . '`';
			},
			$statement
		);
	}

	/** @param array{exact:array<string,string>,suffix:array<string,string>} $map */
	private static function foreign_key_target( string $name, array $map ): string {
		if ( isset( $map['exact'][ $name ] ) ) {
			return $map['exact'][ $name ];
		}

		foreach ( (array) ( $map['suffix'] ?? array() ) as $suffix => $tmp ) {
			$suffix = (string) $suffix;
			$prefix = substr( $name, 0, - strlen( $suffix ) );

			if ( strlen( $name ) > strlen( $suffix ) && substr( $name, - strlen( $suffix ) ) === $suffix && 1 === preg_match( '/^[A-Za-z0-9_]*_$/', $prefix ) ) {
				return $tmp;
			}
		}

		return $name; // Not part of this restore: leave it as the backup has it.
	}

	/**
	 * Undo the wpdb placeholder tokens that archives written by 0.9.1-beta and
	 * earlier contain in place of every "%" (the exporter ran row values
	 * through $wpdb->prepare(), which swaps "%" for a random per-request
	 * "{64 hex chars}" token that only that same request could reverse).
	 * Each token stood for exactly one "%", so this restores the original
	 * bytes — including serialized string lengths — exactly.
	 */
	public static function repair_placeholder_escapes( string $statement ): string {
		if ( false === strpos( $statement, '{' ) ) {
			return $statement;
		}

		return (string) preg_replace( '/\{[0-9a-f]{64}\}/', '%', $statement );
	}

	/**
	 * Replace the backtick-quoted table name immediately following
	 * DROP TABLE IF EXISTS / CREATE TABLE / INSERT INTO with the
	 * destination table name. Anchored to the start of the statement so
	 * key/index names sharing the source prefix, or row data that happens
	 * to mention it, are left untouched.
	 */
	private function rewrite_table_identifier( string $statement, string $source_table, string $dest_table ): string {
		if ( $source_table === $dest_table ) {
			return $statement;
		}

		$pattern = '/^(DROP TABLE IF EXISTS|CREATE TABLE|INSERT(?:\s+IGNORE)?\s+INTO|REPLACE\s+INTO|UPDATE|DELETE\s+FROM)\s+`' . preg_quote( $source_table, '/' ) . '`/i';

		return preg_replace( $pattern, '$1 `' . $dest_table . '`', $statement, 1 );
	}
}
