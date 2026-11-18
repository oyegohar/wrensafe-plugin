<?php
/**
 * Creates and upgrades Wrensafe's custom database tables.
 *
 * @package WPBP\Core\Database
 */

namespace WPBP\Core\Database;

defined( 'ABSPATH' ) || exit;

class Schema {

	const DB_VERSION_OPTION = 'wpbp_db_version';
	const DB_VERSION        = '1.5.0';

	/**
	 * Create or upgrade every Wrensafe table using dbDelta.
	 */
	public static function install(): void {
		global $wpdb;

		require_once \WPBP\Utils\Paths::admin_dir() . '/includes/upgrade.php';

		$charset_collate = $wpdb->get_charset_collate();

		foreach ( self::table_definitions( $wpdb, $charset_collate ) as $sql ) {
			dbDelta( $sql );
		}

		// 1.5.0: staging passwords are no longer stored (only their hash, in
		// the staging site's .htpasswd); clear the ones earlier versions kept.
		$wpdb->query( "UPDATE `{$wpdb->prefix}wpbp_staging_sites` SET staging_password = ''" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- one-time clean-up of this plugin's own table (fixed name).

		update_option( self::DB_VERSION_OPTION, self::DB_VERSION );
	}

	/**
	 * Run install() again if the stored schema version is out of date.
	 * dbDelta is idempotent, so this is safe to call on every page load.
	 */
	public static function maybe_upgrade(): void {
		if ( get_option( self::DB_VERSION_OPTION ) !== self::DB_VERSION ) {
			self::install();
		}
	}

	/**
	 * @return string[] CREATE TABLE statements, dbDelta-formatted.
	 */
	private static function table_definitions( \wpdb $wpdb, string $charset_collate ): array {
		$prefix = $wpdb->prefix . 'wpbp_';

		return array(
			"CREATE TABLE {$prefix}jobs (
				id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
				job_type VARCHAR(20) NOT NULL,
				status VARCHAR(20) NOT NULL DEFAULT 'pending',
				progress SMALLINT UNSIGNED NOT NULL DEFAULT 0,
				current_step VARCHAR(191) NOT NULL DEFAULT '',
				payload LONGTEXT NULL,
				result LONGTEXT NULL,
				error_message TEXT NULL,
				started_at DATETIME NULL,
				completed_at DATETIME NULL,
				created_at DATETIME NULL DEFAULT NULL,
				updated_at DATETIME NULL DEFAULT NULL,
				PRIMARY KEY  (id),
				KEY job_type (job_type),
				KEY status (status)
			) {$charset_collate};",

			"CREATE TABLE {$prefix}backups (
				id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
				job_id BIGINT UNSIGNED NULL,
				schedule_id BIGINT UNSIGNED NULL,
				file_name VARCHAR(255) NOT NULL,
				file_path VARCHAR(500) NOT NULL,
				backup_type VARCHAR(50) NOT NULL DEFAULT 'full',
				size BIGINT UNSIGNED NOT NULL DEFAULT 0,
				status VARCHAR(20) NOT NULL DEFAULT 'completed',
				source_url VARCHAR(255) NOT NULL DEFAULT '',
				wp_version VARCHAR(20) NOT NULL DEFAULT '',
				php_version VARCHAR(20) NOT NULL DEFAULT '',
				checksum VARCHAR(128) NOT NULL DEFAULT '',
				is_locked TINYINT(1) UNSIGNED NOT NULL DEFAULT 0,
				note TEXT NULL,
				tags VARCHAR(255) NOT NULL DEFAULT '',
				destination VARCHAR(50) NOT NULL DEFAULT 'local',
				chain_mode VARCHAR(20) NOT NULL DEFAULT 'full',
				baseline_backup_id BIGINT UNSIGNED NULL,
				is_encrypted TINYINT(1) UNSIGNED NOT NULL DEFAULT 0,
				created_at DATETIME NULL DEFAULT NULL,
				PRIMARY KEY  (id),
				KEY backup_type (backup_type),
				KEY destination (destination),
				KEY baseline_backup_id (baseline_backup_id),
				KEY schedule_id (schedule_id)
			) {$charset_collate};",

			"CREATE TABLE {$prefix}schedules (
				id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
				title VARCHAR(191) NOT NULL,
				frequency VARCHAR(20) NOT NULL DEFAULT 'daily',
				custom_interval_seconds BIGINT UNSIGNED NULL,
				run_time VARCHAR(10) NOT NULL DEFAULT '00:00',
				backup_type VARCHAR(50) NOT NULL DEFAULT 'full',
				destination VARCHAR(50) NOT NULL DEFAULT 'local',
				retention_count SMALLINT UNSIGNED NOT NULL DEFAULT 5,
				notify_email TINYINT(1) UNSIGNED NOT NULL DEFAULT 0,
				enabled TINYINT(1) UNSIGNED NOT NULL DEFAULT 1,
				last_run_at DATETIME NULL,
				next_run_at DATETIME NULL,
				last_status VARCHAR(20) NOT NULL DEFAULT '',
				created_at DATETIME NULL DEFAULT NULL,
				PRIMARY KEY  (id),
				KEY enabled (enabled)
			) {$charset_collate};",

			"CREATE TABLE {$prefix}logs (
				id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
				level VARCHAR(20) NOT NULL DEFAULT 'info',
				message TEXT NOT NULL,
				context LONGTEXT NULL,
				job_id BIGINT UNSIGNED NULL,
				created_at DATETIME NULL DEFAULT NULL,
				PRIMARY KEY  (id),
				KEY level (level),
				KEY job_id (job_id)
			) {$charset_collate};",

			"CREATE TABLE {$prefix}restore_points (
				id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
				label VARCHAR(191) NOT NULL DEFAULT '',
				backup_id BIGINT UNSIGNED NULL,
				reason VARCHAR(100) NOT NULL DEFAULT 'manual',
				created_at DATETIME NULL DEFAULT NULL,
				PRIMARY KEY  (id),
				KEY reason (reason)
			) {$charset_collate};",

			"CREATE TABLE {$prefix}migration_keys (
				id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
				token_hash VARCHAR(191) NOT NULL,
				direction VARCHAR(20) NOT NULL DEFAULT 'incoming',
				expires_at DATETIME NOT NULL,
				ip_restriction VARCHAR(45) NOT NULL DEFAULT '',
				used TINYINT(1) UNSIGNED NOT NULL DEFAULT 0,
				created_at DATETIME NULL DEFAULT NULL,
				PRIMARY KEY  (id),
				KEY token_hash (token_hash)
			) {$charset_collate};",

			"CREATE TABLE {$prefix}staging_sites (
				id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
				slug VARCHAR(64) NOT NULL,
				path VARCHAR(500) NOT NULL,
				url VARCHAR(255) NOT NULL,
				table_prefix VARCHAR(64) NOT NULL,
				status VARCHAR(20) NOT NULL DEFAULT 'creating',
				password_protected TINYINT(1) UNSIGNED NOT NULL DEFAULT 0,
				staging_username VARCHAR(60) NOT NULL DEFAULT '',
				staging_password VARCHAR(255) NOT NULL DEFAULT '',
				last_synced_at DATETIME NULL,
				created_at DATETIME NULL DEFAULT NULL,
				PRIMARY KEY  (id),
				KEY slug (slug)
			) {$charset_collate};",
		);
	}
}
