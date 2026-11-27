<?php
/**
 * Reads a ZIP archive with plain PHP (file functions and zlib), without
 * PHP's zip extension.
 *
 * Wrensafe writes its .wbp archives itself (ZipStreamWriter), so it must be
 * able to read them back on any server too. PHP's zip extension isn't always
 * there: on Windows it is a separate DLL since PHP 8.2 that local stacks
 * such as Laragon and XAMPP ship switched off, and some hosts leave it out.
 * ArchiveReader still uses ZipArchive when it can; this reader takes over
 * when ZipArchive is missing or refuses the file, and it lets a large entry
 * be unpacked across several requests (copy() with $skip, see there).
 *
 * Supports stored and deflated entries, ZIP64 and data descriptors (sizes
 * come from the central directory). Encrypted entries and other compression
 * methods are reported as unsupported. Every entry copied to the end is
 * checked against the CRC-32 recorded in the archive.
 *
 * @package WPBP\Restore
 */

namespace WPBP\Restore;

use WPBP\Backup\ZipStreamWriter;

defined( 'ABSPATH' ) || exit;

// Archives are read with native file handles (fopen/fread/fseek): WP_Filesystem
// has no streaming or random-access read API. Every path is a backup inside
// Wrensafe's protected storage directory or an upload being validated there.
// phpcs:disable WordPress.WP.AlternativeFunctions

class ZipFileReader {

	private const SIGNATURE_END      = 0x06054b50;
	private const SIGNATURE_END64    = 0x06064b50;
	private const SIGNATURE_LOCATOR  = 0x07064b50;
	private const SIGNATURE_CENTRAL  = 0x02014b50;
	private const SIGNATURE_LOCAL    = 0x04034b50;
	private const MAX_32             = 0xFFFFFFFF;
	private const MAX_16             = 0xFFFF;
	private const MAX_INDEX_BYTES    = 268435456; // A central directory this big is not one of ours.
	private const COPY_BYTES         = 1048576;
	private const INFLATE_INPUT      = 8192;
	private const READ_LIMIT_DEFAULT = 67108864;

	/**
	 * Seconds of new work a resumed copy does before it may pause again.
	 * Resuming a compressed entry inflates it again up to where it stopped;
	 * without this, a step whose deadline passed during that catch-up would
	 * pause after one piece and a large entry would take thousands of steps.
	 */
	public const MIN_RUN_SECONDS = 4.0;

	/** Reasons open() or copy() can fail, for messages and the log. */
	public const ERROR_NO_ZLIB   = 'no_zlib';
	public const ERROR_UNOPENED  = 'unopened';
	public const ERROR_NO_END    = 'no_end';
	public const ERROR_INDEX     = 'index';
	public const ERROR_MISSING   = 'missing';
	public const ERROR_ENCRYPTED = 'encrypted';
	public const ERROR_METHOD    = 'method';
	public const ERROR_DATA      = 'data';
	public const ERROR_WRITE     = 'write';

	private string $path;

	/** The raw central directory. */
	private string $directory = '';

	/**
	 * Entry name => offset of its record in $directory (the first one when a
	 * name repeats, as ZipArchive does).
	 *
	 * @var array<string,int>
	 */
	private array $records = array();

	private int $archive_size = 0;

	private string $error = '';

	public function __construct( string $path ) {
		$this->path = $path;
	}

	/** Whether this server can read compressed entries (zlib's incremental inflate). */
	public static function available(): bool {
		return function_exists( 'inflate_init' ) && function_exists( 'inflate_add' );
	}

	/** Why the last open() or copy() failed (one of the ERROR_* codes), or ''. */
	public function error(): string {
		return $this->error;
	}

	/** Read the archive's index (central directory). */
	public function open(): bool {
		$this->error     = '';
		$this->directory = '';
		$this->records   = array();

		if ( ! self::available() ) {
			return $this->fail( self::ERROR_NO_ZLIB );
		}

		$handle = is_readable( $this->path ) ? fopen( $this->path, 'rb' ) : false;

		if ( false === $handle ) {
			return $this->fail( self::ERROR_UNOPENED );
		}

		$this->archive_size = (int) filesize( $this->path );
		$end                = $this->read_end_record( $handle );

		if ( null === $end ) {
			fclose( $handle );
			return $this->fail( self::ERROR_NO_END );
		}

		list( $count, $size, $offset ) = $end;

		if ( $size > self::MAX_INDEX_BYTES || $offset + $size > $this->archive_size ) {
			fclose( $handle );
			return $this->fail( self::ERROR_INDEX );
		}

		fseek( $handle, $offset );
		$directory = $this->read_exactly( $handle, $size );
		fclose( $handle );

		if ( null === $directory || ! $this->index( $directory, $count ) ) {
			$this->directory = '';
			$this->records   = array();
			return $this->fail( self::ERROR_INDEX );
		}

		return true;
	}

	/**
	 * Entry names, in the archive's order.
	 *
	 * @return string[]
	 */
	public function names(): array {
		return array_map( 'strval', array_keys( $this->records ) );
	}

	public function count(): int {
		return count( $this->records );
	}

	/**
	 * An entry's details from the central directory, or null if it isn't there.
	 *
	 * @return array{name:string,size:int,comp_size:int,method:int,crc:int,encrypted:bool,local_offset:int}|null
	 */
	public function stat( string $name ): ?array {
		if ( ! isset( $this->records[ $name ] ) ) {
			return null;
		}

		$at     = $this->records[ $name ];
		$fields = unpack( 'vflags/vmethod/vtime/vdate/Vcrc/Vcomp/Vsize/vname/vextra', substr( $this->directory, $at + 8, 24 ) );
		$local  = unpack( 'V', substr( $this->directory, $at + 42, 4 ) )[1];
		$size   = (int) $fields['size'];
		$comp   = (int) $fields['comp'];

		if ( self::MAX_32 === $size || self::MAX_32 === $comp || self::MAX_32 === $local ) {
			// ZIP64: the overflowing values follow in the extra field, in this order.
			$extra  = substr( $this->directory, $at + 46 + (int) $fields['name'], (int) $fields['extra'] );
			$values = $this->zip64_values( $extra );

			if ( self::MAX_32 === $size ) {
				$size = (int) array_shift( $values );
			}
			if ( self::MAX_32 === $comp ) {
				$comp = (int) array_shift( $values );
			}
			if ( self::MAX_32 === $local ) {
				$local = (int) array_shift( $values );
			}
		}

		return array(
			'name'         => $name,
			'size'         => $size,
			'comp_size'    => $comp,
			'method'       => (int) $fields['method'],
			'crc'          => (int) $fields['crc'],
			'encrypted'    => 0 !== ( (int) $fields['flags'] & 0x0001 ),
			'local_offset' => (int) $local,
		);
	}

	/** Where an entry's (stored or compressed) bytes start in the archive file, or null. */
	public function data_offset( string $name ): ?int {
		$stat = $this->stat( $name );

		if ( null === $stat ) {
			return null;
		}

		$handle = fopen( $this->path, 'rb' );

		if ( false === $handle ) {
			return null;
		}

		$start = $this->data_start( $handle, $stat );
		fclose( $handle );

		return $start;
	}

	/** An entry's whole content, or null (missing, too large for $limit, unreadable). */
	public function read( string $name, int $limit = self::READ_LIMIT_DEFAULT ): ?string {
		$stat = $this->stat( $name );

		if ( null === $stat || $stat['size'] > $limit ) {
			return null;
		}

		$content = '';
		$result  = $this->copy(
			$name,
			static function ( string $bytes ) use ( &$content ): bool {
				$content .= $bytes;
				return true;
			}
		);

		return $result['done'] && '' === $result['error'] ? $content : null;
	}

	/**
	 * Unpack an entry into $sink, optionally continuing an earlier call.
	 *
	 * $sink receives the entry's bytes in order and returns false to stop
	 * (a failed write). With $skip > 0 the first $skip bytes were already
	 * delivered by an earlier call that paused: a stored entry continues
	 * right there; a compressed one is inflated again from its start (deflate
	 * can't be entered in the middle) and the bytes up to $skip are
	 * dropped, which costs CPU but no disk writes. $crc is the CRC-32 of
	 * those first $skip bytes, as the earlier call returned it, or -1 when
	 * the caller doesn't track it (a checksum of the whole content is
	 * checked instead): the entry's CRC is then only checked from byte 0.
	 *
	 * At $deadline the call pauses (after delivering at least one piece, so
	 * every call makes progress) and returns done = false with the new
	 * position and CRC for the next call.
	 *
	 * @param callable(string):bool $sink
	 * @return array{done:bool,position:int,crc:int,error:string}
	 */
	public function copy( string $name, callable $sink, int $skip = 0, int $crc = 0, ?float $deadline = null ): array {
		$this->error = '';
		$stat        = $this->stat( $name );
		$result      = array(
			'done'     => false,
			'position' => $skip,
			'crc'      => $crc,
			'error'    => '',
		);

		if ( null === $stat ) {
			return $this->copy_failed( $result, self::ERROR_MISSING );
		}

		if ( $stat['encrypted'] ) {
			return $this->copy_failed( $result, self::ERROR_ENCRYPTED );
		}

		if ( 0 !== $stat['method'] && 8 !== $stat['method'] ) {
			return $this->copy_failed( $result, self::ERROR_METHOD );
		}

		if ( $skip < 0 || $skip > $stat['size'] ) {
			return $this->copy_failed( $result, self::ERROR_DATA );
		}

		$handle = fopen( $this->path, 'rb' );

		if ( false === $handle ) {
			return $this->copy_failed( $result, self::ERROR_UNOPENED );
		}

		$start = $this->data_start( $handle, $stat );

		if ( null === $start || $start + $stat['comp_size'] > $this->archive_size ) {
			fclose( $handle );
			return $this->copy_failed( $result, self::ERROR_DATA );
		}

		$hash     = hash_init( 'crc32b' );
		$progress = 0 === $stat['method']
			? $this->copy_stored( $handle, $start, $stat, $sink, $hash, $skip, $deadline )
			: $this->copy_deflated( $handle, $start, $stat, $sink, $hash, $skip, $deadline );

		fclose( $handle );

		if ( '' !== $progress['error'] ) {
			return $this->copy_failed( $result, $progress['error'] );
		}

		// The CRC-32 of everything delivered so far: earlier calls' and this one's.
		$part = (int) hexdec( hash_final( $hash ) );

		if ( 0 === $skip ) {
			$crc = $part;
		} elseif ( $crc >= 0 ) {
			$crc = ZipStreamWriter::crc32_combine( $crc, $part, $progress['position'] - $skip );
		}

		$done = $progress['position'] === (int) $stat['size'];

		if ( $done && $crc >= 0 && $crc !== (int) $stat['crc'] ) {
			return $this->copy_failed( $result, self::ERROR_DATA );
		}

		return array(
			'done'     => $done,
			'position' => $progress['position'],
			'crc'      => $crc,
			'error'    => '',
		);
	}

	/**
	 * @param resource              $handle
	 * @param array<string,mixed>   $stat
	 * @param callable(string):bool $sink
	 * @param \HashContext          $hash CRC-32 of the bytes delivered by this call.
	 * @return array{position:int,error:string}
	 */
	private function copy_stored( $handle, int $start, array $stat, callable $sink, $hash, int $position, ?float $deadline ): array {
		$size = (int) $stat['size'];

		fseek( $handle, $start + $position );

		while ( $position < $size ) {
			$bytes = fread( $handle, (int) min( self::COPY_BYTES, $size - $position ) );

			if ( false === $bytes || '' === $bytes ) {
				return $this->progress( $position, self::ERROR_DATA );
			}

			if ( ! $sink( $bytes ) ) {
				return $this->progress( $position, self::ERROR_WRITE );
			}

			hash_update( $hash, $bytes );
			$position += strlen( $bytes );

			if ( $position < $size && null !== $deadline && microtime( true ) >= $deadline ) {
				break;
			}
		}

		return $this->progress( $position, '' );
	}

	/**
	 * @param resource              $handle
	 * @param array<string,mixed>   $stat
	 * @param callable(string):bool $sink
	 * @param \HashContext          $hash CRC-32 of the bytes delivered by this call.
	 * @return array{position:int,error:string}
	 */
	private function copy_deflated( $handle, int $start, array $stat, callable $sink, $hash, int $skip, ?float $deadline ): array {
		$inflate = inflate_init( ZLIB_ENCODING_RAW );

		if ( false === $inflate ) {
			return $this->progress( $skip, self::ERROR_NO_ZLIB );
		}

		$size      = (int) $stat['size'];
		$remaining = (int) $stat['comp_size'];
		$produced  = 0; // Bytes inflated so far, skipped ones included.
		$delivered = false;
		$pause_at  = $deadline;

		fseek( $handle, $start );

		while ( $remaining > 0 ) {
			$input = fread( $handle, (int) min( self::INFLATE_INPUT, $remaining ) );

			if ( false === $input || '' === $input ) {
				return $this->progress( $skip, self::ERROR_DATA );
			}

			$remaining -= strlen( $input );
			// Small inputs keep each call's output small: deflate expands at
			// most ~1000 times, so 8 KB in is at most ~8 MB out.
			$output = @inflate_add( $inflate, $input, $remaining > 0 ? ZLIB_SYNC_FLUSH : ZLIB_FINISH ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Damaged data returns false and becomes a clear error below; zlib's warning would only fill the site's error log.

			if ( false === $output ) {
				return $this->progress( $skip, self::ERROR_DATA );
			}

			$length = strlen( $output );

			if ( $produced + $length > $size ) {
				return $this->progress( $skip, self::ERROR_DATA );
			}

			if ( $produced + $length > $skip ) {
				$new = $produced >= $skip ? $output : substr( $output, $skip - $produced );

				if ( ! $sink( $new ) ) {
					return $this->progress( $skip, self::ERROR_WRITE );
				}

				hash_update( $hash, $new );

				if ( ! $delivered && null !== $pause_at ) {
					$pause_at = max( $pause_at, microtime( true ) + self::MIN_RUN_SECONDS );
				}

				$delivered = true;
			}

			$produced += $length;

			if ( $delivered && $produced < $size && null !== $pause_at && microtime( true ) >= $pause_at ) {
				break;
			}
		}

		// Every compressed byte read and still short of the recorded size (or
		// a size with no compressed data at all): damaged, or crafted to keep
		// a resumable unpack waiting for bytes that never come.
		if ( $remaining <= 0 && $produced < $size ) {
			return $this->progress( $skip, self::ERROR_DATA );
		}

		return $this->progress( max( $skip, $produced ), '' );
	}

	/** @return array{position:int,error:string} */
	private function progress( int $position, string $error ): array {
		return array(
			'position' => $position,
			'error'    => $error,
		);
	}

	/**
	 * @param array{done:bool,position:int,crc:int,error:string} $result
	 * @return array{done:bool,position:int,crc:int,error:string}
	 */
	private function copy_failed( array $result, string $error ): array {
		$this->error     = $error;
		$result['error'] = $error;

		return $result;
	}

	/**
	 * Where the entry's data starts: after its local header, whose name and
	 * extra field lengths can differ from the central directory's.
	 *
	 * @param resource            $handle
	 * @param array<string,mixed> $stat
	 */
	private function data_start( $handle, array $stat ): ?int {
		$offset = (int) $stat['local_offset'];

		if ( $offset + 30 > $this->archive_size ) {
			return null;
		}

		fseek( $handle, $offset );
		$header = (string) fread( $handle, 30 );

		if ( 30 !== strlen( $header ) || pack( 'V', self::SIGNATURE_LOCAL ) !== substr( $header, 0, 4 ) ) {
			return null;
		}

		$lengths = unpack( 'vname/vextra', substr( $header, 26, 4 ) );

		return $offset + 30 + (int) $lengths['name'] + (int) $lengths['extra'];
	}

	/**
	 * The entry count, size and offset of the central directory, from the
	 * end-of-central-directory record (and its ZIP64 version when present).
	 *
	 * @param resource $handle
	 * @return array{0:int,1:int,2:int}|null
	 */
	private function read_end_record( $handle ): ?array {
		if ( $this->archive_size < 22 ) {
			return null;
		}

		$tail_length = (int) min( $this->archive_size, 65557 ); // 22-byte record + the longest comment.
		fseek( $handle, $this->archive_size - $tail_length );
		$tail = $this->read_exactly( $handle, $tail_length );

		if ( null === $tail ) {
			return null;
		}

		// The last signature whose record and comment end exactly at the end of the file.
		$signature = pack( 'V', self::SIGNATURE_END );
		$at        = strrpos( $tail, $signature );

		while ( false !== $at ) {
			if ( $at + 22 <= $tail_length && $at + 22 + unpack( 'v', substr( $tail, $at + 20, 2 ) )[1] === $tail_length ) {
				break;
			}

			$at = $at > 0 ? strrpos( substr( $tail, 0, $at ), $signature ) : false;
		}

		if ( false === $at ) {
			return null;
		}

		$record = unpack( 'vdisk/vstart/vhere/vcount/Vsize/Voffset', substr( $tail, $at + 4, 16 ) );
		$count  = (int) $record['count'];
		$size   = (int) $record['size'];
		$offset = (int) $record['offset'];

		if ( self::MAX_16 !== $count && self::MAX_32 !== $size && self::MAX_32 !== $offset ) {
			return array( $count, $size, $offset );
		}

		$locator = $at - 20;

		if ( $locator < 0 || pack( 'V', self::SIGNATURE_LOCATOR ) !== substr( $tail, $locator, 4 ) ) {
			return null;
		}

		fseek( $handle, (int) unpack( 'P', substr( $tail, $locator + 8, 8 ) )[1] );
		$record64 = $this->read_exactly( $handle, 56 );

		if ( null === $record64 || pack( 'V', self::SIGNATURE_END64 ) !== substr( $record64, 0, 4 ) ) {
			return null;
		}

		return array(
			(int) unpack( 'P', substr( $record64, 32, 8 ) )[1],
			(int) unpack( 'P', substr( $record64, 40, 8 ) )[1],
			(int) unpack( 'P', substr( $record64, 48, 8 ) )[1],
		);
	}

	/** Index the central directory's records by entry name; false when it is malformed. */
	private function index( string $directory, int $count ): bool {
		$length    = strlen( $directory );
		$signature = pack( 'V', self::SIGNATURE_CENTRAL );
		$at        = 0;
		$found     = 0;
		$records   = array();

		while ( $at < $length ) {
			if ( $at + 46 > $length || substr( $directory, $at, 4 ) !== $signature ) {
				return false;
			}

			$lengths = unpack( 'vname/vextra/vcomment', substr( $directory, $at + 28, 6 ) );
			$next    = $at + 46 + (int) $lengths['name'] + (int) $lengths['extra'] + (int) $lengths['comment'];

			if ( $next > $length ) {
				return false;
			}

			$name = substr( $directory, $at + 46, (int) $lengths['name'] );

			if ( ! isset( $records[ $name ] ) ) {
				$records[ $name ] = $at;
			}

			$at = $next;
			++$found;
		}

		if ( $found !== $count ) {
			return false;
		}

		$this->directory = $directory;
		$this->records   = $records;

		return true;
	}

	/**
	 * The 64-bit values of a ZIP64 extended information extra field.
	 *
	 * @return int[]
	 */
	private function zip64_values( string $extra ): array {
		$at     = 0;
		$length = strlen( $extra );

		while ( $at + 4 <= $length ) {
			$header = unpack( 'vid/vsize', substr( $extra, $at, 4 ) );

			if ( 0x0001 === (int) $header['id'] ) {
				$values = array();

				for ( $i = 0; $i + 8 <= (int) $header['size']; $i += 8 ) {
					$values[] = (int) unpack( 'P', substr( $extra, $at + 4 + $i, 8 ) )[1];
				}

				return $values;
			}

			$at += 4 + (int) $header['size'];
		}

		return array();
	}

	/**
	 * Exactly $length bytes from the handle's position, or null.
	 *
	 * @param resource $handle
	 */
	private function read_exactly( $handle, int $length ): ?string {
		$data = '';
		$read = 0;

		while ( $read < $length ) {
			$part = fread( $handle, (int) min( 8388608, $length - $read ) );

			if ( false === $part || '' === $part ) {
				return null;
			}

			$data .= $part;
			$read += strlen( $part );
		}

		return $data;
	}

	private function fail( string $error ): bool {
		$this->error = $error;

		return false;
	}
}

// phpcs:enable WordPress.WP.AlternativeFunctions
