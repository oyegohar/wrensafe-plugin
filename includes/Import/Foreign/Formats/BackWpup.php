<?php
/**
 * BackWPup: one archive (.zip, .tar, .tar.gz, .tar.bz2) relative to the
 * WordPress folder, with the database dump (.sql / .sql.gz) and manifest.json
 * at the top level.
 *
 * @package WPBP\Import\Foreign
 */

namespace WPBP\Import\Foreign\Formats;

use WPBP\Import\Foreign\AbstractFormat;
use WPBP\Import\Foreign\TarReader;

defined( 'ABSPATH' ) || exit;

// Small metadata entries are read with native handles: WP_Filesystem has no
// streaming API. Paths are uploaded import files in protected storage.
// phpcs:disable WordPress.WP.AlternativeFunctions

class BackWpup extends AbstractFormat {

	public function key(): string {
		return 'backwpup';
	}

	public function name(): string {
		return 'BackWPup';
	}

	public function matches( array $files ): bool {
		foreach ( $files as $name => $path ) {
			$ext = self::ext( $name );

			if ( 'zip' === $ext ) {
				if ( self::zip_has( $path, 'backwpup_readme.txt' ) ) {
					return true;
				}
			} elseif ( 'tar' === $ext && null !== $this->tar_manifest( $path ) ) {
				return true;
			} elseif ( in_array( $ext, array( 'tar.gz', 'tgz', 'tar.bz2' ), true ) && preg_match( '/(?:backwpup|_FILE-DBDUMP|_DBDUMP|_FILE)/i', $name ) ) {
				return true;
			}
		}

		return false;
	}

	public function describe( array $files ): array {
		foreach ( $files as $name => $path ) {
			$ext = self::ext( $name );

			if ( ! in_array( $ext, array( 'zip', 'tar', 'tar.gz', 'tgz', 'tar.bz2' ), true ) ) {
				continue;
			}

			$manifest = null;

			if ( 'zip' === $ext ) {
				self::require_zip();
				$manifest = json_decode( (string) self::zip_entry( $path, 'manifest.json' ), true );
			} elseif ( 'tar' === $ext ) {
				$manifest = $this->tar_manifest( $path );
			}

			$info = is_array( $manifest ) ? (array) ( $manifest['blog_info'] ?? array() ) : array();

			return array(
				'sources'  => array(
					array(
						'type' => 'zip' === $ext ? 'zip' : ( 'tar' === $ext ? 'tar' : 'tgz' ),
						'file' => $path,
						'base' => 'root',
					),
				),
				'meta'     => array_filter(
					array(
						'home_url'   => (string) ( $info['url'] ?? '' ),
						'site_url'   => (string) ( $info['wpurl'] ?? '' ),
						'prefix'     => (string) ( $info['prefix'] ?? '' ),
						'wp_version' => (string) ( $info['version'] ?? '' ),
					)
				),
				'warnings' => array(),
			);
		}

		throw new \RuntimeException( esc_html__( 'No BackWPup archive was found.', 'wrensafe' ) );
	}

	public function classify( array $source, string $path ): array {
		if ( false === strpos( $path, '/' ) ) {
			if ( preg_match( '/\.sql$/i', $path ) ) {
				return array( 'sql' );
			}

			if ( preg_match( '/\.sql\.gz$/i', $path ) ) {
				return array( 'sqlgz' );
			}
		}

		return $this->root_target( $path );
	}

	/** manifest.json of a BackWPup tar, or null if the tar isn't BackWPup's. */
	private function tar_manifest( string $path ): ?array {
		$reader = new TarReader();
		$reader->open( $path, 0 );
		$found = null;
		$seen  = 0;

		try {
			while ( null !== ( $entry = $reader->next() ) && $seen < 200000 ) { // phpcs:ignore Generic.CodeAnalysis.AssignmentInCondition.FoundInWhileCondition
				++$seen;

				if ( 'manifest.json' === $entry['path'] && $entry['size'] < 1048576 ) {
					$tmp = fopen( 'php://temp', 'w+b' );
					$reader->copy_to( $tmp );
					rewind( $tmp );
					$data = json_decode( (string) stream_get_contents( $tmp ), true );
					fclose( $tmp );

					if ( is_array( $data ) && isset( $data['blog_info'] ) ) {
						$found = $data;
						break;
					}
					continue;
				}

				$reader->skip();
			}
		} finally {
			$reader->close();
		}

		return $found;
	}
}

// phpcs:enable WordPress.WP.AlternativeFunctions
