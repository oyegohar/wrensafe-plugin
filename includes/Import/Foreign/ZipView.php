<?php
/**
 * Import of other plugins' backups: ZipView.
 *
 * One way to read a .zip, whatever PHP has: ZipArchive when the zip
 * extension is there (as before), otherwise Wrensafe's own ZipFileReader
 * (plain PHP and zlib). PHP's zip extension is often off on local stacks
 * (a separate DLL on Windows since PHP 8.2: Laragon, XAMPP) and on some
 * hosts, and backups from UpdraftPlus, Duplicator, WPvivid and the others
 * are ordinary zips that zlib alone can read.
 *
 * @package WPBP\Import\Foreign
 */

namespace WPBP\Import\Foreign;

use WPBP\Restore\ZipFileReader;

defined( 'ABSPATH' ) || exit;

// Archives are read with native file handles (fread/fclose on entry streams):
// WP_Filesystem has no streaming API. Every path is an uploaded import file or
// a backup found inside the site's own folders.
// phpcs:disable WordPress.WP.AlternativeFunctions

class ZipView {

	/** @var \ZipArchive|null */
	private $zip = null;

	/** @var ZipFileReader|null */
	private $plain = null;

	/** @var string[] Entry names in the archive's order (plain reader only). */
	private array $names = array();

	/** Whether this server can read .zip files at all. */
	public static function available(): bool {
		return class_exists( '\ZipArchive' ) || ZipFileReader::available();
	}

	/** Open a .zip, or null when it can't be read. */
	public static function open( string $path ): ?self {
		$view = new self();

		if ( class_exists( '\ZipArchive' ) ) {
			$zip = new \ZipArchive();

			if ( true === $zip->open( $path ) ) {
				$view->zip = $zip;
				return $view;
			}
		}

		$plain = new ZipFileReader( $path );

		if ( ! $plain->open() ) {
			return null;
		}

		$view->plain = $plain;
		$view->names = $plain->names();

		return $view;
	}

	public function count(): int {
		// phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- ZipArchive property.
		return null !== $this->zip ? (int) $this->zip->numFiles : count( $this->names );
	}

	/** The name of the entry at $index (archive order), or ''. */
	public function name( int $index ): string {
		if ( null !== $this->zip ) {
			return (string) $this->zip->getNameIndex( $index );
		}

		return $this->names[ $index ] ?? '';
	}

	public function has( string $name ): bool {
		return null !== $this->size( $name );
	}

	/** An entry's unpacked size, or null when it isn't there. */
	public function size( string $name ): ?int {
		if ( null !== $this->zip ) {
			$stat = $this->zip->statName( $name );

			return false !== $stat ? (int) $stat['size'] : null;
		}

		$stat = $this->plain->stat( $name );

		return null !== $stat ? (int) $stat['size'] : null;
	}

	/** An entry's content, or null (missing, larger than $max, unreadable). */
	public function read( string $name, int $max = PHP_INT_MAX ): ?string {
		$size = $this->size( $name );

		if ( null === $size || $size > $max ) {
			return null;
		}

		if ( null !== $this->zip ) {
			$data = $this->zip->getFromName( $name );

			return false === $data ? null : (string) $data;
		}

		return $this->plain->read( $name, $max );
	}

	/** The first $length bytes of an entry ('' when it can't be read). */
	public function head( string $name, int $length ): string {
		if ( null !== $this->zip ) {
			$stream = $this->zip->getStream( $name );

			if ( false === $stream ) {
				return '';
			}

			$head = (string) fread( $stream, $length );
			fclose( $stream );

			return $head;
		}

		$head = '';

		// The sink stops the copy once enough has arrived.
		$this->plain->copy(
			$name,
			static function ( string $bytes ) use ( &$head, $length ): bool {
				$head .= $bytes;
				return strlen( $head ) < $length;
			}
		);

		return (string) substr( $head, 0, $length );
	}

	/**
	 * Copy an entry into an open file.
	 *
	 * @param resource $out
	 */
	public function copy_to( string $name, $out ): bool {
		if ( null !== $this->zip ) {
			$stream = $this->zip->getStream( $name );

			if ( false === $stream ) {
				return false;
			}

			$ok = false !== stream_copy_to_stream( $stream, $out );
			fclose( $stream );

			return $ok;
		}

		$result = $this->plain->copy(
			$name,
			static function ( string $bytes ) use ( $out ): bool {
				return fwrite( $out, $bytes ) === strlen( $bytes );
			}
		);

		return $result['done'];
	}

	public function close(): void {
		if ( null !== $this->zip ) {
			$this->zip->close();
			$this->zip = null;
		}

		$this->plain = null;
		$this->names = array();
	}
}

// phpcs:enable WordPress.WP.AlternativeFunctions
