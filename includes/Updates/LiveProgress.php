<?php
/**
 * Live progress of a Safe Updates run, for the screen that follows it.
 *
 * While WordPress installs plugins it puts the site in maintenance mode, so
 * every request to WordPress answers 503 and the screen can't ask how far the
 * run has got. The run writes a small file the web server hands out directly.
 * It holds only each item's position and state, no names or versions, under a
 * random name only the administrator's screen is told, and goes when the run ends.
 *
 * @package WPBP\Updates
 */

namespace WPBP\Updates;

use WPBP\Utils\FileSystem;

defined( 'ABSPATH' ) || exit;

class LiveProgress {

	const FOLDER = 'wrensafe-live';

	/** A file older than this was left by a run that never finished. */
	const STALE_SECONDS = DAY_IN_SECONDS;

	/** @var string */
	private static $token = '';

	/** @var string[] State of each item. */
	private static $states = array();

	/** @var string[] What each item being installed is doing: download, install, installed, failed. */
	private static $stages = array();

	/** @var array<string,int> Item id => position. */
	private static $positions = array();

	/** A name for a new run's file. */
	public static function new_token(): string {
		self::sweep();

		return strtolower( wp_generate_password( 32, false ) );
	}

	/** Where the screen reads this run's progress ('' when it has none). */
	public static function url( array $p ): string {
		$token = self::clean_token( (string) ( $p['live'] ?? '' ) );
		$up    = wp_upload_dir( null, false );

		if ( '' === $token || empty( $up['baseurl'] ) || ! empty( $up['error'] ) ) {
			return '';
		}

		return trailingslashit( (string) $up['baseurl'] ) . self::FOLDER . '/' . $token . '.json';
	}

	/** Publish the run as it was just saved. */
	public static function publish( array $p ): void {
		self::$token     = self::clean_token( (string) ( $p['live'] ?? '' ) );
		self::$states    = array();
		self::$positions = array();
		$stages          = array();

		foreach ( array_values( (array) ( $p['items'] ?? array() ) ) as $i => $item ) {
			$state = (string) ( $item['state'] ?? '' );

			self::$states[ $i ]                             = $state;
			$stages[ $i ]                                   = 'updating' === $state ? (string) ( self::$stages[ $i ] ?? '' ) : '';
			self::$positions[ UpdateTypes::id_of( $item ) ] = $i;
		}

		self::$stages = $stages;
		self::write( true );
	}

	/** An item being installed reached a new stage (WordPress's upgrader hooks). */
	public static function stage( string $id, string $stage ): void {
		if ( '' === self::$token || ! isset( self::$positions[ $id ] ) ) {
			return;
		}

		// The next download starting means the one before it ended without installing.
		if ( 'download' === $stage ) {
			foreach ( self::$stages as $i => $was ) {
				if ( in_array( $was, array( 'download', 'install' ), true ) ) {
					self::$stages[ $i ] = 'failed';
				}
			}
		}

		self::$stages[ self::$positions[ $id ] ] = $stage;
		// Only an existing file: a request that lost the run must not bring back what the run's end removed.
		self::write( false );
	}

	/** The run ended: its file goes, and the folder with it. */
	public static function close(): void {
		self::$token = '';
		$dir         = self::dir();

		if ( '' !== $dir && is_dir( $dir ) && ! is_link( $dir ) ) {
			FileSystem::delete_directory( $dir );
		}
	}

	private static function write( bool $create ): void {
		$dir  = self::dir();
		$file = $dir . '/' . self::$token . '.json';

		if ( '' === self::$token || '' === $dir || ( $create ? ! wp_mkdir_p( $dir ) : ! is_file( $file ) ) || ! wp_is_writable( $dir ) ) {
			return;
		}

		if ( ! is_file( $dir . '/index.php' ) ) {
			file_put_contents( $dir . '/index.php', FileSystem::INDEX_PHP );
		}

		file_put_contents(
			$file,
			(string) wp_json_encode(
				array(
					's' => array_values( self::$states ),
					'g' => array_values( self::$stages ),
				)
			),
			LOCK_EX
		);
	}

	/** Files left by a run that was cut off for good. */
	private static function sweep(): void {
		$dir = self::dir();

		if ( '' === $dir || ! is_dir( $dir ) ) {
			return;
		}

		foreach ( (array) glob( $dir . '/*.json' ) as $file ) {
			if ( is_string( $file ) && time() - (int) filemtime( $file ) > self::STALE_SECONDS ) {
				wp_delete_file( $file );
			}
		}
	}

	private static function dir(): string {
		$up = wp_upload_dir( null, false );

		return empty( $up['basedir'] ) ? '' : trailingslashit( wp_normalize_path( (string) $up['basedir'] ) ) . self::FOLDER;
	}

	private static function clean_token( string $token ): string {
		return 1 === preg_match( '/^[a-z0-9]{32}$/', $token ) ? $token : '';
	}
}
