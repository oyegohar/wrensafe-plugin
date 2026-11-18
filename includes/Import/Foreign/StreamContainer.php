<?php
/**
 * Import of other plugins' backups: StreamContainer.
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


/** Shared helpers for byte-stream containers. */
abstract class StreamContainer implements ContainerReader {

	/** @var resource|null */
	protected $handle = null;

	/** Unread content bytes of the current entry. */
	protected int $remaining = 0;

	protected int $next_cursor = 0;

	public function open( string $file, int $cursor ): void {
		$handle = fopen( $file, 'rb' );

		if ( false === $handle ) {
			throw new \RuntimeException( __( 'The backup file could not be opened.', 'wrensafe' ) );
		}

		$this->handle      = $handle;
		$this->next_cursor = $cursor;
		$this->remaining   = 0;

		$this->start( $cursor );
	}

	/** Position at a cursor (0 = beginning, including any archive header). */
	protected function start( int $cursor ): void {
		fseek( $this->handle, $cursor );
	}

	public function cursor(): int {
		return $this->next_cursor;
	}

	public function close(): void {
		if ( is_resource( $this->handle ) ) {
			fclose( $this->handle );
		}

		$this->handle = null;
	}

	/**
	 * Copy $length raw bytes from the archive to $out.
	 *
	 * @param resource $out
	 */
	protected function copy_raw( $out, int $length ): void {
		while ( $length > 0 ) {
			$chunk = fread( $this->handle, (int) min( 1048576, $length ) );

			if ( false === $chunk || '' === $chunk ) {
				throw new \RuntimeException( __( 'The backup file ended unexpectedly (it may be incomplete).', 'wrensafe' ) );
			}

			if ( null !== $out ) {
				fwrite( $out, $chunk );
			}

			$length -= strlen( $chunk );
		}
	}

	/** Read exactly $length bytes. */
	protected function read_exact( int $length ): string {
		if ( $length <= 0 ) {
			return '';
		}

		$data = (string) fread( $this->handle, $length );

		if ( strlen( $data ) !== $length ) {
			throw new \RuntimeException( __( 'The backup file ended unexpectedly (it may be incomplete).', 'wrensafe' ) );
		}

		return $data;
	}
}

// phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped

// phpcs:enable WordPress.WP.AlternativeFunctions
