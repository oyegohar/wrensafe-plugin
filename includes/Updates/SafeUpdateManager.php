<?php
/**
 * Runs Safe Updates: updates with a restore point and a site check around
 * each one.
 *
 * What is updated is a list of items, each of an update type (UpdateType):
 * plugins are built in, and an add-on can register others through the
 * wpbp_safe_update_types filter. The engine itself only works through the
 * type of each item.
 *
 * A run is a job (SafeUpdateJob) worked through in short steps, driven by
 * the update window or the Safe Updates page (one AJAX request every few
 * seconds) or, when no browser is open, by WP-Cron (SafeUpdates). Each
 * step does one piece of work and saves where it got to:
 *
 *   backup    a database or full backup first, when "Back up before
 *             updates" covers updates started by hand
 *   baseline  load the site once before anything changes (safe modes)
 *   items     one by one: for each item, save a copy, update it, check
 *             the site, and put the copy back if the site got worse
 *   points    all at once: save a copy of every item first
 *   update    all at once: update the items, a few per request
 *   check     all at once: check the site; roll back the item a fatal
 *             error names, or every updated item when none is named,
 *             and check again
 *   finish    clear caches, remove the guard, report
 *
 * The update itself is WordPress's own (the type's upgrader, like the
 * Update now link). The check and the rollback run in the same request as
 * the update, so a broken update is on the live site only for the seconds
 * the check takes. If that request is cut off, the boot guard keeps the
 * item out of Wrensafe's next request, which checks the site and rolls
 * back.
 *
 * @package WPBP\Updates
 */

namespace WPBP\Updates;

use WPBP\Backup\BackupJob;
use WPBP\Backup\BackupManager;
use WPBP\Core\Database\JobsRepository;
use WPBP\Restore\RestoreManager;
use WPBP\Utils\Errors;
use WPBP\Utils\Logger;

defined( 'ABSPATH' ) || exit;

// Add-ons can add their own checks: wpbp_safe_update_baseline (before an
// update) and wpbp_safe_update_check (after it) may answer 'pending' to be
// asked again on the next step, so a check that takes a while (screenshots,
// for example) never holds a request open.
//
// An update type whose items aren't one folder implements OwnRestorePoint
// and saves its own copy; putting such a copy back may take several steps
// (the item stays "rolling back" in between). wpbp_safe_update_run lets an
// add-on adjust a run before it starts (the order of its items, a backup
// its rollback needs).

// The run's lock is an options row written with INSERT IGNORE, so two
// requests can never both get it (add_option() would overwrite, and the
// options API caches the row, so a request could miss another's lock).
// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

class SafeUpdateManager {

	const ORDER_ONE = 'one_by_one';
	const ORDER_ALL = 'all_at_once';

	/** Most items in one run. */
	const MAX_ITEMS = 100;

	/** @deprecated 1.2.0 Use MAX_ITEMS. */
	const MAX_PLUGINS = self::MAX_ITEMS;

	/** Items updated together in one request when updating all at once. */
	const GROUP_SIZE = 5;

	const LOCK_OPTION = 'wpbp_safe_update_lock';

	/** Seconds a step may hold the lock before another request may take over. */
	const LOCK_SECONDS = 330;

	const CANCEL_TRANSIENT = 'wpbp_su_cancel_';

	/** How long a 'pending' answer may hold a run by default (seconds). */
	const PENDING_SECONDS = 600;

	/** Item states that end an item's part in the run. */
	const DONE_STATES = array( 'updated', 'rolled_back', 'failed', 'skipped', 'cancelled' );

	/** Seconds one step of a rollback may take (an own copy may need several steps). */
	const ROLLBACK_SECONDS = 120;

	/** Error code an update type's upgrade() returns when the update stopped part-way (see UpdateType::upgrade()). */
	const INCOMPLETE = 'wpbp_update_incomplete';

	/** Whether this request is inside a Safe Updates update right now. */
	private static bool $updating = false;

	/** When the work of this request should stop (microtime), 0 when none was set. */
	private static float $deadline = 0.0;

	/**
	 * WP-Cron: seconds a step may run past its budget so it doesn't end with
	 * updates installed but not checked ("all at once" updates in groups and
	 * checks after). Visitors would otherwise run the unchecked plugins until
	 * the next cron run, which on a host with a system cron may be minutes
	 * away. PHP is given 300 seconds per step (prepare()).
	 */
	private const FINISH_CHECK_SECONDS = 150;

	/**
	 * Steps in a row that may be cut off (a fatal error, PHP's time limit,
	 * the host ending the request) with the run still at the same point.
	 * The next one stops the run safely instead of starting it again.
	 */
	private const MAX_CUT_OFF_STEPS = 3;

	/** Where the last step started: run, position and how many times in a row. */
	const CUT_OFF_OPTION = 'wpbp_safe_update_cut_offs';

	/** The lock value this request holds ('' when none). */
	private static string $lock = '';

	private static bool $shutdown_registered = false;

	/** Another request took over this request's lock: nothing more is saved. */
	private static bool $lock_lost = false;

	/** Exception code for a save refused after the lock was taken over. */
	private const LOCK_LOST = 4091;

	/** Upgrader filters that renew the lock while a step installs a package. */
	private const KEEP_LOCK_FILTERS = array( 'upgrader_pre_download', 'upgrader_source_selection', 'upgrader_clear_destination', 'upgrader_post_install' );

	public static function is_running_here(): bool {
		return self::$updating;
	}

	// ---------------------------------------------------------------------
	// What can be updated.
	// ---------------------------------------------------------------------

	/**
	 * The items of one type WordPress has an update for, by id, sorted by
	 * name. 'problem' explains why one can't be updated here ('' when it can).
	 *
	 * @param string $type Update type key (plugins by default).
	 * @return array<string,array<string,mixed>>
	 */
	public static function available( string $type = PluginUpdateType::KEY ): array {
		$handler = UpdateTypes::get( $type );

		if ( null === $handler ) {
			return array();
		}

		$list = array();

		foreach ( $handler->available() as $id => $item ) {
			if ( ! is_array( $item ) || empty( $item['name'] ) ) {
				continue;
			}

			$id          = (string) $id;
			$list[ $id ] = array_merge(
				array(
					'tested'  => '',
					'problem' => '',
					'active'  => false,
				),
				$item,
				array(
					'id'   => $id,
					'type' => $handler->key(),
					'name' => wp_strip_all_tags( (string) $item['name'] ),
					'from' => (string) ( $item['from'] ?? '' ),
					'to'   => (string) ( $item['to'] ?? '' ),
				)
			);
		}

		uasort(
			$list,
			static function ( array $a, array $b ): int {
				return strcasecmp( $a['name'], $b['name'] );
			}
		);

		return $list;
	}

	/**
	 * Every type's available updates.
	 *
	 * @return array<string,array<string,array<string,mixed>>> Type key => id => item.
	 */
	public static function available_by_type(): array {
		$all = array();

		foreach ( array_keys( UpdateTypes::all() ) as $key ) {
			$all[ $key ] = self::available( $key );
		}

		return $all;
	}

	/**
	 * Plugins never updated by Safe Updates (see PluginUpdateType::excluded()).
	 *
	 * @return string[]
	 */
	public static function excluded(): array {
		return PluginUpdateType::excluded();
	}

	// ---------------------------------------------------------------------
	// Starting, continuing and stopping a run.
	// ---------------------------------------------------------------------

	/** The run in progress, if any. */
	public static function running_job(): ?SafeUpdateJob {
		foreach ( JobsRepository::list_by_type( SafeUpdateJob::TYPE, 5 ) as $row ) {
			if ( ! in_array( (string) $row['status'], array( 'pending', 'running', 'paused' ), true ) ) {
				continue;
			}

			$job = SafeUpdateJob::get( (int) $row['id'] );

			if ( null === $job ) {
				continue;
			}

			// Nothing continued it for six hours (WP-Cron doesn't run on this site).
			if ( SafeUpdateJob::STATUS_PAUSED !== $job->status() && self::seconds_since_activity( $job ) > 6 * HOUR_IN_SECONDS ) {
				self::finish( $job, self::normalize( $job->payload() ), __( 'The run stopped: nothing continued it for six hours. Items not updated yet were left as they were.', 'wrensafe' ) );
				continue;
			}

			return $job;
		}

		return null;
	}

	public static function seconds_since_activity( SafeUpdateJob $job ): int {
		$row = JobsRepository::find( $job->id() );

		if ( null === $row ) {
			return PHP_INT_MAX;
		}

		return max( 0, (int) ( strtotime( current_time( 'mysql' ) ) - strtotime( (string) $row['updated_at'] ) ) );
	}

	/**
	 * Start a run.
	 *
	 * @param array<int,string|array{type?:string,id:string}> $items Plugin basenames, or
	 *                                                               array( 'type' => …, 'id' => … ) for any type.
	 * @return SafeUpdateJob|\WP_Error
	 */
	public static function start( array $items, string $mode, string $order, bool $clear_cache, string $source ) {
		$blocked = SafeUpdates::blocked_reason();

		if ( '' === $blocked ) {
			// The file system check connects (FTP on some hosts): only when a run starts.
			$blocked = SafeUpdates::filesystem_reason();
		}

		if ( '' !== $blocked ) {
			return new \WP_Error( 'wpbp_blocked', $blocked );
		}

		if ( null !== self::running_job() ) {
			return new \WP_Error( 'wpbp_busy', __( 'Updates are already running. Wait for that run to finish; you can follow it on the Safe Updates page.', 'wrensafe' ) );
		}

		if ( JobsRepository::count_active() > 0 ) {
			return new \WP_Error( 'wpbp_busy', __( 'A backup, restore or transfer is running. Start the updates when it has finished.', 'wrensafe' ) );
		}

		$modes     = SafeUpdates::modes();
		$mode      = isset( $modes[ $mode ] ) ? $mode : SafeUpdates::default_mode();
		$order     = self::ORDER_ALL === $order ? self::ORDER_ALL : self::ORDER_ONE;
		$available = array();
		$list      = array();
		$seen      = array();

		foreach ( $items as $wanted ) {
			$key = is_array( $wanted ) ? sanitize_key( (string) ( $wanted['type'] ?? '' ) ) : PluginUpdateType::KEY;
			$key = '' !== $key ? $key : PluginUpdateType::KEY;
			$id  = (string) ( is_array( $wanted ) ? ( $wanted['id'] ?? '' ) : $wanted );

			if ( '' === $id || isset( $seen[ $key . ':' . $id ] ) || count( $list ) >= self::MAX_ITEMS || null === UpdateTypes::get( $key ) ) {
				continue;
			}

			$seen[ $key . ':' . $id ] = true;

			if ( ! isset( $available[ $key ] ) ) {
				$available[ $key ] = self::available( $key );
			}

			if ( ! isset( $available[ $key ][ $id ] ) || '' !== $available[ $key ][ $id ]['problem'] ) {
				continue;
			}

			$found = $available[ $key ][ $id ];
			$item  = array(
				'type'     => $key,
				'id'       => $id,
				'name'     => $found['name'],
				'from'     => $found['from'],
				'to'       => $found['to'],
				'active'   => ! empty( $found['active'] ),
				'version'  => $found['from'],
				'state'    => 'waiting',
				'message'  => '',
				'point'    => array(),
				'attempts' => 0,
				'details'  => array(),
			);

			if ( PluginUpdateType::KEY === $key ) {
				// Readers of 1.2.0 plugin runs know the item by 'plugin'.
				$item['plugin'] = $id;
			}

			$list[] = $item;
		}

		if ( empty( $list ) ) {
			return new \WP_Error( 'wpbp_nothing', __( 'None of the selected updates can be installed here. Reload the page to see the current list.', 'wrensafe' ) );
		}

		$before = (array) get_option( 'wpbp_backup_before_updates', array() );
		$backup = empty( $before['manual_updates'] ) ? 'none' : ( 'full' === ( $before['backup_type'] ?? '' ) ? 'full' : 'database' );
		$safe   = ! empty( $modes[ $mode ]['safe'] );

		/**
		 * Filters a Safe Updates run before it starts. An update type that
		 * needs something of the run changes it here: its item updated first
		 * or one by one, or a backup its rollback needs.
		 *
		 * The items can be put in another order or left out (not added); the
		 * order can be changed; the backup can be made stronger ('none' <
		 * 'database' < 'full'), never weaker. Return a WP_Error to refuse the
		 * run; its message is shown to the administrator.
		 *
		 * @param array $run {
		 *     @type array[] $items  The items, in the order they will be updated ('type', 'id', 'name', 'from', 'to', …).
		 *     @type string  $order  'one_by_one' or 'all_at_once'.
		 *     @type string  $backup The backup made before the first update: 'none', 'database' or 'full'
		 *                           (from Settings → Updates → Back up before updates).
		 *     @type string  $mode   The update mode (see SafeUpdates::modes()).
		 *     @type bool    $safe   Whether the mode saves copies and checks the site.
		 *     @type string  $source Where the run was started ('page', 'plugins', 'schedule', …).
		 * }
		 */
		$run = apply_filters(
			'wpbp_safe_update_run',
			array(
				'items'  => $list,
				'order'  => $order,
				'backup' => $backup,
				'mode'   => $mode,
				'safe'   => $safe,
				'source' => sanitize_key( $source ),
			)
		);

		if ( is_wp_error( $run ) ) {
			return $run;
		}

		$run    = is_array( $run ) ? $run : array();
		$list   = self::known_items( $list, is_array( $run['items'] ?? null ) ? $run['items'] : $list );
		$order  = in_array( $run['order'] ?? '', array( self::ORDER_ONE, self::ORDER_ALL ), true ) ? (string) $run['order'] : $order;
		$backup = self::stronger_backup( $backup, (string) ( $run['backup'] ?? $backup ) );

		if ( empty( $list ) ) {
			return new \WP_Error( 'wpbp_nothing', __( 'None of the selected updates can be installed here. Reload the page to see the current list.', 'wrensafe' ) );
		}

		$job = SafeUpdateJob::create(
			array(
				'live'        => LiveProgress::new_token(),
				'mode'        => $mode,
				'safe'        => $safe,
				'order'       => $order,
				'clear_cache' => $clear_cache,
				'source'      => sanitize_key( $source ),
				'backup'      => $backup,
				'backup_job'  => 0,
				'backup_id'   => 0,
				'phase'       => 'backup',
				'items'       => $list,
				'cursor'      => 0,
				'rounds'      => 0,
				'baseline'    => array(),
				'unverified'  => false,
				'cleared'     => array(),
				'user_id'     => get_current_user_id(),
			)
		);

		$job->mark_running();
		$job->update_progress( 1, __( 'Starting…', 'wrensafe' ) );

		Logger::info(
			sprintf(
				'Safe Updates run #%1$d started by user #%2$d (%3$s, %4$s): %5$s.',
				$job->id(),
				get_current_user_id(),
				$mode,
				$order,
				implode( ', ', array_map( array( self::class, 'item_label' ), $list ) )
			),
			array(),
			$job->id()
		);

		if ( $safe ) {
			SafeUpdates::install_guard( $job->id() );
			SafeUpdates::open_guard();
		}

		SafeUpdates::schedule_continue( $job->id() );

		/**
		 * Fires when a Safe Updates run starts.
		 *
		 * @param SafeUpdateJob $job
		 */
		do_action( 'wpbp_safe_update_started', $job );

		return $job;
	}

	/**
	 * Work on the run for up to $seconds (one step at least).
	 *
	 * @param bool $from_browser The request comes from the administrator's browser
	 *                           (their login is used for the Plugins screen check).
	 * @return bool False when nothing ran: the run is over or waiting for a
	 *              decision, or another request holds its lock.
	 */
	public static function process( SafeUpdateJob $job, bool $from_browser, int $seconds = 20 ): bool {
		if ( $job->is_finished() || SafeUpdateJob::STATUS_PAUSED === $job->status() || ! self::lock() ) {
			return false;
		}

		try {
			self::prepare();

			// A step that dies at the same point every time would otherwise be
			// started again forever, running the site check (and an add-on's
			// paid screenshots) each time. The safety stop then puts the updates
			// back; if that is cut off too, the run ends without touching files,
			// and if even that is cut off, the run is marked failed.
			$starts = self::note_step_start( $job );

			if ( $starts > self::MAX_CUT_OFF_STEPS + 2 ) {
				// Marked failed first, so nothing after this can start the run again.
				Logger::critical( 'Safe Updates run #' . $job->id() . ' marked failed: its steps were cut off at the same point again and again, also while it was stopping.', array(), $job->id() );
				$job->mark_failed( __( 'The run stopped: its steps were cut off at the same point again and again. Check the updates on the Plugins, Themes and Updates screens.', 'wrensafe' ) );
				delete_option( self::CUT_OFF_OPTION );

				$p           = self::without_rollback( $job );
				$interrupted = false;
				$own_restore = array();

				foreach ( $p['items'] as $i => $item ) {
					if ( in_array( $item['state'], self::DONE_STATES, true ) ) {
						continue;
					}

					if ( 'rolling_back' === $item['state'] && ! empty( $item['point']['restore'] ) ) {
						$interrupted   = true;
						$own_restore[] = (int) $item['point']['restore'];
					}

					// No file work here: an own copy part-way back stays as it is.
					$before                      = in_array( $item['state'], array( 'waiting', 'saving', 'ready' ), true );
					$p['items'][ $i ]['state']   = $before ? 'skipped' : 'failed';
					$p['items'][ $i ]['message'] = $before ? __( 'Not updated: the run stopped.', 'wrensafe' ) : ( 'rolling_back' === $item['state']
						? __( 'The run stopped while the previous version was being put back. Check this update on the Plugins, Themes or Updates screen.', 'wrensafe' )
						: __( 'The run stopped before this update could be checked. Check it on the Plugins, Themes or Updates screen.', 'wrensafe' ) );
				}

				$job->set_payload( $p );
				SafeUpdates::remove_guard();

				// An own copy's restore left part-way (WordPress core in Wrensafe Pro): its
				// lock and maintenance page are cleared, as settling it would. Nothing is restored.
				// That clean-up is site-wide, so it waits while another restore is under way.
				if ( $interrupted && self::other_restore_active( $own_restore ) ) {
					Logger::warning( 'Safe Updates run #' . $job->id() . ': another restore is under way, so the interrupted restore’s lock and maintenance page were left for it (clear them under Rollback if they stay).', array(), $job->id() );
				} elseif ( $interrupted ) {
					try {
						RestoreManager::clear_restore_locks_and_maintenance();
					} catch ( \Throwable $e ) {
						Logger::warning( 'Safe Updates run #' . $job->id() . ': the restore lock couldn’t be cleared: ' . Errors::text( $e ), array(), $job->id() );
					}
				}

				/** This action is documented in finish(). */
				do_action( 'wpbp_safe_update_finished', $job, self::summary( $p ) );

				return true;
			}

			if ( $starts > self::MAX_CUT_OFF_STEPS + 1 ) {
				$error = __( 'its steps were cut off at the same point again and again, also while putting updates back', 'wrensafe' );

				Logger::error( 'Safe Updates run #' . $job->id() . ' stopped: ' . $error, array(), $job->id() );
				self::finish( $job, self::without_rollback( $job ), $error );
				delete_option( self::CUT_OFF_OPTION );

				return true;
			}

			if ( $starts > self::MAX_CUT_OFF_STEPS ) {
				throw new \RuntimeException( esc_html__( 'its steps were cut off at the same point three times in a row: PHP’s time or memory limit, or the host ending the request', 'wrensafe' ) );
			}

			$deadline       = microtime( true ) + max( 5, $seconds );
			$steps          = 0;
			self::$deadline = $deadline;

			// The lock is kept while a step waits for a download or a site check,
			// and between the stages of an install (after the unzip, before and
			// after the copy), so one large package doesn't outlast it.
			foreach ( self::KEEP_LOCK_FILTERS as $hook ) {
				add_filter( $hook, array( self::class, 'keep_lock_filter' ), 1 );
			}
			add_action( 'http_api_debug', array( self::class, 'keep_lock' ), 10, 0 );

			SafeUpdates::touch_guard();

			do {
				$more = self::step( $job, $from_browser, $deadline );
				++$steps;
				SafeUpdates::touch_guard();

				// Lost here or during the step (keep_lock()): the lock is then no longer this request's.
				if ( ! self::renew_lock() || '' === self::$lock ) {
					// Another request took over a lock this step outlived: it carries on with the run.
					Logger::warning( 'Safe Updates run #' . $job->id() . ': a step took longer than its lock; another request continues the run.', array(), $job->id() );
					break;
				}

				// WP-Cron: an add-on's check isn't done yet; wait here rather than for the next visit.
				if ( ! $more && ! $from_browser && ! $job->is_finished() && ! empty( $job->payload()['wait'] ) && microtime( true ) < $deadline - 8 ) {
					sleep( 3 );
					$more = true;
				}
			} while (
				$more && ! $job->is_finished() && SafeUpdateJob::STATUS_PAUSED !== $job->status() && $steps < 500
				&& ( microtime( true ) < $deadline - 3 || ( ! $from_browser && microtime( true ) < $deadline + self::FINISH_CHECK_SECONDS && self::has_unchecked( $job ) ) )
			);
		} catch ( \Throwable $e ) {
			// A takeover no save() has noticed yet: the run is the other request's now.
			if ( ! self::$lock_lost && '' !== self::$lock && ! self::holds_lock() ) {
				self::$lock      = '';
				self::$lock_lost = true;
			}

			if ( self::$lock_lost ) {
				// The run belongs to the request that took the lock over: leave it to that one.
				Logger::warning( 'Safe Updates run #' . $job->id() . ': a step took longer than its lock; another request continues the run.', array(), $job->id() );
			} else {
				$error = Errors::text( $e );

				Logger::error( 'Safe Updates run #' . $job->id() . ' stopped: ' . $error, array(), $job->id() );
				self::finish( $job, self::safety_stop( $job, $error ), $error );
			}
		} finally {
			foreach ( self::KEEP_LOCK_FILTERS as $hook ) {
				remove_filter( $hook, array( self::class, 'keep_lock_filter' ), 1 );
			}
			remove_action( 'http_api_debug', array( self::class, 'keep_lock' ), 10 );
			self::unlock();
		}

		// Another request took the lock over: it carries on with the run and
		// counts its own steps, so this one leaves the count and the next step to it.
		if ( self::$lock_lost ) {
			return false;
		}

		// The step returned: the next one starts counting again.
		delete_option( self::CUT_OFF_OPTION );

		if ( ! $job->is_finished() && SafeUpdateJob::STATUS_PAUSED !== $job->status() ) {
			SafeUpdates::schedule_continue( $job->id() );
		}

		return true;
	}

	/**
	 * Stop the run after the item being updated now. Kept outside the
	 * payload, which the running step rewrites.
	 */
	public static function request_cancel( SafeUpdateJob $job ): void {
		set_transient( self::CANCEL_TRANSIENT . $job->id(), 1, DAY_IN_SECONDS );
		Logger::info( 'Safe Updates run #' . $job->id() . ': stop requested by user #' . get_current_user_id() . '.', array(), $job->id() );
	}

	private static function cancel_requested( SafeUpdateJob $job ): bool {
		return (bool) get_transient( self::CANCEL_TRANSIENT . $job->id() );
	}

	/**
	 * The administrator's answer for an update an add-on held back for a
	 * decision ('keep' or 'rollback').
	 *
	 * @param string $id   The item's id (a plugin basename for plugins).
	 * @param string $type The item's update type.
	 * @return true|\WP_Error
	 */
	public static function decide( SafeUpdateJob $job, string $id, string $decision, string $type = PluginUpdateType::KEY ) {
		if ( SafeUpdateJob::STATUS_PAUSED !== $job->status() || ! self::lock() ) {
			return new \WP_Error( 'wpbp_not_waiting', __( 'This run isn’t waiting for a decision.', 'wrensafe' ) );
		}

		try {
			self::prepare();

			self::$deadline = microtime( true ) + 15;

			$p     = self::normalize( $job->payload() );
			$found = false;

			foreach ( $p['items'] as $i => $item ) {
				if ( ! self::is_item( $item, $type, $id ) || 'awaiting' !== $item['state'] ) {
					continue;
				}

				$found = true;

				if ( 'rollback' === $decision ) {
					$p = self::roll_back( $job, $p, array( $i ), __( 'You chose to roll back this update.', 'wrensafe' ), true );
				} else {
					$p['items'][ $i ]['state']   = 'updated';
					$p['items'][ $i ]['message'] = __( 'You chose to keep this update.', 'wrensafe' );
				}
			}

			if ( ! $found ) {
				return new \WP_Error( 'wpbp_not_waiting', __( 'This update isn’t waiting for a decision.', 'wrensafe' ) );
			}

			$waiting = self::indexes( $p, array( 'awaiting' ) );

			self::save( $job, $p );

			if ( empty( $waiting ) ) {
				$job->resume();
				SafeUpdates::schedule_continue( $job->id(), 1 );
			}
		} catch ( \RuntimeException $e ) {
			if ( self::LOCK_LOST !== $e->getCode() ) {
				throw $e;
			}

			// A rollback that outlasted the lock: another request has the run now.
			return new \WP_Error( 'wpbp_busy', __( 'Another request took over this run while your choice was being applied. Reload the page to see where it is.', 'wrensafe' ) );
		} finally {
			self::unlock();
		}

		return true;
	}

	/**
	 * Roll back an item updated in a finished run, from the copy saved
	 * before its update.
	 *
	 * @param string $id   The item's id (a plugin basename for plugins).
	 * @param string $type The item's update type.
	 * @return true|\WP_Error
	 */
	public static function roll_back_later( SafeUpdateJob $job, string $id, string $type = PluginUpdateType::KEY ) {
		if ( ! $job->is_finished() ) {
			return new \WP_Error( 'wpbp_running', __( 'Wait for the run to finish.', 'wrensafe' ) );
		}

		if ( null !== self::running_job() || ! self::lock() ) {
			return new \WP_Error( 'wpbp_busy', __( 'Updates are running right now. Try again when they have finished.', 'wrensafe' ) );
		}

		try {
			self::prepare();

			$p = self::normalize( $job->payload() );

			foreach ( $p['items'] as $i => $item ) {
				if ( ! self::is_item( $item, $type, $id ) ) {
					continue;
				}

				$handler = UpdateTypes::get( $type );

				if ( null === $handler || ! self::can_roll_back( $item ) ) {
					return new \WP_Error( 'wpbp_no_rollback', __( 'This update can’t be rolled back any more: the saved copy was deleted, or the files have changed since.', 'wrensafe' ) );
				}

				$reason = SafeUpdates::filesystem_reason();

				if ( '' !== $reason ) {
					return new \WP_Error( 'wpbp_no_filesystem', $reason );
				}

				// No run is going on, so this request finishes the rollback: an
				// own copy gets its steps here, and is settled if they don't end.
				$limit    = microtime( true ) + 2 * self::ROLLBACK_SECONDS;
				$copy     = $item;
				$restored = self::restore_copy( $job, $copy, microtime( true ) + self::ROLLBACK_SECONDS );

				while ( false === $restored && microtime( true ) < $limit ) {
					$restored = self::restore_copy( $job, $copy, min( $limit, microtime( true ) + self::ROLLBACK_SECONDS ) );
				}

				if ( false === $restored ) {
					$restored = self::settle_copy( $job, $copy );
				}

				if ( true !== $restored ) {
					// What the copy's state says now (an own copy may have been settled).
					$p['items'][ $i ]['point'] = $copy['point'];
					$job->set_payload( $p );

					return is_wp_error( $restored ) ? $restored : new \WP_Error( 'wpbp_rollback_failed', __( 'The saved copy couldn’t be put back.', 'wrensafe' ) );
				}

				$item['point'] = $copy['point'];

				$handler->keep_after_rollback( $item );

				$user = wp_get_current_user();

				$p['items'][ $i ]['state']   = 'rolled_back';
				$p['items'][ $i ]['version'] = $handler->installed_version( $id );
				$p['items'][ $i ]['message'] = sprintf(
					/* translators: 1: version number, 2: user name, 3: date and time */
					__( 'Rolled back to %1$s by %2$s on %3$s.', 'wrensafe' ),
					$item['from'],
					$user->display_name,
					wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ) )
				);

				self::delete_copy( $item );
				$p['items'][ $i ]['point'] = array();

				$job->set_payload( $p );
				// WordPress offers the update again after its next check.
				$handler->forget_update( array() );

				if ( PluginUpdateType::KEY === $type ) {
					wp_clean_plugins_cache( true );
				}

				Logger::warning( 'Safe Updates: ' . self::item_label( $item ) . ' rolled back to ' . $item['from'] . ' by user #' . get_current_user_id() . ' (run #' . $job->id() . ').', array(), $job->id() );

				/** This action is documented in includes/Updates/SafeUpdateManager.php */
				do_action( 'wpbp_safe_update_rolled_back', $job, array( $id ), $p['items'][ $i ]['message'], array( $p['items'][ $i ] ) );

				return true;
			}

			return new \WP_Error( 'wpbp_not_found', __( 'This update isn’t part of that run.', 'wrensafe' ) );
		} finally {
			self::unlock();
		}
	}

	// ---------------------------------------------------------------------
	// The steps.
	// ---------------------------------------------------------------------

	/** Do the next piece of work. True when more may follow in this request. */
	private static function step( SafeUpdateJob $job, bool $from_browser, float $deadline ): bool {
		$p = self::normalize( $job->payload() );
		$p = self::drop_unknown_types( $job, $p );

		switch ( (string) ( $p['phase'] ?? 'finish' ) ) {
			case 'backup':
				return self::phase_backup( $job, $p, $deadline );

			case 'baseline':
				if ( ! self::waiting( $p, 'baseline' ) ) {
					$p['baseline'] = UpdateHealthCheck::run( $from_browser );

					if ( ! UpdateHealthCheck::any_reachable( $p['baseline'] ) ) {
						$p['unverified'] = true;
						Logger::warning( 'Safe Updates run #' . $job->id() . ': this server can’t load its own pages (loopback requests fail), so the site can’t be checked after the updates. Copies are still saved, so each update can be rolled back from the Safe Updates page.', array(), $job->id() );
					}
				}

				// All at once: add-ons look at the site once, before anything changes.
				if ( self::ORDER_ALL === $p['order'] && ! self::before_update( $job, $p, self::indexes( $p, array( 'waiting' ) ) ) ) {
					self::save( $job, $p, self::wait_step( $p ) );
					return false;
				}

				$p['phase'] = self::ORDER_ALL === $p['order'] ? 'points' : 'items';
				self::save( $job, $p );
				return true;

			case 'items':
				return self::phase_items( $job, $p, $from_browser, $deadline );

			case 'points':
				return self::phase_points( $job, $p, $deadline );

			case 'update':
				return self::phase_update_group( $job, $p );

			case 'check':
				return self::phase_check_all( $job, $p, $from_browser );

			default:
				self::finish( $job, $p );
				return false;
		}
	}

	private static function phase_backup( SafeUpdateJob $job, array $p, float $deadline ): bool {
		if ( 'none' === $p['backup'] ) {
			$p['phase'] = self::after_backup_phase( $p );
			self::save( $job, $p );
			return true;
		}

		$backup = $p['backup_job'] ? BackupJob::get( (int) $p['backup_job'] ) : null;

		if ( null === $backup ) {
			$names  = wp_list_pluck( $p['items'], 'name' );
			$backup = BackupJob::create(
				array(
					'backup_type' => $p['backup'],
					'note'        => sprintf(
						/* translators: %s: names of the plugins (or themes) being updated */
						__( 'Before Safe Updates: %s', 'wrensafe' ),
						wp_html_excerpt( implode( ', ', $names ), 180, '…' )
					),
					'tag'         => 'pre_update',
				)
			);

			$p['backup_job'] = $backup->id();
			self::save( $job, $p, __( 'Backing up the site before the updates…', 'wrensafe' ) );
		}

		$manager = new BackupManager();
		$done    = array( BackupJob::STATUS_COMPLETED, BackupJob::STATUS_FAILED, BackupJob::STATUS_CANCELLED );

		while ( ! in_array( $backup->status(), $done, true ) && microtime( true ) < $deadline ) {
			$manager->process_step( $backup );
		}

		if ( ! in_array( $backup->status(), $done, true ) ) {
			self::save(
				$job,
				$p,
				sprintf(
					/* translators: %d: percent */
					__( 'Backing up the site before the updates (%d%%)…', 'wrensafe' ),
					$backup->progress()
				)
			);
			return false;
		}

		if ( BackupJob::STATUS_COMPLETED !== $backup->status() ) {
			foreach ( $p['items'] as $i => $item ) {
				$p['items'][ $i ]['state']   = 'skipped';
				$p['items'][ $i ]['message'] = __( 'Not updated: the backup before the updates failed.', 'wrensafe' );
			}

			$reason = '' !== $backup->error_message() ? $backup->error_message() : __( 'see the Activity Logs', 'wrensafe' );

			/* translators: %s: why the backup failed */
			self::finish( $job, $p, sprintf( __( 'The backup before the updates failed, so nothing was updated: %s', 'wrensafe' ), $reason ) );
			return false;
		}

		$p['backup_id'] = (int) ( $backup->result()['backup_id'] ?? 0 );
		$p['phase']     = self::after_backup_phase( $p );
		self::save( $job, $p );

		return true;
	}

	private static function after_backup_phase( array $p ): string {
		if ( $p['safe'] ) {
			return 'baseline';
		}

		return self::ORDER_ALL === $p['order'] ? 'update' : 'items';
	}

	/** One by one: the item at the cursor. */
	private static function phase_items( SafeUpdateJob $job, array $p, bool $from_browser, float $deadline ): bool {
		$i = (int) $p['cursor'];

		if ( ! isset( $p['items'][ $i ] ) ) {
			$p['phase'] = 'finish';
			self::save( $job, $p );
			return true;
		}

		$state = (string) $p['items'][ $i ]['state'];

		if ( in_array( $state, self::DONE_STATES, true ) ) {
			$p['cursor'] = $i + 1;
			self::save( $job, $p );
			return true;
		}

		if ( in_array( $state, array( 'waiting', 'saving', 'ready' ), true ) && self::cancel_requested( $job ) ) {
			$p          = self::cancel_remaining( $p );
			$p['phase'] = 'finish';
			self::save( $job, $p );
			return true;
		}

		switch ( $state ) {
			case 'waiting':
			case 'saving':
				$p = self::save_copy( $job, $p, $i, $deadline );
				return 'ready' === $p['items'][ $i ]['state'] || 'skipped' === $p['items'][ $i ]['state'];

			case 'ready':
				// Add-ons look at the site before this item changes it.
				if ( ! self::before_update( $job, $p, array( $i ) ) ) {
					self::save( $job, $p, self::wait_step( $p ) );
					return false;
				}

				$p = self::update_items( $job, $p, array( $i ) );
				$p = self::check_items( $job, $p, array( $i ), $from_browser );
				self::save( $job, $p );
				return ! self::waiting( $p, 'check' ) && microtime( true ) < $deadline - 10;

			case 'checking':
				if ( self::waiting( $p, 'check' ) ) {
					// An add-on's check wasn't done yet: ask again.
					$p = self::check_items( $job, $p, array( $i ), $from_browser );
					self::save( $job, $p );
					return ! self::waiting( $p, 'check' );
				}

				// The request that checked this item was cut off.
				$p = self::recover( $job, $p, array( $i ) );
				$p = self::check_items( $job, $p, array( $i ), $from_browser );
				self::save( $job, $p );
				return true;

			case 'updating':
			case 'installed':
				// The request that updated this item was cut off.
				$p = self::recover( $job, $p, array( $i ) );
				$p = self::check_items( $job, $p, array( $i ), $from_browser );
				self::save( $job, $p );
				return true;

			case 'rolling_back':
				$p = self::roll_back( $job, $p, array( $i ), (string) $p['items'][ $i ]['message'], $from_browser );
				self::save( $job, $p );
				// A copy that needs another step gets it in the next request.
				return 'rolling_back' !== $p['items'][ $i ]['state'];

			default:
				// 'awaiting': the run is paused until the administrator decides.
				return false;
		}
	}

	/** All at once: save every copy first. */
	private static function phase_points( SafeUpdateJob $job, array $p, float $deadline ): bool {
		foreach ( $p['items'] as $i => $item ) {
			if ( ! in_array( $item['state'], array( 'waiting', 'saving' ), true ) ) {
				continue;
			}

			if ( self::cancel_requested( $job ) ) {
				$p          = self::cancel_remaining( $p );
				$p['phase'] = 'finish';
				self::save( $job, $p );
				return true;
			}

			$p = self::save_copy( $job, $p, $i, $deadline );

			if ( ! in_array( $p['items'][ $i ]['state'], array( 'ready', 'skipped' ), true ) ) {
				return false;
			}
		}

		$p['phase'] = 'update';
		self::save( $job, $p );

		return true;
	}

	/** All at once: update the next few items. */
	private static function phase_update_group( SafeUpdateJob $job, array $p ): bool {
		// A request cut off while updating: those items go to the check.
		$interrupted = self::indexes( $p, array( 'updating' ) );

		if ( $interrupted ) {
			$p = self::recover( $job, $p, $interrupted );
			self::save( $job, $p );
			return true;
		}

		$ready = self::indexes( $p, $p['safe'] ? array( 'ready' ) : array( 'waiting', 'ready' ) );

		if ( $ready && self::cancel_requested( $job ) ) {
			$p     = self::cancel_remaining( $p );
			$ready = array();
		}

		// Past the step's time (a background step finishing its checks), the
		// updates already installed are checked before the next group goes in:
		// a step that runs out of time then ends between a check and an
		// update, never with updates installed but not checked.
		if ( empty( $ready ) || ( self::indexes( $p, array( 'installed' ) ) && microtime( true ) >= self::deadline() - 3 ) ) {
			$p['phase'] = 'check';
			self::save( $job, $p );
			return true;
		}

		$p = self::update_items( $job, $p, array_slice( $ready, 0, self::GROUP_SIZE ) );
		self::save( $job, $p );

		return true;
	}

	/** All at once: check the site, roll back what broke it, check again. */
	private static function phase_check_all( SafeUpdateJob $job, array $p, bool $from_browser ): bool {
		if ( self::waiting( $p, 'check' ) ) {
			// An add-on's check wasn't done yet: ask again.
			$p = self::check_items( $job, $p, array_map( 'intval', (array) $p['wait']['items'] ), $from_browser );
			self::save( $job, $p );
			return ! self::waiting( $p, 'check' );
		}

		$interrupted = self::indexes( $p, array( 'updating', 'checking' ) );

		if ( $interrupted ) {
			$p = self::recover( $job, $p, $interrupted );
		}

		foreach ( self::indexes( $p, array( 'rolling_back' ) ) as $i ) {
			$p = self::roll_back( $job, $p, array( $i ), (string) $p['items'][ $i ]['message'], $from_browser );
		}

		// A copy that takes more than one step to put back: the next request goes on.
		if ( self::indexes( $p, array( 'rolling_back' ) ) ) {
			self::save( $job, $p, __( 'Putting the previous version back…', 'wrensafe' ) );
			return false;
		}

		$installed = self::indexes( $p, array( 'installed' ) );

		if ( empty( $installed ) ) {
			// Groups the update phase left for after this check (see phase_update_group()).
			$p['phase'] = self::indexes( $p, $p['safe'] ? array( 'ready' ) : array( 'waiting', 'ready' ) ) ? 'update' : 'finish';
			self::save( $job, $p );
			return true;
		}

		++$p['rounds'];

		// Every round rolls back at least one item or ends a group's check
		// (see phase_update_group()); this only guards against a loop.
		if ( $p['rounds'] > count( $p['items'] ) + 2 + (int) ceil( count( $p['items'] ) / self::GROUP_SIZE ) ) {
			$p = self::roll_back( $job, $p, $installed, __( 'The site check didn’t settle after several rollbacks.', 'wrensafe' ), $from_browser );
		} else {
			$p = self::check_items( $job, $p, $installed, $from_browser );
		}

		self::save( $job, $p );

		return ! self::waiting( $p, 'check' );
	}

	// ---------------------------------------------------------------------
	// The pieces of work.
	// ---------------------------------------------------------------------

	/** Save (or continue saving) the copy of item $i. */
	private static function save_copy( SafeUpdateJob $job, array $p, int $i, float $deadline ): array {
		$item = $p['items'][ $i ];

		if ( ! $p['safe'] ) {
			$p['items'][ $i ]['state'] = 'ready';
			return $p;
		}

		try {
			$own = self::own_point( $item );

			if ( 'waiting' === $item['state'] ) {
				if ( null !== $own ) {
					$item['point'] = $own->begin_point( $job->id(), $item );
				} else {
					$folder        = self::type_of( $item )->folder( UpdateTypes::id_of( $item ) );
					$item['point'] = RestorePoint::begin( $job->id(), (string) $folder['path'], (string) $item['from'], (string) ( $folder['main'] ?? '' ) );
				}

				$item['state'] = 'saving';
			}

			$point = (array) $item['point'];
			$built = null !== $own ? $own->build_point( $point, $deadline ) : RestorePoint::build( $point, $deadline );

			$item['point'] = $point;

			if ( $built ) {
				$item['state'] = 'ready';
			}
		} catch ( \Throwable $e ) {
			$error = Errors::text( $e );

			self::delete_copy( $item );

			$item['point']   = array();
			$item['state']   = 'skipped';
			$item['message'] = sprintf(
				/* translators: %s: error message */
				__( 'Not updated: a copy couldn’t be saved first (%s).', 'wrensafe' ),
				$error
			);

			Logger::warning( 'Safe Updates: ' . self::item_label( $item ) . ' skipped, no copy could be saved: ' . $error, array(), $job->id() );
		}

		$p['items'][ $i ] = $item;

		/* translators: %s: name of the plugin (or theme) */
		self::save( $job, $p, sprintf( __( 'Saving a copy of %s…', 'wrensafe' ), $item['name'] ) );

		return $p;
	}

	/**
	 * Update the items at $indexes with WordPress's own updater (each
	 * type's own), a type at a time.
	 *
	 * @param int[] $indexes
	 */
	private static function update_items( SafeUpdateJob $job, array $p, array $indexes ): array {
		foreach ( $indexes as $i ) {
			$p['items'][ $i ]['state'] = 'updating';
			++$p['items'][ $i ]['attempts'];
		}

		if ( $p['safe'] ) {
			self::guard( $p, array( 'updating', 'installed' ) );
		}

		self::save(
			$job,
			$p,
			sprintf(
				/* translators: %s: names of the plugins (or themes) */
				__( 'Updating %s…', 'wrensafe' ),
				implode( ', ', wp_list_pluck( array_intersect_key( $p['items'], array_flip( $indexes ) ), 'name' ) )
			)
		);

		$results = array();

		foreach ( self::by_type( $p, $indexes ) as $key => $group ) {
			$ids = self::ids_at( $p, $group );

			/**
			 * Fires just before Safe Updates installs updates.
			 *
			 * @param SafeUpdateJob $job
			 * @param string[]      $ids  The items' ids (plugin basenames for plugins).
			 * @param string        $type The items' update type ('plugin', or a type an add-on registered).
			 */
			do_action( 'wpbp_safe_update_before_update', $job, $ids, $key );

			self::$updating = true;
			BackupBeforeUpdates::pause( true );

			try {
				$done = UpdateTypes::get( $key )->upgrade( $ids );
			} finally {
				BackupBeforeUpdates::pause( false );
				self::$updating = false;
			}

			foreach ( $group as $i ) {
				$results[ $i ] = $done[ UpdateTypes::id_of( $p['items'][ $i ] ) ] ?? null;
			}
		}

		foreach ( $indexes as $i ) {
			$item    = $p['items'][ $i ];
			$version = self::type_of( $item )->installed_version( UpdateTypes::id_of( $item ) );
			$result  = $results[ $i ] ?? null;

			$p['items'][ $i ]['version'] = $version;

			if ( true === $result && '' !== $version && version_compare( $version, $item['from'], '>' ) ) {
				$p['items'][ $i ]['state'] = 'installed';
				Logger::info( 'Safe Updates: ' . self::item_label( $item ) . ' updated from ' . $item['from'] . ' to ' . $version . '.', array(), $job->id() );
				continue;
			}

			$cause  = is_wp_error( $result ) ? UpdateFailure::explain( $result ) : UpdateFailure::not_installed();
			$reason = $cause['text'];

			$p['items'][ $i ]['cause'] = $cause;

			// The update stopped part-way: the item's files may be a mix of both versions.
			$incomplete = is_wp_error( $result ) && self::INCOMPLETE === $result->get_error_code();

			if ( ( '' === $version || $incomplete ) && $p['safe'] && ! empty( $item['point']['done'] ) ) {
				// The update removed the item and didn't finish: put the copy back.
				$p['items'][ $i ]['state'] = 'rolling_back';
				/* translators: %s: error message */
				$p['items'][ $i ]['message'] = sprintf( __( 'The update failed part-way (%s).', 'wrensafe' ), $reason );
				continue;
			}

			$p['items'][ $i ]['state'] = 'failed';
			/* translators: %s: error message */
			$p['items'][ $i ]['message'] = sprintf( __( 'The update didn’t install: %s', 'wrensafe' ), trim( $reason . ' ' . $cause['fix'] ) );

			if ( '' === $version ) {
				$p['items'][ $i ]['message'] .= ' ' . self::text( $item, 'missing' );
			}

			Logger::error( 'Safe Updates: ' . self::item_label( $item ) . ' was not updated: ' . trim( $reason . ' ' . $cause['detail'] ), array(), $job->id() );
		}

		// Items that didn't install aren't on the live site in a new version.
		if ( $p['safe'] ) {
			self::guard( $p, array( 'installed', 'rolling_back' ) );
		}

		return $p;
	}

	/**
	 * Before the site changes for these items: let add-ons look at it first.
	 * False while an add-on answers 'pending' (the next step asks again).
	 *
	 * @param int[] $indexes
	 */
	private static function before_update( SafeUpdateJob $job, array &$p, array $indexes ): bool {
		if ( empty( $p['safe'] ) || empty( $indexes ) ) {
			unset( $p['wait'] );
			return true;
		}

		$since = self::waiting( $p, 'baseline' ) ? (int) $p['wait']['since'] : time();

		/**
		 * Filters whether Safe Updates can go on and install these updates.
		 * Called in safe modes after the site was loaded once as it is now:
		 * before each item when updating one by one, and once for all items
		 * when updating all at once. An add-on can look at the site first
		 * (take screenshots, for example).
		 *
		 * Return null to go on. Return array( 'reason' => 'pending' ) while
		 * the add-on isn't done: the run waits and the filter is called again
		 * on the next step (every few seconds), until it returns something
		 * else or the wait reaches wpbp_safe_update_pending_timeout (10
		 * minutes by default); then the run goes on and says so in the
		 * items' messages. 'message' in the answer is shown as the run's
		 * current step, and 'details' is kept with the items (see
		 * wpbp_safe_update_item_report).
		 *
		 * @param array|null    $result   Null.
		 * @param SafeUpdateJob $job
		 * @param array[]       $items    The items about to be updated ('type', 'id', 'name', 'from', 'to', 'active', …).
		 * @param array         $baseline The site check's results now (see UpdateHealthCheck::run()).
		 * @param int           $since    When the run first asked about these items (Unix time).
		 */
		$result = apply_filters( 'wpbp_safe_update_baseline', null, $job, self::items_at( $p, $indexes ), (array) $p['baseline'], $since );

		self::keep_details( $p, $indexes, $result );

		if ( is_array( $result ) && 'pending' === ( $result['reason'] ?? '' ) ) {
			if ( time() - $since < self::pending_timeout( $job, 'baseline' ) ) {
				$p['wait'] = array(
					'what'    => 'baseline',
					'items'   => array_values( $indexes ),
					'since'   => $since,
					'message' => sanitize_text_field( (string) ( $result['message'] ?? '' ) ),
				);

				return false;
			}

			Logger::warning( 'Safe Updates run #' . $job->id() . ': an add-on’s look at the site before the update didn’t finish in time; updating anyway.', array(), $job->id() );

			foreach ( $indexes as $i ) {
				$p['items'][ $i ]['late'] = __( 'An add-on’s check before the update didn’t finish in time and was skipped.', 'wrensafe' );
			}
		}

		unset( $p['wait'] );

		return true;
	}

	/**
	 * Check the site after the items at $indexes were installed, and roll
	 * back what broke it.
	 *
	 * @param int[] $indexes
	 */
	private static function check_items( SafeUpdateJob $job, array $p, array $indexes, bool $from_browser ): array {
		$again = self::waiting( $p, 'check' );

		if ( ! $again ) {
			foreach ( $indexes as $i ) {
				if ( 'rolling_back' === $p['items'][ $i ]['state'] ) {
					$p = self::roll_back( $job, $p, array( $i ), (string) $p['items'][ $i ]['message'], $from_browser );
				}
			}
		}

		$installed = array_values(
			array_filter(
				$indexes,
				static function ( int $i ) use ( $p, $again ): bool {
					return ( $again ? 'checking' : 'installed' ) === $p['items'][ $i ]['state'];
				}
			)
		);

		if ( empty( $installed ) ) {
			unset( $p['wait'] );
			return $p;
		}

		if ( ! $p['safe'] || $p['unverified'] ) {
			foreach ( $installed as $i ) {
				$p['items'][ $i ]['state']   = 'updated';
				$p['items'][ $i ]['message'] = $p['safe'] ? __( 'Updated. Not checked: this server can’t load its own pages, so the site couldn’t be checked.', 'wrensafe' ) : '';
			}

			return $p;
		}

		if ( $again ) {
			// The site was checked already; only the add-on is asked again.
			$after   = (array) ( $p['wait']['after'] ?? array() );
			$problem = isset( $p['wait']['problem'] ) && is_array( $p['wait']['problem'] ) ? $p['wait']['problem'] : null;
			$since   = (int) $p['wait']['since'];
		} else {
			foreach ( $installed as $i ) {
				$p['items'][ $i ]['state'] = 'checking';
			}

			self::save( $job, $p, __( 'Checking the site…', 'wrensafe' ) );

			self::before_check( $p, self::changed( $p ) );

			$after   = UpdateHealthCheck::run( $from_browser );
			$problem = UpdateHealthCheck::compare( $p['baseline'], $after );

			// A page can fail once while caches warm up; a fatal error is definite. A page that only took
			// too long gets a longer second try: on a slow site, one slow answer isn't a broken site.
			if ( null !== $problem && 'fatal' !== $problem['reason'] ) {
				sleep( 3 );
				$after   = UpdateHealthCheck::timed_out( $after ) ? UpdateHealthCheck::retry_timed_out( $after, $from_browser, $p['baseline'] ) : UpdateHealthCheck::run( $from_browser );
				$problem = UpdateHealthCheck::compare( $p['baseline'], $after );
			}

			$since = time();
		}

		/**
		 * Filters the result of the site check after Safe Updates installed
		 * updates.
		 *
		 * Return null (or array( 'reason' => 'passed' )) when the site is
		 * fine, or an array with 'reason' and 'message' to roll back (and
		 * 'file' for a fatal error, to roll back only the item it belongs
		 * to). Two reasons don't roll back:
		 *
		 *  - 'awaiting' holds the items back and pauses the run until the
		 *    administrator keeps or rolls back each one;
		 *  - 'pending' means the add-on's own check isn't finished: the items
		 *    stay in "Checking the site" and the filter is called again on
		 *    the next step (every few seconds) with the same $problem and
		 *    $after, until it returns something else or the wait reaches
		 *    wpbp_safe_update_pending_timeout (10 minutes by default). Then
		 *    the site check's own result decides, and an update it found
		 *    nothing wrong with is kept with a note that it wasn't fully
		 *    verified. 'message' is shown as the run's current step.
		 *
		 * 'details' in any answer is kept with the items (see
		 * wpbp_safe_update_item_report).
		 *
		 * @param array|null    $problem What got worse according to the site check, or null.
		 * @param SafeUpdateJob $job
		 * @param string[]      $ids     The ids of the items just installed (plugin basenames for plugins).
		 * @param array         $after   The site check's results (see UpdateHealthCheck::run()).
		 * @param array[]       $items   Those items ('type', 'id', 'name', 'from', 'to', 'active', …).
		 * @param int           $since   When the site was checked for these items (Unix time).
		 */
		$result = apply_filters( 'wpbp_safe_update_check', $problem, $job, self::ids_at( $p, $installed ), $after, self::items_at( $p, $installed ), $since );

		self::keep_details( $p, $installed, $result );

		$late = '';

		if ( is_array( $result ) && 'pending' === ( $result['reason'] ?? '' ) ) {
			if ( time() - $since < self::pending_timeout( $job, 'check' ) ) {
				$p['wait'] = array(
					'what'    => 'check',
					'items'   => $installed,
					'since'   => $since,
					'after'   => $after,
					'problem' => $problem,
					'message' => sanitize_text_field( (string) ( $result['message'] ?? '' ) ),
				);

				self::save( $job, $p, self::wait_step( $p ) );

				return $p;
			}

			Logger::warning( 'Safe Updates run #' . $job->id() . ': an add-on’s check after the update didn’t finish in time; the site check decides.', array(), $job->id() );

			$result = $problem;
			$late   = __( 'An add-on’s check didn’t finish in time, so the update wasn’t fully verified.', 'wrensafe' );
		}

		unset( $p['wait'] );

		if ( null === $result || ! is_array( $result ) || 'passed' === ( $result['reason'] ?? '' ) ) {
			foreach ( $installed as $i ) {
				$p['items'][ $i ]['state']   = 'updated';
				$p['items'][ $i ]['message'] = trim( __( 'Updated. The site works as before.', 'wrensafe' ) . ' ' . $late . ' ' . (string) ( $p['items'][ $i ]['late'] ?? '' ) );
				unset( $p['items'][ $i ]['late'] );
			}

			// The next item is compared with the site as it is now.
			$p['baseline'] = array_merge( $p['baseline'], $after );
			self::guard( $p, array( 'installed' ) );

			return $p;
		}

		$message = (string) ( $result['message'] ?? __( 'The site check found a problem.', 'wrensafe' ) );

		if ( 'awaiting' === ( $result['reason'] ?? '' ) ) {
			foreach ( $installed as $i ) {
				$p['items'][ $i ]['state']   = 'awaiting';
				$p['items'][ $i ]['message'] = $message;
			}

			self::save( $job, $p, __( 'Waiting for your decision…', 'wrensafe' ) );
			SafeUpdates::hold_guard();
			$job->pause();

			return $p;
		}

		// Roll back the item the fatal error names, or all of them.
		$culprit = 'fatal' === ( $result['reason'] ?? '' ) ? self::culprit( $p, $installed, (string) ( $result['file'] ?? '' ) ) : null;
		$targets = null !== $culprit ? array( $culprit ) : $installed;

		if ( count( $installed ) > 1 && null === $culprit ) {
			$message .= ' ' . ( self::all_of_type( $p, $installed, PluginUpdateType::KEY )
				? __( 'Wrensafe couldn’t tell which plugin caused it, so every plugin updated in this step was rolled back. Update them one by one to find it.', 'wrensafe' )
				: __( 'Wrensafe couldn’t tell which update caused it, so every update installed in this step was rolled back. Install them one by one to find it.', 'wrensafe' ) );
		}

		foreach ( array_diff( $installed, $targets ) as $i ) {
			$p['items'][ $i ]['state'] = 'installed';
		}

		// Only a page that didn't answer in time, even with more time: no proof the update broke anything.
		$why  = (string) ( $result['reason'] ?? '' );
		$page = (array) ( $after[ (string) ( $result['check'] ?? '' ) ] ?? array() );

		if ( ( 'unreachable' === $why && UpdateHealthCheck::timed_out( $after ) ) || ( 'server_error' === $why && UpdateHealthCheck::page_timed_out( $page ) ) ) {
			$why = 'timeout';
		}

		return self::roll_back( $job, $p, $targets, $message, $from_browser, $why );
	}

	/**
	 * Put the saved copies of the items at $indexes back.
	 *
	 * @param int[]  $indexes
	 * @param string $why What the check found: 'fatal', 'server_error', 'blank', 'unreachable', 'timeout'
	 *                    (pages that didn't answer in time), or '' when not from a check.
	 */
	private static function roll_back( SafeUpdateJob $job, array $p, array $indexes, string $reason, bool $from_browser, string $why = '' ): array {
		foreach ( $indexes as $i ) {
			$p['items'][ $i ]['state']   = 'rolling_back';
			$p['items'][ $i ]['message'] = $reason;

			// Kept with the item: a rollback cut off here goes on in another request.
			if ( '' !== $why ) {
				$p['items'][ $i ]['why'] = $why;
			}
		}

		self::save( $job, $p, __( 'Putting the previous version back…', 'wrensafe' ) );

		$rolled_back = array();
		$items       = array();
		$pending     = array();

		foreach ( $indexes as $i ) {
			$item     = $p['items'][ $i ];
			$item_why = (string) ( $item['why'] ?? $why );
			$handler  = self::type_of( $item );
			$id       = UpdateTypes::id_of( $item );
			$restored = self::restore_copy( $job, $item, max( self::deadline(), microtime( true ) + self::ROLLBACK_SECONDS ) );

			// No copy, but the previous version is already in place: an earlier request put it back (and
			// deleted the copy), then stopped before it could save that. The rollback is done; nothing must be
			// deactivated over it.
			if ( is_wp_error( $restored ) && 'wpbp_no_copy' === $restored->get_error_code() && '' !== (string) $item['from'] && (string) $item['from'] === $handler->installed_version( $id ) ) {
				Logger::info( 'Safe Updates run #' . $job->id() . ': ' . self::item_label( $item ) . ' was already back at ' . $item['from'] . ' (put back by an earlier step).', array(), $job->id() );
				$restored = true;
			}

			// The previous version isn't back yet; the run's next step goes on with it.
			if ( false === $restored ) {
				$p['items'][ $i ]['point'] = $item['point'];
				$pending[]                 = $i;
				continue;
			}

			unset( $p['items'][ $i ]['why'] );

			if ( true === $restored ) {
				$handler->keep_after_rollback( $item );

				$p['items'][ $i ]['state']   = 'rolled_back';
				$p['items'][ $i ]['version'] = $handler->installed_version( $id );
				/* translators: 1: version number, 2: what went wrong */
				$p['items'][ $i ]['message'] = sprintf( __( 'Rolled back to %1$s. %2$s', 'wrensafe' ), $item['from'], $reason );
				$p['items'][ $i ]['cause']   = UpdateFailure::rolled_back( $item_why, (array) ( $item['cause'] ?? array() ) );

				self::delete_copy( $item );
				$p['items'][ $i ]['point'] = array();
				$rolled_back[]             = $id;
				$items[]                   = $p['items'][ $i ];

				Logger::warning( 'Safe Updates: ' . self::item_label( $item ) . ' rolled back to ' . $item['from'] . '. ' . $reason, array(), $job->id() );
				continue;
			}

			if ( ! is_wp_error( $restored ) ) {
				$restored = new \WP_Error( 'wpbp_rollback_failed', __( 'the saved copy couldn’t be put back', 'wrensafe' ) );
			}

			if ( 'timeout' === $item_why && ! empty( $p['unverified'] ) ) {
				// Slow, and no check to tell whether switching it off helps: the update stays.
				$done = __( 'The update was kept: the site only answered too slowly, which doesn’t show the update broke it. Check the site.', 'wrensafe' );
			} else {
				// Last resort: whatever keeps the site working (plugins: deactivate).
				$done = self::last_resort_checked( $job, $p, $i, $from_browser, $item_why );
			}

			$p['items'][ $i ]['point']   = $item['point'];
			$p['items'][ $i ]['state']   = 'failed';
			$p['items'][ $i ]['cause']   = UpdateFailure::not_restored();
			$p['items'][ $i ]['message'] = trim(
				sprintf(
					/* translators: 1: what went wrong, 2: why the rollback failed, 3: what Wrensafe did instead, e.g. "The plugin was deactivated to keep the site working." */
					__( '%1$s The previous version couldn’t be put back (%2$s). %3$s Install the previous version again by hand.', 'wrensafe' ),
					$reason,
					$restored->get_error_message(),
					$done
				)
			);

			Logger::critical( 'Safe Updates: ' . self::item_label( $item ) . ' broke the site and could not be rolled back (' . $restored->get_error_message() . '). ' . $done . ' ' . $reason, array(), $job->id() );
		}

		$failed          = array_values( array_diff( array_intersect( $indexes, self::indexes( $p, array( 'failed' ) ) ), $pending ) );
		$still_installed = self::indexes( $p, array( 'installed' ) );
		self::guard( $p, array( 'installed' ) );

		if ( count( $pending ) === count( $indexes ) ) {
			// Nothing finished in this step.
			self::save( $job, $p, __( 'Putting the previous version back…', 'wrensafe' ) );

			return $p;
		}

		/**
		 * Fires after Safe Updates rolled updates back.
		 *
		 * @param SafeUpdateJob $job
		 * @param string[]      $ids    Ids of the items rolled back (plugin basenames for plugins).
		 * @param string        $reason
		 * @param array[]       $items  Those items ('type', 'id', 'name', …).
		 */
		do_action( 'wpbp_safe_update_rolled_back', $job, $rolled_back, $reason, $items );

		// Is the site back to how it was? (All at once: the next round checks.) Also after an
		// update that couldn't be rolled back: what Wrensafe did instead may not have been enough.
		if ( empty( $still_installed ) && empty( self::indexes( $p, array( 'rolling_back' ) ) ) && ! $p['unverified'] && ( $rolled_back || $failed ) ) {
			self::before_check( $p, self::changed( $p ) );

			$after   = UpdateHealthCheck::run( $from_browser );
			$problem = UpdateHealthCheck::compare( $p['baseline'], $after );

			if ( null !== $problem ) {
				sleep( 3 );
				$after   = UpdateHealthCheck::timed_out( $after ) ? UpdateHealthCheck::retry_timed_out( $after, $from_browser, $p['baseline'] ) : UpdateHealthCheck::run( $from_browser );
				$problem = UpdateHealthCheck::compare( $p['baseline'], $after );
			}

			if ( null !== $problem ) {
				$culprit = 'fatal' === $problem['reason'] ? self::culprit( $p, $indexes, (string) ( $problem['file'] ?? '' ) ) : null;
				$done    = null !== $culprit ? self::last_resort_checked( $job, $p, $culprit, $from_browser, 'fatal' ) : '';

				foreach ( $indexes as $i ) {
					$p['items'][ $i ]['message'] .= ' ' . ( $i === $culprit
						? trim( __( 'The site still showed the error afterwards.', 'wrensafe' ) . ' ' . $done )
						: __( 'The site still reports a problem after the rollback; see Wrensafe → Activity Logs.', 'wrensafe' ) );
				}

				Logger::error( 'Safe Updates run #' . $job->id() . ': the site still reports a problem after the rollback: ' . $problem['message'], array(), $job->id() );

				// Every later update would be compared with the site as it was before the run and
				// rolled back for this same problem: the run stops here instead.
				$p = self::stop_remaining( $p, __( 'Not updated: the site still had a problem after an earlier update in this run, so the run stopped instead of blaming more updates for it.', 'wrensafe' ) );
			}
		}

		return $p;
	}

	/**
	 * The last resort for an update that couldn't be rolled back (plugins: deactivate it), then a look
	 * at the site: when switching a plugin off broke something that needs it (a theme calling its
	 * functions), it is switched back on. Not a plugin that itself stopped the site with a fatal error:
	 * on again, it could lock the administrator out of wp-admin too.
	 *
	 * @param array<string,mixed> $p
	 * @param string              $why What the check found (see roll_back()).
	 * @return string What was done, for the item's message.
	 */
	private static function last_resort_checked( SafeUpdateJob $job, array $p, int $i, bool $from_browser, string $why = '' ): string {
		require_once ABSPATH . 'wp-admin/includes/plugin.php';

		$item    = $p['items'][ $i ];
		$handler = self::type_of( $item );
		$plugin  = PluginUpdateType::KEY === UpdateTypes::key_of( $item ) ? UpdateTypes::id_of( $item ) : '';
		$active  = '' !== $plugin && is_plugin_active( $plugin );
		$network = '' !== $plugin && is_multisite() && is_plugin_active_for_network( $plugin );
		$done    = $handler->last_resort( $item, $job->id() );

		if ( ! $active || $p['unverified'] || 'fatal' === $why ) {
			return $done;
		}

		$results = UpdateHealthCheck::run( $from_browser );
		$problem = UpdateHealthCheck::compare( $p['baseline'], $results );
		$owner   = null !== $problem && 'fatal' === $problem['reason'] ? self::culprit( $p, array( $i ), (string) ( $problem['file'] ?? '' ) ) : $i;

		// Only slow pages before: it stays off only if the site works again without it. Still slow, or any
		// other problem, means switching it off didn't help (the update isn't shown to be the cause).
		$slow = 'timeout' === $why && ( null !== $problem || UpdateHealthCheck::timed_out( $results ) );

		if ( $slow || ( null !== $problem && 'fatal' === $problem['reason'] && null === $owner ) ) {
			$back = activate_plugin( $plugin, '', $network, true );

			Logger::warning( 'Safe Updates run #' . $job->id() . ': ' . ( $slow ? 'with ' . self::item_label( $item ) . ' off the site still didn’t work' . ( null !== $problem ? ' (' . $problem['message'] . ')' : '' ) : 'switching ' . self::item_label( $item ) . ' off broke the site (' . $problem['message'] . ')' ) . '; ' . ( is_wp_error( $back ) ? 'switching it back on failed: ' . $back->get_error_message() : 'it was switched back on.' ), array(), $job->id() );

			if ( $slow ) {
				return is_wp_error( $back )
					? __( 'It was deactivated, but the site still didn’t work without it, and it couldn’t be switched back on.', 'wrensafe' )
					: __( 'The update was kept and the plugin stays active: switching it off didn’t make the site work again, so the update isn’t shown to be the cause. Check the site.', 'wrensafe' );
			}

			return is_wp_error( $back )
				? __( 'It was deactivated, but the site needs it (something else stopped working without it) and it couldn’t be switched back on.', 'wrensafe' )
				: __( 'It stays active: switching it off broke something that needs it (often the theme), so it was switched back on.', 'wrensafe' );
		}

		return $done;
	}

	/**
	 * End the items not started yet, with a reason (the run then finishes).
	 *
	 * @param array<string,mixed> $p
	 */
	private static function stop_remaining( array $p, string $message ): array {
		foreach ( $p['items'] as $i => $item ) {
			if ( in_array( $item['state'], array( 'waiting', 'saving', 'ready' ), true ) ) {
				$p['items'][ $i ]['state']   = 'skipped';
				$p['items'][ $i ]['message'] = $message;
			}
		}

		return $p;
	}

	/**
	 * Stop the run at once ("Stop now"). Updates not started stay as they are; an update installed but
	 * not checked yet goes back to its saved copy. When a step is running right now, it stops at its next
	 * safe point instead (as with "Stop after this update"), at most a few minutes later.
	 *
	 * @param string $why Why it was stopped, for the log ('' = by the administrator).
	 * @return bool True when the run has ended; false when a running step will end it.
	 */
	public static function stop_now( SafeUpdateJob $job, string $why = '' ): bool {
		if ( $job->is_finished() ) {
			return true;
		}

		// Once: a restore waiting for the run asks again on every step.
		if ( ! self::cancel_requested( $job ) ) {
			self::request_cancel( $job );
		}

		if ( ! self::lock() ) {
			return false;
		}

		try {
			$fresh = SafeUpdateJob::get( $job->id() );

			if ( null === $fresh || $fresh->is_finished() ) {
				return true;
			}

			if ( function_exists( 'set_time_limit' ) ) {
				@set_time_limit( 300 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.PHP.DiscouragedPHPFunctions.runtime_configuration_set_time_limit, Squiz.PHP.DiscouragedFunctions.Discouraged -- putting saved copies back can take a while.
			}

			$reason = '' !== $why ? $why : __( 'You stopped the run before this update was checked.', 'wrensafe' );

			Logger::info( 'Safe Updates run #' . $fresh->id() . ': stopped at once (' . ( '' !== $why ? $why : 'by user #' . get_current_user_id() ) . ').', array(), $fresh->id() );

			self::finish( $fresh, self::safety_stop( $fresh, '', $reason ) );
		} finally {
			self::unlock();
		}

		return true;
	}

	/**
	 * Items whose update request was cut off: find out what is on disk.
	 *
	 * @param int[] $indexes
	 */
	private static function recover( SafeUpdateJob $job, array $p, array $indexes ): array {
		self::end_maintenance_mode();

		foreach ( $indexes as $i ) {
			$item    = $p['items'][ $i ];
			$version = self::type_of( $item )->installed_version( UpdateTypes::id_of( $item ) );

			$p['items'][ $i ]['version'] = $version;

			if ( '' !== $version && version_compare( $version, $item['from'], '>' ) ) {
				$p['items'][ $i ]['state'] = 'installed';
			} elseif ( '' === $version && $p['safe'] && ! empty( $item['point']['done'] ) ) {
				$p['items'][ $i ]['state']   = 'rolling_back';
				$p['items'][ $i ]['message'] = __( 'The update was cut off part-way and left the files incomplete.', 'wrensafe' );
			} elseif ( '' !== $version && (int) $item['attempts'] < 2 ) {
				// Cut off before anything changed: try once more.
				$p['items'][ $i ]['state'] = 'ready';
			} else {
				$p['items'][ $i ]['state']   = 'failed';
				$p['items'][ $i ]['message'] = '' === $version
					? __( 'The update was cut off part-way.', 'wrensafe' ) . ' ' . self::text( $item, 'missing' )
					: __( 'The update was cut off twice before it could install; nothing was changed.', 'wrensafe' );
			}

			Logger::warning( 'Safe Updates run #' . $job->id() . ': the request updating ' . self::item_label( $item ) . ' was cut off; continuing (' . $p['items'][ $i ]['state'] . ').', array(), $job->id() );
		}

		return $p;
	}

	/**
	 * The run stopped with an error, or was stopped at once. In a safe mode,
	 * no update stays unchecked: every item installed but not yet checked
	 * goes back to its saved copy.
	 *
	 * @param string $error  The error that stopped the run ('' when stopped on purpose).
	 * @param string $reason Why, in the items' messages, when stopped on purpose.
	 */
	private static function safety_stop( SafeUpdateJob $job, string $error, string $reason = '' ): array {
		$p = self::normalize( $job->payload() );

		if ( empty( $p['safe'] ) || empty( $p['items'] ) ) {
			return $p;
		}

		foreach ( self::indexes( $p, array( 'updating', 'installed', 'checking', 'awaiting', 'rolling_back' ) ) as $i ) {
			$item    = $p['items'][ $i ];
			$handler = UpdateTypes::get( UpdateTypes::key_of( $item ) );

			if ( empty( $item['point']['done'] ) || null === $handler ) {
				continue;
			}

			// Several copies can take longer than the lock lasts.
			self::renew_lock();

			try {
				$restored = self::restore_copy( $job, $item, microtime( true ) + 60 );

				// An own copy that needs more steps: the run stops here, so it is settled now.
				if ( false === $restored ) {
					$restored = self::settle_copy( $job, $item );
				}
			} catch ( \Throwable $e ) {
				$restored = new \WP_Error( 'wpbp_rollback_failed', Errors::text( $e ) );
			}

			$p['items'][ $i ]['point'] = $item['point'];

			if ( true === $restored ) {
				$handler->keep_after_rollback( $item );

				$p['items'][ $i ]['state']   = 'rolled_back';
				$p['items'][ $i ]['version'] = $handler->installed_version( UpdateTypes::id_of( $item ) );
				$p['items'][ $i ]['message'] = '' !== $reason
					/* translators: 1: version number, 2: why, e.g. "You stopped the run before this update was checked." */
					? sprintf( __( 'Rolled back to %1$s. %2$s', 'wrensafe' ), $item['from'], $reason )
					/* translators: 1: version number, 2: error message */
					: sprintf( __( 'Rolled back to %1$s: the run stopped with an error before the site could be checked (%2$s).', 'wrensafe' ), $item['from'], $error );

				self::delete_copy( $item );
				$p['items'][ $i ]['point'] = array();

				Logger::warning( 'Safe Updates: ' . self::item_label( $item ) . ' rolled back because the run stopped before the site was checked.', array(), $job->id() );
			} elseif ( null !== self::own_point( $item ) && is_wp_error( $restored ) ) {
				// An own copy says what it left in place.
				$p['items'][ $i ]['state']   = 'failed';
				$p['items'][ $i ]['version'] = $handler->installed_version( UpdateTypes::id_of( $item ) );
				$p['items'][ $i ]['message'] = '' !== $reason
					? trim( $reason . ' ' . $restored->get_error_message() )
					/* translators: 1: error message, 2: what was kept, e.g. "WordPress 7.1 was kept." */
					: sprintf( __( 'The run stopped with an error before the site could be checked (%1$s). %2$s', 'wrensafe' ), $error, $restored->get_error_message() );

				Logger::critical( 'Safe Updates: ' . self::item_label( $item ) . ' could not be rolled back after the run stopped: ' . $restored->get_error_message(), array(), $job->id() );
			}
		}

		return $p;
	}

	/** Clean up and report. */
	private static function finish( SafeUpdateJob $job, array $p, string $error = '' ): void {
		LiveProgress::close();

		$items = isset( $p['items'] ) && is_array( $p['items'] ) ? $p['items'] : array();

		foreach ( $items as $i => $item ) {
			$handler = UpdateTypes::get( UpdateTypes::key_of( $item ) );

			if ( in_array( $item['state'], array( 'waiting', 'saving', 'ready' ), true ) ) {
				$items[ $i ]['state']   = '' !== $error ? 'skipped' : 'cancelled';
				$items[ $i ]['message'] = '' !== $error ? __( 'Not updated: the run stopped.', 'wrensafe' ) : __( 'Not updated: the run was stopped.', 'wrensafe' );
			} elseif ( 'rolling_back' === $item['state'] && null !== $handler && null !== self::own_point( $item ) && ! empty( $item['point']['done'] ) ) {
				// An own copy part-way back: finished now, or the part done undone.
				$copy = $item;

				try {
					$settled = self::settle_copy( $job, $copy );
				} catch ( \Throwable $e ) {
					$settled = new \WP_Error( 'wpbp_rollback_failed', Errors::text( $e ) );
				}

				$items[ $i ]['point']   = $copy['point'];
				$items[ $i ]['version'] = $handler->installed_version( UpdateTypes::id_of( $item ) );

				if ( true === $settled ) {
					$handler->keep_after_rollback( $item );

					$items[ $i ]['state'] = 'rolled_back';
					/* translators: 1: version number, 2: what went wrong */
					$items[ $i ]['message'] = sprintf( __( 'Rolled back to %1$s. %2$s', 'wrensafe' ), $item['from'], (string) $item['message'] );
				} else {
					$items[ $i ]['state']   = 'failed';
					$items[ $i ]['message'] = trim( __( 'The run stopped while the previous version was being put back.', 'wrensafe' ) . ' ' . ( is_wp_error( $settled ) ? $settled->get_error_message() : '' ) );
				}
			} elseif ( 'rolling_back' === $item['state'] ) {
				$items[ $i ]['state']   = 'failed';
				$items[ $i ]['version'] = null !== $handler ? $handler->installed_version( UpdateTypes::id_of( $item ) ) : (string) $item['version'];
				$items[ $i ]['message'] = __( 'The run stopped while the previous version was being put back. Check this update on the Plugins or Themes screen.', 'wrensafe' );
			} elseif ( ! in_array( $item['state'], self::DONE_STATES, true ) ) {
				// The run stopped with an error between the update and the check.
				$version = null !== $handler ? $handler->installed_version( UpdateTypes::id_of( $item ) ) : (string) $item['version'];

				$items[ $i ]['version'] = $version;

				if ( '' !== $version && version_compare( $version, (string) $item['from'], '>' ) ) {
					$items[ $i ]['state']   = 'updated';
					$items[ $i ]['message'] = __( 'Updated, but the run stopped before the site was checked.', 'wrensafe' );
				} else {
					$items[ $i ]['state']   = 'failed';
					$items[ $i ]['message'] = '' === $version
						? __( 'The run stopped during the update.', 'wrensafe' ) . ' ' . self::text( $item, 'missing' )
						: __( 'Not updated: the run stopped.', 'wrensafe' );
				}
			}

			unset( $items[ $i ]['late'] );

			// A copy is only kept for an item that now runs its new version, or
			// whose folder is gone (the copy is then the only one left).
			if ( 'updated' !== $items[ $i ]['state'] && ! empty( $items[ $i ]['point'] ) && '' !== (string) ( $items[ $i ]['version'] ?? '' ) ) {
				self::delete_copy( $items[ $i ] );
				$items[ $i ]['point'] = array();
			}
		}

		$p['items'] = $items;
		unset( $p['wait'] );
		$summary = self::summary( $p );

		if ( ! empty( $p['clear_cache'] ) && ( $summary['updated'] + $summary['rolled_back'] ) > 0 ) {
			$p['cleared'] = CacheCleaner::clear_all( $job->id() );
		}

		foreach ( UpdateTypes::all() as $key => $handler ) {
			$updated = array();

			foreach ( $items as $item ) {
				if ( 'updated' === $item['state'] && UpdateTypes::key_of( $item ) === $key ) {
					$updated[] = UpdateTypes::id_of( $item );
				}
			}

			$handler->forget_update( $updated );
		}

		SafeUpdates::remove_guard();
		delete_transient( self::CANCEL_TRANSIENT . $job->id() );

		$p['phase'] = 'done';
		$job->set_payload( $p );

		Logger::log(
			'' !== $error || $summary['rolled_back'] + $summary['failed'] > 0 ? 'warning' : 'success',
			sprintf(
				'Safe Updates run #%1$d finished: %2$d updated, %3$d rolled back, %4$d failed, %5$d not updated.%6$s',
				$job->id(),
				$summary['updated'],
				$summary['rolled_back'],
				$summary['failed'],
				$summary['skipped'] + $summary['cancelled'],
				'' !== $error ? ' ' . $error : ''
			),
			array(),
			$job->id()
		);

		if ( '' !== $error ) {
			$job->mark_failed( $error );
		} else {
			$job->update_progress( 100, self::summary_text( $summary ) );
			$job->mark_completed( array( 'summary' => $summary ) );
		}

		/**
		 * Fires when a Safe Updates run ends.
		 *
		 * @param SafeUpdateJob $job
		 * @param array         $summary Counts by state.
		 */
		do_action( 'wpbp_safe_update_finished', $job, $summary );
	}

	public static function is_updated_item( array $item ): bool {
		return 'updated' === $item['state'];
	}

	// ---------------------------------------------------------------------
	// Reports.
	// ---------------------------------------------------------------------

	/** The run, for the update window, the Safe Updates page and add-ons' reports. */
	public static function report( SafeUpdateJob $job ): array {
		$p     = self::normalize( $job->payload() );
		$items = array();

		foreach ( (array) ( $p['items'] ?? array() ) as $item ) {
			$key = UpdateTypes::key_of( $item );
			$id  = UpdateTypes::id_of( $item );

			/**
			 * Filters the details shown with an item of a Safe Updates run (on
			 * the Safe Updates page, in the update window, and in add-ons'
			 * reports). Supported keys, all optional:
			 *
			 *  - 'note':   a sentence or two of plain text;
			 *  - 'images': a list of array( 'label' => …, 'url' => … ), shown as
			 *              linked thumbnails (screenshots before and after the
			 *              update, for example);
			 *  - 'links':  a list of array( 'label' => …, 'url' => … ).
			 *
			 * Other keys are dropped, and only http(s) URLs are kept.
			 *
			 * @param array         $details What the run kept for the item ('details' answered by
			 *                               wpbp_safe_update_baseline and wpbp_safe_update_check).
			 * @param array         $item    The run's item ('type', 'id', 'name', 'from', 'to', 'state', 'message', …).
			 * @param SafeUpdateJob $job
			 */
			$details = apply_filters( 'wpbp_safe_update_item_report', (array) ( $item['details'] ?? array() ), $item, $job );

			$items[] = array(
				'type'        => $key,
				'id'          => $id,
				'plugin'      => PluginUpdateType::KEY === $key ? $id : '',
				'name'        => (string) $item['name'],
				'from'        => (string) $item['from'],
				'to'          => (string) $item['to'],
				'version'     => (string) $item['version'],
				'state'       => (string) $item['state'],
				'message'     => (string) $item['message'],
				'cause'       => self::cause_of( $item ),
				'canRollBack' => $job->is_finished() && self::can_roll_back( $item ),
				'details'     => self::clean_details( is_array( $details ) ? $details : array() ),
			);
		}

		$row      = JobsRepository::find( $job->id() );
		$summary  = self::summary( $p );
		$critical = false;

		// An update that broke the site and couldn't be put back: the run isn't a success.
		foreach ( $items as $item ) {
			if ( 'critical' === (string) ( $item['cause']['kind'] ?? '' ) ) {
				$critical = true;
			}
		}

		return array(
			'id'       => $job->id(),
			'status'   => $job->status(),
			'progress' => $job->progress(),
			'step'     => $job->current_step(),
			'error'    => $job->error_message(),
			'finished' => $job->is_finished(),
			'paused'   => SafeUpdateJob::STATUS_PAUSED === $job->status(),
			'waiting'  => ! empty( $p['wait'] ),
			'mode'     => (string) ( $p['mode'] ?? '' ),
			'modeName' => (string) ( SafeUpdates::modes()[ $p['mode'] ?? '' ]['label'] ?? ( $p['mode'] ?? '' ) ),
			'order'    => (string) ( $p['order'] ?? '' ),
			'items'    => $items,
			'types'    => array_values( array_unique( wp_list_pluck( $items, 'type' ) ) ),
			'summary'  => $summary,
			'live'     => $job->is_finished() ? '' : LiveProgress::url( $p ),
			'base'     => 'none' === ( $p['backup'] ?? 'none' ) ? 3 : 25,
			'pills'    => UpdateFailure::pills( $summary, $items, 'failed' === $job->status() ),
			// A run where most updates worked is a success, with the ones that didn't marked next to it.
			'tone'     => '' !== (string) $job->error_message() || $critical || $summary['rolled_back'] || ( $summary['failed'] && ! $summary['updated'] ) ? 'is-warning' : 'is-success',
			'cleared'  => array_values( (array) ( $p['cleared'] ?? array() ) ),
			'backupId' => (int) ( $p['backup_id'] ?? 0 ),
			'date'     => $row ? mysql2date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), (string) $row['created_at'] ) : '',
			// For the screens: a stop asked for, and how long nothing has moved (a run nobody continues).
			'stopping' => ! $job->is_finished() && self::cancel_requested( $job ),
			'idle'     => $job->is_finished() ? 0 : self::seconds_since_activity( $job ),
		);
	}

	/**
	 * Why an item wasn't updated or was rolled back, for the screens (empty when it was).
	 *
	 * @return array<string,string>
	 */
	private static function cause_of( array $item ): array {
		$cause = (array) ( $item['cause'] ?? array() );

		if ( empty( $cause['label'] ) || ! in_array( (string) $item['state'], array( 'failed', 'rolled_back', 'rolling_back' ), true ) ) {
			return array();
		}

		$cause = array_map( 'strval', $cause );

		if ( '' === ( $cause['text'] ?? '' ) ) {
			$cause['text'] = (string) $item['message'];
		}

		return $cause;
	}

	/**
	 * The details an item may carry, cleaned (see wpbp_safe_update_item_report).
	 *
	 * @return array{note?:string,images?:array,links?:array}
	 */
	public static function clean_details( array $details ): array {
		$out = array();

		if ( isset( $details['note'] ) && is_scalar( $details['note'] ) && '' !== trim( (string) $details['note'] ) ) {
			$out['note'] = sanitize_textarea_field( (string) $details['note'] );
		}

		foreach ( array( 'images', 'links' ) as $key ) {
			if ( empty( $details[ $key ] ) || ! is_array( $details[ $key ] ) ) {
				continue;
			}

			foreach ( array_slice( $details[ $key ], 0, 20 ) as $entry ) {
				$url = is_array( $entry ) ? esc_url_raw( (string) ( $entry['url'] ?? '' ), array( 'http', 'https' ) ) : '';

				if ( '' !== $url ) {
					$out[ $key ][] = array(
						'label' => sanitize_text_field( (string) ( $entry['label'] ?? '' ) ),
						'url'   => $url,
					);
				}
			}
		}

		return $out;
	}

	/** @return array<string,int> */
	public static function summary( array $p ): array {
		$summary = array_fill_keys( array( 'updated', 'rolled_back', 'failed', 'skipped', 'cancelled', 'pending' ), 0 );

		foreach ( (array) ( $p['items'] ?? array() ) as $item ) {
			$state = (string) $item['state'];
			$key   = isset( $summary[ $state ] ) ? $state : 'pending';
			++$summary[ $key ];
		}

		return $summary;
	}

	public static function summary_text( array $summary ): string {
		$parts = array();

		if ( $summary['updated'] ) {
			/* translators: %d: number of plugins (or themes) */
			$parts[] = sprintf( _n( '%d updated', '%d updated', $summary['updated'], 'wrensafe' ), $summary['updated'] );
		}

		if ( $summary['rolled_back'] ) {
			/* translators: %d: number of plugins (or themes) */
			$parts[] = sprintf( _n( '%d rolled back', '%d rolled back', $summary['rolled_back'], 'wrensafe' ), $summary['rolled_back'] );
		}

		if ( $summary['failed'] ) {
			/* translators: %d: number of plugins (or themes) */
			$parts[] = sprintf( _n( '%d failed', '%d failed', $summary['failed'], 'wrensafe' ), $summary['failed'] );
		}

		if ( $summary['skipped'] + $summary['cancelled'] ) {
			/* translators: %d: number of plugins (or themes) */
			$parts[] = sprintf( _n( '%d not updated', '%d not updated', $summary['skipped'] + $summary['cancelled'], 'wrensafe' ), $summary['skipped'] + $summary['cancelled'] );
		}

		return empty( $parts ) ? __( 'Nothing was updated.', 'wrensafe' ) : implode( ', ', $parts ) . '.';
	}

	/** Whether a finished item can still be rolled back from its saved copy. */
	public static function can_roll_back( array $item ): bool {
		$handler = UpdateTypes::get( UpdateTypes::key_of( $item ) );

		if ( 'updated' !== $item['state'] || null === $handler || empty( $item['point']['done'] ) ) {
			return false;
		}

		$usable = $handler instanceof OwnRestorePoint
			? $handler->point_usable( (array) $item['point'], $item )
			: is_file( (string) ( $item['point']['zip'] ?? '' ) );

		return $usable && $handler->installed_version( UpdateTypes::id_of( $item ) ) === (string) $item['version'];
	}

	// ---------------------------------------------------------------------
	// Helpers.
	// ---------------------------------------------------------------------

	/** Whether updates of this run are installed but not checked yet. */
	private static function has_unchecked( SafeUpdateJob $job ): bool {
		return array() !== self::indexes( (array) $job->payload(), array( 'installed' ) );
	}

	/** When this request's work should stop (microtime). */
	private static function deadline(): float {
		return self::$deadline > 0 ? self::$deadline : microtime( true ) + 20;
	}

	/**
	 * The items of a run as an add-on left them (see wpbp_safe_update_run):
	 * in its order, only items the run had, each as the run had it.
	 *
	 * @param array[] $items    The run's items.
	 * @param array   $filtered The filter's answer.
	 * @return array[]
	 */
	private static function known_items( array $items, array $filtered ): array {
		$known = array();
		$out   = array();

		foreach ( $items as $item ) {
			$known[ $item['type'] . ':' . $item['id'] ] = $item;
		}

		foreach ( $filtered as $item ) {
			$key = is_array( $item ) ? (string) ( $item['type'] ?? '' ) . ':' . (string) ( $item['id'] ?? '' ) : '';

			if ( isset( $known[ $key ] ) ) {
				$out[] = $known[ $key ];
				unset( $known[ $key ] );
			}
		}

		return $out;
	}

	/** The stronger of two backups ('none' < 'database' < 'full'). */
	private static function stronger_backup( string $backup, string $wanted ): string {
		$rank = array(
			'none'     => 0,
			'database' => 1,
			'full'     => 2,
		);

		return isset( $rank[ $wanted ] ) && $rank[ $wanted ] > ( $rank[ $backup ] ?? 0 ) ? $wanted : $backup;
	}

	/** The item's type when it keeps its own copy (see OwnRestorePoint), or null. */
	private static function own_point( array $item ): ?OwnRestorePoint {
		$handler = UpdateTypes::get( UpdateTypes::key_of( $item ) );

		return $handler instanceof OwnRestorePoint ? $handler : null;
	}

	/**
	 * Put an item's saved copy back. $item['point'] is updated in place.
	 *
	 * @return true|false|\WP_Error False when the item's type needs another step (an own copy).
	 */
	private static function restore_copy( SafeUpdateJob $job, array &$item, float $deadline ) {
		$point = (array) ( $item['point'] ?? array() );

		if ( empty( $point['done'] ) ) {
			return new \WP_Error( 'wpbp_no_copy', __( 'no copy was saved', 'wrensafe' ) );
		}

		$own = self::own_point( $item );

		if ( null === $own ) {
			return RestorePoint::restore( $point, UpdateTypes::id_of( $item ) );
		}

		$result        = $own->restore_point( $point, $item, $job->id(), $deadline );
		$item['point'] = $point;

		return true === $result || false === $result || is_wp_error( $result ) ? $result : new \WP_Error( 'wpbp_rollback_failed', __( 'the saved copy couldn’t be put back', 'wrensafe' ) );
	}

	/**
	 * An own copy part-way back while the run (or this request) can't go
	 * on: its type finishes the rollback or undoes the part done.
	 *
	 * @return true|\WP_Error
	 */
	private static function settle_copy( SafeUpdateJob $job, array &$item ) {
		$own = self::own_point( $item );

		if ( null === $own ) {
			return new \WP_Error( 'wpbp_rollback_failed', __( 'the saved copy couldn’t be put back', 'wrensafe' ) );
		}

		$point         = (array) ( $item['point'] ?? array() );
		$result        = $own->settle_point( $point, $item, $job->id() );
		$item['point'] = $point;

		return true === $result || is_wp_error( $result ) ? $result : new \WP_Error( 'wpbp_rollback_failed', __( 'the saved copy couldn’t be put back', 'wrensafe' ) );
	}

	/** Remove an item's saved copy. */
	private static function delete_copy( array $item ): void {
		$point = (array) ( $item['point'] ?? array() );

		if ( empty( $point ) ) {
			return;
		}

		$own = self::own_point( $item );

		if ( null !== $own ) {
			$own->delete_point( $point );
		} else {
			RestorePoint::delete( $point );
		}
	}

	/**
	 * Give every item its type and id (runs saved before update types
	 * existed only name 'plugin').
	 */
	private static function normalize( array $p ): array {
		foreach ( (array) ( $p['items'] ?? array() ) as $i => $item ) {
			$p['items'][ $i ]['type']    = UpdateTypes::key_of( $item );
			$p['items'][ $i ]['id']      = UpdateTypes::id_of( $item );
			$p['items'][ $i ]['details'] = (array) ( $item['details'] ?? array() );
		}

		return $p;
	}

	/** Items whose type is no longer registered (its add-on was switched off) can't go on. */
	private static function drop_unknown_types( SafeUpdateJob $job, array $p ): array {
		foreach ( $p['items'] as $i => $item ) {
			if ( null !== UpdateTypes::get( UpdateTypes::key_of( $item ) ) || in_array( $item['state'], self::DONE_STATES, true ) ) {
				continue;
			}

			$p['items'][ $i ]['state']   = in_array( $item['state'], array( 'waiting', 'saving', 'ready' ), true ) ? 'skipped' : 'failed';
			$p['items'][ $i ]['message'] = __( 'The add-on that handles this kind of update is no longer active.', 'wrensafe' );

			Logger::warning( 'Safe Updates run #' . $job->id() . ': ' . self::item_label( $item ) . ' dropped, its update type is no longer registered.', array(), $job->id() );
		}

		return $p;
	}

	/** The item's update type (only called for items whose type is registered). */
	private static function type_of( array $item ): UpdateType {
		$handler = UpdateTypes::get( UpdateTypes::key_of( $item ) );

		if ( null === $handler ) {
			throw new \RuntimeException( esc_html__( 'The add-on that handles this kind of update is no longer active.', 'wrensafe' ) );
		}

		return $handler;
	}

	private static function is_item( array $item, string $type, string $id ): bool {
		return UpdateTypes::key_of( $item ) === ( '' !== $type ? $type : PluginUpdateType::KEY ) && UpdateTypes::id_of( $item ) === $id;
	}

	/** "type:id" for the log. */
	public static function item_label( array $item ): string {
		return UpdateTypes::key_of( $item ) . ' ' . UpdateTypes::id_of( $item );
	}

	/** A text of the item's type, or the general one. */
	private static function text( array $item, string $key ): string {
		$handler = UpdateTypes::get( UpdateTypes::key_of( $item ) );
		$strings = null !== $handler ? $handler->strings() : array();

		if ( isset( $strings[ $key ] ) && is_string( $strings[ $key ] ) ) {
			return $strings[ $key ];
		}

		$general = array(
			'missing' => PluginUpdateType::KEY === UpdateTypes::key_of( $item )
				? __( 'The plugin’s files are missing now; install it again from Plugins → Add New.', 'wrensafe' )
				: __( 'Its files are missing now; install it again.', 'wrensafe' ),
		);

		return $general[ $key ] ?? '';
	}

	/**
	 * Indexes of the items in the given states.
	 *
	 * @param string[] $states
	 * @return int[]
	 */
	private static function indexes( array $p, array $states ): array {
		$found = array();

		foreach ( $p['items'] as $i => $item ) {
			if ( in_array( $item['state'], $states, true ) ) {
				$found[] = (int) $i;
			}
		}

		return $found;
	}

	/**
	 * @param int[] $indexes
	 * @return string[]
	 */
	private static function ids_at( array $p, array $indexes ): array {
		$ids = array();

		foreach ( $indexes as $i ) {
			$ids[] = UpdateTypes::id_of( $p['items'][ $i ] );
		}

		return $ids;
	}

	/**
	 * The items at $indexes without the run's own bookkeeping.
	 *
	 * @param int[] $indexes
	 * @return array[]
	 */
	private static function items_at( array $p, array $indexes ): array {
		$items = array();

		foreach ( $indexes as $i ) {
			$items[] = array_diff_key( $p['items'][ $i ], array_flip( array( 'point', 'attempts', 'late' ) ) );
		}

		return $items;
	}

	/**
	 * The indexes grouped by the items' type, in the order they come.
	 *
	 * @param int[] $indexes
	 * @return array<string,int[]>
	 */
	private static function by_type( array $p, array $indexes ): array {
		$groups = array();

		foreach ( $indexes as $i ) {
			$groups[ UpdateTypes::key_of( $p['items'][ $i ] ) ][] = (int) $i;
		}

		return $groups;
	}

	/** @param int[] $indexes */
	private static function all_of_type( array $p, array $indexes, string $type ): bool {
		return array( $type ) === array_keys( self::by_type( $p, $indexes ) );
	}

	/**
	 * The index of the item a fatal error's file belongs to, or null.
	 *
	 * @param int[] $indexes
	 */
	private static function culprit( array $p, array $indexes, string $file ): ?int {
		if ( '' === $file ) {
			return null;
		}

		foreach ( self::by_type( $p, $indexes ) as $key => $group ) {
			$owner = (string) self::type_of( $p['items'][ $group[0] ] )->owner_of_file( $file, self::ids_at( $p, $group ) );

			if ( '' === $owner ) {
				continue;
			}

			foreach ( $group as $i ) {
				if ( UpdateTypes::id_of( $p['items'][ $i ] ) === $owner ) {
					return $i;
				}
			}
		}

		return null;
	}

	/**
	 * Tell the boot guard which items are on the site but not checked yet.
	 *
	 * @param string[] $states
	 */
	private static function guard( array $p, array $states ): void {
		if ( empty( $p['safe'] ) ) {
			return;
		}

		$by_type = array();

		foreach ( self::by_type( $p, self::indexes( $p, $states ) ) as $key => $group ) {
			$by_type[ $key ] = self::ids_at( $p, $group );
		}

		SafeUpdates::guard_items( $by_type );
	}

	/** Whether the run waits for an add-on's 'pending' answer of this kind ('baseline' or 'check'). */
	private static function waiting( array $p, string $what ): bool {
		return isset( $p['wait']['what'] ) && $what === $p['wait']['what'];
	}

	/** The step shown while an add-on's answer is pending. */
	private static function wait_step( array $p ): string {
		$message = (string) ( $p['wait']['message'] ?? '' );

		if ( '' !== $message ) {
			return $message;
		}

		return self::waiting( $p, 'baseline' ) ? __( 'Getting ready to update…', 'wrensafe' ) : __( 'Checking the site…', 'wrensafe' );
	}

	/** Seconds a 'pending' answer may hold the run. */
	private static function pending_timeout( SafeUpdateJob $job, string $what ): int {
		/**
		 * Filters how long a 'pending' answer from wpbp_safe_update_baseline
		 * or wpbp_safe_update_check may hold a Safe Updates run.
		 *
		 * @param int           $seconds Default 600 (10 minutes).
		 * @param SafeUpdateJob $job
		 * @param string        $what    'baseline' or 'check'.
		 */
		return max( 10, (int) apply_filters( 'wpbp_safe_update_pending_timeout', self::PENDING_SECONDS, $job, $what ) );
	}

	/**
	 * Keep the 'details' of an add-on's answer with these items.
	 *
	 * @param mixed $result The baseline or check filter's answer.
	 * @param int[] $indexes
	 */
	private static function keep_details( array &$p, array $indexes, $result ): void {
		if ( ! is_array( $result ) || empty( $result['details'] ) || ! is_array( $result['details'] ) ) {
			return;
		}

		foreach ( $indexes as $i ) {
			$p['items'][ $i ]['details'] = self::clean_details( array_merge( (array) ( $p['items'][ $i ]['details'] ?? array() ), $result['details'] ) );
		}
	}

	private static function cancel_remaining( array $p ): array {
		foreach ( $p['items'] as $i => $item ) {
			if ( in_array( $item['state'], array( 'waiting', 'saving', 'ready' ), true ) ) {
				$p['items'][ $i ]['state']   = 'cancelled';
				$p['items'][ $i ]['message'] = __( 'Not updated: the run was stopped.', 'wrensafe' );
			}
		}

		unset( $p['wait'] );

		return $p;
	}

	private static function save( SafeUpdateJob $job, array $p, string $step = '' ): void {
		// Once the lock's time is up, or past the renewal window (keep_lock()), a
		// takeover may not have been noticed yet.
		if ( ! self::$lock_lost && '' !== self::$lock && ( time() >= (int) self::$lock || microtime( true ) > self::$deadline + self::FINISH_CHECK_SECONDS + self::ROLLBACK_SECONDS ) && ! self::holds_lock() ) {
			self::$lock      = '';
			self::$lock_lost = true;
		}

		if ( self::$lock_lost ) {
			throw new \RuntimeException( esc_html( 'Safe Updates: this request no longer holds the run lock.' ), (int) self::LOCK_LOST );
		}

		$job->set_payload( $p );
		$job->update_progress( self::progress( $p ), '' !== $step ? $step : self::describe( $p ) );
		LiveProgress::publish( $p );
	}

	private static function progress( array $p ): int {
		$total = max( 1, count( $p['items'] ) );
		$done  = 0.0;

		foreach ( $p['items'] as $item ) {
			if ( in_array( $item['state'], self::DONE_STATES, true ) ) {
				++$done;
			} elseif ( in_array( $item['state'], array( 'installed', 'checking', 'rolling_back', 'awaiting' ), true ) ) {
				$done += 0.7;
			} elseif ( in_array( $item['state'], array( 'ready', 'updating' ), true ) ) {
				$done += 0.3;
			}
		}

		$start = 'none' === $p['backup'] ? 3 : 25;

		if ( 'backup' === $p['phase'] ) {
			return 3;
		}

		return (int) min( 99, $start + ( 99 - $start ) * $done / $total );
	}

	private static function describe( array $p ): string {
		// An update is held back until the administrator keeps it or rolls it back.
		if ( in_array( 'awaiting', array_column( $p['items'], 'state' ), true ) ) {
			return __( 'Waiting for your decision…', 'wrensafe' );
		}

		switch ( $p['phase'] ) {
			case 'backup':
				return __( 'Backing up the site before the updates…', 'wrensafe' );
			case 'baseline':
				return __( 'Checking the site before the updates…', 'wrensafe' );
			case 'points':
				return self::all_of_type( $p, array_keys( $p['items'] ), PluginUpdateType::KEY ) ? __( 'Saving copies of the plugins…', 'wrensafe' ) : __( 'Saving copies before updating…', 'wrensafe' );
			case 'check':
				return __( 'Checking the site…', 'wrensafe' );
			case 'finish':
			case 'done':
				return __( 'Finishing…', 'wrensafe' );
		}

		$total = count( $p['items'] );
		$i     = min( (int) $p['cursor'], $total - 1 );

		return sprintf(
			/* translators: 1: name of the plugin (or theme), 2: its position, 3: number of updates */
			__( '%1$s (%2$d of %3$d)…', 'wrensafe' ),
			$p['items'][ $i ]['name'],
			$i + 1,
			$total
		);
	}

	/**
	 * Indexes of the items whose files this run has changed so far
	 * (updated, or rolled back after an update).
	 *
	 * @return int[]
	 */
	private static function changed( array $p ): array {
		return self::indexes( $p, array( 'updating', 'installed', 'checking', 'awaiting', 'rolling_back', 'updated', 'rolled_back', 'failed' ) );
	}

	/**
	 * Make the next site check load these items' files as they are on disk
	 * now: clear this PHP's opcode cache for them, and have the check
	 * requests clear theirs (through the guard). Without the guard, give
	 * PHP's own timestamp check a moment.
	 *
	 * @param int[] $indexes
	 */
	private static function before_check( array $p, array $indexes ): void {
		global $wp_filesystem;

		// wp_opcache_invalidate_directory() lists files through WP_Filesystem.
		$listable = SafeUpdates::filesystem() && is_object( $wp_filesystem );
		$paths    = array();

		foreach ( $indexes as $i ) {
			$handler = UpdateTypes::get( UpdateTypes::key_of( $p['items'][ $i ] ) );

			if ( null === $handler ) {
				continue;
			}

			$path    = untrailingslashit( wp_normalize_path( (string) $handler->folder( UpdateTypes::id_of( $p['items'][ $i ] ) )['path'] ) );
			$paths[] = $path;

			if ( is_dir( $path ) && $listable && function_exists( 'wp_opcache_invalidate_directory' ) ) {
				wp_opcache_invalidate_directory( $path );
			} elseif ( function_exists( 'wp_opcache_invalidate' ) ) {
				wp_opcache_invalidate( $path, true );
			}
		}

		SafeUpdates::refresh_on_check( $paths );

		if ( ! SafeUpdates::guard_installed() ) {
			sleep( 3 );
		}
	}

	/**
	 * WordPress's .maintenance file left by an update request that was cut
	 * off. This request only runs once WordPress considers it expired.
	 */
	private static function end_maintenance_mode(): void {
		global $wp_filesystem;

		$file = ABSPATH . '.maintenance';

		if ( file_exists( $file ) && SafeUpdates::filesystem() && $wp_filesystem ) {
			$wp_filesystem->delete( trailingslashit( $wp_filesystem->abspath() ) . '.maintenance' );
		}
	}

	private static function prepare(): void {
		SafeUpdates::load_upgrader();

		// Both can be disabled on a host (disable_functions); calling one then is a fatal error on PHP 8.
		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( 300 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.PHP.DiscouragedPHPFunctions.runtime_configuration_set_time_limit, Squiz.PHP.DiscouragedFunctions.Discouraged -- an update and its check need more than 30 seconds on slow hosts.
		}

		if ( function_exists( 'ignore_user_abort' ) ) {
			ignore_user_abort( true );
		}

		wp_raise_memory_limit( 'admin' );
	}

	/**
	 * Count a step starting at the run's current position. The position is
	 * everything a step that gets further changes: the run's saved data and
	 * progress text (an add-on's check reports its progress there), and the
	 * state of the jobs it works through (the backup before the updates, an
	 * own copy's restore). A step that returns resets the count (process()).
	 *
	 * @return int How many steps in a row started at this position.
	 */
	private static function note_step_start( SafeUpdateJob $job ): int {
		$payload = $job->payload();
		$own     = JobsRepository::find( $job->id() );
		$ids     = array( (int) ( $payload['backup_job'] ?? 0 ) );

		foreach ( (array) ( $payload['items'] ?? array() ) as $item ) {
			$ids[] = (int) ( ( (array) ( $item['point'] ?? array() ) )['restore'] ?? 0 );
		}

		$jobs = array();

		foreach ( array_unique( array_filter( $ids ) ) as $id ) {
			$row = JobsRepository::find( $id );

			if ( null !== $row ) {
				$jobs[ $id ] = array( $row['status'] ?? '', $row['progress'] ?? '', $row['current_step'] ?? '', $row['payload'] ?? '', $row['result'] ?? '' );
			}
		}

		$where = md5( (string) wp_json_encode( array( $payload, (string) ( $own['current_step'] ?? '' ), $jobs ) ) );
		$seen  = get_option( self::CUT_OFF_OPTION, array() );
		$count = is_array( $seen ) && (int) ( $seen['run'] ?? 0 ) === $job->id() && (string) ( $seen['where'] ?? '' ) === $where ? (int) ( $seen['count'] ?? 0 ) + 1 : 1;

		update_option(
			self::CUT_OFF_OPTION,
			array(
				'run'   => $job->id(),
				'where' => $where,
				'count' => $count,
			),
			false
		);

		return $count;
	}

	/**
	 * The run's items for a stop after the safety stop itself was cut off.
	 * An update being put back isn't put back again: as when a rollback
	 * fails, the last resort keeps the site working (plugins: deactivated),
	 * and the saved copy stays on disk until saved copies expire. An own copy
	 * part-way back (WordPress core in Wrensafe Pro) is left as it is: when
	 * finish() runs next, it finishes or undoes the part done without starting
	 * it over (the last stop step marks it failed instead).
	 */
	private static function without_rollback( SafeUpdateJob $job ): array {
		$p = self::normalize( $job->payload() );

		foreach ( $p['items'] as $i => $item ) {
			if ( 'rolling_back' !== $item['state'] || ( null !== self::own_point( $item ) && ! empty( $item['point']['done'] ) ) ) {
				continue;
			}

			$handler = UpdateTypes::get( UpdateTypes::key_of( $item ) );

			if ( 'timeout' === ( $item['why'] ?? '' ) ) {
				// Only slow pages: no proof the update broke anything, so nothing is switched off.
				$done = __( 'It was left as it is: the site only answered too slowly, which doesn’t show the update broke it.', 'wrensafe' );
			} else {
				try {
					$done = null !== $handler ? $handler->last_resort( $item, $job->id() ) : '';
				} catch ( \Throwable $e ) {
					$done = '';
				}
			}

			unset( $p['items'][ $i ]['why'] );

			$p['items'][ $i ]['state']   = 'failed';
			$p['items'][ $i ]['message'] = trim( __( 'The run stopped while the previous version was being put back: that was cut off again and again.', 'wrensafe' ) . ' ' . $done . ' ' . __( 'Install the previous version again by hand.', 'wrensafe' ) );
			$p['items'][ $i ]['point']   = array();

			Logger::warning( 'Safe Updates: ' . self::item_label( $item ) . ' was not put back: the rollback was cut off again and again. ' . $done, array(), $job->id() );
		}

		return $p;
	}

	/**
	 * While a step runs: after each HTTP request (a download, a site check),
	 * up to the longest a step may take, so a step that hangs doesn't keep
	 * the run locked.
	 */
	public static function keep_lock(): void {
		if ( microtime( true ) < self::$deadline + self::FINISH_CHECK_SECONDS + self::ROLLBACK_SECONDS ) {
			self::renew_lock();
		} elseif ( '' !== self::$lock && ! self::holds_lock() ) {
			// Past that, the lock isn't renewed, but a takeover is still noticed.
			self::$lock      = '';
			self::$lock_lost = true;
		}
	}

	/**
	 * Whether a restore other than the given ones is under way: running and
	 * active in the last three minutes (a running restore updates its job
	 * every few seconds). Only a running restore holds the restore lock, the
	 * maintenance page, the boot guard and work tables; upload and import
	 * previews stay "pending" for good, and an older running job was cut off.
	 *
	 * @param int[] $own The run's own restore job ids.
	 */
	private static function other_restore_active( array $own ): bool {
		$since = gmdate( 'Y-m-d H:i:s', strtotime( current_time( 'mysql' ) ) - 3 * MINUTE_IN_SECONDS );

		foreach ( JobsRepository::list_by_type( 'restore', 50 ) as $row ) {
			if ( 'running' === (string) $row['status'] && (string) ( $row['updated_at'] ?? '' ) >= $since && ! in_array( (int) $row['id'], $own, true ) ) {
				return true;
			}
		}

		return false;
	}

	/** The lock row still holds this request's value. */
	private static function holds_lock(): bool {
		global $wpdb;

		return (string) $wpdb->get_var( $wpdb->prepare( 'SELECT option_value FROM %i WHERE option_name = %s', $wpdb->options, self::LOCK_OPTION ) ) === self::$lock;
	}

	/**
	 * While a step runs: before a package download (upgrader_pre_download).
	 *
	 * @param mixed $reply Passed on unchanged.
	 * @return mixed
	 */
	public static function keep_lock_filter( $reply ) {
		self::keep_lock();

		return $reply;
	}

	/**
	 * A long step (a check, a rollback, or a WP-Cron step finishing its
	 * checks) keeps the lock: its expiry moves forward, only while this
	 * request still holds it, and PHP gets its time again.
	 *
	 * @return bool False when another request has taken the lock over.
	 */
	private static function renew_lock(): bool {
		global $wpdb;

		if ( '' === self::$lock || false === strpos( self::$lock, '|' ) ) {
			return true;
		}

		$value = ( time() + self::LOCK_SECONDS ) . substr( self::$lock, strpos( self::$lock, '|' ) );

		if ( $wpdb->query( $wpdb->prepare( 'UPDATE %i SET option_value = %s WHERE option_name = %s AND option_value = %s', $wpdb->options, $value, self::LOCK_OPTION, self::$lock ) ) ) {
			self::$lock = $value;
			wp_cache_delete( self::LOCK_OPTION, 'options' );
		} elseif ( ! self::holds_lock() ) {
			// Not changed and not ours any more. A renewal in the same second as the
			// last one changes nothing either (MySQL counts no changed row), so the
			// row itself says whether the lock is still this request's.
			self::$lock      = '';
			self::$lock_lost = true;

			return false;
		}

		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( 300 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.PHP.DiscouragedPHPFunctions.runtime_configuration_set_time_limit, Squiz.PHP.DiscouragedFunctions.Discouraged -- as in prepare(): a step and its checks need time on slow hosts.
		}

		return true;
	}

	/**
	 * Take the run's lock ("expiry|token"). It is released when the step
	 * ends, and also on shutdown, so a request that dies (an update that
	 * exits, a fatal error, the time limit) doesn't hold up the next one;
	 * only a request killed outright keeps it until it expires.
	 *
	 * Written with queries rather than add_option()/update_option(): the
	 * INSERT IGNORE and the compare-and-swap UPDATE are what make sure only
	 * one request gets the lock, and they must bypass the options cache.
	 */
	private static function lock(): bool {
		global $wpdb;

		$now   = time();
		$value = ( $now + self::LOCK_SECONDS ) . '|' . wp_generate_password( 12, false );
		$taken = (bool) $wpdb->query(
			$wpdb->prepare(
				'INSERT IGNORE INTO %i (option_name, option_value, autoload) VALUES (%s, %s, %s)',
				$wpdb->options,
				self::LOCK_OPTION,
				$value,
				'no'
			)
		);

		if ( ! $taken ) {
			$held = (string) $wpdb->get_var( $wpdb->prepare( 'SELECT option_value FROM %i WHERE option_name = %s', $wpdb->options, self::LOCK_OPTION ) );

			if ( (int) $held > $now ) {
				return false;
			}

			// The request holding it was cut off: take it over (only one request can).
			$taken = (bool) $wpdb->query(
				$wpdb->prepare(
					'UPDATE %i SET option_value = %s WHERE option_name = %s AND option_value = %s',
					$wpdb->options,
					$value,
					self::LOCK_OPTION,
					$held
				)
			);
		}

		if ( $taken ) {
			self::$lock      = $value;
			self::$lock_lost = false;

			if ( ! self::$shutdown_registered ) {
				self::$shutdown_registered = true;
				register_shutdown_function( array( self::class, 'unlock' ) );
			}
		}

		return $taken;
	}

	/** Release the lock this request holds (also called on shutdown). */
	public static function unlock(): void {
		global $wpdb;

		if ( '' === self::$lock ) {
			return;
		}

		$wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE option_name = %s AND option_value = %s', $wpdb->options, self::LOCK_OPTION, self::$lock ) );
		wp_cache_delete( self::LOCK_OPTION, 'options' );

		self::$lock = '';
	}
}
