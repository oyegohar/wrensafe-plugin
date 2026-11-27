<?php
/**
 * An "import set": the files of one backup made by another plugin, uploaded
 * together (UpdraftPlus and WPvivid split a backup over several files), or
 * found on this server (read where they are, see ServerBackups).
 *
 * @package WPBP\Import\Foreign
 */

namespace WPBP\Import\Foreign;

use WPBP\Utils\FileSystem;

defined( 'ABSPATH' ) || exit;

class ImportSet {

	/** File types accepted into a set. */
	const EXTENSIONS = array( 'wpress', 'zip', 'daf', 'tar', 'gz', 'tgz', 'bz2', 'sql', 'crypt' );

	/** In the set's folder: the files of a backup found on this server, read where they are (name => path). */
	const FOUND = 'found.json';

	public static function valid_id( string $id ): bool {
		return 1 === preg_match( '/^[a-f0-9]{32}$/', $id );
	}

	public static function new_id(): string {
		return bin2hex( random_bytes( 16 ) );
	}

	public static function dir( string $id ): string {
		return FileSystem::uploads_dir() . 'import-set-' . $id . '/';
	}

	/**
	 * A safe file name that keeps the backup's real extension:
	 * sanitize_file_name() turns "site.sql.gz" into "site.sql_.gz".
	 */
	public static function clean_name( string $filename ): string {
		return (string) preg_replace( '/\.sql_\.gz$/i', '.sql.gz', sanitize_file_name( $filename ) );
	}

	public static function allowed( string $filename ): bool {
		return in_array( strtolower( (string) pathinfo( $filename, PATHINFO_EXTENSION ) ), self::EXTENSIONS, true );
	}

	/** Move an assembled upload into the set. Returns the stored name. */
	public static function add( string $id, string $from, string $filename ): string {
		$dir = self::dir( $id );
		FileSystem::ensure_protected_directory( $dir );

		$name = self::clean_name( $filename );

		if ( ! rename( $from, $dir . $name ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename -- moving a finished upload inside protected storage.
			throw new \RuntimeException( esc_html__( 'The uploaded file could not be moved into place (check file permissions).', 'wrensafe' ) );
		}

		return $name;
	}

	/**
	 * Start a set with a backup found on this server (ServerBackups): the files stay where they are and are only
	 * read from there, never moved or deleted.
	 *
	 * @param array<string,string> $files File name => absolute path.
	 */
	public static function add_found( string $id, array $files ): void {
		$dir = self::dir( $id );
		FileSystem::ensure_protected_directory( $dir );

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- a small list in protected storage.
		if ( false === file_put_contents( $dir . self::FOUND, (string) wp_json_encode( $files ) ) ) {
			throw new \RuntimeException( esc_html__( 'Wrensafe couldn’t write to its storage folder (check file permissions).', 'wrensafe' ) );
		}
	}

	/** @return array<string,string> The found files still listed and still in their place. */
	private static function found( string $id ): array {
		$file = self::dir( $id ) . self::FOUND;

		if ( ! is_file( $file ) ) {
			return array();
		}

		$list  = json_decode( (string) file_get_contents( $file ), true ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- a small list in protected storage.
		$files = array();

		foreach ( is_array( $list ) ? $list : array() as $name => $path ) {
			if ( is_string( $name ) && is_string( $path ) && basename( $path ) === $name && ServerBackups::is_found_file( $path ) ) {
				$files[ $name ] = $path;
			}
		}

		return $files;
	}

	/** @return array<string,string> File name => absolute path. */
	public static function files( string $id ): array {
		$dir = self::dir( $id );

		if ( ! self::valid_id( $id ) || ! is_dir( $dir ) ) {
			return array();
		}

		$files = array();
		$names = scandir( $dir );

		// Not the folder's own protection files (index.php, .htaccess, web.config): only what was uploaded.
		foreach ( is_array( $names ) ? $names : array() as $name ) {
			if ( '' !== $name && '.' !== $name[0] && ! in_array( strtolower( $name ), array( 'index.php', 'web.config', self::FOUND ), true ) && is_file( $dir . $name ) ) {
				$files[ $name ] = $dir . $name;
			}
		}

		$files += self::found( $id );

		ksort( $files );

		return $files;
	}

	public static function size( string $id ): int {
		$total = 0;

		foreach ( self::files( $id ) as $path ) {
			$total += (int) filesize( $path );
		}

		return $total;
	}

	/** Remove one file from the set: an uploaded file is deleted, a found one only taken off the list. */
	public static function remove( string $id, string $name ): void {
		$files = self::files( $id );
		$found = self::found( $id );

		if ( isset( $found[ $name ] ) ) {
			unset( $found[ $name ] );
			self::add_found( $id, $found );
		} elseif ( isset( $files[ $name ] ) && 0 === strpos( $files[ $name ], self::dir( $id ) ) ) {
			wp_delete_file( $files[ $name ] );
		}
	}

	public static function delete( string $id ): void {
		if ( self::valid_id( $id ) ) {
			FileSystem::delete_directory( self::dir( $id ) );
		}
	}
}
