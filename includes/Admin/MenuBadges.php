<?php
/**
 * The numbers and "!" marks next to Wrensafe's menu items.
 *
 * A mark means "something here needs you": updates waiting (Safe Updates), links to fix (Fix Links), a restore
 * that failed (Rollback), scheduled backups that failed or stopped running (Scheduled Backups). Things that only
 * accumulate (rollback points, schedules) get no number, so a mark is never there for good and keeps its meaning.
 *
 * @package WPBP\Admin
 */

namespace WPBP\Admin;

use WPBP\Admin\Pages\FixLinksPage;
use WPBP\Core\Database\JobsRepository;
use WPBP\Core\Database\SchedulesRepository;
use WPBP\Restore\LinkCheck;
use WPBP\Updates\UpdateTypes;

defined( 'ABSPATH' ) || exit;

class MenuBadges {

	/** User meta: when this user last opened the Rollback screen (site time, MySQL format). */
	const SEEN_ROLLBACK = 'wpbp_seen_rollback';

	/** Option: the Safe Updates count and what it was worked out from (removed with the plugin's data). */
	const UPDATES_CACHE = 'wpbp_menu_updates';

	/** A failed restore is pointed out for this long (unless the Rollback screen was opened since). */
	const RESTORE_DAYS = 14;

	/** A schedule this late is reported as not running (WP-Cron needs visitors; a quiet site can be hours late). */
	const LATE_HOURS = 24;

	public static function register(): void {
		// Updates installed or removed: count again.
		add_action( 'upgrader_process_complete', array( self::class, 'forget_updates' ) );
		add_action( 'deleted_plugin', array( self::class, 'forget_updates' ) );
	}

	public static function forget_updates(): void {
		delete_option( self::UPDATES_CACHE );
	}

	/**
	 * The mark for a menu item, or null for none.
	 *
	 * @return array{text:string,label:string}|null
	 */
	public static function for_page( string $slug ): ?array {
		try {
			switch ( $slug ) {
				case 'wrensafe-safe-updates':
					return self::updates();
				case FixLinksPage::SLUG:
					return self::links();
				case 'wrensafe-rollback':
					return self::rollback();
				case 'wrensafe-schedule':
					return self::schedules();
			}
		} catch ( \Throwable $e ) {
			return null; // A menu mark is never worth a broken admin menu.
		}

		return null;
	}

	/** Updates Safe Updates can run now (the ones it lists without a problem). */
	private static function updates(): ?array {
		$stamp = self::updates_stamp();
		$saved = get_option( self::UPDATES_CACHE );

		if ( is_array( $saved ) && ( $saved['stamp'] ?? '' ) === $stamp ) {
			$count = (int) ( $saved['count'] ?? 0 );
		} else {
			$count = 0;

			foreach ( UpdateTypes::all() as $type ) {
				foreach ( (array) $type->available() as $item ) {
					if ( '' === (string) ( $item['problem'] ?? '' ) ) {
						++$count;
					}
				}
			}

			update_option(
				self::UPDATES_CACHE,
				array(
					'stamp' => $stamp,
					'count' => $count,
				),
				false
			);
		}

		if ( $count < 1 ) {
			return null;
		}

		return array(
			'text'  => (string) $count,
			/* translators: %d: number of updates */
			'label' => sprintf( _n( '%d update waiting', '%d updates waiting', $count, 'wrensafe' ), $count ),
		);
	}

	/** What the count depends on: WordPress's update checks and the update types Safe Updates handles. */
	private static function updates_stamp(): string {
		$parts = array( implode( ',', array_keys( UpdateTypes::all() ) ) );

		foreach ( array( 'update_plugins', 'update_themes', 'update_core' ) as $name ) {
			$check   = get_site_transient( $name );
			$parts[] = is_object( $check ) ? (int) ( $check->last_checked ?? 0 ) . ':' . ( isset( $check->response ) && is_array( $check->response ) ? count( $check->response ) : ( isset( $check->updates ) && is_array( $check->updates ) ? count( $check->updates ) : 0 ) ) : '-';
		}

		return md5( implode( '|', $parts ) );
	}

	/** Links the last check found to fix. Gone once they're fixed. */
	private static function links(): ?array {
		$report = LinkCheck::report( LinkCheck::state() );
		$count  = (int) $report['fixable'];

		if ( 'checked' !== $report['status'] || $count < 1 ) {
			return null;
		}

		return array(
			'text'  => (string) $count,
			/* translators: %d: number of links */
			'label' => sprintf( _n( '%d link to fix', '%d links to fix', $count, 'wrensafe' ), $count ),
		);
	}

	/** The last restore failed, recently, and the Rollback screen hasn't been opened since. */
	private static function rollback(): ?array {
		global $plugin_page;

		// Opening the Rollback screen is seeing it (the menu is built before the screen).
		if ( 'wrensafe-rollback' === $plugin_page ) {
			update_user_meta( get_current_user_id(), self::SEEN_ROLLBACK, current_time( 'mysql' ) );
			return null;
		}

		$last = JobsRepository::list_by_type( 'restore', 1 );
		$job  = $last[0] ?? null;

		if ( ! is_array( $job ) || 'failed' !== (string) $job['status'] ) {
			return null;
		}

		$when = (string) ( $job['updated_at'] ?? $job['created_at'] ?? '' );
		$seen = (string) get_user_meta( get_current_user_id(), self::SEEN_ROLLBACK, true );

		if ( '' === $when || strtotime( $when ) < strtotime( current_time( 'mysql' ) ) - self::RESTORE_DAYS * DAY_IN_SECONDS || ( '' !== $seen && $seen >= $when ) ) {
			return null;
		}

		return array(
			'text'  => '!',
			'label' => __( 'A restore failed. Its rollback point is here.', 'wrensafe' ),
		);
	}

	/** A schedule whose last backup failed, or that is far behind (scheduled backups aren't running). */
	private static function schedules(): ?array {
		$late = gmdate( 'Y-m-d H:i:s', strtotime( current_time( 'mysql' ) ) - self::LATE_HOURS * HOUR_IN_SECONDS );

		foreach ( SchedulesRepository::list_all() as $schedule ) {
			if ( empty( $schedule['enabled'] ) ) {
				continue;
			}

			if ( 'failed' === (string) ( $schedule['last_status'] ?? '' ) ) {
				return array(
					'text'  => '!',
					'label' => __( 'The last scheduled backup failed.', 'wrensafe' ),
				);
			}

			$next = (string) ( $schedule['next_run_at'] ?? '' );

			if ( '' !== $next && $next < $late ) {
				return array(
					'text'  => '!',
					'label' => __( 'Scheduled backups aren’t running.', 'wrensafe' ),
				);
			}
		}

		return null;
	}
}
