<?php
/**
 * Ensures Wrensafe's storage directory is protected from direct web
 * access: an .htaccess deny-all rule (Apache, LiteSpeed), a web.config
 * (IIS) and an index.php stub.
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

	/**
	 * (Re-)write protection files in the storage directory.
	 */
	public static function protect_storage(): void {
		self::protect_directory( FileSystem::storage_dir() );
	}

	/**
	 * Write index.php, .htaccess and web.config into a directory (the same
	 * files as FileSystem::ensure_protected_directory()).
	 */
	public static function protect_directory( string $dir ): void {
		FileSystem::write_protection_files( $dir );
	}

	/**
	 * Whether the storage directory's protection files are present (an
	 * .htaccess from before 1.1.4 counts as missing: it is replaced).
	 *
	 * @return array{htaccess:bool, index_php:bool, web_config:bool, protected:bool}
	 */
	public static function status(): array {
		$dir      = FileSystem::storage_dir();
		$htaccess = file_exists( $dir . '.htaccess' ) && ! FileSystem::has_old_htaccess( $dir );
		$index    = file_exists( $dir . 'index.php' );
		$config   = file_exists( $dir . 'web.config' );

		return array(
			'htaccess'   => $htaccess,
			'index_php'  => $index,
			'web_config' => $config,
			'protected'  => $htaccess && $index && $config,
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
