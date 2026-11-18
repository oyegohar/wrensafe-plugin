<?php
/**
 * Import of other plugins' backups: WpressReader.
 *
 * @package WPBP\Import\Foreign
 */

namespace WPBP\Import\Foreign;

defined( 'ABSPATH' ) || exit;

// Archives are read with native file handles (fopen/fread/fseek): WP_Filesystem
// has no streaming API. Every path is an uploaded import file inside
// Wrensafe's protected storage directory.
// phpcs:disable WordPress.WP.AlternativeFunctions

// Exception messages are stored as a job's error and shown with jQuery .text().
// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped

/**
 * All-in-One WP Migration .wpress: per entry a 4377-byte header
 * (name 255, size 14, mtime 12, path 4096; NUL padded) followed by the raw
 * content; an all-zero header ends the archive.
 */
class WpressReader extends StreamContainer {

	const HEADER = 4377;

	public function next(): ?array {
		if ( $this->remaining > 0 ) {
			$this->skip();
		}

		$header = fread( $this->handle, self::HEADER );

		if ( false === $header || strlen( $header ) < self::HEADER || str_repeat( "\0", self::HEADER ) === $header ) {
			return null;
		}

		// Each field ends at its first NUL: newer versions store a checksum
		// in the last bytes of the path field.
		$name = self::field( substr( $header, 0, 255 ) );
		$size = (int) self::field( substr( $header, 255, 14 ) );
		$path = self::field( substr( $header, 281, 4096 ) );
		$path = str_replace( '\\', '/', $path );
		$rel  = ( '' === $path || '.' === $path ) ? $name : trim( $path, '/' ) . '/' . $name;

		$this->remaining   = $size;
		$this->next_cursor = (int) ftell( $this->handle ) + $size;

		return array(
			'path' => ltrim( preg_replace( '#^\./#', '', $rel ), '/' ),
			'size' => $size,
			'dir'  => false,
		);
	}

	private static function field( string $raw ): string {
		$end = strpos( $raw, "\0" );

		return false === $end ? $raw : substr( $raw, 0, $end );
	}

	public function copy_to( $out ): void {
		$this->copy_raw( $out, $this->remaining );
		$this->remaining = 0;
	}

	public function skip(): void {
		fseek( $this->handle, $this->remaining, SEEK_CUR );
		$this->remaining = 0;
	}
}

// phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped

// phpcs:enable WordPress.WP.AlternativeFunctions
