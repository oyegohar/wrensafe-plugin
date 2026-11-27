<?php
/**
 * Collects server and environment facts for the System Info page and
 * the Pre-Migration Health Check.
 *
 * @package WPBP\Utils
 */

namespace WPBP\Utils;

defined( 'ABSPATH' ) || exit;

class ServerInfo {

	public static function php_version(): string {
		return PHP_VERSION;
	}

	public static function wordpress_version(): string {
		return get_bloginfo( 'version' );
	}

	public static function db_version(): string {
		global $wpdb;
		return (string) $wpdb->db_version();
	}

	public static function memory_limit(): string {
		return (string) ini_get( 'memory_limit' );
	}

	public static function memory_limit_bytes(): int {
		return wp_convert_hr_to_bytes( (string) ini_get( 'memory_limit' ) );
	}

	public static function max_execution_time(): int {
		return (int) ini_get( 'max_execution_time' );
	}

	public static function max_upload_size(): int {
		return (int) wp_max_upload_size();
	}

	public static function post_max_size(): string {
		return (string) ini_get( 'post_max_size' );
	}

	/** The disk backups are written to: the backup folder's, or the site's. */
	private static function disk_path(): string {
		$storage = FileSystem::storage_dir();

		return is_dir( $storage ) ? $storage : Paths::root();
	}

	public static function disk_free_bytes(): int {
		$free = function_exists( 'disk_free_space' ) ? @disk_free_space( self::disk_path() ) : false; // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Some hosts disable or restrict it.
		return false === $free ? 0 : (int) $free;
	}

	public static function disk_total_bytes(): int {
		$total = function_exists( 'disk_total_space' ) ? @disk_total_space( self::disk_path() ) : false; // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Some hosts disable or restrict it.
		return false === $total ? 0 : (int) $total;
	}

	public static function has_zip_archive(): bool {
		return class_exists( 'ZipArchive' );
	}

	/** Whether Wrensafe can write and read ZIP backups here (ZipArchive or its built-in engine). */
	public static function can_zip(): bool {
		return self::has_zip_archive() || \WPBP\Backup\ZipStreamWriter::available();
	}

	public static function has_openssl(): bool {
		return extension_loaded( 'openssl' );
	}

	public static function has_curl(): bool {
		return extension_loaded( 'curl' );
	}

	public static function has_phar(): bool {
		return class_exists( 'PharData' );
	}

	public static function ssl_enabled(): bool {
		return is_ssl();
	}

	public static function wp_cron_disabled(): bool {
		return defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON;
	}

	/**
	 * Return all server facts as a flat key → value array.
	 */
	public static function all(): array {
		return array(
			'php_version'        => self::php_version(),
			'wordpress_version'  => self::wordpress_version(),
			'db_version'         => self::db_version(),
			'memory_limit'       => self::memory_limit(),
			'max_execution_time' => self::max_execution_time() . 's',
			'max_upload_size'    => size_format( self::max_upload_size() ),
			'post_max_size'      => self::post_max_size(),
			'disk_free'          => size_format( self::disk_free_bytes() ),
			'disk_total'         => size_format( self::disk_total_bytes() ),
			'zip_archive'        => self::has_zip_archive() ? 'Available' : ( self::can_zip() ? 'Not available (Wrensafe uses its built-in ZIP engine)' : 'Not available' ),
			'openssl'            => self::has_openssl() ? 'Available' : 'Not available',
			'curl'               => self::has_curl() ? 'Available' : 'Not available',
			'phar_data'          => self::has_phar() ? 'Available' : 'Not available',
			'ssl'                => self::ssl_enabled() ? 'Enabled' : 'Disabled',
			'wp_cron'            => self::wp_cron_disabled() ? 'Disabled (DISABLE_WP_CRON)' : 'Enabled',
		);
	}
}
