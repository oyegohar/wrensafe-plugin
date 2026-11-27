<?php
/**
 * Packages a staged backup (files.jsonl + database SQL in a job's tmp dir)
 * into a single original .wbp archive, then records it in wp_wpbp_backups.
 *
 * Issue 09: the writer is now RESUMABLE — instead of packing every file,
 * writing the metadata, and hashing the whole archive inside one AJAX
 * request (which died silently on live hosts with max_execution_time 30 and
 * left jobs stuck "running" at 90%), the work is split into bounded batches
 * driven by BackupManager phases: begin() → add_database_batch() →
 * add_files_batch() → finish() → create_record(). Each batch opens the
 * container, adds files until the request's time/bytes budget is spent,
 * closes it, and persists its cursor in the job payload so the next AJAX
 * request resumes exactly where the last one stopped.
 *
 * Internal container: ZIP, written by ZipStreamWriter (it needs only zlib,
 * not PHP's zip extension). Where zlib is missing too, PharData (TAR) is
 * used as a fallback so the engine still works on minimal PHP builds — the user-facing format is always Wrensafe's
 * own .wbp envelope (manifest.json, metadata.json, checksums.json, etc.);
 * the low-level container is an implementation detail recorded in
 * manifest.json so the Restore Engine knows how to open it.
 *
 * @package WPBP\Backup
 */

namespace WPBP\Backup;

use WPBP\Core\Database\BackupsRepository;
use WPBP\Utils\FileSystem;
use WPBP\Utils\Logger;
use WPBP\Utils\Sanitizer;

defined( 'ABSPATH' ) || exit;

// Backup archives and upload chunks are streamed in bounded blocks with native
// file handles (fopen/fread/fwrite/rename): WP_Filesystem has no streaming API.
// Every path is inside Wrensafe's protected storage directory, a PHP upload
// temp file, or validated by WPBP\Security\PathValidator.
// phpcs:disable WordPress.WP.AlternativeFunctions

class ArchiveWriter {

	/**
	 * Max raw bytes added to the container per request, so the container
	 * flush (ZipArchive::close reads/compresses the newly added files) stays
	 * within a shared host's max_execution_time even when single files are
	 * large.
	 */
	private const BATCH_MAX_BYTES = 104857600; // 100 MB.

	/**
	 * Initialize the resumable archive session: pick the destination path and
	 * container backend, verify disk space, and store every cursor the
	 * later batches need in the job payload.
	 */
	public function begin( BackupJob $job, array &$payload ): void {
		$tmp_dir = FileSystem::job_tmp_dir( $job->id() );

		$filename = $this->generate_filename();
		FileSystem::ensure_protected_directory( FileSystem::backups_dir() );
		$dest = FileSystem::backups_dir() . $filename;

		// ZIP is written by ZipStreamWriter and read back by ZipFileReader, so
		// it only needs zlib; TAR stays the fallback for PHP without zlib.
		$container_format = ( class_exists( '\ZipArchive' ) || ZipStreamWriter::available() ) ? 'zip' : ( class_exists( '\PharData' ) ? 'tar' : '' );

		if ( '' === $container_format ) {
			throw new \RuntimeException( esc_html__( 'No archive backend available on this server (ZipArchive or Phar is required).', 'wrensafe' ) );
		}

		// Fail fast when the disk clearly can't hold the archive. total_size is
		// the raw size of the files being packed; media-heavy sites barely
		// compress, so requiring that much free space (plus head-room) prevents
		// a half-written archive dying at 90% on a full live host.
		$estimated_need = (int) ( $payload['total_size'] ?? 0 ) + 52428800; // + 50 MB head-room.
		$disk_free      = function_exists( 'disk_free_space' ) ? @disk_free_space( FileSystem::backups_dir() ) : false; // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged

		if ( false !== $disk_free && $disk_free < $estimated_need ) {
			throw new \RuntimeException(
				esc_html(
					sprintf(
						/* translators: 1: free disk space, 2: estimated space needed */
						__( 'Not enough free disk space to build the backup archive (free: %1$s, estimated need: %2$s). Free up space on the server and try again.', 'wrensafe' ),
						size_format( (int) $disk_free ),
						size_format( $estimated_need )
					)
				)
			);
		}

		global $wpdb;
		$source_prefix = $wpdb->prefix;

		// Resolve the per-table database manifest entries up front (small —
		// one row per table); their checksums are filled by add_database_batch.
		$db_manifest = array();
		if ( ! empty( $payload['needs_db'] ) ) {
			foreach ( $payload['db_manifest'] ?? array() as $entry ) {
				$table = (string) ( $entry['source_table'] ?? '' );
				$slug  = (string) ( $entry['slug'] ?? Sanitizer::table_slug( $table ) );

				$db_manifest[] = array(
					'source_table'     => $table,
					'normalized_table' => $this->strip_prefix( $table, $source_prefix ),
					'source_prefix'    => $source_prefix,
					'rows'             => (int) ( $entry['rows'] ?? 0 ),
					'schema_file'      => 'schema/' . $slug . '.schema.sql',
					'data_file'        => 'data/' . $slug . '.data.sql',
					'schema_checksum'  => '',
					'data_checksum'    => '',
				);
			}
		}

		$payload['aw_filename']       = $filename;
		$payload['aw_dest']           = $dest;
		$payload['aw_container']      = $container_format;
		$payload['aw_tar_path']       = $tmp_dir . 'archive.tar';
		$payload['aw_db_manifest']    = $db_manifest;
		$payload['aw_db_index']       = 0;
		$payload['aw_file_offset']    = 0;
		$payload['aw_files_added']    = 0;
		$payload['aw_checksums_file'] = $tmp_dir . 'entry-checksums.tsv';

		if ( file_exists( $payload['aw_checksums_file'] ) ) {
			wp_delete_file( $payload['aw_checksums_file'] );
		}

		// ZIP archives are written by the append-only streaming writer (see
		// ZipStreamWriter for why ZipArchive can't be used to build them).
		$payload['aw_zip'] = 'zip' === $container_format
			? ZipStreamWriter::new_state( $dest, $tmp_dir . 'central-directory.bin' )
			: null;

		Logger::info(
			'backup_archive_writer_started: packing ' . ( $payload['file_count'] ?? 0 ) . ' files'
				. ' (' . size_format( (int) ( $payload['total_size'] ?? 0 ) ) . ') into a ' . $container_format . ' container.',
			array(),
			$job->id()
		);
	}

	/**
	 * Open the container for appending (creating it on the first batch).
	 *
	 * @return \ZipArchive|\PharData
	 */
	private function open_container( array $payload ) {
		if ( 'zip' === $payload['aw_container'] ) {
			$zip    = new \ZipArchive();
			$opened = $zip->open( $payload['aw_dest'], \ZipArchive::CREATE );

			if ( true !== $opened ) {
				/* translators: %d: ZipArchive error code */
				throw new \RuntimeException( esc_html( sprintf( __( 'The backup archive could not be opened for writing (zip error %d). Check that the backup folder is writable and the disk has free space.', 'wrensafe' ), (int) $opened ) ) );
			}

			return $zip;
		}

		return new \PharData( $payload['aw_tar_path'] );
	}

	/**
	 * @param \ZipArchive|\PharData $container
	 */
	private function close_container( $container ): void {
		if ( $container instanceof \ZipArchive ) {
			if ( ! $container->close() ) {
				throw new \RuntimeException( esc_html__( 'The server failed to flush the backup archive to disk (zip close failed). The disk may be full or the file blocked.', 'wrensafe' ) );
			}
			return;
		}

		unset( $container );
	}

	/**
	 * Add database schema/data files to the archive until every table is
	 * packed or the request deadline is reached. Persists its cursor in the
	 * payload and returns true when all database files are in the archive.
	 */
	public function add_database_batch( BackupJob $job, array &$payload, float $deadline ): bool {
		$tmp_dir  = FileSystem::job_tmp_dir( $job->id() );
		$manifest = $payload['aw_db_manifest'];
		$total    = count( $manifest );

		if ( $payload['aw_db_index'] >= $total ) {
			return true;
		}

		if ( ! empty( $payload['aw_zip'] ) ) {
			return $this->stream_database_batch( $tmp_dir, $payload, $deadline );
		}

		$container   = $this->open_container( $payload );
		$batch_bytes = 0;

		for ( $i = $payload['aw_db_index']; $i < $total; $i++ ) {
			$entry       = $manifest[ $i ];
			$schema_path = $tmp_dir . 'db/schema/' . basename( $entry['schema_file'] );
			$data_path   = $tmp_dir . 'db/data/' . basename( $entry['data_file'] );

			if ( file_exists( $schema_path ) ) {
				$container->addFile( $schema_path, 'database/' . $entry['schema_file'] );
				$manifest[ $i ]['schema_checksum'] = (string) hash_file( 'sha256', $schema_path );
				$batch_bytes                      += (int) filesize( $schema_path );
			}

			if ( file_exists( $data_path ) ) {
				$container->addFile( $data_path, 'database/' . $entry['data_file'] );
				$manifest[ $i ]['data_checksum'] = (string) hash_file( 'sha256', $data_path );
				$batch_bytes                    += (int) filesize( $data_path );
			}

			$payload['aw_db_index'] = $i + 1;

			if ( microtime( true ) >= $deadline || $batch_bytes >= self::BATCH_MAX_BYTES ) {
				break;
			}
		}

		$payload['aw_db_manifest'] = $manifest;

		$this->close_container( $container );

		return $payload['aw_db_index'] >= $total;
	}

	/**
	 * Add content files (from files.jsonl) to the archive until the request
	 * deadline or byte budget is reached, hashing each file into the
	 * entry-checksums sidecar as it goes. The files.jsonl BYTE offset is
	 * persisted so the next request fseek()s straight back to where this one
	 * stopped. Returns true when every file is in the archive.
	 */
	public function add_files_batch( BackupJob $job, array &$payload, float $deadline ): bool {
		$tmp_dir       = FileSystem::job_tmp_dir( $job->id() );
		$manifest_path = $tmp_dir . 'files.jsonl';

		if ( ! file_exists( $manifest_path ) ) {
			return true;
		}

		if ( ! empty( $payload['aw_zip'] ) ) {
			return $this->stream_files_batch( $manifest_path, $payload, $deadline );
		}

		$handle = fopen( $manifest_path, 'r' );

		if ( false === $handle ) {
			throw new \RuntimeException( esc_html__( 'The backup file manifest could not be reopened while building the archive.', 'wrensafe' ) );
		}

		if ( $payload['aw_file_offset'] > 0 ) {
			fseek( $handle, (int) $payload['aw_file_offset'] );
		}

		$checksums_handle = fopen( $payload['aw_checksums_file'], 'a' );
		$container        = $this->open_container( $payload );
		$batch_bytes      = 0;
		$done             = false;

		while ( true ) {
			$line = fgets( $handle );

			if ( false === $line ) {
				$done = true;
				break;
			}

			$payload['aw_file_offset'] = ftell( $handle );

			$entry = json_decode( trim( $line ), true );

			if ( ! is_array( $entry ) || empty( $entry['abs'] ) || ! file_exists( $entry['abs'] ) ) {
				continue;
			}

			$entry_name = 'files/' . $entry['root'] . '/' . $entry['path'];
			$container->addFile( $entry['abs'], $entry_name );

			$hash = (string) hash_file( 'sha256', $entry['abs'] );
			fwrite( $checksums_handle, $entry_name . "\t" . $hash . "\n" );

			++$payload['aw_files_added'];
			$batch_bytes += (int) ( $entry['size'] ?? 0 );

			if ( microtime( true ) >= $deadline || $batch_bytes >= self::BATCH_MAX_BYTES ) {
				break;
			}
		}

		fclose( $handle );
		fclose( $checksums_handle );

		// close_container is where ZipArchive actually reads/compresses the
		// batch — bounded by BATCH_MAX_BYTES so it fits the time budget.
		$this->close_container( $container );

		return $done;
	}

	/**
	 * Streaming variant of add_database_batch(): every SQL file is appended
	 * to the archive (and hashed in the same pass). A huge table dump can be
	 * paused mid-file at the deadline and resumed by the next request.
	 */
	private function stream_database_batch( string $tmp_dir, array &$payload, float $deadline ): bool {
		$manifest = $payload['aw_db_manifest'];
		$total    = count( $manifest );
		$writer   = new ZipStreamWriter( $payload['aw_zip'] );

		// Resume point inside the current table: 0 = schema file, 1 = data file.
		$part = (int) ( $payload['aw_db_part'] ?? 0 );

		for ( $i = (int) $payload['aw_db_index']; $i < $total; $i++ ) {
			$entry = $manifest[ $i ];
			$files = array(
				array( 'schema/' . basename( $entry['schema_file'] ), 'schema_file', 'schema_checksum' ),
				array( 'data/' . basename( $entry['data_file'] ), 'data_file', 'data_checksum' ),
			);

			for ( ; $part < 2; $part++ ) {
				list( $relative, $file_key, $checksum_key ) = $files[ $part ];
				$source                                     = $tmp_dir . 'db/' . $relative;

				if ( ! file_exists( $source ) ) {
					continue;
				}

				$sha = $writer->add_file( $source, 'database/' . $entry[ $file_key ], $deadline );

				if ( null === $sha ) {
					// Paused inside this SQL file — the next request resumes it.
					$payload['aw_db_index']    = $i;
					$payload['aw_db_part']     = $part;
					$payload['aw_db_manifest'] = $manifest;
					$payload['aw_zip']         = $writer->state();
					return false;
				}

				$manifest[ $i ][ $checksum_key ] = $sha;
			}

			$part                   = 0;
			$payload['aw_db_index'] = $i + 1;
			$payload['aw_db_part']  = 0;

			if ( microtime( true ) >= $deadline ) {
				break;
			}
		}

		$payload['aw_db_manifest'] = $manifest;
		$payload['aw_zip']         = $writer->state();

		return $payload['aw_db_index'] >= $total;
	}

	/**
	 * Streaming variant of add_files_batch(). Files are appended one after
	 * another until the deadline; a file still being written when the
	 * deadline hits is paused and continued by the next request, so no
	 * single file (a 2 GB video, say) has to fit into one request.
	 */
	private function stream_files_batch( string $manifest_path, array &$payload, float $deadline ): bool {
		$handle = fopen( $manifest_path, 'r' );

		if ( false === $handle ) {
			throw new \RuntimeException( esc_html__( 'The backup file manifest could not be reopened while building the archive.', 'wrensafe' ) );
		}

		if ( $payload['aw_file_offset'] > 0 ) {
			fseek( $handle, (int) $payload['aw_file_offset'] );
		}

		$checksums_handle = fopen( $payload['aw_checksums_file'], 'a' );
		$writer           = new ZipStreamWriter( $payload['aw_zip'] );
		$done             = false;

		while ( true ) {
			$line_start = ftell( $handle );
			$line       = fgets( $handle );

			if ( false === $line ) {
				$done = true;
				break;
			}

			$entry = json_decode( trim( $line ), true );

			if ( ! is_array( $entry ) || empty( $entry['abs'] ) ) {
				$payload['aw_file_offset'] = ftell( $handle );
				continue;
			}

			$entry_name = 'files/' . $entry['root'] . '/' . $entry['path'];
			if ( ! file_exists( $entry['abs'] ) || ! is_readable( $entry['abs'] ) ) {
				// Deleted or locked since the scan: skip it, like before. If it
				// vanished while paused mid-way, drop its partial entry too.
				if ( $writer->pending_name() === $entry_name ) {
					$writer->abandon_pending();
				}

				$payload['aw_file_offset'] = ftell( $handle );
				continue;
			}

			$sha = $writer->add_file( $entry['abs'], $entry_name, $deadline );

			if ( null === $sha ) {
				// Paused mid-file: re-read this same line next request.
				$payload['aw_file_offset'] = $line_start;
				break;
			}

			fwrite( $checksums_handle, $entry_name . "\t" . $sha . "\n" );

			$payload['aw_file_offset'] = ftell( $handle );
			++$payload['aw_files_added'];

			if ( microtime( true ) >= $deadline ) {
				break;
			}
		}

		fclose( $handle );
		fclose( $checksums_handle );

		$payload['aw_zip'] = $writer->state();
		$writer->close();

		return $done;
	}

	/**
	 * Write the JSON metadata entries (manifest, metadata, database manifest,
	 * checksums, chain, report) into the finished container and verify the
	 * archive landed on disk. Called once, after every content batch is done.
	 */
	public function finish( BackupJob $job, array &$payload ): void {
		$builder = new ManifestBuilder();
		$type    = $payload['backup_type'] ?? 'full';

		$context = array(
			'archive_filename' => $payload['aw_filename'],
			'backup_type'      => $type,
			'needs_files'      => ! empty( $payload['needs_files'] ),
			'needs_db'         => ! empty( $payload['needs_db'] ),
			'file_count'       => $payload['file_count'] ?? 0,
			'total_size'       => $payload['total_size'] ?? 0,
			'tables_exported'  => count( $payload['tables'] ?? array() ),
			'note'             => $payload['note'] ?? '',
			'tag'              => $payload['tag'] ?? '',
		);

		$manifest                     = $builder->build_manifest( $context );
		$manifest['container_format'] = $payload['aw_container'];

		// Rebuild the per-entry checksums map from the sidecar written during
		// the file batches (kept out of the job payload, which would otherwise
		// grow by megabytes on large sites).
		$checksums = array(
			'files'    => array(),
			'database' => '',
		);

		if ( file_exists( $payload['aw_checksums_file'] ) ) {
			$handle = fopen( $payload['aw_checksums_file'], 'r' );
			$line   = false === $handle ? false : fgets( $handle );

			while ( false !== $line ) {
				$parts = explode( "\t", rtrim( $line, "\n" ), 2 );

				if ( 2 === count( $parts ) && '' !== $parts[0] ) {
					// A sliced checksum (a huge file packed across requests on
					// PHP before 8.1) goes in its own map, which versions before
					// 1.2.1 don't read (they'd report it as a mismatch).
					if ( SliceHash::is_sliced( $parts[1] ) ) {
						$checksums['files_sliced'][ $parts[0] ] = $parts[1];
					} else {
						$checksums['files'][ $parts[0] ] = $parts[1];
					}
				}

				$line = fgets( $handle );
			}

			if ( false !== $handle ) {
				fclose( $handle );
			}
		}

		$chain_mode  = $payload['chain_mode'] ?? 'full';
		$baseline_id = $payload['baseline_backup_id'] ?? null;

		$chain_json = array(
			'type'               => $chain_mode,
			'baseline_backup_id' => $baseline_id,
			'prior_chain_ids'    => $baseline_id ? wp_list_pluck( BackupsRepository::get_chain( (int) $baseline_id ), 'id' ) : array(),
		);

		// For the TAR fallback the content batches wrote to tmp/archive.tar;
		// move it to the destination now, before the metadata entries go in.
		if ( 'tar' === $payload['aw_container'] && file_exists( $payload['aw_tar_path'] ) ) {
			rename( $payload['aw_tar_path'], $payload['aw_dest'] );
		}

		$this->append_metadata_entries(
			$payload['aw_dest'],
			$payload['aw_container'],
			$manifest,
			$builder->build_metadata( $context ),
			$payload['aw_db_manifest'],
			$checksums,
			$builder->build_report( $context ),
			$chain_json,
			! empty( $payload['aw_zip'] ) ? new ZipStreamWriter( $payload['aw_zip'] ) : null
		);

		// The container is fully written and closed at this point; clear the stat
		// cache so filesize()/the checksum read see the final bytes on disk.
		clearstatcache( true, $payload['aw_dest'] );

		if ( ! file_exists( $payload['aw_dest'] ) || (int) filesize( $payload['aw_dest'] ) <= 0 ) {
			throw new \RuntimeException( esc_html__( 'The backup archive was not written to disk (empty or missing after packing). The server may have blocked the write or run out of space.', 'wrensafe' ) );
		}

		Logger::info( 'backup_archive_writer_completed: archive written (' . size_format( (int) filesize( $payload['aw_dest'] ) ) . ', ' . (int) $payload['aw_files_added'] . ' files packed).', array(), $job->id() );
	}

	/**
	 * Insert the completed backup's database record. Runs after the final
	 * whole-file checksum has been verified by the chunked checksum phase.
	 *
	 * A sliced checksum (see SliceHash) goes in its own column and the
	 * classic one stays empty: Wrensafe 1.2.0 and older (after a downgrade)
	 * then skip the whole-file check of that backup instead of reporting a
	 * mismatch and marking it corrupted.
	 */
	public function create_record( BackupJob $job, array $payload, string $checksum ): int {
		clearstatcache( true, $payload['aw_dest'] );

		return BackupsRepository::create(
			array(
				'job_id'             => $job->id(),
				'schedule_id'        => $payload['schedule_id'] ?? null,
				'file_name'          => $payload['aw_filename'],
				'file_path'          => $payload['aw_dest'],
				'backup_type'        => $payload['backup_type'] ?? 'full',
				'size'               => (int) filesize( $payload['aw_dest'] ),
				'status'             => 'completed',
				'source_url'         => home_url(),
				'wp_version'         => get_bloginfo( 'version' ),
				'php_version'        => PHP_VERSION,
				'checksum'           => SliceHash::is_sliced( $checksum ) ? '' : $checksum,
				'sliced_checksum'    => SliceHash::is_sliced( $checksum ) ? $checksum : '',
				'note'               => (string) ( $payload['note'] ?? '' ),
				'tags'               => (string) ( $payload['tag'] ?? '' ),
				'destination'        => 'local',
				'chain_mode'         => $payload['chain_mode'] ?? 'full',
				'baseline_backup_id' => $payload['baseline_backup_id'] ?? null,
			)
		);
	}

	private function generate_filename(): string {
		$host = (string) wp_parse_url( home_url(), PHP_URL_HOST );
		$host = '' !== $host ? $host : 'site';
		$slug = preg_replace( '/[^a-z0-9]+/', '-', strtolower( $host ) );
		$slug = trim( $slug, '-' );

		$random_id = strtolower( substr( wp_generate_password( 16, false, false ), 0, 12 ) );

		return sprintf( '%s-%s-%s.wbp', $slug, gmdate( 'Ymd-His' ), $random_id );
	}

	/**
	 * Strip a known prefix from a table name, used to compute the
	 * prefix-independent "normalized_table" stored per entry in
	 * database/manifest.json.
	 */
	private function strip_prefix( string $table, string $prefix ): string {
		if ( '' !== $prefix && 0 === strpos( $table, $prefix ) ) {
			return substr( $table, strlen( $prefix ) );
		}

		return $table;
	}

	/**
	 * Reopen the finished archive to add the small JSON metadata entries
	 * that depend on data only known after the main content was written.
	 */
	private function append_metadata_entries( string $path, string $container_format, array $manifest, array $metadata, array $db_manifest, array $checksums, array $report, array $chain_json, ?ZipStreamWriter $writer = null ): void {
		$entries = array(
			'manifest.json'          => $manifest,
			'metadata.json'          => $metadata,
			'database/manifest.json' => $db_manifest,
			'checksums.json'         => $checksums,
			'search-replace.json'    => array(
				'performed'    => false,
				'replacements' => array(),
			),
			'backup-chain.json'      => $chain_json,
			'encryption.json'        => array( 'encrypted' => false ),
			'report.json'            => $report,
		);

		if ( null !== $writer ) {
			foreach ( $entries as $name => $data ) {
				$writer->add_string( $name, (string) wp_json_encode( $data ) );
			}

			$writer->finish();
		} elseif ( 'zip' === $container_format ) {
			$zip = new \ZipArchive();
			$zip->open( $path, \ZipArchive::CREATE );

			foreach ( $entries as $name => $data ) {
				$zip->addFromString( $name, wp_json_encode( $data ) );
			}

			$zip->close();
		} else {
			// The file was renamed from .tar to .wbp, so the TAR format must
			// be passed explicitly — Phar can no longer infer it from the extension.
			$phar = new \PharData( $path, 0, null, \Phar::TAR );

			foreach ( $entries as $name => $data ) {
				$phar->addFromString( $name, wp_json_encode( $data ) );
			}

			unset( $phar );
		}
	}
}

// phpcs:enable WordPress.WP.AlternativeFunctions
