<?php
/**
 * Splits another plugin's SQL dump into Wrensafe's per-table layout
 * (schema/{slug}.schema.sql + data/{slug}.data.sql), in resumable steps.
 *
 * Statements are normalised so the restore engine can rename each table:
 * "CREATE TABLE [IF NOT EXISTS] name" becomes "CREATE TABLE `name`",
 * INSERT / INSERT IGNORE become "INSERT IGNORE INTO `name`", and REPLACE
 * INTO, UPDATE and DELETE FROM keep their meaning (Jetpack's "-updates"
 * files change rows written earlier). $state['rename'] maps table names
 * used in the dump to the real ones (Backup Migration).
 * Session statements (SET, LOCK, DROP TABLE, ALTER … KEYS, transactions)
 * are dropped; views, triggers and routines are counted and skipped.
 *
 * While splitting it also notes the site's URLs (the siteurl and home rows
 * of the options table) so a dump without metadata can still be migrated.
 *
 * @package WPBP\Import\Foreign
 */

namespace WPBP\Import\Foreign;

use WPBP\Utils\Sanitizer;
use WPBP\Utils\SqlStatementReader;

defined( 'ABSPATH' ) || exit;

// Per-table files are appended with native handles: WP_Filesystem has no
// streaming API. Paths are inside the import job's protected tmp directory.
// phpcs:disable WordPress.WP.AlternativeFunctions

class SqlSplitter {

	/** @var array<string,resource> Open data file handles, by table. */
	private array $handles = array();

	/**
	 * Split from $state['offset'] until the end of the file or $deadline.
	 *
	 * @param array<string,mixed> $state  offset, tables (name => info), found (siteurl, home), skipped.
	 * @return bool True when the whole file is split.
	 */
	public function split( string $sql_file, string $out_dir, array &$state, float $deadline ): bool {
		$state += array(
			'offset'  => 0,
			'tables'  => array(),
			'found'   => array(),
			'skipped' => 0,
		);

		wp_mkdir_p( $out_dir . 'schema' );
		wp_mkdir_p( $out_dir . 'data' );

		$reader = new SqlStatementReader();

		if ( ! $reader->open( $sql_file, (int) $state['offset'] ) ) {
			return true;
		}

		$done = true;

		while ( null !== ( $statement = $reader->read_statement() ) ) { // phpcs:ignore Generic.CodeAnalysis.AssignmentInCondition.FoundInWhileCondition
			$this->route( $statement, $out_dir, $state );
			$state['offset'] = $reader->last_stmt_end();

			if ( microtime( true ) >= $deadline ) {
				$done = $reader->is_eof();
				break;
			}
		}

		$reader->close();
		$this->close();

		return $done;
	}

	public function close(): void {
		foreach ( $this->handles as $handle ) {
			if ( is_resource( $handle ) ) {
				fclose( $handle );
			}
		}

		$this->handles = array();
	}

	/** @param array<string,mixed> $state */
	private function route( string $statement, string $out_dir, array &$state ): void {
		if ( preg_match( '/^CREATE\s+TABLE\s+(?:IF\s+NOT\s+EXISTS\s+)?(`(?:[^`]|``)+`|[A-Za-z0-9_$]+)/i', $statement, $m ) ) {
			$table = $this->real_name( $this->unquote( $m[1] ), $state );
			$sql   = 'CREATE TABLE `' . str_replace( '`', '``', $table ) . '`' . substr( $statement, strlen( $m[0] ) );
			$info  = $this->table( $state, $table );

			file_put_contents( $out_dir . 'schema/' . $info['slug'] . '.schema.sql', $sql . ";\n" );
			$state['tables'][ $table ]['schema'] = true;
			return;
		}

		if ( preg_match( '/^(INSERT|REPLACE)(?:\s+(?:LOW_PRIORITY|DELAYED|HIGH_PRIORITY|IGNORE))*\s+INTO\s+(`(?:[^`]|``)+`|[A-Za-z0-9_$]+)|^(UPDATE)(?:\s+(?:LOW_PRIORITY|IGNORE))*\s+(`(?:[^`]|``)+`|[A-Za-z0-9_$]+)\s+SET\b|^(DELETE)(?:\s+(?:LOW_PRIORITY|QUICK|IGNORE))*\s+FROM\s+(`(?:[^`]|``)+`|[A-Za-z0-9_$]+)/i', $statement, $m ) ) {
			$verb  = strtoupper( $m[1] . ( $m[3] ?? '' ) . ( $m[5] ?? '' ) );
			$table = $this->real_name( $this->unquote( (string) ( '' !== $m[2] ? $m[2] : ( '' !== ( $m[4] ?? '' ) ? $m[4] : $m[6] ) ) ), $state );
			$head  = array(
				'INSERT'  => 'INSERT IGNORE INTO',
				'REPLACE' => 'REPLACE INTO',
				'UPDATE'  => 'UPDATE',
				'DELETE'  => 'DELETE FROM',
			)[ $verb ];
			$rest  = 'UPDATE' === $verb ? ' SET' . substr( $statement, strlen( $m[0] ) ) : substr( $statement, strlen( $m[0] ) );

			if ( preg_match( '/[(,\s]0x[,)\s]/i', $rest ) ) {
				$rest = self::fix_empty_hex( $rest );
			}
			$sql  = $head . ' `' . str_replace( '`', '``', $table ) . '`' . $rest;
			$info = $this->table( $state, $table );

			if ( ! isset( $this->handles[ $table ] ) ) {
				$this->handles[ $table ] = fopen( $out_dir . 'data/' . $info['slug'] . '.data.sql', 'ab' );
			}

			fwrite( $this->handles[ $table ], $sql . ";\n" );
			++$state['tables'][ $table ]['inserts'];

			if ( 'UPDATE' !== $verb && 'DELETE' !== $verb && preg_match( '/options$/i', $table ) ) {
				$this->find_urls( $statement, $state );
			}

			return;
		}

		if ( preg_match( '/^(?:CREATE\s+(?:OR\s+REPLACE\s+)?(?:ALGORITHM\s*=\s*\w+\s+)?(?:DEFINER\s*=\s*\S+\s+)?(?:SQL\s+SECURITY\s+\w+\s+)?(?:VIEW|TRIGGER|PROCEDURE|FUNCTION|EVENT))/i', $statement ) ) {
			++$state['skipped'];
		}

		// SET, LOCK/UNLOCK, DROP TABLE, ALTER … KEYS, transactions: not needed.
	}

	/**
	 * @param array<string,mixed> $state
	 * @return array{slug:string,schema:bool,inserts:int}
	 */
	private function table( array &$state, string $table ): array {
		if ( ! isset( $state['tables'][ $table ] ) ) {
			$state['tables'][ $table ] = array(
				'slug'    => Sanitizer::table_slug( $table ),
				'schema'  => false,
				'inserts' => 0,
			);
		}

		return $state['tables'][ $table ];
	}

	/**
	 * All-in-One WP Migration dumps an empty binary value as a bare "0x",
	 * which MySQL reads as a column name ("Unknown column '0x'"). Outside
	 * quoted strings, a lone 0x value becomes ''.
	 */
	public static function fix_empty_hex( string $sql ): string {
		$out    = '';
		$quote  = '';
		$length = strlen( $sql );

		for ( $i = 0; $i < $length; $i++ ) {
			$ch = $sql[ $i ];

			if ( '' !== $quote ) {
				$out .= $ch;

				if ( '\\' === $ch && $i + 1 < $length ) {
					$out .= $sql[ ++$i ];
				} elseif ( $ch === $quote ) {
					$quote = '';
				}
				continue;
			}

			if ( "'" === $ch || '"' === $ch || '`' === $ch ) {
				$quote = $ch;
				$out  .= $ch;
				continue;
			}

			if ( '0' === $ch && $i + 2 <= $length && ( 'x' === $sql[ $i + 1 ] || 'X' === $sql[ $i + 1 ] )
				&& ( 0 === $i || false !== strpos( "(, \t\n\r", $sql[ $i - 1 ] ) )
				&& ( $i + 2 === $length || false !== strpos( ",) \t\n\r", $sql[ $i + 2 ] ) ) ) {
				$out .= "''";
				++$i;
				continue;
			}

			$out .= $ch;
		}

		return $out;
	}

	/** @param array<string,mixed> $state */
	private function real_name( string $table, array $state ): string {
		return isset( $state['rename'][ $table ] ) ? (string) $state['rename'][ $table ] : $table;
	}

	private function unquote( string $name ): string {
		return '`' === substr( $name, 0, 1 ) ? str_replace( '``', '`', substr( $name, 1, -1 ) ) : $name;
	}

	/**
	 * Note the siteurl / home option values from an options-table INSERT.
	 *
	 * @param array<string,mixed> $state
	 */
	private function find_urls( string $statement, array &$state ): void {
		foreach ( array( 'siteurl', 'home' ) as $option ) {
			if ( isset( $state['found'][ $option ] ) || false === stripos( $statement, $option ) ) {
				continue;
			}

			if ( preg_match( '/([\'"])' . $option . '\1\s*,\s*([\'"])((?:\\\\.|(?!\2).)*)\2/s', $statement, $m ) ) {
				$state['found'][ $option ] = untrailingslashit( stripcslashes( $m[3] ) );
			}
		}
	}

	/**
	 * The table prefix of a set of WordPress tables: the part before
	 * "options" of the options table that has a matching posts table.
	 *
	 * @param string[] $tables
	 */
	public static function detect_prefix( array $tables ): string {
		foreach ( $tables as $table ) {
			if ( preg_match( '/^(.*)options$/i', $table, $m ) && in_array( $m[1] . 'posts', $tables, true ) ) {
				return $m[1];
			}
		}

		return '';
	}
}

// phpcs:enable WordPress.WP.AlternativeFunctions
