<?php
/**
 * Step-based, read-only backup verification.
 *
 * Drives a backup archive through a fixed checklist one bounded step per
 * request — mirroring the chunked design of BackupManager / RestoreManager —
 * so the Backup Browser can show real progress, a live checklist, and a final
 * pass/fail/warning summary instead of an opaque "Verifying…" state.
 *
 * Verification never restores, never writes to the database, and never
 * modifies any file. It only reads the archive: opening the container,
 * checking the JSON manifests, and stream-hashing each database and content
 * entry against the checksums recorded when the backup was written (using
 * ArchiveReader::checksum_entry(), which hashes in chunks so even a
 * multi-gigabyte entry never loads into memory).
 *
 * Job state lives in a short-lived transient (1-hour expiry) so a stuck or
 * abandoned verification never lingers, and never touches the jobs table.
 *
 * @package WPBP\Backup
 */

namespace WPBP\Backup;

use WPBP\Core\Database\BackupsRepository;
use WPBP\Restore\ArchiveReader;
use WPBP\Utils\FileSystem;
use WPBP\Utils\Logger;

defined( 'ABSPATH' ) || exit;

class VerifyManager {

	private const TRANSIENT_PREFIX = 'wpbp_verify_job_';
	private const EXPIRY           = HOUR_IN_SECONDS;

	/** Database manifest entries checked per step. */
	private const DB_BATCH = 8;

	/** Content-file checksums verified per step. */
	private const FILE_BATCH = 200;

	/** Skip the whole-file checksum above this size to avoid request timeouts. */
	private const WHOLE_FILE_LIMIT = 1073741824; // 1 GB.

	private const STATUS_PENDING   = 'pending';
	private const STATUS_RUNNING   = 'running';
	private const STATUS_PASSED    = 'passed';
	private const STATUS_FAILED    = 'failed';
	private const STATUS_WARNING   = 'warning';
	private const STATUS_SKIPPED   = 'skipped';
	private const STATUS_COMPLETED = 'completed';

	/**
	 * The full checklist, in display order. Statuses transition from
	 * 'pending' as each step runs, so the frontend can render the live list.
	 *
	 * @return array<int,array{key:string,label:string}>
	 */
	private static function checklist(): array {
		return array(
			array(
				'key'   => 'backup_record',
				'label' => __( 'Backup record', 'wrensafe' ),
			),
			array(
				'key'   => 'backup_file',
				'label' => __( 'Backup file', 'wrensafe' ),
			),
			array(
				'key'   => 'backup_path',
				'label' => __( 'Backup path security', 'wrensafe' ),
			),
			array(
				'key'   => 'archive_container',
				'label' => __( 'Archive container', 'wrensafe' ),
			),
			array(
				'key'   => 'manifest_json',
				'label' => __( 'manifest.json', 'wrensafe' ),
			),
			array(
				'key'   => 'metadata_json',
				'label' => __( 'metadata.json', 'wrensafe' ),
			),
			array(
				'key'   => 'archive_version',
				'label' => __( 'Archive version', 'wrensafe' ),
			),
			array(
				'key'   => 'database_manifest',
				'label' => __( 'Database manifest', 'wrensafe' ),
			),
			array(
				'key'   => 'database_schema_files',
				'label' => __( 'Database schema files', 'wrensafe' ),
			),
			array(
				'key'   => 'database_data_files',
				'label' => __( 'Database data files', 'wrensafe' ),
			),
			array(
				'key'   => 'checksums_json',
				'label' => __( 'checksums.json', 'wrensafe' ),
			),
			array(
				'key'   => 'backup_file_checksums',
				'label' => __( 'Backup file checksums', 'wrensafe' ),
			),
			array(
				'key'   => 'whole_archive_checksum',
				'label' => __( 'Whole archive checksum', 'wrensafe' ),
			),
			array(
				'key'   => 'final_result',
				'label' => __( 'Final result', 'wrensafe' ),
			),
		);
	}

	/**
	 * Begin a verification job. Runs the three instant pre-flight checks
	 * (record, file on disk, path inside storage) immediately so an obviously
	 * broken backup fails fast; everything that requires opening the archive
	 * is deferred to step().
	 *
	 * @return array The initial job state (also persisted to the transient).
	 */
	public static function start( int $backup_id, string $password = '' ): array {
		$job_id = 'verify_' . wp_generate_password( 12, false );

		$checks = array();
		foreach ( self::checklist() as $item ) {
			$checks[ $item['key'] ] = array(
				'key'     => $item['key'],
				'label'   => $item['label'],
				'status'  => self::STATUS_PENDING,
				'message' => __( 'Pending', 'wrensafe' ),
			);
		}

		$backup = BackupsRepository::find( $backup_id );

		$state = array(
			'job_id'                 => $job_id,
			'backup_id'              => $backup_id,
			'file_path'              => $backup['file_path'] ?? '',
			'file_name'              => $backup['file_name'] ?? '',
			'expected_checksum'      => $backup['checksum'] ?? '',
			'encrypted'              => false,
			'needs_password'         => false,
			'work_path'              => $backup['file_path'] ?? '',
			'decrypted_path'         => null,
			'skip_status_update'     => false,
			'status'                 => self::STATUS_RUNNING,
			'progress'               => 5,
			'current_step'           => __( 'Checking backup record…', 'wrensafe' ),
			'phase'                  => 'archive_container',
			'checks'                 => $checks,
			'contains_database'      => false,
			'db_entries'             => array(),
			'db_index'               => 0,
			'db_schema_ok'           => true,
			'db_data_ok'             => true,
			'db_schema_fail_msg'     => '',
			'db_data_fail_msg'       => '',
			'checked_database_files' => 0,
			'file_total'             => 0,
			'file_index'             => 0,
			'file_ok'                => true,
			'file_fail_msg'          => '',
			'checked_files'          => 0,
			'errors'                 => array(),
			'warnings'               => array(),
			'started_at'             => time(),
			'completed_at'           => null,
		);

		// Pre-flight check 1: backup record exists.
		if ( null === $backup ) {
			self::set_check( $state, 'backup_record', self::STATUS_FAILED, __( 'Backup record not found.', 'wrensafe' ) );
			return self::fail_fast( $state, __( 'Backup record not found.', 'wrensafe' ) );
		}
		self::set_check( $state, 'backup_record', self::STATUS_PASSED, __( 'Backup record found.', 'wrensafe' ) );

		// Pre-flight check 2: file exists on disk.
		if ( empty( $state['file_path'] ) || ! file_exists( $state['file_path'] ) ) {
			self::set_check( $state, 'backup_file', self::STATUS_FAILED, __( 'Backup file is missing from disk.', 'wrensafe' ) );
			return self::fail_fast( $state, __( 'Backup file is missing from disk.', 'wrensafe' ) );
		}
		self::set_check( $state, 'backup_file', self::STATUS_PASSED, __( 'Backup file exists.', 'wrensafe' ) );

		// Pre-flight check 3: path is inside the backups storage directory.
		if ( ! FileSystem::is_backup_file_path( (string) $state['file_path'] ) ) {
			self::set_check( $state, 'backup_path', self::STATUS_FAILED, __( 'Backup path resolves outside Wrensafe storage.', 'wrensafe' ) );
			return self::fail_fast( $state, __( 'Backup path resolves outside Wrensafe storage.', 'wrensafe' ) );
		}
		self::set_check( $state, 'backup_path', self::STATUS_PASSED, __( 'Backup path is inside Wrensafe storage.', 'wrensafe' ) );

		self::cleanup_stale_temp_files();

		$state['encrypted'] = ( new BackupEncryption() )->is_encrypted( $state['file_path'] );

		if ( $state['encrypted'] ) {
			if ( '' === $password ) {
				// No password supplied — we can confirm the file exists and its
				// whole-file integrity, but cannot inspect internals. This is a
				// WARNING, never "corrupted".
				$state['needs_password'] = true;
			} else {
				// Decrypt once to a protected, randomly-named temp file; every
				// later step reads that plaintext copy, so the password is used
				// exactly once and never stored. A wrong password is reported as
				// such and must NOT mark the backup corrupted.
				$tmp_dir = FileSystem::storage_dir() . 'tmp';
				FileSystem::ensure_protected_directory( $tmp_dir );
				$decrypted_path = trailingslashit( $tmp_dir ) . 'verify-' . wp_generate_password( 20, false ) . '.wbp';

				if ( ! ( new BackupEncryption() )->decrypt_file( $state['file_path'], $password, $decrypted_path ) ) {
					if ( file_exists( $decrypted_path ) ) {
						wp_delete_file( $decrypted_path );
					}
					self::set_check( $state, 'archive_container', self::STATUS_FAILED, __( 'Incorrect backup password. Verification could not continue.', 'wrensafe' ) );
					self::set_check( $state, 'final_result', self::STATUS_FAILED, __( 'Verification failed: incorrect password.', 'wrensafe' ) );
					$state['skip_status_update'] = true;
					self::finalize( $state, self::STATUS_FAILED, __( 'Verification failed: incorrect password.', 'wrensafe' ) );
					self::save( $state );
					return $state;
				}

				$state['decrypted_path'] = $decrypted_path;
				$state['work_path']      = $decrypted_path;
			}
		}

		$state['progress']     = 18;
		$state['current_step'] = __( 'Opening archive…', 'wrensafe' );

		Logger::info( 'Verification started for backup #' . $backup_id . ' (' . $state['file_name'] . ').' );

		self::save( $state );

		return $state;
	}

	/**
	 * Advance a verification job by one bounded step and return its updated
	 * state. Safe to call after the job has finished — it just returns the
	 * stored final state.
	 */
	public static function step( string $job_id, string $password = '' ): ?array {
		$state = self::get( $job_id );

		if ( null === $state ) {
			return null;
		}

		if ( in_array( $state['status'], array( self::STATUS_COMPLETED, self::STATUS_FAILED ), true ) ) {
			return $state;
		}

		try {
			switch ( $state['phase'] ) {
				case 'archive_container':
					self::step_archive_container( $state, $password );
					break;
				case 'manifest_json':
					self::step_manifest_json( $state, $password );
					break;
				case 'metadata_json':
					self::step_metadata_json( $state, $password );
					break;
				case 'archive_version':
					self::step_archive_version( $state );
					break;
				case 'database_manifest':
					self::step_database_manifest( $state, $password );
					break;
				case 'database_files':
					self::step_database_files( $state, $password );
					break;
				case 'checksums_json':
					self::step_checksums_json( $state, $password );
					break;
				case 'backup_files':
					self::step_backup_files( $state, $password );
					break;
				case 'whole_archive':
					self::step_whole_archive( $state );
					break;
				case 'final':
				default:
					self::step_final( $state );
					break;
			}
		} catch ( \Throwable $e ) {
			self::set_check( $state, 'final_result', self::STATUS_FAILED, $e->getMessage() );
			$state['errors'][] = $e->getMessage();
			self::finalize( $state, self::STATUS_FAILED, $e->getMessage() );
		}

		self::save( $state );

		return $state;
	}

	public static function get( string $job_id ): ?array {
		$state = get_transient( self::TRANSIENT_PREFIX . sanitize_key( $job_id ) );

		return is_array( $state ) ? $state : null;
	}

	// ---------------------------------------------------------------------
	// Step implementations.
	// ---------------------------------------------------------------------

	private static function step_archive_container( array &$state, string $password ): void {
		if ( ! empty( $state['needs_password'] ) ) {
			// Encrypted backup, no password supplied: the contents can't be
			// inspected. Skip the content checks AND the whole-file checksum
			// (its meaning differs across plugin versions for encrypted files),
			// and finish with a WARNING — never "corrupted".
			foreach ( array( 'archive_container', 'manifest_json', 'metadata_json', 'archive_version', 'database_manifest', 'database_schema_files', 'database_data_files', 'checksums_json', 'backup_file_checksums', 'whole_archive_checksum' ) as $key ) {
				self::set_check( $state, $key, self::STATUS_SKIPPED, __( 'Skipped — backup is encrypted. Provide the password to verify its contents.', 'wrensafe' ) );
			}
			$state['warnings'][]   = __( 'Backup is encrypted; enter the password to fully verify its contents.', 'wrensafe' );
			$state['phase']        = 'final';
			$state['progress']     = 95;
			$state['current_step'] = __( 'Finalizing…', 'wrensafe' );
			return;
		}

		// work_path is the original file for unencrypted backups, or the
		// already-decrypted temp copy for encrypted ones — so no password is
		// needed here.
		$reader = new ArchiveReader( $state['work_path'] );

		if ( ! $reader->open() ) {
			self::set_check( $state, 'archive_container', self::STATUS_FAILED, __( 'The archive could not be opened (it may be corrupt or truncated).', 'wrensafe' ) );
			self::finalize( $state, self::STATUS_FAILED, __( 'The archive could not be opened.', 'wrensafe' ) );
			return;
		}

		self::set_check( $state, 'archive_container', self::STATUS_PASSED, __( 'Archive opened successfully.', 'wrensafe' ) );
		$state['phase']        = 'manifest_json';
		$state['progress']     = 30;
		$state['current_step'] = __( 'Checking manifest.json…', 'wrensafe' );
	}

	private static function step_manifest_json( array &$state, string $password ): void {
		$reader   = new ArchiveReader( $state['work_path'] );
		$manifest = $reader->read_json( 'manifest.json' );

		if ( null === $manifest ) {
			self::set_check( $state, 'manifest_json', self::STATUS_FAILED, __( 'manifest.json is missing or not valid JSON.', 'wrensafe' ) );
			self::finalize( $state, self::STATUS_FAILED, __( 'manifest.json is missing or invalid.', 'wrensafe' ) );
			return;
		}

		self::set_check( $state, 'manifest_json', self::STATUS_PASSED, __( 'manifest.json exists and is valid JSON.', 'wrensafe' ) );
		$state['contains_database'] = ! empty( $manifest['contains_database'] );
		$state['format_version']    = (string) ( $manifest['format_version'] ?? '' );
		$state['phase']             = 'metadata_json';
		$state['progress']          = 40;
		$state['current_step']      = __( 'Checking metadata.json…', 'wrensafe' );
	}

	private static function step_metadata_json( array &$state, string $password ): void {
		$reader   = new ArchiveReader( $state['work_path'] );
		$metadata = $reader->read_json( 'metadata.json' );

		if ( null === $metadata ) {
			self::set_check( $state, 'metadata_json', self::STATUS_FAILED, __( 'metadata.json is missing or not valid JSON.', 'wrensafe' ) );
			self::finalize( $state, self::STATUS_FAILED, __( 'metadata.json is missing or invalid.', 'wrensafe' ) );
			return;
		}

		self::set_check( $state, 'metadata_json', self::STATUS_PASSED, __( 'metadata.json exists and is valid JSON.', 'wrensafe' ) );
		$state['phase']        = 'archive_version';
		$state['progress']     = 50;
		$state['current_step'] = __( 'Checking archive version…', 'wrensafe' );
	}

	private static function step_archive_version( array &$state ): void {
		$version = (string) ( $state['format_version'] ?? '' );

		if ( '' === $version ) {
			self::set_check( $state, 'archive_version', self::STATUS_FAILED, __( 'Archive format version is missing.', 'wrensafe' ) );
			self::finalize( $state, self::STATUS_FAILED, __( 'Archive format version is missing.', 'wrensafe' ) );
			return;
		}

		if ( version_compare( $version, ManifestBuilder::FORMAT_VERSION, '>' ) ) {
			self::set_check( $state, 'archive_version', self::STATUS_FAILED, __( 'Archive was created by a newer plugin version than is installed.', 'wrensafe' ) );
			self::finalize( $state, self::STATUS_FAILED, __( 'Archive version is newer than this plugin supports.', 'wrensafe' ) );
			return;
		}

		self::set_check(
			$state,
			'archive_version',
			self::STATUS_PASSED,
			sprintf(
				/* translators: %s: archive format version */
				__( 'Archive format version %s is supported.', 'wrensafe' ),
				$version
			)
		);
		$state['phase']        = 'database_manifest';
		$state['progress']     = 60;
		$state['current_step'] = __( 'Checking database manifest…', 'wrensafe' );
	}

	private static function step_database_manifest( array &$state, string $password ): void {
		if ( empty( $state['contains_database'] ) ) {
			foreach ( array( 'database_manifest', 'database_schema_files', 'database_data_files' ) as $key ) {
				self::set_check( $state, $key, self::STATUS_SKIPPED, __( 'Skipped — this backup does not include a database.', 'wrensafe' ) );
			}
			$state['phase']        = 'checksums_json';
			$state['progress']     = 76;
			$state['current_step'] = __( 'Checking checksums.json…', 'wrensafe' );
			return;
		}

		$reader      = new ArchiveReader( $state['work_path'] );
		$db_manifest = $reader->read_json( 'database/manifest.json' );

		if ( null === $db_manifest ) {
			self::set_check( $state, 'database_manifest', self::STATUS_FAILED, __( 'database/manifest.json is missing or not valid JSON.', 'wrensafe' ) );
			self::finalize( $state, self::STATUS_FAILED, __( 'database/manifest.json is missing or invalid.', 'wrensafe' ) );
			return;
		}

		self::set_check( $state, 'database_manifest', self::STATUS_PASSED, __( 'database/manifest.json exists and is valid JSON.', 'wrensafe' ) );
		$state['db_entries']   = array_values( $db_manifest );
		$state['db_index']     = 0;
		$state['phase']        = 'database_files';
		$state['current_step'] = __( 'Verifying database files…', 'wrensafe' );
	}

	private static function step_database_files( array &$state, string $password ): void {
		$entries = $state['db_entries'];
		$total   = count( $entries );

		if ( 0 === $total ) {
			self::set_check( $state, 'database_schema_files', self::STATUS_PASSED, __( 'No database tables to verify.', 'wrensafe' ) );
			self::set_check( $state, 'database_data_files', self::STATUS_PASSED, __( 'No database tables to verify.', 'wrensafe' ) );
			$state['phase'] = 'checksums_json';
			return;
		}

		$reader = new ArchiveReader( $state['work_path'] );
		$end    = min( $total, $state['db_index'] + self::DB_BATCH );

		for ( ; $state['db_index'] < $end; $state['db_index']++ ) {
			$entry = $entries[ $state['db_index'] ];

			foreach ( array(
				'schema' => array( 'schema_file', 'schema_checksum', 'db_schema_ok', 'db_schema_fail_msg' ),
				'data'   => array( 'data_file', 'data_checksum', 'db_data_ok', 'db_data_fail_msg' ),
			) as $cols ) {
				list( $file_key, $checksum_key, $ok_key, $msg_key ) = $cols;

				if ( empty( $entry[ $file_key ] ) ) {
					continue;
				}

				$entry_name = 'database/' . $entry[ $file_key ];
				$problem    = self::check_entry( $reader, $entry_name, (string) ( $entry[ $checksum_key ] ?? '' ) );

				if ( null !== $problem && $state[ $ok_key ] ) {
					$state[ $ok_key ]  = false;
					$state[ $msg_key ] = $problem;
					$state['errors'][] = $problem;
				}

				++$state['checked_database_files'];
			}
		}

		$state['progress']     = 60 + (int) ( ( $state['db_index'] / max( 1, $total ) ) * 15 );
		$state['current_step'] = sprintf(
			/* translators: 1: tables checked, 2: total tables */
			__( 'Verifying database files (%1$d / %2$d tables)…', 'wrensafe' ),
			$state['db_index'],
			$total
		);

		if ( $state['db_index'] >= $total ) {
			self::set_check(
				$state,
				'database_schema_files',
				$state['db_schema_ok'] ? self::STATUS_PASSED : self::STATUS_FAILED,
				$state['db_schema_ok'] ? __( 'All database schema files present and checksums match.', 'wrensafe' ) : $state['db_schema_fail_msg']
			);
			self::set_check(
				$state,
				'database_data_files',
				$state['db_data_ok'] ? self::STATUS_PASSED : self::STATUS_FAILED,
				$state['db_data_ok'] ? __( 'All database data files present and checksums match.', 'wrensafe' ) : $state['db_data_fail_msg']
			);
			$state['phase']        = 'checksums_json';
			$state['progress']     = 76;
			$state['current_step'] = __( 'Checking checksums.json…', 'wrensafe' );
		}
	}

	private static function step_checksums_json( array &$state, string $password ): void {
		$reader    = new ArchiveReader( $state['work_path'] );
		$checksums = $reader->read_json( 'checksums.json' );

		if ( null === $checksums ) {
			// Not every backup records per-file checksums (e.g. database-only).
			self::set_check( $state, 'checksums_json', self::STATUS_SKIPPED, __( 'No per-file checksums were recorded for this backup.', 'wrensafe' ) );
			self::set_check( $state, 'backup_file_checksums', self::STATUS_SKIPPED, __( 'No per-file checksums to verify.', 'wrensafe' ) );
			$state['phase']        = 'whole_archive';
			$state['progress']     = 90;
			$state['current_step'] = __( 'Verifying whole-file checksum…', 'wrensafe' );
			return;
		}

		$files_map = is_array( $checksums['files'] ?? null ) ? $checksums['files'] : array();

		self::set_check( $state, 'checksums_json', self::STATUS_PASSED, __( 'checksums.json exists and is valid JSON.', 'wrensafe' ) );
		$state['file_total']   = count( $files_map );
		$state['file_index']   = 0;
		$state['phase']        = 'backup_files';
		$state['current_step'] = __( 'Verifying backup files…', 'wrensafe' );

		if ( 0 === $state['file_total'] ) {
			self::set_check( $state, 'backup_file_checksums', self::STATUS_PASSED, __( 'No content files to verify.', 'wrensafe' ) );
			$state['phase']    = 'whole_archive';
			$state['progress'] = 90;
		}
	}

	private static function step_backup_files( array &$state, string $password ): void {
		$reader    = new ArchiveReader( $state['work_path'] );
		$checksums = $reader->read_json( 'checksums.json' );
		$files_map = is_array( $checksums['files'] ?? null ) ? $checksums['files'] : array();
		$names     = array_keys( $files_map );
		$total     = count( $names );

		$end      = min( $total, $state['file_index'] + self::FILE_BATCH );
		$max_time = (int) ini_get( 'max_execution_time' );
		$deadline = microtime( true ) + ( ( $max_time > 0 && $max_time < 20 ) ? max( 4, $max_time - 6 ) : 10 );

		// Up to FILE_BATCH entries, but never past the request budget — 200
		// large media files can take longer than a 30-second host allows.
		$processed = 0;

		for ( ; $state['file_index'] < $end && ( 0 === $processed || microtime( true ) < $deadline ); $state['file_index']++, $processed++ ) {
			$entry_name = (string) $names[ $state['file_index'] ];
			$problem    = self::check_entry( $reader, $entry_name, (string) $files_map[ $entry_name ] );

			if ( null !== $problem && $state['file_ok'] ) {
				$state['file_ok']       = false;
				$state['file_fail_msg'] = $problem;
			}

			if ( null !== $problem && count( $state['errors'] ) < 10 ) {
				// Keep at most the first 10 failing paths (Part F).
				$state['errors'][] = $problem;
			}

			++$state['checked_files'];
		}

		$state['progress']     = 78 + (int) ( ( $state['file_index'] / max( 1, $total ) ) * 12 );
		$state['current_step'] = sprintf(
			/* translators: 1: files checked, 2: total files */
			__( 'Verifying backup files (%1$d / %2$d)…', 'wrensafe' ),
			$state['file_index'],
			$total
		);

		if ( $state['file_index'] >= $total ) {
			self::set_check(
				$state,
				'backup_file_checksums',
				$state['file_ok'] ? self::STATUS_PASSED : self::STATUS_FAILED,
				$state['file_ok']
					? sprintf(
						/* translators: %d: number of files verified */
						__( 'All %d backup files present and checksums match.', 'wrensafe' ),
						$state['checked_files']
					)
					: $state['file_fail_msg']
			);
			$state['phase']        = 'whole_archive';
			$state['progress']     = 90;
			$state['current_step'] = __( 'Verifying whole-file checksum…', 'wrensafe' );
		}
	}

	private static function step_whole_archive( array &$state ): void {
		// For encrypted backups the on-disk bytes are ciphertext, and the
		// stored whole-file checksum's meaning differs across plugin versions,
		// so the per-entry checksums (verified on the decrypted copy) are the
		// authoritative signal — skip the whole-file check here.
		if ( ! empty( $state['encrypted'] ) ) {
			self::set_check( $state, 'whole_archive_checksum', self::STATUS_SKIPPED, __( 'Skipped for encrypted backups — per-entry checksums were verified instead.', 'wrensafe' ) );
			$state['phase'] = 'final';
			return;
		}

		$expected = (string) $state['expected_checksum'];

		if ( '' === $expected ) {
			self::set_check( $state, 'whole_archive_checksum', self::STATUS_SKIPPED, __( 'No whole-file checksum was recorded for this backup.', 'wrensafe' ) );
			$state['phase'] = 'final';
			return;
		}

		$size = file_exists( $state['file_path'] ) ? (int) filesize( $state['file_path'] ) : 0;

		if ( $size > self::WHOLE_FILE_LIMIT ) {
			self::set_check( $state, 'whole_archive_checksum', self::STATUS_SKIPPED, __( 'Skipped — backup is very large; per-entry checksums were verified instead.', 'wrensafe' ) );
			$state['warnings'][] = __( 'Whole-file checksum skipped for a very large backup.', 'wrensafe' );
			$state['phase']      = 'final';
			return;
		}

		$actual = ( new IntegrityChecker() )->compute( $state['file_path'] );

		if ( '' === $actual || ! hash_equals( $expected, $actual ) ) {
			self::set_check( $state, 'whole_archive_checksum', self::STATUS_FAILED, __( 'The backup file has changed since it was created (whole-file checksum mismatch).', 'wrensafe' ) );
			$state['errors'][] = __( 'Whole-file checksum mismatch.', 'wrensafe' );
		} else {
			self::set_check( $state, 'whole_archive_checksum', self::STATUS_PASSED, __( 'Whole-file checksum matches.', 'wrensafe' ) );
		}

		$state['phase']        = 'final';
		$state['progress']     = 96;
		$state['current_step'] = __( 'Finalizing…', 'wrensafe' );
	}

	private static function step_final( array &$state ): void {
		$failed  = 0;
		$passed  = 0;
		$warning = 0;

		foreach ( $state['checks'] as $check ) {
			if ( 'final_result' === $check['key'] ) {
				continue;
			}
			if ( self::STATUS_FAILED === $check['status'] ) {
				++$failed;
			} elseif ( self::STATUS_PASSED === $check['status'] ) {
				++$passed;
			} elseif ( self::STATUS_WARNING === $check['status'] ) {
				++$warning;
			}
		}

		if ( ! empty( $state['warnings'] ) ) {
			$warning += count( $state['warnings'] );
		}

		if ( $failed > 0 ) {
			$result_status = self::STATUS_FAILED;
			$message       = __( 'Backup verification failed. Do not restore this backup until the problem is fixed.', 'wrensafe' );
		} elseif ( $warning > 0 ) {
			$result_status = self::STATUS_WARNING;
			$message       = __( 'Backup verification completed with warnings.', 'wrensafe' );
		} else {
			$result_status = self::STATUS_PASSED;
			$message       = __( 'Backup verification passed. This backup appears safe to restore.', 'wrensafe' );
		}

		self::set_check( $state, 'final_result', $result_status, $message );
		self::finalize( $state, $result_status, $message );
	}

	// ---------------------------------------------------------------------
	// Helpers.
	// ---------------------------------------------------------------------

	/**
	 * Stream-hash one archive entry and compare against the expected value.
	 * Returns null when fine, or a human-readable problem naming the entry.
	 */
	private static function check_entry( ArchiveReader $reader, string $entry_name, string $expected_checksum ): ?string {
		$actual = $reader->checksum_entry( $entry_name );

		if ( null === $actual ) {
			return sprintf(
				/* translators: %s: archive entry path */
				__( '%s is missing from the archive.', 'wrensafe' ),
				$entry_name
			);
		}

		if ( '' === $expected_checksum || ! hash_equals( $expected_checksum, $actual ) ) {
			return sprintf(
				/* translators: %s: archive entry path */
				__( '%s checksum mismatch.', 'wrensafe' ),
				$entry_name
			);
		}

		return null;
	}

	private static function set_check( array &$state, string $key, string $status, string $message ): void {
		if ( isset( $state['checks'][ $key ] ) ) {
			$state['checks'][ $key ]['status']  = $status;
			$state['checks'][ $key ]['message'] = $message;
		}
	}

	/**
	 * Mark a job terminal (completed or failed), record the summary, and log.
	 */
	private static function finalize( array &$state, string $result_status, string $message ): void {
		// Never leave a check stuck on "pending"/"running" once the job is
		// terminal — when verification stops early (failure or a thrown step),
		// every remaining check is reported as "skipped" with a clear reason so
		// the modal shows no perpetually-spinning rows (Part B).
		if ( in_array( $result_status, array( self::STATUS_FAILED, self::STATUS_COMPLETED ), true ) ) {
			$skip_reason = self::STATUS_FAILED === $result_status
				? __( 'Skipped — verification stopped after an earlier failure.', 'wrensafe' )
				: __( 'Not required for this backup.', 'wrensafe' );

			foreach ( $state['checks'] as $key => $check ) {
				if ( 'final_result' === $key ) {
					continue;
				}
				if ( in_array( $check['status'], array( self::STATUS_PENDING, self::STATUS_RUNNING ), true ) ) {
					self::set_check( $state, $key, self::STATUS_SKIPPED, $skip_reason );
				}
			}
		}

		$summary = array(
			'total_checks'           => 0,
			'passed_checks'          => 0,
			'failed_checks'          => 0,
			'warning_checks'         => 0,
			'skipped_checks'         => 0,
			'checked_files'          => $state['checked_files'],
			'checked_database_files' => $state['checked_database_files'],
		);

		foreach ( $state['checks'] as $check ) {
			++$summary['total_checks'];
			switch ( $check['status'] ) {
				case self::STATUS_PASSED:
					++$summary['passed_checks'];
					break;
				case self::STATUS_FAILED:
					++$summary['failed_checks'];
					break;
				case self::STATUS_WARNING:
					++$summary['warning_checks'];
					break;
				case self::STATUS_SKIPPED:
					++$summary['skipped_checks'];
					break;
			}
		}

		$state['summary']      = $summary;
		$state['result']       = $result_status;
		$state['message']      = $message;
		$state['progress']     = 100;
		$state['current_step'] = $message;
		$state['completed_at'] = time();
		$state['status']       = self::STATUS_FAILED === $result_status ? self::STATUS_FAILED : self::STATUS_COMPLETED;

		// Remove the temporary decrypted copy of an encrypted backup, if any.
		if ( ! empty( $state['decrypted_path'] ) && file_exists( $state['decrypted_path'] ) ) {
			wp_delete_file( $state['decrypted_path'] );
			$state['decrypted_path'] = null;
		}

		// A wrong-password verification must NOT relabel the backup corrupted —
		// the archive itself is fine; only the supplied password was wrong.
		if ( empty( $state['skip_status_update'] ) ) {
			BackupsRepository::update( (int) $state['backup_id'], array( 'status' => self::STATUS_FAILED === $result_status ? 'corrupted' : 'completed' ) );
		}

		if ( self::STATUS_FAILED === $result_status ) {
			$detail = ! empty( $state['errors'] ) ? ' ' . implode( '; ', array_slice( $state['errors'], 0, 10 ) ) : '';
			Logger::warning( 'Verification failed for backup #' . $state['backup_id'] . ' (' . $state['file_name'] . '): ' . $message . $detail );
		} elseif ( self::STATUS_WARNING === $result_status ) {
			Logger::warning( 'Verification warning for backup #' . $state['backup_id'] . ' (' . $state['file_name'] . '): ' . $message );
		} else {
			Logger::info( 'Verification passed for backup #' . $state['backup_id'] . ' (' . $state['file_name'] . ').' );
		}
	}

	private static function fail_fast( array $state, string $message ): array {
		self::set_check( $state, 'final_result', self::STATUS_FAILED, $message );
		self::finalize( $state, self::STATUS_FAILED, $message );
		self::save( $state );

		return $state;
	}

	private static function save( array $state ): void {
		set_transient( self::TRANSIENT_PREFIX . sanitize_key( $state['job_id'] ), $state, self::EXPIRY );
	}

	/**
	 * Delete any leftover temporary decrypted verify files (verify-*.wbp) older
	 * than an hour, so an interrupted verification never leaves plaintext copies
	 * of an encrypted backup lying around in the protected temp directory.
	 */
	private static function cleanup_stale_temp_files(): void {
		$tmp_dir = FileSystem::storage_dir() . 'tmp';

		if ( ! is_dir( $tmp_dir ) ) {
			return;
		}

		foreach ( (array) glob( trailingslashit( $tmp_dir ) . 'verify-*.wbp' ) as $file ) {
			if ( is_file( $file ) && ( time() - (int) filemtime( $file ) ) > HOUR_IN_SECONDS ) {
				wp_delete_file( $file );
			}
		}
	}
}
