<?php
/**
 * FTP and FTPS (explicit TLS) storage, with PHP's ftp functions.
 *
 * The backup is appended in 8 MB pieces to "<name>.part" (FTP APPE), so an
 * upload continues where it stopped on the next request, then renamed to
 * its final name. The remote size is checked against the expected position
 * before every piece, so an interrupted request never leaves a gap.
 *
 * @package WPBP\Remote
 */

namespace WPBP\Remote;

use WPBP\Utils\FileSystem;

defined( 'ABSPATH' ) || exit;

// Backup archives are read in bounded pieces with native file handles
// (fopen/fread/fwrite): WP_Filesystem has no streaming API. The local paths are
// backups in Wrensafe's storage folder and a temporary piece in its tmp folder.
// phpcs:disable WordPress.WP.AlternativeFunctions

final class FtpTarget implements RemoteTarget {

	private const PIECE = 8388608; // 8 MB.

	/** @var array<string,mixed> */
	private array $config;

	/** @var resource|\FTP\Connection|null */
	private $conn = null;

	/** The folder the account logs in to ('' for the root). */
	private string $home = '';

	/**
	 * @param array<string,mixed> $config host, port, username, password, folder, ftps, passive.
	 */
	public function __construct( array $config ) {
		$this->config = $config;
	}

	public function __destruct() {
		if ( $this->conn ) {
			@ftp_close( $this->conn ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- closing a connection the server may already have dropped.
		}
	}

	public static function unavailable_reason(): string {
		return function_exists( 'ftp_connect' ) ? '' : __( 'This server’s PHP has no FTP support (the ftp extension). Ask your host to turn it on, or use S3-compatible storage.', 'wrensafe' );
	}

	/** @return resource|\FTP\Connection */
	private function connection() {
		if ( $this->conn ) {
			return $this->conn;
		}

		$host = (string) ( $this->config['host'] ?? '' );
		$port = (int) ( $this->config['port'] ?? 21 );
		$tls  = ! empty( $this->config['ftps'] );

		if ( $tls && ! function_exists( 'ftp_ssl_connect' ) ) {
			throw new \RuntimeException( esc_html__( 'This server’s PHP can’t make FTPS (TLS) connections. Turn off "Use TLS" or ask your host about OpenSSL support for FTP.', 'wrensafe' ) );
		}

		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- a failed connection is reported below.
		$conn = $tls ? @ftp_ssl_connect( $host, $port, 20 ) : @ftp_connect( $host, $port, 20 );

		if ( ! $conn ) {
			/* translators: %s: host and port */
			throw new \RuntimeException( esc_html( sprintf( __( 'Couldn’t connect to %s. Check the host name and port.', 'wrensafe' ), $host . ':' . $port ) ) );
		}

		if ( ! @ftp_login( $conn, (string) ( $this->config['username'] ?? '' ), (string) ( $this->config['password'] ?? '' ) ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			@ftp_close( $conn ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			throw new \RuntimeException( esc_html__( 'The FTP server didn’t accept the username or password.', 'wrensafe' ) );
		}

		ftp_pasv( $conn, ! array_key_exists( 'passive', $this->config ) || ! empty( $this->config['passive'] ) );
		$this->conn = $conn;
		$this->home = rtrim( (string) @ftp_pwd( $conn ), '/' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- '' (the root) when the server doesn't say.

		return $conn;
	}

	/**
	 * The folder as a full path ('' for the root). A folder without a
	 * leading slash is inside the folder the account logs in to.
	 */
	private function folder(): string {
		$folder = trim( str_replace( '\\', '/', (string) ( $this->config['folder'] ?? '' ) ) );

		if ( '' !== $folder && '/' === $folder[0] ) {
			$folder = trim( $folder, '/' );

			return '' === $folder ? '' : '/' . $folder;
		}

		$this->connection();
		$folder = trim( $folder, '/' );

		return $this->home . ( '' === $folder ? '' : '/' . $folder );
	}

	private function path( string $name ): string {
		return $this->folder() . '/' . $name;
	}

	private function ensure_folder(): void {
		$conn = $this->connection();
		$path = '';

		foreach ( array_filter( explode( '/', $this->folder() ) ) as $part ) {
			$path .= '/' . $part;

			if ( ! @ftp_chdir( $conn, $path ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- a missing folder is created next.
				@ftp_mkdir( $conn, $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- checked by the upload that follows.
			}
		}

		@ftp_chdir( $conn, '/' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
	}

	public function test(): array {
		try {
			$this->ensure_folder();
			$name  = '.wrensafe-test-' . wp_generate_password( 8, false ) . '.txt';
			$local = $this->temp_file( 'Wrensafe connection test' );
			$ok    = @ftp_put( $this->connection(), $this->path( $name ), $local, FTP_BINARY ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			wp_delete_file( $local );

			if ( ! $ok ) {
				return array(
					'ok'      => false,
					/* translators: %s: folder */
					'message' => sprintf( __( 'Connected, but couldn’t write to %s. Check the folder and its permissions.', 'wrensafe' ), $this->folder() ? $this->folder() : '/' ),
				);
			}

			@ftp_delete( $this->connection(), $this->path( $name ) ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged

			return array(
				'ok'      => true,
				'message' => __( 'Connected. A test file was written to the folder and removed.', 'wrensafe' ),
			);
		} catch ( \RuntimeException $e ) {
			return array(
				'ok'      => false,
				'message' => RemoteStorage::error_text( $e ),
			);
		}
	}

	public function begin( string $remote_name, int $size ): array {
		$this->ensure_folder();
		@ftp_delete( $this->connection(), $this->path( $remote_name . '.part' ) ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- an old unfinished copy may not exist.

		return array(
			'name'   => $remote_name,
			'size'   => $size,
			'offset' => 0,
		);
	}

	public function send( string $local_path, array &$state, float $deadline ): bool {
		$conn   = $this->connection();
		$part   = $this->path( $state['name'] . '.part' );
		$size   = (int) $state['size'];
		$remote = (int) @ftp_size( $conn, $part ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- -1 when the file doesn't exist yet.

		// Continue from what the server really has (an earlier request may
		// have been cut off mid-piece).
		$state['offset'] = max( 0, min( $remote, $size ) );

		$in = fopen( $local_path, 'rb' );

		if ( false === $in ) {
			throw new \RuntimeException( esc_html__( 'The backup file couldn’t be read.', 'wrensafe' ) );
		}

		fseek( $in, (int) $state['offset'] );

		while ( $state['offset'] < $size ) {
			$piece = (string) fread( $in, self::PIECE );

			if ( '' === $piece ) {
				break;
			}

			$tmp = $this->temp_file( $piece );
			$ok  = @ftp_append( $conn, $part, $tmp, FTP_BINARY ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- reported below.
			wp_delete_file( $tmp );

			if ( ! $ok ) {
				fclose( $in );
				throw new \RuntimeException( esc_html__( 'The FTP server stopped accepting the upload. Check the free space and the folder’s permissions.', 'wrensafe' ) );
			}

			$state['offset'] += strlen( $piece );

			if ( microtime( true ) >= $deadline ) {
				break;
			}
		}

		fclose( $in );

		return $state['offset'] >= $size;
	}

	public function finish( array &$state ): void {
		$conn  = $this->connection();
		$part  = $this->path( $state['name'] . '.part' );
		$final = $this->path( $state['name'] );

		if ( (int) @ftp_size( $conn, $part ) !== (int) $state['size'] ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			throw new \RuntimeException( esc_html__( 'The copy on the FTP server doesn’t have the same size as the backup.', 'wrensafe' ) );
		}

		@ftp_delete( $conn, $final ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- usually doesn't exist.

		if ( ! @ftp_rename( $conn, $part, $final ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			throw new \RuntimeException( esc_html__( 'The uploaded copy couldn’t be renamed on the FTP server.', 'wrensafe' ) );
		}
	}

	public function abort( array $state ): void {
		try {
			@ftp_delete( $this->connection(), $this->path( $state['name'] . '.part' ) ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		} catch ( \RuntimeException $e ) {
			unset( $e ); // Best effort.
		}
	}

	public function list_backups(): array {
		$conn  = $this->connection();
		$names = @ftp_nlist( $conn, '' !== $this->folder() ? $this->folder() : '/' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		$items = array();

		foreach ( is_array( $names ) ? $names : array() as $name ) {
			$base = basename( (string) $name );

			if ( ! str_ends_with( $base, '.wbp' ) ) {
				continue;
			}

			$items[] = array(
				'name' => $base,
				'time' => (int) @ftp_mdtm( $conn, $this->path( $base ) ), // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
				'size' => (int) @ftp_size( $conn, $this->path( $base ) ), // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			);
		}

		return RemoteStorage::newest_first( $items );
	}

	public function delete( string $name ): bool {
		return (bool) @ftp_delete( $this->connection(), $this->path( $name ) ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
	}

	private function temp_file( string $contents ): string {
		$dir = FileSystem::storage_dir() . 'tmp/';
		wp_mkdir_p( $dir );
		$path = $dir . 'remote-piece-' . wp_generate_password( 12, false ) . '.tmp';
		file_put_contents( $path, $contents );

		return $path;
	}
}

// phpcs:enable WordPress.WP.AlternativeFunctions
