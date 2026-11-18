<?php
/**
 * EXPERIMENTAL All-in-One WP Migration (.wpress) import adapter.
 *
 * Clean-room implementation: no code from All-in-One WP Migration is used or
 * referenced. It reads the publicly documented block structure of .wpress
 * archives — a sequence of fixed-size entry headers (255-byte name,
 * 14-byte size, 12-byte mtime, 4096-byte path, all null-padded) each
 * followed by the raw file content, terminated by an all-zero header block.
 * Every read is defensive: implausible headers abort the scan and the file
 * is reported as unsupported rather than guessed at.
 *
 * Restore is intentionally DISABLED (can_restore() = false) until this
 * adapter has been validated against real user-owned sample files — preview
 * only, clearly labelled Experimental. It must never fake a restore success
 * or damage a site with a partial import.
 *
 * @package WPBP\Import\Adapters
 */

namespace WPBP\Import\Adapters;

defined( 'ABSPATH' ) || exit;

// Backup archives and upload chunks are streamed in bounded blocks with native
// file handles (fopen/fread/fwrite/rename): WP_Filesystem has no streaming API.
// Every path is inside Wrensafe's protected storage directory, a PHP upload
// temp file, or validated by WPBP\Security\PathValidator.
// phpcs:disable WordPress.WP.AlternativeFunctions

class WpressAdapter implements ImportAdapterInterface {

	private const NAME_BYTES   = 255;
	private const SIZE_BYTES   = 14;
	private const MTIME_BYTES  = 12;
	private const PATH_BYTES   = 4096;
	private const HEADER_BYTES = self::NAME_BYTES + self::SIZE_BYTES + self::MTIME_BYTES + self::PATH_BYTES; // 4377.

	/** Stop scanning after this many entries — inspection, not extraction. */
	private const MAX_ENTRIES = 20000;

	/** Only metadata files smaller than this are ever read into memory. */
	private const MAX_META_CONTENT = 1048576; // 1 MB.

	public function key(): string {
		return 'wpress';
	}

	public function label(): string {
		return __( 'All-in-One WP Migration Backup (.wpress) — Experimental', 'wrensafe' );
	}

	public function can_restore(): bool {
		return false; // Experimental: preview only until validated on real samples.
	}

	public function supports( string $file_path, string $extension ): bool {
		return 'wpress' === strtolower( $extension );
	}

	/**
	 * Walk the block headers (seeking past each entry's content, so even a
	 * multi-GB archive scans in milliseconds) and collect structural facts.
	 *
	 * @return array|\WP_Error
	 */
	public function inspect( string $file_path ) {
		clearstatcache( true, $file_path );

		if ( ! file_exists( $file_path ) || ! is_readable( $file_path ) ) {
			return new \WP_Error( 'wpbp_wpress_unreadable', __( 'The .wpress file could not be read.', 'wrensafe' ) );
		}

		$total_size = (int) filesize( $file_path );

		if ( $total_size < self::HEADER_BYTES ) {
			return new \WP_Error( 'wpbp_wpress_unsupported', $this->unsupported_message() );
		}

		$handle = fopen( $file_path, 'rb' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen

		if ( false === $handle ) {
			return new \WP_Error( 'wpbp_wpress_unreadable', __( 'The .wpress file could not be opened.', 'wrensafe' ) );
		}

		$facts = array(
			'entries'       => 0,
			'total_content' => 0,
			'has_database'  => false,
			'has_files'     => false,
			'package_json'  => null,
			'sample_names'  => array(),
		);

		try {
			while ( $facts['entries'] < self::MAX_ENTRIES ) {
				$header = fread( $handle, self::HEADER_BYTES );

				if ( false === $header || strlen( $header ) < self::HEADER_BYTES ) {
					break; // Clean EOF or truncated file — stop either way.
				}

				// The archive terminator is an all-zero header block.
				if ( '' === trim( $header, "\0" ) ) {
					break;
				}

				$name = rtrim( substr( $header, 0, self::NAME_BYTES ), "\0" );
				$size = rtrim( substr( $header, self::NAME_BYTES, self::SIZE_BYTES ), "\0" );
				$path = rtrim( substr( $header, self::NAME_BYTES + self::SIZE_BYTES + self::MTIME_BYTES, self::PATH_BYTES ), "\0" );

				// Plausibility guard: a real header has a printable name and a
				// purely numeric size. Anything else means this isn't a layout
				// we can safely read — report unsupported, never guess.
				if ( '' === $name || ! ctype_digit( $size ) || preg_match( '/[^\x20-\x7E]/', $name ) ) {
					fclose( $handle );
					return new \WP_Error( 'wpbp_wpress_unsupported', $this->unsupported_message() );
				}

				$content_size = (int) $size;
				$entry_start  = ftell( $handle );

				if ( 'database.sql' === $name ) {
					$facts['has_database'] = true;
				} elseif ( 'package.json' === $name && $content_size > 0 && $content_size <= self::MAX_META_CONTENT ) {
					$json = fread( $handle, $content_size );
					if ( is_string( $json ) ) {
						$decoded = json_decode( $json, true );
						if ( is_array( $decoded ) ) {
							$facts['package_json'] = $decoded;
						}
					}
					fseek( $handle, $entry_start ); // Rewind to seek uniformly below.
				} else {
					$facts['has_files'] = true;
				}

				if ( count( $facts['sample_names'] ) < 5 ) {
					$facts['sample_names'][] = ( '' !== $path && '.' !== $path ? $path . '/' : '' ) . $name;
				}

				++$facts['entries'];
				$facts['total_content'] += $content_size;

				if ( 0 !== fseek( $handle, $entry_start + $content_size ) || ftell( $handle ) > $total_size ) {
					fclose( $handle );
					return new \WP_Error( 'wpbp_wpress_unsupported', $this->unsupported_message() );
				}
			}
		} finally {
			if ( is_resource( $handle ) ) {
				fclose( $handle );
			}
		}

		if ( 0 === $facts['entries'] ) {
			return new \WP_Error( 'wpbp_wpress_unsupported', $this->unsupported_message() );
		}

		return $facts;
	}

	public function validate( string $file_path ) {
		$facts = $this->inspect( $file_path );

		return is_wp_error( $facts ) ? $facts : true;
	}

	public function preview( string $file_path, array $destination_context ) {
		$facts = $this->inspect( $file_path );

		if ( is_wp_error( $facts ) ) {
			return $facts;
		}

		$package    = is_array( $facts['package_json'] ) ? $facts['package_json'] : array();
		$source_url = '';

		// Common metadata keys observed in user-owned sample packages; all
		// optional and read defensively.
		foreach ( array( 'SiteURL', 'siteurl', 'HomeURL', 'home' ) as $url_key ) {
			if ( ! empty( $package[ $url_key ] ) && is_string( $package[ $url_key ] ) ) {
				$source_url = esc_url_raw( $package[ $url_key ] );
				break;
			}
		}

		$warnings = array(
			__( 'Experimental: .wpress support is preview-only. Restoring .wpress archives is not enabled yet.', 'wrensafe' ),
		);

		if ( $facts['entries'] >= self::MAX_ENTRIES ) {
			$warnings[] = __( 'Very large archive — only the first entries were inspected.', 'wrensafe' );
		}

		return array(
			'source_site_url'   => $source_url,
			'contains_database' => (bool) $facts['has_database'],
			'contains_files'    => (bool) $facts['has_files'],
			'estimated_size'    => (int) $facts['total_content'],
			'format_version'    => isset( $package['Plugin'] ) && is_string( $package['Plugin'] ) ? sanitize_text_field( $package['Plugin'] ) : '',
			'entries'           => (int) $facts['entries'],
			'warnings'          => $warnings,
		);
	}

	public function restore( string $file_path, array $restore_options, array $destination_context ) {
		return new \WP_Error(
			'wpbp_wpress_restore_disabled',
			__( 'Restoring .wpress archives is experimental and not enabled yet. Please provide a sample file for compatibility testing, or restore it with its original plugin for now.', 'wrensafe' )
		);
	}

	private function unsupported_message(): string {
		return __( 'This .wpress file format is not supported yet. Please provide a sample file for compatibility testing.', 'wrensafe' );
	}
}

// phpcs:enable WordPress.WP.AlternativeFunctions
