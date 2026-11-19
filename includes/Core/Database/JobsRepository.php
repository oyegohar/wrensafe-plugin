<?php
/**
 * Low-level CRUD access to the wp_wpbp_jobs table.
 *
 * BackupJob and RestoreJob are thin domain wrappers around this repository.
 *
 * @package WPBP\Core\Database
 */

namespace WPBP\Core\Database;

defined( 'ABSPATH' ) || exit;

// Database access in this file is direct by design: Wrensafe's own tables (or,
// in the backup/restore engine, the site's tables being exported, imported or
// swapped) have no WordPress API, and the data must be read fresh, so it is not
// object-cached. Every query goes through $wpdb->prepare(), with the table
// name as an identifier placeholder (%i).
// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

class JobsRepository {

	private static function table(): string {
		global $wpdb;

		return $wpdb->prefix . 'wpbp_jobs';
	}

	/**
	 * Insert a new job row and return its id.
	 */
	public static function create( string $job_type, array $payload = array() ): int {
		global $wpdb;

		$now = current_time( 'mysql' );

		$wpdb->insert(
			self::table(),
			array(
				'job_type'   => $job_type,
				'status'     => 'pending',
				'progress'   => 0,
				'payload'    => wp_json_encode( $payload ),
				'created_at' => $now,
				'updated_at' => $now,
			),
			array( '%s', '%s', '%d', '%s', '%s', '%s' )
		);

		return (int) $wpdb->insert_id;
	}

	/**
	 * Fetch a single job row as an associative array, or null when missing.
	 */
	public static function find( int $id ): ?array {
		global $wpdb;

		$row = $wpdb->get_row(
			$wpdb->prepare( 'SELECT * FROM %i WHERE id = %d', self::table(), $id ),
			ARRAY_A
		);

		return $row ? $row : null;
	}

	/**
	 * Update arbitrary job columns. Always bumps updated_at.
	 */
	public static function update( int $id, array $data ): void {
		global $wpdb;

		$data['updated_at'] = current_time( 'mysql' );

		$wpdb->update( self::table(), $data, array( 'id' => $id ) );
	}

	/**
	 * List recent jobs of a given type, newest first.
	 */
	public static function list_by_type( string $job_type, int $limit = 20 ): array {
		global $wpdb;

		return $wpdb->get_results(
			$wpdb->prepare(
				'SELECT * FROM %i WHERE job_type = %s ORDER BY id DESC LIMIT %d',
				self::table(),
				$job_type,
				$limit
			),
			ARRAY_A
		);
	}

	/**
	 * Jobs of a given type still marked running/pending whose heartbeat
	 * (updated_at, bumped by every progress/payload write) is older than the
	 * given number of minutes — i.e. jobs whose PHP request the server killed
	 * mid-step. Used by the stale-job watchdog.
	 */
	public static function list_stale_running( string $job_type, int $minutes ): array {
		global $wpdb;

		$cutoff = gmdate( 'Y-m-d H:i:s', strtotime( current_time( 'mysql' ) ) - ( $minutes * MINUTE_IN_SECONDS ) );

		return (array) $wpdb->get_results(
			$wpdb->prepare(
				'SELECT * FROM %i WHERE job_type = %s AND status IN (%s, %s) AND updated_at < %s ORDER BY id ASC LIMIT 20',
				self::table(),
				$job_type,
				'running',
				'pending',
				$cutoff
			),
			ARRAY_A
		);
	}

	/**
	 * Backup, restore, staging or transfer jobs still running (updated in
	 * the last $minutes minutes; a running job updates every few seconds, so older ones were stopped).
	 */
	public static function count_active( int $minutes = 3 ): int {
		global $wpdb;

		$since = gmdate( 'Y-m-d H:i:s', strtotime( current_time( 'mysql' ) ) - ( $minutes * MINUTE_IN_SECONDS ) );

		return (int) $wpdb->get_var(
			$wpdb->prepare(
				'SELECT COUNT(*) FROM %i WHERE status IN (%s, %s) AND updated_at >= %s',
				self::table(),
				'running',
				'pending',
				$since
			)
		);
	}

	/**
	 * Count completed jobs of a given type created since a given datetime —
	 * used to enforce monthly fair-use limits.
	 */
	public static function count_completed_since( string $job_type, string $since_mysql_datetime ): int {
		global $wpdb;

		return (int) $wpdb->get_var(
			$wpdb->prepare(
				'SELECT COUNT(*) FROM %i WHERE job_type = %s AND status = %s AND created_at >= %s',
				self::table(),
				$job_type,
				'completed',
				$since_mysql_datetime
			)
		);
	}

	/**
	 * Job counts since a (site-time) datetime, grouped by type and status.
	 *
	 * @return array<string,array<string,int>> job_type => status => count.
	 */
	public static function count_by_type_and_status_since( string $since_mysql_datetime ): array {
		global $wpdb;

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT job_type, status, COUNT(*) AS total FROM %i WHERE created_at >= %s GROUP BY job_type, status',
				self::table(),
				$since_mysql_datetime
			),
			ARRAY_A
		);

		$counts = array();

		foreach ( (array) $rows as $row ) {
			$counts[ (string) $row['job_type'] ][ (string) $row['status'] ] = (int) $row['total'];
		}

		return $counts;
	}
}
