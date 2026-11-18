<?php
/**
 * Ensures Wrensafe's storage directory is protected from direct
 * web access by writing an .htaccess deny-all rule and an index.php stub.
 *
 * FileSystem::ensure_protected_directory() already calls these writes at
 * directory-creation time. FileGuard::status() lets the admin UI verify
 * that protection is still in place.
 *
 * @package WPBP\Security
 */

namespace WPBP\Security;

use WPBP\Utils\FileSystem;

defined( 'ABSPATH' ) || exit;

class FileGuard {

	private const HTACCESS_CONTENT = "Options -Indexes\nDeny from all\n";
	private const INDEX_CONTENT    = "<?php // Silence is golden.\n";

	/**
	 * (Re-)write protection files in the storage directory.
	 */
	public static function protect_storage(): void {
		self::protect_directory( FileSystem::storage_dir() );
	}

	/**
	 * Write .htaccess and index.php into an arbitrary directory.
	 */
	public static function protect_directory( string $dir ): void {
		if ( ! is_dir( $dir ) ) {
			return;
		}

		$htaccess = $dir . '.htaccess';
		$index    = $dir . 'index.php';

		if ( ! file_exists( $htaccess ) ) {
			file_put_contents( $htaccess, self::HTACCESS_CONTENT ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		}

		if ( ! file_exists( $index ) ) {
			file_put_contents( $index, self::INDEX_CONTENT ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		}
	}

	/**
	 * Whether the storage directory's protection files are present.
	 *
	 * @return array{htaccess:bool, index_php:bool, protected:bool}
	 */
	public static function status(): array {
		$dir      = FileSystem::storage_dir();
		$htaccess = file_exists( $dir . '.htaccess' );
		$index    = file_exists( $dir . 'index.php' );

		return array(
			'htaccess'  => $htaccess,
			'index_php' => $index,
			'protected' => $htaccess && $index,
		);
	}

	/**
	 * Repair protection if any file is missing.
	 */
	public static function repair_if_needed(): void {
		$status = self::status();

		if ( ! $status['protected'] ) {
			self::protect_storage();
		}
	}
}
