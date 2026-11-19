<?php
/**
 * UpdraftPlus: a set of files sharing one date and nonce —
 * backup_DATE_SITE_NONCE-db.gz plus -plugins / -themes / -uploads / -others /
 * -mu-plugins zips (large sites add numbered parts: -uploads2.zip …).
 *
 * @package WPBP\Import\Foreign
 */

namespace WPBP\Import\Foreign\Formats;

use WPBP\Import\Foreign\AbstractFormat;

defined( 'ABSPATH' ) || exit;

// Small metadata entries are read with native handles: WP_Filesystem has no
// streaming API. Paths are uploaded import files in protected storage.
// phpcs:disable WordPress.WP.AlternativeFunctions

class UpdraftPlus extends AbstractFormat {

	private const PATTERN = '/^backup_(\d{4}-\d{2}-\d{2}-\d{4})_.*_([0-9a-f]{12})-(db|plugins|themes|uploads|others|mu-plugins)(\d*)\.(zip|gz|sql|crypt|gz\.crypt)$/i';

	public function key(): string {
		return 'updraftplus';
	}

	public function name(): string {
		return 'UpdraftPlus';
	}

	public function matches( array $files ): bool {
		foreach ( array_keys( $files ) as $name ) {
			if ( preg_match( self::PATTERN, $name ) ) {
				return true;
			}
		}

		return false;
	}

	public function describe( array $files ): array {
		$sets = array();

		foreach ( $files as $name => $path ) {
			if ( preg_match( self::PATTERN, $name, $m ) ) {
				$sets[ $m[1] . '_' . $m[2] ][] = array( $name, $path, strtolower( $m[3] ), strtolower( $m[5] ) );
			}
		}

		krsort( $sets );
		$set      = reset( $sets );
		$warnings = array();

		if ( count( $sets ) > 1 ) {
			$warnings[] = __( 'Files from more than one UpdraftPlus backup were uploaded; only the newest backup is used.', 'wrensafe' );
		}

		$sources = array();
		$meta    = array();
		$parts   = array();

		foreach ( $set as list( $name, $path, $part, $ext ) ) {
			$parts[ $part ] = true;

			if ( 'db' === $part ) {
				if ( false !== strpos( $ext, 'crypt' ) ) {
					throw new \RuntimeException( esc_html__( 'This UpdraftPlus database backup is encrypted (.crypt). Decrypt it in UpdraftPlus first (Existing backups → database → Download), or create the backup without database encryption.', 'wrensafe' ) );
				}

				$gzip      = 'gz' === $ext;
				$meta      = self::sql_header_meta( self::sql_head( $path, $gzip ) );
				$sources[] = array(
					'type' => $gzip ? 'sqlgz' : 'sql',
					'file' => $path,
				);
				continue;
			}

			$sources[] = array(
				'type' => 'zip',
				'file' => $path,
				'base' => 'content',
			);
		}

		if ( empty( $parts['db'] ) ) {
			$warnings[] = __( 'No database file (…-db.gz) was uploaded, so only files will be restored.', 'wrensafe' );
		}

		foreach ( array( 'plugins', 'themes', 'uploads' ) as $part ) {
			if ( empty( $parts[ $part ] ) ) {
				/* translators: %s: backup part such as uploads */
				$warnings[] = sprintf( __( 'No %s zip was uploaded; that part of the site will be left as it is.', 'wrensafe' ), $part );
			}
		}

		return array(
			'sources'  => $sources,
			'meta'     => $meta,
			'warnings' => $warnings,
		);
	}
}

// phpcs:enable WordPress.WP.AlternativeFunctions
