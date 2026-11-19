<?php
/**
 * Import of other plugins' backups: TarReader.
 *
 * @package WPBP\Import\Foreign
 */

namespace WPBP\Import\Foreign;

defined( 'ABSPATH' ) || exit;

// Archives are read with native file handles (fopen/fread/fseek): WP_Filesystem
// has no streaming API. Every path is an uploaded import file inside
// Wrensafe's protected storage directory.
// phpcs:disable WordPress.WP.AlternativeFunctions

/**
 * POSIX/GNU tar (BackWPup). 512-byte headers; GNU long names ("L"/"K"),
 * PAX headers ("x" path=, "g") and base-256 sizes are honoured.
 *
 * Only regular files carry data. BackWPup writes the folder's st_size into
 * directory headers, so the size field of every other type is ignored (as
 * GNU tar and Python's tarfile do).
 */
class TarReader extends StreamContainer {

	/** Entry types whose size field counts data blocks that follow the header. */
	private const DATA_TYPES = array( '0', "\0", '7', 'L', 'K', 'x', 'g' );

	/** Zero padding after the current entry's content. */
	private int $pad = 0;

	public function next(): ?array {
		if ( $this->remaining > 0 || $this->pad > 0 ) {
			$this->skip();
		}

		$long_name = null;

		while ( true ) {
			$header = fread( $this->handle, 512 );

			if ( false === $header || strlen( $header ) < 512 || str_repeat( "\0", 512 ) === $header ) {
				return null;
			}

			$type   = substr( $header, 156, 1 );
			$size   = in_array( $type, self::DATA_TYPES, true ) ? self::size( substr( $header, 124, 12 ) ) : 0;
			$name   = self::field( substr( $header, 0, 100 ) );
			$padded = (int) ( ceil( $size / 512 ) * 512 );

			// The prefix field exists only in POSIX ustar headers; GNU tar
			// ("ustar  \0") keeps access times and sparse data there.
			$prefix = "ustar\0" === substr( $header, 257, 6 ) ? self::field( substr( $header, 345, 155 ) ) : '';

			if ( in_array( $type, array( 'L', 'K', 'x', 'g' ), true ) ) {
				$data = substr( $size > 0 ? $this->read_exact( $padded ) : '', 0, $size );

				if ( 'L' === $type ) {
					$long_name = self::field( $data );
				} elseif ( 'x' === $type && preg_match( '/(?:^|\n)\d+ path=([^\n]+)\n/', $data, $m ) ) {
					$long_name = $m[1];
				}
				continue;
			}

			$path = null !== $long_name ? $long_name : ( '' !== $prefix ? $prefix . '/' . $name : $name );

			$this->remaining   = $size;
			$this->next_cursor = (int) ftell( $this->handle ) + $padded;
			$this->pad         = $padded - $size;

			return array(
				'path' => ltrim( (string) preg_replace( '#^\./#', '', str_replace( '\\', '/', $path ) ), '/' ),
				'size' => $size,
				'dir'  => '5' === $type || ( "\0" === $type && '/' === substr( $name, -1 ) ),
				'skip' => ! in_array( $type, array( '0', "\0", '7', '5' ), true ), // Links, devices, FIFOs.
			);
		}
	}

	/** A NUL-terminated header field. */
	private static function field( string $raw ): string {
		$end = strpos( $raw, "\0" );

		return false === $end ? $raw : substr( $raw, 0, $end );
	}

	/** Octal size, or GNU base-256 (high bit set) for entries over 8 GB. */
	private static function size( string $raw ): int {
		if ( '' !== $raw && ( ord( $raw[0] ) & 0x80 ) ) {
			$size = 0;
			for ( $i = 1, $len = strlen( $raw ); $i < $len; $i++ ) {
				$size = ( $size << 8 ) | ord( $raw[ $i ] );
			}
			return $size;
		}

		return (int) octdec( trim( $raw, " \0" ) );
	}

	public function copy_to( $out ): void {
		$this->copy_raw( $out, $this->remaining );
		fseek( $this->handle, $this->pad, SEEK_CUR );
		$this->remaining = 0;
		$this->pad       = 0;
	}

	public function skip(): void {
		fseek( $this->handle, $this->remaining + $this->pad, SEEK_CUR );
		$this->remaining = 0;
		$this->pad       = 0;
	}
}

// phpcs:enable WordPress.WP.AlternativeFunctions
