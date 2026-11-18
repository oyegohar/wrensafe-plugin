<?php
/**
 * Import of other plugins' backups: ZipReader.
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


/** Zip archives through ZipArchive; the cursor is the entry index. */
class ZipReader implements ContainerReader {

	private ?\ZipArchive $zip = null;

	private int $index = 0;

	private int $current = -1;

	public function open( string $file, int $cursor ): void {
		if ( ! class_exists( '\ZipArchive' ) ) {
			throw new \RuntimeException( __( 'Reading .zip backups needs the PHP Zip extension, which this server does not have.', 'wrensafe' ) );
		}

		$zip = new \ZipArchive();

		if ( true !== $zip->open( $file ) ) {
			throw new \RuntimeException( __( 'The .zip backup could not be opened (it may be incomplete or damaged).', 'wrensafe' ) );
		}

		$this->zip   = $zip;
		$this->index = $cursor;
	}

	public function next(): ?array {
		// phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- ZipArchive property.
		if ( $this->index >= $this->zip->numFiles ) {
			return null;
		}

		$stat          = $this->zip->statIndex( $this->index );
		$this->current = $this->index;
		++$this->index;

		$name = str_replace( '\\', '/', (string) ( $stat['name'] ?? '' ) );

		return array(
			'path' => ltrim( $name, '/' ),
			'size' => (int) ( $stat['size'] ?? 0 ),
			'dir'  => '/' === substr( $name, -1 ),
		);
	}

	public function copy_to( $out ): void {
		$stream = $this->zip->getStream( (string) $this->zip->getNameIndex( $this->current ) );

		if ( false === $stream ) {
			throw new \RuntimeException( __( 'A file inside the .zip backup could not be read.', 'wrensafe' ) );
		}

		stream_copy_to_stream( $stream, $out );
		fclose( $stream );
	}

	public function skip(): void {
		// Nothing to do: entries are addressed by index.
	}

	public function cursor(): int {
		return $this->index;
	}

	public function close(): void {
		if ( null !== $this->zip ) {
			$this->zip->close();
		}

		$this->zip = null;
	}
}

// phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped

// phpcs:enable WordPress.WP.AlternativeFunctions
