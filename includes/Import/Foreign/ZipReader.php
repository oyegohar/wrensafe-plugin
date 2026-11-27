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

/** Zip archives (ZipArchive, or Wrensafe's own reader: see ZipView); the cursor is the entry index. */
class ZipReader implements ContainerReader {

	private ?ZipView $zip = null;

	private int $index = 0;

	private int $current = -1;

	public function open( string $file, int $cursor ): void {
		AbstractFormat::require_zip();

		$zip = ZipView::open( $file );

		if ( null === $zip ) {
			throw new \RuntimeException( esc_html__( 'The .zip backup could not be opened (it may be incomplete or damaged).', 'wrensafe' ) );
		}

		$this->zip   = $zip;
		$this->index = $cursor;
	}

	public function next(): ?array {
		if ( $this->index >= $this->zip->count() ) {
			return null;
		}

		$raw           = $this->zip->name( $this->index );
		$this->current = $this->index;
		++$this->index;

		$name = str_replace( '\\', '/', $raw );

		return array(
			'path' => ltrim( $name, '/' ),
			'size' => (int) $this->zip->size( $raw ),
			'dir'  => '/' === substr( $name, -1 ),
		);
	}

	public function copy_to( $out ): void {
		if ( ! $this->zip->copy_to( $this->zip->name( $this->current ), $out ) ) {
			throw new \RuntimeException( esc_html__( 'A file inside the .zip backup could not be read.', 'wrensafe' ) );
		}
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

// phpcs:enable WordPress.WP.AlternativeFunctions
