<?php
/**
 * An "import set": the files of one backup made by another plugin, uploaded
 * together (UpdraftPlus and WPvivid split a backup over several files).
 *
 * @package WPBP\Import\Foreign
 */

namespace WPBP\Import\Foreign;

use WPBP\Utils\FileSystem;

defined( 'ABSPATH' ) || exit;

class ImportSet {

	/** File types accepted into a set. */
	const EXTENSIONS = array( 'wpress', 'zip', 'daf', 'tar', 'gz', 'tgz', 'bz2', 'sql', 'crypt' );

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
			throw new \RuntimeException( __( 'The uploaded file could not be moved into place (check file permissions).', 'wrensafe' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
		}

		return $name;
	}

	/** @return array<string,string> File name => absolute path. */
	public static function files( string $id ): array {
		$dir = self::dir( $id );

		if ( ! self::valid_id( $id ) || ! is_dir( $dir ) ) {
			return array();
		}

		$files = array();

		foreach ( (array) scandir( $dir ) as $name ) {
			if ( '.' !== $name[0] && 'index.php' !== $name && is_file( $dir . $name ) ) {
				$files[ $name ] = $dir . $name;
			}
		}

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

	/** Remove one file from the set. */
	public static function remove( string $id, string $name ): void {
		$files = self::files( $id );

		if ( isset( $files[ $name ] ) ) {
			wp_delete_file( $files[ $name ] );
		}
	}

	public static function delete( string $id ): void {
		if ( self::valid_id( $id ) ) {
			FileSystem::delete_directory( self::dir( $id ) );
		}
	}
}
