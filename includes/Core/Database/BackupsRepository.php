<?php
/**
 * Low-level CRUD access to the wp_wpbp_backups table.
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

class BackupsRepository {

	private static function table(): string {
		global $wpdb;

		return $wpdb->prefix . 'wpbp_backups';
	}

	public static function create( array $data ): int {
		global $wpdb;

		$data['created_at'] = $data['created_at'] ?? current_time( 'mysql' );

		$wpdb->insert( self::table(), $data );

		return (int) $wpdb->insert_id;
	}

	public static function find( int $id ): ?array {
		global $wpdb;

		$row = $wpdb->get_row(
			$wpdb->prepare( 'SELECT * FROM %i WHERE id = %d', self::table(), $id ),
			ARRAY_A
		);

		return $row ? $row : null;
	}

	public static function update( int $id, array $data ): void {
		global $wpdb;

		$wpdb->update( self::table(), $data, array( 'id' => $id ) );
	}

	public static function delete( int $id ): void {
		global $wpdb;

		$wpdb->delete( self::table(), array( 'id' => $id ) );
	}

	/**
	 * List backups, newest first.
	 */
	public static function list_all( int $limit = 100 ): array {
		global $wpdb;

		return $wpdb->get_results(
			$wpdb->prepare( 'SELECT * FROM %i ORDER BY id DESC LIMIT %d', self::table(), $limit ),
			ARRAY_A
		);
	}

	public static function count_all(): int {
		global $wpdb;

		return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i', self::table() ) );
	}

	/**
	 * Number of completed local backups — the count shown in the Backup
	 * Browser menu badge, page header, and Dashboard card. Failed/corrupted
	 * records and cancelled jobs are excluded (cancelled jobs never create a
	 * backup record at all; temp imports are never inserted into this table).
	 */
	public static function count_completed(): int {
		global $wpdb;

		return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE status = %s', self::table(), 'completed' ) );
	}

	public static function sum_size(): int {
		global $wpdb;

		return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT SUM(size) FROM %i', self::table() ) );
	}

	/**
	 * The most recent completed backup of a given backup_type ('full',
	 * 'database', …). Unlike find_latest_full(), which matches chain_mode,
	 * this is what "the last full-site backup" means to a user.
	 */
	public static function find_latest_of_type( string $backup_type ): ?array {
		global $wpdb;

		$row = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT * FROM %i WHERE backup_type = %s AND status = %s ORDER BY id DESC LIMIT 1',
				self::table(),
				$backup_type,
				'completed'
			),
			ARRAY_A
		);

		return $row ? $row : null;
	}

	/** The newest completed backup of any type, or null. */
	public static function find_latest_completed(): ?array {
		global $wpdb;

		$row = $wpdb->get_row(
			$wpdb->prepare( 'SELECT * FROM %i WHERE status = %s ORDER BY id DESC LIMIT 1', self::table(), 'completed' ),
			ARRAY_A
		);

		return $row ? $row : null;
	}

	/**
	 * The most recent full (non-chained) backup, used as an incremental/
	 * differential baseline.
	 */
	public static function find_latest_full(): ?array {
		global $wpdb;

		$row = $wpdb->get_row(
			$wpdb->prepare( 'SELECT * FROM %i WHERE chain_mode = %s AND status = %s ORDER BY id DESC LIMIT 1', self::table(), 'full', 'completed' ),
			ARRAY_A
		);

		return $row ? $row : null;
	}

	/**
	 * The newest completed, unencrypted full-mode backup of a given type —
	 * the baseline an incremental/differential backup of that type compares
	 * against (its checksums.json must be readable without a password).
	 */
	public static function find_latest_baseline( string $backup_type ): ?array {
		global $wpdb;

		$row = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT * FROM %i WHERE chain_mode = %s AND status = %s AND is_encrypted = %d AND backup_type = %s ORDER BY id DESC LIMIT 1',
				self::table(),
				'full',
				'completed',
				0,
				$backup_type
			),
			ARRAY_A
		);

		return $row ? $row : null;
	}

	/**
	 * Most recent backup belonging to a chain (the baseline itself, or its
	 * latest incremental/differential) — used as the comparison point for
	 * the next incremental backup.
	 */
	public static function find_latest_in_chain( int $baseline_id ): ?array {
		global $wpdb;

		$row = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT * FROM %i WHERE (id = %d OR baseline_backup_id = %d) AND status = %s ORDER BY id DESC LIMIT 1',
				self::table(),
				$baseline_id,
				$baseline_id,
				'completed'
			),
			ARRAY_A
		);

		return $row ? $row : null;
	}

	/**
	 * Walk a backup's chain from baseline to itself, oldest first.
	 */
	public static function get_chain( int $backup_id ): array {
		$chain   = array();
		$current = self::find( $backup_id );

		while ( $current ) {
			array_unshift( $chain, $current );

			if ( empty( $current['baseline_backup_id'] ) ) {
				break;
			}

			$current = self::find( (int) $current['baseline_backup_id'] );
		}

		return $chain;
	}

	/**
	 * Incremental/differential backups older than the given number of days
	 * (and not locked) — used by an add-on's incremental retention limit
	 * (the free plugin sets no limit).
	 *
	 * Only backups belonging to a *superseded* chain are eligible (i.e. a
	 * newer full baseline already exists) — links in the still-current
	 * chain are never deleted, since restoring any member replays the
	 * whole chain from the baseline forward and an earlier deletion would
	 * silently break that.
	 */
	public static function list_old_incrementals( int $days ): array {
		global $wpdb;

		$current_baseline = self::find_latest_full();

		if ( null === $current_baseline ) {
			return array();
		}

		return $wpdb->get_results(
			$wpdb->prepare(
				'SELECT * FROM %i WHERE chain_mode IN (%s, %s) AND is_locked = %d AND created_at < %s AND ( baseline_backup_id IS NULL OR baseline_backup_id != %d )',
				self::table(),
				'incremental',
				'differential',
				0,
				// created_at is written in site time (current_time( 'mysql' )).
				wp_date( 'Y-m-d H:i:s', time() - $days * DAY_IN_SECONDS ),
				(int) $current_baseline['id']
			),
			ARRAY_A
		);
	}

	/**
	 * Backups created by a given schedule, newest first — used for retention cleanup.
	 */
	public static function list_for_schedule( int $schedule_id ): array {
		global $wpdb;

		return $wpdb->get_results(
			$wpdb->prepare( 'SELECT * FROM %i WHERE schedule_id = %d ORDER BY id DESC', self::table(), $schedule_id ),
			ARRAY_A
		);
	}
}
