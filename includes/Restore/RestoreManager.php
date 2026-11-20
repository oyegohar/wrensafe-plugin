<?php
/**
 * Drives a RestoreJob through its phases, one bounded step per call —
 * mirrors BackupManager's timeout-safe, chunked design.
 *
 * Phases: init (preflight) -> rollback_point -> maintenance_on ->
 * restore_files -> restore_db (staging) -> swap_db -> search_replace ->
 * finalize. Files are restored BEFORE the database goes live (Issue 10) so
 * the source's active_plugins can never reference files that aren't on disk
 * yet — the cause of the half-restored-WooCommerce fatal.
 *
 * @package WPBP\Restore
 */

namespace WPBP\Restore;

use WPBP\Backup\BackupEncryption;
use WPBP\Backup\BackupJob;
use WPBP\Backup\BackupManager;
use WPBP\Backup\IntegrityChecker;
use WPBP\Core\Database\BackupsRepository;
use WPBP\Core\Database\JobsRepository;
use WPBP\Core\Database\RestorePointsRepository;
use WPBP\Security\PathValidator;
use WPBP\Utils\Errors;
use WPBP\Utils\FileSystem;
use WPBP\Utils\Logger;
use WPBP\Utils\Paths;
use WPBP\Utils\SharedDatabase;
use WPBP\Utils\Str;
use WPBP\WooCommerce\CheckoutMaintenance;
use WPBP\WooCommerce\OrderSync;
use WPBP\WooCommerce\WooSafeMode;

defined( 'ABSPATH' ) || exit;

// Database access in this file is direct by design: Wrensafe's own tables (or,
// in the backup/restore engine, the site's tables being exported, imported or
// swapped) have no WordPress API, and the data must be read fresh, so it is not
// object-cached. Every query goes through $wpdb->prepare(), with table and
// column names as identifier placeholders (%i).
// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange

// Archive entries and restore work files are streamed with native file handles
// (fopen/fread/fwrite/rename): WP_Filesystem has no streaming API. Every path is
// inside Wrensafe's protected storage directory or a destination resolved and
// validated by FileRestorer::resolve_destination().
// phpcs:disable WordPress.WP.AlternativeFunctions


class RestoreManager {

	/**
	 * Marks "a restore is currently running" — set once a job starts and
	 * released on completion, failure, or by the admin cleanup action. Lets
	 * a stuck job (e.g. killed by a PHP fatal/timeout that no try/catch can
	 * intercept) be detected and recovered instead of leaving maintenance
	 * mode on indefinitely.
	 */
	const RESTORE_LOCK_TRANSIENT = 'wpbp_restore_lock';

	/**
	 * Holds the job id a user has asked to cancel. Honored only at a safe
	 * checkpoint before the atomic database swap — never mid-SQL or mid-file —
	 * so a cancel can never corrupt a restore.
	 */
	const CANCEL_TRANSIENT = 'wpbp_restore_cancel';

	/**
	 * Phases where cancelling is fully safe and clean: no files have been
	 * restored and the live database is untouched. Since Issue 10 files are
	 * restored BEFORE the database, so once 'restore_files' starts the site
	 * already carries restored files and a clean no-changes cancel is no
	 * longer possible — past maintenance_on the restore runs to completion
	 * or fails with recovery.
	 */
	private const SAFE_CANCEL_PHASES = array( 'init', 'decrypt', 'verify', 'rollback_point', 'maintenance_on' );

	public static function request_cancel( int $job_id ): void {
		set_transient( self::CANCEL_TRANSIENT, $job_id, HOUR_IN_SECONDS );
	}

	public static function is_cancel_requested( int $job_id ): bool {
		return (int) get_transient( self::CANCEL_TRANSIENT ) === $job_id;
	}

	private function clear_cancel(): void {
		delete_transient( self::CANCEL_TRANSIENT );
	}

	/**
	 * Suffix for the staging tables a restore imports into before swapping
	 * them live. The database restore never touches the real tables until
	 * every staging table is fully built, then swaps them all in with a
	 * single atomic RENAME TABLE — so the live site (and the restore's own
	 * chunked admin-ajax polling, which depends on wp_options / wp_users /
	 * wp_usermeta to boot and authenticate each request) keeps working
	 * throughout the import instead of being half-destroyed mid-restore.
	 */
	const TEMP_TABLE_SUFFIX = '_wpbptmp';

	/**
	 * Suffix the previous live table is renamed to during the atomic swap,
	 * then dropped once the swap succeeds.
	 */
	const OLD_TABLE_SUFFIX = '_wpbpold';

	/**
	 * Wall-clock deadline for one bounded step, leaving head-room under a
	 * short max_execution_time (same budget as the backup engine).
	 */
	private function entries_list_path( int $job_id ): string {
		return $this->tmp_dir_for_job( $job_id ) . 'restore-entries.txt';
	}

	private static function step_deadline(): float {
		$max = (int) ini_get( 'max_execution_time' );

		return microtime( true ) + ( ( $max > 0 && $max < 20 ) ? max( 4, $max - 6 ) : 10 );
	}

	public function tmp_dir_for_job( int $job_id ): string {
		return FileSystem::job_tmp_dir( $job_id );
	}

	/**
	 * Staging table name for a destination table, kept within MySQL's 64-char
	 * identifier limit (hashing the base if it would overflow with the suffix).
	 */
	private function temp_table_name( string $dest_table ): string {
		$candidate = $dest_table . self::TEMP_TABLE_SUFFIX;

		if ( strlen( $candidate ) <= 64 ) {
			return $candidate;
		}

		return substr( $dest_table, 0, 64 - strlen( self::TEMP_TABLE_SUFFIX ) - 9 )
			. '_' . substr( md5( $dest_table ), 0, 8 )
			. self::TEMP_TABLE_SUFFIX;
	}

	/**
	 * Name the live table is renamed to during the atomic swap (dropped once
	 * the swap succeeds). Mirrors temp_table_name()'s 64-char safety.
	 */
	private function old_table_name( string $dest_table ): string {
		$candidate = $dest_table . self::OLD_TABLE_SUFFIX;

		if ( strlen( $candidate ) <= 64 ) {
			return $candidate;
		}

		return substr( $dest_table, 0, 64 - strlen( self::OLD_TABLE_SUFFIX ) - 9 )
			. '_' . substr( md5( $dest_table ), 0, 8 )
			. self::OLD_TABLE_SUFFIX;
	}

	/**
	 * Drop any leftover restore staging/old tables from a previous run or an
	 * interrupted restore, so the live schema is never littered with them.
	 */
	private function drop_restore_staging_tables(): void {
		global $wpdb;

		$wpdb->query( 'SET FOREIGN_KEY_CHECKS = 0' ); // Staging tables may reference each other.

		foreach ( self::leftover_work_tables() as $table ) {
			$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', $table ) );
		}
	}

	/**
	 * This site's leftover restore tables: {prefix}…_wpbptmp and
	 * {prefix}…_wpbpold (temp_table_name() and old_table_name() keep this
	 * site's prefix at the start of every name).
	 *
	 * Another WordPress site can share the database with a longer prefix
	 * that starts with this one (wp_ and wp_shop_, or a staging copy's
	 * wp_stg_…_), and run a restore of its own. Its tables match the pattern
	 * too, so a table is left alone when it starts with such a prefix (one
	 * with both an options and a posts table that isn't this site's, see
	 * SharedDatabase — the same rule the backup's table list uses).
	 *
	 * @return string[]
	 */
	public static function leftover_work_tables(): array {
		global $wpdb;

		$prefix = $wpdb->prefix;
		$all    = array_map( 'strval', (array) $wpdb->get_col( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $prefix ) . '%' ) ) );
		$others = SharedDatabase::other_install_prefixes( $all, $prefix, SharedDatabase::subsite_base() );
		$found  = array();

		foreach ( $all as $table ) {
			if ( ! Str::ends_with( $table, self::TEMP_TABLE_SUFFIX ) && ! Str::ends_with( $table, self::OLD_TABLE_SUFFIX ) ) {
				continue;
			}

			if ( ! SharedDatabase::belongs_to_other( $table, $others ) ) {
				$found[] = $table;
			}
		}

		return $found;
	}

	/**
	 * Issue the job's one-time "resume key" (returned once to the browser that
	 * runs the restore; only its SHA-256 hash is stored, in the job row).
	 *
	 * A database restore replaces wp_users/wp_usermeta, which invalidates the
	 * operator's auth cookie (it is bound to the user's login and password
	 * hash) — every later step request would then be rejected and the restore
	 * would stall right after the swap, before URL replacement and cleanup.
	 * The jobs table is never part of a restore, so this key keeps the browser
	 * that started the job able to finish it. Returns '' if one was already
	 * issued (it is never re-issued or re-sent).
	 */
	public static function issue_resume_key( RestoreJob $job ): string {
		$payload = $job->payload();

		if ( ! empty( $payload['resume_key_hash'] ) ) {
			return '';
		}

		$key                        = bin2hex( random_bytes( 32 ) );
		$payload['resume_key_hash'] = hash( 'sha256', $key );
		$job->set_payload( $payload );

		return $key;
	}

	/**
	 * Whether $key is the resume key of a restore job that is still in
	 * progress. Terminal jobs never accept their key again.
	 */
	public static function verify_resume_key( int $job_id, string $key ): bool {
		if ( $job_id <= 0 || 64 !== strlen( $key ) || ! ctype_xdigit( $key ) ) {
			return false;
		}

		$job = RestoreJob::get( $job_id );

		if ( null === $job || ! in_array( $job->status(), array( RestoreJob::STATUS_PENDING, RestoreJob::STATUS_RUNNING ), true ) ) {
			return false;
		}

		$hash = (string) ( $job->payload()['resume_key_hash'] ?? '' );

		return '' !== $hash && hash_equals( $hash, hash( 'sha256', $key ) );
	}

	public static function is_locked(): bool {
		return false !== get_transient( self::RESTORE_LOCK_TRANSIENT );
	}

	public static function locked_job_id(): int {
		return (int) get_transient( self::RESTORE_LOCK_TRANSIENT );
	}

	private function acquire_restore_lock( int $job_id ): void {
		set_transient( self::RESTORE_LOCK_TRANSIENT, $job_id, HOUR_IN_SECONDS );
	}

	private function release_restore_lock( int $job_id ): void {
		delete_transient( self::RESTORE_LOCK_TRANSIENT );
		delete_transient( self::CANCEL_TRANSIENT );
		Logger::info( 'Restore job #' . $job_id . ' restore lock released.', array(), $job_id );
	}

	/**
	 * Emergency recovery: clears WordPress core maintenance mode (defensive
	 * only — Wrensafe itself no longer writes that file, see
	 * RestoreSiteMaintenance), Wrensafe's own site-wide and
	 * checkout-only maintenance state, the restore lock, and marks any
	 * restore job stuck in pending/running as failed. Used by the admin
	 * "Clear Restore Lock / Disable Maintenance Mode" action and by the
	 * fatal-error shutdown guard in RestoreAjax::process().
	 *
	 * @return array{cleared_maintenance:bool,cleared_site_maintenance:bool,cleared_checkout_maintenance:bool,cleared_lock:bool,failed_jobs:int[]}
	 */
	public static function clear_restore_locks_and_maintenance(): array {
		$maintenance_file    = Paths::root() . '/.maintenance';
		$cleared_maintenance = file_exists( $maintenance_file );

		if ( $cleared_maintenance ) {
			wp_delete_file( $maintenance_file );
		}

		// Remove any staging tables an interrupted restore left behind. The
		// live tables are never altered until the atomic swap succeeds, so
		// this is always safe.
		( new self() )->drop_restore_staging_tables();

		// Deactivate any active plugin whose files are missing/incomplete and
		// remove the temporary boot guard an interrupted restore left behind —
		// this is the admin recovery path for a site crashed mid-restore.
		PluginSafety::filter_active_plugins( null );
		PluginSafety::remove_boot_guard();

		$cleared_site_maintenance = RestoreSiteMaintenance::is_enabled();
		RestoreSiteMaintenance::disable();

		$checkout_maintenance         = new CheckoutMaintenance();
		$cleared_checkout_maintenance = $checkout_maintenance->is_enabled();
		$checkout_maintenance->disable();

		$cleared_lock = self::is_locked();
		delete_transient( self::RESTORE_LOCK_TRANSIENT );
		delete_transient( self::CANCEL_TRANSIENT );

		$failed_jobs = array();

		foreach ( JobsRepository::list_by_type( 'restore', 50 ) as $row ) {
			if ( ! in_array( $row['status'], array( RestoreJob::STATUS_PENDING, RestoreJob::STATUS_RUNNING ), true ) ) {
				continue;
			}

			$job = RestoreJob::get( (int) $row['id'] );

			if ( null === $job ) {
				continue;
			}

			$job->mark_failed( __( 'Restore was interrupted and cleared by an administrator.', 'wrensafe' ) );
			$failed_jobs[] = $job->id();
		}

		Logger::warning(
			sprintf(
				'Restore lock / maintenance cleanup run (core maintenance file cleared: %s, site maintenance cleared: %s, checkout maintenance cleared: %s, restore lock cleared: %s, jobs marked failed: %s).',
				$cleared_maintenance ? 'yes' : 'no',
				$cleared_site_maintenance ? 'yes' : 'no',
				$cleared_checkout_maintenance ? 'yes' : 'no',
				$cleared_lock ? 'yes' : 'no',
				$failed_jobs ? implode( ', ', $failed_jobs ) : 'none'
			)
		);

		return array(
			'cleared_maintenance'          => $cleared_maintenance,
			'cleared_site_maintenance'     => $cleared_site_maintenance,
			'cleared_checkout_maintenance' => $cleared_checkout_maintenance,
			'cleared_lock'                 => $cleared_lock,
			'failed_jobs'                  => $failed_jobs,
		);
	}

	/**
	 * $encryption_password is never persisted — it only exists in memory
	 * for this single request, used solely to decrypt an encrypted archive
	 * during the init step. Once decrypted, the plain copy in the job's
	 * tmp dir is used for every later step, so the password is never
	 * needed (or stored) again.
	 */
	public function process_step( RestoreJob $job, string $encryption_password = '' ): void {
		$payload = $job->payload();
		$phase   = $payload['phase'] ?? 'init';

		// Extra head-room where the host allows it; every phase still stops
		// at its own deadline, so hosts that ignore this stay safe.
		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( 0 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.PHP.DiscouragedPHPFunctions.runtime_configuration_set_time_limit, Squiz.PHP.DiscouragedFunctions.Discouraged -- see above.
		}

		// Honor a cancel request only at a safe checkpoint — before the atomic
		// database swap, where the live database is still untouched (the import
		// wrote to staging tables) and no files have been restored. Past that
		// point the restore is already applied, so we let it finish rather than
		// leave the site half-restored.
		if ( self::is_cancel_requested( $job->id() ) && in_array( $phase, self::SAFE_CANCEL_PHASES, true ) ) {
			$this->disable_maintenance_mode();
			( new CheckoutMaintenance() )->disable();
			PluginSafety::remove_boot_guard();
			$this->drop_restore_staging_tables();
			$this->release_restore_lock( $job->id() );
			$this->clear_cancel();
			Logger::warning( 'Restore job #' . $job->id() . ' cancelled by user at a safe checkpoint; no changes were applied.', array(), $job->id() );
			$job->cancel();
			return;
		}

		try {
			switch ( $phase ) {
				case 'init':
					$this->step_init( $job, $payload, $encryption_password );
					break;

				case 'decrypt':
					$this->step_decrypt( $job, $payload, $encryption_password );
					break;

				case 'verify':
					$this->step_verify( $job, $payload );
					break;

				case 'rollback_point':
					$this->step_rollback_point( $job, $payload );
					break;

				case 'maintenance_on':
					$this->step_maintenance_on( $job, $payload );
					break;

				case 'restore_db':
					$this->step_restore_db( $job, $payload );
					break;

				case 'swap_db':
					$this->step_swap_db( $job, $payload );
					break;

				case 'restore_files':
					$this->step_restore_files( $job, $payload );
					break;

				case 'search_replace':
					$this->step_search_replace( $job, $payload );
					break;

				case 'finalize':
					$this->step_finalize( $job, $payload );
					break;

				case 'health_check':
					$this->step_health_check( $job, $payload );
					break;

				default:
					$job->mark_failed( __( 'Unknown restore phase.', 'wrensafe' ) );
			}
		} catch ( \Throwable $e ) {
			Logger::failed(
				sprintf( 'restore_failed: restore job #%d failed: %s (in %s:%d)', $job->id(), Errors::text( $e ), $e->getFile(), $e->getLine() ),
				array(),
				$job->id()
			);
			Logger::warning( 'restore_failure_recovery_started: disabling maintenance, restoring destination URLs, and checking plugin safety.', array(), $job->id() );
			$this->disable_maintenance_mode();
			( new CheckoutMaintenance() )->disable();
			// If the failure happened AFTER the database swap went live, the
			// options table may hold the source site's URLs — force home/siteurl
			// back to the destination captured before the restore (Issue 08).
			$this->attempt_domain_recovery( $job );
			// Deactivate any active plugin whose files are now missing/partial
			// (e.g. a file restore that died mid-plugin), so the site cannot be
			// left fataling on a half-restored plugin (Issue 10). The boot guard
			// is removed only after this permanent filter is applied.
			PluginSafety::filter_active_plugins( $job->id() );
			PluginSafety::remove_boot_guard();
			// The live tables were never touched (the import wrote only into
			// staging tables), so the site is intact — just clear the leftover
			// staging tables this failed run created.
			$this->drop_restore_staging_tables();
			Logger::info( 'restore_failure_recovery_completed: maintenance disabled, staging tables cleared, destination URLs and plugin safety enforced.', array(), $job->id() );
			$job->mark_failed( Errors::text( $e ) );
			$this->release_restore_lock( $job->id() );
		}
	}

	private function resolve_archive_path( array $payload ): ?string {
		if ( ! empty( $payload['uploaded_file'] ) && file_exists( $payload['uploaded_file'] ) ) {
			return $payload['uploaded_file'];
		}

		if ( ! empty( $payload['backup_id'] ) ) {
			$backup = BackupsRepository::find( (int) $payload['backup_id'] );
			if ( $backup && file_exists( $backup['file_path'] ) ) {
				return $backup['file_path'];
			}
		}

		return null;
	}

	/**
	 * Log a restore failure and mark the job failed in one place, so every
	 * early-return failure path in step_init (invalid archive, bad
	 * password, failed integrity check, …) is as visible in Activity Logs
	 * as the catch-all in process_step().
	 */
	private function fail( RestoreJob $job, string $message ): void {
		if ( ! empty( $job->payload()['uploaded_file'] ) ) {
			Logger::error( 'upload_restore_failed: ' . $message, array(), $job->id() );
		}

		Logger::failed( 'restore_failed: restore job #' . $job->id() . ' failed: ' . $message, array(), $job->id() );
		$job->mark_failed( $message );
	}

	/**
	 * A preflight failure: nothing on the site has been touched — no files,
	 * no database, no active_plugins, no home/siteurl. Logged distinctly so
	 * "Preparing restore failed" always has an exact, findable reason.
	 */
	private function fail_preflight( RestoreJob $job, string $message ): void {
		Logger::failed( 'restore_preflight_failed: ' . $message . ' (no changes were made to this site).', array(), $job->id() );
		$this->fail( $job, $message );
	}

	private function step_init( RestoreJob $job, array $payload, string $encryption_password = '' ): void {
		Logger::info( 'Restore request received for job #' . $job->id() . '.', array(), $job->id() );

		if ( ! empty( $payload['uploaded_file'] ) ) {
			Logger::info( 'upload_restore_started: restoring from an uploaded .wbp archive (' . basename( (string) $payload['uploaded_file'] ) . ').', array(), $job->id() );
		}

		if ( ! empty( $payload['backup_id'] ) ) {
			Logger::info( 'Restore job #' . $job->id() . ' selected backup ID: ' . $payload['backup_id'] . '.', array(), $job->id() );
		}

		Logger::info( 'Restore job #' . $job->id() . ' restore mode selected: ' . ( $payload['restore_mode'] ?? 'full' ) . '.', array(), $job->id() );
		Logger::info( 'restore_preflight_started: validating archive, restore mode, and destination before touching anything.', array(), $job->id() );

		$archive_path = $this->resolve_archive_path( $payload );

		if ( null === $archive_path ) {
			$this->fail_preflight( $job, __( 'Backup archive could not be located.', 'wrensafe' ) );
			return;
		}

		if ( ! is_readable( $archive_path ) ) {
			$this->fail_preflight( $job, __( 'The backup archive exists but is not readable (check file permissions).', 'wrensafe' ) );
			return;
		}

		Logger::info( 'Restore job #' . $job->id() . ' selected backup file path: ' . $archive_path . '.', array(), $job->id() );

		$tmp_dir = $this->tmp_dir_for_job( $job->id() );
		FileSystem::ensure_protected_directory( $tmp_dir );

		if ( ! is_dir( $tmp_dir ) || ! wp_is_writable( $tmp_dir ) ) {
			$this->fail_preflight( $job, __( 'The temporary restore directory is not writable (check file permissions / open_basedir).', 'wrensafe' ) );
			return;
		}

		// Disk space: extraction + staging needs room. Only enforced when the
		// host exposes disk_free_space.
		clearstatcache( true, $archive_path );
		$archive_size = (int) filesize( $archive_path );
		$disk_free    = function_exists( 'disk_free_space' ) ? @disk_free_space( $tmp_dir ) : false; // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged

		if ( false !== $disk_free && $disk_free < ( $archive_size * 1.5 ) + 52428800 ) {
			$this->fail_preflight(
				$job,
				sprintf(
					/* translators: 1: free disk space, 2: archive size */
					__( 'Not enough free disk space to safely restore (free: %1$s, archive: %2$s). Free up space and try again.', 'wrensafe' ),
					size_format( (int) $disk_free ),
					size_format( $archive_size )
				)
			);
			return;
		}

		if ( ( new BackupEncryption() )->is_encrypted( $archive_path ) ) {
			if ( '' === $encryption_password ) {
				$this->fail_preflight( $job, __( 'This backup is encrypted. Please provide its password to restore it.', 'wrensafe' ) );
				return;
			}

			// Decrypt in its own resumable phase (a multi-GB archive can't be
			// decrypted inside one request).
			$payload['preflight_archive'] = $archive_path;
			$payload['dec_state']         = array();
			$payload['phase']             = 'decrypt';
			$job->set_payload( $payload );
			$job->mark_running();
			$job->update_progress( 1, __( 'Decrypting backup…', 'wrensafe' ) );
			return;
		}

		$this->begin_preflight_verify( $job, $payload, $archive_path );
	}

	/**
	 * Phase 'decrypt': decrypt the archive into the job's tmp dir in
	 * time-budgeted steps. The password is sent with every request and is
	 * never stored.
	 */
	private function step_decrypt( RestoreJob $job, array $payload, string $encryption_password ): void {
		if ( '' === $encryption_password ) {
			$this->fail_preflight( $job, __( 'This backup is encrypted. Please provide its password to restore it.', 'wrensafe' ) );
			return;
		}

		$source    = (string) $payload['preflight_archive'];
		$decrypted = $this->tmp_dir_for_job( $job->id() ) . 'decrypted.wbp';
		$state     = is_array( $payload['dec_state'] ?? null ) ? $payload['dec_state'] : array();

		try {
			$done = ( new BackupEncryption() )->decrypt_step( $source, $encryption_password, $decrypted . '.part', $state, self::step_deadline() );
		} catch ( \RuntimeException $e ) {
			if ( file_exists( $decrypted . '.part' ) ) {
				wp_delete_file( $decrypted . '.part' );
			}
			$this->fail_preflight( $job, Errors::text( $e ) );
			return;
		}

		if ( ! $done ) {
			$payload['dec_state'] = $state;
			$job->set_payload( $payload );

			$size = max( 1, (int) @filesize( $source ) ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			$job->update_progress(
				1,
				sprintf(
					/* translators: %d: percent decrypted */
					__( 'Decrypting backup… %d%%', 'wrensafe' ),
					min( 99, (int) ( (int) ( $state['in'] ?? 0 ) * 100 / $size ) )
				)
			);
			return;
		}

		if ( ! rename( $decrypted . '.part', $decrypted ) ) {
			$this->fail_preflight( $job, __( 'The decrypted copy could not be moved into place (check the storage folder permissions).', 'wrensafe' ) );
			return;
		}

		unset( $payload['dec_state'] );
		Logger::info( 'Restore job #' . $job->id() . ' archive decrypted.', array(), $job->id() );

		$this->begin_preflight_verify( $job, $payload, $decrypted );
	}

	/**
	 * Open and structurally validate the archive, then hand off to the
	 * resumable integrity check.
	 */
	private function begin_preflight_verify( RestoreJob $job, array $payload, string $archive_path ): void {
		Logger::info( 'Restore job #' . $job->id() . ' archive opening started.', array(), $job->id() );
		$reader = new ArchiveReader( $archive_path );
		Logger::info( 'Restore job #' . $job->id() . ' archive opening completed.', array(), $job->id() );

		Logger::info( 'Restore job #' . $job->id() . ' archive validation started.', array(), $job->id() );
		$result = $reader->validate();

		if ( ! $result['valid'] ) {
			$this->fail_preflight( $job, $result['message'] );
			return;
		}

		Logger::info( 'Restore job #' . $job->id() . ' archive validation completed.', array(), $job->id() );

		// Verify the archive's internal checksums before anything
		// irreversible happens — no rollback point, no maintenance mode, no
		// database or file restore — so a corrupted or tampered archive is
		// rejected outright instead of partially applied.
		Logger::info( 'Restore job #' . $job->id() . ' integrity verification started.', array(), $job->id() );

		$payload['preflight_archive'] = $archive_path;
		$payload['verify_cursor']     = array();
		$payload['phase']             = 'verify';

		$job->set_payload( $payload );
		$job->mark_running();
		$job->update_progress( 1, __( 'Checking the backup before restoring…', 'wrensafe' ) );
	}

	/**
	 * Phase 'verify': checksum every archive entry in time-budgeted steps,
	 * then run the rest of the preflight.
	 */
	private function step_verify( RestoreJob $job, array $payload ): void {
		$archive_path = (string) $payload['preflight_archive'];
		$reader       = new ArchiveReader( $archive_path );
		$result       = $reader->validate();

		if ( ! $result['valid'] ) {
			$this->fail_preflight( $job, $result['message'] );
			return;
		}

		$cursor    = is_array( $payload['verify_cursor'] ?? null ) ? $payload['verify_cursor'] : array();
		$integrity = ( new IntegrityChecker() )->verify_archive_contents_step( $reader, $result['manifest'], $cursor, self::step_deadline() );

		if ( null === $integrity ) {
			$payload['verify_cursor'] = $cursor;
			$job->set_payload( $payload );

			$total = max( 1, (int) ( $cursor['files_total'] ?? 0 ) );
			$job->update_progress(
				1,
				'files' === ( $cursor['stage'] ?? '' ) && $total > 1
					? sprintf(
						/* translators: 1: files checked, 2: total files */
						__( 'Checking the backup before restoring… %1$s / %2$s files', 'wrensafe' ),
						number_format_i18n( (int) $cursor['file_index'] ),
						number_format_i18n( $total )
					)
					: __( 'Checking the backup before restoring…', 'wrensafe' )
			);
			return;
		}

		if ( ! $integrity['valid'] ) {
			$this->fail_preflight( $job, $integrity['message'] );
			return;
		}

		unset( $payload['verify_cursor'], $payload['preflight_archive'] );

		Logger::info( 'Restore job #' . $job->id() . ' integrity verification completed (' . (int) $integrity['checked_files'] . ' files, ' . (int) $integrity['checked_database_files'] . ' database files).', array(), $job->id() );

		$this->complete_preflight( $job, $payload, $archive_path, $result );
	}

	/**
	 * The rest of the preflight, once the archive is known to be intact:
	 * restore-mode compatibility, destination context, then start.
	 *
	 * @param array<string,mixed> $result ArchiveReader::validate() result.
	 */
	private function complete_preflight( RestoreJob $job, array $payload, string $archive_path, array $result ): void {
		$needs_db    = ! empty( $result['manifest']['contains_database'] );
		$needs_files = ! empty( $result['manifest']['contains_files'] );
		$root_filter = '';

		// Multisite database restores are not supported yet — block them with a
		// clear message instead of attempting an unsafe partial restore.
		if ( $needs_db && ( ! empty( $result['metadata']['is_multisite'] ) || is_multisite() ) ) {
			$this->fail_preflight( $job, __( 'Multisite database restore is not supported yet. This restore was blocked to protect your network.', 'wrensafe' ) );
			return;
		}

		// Restore mode must be compatible with what the backup actually
		// contains — a database restore of a files-only backup (or vice versa)
		// must fail HERE with the real reason, not half-run and "fail during
		// Preparing restore" with no explanation.
		$mode = $payload['restore_mode'] ?? 'full';

		if ( in_array( $mode, array( 'database', 'selected_tables' ), true ) && ! $needs_db ) {
			$this->fail_preflight( $job, __( 'This backup does not contain a database, so a database restore is not possible. Choose a files restore mode instead.', 'wrensafe' ) );
			return;
		}

		if ( in_array( $mode, array( 'files', 'uploads', 'themes', 'plugins', 'plugin_single', 'theme_single' ), true ) && ! $needs_files ) {
			$this->fail_preflight( $job, __( 'This backup does not contain files (it is database-only), so a files restore is not possible. Choose the Database restore mode instead.', 'wrensafe' ) );
			return;
		}

		if ( 'full' === $mode && ! $needs_db && ! $needs_files ) {
			$this->fail_preflight( $job, __( 'This backup contains neither database nor files and cannot be restored.', 'wrensafe' ) );
			return;
		}

		switch ( $payload['restore_mode'] ?? 'full' ) {
			case 'database':
			case 'selected_tables':
				$needs_files = false;
				break;

			case 'files':
				$needs_db = false;
				break;

			case 'uploads':
			case 'themes':
			case 'plugins':
				$needs_db    = false;
				$root_filter = $payload['restore_mode'];
				break;

			case 'plugin_single':
				$needs_db    = false;
				$root_filter = 'plugins';
				break;

			case 'theme_single':
				$needs_db    = false;
				$root_filter = 'themes';
				break;
		}

		// Incremental/differential backups only contain *changed* files, so
		// restoring one means layering the whole chain (baseline first, then
		// each subsequent link) — the database is never chained, since every
		// backup that includes it exports the full database on its own.
		$chain_paths = array();
		if ( $needs_files && ! empty( $payload['backup_id'] ) ) {
			$chain = BackupsRepository::get_chain( (int) $payload['backup_id'] );
			if ( count( $chain ) > 1 ) {
				$chain_paths = wp_list_pluck( $chain, 'file_path' );
			}
		}

		// WooCommerce Safe Mode: when enabled (default, if WooCommerce is
		// active) and the restore touches the database, protect live
		// order/customer-lookup tables by excluding them from the SQL replay.
		$woo_safe_mode = $needs_db && WooSafeMode::is_active() && ( $payload['woo_safe_mode'] ?? true );

		// Capture the DESTINATION site's URLs/paths NOW, before any database
		// change. After the atomic table swap, wp_options holds the SOURCE
		// site's values, so home_url()/site_url() would return the source
		// domain — reading them later is exactly the bug that made a cross-site
		// restore flip the destination onto the source domain (Issue 08).
		global $wpdb;
		$dest_uploads = wp_upload_dir();

		$payload['dest_home_url']        = untrailingslashit( home_url() );
		$payload['dest_site_url']        = untrailingslashit( site_url() );
		$payload['dest_uploads_baseurl'] = untrailingslashit( (string) ( $dest_uploads['baseurl'] ?? '' ) );
		$payload['dest_uploads_basedir'] = untrailingslashit( (string) ( $dest_uploads['basedir'] ?? '' ) );
		$payload['dest_abspath']         = Paths::root();
		// This server's backup folder (Settings → Backup) is not part of the
		// site's content: it is put back after the swap (force_destination_urls).
		$payload['dest_backup_dir']     = (string) \WPBP\Core\Settings::get( 'backup_dir', '' );
		$payload['dest_content_dir']    = Paths::content_dir();
		$payload['dest_table_prefix']   = $wpdb->prefix;
		$payload['source_table_prefix'] = (string) ( $result['metadata']['table_prefix'] ?? '' );

		// Source values recorded in the backup's metadata. Older archives only
		// carry site_url/table_prefix; the extra path/URL fields were added in
		// Issue 08 and are optional.
		$payload['source_site_url_option'] = untrailingslashit( (string) ( $result['metadata']['site_url_option'] ?? '' ) );
		$payload['source_uploads_baseurl'] = untrailingslashit( (string) ( $result['metadata']['uploads_baseurl'] ?? '' ) );
		$payload['source_uploads_basedir'] = untrailingslashit( (string) ( $result['metadata']['uploads_basedir'] ?? '' ) );
		$payload['source_abspath']         = untrailingslashit( (string) ( $result['metadata']['abspath'] ?? '' ) );
		$payload['source_content_dir']     = untrailingslashit( (string) ( $result['metadata']['content_dir'] ?? '' ) );
		$payload['source_plugins_dir']     = untrailingslashit( (string) ( $result['metadata']['plugins_dir'] ?? '' ) );
		$payload['source_mu_plugins_dir']  = untrailingslashit( (string) ( $result['metadata']['mu_plugins_dir'] ?? '' ) );
		$payload['source_themes_dir']      = untrailingslashit( (string) ( $result['metadata']['themes_dir'] ?? '' ) );
		$payload['db_swapped']             = false;

		$payload['archive_path'] = $archive_path;

		// Archives from 0.9.1-beta and earlier stored wpdb placeholder tokens
		// instead of "%" in their SQL dumps — repair them during import.
		$payload['legacy_sql_escapes'] = $needs_db && (int) ( $result['manifest']['sql_dump_version'] ?? 1 ) < 2;

		if ( $payload['legacy_sql_escapes'] ) {
			Logger::info( 'restore_legacy_sql_repair_enabled: this archive was created by an older Wrensafe version; "%" characters in its database dump will be repaired during import.', array(), $job->id() );
		}
		$payload['needs_db']      = $needs_db;
		$payload['needs_files']   = $needs_files;
		$payload['root_filter']   = $root_filter;
		$payload['site_url']      = $result['metadata']['site_url'] ?? '';
		$payload['chain_paths']   = $chain_paths;
		$payload['woo_safe_mode'] = $woo_safe_mode;

		// Snapshot the destination's own active_plugins so failure recovery can
		// reason about what was active BEFORE anything changed.
		$payload['dest_active_plugins'] = array_values( array_filter( (array) get_option( 'active_plugins', array() ), 'is_string' ) );

		Logger::info( 'restore_destination_context_captured: ' . $payload['dest_home_url'] . ' (prefix ' . $payload['dest_table_prefix'] . ', ' . count( $payload['dest_active_plugins'] ) . ' active plugins).', array(), $job->id() );
		Logger::info( 'restore_source_context_detected: ' . ( '' !== (string) $payload['site_url'] ? untrailingslashit( (string) $payload['site_url'] ) : '(unknown)' ) . ' (prefix ' . (string) ( $result['metadata']['table_prefix'] ?? '?' ) . ').', array(), $job->id() );
		Logger::info(
			'restore_source_destination_detected: source ' . ( '' !== (string) $payload['site_url'] ? untrailingslashit( (string) $payload['site_url'] ) : '(unknown)' )
				. ' → destination ' . $payload['dest_home_url']
				. ( untrailingslashit( (string) $payload['site_url'] ) === $payload['dest_home_url'] ? ' (same site — no URL replacement needed)' : ' (cross-site — URLs will be replaced)' ) . '.',
			array(),
			$job->id()
		);
		$payload['chain_index']  = 0;
		$payload['file_entries'] = array();
		$payload['file_index']   = 0;
		$payload['files_listed'] = false;
		$payload['db_extracted'] = false;
		$payload['db_offset']    = 0;
		$payload['phase']        = 'rollback_point';

		$job->set_payload( $payload );
		$job->mark_running();
		$job->update_progress( 2, __( 'Validating archive…', 'wrensafe' ) );

		$this->acquire_restore_lock( $job->id() );

		Logger::info( 'restore_preflight_passed: archive, restore mode, and destination all validated — nothing has been modified yet.', array(), $job->id() );
		Logger::info( 'Restore job #' . $job->id() . ' started.', array(), $job->id() );
	}

	private function step_rollback_point( RestoreJob $job, array $payload ): void {
		if ( empty( $payload['rollback_backup_job_id'] ) ) {
			Logger::info( 'Restore job #' . $job->id() . ' rollback creation started.', array(), $job->id() );

			$backup_job                        = BackupJob::create(
				array(
					'backup_type' => 'database',
					'note'        => 'Automatic rollback point before restore #' . $job->id(),
				)
			);
			$payload['rollback_backup_job_id'] = $backup_job->id();
			$job->set_payload( $payload );
			$job->update_progress( 5, __( 'Creating safety rollback point…', 'wrensafe' ) );
			return;
		}

		$backup_job = BackupJob::get( (int) $payload['rollback_backup_job_id'] );

		if ( null === $backup_job ) {
			$this->fail( $job, __( 'Could not create a rollback point.', 'wrensafe' ) );
			return;
		}

		if ( in_array( $backup_job->status(), array( BackupJob::STATUS_PENDING, BackupJob::STATUS_RUNNING ), true ) ) {
			( new BackupManager() )->process_step( $backup_job );
			$job->update_progress( 5 + (int) ( $backup_job->progress() * 0.1 ), __( 'Creating safety rollback point…', 'wrensafe' ) );
			return;
		}

		if ( BackupJob::STATUS_COMPLETED !== $backup_job->status() ) {
			$this->fail( $job, __( 'The pre-restore safety backup failed, so the restore was stopped to protect your data.', 'wrensafe' ) );
			return;
		}

		$result           = $backup_job->result();
		$restore_point_id = RestorePointsRepository::create(
			array(
				'label'     => __( 'Pre-restore safety point', 'wrensafe' ),
				'backup_id' => $result['backup_id'] ?? null,
				'reason'    => 'pre_restore',
			)
		);

		$payload['restore_point_id'] = $restore_point_id;
		$payload['phase']            = 'maintenance_on';

		$job->set_payload( $payload );
		$job->update_progress( 15, __( 'Rollback point created.', 'wrensafe' ) );

		Logger::info( 'Restore job #' . $job->id() . ' rollback creation completed.', array(), $job->id() );
	}

	private function step_maintenance_on( RestoreJob $job, array $payload ): void {
		// Visitors always get the maintenance notice: the boot guard installed
		// below runs every request without a theme until the restore is done,
		// so a page rendered meanwhile would be blank. WooCommerce Safe Mode
		// also pauses checkout (on top, for requests that bypass the notice).
		$this->enable_maintenance_mode();

		if ( ! empty( $payload['woo_safe_mode'] ) ) {
			( new CheckoutMaintenance() )->enable();
			Logger::info( 'Restore job #' . $job->id() . ' maintenance/custom maintenance enabled (Wrensafe site maintenance — front-end only, wp-admin/AJAX unaffected — and WooCommerce checkout maintenance).', array(), $job->id() );
		} else {
			Logger::info( 'Restore job #' . $job->id() . ' maintenance/custom maintenance enabled (Wrensafe site maintenance — front-end only, wp-admin/AJAX unaffected).', array(), $job->id() );
		}

		// Boot guard for the whole restore window: any active plugin whose
		// files are missing/incomplete is skipped at boot instead of fataling
		// the site — the exact crash a half-extracted WooCommerce caused.
		PluginSafety::install_boot_guard( $job->id(), (array) ( $payload['dest_active_plugins'] ?? array() ) );

		// FILES BEFORE DATABASE (Issue 10). The old order swapped the SOURCE
		// database live first, so its active_plugins named plugins whose files
		// weren't restored yet; the moment a plugin's main file appeared
		// (before its companion files), the next poll request loaded the
		// half-restored plugin and fataled, killing both the restore and the
		// destination site. Restoring files first means every plugin the
		// source database activates is fully on disk before that database
		// ever goes live.
		$payload['phase'] = $payload['needs_files'] ? 'restore_files' : ( $payload['needs_db'] ? 'restore_db' : 'finalize' );

		$job->set_payload( $payload );
		$job->update_progress( 18, __( 'Restoring…', 'wrensafe' ) );
	}

	private function step_restore_db( RestoreJob $job, array $payload ): void {
		$this->enable_maintenance_mode(); // Refresh the maintenance window.

		$tmp_dir = $this->tmp_dir_for_job( $job->id() );

		// Each table's schema/data is a separate archive entry (see
		// ArchiveWriter/database/manifest.json), so the source prefix can be
		// rewritten per table without a SQL parser, and a table that's
		// filtered out or Woo-protected can simply be skipped entirely.
		if ( ! isset( $payload['db_tables'] ) ) {
			Logger::info( 'Restore job #' . $job->id() . ' database restore started.', array(), $job->id() );

			$reader      = new ArchiveReader( $payload['archive_path'] );
			$db_manifest = $reader->read_json( 'database/manifest.json' ) ?? array();

			$payload['db_tables']      = $this->filter_db_tables( $db_manifest, $payload, $job->id() );
			$payload['db_table_index'] = 0;
			$payload['db_stage']       = 'extract';
			$payload['db_offset']      = 0;

			$job->set_payload( $payload );
			$job->update_progress( 50, __( 'Preparing database import…', 'wrensafe' ) );
			return;
		}

		$tables = $payload['db_tables'];
		$index  = $payload['db_table_index'];

		if ( $index >= count( $tables ) ) {
			// Every staging table is built; the live tables are still
			// untouched. Hand off to the atomic swap before anything else.
			$payload['phase'] = 'swap_db';
			$job->set_payload( $payload );
			$job->update_progress( 69, __( 'Database staged, preparing to swap…', 'wrensafe' ) );
			Logger::info( 'Restore job #' . $job->id() . ' database import into staging tables completed.', array(), $job->id() );
			return;
		}

		$entry       = $tables[ $index ];
		$schema_path = $tmp_dir . 'db-restore-schema.sql';
		$data_path   = $tmp_dir . 'db-restore-data.sql';

		if ( 'extract' === $payload['db_stage'] ) {
			$reader = new ArchiveReader( $payload['archive_path'] );
			$reader->extract_to( 'database/' . $entry['schema_file'], $schema_path );
			$reader->extract_to( 'database/' . $entry['data_file'], $data_path );

			// A staging table an interrupted restore left behind (a dump from
			// another plugin has no DROP TABLE before its CREATE TABLE).
			global $wpdb;
			$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', (string) $entry['tmp_table'] ) );

			$payload['db_stage']  = 'schema';
			$payload['db_offset'] = 0;
			$job->set_payload( $payload );
			return;
		}

		// Import into the staging table, never the live one — the live table
		// is only replaced later by step_swap_db's atomic RENAME.
		global $wpdb;

		$importer  = new DatabaseImporter();
		$sql_path  = 'schema' === $payload['db_stage'] ? $schema_path : $data_path;
		$deadline  = self::step_deadline();
		$offset    = (int) $payload['db_offset'];
		$table_map = 'schema' === $payload['db_stage'] ? DatabaseImporter::foreign_key_map( $tables, $wpdb->prefix ) : array();

		// As many statement chunks as fit in this request — one chunk per
		// request made a large wp_postmeta take hundreds of round trips.
		do {
			$result = $importer->import_chunk( $sql_path, $offset, $entry['source_table'], $entry['tmp_table'], ! empty( $payload['legacy_sql_escapes'] ), $table_map );
			$offset = (int) $result['offset'];
		} while ( null === $result['error'] && ! $result['done'] && microtime( true ) < $deadline );

		if ( null !== $result['error'] ) {
			// Thrown rather than marked failed directly, so process_step's
			// catch block uniformly disables maintenance mode, logs the
			// table + SQL error, and stops before file restore ever runs.
			throw new \RuntimeException(
				esc_html(
					sprintf(
						/* translators: 1: destination table name, 2: database error message */
						__( 'Database restore failed on table %1$s: %2$s', 'wrensafe' ),
						$entry['dest_table'],
						Errors::utf8( (string) $result['error'] )
					)
				)
			);
		}

		$progress = 50 + (int) ( ( $index / max( 1, count( $tables ) ) ) * 18 );

		if ( ! $result['done'] ) {
			$payload['db_offset'] = $result['offset'];
			$job->set_payload( $payload );
			$job->update_progress( min( 68, $progress ), sprintf( /* translators: %s: database table name */ __( 'Restoring table %s…', 'wrensafe' ), $entry['dest_table'] ) );
			return;
		}

		if ( 'schema' === $payload['db_stage'] ) {
			$payload['db_stage']  = 'data';
			$payload['db_offset'] = 0;
		} else {
			++$payload['db_table_index'];
			$payload['db_stage']  = 'extract';
			$payload['db_offset'] = 0;
		}

		$job->set_payload( $payload );
		$job->update_progress( min( 68, $progress ), sprintf( /* translators: %s: database table name */ __( 'Restoring table %s…', 'wrensafe' ), $entry['dest_table'] ) );
	}

	/**
	 * Resolve database/manifest.json entries to the destination tables that
	 * will actually be imported: rewrites each source table's prefix to
	 * this site's $wpdb->prefix, then drops any table excluded by a
	 * selective-table restore or by WooCommerce Safe Mode.
	 *
	 * @return array<int,array{source_table:string,dest_table:string,tmp_table:string,schema_file:string,data_file:string}>
	 */
	private function filter_db_tables( array $db_manifest, array $payload, int $job_id = 0 ): array {
		global $wpdb;
		$dest_prefix = $wpdb->prefix;

		$is_selective = 'selected_tables' === ( $payload['restore_mode'] ?? 'full' );
		$table_filter = $is_selective ? array_flip( (array) ( $payload['selected_tables'] ?? array() ) ) : null;
		$excluded     = ! empty( $payload['woo_safe_mode'] ) ? array_flip( WooSafeMode::get_protected_tables() ) : array();
		$others       = $this->other_install_prefixes( $db_manifest, $dest_prefix );
		$skipped      = array();

		$tables = array();

		foreach ( $db_manifest as $entry ) {
			if ( ! is_array( $entry ) ) {
				continue;
			}

			$source_table     = (string) ( $entry['source_table'] ?? '' );
			$normalized_table = (string) ( $entry['normalized_table'] ?? $source_table );
			$dest_table       = $dest_prefix . $normalized_table;

			if ( '' === $source_table || empty( $entry['schema_file'] ) || empty( $entry['data_file'] ) ) {
				continue;
			}

			// Table names from the backup's manifest end up in SQL (as
			// identifiers, and in the checks of DatabaseImporter): only the
			// characters WordPress table names use.
			if ( ! self::is_safe_table_name( $source_table ) || ! self::is_safe_table_name( $normalized_table ) || ! self::is_safe_table_name( $dest_table ) ) {
				Logger::warning( 'restore_table_skipped: the backup lists a table with a name a restore doesn\'t accept (' . sanitize_text_field( Errors::utf8( $source_table ) ) . '); it was not restored.', array(), $job_id );
				continue;
			}

			// Wrensafe's own tables (jobs, logs, backup list — this restore's
			// own job included) always stay this site's.
			if ( 0 === stripos( $normalized_table, 'wpbp_' ) ) {
				continue;
			}

			if ( null !== $table_filter
				&& ! isset( $table_filter[ $source_table ] )
				&& ! isset( $table_filter[ $normalized_table ] )
				&& ! isset( $table_filter[ $dest_table ] ) ) {
				continue;
			}

			if ( isset( $excluded[ $dest_table ] ) ) {
				continue;
			}

			// Another WordPress site's table (a backup made before 1.2.1 could
			// include one): restoring it would overwrite that site.
			if ( SharedDatabase::belongs_to_other( $dest_table, $others ) ) {
				$skipped[] = $dest_table;
				continue;
			}

			$tables[] = array(
				'source_table' => $source_table,
				'dest_table'   => $dest_table,
				'tmp_table'    => $this->temp_table_name( $dest_table ),
				'schema_file'  => (string) $entry['schema_file'],
				'data_file'    => (string) $entry['data_file'],
			);
		}

		if ( $skipped ) {
			Logger::warning( 'restore_tables_of_other_site_skipped: ' . count( $skipped ) . ' tables belong to another WordPress site in this database (table prefix ' . implode( ', ', $others ) . ') and were not restored: ' . implode( ', ', array_slice( $skipped, 0, 20 ) ) . ( count( $skipped ) > 20 ? ', …' : '' ) . '.', array(), $job_id );
		}

		return $tables;
	}

	/**
	 * The table prefixes of other WordPress sites a restore must leave
	 * alone (see SharedDatabase): those in this database, and those the
	 * backup itself holds tables of (a backup made before 1.2.1 took in
	 * every table that started with its site's prefix).
	 *
	 * @return string[]
	 */
	private function other_install_prefixes( array $db_manifest, string $dest_prefix ): array {
		global $wpdb;

		$existing = array_map( 'strval', (array) $wpdb->get_col( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $dest_prefix ) . '%' ) ) );
		$incoming = array();

		foreach ( $db_manifest as $entry ) {
			$normalized = is_array( $entry ) ? (string) ( $entry['normalized_table'] ?? $entry['source_table'] ?? '' ) : '';

			if ( '' !== $normalized ) {
				$incoming[] = $dest_prefix . $normalized;
			}
		}

		$base = SharedDatabase::subsite_base();

		return array_values(
			array_unique(
				array_merge(
					SharedDatabase::other_install_prefixes( $existing, $dest_prefix, $base ),
					SharedDatabase::other_install_prefixes( $incoming, $dest_prefix, $base )
				)
			)
		);
	}

	/** A table name of letters, digits, "_", "$" and "-", at most 64 bytes. */
	private static function is_safe_table_name( string $name ): bool {
		return 1 === preg_match( '/^[A-Za-z0-9_$-]{1,64}$/', $name );
	}

	/**
	 * Atomically replace the live tables with the fully-built staging tables.
	 *
	 * Up to this point the live database has been completely untouched — the
	 * import wrote only into `*_wpbptmp` tables — so the site and the
	 * restore's own admin-ajax polling kept working. A single `RENAME TABLE`
	 * statement swaps every table at once (atomic in MySQL/InnoDB), so there
	 * is never a window where some core tables are restored and others are
	 * not, and never a partially-imported table for a concurrent request to
	 * trip over.
	 *
	 * Because the swap replaces wp_users / wp_usermeta with the backup's
	 * copies, the administrator running the restore would normally be logged
	 * out (their current session token would vanish), which would stop the
	 * remaining restore steps from polling. To prevent that, the current
	 * user's `session_tokens` are captured before the swap and re-applied to
	 * the freshly-restored wp_usermeta immediately afterwards, keeping them
	 * authenticated through to completion.
	 */
	private function step_swap_db( RestoreJob $job, array $payload ): void {
		global $wpdb;

		$tables = $payload['db_tables'] ?? array();

		if ( empty( $tables ) ) {
			// Files (if any) were already restored before the DB phase (Issue 10).
			$payload['phase'] = 'search_replace';
			$job->set_payload( $payload );
			return;
		}

		Logger::info( 'Restore job #' . $job->id() . ' swapping restored database tables live (atomic).', array(), $job->id() );

		$existing = array_flip( (array) $wpdb->get_col( 'SHOW TABLES' ) );

		// Capture the current admin's login session before wp_usermeta is
		// replaced, so we can re-apply it post-swap and stay authenticated.
		$current_user_id   = function_exists( 'get_current_user_id' ) ? get_current_user_id() : 0;
		$preserved_session = null;
		if ( $current_user_id > 0 ) {
			$preserved_session = $wpdb->get_var(
				$wpdb->prepare(
					"SELECT meta_value FROM `{$wpdb->usermeta}` WHERE user_id = %d AND meta_key = 'session_tokens' LIMIT 1",
					$current_user_id
				)
			);
		}

		// This site's own authentication keys and salts, when WordPress keeps
		// them in the database: they stay this site's (see keep_secret_keys()).
		$secret_keys = $this->secret_key_rows();

		// The swap drops the replaced tables, which other tables may still
		// reference through foreign keys (they follow a table when it's renamed).
		$wpdb->query( 'SET FOREIGN_KEY_CHECKS = 0' );

		// Pairs of names for one RENAME TABLE: from, to, from, to, …
		$renames    = array();
		$old_tables = array();

		foreach ( $tables as $entry ) {
			$live = (string) $entry['dest_table'];
			$tmp  = (string) $entry['tmp_table'];

			if ( ! isset( $existing[ $tmp ] ) ) {
				// Staging table missing (e.g. a table the backup didn't
				// contain) — nothing to swap for it.
				continue;
			}

			if ( isset( $existing[ $live ] ) ) {
				$old = $this->old_table_name( $live );
				$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', $old ) );
				array_push( $renames, $live, $old, $tmp, $live );
				$old_tables[] = $old;
			} else {
				array_push( $renames, $tmp, $live );
			}
		}

		if ( ! empty( $renames ) ) {
			// One "%i TO %i" per pair of names in $renames.
			$wpdb->query(
				$wpdb->prepare( // phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- two names per "%i TO %i"; the sniff can't count array_fill() placeholders.
					'RENAME TABLE ' . implode( ', ', array_fill( 0, count( $renames ) / 2, '%i TO %i' ) ),
					$renames
				)
			);

			if ( '' !== (string) $wpdb->last_error ) {
				throw new \RuntimeException(
					esc_html(
						sprintf(
							/* translators: %s: database error message */
							__( 'Database swap failed, no changes applied: %s', 'wrensafe' ),
							Errors::utf8( (string) $wpdb->last_error )
						)
					)
				);
			}
		}

		// Different table prefix on the source: the restored tables were
		// renamed to this site's prefix, but WordPress also stores the prefix
		// INSIDE two tables — the {prefix}user_roles option and per-user
		// {prefix}capabilities / {prefix}user_level meta. Left as the source
		// prefix, no roles exist and every user (including this admin) loses
		// all capabilities. Rename them now, in the same request as the swap.
		$this->migrate_prefixed_keys( $payload, $tables, $job->id() );

		// Re-apply the preserved login session to the restored wp_usermeta so
		// the administrator running the restore stays logged in (best effort —
		// if the backup's password differs, they'll simply log in again).
		if ( $current_user_id > 0 && null !== $preserved_session ) {
			$wpdb->query( $wpdb->prepare( "DELETE FROM `{$wpdb->usermeta}` WHERE user_id = %d AND meta_key = 'session_tokens'", $current_user_id ) );
			$wpdb->query( $wpdb->prepare( "INSERT INTO `{$wpdb->usermeta}` (user_id, meta_key, meta_value) VALUES (%d, 'session_tokens', %s)", $current_user_id, $preserved_session ) );
		}

		$this->keep_secret_keys( $secret_keys, $job->id() );

		// The swapped-in wp_options now carries the SOURCE site's home/siteurl.
		// Force them back to the DESTINATION values captured before the restore
		// started, immediately and via direct SQL (update_option() could read
		// through stale caches mid-swap). This is what keeps Site B on Site B's
		// domain after restoring Site A's backup (Issue 08). The remaining
		// occurrences of the source URL elsewhere in the database are rewritten
		// by the serialization-safe search/replace step that follows.
		$payload['db_swapped'] = true;
		$this->force_destination_urls( $payload, $job->id() );
		$this->quiet_restored_schedule( $job->id() ); // Before any other request sees the restored schedule.

		// The maintenance flags lived in the options table that was just
		// replaced; visitors must not see the site before URLs are rewritten.
		$this->enable_maintenance_mode();

		if ( ! empty( $payload['woo_safe_mode'] ) ) {
			( new CheckoutMaintenance() )->enable();
		}

		// The swapped-in active_plugins is the SOURCE list. Files were restored
		// BEFORE this swap (Issue 10), so plugins the backup carried are fully
		// on disk — but any source-active plugin whose files are missing or
		// incomplete on THIS site (e.g. WooCommerce without src/Autoloader.php)
		// is deactivated now, in this same request, so no later request can
		// fatal on a plugin that isn't really installed here.
		PluginSafety::filter_active_plugins( $job->id() );

		if ( ! $this->is_same_site_restore( $payload ) ) {
			PluginSafety::deactivate_host_plugins( (array) ( $payload['dest_active_plugins'] ?? array() ), $job->id() );
		}

		foreach ( $old_tables as $old ) {
			$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', $old ) );
		}

		// The swapped-in data is invisible to anything still holding the old
		// alloptions/user caches from earlier this request.
		wp_cache_flush();
		if ( $current_user_id > 0 && function_exists( 'clean_user_cache' ) ) {
			clean_user_cache( $current_user_id );
		}

		$payload['phase'] = 'search_replace';
		$job->set_payload( $payload );
		$job->update_progress( 72, __( 'Database restored.', 'wrensafe' ) );

		Logger::info( 'Restore job #' . $job->id() . ' database restore completed (tables swapped live).', array(), $job->id() );
	}

	/**
	 * Rename prefix-bearing keys inside the restored options/usermeta tables
	 * from the source table prefix to this site's prefix.
	 *
	 * @param array $payload Job payload (source_table_prefix, dest_table_prefix).
	 * @param array $tables  Tables that were swapped in (db_tables entries).
	 */
	private function migrate_prefixed_keys( array $payload, array $tables, int $job_id ): void {
		global $wpdb;

		$source = (string) ( $payload['source_table_prefix'] ?? '' );
		$dest   = (string) ( $payload['dest_table_prefix'] ?? $wpdb->prefix );

		if ( '' === $source || $source === $dest ) {
			return;
		}

		$swapped = array_flip( wp_list_pluck( $tables, 'dest_table' ) );

		if ( isset( $swapped[ $wpdb->options ] ) ) {
			$has_source_roles = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM `{$wpdb->options}` WHERE option_name = %s", $source . 'user_roles' ) );

			if ( $has_source_roles > 0 ) {
				$wpdb->query( $wpdb->prepare( "DELETE FROM `{$wpdb->options}` WHERE option_name = %s", $dest . 'user_roles' ) );
				$wpdb->query( $wpdb->prepare( "UPDATE `{$wpdb->options}` SET option_name = %s WHERE option_name = %s", $dest . 'user_roles', $source . 'user_roles' ) );
			}
		}

		$renamed_meta = 0;

		if ( isset( $swapped[ $wpdb->usermeta ] ) ) {
			// Drop any destination-prefixed duplicates first so the rename can
			// never leave two rows for the same user/key.
			$wpdb->query(
				$wpdb->prepare(
					"DELETE dest FROM `{$wpdb->usermeta}` dest JOIN `{$wpdb->usermeta}` src ON src.user_id = dest.user_id AND dest.meta_key = CONCAT( %s, SUBSTRING( src.meta_key, %d ) ) WHERE src.meta_key LIKE %s",
					$dest,
					strlen( $source ) + 1,
					$wpdb->esc_like( $source ) . '%'
				)
			);

			$renamed_meta = (int) $wpdb->query(
				$wpdb->prepare(
					"UPDATE `{$wpdb->usermeta}` SET meta_key = CONCAT( %s, SUBSTRING( meta_key, %d ) ) WHERE meta_key LIKE %s",
					$dest,
					strlen( $source ) + 1,
					$wpdb->esc_like( $source ) . '%'
				)
			);
		}

		wp_cache_flush();

		Logger::info(
			sprintf( 'restore_table_prefix_keys_migrated: source prefix %1$s → %2$s (user_roles option and %3$d user meta keys renamed).', $source, $dest, $renamed_meta ),
			array(),
			$job_id
		);
	}

	private function step_restore_files( RestoreJob $job, array $payload ): void {
		$this->enable_maintenance_mode(); // Refresh the maintenance window.

		$chain_paths  = $payload['chain_paths'] ?? array();
		$chain_index  = $payload['chain_index'] ?? 0;
		$current_path = ! empty( $chain_paths ) ? $chain_paths[ $chain_index ] : $payload['archive_path'];

		if ( empty( $payload['files_listed'] ) ) {
			Logger::info( 'Restore job #' . $job->id() . ' file restore started.', array(), $job->id() );

			$reader  = new ArchiveReader( $current_path );
			$entries = $reader->list_file_entries();

			$root_filter = $payload['root_filter'] ?? '';
			$target      = $payload['restore_target'] ?? '';

			if ( '' !== $root_filter ) {
				// The section can live at the archive top ("files/plugins/…") or,
				// in full/files backups, under the whole-site root
				// ("files/root/wp-content/plugins/…") — the latter previously
				// matched nothing, so "Plugins only" from a full backup restored
				// zero files and still reported success.
				$prefixes = array_map(
					static fn( $prefix ) => $prefix . ( '' !== $target ? $target . '/' : '' ),
					ArchiveReader::section_prefixes( $root_filter, (array) ( $reader->read_json( 'metadata.json' ) ?? array() ) )
				);
				$entries  = array_values(
					array_filter(
						$entries,
						static function ( $entry ) use ( $prefixes ) {
							foreach ( $prefixes as $prefix ) {
								if ( Str::starts_with( (string) $entry, $prefix ) ) {
									return true;
								}
							}
							return false;
						}
					)
				);

				if ( empty( $entries ) ) {
					throw new \RuntimeException(
						esc_html(
							sprintf(
								/* translators: %s: restore section such as "plugins" */
								__( 'This backup has no %s to restore. Nothing was changed.', 'wrensafe' ),
								$root_filter
							)
						)
					);
				}
			}

			$entries = $this->exclude_unrestorable_entries( $entries, $payload, $job->id() );

			// The entry list goes to a tmp file (one name per line) rather than
			// the job payload: 100k names would be ~10 MB re-encoded and written
			// to the database on every request, past many hosts' max_allowed_packet.
			$list_handle = fopen( $this->entries_list_path( $job->id() ), 'wb' );

			if ( false === $list_handle ) {
				throw new \RuntimeException( esc_html__( 'The restore could not write its temporary file list (check the storage folder permissions).', 'wrensafe' ) );
			}

			foreach ( $entries as $entry_name ) {
				fwrite( $list_handle, str_replace( array( "\r", "\n" ), '', (string) $entry_name ) . "\n" );
			}

			fclose( $list_handle );

			$payload['file_entries']     = array();
			$payload['file_total']       = count( $entries );
			$payload['file_index']       = 0;
			$payload['file_list_offset'] = 0;
			$payload['files_listed']     = true;
			$job->set_payload( $payload );
			$job->update_progress( 20, __( 'Preparing file restore…', 'wrensafe' ) );
			return;
		}

		$index    = (int) $payload['file_index'];
		$total    = (int) ( $payload['file_total'] ?? 0 );
		$reader   = new ArchiveReader( $current_path );
		$restorer = new FileRestorer( self::source_layout( $payload ) );
		$batch    = 0;
		$deadline = self::step_deadline();

		if ( ! isset( $payload['restore_failed_files'] ) ) {
			$payload['restore_failed_files'] = array();
			$payload['restore_failed_count'] = 0;
		}

		$list_handle = fopen( $this->entries_list_path( $job->id() ), 'rb' );

		if ( false === $list_handle ) {
			throw new \RuntimeException( esc_html__( 'The restore lost its temporary file list. Start the restore again.', 'wrensafe' ) );
		}

		fseek( $list_handle, (int) ( $payload['file_list_offset'] ?? 0 ) );

		// Time-budgeted batch: as many entries as fit before the deadline
		// (at least one, so a single huge file still makes progress).
		$big_state = is_array( $payload['file_big_state'] ?? null ) ? $payload['file_big_state'] : array();

		while ( $index < $total && ( 0 === $batch || microtime( true ) < $deadline ) && $batch < 5000 ) {
			$line_start = ftell( $list_handle );
			$line       = fgets( $list_handle );

			if ( false === $line ) {
				$index = $total;
				break;
			}

			$entry_name = rtrim( $line, "\n" );
			$restored   = '' === $entry_name ? true : $restorer->restore_entry_step( $reader, $entry_name, $big_state, $deadline );

			if ( null === $restored ) {
				// A very large file paused mid-copy: resume this same entry next request.
				$payload['file_list_offset'] = $line_start;
				break;
			}

			// A silently-ignored extraction failure is exactly what left plugin
			// directories missing files (Issue 11) → autoloader fatal. Record
			// every failure so the post-restore integrity check can deactivate
			// the affected plugin instead of letting the site fatal.
			if ( false === $restored ) {
				++$payload['restore_failed_count'];
				if ( count( $payload['restore_failed_files'] ) < 200 ) {
					$payload['restore_failed_files'][] = $entry_name;
				}
				Logger::warning( 'restore_file_extract_failed: could not restore ' . $entry_name . '.', array(), $job->id() );
			}
			++$index;
			++$batch;
			$payload['file_list_offset'] = ftell( $list_handle );
		}

		$payload['file_big_state'] = $big_state;

		fclose( $list_handle );

		$payload['file_index'] = $index;

		if ( $index < $total ) {
			$progress = 20 + (int) ( ( $index / max( 1, $total ) ) * 28 );
			$job->update_progress( $progress, sprintf( /* translators: 1: number of items processed so far, 2: total number of items */ __( 'Restoring files (%1$d / %2$d)', 'wrensafe' ), $index, $total ) );
			$job->set_payload( $payload );
			return;
		}

		// This chain link is done — advance to the next one, or finish.
		if ( ! empty( $chain_paths ) && $chain_index < count( $chain_paths ) - 1 ) {
			$payload['chain_index']      = $chain_index + 1;
			$payload['files_listed']     = false;
			$payload['file_entries']     = array();
			$payload['file_index']       = 0;
			$payload['file_list_offset'] = 0;
			$job->update_progress( 30, sprintf( /* translators: 1: number of the backup being restored in the chain, 2: total backups in the chain */ __( 'Restoring backup chain (%1$d / %2$d)…', 'wrensafe' ), $chain_index + 2, count( $chain_paths ) ) );
		} else {
			// Files are fully on disk BEFORE the database goes live (Issue 10) —
			// only now is it safe to stage/swap a database whose active_plugins
			// reference these files.
			$payload['phase'] = $payload['needs_db'] ? 'restore_db' : 'search_replace';
			$job->update_progress( 48, __( 'Files restored.', 'wrensafe' ) );
			Logger::info( 'Restore job #' . $job->id() . ' file restore completed.', array(), $job->id() );
		}

		$job->set_payload( $payload );
	}

	/**
	 * Whether this restore puts a site's backup back onto that same site
	 * (same home URL, and same install path when the archive recorded it) —
	 * as opposed to a migration onto a different site or server.
	 */
	private function is_same_site_restore( array $payload ): bool {
		$source_url = untrailingslashit( (string) ( $payload['site_url'] ?? '' ) );
		$dest_url   = (string) ( $payload['dest_home_url'] ?? '' );

		if ( '' === $source_url || $source_url !== $dest_url ) {
			return false;
		}

		$source_path = (string) ( $payload['source_abspath'] ?? '' );

		return '' === $source_path || wp_normalize_path( $source_path ) === wp_normalize_path( (string) ( $payload['dest_abspath'] ?? '' ) );
	}

	/**
	 * The source site's folder layout, as recorded in the backup's metadata,
	 * for FileRestorer (see FileRestorer::__construct()).
	 *
	 * @return array<string,string>
	 */
	public static function source_layout( array $payload ): array {
		return array(
			'abspath'         => (string) ( $payload['source_abspath'] ?? '' ),
			'content_dir'     => (string) ( $payload['source_content_dir'] ?? '' ),
			'uploads_basedir' => (string) ( $payload['source_uploads_basedir'] ?? '' ),
			'plugins_dir'     => (string) ( $payload['source_plugins_dir'] ?? '' ),
			'mu_plugins_dir'  => (string) ( $payload['source_mu_plugins_dir'] ?? '' ),
			'themes_dir'      => (string) ( $payload['source_themes_dir'] ?? '' ),
		);
	}

	/**
	 * Drop archive entries that must never be written to this site (its own
	 * wp-config.php, Wrensafe's own code/storage) and, on a migration,
	 * server-specific configuration/drop-ins — see FileRestorer::skip_reason().
	 * Logged so it's clear why those files were left as they are.
	 *
	 * @param string[] $entries Archive entry names.
	 * @return string[]
	 */
	private function exclude_unrestorable_entries( array $entries, array $payload, int $job_id ): array {
		$restorer  = new FileRestorer( self::source_layout( $payload ) );
		$same_site = $this->is_same_site_restore( $payload );
		$kept      = array();
		$skipped   = array(
			'protected'       => array(),
			'server_specific' => array(),
		);

		foreach ( $entries as $entry ) {
			$reason = $restorer->skip_reason( (string) $entry, $same_site );

			if ( '' === $reason ) {
				$kept[] = $entry;
			} else {
				$skipped[ $reason ][] = $entry;
			}
		}

		if ( ! empty( $skipped['protected'] ) ) {
			Logger::info(
				sprintf(
					'restore_protected_files_skipped: %d file(s) kept as they are on this site (wp-config.php and Wrensafe\'s own files are never overwritten by a restore), e.g. %s.',
					count( $skipped['protected'] ),
					implode( ', ', array_slice( $skipped['protected'], 0, 3 ) )
				),
				array(),
				$job_id
			);
		}

		if ( ! empty( $skipped['server_specific'] ) ) {
			Logger::info(
				'restore_server_specific_files_skipped: migration to a different site/server — kept this server\'s own ' . implode( ', ', array_map( 'basename', $skipped['server_specific'] ) ) . '.',
				array(),
				$job_id
			);
		}

		return $kept;
	}

	/** A folder path with one trailing slash, or '' when unknown. */
	private static function folder_with_slash( string $path ): string {
		return '' === $path ? '' : trailingslashit( $path );
	}

	/**
	 * Build the ordered source → destination replacement pairs for a
	 * cross-site restore. URLs first (site_url covers most content), then
	 * filesystem paths. Pairs where the source is unknown (older archives) or
	 * source equals destination are skipped. Longer sources run first so a
	 * more specific value (uploads URL) is rewritten before the plain site
	 * URL would partially match inside it.
	 *
	 * @return array<int,array{0:string,1:string}>
	 */
	private function build_replacement_pairs( array $payload ): array {
		$candidates = array(
			array( untrailingslashit( (string) ( $payload['site_url'] ?? '' ) ), (string) ( $payload['dest_home_url'] ?? '' ) ),
			array( (string) ( $payload['source_site_url_option'] ?? '' ), (string) ( $payload['dest_site_url'] ?? '' ) ),
			array( (string) ( $payload['source_uploads_baseurl'] ?? '' ), (string) ( $payload['dest_uploads_baseurl'] ?? '' ) ),
			array( (string) ( $payload['source_uploads_basedir'] ?? '' ), (string) ( $payload['dest_uploads_basedir'] ?? '' ) ),
			array( (string) ( $payload['source_content_dir'] ?? '' ), (string) ( $payload['dest_content_dir'] ?? '' ) ),
			// Whole folder only ("/var/www/html/wp/"): without the slash a
			// source folder /var/www/html/wp would also cut into
			// /var/www/html/wp-content. Stored ABSPATH values end in a slash.
			array( self::folder_with_slash( (string) ( $payload['source_abspath'] ?? '' ) ), self::folder_with_slash( (string) ( $payload['dest_abspath'] ?? '' ) ) ),
		);

		// The same site addresses in their other forms: the other scheme
		// (old http:// links on a site that moved to https://) and
		// protocol-relative ("//old-site.com/wp-content/…", common in page
		// builders and sliders). Serializer::replace() applies every pair in
		// one strtr() pass, longest match first, so "https://old" is never
		// also caught by "//old".
		foreach ( array_slice( $candidates, 0, 2 ) as $pair ) {
			if ( preg_match( '#^(https?):(//.+)$#i', $pair[0], $from ) && preg_match( '#^https?:(//.+)$#i', $pair[1], $to ) ) {
				$candidates[] = array( ( 'https' === strtolower( $from[1] ) ? 'http' : 'https' ) . ':' . $from[2], $pair[1] );
				$candidates[] = array( $from[2], $to[1] );
			}
		}

		$pairs = array();
		$seen  = array();

		foreach ( $candidates as $pair ) {
			list( $search, $replace ) = $pair;

			if ( '' === $search || '' === $replace || $search === $replace || isset( $seen[ $search ] ) ) {
				continue;
			}

			$seen[ $search ] = true;
			$pairs[]         = array( $search, $replace );
		}

		// Most specific (longest) source first, so uploads URL/path is
		// rewritten before the shorter site URL/abspath matches inside it.
		usort( $pairs, static fn( $a, $b ) => strlen( $b[0] ) <=> strlen( $a[0] ) );

		return $pairs;
	}

	private function step_search_replace( RestoreJob $job, array $payload ): void {
		// Destination URLs come from the payload (captured in step_init, BEFORE
		// the database swap) — never from home_url() here, which would read the
		// freshly-restored SOURCE values and silently skip the replacement.
		if ( empty( $payload['needs_db'] ) ) {
			$payload['phase'] = 'finalize';
			$job->set_payload( $payload );
			return;
		}

		if ( ! isset( $payload['sr_pairs'] ) ) {
			$payload['sr_pairs'] = $this->build_replacement_pairs( $payload );

			if ( empty( $payload['sr_pairs'] ) ) {
				Logger::info( 'restore_url_replacement_started: source and destination URLs match — no URL replacement needed.', array(), $job->id() );
				$payload['phase'] = 'finalize';
				$job->set_payload( $payload );
				return;
			}

			$plan = array_map( static fn( $p ) => $p[0] . ' → ' . $p[1], $payload['sr_pairs'] );
			Logger::info( 'restore_url_replacement_started: ' . implode( '; ', $plan ) . '.', array(), $job->id() );

			// Only the tables this restore replaced carry the source site's
			// addresses and paths. The others are this site's own and stay as
			// they are, Wrensafe's tables above all: rewriting them pointed
			// the backup list at folders that don't exist (a source path that
			// is the start of this site's path, e.g. /var/www/html → /var/www/html/wp).
			global $wpdb;
			$restored                  = array_column( (array) ( $payload['db_tables'] ?? array() ), 'dest_table' );
			$existing                  = $wpdb->get_col( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $wpdb->prefix ) . '%' ) );
			$payload['sr_tables']      = array_values( array_intersect( $existing, $restored ) );
			$payload['sr_table_index'] = 0;
			$payload['sr_cursor']      = null;
			$job->set_payload( $payload );
			return;
		}

		$tables      = $payload['sr_tables'];
		$table_count = count( $tables );
		$engine      = new SearchReplaceEngine();
		$deadline    = self::step_deadline();

		// As many batches as fit in this request (one 200-row batch per
		// request made a 30k-post site take hundreds of round trips).
		while ( $payload['sr_table_index'] < $table_count && microtime( true ) < $deadline ) {
			$result = $engine->process_table_after( $tables[ $payload['sr_table_index'] ], $payload['sr_cursor'] ?? null, $payload['sr_pairs'] );

			if ( $result['done'] ) {
				++$payload['sr_table_index'];
				$payload['sr_cursor'] = null;
			} else {
				$payload['sr_cursor'] = $result['cursor'];
			}
		}

		$index = $payload['sr_table_index'];

		if ( $index >= count( $tables ) ) {
			// Re-force home/siteurl in case a replacement pair rewrote them to
			// anything unexpected — the destination values always win.
			$this->force_destination_urls( $payload, $job->id() );
			$payload['phase'] = 'finalize';
			$job->set_payload( $payload );
			$job->update_progress( 95, __( 'URL replacement complete.', 'wrensafe' ) );
			Logger::info( 'restore_url_replacement_completed: all tables processed.', array(), $job->id() );
			return;
		}

		$progress = 72 + (int) ( ( $index / max( 1, count( $tables ) ) ) * 23 );
		$job->update_progress( $progress, sprintf( /* translators: %s: database table name */ __( 'Replacing URLs in table %s', 'wrensafe' ), $tables[ min( $index, count( $tables ) - 1 ) ] ) );
		$job->set_payload( $payload );
	}

	/**
	 * The restored database brings its own cron schedule, overdue by however
	 * old the backup is. On the first page load after the restore WordPress
	 * would run that whole backlog — automatic plugin, theme, core and
	 * translation updates among it (its maintenance page shows meanwhile, and
	 * a rollback restore gets undone at once). Overdue jobs wait 15 minutes
	 * instead and WordPress's update check an hour, so the restored site can
	 * be checked first.
	 *
	 * The cron array is edited directly: the backup's plugins aren't loaded
	 * during the restore, so their custom intervals aren't registered and
	 * wp_schedule_event() would refuse (and lose) their events.
	 */
	private function quiet_restored_schedule( int $job_id ): void {
		// Read the restored schedule, not one cached before the swap.
		wp_cache_delete( 'alloptions', 'options' );
		wp_cache_delete( 'notoptions', 'options' );
		wp_cache_delete( 'cron', 'options' );

		$crons = _get_cron_array();

		if ( ! is_array( $crons ) || empty( $crons ) ) {
			return;
		}

		$now      = time();
		$later    = $now + 15 * MINUTE_IN_SECONDS;
		$check_at = $now + HOUR_IN_SECONDS;
		$moved    = 0;
		$out      = array();

		foreach ( $crons as $timestamp => $hooks ) {
			foreach ( (array) $hooks as $hook => $events ) {
				// Background updates start from wp_version_check run in cron.
				$when = 'wp_version_check' === $hook ? max( (int) $timestamp, $check_at ) : ( (int) $timestamp <= $now ? $later : (int) $timestamp );

				if ( $when !== (int) $timestamp ) {
					$moved += count( (array) $events );
				}

				foreach ( (array) $events as $key => $event ) {
					$out[ $when ][ $hook ][ $key ] = $event;
				}
			}
		}

		ksort( $out );
		_set_cron_array( $out );

		Logger::info( 'restore_schedule_quieted: ' . $moved . ' overdue scheduled tasks from the backup will run in 15 minutes, and WordPress\'s automatic updates in an hour, so the restored site can be checked first.', array(), $job_id );
	}

	private function step_finalize( RestoreJob $job, array $payload ): void {
		Logger::info( 'Restore job #' . $job->id() . ' cleanup started.', array(), $job->id() );

		$this->disable_maintenance_mode();

		if ( ! empty( $payload['woo_safe_mode'] ) ) {
			( new CheckoutMaintenance() )->disable();
			( new OrderSync() )->sync();
		}

		Logger::info( 'Restore job #' . $job->id() . ' maintenance/custom maintenance disabled.', array(), $job->id() );

		// Final plugin safety pass (database restores only — file-only restores
		// never touch active_plugins).
		if ( ! empty( $payload['needs_db'] ) ) {
			PluginSafety::filter_active_plugins( $job->id() );
			$this->quiet_restored_schedule( $job->id() );
		}

		// Post-restore plugin file integrity (Issue 11): deactivate any active
		// plugin whose files weren't fully restored, so the site loads cleanly
		// instead of fataling on a missing autoloaded class file. Runs while the
		// boot guard is STILL active, then the guard is removed below — so once
		// it's gone, every remaining active plugin is known to be complete.
		if ( ! empty( $payload['needs_files'] ) || ! empty( $payload['needs_db'] ) ) {
			$integrity = PostRestoreIntegrityChecker::run( $payload, $job->id() );

			if ( ! empty( $integrity['deactivated'] ) ) {
				$payload['restore_deactivated_plugins'] = wp_list_pluck( $integrity['deactivated'], 'plugin' );

				foreach ( $integrity['deactivated'] as $item ) {
					$payload['restore_deactivated'][] = array( (string) $item['plugin'], (string) $item['reason'] );
				}
			}
		}

		// The boot guard stays until the restore is complete (complete_restore()):
		// the health check's own requests must not load a plugin that crashes.

		// Clear caches that can otherwise reference the pre-restore code/files:
		// PHP's opcode cache (stale compiled classes/autoload maps — the direct
		// cause of composer "class file not found" errors lingering after the
		// files are correct) and WordPress's object cache. opcache_reset is
		// best-effort and never fatal if disabled.
		if ( function_exists( 'opcache_reset' ) ) {
			@opcache_reset(); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, Generic.PHP.NoSilencedErrors.Discouraged
			Logger::info( 'restore_opcache_reset: PHP opcode cache cleared after restore.', array(), $job->id() );
		}

		flush_rewrite_rules();
		wp_cache_flush();

		// Last: load the restored site once and switch off whatever stops it.
		$payload['phase']  = 'health_check';
		$payload['health'] = array( 'attempt' => 0 );
		$job->set_payload( $payload );
		$job->update_progress( 97, __( 'Checking that the restored site loads…', 'wrensafe' ) );
	}

	/**
	 * Load the restored site (RestoreHealthCheck). When a plugin it brought
	 * stops the site with a fatal error, deactivate that plugin (or switch
	 * from a broken theme to a default one) and check again — up to three
	 * times, one per request.
	 */
	private function step_health_check( RestoreJob $job, array $payload ): void {
		$attempt = (int) ( $payload['health']['attempt'] ?? 0 );

		if ( $attempt >= 3 || ( empty( $payload['needs_db'] ) && empty( $payload['needs_files'] ) ) ) {
			$this->complete_restore( $job, $payload );
			return;
		}

		$probe = RestoreHealthCheck::probe();

		if ( 'fatal' !== $probe['status'] ) {
			Logger::info( 'ok' === $probe['status'] ? 'restore_health_check_passed: the restored site loads.' : 'restore_health_check_skipped: this server can\'t load its own pages (loopback request), so the restored site wasn\'t test-loaded.', array(), $job->id() );
			$this->complete_restore( $job, $payload );
			return;
		}

		$plugin = RestoreHealthCheck::plugin_for_file( $probe['file'] );
		$theme  = '' === $plugin ? RestoreHealthCheck::theme_for_file( $probe['file'] ) : '';
		// The message, without the file/line and stack trace PHP appends.
		$error = (string) preg_replace( '/\s+in\s+\S+:\d+\b.*$|\s*Stack trace:.*$/s', '', wp_strip_all_tags( $probe['message'] ) );
		$error = wp_trim_words( $error, 30, '…' );
		/* translators: %s: the error message, shortened */
		$reason = sprintf( __( 'it stopped the site from loading after the restore (%s)', 'wrensafe' ), $error );

		if ( '' !== $plugin && ( ! defined( 'WPBP_PLUGIN_BASENAME' ) || WPBP_PLUGIN_BASENAME !== $plugin ) ) {
			PluginSafety::deactivate( array( $plugin ), $job->id() );
			PostRestoreIntegrityChecker::add_notice_items(
				array(
					array(
						'plugin' => $plugin,
						'reason' => $reason,
					),
				)
			);
			$payload['restore_deactivated_plugins'][] = $plugin;
			$payload['restore_deactivated'][]         = array( $plugin, $reason );
			Logger::warning( 'restore_plugin_deactivated: ' . $plugin . ' — ' . $reason . '.', array(), $job->id() );
		} elseif ( '' !== $theme && '' !== RestoreHealthCheck::fallback_theme( $theme ) ) {
			$fallback = RestoreHealthCheck::fallback_theme( $theme );
			switch_theme( $fallback );
			PostRestoreIntegrityChecker::add_notice_items(
				array(
					array(
						/* translators: 1: theme folder name, 2: default theme folder name */
						'plugin' => sprintf( __( 'Theme %1$s (switched to %2$s)', 'wrensafe' ), $theme, $fallback ),
						'reason' => $reason,
					),
				)
			);
			Logger::warning( 'restore_theme_switched: ' . $theme . ' → ' . $fallback . ' — ' . $reason . '.', array(), $job->id() );
		} else {
			Logger::warning( 'restore_health_check_failed: the restored site shows a fatal error that Wrensafe can\'t switch off (' . $probe['file'] . ': ' . $error . ').', array(), $job->id() );
			$this->complete_restore( $job, $payload );
			return;
		}

		$payload['health']['attempt'] = $attempt + 1;
		$job->set_payload( $payload );
		$job->update_progress( 98, __( 'Checking that the restored site loads…', 'wrensafe' ) );
	}

	private function complete_restore( RestoreJob $job, array $payload ): void {
		PluginSafety::remove_boot_guard();

		$deactivated = array();

		if ( ! function_exists( 'get_plugins' ) ) {
			require_once Paths::admin_dir() . '/includes/plugin.php'; // WP-CLI and cron runs.
		}

		$installed = get_plugins();

		foreach ( (array) ( $payload['restore_deactivated'] ?? array() ) as $item ) {
			$name = (string) ( $installed[ (string) $item[0] ]['Name'] ?? '' );

			$deactivated[] = array(
				'name'   => '' !== $name ? $name : $item[0],
				'reason' => $item[1],
			);
		}

		$job->mark_completed(
			array(
				'restore_point_id' => $payload['restore_point_id'] ?? null,
				'needs_db'         => $payload['needs_db'] ?? false,
				'needs_files'      => $payload['needs_files'] ?? false,
				'deactivated'      => $deactivated,
			)
		);

		if ( ! empty( $payload['sr_pairs'] ) ) {
			Logger::info(
				'cross_site_restore_completed: restored a backup from ' . untrailingslashit( (string) ( $payload['site_url'] ?? '' ) )
					. ' onto ' . (string) ( $payload['dest_home_url'] ?? '' ) . ' — destination domain preserved.',
				array(),
				$job->id()
			);
		}

		FileSystem::delete_directory( $this->tmp_dir_for_job( $job->id() ) );

		// An uploaded/imported archive is a temporary copy that no backup
		// record points at: once it's restored, remove it rather than leave
		// gigabytes of hidden files on the server.
		$uploaded = (string) ( $payload['uploaded_file'] ?? '' );

		if ( '' !== $uploaded && file_exists( $uploaded ) && PathValidator::assert_within( $uploaded, FileSystem::uploads_dir() ) ) {
			wp_delete_file( $uploaded );
			Logger::info( 'upload_restore_cleanup: the imported archive was removed after a successful restore.', array(), $job->id() );
		}

		Logger::success( 'restore_completed: restore job #' . $job->id() . ' completed.', array(), $job->id() );

		$this->release_restore_lock( $job->id() );
	}

	/**
	 * Write the destination home/siteurl straight into the (freshly swapped)
	 * options table and flush caches, so the destination site keeps its own
	 * domain no matter what the restored backup contained. Used right after
	 * the atomic swap, and again as recovery if a restore fails after the
	 * database went live.
	 */
	private function force_destination_urls( array $payload, int $job_id ): void {
		global $wpdb;

		$dest_home = (string) ( $payload['dest_home_url'] ?? '' );
		$dest_site = (string) ( $payload['dest_site_url'] ?? '' );

		if ( '' === $dest_home ) {
			return;
		}

		// Direct writes, not update_option(): in this request the option
		// cache still holds the values from before the swap, so
		// update_option() would see "no change" and skip the write.
		$wpdb->update( $wpdb->options, array( 'option_value' => $dest_home ), array( 'option_name' => 'home' ) );
		$wpdb->update( $wpdb->options, array( 'option_value' => '' !== $dest_site ? $dest_site : $dest_home ), array( 'option_name' => 'siteurl' ) );

		$this->keep_destination_backup_dir( $payload );

		wp_cache_delete( 'alloptions', 'options' );
		wp_cache_delete( 'home', 'options' );
		wp_cache_delete( 'siteurl', 'options' );
		wp_cache_delete( \WPBP\Core\Settings::OPTION_NAME, 'options' );
		\WPBP\Utils\FileSystem::reset_storage_cache();

		Logger::info( 'restore_home_siteurl_forced_to_destination: home/siteurl set to ' . $dest_home . '.', array(), $job_id );
	}

	/**
	 * Where WordPress keeps its fallback authentication keys and salts (when
	 * wp-config.php doesn't define them): the options table on a single site,
	 * the network's sitemeta on a multisite.
	 *
	 * @return array{0:string,1:string,2:string} Table, name column, value column.
	 */
	private static function secret_key_table(): array {
		global $wpdb;

		return is_multisite() ? array( $wpdb->sitemeta, 'meta_key', 'meta_value' ) : array( $wpdb->options, 'option_name', 'option_value' );
	}

	/**
	 * This site's stored authentication keys and salts, read right before
	 * the database swap.
	 *
	 * @return array<int,array<string,mixed>> Full rows.
	 */
	private function secret_key_rows(): array {
		global $wpdb;

		list( $table, $name_column ) = self::secret_key_table();
		$names                       = Paths::SECRET_KEY_NAMES;

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT * FROM %i WHERE %i IN (' . implode( ', ', array_fill( 0, count( $names ), '%s' ) ) . ')',
				array_merge( array( $table, $name_column ), $names )
			),
			ARRAY_A
		);

		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Put this site's own authentication keys and salts back after the
	 * swap. Backups made before 1.0.1 carry the source site's; keeping them
	 * would sign this site's logins with another site's secrets (and log out
	 * the administrator running this restore). When this site has none in
	 * the database (wp-config.php defines them), the backup's are removed.
	 *
	 * @param array<int,array<string,mixed>> $rows From secret_key_rows().
	 */
	private function keep_secret_keys( array $rows, int $job_id ): void {
		global $wpdb;

		list( $table, $name_column ) = self::secret_key_table();
		$names                       = Paths::SECRET_KEY_NAMES;

		$wpdb->query(
			$wpdb->prepare(
				'DELETE FROM %i WHERE %i IN (' . implode( ', ', array_fill( 0, count( $names ), '%s' ) ) . ')',
				array_merge( array( $table, $name_column ), $names )
			)
		);

		foreach ( $rows as $row ) {
			// New row ids: the restored table may already use the old ones.
			unset( $row['option_id'], $row['meta_id'] );
			$wpdb->insert( $table, $row );
		}

		wp_cache_delete( 'alloptions', 'options' );

		if ( ! empty( $rows ) ) {
			Logger::info( 'restore_secret_keys_kept: this site\'s own authentication keys and salts were kept.', array(), $job_id );
		}
	}

	/**
	 * Keep this server's backup folder after the restored options table went
	 * live: the backup's copy of Wrensafe's settings holds the folder of
	 * the server it was made on (another path, possibly another server).
	 * Direct SQL, like the home/siteurl fix above.
	 */
	private function keep_destination_backup_dir( array $payload ): void {
		global $wpdb;

		if ( ! array_key_exists( 'dest_backup_dir', $payload ) ) {
			return; // A restore started by an older version.
		}

		$wanted = (string) $payload['dest_backup_dir'];
		$name   = \WPBP\Core\Settings::OPTION_NAME;
		$raw    = $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM `{$wpdb->options}` WHERE option_name = %s", $name ) );

		if ( null === $raw ) {
			if ( '' !== $wanted ) {
				$wpdb->insert(
					$wpdb->options,
					array(
						'option_name'  => $name,
						'option_value' => maybe_serialize( array( 'backup_dir' => $wanted ) ),
						'autoload'     => 'on',
					)
				);
			}
			return;
		}

		// The row came from the backup: never let it create PHP objects.
		$settings = \WPBP\Utils\Serializer::safe_unserialize( (string) $raw );
		$settings = is_array( $settings ) ? $settings : array();

		if ( (string) ( $settings['backup_dir'] ?? '' ) === $wanted ) {
			return;
		}

		$settings['backup_dir'] = $wanted;
		$wpdb->update( $wpdb->options, array( 'option_value' => maybe_serialize( $settings ) ), array( 'option_name' => $name ) );
	}

	/**
	 * If a restore fails AFTER the database swap went live, the options table
	 * may carry the source site's URLs — attempt to force them back to the
	 * destination values captured before the restore, and log the outcome.
	 */
	private function attempt_domain_recovery( RestoreJob $job ): void {
		$payload = $job->payload();

		if ( empty( $payload['db_swapped'] ) || empty( $payload['dest_home_url'] ) ) {
			return;
		}

		Logger::warning( 'restore_domain_recovery_attempted: forcing home/siteurl back to ' . $payload['dest_home_url'] . ' after a failed restore.', array(), $job->id() );

		try {
			$this->force_destination_urls( $payload, $job->id() );
			Logger::info( 'restore_domain_recovery_completed: destination home/siteurl restored.', array(), $job->id() );
		} catch ( \Throwable $e ) {
			Logger::error( 'restore_domain_recovery_failed: ' . Errors::text( $e ), array(), $job->id() );
		}
	}

	/**
	 * Deliberately does not write WordPress core's `.maintenance` file —
	 * see RestoreSiteMaintenance for why that would deadlock this chunked,
	 * admin-ajax.php-driven restore.
	 */
	private function enable_maintenance_mode(): void {
		RestoreSiteMaintenance::enable();
	}

	private function disable_maintenance_mode(): void {
		RestoreSiteMaintenance::disable();
	}
}

// phpcs:enable WordPress.WP.AlternativeFunctions
