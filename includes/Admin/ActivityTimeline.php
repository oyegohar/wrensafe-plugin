<?php
/**
 * Activity Logs as a timeline: Wrensafe's own work (backups, restores, Safe Updates, moves, Fix Links), one card per
 * job with its steps inside, grouped by day. Read from the activity log (wpbp_logs) only, so it still reads well
 * after old job records are gone. Who changed what on the rest of the site is Wrensafe Pro's Site Activity.
 *
 * @package WPBP\Admin
 */

namespace WPBP\Admin;

use WPBP\Core\Database\LogsRepository;

defined( 'ABSPATH' ) || exit;

final class ActivityTimeline {

	/** Cards on one page of the timeline. */
	const PAGE_ITEMS = 25;

	/** Log rows read at a time while looking for them, and at most this many reads per page. */
	const BATCH       = 1000;
	const MAX_BATCHES = 10;

	/** A step written this soon before or after a job (a pre-update backup's start, an off-site copy) still belongs to it. */
	const TRAILING_SECONDS = 90;

	/** The same event again within this time joins the card before it ("Fix Links checked the site · 3 times"). */
	const REPEAT_SECONDS = 600;

	/** The filters above the timeline. */
	const KINDS = array( 'backup', 'restore', 'update', 'move', 'tools', 'problems' );

	/** What each kind of entry is, by the start of its message (event code or sentence). */
	private const PATTERNS = array(
		'update'  => '/^(?:Safe Updates|Caches cleared after updates|Clearing the .+ cache failed)/',
		'backup'  => '/^(?:backup_|remote_copy_|remote_retention_|large_file_excluded|wp_config_excluded|Backup job #|Verification (?:started|passed|failed)|Scheduled backup|Pre-update backup|Starting pre-update backup)/i',
		'restore' => '/^(?:restore_|cross_site_restore_|Restore job #|upload_|rollback_|foreign_import_|import_)/i',
		'move'    => '/^(?:migration_|direct_migration_|Migration transfer #|Incoming direct migration|Migration Assistant|staging_)/i',
		'tools'   => '/^(?:link_check_|link_fix_|search_replace_|malware_|scan_|ai_)/i',
	);

	/** Job types and the kind they belong to. */
	private const JOB_KINDS = array(
		'backup'             => 'backup',
		'restore'            => 'restore',
		'import'             => 'restore',
		'restore_test'       => 'restore',
		'safe_update'        => 'update',
		'migration_transfer' => 'move',
		'staging'            => 'move',
	);

	public static function register(): void {
		add_action( 'admin_enqueue_scripts', array( self::class, 'enqueue' ) );
	}

	public static function enqueue( string $hook_suffix ): void {
		if ( false !== strpos( $hook_suffix, 'wrensafe-logs' ) ) {
			wp_enqueue_style( 'wpbp-activity', WPBP_PLUGIN_URL . 'assets/admin/css/activity.css', array( 'wpbp-admin' ), WPBP_VERSION );
			wp_style_add_data( 'wpbp-activity', 'rtl', 'replace' );
		}
	}

	/**
	 * One page of the timeline, newest first.
	 *
	 * @return array{items:array<int,array<string,mixed>>,older:int}
	 */
	public static function page( string $kind, string $search, int $before ): array {
		$kind   = in_array( $kind, self::KINDS, true ) ? $kind : '';
		$search = trim( $search );
		$cursor = $before > 0 ? $before : PHP_INT_MAX;
		$rows   = array();
		$items  = array();
		$more   = false;

		for ( $batch = 0; $batch < self::MAX_BATCHES; $batch++ ) {
			$read = LogsRepository::before( $cursor, self::BATCH );

			foreach ( $read as $row ) {
				$rows[ (int) $row['id'] ] = $row;
			}

			$more = count( $read ) === self::BATCH;

			if ( $read ) {
				$cursor = (int) end( $read )['id'];
			}

			// A page holds the cards whose newest entry is older than $before: each card is on one page only,
			// complete (every step of a job is read, also those older than what was read here).
			$items = array_values(
				array_filter(
					self::matching( self::build( self::complete( $rows ) ), $kind, $search ),
					static function ( array $item ) use ( $before ): bool {
						return $before <= 0 || $item['last_id'] < $before;
					}
				)
			);

			if ( ! $more || count( $items ) > self::PAGE_ITEMS ) {
				break;
			}
		}

		$older = 0;

		if ( count( $items ) > self::PAGE_ITEMS ) {
			$items = array_slice( $items, 0, self::PAGE_ITEMS );
			$older = (int) end( $items )['last_id'];
		} elseif ( $more ) {
			$older = $cursor;
		}

		return array(
			'items' => $items,
			'older' => $older,
		);
	}

	/** Totals for the last 30 days. @return array{backups:int,restores:int,updates:int,problems:int} */
	public static function totals(): array {
		return LogsRepository::totals_since( gmdate( 'Y-m-d H:i:s', strtotime( current_time( 'mysql' ) ) - 30 * DAY_IN_SECONDS ) );
	}

	/** Every step of each job that appears in the rows (a job may have started before the oldest row read). */
	private static function complete( array $rows ): array {
		$jobs = array();

		foreach ( $rows as $row ) {
			if ( ! empty( $row['job_id'] ) ) {
				$jobs[ (int) $row['job_id'] ] = true;
			}
		}

		foreach ( LogsRepository::for_jobs( array_keys( $jobs ), 4000 ) as $row ) {
			$rows[ (int) $row['id'] ] = $row;
		}

		ksort( $rows );

		return $rows;
	}

	/**
	 * Rows (oldest first) → cards (newest first): a job's rows together, with the entries written while it ran;
	 * other entries on their own, the same event in a row counted once.
	 *
	 * @param array<int,array<string,mixed>> $rows
	 * @return array<int,array<string,mixed>>
	 */
	private static function build( array $rows ): array {
		$jobs = array();

		foreach ( $rows as $row ) {
			if ( empty( $row['job_id'] ) ) {
				continue;
			}

			$id = (int) $row['job_id'];

			if ( ! isset( $jobs[ $id ] ) ) {
				$jobs[ $id ] = array(
					'job'   => $id,
					'kind'  => self::kind_of( (string) $row['message'] ),
					'rows'  => array(),
					'first' => (int) $row['id'],
					'last'  => (int) $row['id'],
					'end'   => 0,
				);
			}

			$jobs[ $id ]['rows'][] = $row;
			$jobs[ $id ]['last']   = (int) $row['id'];
			$jobs[ $id ]['end']    = (int) strtotime( (string) $row['created_at'] );

			if ( ! isset( $jobs[ $id ]['start'] ) ) {
				$jobs[ $id ]['start'] = $jobs[ $id ]['end'];
			}

			if ( '' === $jobs[ $id ]['kind'] ) {
				$jobs[ $id ]['kind'] = self::kind_of( (string) $row['message'] );
			}
		}

		$types = \WPBP\Core\Database\JobsRepository::types_of( array_keys( $jobs ) );

		foreach ( $types as $id => $type ) {
			if ( isset( $jobs[ $id ], self::JOB_KINDS[ $type['job_type'] ] ) ) {
				$jobs[ $id ]['kind']   = self::JOB_KINDS[ $type['job_type'] ];
				$jobs[ $id ]['status'] = $type['status'];
				$jobs[ $id ]['error']  = $type['error_message'];
			}
		}

		$alone = array();

		foreach ( $rows as $row ) {
			if ( ! empty( $row['job_id'] ) ) {
				continue;
			}

			$owner = self::owner( $row, $jobs );

			if ( null !== $owner ) {
				$jobs[ $owner ]['rows'][] = $row;
				continue;
			}

			$code = self::code( (string) $row['message'] );
			$prev = count( $alone ) - 1;

			$time = (int) strtotime( (string) $row['created_at'] );

			if ( $prev >= 0 && '' !== $code && $alone[ $prev ]['code'] === $code && $alone[ $prev ]['level'] === $row['level'] && abs( $time - $alone[ $prev ]['end'] ) <= self::REPEAT_SECONDS ) {
				$alone[ $prev ]['rows'][] = $row;
				$alone[ $prev ]['last']   = (int) $row['id'];
				$alone[ $prev ]['end']    = $time;
				continue;
			}

			$alone[] = array(
				'job'   => 0,
				'code'  => $code,
				'level' => (string) $row['level'],
				'kind'  => self::kind_of( (string) $row['message'] ),
				'rows'  => array( $row ),
				'first' => (int) $row['id'],
				'last'  => (int) $row['id'],
				'end'   => $time,
			);
		}

		$items = array();

		foreach ( array_merge( array_values( $jobs ), $alone ) as $group ) {
			usort(
				$group['rows'],
				static function ( array $a, array $b ): int {
					return (int) $a['id'] <=> (int) $b['id'];
				}
			);
			$items[] = self::card( $group );
		}

		usort(
			$items,
			static function ( array $a, array $b ): int {
				return $b['last_id'] <=> $a['last_id'];
			}
		);

		return $items;
	}

	/**
	 * The job a separate entry belongs to: one running when it was written; otherwise, of the same kind, the job that
	 * ended just before it, or (for "Starting …") the job that starts just after it. The nearest one wins.
	 */
	private static function owner( array $row, array $jobs ): ?int {
		$id       = (int) $row['id'];
		$time     = (int) strtotime( (string) $row['created_at'] );
		$kind     = self::kind_of( (string) $row['message'] );
		$starting = 0 === stripos( (string) $row['message'], 'Starting ' );
		$best     = null;
		$score    = array( 0, 0 );

		foreach ( $jobs as $job ) {
			$same = '' !== $kind && $kind === $job['kind'];
			$rank = array( 0, 0 );

			if ( $id > $job['first'] && $id < $job['last'] ) {
				$rank = array( 3 + ( $same ? 1 : 0 ), -( $job['last'] - $job['first'] ) );
			} elseif ( $same && ! $starting && $id > $job['last'] && $time - $job['end'] <= self::TRAILING_SECONDS ) {
				$rank = array( 2, -( $id - $job['last'] ) );
			} elseif ( $same && $id < $job['first'] && $job['start'] - $time <= self::TRAILING_SECONDS ) {
				$rank = array( $starting ? 2 : 1, -( $job['first'] - $id ) );
			}

			if ( $rank[0] > 0 && ( $rank[0] > $score[0] || ( $rank[0] === $score[0] && $rank[1] > $score[1] ) ) ) {
				$best  = $job['job'];
				$score = $rank;
			}
		}

		return $best;
	}

	/** A card: what happened, how it went, when, and its steps. @return array<string,mixed> */
	private static function card( array $group ): array {
		$rows   = $group['rows'];
		$levels = array_column( $rows, 'level' );
		$kind   = '' !== $group['kind'] ? $group['kind'] : 'tools';
		$status = self::status( $group, $levels );
		$first  = reset( $rows );
		$last   = end( $rows );
		$facts  = array();

		if ( $group['job'] ) {
			$seconds = (int) strtotime( (string) $last['created_at'] ) - (int) strtotime( (string) $first['created_at'] );
			$facts[] = self::duration( $seconds );
			/* translators: %s: number of steps */
			$facts[] = sprintf( _n( '%s step', '%s steps', count( $rows ), 'wrensafe' ), number_format_i18n( count( $rows ) ) );

			foreach ( $rows as $row ) {
				if ( 1 === preg_match( '/^backup_archive_writer_completed: archive written \(([^,)]+)/', (string) $row['message'], $m ) ) {
					array_unshift( $facts, $m[1] );
				}
			}
		} elseif ( count( $rows ) > 1 ) {
			/* translators: %s: how many times the same event happened in a row */
			$facts[] = sprintf( __( '%s times', 'wrensafe' ), number_format_i18n( count( $rows ) ) );
		}

		return array(
			'kind'     => $kind,
			'status'   => $status,
			'title'    => self::title( $kind, $status, $group ),
			'summary'  => self::summary( $group, $status ),
			'facts'    => array_values( array_filter( $facts ) ),
			'time'     => (string) $last['created_at'],
			'job'      => (int) $group['job'],
			'steps'    => $group['job'] || count( $rows ) > 1 ? $rows : array(),
			'problem'  => 'failed' === $status || 'warning' === $status || (bool) array_intersect( $levels, array( 'error', 'failed', 'critical' ) ),
			'search'   => strtolower( implode( ' ', array_column( $rows, 'message' ) ) ),
			'first_id' => (int) $first['id'],
			'last_id'  => (int) $last['id'],
		);
	}

	/** completed | failed | cancelled | running | warning | info */
	private static function status( array $group, array $levels ): string {
		$job   = (string) ( $group['status'] ?? '' );
		$wrong = (bool) array_intersect( $levels, array( 'warning', 'error', 'failed', 'critical' ) );

		// It reached its end (a success entry, or a run's "… finished: 1 updated, 1 rolled back" written as a
		// warning), though something on the way may not have gone well (an off-site copy, one update put back).
		$worked = in_array( 'success', $levels, true );

		foreach ( $group['rows'] as $row ) {
			if ( in_array( $row['level'], array( 'info', 'warning' ), true ) && 1 === preg_match( '/^Safe Updates run #\d+ finished:/', (string) $row['message'] ) ) {
				$worked = true;
			}
		}

		if ( in_array( $job, array( 'completed', 'failed', 'cancelled' ), true ) ) {
			return 'completed' === $job && $wrong ? 'warning' : $job;
		}

		if ( in_array( $job, array( 'running', 'pending' ), true ) ) {
			return 'running';
		}

		if ( $worked && $group['job'] ) {
			return $wrong ? 'warning' : 'completed';
		}

		if ( array_intersect( $levels, array( 'failed', 'critical', 'error' ) ) ) {
			return 'failed';
		}

		if ( in_array( 'warning', $levels, true ) ) {
			return 'warning';
		}

		return in_array( 'success', $levels, true ) ? 'completed' : 'info';
	}

	private static function title( string $kind, string $status, array $group ): string {
		if ( ! $group['job'] ) {
			$code   = (string) ( $group['code'] ?? '' );
			$titles = array(
				'link_check_finished'       => __( 'Fix Links checked the site', 'wrensafe' ),
				'link_fix_completed'        => __( 'Fix Links repaired links', 'wrensafe' ),
				'search_replace_completed'  => __( 'Search & Replace finished', 'wrensafe' ),
				'direct_migration_received' => __( 'A site was moved here', 'wrensafe' ),
				'remote_copy_completed'     => __( 'Off-site copy saved', 'wrensafe' ),
				'remote_copy_failed'        => __( 'Off-site copy failed', 'wrensafe' ),
				'large_file_excluded'       => __( 'Large file left out of a backup', 'wrensafe' ),
				'wp_config_excluded'        => __( 'wp-config.php left out of a backup', 'wrensafe' ),
				'backup_folder_changed'     => __( 'Backup folder changed', 'wrensafe' ),
				'foreign_import_failed'     => __( 'A backup from another plugin couldn’t be prepared', 'wrensafe' ),
				'ai_action'                 => __( 'AI assistant', 'wrensafe' ),
				'ai_request'                => __( 'An AI assistant asked for approval', 'wrensafe' ),
				'ai_request_approved'       => __( 'AI request approved', 'wrensafe' ),
				'ai_request_declined'       => __( 'AI request declined', 'wrensafe' ),
			);

			return $titles[ $code ] ?? wp_html_excerpt( self::sentence( (string) $group['rows'][0]['message'] ), 90, '…' );
		}

		$titles = array(
			'backup'  => array( __( 'Backup completed', 'wrensafe' ), __( 'Backup failed', 'wrensafe' ), __( 'Backup cancelled', 'wrensafe' ), __( 'Backup in progress', 'wrensafe' ) ),
			'restore' => array( __( 'Site restored', 'wrensafe' ), __( 'Restore failed', 'wrensafe' ), __( 'Restore cancelled', 'wrensafe' ), __( 'Restore in progress', 'wrensafe' ) ),
			'update'  => array( __( 'Safe Updates finished', 'wrensafe' ), __( 'Safe Updates didn’t finish', 'wrensafe' ), __( 'Safe Updates stopped', 'wrensafe' ), __( 'Safe Updates running', 'wrensafe' ) ),
			'move'    => array( __( 'Move finished', 'wrensafe' ), __( 'Move failed', 'wrensafe' ), __( 'Move cancelled', 'wrensafe' ), __( 'Move in progress', 'wrensafe' ) ),
			'tools'   => array( __( 'Finished', 'wrensafe' ), __( 'Failed', 'wrensafe' ), __( 'Cancelled', 'wrensafe' ), __( 'In progress', 'wrensafe' ) ),
		);
		$set    = $titles[ $kind ] ?? $titles['tools'];

		if ( preg_grep( '/^restore_test_/', array_column( $group['rows'], 'message' ) ) ) {
			$set = array( __( 'Restore test passed', 'wrensafe' ), __( 'Restore test failed', 'wrensafe' ), __( 'Restore test stopped', 'wrensafe' ), __( 'Restore test running', 'wrensafe' ) );
		} elseif ( 'backup' === $kind && preg_grep( '/^(?:Starting pre-update backup|Pre-update backup)/', array_column( $group['rows'], 'message' ) ) ) {
			$set = array( __( 'Backup before updates', 'wrensafe' ), __( 'Backup before updates failed', 'wrensafe' ), __( 'Backup before updates cancelled', 'wrensafe' ), __( 'Backup before updates in progress', 'wrensafe' ) );
		}
		$index = array(
			'failed'    => 1,
			'cancelled' => 2,
			'running'   => 3,
		);

		return $set[ $index[ $status ] ?? 0 ];
	}

	/** The line under the title: the reason when it went wrong, otherwise how it ended. */
	private static function summary( array $group, string $status ): string {
		$rows = array_reverse( $group['rows'] );

		if ( 'failed' === $status || 'warning' === $status ) {
			if ( ! empty( $group['error'] ) && 'failed' === $status ) {
				return (string) $group['error'];
			}

			foreach ( $rows as $row ) {
				if ( in_array( $row['level'], 'failed' === $status ? array( 'failed', 'critical', 'error' ) : array( 'warning', 'error', 'failed', 'critical' ), true ) ) {
					return self::sentence( (string) $row['message'] );
				}
			}
		}

		foreach ( $rows as $row ) {
			if ( 'success' === $row['level'] ) {
				return self::sentence( (string) $row['message'] );
			}
		}

		return self::sentence( (string) $rows[0]['message'] );
	}

	/** "backup_completed: full backup job #4 completed." → "Full backup job #4 completed." */
	public static function sentence( string $message ): string {
		if ( 1 === preg_match( '/^[a-z0-9_]+:\s+(.*)$/s', $message, $m ) ) {
			$message = $m[1];
		}

		$message = trim( $message );

		// Not a file name or a path ("wp-config.php is not included…").
		return 1 === preg_match( '/^[a-z][a-z ]*(?:\s|$)/', $message ) && 1 !== preg_match( '/^\S*[.\/_-]/', $message ) ? ucfirst( $message ) : $message;
	}

	public static function code( string $message ): string {
		return 1 === preg_match( '/^([a-z0-9_]+):\s/', $message, $m ) ? $m[1] : '';
	}

	private static function kind_of( string $message ): string {
		foreach ( self::PATTERNS as $kind => $pattern ) {
			if ( 1 === preg_match( $pattern, $message ) ) {
				return $kind;
			}
		}

		return '';
	}

	private static function duration( int $seconds ): string {
		if ( $seconds < 1 ) {
			return '';
		}

		if ( $seconds < MINUTE_IN_SECONDS ) {
			/* translators: %s: seconds */
			return sprintf( _n( 'took %s second', 'took %s seconds', $seconds, 'wrensafe' ), number_format_i18n( $seconds ) );
		}

		/* translators: %s: how long, such as "3 mins" */
		return sprintf( __( 'took %s', 'wrensafe' ), human_time_diff( 0, $seconds ) );
	}

	/** @param array<int,array<string,mixed>> $items */
	private static function matching( array $items, string $kind, string $search ): array {
		$search = strtolower( $search );

		return array_values(
			array_filter(
				$items,
				static function ( array $item ) use ( $kind, $search ): bool {
					if ( 'problems' === $kind ? ! $item['problem'] : ( '' !== $kind && $kind !== $item['kind'] ) ) {
						return false;
					}

					return '' === $search || false !== strpos( $item['search'], $search ) || false !== strpos( strtolower( $item['title'] ), $search );
				}
			)
		);
	}
}
