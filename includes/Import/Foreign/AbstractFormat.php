<?php
/**
 * Base for another plugin's backup format: how to recognise its files, what
 * they contain, and where each archive entry belongs in a Wrensafe
 * archive.
 *
 * Sources describe the files to read, in order:
 *   type  wpress | daf | tar | tgz | zip | sql | sqlgz
 *   file  absolute path (or the outer zip, with "inner" = entry name of a
 *         zip stored inside it, for WPvivid)
 *   base  content (paths relative to wp-content) | root (relative to the
 *         WordPress folder) | auto (guess per entry)
 *
 * @package WPBP\Import\Foreign
 */

namespace WPBP\Import\Foreign;

defined( 'ABSPATH' ) || exit;

// Small metadata files are read with native handles (gzopen/fread).
// phpcs:disable WordPress.WP.AlternativeFunctions

abstract class AbstractFormat {

	/** wp-content folders that never belong in a restore (other plugins' backups, caches). */
	private const SKIP_CONTENT_DIRS = array(
		'ai1wm-backups',
		'updraft',
		'wpvividbackups',
		'wpvivid_staging',
		'wpvivid_uploads',
		'duplicator-backups',
		'backups-dup-lite',
		'backups-dup-pro',
		'backup-guard',
		'backups',
		'wrensafe-storage',
		'wp-backup-pilot-storage', // The storage folder before the plugin's rename.
		'cache',
		'upgrade',
		'upgrade-temp-backup',
		'uploads/backwpup',
		'uploads/ai1wm-backups',
		'plugins/wrensafe',
		'plugins/backuppilot', // This plugin's folder before its rename.
		'backupbuddy_backups',
		'uploads/backupbuddy_backups',
		'uploads/backupbuddy_temp',
		'uploads/pb_backupbuddy',
		'uploads/wp-migrate-db',
		'uploads/jetpack-backup',
	);

	/** Backup folders with a random suffix (Total Upkeep, Backup Migration). */
	private const SKIP_CONTENT_PATTERN = '#^(?:boldgrid_backup_[^/]+|backup-migration-[^/]+|uploads/backup-migration-[^/]+)(?:/|$)#';

	abstract public function key(): string;

	abstract public function name(): string;

	/** The backup note shown on the review screen. */
	public function note(): string {
		/* translators: %s: name of the plugin that made the backup */
		return sprintf( __( 'Converted from %s', 'wrensafe' ), $this->name() );
	}

	/** @param array<string,string> $files File name => path. */
	abstract public function matches( array $files ): bool;

	/**
	 * What the set contains. Throws a readable \RuntimeException for
	 * backups that can't be imported (password-protected, incomplete).
	 *
	 * @param array<string,string> $files
	 * @return array{sources:array,meta:array,warnings:string[]}
	 */
	abstract public function describe( array $files ): array;

	/**
	 * Where an entry of a source goes: array( 'skip' ), array( 'file',
	 * 'files/{root}/{path}' ), array( 'sql' ) or array( 'sqlgz' ).
	 *
	 * @param array<string,mixed> $source
	 */
	public function classify( array $source, string $path ): array {
		$base = $source['base'] ?? 'content';

		if ( 'root' === $base ) {
			return $this->root_target( $path );
		}

		if ( 'auto' === $base ) {
			return $this->auto_target( $path );
		}

		return $this->content_target( $path );
	}

	/** A wp-content-relative path → its Wrensafe archive entry. */
	protected function content_target( string $rel ): array {
		$rel = ltrim( (string) preg_replace( '#/{2,}#', '/', str_replace( '\\', '/', $rel ) ), '/' );

		if ( '' === $rel || false !== strpos( '/' . $rel . '/', '/../' ) || preg_match( self::SKIP_CONTENT_PATTERN, $rel ) ) {
			return array( 'skip' );
		}

		foreach ( self::SKIP_CONTENT_DIRS as $dir ) {
			if ( $rel === $dir || 0 === strpos( $rel, $dir . '/' ) || 0 === strpos( $rel, 'uploads/' . $dir . '/' ) ) {
				return array( 'skip' );
			}
		}

		if ( preg_match( '#^(?:debug\.log|uploads/backwpup[^/]*/)#', $rel ) ) {
			return array( 'skip' );
		}

		foreach ( array( 'plugins', 'themes', 'uploads' ) as $section ) {
			if ( 0 === strpos( $rel, $section . '/' ) ) {
				return array( 'file', 'files/' . $section . '/' . substr( $rel, strlen( $section ) + 1 ) );
			}
		}

		return array( 'file', 'files/content/' . $rel );
	}

	/**
	 * A path relative to the source site's WordPress folder, inside another
	 * plugin's archive (its own layout, not this site's folders): only the
	 * content folder is restored, to this site's content folder.
	 */
	protected function root_target( string $path ): array {
		$path = ltrim( str_replace( '\\', '/', $path ), '/' );

		return 0 === strpos( $path, 'wp-content/' ) ? $this->content_target( substr( $path, 11 ) ) : array( 'skip' );
	}

	/**
	 * Unknown archive layout (another plugin's archive, not this site's
	 * folders): find wp-content, a bare plugins/themes/uploads folder, or SQL.
	 */
	protected function auto_target( string $path ): array {
		$path = ltrim( str_replace( '\\', '/', $path ), '/' );
		$at   = strpos( $path, 'wp-content/' );

		if ( false !== $at ) {
			return $this->content_target( substr( $path, $at + 11 ) );
		}

		if ( preg_match( '#^(?:plugins|themes|uploads|mu-plugins)/#', $path ) ) {
			return $this->content_target( $path );
		}

		if ( preg_match( '/\.sql$/i', $path ) ) {
			return array( 'sql' );
		}

		if ( preg_match( '/\.sql\.gz$/i', $path ) ) {
			return array( 'sqlgz' );
		}

		return array( 'skip' );
	}

	/**
	 * When the backup was made, from its file names: every supported plugin
	 * puts a timestamp there (UpdraftPlus 2026-11-11-1030, WPvivid
	 * 2026-11-11-10-32, All-in-One WP Migration 20261111-103048, Duplicator
	 * 20261111103012, BackWPup 2026-11-11_10-31-27). Returned without a
	 * time zone, as the plugin wrote it; '' when no name carries a date.
	 *
	 * @param string[] $names
	 */
	public static function date_from_names( array $names ): string {
		foreach ( $names as $name ) {
			if ( preg_match( '/(?<!\d)(20\d{2})-?(\d{2})-?(\d{2})[-_]?(\d{2})-?(\d{2})(?:-?(\d{2}))?(?!\d)/', (string) $name, $m )
				&& checkdate( (int) $m[2], (int) $m[3], (int) $m[1] ) && (int) $m[4] < 24 && (int) $m[5] < 60 ) {
				return sprintf( '%s-%s-%sT%s:%s:%s', $m[1], $m[2], $m[3], $m[4], $m[5], isset( $m[6] ) && '' !== $m[6] ? $m[6] : '00' );
			}
		}

		return '';
	}

	/**
	 * Tables a dump names differently from the real site (Backup Migration
	 * writes "1794399448_wp_posts" with the real name in a header comment).
	 *
	 * @return array<string,string> Name in the dump => real table name.
	 */
	public function sql_table_renames( string $head ): array {
		unset( $head );
		return array();
	}

	/**
	 * The order to split a backup's SQL files in (indexes into $names, the
	 * archive paths). Default: as found.
	 *
	 * @param string[] $names
	 * @return int[]
	 */
	public function sql_order( array $names ): array {
		return array_keys( $names );
	}

	/**
	 * Stop with a readable reason when PHP can't open .zip files.
	 *
	 * @throws \RuntimeException When the Zip extension (ZipArchive) is missing.
	 */
	public static function require_zip(): void {
		if ( ! class_exists( 'ZipArchive' ) ) {
			throw new \RuntimeException( esc_html__( 'This backup is a .zip file and this server\'s PHP has no Zip extension (ZipArchive). Ask your host to enable the PHP Zip extension, then try again.', 'wrensafe' ) );
		}
	}

	/**
	 * Stop with a readable reason when PHP can't read gzip/deflate data.
	 *
	 * @throws \RuntimeException When the Zlib extension is missing.
	 */
	public static function require_zlib(): void {
		foreach ( array( 'gzopen', 'gzread', 'inflate_init', 'inflate_add' ) as $function ) {
			if ( ! function_exists( $function ) ) {
				throw new \RuntimeException( esc_html__( 'This backup is compressed (gzip) and this server\'s PHP has no Zlib extension. Ask your host to enable the PHP Zlib extension, then try again.', 'wrensafe' ) );
			}
		}
	}

	/**
	 * Stop with a readable reason when PHP can't read .bz2 files.
	 *
	 * @throws \RuntimeException When the Bzip2 extension is missing.
	 */
	public static function require_bz2(): void {
		if ( ! function_exists( 'bzopen' ) || ! in_array( 'compress.bzip2', stream_get_wrappers(), true ) ) {
			throw new \RuntimeException( esc_html__( 'This backup is compressed with bzip2 and this server\'s PHP has no Bzip2 extension. Ask your host to enable the PHP Bz2 extension, or upload a .tar.gz or .zip copy of the backup.', 'wrensafe' ) );
		}
	}

	/** Whether a zip has an entry (false when it can't be read, or PHP has no Zip extension). */
	protected static function zip_has( string $zip_path, string $entry ): bool {
		if ( ! class_exists( 'ZipArchive' ) ) {
			return false;
		}

		$zip = new \ZipArchive();

		if ( true !== $zip->open( $zip_path ) ) {
			return false;
		}

		$found = false !== $zip->locateName( $entry );
		$zip->close();

		return $found;
	}

	/** Contents of a small zip entry, or null (also when PHP has no Zip extension). */
	protected static function zip_entry( string $zip_path, string $entry, int $max = 1048576 ): ?string {
		if ( ! class_exists( 'ZipArchive' ) ) {
			return null;
		}

		$zip = new \ZipArchive();

		if ( true !== $zip->open( $zip_path ) ) {
			return null;
		}

		$stat = $zip->statName( $entry );
		$data = ( false !== $stat && $stat['size'] <= $max ) ? $zip->getFromName( $entry ) : false;
		$zip->close();

		return false === $data ? null : (string) $data;
	}

	/**
	 * First entry name of a zip matching $pattern (reads only the zip's
	 * index), or null (also when PHP has no Zip extension).
	 */
	protected static function zip_find( string $zip_path, string $pattern ): ?string {
		if ( ! class_exists( 'ZipArchive' ) ) {
			return null;
		}

		$zip = new \ZipArchive();

		if ( true !== $zip->open( $zip_path ) ) {
			return null;
		}

		$found = null;
		$count = (int) $zip->numFiles; // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- ZipArchive property.

		for ( $i = 0; $i < $count && null === $found; $i++ ) {
			$name = (string) $zip->getNameIndex( $i );

			if ( preg_match( $pattern, $name ) ) {
				$found = $name;
			}
		}

		$zip->close();

		return $found;
	}

	/** Lower-case extension, treating .sql.gz / .tar.gz as one. */
	protected static function ext( string $name ): string {
		if ( preg_match( '/\.(sql\.gz|tar\.gz|tar\.bz2|tgz)$/i', $name, $m ) ) {
			return strtolower( $m[1] );
		}

		return strtolower( (string) pathinfo( $name, PATHINFO_EXTENSION ) );
	}

	/**
	 * Site details from the comment header other plugins write at the top
	 * of their SQL dumps (UpdraftPlus "#", WPvivid "/* # …", BackWPup "--").
	 *
	 * @return array<string,string>
	 */
	public static function sql_header_meta( string $head ): array {
		$map  = array(
			'site_url'        => '/^(?:#|--|\/\*\s*#)\s*(?:Backup of|site_url|Blog URL):\s*(\S+)/mi',
			'home_url'        => '/^(?:#|--|\/\*\s*#)\s*(?:Home URL|home_url):\s*(\S+)/mi',
			'prefix'          => '/^(?:#|--|\/\*\s*#)\s*(?:Table prefix|table_prefix):\s*([A-Za-z0-9_]+)/mi',
			'uploads_baseurl' => '/^(?:#|--|\/\*\s*#)\s*(?:Uploads URL|upload_url):\s*(\S+)/mi',
			'abspath'         => '/^(?:#|--)\s*(?:ABSPATH|Blog ABSPATH):\s*(\S+)/mi',
			'wp_version'      => '/^#\s*WordPress Version:\s*([0-9.]+)/mi',
			'wpmdb_url'       => '/^#\s*URL:\s*(\S+)/mi',
			'wpmdb_protocol'  => '/^#\s*Protocol:\s*(https?)\s*$/mi',
		);
		$meta = array();

		foreach ( $map as $key => $pattern ) {
			if ( preg_match( $pattern, $head, $m ) ) {
				$meta[ $key ] = untrailingslashit( rtrim( trim( $m[1] ), '*/; ' ) );
			}
		}

		if ( isset( $meta['abspath'] ) ) {
			$meta['abspath'] .= '/';
		}

		// WP Migrate: "# URL: //example.com" plus "# Protocol: https".
		if ( isset( $meta['wpmdb_url'] ) && empty( $meta['site_url'] ) ) {
			$url = $meta['wpmdb_url'];

			if ( 0 === strpos( $url, '//' ) ) {
				$url = ( $meta['wpmdb_protocol'] ?? 'https' ) . ':' . $url;
			}

			$meta['site_url'] = $url;
			$meta['home_url'] = $url;
		}

		unset( $meta['wpmdb_url'], $meta['wpmdb_protocol'] );

		return $meta;
	}

	/**
	 * The first few KB of a (possibly gzipped) SQL file; '' when it can't be
	 * read (a gzipped file without PHP's Zlib extension: the conversion
	 * reports that, see ForeignConverter::describe()).
	 */
	public static function sql_head( string $file, bool $gzip ): string {
		if ( $gzip ) {
			if ( ! function_exists( 'gzopen' ) ) {
				return '';
			}

			$handle = gzopen( $file, 'rb' );
			$head   = false === $handle ? '' : (string) gzread( $handle, 8192 );
			if ( false !== $handle ) {
				gzclose( $handle );
			}
			return $head;
		}

		$handle = fopen( $file, 'rb' );
		$head   = false === $handle ? '' : (string) fread( $handle, 8192 );
		if ( false !== $handle ) {
			fclose( $handle );
		}

		return $head;
	}
}

// phpcs:enable WordPress.WP.AlternativeFunctions
