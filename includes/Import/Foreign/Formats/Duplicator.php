<?php
/**
 * Duplicator / Duplicator Pro: …_archive.daf (DupArchive) or …_archive.zip,
 * paths relative to the WordPress folder. The database is
 * one .sql.gz per table under dup-installer/dup_descriptors_{id}/db_dumps/
 * (Duplicator 5) or dup-installer/dup-database__{hash}.sql (older versions).
 *
 * @package WPBP\Import\Foreign
 */

namespace WPBP\Import\Foreign\Formats;

use WPBP\Import\Foreign\AbstractFormat;
use WPBP\Import\Foreign\DafReader;

defined( 'ABSPATH' ) || exit;

// Small metadata entries are read with native handles: WP_Filesystem has no
// streaming API. Paths are uploaded import files in protected storage.
// phpcs:disable WordPress.WP.AlternativeFunctions

class Duplicator extends AbstractFormat {

	public function key(): string {
		return 'duplicator';
	}

	public function name(): string {
		return 'Duplicator';
	}

	public function matches( array $files ): bool {
		foreach ( array_keys( $files ) as $name ) {
			if ( preg_match( '/_archive\.(daf|zip)$/i', $name ) ) {
				return true;
			}
		}

		return false;
	}

	public function describe( array $files ): array {
		foreach ( $files as $name => $path ) {
			if ( preg_match( '/_archive\.(daf|zip)$/i', $name, $m ) ) {
				$type = strtolower( $m[1] );
				$meta = 'zip' === $type ? $this->zip_meta( $path ) : $this->daf_meta( $path );

				return array(
					'sources'  => array(
						array(
							'type' => $type,
							'file' => $path,
							'base' => 'root',
						),
					),
					'meta'     => $meta,
					'warnings' => array(),
				);
			}
		}

		throw new \RuntimeException( esc_html__( 'No Duplicator archive (…_archive.daf or …_archive.zip) was found.', 'wrensafe' ) );
	}

	public function classify( array $source, string $path ): array {
		if ( preg_match( '#^dup-installer/(?:[^/]+/)?db_dumps/[^/]+\.sql\.gz$#', $path ) ) {
			return array( 'sqlgz' );
		}

		if ( preg_match( '#^dup-installer/dup-database__[^/]+\.sql$#', $path ) ) {
			return array( 'sql' );
		}

		return $this->root_target( $path );
	}

	/** Site details from the package descriptor (archive.txt). */
	private static function descriptor_meta( string $json ): array {
		$data = json_decode( $json, true );

		if ( ! is_array( $data ) ) {
			return array();
		}

		$meta = array(
			'prefix'      => (string) ( $data['wp_tableprefix'] ?? '' ),
			'wp_version'  => (string) ( $data['version_wp'] ?? '' ),
			'php_version' => (string) ( $data['version_php'] ?? '' ),
		);

		// Duplicator 1.5+ keeps the URLs under wpInfo.configs.realValues; older
		// packages had url_old.
		$real = (array) ( $data['wpInfo']['configs']['realValues'] ?? array() );

		foreach ( array(
			'home_url'        => array( $real['homeUrl'] ?? '', $data['subsites'][0]['fullHomeUrl'] ?? '', $data['url_old'] ?? '' ),
			'site_url'        => array( $real['siteUrl'] ?? '', $data['subsites'][0]['fullSiteUrl'] ?? '', $data['url_old'] ?? '' ),
			'uploads_baseurl' => array( $real['uploadBaseUrl'] ?? '', $data['subsites'][0]['fullUploadUrl'] ?? '' ),
		) as $key => $candidates ) {
			foreach ( $candidates as $value ) {
				if ( is_string( $value ) && '' !== $value ) {
					$meta[ $key ] = untrailingslashit( $value );
					break;
				}
			}
		}

		if ( ! empty( $data['created'] ) && preg_match( '/^(\d{4}-\d{2}-\d{2}) (\d{2}:\d{2}:\d{2})$/', (string) $data['created'], $m ) ) {
			$meta['created_at'] = $m[1] . 'T' . $m[2];
		}

		if ( ! empty( $data['wpInfo']['is_multisite'] ) || ! empty( $data['mu_mode'] ) ) {
			$meta['is_multisite'] = true;
		}

		return array_filter( $meta, static fn( $v ) => '' !== $v );
	}

	private function zip_meta( string $path ): array {
		self::require_zip();

		$zip = new \ZipArchive();

		if ( true !== $zip->open( $path ) ) {
			throw new \RuntimeException( esc_html__( 'The Duplicator .zip archive could not be opened.', 'wrensafe' ) );
		}

		// phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- ZipArchive property.
		for ( $i = 0; $i < $zip->numFiles; $i++ ) {
			$name = (string) $zip->getNameIndex( $i );

			if ( preg_match( '#^dup-installer/(?:dup_descriptors_[^/]+/archive\.txt|dup-archive__[^/]+\.txt)$#', $name ) ) {
				$meta = self::descriptor_meta( (string) $zip->getFromIndex( $i ) );
				$zip->close();
				return $meta;
			}
		}

		$zip->close();

		return array();
	}

	private function daf_meta( string $path ): array {
		$reader = new DafReader();
		$reader->open( $path, 0 );

		try {
			while ( null !== ( $entry = $reader->next() ) ) { // phpcs:ignore Generic.CodeAnalysis.AssignmentInCondition.FoundInWhileCondition
				if ( preg_match( '#^dup-installer/(?:dup_descriptors_[^/]+/archive\.txt|dup-archive__[^/]+\.txt)$#', $entry['path'] ) && $entry['size'] < 5242880 ) {
					$tmp = fopen( 'php://temp', 'w+b' );
					$reader->copy_to( $tmp );
					rewind( $tmp );
					$meta = self::descriptor_meta( (string) stream_get_contents( $tmp ) );
					fclose( $tmp );
					return $meta;
				}

				$reader->skip();
			}
		} finally {
			$reader->close();
		}

		return array();
	}
}

// phpcs:enable WordPress.WP.AlternativeFunctions
