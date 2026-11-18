<?php
/**
 * Append-only, resumable ZIP writer for large backups.
 *
 * ZipArchive (libzip) rewrites the WHOLE archive into a temp file every time
 * it is closed, so building a backup in per-request batches cost O(n²) disk
 * I/O and needed twice the archive size in free space: appending a 1 KB file
 * to a 600 MB archive took ~10 s, and every later batch of a 2-3 GB site
 * timed out on 30-second hosts.
 *
 * This writer only ever appends to the archive and keeps the central
 * directory in a sidecar file until finish(). Each entry is streamed in
 * 1 MB slices, so memory stays flat, and on PHP 8.1+ (serializable
 * HashContext) a single huge file can be paused at a request deadline and
 * resumed in the next request. Compressed entries survive the pause because
 * every pause ends on a deflate SYNC_FLUSH (byte-aligned, no back-references
 * across it), so a fresh deflate context can continue the same entry.
 *
 * The output is a standard ZIP (ZIP64 when sizes, offsets or entry counts
 * need it), read back by ArchiveReader through ZipArchive like before.
 *
 * @package WPBP
 */

namespace WPBP\Backup;

defined( 'ABSPATH' ) || exit;

// Archives are streamed with native file handles (fopen/fread/fwrite/fseek):
// WP_Filesystem has no streaming or random-access write API. Every path is
// inside Wrensafe's protected storage directory.
// phpcs:disable WordPress.WP.AlternativeFunctions

// Exception messages are stored as a job's error_message and logged; they
// reach the browser only as JSON rendered with jQuery .text(), never as HTML.
// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped

class ZipStreamWriter {

	private const SLICE_BYTES = 1048576; // 1 MB read/compress slices.

	private const METHOD_STORE   = 0;
	private const METHOD_DEFLATE = 8;

	private const FLAG_UTF8 = 0x0800;

	private const MAX_32 = 0xFFFFFFFF;
	private const MAX_16 = 0xFFFF;

	/** Unix regular file, mode 0644, in the high word of the external attributes. */
	private const EXTERNAL_ATTR_FILE = 0x81A40000;

	/**
	 * File types that are already compressed: deflating them burns CPU on
	 * the host for ~0 % gain, so they are stored.
	 */
	private const STORED_EXTENSIONS = array(
		'jpg',
		'jpeg',
		'png',
		'gif',
		'webp',
		'avif',
		'heic',
		'heif',
		'jxl',
		'mp4',
		'm4v',
		'mov',
		'avi',
		'mkv',
		'webm',
		'wmv',
		'flv',
		'mpg',
		'mpeg',
		'mp3',
		'm4a',
		'aac',
		'ogg',
		'oga',
		'ogv',
		'opus',
		'flac',
		'zip',
		'gz',
		'tgz',
		'bz2',
		'xz',
		'7z',
		'rar',
		'zst',
		'woff',
		'woff2',
		'pdf',
		'docx',
		'xlsx',
		'pptx',
		'odt',
		'ods',
		'odp',
		'epub',
		'jar',
		'apk',
		'wbp',
		'wpress',
		'svgz',
	);

	/**
	 * Persisted writer state (see new_state()). Callers store it in the job
	 * payload between requests and hand it back to the constructor.
	 *
	 * @var array<string,mixed>
	 */
	private $state;

	/** @var resource|null */
	private $handle = null;

	/**
	 * @param array<string,mixed> $state State from new_state() or a previous state().
	 */
	public function __construct( array $state ) {
		$this->state = $state;
	}

	public function __destruct() {
		$this->close();
	}

	/**
	 * Start a new, empty archive (truncating any leftover file from a
	 * previous attempt) and return its initial state.
	 *
	 * @return array<string,mixed>
	 */
	public static function new_state( string $archive_path, string $central_path ): array {
		foreach ( array( $archive_path, $central_path ) as $path ) {
			$handle = fopen( $path, 'wb' );

			if ( false === $handle ) {
				throw new \RuntimeException( __( 'The backup archive could not be created (check the storage folder permissions).', 'wrensafe' ) );
			}

			fclose( $handle );
		}

		return array(
			'path'     => $archive_path,
			'central'  => $central_path,
			'offset'   => 0,
			'count'    => 0,
			'cd_bytes' => 0,
			'pending'  => null,
		);
	}

	/**
	 * Whether this PHP build can pause a single file mid-way (needs a
	 * serializable HashContext, PHP 8.1+). Older builds finish each file
	 * in the request that started it.
	 */
	public static function can_pause_mid_file(): bool {
		return PHP_VERSION_ID >= 80100;
	}

	/** @return array<string,mixed> The state to persist for the next request. */
	public function state(): array {
		return $this->state;
	}

	/** Entry name of the file currently paused mid-way, if any. */
	public function pending_name(): ?string {
		return is_array( $this->state['pending'] ) ? (string) $this->state['pending']['name'] : null;
	}

	/**
	 * Add (or continue adding) a file.
	 *
	 * Returns the SHA-256 of the file's contents once the entry is complete,
	 * or null when the deadline arrived first — the partial entry is kept in
	 * the state and the next call with the same entry name continues it.
	 *
	 * @param float|null $deadline microtime(true) value to pause at, or null to never pause.
	 */
	public function add_file( string $source_path, string $entry_name, ?float $deadline = null ): ?string {
		$pending = $this->state['pending'];

		if ( is_array( $pending ) && $pending['name'] !== $entry_name ) {
			throw new \RuntimeException( 'Archive writer resumed with a different entry than the one in progress.' );
		}

		$source = fopen( $source_path, 'rb' );

		if ( false === $source ) {
			throw new \RuntimeException(
				sprintf(
					/* translators: %s: file path inside the backup */
					__( 'A file could not be read while building the backup: %s', 'wrensafe' ),
					$entry_name
				)
			);
		}

		if ( ! is_array( $pending ) ) {
			$size    = (int) filesize( $source_path );
			$mtime   = (int) filemtime( $source_path );
			$pending = $this->begin_entry( $entry_name, $size, $mtime, $this->choose_method( $entry_name, $size ) );
		} elseif ( $pending['read'] > 0 ) {
			fseek( $source, (int) $pending['read'] );
		}

		$crc     = $this->restore_hash( $pending['crc'], 'crc32b' );
		$sha     = $this->restore_hash( $pending['sha'], 'sha256' );
		$deflate = self::METHOD_DEFLATE === $pending['method'] ? deflate_init( ZLIB_ENCODING_RAW, array( 'level' => 6 ) ) : null;

		$this->open();
		fseek( $this->handle, (int) $this->state['offset'] );

		$paused = false;

		while ( ! feof( $source ) ) {
			$slice = fread( $source, self::SLICE_BYTES );

			if ( false === $slice ) {
				fclose( $source );
				throw new \RuntimeException(
					sprintf(
						/* translators: %s: file path inside the backup */
						__( 'A read error occurred while adding a file to the backup: %s', 'wrensafe' ),
						$entry_name
					)
				);
			}

			if ( '' === $slice ) {
				break;
			}

			hash_update( $crc, $slice );
			hash_update( $sha, $slice );
			$pending['read'] += strlen( $slice );

			$pause = null !== $deadline && microtime( true ) >= $deadline && self::can_pause_mid_file() && ! feof( $source );

			// The pause point must be flushed together with the last slice:
			// deflate_add() ignores a flush request that has no input.
			$out = null !== $deflate ? deflate_add( $deflate, $slice, $pause ? ZLIB_SYNC_FLUSH : ZLIB_NO_FLUSH ) : $slice;
			$this->write( $out );
			$pending['written'] += strlen( $out );

			if ( $pause ) {
				$paused = true;
				break;
			}
		}

		fclose( $source );

		if ( $paused ) {
			// The SYNC_FLUSH above left the compressed run byte-aligned with
			// no back-references across it, so the next request's fresh
			// deflate context can append to the same entry.
			// Hex: the serialized hash state holds raw block bytes, and the job
			// payload it is stored in is JSON.
			$pending['crc']         = bin2hex( serialize( $crc ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize -- HashContext state, read back with allowed_classes limited to HashContext.
			$pending['sha']         = bin2hex( serialize( $sha ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize -- see above.
			$this->state['pending'] = $pending;
			$this->flush_handle();

			return null;
		}

		if ( null !== $deflate ) {
			$tail = deflate_add( $deflate, '', ZLIB_FINISH );
			$this->write( $tail );
			$pending['written'] += strlen( $tail );
		}

		$crc_value = (int) hexdec( hash_final( $crc ) );
		$this->complete_entry( $pending, $crc_value );

		return hash_final( $sha );
	}

	/**
	 * Add a small in-memory entry (JSON metadata).
	 *
	 * @return string SHA-256 of $data.
	 */
	public function add_string( string $entry_name, string $data ): string {
		if ( null !== $this->state['pending'] ) {
			throw new \RuntimeException( 'Archive writer cannot add a new entry while another is in progress.' );
		}

		$use_deflate = function_exists( 'gzdeflate' ) && strlen( $data ) > 64;
		$body        = $use_deflate ? (string) gzdeflate( $data, 6 ) : $data;
		$pending     = $this->begin_entry( $entry_name, strlen( $data ), time(), $use_deflate ? self::METHOD_DEFLATE : self::METHOD_STORE );

		$this->write( $body );
		$pending['read']    = strlen( $data );
		$pending['written'] = strlen( $body );

		$this->complete_entry( $pending, (int) hexdec( hash( 'crc32b', $data ) ) );

		return hash( 'sha256', $data );
	}

	/**
	 * Append the central directory and end records. After this the archive
	 * is a complete ZIP file. Returns its final size in bytes.
	 */
	public function finish(): int {
		if ( null !== $this->state['pending'] ) {
			throw new \RuntimeException( 'Archive writer cannot finish while an entry is still in progress.' );
		}

		$this->open();
		fseek( $this->handle, (int) $this->state['offset'] );

		$cd_offset = (int) $this->state['offset'];
		$central   = fopen( $this->state['central'], 'rb' );

		if ( false === $central ) {
			throw new \RuntimeException( __( 'The backup archive index could not be read back while finishing the archive.', 'wrensafe' ) );
		}

		while ( ! feof( $central ) ) {
			$chunk = fread( $central, self::SLICE_BYTES );

			if ( false === $chunk || '' === $chunk ) {
				break;
			}

			$this->write( $chunk );
		}

		fclose( $central );

		$cd_size = (int) $this->state['cd_bytes'];
		$count   = (int) $this->state['count'];

		if ( $count >= self::MAX_16 || $cd_size >= self::MAX_32 || $cd_offset >= self::MAX_32 ) {
			$zip64_eocd_offset = (int) $this->state['offset'];

			// ZIP64 end of central directory record.
			$this->write(
				pack( 'V', 0x06064b50 )
				. pack( 'P', 44 )
				. pack( 'v', ( 3 << 8 ) | 45 )
				. pack( 'v', 45 )
				. pack( 'V', 0 )
				. pack( 'V', 0 )
				. pack( 'P', $count )
				. pack( 'P', $count )
				. pack( 'P', $cd_size )
				. pack( 'P', $cd_offset )
			);

			// ZIP64 end of central directory locator.
			$this->write(
				pack( 'V', 0x07064b50 )
				. pack( 'V', 0 )
				. pack( 'P', $zip64_eocd_offset )
				. pack( 'V', 1 )
			);
		}

		$this->write(
			pack( 'V', 0x06054b50 )
			. pack( 'v', 0 )
			. pack( 'v', 0 )
			. pack( 'v', min( $count, self::MAX_16 ) )
			. pack( 'v', min( $count, self::MAX_16 ) )
			. pack( 'V', min( $cd_size, self::MAX_32 ) )
			. pack( 'V', min( $cd_offset, self::MAX_32 ) )
			. pack( 'v', 0 )
		);

		// Drop anything a request killed mid-write left past the end.
		ftruncate( $this->handle, (int) $this->state['offset'] );
		$this->flush_handle();
		$this->close();

		if ( file_exists( $this->state['central'] ) ) {
			wp_delete_file( $this->state['central'] );
		}

		clearstatcache( true, $this->state['path'] );

		return (int) $this->state['offset'];
	}

	/**
	 * Drop a partially written entry (its source file vanished between
	 * requests). The next entry overwrites its bytes; finish() truncates
	 * anything left past the end.
	 */
	public function abandon_pending(): void {
		if ( is_array( $this->state['pending'] ) ) {
			$this->state['offset']  = (int) $this->state['pending']['header_offset'];
			$this->state['pending'] = null;
		}
	}

	/** Close the archive handle (state is kept). */
	public function close(): void {
		if ( is_resource( $this->handle ) ) {
			fclose( $this->handle );
		}

		$this->handle = null;
	}

	/**
	 * Store or deflate: already-compressed media is stored; everything else
	 * (SQL dumps, PHP, CSS, JS, text) is deflated when zlib is available.
	 */
	private function choose_method( string $entry_name, int $size ): int {
		if ( $size < 64 || ! function_exists( 'deflate_init' ) ) {
			return self::METHOD_STORE;
		}

		$extension = strtolower( (string) pathinfo( $entry_name, PATHINFO_EXTENSION ) );

		return in_array( $extension, self::STORED_EXTENSIONS, true ) ? self::METHOD_STORE : self::METHOD_DEFLATE;
	}

	/**
	 * Write a local file header with placeholder CRC/sizes (patched by
	 * complete_entry once the data is written) and return the pending entry.
	 *
	 * @return array<string,mixed>
	 */
	private function begin_entry( string $entry_name, int $size, int $mtime, int $method ): array {
		$name_bytes = $entry_name;
		$zip64      = $size >= self::MAX_32;

		// Deflate never expands by more than ~0.03 %, so files close to the
		// 4 GB line get ZIP64 headers too.
		if ( self::METHOD_DEFLATE === $method && $size >= self::MAX_32 - 16777216 ) {
			$zip64 = true;
		}

		$extra = $zip64 ? pack( 'v', 0x0001 ) . pack( 'v', 16 ) . pack( 'P', 0 ) . pack( 'P', 0 ) : '';

		list( $dos_time, $dos_date ) = $this->dos_datetime( $mtime );

		$header = pack( 'V', 0x04034b50 )
			. pack( 'v', $zip64 ? 45 : 20 )
			. pack( 'v', self::FLAG_UTF8 )
			. pack( 'v', $method )
			. pack( 'v', $dos_time )
			. pack( 'v', $dos_date )
			. pack( 'V', 0 )
			. pack( 'V', $zip64 ? self::MAX_32 : 0 )
			. pack( 'V', $zip64 ? self::MAX_32 : 0 )
			. pack( 'v', strlen( $name_bytes ) )
			. pack( 'v', strlen( $extra ) )
			. $name_bytes
			. $extra;

		$header_offset = (int) $this->state['offset'];

		$this->open();
		fseek( $this->handle, $header_offset );
		$this->write( $header );

		return array(
			'name'          => $entry_name,
			'header_offset' => $header_offset,
			'header_length' => strlen( $header ),
			'method'        => $method,
			'zip64'         => $zip64,
			'dos_time'      => $dos_time,
			'dos_date'      => $dos_date,
			'read'          => 0,
			'written'       => 0,
			'crc'           => '',
			'sha'           => '',
		);
	}

	/**
	 * Patch the local header with the final CRC/sizes and append the
	 * entry's central directory record to the sidecar.
	 *
	 * @param array<string,mixed> $pending
	 */
	private function complete_entry( array $pending, int $crc ): void {
		$usize  = (int) $pending['read'];
		$csize  = (int) $pending['written'];
		$offset = (int) $pending['header_offset'];
		$zip64  = (bool) $pending['zip64'];

		if ( ! $zip64 && ( $usize >= self::MAX_32 || $csize >= self::MAX_32 ) ) {
			// The file grew past 4 GB after its header was written.
			throw new \RuntimeException(
				sprintf(
					/* translators: %s: file path inside the backup */
					__( 'A file grew past 4 GB while it was being backed up: %s. Exclude it or try again when it is not being written to.', 'wrensafe' ),
					$pending['name']
				)
			);
		}

		$end = (int) $this->state['offset'];

		// Patch CRC-32 and sizes into the local header.
		fseek( $this->handle, $offset + 14 );
		fwrite( $this->handle, pack( 'V', $crc ) . pack( 'V', $zip64 ? self::MAX_32 : $csize ) . pack( 'V', $zip64 ? self::MAX_32 : $usize ) );

		if ( $zip64 ) {
			fseek( $this->handle, $offset + 30 + strlen( (string) $pending['name'] ) + 4 );
			fwrite( $this->handle, pack( 'P', $usize ) . pack( 'P', $csize ) );
		}

		fseek( $this->handle, $end );

		// Central directory record; ZIP64 extra carries only overflowing fields, in spec order.
		$cd_extra_body = '';
		if ( $usize >= self::MAX_32 || $zip64 ) {
			$cd_extra_body .= pack( 'P', $usize );
		}
		if ( $csize >= self::MAX_32 || $zip64 ) {
			$cd_extra_body .= pack( 'P', $csize );
		}
		if ( $offset >= self::MAX_32 ) {
			$cd_extra_body .= pack( 'P', $offset );
		}

		$cd_extra = '' !== $cd_extra_body ? pack( 'v', 0x0001 ) . pack( 'v', strlen( $cd_extra_body ) ) . $cd_extra_body : '';
		$needs_45 = '' !== $cd_extra;

		$record = pack( 'V', 0x02014b50 )
			. pack( 'v', ( 3 << 8 ) | ( $needs_45 ? 45 : 20 ) )
			. pack( 'v', $zip64 ? 45 : 20 )
			. pack( 'v', self::FLAG_UTF8 )
			. pack( 'v', (int) $pending['method'] )
			. pack( 'v', (int) $pending['dos_time'] )
			. pack( 'v', (int) $pending['dos_date'] )
			. pack( 'V', $crc )
			. pack( 'V', ( $csize >= self::MAX_32 || $zip64 ) ? self::MAX_32 : $csize )
			. pack( 'V', ( $usize >= self::MAX_32 || $zip64 ) ? self::MAX_32 : $usize )
			. pack( 'v', strlen( (string) $pending['name'] ) )
			. pack( 'v', strlen( $cd_extra ) )
			. pack( 'v', 0 )
			. pack( 'v', 0 )
			. pack( 'v', 0 )
			. pack( 'V', self::EXTERNAL_ATTR_FILE )
			. pack( 'V', $offset >= self::MAX_32 ? self::MAX_32 : $offset )
			. $pending['name']
			. $cd_extra;

		$this->append_central( $record );

		$this->state['cd_bytes'] = (int) $this->state['cd_bytes'] + strlen( $record );
		$this->state['count']    = (int) $this->state['count'] + 1;
		$this->state['pending']  = null;

		$this->flush_handle();
	}

	/**
	 * Append one central directory record. The sidecar is first cut back to
	 * the length the persisted state knows about, so a record written by a
	 * request that was killed before its state was saved is not duplicated.
	 */
	private function append_central( string $record ): void {
		$handle = fopen( $this->state['central'], 'c+b' );

		if ( false === $handle ) {
			throw new \RuntimeException( __( 'The backup archive index could not be written (check the storage folder permissions).', 'wrensafe' ) );
		}

		ftruncate( $handle, (int) $this->state['cd_bytes'] );
		fseek( $handle, (int) $this->state['cd_bytes'] );
		$written = fwrite( $handle, $record );
		fclose( $handle );

		if ( strlen( $record ) !== $written ) {
			throw new \RuntimeException( __( 'The backup archive index could not be written (the disk may be full).', 'wrensafe' ) );
		}
	}

	private function restore_hash( string $serialized, string $algorithm ): \HashContext {
		if ( '' !== $serialized ) {
			$context = unserialize( (string) hex2bin( $serialized ), array( 'allowed_classes' => array( \HashContext::class ) ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_unserialize -- only HashContext may be instantiated.

			if ( $context instanceof \HashContext ) {
				return $context;
			}

			throw new \RuntimeException( 'Archive writer could not resume a paused file (hash state unreadable).' );
		}

		return hash_init( $algorithm );
	}

	private function open(): void {
		if ( is_resource( $this->handle ) ) {
			return;
		}

		// 'c+b': read/write, create if missing, never truncate.
		$handle = fopen( $this->state['path'], 'c+b' );

		if ( false === $handle ) {
			throw new \RuntimeException( __( 'The backup archive could not be opened for writing (check the storage folder permissions).', 'wrensafe' ) );
		}

		$this->handle = $handle;
	}

	private function write( string $bytes ): void {
		if ( '' === $bytes ) {
			return;
		}

		$length  = strlen( $bytes );
		$written = 0;

		while ( $written < $length ) {
			$result = fwrite( $this->handle, 0 === $written ? $bytes : substr( $bytes, $written ) );

			if ( false === $result || 0 === $result ) {
				throw new \RuntimeException( __( 'Writing to the backup archive failed. The disk may be full.', 'wrensafe' ) );
			}

			$written += $result;
		}

		$this->state['offset'] = (int) $this->state['offset'] + $length;
	}

	private function flush_handle(): void {
		if ( is_resource( $this->handle ) ) {
			fflush( $this->handle );
		}
	}

	/**
	 * MS-DOS time and date words for a Unix timestamp (local time, as the
	 * ZIP format expects; years before 1980 are clamped).
	 *
	 * @return array{0:int,1:int}
	 */
	private function dos_datetime( int $timestamp ): array {
		$timestamp = max( 315532800, $timestamp ); // 1980-01-01.
		$parts     = getdate( $timestamp );

		$time = ( $parts['hours'] << 11 ) | ( $parts['minutes'] << 5 ) | intdiv( $parts['seconds'], 2 );
		$date = ( ( $parts['year'] - 1980 ) << 9 ) | ( $parts['mon'] << 5 ) | $parts['mday'];

		return array( $time, $date );
	}
}

// phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped

// phpcs:enable WordPress.WP.AlternativeFunctions
