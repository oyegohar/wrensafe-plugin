<?php
/**
 * Jetpack VaultPress Backup download: a .tar.gz with wp-content/ and an
 * sql/ folder holding one file per table ("wp_posts.sql"), sometimes with
 * a "-updates" file per table that must be applied after the table's main
 * file. The archive may put everything under one top-level folder.
 *
 * @package WPBP\Import\Foreign
 */

namespace WPBP\Import\Foreign\Formats;

use WPBP\Import\Foreign\AbstractFormat;
use WPBP\Import\Foreign\TarReader;

defined( 'ABSPATH' ) || exit;

// Exception messages reach the browser as JSON shown with jQuery .text().
// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped

class Jetpack extends AbstractFormat {

	private const SQL_PATTERN = '#(?:^|/)sql/[^/]+\.sql(?:\.gz)?$#i';

	public function key(): string {
		return 'jetpack';
	}

	public function name(): string {
		return 'Jetpack VaultPress Backup';
	}

	public function matches( array $files ): bool {
		return null !== $this->archive( $files );
	}

	public function describe( array $files ): array {
		$path = $this->archive( $files );

		if ( null === $path ) {
			throw new \RuntimeException( __( 'No Jetpack VaultPress Backup download was found.', 'wrensafe' ) );
		}

		return array(
			'sources'  => array(
				array(
					'type' => 'tar' === self::ext( (string) array_search( $path, $files, true ) ) ? 'tar' : 'tgz',
					'file' => $path,
					'base' => 'jetpack',
				),
			),
			'meta'     => array(),
			'warnings' => array(),
		);
	}

	public function classify( array $source, string $path ): array {
		if ( preg_match( self::SQL_PATTERN, $path ) ) {
			return array( preg_match( '/\.gz$/i', $path ) ? 'sqlgz' : 'sql' );
		}

		// wp-content/, possibly under a top-level folder; site-root files are not restored.
		$at = strpos( '/' . $path, '/wp-content/' );

		return false === $at ? array( 'skip' ) : $this->content_target( substr( $path, $at + 11 ) );
	}

	/** Each table's main file before its "-updates" file; otherwise as found. */
	public function sql_order( array $names ): array {
		$keys = array_keys( $names );

		usort(
			$keys,
			static function ( $a, $b ) use ( $names ) {
				$ua = (int) preg_match( '/-updates\.sql(?:\.gz)?$/i', (string) $names[ $a ] );
				$ub = (int) preg_match( '/-updates\.sql(?:\.gz)?$/i', (string) $names[ $b ] );

				return $ua === $ub ? $a <=> $b : $ua <=> $ub;
			}
		);

		return $keys;
	}

	/** @param array<string,string> $files */
	private function archive( array $files ): ?string {
		foreach ( $files as $name => $path ) {
			$ext = self::ext( $name );

			if ( ! in_array( $ext, array( 'tar.gz', 'tgz', 'tar' ), true ) ) {
				continue;
			}

			// The download is named {site}-{parts}-{date}.tar.gz, parts such as "sqls" and "contents".
			if ( preg_match( '/-(?:sqls|contents|themes|plugins|uploads)-/i', $name ) || $this->has_sql_folder( $path, 'tar' !== $ext ) ) {
				return $path;
			}
		}

		return null;
	}

	/**
	 * Whether the first entries of a tar(.gz) include sql/{table}.sql. A
	 * compressed archive is read from the start, so this stops after a few
	 * seconds (the analysis runs in one request).
	 */
	private function has_sql_folder( string $path, bool $gzip ): bool {
		$reader   = new TarReader();
		$found    = false;
		$deadline = microtime( true ) + 4;

		try {
			$reader->open( ( $gzip ? 'compress.zlib://' : '' ) . $path, 0 );

			for ( $i = 0; $i < 5000 && ! $found; $i++ ) {
				$entry = $reader->next();

				if ( null === $entry || microtime( true ) > $deadline ) {
					break;
				}

				$found = (bool) preg_match( self::SQL_PATTERN, $entry['path'] );
			}
		} catch ( \Throwable $e ) {
			$found = false;
		}

		$reader->close();

		return $found;
	}
}

// phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped
