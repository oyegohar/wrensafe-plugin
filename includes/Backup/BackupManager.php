<?php
/**
 * Drives a BackupJob through its phases, one bounded step per call.
 *
 * Callers (BackupAjax::process) invoke process_step() repeatedly until the
 * job reaches a terminal status. Each step does a fixed amount of work so
 * it stays within typical PHP execution time limits no matter how large
 * the site is — this is what makes the engine timeout-safe.
 *
 * The final phase only stages files and a database dump under the
 * protected storage directory; turning that staged output into a .wbp
 * archive is handled by the Archive Writer (next development phase).
 *
 * @package WPBP\Backup
 */

namespace WPBP\Backup;

use WPBP\Core\Database\BackupsRepository;
use WPBP\Core\Database\JobsRepository;
use WPBP\Core\Settings;
use WPBP\Remote\RemoteStorage;
use WPBP\Restore\ArchiveReader;
use WPBP\Utils\FileSystem;
use WPBP\Utils\Logger;
use WPBP\Utils\Sanitizer;

defined( 'ABSPATH' ) || exit;

// Backup archives and upload chunks are streamed in bounded blocks with native
// file handles (fopen/fread/fwrite/rename): WP_Filesystem has no streaming API.
// Every path is inside Wrensafe's protected storage directory, a PHP upload
// temp file, or validated by WPBP\Security\PathValidator.
// phpcs:disable WordPress.WP.AlternativeFunctions

// Exception messages here are stored as a job's error_message and logged; they
// reach the browser only as JSON rendered with jQuery .text(), never as HTML.
// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped

class BackupManager {

	private const FILE_TYPES = array( 'full', 'files', 'media', 'uploads', 'themes', 'plugins', 'custom' );
	private const DB_TYPES   = array( 'full', 'database' );

	/** Transient holding the id of a backup job the user has asked to cancel. */
	const CANCEL_TRANSIENT = 'wpbp_backup_cancel';

	/** Transient that throttles the stale-job watchdog to one sweep per interval. */
	private const WATCHDOG_TRANSIENT = 'wpbp_backup_watchdog_ran';

	/** A running job whose heartbeat is older than this is considered dead. */
	const STALE_JOB_MINUTES = 10;

	/**
	 * Wall-clock deadline for the current AJAX step. Each phase does bounded
	 * work and returns before this, persisting its cursor so the browser's
	 * next request resumes it — the fix for live hosts (max_execution_time
	 * 30) killing one huge archive/checksum request and leaving the job
	 * stuck "running" at 90% with nothing logged.
	 */
	private static function request_deadline(): float {
		$max    = (int) ini_get( 'max_execution_time' );
		$budget = ( $max > 0 && $max < 20 ) ? max( 4, $max - 6 ) : 10;

		return microtime( true ) + $budget;
	}

	public function tmp_dir_for_job( int $job_id ): string {
		return FileSystem::job_tmp_dir( $job_id );
	}

	/**
	 * Flag a running backup job for cancellation. The job stops at the next
	 * safe checkpoint (the start of its next bounded step), before any archive
	 * is finalized into a completed backup record.
	 */
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
	 * Perform one bounded unit of work for the given job.
	 *
	 * $encryption_password is never persisted — it only exists in memory
	 * for this single request, used solely if this step happens to be the
	 * archive step of a backup with encryption enabled.
	 */
	public function process_step( BackupJob $job, string $encryption_password = '' ): void {
		$payload = $job->payload();
		$phase   = $payload['phase'] ?? 'init';

		// Extra head-room where the host allows it; each phase still returns
		// within its own deadline so hosts that ignore this stay safe.
		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( 0 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.PHP.DiscouragedPHPFunctions.runtime_configuration_set_time_limit, Squiz.PHP.DiscouragedFunctions.Discouraged -- extra time where the host allows it; every step is still time-budgeted.
		}

		// Honour a cancellation request at the start of any step. Every phase
		// before 'record' is safe to cancel — nothing exists yet except temp
		// files and (during the archive phases) a partial archive, both of
		// which are deleted here. 'record'/'upload_cloud' run after a completed
		// backup record exists, so cancellation there is intentionally a no-op.
		if ( self::is_cancel_requested( $job->id() ) && ! in_array( $phase, array( 'record', 'upload_cloud' ), true ) ) {
			$this->clear_cancel();
			FileSystem::delete_directory( $this->tmp_dir_for_job( $job->id() ) );

			// Remove the partially written archive, if the writer had started.
			if ( ! empty( $payload['aw_dest'] ) ) {
				foreach ( array( $payload['aw_dest'], $payload['aw_dest'] . '.encrypting.tmp' ) as $partial ) {
					if ( file_exists( $partial ) ) {
						wp_delete_file( $partial );
					}
				}
			}

			$job->cancel();
			Logger::warning( 'backup_cancelled: job #' . $job->id() . ' cancelled at phase "' . $phase . '"; temporary files cleaned up.', array(), $job->id() );
			return;
		}

		try {
			switch ( $phase ) {
				case 'init':
					$this->step_init( $job, $payload );
					break;

				case 'scan_files':
					$this->step_scan_files( $job, $payload );
					break;

				case 'export_db':
					$this->step_export_db( $job, $payload );
					break;

				case 'archive':
					$this->step_archive_begin( $job, $payload );
					break;

				case 'archive_db':
					$this->step_archive_db( $job, $payload );
					break;

				case 'archive_files':
					$this->step_archive_files( $job, $payload );
					break;

				case 'archive_meta':
					$this->step_archive_meta( $job, $payload );
					break;

				case 'encrypt':
					$this->step_encrypt( $job, $payload, $encryption_password );
					break;

				case 'checksum':
					$this->step_checksum( $job, $payload );
					break;

				case 'record':
					$this->step_record( $job, $payload );
					break;

				case 'verify_backup':
					$this->step_verify_backup( $job, $payload );
					break;

				case 'remote_copy':
					$this->step_remote_copy( $job, $payload );
					break;

				case 'upload_cloud':
					$this->step_upload_cloud( $job, $payload );
					break;

				default:
					$job->mark_failed( __( 'Unknown backup phase.', 'wrensafe' ) );
			}
		} catch ( \Throwable $e ) {
			Logger::failed( 'backup_failed: ' . ( $payload['backup_type'] ?? 'full' ) . ' backup job #' . $job->id() . ' failed during ' . $phase . ': ' . $e->getMessage(), array(), $job->id() );
			$job->mark_failed( $e->getMessage() );
		}
	}

	/**
	 * Watchdog: mark long-silent "running" backup jobs as failed so a PHP
	 * request the server killed mid-step (fatal, OOM, hard timeout) can never
	 * leave a job stuck at 90% forever with nothing in the Activity Logs —
	 * exactly what the live debug bundle showed. Throttled to one sweep per
	 * 5 minutes; called from BackupAjax and the Backup/Dashboard pages.
	 */
	public static function fail_stale_jobs(): void {
		if ( false !== get_transient( self::WATCHDOG_TRANSIENT ) ) {
			return;
		}

		set_transient( self::WATCHDOG_TRANSIENT, 1, 5 * MINUTE_IN_SECONDS );

		foreach ( JobsRepository::list_stale_running( 'backup', self::STALE_JOB_MINUTES ) as $row ) {
			$job = BackupJob::get( (int) $row['id'] );

			if ( null === $job ) {
				continue;
			}

			$payload = $job->payload();
			$phase   = (string) ( $payload['phase'] ?? 'unknown' );
			$step    = (string) ( $row['current_step'] ?? '' );

			$message = sprintf(
				/* translators: %s: backup phase the job stopped in */
				__( 'Backup failed: the job stopped responding during %s (the server likely killed the request — timeout, memory limit, or a fatal error).', 'wrensafe' ),
				'' !== $step ? $step : $phase
			);

			$job->mark_failed( $message );
			Logger::failed( 'backup_stale_job_failed: job #' . $job->id() . ' had no heartbeat for over ' . self::STALE_JOB_MINUTES . ' minutes (phase: ' . $phase . ', step: ' . $step . '); marked failed and cleaned up.', array(), $job->id() );

			// Clean up whatever the dead job left behind.
			FileSystem::delete_directory( FileSystem::job_tmp_dir( $job->id() ) );
			if ( ! empty( $payload['aw_dest'] ) && file_exists( $payload['aw_dest'] ) && empty( $payload['backup_id'] ) ) {
				wp_delete_file( $payload['aw_dest'] );
			}
		}
	}

	/**
	 * Log a one-line environment summary and verify the storage/temp
	 * directories are actually writable before any work happens, so a
	 * live-host failure (permissions, open_basedir, security module, full
	 * disk) surfaces as an exact reason instead of a generic error later.
	 */
	private function check_backup_environment( BackupJob $job, string $tmp_dir ): void {
		Logger::info( 'backup_preflight_started: checking environment and storage paths.', array(), $job->id() );

		$backups_dir = FileSystem::backups_dir();
		$disk_free   = function_exists( 'disk_free_space' ) ? @disk_free_space( dirname( $backups_dir ) ) : false; // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged

		Logger::info(
			'backup_environment_summary: PHP ' . PHP_VERSION
				. ', WP ' . get_bloginfo( 'version' )
				. ', memory_limit ' . (string) ini_get( 'memory_limit' )
				. ', max_execution_time ' . (string) ini_get( 'max_execution_time' )
				. ', archive backend ' . ( class_exists( '\ZipArchive' ) ? 'ZipArchive' : ( class_exists( '\PharData' ) ? 'PharData' : 'none' ) )
				. ( false !== $disk_free ? ', disk free ' . size_format( (int) $disk_free ) : ', disk free unknown' ) . '.',
			array(),
			$job->id()
		);

		foreach ( array(
			'backups directory' => $backups_dir,
			'temp directory'    => $tmp_dir,
		) as $label => $dir ) {
			if ( ! is_dir( $dir ) || ! wp_is_writable( $dir ) ) {
				$message = sprintf(
					/* translators: 1: directory role, 2: directory path */
					__( 'The %1$s is not writable (%2$s). Check file permissions/ownership or open_basedir restrictions on this server.', 'wrensafe' ),
					$label,
					basename( dirname( $dir ) ) . '/' . basename( $dir )
				);

				Logger::failed( 'backup_preflight_failed: ' . $message, array(), $job->id() );
				throw new \RuntimeException( $message );
			}
		}

		Logger::info( 'backup_storage_paths_checked: backups and temp directories exist and are writable.', array(), $job->id() );
		Logger::info( 'backup_preflight_passed: environment and storage checks passed.', array(), $job->id() );
	}

	private function step_init( BackupJob $job, array $payload ): void {
		$type = $payload['backup_type'] ?? 'full';

		$tmp_dir = $this->tmp_dir_for_job( $job->id() );
		FileSystem::ensure_protected_directory( $tmp_dir );
		FileSystem::ensure_protected_directory( FileSystem::backups_dir() );

		$this->check_backup_environment( $job, $tmp_dir );

		$needs_files = in_array( $type, self::FILE_TYPES, true );
		$needs_db    = in_array( $type, self::DB_TYPES, true );

		/**
		 * Choose the backup chain for this job. The free plugin always makes a
		 * full backup; an add-on can turn it into an incremental or
		 * differential link by returning a chain mode and its baseline.
		 *
		 * @param array{chain_mode:string,baseline_backup_id:int|null} $chain
		 * @param array                                               $payload Job payload.
		 */
		$chain              = (array) apply_filters(
			'wpbp_backup_chain',
			array(
				'chain_mode'         => 'full',
				'baseline_backup_id' => null,
			),
			$payload
		);
		$chain_mode         = in_array( $chain['chain_mode'] ?? '', array( 'full', 'incremental', 'differential' ), true ) ? $chain['chain_mode'] : 'full';
		$baseline_backup_id = 'full' === $chain_mode || empty( $chain['baseline_backup_id'] ) ? null : (int) $chain['baseline_backup_id'];
		$chain_mode         = null === $baseline_backup_id ? 'full' : $chain_mode;

		$payload['chain_mode']         = $chain_mode;
		$payload['baseline_backup_id'] = $baseline_backup_id;
		$payload['needs_files']        = $needs_files;
		$payload['needs_db']           = $needs_db;
		$payload['tables']             = $needs_db ? ( new DatabaseExporter() )->get_tables() : array();
		$payload['table_index']        = 0;
		$payload['row_offset']         = 0;
		$payload['db_manifest']        = array();
		$payload['file_count']         = 0;
		$payload['total_size']         = 0;

		if ( $needs_files ) {
			$payload['phase'] = 'scan_files';
		} elseif ( $needs_db ) {
			$payload['phase'] = 'export_db';
		} else {
			$payload['phase'] = 'archive';
		}

		$job->set_payload( $payload );
		$job->mark_running();
		$job->update_progress( 5, __( 'Preparing backup…', 'wrensafe' ) );

		Logger::info( 'backup_type_started: ' . $type . ' backup job #' . $job->id() . ' started.', array(), $job->id() );
	}

	private function step_scan_files( BackupJob $job, array $payload ): void {
		$type    = $payload['backup_type'] ?? 'full';
		$tmp_dir = $this->tmp_dir_for_job( $job->id() );

		$scanner = new FileScanner();
		$roots   = $scanner->get_roots_for_type( $type, $payload['custom_paths'] ?? array() );
		$cursor  = isset( $payload['scan_cursor'] ) && is_array( $payload['scan_cursor'] ) ? $payload['scan_cursor'] : array();

		// The directory stack lives in a tmp file, not the job payload, so a
		// site with thousands of folders doesn't bloat every payload write.
		$stack_file = $tmp_dir . 'scan-stack.json';

		if ( ! empty( $cursor ) && file_exists( $stack_file ) ) {
			$cursor['stack'] = json_decode( (string) file_get_contents( $stack_file ), true );
		}

		$handle = fopen( $tmp_dir . 'files.jsonl', empty( $cursor ) ? 'w' : 'a' );
		$done   = $scanner->scan_step( $roots, $handle, $this->resolve_baseline_checksums( $payload ), $cursor, self::request_deadline() );
		fclose( $handle );

		file_put_contents( $stack_file, wp_json_encode( $cursor['stack'] ) );
		$cursor['stack'] = null;

		$file_count = (int) $cursor['file_count'];

		if ( ! $done ) {
			$payload['scan_cursor'] = $cursor;
			$job->set_payload( $payload );
			$job->update_progress(
				5,
				sprintf( /* translators: %s: number of files found so far */ __( 'Scanning files… %s found so far', 'wrensafe' ), number_format_i18n( $file_count ) )
			);
			return;
		}

		wp_delete_file( $stack_file );
		unset( $payload['scan_cursor'] );

		$payload['file_count'] = $file_count;
		$payload['total_size'] = (int) $cursor['total_size'];
		$payload['phase']      = $payload['needs_db'] ? 'export_db' : 'archive';

		$job->set_payload( $payload );
		$job->update_progress(
			$payload['needs_db'] ? 35 : 82,
			sprintf( /* translators: %d: number of files */ __( 'Scanned %d files', 'wrensafe' ), $file_count )
		);

		Logger::info( 'Backup job #' . $job->id() . ' scanned ' . $file_count . ' files (' . size_format( (int) $cursor['total_size'] ) . ').', array(), $job->id() );

		if ( $cursor['excluded_count'] > 0 ) {
			Logger::info(
				'backup_exclusion_applied: skipped ' . $cursor['excluded_count'] . ' files (' . size_format( (int) $cursor['excluded_size'] ) . ') — other plugins\' backup archives, cache folders, and custom exclusions (see Settings → Backup).',
				array(),
				$job->id()
			);
		}
	}

	/**
	 * Load the per-file checksums of whatever this backup should be
	 * compared against: the baseline itself for a differential, or the
	 * latest backup in the chain for an incremental.
	 *
	 * @return array<string,string>|null Entry name => sha256, or null for a full backup.
	 */
	private function resolve_baseline_checksums( array $payload ): ?array {
		if ( empty( $payload['baseline_backup_id'] ) ) {
			return null;
		}

		/**
		 * Checksums of the backup an incremental/differential job is compared
		 * against (entry name => sha256). Files whose checksum matches are
		 * left out of the new archive. Provided by an add-on.
		 *
		 * @param array|null $checksums null for a full backup.
		 * @param array      $payload   Job payload.
		 */
		$checksums = apply_filters( 'wpbp_backup_baseline_checksums', null, $payload );

		return is_array( $checksums ) ? $checksums : null;
	}

	/**
	 * Export each table to its own schema/data SQL file under the job's tmp
	 * dir (db/schema/{slug}.schema.sql, db/data/{slug}.data.sql), so the
	 * Archive Writer can pack a per-table database/manifest.json instead of
	 * one monolithic database.sql — this is what lets the Restore Engine
	 * replay tables individually and rewrite their prefix on the way in.
	 */
	private function step_export_db( BackupJob $job, array $payload ): void {
		$tables   = $payload['tables'] ?? array();
		$tmp_dir  = $this->tmp_dir_for_job( $job->id() );
		$deadline = self::request_deadline();
		$exporter = new DatabaseExporter();
		$table    = '';

		wp_mkdir_p( $tmp_dir . 'db/schema' );
		wp_mkdir_p( $tmp_dir . 'db/data' );

		// As many row chunks as fit in this request (one chunk per request
		// made a big wp_posts/wp_postmeta export take hundreds of requests).
		do {
			$table_index = (int) ( $payload['table_index'] ?? 0 );
			$row_offset  = (int) ( $payload['row_offset'] ?? 0 );

			if ( $table_index >= count( $tables ) ) {
				break;
			}

			$table = $tables[ $table_index ];
			$slug  = Sanitizer::table_slug( $table );

			if ( 0 === $row_offset ) {
				$schema_handle = fopen( $tmp_dir . 'db/schema/' . $slug . '.schema.sql', 'w' );
				$exporter->export_create_statement( $table, $schema_handle );
				fclose( $schema_handle );
			}

			$data_handle = fopen( $tmp_dir . 'db/data/' . $slug . '.data.sql', 0 === $row_offset ? 'w' : 'a' );
			$written     = $exporter->export_data_chunk( $table, $row_offset, $data_handle );
			fclose( $data_handle );

			if ( $written < DatabaseExporter::rows_per_chunk() ) {
				$payload['db_manifest'][] = array(
					'source_table' => $table,
					'slug'         => $slug,
					'rows'         => $row_offset + $written,
				);
				$payload['table_index']   = $table_index + 1;
				$payload['row_offset']    = 0;
			} else {
				$payload['row_offset'] = $row_offset + $written;
			}
		} while ( microtime( true ) < $deadline );

		if ( (int) $payload['table_index'] >= count( $tables ) ) {
			$payload['phase'] = 'archive';
			$progress         = 82;
		} else {
			$base     = $payload['needs_files'] ? 35 : 10;
			$progress = (int) ( $base + ( $payload['table_index'] / max( 1, count( $tables ) ) ) * ( 82 - $base ) );
		}

		$job->set_payload( $payload );
		$job->update_progress( $progress, sprintf( /* translators: %s: database table name */ __( 'Exporting table %s', 'wrensafe' ), $table ) );
	}

	/**
	 * Phase 'archive': set up the resumable archive session (destination,
	 * container backend, cursors). Bounded — no file content is packed here.
	 */
	private function step_archive_begin( BackupJob $job, array $payload ): void {
		( new ArchiveWriter() )->begin( $job, $payload );

		$payload['phase'] = ! empty( $payload['needs_db'] ) ? 'archive_db' : 'archive_files';
		$job->set_payload( $payload );
		$job->update_progress( 84, __( 'Building archive…', 'wrensafe' ) );
	}

	/**
	 * Phase 'archive_db': pack database SQL files in time-budgeted batches.
	 */
	private function step_archive_db( BackupJob $job, array $payload ): void {
		$done = ( new ArchiveWriter() )->add_database_batch( $job, $payload, self::request_deadline() );

		if ( $done ) {
			$payload['phase'] = ! empty( $payload['needs_files'] ) ? 'archive_files' : 'archive_meta';
		}

		$job->set_payload( $payload );
		$job->update_progress(
			$done ? 86 : 85,
			sprintf(
				/* translators: 1: tables packed, 2: total tables */
				__( 'Building archive (database %1$d / %2$d)…', 'wrensafe' ),
				(int) $payload['aw_db_index'],
				count( $payload['aw_db_manifest'] )
			)
		);
	}

	/**
	 * Phase 'archive_files': pack content files in time/byte-budgeted batches,
	 * resuming from the persisted files.jsonl byte offset each request.
	 */
	private function step_archive_files( BackupJob $job, array $payload ): void {
		$done = ( new ArchiveWriter() )->add_files_batch( $job, $payload, self::request_deadline() );

		if ( $done ) {
			$payload['phase'] = 'archive_meta';
		}

		$total    = max( 1, (int) ( $payload['file_count'] ?? 1 ) );
		$added    = (int) $payload['aw_files_added'];
		$progress = 86 + (int) ( min( 1, $added / $total ) * 5 ); // 86 → 91.

		$job->set_payload( $payload );
		$job->update_progress(
			$done ? 91 : min( 91, $progress ),
			sprintf(
				/* translators: 1: files packed, 2: total files */
				__( 'Building archive (%1$d / %2$d files)…', 'wrensafe' ),
				$added,
				$total
			)
		);
	}

	/**
	 * Phase 'archive_meta': write the archive's JSON metadata entries and
	 * verify the file landed on disk.
	 */
	private function step_archive_meta( BackupJob $job, array $payload ): void {
		( new ArchiveWriter() )->finish( $job, $payload );

		$payload['ck_offset'] = 0;

		if ( ! empty( $payload['encrypt'] ) ) {
			// Encrypt first, then checksum the encrypted file — the encrypted
			// file is the final artifact, so hashing the plaintext would be a
			// wasted pass over the whole archive.
			$payload['phase']     = 'encrypt';
			$payload['enc_state'] = array();
			$job->set_payload( $payload );
			$job->update_progress( 92, __( 'Encrypting backup…', 'wrensafe' ) );
			return;
		}

		$payload['phase'] = 'checksum';

		$job->set_payload( $payload );
		$job->update_progress( 93, __( 'Calculating checksum…', 'wrensafe' ) );

		Logger::info( 'backup_checksum_started: hashing final archive (' . basename( (string) $payload['aw_dest'] ) . ').', array(), $job->id() );
	}

	/**
	 * Phase 'checksum': stream-hash the final archive in time-budgeted chunks,
	 * persisting the rolling hash state between requests so a multi-GB archive
	 * never needs one long request (the exact spot live jobs died at 90%).
	 */
	private function step_checksum( BackupJob $job, array $payload ): void {
		$result = $this->checksum_chunk( $job, $payload );

		if ( null === $result ) {
			// Not finished yet — cursor persisted, next request continues.
			$job->set_payload( $payload );
			$job->update_progress( 94, __( 'Calculating checksum…', 'wrensafe' ) );
			return;
		}

		$payload['final_checksum'] = $result;
		$payload['phase']          = 'record';

		$job->set_payload( $payload );
		$job->update_progress( 96, __( 'Saving backup record…', 'wrensafe' ) );

		Logger::info( 'backup_checksum_completed: archive hashed (chunked streaming).', array(), $job->id() );
	}

	/**
	 * Hash the next slice of the final archive. Returns the finished hex
	 * digest, or null when the deadline hit first (state persisted for the
	 * next request). The rolling HashContext is serialized to a state file in
	 * the job's tmp dir (supported since PHP 8.1); when serialization isn't
	 * available the whole remaining file is hashed in this request as a
	 * fallback.
	 */
	private function checksum_chunk( BackupJob $job, array &$payload ): ?string {
		$path       = (string) $payload['aw_dest'];
		$state_file = $this->tmp_dir_for_job( $job->id() ) . 'hash.state';
		$offset     = (int) ( $payload['ck_offset'] ?? 0 );
		$deadline   = self::request_deadline();

		clearstatcache( true, $path );

		if ( ! file_exists( $path ) || ! is_readable( $path ) ) {
			throw new \RuntimeException( __( 'The backup archive disappeared before its checksum could be calculated.', 'wrensafe' ) );
		}

		// Restore the rolling hash state from the previous request, or start fresh.
		$context = null;
		if ( $offset > 0 && file_exists( $state_file ) ) {
			try {
				$context = unserialize( (string) file_get_contents( $state_file ), array( 'allowed_classes' => array( \HashContext::class ) ) ); // phpcs:ignore
			} catch ( \Throwable $e ) {
				$context = null;
			}
		}

		if ( ! $context instanceof \HashContext ) {
			$context = hash_init( IntegrityChecker::ALGORITHM );
			$offset  = 0;
		}

		$handle = fopen( $path, 'rb' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen

		if ( false === $handle ) {
			throw new \RuntimeException( __( 'The backup archive could not be opened for its checksum.', 'wrensafe' ) );
		}

		if ( $offset > 0 ) {
			fseek( $handle, $offset );
		}

		while ( ! feof( $handle ) ) {
			$buffer = fread( $handle, 4194304 ); // 4 MB.

			if ( false === $buffer ) {
				fclose( $handle );
				throw new \RuntimeException( __( 'A read error occurred while calculating the backup checksum.', 'wrensafe' ) );
			}

			hash_update( $context, $buffer );
			$offset += strlen( $buffer );

			if ( microtime( true ) >= $deadline && ! feof( $handle ) ) {
				fclose( $handle );

				// Persist the rolling state; if this PHP build can't serialize
				// HashContext, finish the rest right now instead of stalling.
				try {
					file_put_contents( $state_file, serialize( $context ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize
					$payload['ck_offset'] = $offset;
					return null;
				} catch ( \Throwable $e ) {
					$whole = IntegrityChecker::streaming_sha256( $path );

					if ( is_wp_error( $whole ) ) {
						throw new \RuntimeException( $whole->get_error_message() );
					}

					return $whole;
				}
			}
		}

		fclose( $handle );

		if ( file_exists( $state_file ) ) {
			wp_delete_file( $state_file );
		}

		$digest = hash_final( $context );

		if ( '' === $digest ) {
			throw new \RuntimeException( __( 'The backup checksum could not be calculated.', 'wrensafe' ) );
		}

		return $digest;
	}

	/**
	 * Phase 'encrypt': encrypt the finished archive in time-budgeted steps
	 * (a 2-3 GB archive can't be encrypted inside one 30-second request).
	 * The password arrives with every request and is never stored.
	 */
	private function step_encrypt( BackupJob $job, array $payload, string $encryption_password ): void {
		$archive = (string) $payload['aw_dest'];
		$tmp     = $archive . '.encrypting.tmp';

		if ( '' === $encryption_password ) {
			$this->discard_unrecorded_archive( $archive, $tmp, $job );
			$job->mark_failed( __( 'Encryption is enabled for this backup but no password was provided.', 'wrensafe' ) );
			return;
		}

		$state = is_array( $payload['enc_state'] ?? null ) ? $payload['enc_state'] : array();

		try {
			$done = ( new BackupEncryption() )->encrypt_step( $archive, $tmp, $encryption_password, $state, self::request_deadline() );
		} catch ( \Throwable $e ) {
			$this->discard_unrecorded_archive( $archive, $tmp, $job );
			$job->mark_failed( $e->getMessage() );
			return;
		}

		if ( ! $done ) {
			$payload['enc_state'] = $state;
			$job->set_payload( $payload );

			$size = max( 1, (int) @filesize( $archive ) ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			$job->update_progress(
				92,
				sprintf(
					/* translators: %d: percent of the archive encrypted */
					__( 'Encrypting backup… %d%%', 'wrensafe' ),
					min( 99, (int) ( (int) $state['in'] * 100 / $size ) )
				)
			);
			return;
		}

		if ( ! rename( $tmp, $archive ) ) {
			$this->discard_unrecorded_archive( $archive, $tmp, $job );
			$job->mark_failed( __( 'Backup encryption failed: the encrypted file could not be moved into place.', 'wrensafe' ) );
			return;
		}

		unset( $payload['enc_state'] );
		$payload['encrypted'] = true;
		$payload['phase']     = 'checksum';
		$payload['ck_offset'] = 0;

		$job->set_payload( $payload );
		$job->update_progress( 93, __( 'Calculating checksum…', 'wrensafe' ) );

		Logger::info( 'backup_encrypted: archive encrypted (AES-256).', array(), $job->id() );
	}

	/**
	 * Encryption was requested but can't be completed: remove the plaintext
	 * archive (no backup record exists yet) — the user asked for this data
	 * never to be stored unencrypted.
	 */
	private function discard_unrecorded_archive( string $archive, string $tmp, BackupJob $job ): void {
		foreach ( array( $archive, $tmp ) as $path ) {
			if ( '' !== $path && file_exists( $path ) ) {
				wp_delete_file( $path );
			}
		}

		FileSystem::delete_directory( $this->tmp_dir_for_job( $job->id() ) );
		Logger::failed( 'backup_encryption_failed: job #' . $job->id() . ' — the unencrypted archive was deleted because encryption could not be completed.', array(), $job->id() );
	}

	/**
	 * Phase 'record': insert the completed backup record (checksum of the
	 * final — possibly encrypted — file already computed), clean up, and
	 * complete or hand off to the cloud upload.
	 */
	private function step_record( BackupJob $job, array $payload ): void {
		$backup_id = ( new ArchiveWriter() )->create_record( $job, $payload, (string) $payload['final_checksum'] );

		FileSystem::delete_directory( $this->tmp_dir_for_job( $job->id() ) );

		if ( ! empty( $payload['encrypted'] ) ) {
			BackupsRepository::update( $backup_id, array( 'is_encrypted' => 1 ) );
		}

		$payload['backup_id'] = $backup_id;

		// Optional check of the finished archive (Settings → Backup). Rollback
		// points, made in the middle of a restore, are checked by the restore.
		if ( Settings::get( 'verify_after_backup', false ) && 0 !== strpos( (string) ( $payload['note'] ?? '' ), 'Automatic rollback point' ) ) {
			$verify                   = \WPBP\Backup\VerifyManager::start( $backup_id );
			$payload['verify_job_id'] = (string) $verify['job_id'];
			$payload['phase']         = 'verify_backup';
			$job->set_payload( $payload );
			$job->update_progress( 95, __( 'Checking the backup…', 'wrensafe' ) );
			return;
		}

		$this->after_checks( $job, $payload );
	}

	/**
	 * Phase 'verify_backup': run the same integrity check as Verify in the
	 * Backup Browser. A backup that fails it is not kept as good: the job
	 * fails (and the failure email is sent), and it isn't copied off-site.
	 */
	private function step_verify_backup( BackupJob $job, array $payload ): void {
		$state = \WPBP\Backup\VerifyManager::step( (string) ( $payload['verify_job_id'] ?? '' ) );

		if ( null === $state ) {
			Logger::warning( 'backup_verify_skipped: the check of backup #' . (int) $payload['backup_id'] . ' couldn’t continue; run Verify in the Backup Browser.', array(), $job->id() );
			$this->after_checks( $job, $payload );
			return;
		}

		if ( ! in_array( $state['status'], array( 'completed', 'failed' ), true ) ) {
			$job->update_progress( 95, __( 'Checking the backup…', 'wrensafe' ) );
			return;
		}

		if ( 'failed' === ( $state['result'] ?? '' ) ) {
			/* translators: %s: what the check found */
			$job->mark_failed( sprintf( __( 'The backup was made, but it failed its check: %s', 'wrensafe' ), (string) ( $state['message'] ?? '' ) ) );
			return;
		}

		$payload['verified'] = (string) ( $state['result'] ?? 'passed' );

		if ( 'warning' === $payload['verified'] ) {
			Logger::warning( 'backup_verified_with_warnings: backup #' . (int) $payload['backup_id'] . ' was checked after it was made, with warnings: ' . implode( ' ', (array) ( $state['warnings'] ?? array() ) ), array(), $job->id() );
		} else {
			Logger::info( 'backup_verified: backup #' . (int) $payload['backup_id'] . ' passed its check after it was made.', array(), $job->id() );
		}
		$this->after_checks( $job, $payload );
	}

	/**
	 * After the archive is recorded (and checked): copy it off-site when
	 * Remote storage asks for it, then finish.
	 */
	private function after_checks( BackupJob $job, array $payload ): void {
		if ( RemoteStorage::wants( $payload ) ) {
			$payload['phase']        = 'remote_copy';
			$payload['remote_state'] = array();
			$job->set_payload( $payload );
			/* translators: %s: storage name, e.g. FTP / FTPS */
			$job->update_progress( 96, sprintf( __( 'Copying the backup to %s…', 'wrensafe' ), RemoteStorage::types()[ RemoteStorage::get()['type'] ] ?? '' ) );
			return;
		}

		$this->finish( $job, $payload );
	}

	/**
	 * Phase 'remote_copy': upload the backup to the remote storage in
	 * bounded steps. A failed copy is reported, but the backup itself is
	 * complete and stays on this server.
	 */
	private function step_remote_copy( BackupJob $job, array $payload ): void {
		$backup = BackupsRepository::find( (int) $payload['backup_id'] );
		$state  = (array) ( $payload['remote_state'] ?? array() );
		$label  = RemoteStorage::types()[ RemoteStorage::get()['type'] ] ?? '';

		if ( null === $backup ) {
			$this->finish( $job, $payload );
			return;
		}

		try {
			$done = RemoteStorage::copy_step( $state, (string) $backup['file_path'], (string) $backup['file_name'], self::remote_deadline() );
		} catch ( \Throwable $e ) {
			RemoteStorage::abort( $state );
			RemoteStorage::record( (int) $backup['id'], false, RemoteStorage::error_text( $e ) );
			$payload['remote_copy'] = 'failed';
			$this->finish( $job, $payload );
			return;
		}

		$payload['remote_state'] = $state;

		if ( ! $done ) {
			$job->set_payload( $payload );
			$percent = ! empty( $state['size'] ) ? (int) floor( 100 * (int) $state['offset'] / (int) $state['size'] ) : 0;
			/* translators: 1: storage name, 2: percentage */
			$job->update_progress( 96 + (int) floor( $percent * 3 / 100 ), sprintf( __( 'Copying the backup to %1$s (%2$d%%)…', 'wrensafe' ), $label, $percent ) );
			return;
		}

		RemoteStorage::record( (int) $backup['id'], true );
		$payload['remote_copy'] = 'completed';
		$this->finish( $job, $payload );
	}

	/** Time for one remote-copy request, inside PHP's own limit. */
	private static function remote_deadline(): float {
		$max = (int) ini_get( 'max_execution_time' );

		return microtime( true ) + ( ( $max > 0 && $max < 30 ) ? max( 4, $max - 10 ) : 15 );
	}

	/**
	 * Complete the job, or hand the backup to an add-on's cloud destination
	 * first (phase 'upload_cloud').
	 */
	private function finish( BackupJob $job, array $payload ): void {
		$destination = $payload['destination'] ?? 'local';

		if ( 'local' === $destination || ! is_numeric( $destination ) ) {
			$result = array(
				'backup_id'   => (int) $payload['backup_id'],
				'backup_type' => $payload['backup_type'] ?? 'full',
			);

			foreach ( array( 'verified', 'remote_copy' ) as $key ) {
				if ( isset( $payload[ $key ] ) ) {
					$result[ $key ] = $payload[ $key ];
				}
			}

			$job->set_payload( $payload );
			$job->mark_completed( $result );
			Logger::success( 'backup_completed: ' . ( $payload['backup_type'] ?? 'full' ) . ' backup job #' . $job->id() . ' completed, archive #' . (int) $payload['backup_id'] . '.', array(), $job->id() );
			return;
		}

		$payload['phase'] = 'upload_cloud';
		$job->set_payload( $payload );
		$job->update_progress( 98, __( 'Uploading to cloud storage…', 'wrensafe' ) );
	}

	/**
	 * Phase 'upload_cloud': hand the finished backup to a remote destination
	 * registered by an add-on. The free plugin has no
	 * remote destinations, so without a handler the backup simply completes
	 * locally.
	 */
	private function step_upload_cloud( BackupJob $job, array $payload ): void {
		$backup = BackupsRepository::find( (int) $payload['backup_id'] );

		if ( null === $backup ) {
			$job->mark_failed( __( 'Backup record not found for upload.', 'wrensafe' ) );
			return;
		}

		/**
		 * Upload a finished backup to a remote destination.
		 *
		 * @param array|null $result      null when no handler took it, else array{success:bool,message?:string}.
		 * @param array      $backup      Backup record.
		 * @param string     $destination Destination id chosen for the job.
		 * @param BackupJob  $job
		 */
		$result = apply_filters( 'wpbp_backup_remote_upload', null, $backup, (string) $payload['destination'], $job );
		$status = null === $result ? null : ( empty( $result['success'] ) ? 'failed' : 'completed' );

		if ( 'failed' === $status ) {
			Logger::error( 'Remote upload failed for backup #' . $backup['id'] . ': ' . ( $result['message'] ?? '' ), array(), $job->id() );
		} elseif ( 'completed' === $status ) {
			BackupsRepository::update( (int) $backup['id'], array( 'destination' => 'cloud:' . $payload['destination'] ) );
			Logger::info( 'Backup job #' . $job->id() . ' uploaded to remote storage.', array(), $job->id() );
		}

		$completed = array(
			'backup_id'   => $payload['backup_id'],
			'backup_type' => $payload['backup_type'] ?? 'full',
		);

		if ( null !== $status ) {
			$completed['cloud_upload'] = $status;
		}

		$job->mark_completed( $completed );
	}
}

// phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped

// phpcs:enable WordPress.WP.AlternativeFunctions
