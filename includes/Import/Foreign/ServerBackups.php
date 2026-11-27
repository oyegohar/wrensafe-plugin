<?php
/**
 * Backups that other plugins left on this server (UpdraftPlus, All-in-One WP Migration, Duplicator and the others
 * Wrensafe can read), found in the folders those plugins use inside wp-content, so they can be restored without
 * downloading and uploading them again.
 *
 * Only those folders are looked at, never the whole site. Nothing is moved, copied or deleted: an import reads the
 * files where they are (ImportSet::add_found()), and they stay for the other plugin.
 *
 * @package WPBP\Import\Foreign
 */

namespace WPBP\Import\Foreign;

use WPBP\Security\PathValidator;
use WPBP\Utils\FileSystem;
use WPBP\Utils\Paths;

defined( 'ABSPATH' ) || exit;

final class ServerBackups {

	/** Where other backup plugins keep their backups, inside wp-content (glob patterns). */
	const PLACES = array(
		'updraft',
		'ai1wm-backups',
		'backups-dup-lite',
		'backups-dup-pro',
		'duplicator-backups',
		'wpvividbackups',
		'backup-migration-*/backups',
		'boldgrid_backup_*',
		'uploads/backwpup/*/backups',
		'uploads/backwpup-*-backups',
		'backupbuddy_backups',
		'uploads/backupbuddy_backups',
		'uploads/wp-migrate-db',
		'backups',
	);

	/** At most this many files are looked at, and this many backups listed (newest first). */
	const MAX_FILES = 400;
	const MAX_SETS  = 20;

	/** A file still changing in the last minute may be a backup being made: not offered yet. */
	const SETTLE = 60;

	/** Short monograms for the list: other plugins' logos are their trademarks. */
	const MONOGRAMS = array(
		'all-in-one-wp-migration' => 'AIO',
		'updraftplus'             => 'UP',
		'duplicator'              => 'DU',
		'wpvivid'                 => 'WV',
		'backwpup'                => 'BW',
		'backup-migration'        => 'BM',
		'total-upkeep'            => 'TU',
		'solid-backups'           => 'SB',
		'wp-migrate'              => 'WM',
		'jetpack'                 => 'JP',
	);

	/**
	 * The backups found, newest first.
	 *
	 * @return array<int,array{id:string,format:string,name:string,folder:string,date:int,size:int,files:array<string,string>}>
	 */
	public static function find(): array {
		$content = self::clean( Paths::content_dir() );
		$groups  = array();
		$seen    = 0;

		foreach ( self::folders( $content ) as $folder ) {
			$names = scandir( $folder );

			foreach ( is_array( $names ) ? $names : array() as $name ) {
				$path = $folder . '/' . $name;

				if ( '' === $name || '.' === $name[0] || ! ImportSet::allowed( $name ) || is_link( $path ) || ! is_file( $path ) || ! is_readable( $path ) ) {
					continue;
				}

				if ( ++$seen > self::MAX_FILES ) {
					break 2;
				}

				$groups[ $folder . '|' . self::group( $name ) ][ $name ] = $path;
			}
		}

		$sets = array();

		foreach ( $groups as $key => $files ) {
			$date = 0;
			$size = 0;

			foreach ( $files as $path ) {
				$date  = max( $date, (int) filemtime( $path ) );
				$size += (int) filesize( $path );
			}

			if ( $size > 0 && $date <= time() - self::SETTLE ) {
				ksort( $files );
				$sets[] = array(
					'id'     => md5( $key ),
					'folder' => (string) strtok( $key, '|' ),
					'date'   => $date,
					'size'   => $size,
					'files'  => $files,
				);
			}
		}

		usort(
			$sets,
			static function ( array $a, array $b ): int {
				return $b['date'] <=> $a['date'];
			}
		);

		$found = array();

		foreach ( $sets as $set ) {
			try {
				$format = ForeignConverter::detect( $set['files'] );
			} catch ( \Throwable $e ) {
				$format = null; // A damaged archive: not offered.
			}

			if ( null === $format ) {
				continue;
			}

			$found[] = array(
				'id'     => $set['id'],
				'format' => $format->key(),
				'name'   => $format->name(),
				'folder' => Paths::display( $set['folder'] ),
				'date'   => $set['date'],
				'size'   => $set['size'],
				'files'  => $set['files'],
			);

			if ( count( $found ) >= self::MAX_SETS ) {
				break;
			}
		}

		return $found;
	}

	/** One backup found, by the id find() gave it, looked up again (an id from the browser is never a path). */
	public static function get( string $id ): ?array {
		if ( 1 !== preg_match( '/^[a-f0-9]{32}$/', $id ) ) {
			return null;
		}

		foreach ( self::find() as $set ) {
			if ( $set['id'] === $id ) {
				return $set;
			}
		}

		return null;
	}

	/** A file belongs to one of the places, so an import may read it where it is. */
	public static function is_found_file( string $path ): bool {
		$content = self::clean( Paths::content_dir() );
		$folder  = self::clean( dirname( $path ) );

		return ! is_link( $path ) && is_file( $path ) && ImportSet::allowed( basename( $path ) ) && in_array( $folder, self::folders( $content ), true );
	}

	/** The places that exist on this server, inside wp-content and not Wrensafe's own storage. @return string[] */
	private static function folders( string $content ): array {
		$own   = self::clean( FileSystem::storage_dir() );
		$found = array();

		foreach ( self::PLACES as $place ) {
			$paths = false !== strpos( $place, '*' ) ? glob( $content . '/' . $place, GLOB_ONLYDIR ) : array( $content . '/' . $place );

			foreach ( is_array( $paths ) ? $paths : array() as $path ) {
				$path = self::clean( $path );

				if ( is_dir( $path ) && ! is_link( $path ) && is_readable( $path ) && $path !== $own && 0 !== strpos( $path . '/', $own . '/' ) && PathValidator::assert_within( $path, $content ) ) {
					$found[ $path ] = true;
				}
			}
		}

		return array_keys( $found );
	}

	/** Files of one backup share a key: UpdraftPlus's date and nonce, WPvivid's name; otherwise one file is one backup. */
	private static function group( string $name ): string {
		if ( preg_match( '/^backup_(\d{4}-\d{2}-\d{2}-\d{4})_.*_([0-9a-f]{12})-/i', $name, $m ) ) {
			return 'updraftplus-' . $m[1] . '-' . strtolower( $m[2] );
		}

		if ( preg_match( '/^(.+_wpvivid-.+?)_backup_[a-z]+(?:\.part\d+)?\.zip$/i', $name, $m ) ) {
			return 'wpvivid-' . $m[1];
		}

		return 'file-' . $name;
	}

	private static function clean( string $path ): string {
		return untrailingslashit( wp_normalize_path( $path ) );
	}
}
