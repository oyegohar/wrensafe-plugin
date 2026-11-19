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
		$wpdb->query( $wpdb->prepare( 'UPDATE %i SET staging_password = %s WHERE staging_password <> %s', $wpdb->prefix . 'wpbp_staging_sites', '', '' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- one-time clean-up of this plugin's own table.

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
				id bigint(20) unsigned NOT NULL auto_increment,
				job_type varchar(20) NOT NULL,
				status varchar(20) NOT NULL default 'pending',
				progress smallint(5) unsigned NOT NULL default '0',
				current_step varchar(191) NOT NULL default '',
				payload longtext,
				result longtext,
				error_message text,
				started_at datetime default NULL,
				completed_at datetime default NULL,
				created_at datetime default NULL,
				updated_at datetime default NULL,
				PRIMARY KEY  (id),
				KEY job_type (job_type),
				KEY status (status)
			) {$charset_collate};",

			"CREATE TABLE {$prefix}backups (
				id bigint(20) unsigned NOT NULL auto_increment,
				job_id bigint(20) unsigned default NULL,
				schedule_id bigint(20) unsigned default NULL,
				file_name varchar(255) NOT NULL,
				file_path varchar(500) NOT NULL,
				backup_type varchar(50) NOT NULL default 'full',
				size bigint(20) unsigned NOT NULL default '0',
				status varchar(20) NOT NULL default 'completed',
				source_url varchar(255) NOT NULL default '',
				wp_version varchar(20) NOT NULL default '',
				php_version varchar(20) NOT NULL default '',
				checksum varchar(128) NOT NULL default '',
				is_locked tinyint(1) unsigned NOT NULL default '0',
				note text,
				tags varchar(255) NOT NULL default '',
				destination varchar(50) NOT NULL default 'local',
				chain_mode varchar(20) NOT NULL default 'full',
				baseline_backup_id bigint(20) unsigned default NULL,
				is_encrypted tinyint(1) unsigned NOT NULL default '0',
				created_at datetime default NULL,
				PRIMARY KEY  (id),
				KEY backup_type (backup_type),
				KEY destination (destination),
				KEY baseline_backup_id (baseline_backup_id),
				KEY schedule_id (schedule_id)
			) {$charset_collate};",

			"CREATE TABLE {$prefix}schedules (
				id bigint(20) unsigned NOT NULL auto_increment,
				title varchar(191) NOT NULL,
				frequency varchar(20) NOT NULL default 'daily',
				custom_interval_seconds bigint(20) unsigned default NULL,
				run_time varchar(10) NOT NULL default '00:00',
				backup_type varchar(50) NOT NULL default 'full',
				destination varchar(50) NOT NULL default 'local',
				retention_count smallint(5) unsigned NOT NULL default '5',
				notify_email tinyint(1) unsigned NOT NULL default '0',
				enabled tinyint(1) unsigned NOT NULL default '1',
				last_run_at datetime default NULL,
				next_run_at datetime default NULL,
				last_status varchar(20) NOT NULL default '',
				created_at datetime default NULL,
				PRIMARY KEY  (id),
				KEY enabled (enabled)
			) {$charset_collate};",

			"CREATE TABLE {$prefix}logs (
				id bigint(20) unsigned NOT NULL auto_increment,
				level varchar(20) NOT NULL default 'info',
				message text NOT NULL,
				context longtext,
				job_id bigint(20) unsigned default NULL,
				created_at datetime default NULL,
				PRIMARY KEY  (id),
				KEY level (level),
				KEY job_id (job_id)
			) {$charset_collate};",

			"CREATE TABLE {$prefix}restore_points (
				id bigint(20) unsigned NOT NULL auto_increment,
				label varchar(191) NOT NULL default '',
				backup_id bigint(20) unsigned default NULL,
				reason varchar(100) NOT NULL default 'manual',
				created_at datetime default NULL,
				PRIMARY KEY  (id),
				KEY reason (reason)
			) {$charset_collate};",

			"CREATE TABLE {$prefix}migration_keys (
				id bigint(20) unsigned NOT NULL auto_increment,
				token_hash varchar(191) NOT NULL,
				direction varchar(20) NOT NULL default 'incoming',
				expires_at datetime NOT NULL,
				ip_restriction varchar(45) NOT NULL default '',
				used tinyint(1) unsigned NOT NULL default '0',
				created_at datetime default NULL,
				PRIMARY KEY  (id),
				KEY token_hash (token_hash)
			) {$charset_collate};",

			"CREATE TABLE {$prefix}staging_sites (
				id bigint(20) unsigned NOT NULL auto_increment,
				slug varchar(64) NOT NULL,
				path varchar(500) NOT NULL,
				url varchar(255) NOT NULL,
				table_prefix varchar(64) NOT NULL,
				status varchar(20) NOT NULL default 'creating',
				password_protected tinyint(1) unsigned NOT NULL default '0',
				staging_username varchar(60) NOT NULL default '',
				staging_password varchar(255) NOT NULL default '',
				last_synced_at datetime default NULL,
				created_at datetime default NULL,
				PRIMARY KEY  (id),
				KEY slug (slug)
			) {$charset_collate};",
		);
	}
}
