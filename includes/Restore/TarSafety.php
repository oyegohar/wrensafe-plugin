<?php
/**
 * Checks a TAR backup before PharData opens it.
 *
 * PharData keeps Phar's own data in a TAR under ".phar/", and on PHP 7 it
 * unserializes ".phar/.metadata.bin" (and the ".phar/.metadata/…" entry of
 * each file) as soon as the archive is opened, so a crafted "backup" could
 * make PHP create objects of its choice. Phar also reads a file that starts
 * like a gzip, bzip2 or ZIP file, or isn't a TAR at all, in its other
 * formats, which carry the same metadata.
 *
 * Wrensafe's own TAR backups (written where ZipArchive is missing) never
 * contain such entries. is_plain_tar() walks the headers the way PHP's TAR
 * reader does (ext/phar/tar.c: the same header positions, 32-bit sizes, GNU
 * long names and pax headers), so PharData only opens a file in which no
 * name starts with ".phar".
 *
 * @package WPBP\Restore
 */

namespace WPBP\Restore;

defined( 'ABSPATH' ) || exit;

// The archive's headers are read with a native file handle: WP_Filesystem
// can't seek or read part of a file. The path is the backup ArchiveReader
// is opening (in Wrensafe's protected storage or a validated import path).
// phpcs:disable WordPress.WP.AlternativeFunctions

class TarSafety {

	private const BLOCK = 512;

	/** Bytes of an old-style (pre-POSIX) header that Phar checksums. */
	private const OLD_HEADER = 257;

	/** Longest GNU long name accepted (Phar has no limit; real ones are short). */
	private const MAX_LONG_NAME = 65536;

	/**
	 * True when PharData can open the file without reading Phar's own data:
	 * a TAR (as Phar detects it) in which no entry name starts with ".phar".
	 */
	public static function is_plain_tar( string $path ): bool {
		if ( ! is_file( $path ) || ! is_readable( $path ) ) {
			return false;
		}

		$size   = (int) filesize( $path );
		$handle = fopen( $path, 'rb' );

		if ( false === $handle ) {
			return false;
		}

		$plain = self::scan( $handle, $size );
		fclose( $handle );

		return $plain;
	}

	/**
	 * @param resource $handle
	 */
	private static function scan( $handle, int $size ): bool {
		// Phar only reads a file as a TAR when its first read is longer than
		// one header; a shorter file is searched for a phar stub instead.
		if ( $size <= self::BLOCK ) {
			return false;
		}

		$block = self::read( $handle, self::BLOCK );

		// Phar checks for these before it looks for a TAR header.
		if ( null === $block
			|| 0 === strncmp( $block, "\x1f\x8b", 2 )
			|| 0 === strncmp( $block, 'BZh', 3 )
			|| 0 === strncmp( $block, "PK\x03\x04", 4 )
			|| 0 === strncmp( $block, '<?php', 5 ) ) {
			return false;
		}

		// phar_is_tar(): without a matching first checksum Phar reads the
		// file as a phar instead.
		if ( self::number( substr( $block, 148, 8 ) ) !== self::checksum( substr_replace( $block, '        ', 148, 8 ) ) ) {
			return false;
		}

		$old       = 'ustar' !== substr( $block, 257, 5 );
		$long_name = null;

		while ( true ) {
			$sum1 = self::number( substr( $block, 148, 8 ) );

			if ( 0 === $sum1 && 0 === self::checksum( $block ) ) {
				return true; // End of the archive; Phar stops here too.
			}

			$header = substr_replace( $block, '        ', 148, 8 );
			$sum2   = self::checksum( $old ? substr( $header, 0, self::OLD_HEADER ) : $header );

			if ( $old && $sum2 !== $sum1 && self::checksum( $header ) === $sum1 ) {
				// A USTAR header without the "ustar" marker: Phar switches.
				$sum2 = $sum1;
				$old  = false;
			}

			$entry_size = self::number( substr( $block, 124, 12 ) );
			$type       = $block[156];
			$name       = self::field( $block, 0, 100 );
			$prefix     = self::field( $block, 345, 155 );

			// Whichever way Phar reads this header's name, it can't be Phar's.
			if ( self::is_phar_name( $name ) || self::is_phar_name( $prefix ) ) {
				return false;
			}

			if ( ! $old && ( 'g' === $type || 'x' === $type ) ) {
				// pax headers: Phar skips them.
				$skip = self::padded( $entry_size );
			} elseif ( null === $long_name && 'L' === $type ) {
				// GNU long name: the data is the next entry's name.
				if ( 0 === $entry_size || $entry_size > self::MAX_LONG_NAME ) {
					return false;
				}

				$long_name = self::read( $handle, $entry_size );

				if ( null === $long_name || self::is_phar_name( self::field( $long_name, 0, $entry_size ) ) ) {
					return false;
				}

				$skip = self::padded( $entry_size ) - $entry_size;
			} else {
				if ( null !== $long_name ) {
					$filename = $long_name;
				} elseif ( ! $old && '' !== $prefix ) {
					$filename = $prefix . '/' . $name;
				} else {
					$filename = $name;
				}

				$long_name = null;

				// Phar refuses the file; so does Wrensafe.
				if ( $sum1 !== $sum2 || self::is_phar_name( $filename ) ) {
					return false;
				}

				// Phar only skips the data of regular files.
				$skip = ( "\0" === $type || '0' === $type ) ? self::padded( $entry_size ) : 0;
			}

			if ( $skip > 0 && ( 0 !== fseek( $handle, $skip, SEEK_CUR ) || ftell( $handle ) > $size ) ) {
				return false;
			}

			$block = self::read( $handle, self::BLOCK );

			if ( null === $block ) {
				return false; // Truncated: Phar refuses it.
			}
		}
	}

	/**
	 * A name Phar treats as its own data (".phar/…"), also with a leading
	 * "./" or "/".
	 */
	private static function is_phar_name( string $name ): bool {
		return 0 === strncmp( (string) preg_replace( '#^(?:\./|/)+#', '', $name ), '.phar', 5 );
	}

	/**
	 * A header field up to its first NUL byte.
	 */
	private static function field( string $block, int $offset, int $length ): string {
		$value = substr( $block, $offset, $length );
		$nul   = strpos( $value, "\0" );

		return false === $nul ? $value : substr( $value, 0, $nul );
	}

	/**
	 * Phar's phar_tar_number(): leading spaces, then octal digits, as an
	 * unsigned 32-bit number.
	 */
	private static function number( string $field ): int {
		$length = strlen( $field );
		$i      = 0;
		$number = 0;

		while ( $i < $length && ' ' === $field[ $i ] ) {
			++$i;
		}

		while ( $i < $length && $field[ $i ] >= '0' && $field[ $i ] <= '7' ) {
			$number = ( $number * 8 + ( ord( $field[ $i ] ) - 48 ) ) & 0xFFFFFFFF;
			++$i;
		}

		return $number;
	}

	/**
	 * Phar's phar_tar_checksum(): the sum of the bytes as unsigned values.
	 */
	private static function checksum( string $bytes ): int {
		return '' === $bytes ? 0 : (int) array_sum( (array) unpack( 'C*', $bytes ) );
	}

	/**
	 * A size rounded up to whole blocks, as Phar computes it (32-bit).
	 */
	private static function padded( int $size ): int {
		return ( ( $size + 511 ) & 0xFFFFFFFF ) & ~511;
	}

	/**
	 * @param resource $handle
	 */
	private static function read( $handle, int $length ): ?string {
		$data = '';
		$got  = 0;

		while ( $got < $length && ! feof( $handle ) ) {
			$chunk = fread( $handle, $length - $got );

			if ( false === $chunk || '' === $chunk ) {
				break;
			}

			$data .= $chunk;
			$got  += strlen( $chunk );
		}

		return $got === $length ? $data : null;
	}
}

// phpcs:enable WordPress.WP.AlternativeFunctions
