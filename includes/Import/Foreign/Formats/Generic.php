<?php
/**
 * Any other backup: a plain .sql / .sql.gz dump, and/or a .zip or .tar(.gz)
 * of the site or its wp-content folder (hosting control panels, phpMyAdmin,
 * manual copies, and plugins without a dedicated importer).
 *
 * @package WPBP\Import\Foreign
 */

namespace WPBP\Import\Foreign\Formats;

use WPBP\Import\Foreign\AbstractFormat;
use WPBP\Import\Foreign\ZipView;

defined( 'ABSPATH' ) || exit;

// Small metadata entries are read with native handles: WP_Filesystem has no
// streaming API. Paths are uploaded import files in protected storage.
// phpcs:disable WordPress.WP.AlternativeFunctions

class Generic extends AbstractFormat {

	public function key(): string {
		return 'generic';
	}

	public function name(): string {
		return __( 'Database dump or site archive', 'wrensafe' );
	}

	public function note(): string {
		return __( 'Converted from a database dump or site archive', 'wrensafe' );
	}

	public function matches( array $files ): bool {
		foreach ( array_keys( $files ) as $name ) {
			if ( in_array( self::ext( $name ), array( 'sql', 'sql.gz', 'zip', 'tar', 'tar.gz', 'tgz' ), true ) ) {
				return true;
			}
		}

		return false;
	}

	public function describe( array $files ): array {
		$sources = array();
		$meta    = array();

		foreach ( $files as $name => $path ) {
			$ext = self::ext( $name );

			if ( 'sql' === $ext || 'sql.gz' === $ext ) {
				$meta      = $meta + self::sql_header_meta( self::sql_head( $path, 'sql.gz' === $ext ) );
				$sources[] = array(
					'type' => 'sql' === $ext ? 'sql' : 'sqlgz',
					'file' => $path,
				);
			} elseif ( in_array( $ext, array( 'zip', 'tar', 'tar.gz', 'tgz' ), true ) ) {
				$sources[] = array(
					'type' => 'zip' === $ext ? 'zip' : ( 'tar' === $ext ? 'tar' : 'tgz' ),
					'file' => $path,
					'base' => 'auto',
				);
			}
		}

		// Say now, not after a long conversion, when a .zip holds nothing of a site.
		$usable = false;

		foreach ( $sources as $source ) {
			if ( 'zip' !== $source['type'] || $this->zip_has_site_content( $source['file'] ) ) {
				$usable = true;
				break;
			}
		}

		if ( ! $usable ) {
			throw new \RuntimeException( esc_html__( 'No WordPress site files (a wp-content, plugins, themes or uploads folder) and no database dump were found in these files.', 'wrensafe' ) );
		}

		return array(
			'sources'  => $sources,
			'meta'     => $meta,
			'warnings' => array( __( 'This backup was not made by a plugin Wrensafe recognises. Files inside a wp-content folder and any .sql database dump will be imported; please review the summary carefully.', 'wrensafe' ) ),
		);
	}

	/** Whether any entry of a zip would be imported (reads only the zip's index). */
	private function zip_has_site_content( string $path ): bool {
		self::require_zip();

		$zip = ZipView::open( $path );

		if ( null === $zip ) {
			throw new \RuntimeException( esc_html__( 'The .zip file could not be opened. It may be damaged or incomplete.', 'wrensafe' ) );
		}

		$found = false;
		$count = $zip->count();

		for ( $i = 0; $i < $count && ! $found; $i++ ) {
			$found = 'skip' !== $this->auto_target( $zip->name( $i ) )[0];
		}

		$zip->close();

		return $found;
	}
}

// phpcs:enable WordPress.WP.AlternativeFunctions
