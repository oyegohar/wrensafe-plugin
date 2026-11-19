<?php
/**
 * Import of other plugins' backups: DafReader.
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
 * Duplicator DupArchive (.daf): "<A>…</A>" archive header, then "<D>"
 * directory and "<F>" file headers; file content follows as "<G>" globs
 * (each optionally deflated). Password-protected archives are refused.
 */
class DafReader extends StreamContainer {

	private string $version = '1.0.0';

	private int $file_flags = 0;

	const FLAG_COMPRESS = 1;
	const FLAG_CRYPT    = 2;

	protected function start( int $cursor ): void {
		fseek( $this->handle, 0 );

		if ( '<A>' !== fread( $this->handle, 3 ) ) {
			throw new \RuntimeException( esc_html__( 'This is not a valid Duplicator archive (.daf).', 'wrensafe' ) );
		}

		$this->version = $this->field( 'V' );

		if ( version_compare( $this->version, '5.0.0', '>=' ) ) {
			$flags = unpack( 'v', $this->field( 'X' ) )[1] ?? 0;
			$hash  = $this->field( 'P' );

			if ( ( $flags & self::FLAG_CRYPT ) || '' !== $hash ) {
				throw new \RuntimeException( esc_html__( 'This Duplicator archive is password-protected. Create the backup again without archive encryption, then import it.', 'wrensafe' ) );
			}
		}

		$this->read_exact( 4 ); // </A>

		if ( $cursor > 0 ) {
			fseek( $this->handle, $cursor );
		}
	}

	/** Read "<NAME>value</NAME>" at the current position. */
	private function field( string $name ): string {
		$open = '<' . $name . '>';

		if ( fread( $this->handle, strlen( $open ) ) !== $open ) {
			throw new \RuntimeException( esc_html__( 'The Duplicator archive is damaged (unexpected header).', 'wrensafe' ) );
		}

		$value = stream_get_line( $this->handle, 65536, '</' . $name . '>' );

		if ( false === $value ) {
			throw new \RuntimeException( esc_html__( 'The Duplicator archive is damaged (unreadable header).', 'wrensafe' ) );
		}

		return $value;
	}

	public function next(): ?array {
		if ( $this->remaining > 0 ) {
			$this->skip();
		}

		while ( true ) {
			$marker = fread( $this->handle, 3 );

			if ( '<D>' === $marker ) {
				$this->field( 'MT' );
				$this->field( 'P' );
				$length = (int) $this->field( 'RPL' );
				$this->read_exact( 4 );
				$this->read_exact( $length );
				$this->read_exact( 9 );
				$this->next_cursor = (int) ftell( $this->handle );
				continue;
			}

			if ( '<F>' !== $marker ) {
				return null; // End of archive (or a trailing index).
			}

			$size = (int) $this->field( 'FS' );
			$this->field( 'MT' );
			$this->field( 'P' );
			$this->file_flags = version_compare( $this->version, '5.0.0', '>=' ) ? (int) ( unpack( 'v', $this->field( 'X' ) )[1] ?? 0 ) : self::FLAG_COMPRESS;
			$this->field( 'HA' );
			$length = (int) $this->field( 'RPL' );
			$this->read_exact( 4 );
			$path = $this->read_exact( $length );
			$this->read_exact( 9 );

			if ( $this->file_flags & self::FLAG_CRYPT ) {
				throw new \RuntimeException( esc_html__( 'This Duplicator archive is password-protected. Create the backup again without archive encryption, then import it.', 'wrensafe' ) );
			}

			$this->remaining = $size;

			return array(
				'path' => ltrim( str_replace( '\\', '/', $path ), '/' ),
				'size' => $size,
				'dir'  => false,
			);
		}
	}

	public function copy_to( $out ): void {
		$this->read_globs( $out );
	}

	public function skip(): void {
		$this->read_globs( null );
	}

	/** @param resource|null $out */
	private function read_globs( $out ): void {
		$done = 0;

		while ( $done < $this->remaining ) {
			if ( '<G>' !== fread( $this->handle, 3 ) ) {
				throw new \RuntimeException( esc_html__( 'The Duplicator archive is damaged (missing data block).', 'wrensafe' ) );
			}

			$original = (int) $this->field( 'OS' );
			$stored   = (int) $this->field( 'SS' );
			$this->field( 'HA' );
			$this->read_exact( 4 ); // </G>

			if ( null === $out ) {
				fseek( $this->handle, $stored, SEEK_CUR );
			} else {
				$data = $stored > 0 ? $this->read_exact( $stored ) : '';

				if ( ( $this->file_flags & self::FLAG_COMPRESS ) && '' !== $data ) {
					// A raw DEFLATE block (no zlib or gzip header).
					AbstractFormat::require_zlib();

					$inflate = inflate_init( ZLIB_ENCODING_RAW );
					$data    = false === $inflate ? false : @inflate_add( $inflate, $data, ZLIB_FINISH ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- a damaged block is reported below.

					if ( false === $data ) {
						throw new \RuntimeException( esc_html__( 'The Duplicator archive is damaged (a data block could not be decompressed).', 'wrensafe' ) );
					}
				}

				fwrite( $out, $data );
			}

			$done += $original;

			if ( 0 === $original && 0 === $stored ) {
				break;
			}
		}

		$this->remaining   = 0;
		$this->next_cursor = (int) ftell( $this->handle );
	}
}

// phpcs:enable WordPress.WP.AlternativeFunctions
