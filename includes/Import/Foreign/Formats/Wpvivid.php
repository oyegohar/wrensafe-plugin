<?php
/**
 * WPvivid Backup: …_wpvivid-ID_DATE_backup_all.zip holding one zip per part
 * (db, plugin, themes, uploads, content, core), or those parts as separate
 * files; the database zip holds a .sql whose first comment lines carry the
 * site URL and table prefix.
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

class Wpvivid extends AbstractFormat {

	public function key(): string {
		return 'wpvivid';
	}

	public function name(): string {
		return 'WPvivid Backup';
	}

	public function matches( array $files ): bool {
		foreach ( array_keys( $files ) as $name ) {
			if ( false !== stripos( $name, '_wpvivid-' ) && preg_match( '/_backup_[a-z]+(?:\.part\d+)?\.zip$/i', $name ) ) {
				return true;
			}
		}

		return false;
	}

	public function describe( array $files ): array {
		$sources = array();
		$meta    = array();

		foreach ( $files as $name => $path ) {
			if ( ! preg_match( '/_backup_([a-z]+)(?:\.part\d+)?\.zip$/i', $name, $m ) ) {
				continue;
			}

			$part = strtolower( $m[1] );

			if ( 'all' === $part ) {
				self::require_zip();

				$zip = ZipView::open( $path );

				if ( null === $zip ) {
					throw new \RuntimeException( esc_html__( 'The WPvivid backup could not be opened (it may be incomplete).', 'wrensafe' ) );
				}

				$info = json_decode( (string) $zip->read( 'wpvivid_package_info.json' ), true );
				$meta = array(
					'home_url'    => (string) ( $info['home_url'] ?? '' ),
					'wp_version'  => (string) ( $info['wp_version'] ?? '' ),
					'php_version' => (string) ( $info['php_version'] ?? '' ),
				);

				$count = $zip->count();

				for ( $i = 0; $i < $count; $i++ ) {
					$inner = $zip->name( $i );

					if ( preg_match( '/_backup_([a-z]+)(?:\.part\d+)?\.zip$/i', $inner, $im ) && 'core' !== strtolower( $im[1] ) ) {
						$sources[] = array(
							'type'  => 'zip',
							'file'  => $path,
							'inner' => $inner,
							'base'  => 'content',
						);
					}
				}

				$zip->close();
				continue;
			}

			if ( 'core' === $part ) {
				continue; // WordPress core files are never restored.
			}

			$sources[] = array(
				'type' => 'zip',
				'file' => $path,
				'base' => 'content',
			);
		}

		return array(
			'sources'  => $sources,
			'meta'     => array_filter( $meta ),
			'warnings' => array(),
		);
	}

	public function classify( array $source, string $path ): array {
		if ( preg_match( '/\.sql$/i', $path ) && false === strpos( $path, '/' ) ) {
			return array( 'sql' );
		}

		if ( 'wpvivid_package_info.json' === $path ) {
			return array( 'skip' );
		}

		return $this->content_target( $path );
	}
}

// phpcs:enable WordPress.WP.AlternativeFunctions
