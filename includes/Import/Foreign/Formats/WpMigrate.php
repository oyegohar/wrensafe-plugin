<?php
/**
 * WP Migrate (Lite and Pro, by WP Engine): a database export (.sql or
 * .sql.gz, starting "# WordPress MySQL database migration"), or a full-site
 * export .zip with database.sql, wpmigrate-export.json and the files under
 * files/wp-content/.
 *
 * @package WPBP\Import\Foreign
 */

namespace WPBP\Import\Foreign\Formats;

use WPBP\Import\Foreign\AbstractFormat;

defined( 'ABSPATH' ) || exit;

// Exception messages reach the browser as JSON shown with jQuery .text().
// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped

class WpMigrate extends AbstractFormat {

	private const SQL_SIGNATURE = '# WordPress MySQL database migration';

	public function key(): string {
		return 'wp-migrate';
	}

	public function name(): string {
		return 'WP Migrate';
	}

	public function matches( array $files ): bool {
		foreach ( $files as $name => $path ) {
			$ext = self::ext( $name );

			if ( 'zip' === $ext && null !== self::zip_entry( $path, 'wpmigrate-export.json' ) ) {
				return true;
			}

			if ( ( 'sql' === $ext || 'sql.gz' === $ext ) && 0 === strpos( ltrim( self::sql_head( $path, 'sql.gz' === $ext ) ), self::SQL_SIGNATURE ) ) {
				return true;
			}
		}

		return false;
	}

	public function describe( array $files ): array {
		$sources  = array();
		$meta     = array();
		$warnings = array();

		foreach ( $files as $name => $path ) {
			$ext = self::ext( $name );

			if ( 'zip' === $ext ) {
				$info = json_decode( (string) self::zip_entry( $path, 'wpmigrate-export.json' ), true );

				if ( ! is_array( $info ) ) {
					continue;
				}

				$url       = untrailingslashit( (string) ( $info['domain'] ?? '' ) );
				$meta     += array_filter(
					array(
						'site_url'    => $url,
						'home_url'    => $url,
						'wp_version'  => (string) ( $info['wpVersion'] ?? '' ),
						'php_version' => (string) ( $info['services']['php']['version'] ?? '' ),
					)
				);
				$sources[] = array(
					'type' => 'zip',
					'file' => $path,
					'base' => 'wpmigrate',
				);
			} elseif ( 'sql' === $ext || 'sql.gz' === $ext ) {
				$sources[] = array(
					'type' => 'sql' === $ext ? 'sql' : 'sqlgz',
					'file' => $path,
				);
			}
		}

		if ( empty( $sources ) ) {
			throw new \RuntimeException( __( 'No WP Migrate export was found.', 'wrensafe' ) );
		}

		if ( 1 === count( $sources ) && 'zip' !== $sources[0]['type'] ) {
			$warnings[] = __( 'This WP Migrate export holds only the database. Plugins, themes and media on this site stay as they are.', 'wrensafe' );
		}

		return array(
			'sources'  => $sources,
			'meta'     => $meta,
			'warnings' => $warnings,
		);
	}

	public function classify( array $source, string $path ): array {
		if ( 'database.sql' === $path ) {
			return array( 'sql' );
		}

		if ( 'database.sql.gz' === $path ) {
			return array( 'sqlgz' );
		}

		if ( 0 === strpos( $path, 'files/wp-content/' ) ) {
			return $this->content_target( substr( $path, 17 ) );
		}

		if ( 0 === strpos( $path, 'files/' ) ) {
			return $this->root_target( substr( $path, 6 ) );
		}

		return array( 'skip' );
	}
}

// phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped
