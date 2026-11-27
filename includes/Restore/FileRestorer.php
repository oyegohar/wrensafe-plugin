<?php
/**
 * Extracts files from an archive entry name ("files/{root}/{relative}")
 * back to their real location on disk.
 *
 * @package WPBP\Restore
 */

namespace WPBP\Restore;

use WPBP\Security\PathValidator;
use WPBP\Utils\FileSystem;
use WPBP\Utils\Paths;

defined( 'ABSPATH' ) || exit;

// Archive entries and restore work files are streamed with native file handles
// (fopen/fread/fwrite/rename): WP_Filesystem has no streaming API. Every path is
// inside Wrensafe's protected storage directory or a destination resolved and
// validated by FileRestorer::resolve_destination().
// phpcs:disable WordPress.WP.AlternativeFunctions

class FileRestorer {

	/**
	 * Folders the source site kept inside its WordPress folder, mapped to the
	 * matching folder here when the two sites use different layouts:
	 * source path relative to the WordPress folder => destination folder,
	 * longest first. Empty when the layouts match (the usual case).
	 *
	 * @var array<string,string>
	 */
	private array $root_map = array();

	/**
	 * @param array<string,mixed> $source_layout The backup's metadata.json
	 *        (abspath, content_dir, uploads_basedir, plugins_dir,
	 *        mu_plugins_dir, themes_dir). Without it, "files/root/…" entries
	 *        are restored to the same place inside this site's WordPress folder.
	 */
	public function __construct( array $source_layout = array() ) {
		$this->root_map = self::build_root_map( $source_layout );
	}

	/**
	 * @param array<string,mixed> $layout
	 * @return array<string,string>
	 */
	private static function build_root_map( array $layout ): array {
		$abspath = rtrim( str_replace( '\\', '/', (string) ( $layout['abspath'] ?? '' ) ), '/' );

		if ( '' === $abspath ) {
			return array();
		}

		// Archives made before 1.0.1 only record the content and uploads
		// folders; their plugins and themes then follow the content folder.
		$sections = array(
			'content_dir'     => Paths::content_dir(),
			'uploads_basedir' => Paths::uploads_dir(),
			'plugins_dir'     => Paths::plugins_dir(),
			'mu_plugins_dir'  => Paths::mu_plugins_dir(),
			'themes_dir'      => Paths::themes_dir(),
		);

		$map = array();

		foreach ( $sections as $key => $destination ) {
			$source = rtrim( str_replace( '\\', '/', (string) ( $layout[ $key ] ?? '' ) ), '/' );

			if ( '' === $source || '' === $destination || 0 !== strpos( $source, $abspath . '/' ) ) {
				continue;
			}

			$relative = substr( $source, strlen( $abspath ) + 1 );

			if ( Paths::root() . '/' . $relative !== $destination ) {
				$map[ $relative ] = $destination;
			}
		}

		if ( empty( $map ) ) {
			return array();
		}

		// Same-layout sections stay as they are, but a more specific one
		// (plugins) must still win over a remapped parent (content).
		foreach ( $sections as $key => $destination ) {
			$source = rtrim( str_replace( '\\', '/', (string) ( $layout[ $key ] ?? '' ) ), '/' );

			if ( '' !== $source && '' !== $destination && 0 === strpos( $source, $abspath . '/' ) ) {
				$relative = substr( $source, strlen( $abspath ) + 1 );
				$map     += array( $relative => $destination );
			}
		}

		uksort(
			$map,
			static function ( $a, $b ) {
				return strlen( (string) $b ) - strlen( (string) $a );
			}
		);

		return $map;
	}

	/**
	 * Map a backup's root key back to a destination base path on this site.
	 * Mirrors FileScanner::get_roots_for_type() and FileScanner::site_roots()
	 * in reverse.
	 */
	public function get_destination_root( string $root_key ): ?string {
		switch ( $root_key ) {
			case 'uploads':
				return Paths::uploads_dir();

			case 'themes':
				return Paths::themes_dir();

			case 'plugins':
				return Paths::plugins_dir();

			case 'mu_plugins':
				return Paths::mu_plugins_dir();

			case 'root':
				return Paths::root();

			case 'content':
				// The content folder: full backups of sites that keep it outside
				// the WordPress folder, and imports from other backup plugins.
				return Paths::content_dir();

			default:
				// "custom_N" roots need their original path recorded at backup
				// time to be restorable; not yet exposed in the UI.
				return null;
		}
	}

	/**
	 * Where a "files/{root}/{relative}" archive entry belongs on this site,
	 * as [destination folder, path inside it], or null when the entry is
	 * malformed or belongs to an unknown root. No filesystem checks.
	 *
	 * @return array{0:string,1:string}|null
	 */
	private function locate( string $entry_name ): ?array {
		if ( 0 !== strpos( $entry_name, 'files/' ) ) {
			return null;
		}

		$parts = explode( '/', substr( $entry_name, strlen( 'files/' ) ), 2 );

		if ( count( $parts ) < 2 || '' === $parts[1] ) {
			return null;
		}

		list( $root_key, $relative_path ) = $parts;
		$destination_root                 = $this->get_destination_root( $root_key );
		$relative_path                    = PathValidator::sanitize_relative( $relative_path );

		if ( null === $destination_root || '' === $destination_root || '' === $relative_path ) {
			return null;
		}

		// A full backup of a site with another folder layout: put its
		// content, plugins, themes and uploads in this site's own folders.
		if ( 'root' === $root_key ) {
			foreach ( $this->root_map as $source_relative => $folder ) {
				$source_relative = (string) $source_relative;

				if ( 0 === strpos( $relative_path, $source_relative . '/' ) ) {
					return array( $folder, substr( $relative_path, strlen( $source_relative ) + 1 ) );
				}
			}
		}

		return array( $destination_root, $relative_path );
	}

	/**
	 * The absolute path an archive entry is restored to, without the
	 * traversal check of resolve_destination() (for read-only lookups).
	 */
	public function entry_path( string $entry_name ): ?string {
		$located = $this->locate( $entry_name );

		return null === $located ? null : $located[0] . '/' . $located[1];
	}

	/**
	 * Resolve a "files/{root}/{relative}" archive entry to its absolute
	 * destination path on this site, or null when the entry is malformed,
	 * belongs to an unknown root, or would escape its root (path traversal
	 * protection is delegated to PathValidator so every extraction point in
	 * the plugin rejects an unsafe path the same way).
	 */
	public function resolve_destination( string $entry_name ): ?string {
		$located = $this->locate( $entry_name );

		if ( null === $located ) {
			return null;
		}

		list( $destination_root, $relative_path ) = $located;

		// The must-use plugins folder doesn't exist on every site.
		if ( ! is_dir( $destination_root ) ) {
			wp_mkdir_p( $destination_root );
		}

		$destination = $destination_root . '/' . $relative_path;

		if ( ! PathValidator::assert_within( $destination, $destination_root ) ) {
			return null;
		}

		return $destination;
	}

	/**
	 * Why an archive entry must NOT be written to this site, or '' when it can
	 * be restored.
	 *
	 * - 'protected': never restored — wp-config.php (this server's database
	 *   credentials, table prefix and salts; restoring another site's copy
	 *   points this site at the SOURCE database), Wrensafe's own plugin
	 *   files (the code running this restore), its storage directory, and
	 *   the temporary restore boot guard.
	 * - 'server_specific': web-server/PHP configuration and cache drop-ins
	 *   that are tied to the server they were created on (PHP handlers,
	 *   Redis/Memcached object caches, page-cache paths). Restored only when
	 *   restoring a site onto itself; on a migration they can 500 or fatal
	 *   the destination, so the destination's own copies are kept.
	 */
	public function skip_reason( string $entry_name, bool $same_site ): string {
		$destination = $this->resolve_destination( $entry_name );

		if ( null === $destination ) {
			return '';
		}

		$destination = wp_normalize_path( $destination );

		foreach ( self::protected_prefixes() as $prefix ) {
			if ( 0 === strpos( $destination, $prefix ) ) {
				return 'protected';
			}
		}

		if ( Paths::is_config_file( $destination ) || in_array( $destination, self::protected_files(), true ) ) {
			return 'protected';
		}

		if ( ! $same_site && ( in_array( $destination, self::server_specific_files(), true ) || self::is_host_mu_plugin( $destination ) ) ) {
			return 'server_specific';
		}

		return '';
	}

	/**
	 * Directories (normalized, trailing slash) whose contents are never restored.
	 *
	 * @return string[]
	 */
	private static function protected_prefixes(): array {
		$prefixes = array(
			trailingslashit( wp_normalize_path( WPBP_PLUGIN_DIR ) ),
			Paths::content_dir() . '/' . WPBP_STORAGE_DIR_NAME . '/',
			Paths::uploads_dir() . '/' . WPBP_STORAGE_DIR_NAME . '/',
			Paths::content_dir() . '/' . WPBP_LEGACY_STORAGE_DIR_NAME . '/',
			Paths::uploads_dir() . '/' . WPBP_LEGACY_STORAGE_DIR_NAME . '/',
			trailingslashit( wp_normalize_path( FileSystem::storage_dir() ) ),
		);

		return array_values( array_unique( $prefixes ) );
	}

	/**
	 * Individual files (normalized) that are never restored.
	 *
	 * @return string[]
	 */
	private static function protected_files(): array {
		return array(
			Paths::mu_plugins_dir() . '/' . PluginSafety::GUARD_FILE_NAME,
			// A full backup made while a Safe Update runs contains its guard.
			Paths::mu_plugins_dir() . '/' . \WPBP\Updates\SafeUpdates::GUARD_FILE,
		);
	}

	/**
	 * Server-specific files (normalized) restored only onto the same site.
	 *
	 * @return string[]
	 */
	private static function server_specific_files(): array {
		$root    = trailingslashit( Paths::root() );
		$content = trailingslashit( Paths::content_dir() );

		return array(
			$root . '.htaccess',
			$root . '.user.ini',
			$root . 'php.ini',
			$root . 'web.config',
			$content . 'object-cache.php',
			$content . 'advanced-cache.php',
			$content . 'db.php',
		);
	}

	/**
	 * A managed host's must-use plugin (WP Engine, Kinsta, GoDaddy, …). They
	 * expect that host's configuration and fatal elsewhere — on every
	 * request, since must-use plugins can't be switched off — so they are
	 * restored only onto the same site.
	 */
	private static function is_host_mu_plugin( string $destination ): bool {
		$mu_dir = trailingslashit( Paths::mu_plugins_dir() );

		if ( 0 !== strpos( $destination, $mu_dir ) ) {
			return false;
		}

		$first = explode( '/', substr( $destination, strlen( $mu_dir ) ), 2 )[0];

		/**
		 * Filters the pattern of must-use plugin names (file or folder directly
		 * in mu-plugins) that belong to a hosting company.
		 *
		 * @param string $pattern Regular expression matched against the name.
		 */
		$pattern = (string) apply_filters( 'wpbp_host_mu_plugins_pattern', '/^(?:wpengine-.*|wpe-.*|mu-plugin\.php|slt-force-strong-passwords\.php|force-strong-passwords|kinsta-mu-plugins(?:\.php)?|gd-system-plugin(?:\.php)?|endurance-.*|sso\.php|pantheon(?:\.php)?|wpcomsh(?:-loader\.php)?|wp-stack-cache\.php|pressable-.*|sg-cachepress-.*|object-cache-pro(?:\.php)?|redis-cache-pro(?:\.php)?)$/i' );

		return '' !== $first && 1 === preg_match( $pattern, $first );
	}

	/**
	 * Restore a single "files/{root}/{relative}" archive entry to disk.
	 */
	public function restore_entry( ArchiveReader $reader, string $entry_name ): bool {
		$destination = $this->resolve_destination( $entry_name );

		if ( null === $destination ) {
			return false;
		}

		return $reader->extract_to( $entry_name, $destination );
	}

	/** Files above this size are copied in resumable slices when stored. */
	const LARGE_ENTRY_BYTES = 67108864; // 64 MB.

	/**
	 * Restore one entry, pausing a very large file at $deadline so it can be
	 * finished by the next request (a 2 GB video no longer has to be copied
	 * inside a single request). Returns true when the entry is restored,
	 * false when it failed, or null when it paused — $state then holds the
	 * progress and the caller must call again with the same entry.
	 *
	 * @param array<string,mixed> $state Progress of a paused large file.
	 */
	public function restore_entry_step( ArchiveReader $reader, string $entry_name, array &$state, float $deadline ): ?bool {
		$span = $reader->stored_entry_span( $entry_name );

		if ( null === $span && ( $reader->entry_size( $entry_name ) ?? 0 ) >= self::LARGE_ENTRY_BYTES ) {
			return $this->restore_compressed_step( $reader, $entry_name, $state, $deadline );
		}

		if ( null === $span || $span['size'] < self::LARGE_ENTRY_BYTES ) {
			$state = array();
			return $this->restore_entry( $reader, $entry_name );
		}

		$destination = $this->resolve_destination( $entry_name );

		if ( null === $destination ) {
			$state = array();
			return false;
		}

		$tmp = $destination . '.extracting.tmp';

		if ( ( $state['entry'] ?? '' ) !== $entry_name ) {
			wp_mkdir_p( dirname( $destination ) );
			$state = array(
				'entry'  => $entry_name,
				'copied' => 0,
			);
		}

		$in  = fopen( $reader->path(), 'rb' );
		$out = fopen( $tmp, 'c+b' );

		if ( false === $in || false === $out ) {
			$state = array();
			return false;
		}

		$copied = (int) $state['copied'];
		fseek( $in, $span['offset'] + $copied );
		ftruncate( $out, $copied );
		fseek( $out, $copied );

		while ( $copied < $span['size'] ) {
			$chunk = fread( $in, (int) min( 8388608, $span['size'] - $copied ) );

			if ( false === $chunk || '' === $chunk || fwrite( $out, $chunk ) !== strlen( $chunk ) ) {
				fclose( $in );
				fclose( $out );
				wp_delete_file( $tmp );
				$state = array();
				return false;
			}

			$copied += strlen( $chunk );

			if ( $copied < $span['size'] && microtime( true ) >= $deadline ) {
				fclose( $in );
				fclose( $out );
				$state['copied'] = $copied;
				return null;
			}
		}

		fclose( $in );
		fclose( $out );
		$state = array();

		return rename( $tmp, $destination );
	}

	/**
	 * A large compressed file (a big .sql or .log kept in wp-content) is
	 * unpacked in steps as well, continuing in the next request at $deadline.
	 *
	 * @param array<string,mixed> $state Progress of a paused large file.
	 */
	private function restore_compressed_step( ArchiveReader $reader, string $entry_name, array &$state, float $deadline ): ?bool {
		$destination = $this->resolve_destination( $entry_name );

		if ( null === $destination ) {
			$state = array();
			return false;
		}

		$tmp = $destination . '.extracting.tmp';

		if ( ( $state['entry'] ?? '' ) !== $entry_name ) {
			wp_mkdir_p( dirname( $destination ) );
			$state = array( 'entry' => $entry_name );
		}

		$result = $reader->extract_step( $entry_name, $tmp, $state, $deadline );

		if ( $result['failed'] ) {
			if ( file_exists( $tmp ) ) {
				wp_delete_file( $tmp );
			}

			$state = array();
			return false;
		}

		if ( ! $result['done'] ) {
			$state['position'] = $result['position'];
			$state['crc']      = $result['crc'];
			return null;
		}

		$state = array();

		return rename( $tmp, $destination );
	}
}

// phpcs:enable WordPress.WP.AlternativeFunctions
