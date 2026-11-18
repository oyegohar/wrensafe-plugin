<?php
/**
 * A place backups are copied to (FTP, SFTP, S3-compatible storage).
 *
 * Uploads are resumable: begin() prepares, send() moves as much of the file
 * as fits before a deadline and keeps its position in $state (stored in the
 * backup job between requests), finish() makes the copy final. This keeps
 * every request short, so multi-gigabyte backups upload even on hosts that
 * stop PHP after 30 seconds.
 *
 * @package WPBP\Remote
 */

namespace WPBP\Remote;

defined( 'ABSPATH' ) || exit;

interface RemoteTarget {

	/** Why this target can't be used on this server ('' when it can). */
	public static function unavailable_reason(): string;

	/**
	 * Connect, write a small test file and remove it.
	 *
	 * @return array{ok:bool,message:string}
	 */
	public function test(): array;

	/**
	 * Start uploading $remote_name ($size bytes).
	 *
	 * @return array<string,mixed> Upload state.
	 */
	public function begin( string $remote_name, int $size ): array;

	/**
	 * Upload the next part of $local_path. Returns true when every byte is
	 * on the remote side.
	 *
	 * @param array<string,mixed> $state Upload state (updated).
	 */
	public function send( string $local_path, array &$state, float $deadline ): bool;

	/**
	 * Make the uploaded file final under its name.
	 *
	 * @param array<string,mixed> $state
	 */
	public function finish( array &$state ): void;

	/**
	 * Drop an unfinished upload (best effort).
	 *
	 * @param array<string,mixed> $state
	 */
	public function abort( array $state ): void;

	/**
	 * Wrensafe backups stored there, newest first.
	 *
	 * @return array<int,array{name:string,time:int,size:int}>
	 */
	public function list_backups(): array;

	public function delete( string $name ): bool;
}
