<?php
/**
 * SFTP storage, with PHP's SSH2 extension (ssh2_*).
 *
 * The backup is written in pieces to "<name>.part" over the ssh2.sftp://
 * stream wrapper, continuing from the size the server reports, then renamed
 * to its final name.
 *
 * @package WPBP\Remote
 */

namespace WPBP\Remote;

defined( 'ABSPATH' ) || exit;

// Backup archives are streamed in bounded pieces with native file handles
// (fopen/fread/fwrite) to the ssh2.sftp:// wrapper: WP_Filesystem has no
// streaming API and no SFTP stream support.
// phpcs:disable WordPress.WP.AlternativeFunctions

final class SftpTarget implements RemoteTarget {

	private const PIECE = 1048576; // 1 MB writes.

	/** @var array<string,mixed> */
	private array $config;

	/** @var resource|null */
	private $sftp = null;

	/** The account's home folder ('' for the root). */
	private string $home = '';

	/**
	 * @param array<string,mixed> $config host, port, username, password, folder.
	 */
	public function __construct( array $config ) {
		$this->config = $config;
	}

	public static function unavailable_reason(): string {
		return function_exists( 'ssh2_connect' ) ? '' : __( 'SFTP needs the PHP SSH2 extension, which this server doesn’t have. Ask your host to turn it on, or use FTP with TLS or S3-compatible storage.', 'wrensafe' );
	}

	/** @return resource */
	private function sftp() {
		if ( $this->sftp ) {
			return $this->sftp;
		}

		$host    = (string) ( $this->config['host'] ?? '' );
		$port    = (int) ( $this->config['port'] ?? 22 );
		$session = @ssh2_connect( $host, $port ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- reported below.

		if ( ! $session ) {
			/* translators: %s: host and port */
			throw new \RuntimeException( esc_html( sprintf( __( 'Couldn’t connect to %s. Check the host name and port.', 'wrensafe' ), $host . ':' . $port ) ) );
		}

		if ( ! @ssh2_auth_password( $session, (string) ( $this->config['username'] ?? '' ), (string) ( $this->config['password'] ?? '' ) ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			throw new \RuntimeException( esc_html__( 'The SFTP server didn’t accept the username or password.', 'wrensafe' ) );
		}

		$sftp = @ssh2_sftp( $session ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged

		if ( ! $sftp ) {
			throw new \RuntimeException( esc_html__( 'The server accepted the login but didn’t start SFTP.', 'wrensafe' ) );
		}

		$this->sftp = $sftp;
		$this->home = rtrim( (string) @ssh2_sftp_realpath( $sftp, '.' ), '/' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- '' (the root) when the server doesn't say.

		return $sftp;
	}

	/**
	 * The folder as a full path ('' for the root). A folder without a
	 * leading slash is inside the account's home folder.
	 */
	private function folder(): string {
		$folder = trim( str_replace( '\\', '/', (string) ( $this->config['folder'] ?? '' ) ) );

		if ( '' !== $folder && '/' === $folder[0] ) {
			$folder = trim( $folder, '/' );

			return '' === $folder ? '' : '/' . $folder;
		}

		$this->sftp();
		$folder = trim( $folder, '/' );

		return $this->home . ( '' === $folder ? '' : '/' . $folder );
	}

	private function url( string $path ): string {
		return 'ssh2.sftp://' . intval( $this->sftp() ) . $path;
	}

	private function path( string $name ): string {
		return $this->folder() . '/' . $name;
	}

	private function ensure_folder(): void {
		if ( '' !== $this->folder() && ! is_dir( $this->url( $this->folder() ) ) ) {
			@ssh2_sftp_mkdir( $this->sftp(), $this->folder(), 0755, true ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- checked by the write that follows.
		}
	}

	private function remote_size( string $path ): int {
		$stat = @ssh2_sftp_stat( $this->sftp(), $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- false when the file doesn't exist.

		return is_array( $stat ) ? (int) $stat['size'] : -1;
	}

	public function test(): array {
		try {
			$this->ensure_folder();
			$path = $this->path( '.wrensafe-test-' . wp_generate_password( 8, false ) . '.txt' );

			if ( false === @file_put_contents( $this->url( $path ), 'Wrensafe connection test' ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
				return array(
					'ok'      => false,
					/* translators: %s: folder */
					'message' => sprintf( __( 'Connected, but couldn’t write to %s. Check the folder and its permissions.', 'wrensafe' ), $this->folder() ? $this->folder() : '/' ),
				);
			}

			@ssh2_sftp_unlink( $this->sftp(), $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged

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
		$part = $this->path( $remote_name . '.part' );
		$out  = @fopen( $this->url( $part ), 'w' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- reported below.

		if ( false === $out ) {
			throw new \RuntimeException( esc_html__( 'Couldn’t create the file on the SFTP server. Check the folder and its permissions.', 'wrensafe' ) );
		}

		fclose( $out );

		return array(
			'name'   => $remote_name,
			'size'   => $size,
			'offset' => 0,
		);
	}

	public function send( string $local_path, array &$state, float $deadline ): bool {
		$part = $this->path( $state['name'] . '.part' );
		$size = (int) $state['size'];

		// Continue from what the server really has.
		$state['offset'] = max( 0, min( $this->remote_size( $part ), $size ) );

		// Write at the offset: OpenSSH's SFTP server doesn't support append mode.
		$in  = fopen( $local_path, 'rb' );
		$out = @fopen( $this->url( $part ), 'r+' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- reported below.

		if ( false === $in || false === $out || 0 !== fseek( $out, (int) $state['offset'] ) ) {
			throw new \RuntimeException( esc_html__( 'The upload to the SFTP server couldn’t continue.', 'wrensafe' ) );
		}

		fseek( $in, (int) $state['offset'] );

		while ( $state['offset'] < $size ) {
			$piece = (string) fread( $in, self::PIECE );

			if ( '' === $piece ) {
				break;
			}

			$written = 0;
			$length  = strlen( $piece );

			while ( $written < $length ) {
				$n = fwrite( $out, substr( $piece, $written ) );

				if ( false === $n || 0 === $n ) {
					fclose( $in );
					fclose( $out );
					throw new \RuntimeException( esc_html__( 'The SFTP server stopped accepting the upload. Check the free space there.', 'wrensafe' ) );
				}

				$written += $n;
			}

			$state['offset'] += $written;

			if ( microtime( true ) >= $deadline ) {
				break;
			}
		}

		fclose( $in );
		fclose( $out );

		return $state['offset'] >= $size;
	}

	public function finish( array &$state ): void {
		$part  = $this->path( $state['name'] . '.part' );
		$final = $this->path( $state['name'] );

		if ( $this->remote_size( $part ) !== (int) $state['size'] ) {
			throw new \RuntimeException( esc_html__( 'The copy on the SFTP server doesn’t have the same size as the backup.', 'wrensafe' ) );
		}

		@ssh2_sftp_unlink( $this->sftp(), $final ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- usually doesn't exist.

		if ( ! @ssh2_sftp_rename( $this->sftp(), $part, $final ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			throw new \RuntimeException( esc_html__( 'The uploaded copy couldn’t be renamed on the SFTP server.', 'wrensafe' ) );
		}
	}

	public function abort( array $state ): void {
		try {
			@ssh2_sftp_unlink( $this->sftp(), $this->path( $state['name'] . '.part' ) ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		} catch ( \RuntimeException $e ) {
			unset( $e ); // Best effort.
		}
	}

	public function list_backups(): array {
		$dir   = '' !== $this->folder() ? $this->folder() : '/';
		$names = @scandir( $this->url( $dir ) ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		$items = array();

		foreach ( is_array( $names ) ? $names : array() as $name ) {
			if ( ! str_ends_with( (string) $name, '.wbp' ) ) {
				continue;
			}

			$stat    = @ssh2_sftp_stat( $this->sftp(), $this->path( $name ) ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			$items[] = array(
				'name' => (string) $name,
				'time' => is_array( $stat ) ? (int) $stat['mtime'] : 0,
				'size' => is_array( $stat ) ? (int) $stat['size'] : 0,
			);
		}

		return RemoteStorage::newest_first( $items );
	}

	public function delete( string $name ): bool {
		return (bool) @ssh2_sftp_unlink( $this->sftp(), $this->path( $name ) ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
	}
}

// phpcs:enable WordPress.WP.AlternativeFunctions
