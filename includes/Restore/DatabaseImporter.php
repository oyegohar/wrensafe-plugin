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
 * Only statements that write to that one table are run (see
 * statement_for_table()): a backup's SQL file can't drop, create or change
 * any other table, and anything that isn't a table statement stops the
 * restore before the live database is touched.
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
use WPBP\Utils\Errors;
use WPBP\Utils\SqlStatementReader;

defined( 'ABSPATH' ) || exit;

// Database access in this file is direct by design: Wrensafe's own tables (or,
// in the backup/restore engine, the site's tables being exported, imported or
// swapped) have no WordPress API, and the data must be read fresh, so it is not
// object-cached. The statements of a backup's SQL file can't be prepared (they
// are complete SQL); each one is checked by statement_for_table() first.
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
	 * Session statements a dump may carry (mysqldump, phpMyAdmin, other
	 * backup plugins). They are skipped: the import sets up its own session
	 * (foreign key checks off, NO_AUTO_VALUE_ON_ZERO), and table locks or
	 * transactions add nothing to one table's import.
	 */
	private const SKIPPED_STATEMENT = '/^(?:SET\s|LOCK\s+TABLES\s|UNLOCK\s+TABLES$|START\s+TRANSACTION|BEGIN$|COMMIT$|ROLLBACK$)/i';

	/**
	 * Execute one chunk of statements from a schema or data SQL file,
	 * starting at a byte offset, rewriting the source table's identifier to
	 * the destination table name.
	 *
	 * @param array{exact:array<string,string>,suffix:array<string,string>} $table_map
	 *        Foreign-key targets: table name in the backup (or name without
	 *        prefix) → staging table. See foreign_key_map().
	 * @return array{offset:int,done:bool,statements_run:int,error:?string}
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

			++$count;

			$checked = self::statement_for_table( $statement, $source_table, $dest_table );

			if ( null === $checked ) {
				continue; // A session statement (see SKIPPED_STATEMENT).
			}

			if ( '' === $checked ) {
				$error = sprintf(
					/* translators: %s: the start of an SQL statement */
					__( 'its SQL file in the backup contains a statement a restore doesn’t run (%s…). The restore stopped before the live database was changed.', 'wrensafe' ),
					Errors::utf8( substr( (string) preg_replace( '/\s+/', ' ', $statement ), 0, 60 ) )
				);
				break;
			}

			if ( ! empty( $table_map ) && 0 === stripos( $checked, 'CREATE TABLE' ) && false !== stripos( $checked, 'REFERENCES' ) ) {
				$checked = self::rewrite_foreign_keys( $checked, $table_map );
			}

			if ( $repair_legacy_escapes ) {
				$checked = self::repair_placeholder_escapes( $checked );
			}

			$failed = self::run_statement( $checked );

			if ( '' !== $failed ) {
				$error = $failed;
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
	 * Run one statement of a backup's SQL file, already checked and
	 * rewritten by statement_for_table(). Returns the database error, or ''.
	 */
	private static function run_statement( string $sql ): string {
		global $wpdb;

		// wpdb refuses a query whose text doesn't fit the TABLE's default
		// charset, even when the column is utf8mb4 (emoji in a utf8 table's
		// utf8mb4 column: "contains invalid data"). The second attempt sends
		// the non-ASCII strings as _utf8mb4 hex literals: the same text for
		// MySQL, but nothing for wpdb to strip.
		$attempts = 1 === preg_match( '/[\x80-\xFF]/', $sql ) ? 2 : 1;

		for ( $attempt = 1; $attempt <= $attempts; $attempt++ ) {
			if ( 2 === $attempt ) {
				$sql = self::ascii_literals( $sql );
			}

			// The only query Wrensafe runs without $wpdb->prepare(): a
			// complete statement from the SQL file of the backup an
			// administrator chose to restore (checksum-verified before the
			// restore started), which can't be written with placeholders.
			// statement_for_table() only lets through DROP TABLE IF EXISTS,
			// CREATE TABLE, INSERT, REPLACE, UPDATE and DELETE statements on
			// the one table being restored, renamed to this restore's own
			// staging table, so no other table can be read into it or changed.
			$wpdb->query( $sql ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- see above: a restored backup's own SQL, limited to its staging table.

			if ( '' === (string) $wpdb->last_error ) {
				return '';
			}
		}

		return (string) $wpdb->last_error;
	}

	/**
	 * What to run for one statement of a table's SQL file:
	 *  - the statement with the table renamed to $dest_table, when it is one
	 *    of the statements DatabaseExporter, SqlSplitter and ForeignConverter
	 *    write, on the table this file belongs to: DROP TABLE IF EXISTS,
	 *    CREATE TABLE, INSERT [IGNORE] INTO, REPLACE INTO, UPDATE … SET and
	 *    DELETE FROM (Jetpack's incremental "-updates" files);
	 *  - null for a session statement, which is skipped (SKIPPED_STATEMENT,
	 *    and ALTER TABLE … DISABLE/ENABLE KEYS: MyISAM load hints);
	 *  - '' for anything else (another table, several tables, any other
	 *    kind of statement), which stops the restore.
	 *
	 * Comments, including mysqldump's /*!40101 … *\/ ones, never get here:
	 * SqlStatementReader drops them.
	 */
	public static function statement_for_table( string $statement, string $source_table, string $dest_table ): ?string {
		$statement = ltrim( $statement );
		$name      = '`' . preg_quote( str_replace( '`', '``', $source_table ), '/' ) . '`';

		if ( 1 === preg_match( self::SKIPPED_STATEMENT, $statement ) || 1 === preg_match( '/^ALTER\s+TABLE\s+' . $name . '\s+(?:DISABLE|ENABLE)\s+KEYS$/i', $statement ) ) {
			return null;
		}

		// What may follow the table name, per statement: one table only
		// ("DROP TABLE IF EXISTS `a`, `b`" and multi-table UPDATE / DELETE
		// are refused).
		$forms = array(
			'DROP\s+TABLE\s+IF\s+EXISTS'              => '$',
			'CREATE\s+TABLE'                          => '(?:\s|\(|$)',
			'(?:INSERT(?:\s+IGNORE)?|REPLACE)\s+INTO' => '(?:\s|\()',
			'UPDATE'                                  => '\s+SET\b',
			'DELETE\s+FROM'                           => '(?:$|\s+(?:WHERE|ORDER\s+BY|LIMIT)\b)',
		);

		foreach ( $forms as $verb => $after ) {
			if ( 1 !== preg_match( '/^(' . $verb . ')\s+' . $name . '(?=' . $after . ')/i', $statement, $m ) ) {
				continue;
			}

			return preg_replace( '/\s+/', ' ', strtoupper( $m[1] ) ) . ' `' . str_replace( '`', '``', $dest_table ) . '`' . substr( $statement, strlen( $m[0] ) );
		}

		return '';
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
}
