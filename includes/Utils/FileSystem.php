<?php
/**
 * Filesystem helpers for protected plugin storage.
 *
 * @package WPBP\Utils
 */

namespace WPBP\Utils;

defined( 'ABSPATH' ) || exit;

// Backup archives and upload chunks are streamed in bounded blocks with native
// file handles (fopen/fread/fwrite/rename): WP_Filesystem has no streaming API.
// Every path is inside Wrensafe's protected storage directory, a PHP upload
// temp file, or validated by WPBP\Security\PathValidator.
// phpcs:disable WordPress.WP.AlternativeFunctions

class FileSystem {

	/**
	 * The .htaccess written in every folder Wrensafe protects. It works on
	 * Apache 2.4 (mod_authz_core) and 2.2 or 2.4 with mod_access_compat, and
	 * on LiteSpeed; it has no Options line, which a host may not allow (a
	 * 500 error). Nginx ignores .htaccess files: see SiteHealth.
	 */
	public const HTACCESS = "# Wrensafe: no web access to this folder.\n<IfModule mod_authz_core.c>\n\tRequire all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\n\tOrder deny,allow\n\tDeny from all\n</IfModule>\n";

	/**
	 * The same for IIS: request filtering (built into IIS 7+) refuses every
	 * file in the folder.
	 */
	public const WEB_CONFIG = "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<configuration>\n\t<system.webServer>\n\t\t<security>\n\t\t\t<requestFiltering>\n\t\t\t\t<fileExtensions allowUnlisted=\"false\" />\n\t\t\t</requestFiltering>\n\t\t</security>\n\t</system.webServer>\n</configuration>\n";

	public const INDEX_PHP = "<?php\n// Silence is golden.\n";

	/**
	 * .htaccess files earlier versions wrote. Each fails with a 500 error on
	 * some Apache setups, so they are replaced (see upgrade_protection()).
	 */
	private const OLD_HTACCESS = array(
		"Require all denied\nDeny from all\n",
		"Options -Indexes\nDeny from all\n",
	);

	/** Raise when the protection files change, to rewrite them once. */
	private const PROTECTION_VERSION = '2';

	/** @var string|null The custom folder checked once per request. */
	private static ?string $custom_dir_cache = null;

	/**
	 * Absolute path to the plugin's protected storage directory: the folder
	 * chosen in Settings → Backup, or the default one.
	 */
	public static function storage_dir(): string {
		$custom = self::custom_storage_dir();

		return '' !== $custom ? $custom : self::default_storage_dir();
	}

	/**
	 * The default storage folder: WPBP_STORAGE_DIR_NAME in the content folder
	 * (or in uploads when the content folder isn't writable).
	 */
	public static function default_storage_dir(): string {
		$base = Paths::content_dir() . '/' . WPBP_STORAGE_DIR_NAME;

		if ( ! is_writable( Paths::content_dir() ) && '' !== Paths::uploads_dir() ) {
			$base = Paths::uploads_dir() . '/' . WPBP_STORAGE_DIR_NAME;
		}

		return trailingslashit( wp_normalize_path( $base ) );
	}

	/**
	 * The folder chosen in Settings → Backup, when it is set and usable;
	 * '' otherwise (backups then go to the default folder).
	 */
	public static function custom_storage_dir(): string {
		if ( null === self::$custom_dir_cache ) {
			$dir = (string) \WPBP\Core\Settings::get( 'backup_dir', '' );
			$dir = '' !== $dir ? trailingslashit( wp_normalize_path( $dir ) ) : '';

			self::$custom_dir_cache = ( '' !== $dir && is_dir( $dir ) && wp_is_writable( $dir ) ) ? $dir : '';
		}

		return self::$custom_dir_cache;
	}

	/**
	 * Whether a file is inside one of Wrensafe's backup folders: the one in
	 * use, the default one, or the one chosen in Settings → Backup (backups
	 * that couldn't be moved when the folder changed stay where they were).
	 */
	public static function is_backup_file_path( string $path ): bool {
		$roots = array( self::backups_dir(), self::default_storage_dir() . 'backups/' );

		foreach ( self::legacy_storage_dirs() as $legacy ) {
			$roots[] = $legacy . 'backups/';
		}

		$dir = (string) \WPBP\Core\Settings::get( 'backup_dir', '' );

		if ( '' !== $dir ) {
			$roots[] = trailingslashit( wp_normalize_path( $dir ) ) . 'backups/';
		}

		foreach ( array_unique( $roots ) as $root ) {
			if ( \WPBP\Security\PathValidator::assert_within( $path, $root ) ) {
				return true;
			}
		}

		return false;
	}

	/** Forget the cached folder (after the setting changes). */
	public static function reset_storage_cache(): void {
		self::$custom_dir_cache = null;
	}

	/**
	 * Check a folder someone wants to keep backups in.
	 *
	 * @param string $path   Absolute path.
	 * @param bool   $create Create the folder when it doesn't exist.
	 * @return array{ok:bool,path:string,message:string,inside_site:bool}
	 */
	public static function check_storage_dir( string $path, bool $create ): array {
		$path   = untrailingslashit( wp_normalize_path( trim( $path ) ) );
		$result = array(
			'ok'          => false,
			'path'        => $path,
			'message'     => '',
			'inside_site' => false,
		);

		if ( '' === $path || ! path_is_absolute( $path ) || false !== strpos( $path, '..' ) || preg_match( '/[\x00-\x1f]/', $path ) || wp_is_stream( $path ) ) {
			$result['message'] = __( 'Enter the full path of a folder on this server, for example /home/user/wrensafe-backups.', 'wrensafe' );
			return $result;
		}

		// Folders that must not hold backups: WordPress's own folders, and
		// anything inside its code folders (replaced by every update).
		$code      = array_merge( array( Paths::admin_dir(), Paths::includes_dir(), Paths::plugins_dir(), Paths::mu_plugins_dir() ), Paths::theme_dirs() );
		$protected = array_merge( array( Paths::root(), Paths::content_dir(), Paths::uploads_dir() ), $code );

		foreach ( $protected as $dir ) {
			if ( '' === $dir ) {
				continue;
			}

			if ( $path === $dir || ( in_array( $dir, $code, true ) && Paths::is_within( $path, $dir ) ) ) {
				$result['message'] = __( 'Choose a folder of its own, not the WordPress folder or one of its main folders (wp-admin, wp-includes, plugins, themes, uploads).', 'wrensafe' );
				return $result;
			}
		}

		if ( in_array( $path, array( '', '/', '/bin', '/boot', '/dev', '/etc', '/lib', '/proc', '/root', '/sbin', '/sys', '/usr', '/var' ), true ) ) {
			$result['message'] = __( 'Choose a folder of its own, not a system folder.', 'wrensafe' );
			return $result;
		}

		$result['inside_site'] = Paths::is_within( $path, Paths::root() );

		if ( ! is_dir( $path ) ) {
			if ( ! $create ) {
				$parent            = dirname( $path );
				$result['ok']      = is_dir( $parent ) && wp_is_writable( $parent );
				$result['message'] = $result['ok']
					? __( 'This folder doesn’t exist yet. Wrensafe creates it when you save.', 'wrensafe' )
					: __( 'This folder doesn’t exist, and Wrensafe can’t create it there. Check the path, or ask your host which folders WordPress may write to.', 'wrensafe' );
				return $result;
			}

			if ( ! wp_mkdir_p( $path ) ) {
				$result['message'] = __( 'Wrensafe couldn’t create this folder. Check the path, or ask your host which folders WordPress may write to.', 'wrensafe' );
				return $result;
			}
		}

		if ( ! wp_is_writable( $path ) ) {
			$result['message'] = __( 'WordPress can’t write to this folder. Change its permissions, or choose another folder.', 'wrensafe' );
			return $result;
		}

		$free = function_exists( 'disk_free_space' ) ? @disk_free_space( $path ) : false; // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Some hosts disable or restrict it.

		$result['ok']      = true;
		$result['message'] = false !== $free
			/* translators: %s: free disk space, such as "12 GB" */
			? sprintf( __( 'This folder can be used. Free space: %s.', 'wrensafe' ), size_format( (float) $free, 1 ) )
			: __( 'This folder can be used.', 'wrensafe' );

		return $result;
	}

	/**
	 * Move every backup kept in one of Wrensafe's folders (the one in use,
	 * the default one, and $from_base) into $to_base. On the same disk this
	 * is an instant rename. Backups that can't be moved stay where they are
	 * and keep working.
	 *
	 * @return array{moved:int,kept:int}
	 */
	public static function move_backups( string $from_base, string $to_base ): array {
		$to     = trailingslashit( wp_normalize_path( $to_base ) ) . 'backups/';
		$counts = array(
			'moved' => 0,
			'kept'  => 0,
		);
		$froms  = array(
			trailingslashit( wp_normalize_path( $from_base ) ) . 'backups/',
			self::backups_dir(),
			self::default_storage_dir() . 'backups/',
		);

		foreach ( self::legacy_storage_dirs() as $legacy ) {
			$froms[] = $legacy . 'backups/';
		}

		$froms = array_diff( array_unique( $froms ), array( $to ) );

		if ( ! $froms ) {
			return $counts;
		}

		self::ensure_protected_directory( $to );

		foreach ( \WPBP\Core\Database\BackupsRepository::list_all( 100000 ) as $backup ) {
			$old  = wp_normalize_path( (string) $backup['file_path'] );
			$ours = false;

			foreach ( $froms as $from ) {
				$ours = $ours || 0 === strpos( $old, $from );
			}

			if ( ! $ours || ! file_exists( $old ) ) {
				continue;
			}

			$new = $to . basename( $old );

			if ( ! file_exists( $new ) && @rename( $old, $new ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- A failed move is counted and reported.
				\WPBP\Core\Database\BackupsRepository::update( (int) $backup['id'], array( 'file_path' => $new ) );
				++$counts['moved'];
			} else {
				++$counts['kept'];
			}
		}

		return $counts;
	}

	/**
	 * Storage folders of the plugin's earlier name that still exist (in the
	 * content folder or in uploads), normalized with a trailing slash.
	 *
	 * @return string[]
	 */
	public static function legacy_storage_dirs(): array {
		$dirs = array();

		foreach ( array( Paths::content_dir(), Paths::uploads_dir() ) as $parent ) {
			$dir = $parent . '/' . WPBP_LEGACY_STORAGE_DIR_NAME;

			if ( '' !== $parent && is_dir( $dir ) ) {
				$dirs[] = trailingslashit( wp_normalize_path( $dir ) );
			}
		}

		return array_values( array_unique( $dirs ) );
	}

	/**
	 * Move backups from the folder the plugin used before its rename to the
	 * current one, once. The whole folder is renamed when the new one
	 * doesn't exist yet (one step, same disk); otherwise the backups are
	 * moved one by one. The backup list is updated either way, and a folder
	 * that can't be moved stays usable (is_backup_file_path() accepts it).
	 */
	public static function adopt_legacy_storage(): void {
		// Runs early on every request (Plugin::boot()); cheap after the first time.
		self::upgrade_protection();

		if ( get_option( 'wpbp_legacy_storage_adopted' ) ) {
			return;
		}

		foreach ( self::legacy_storage_dirs() as $legacy ) {
			$target = self::default_storage_dir();

			if ( ! file_exists( $target ) && dirname( untrailingslashit( $legacy ) ) === dirname( untrailingslashit( $target ) ) && @rename( untrailingslashit( $legacy ), untrailingslashit( $target ) ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- A failed rename falls back to moving each backup.
				foreach ( \WPBP\Core\Database\BackupsRepository::list_all( 100000 ) as $backup ) {
					$old = wp_normalize_path( (string) $backup['file_path'] );

					if ( 0 === strpos( $old, $legacy ) ) {
						\WPBP\Core\Database\BackupsRepository::update( (int) $backup['id'], array( 'file_path' => $target . substr( $old, strlen( $legacy ) ) ) );
					}
				}

				Logger::info( 'legacy_storage_renamed: ' . $legacy . ' → ' . $target );
				continue;
			}

			$counts = self::move_backups( $legacy, self::storage_dir() );
			Logger::info( sprintf( 'legacy_storage_moved: %d backup(s) moved from %s, %d kept there.', $counts['moved'], $legacy, $counts['kept'] ) );
		}

		update_option( 'wpbp_legacy_storage_adopted', 1 );
	}

	/**
	 * Ensure a directory exists and is protected from direct web access.
	 */
	public static function ensure_protected_directory( string $path ): bool {
		$path = trailingslashit( $path );

		if ( ! file_exists( $path ) ) {
			if ( ! wp_mkdir_p( $path ) ) {
				return false;
			}
		}

		self::write_protection_files( $path );

		return true;
	}

	/**
	 * Write index.php, .htaccess and web.config into an existing folder.
	 * A missing file is written; an .htaccess an earlier version wrote is
	 * replaced; any other existing file (a site's own .htaccess in a folder
	 * chosen in Settings → Backup) is left as it is.
	 */
	public static function write_protection_files( string $dir ): void {
		$dir = trailingslashit( $dir );

		if ( ! is_dir( $dir ) ) {
			return;
		}

		if ( ! file_exists( $dir . 'index.php' ) ) {
			file_put_contents( $dir . 'index.php', self::INDEX_PHP );
		}

		if ( ! file_exists( $dir . '.htaccess' ) || self::has_old_htaccess( $dir ) ) {
			file_put_contents( $dir . '.htaccess', self::HTACCESS );
		}

		if ( ! file_exists( $dir . 'web.config' ) ) {
			file_put_contents( $dir . 'web.config', self::WEB_CONFIG );
		}
	}

	/** Whether $dir has an .htaccess written by a version before 1.1.4. */
	public static function has_old_htaccess( string $dir ): bool {
		$file = trailingslashit( $dir ) . '.htaccess';

		if ( ! is_file( $file ) || filesize( $file ) > 200 ) {
			return false;
		}

		$body = str_replace( "\r\n", "\n", (string) file_get_contents( $file ) );

		return in_array( $body, self::OLD_HTACCESS, true );
	}

	/**
	 * Once per change of the protection files: rewrite them in the storage
	 * folders that exist (the one in use and the default one), their
	 * sub-folders and the job folders in tmp/.
	 */
	public static function upgrade_protection(): void {
		if ( self::PROTECTION_VERSION === get_option( 'wpbp_protection_version' ) ) {
			return;
		}

		// Set first: a folder that can't be written isn't retried on every request.
		update_option( 'wpbp_protection_version', self::PROTECTION_VERSION );

		foreach ( array_unique( array( self::storage_dir(), self::default_storage_dir() ) ) as $base ) {
			if ( ! is_dir( $base ) ) {
				continue;
			}

			$dirs = array_merge(
				array( $base ),
				(array) glob( $base . '*', GLOB_ONLYDIR ),
				(array) glob( $base . 'tmp/*', GLOB_ONLYDIR )
			);

			foreach ( $dirs as $dir ) {
				$dir = trailingslashit( wp_normalize_path( (string) $dir ) );

				// Only folders Wrensafe protected before (and the base itself).
				if ( $dir === $base || file_exists( $dir . '.htaccess' ) || file_exists( $dir . 'index.php' ) ) {
					self::write_protection_files( $dir );
				}
			}
		}
	}

	/**
	 * Ensure the main storage directory and its sub-folders are created and protected.
	 */
	public static function ensure_storage_structure(): void {
		$base = self::storage_dir();

		self::ensure_protected_directory( $base );
		self::ensure_protected_directory( $base . 'backups' );
		self::ensure_protected_directory( $base . 'logs' );
		self::ensure_protected_directory( $base . 'tmp' );
		self::ensure_protected_directory( $base . 'uploads' );
	}

	/**
	 * Absolute path to the directory holding in-progress chunked uploads.
	 */
	public static function uploads_dir(): string {
		return self::storage_dir() . 'uploads/';
	}

	/**
	 * Absolute path to a backup job's scratch working directory.
	 */
	public static function job_tmp_dir( int $job_id ): string {
		$base = self::storage_dir() . 'tmp/';

		// A job started before 1.1.4 keeps the folder it already has.
		if ( is_dir( $base . 'job-' . $job_id ) ) {
			return $base . 'job-' . $job_id . '/';
		}

		// The folder holds database dumps while a job runs. Its name can't be
		// guessed, so a web server that ignores .htaccess (nginx) can't serve
		// them. The key comes from wp-config.php, which a restore never
		// replaces, so the name stays the same for the whole job.
		$key = ( defined( 'AUTH_KEY' ) ? (string) AUTH_KEY : '' ) . ( defined( 'SECURE_AUTH_KEY' ) ? (string) SECURE_AUTH_KEY : '' );

		if ( strlen( $key ) < 32 ) {
			$key .= wp_salt( 'auth' );
		}

		return $base . 'job-' . $job_id . '-' . substr( hash_hmac( 'sha256', 'wpbp-job-' . $job_id, $key ), 0, 32 ) . '/';
	}

	/**
	 * Absolute path to the directory finished .wbp archives are stored in.
	 */
	public static function backups_dir(): string {
		return self::storage_dir() . 'backups/';
	}

	/**
	 * Recursively delete a directory and everything inside it.
	 */
	public static function delete_directory( string $path ): void {
		if ( ! is_dir( $path ) ) {
			return;
		}

		$iterator = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator( $path, \FilesystemIterator::SKIP_DOTS ),
			\RecursiveIteratorIterator::CHILD_FIRST
		);

		foreach ( $iterator as $item ) {
			$item->isDir() ? rmdir( $item->getPathname() ) : wp_delete_file( $item->getPathname() );
		}

		rmdir( $path );
	}
}

// phpcs:enable WordPress.WP.AlternativeFunctions
