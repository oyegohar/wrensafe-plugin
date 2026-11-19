<?php
/**
 * All-in-One WP Migration (.wpress): one file, paths relative to wp-content,
 * database.sql with SERVMASK_PREFIX_ in place of the table prefix, and site
 * details in package.json.
 *
 * @package WPBP\Import\Foreign
 */

namespace WPBP\Import\Foreign\Formats;

use WPBP\Import\Foreign\AbstractFormat;
use WPBP\Import\Foreign\WpressReader;

defined( 'ABSPATH' ) || exit;

// Small metadata entries are read with native handles: WP_Filesystem has no
// streaming API. Paths are uploaded import files in protected storage.
// phpcs:disable WordPress.WP.AlternativeFunctions

class AllInOneWpMigration extends AbstractFormat {

	public function key(): string {
		return 'all-in-one-wp-migration';
	}

	public function name(): string {
		return 'All-in-One WP Migration';
	}

	public function matches( array $files ): bool {
		foreach ( array_keys( $files ) as $name ) {
			if ( 'wpress' === self::ext( $name ) ) {
				return true;
			}
		}

		return false;
	}

	public function describe( array $files ): array {
		$file = '';
		foreach ( $files as $name => $path ) {
			if ( 'wpress' === self::ext( $name ) ) {
				$file = $path;
				break;
			}
		}

		$reader    = new WpressReader();
		$package   = null;
		$multisite = false;
		$reader->open( $file, 0 );

		for ( $i = 0; $i < 50; $i++ ) {
			$entry = $reader->next();

			if ( null === $entry ) {
				break;
			}

			if ( 'multisite.json' === $entry['path'] ) {
				$multisite = true;
			}

			if ( 'package.json' === $entry['path'] && $entry['size'] < 1048576 ) {
				$tmp = fopen( 'php://temp', 'w+b' );
				$reader->copy_to( $tmp );
				rewind( $tmp );
				$package = json_decode( (string) stream_get_contents( $tmp ), true );
				fclose( $tmp );
				continue;
			}

			$reader->skip();
		}

		$reader->close();

		if ( ! is_array( $package ) ) {
			throw new \RuntimeException( esc_html__( 'This .wpress file has no package.json, so it is not a complete All-in-One WP Migration backup.', 'wrensafe' ) );
		}

		if ( ! empty( $package['Encrypted'] ) ) {
			throw new \RuntimeException( esc_html__( 'This .wpress backup is password-protected. Export it again in All-in-One WP Migration without a password, then import it here.', 'wrensafe' ) );
		}

		if ( ! empty( $package['Compression']['Enabled'] ) || ( isset( $package['Compression'] ) && true === $package['Compression'] ) ) {
			throw new \RuntimeException( esc_html__( 'This .wpress backup is compressed, which Wrensafe cannot read yet. Export it again in All-in-One WP Migration without compression, then import it here.', 'wrensafe' ) );
		}

		$uploads = (string) ( $package['Uploads'] ?? '' );

		return array(
			'sources'  => array(
				array(
					'type' => 'wpress',
					'file' => $file,
					'base' => 'content',
				),
			),
			'meta'     => array(
				'site_url'        => (string) ( $package['SiteURL'] ?? '' ),
				'home_url'        => (string) ( $package['HomeURL'] ?? '' ),
				'prefix'          => 'SERVMASK_PREFIX_',
				'wp_version'      => (string) ( $package['WordPress']['Version'] ?? '' ),
				'php_version'     => (string) ( $package['PHP']['Version'] ?? '' ),
				'content_dir'     => str_replace( '\\', '/', (string) ( $package['WordPress']['Content'] ?? '' ) ),
				'uploads_basedir' => str_replace( '\\', '/', $uploads ),
				'uploads_baseurl' => (string) ( $package['UploadsURL'] ?? '' ),
				'is_multisite'    => $multisite,
				'plugin_version'  => (string) ( $package['Plugin']['Version'] ?? '' ),
				'options'         => self::package_options( $package ),
			),
			'warnings' => array(),
		);
	}

	/**
	 * All-in-One WP Migration leaves the active plugins and theme out of its
	 * database export and keeps them in package.json (its own importer sets
	 * them afterwards). They go back into the options table here.
	 *
	 * @param array<string,mixed> $package
	 * @return array<string,string> Option name => stored value.
	 */
	private static function package_options( array $package ): array {
		$options = array();

		if ( isset( $package['Plugins'] ) && is_array( $package['Plugins'] ) ) {
			$options['active_plugins'] = serialize( array_values( array_filter( $package['Plugins'], 'is_string' ) ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize -- the options table stores arrays serialized.
		}

		foreach ( array(
			'template'   => 'Template',
			'stylesheet' => 'Stylesheet',
		) as $option => $key ) {
			if ( isset( $package[ $key ] ) && is_string( $package[ $key ] ) && '' !== $package[ $key ] ) {
				$options[ $option ] = $package[ $key ];
			}
		}

		return $options;
	}

	public function classify( array $source, string $path ): array {
		if ( 'database.sql' === $path ) {
			return array( 'sql' );
		}

		if ( in_array( $path, array( 'package.json', 'multisite.json', 'blogs.json', 'filemap.list' ), true ) ) {
			return array( 'skip' );
		}

		return $this->content_target( $path );
	}
}

// phpcs:enable WordPress.WP.AlternativeFunctions
