<?php
/**
 * Verifies .wbp archive integrity: a whole-file checksum (the original,
 * coarse check: a SHA-256, or since 1.2.1 on old PHP a sliced checksum, see
 * SliceHash and ArchiveChecksum), and a deeper check of the archive's internal
 * structure — manifest/metadata presence, archive format version, and a
 * per-entry checksum of every database schema/data file and every backed
 * up content file. The deep check is what guards against a corrupted or
 * tampered archive being restored even though the outer .wbp file itself
 * still opens.
 *
 * @package WPBP\Backup
 */

namespace WPBP\Backup;

use WPBP\Restore\ArchiveReader;
use WPBP\Utils\Logger;

defined( 'ABSPATH' ) || exit;

// Backup archives and upload chunks are streamed in bounded blocks with native
// file handles (fopen/fread/fwrite/rename): WP_Filesystem has no streaming API.
// Every path is inside Wrensafe's protected storage directory, a PHP upload
// temp file, or validated by WPBP\Security\PathValidator.
// phpcs:disable WordPress.WP.AlternativeFunctions

class IntegrityChecker {

	const ALGORITHM = 'sha256';

	/** Bytes read per streaming-hash iteration (4 MB) — never loads the whole file. */
	private const STREAM_CHUNK = 4194304;

	/**
	 * Compute the checksum of a file on disk.
	 *
	 * Returns '' on any failure (missing/unreadable/empty file, hashing error)
	 * and logs the exact reason. Callers that must not complete a backup on a
	 * failed checksum should treat '' as fatal (see ArchiveWriter / BackupManager).
	 *
	 * The actual hashing is delegated to streaming_sha256() so even multi-GB
	 * archives are hashed in bounded chunks and never read into memory at once —
	 * the cause of "Calculating checksum → An error occurred" on memory-limited
	 * live hosts.
	 */
	public function compute( string $file_path ): string {
		$result = self::streaming_sha256( $file_path );

		if ( is_wp_error( $result ) ) {
			Logger::error( 'checksum_failed: ' . $result->get_error_message() . ' [' . self::safe_path( $file_path ) . ']' );
			return '';
		}

		return $result;
	}

	/**
	 * Stream-hash a file with SHA-256 in bounded chunks.
	 *
	 * Hosting-safe: clears the stat cache, validates the file is present,
	 * readable and non-empty, prefers hash_file() when the hash extension is
	 * available, and otherwise falls back to an explicit chunked
	 * hash_init/hash_update/hash_final loop. Returns the lowercase hex digest,
	 * or a WP_Error naming the exact failure for logging/AJAX surfacing.
	 *
	 * @return string|\WP_Error
	 */
	public static function streaming_sha256( string $path ) {
		// A stale stat cache can report the wrong size (or "missing") for a file
		// that was just written/renamed/closed — clear it before any check.
		clearstatcache( true, $path );

		if ( '' === $path ) {
			return new \WP_Error( 'wpbp_checksum_no_path', __( 'No file path was provided for checksum.', 'wrensafe' ) );
		}

		if ( ! file_exists( $path ) ) {
			return new \WP_Error( 'wpbp_checksum_missing', __( 'The backup file was not found on disk when calculating its checksum.', 'wrensafe' ) );
		}

		if ( ! is_readable( $path ) ) {
			return new \WP_Error( 'wpbp_checksum_unreadable', __( 'The backup file is not readable (check file permissions / open_basedir).', 'wrensafe' ) );
		}

		$size = filesize( $path );

		if ( false === $size || $size <= 0 ) {
			return new \WP_Error( 'wpbp_checksum_empty', __( 'The backup file is empty or its size could not be read.', 'wrensafe' ) );
		}

		// Give large-file hashing more head-room where the host allows it; never
		// fatal if set_time_limit is disabled (disable_functions / safe restrictions).
		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( 0 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.PHP.DiscouragedPHPFunctions.runtime_configuration_set_time_limit, Squiz.PHP.DiscouragedFunctions.Discouraged -- extra time where the host allows it; every step is still time-budgeted.
		}

		// Preferred path: the hash extension's own file hasher (also streams).
		if ( function_exists( 'hash_file' ) ) {
			$hash = is_readable( $path ) ? hash_file( self::ALGORITHM, $path ) : false;

			if ( is_string( $hash ) && '' !== $hash ) {
				return $hash;
			}
		}

		// Fallback: explicit chunked streaming hash for builds where hash_file
		// is unavailable or returned false.
		if ( ! function_exists( 'hash_init' ) ) {
			return new \WP_Error( 'wpbp_checksum_no_hash_ext', __( 'The PHP hash extension is unavailable, so the backup checksum could not be calculated.', 'wrensafe' ) );
		}

		$handle = is_readable( $path ) ? fopen( $path, 'rb' ) : false; // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen

		if ( false === $handle ) {
			return new \WP_Error( 'wpbp_checksum_open_failed', __( 'The backup file could not be opened for reading while calculating its checksum.', 'wrensafe' ) );
		}

		$context = hash_init( self::ALGORITHM );

		while ( ! feof( $handle ) ) {
			$buffer = fread( $handle, self::STREAM_CHUNK );

			if ( false === $buffer ) {
				fclose( $handle );
				return new \WP_Error( 'wpbp_checksum_read_failed', __( 'A read error occurred while calculating the backup checksum.', 'wrensafe' ) );
			}

			hash_update( $context, $buffer );
		}

		fclose( $handle );

		return hash_final( $context );
	}

	/**
	 * Compute a checksum and return a WP_Error (instead of '') on failure, so
	 * the caller can surface the exact reason and refuse to mark the backup
	 * completed. Used at the two points a final archive checksum is taken.
	 *
	 * @return string|\WP_Error
	 */
	public function compute_or_error( string $file_path ) {
		return self::streaming_sha256( $file_path );
	}

	/**
	 * Shorten an absolute path for logs so the host's directory layout isn't
	 * needlessly exposed — keeps only the file name plus its parent directory.
	 */
	private static function safe_path( string $path ): string {
		if ( '' === $path ) {
			return '(none)';
		}

		$base   = basename( $path );
		$parent = basename( dirname( $path ) );

		return $parent . '/' . $base;
	}

	/**
	 * Whether a file's current checksum matches the expected value (a
	 * whole-file SHA-256 or a sliced checksum, calculated the same way).
	 */
	public function verify( string $file_path, string $expected_checksum ): bool {
		if ( '' === $expected_checksum || ! file_exists( $file_path ) ) {
			return false;
		}

		return ArchiveChecksum::matches( $file_path, $expected_checksum );
	}

	/**
	 * The per-file checksums of an archive's checksums.json: entry name =>
	 * recorded checksum. "files" holds the SHA-256 of each file (what every
	 * version reads); "files_sliced" (1.2.1+) the files whose checksum is a
	 * sliced one (a huge file packed across requests on PHP before 8.1),
	 * kept apart so that older versions, which can't check them, skip them
	 * instead of reporting a mismatch.
	 *
	 * @param mixed $checksums Decoded checksums.json.
	 * @return array<string,string>
	 */
	public static function file_checksums( $checksums ): array {
		$map = array();

		foreach ( array( 'files', 'files_sliced' ) as $key ) {
			if ( is_array( $checksums ) && is_array( $checksums[ $key ] ?? null ) ) {
				foreach ( $checksums[ $key ] as $name => $checksum ) {
					$map[ (string) $name ] = (string) $checksum;
				}
			}
		}

		return $map;
	}

	/**
	 * Open an archive and run the full deep verification. Convenience
	 * wrapper for callers (e.g. Backup Browser "Verify") that don't already
	 * have an open ArchiveReader. If the archive is encrypted and no
	 * password is supplied, the archive can't be opened to inspect its
	 * contents at all — the caller should fall back to verify() against the
	 * stored whole-file checksum in that case (see 'requires_password').
	 *
	 * @return array{valid:bool,message:string,checked_files:int,checked_database_files:int,requires_password:bool}
	 */
	public function verify_archive( string $file_path, string $password = '' ): array {
		$reader = new ArchiveReader( $file_path, $password );
		$result = $reader->validate();

		if ( ! $result['valid'] ) {
			return array(
				'valid'                  => false,
				'message'                => $result['message'],
				'checked_files'          => 0,
				'checked_database_files' => 0,
				'requires_password'      => ! empty( $result['requires_password'] ),
			);
		}

		return $this->verify_archive_contents( $reader, $result['manifest'] );
	}

	/**
	 * Run the deep verification against an archive that's already been
	 * opened and validated (manifest.json present, format version
	 * supported) — reused by RestoreManager so an archive is never decrypted
	 * or opened twice for the same restore.
	 *
	 * @return array{valid:bool,message:string,checked_files:int,checked_database_files:int,requires_password:bool}
	 */
	public function verify_archive_contents( ArchiveReader $reader, array $manifest ): array {
		$cursor = array();

		return (array) $this->verify_archive_contents_step( $reader, $manifest, $cursor, null );
	}

	/**
	 * Resumable form of verify_archive_contents(): checks entries until
	 * $deadline and returns null (cursor saved in $cursor) when more work
	 * remains, or the final result array once every entry is checked or one
	 * fails. A 2-3 GB archive is far too much to hash inside one request.
	 *
	 * @param array<string,mixed> $cursor   Position; pass an empty array on the first call.
	 * @param float|null          $deadline microtime(true) to stop at, or null for no limit.
	 * @return array<string,mixed>|null
	 */
	public function verify_archive_contents_step( ArchiveReader $reader, array $manifest, array &$cursor, ?float $deadline ): ?array {
		$invalid = static function ( string $message ) {
			return array(
				'valid'                  => false,
				'message'                => $message,
				'checked_files'          => 0,
				'checked_database_files' => 0,
				'requires_password'      => false,
			);
		};

		if ( empty( $cursor ) ) {
			if ( null === $reader->read_json( 'metadata.json' ) ) {
				return $invalid( __( 'This archive is missing metadata.json and cannot be trusted.', 'wrensafe' ) );
			}

			$cursor = array(
				'stage'      => ! empty( $manifest['contains_database'] ) ? 'db' : 'files',
				'db_index'   => 0,
				'file_index' => 0,
				'checked_db' => 0,
				'checked'    => 0,
				'bytes'      => 0,
			);
		}

		$out_of_time = static function () use ( $deadline ): bool {
			return null !== $deadline && microtime( true ) >= $deadline;
		};

		if ( 'db' === $cursor['stage'] ) {
			$db_manifest = $reader->read_json( 'database/manifest.json' );

			if ( null === $db_manifest ) {
				return $invalid( __( 'This archive is missing database/manifest.json and cannot be trusted.', 'wrensafe' ) );
			}

			$db_manifest = array_values( $db_manifest );
			$total       = count( $db_manifest );

			while ( $cursor['db_index'] < $total ) {
				$entry = $db_manifest[ $cursor['db_index'] ];

				foreach ( array(
					'schema_file' => 'schema_checksum',
					'data_file'   => 'data_checksum',
				) as $file_key => $checksum_key ) {
					if ( empty( $entry[ $file_key ] ) ) {
						continue;
					}

					$check = $this->verify_entry( $reader, 'database/' . $entry[ $file_key ], (string) ( $entry[ $checksum_key ] ?? '' ) );

					if ( null !== $check ) {
						return $invalid( $check );
					}

					++$cursor['checked_db'];
				}

				++$cursor['db_index'];

				if ( $cursor['db_index'] < $total && $out_of_time() ) {
					return null;
				}
			}

			$cursor['stage'] = 'files';

			if ( $out_of_time() ) {
				return null;
			}
		}

		$files_map = self::file_checksums( $reader->read_json( 'checksums.json' ) );
		$names     = array_keys( $files_map );
		$total     = count( $names );

		$cursor['files_total'] = $total;

		while ( $cursor['file_index'] < $total ) {
			$entry_name = (string) $names[ $cursor['file_index'] ];
			$check      = $this->verify_entry( $reader, $entry_name, (string) $files_map[ $entry_name ] );

			if ( null !== $check ) {
				return $invalid( $check );
			}

			++$cursor['file_index'];
			++$cursor['checked'];

			if ( $cursor['file_index'] < $total && $out_of_time() ) {
				return null;
			}
		}

		return array(
			'valid'                  => true,
			'message'                => __( 'Integrity check passed.', 'wrensafe' ),
			'checked_files'          => (int) $cursor['checked'],
			'checked_database_files' => (int) $cursor['checked_db'],
			'requires_password'      => false,
		);
	}

	/**
	 * Checksum one archive entry and compare it to the expected value.
	 * Returns null when the entry is fine, or a human-readable error
	 * message naming the failing entry when it isn't.
	 */
	private function verify_entry( ArchiveReader $reader, string $entry_name, string $expected_checksum ): ?string {
		$actual = $reader->checksum_entry( $entry_name, $expected_checksum );

		if ( null === $actual ) {
			return sprintf(
				/* translators: %s: archive entry path */
				__( '%s is missing from the archive.', 'wrensafe' ),
				$entry_name
			);
		}

		if ( ! SliceHash::equals( $expected_checksum, $actual ) ) {
			return sprintf(
				/* translators: %s: archive entry path */
				__( '%s checksum mismatch.', 'wrensafe' ),
				$entry_name
			);
		}

		return null;
	}
}

// phpcs:enable WordPress.WP.AlternativeFunctions
