<?php
/**
 * Walks the filesystem for a given backup type and streams matching files
 * to a manifest file, memory-safe regardless of site size.
 *
 * @package WPBP\Backup
 */

namespace WPBP\Backup;

use WPBP\Core\Settings;
use WPBP\Utils\FileSystem;
use WPBP\Utils\Logger;
use WPBP\Utils\Paths;

defined( 'ABSPATH' ) || exit;

// Backup archives and upload chunks are streamed in bounded blocks with native
// file handles (fopen/fread/fwrite/rename): WP_Filesystem has no streaming API.
// Every path is inside Wrensafe's protected storage directory, a PHP upload
// temp file, or validated by WPBP\Security\PathValidator.
// phpcs:disable WordPress.WP.AlternativeFunctions

class FileScanner {

	private const LARGE_FILE_WARNING_BYTES = 524288000; // 500MB.

	/** A backup archive above this size gets its own exclusion log line. */
	private const LARGE_ARCHIVE_BYTES = 52428800; // 50MB.

	/** Per-root stats for the exclusion summary log. */
	private int $excluded_count        = 0;
	private int $excluded_size         = 0;
	private int $large_archives_logged = 0;

	/**
	 * Directory names skipped wherever they appear: Wrensafe's own
	 * storage and version-control metadata (never needed at runtime).
	 */
	private function excluded_dir_names(): array {
		return array( WPBP_STORAGE_DIR_NAME, WPBP_LEGACY_STORAGE_DIR_NAME, '.git', '.svn', '.hg' );
	}

	/** @var string[]|null Cached plugin/mu-plugin/theme directories. */
	private ?array $code_dirs_cache = null;

	/** @var string[]|null Cached custom excluded path prefixes. */
	private ?array $custom_paths_cache = null;

	/** @var string[]|null Cached excluded extensions. */
	private ?array $extensions_cache = null;

	/** @var array<string,string[]>|null Cached absolute exclusion locations. */
	private ?array $location_cache = null;

	/**
	 * Absolute directory locations (normalized, trailing slash) that are
	 * excluded, per reason.
	 *
	 * These are matched by LOCATION, never by a folder name anywhere in the
	 * tree: plugins and themes legitimately ship code in folders called
	 * "cache" or "backup" (Yoast SEO src/…/cache/, Elementor
	 * modules/interactions/cache/, Wrensafe's own includes/Backup/), and
	 * skipping those by name left restored sites fataling on missing
	 * autoloaded class files.
	 *
	 * @return array<string,string[]>
	 */
	private function excluded_locations(): array {
		if ( null !== $this->location_cache ) {
			return $this->location_cache;
		}

		$root    = trailingslashit( Paths::root() );
		$content = trailingslashit( Paths::content_dir() );
		$uploads = trailingslashit( Paths::uploads_dir() );

		$locations = array(
			'dir'        => array(),
			'backup_dir' => array(),
		);

		// Wrensafe's own storage, wherever it is (Settings → Backup), and
		// the default folder, which can still hold backups made before.
		$locations['dir'][] = trailingslashit( wp_normalize_path( FileSystem::storage_dir() ) );
		$locations['dir'][] = trailingslashit( wp_normalize_path( FileSystem::default_storage_dir() ) );

		// Regenerated automatically by the cache/security plugin that owns them.
		if ( Settings::get( 'exclude_cache_folders', true ) ) {
			foreach ( array( 'cache', 'et-cache', 'litespeed', 'wflogs' ) as $name ) {
				$locations['dir'][] = $content . $name . '/';
			}
		}

		// Storage folders of other backup/migration plugins.
		if ( Settings::get( 'exclude_backup_archives', true ) ) {
			foreach ( array( 'ai1wm-backups', 'updraft', 'wpvividbackups', 'backups-dup-lite', 'backups-dup-pro', 'backup', 'backups', 'backup-db' ) as $name ) {
				$locations['backup_dir'][] = $content . $name . '/';
			}

			foreach ( array( 'backup', 'backups', 'wpvividbackups' ) as $name ) {
				$locations['backup_dir'][] = $uploads . $name . '/';
			}

			$locations['backup_dir'][] = $root . 'wp-snapshots/';
		}

		$this->location_cache = $locations;

		return $locations;
	}

	/**
	 * Whether a normalized absolute path is inside an installed plugin or
	 * theme. Their contents are always backed up completely (except VCS
	 * metadata), because any missing shipped file can fatal the restored site.
	 */
	private function is_inside_plugin_or_theme( string $absolute ): bool {
		if ( null === $this->code_dirs_cache ) {
			$dirs = array_merge( array( Paths::plugins_dir(), Paths::mu_plugins_dir() ), Paths::theme_dirs() );

			$this->code_dirs_cache = array_values( array_unique( array_map( 'trailingslashit', $dirs ) ) );
		}

		foreach ( $this->code_dirs_cache as $dir ) {
			if ( 0 === strpos( $absolute, $dir ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Extensions that are always backup archives regardless of location.
	 */
	private function backup_archive_extensions(): array {
		return array( 'wpress', 'wbp' );
	}

	/**
	 * File extensions excluded from every backup.
	 */
	private function excluded_extensions(): array {
		if ( null !== $this->extensions_cache ) {
			return $this->extensions_cache;
		}

		$extensions = array( 'log', 'tmp', 'bak' );

		$custom = (string) Settings::get( 'custom_excluded_extensions', '' );

		foreach ( explode( ',', $custom ) as $extension ) {
			$extension = strtolower( ltrim( trim( $extension ), '.' ) );

			if ( '' !== $extension ) {
				$extensions[] = $extension;
			}
		}

		$this->extensions_cache = $extensions;

		return $extensions;
	}

	/**
	 * User-defined excluded path prefixes (one per line, relative to the
	 * site root), from Settings → Backup.
	 */
	private function custom_excluded_paths(): array {
		if ( null !== $this->custom_paths_cache ) {
			return $this->custom_paths_cache;
		}

		$raw   = (string) Settings::get( 'custom_excluded_paths', '' );
		$paths = array();

		$lines = preg_split( '/[\r\n]+/', $raw );

		foreach ( is_array( $lines ) ? $lines : array() as $line ) {
			$line = trim( str_replace( '\\', '/', $line ), " \t/" );

			if ( '' !== $line ) {
				$paths[] = strtolower( $line );
			}
		}

		$this->custom_paths_cache = $paths;

		return $paths;
	}

	/**
	 * Root paths to scan for a given backup type.
	 *
	 * @return array<string,string> Label => absolute path.
	 */
	public function get_roots_for_type( string $type, array $custom_paths = array() ): array {
		switch ( $type ) {
			case 'media':
			case 'uploads':
				return array( 'uploads' => Paths::uploads_dir() );

			case 'themes':
				return array( 'themes' => Paths::themes_dir() );

			case 'plugins':
				return array( 'plugins' => Paths::plugins_dir() );

			case 'custom':
				$roots = array();
				foreach ( $custom_paths as $index => $path ) {
					$roots[ 'custom_' . $index ] = $path;
				}
				return $roots;

			case 'database':
				return array();

			case 'files':
			case 'full':
			default:
				return self::site_roots();
		}
	}

	/**
	 * Roots of a full or files-only backup: the WordPress folder, plus each
	 * of the content, plugins, must-use plugins, themes and uploads folders
	 * that a custom setup keeps outside it (WP_CONTENT_DIR, WP_PLUGIN_DIR,
	 * UPLOADS …), so those are backed up too. Each one is restored to the
	 * matching folder of the destination site (FileRestorer).
	 *
	 * @return array<string,string> Root key => absolute path.
	 */
	public static function site_roots(): array {
		$roots = array( 'root' => Paths::root() );
		$extra = array(
			'content'    => Paths::content_dir(),
			'plugins'    => Paths::plugins_dir(),
			'mu_plugins' => Paths::mu_plugins_dir(),
			'themes'     => Paths::themes_dir(),
			'uploads'    => Paths::uploads_dir(),
		);

		foreach ( $extra as $key => $dir ) {
			if ( '' === $dir || ! is_dir( $dir ) ) {
				continue;
			}

			foreach ( $roots as $covered ) {
				if ( Paths::is_within( $dir, $covered ) || Paths::is_within( $covered, $dir ) ) {
					continue 2;
				}
			}

			$roots[ $key ] = $dir;
		}

		return $roots;
	}

	/**
	 * Whether a file should be skipped, and why. Returns '' when the file is
	 * included, or a short reason key when excluded:
	 * 'config' (wp-config.php: database password, keys and salts), 'dir' (VCS
	 * metadata, own storage, cache location), 'backup_dir' (another backup
	 * plugin's storage folder), 'backup_archive' (.wpress/.wbp anywhere),
	 * 'ext', 'custom'. (consider_file() adds 'size' for the size limit.)
	 *
	 * @param string $relative_path Path relative to the scanned root.
	 * @param string $absolute_path Absolute path (used for location rules).
	 */
	private function exclusion_reason( string $relative_path, string $absolute_path = '' ): string {
		$relative_path = str_replace( '\\', '/', trim( $relative_path, '/' ) );
		$absolute      = wp_normalize_path( '' !== $absolute_path ? $absolute_path : $relative_path );

		// wp-config.php holds this server's database password and the
		// authentication keys and salts. It is never put in a backup (so it
		// is never stored, downloaded or sent by Direct Migration); a restore
		// always keeps the destination's own wp-config.php.
		if ( Paths::is_config_file( $absolute ) ) {
			return 'config';
		}

		foreach ( explode( '/', $relative_path ) as $segment ) {
			if ( in_array( $segment, $this->excluded_dir_names(), true ) ) {
				return 'dir';
			}
		}

		$in_code_dir = $this->is_inside_plugin_or_theme( $absolute );

		// node_modules outside plugins/themes is development tooling; inside an
		// installed plugin/theme it may be shipped runtime code, so it's kept.
		if ( ! $in_code_dir && false !== strpos( '/' . $relative_path . '/', '/node_modules/' ) ) {
			return 'dir';
		}

		if ( ! $in_code_dir ) {
			foreach ( $this->excluded_locations() as $reason => $prefixes ) {
				foreach ( $prefixes as $prefix ) {
					if ( 0 === stripos( $absolute, $prefix ) ) {
						return $reason;
					}
				}
			}
		}

		$extension = strtolower( pathinfo( $relative_path, PATHINFO_EXTENSION ) );

		// .wpress/.wbp files are backup archives wherever they live — packing
		// them re-backs-up entire old backups (600 MB+ each on the live site).
		if ( in_array( $extension, $this->backup_archive_extensions(), true ) && Settings::get( 'exclude_backup_archives', true ) ) {
			return 'backup_archive';
		}

		if ( in_array( $extension, $this->excluded_extensions(), true ) ) {
			return 'ext';
		}

		// Custom exclusions are documented as relative to the SITE ROOT, so
		// match them against the path inside the WordPress folder whatever the
		// backup type.
		$site_relative = Paths::relative_to_root( $absolute );
		$site_relative = strtolower( '' !== $site_relative ? $site_relative : $relative_path );

		foreach ( $this->custom_excluded_paths() as $prefix ) {
			if ( $site_relative === $prefix || 0 === strpos( $site_relative, $prefix . '/' ) ) {
				return 'custom';
			}
		}

		return '';
	}

	/**
	 * Back-compat boolean wrapper around exclusion_reason().
	 */
	private function is_excluded( string $relative_path, string $absolute_path = '' ): bool {
		return '' !== $this->exclusion_reason( $relative_path, $absolute_path );
	}

	/**
	 * Resumable scan: walk every root (depth-first, with an explicit
	 * directory stack instead of recursion) and stream matching files to
	 * the manifest until $deadline. Returns true when every root is done;
	 * otherwise the position is in $cursor and the next request continues.
	 *
	 * A single-request walk of a 100k-file site on slow shared storage (or
	 * an incremental backup, which hashes every file) could outlive
	 * max_execution_time; this never has to.
	 *
	 * @param array<string,string> $roots              Root key => absolute path.
	 * @param resource             $manifest_handle    Open handle to files.jsonl (append).
	 * @param array|null           $baseline_checksums Entry name => sha256 for incremental/differential runs.
	 * @param array<string,mixed>  $cursor             Scan position; pass an empty array on the first call.
	 */
	public function scan_step( array $roots, $manifest_handle, ?array $baseline_checksums, array &$cursor, float $deadline ): bool {
		if ( empty( $cursor ) ) {
			$cursor = array(
				'root'           => 0,
				'stack'          => null,
				'resume'         => null,
				'file_count'     => 0,
				'total_size'     => 0,
				'excluded_count' => 0,
				'excluded_size'  => 0,
				'large_logged'   => 0,
			);
		}

		$this->excluded_count        = (int) $cursor['excluded_count'];
		$this->excluded_size         = (int) $cursor['excluded_size'];
		$this->large_archives_logged = (int) $cursor['large_logged'];

		$keys       = array_keys( $roots );
		$root_count = count( $keys );
		$finished   = true;

		while ( $cursor['root'] < $root_count ) {
			$root_key  = (string) $keys[ $cursor['root'] ];
			$root_path = untrailingslashit( (string) $roots[ $root_key ] );

			if ( null === $cursor['stack'] ) {
				$cursor['stack'] = is_dir( $root_path ) ? array( '' ) : array();
			}

			while ( null !== $cursor['resume'] || ! empty( $cursor['stack'] ) ) {
				if ( null !== $cursor['resume'] ) {
					$relative_dir     = (string) $cursor['resume']['dir'];
					$start            = (int) $cursor['resume']['pos'];
					$cursor['resume'] = null;
				} else {
					$relative_dir = (string) array_pop( $cursor['stack'] );
					$start        = 0;
				}

				$directory = '' === $relative_dir ? $root_path : $root_path . '/' . $relative_dir;
				$names     = @scandir( $directory ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- unreadable folders are skipped, like the iterator did.

				if ( false === $names ) {
					continue;
				}

				$subdirs = array();
				$count   = count( $names );

				for ( $i = $start; $i < $count; $i++ ) {
					$name = $names[ $i ];

					if ( '.' === $name || '..' === $name ) {
						continue;
					}

					$absolute = $directory . '/' . $name;
					$relative = '' === $relative_dir ? $name : $relative_dir . '/' . $name;

					if ( is_dir( $absolute ) ) {
						// Symlinked folders are not followed (same as before).
						if ( ! is_link( $absolute ) ) {
							$subdirs[] = $relative;
						}
						continue;
					}

					if ( is_file( $absolute ) && is_readable( $absolute ) ) {
						$this->consider_file( $root_key, $relative, $absolute, $manifest_handle, $baseline_checksums, $cursor );
					}

					// Huge folders (50k thumbnails, or hashing for an
					// incremental run): stop mid-folder and resume here.
					if ( 0 === ( $i % 250 ) && $i + 1 < $count && microtime( true ) >= $deadline ) {
						$cursor['resume'] = array(
							'dir' => $relative_dir,
							'pos' => $i + 1,
						);
						break;
					}
				}

				for ( $j = count( $subdirs ) - 1; $j >= 0; $j-- ) {
					$cursor['stack'][] = $subdirs[ $j ];
				}

				if ( microtime( true ) >= $deadline ) {
					$finished = false;
					break 2;
				}
			}

			++$cursor['root'];
			$cursor['stack'] = null;
		}

		$cursor['excluded_count'] = $this->excluded_count;
		$cursor['excluded_size']  = $this->excluded_size;
		$cursor['large_logged']   = $this->large_archives_logged;

		return $finished && $cursor['root'] >= $root_count;
	}

	/**
	 * Apply the exclusion rules (and, for incremental runs, the unchanged
	 * check) to one file and write it to the manifest when it's included.
	 *
	 * @param resource            $manifest_handle
	 * @param array<string,mixed> $cursor Running totals.
	 */
	private function consider_file( string $root_key, string $relative, string $absolute, $manifest_handle, ?array $baseline_checksums, array &$cursor ): void {
		$size   = (int) @filesize( $absolute ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- a file deleted mid-scan is skipped below.
		$reason = $this->exclusion_reason( $relative, $absolute );

		// Settings → Backup → "Leave out files larger than". Never plugin or
		// theme code: a missing file there can break the restored site.
		$limit = (int) Settings::get( 'exclude_larger_than_mb', 0 ) * MB_IN_BYTES;

		if ( '' === $reason && $limit > 0 && $size > $limit && ! $this->is_inside_plugin_or_theme( wp_normalize_path( $absolute ) ) ) {
			$reason = 'size';

			if ( (int) ( $cursor['size_logged'] ?? 0 ) < 10 ) {
				$cursor['size_logged'] = (int) ( $cursor['size_logged'] ?? 0 ) + 1;
				Logger::info( 'large_file_excluded: ' . $relative . ' (' . size_format( $size ) . ') is larger than the limit set under Settings → Backup.' );
			}
		}

		if ( '' !== $reason ) {
			++$this->excluded_count;
			$this->excluded_size += $size;

			if ( 'config' === $reason && empty( $cursor['config_logged'] ) ) {
				$cursor['config_logged'] = true;
				Logger::info( 'wp_config_excluded: ' . $relative . ' is not included in the backup (it holds this server\'s database password and authentication keys and salts). A restore keeps the destination site\'s own wp-config.php.' );
			}

			// Call out individual huge backup archives (the exact live-site
			// problem: 600 MB .wpress files) — bounded to 10 log lines.
			if ( in_array( $reason, array( 'backup_dir', 'backup_archive' ), true )
				&& $size > self::LARGE_ARCHIVE_BYTES
				&& $this->large_archives_logged < 10 ) {
				++$this->large_archives_logged;
				Logger::info( 'large_backup_archive_excluded: ' . $relative . ' (' . size_format( $size ) . ') skipped — backup archives from other plugins are excluded by default (Settings → Backup).' );
			}

			return;
		}

		// Incremental/differential mode: skip files unchanged since the baseline.
		if ( null !== $baseline_checksums ) {
			$entry_name = 'files/' . $root_key . '/' . $relative;

			if ( isset( $baseline_checksums[ $entry_name ] ) && hash_file( 'sha256', $absolute ) === $baseline_checksums[ $entry_name ] ) {
				return;
			}
		}

		if ( $size > self::LARGE_FILE_WARNING_BYTES ) {
			Logger::warning(
				sprintf( 'Large file included in backup: %s (%s)', $relative, size_format( $size ) )
			);
		}

		fwrite(
			$manifest_handle,
			wp_json_encode(
				array(
					'root'  => $root_key,
					'path'  => $relative,
					'abs'   => $absolute,
					'size'  => $size,
					'mtime' => (int) @filemtime( $absolute ), // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
				)
			) . "\n"
		);

		++$cursor['file_count'];
		$cursor['total_size'] = (int) $cursor['total_size'] + $size;
	}

	/**
	 * Totals across every root scanned by this instance, for the
	 * backup_exclusion_applied summary log.
	 *
	 * @return array{count:int,size:int}
	 */
	public function exclusion_stats(): array {
		return array(
			'count' => $this->excluded_count,
			'size'  => $this->excluded_size,
		);
	}
}

// phpcs:enable WordPress.WP.AlternativeFunctions
