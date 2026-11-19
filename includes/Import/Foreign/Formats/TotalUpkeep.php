<?php
/**
 * Total Upkeep (BoldGrid Backup): one .zip of the whole WordPress folder
 * with the database dump ("{database}.{date}.sql", mysqldump-php) and a
 * log file at the top level.
 *
 * @package WPBP\Import\Foreign
 */

namespace WPBP\Import\Foreign\Formats;

use WPBP\Import\Foreign\AbstractFormat;

defined( 'ABSPATH' ) || exit;

class TotalUpkeep extends AbstractFormat {

	public function key(): string {
		return 'total-upkeep';
	}

	public function name(): string {
		return 'Total Upkeep';
	}

	public function matches( array $files ): bool {
		return null !== $this->archive( $files );
	}

	public function describe( array $files ): array {
		$path = $this->archive( $files );

		if ( null === $path ) {
			throw new \RuntimeException( esc_html__( 'No Total Upkeep archive was found.', 'wrensafe' ) );
		}

		self::require_zip();

		$dump = self::zip_find( $path, '#^[^/]+\.sql$#i' );

		if ( null === $dump ) {
			throw new \RuntimeException( esc_html__( 'This Total Upkeep backup has no database dump. Make a full backup in Total Upkeep, then upload that one.', 'wrensafe' ) );
		}

		// Total Upkeep Premium can encrypt the dump; a plain one starts with SQL text.
		$head = $this->entry_head( $path, $dump );

		if ( ! preg_match( '#^\s*(?:--|/\*|\#|CREATE|DROP|SET|INSERT)#i', $head ) ) {
			throw new \RuntimeException( esc_html__( 'This Total Upkeep backup\'s database is encrypted. Turn off database encryption in Total Upkeep, make a new backup, then upload that one.', 'wrensafe' ) );
		}

		return array(
			'sources'  => array(
				array(
					'type' => 'zip',
					'file' => $path,
					'base' => 'root',
				),
			),
			'meta'     => array(),
			'warnings' => array(),
		);
	}

	public function classify( array $source, string $path ): array {
		if ( false === strpos( $path, '/' ) ) {
			return preg_match( '/\.sql$/i', $path ) ? array( 'sql' ) : array( 'skip' );
		}

		return $this->root_target( $path );
	}

	/** @param array<string,string> $files */
	private function archive( array $files ): ?string {
		foreach ( $files as $name => $path ) {
			if ( 'zip' === self::ext( $name ) && ( preg_match( '/^boldgrid-backup-.+\.zip$/i', $name ) || null !== self::zip_find( $path, '#^boldgrid-backup-[^/]+\.log$#' ) ) ) {
				return $path;
			}
		}

		return null;
	}

	/** The first bytes of a zip entry. */
	private function entry_head( string $zip_path, string $entry ): string {
		if ( ! class_exists( 'ZipArchive' ) ) {
			return '';
		}

		$zip = new \ZipArchive();

		if ( true !== $zip->open( $zip_path ) ) {
			return '';
		}

		$stream = $zip->getStream( $entry );
		$head   = false === $stream ? '' : (string) fread( $stream, 256 ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fread -- reading a zip entry stream.

		if ( false !== $stream ) {
			fclose( $stream ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		}

		$zip->close();

		return $head;
	}
}
