<?php
/**
 * Opens and validates a .wbp archive, regardless of whether it was written
 * with the ZipArchive or PharData (TAR fallback) container.
 *
 * ZIP archives are read through ZipArchive when PHP's zip extension is there
 * and accepts the file, and otherwise through ZipFileReader (plain PHP and
 * zlib), so a backup can be restored on any server. Large entries are
 * unpacked across requests with extract_step().
 *
 * @package WPBP\Restore
 */

namespace WPBP\Restore;

use WPBP\Backup\ArchiveChecksum;
use WPBP\Backup\BackupEncryption;
use WPBP\Backup\ManifestBuilder;
use WPBP\Backup\SliceHash;
use WPBP\Utils\ChunkSizeCalculator;
use WPBP\Utils\FileSystem;
use WPBP\Utils\Str;

defined( 'ABSPATH' ) || exit;

// Backup archives and upload chunks are streamed in bounded blocks with native
// file handles (fopen/fread/fwrite/rename): WP_Filesystem has no streaming API.
// Every path is inside Wrensafe's protected storage directory, a PHP upload
// temp file, or validated by WPBP\Security\PathValidator.
// phpcs:disable WordPress.WP.AlternativeFunctions

class ArchiveReader {

	private string $path;
	private string $password;
	private string $container_format         = '';
	private bool $opened                     = false;
	private bool $encrypted_without_password = false;
	private bool $decryption_failed          = false;
	private ?string $temp_decrypted_path     = null;

	/** Why this server can't decrypt the archive (no OpenSSL), or ''. */
	private string $decryption_unavailable = '';

	/**
	 * The plain-PHP reader of a ZIP: used for everything when ZipArchive is
	 * missing or refused the file, and for extract_step() always. Opened on
	 * first use; false once it failed to open.
	 *
	 * @var ZipFileReader|false|null
	 */
	private $plain = null;

	/** Whether ZipArchive is the backend for this ZIP (false: ZipFileReader). */
	private bool $use_zip_archive = false;

	/** Why opening failed, for the log: ZipArchive's error and the plain reader's. */
	private string $open_detail = '';

	/** Why the plain reader couldn't open the file (a ZipFileReader::ERROR_* code), or ''. */
	private string $plain_error = '';

	/**
	 * A single ZipArchive handle kept open for this reader's lifetime and
	 * reused by every read/checksum/extract/list call. Before Issue 11 each
	 * of those opened a fresh ZipArchive on the whole file — so verifying an
	 * archive with thousands of entries (e.g. an 87 MB WooCommerce backup)
	 * meant thousands of full ZIP opens in one request, which timed out the
	 * upload finalize and slowed the restore preflight to a crawl.
	 */
	private ?\ZipArchive $zip = null;

	/**
	 * Preview readers of an encrypted archive decrypt only its tail (index
	 * and metadata), never the file contents — see
	 * BackupEncryption::decrypt_tail_for_preview().
	 */
	private bool $preview_only = false;

	public function __construct( string $path, string $password = '' ) {
		$this->path     = $path;
		$this->password = $password;
	}

	/**
	 * Read only the archive's index and metadata (fast for large encrypted
	 * archives). Entry contents must not be read from a preview reader.
	 */
	public function preview_only(): self {
		$this->preview_only = true;

		return $this;
	}

	public function __destruct() {
		$this->close();

		if ( null !== $this->temp_decrypted_path && file_exists( $this->temp_decrypted_path ) ) {
			wp_delete_file( $this->temp_decrypted_path );
		}
	}

	/**
	 * Close the cached ZIP handle. Safe to call repeatedly.
	 */
	public function close(): void {
		if ( null !== $this->zip ) {
			try {
				$this->zip->close(); // Opened read-only: nothing to write back.
			} catch ( \Throwable $e ) {
				// PHP 8 throws for an archive that is already closed; nothing to do.
				unset( $e );
			}

			$this->zip = null;
		}
	}

	/**
	 * The shared ZipArchive handle, opened once on first use. Returns null if
	 * the archive can't be opened as a ZIP.
	 */
	private function zip(): ?\ZipArchive {
		if ( null !== $this->zip ) {
			return $this->zip;
		}

		if ( ! $this->opened && ! $this->open() ) {
			return null;
		}

		if ( 'zip' !== $this->container_format || ! $this->use_zip_archive ) {
			return null;
		}

		$zip = new \ZipArchive();

		if ( true !== $zip->open( $this->path ) ) {
			return null;
		}

		$this->zip = $zip;

		return $this->zip;
	}

	/**
	 * Detect and confirm the archive can be opened. Does not yet validate
	 * contents. Transparently decrypts to a temp file first if the archive
	 * is encrypted and a password was supplied.
	 */
	public function open(): bool {
		if ( $this->opened ) {
			return true;
		}

		if ( ! file_exists( $this->path ) ) {
			return false;
		}

		if ( ( new BackupEncryption() )->is_encrypted( $this->path ) ) {
			if ( '' === $this->password ) {
				$this->encrypted_without_password = true;
				return false;
			}

			$this->decryption_unavailable = BackupEncryption::unavailable_reason();

			if ( '' !== $this->decryption_unavailable ) {
				return false;
			}

			FileSystem::ensure_protected_directory( FileSystem::storage_dir() . 'tmp' );
			$decrypted_path = FileSystem::storage_dir() . 'tmp/decrypt-' . wp_generate_password( 16, false ) . '.wbp';

			$decrypted = $this->preview_only
				? ( new BackupEncryption() )->decrypt_tail_for_preview( $this->path, $this->password, $decrypted_path )
				: ( new BackupEncryption() )->decrypt_file( $this->path, $this->password, $decrypted_path );

			if ( ! $decrypted ) {
				$this->decryption_failed = true;
				return false;
			}

			$this->temp_decrypted_path = $decrypted_path;
			$this->path                = $decrypted_path;
		}

		$handle = fopen( $this->path, 'rb' );
		$header = $handle ? fread( $handle, 4 ) : '';
		if ( $handle ) {
			fclose( $handle );
		}

		if ( "PK\x03\x04" === $header ) {
			return $this->open_zip();
		}

		// A TAR backup (written where ZipArchive is missing). PharData reads
		// Phar's own metadata from an archive as it opens it, so it only gets
		// a plain TAR without any (see TarSafety). Every other PharData use in
		// this class runs after open() has succeeded.
		if ( class_exists( '\PharData' ) && TarSafety::is_plain_tar( $this->path ) ) {
			try {
				new \PharData( $this->path, 0, null, \Phar::TAR );
				$this->container_format = 'tar';
				$this->opened           = true;
				return true;
			} catch ( \Throwable $e ) {
				return false;
			}
		}

		return false;
	}

	/**
	 * Open a ZIP: through ZipArchive when it is installed and accepts the
	 * file, otherwise with the plain-PHP reader. PHP's zip extension is off
	 * on some servers (a separate DLL on Windows since PHP 8.2: Laragon,
	 * XAMPP), and a .wbp is a standard ZIP that zlib alone can read.
	 */
	private function open_zip(): bool {
		$detail = 'ZipArchive not installed';

		if ( class_exists( '\ZipArchive' ) ) {
			$zip    = new \ZipArchive();
			$result = $zip->open( $this->path );

			if ( true === $result ) {
				$zip->close();
				$this->container_format = 'zip';
				$this->use_zip_archive  = true;
				$this->opened           = true;
				return true;
			}

			$detail = 'ZipArchive error ' . (int) $result;
		}

		$plain = $this->plain();

		if ( null !== $plain ) {
			$this->container_format = 'zip';
			$this->use_zip_archive  = false;
			$this->opened           = true;
			return true;
		}

		$this->open_detail = $detail . '; built-in reader: ' . $this->plain_error;

		return false;
	}

	/** The plain-PHP ZIP reader, opened once; null when it can't read this file. */
	private function plain(): ?ZipFileReader {
		if ( null === $this->plain ) {
			$reader      = new ZipFileReader( $this->path );
			$this->plain = $reader->open() ? $reader : false;

			if ( false === $this->plain ) {
				$this->plain_error = $reader->error();
			}
		}

		return false === $this->plain ? null : $this->plain;
	}

	/**
	 * Why the archive couldn't be opened, for the log (empty when it opened):
	 * e.g. "ZipArchive not installed; built-in reader: no_end".
	 */
	public function open_detail(): string {
		return $this->open_detail;
	}

	public function container_format(): string {
		return $this->container_format;
	}

	/**
	 * Read and json_decode a single entry from the archive (e.g. "manifest.json").
	 */
	public function read_json( string $entry_name ): ?array {
		$contents = $this->read_raw( $entry_name );

		if ( null === $contents ) {
			return null;
		}

		$decoded = json_decode( $contents, true );

		return is_array( $decoded ) ? $decoded : null;
	}

	/**
	 * Read a single entry's raw contents from the archive.
	 */
	public function read_raw( string $entry_name ): ?string {
		if ( ! $this->opened && ! $this->open() ) {
			return null;
		}

		if ( 'zip' === $this->container_format ) {
			if ( ! $this->use_zip_archive ) {
				$plain = $this->plain();

				// 256 MB: far above any real manifest or checksums.json, low enough
				// that a crafted entry can't exhaust the server's memory.
				return null !== $plain ? $plain->read( $entry_name, 268435456 ) : null;
			}

			$zip = $this->zip();

			if ( null === $zip ) {
				return null;
			}

			$contents = $zip->getFromName( $entry_name );

			return false !== $contents ? $contents : null;
		}

		try {
			$phar = new \PharData( $this->path, 0, null, \Phar::TAR );

			if ( ! isset( $phar[ $entry_name ] ) ) {
				return null;
			}

			return $phar[ $entry_name ]->getContent();
		} catch ( \Throwable $e ) {
			return null;
		}
	}

	/**
	 * Extract a single archive entry to a destination path on disk.
	 *
	 * Streams the entry's content rather than loading it whole (an entry
	 * can be as large as a multi-gigabyte database data file), and writes
	 * to a temp path first — the destination is only ever replaced once
	 * the entire entry has extracted successfully.
	 */
	/** Raw central directory of the ZIP, read once (see stored_entry_span()). */
	private ?string $central_directory = null;

	/** Path of the (possibly decrypted) archive file this reader reads. */
	public function path(): string {
		return $this->path;
	}

	/**
	 * Where a STORED (uncompressed) entry's bytes sit inside the archive
	 * file, so a very large file — media is stored, not deflated — can be
	 * copied out in slices across several requests instead of in one.
	 * Returns null for deflated entries or when the location can't be read.
	 *
	 * @return array{offset:int,size:int}|null
	 */
	public function stored_entry_span( string $entry_name ): ?array {
		if ( ( $this->opened || $this->open() ) && 'zip' === $this->container_format && ! $this->use_zip_archive ) {
			$plain = $this->plain();
			$stat  = null !== $plain ? $plain->stat( $entry_name ) : null;

			if ( null === $stat || 0 !== $stat['method'] || $stat['encrypted'] ) {
				return null;
			}

			$offset = $plain->data_offset( $entry_name );

			return null === $offset ? null : array(
				'offset' => $offset,
				'size'   => $stat['size'],
			);
		}

		$zip = $this->zip();

		if ( null === $zip ) {
			return null;
		}

		$stat = $zip->statName( $entry_name );

		if ( false === $stat || 0 !== (int) $stat['comp_method'] || ! empty( $stat['encryption_method'] ) ) {
			return null;
		}

		$local = $this->local_header_offset( $entry_name );

		if ( null === $local ) {
			return null;
		}

		$handle = fopen( $this->path, 'rb' );

		if ( false === $handle ) {
			return null;
		}

		fseek( $handle, $local );
		$header = (string) fread( $handle, 30 );
		fclose( $handle );

		if ( 30 !== strlen( $header ) || pack( 'V', 0x04034b50 ) !== substr( $header, 0, 4 ) ) {
			return null;
		}

		$name_length  = unpack( 'v', substr( $header, 26, 2 ) )[1];
		$extra_length = unpack( 'v', substr( $header, 28, 2 ) )[1];

		return array(
			'offset' => $local + 30 + $name_length + $extra_length,
			'size'   => (int) $stat['size'],
		);
	}

	/**
	 * Local header offset of an entry, from the ZIP central directory
	 * (ZipArchive doesn't expose it). ZIP64 offsets are supported.
	 */
	private function local_header_offset( string $entry_name ): ?int {
		if ( null === $this->central_directory ) {
			$this->central_directory = (string) $this->read_central_directory();
		}

		$directory = $this->central_directory;
		$needle    = $entry_name;
		$from      = 0;

		while ( false !== ( $pos = strpos( $directory, $needle, $from ) ) ) { // phpcs:ignore Generic.CodeAnalysis.AssignmentInCondition.FoundInWhileCondition
			$start = $pos - 46;
			$from  = $pos + 1;

			if ( $start < 0 || pack( 'V', 0x02014b50 ) !== substr( $directory, $start, 4 ) || strlen( $needle ) !== unpack( 'v', substr( $directory, $start + 28, 2 ) )[1] ) {
				continue;
			}

			$offset = unpack( 'V', substr( $directory, $start + 42, 4 ) )[1];

			if ( 0xFFFFFFFF !== $offset ) {
				return (int) $offset;
			}

			// ZIP64: the offset follows any overflowing size fields in the extra field.
			$extra_length = unpack( 'v', substr( $directory, $start + 30, 2 ) )[1];
			$extra        = substr( $directory, $start + 46 + strlen( $needle ), $extra_length );
			$skip         = ( 0xFFFFFFFF === unpack( 'V', substr( $directory, $start + 24, 4 ) )[1] ? 8 : 0 ) + ( 0xFFFFFFFF === unpack( 'V', substr( $directory, $start + 20, 4 ) )[1] ? 8 : 0 );
			$cursor       = 0;
			$extra_size   = strlen( $extra );

			while ( $cursor + 4 <= $extra_size ) {
				$id   = unpack( 'v', substr( $extra, $cursor, 2 ) )[1];
				$size = unpack( 'v', substr( $extra, $cursor + 2, 2 ) )[1];

				if ( 0x0001 === $id && $size >= $skip + 8 ) {
					return (int) unpack( 'P', substr( $extra, $cursor + 4 + $skip, 8 ) )[1];
				}

				$cursor += 4 + $size;
			}

			return null;
		}

		return null;
	}

	/** The archive's raw central directory, or '' when it can't be located. */
	private function read_central_directory(): string {
		$handle = fopen( $this->path, 'rb' );

		if ( false === $handle ) {
			return '';
		}

		$size        = (int) filesize( $this->path );
		$tail_length = (int) min( $size, 65557 );
		fseek( $handle, $size - $tail_length );
		$tail = (string) fread( $handle, $tail_length );
		$eocd = strrpos( $tail, pack( 'V', 0x06054b50 ) );

		if ( false === $eocd || strlen( $tail ) < $eocd + 22 ) {
			fclose( $handle );
			return '';
		}

		$cd_size   = unpack( 'V', substr( $tail, $eocd + 12, 4 ) )[1];
		$cd_offset = unpack( 'V', substr( $tail, $eocd + 16, 4 ) )[1];

		if ( 0xFFFFFFFF === $cd_offset || 0xFFFFFFFF === $cd_size ) {
			$locator = $eocd - 20;

			if ( $locator < 0 || pack( 'V', 0x07064b50 ) !== substr( $tail, $locator, 4 ) ) {
				fclose( $handle );
				return '';
			}

			fseek( $handle, unpack( 'P', substr( $tail, $locator + 8, 8 ) )[1] );
			$record = (string) fread( $handle, 56 );

			if ( 56 !== strlen( $record ) || pack( 'V', 0x06064b50 ) !== substr( $record, 0, 4 ) ) {
				fclose( $handle );
				return '';
			}

			$cd_size   = unpack( 'P', substr( $record, 40, 8 ) )[1];
			$cd_offset = unpack( 'P', substr( $record, 48, 8 ) )[1];
		}

		fseek( $handle, (int) $cd_offset );
		$directory = (string) fread( $handle, (int) $cd_size );
		fclose( $handle );

		return $directory;
	}

	public function extract_to( string $entry_name, string $destination_path ): bool {
		if ( ! $this->opened && ! $this->open() ) {
			return false;
		}

		wp_mkdir_p( dirname( $destination_path ) );

		$tmp_path = $destination_path . '.extracting.tmp';
		$ok       = $this->stream_entry_to_file( $entry_name, $tmp_path );

		if ( ! $ok ) {
			if ( file_exists( $tmp_path ) ) {
				wp_delete_file( $tmp_path );
			}
			return false;
		}

		return rename( $tmp_path, $destination_path );
	}

	private function stream_entry_to_file( string $entry_name, string $tmp_path ): bool {
		$out = fopen( $tmp_path, 'wb' );

		if ( false === $out ) {
			return false;
		}

		if ( 'zip' === $this->container_format ) {
			// The built-in reader first: it reads the archive's index once, while
			// ZipArchive::getStream() reopens the whole archive for every entry
			// on older PHP (minutes for a backup of thousands of files on 7.4).
			$plain  = $this->plain();
			$result = null !== $plain ? $plain->copy( $entry_name, self::file_sink( $out ) ) : null;

			if ( null !== $result && $result['done'] ) {
				fclose( $out );
				return true;
			}

			// Damaged data or a failed write: ZipArchive wouldn't do better.
			if ( ! $this->use_zip_archive || ( null !== $result && ! self::zip_archive_may_help( $result['error'] ) ) ) {
				fclose( $out );
				return false;
			}

			// An entry it can't read goes through ZipArchive, from the start.
			ftruncate( $out, 0 );
			rewind( $out );

			$zip = $this->zip();

			if ( null === $zip ) {
				fclose( $out );
				return false;
			}

			$stream = $zip->getStream( $entry_name );

			if ( false === $stream ) {
				fclose( $out );
				return false;
			}

			$ok = false !== stream_copy_to_stream( $stream, $out );
			fclose( $stream );
			fclose( $out );

			return $ok;
		}

		try {
			$phar = new \PharData( $this->path, 0, null, \Phar::TAR );

			if ( ! isset( $phar[ $entry_name ] ) ) {
				fclose( $out );
				return false;
			}

			$file       = $phar[ $entry_name ]->openFile( 'rb' );
			$chunk_size = ChunkSizeCalculator::bytes_per_chunk();

			while ( ! $file->eof() ) {
				$chunk = $file->fread( $chunk_size );

				if ( '' === $chunk ) {
					break;
				}

				fwrite( $out, $chunk );
			}

			fclose( $out );
			return true;
		} catch ( \Throwable $e ) {
			fclose( $out );
			return false;
		}
	}

	/**
	 * Unpack one entry to $tmp_path in bounded steps: call again with the
	 * returned state until it is done. A paused ZIP entry continues where it
	 * stopped (ZipFileReader::copy()), so a 2 GB table dump no longer has to
	 * be unpacked within one request, and its CRC-32 is checked at the end.
	 *
	 * @param array{position?:int,crc?:int} $state array() for a new entry.
	 * @return array{done:bool,failed:bool,position:int,crc:int}
	 */
	public function extract_step( string $entry_name, string $tmp_path, array $state, float $deadline ): array {
		$failed = array(
			'done'     => false,
			'failed'   => true,
			'position' => 0,
			'crc'      => 0,
		);

		if ( ! $this->opened && ! $this->open() ) {
			return $failed;
		}

		$position = (int) ( $state['position'] ?? 0 );
		$crc      = (int) ( $state['crc'] ?? 0 );

		// A part file shorter than the recorded progress was removed or
		// replaced in between: start the entry again.
		clearstatcache( true, $tmp_path );

		if ( $position > 0 && ( ! file_exists( $tmp_path ) || (int) filesize( $tmp_path ) < $position ) ) {
			$position = 0;
			$crc      = 0;
		}

		$plain = 'zip' === $this->container_format ? $this->plain() : null;

		if ( null === $plain && 'tar' !== $this->container_format ) {
			// Only ZipArchive can read this file (zlib is missing): in one go, as before.
			return $this->extract_to( $entry_name, $tmp_path )
				? array(
					'done'     => true,
					'failed'   => false,
					'position' => 0,
					'crc'      => 0,
				)
				: $failed;
		}

		wp_mkdir_p( dirname( $tmp_path ) );
		$out = fopen( $tmp_path, 0 === $position ? 'wb' : 'c+b' );

		if ( false === $out ) {
			return $failed;
		}

		if ( $position > 0 ) {
			// A request that died mid-write may have left bytes past the recorded position.
			ftruncate( $out, $position );
			fseek( $out, $position );
		}

		if ( null !== $plain ) {
			$result = $plain->copy( $entry_name, self::file_sink( $out ), $position, $crc, $deadline );
			fclose( $out );

			if ( '' !== $result['error'] ) {
				return $failed;
			}

			return array(
				'done'     => $result['done'],
				'failed'   => false,
				'position' => $result['position'],
				'crc'      => $result['crc'],
			);
		}

		// TAR: the entry's bytes are stored as they are, so it can seek.
		try {
			$phar = new \PharData( $this->path, 0, null, \Phar::TAR );

			if ( ! isset( $phar[ $entry_name ] ) ) {
				fclose( $out );
				return $failed;
			}

			$file = $phar[ $entry_name ]->openFile( 'rb' );
			$file->fseek( $position );

			while ( ! $file->eof() ) {
				$chunk = $file->fread( 1048576 );

				if ( false === $chunk || '' === $chunk ) {
					break;
				}

				if ( fwrite( $out, $chunk ) !== strlen( $chunk ) ) {
					fclose( $out );
					return $failed;
				}

				$position += strlen( $chunk );

				if ( microtime( true ) >= $deadline && ! $file->eof() ) {
					fclose( $out );

					return array(
						'done'     => false,
						'failed'   => false,
						'position' => $position,
						'crc'      => 0,
					);
				}
			}
		} catch ( \Throwable $e ) {
			fclose( $out );
			return $failed;
		}

		fclose( $out );

		return array(
			'done'     => true,
			'failed'   => false,
			'position' => $position,
			'crc'      => 0,
		);
	}

	/** An entry's unpacked size in bytes, or null when it isn't in the archive. */
	public function entry_size( string $entry_name ): ?int {
		if ( ! $this->opened && ! $this->open() ) {
			return null;
		}

		if ( 'zip' === $this->container_format ) {
			if ( ! $this->use_zip_archive ) {
				$plain = $this->plain();
				$stat  = null !== $plain ? $plain->stat( $entry_name ) : null;

				return null !== $stat ? $stat['size'] : null;
			}

			$zip  = $this->zip();
			$stat = null !== $zip ? $zip->statName( $entry_name ) : false;

			return false !== $stat ? (int) $stat['size'] : null;
		}

		try {
			$phar = new \PharData( $this->path, 0, null, \Phar::TAR );

			return isset( $phar[ $entry_name ] ) ? (int) $phar[ $entry_name ]->getSize() : null;
		} catch ( \Throwable $e ) {
			return null;
		}
	}

	/**
	 * Whether ZipArchive may read an entry the built-in reader couldn't: a
	 * compression method or encryption it doesn't support, or a name it
	 * didn't find. Not for damaged data or a failed write.
	 */
	private static function zip_archive_may_help( string $error ): bool {
		return in_array( $error, array( ZipFileReader::ERROR_METHOD, ZipFileReader::ERROR_ENCRYPTED, ZipFileReader::ERROR_MISSING, ZipFileReader::ERROR_UNOPENED ), true );
	}

	/**
	 * A ZipFileReader sink that appends to an open file.
	 *
	 * @param resource $out
	 * @return callable(string):bool
	 */
	private static function file_sink( $out ): callable {
		return static function ( string $bytes ) use ( $out ): bool {
			return fwrite( $out, $bytes ) === strlen( $bytes );
		};
	}

	/**
	 * Stream-hash a single archive entry's content, without ever loading the
	 * whole entry into memory — needed since data files can be as large as
	 * the table they were exported from. Returns null if the entry doesn't
	 * exist in the archive.
	 *
	 * @param string $like A recorded checksum: the entry is hashed the same
	 *                     way (its SHA-256, or a sliced checksum, see
	 *                     SliceHash). '' for its SHA-256.
	 */
	public function checksum_entry( string $entry_name, string $like = '' ): ?string {
		if ( ! $this->opened && ! $this->open() ) {
			return null;
		}

		$format = '' !== $like ? SliceHash::parse( $like ) : null;
		$slices = null !== $format && $format['sliced'] ? new SliceHash( $format['slice'] ) : null;
		$ctx    = null === $slices ? hash_init( 'sha256' ) : null;

		$chunk_size = ChunkSizeCalculator::bytes_per_chunk();

		if ( 'zip' === $this->container_format ) {
			$plain = $this->plain();

			if ( null !== $plain && null !== $plain->stat( $entry_name ) ) {
				$result = $plain->copy(
					$entry_name,
					static function ( string $bytes ) use ( $ctx, $slices ): bool {
						self::hash_chunk( $ctx, $slices, $bytes );
						return true;
					}
				);

				// A damaged entry stops early: its checksum then doesn't match.
				if ( $result['done'] || ! $this->use_zip_archive || ! self::zip_archive_may_help( $result['error'] ) ) {
					return null !== $slices ? $slices->result() : hash_final( $ctx );
				}

				// Anything else it can't read: ZipArchive, with fresh hashes.
				$slices = null !== $format && $format['sliced'] ? new SliceHash( $format['slice'] ) : null;
				$ctx    = null === $slices ? hash_init( 'sha256' ) : null;
			} elseif ( ! $this->use_zip_archive ) {
				return null;
			}

			$zip = $this->zip();

			if ( null === $zip ) {
				return null;
			}

			$stream = $zip->getStream( $entry_name );

			if ( false === $stream ) {
				return null;
			}

			while ( ! feof( $stream ) ) {
				$chunk = fread( $stream, $chunk_size );
				if ( false === $chunk || '' === $chunk ) {
					break;
				}
				self::hash_chunk( $ctx, $slices, $chunk );
			}
			fclose( $stream );

			return null !== $slices ? $slices->result() : hash_final( $ctx );
		}

		try {
			$phar = new \PharData( $this->path, 0, null, \Phar::TAR );

			if ( ! isset( $phar[ $entry_name ] ) ) {
				return null;
			}

			$file = $phar[ $entry_name ]->openFile( 'rb' );

			while ( ! $file->eof() ) {
				$chunk = $file->fread( $chunk_size );
				if ( '' === $chunk ) {
					break;
				}
				self::hash_chunk( $ctx, $slices, $chunk );
			}

			return null !== $slices ? $slices->result() : hash_final( $ctx );
		} catch ( \Throwable $e ) {
			return null;
		}
	}

	/**
	 * checksum_entry() in steps: hashes until $deadline and hands back the
	 * progress, so one very large entry (a 2 GB table dump) no longer has to
	 * be hashed within one request. A ZIP entry continues where it stopped
	 * (ZipFileReader::copy()): a classic SHA-256 anywhere on PHP 8.1+ (its
	 * state is saved), a sliced checksum at a slice boundary on any PHP.
	 * Otherwise (a classic SHA-256 before PHP 8.1, a TAR archive, no
	 * deadline) the entry is hashed in one go, as checksum_entry() does.
	 *
	 * @param array<string,mixed> $state array() for a new entry.
	 * @return array{done:bool,checksum:?string,state:array<string,mixed>}
	 */
	public function checksum_entry_step( string $entry_name, string $like, array $state, ?float $deadline ): array {
		$format = '' !== $like ? SliceHash::parse( $like ) : null;
		$sliced = null !== $format && ! empty( $format['sliced'] );
		$plain  = null;

		if ( null !== $deadline && ( $sliced || SliceHash::can_save_hash_state() ) && ( $this->opened || $this->open() ) && 'zip' === $this->container_format ) {
			$plain = $this->plain();
		}

		if ( null === $plain ) {
			return array(
				'done'     => true,
				'checksum' => $this->checksum_entry( $entry_name, $like ),
				'state'    => array(),
			);
		}

		if ( null === $plain->stat( $entry_name ) ) {
			return array(
				'done'     => true,
				'checksum' => null,
				'state'    => array(),
			);
		}

		$position = max( 0, (int) ( $state['position'] ?? 0 ) );
		$slices   = null;
		$whole    = null;

		try {
			if ( $sliced ) {
				$slices = $position > 0 ? SliceHash::resume( (array) ( $state['slices'] ?? array() ) ) : new SliceHash( (int) $format['slice'] );
			} else {
				$whole = $position > 0 ? ArchiveChecksum::restore_context( (string) ( $state['context'] ?? '' ) ) : hash_init( 'sha256' );
			}
		} catch ( \RuntimeException $e ) {
			$position = -1; // Unreadable saved progress: start the entry again below.
		}

		if ( $position < 0 || ( null !== $slices && $slices->length() !== $position ) ) {
			$position = 0;
			$slices   = $sliced ? new SliceHash( (int) $format['slice'] ) : null;
			$whole    = $sliced ? null : hash_init( 'sha256' );
		}

		$slice_bytes = $sliced ? (int) $format['slice'] : 0;
		$hashed      = $position;
		$paused      = false;
		$pause_at    = null;

		$sink = static function ( string $bytes ) use ( $whole, $slices, $slice_bytes, $deadline, &$hashed, &$paused, &$pause_at ): bool {
			// New work starts once a resumed entry has caught up (see ZipFileReader::MIN_RUN_SECONDS).
			if ( null === $pause_at ) {
				$pause_at = max( (float) $deadline, microtime( true ) + ZipFileReader::MIN_RUN_SECONDS );
			}

			if ( null !== $whole ) {
				hash_update( $whole, $bytes );
				$hashed += strlen( $bytes );
				$paused  = microtime( true ) >= $pause_at;

				return ! $paused;
			}

			// A sliced checksum can only stop on a slice boundary.
			$offset = 0;
			$total  = strlen( $bytes );

			while ( $offset < $total ) {
				$take = (int) min( $slice_bytes - ( $slices->length() % $slice_bytes ), $total - $offset );
				$slices->update( substr( $bytes, $offset, $take ) );
				$offset += $take;
				$hashed += $take;

				if ( $slices->at_boundary() && microtime( true ) >= $pause_at ) {
					$paused = true;
					return false;
				}
			}

			return true;
		};

		$plain->copy( $entry_name, $sink, $position, -1 );

		if ( $paused ) {
			return array(
				'done'     => false,
				'checksum' => null,
				'state'    => null !== $whole
					? array(
						'position' => $hashed,
						'context'  => ArchiveChecksum::save_context( $whole ),
					)
					: array(
						'position' => $hashed,
						'slices'   => $slices->state(),
					),
			);
		}

		// Finished, or stopped by damaged data: the checksum decides either way.
		return array(
			'done'     => true,
			'checksum' => null !== $whole ? hash_final( $whole ) : $slices->result(),
			'state'    => array(),
		);
	}

	/** @param \HashContext|null $ctx */
	private static function hash_chunk( $ctx, ?SliceHash $slices, string $chunk ): void {
		if ( null !== $slices ) {
			$slices->update( $chunk );
		} else {
			hash_update( $ctx, $chunk );
		}
	}

	/**
	 * List every entry name under "files/", used by the Restore Engine to
	 * walk the archive's file payload without extracting everything at once.
	 *
	 * @return string[]
	 */
	public function list_file_entries(): array {
		if ( ! $this->opened && ! $this->open() ) {
			return array();
		}

		$entries = array();

		if ( 'zip' === $this->container_format ) {
			if ( ! $this->use_zip_archive ) {
				$plain = $this->plain();

				foreach ( null !== $plain ? $plain->names() : array() as $name ) {
					if ( Str::starts_with( $name, 'files/' ) ) {
						$entries[] = $name;
					}
				}

				return $entries;
			}

			$zip = $this->zip();

			if ( null === $zip ) {
				return array();
			}

			for ( $i = 0; $i < $zip->numFiles; $i++ ) { // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- ZipArchive property.
				$name = $zip->getNameIndex( $i );
				if ( Str::starts_with( $name, 'files/' ) ) {
					$entries[] = $name;
				}
			}

			return $entries;
		}

		try {
			$phar = new \PharData( $this->path, 0, null, \Phar::TAR );

			// Each item's path is "phar://{archive}/{entry}": keep the entry
			// name. (Before 1.1.6 the "phar://" stayed in, so a restore from a
			// TAR backup couldn't find any of its files.)
			$root = 'phar://' . wp_normalize_path( $phar->getPath() ) . '/';

			foreach ( new \RecursiveIteratorIterator( $phar ) as $file ) {
				$path = wp_normalize_path( $file->getPathname() );
				$name = Str::starts_with( $path, $root ) ? substr( $path, strlen( $root ) ) : '';
				if ( Str::starts_with( $name, 'files/' ) ) {
					$entries[] = $name;
				}
			}
		} catch ( \Throwable $e ) {
			return array();
		}

		return $entries;
	}

	/**
	 * Summarize what a restore from this archive would change, for the
	 * Visual Restore Preview shown before the user confirms.
	 *
	 * @return array{tables:array,plugins:string[],themes:string[],uploads_count:int}
	 */
	public function get_change_summary(): array {
		$tables   = $this->read_json( 'database/manifest.json' ) ?? array();
		$metadata = $this->read_json( 'metadata.json' ) ?? array();
		$entries  = $this->list_file_entries();

		$prefixes = array();
		foreach ( array( 'plugins', 'themes', 'uploads' ) as $section ) {
			$prefixes[ $section ] = self::section_prefixes( $section, $metadata );
		}

		$plugins       = array();
		$themes        = array();
		$uploads_count = 0;
		$files_count   = 0;

		foreach ( $entries as $entry ) {
			if ( ! Str::starts_with( $entry, 'files/' ) ) {
				continue;
			}

			++$files_count;

			foreach ( $prefixes['plugins'] as $prefix ) {
				if ( Str::starts_with( $entry, $prefix ) ) {
					$slug = self::section_item( substr( $entry, strlen( $prefix ) ) );
					if ( '' !== $slug ) {
						$plugins[ $slug ] = true;
					}
					continue 2;
				}
			}

			foreach ( $prefixes['themes'] as $prefix ) {
				if ( Str::starts_with( $entry, $prefix ) ) {
					$rest = substr( $entry, strlen( $prefix ) );
					if ( Str::contains( $rest, '/' ) ) {
						$themes[ strtok( $rest, '/' ) ] = true;
					}
					continue 2;
				}
			}

			foreach ( $prefixes['uploads'] as $prefix ) {
				if ( Str::starts_with( $entry, $prefix ) ) {
					++$uploads_count;
					continue 2;
				}
			}
		}

		ksort( $plugins );
		ksort( $themes );

		return array(
			'tables'        => $tables,
			'plugins'       => array_keys( $plugins ),
			'themes'        => array_keys( $themes ),
			'uploads_count' => $uploads_count,
			'files_count'   => $files_count,
		);
	}

	/**
	 * The plugin a path inside the plugins folder belongs to: its folder
	 * name, or the file name of a single-file plugin ("hello.php").
	 */
	private static function section_item( string $rest ): string {
		if ( Str::contains( $rest, '/' ) ) {
			return (string) strtok( $rest, '/' );
		}

		return ( Str::ends_with( $rest, '.php' ) && 'index.php' !== $rest ) ? $rest : '';
	}

	/**
	 * Archive entry prefixes under which a section (plugins, themes,
	 * uploads) can live. Type-specific backups store it at the top
	 * ("files/plugins/…"); full and files-only backups store the whole
	 * site under "files/root/…", so the section sits at its path relative
	 * to the source site's WordPress folder ("files/root/wp-content/plugins/…"),
	 * or, when the source kept it outside that folder, at "files/{section}/".
	 *
	 * @param array<string,mixed> $metadata metadata.json of the archive.
	 * @return string[] Prefixes, each ending in "/".
	 */
	public static function section_prefixes( string $section, array $metadata ): array {
		$abspath     = rtrim( str_replace( '\\', '/', (string) ( $metadata['abspath'] ?? '' ) ), '/' );
		$content_dir = rtrim( str_replace( '\\', '/', (string) ( $metadata['content_dir'] ?? '' ) ), '/' );
		$uploads_dir = rtrim( str_replace( '\\', '/', (string) ( $metadata['uploads_basedir'] ?? '' ) ), '/' );

		$relative = static function ( string $path ) use ( $abspath ): string {
			if ( '' !== $abspath && '' !== $path && Str::starts_with( $path, $abspath . '/' ) ) {
				return substr( $path, strlen( $abspath ) + 1 );
			}

			return '';
		};

		switch ( $section ) {
			case 'uploads':
				$section_rel = $relative( $uploads_dir );
				break;

			case 'plugins':
			case 'themes':
				// Recorded since 1.0.1. Older archives only carry the content
				// folder, and their plugins/themes sit inside it.
				$section_dir = rtrim( str_replace( '\\', '/', (string) ( $metadata[ $section . '_dir' ] ?? '' ) ), '/' );
				$content_rel = $relative( $content_dir );
				$section_rel = '' !== $section_dir ? $relative( $section_dir ) : ( '' !== $content_rel ? $content_rel . '/' . $section : '' );
				break;

			default:
				return array( 'files/' . $section . '/' );
		}

		$prefixes = array( 'files/' . $section . '/' );

		if ( '' !== $section_rel ) {
			$prefixes[] = 'files/root/' . $section_rel . '/';
		}

		return $prefixes;
	}

	/** Why the file can't be read, in words the person restoring can act on. */
	private function unreadable_message(): string {
		switch ( $this->plain_error ) {
			case ZipFileReader::ERROR_NO_END:
			case ZipFileReader::ERROR_INDEX:
				return __( 'This backup file is incomplete or damaged: the index at the end of the file is missing or unreadable. Download or upload the backup again.', 'wrensafe' );

			case ZipFileReader::ERROR_UNOPENED:
				return __( 'PHP couldn’t open this file. Check the file’s permissions, and whether security software is blocking it.', 'wrensafe' );

			case ZipFileReader::ERROR_NO_ZLIB:
				return __( 'This server’s PHP can’t read ZIP files: both its zip and zlib extensions are turned off. Turn on the zip extension in php.ini (or ask your host to), then try again.', 'wrensafe' );
		}

		return __( 'This file is not a readable archive.', 'wrensafe' );
	}

	/**
	 * Validate that this is a genuine, supported Wrensafe archive.
	 *
	 * @return array{valid:bool,message:string,manifest:array,metadata:array}
	 */
	public function validate(): array {
		if ( ! $this->open() ) {
			if ( $this->encrypted_without_password ) {
				$message = __( 'This backup is encrypted. Please provide its password.', 'wrensafe' );
			} elseif ( '' !== $this->decryption_unavailable ) {
				$message = $this->decryption_unavailable;
			} elseif ( $this->decryption_failed ) {
				$message = __( 'Incorrect password, or the backup file is corrupted.', 'wrensafe' );
			} else {
				$message = $this->unreadable_message();
			}

			return array(
				'valid'             => false,
				'message'           => $message,
				'manifest'          => array(),
				'metadata'          => array(),
				'requires_password' => $this->encrypted_without_password,
			);
		}

		$manifest = $this->read_json( 'manifest.json' );

		if ( null === $manifest || empty( $manifest['format_version'] ) ) {
			return array(
				'valid'    => false,
				'message'  => __( 'This archive is missing its Wrensafe manifest and cannot be trusted.', 'wrensafe' ),
				'manifest' => array(),
				'metadata' => array(),
			);
		}

		if ( version_compare( (string) $manifest['format_version'], ManifestBuilder::FORMAT_VERSION, '>' ) ) {
			return array(
				'valid'    => false,
				'message'  => __( 'This archive was created by a newer version of Wrensafe. Please update the plugin.', 'wrensafe' ),
				'manifest' => $manifest,
				'metadata' => array(),
			);
		}

		$metadata = $this->read_json( 'metadata.json' ) ?? array();

		return array(
			'valid'    => true,
			'message'  => '',
			'manifest' => $manifest,
			'metadata' => $metadata,
		);
	}
}

// phpcs:enable WordPress.WP.AlternativeFunctions
