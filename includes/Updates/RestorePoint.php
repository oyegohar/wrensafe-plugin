<?php
/**
 * A copy of one folder (a plugin's, or another update type's item), saved
 * before Safe Updates updates it, and the rollback that puts it back.
 *
 * The copy is a ZIP of the folder (or single file) in Wrensafe's protected
 * storage folder, so no copied PHP file can be opened from the web. Its
 * name ends in a random part, so it can't be guessed on a server that
 * ignores .htaccess (nginx). It is written with the append-only
 * ZipStreamWriter in bounded steps, so a large folder never runs into a
 * request time limit.
 *
 * A rollback extracts the copy into WordPress's upgrade folder first and
 * only then swaps it in place of the updated folder, so the item is never
 * left missing if the extraction fails.
 *
 * Restore points saved before update types existed (plugins only) have no
 * 'root', 'name' and 'main'; they are read as plugin copies.
 *
 * @package WPBP\Updates
 */

namespace WPBP\Updates;

use WPBP\Backup\ZipStreamWriter;
use WPBP\Utils\FileSystem;
use WPBP\Utils\Paths;
use WPBP\Utils\Str;

defined( 'ABSPATH' ) || exit;

// The file list and the ZIP live in Wrensafe's own storage folder and are
// streamed with native file functions (see ZipStreamWriter); changes inside
// the plugins and themes folders go through WP_Filesystem.
// phpcs:disable WordPress.WP.AlternativeFunctions

class RestorePoint {

	public static function dir(): string {
		return FileSystem::storage_dir() . 'safe-updates/';
	}

	/**
	 * Start a restore point: list the files to copy.
	 *
	 * @param string $path    Absolute path of the folder (or single file) to copy.
	 * @param string $version The version being replaced (part of the file name).
	 * @param string $main    A file that must be in the copy, relative to the folder's parent.
	 * @return array<string,mixed> The restore point's state, kept in the job.
	 */
	public static function begin( int $job_id, string $path, string $version, string $main ): array {
		$dir  = self::dir();
		$path = untrailingslashit( wp_normalize_path( $path ) );

		if ( ! FileSystem::ensure_protected_directory( $dir ) ) {
			throw new \RuntimeException( esc_html__( 'Wrensafe’s storage folder isn’t writable, so no copy could be saved.', 'wrensafe' ) );
		}

		if ( '' === $path || ! file_exists( $path ) ) {
			throw new \RuntimeException( esc_html__( 'The files to copy weren’t found.', 'wrensafe' ) );
		}

		$root   = dirname( $path );
		$name   = basename( $path );
		$base   = sanitize_file_name( $job_id . '-' . basename( $name, '.php' ) . '-' . $version ) . '-' . strtolower( wp_generate_password( 16, false ) );
		$zip    = $dir . $base . '.zip';
		$list   = $dir . $base . '.list';
		$handle = fopen( $list, 'wb' );

		if ( false === $handle ) {
			throw new \RuntimeException( esc_html__( 'Wrensafe’s storage folder isn’t writable, so no copy could be saved.', 'wrensafe' ) );
		}

		$count = 0;

		if ( is_file( $path ) ) {
			fwrite( $handle, $name . "\n" );
			$count = 1;
		} else {
			$iterator = new \RecursiveIteratorIterator(
				new \RecursiveDirectoryIterator( $path, \FilesystemIterator::SKIP_DOTS ),
				\RecursiveIteratorIterator::LEAVES_ONLY
			);

			foreach ( $iterator as $item ) {
				if ( $item->isFile() ) {
					fwrite( $handle, substr( wp_normalize_path( $item->getPathname() ), strlen( $root ) + 1 ) . "\n" );
					++$count;
				}
			}
		}

		fclose( $handle );

		return array(
			'zip'    => $zip,
			'list'   => $list,
			'root'   => $root,
			'name'   => $name,
			'main'   => '' !== $main ? $main : $name,
			'offset' => 0,
			'files'  => $count,
			'added'  => 0,
			'writer' => ZipStreamWriter::new_state( $zip, $zip . '.cd' ),
			'done'   => false,
			'size'   => 0,
		);
	}

	/** The folder the copied paths are relative to (the plugins folder for old points). */
	private static function root( array $point ): string {
		return ! empty( $point['root'] ) ? untrailingslashit( (string) $point['root'] ) : Paths::plugins_dir();
	}

	/**
	 * Add files to the copy until the deadline. True once the copy is complete.
	 *
	 * @param array<string,mixed> $point State from begin(), updated in place.
	 */
	public static function build( array &$point, float $deadline ): bool {
		if ( ! empty( $point['done'] ) ) {
			return true;
		}

		$writer = new ZipStreamWriter( (array) $point['writer'] );
		$handle = fopen( (string) $point['list'], 'rb' );
		$root   = self::root( $point ) . '/';

		if ( false === $handle ) {
			throw new \RuntimeException( esc_html__( 'The list of the files to copy could not be read back.', 'wrensafe' ) );
		}

		fseek( $handle, (int) $point['offset'] );

		while ( true ) {
			$line = fgets( $handle );

			if ( false === $line ) {
				break;
			}

			$relative = rtrim( $line, "\r\n" );

			if ( '' === $relative ) {
				$point['offset'] = ftell( $handle );
				continue;
			}

			if ( null === $writer->add_file( $root . $relative, $relative, $deadline ) ) {
				// Paused inside a large file: the same line continues next time.
				$point['writer'] = $writer->state();
				fclose( $handle );
				return false;
			}

			$point['offset'] = ftell( $handle );
			++$point['added'];

			if ( microtime( true ) >= $deadline ) {
				$point['writer'] = $writer->state();
				fclose( $handle );
				return false;
			}
		}

		fclose( $handle );

		$point['size']   = $writer->finish();
		$point['writer'] = array();
		$point['done']   = true;

		wp_delete_file( (string) $point['list'] );
		wp_delete_file( (string) $point['zip'] . '.cd' );

		return true;
	}

	/**
	 * Put the saved copy back in place of the current files.
	 *
	 * @param array<string,mixed> $point  A finished restore point.
	 * @param string              $plugin For a point saved before update types existed: the plugin basename.
	 * @return true|\WP_Error
	 */
	public static function restore( array $point, string $plugin = '' ) {
		global $wp_filesystem;

		$zip = (string) ( $point['zip'] ?? '' );

		if ( '' === $zip || ! is_file( $zip ) ) {
			return new \WP_Error( 'wpbp_no_copy', __( 'The saved copy is no longer there.', 'wrensafe' ) );
		}

		if ( ! SafeUpdates::filesystem() ) {
			return new \WP_Error( 'wpbp_no_filesystem', __( 'WordPress couldn’t get write access to the files.', 'wrensafe' ) );
		}

		if ( empty( $point['name'] ) ) {
			// A plugin copy from before update types existed.
			$folder = PluginUpdateType::plugin_folder( $plugin );
			$name   = '' !== $folder ? $folder : $plugin;
			$main   = $plugin;
		} else {
			$name = (string) $point['name'];
			$main = (string) $point['main'];
		}

		$root   = self::root( $point );
		$remote = trailingslashit( (string) $wp_filesystem->find_folder( $root ) );

		if ( '' === $name || '/' === $remote || validate_file( $name ) !== 0 || validate_file( $main ) !== 0 ) {
			return new \WP_Error( 'wpbp_bad_copy', __( 'The saved copy doesn’t say where it belongs.', 'wrensafe' ) );
		}

		// WordPress's own unpacking, into its upgrade folder.
		$upgrader = new \WP_Upgrader( new \Automatic_Upgrader_Skin() );
		$upgrader->init();

		$working = $upgrader->unpack_package( $zip, false );

		if ( is_wp_error( $working ) ) {
			return $working;
		}

		$staging = trailingslashit( $working );
		$aside   = $staging . 'wpbp-replaced-' . strtolower( wp_generate_password( 8, false ) );

		if ( ! $wp_filesystem->exists( $staging . $main ) ) {
			$wp_filesystem->delete( $staging, true );
			return new \WP_Error( 'wpbp_bad_copy', __( 'The saved copy is incomplete (its main file is missing).', 'wrensafe' ) );
		}

		// Move the updated version aside, then the saved one into its place.
		$had_current = $wp_filesystem->exists( $remote . $name );

		if ( $had_current && ! $wp_filesystem->move( $remote . $name, $aside, true ) ) {
			$wp_filesystem->delete( $staging, true );
			return new \WP_Error( 'wpbp_move_failed', __( 'The updated version couldn’t be moved out of the way.', 'wrensafe' ) );
		}

		if ( ! $wp_filesystem->move( $staging . $name, $remote . $name, true ) ) {
			if ( $had_current ) {
				$wp_filesystem->move( $aside, $remote . $name, true );
			}

			$wp_filesystem->delete( $staging, true );
			return new \WP_Error( 'wpbp_move_failed', __( 'The saved copy couldn’t be moved back into place.', 'wrensafe' ) );
		}

		if ( $had_current ) {
			$wp_filesystem->delete( $aside, true );
		}

		$wp_filesystem->delete( $staging, true );

		$local = $root . '/' . $name;

		if ( is_dir( $local ) && function_exists( 'wp_opcache_invalidate_directory' ) ) {
			wp_opcache_invalidate_directory( $local );
		} elseif ( function_exists( 'wp_opcache_invalidate' ) ) {
			wp_opcache_invalidate( $local, true );
		}

		wp_clean_plugins_cache( false );

		return true;
	}

	/** Remove a restore point's files (a finished copy or one left half-written). */
	public static function delete( array $point ): void {
		foreach ( array( 'zip', 'list' ) as $key ) {
			if ( ! empty( $point[ $key ] ) && Str::starts_with( wp_normalize_path( (string) $point[ $key ] ), wp_normalize_path( self::dir() ) ) ) {
				wp_delete_file( (string) $point[ $key ] );

				if ( 'zip' === $key ) {
					wp_delete_file( (string) $point[ $key ] . '.cd' );
				}
			}
		}
	}

	/**
	 * Delete saved copies older than the number of days set under
	 * Settings → Updates.
	 */
	public static function cleanup( int $days ): int {
		$dir = self::dir();

		if ( ! is_dir( $dir ) ) {
			return 0;
		}

		$cutoff  = time() - max( 1, $days ) * DAY_IN_SECONDS;
		$deleted = 0;

		foreach ( (array) glob( $dir . '*' ) as $file ) {
			if ( is_string( $file ) && in_array( pathinfo( $file, PATHINFO_EXTENSION ), array( 'zip', 'list', 'cd' ), true ) && is_file( $file ) && filemtime( $file ) < $cutoff ) {
				wp_delete_file( $file );
				++$deleted;
			}
		}

		return $deleted;
	}
}
