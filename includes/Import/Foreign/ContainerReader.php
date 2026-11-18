<?php
/**
 * Import of other plugins' backups: ContainerReader.
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
 * Common contract: next() returns the next entry and positions the reader at
 * its content; copy_to() streams that content (or skip() passes it).
 */
interface ContainerReader {

	/** Start (or resume) reading at a cursor from cursor(). */
	public function open( string $file, int $cursor ): void;

	/**
	 * The next entry, or null at the end.
	 *
	 * @return array{path:string,size:int,dir:bool,skip?:bool}|null skip: no file content (link, device).
	 */
	public function next(): ?array;

	/**
	 * Write the current entry's content to a handle.
	 *
	 * @param resource $out
	 */
	public function copy_to( $out ): void;

	/** Pass over the current entry's content. */
	public function skip(): void;

	/** Where the next call to next() starts. */
	public function cursor(): int;

	public function close(): void;
}

// phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped

// phpcs:enable WordPress.WP.AlternativeFunctions
