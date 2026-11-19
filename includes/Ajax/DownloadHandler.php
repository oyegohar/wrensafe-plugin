<?php
/**
 * Streams a .wbp backup file to the browser through an authenticated
 * endpoint, since backups are never directly web-accessible.
 *
 * @package WPBP\Ajax
 */

namespace WPBP\Ajax;

use WPBP\Core\Database\BackupsRepository;
use WPBP\Core\Settings;
use WPBP\Security\CapabilityManager;
use WPBP\Utils\FileSystem;
use WPBP\Utils\Logger;

defined( 'ABSPATH' ) || exit;

// Backup archives and upload chunks are streamed in bounded blocks with native
// file handles (fopen/fread/fwrite/rename): WP_Filesystem has no streaming API.
// Every path is inside Wrensafe's protected storage directory, a PHP upload
// temp file, or validated by WPBP\Security\PathValidator.
// phpcs:disable WordPress.WP.AlternativeFunctions

class DownloadHandler {

	private const CHUNK_SIZE = 1048576; // 1MB — never loads the whole archive into memory.

	public function register_hooks(): void {
		add_action( 'admin_post_wpbp_download_backup', array( $this, 'handle' ) );
	}

	public function handle(): void {
		check_admin_referer( 'wpbp_download_backup', '_wpbp_nonce' );

		if ( ! CapabilityManager::current_user_can( CapabilityManager::DOWNLOAD_BACKUPS ) ) {
			wp_die( esc_html__( 'You do not have permission to download this file.', 'wrensafe' ), 403 );
		}

		$id     = isset( $_GET['backup_id'] ) ? absint( $_GET['backup_id'] ) : 0;
		$backup = $id ? BackupsRepository::find( $id ) : null;

		if ( null === $backup || empty( $backup['file_path'] ) || ! file_exists( $backup['file_path'] ) ) {
			wp_die( esc_html__( 'Backup file not found.', 'wrensafe' ), 404 );
		}

		if ( 'corrupted' === ( $backup['status'] ?? '' ) ) {
			wp_die( esc_html__( 'This backup failed its last integrity check and cannot be downloaded.', 'wrensafe' ), 400 );
		}

		if ( ! empty( $backup['is_locked'] ) && ! Settings::get( 'allow_locked_backup_downloads', false ) ) {
			Logger::warning( 'Blocked download of locked backup #' . $backup['id'] . ' (' . $backup['file_name'] . ').' );
			wp_die( esc_html__( 'This backup is locked. Unlock it before downloading.', 'wrensafe' ), 403 );
		}

		if ( ! FileSystem::is_backup_file_path( (string) $backup['file_path'] ) ) {
			Logger::error( 'Blocked download of backup #' . $backup['id'] . ': file path resolves outside the backups directory.' );
			wp_die( esc_html__( 'Backup file not found.', 'wrensafe' ), 404 );
		}

		$path = $backup['file_path'];
		clearstatcache( true, $path );
		$size = (int) filesize( $path );

		// Optional single byte range ("Range: bytes=start-end"), so browsers
		// and download managers can resume a multi-GB download that dropped
		// instead of starting over.
		list( $start, $end, $partial ) = $this->requested_range( $size );

		if ( null === $start ) {
			header( 'Content-Range: bytes */' . $size );
			status_header( 416 );
			exit;
		}

		$handle = fopen( $path, 'rb' );

		if ( false === $handle ) {
			wp_die( esc_html__( 'Backup file could not be opened.', 'wrensafe' ), 500 );
		}

		if ( 0 === $start ) {
			Logger::info( 'Backup #' . $backup['id'] . ' (' . $backup['file_name'] . ') downloaded.' );
		}

		// A multi-GB stream must not be held in any output buffer (a page
		// cache or gzip handler would buffer the whole archive in memory),
		// compressed on the fly, or cut off by max_execution_time.
		if ( function_exists( 'apache_setenv' ) ) {
			@apache_setenv( 'no-gzip', '1' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.PHP.DiscouragedPHPFunctions.runtime_configuration_apache_setenv -- mod_deflate must not compress a multi-GB binary stream.
		}
		@ini_set( 'zlib.output_compression', 'Off' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.PHP.IniSet.Risky, Squiz.PHP.DiscouragedFunctions.Discouraged -- a multi-GB binary download must not be compressed in memory.
		while ( ob_get_level() > 0 ) {
			ob_end_clean();
		}
		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( 0 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.PHP.DiscouragedPHPFunctions.runtime_configuration_set_time_limit, Squiz.PHP.DiscouragedFunctions.Discouraged -- long download of a large archive.
		}
		ignore_user_abort( false );

		nocache_headers();
		header( 'Content-Type: application/octet-stream' );
		header( 'Content-Disposition: attachment; filename="' . sanitize_file_name( $backup['file_name'] ) . '"' );
		header( 'Accept-Ranges: bytes' );
		header( 'X-Content-Type-Options: nosniff' );
		header( 'Last-Modified: ' . gmdate( 'D, d M Y H:i:s', (int) filemtime( $path ) ) . ' GMT' );
		header( 'Content-Length: ' . ( $end - $start + 1 ) );

		if ( $partial ) {
			status_header( 206 );
			header( 'Content-Range: bytes ' . $start . '-' . $end . '/' . $size );
		}

		if ( $start > 0 ) {
			fseek( $handle, $start );
		}

		// The file is copied stream to stream, at most CHUNK_SIZE bytes at a
		// time, so memory use stays the same for a multi-GB archive.
		$output = fopen( 'php://output', 'wb' );

		if ( false === $output ) {
			fclose( $handle );
			exit;
		}

		$remaining = $end - $start + 1;

		while ( $remaining > 0 && ! connection_aborted() ) {
			$copied = stream_copy_to_stream( $handle, $output, (int) min( self::CHUNK_SIZE, $remaining ) );

			if ( false === $copied || 0 === $copied ) {
				break;
			}

			$remaining -= $copied;
			flush();
		}

		fclose( $output );
		fclose( $handle );

		exit;
	}

	/**
	 * Parse a single "bytes=" Range header against the file size.
	 *
	 * @return array{0:int|null,1:int,2:bool} Start (null = unsatisfiable), end, whether it is a partial response.
	 */
	private function requested_range( int $size ): array {
		$header = isset( $_SERVER['HTTP_RANGE'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_RANGE'] ) ) : '';

		if ( '' === $header || $size <= 0 || ! preg_match( '/^bytes=(\d*)-(\d*)$/', trim( $header ), $m ) || ( '' === $m[1] && '' === $m[2] ) ) {
			return array( 0, max( 0, $size - 1 ), false );
		}

		if ( '' === $m[1] ) {
			// Suffix range: the last N bytes.
			$start = max( 0, $size - (int) $m[2] );
			$end   = $size - 1;
		} else {
			$start = (int) $m[1];
			$end   = '' === $m[2] ? $size - 1 : min( (int) $m[2], $size - 1 );
		}

		if ( $start >= $size || $start > $end ) {
			return array( null, 0, false );
		}

		return array( $start, $end, true );
	}
}

// phpcs:enable WordPress.WP.AlternativeFunctions
