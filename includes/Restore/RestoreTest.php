<?php
/**
 * Restore Test: proves a backup really restores, without touching the live site.
 *
 * 1. Every file in the backup is checked against its checksum (VerifyManager).
 * 2. Every table is imported into a temporary copy ({table}_wpbptest), with the same importer a restore uses.
 * 3. The copy is checked: every table and its rows are there, the site address is set, there is an administrator,
 *    and the active theme and plugins come with the backup (or are on this site).
 * 4. The copy and any decrypted file are removed, and the result is kept with the backup.
 *
 * Nothing is written to the site's own tables or files, there is no maintenance page, and the live site keeps
 * running. It needs the same free database space a restore needs. One test at a time, never during a restore.
 *
 * @package WPBP\Restore
 */

namespace WPBP\Restore;

use WPBP\Backup\VerifyManager;
use WPBP\Core\Database\BackupsRepository;
use WPBP\Utils\Errors;
use WPBP\Utils\FileSystem;
use WPBP\Utils\Logger;

defined( 'ABSPATH' ) || exit;

// The SQL of each table is read from files in the job's own work folder.
// phpcs:disable WordPress.WP.AlternativeFunctions
// Temporary copies of the backup's tables: created, counted, read and dropped here, never cached.
// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange

final class RestoreTest {

	/** The end of every temporary table's name (backups, staging and Push to Live leave such tables out). */
	const SUFFIX = '_wpbptest';

	/** Option: the latest result per backup id. */
	const RESULTS = 'wpbp_restore_tests';

	/** Option: the test that is running (job id). */
	const LOCK = 'wpbp_restore_test_running';

	/** Seconds of work per request. */
	const STEP_SECONDS = 15;

	/**
	 * The same room a real restore asks for (RestoreManager's preflight: 1.5 × the archive + 50 MB for unpacked table dumps),
	 * plus a full decrypted copy of an encrypted backup. Checked before anything is unpacked, so a test can't fill the disk
	 * of the site it promises not to change. Only where the host tells PHP the free space.
	 */
	private static function check_disk_space( string $archive, bool $encrypted ): void {
		$dir = FileSystem::storage_dir();
		clearstatcache( true, $archive );
		$size = is_file( $archive ) ? (int) filesize( $archive ) : 0;
		$free = function_exists( 'disk_free_space' ) && is_dir( $dir ) ? @disk_free_space( $dir ) : false; // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- some hosts disable it or refuse the path; then the check is skipped.
		$need = (int) ( $size * 1.5 ) + 52428800 + ( $encrypted ? $size : 0 );

		if ( false !== $free && $free < $need ) {
			throw new \RuntimeException(
				esc_html(
					sprintf(
						/* translators: 1: free disk space, 2: space the test needs */
						__( 'Not enough free disk space to test this backup (free: %1$s, needed: about %2$s). Free up space and try again.', 'wrensafe' ),
						size_format( (int) $free ),
						size_format( $need )
					)
				)
			);
		}
	}

	/**
	 * Start a test of a backup. The password of an encrypted backup is used once, here, and never kept.
	 *
	 * @return array<string,mixed> The test's state.
	 */
	public static function start( int $backup_id, string $password = '' ): array {
		$backup = BackupsRepository::find( $backup_id );

		if ( null === $backup ) {
			throw new \RuntimeException( esc_html__( 'Backup not found.', 'wrensafe' ) );
		}

		if ( RestoreManager::is_locked() ) {
			throw new \RuntimeException( esc_html__( 'A restore is running. Test the backup when it has finished.', 'wrensafe' ) );
		}

		$running = (int) get_option( self::LOCK, 0 );
		$other   = $running ? RestoreTestJob::get( $running ) : null;

		if ( null !== $other && 'running' === $other->status() && ! self::stale( $other ) ) {
			throw new \RuntimeException( esc_html__( 'Another restore test is running. Wait for it to finish.', 'wrensafe' ) );
		}

		if ( null !== $other ) {
			self::cleanup( $other );
		}

		$encrypted = ( new \WPBP\Backup\BackupEncryption() )->is_encrypted( (string) $backup['file_path'] );

		if ( $encrypted && '' === $password ) {
			throw new \RuntimeException( esc_html__( 'This backup is encrypted. Enter its password to test it.', 'wrensafe' ) );
		}

		self::check_disk_space( (string) $backup['file_path'], $encrypted );

		self::drop_tables(); // Left by a test that was cut off.

		$job = RestoreTestJob::create(
			array(
				'backup_id' => $backup_id,
				'file_name' => (string) $backup['file_name'],
				'phase'     => 'verify',
				'checks'    => array(),
			)
		);

		$job->mark_running();
		update_option( self::LOCK, $job->id(), false );

		Logger::info( 'restore_test_started: restore test of backup #' . $backup_id . ' (' . $backup['file_name'] . ') started; the live site isn’t changed.', array(), $job->id() );

		$verify = VerifyManager::start( $backup_id, $password, true );

		$payload           = $job->payload();
		$payload['verify'] = (string) ( $verify['job_id'] ?? '' );
		$job->set_payload( $payload );
		$job->update_progress( 3, __( 'Checking every file in the backup…', 'wrensafe' ) );

		if ( 'failed' === ( $verify['status'] ?? '' ) ) {
			self::finish( $job, false, (string) ( $verify['message'] ?? __( 'The backup could not be checked.', 'wrensafe' ) ) );
		}

		return self::state( $job );
	}

	/** One bounded step of a test. @return array<string,mixed>|null */
	public static function step( int $job_id ): ?array {
		$job = RestoreTestJob::get( $job_id );

		if ( null === $job ) {
			return null;
		}

		if ( 'running' !== $job->status() ) {
			return self::state( $job );
		}

		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( 0 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.PHP.DiscouragedPHPFunctions.runtime_configuration_set_time_limit, Squiz.PHP.DiscouragedFunctions.Discouraged -- every step stops at its own deadline anyway.
		}

		$payload = $job->payload();

		try {
			switch ( $payload['phase'] ?? 'verify' ) {
				case 'verify':
					self::step_verify( $job );
					break;
				case 'database':
					self::step_database( $job );
					break;
				default:
					self::step_check( $job );
			}
		} catch ( \Throwable $e ) {
			self::finish( $job, false, Errors::text( $e ) );
		}

		return self::state( RestoreTestJob::get( $job_id ) ?? $job );
	}

	/** Stop a test and remove what it made. */
	public static function cancel( int $job_id ): void {
		$job = RestoreTestJob::get( $job_id );

		if ( null === $job || 'running' !== $job->status() ) {
			return;
		}

		self::cleanup( $job );
		Logger::warning( 'restore_test_cancelled: restore test of backup #' . (int) $job->payload()['backup_id'] . ' stopped by user #' . get_current_user_id() . '; its temporary copy was removed.', array(), $job->id() );
		$job->cancel();
	}

	/** The latest result of each backup's test. @return array<int,array{ok:bool,at:int,message:string}> */
	public static function results(): array {
		$results = get_option( self::RESULTS, array() );

		return is_array( $results ) ? $results : array();
	}

	/** @return array<string,mixed> What the browser shows. */
	public static function state( RestoreTestJob $job ): array {
		$payload = $job->payload();

		return array(
			'job_id'   => $job->id(),
			'status'   => $job->status(),
			'phase'    => (string) ( $payload['phase'] ?? '' ),
			'progress' => $job->progress(),
			'step'     => $job->current_step(),
			'checks'   => array_values( (array) ( $payload['checks'] ?? array() ) ),
			'message'  => 'failed' === $job->status() ? $job->error_message() : (string) ( $job->result()['message'] ?? '' ),
		);
	}

	// ---------------------------------------------------------------------

	/** Phase 1: every file against its checksum, by the same check as Verify. */
	private static function step_verify( RestoreTestJob $job ): void {
		$payload  = $job->payload();
		$deadline = microtime( true ) + self::STEP_SECONDS;

		do {
			$verify = VerifyManager::step( (string) $payload['verify'] );
		} while ( is_array( $verify ) && 'running' === $verify['status'] && microtime( true ) < $deadline );

		if ( ! is_array( $verify ) ) {
			throw new \RuntimeException( esc_html__( 'The check of the backup’s files stopped. Start the test again.', 'wrensafe' ) );
		}

		if ( 'running' === $verify['status'] ) {
			$job->update_progress( 3 + (int) round( (int) $verify['progress'] * 0.37 ), __( 'Checking every file in the backup…', 'wrensafe' ) );
			return;
		}

		if ( 'failed' === $verify['status'] ) {
			$detail  = (string) ( ( (array) ( $verify['errors'] ?? array() ) )[0] ?? '' );
			$message = (string) $verify['message'] . ( '' !== $detail && false === strpos( (string) $verify['message'], $detail ) ? ' ' . $detail : '' );
			self::add_check( $job, 'files', 'failed', $message );
			throw new \RuntimeException( esc_html( $message ) );
		}

		$files = (int) ( $verify['summary']['checked_files'] ?? 0 );
		/* translators: %s: number of files */
		self::add_check( $job, 'files', 'passed', sprintf( _n( '%s file checked against its checksum.', 'Every one of %s files checked against its checksum.', $files, 'wrensafe' ), number_format_i18n( $files ) ) );

		$payload            = $job->payload();
		$payload['archive'] = (string) $verify['work_path'];
		$payload['temp']    = (string) ( $verify['decrypted_path'] ?? '' );

		if ( empty( $verify['contains_database'] ) ) {
			$payload['phase'] = 'check';
			$job->set_payload( $payload );
			self::add_check( $job, 'database', 'skipped', __( 'This backup has no database.', 'wrensafe' ) );
			return;
		}

		$reader   = new ArchiveReader( $payload['archive'] );
		$manifest = (array) ( $reader->read_json( 'manifest.json' ) ?? array() );
		$tables   = array();

		global $wpdb;

		foreach ( (array) ( $reader->read_json( 'database/manifest.json' ) ?? array() ) as $entry ) {
			$source     = (string) ( $entry['source_table'] ?? '' );
			$normalized = (string) ( $entry['normalized_table'] ?? $source );

			if ( '' === $source || empty( $entry['schema_file'] ) || empty( $entry['data_file'] ) || 1 !== preg_match( '/^[A-Za-z0-9_$]+$/', $source . $normalized ) ) {
				continue;
			}

			$tables[] = array(
				'source_table' => $source,
				'normalized'   => $normalized,
				'dest_table'   => $wpdb->prefix . $normalized,
				'tmp_table'    => self::table_name( $wpdb->prefix . $normalized ),
				'schema_file'  => (string) $entry['schema_file'],
				'data_file'    => (string) $entry['data_file'],
				'rows'         => (int) ( $entry['rows'] ?? 0 ),
			);
		}

		$payload['phase']  = 'database';
		$payload['tables'] = $tables;
		$payload['legacy'] = (int) ( $manifest['sql_dump_version'] ?? 1 ) < 2;
		$payload['index']  = 0;
		$payload['stage']  = 'extract';
		$payload['offset'] = 0;

		// The job's work folder: its name can't be guessed, so a web server
		// that ignores .htaccess (nginx) can't serve the table dumps in it.
		$payload['dir'] = FileSystem::job_tmp_dir( $job->id() );

		$job->set_payload( $payload );
		$job->update_progress( 40, __( 'Importing the database into a temporary copy…', 'wrensafe' ) );
	}

	/** Phase 2: each table into its temporary copy, as a restore imports it. */
	private static function step_database( RestoreTestJob $job ): void {
		global $wpdb;

		$payload  = $job->payload();
		$tables   = (array) $payload['tables'];
		$deadline = microtime( true ) + self::STEP_SECONDS;
		$importer = new DatabaseImporter();
		$map      = DatabaseImporter::foreign_key_map( $tables, $wpdb->prefix );

		FileSystem::ensure_protected_directory( $payload['dir'] );

		$total = count( $tables );

		while ( $payload['index'] < $total && microtime( true ) < $deadline ) {
			$entry  = $tables[ $payload['index'] ];
			$schema = $payload['dir'] . 'schema.sql';
			$data   = $payload['dir'] . 'data.sql';

			if ( 'extract' === $payload['stage'] ) {
				// A large dump is unpacked across steps, as a restore does.
				if ( null !== RestoreManager::unpack_table_files( new ArchiveReader( $payload['archive'] ), $entry, $schema, $data, $payload, $deadline ) ) {
					break;
				}

				$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', $entry['tmp_table'] ) );
				$payload['stage']  = 'schema';
				$payload['offset'] = 0;
				continue;
			}

			$result = $importer->import_chunk( 'schema' === $payload['stage'] ? $schema : $data, (int) $payload['offset'], $entry['source_table'], $entry['tmp_table'], ! empty( $payload['legacy'] ), 'schema' === $payload['stage'] ? $map : array() );

			if ( null !== $result['error'] ) {
				/* translators: 1: table name, 2: database error */
				$message = sprintf( __( 'The table %1$s couldn’t be imported: %2$s', 'wrensafe' ), $entry['dest_table'], Errors::utf8( (string) $result['error'] ) );
				self::add_check( $job, 'database', 'failed', $message );
				throw new \RuntimeException( esc_html( $message ) );
			}

			$payload['offset'] = (int) $result['offset'];

			if ( $result['done'] ) {
				if ( 'schema' === $payload['stage'] ) {
					$payload['stage']  = 'data';
					$payload['offset'] = 0;
				} else {
					++$payload['index'];
					$payload['stage'] = 'extract';
				}
			}
		}

		$job->set_payload( $payload );

		if ( $payload['index'] < count( $tables ) ) {
			/* translators: 1: tables imported, 2: all tables */
			$job->update_progress( 40 + (int) round( $payload['index'] / max( 1, count( $tables ) ) * 50 ), sprintf( __( 'Importing the database into a temporary copy… %1$s of %2$s tables', 'wrensafe' ), number_format_i18n( $payload['index'] ), number_format_i18n( count( $tables ) ) ) );
			return;
		}

		$payload['phase'] = 'check';
		$job->set_payload( $payload );
		$job->update_progress( 92, __( 'Checking the restored copy…', 'wrensafe' ) );
	}

	/** Phase 3: what a restored site needs, read from the copy; then clean up. */
	private static function step_check( RestoreTestJob $job ): void {
		global $wpdb;

		$payload = $job->payload();
		$tables  = (array) ( $payload['tables'] ?? array() );
		$by_name = array();

		foreach ( $tables as $entry ) {
			$by_name[ $entry['normalized'] ] = $entry['tmp_table'];
		}

		if ( $tables ) {
			// Every table, with the rows the backup recorded.
			$short = array();

			foreach ( $tables as $entry ) {
				$rows = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i', $entry['tmp_table'] ) );

				if ( $entry['rows'] > 0 && $rows < $entry['rows'] ) {
					$short[] = $entry['dest_table'] . ' (' . number_format_i18n( $rows ) . ' / ' . number_format_i18n( $entry['rows'] ) . ')';
				}
			}

			if ( $short ) {
				/* translators: %s: tables with fewer rows than recorded */
				self::add_check( $job, 'database', 'failed', sprintf( __( 'Rows are missing after the import: %s', 'wrensafe' ), implode( ', ', array_slice( $short, 0, 5 ) ) ) );
			} else {
				/* translators: %s: number of tables */
				self::add_check( $job, 'database', 'passed', sprintf( _n( '%s table imported with all its rows.', 'All %s tables imported with all their rows.', count( $tables ), 'wrensafe' ), number_format_i18n( count( $tables ) ) ) );
			}

			// The site's address, an administrator, the active theme and plugins.
			$options = $by_name['options'] ?? '';
			$read    = static function ( string $name ) use ( $wpdb, $options ) {
				return '' === $options ? null : $wpdb->get_var( $wpdb->prepare( 'SELECT option_value FROM %i WHERE option_name = %s', $options, $name ) );
			};
			$home    = (string) $read( 'home' );

			if ( '' === $options || '' === (string) $read( 'siteurl' ) ) {
				self::add_check( $job, 'settings', 'failed', __( 'The site’s settings (its address) aren’t in the backup.', 'wrensafe' ) );
			} else {
				/* translators: %s: the site's address */
				self::add_check( $job, 'settings', 'passed', sprintf( __( 'Site settings are there (address %s).', 'wrensafe' ), $home ) );
			}

			$meta   = $by_name['usermeta'] ?? '';
			$admins = '' === $meta || ! isset( $by_name['users'] ) ? 0 : (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(DISTINCT user_id) FROM %i WHERE meta_key LIKE %s AND meta_value LIKE %s', $meta, '%' . $wpdb->esc_like( 'capabilities' ), '%' . $wpdb->esc_like( '"administrator"' ) . '%' ) );

			if ( $admins > 0 ) {
				/* translators: %s: number of administrator accounts */
				self::add_check( $job, 'admin', 'passed', sprintf( _n( '%s administrator account to sign in with.', '%s administrator accounts to sign in with.', $admins, 'wrensafe' ), number_format_i18n( $admins ) ) );
			} else {
				self::add_check( $job, 'admin', 'failed', __( 'No administrator account: after a restore, nobody could sign in to manage the site.', 'wrensafe' ) );
			}

			// The plugin list is read as text, never unserialized: it comes from a backup.
			preg_match_all( '/s:\d+:"([^"]+)"/', (string) $read( 'active_plugins' ), $plugins );
			self::check_code( $job, $payload, $plugins[1], (string) $read( 'stylesheet' ), (string) $read( 'template' ) );
		}

		$checks = (array) $job->payload()['checks'];
		$failed = array_filter(
			$checks,
			static function ( array $check ): bool {
				return 'failed' === $check['status'];
			}
		);

		if ( $failed ) {
			self::finish( $job, false, (string) reset( $failed )['message'] );
			return;
		}

		self::finish( $job, true, __( 'Every file is intact, the database imports, and the site would start with its settings, an administrator, its theme and its plugins.', 'wrensafe' ) );
	}

	/** The active theme and plugins come with the backup, or are on this site already. */
	private static function check_code( RestoreTestJob $job, array $payload, array $plugins, string $stylesheet, string $template ): void {
		$reader  = new ArchiveReader( $payload['archive'] );
		$entries = \WPBP\Backup\IntegrityChecker::file_checksums( $reader->read_json( 'checksums.json' ) );

		$has = static function ( string $folder ) use ( $entries ): bool {
			foreach ( array_keys( $entries ) as $path ) {
				if ( 0 === strpos( $path, $folder ) ) {
					return true;
				}
			}

			return false;
		};

		$missing = array();

		foreach ( array_unique( array_filter( array( $stylesheet, $template ) ) ) as $theme ) {
			if ( ! $has( 'files/themes/' . $theme . '/' ) && ! is_dir( get_theme_root() . '/' . $theme ) ) {
				/* translators: %s: theme folder */
				$missing[] = sprintf( __( 'theme %s', 'wrensafe' ), $theme );
			}
		}

		foreach ( $plugins as $plugin ) {
			$folder = strtok( (string) $plugin, '/' );

			if ( false !== strpos( (string) $plugin, '/' ) ? ( ! $has( 'files/plugins/' . $folder . '/' ) && ! is_dir( WP_PLUGIN_DIR . '/' . $folder ) ) : ( ! $has( 'files/plugins/' . $plugin ) && ! is_file( WP_PLUGIN_DIR . '/' . $plugin ) ) ) {
				/* translators: %s: plugin file */
				$missing[] = sprintf( __( 'plugin %s', 'wrensafe' ), $plugin );
			}
		}

		if ( $missing ) {
			/* translators: %s: themes and plugins */
			self::add_check( $job, 'code', 'warning', sprintf( __( 'Not in the backup and not on this site: %s. The site would start without them.', 'wrensafe' ), implode( ', ', array_slice( $missing, 0, 6 ) ) ) );
		} else {
			$count = count( $plugins );
			/* translators: %s: number of active plugins */
			self::add_check( $job, 'code', 'passed', sprintf( _n( 'The active theme and %s active plugin are there.', 'The active theme and %s active plugins are there.', $count, 'wrensafe' ), number_format_i18n( $count ) ) );
		}
	}

	private static function finish( RestoreTestJob $job, bool $ok, string $message ): void {
		$backup_id = (int) $job->payload()['backup_id'];

		self::cleanup( $job );

		$results               = self::results();
		$results[ $backup_id ] = array(
			'ok'      => $ok,
			'at'      => time(),
			'message' => $message,
			'job'     => $job->id(),
		);

		// The newest 200 results.
		uasort(
			$results,
			static function ( array $a, array $b ): int {
				return $b['at'] <=> $a['at'];
			}
		);
		update_option( self::RESULTS, array_slice( $results, 0, 200, true ), false );

		if ( $ok ) {
			Logger::success( 'restore_test_passed: backup #' . $backup_id . ' restores. ' . $message, array(), $job->id() );
			$job->mark_completed( array( 'message' => $message ) );
		} else {
			Logger::failed( 'restore_test_failed: backup #' . $backup_id . ' wouldn’t restore: ' . $message, array(), $job->id() );
			$job->mark_failed( $message );
		}
	}

	/** Remove the temporary copy, the work folder and a decrypted file. */
	private static function cleanup( RestoreTestJob $job ): void {
		$payload = $job->payload();

		self::drop_tables();

		if ( ! empty( $payload['dir'] ) && 0 === strpos( (string) $payload['dir'], FileSystem::storage_dir() ) ) {
			FileSystem::delete_directory( (string) $payload['dir'] );
		}

		if ( ! empty( $payload['temp'] ) && file_exists( (string) $payload['temp'] ) && 0 === strpos( (string) $payload['temp'], FileSystem::storage_dir() ) ) {
			wp_delete_file( (string) $payload['temp'] );
		}

		if ( (int) get_option( self::LOCK, 0 ) === $job->id() ) {
			delete_option( self::LOCK );
		}
	}

	/** Every temporary table of this site's tests. */
	public static function drop_tables(): void {
		global $wpdb;

		$tables = (array) $wpdb->get_col( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $wpdb->prefix ) . '%' . $wpdb->esc_like( self::SUFFIX ) ) );

		if ( ! $tables ) {
			return;
		}

		$wpdb->query( 'SET FOREIGN_KEY_CHECKS = 0' );

		foreach ( $tables as $table ) {
			$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', (string) $table ) );
		}

		$wpdb->query( 'SET FOREIGN_KEY_CHECKS = 1' );
	}

	/** A test whose browser went away: nothing has moved for 10 minutes. */
	private static function stale( RestoreTestJob $job ): bool {
		$row = \WPBP\Core\Database\JobsRepository::find( $job->id() );

		return null === $row || strtotime( (string) $row['updated_at'] ) < strtotime( current_time( 'mysql' ) ) - 10 * MINUTE_IN_SECONDS;
	}

	private static function table_name( string $table ): string {
		$name = $table . self::SUFFIX;

		return strlen( $name ) <= 64 ? $name : substr( $table, 0, 64 - strlen( self::SUFFIX ) - 9 ) . '_' . substr( md5( $table ), 0, 8 ) . self::SUFFIX;
	}

	private static function add_check( RestoreTestJob $job, string $key, string $status, string $message ): void {
		$labels  = array(
			'files'    => __( 'Every file', 'wrensafe' ),
			'database' => __( 'Database', 'wrensafe' ),
			'settings' => __( 'Site settings', 'wrensafe' ),
			'admin'    => __( 'Administrator', 'wrensafe' ),
			'code'     => __( 'Theme and plugins', 'wrensafe' ),
		);
		$payload = $job->payload();

		$payload['checks'][ $key ] = array(
			'key'     => $key,
			'label'   => $labels[ $key ] ?? $key,
			'status'  => $status,
			'message' => $message,
		);

		$job->set_payload( $payload );
	}
}

// phpcs:enable WordPress.WP.AlternativeFunctions, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange
