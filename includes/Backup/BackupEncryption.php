<?php
/**
 * Streaming, authenticated AES-256 encryption for .wbp backups.
 *
 * The password is never written to the database — callers pass it in
 * memory for the single request that needs it (encrypting at archive time,
 * or decrypting at restore time) and it is discarded immediately after.
 * Losing the password means the backup cannot be recovered; there is no
 * way around it by design.
 *
 * The archive is processed in fixed-size chunks rather than loaded whole,
 * so encrypting/decrypting a multi-gigabyte backup never approaches PHP's
 * memory_limit. Each chunk is independently authenticated (AES-256-GCM tag
 * per chunk, or — when GCM isn't available — a single streaming
 * HMAC-SHA256 over the whole ciphertext, verified in a dedicated pass
 * before any plaintext is written out). A wrong password or any tampering
 * is therefore caught before restore ever sees the (would-be) plaintext.
 *
 * File format (v2, "WPBPENC2"):
 *   header:  magic(8) | version(1) | cipher_id(1) | iterations(4, uint32BE) | salt(16)
 *   body:    one of —
 *     GCM chunks:      [len(4,uint32BE)][nonce(12)][ciphertext(len)][tag(16)] ...
 *     CBC+HMAC chunks: [len(4,uint32BE)][iv(16)][ciphertext(len)] ... , followed by a
 *                      trailing hmac(32) over the whole body (header excluded).
 * Each chunk carries its own iv/nonce rather than one global value, since a
 * single IV/nonce can never safely be reused across more than one chunk
 * encrypted under the same key.
 *
 * Archives written before this format existed (magic "WPBPENC1", whole-file
 * AES-256-CBC, unauthenticated) are still readable via decrypt_file() for
 * backward compatibility, but are never written going forward.
 *
 * @package WPBP\Backup
 */

namespace WPBP\Backup;

use WPBP\Utils\ChunkSizeCalculator;

defined( 'ABSPATH' ) || exit;

// Backup archives and upload chunks are streamed in bounded blocks with native
// file handles (fopen/fread/fwrite/rename): WP_Filesystem has no streaming API.
// Every path is inside Wrensafe's protected storage directory, a PHP upload
// temp file, or validated by WPBP\Security\PathValidator.
// phpcs:disable WordPress.WP.AlternativeFunctions

// Exception messages are stored as a job's error_message and logged; they
// reach the browser only as JSON rendered with jQuery .text(), never as HTML.
// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped

class BackupEncryption {

	private const MAGIC_V1 = 'WPBPENC1';
	private const MAGIC_V2 = 'WPBPENC2';

	private const CIPHER_GCM      = 1;
	private const CIPHER_CBC_HMAC = 2;

	private const SALT_LENGTH      = 16;
	private const ITERATIONS       = 100000;
	private const GCM_CIPHER       = 'aes-256-gcm';
	private const GCM_NONCE_LENGTH = 12;
	private const GCM_TAG_LENGTH   = 16;
	private const CBC_CIPHER       = 'aes-256-cbc';
	private const CBC_IV_LENGTH    = 16;
	private const HMAC_LENGTH      = 32;

	private const HEADER_LENGTH = 8 + 1 + 1 + 4 + self::SALT_LENGTH;

	/**
	 * Whether a file is one of our encrypted archives (either format version).
	 */
	public function is_encrypted( string $file_path ): bool {
		$handle = fopen( $file_path, 'rb' );

		if ( false === $handle ) {
			return false;
		}

		$header = fread( $handle, 8 );
		fclose( $handle );

		return self::MAGIC_V1 === $header || self::MAGIC_V2 === $header;
	}

	private function derive_key( string $password, string $salt, int $iterations ): string {
		return hash_pbkdf2( 'sha256', $password, $salt, $iterations, 32, true );
	}

	/**
	 * Derive an independent MAC key from the encryption key (a minimal
	 * HKDF-Expand step), so the same key material is never used for both
	 * confidentiality and authentication.
	 */
	private function derive_mac_key( string $key ): string {
		return hash_hmac( 'sha256', 'wpbp-backup-pilot-hmac-key', $key, true );
	}

	private function gcm_available(): bool {
		return in_array( self::GCM_CIPHER, openssl_get_cipher_methods(), true );
	}

	/**
	 * Encrypt a file in place: streams $file_path in chunks to a temp file,
	 * then renames the temp file over the original only once the whole
	 * archive has encrypted successfully.
	 */
	public function encrypt_file_in_place( string $file_path, string $password ): bool {
		$tmp_path = $file_path . '.encrypting.tmp';
		$ok       = $this->encrypt_stream( $file_path, $tmp_path, $password );

		if ( ! $ok ) {
			if ( file_exists( $tmp_path ) ) {
				wp_delete_file( $tmp_path );
			}
			return false;
		}

		return rename( $tmp_path, $file_path );
	}

	/**
	 * Resumable encryption for large archives: encrypts $source_path into
	 * $tmp_path until $deadline, then returns false with its cursor in
	 * $state so the next request continues. Returns true once the whole
	 * file is encrypted (the caller renames $tmp_path into place).
	 *
	 * AES-GCM chunks are independent records, so stopping between two
	 * records needs no cipher state. When GCM is unavailable the CBC+HMAC
	 * format (one HMAC over the whole body) is written in a single request.
	 *
	 * @param array<string,mixed> $state Cursor; pass an empty array on the first call.
	 * @throws \RuntimeException When the files can't be read/written or encryption fails.
	 */
	public function encrypt_step( string $source_path, string $tmp_path, string $password, array &$state, float $deadline ): bool {
		if ( ! $this->gcm_available() ) {
			if ( ! $this->encrypt_stream( $source_path, $tmp_path, $password ) ) {
				throw new \RuntimeException( __( 'Backup encryption failed.', 'wrensafe' ) );
			}

			return true;
		}

		if ( empty( $state ) ) {
			$salt = random_bytes( self::SALT_LENGTH );
			$out  = fopen( $tmp_path, 'wb' );

			if ( false === $out ) {
				throw new \RuntimeException( __( 'Backup encryption failed: the encrypted file could not be created.', 'wrensafe' ) );
			}

			fwrite( $out, self::MAGIC_V2 . pack( 'C', 2 ) . pack( 'C', self::CIPHER_GCM ) . pack( 'N', self::ITERATIONS ) . $salt );
			fclose( $out );

			$state = array(
				'in'   => 0,
				'out'  => self::HEADER_LENGTH,
				'salt' => bin2hex( $salt ),
			);
		}

		$key        = $this->derive_key( $password, (string) hex2bin( (string) $state['salt'] ), self::ITERATIONS );
		$chunk_size = ChunkSizeCalculator::bytes_per_chunk();
		$in         = fopen( $source_path, 'rb' );
		$out        = fopen( $tmp_path, 'c+b' );

		if ( false === $in || false === $out ) {
			throw new \RuntimeException( __( 'Backup encryption failed: the archive could not be opened.', 'wrensafe' ) );
		}

		fseek( $in, (int) $state['in'] );
		// Cut off anything a killed request wrote past the last saved record.
		ftruncate( $out, (int) $state['out'] );
		fseek( $out, (int) $state['out'] );

		$done = false;

		while ( true ) {
			$plain = fread( $in, $chunk_size );

			if ( false === $plain ) {
				fclose( $in );
				fclose( $out );
				throw new \RuntimeException( __( 'Backup encryption failed: a read error occurred.', 'wrensafe' ) );
			}

			if ( '' === $plain ) {
				$done = true;
				break;
			}

			$nonce  = random_bytes( self::GCM_NONCE_LENGTH );
			$tag    = '';
			$cipher = openssl_encrypt( $plain, self::GCM_CIPHER, $key, OPENSSL_RAW_DATA, $nonce, $tag, '', self::GCM_TAG_LENGTH );

			if ( false === $cipher ) {
				fclose( $in );
				fclose( $out );
				throw new \RuntimeException( __( 'Backup encryption failed.', 'wrensafe' ) );
			}

			$record = pack( 'N', strlen( $cipher ) ) . $nonce . $cipher . $tag;

			if ( fwrite( $out, $record ) !== strlen( $record ) ) {
				fclose( $in );
				fclose( $out );
				throw new \RuntimeException( __( 'Backup encryption failed: the disk may be full.', 'wrensafe' ) );
			}

			$state['in']  = (int) $state['in'] + strlen( $plain );
			$state['out'] = (int) $state['out'] + strlen( $record );

			if ( microtime( true ) >= $deadline ) {
				break;
			}
		}

		fclose( $in );
		fflush( $out );
		fclose( $out );

		return $done;
	}

	/**
	 * Resumable decryption, the counterpart of encrypt_step(): decrypts into
	 * $tmp_path until $deadline. Returns true when finished, false when the
	 * next request must continue. Every GCM record is authenticated before
	 * its plaintext is written. Legacy v1 and CBC+HMAC archives are
	 * decrypted in a single request (their format can't be resumed).
	 *
	 * @param array<string,mixed> $state Cursor; pass an empty array on the first call.
	 * @throws \RuntimeException On a wrong password or a damaged file.
	 */
	public function decrypt_step( string $source_path, string $password, string $tmp_path, array &$state, float $deadline ): bool {
		$wrong = __( 'Incorrect password, or the backup file is corrupted.', 'wrensafe' );

		if ( empty( $state ) ) {
			$handle = fopen( $source_path, 'rb' );

			if ( false === $handle ) {
				throw new \RuntimeException( __( 'The encrypted backup could not be opened.', 'wrensafe' ) );
			}

			$magic      = (string) fread( $handle, 8 );
			$version    = unpack( 'C', (string) fread( $handle, 1 ) )[1] ?? 0;
			$cipher_id  = unpack( 'C', (string) fread( $handle, 1 ) )[1] ?? 0;
			$iterations = unpack( 'N', (string) fread( $handle, 4 ) )[1] ?? 0;
			$salt       = (string) fread( $handle, self::SALT_LENGTH );
			fclose( $handle );

			if ( self::MAGIC_V2 !== $magic || 2 !== $version || self::CIPHER_GCM !== $cipher_id || self::SALT_LENGTH !== strlen( $salt ) ) {
				// v1, CBC+HMAC, or unknown: one-shot path.
				if ( ! $this->decrypt_file( $source_path, $password, $tmp_path ) ) {
					throw new \RuntimeException( $wrong );
				}

				return true;
			}

			$state = array(
				'in'         => self::HEADER_LENGTH,
				'out'        => 0,
				'salt'       => bin2hex( $salt ),
				'iterations' => (int) $iterations,
			);

			$out = fopen( $tmp_path, 'wb' );

			if ( false === $out ) {
				throw new \RuntimeException( __( 'The decrypted copy could not be created (check the storage folder permissions).', 'wrensafe' ) );
			}

			fclose( $out );
		}

		$key = $this->derive_key( $password, (string) hex2bin( (string) $state['salt'] ), (int) $state['iterations'] );
		$in  = fopen( $source_path, 'rb' );
		$out = fopen( $tmp_path, 'c+b' );

		if ( false === $in || false === $out ) {
			throw new \RuntimeException( __( 'The encrypted backup could not be opened.', 'wrensafe' ) );
		}

		fseek( $in, (int) $state['in'] );
		ftruncate( $out, (int) $state['out'] );
		fseek( $out, (int) $state['out'] );

		$done = false;

		while ( true ) {
			$len_raw = fread( $in, 4 );

			if ( '' === $len_raw || false === $len_raw ) {
				$done = true;
				break;
			}

			$len        = 4 === strlen( $len_raw ) ? unpack( 'N', $len_raw )[1] : -1;
			$nonce      = $len >= 0 ? (string) fread( $in, self::GCM_NONCE_LENGTH ) : '';
			$ciphertext = $len > 0 ? (string) fread( $in, $len ) : '';
			$tag        = $len >= 0 ? (string) fread( $in, self::GCM_TAG_LENGTH ) : '';
			$plain      = ( $len >= 0 && self::GCM_NONCE_LENGTH === strlen( $nonce ) && strlen( $ciphertext ) === $len && self::GCM_TAG_LENGTH === strlen( $tag ) )
				? openssl_decrypt( $ciphertext, self::GCM_CIPHER, $key, OPENSSL_RAW_DATA, $nonce, $tag )
				: false;

			if ( false === $plain ) {
				fclose( $in );
				fclose( $out );
				throw new \RuntimeException( $wrong );
			}

			fwrite( $out, $plain );

			$state['in']  = (int) $state['in'] + 4 + self::GCM_NONCE_LENGTH + $len + self::GCM_TAG_LENGTH;
			$state['out'] = (int) $state['out'] + strlen( $plain );

			if ( microtime( true ) >= $deadline ) {
				break;
			}
		}

		fclose( $in );
		fflush( $out );
		fclose( $out );

		return $done;
	}

	/**
	 * Decrypt only what an archive PREVIEW needs: the ZIP central directory
	 * and the small JSON metadata entries, which all sit at the end of the
	 * archive. They are written at their real offsets into a sparse file
	 * the size of the full plaintext, so ZipArchive can open it, list every
	 * entry and read the metadata — while a 2-3 GB backup costs a few MB of
	 * work instead of a full decrypt in one request. File contents in the
	 * undecrypted region read back as zeros, so this copy is ONLY for
	 * previews; restores decrypt the whole archive (decrypt_step()).
	 *
	 * Falls back to a full decrypt for formats that can't be read from the
	 * end (v1, CBC+HMAC) and for small archives.
	 */
	public function decrypt_tail_for_preview( string $source_path, string $password, string $destination_path ): bool {
		$size = (int) @filesize( $source_path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		$in   = fopen( $source_path, 'rb' );

		if ( false === $in ) {
			return false;
		}

		$magic      = (string) fread( $in, 8 );
		$version    = unpack( 'C', (string) fread( $in, 1 ) )[1] ?? 0;
		$cipher_id  = unpack( 'C', (string) fread( $in, 1 ) )[1] ?? 0;
		$iterations = unpack( 'N', (string) fread( $in, 4 ) )[1] ?? 0;
		$salt       = (string) fread( $in, self::SALT_LENGTH );
		$first_len  = unpack( 'N', (string) fread( $in, 4 ) . "\0\0\0\0" )[1] ?? 0;

		if ( self::MAGIC_V2 !== $magic || 2 !== $version || self::CIPHER_GCM !== $cipher_id || self::SALT_LENGTH !== strlen( $salt ) || $first_len <= 0 || $size < 134217728 ) {
			fclose( $in );
			return $this->decrypt_file( $source_path, $password, $destination_path );
		}

		$record   = 4 + self::GCM_NONCE_LENGTH + $first_len + self::GCM_TAG_LENGTH;
		$body     = $size - self::HEADER_LENGTH;
		$full     = intdiv( $body, $record );
		$rest     = $body % $record;
		$overhead = 4 + self::GCM_NONCE_LENGTH + self::GCM_TAG_LENGTH;

		if ( 0 !== $rest && $rest <= $overhead ) {
			fclose( $in );
			return false; // Truncated or damaged.
		}

		$count       = $full + ( 0 !== $rest ? 1 : 0 );
		$plain_total = $full * $first_len + ( 0 !== $rest ? $rest - $overhead : 0 );
		$key         = $this->derive_key( $password, $salt, (int) $iterations );
		$out         = fopen( $destination_path, 'w+b' );

		if ( false === $out ) {
			fclose( $in );
			return false;
		}

		ftruncate( $out, $plain_total ); // Sparse: unwritten regions cost no disk.

		$decrypted_from = $count;
		$decrypt_range  = function ( int $from_record ) use ( $in, $out, $key, $record, $first_len, &$decrypted_from ): bool {
			for ( $i = max( 0, $from_record ); $i < $decrypted_from; $i++ ) {
				if ( ! $this->decrypt_record_into( $in, $out, $key, $i, $record, $first_len ) ) {
					return false; // Wrong password or a tampered record.
				}
			}

			$decrypted_from = min( $decrypted_from, max( 0, $from_record ) );

			return true;
		};

		// 0) The first record: readers recognise the format by its leading
		// bytes ("PK\x03\x04"), which would otherwise be zeros.
		$ok = $this->decrypt_record_into( $in, $out, $key, 0, $record, $first_len );

		// 1) The last records: end of central directory (+ ZIP64 records).
		$ok = $ok && $decrypt_range( $count - (int) max( 1, ceil( 1048576 / $first_len ) ) );

		// 2) Back to the start of the central directory, then to the lowest
		// offset of any metadata entry (everything outside files/ and the
		// per-table SQL dumps).
		for ( $pass = 0; $ok && $pass < 2; $pass++ ) {
			$needed = 0 === $pass ? $this->zip_central_directory_offset( $out, $plain_total ) : $this->zip_metadata_offset( $out, $plain_total );

			if ( null === $needed ) {
				$ok = false;
				break;
			}

			$ok = $decrypt_range( intdiv( $needed, $first_len ) );
		}

		fclose( $in );
		fflush( $out );
		fclose( $out );

		if ( ! $ok ) {
			wp_delete_file( $destination_path );
		}

		return $ok;
	}

	/**
	 * Decrypt GCM record $index of an encrypted archive into its plaintext
	 * position in $out (used for the preview's leading record).
	 *
	 * @param resource $in
	 * @param resource $out
	 */
	private function decrypt_record_into( $in, $out, string $key, int $index, int $record, int $first_len ): bool {
		fseek( $in, self::HEADER_LENGTH + $index * $record );

		$len_raw = (string) fread( $in, 4 );
		$len     = 4 === strlen( $len_raw ) ? unpack( 'N', $len_raw )[1] : -1;

		if ( $len <= 0 || $len > $first_len ) {
			return false;
		}

		$nonce      = (string) fread( $in, self::GCM_NONCE_LENGTH );
		$ciphertext = (string) fread( $in, $len );
		$tag        = (string) fread( $in, self::GCM_TAG_LENGTH );
		$plain      = openssl_decrypt( $ciphertext, self::GCM_CIPHER, $key, OPENSSL_RAW_DATA, $nonce, $tag );

		if ( false === $plain ) {
			return false;
		}

		fseek( $out, $index * $first_len );

		return strlen( $plain ) === fwrite( $out, $plain );
	}

	/**
	 * Locate the ZIP end-of-central-directory record in a (partially
	 * decrypted) plaintext file and return the central directory offset.
	 *
	 * @param resource $handle
	 * @return array{0:int,1:int}|null Central directory offset and size.
	 */
	private function zip_central_directory( $handle, int $total ): ?array {
		$tail_length = (int) min( $total, 65557 );
		fseek( $handle, $total - $tail_length );
		$tail = (string) fread( $handle, $tail_length );
		$pos  = strrpos( $tail, pack( 'V', 0x06054b50 ) );

		if ( false === $pos || strlen( $tail ) < $pos + 22 ) {
			return null;
		}

		$cd_size   = unpack( 'V', substr( $tail, $pos + 12, 4 ) )[1];
		$cd_offset = unpack( 'V', substr( $tail, $pos + 16, 4 ) )[1];

		if ( 0xFFFFFFFF === $cd_offset || 0xFFFFFFFF === $cd_size ) {
			$locator = $pos - 20;

			if ( $locator < 0 || pack( 'V', 0x07064b50 ) !== substr( $tail, $locator, 4 ) ) {
				return null;
			}

			$eocd64 = unpack( 'P', substr( $tail, $locator + 8, 8 ) )[1];
			fseek( $handle, $eocd64 );
			$record = (string) fread( $handle, 56 );

			if ( 56 !== strlen( $record ) || pack( 'V', 0x06064b50 ) !== substr( $record, 0, 4 ) ) {
				return null;
			}

			$cd_size   = unpack( 'P', substr( $record, 40, 8 ) )[1];
			$cd_offset = unpack( 'P', substr( $record, 48, 8 ) )[1];
		}

		return array( (int) $cd_offset, (int) $cd_size );
	}

	/** @param resource $handle */
	private function zip_central_directory_offset( $handle, int $total ): ?int {
		$cd = $this->zip_central_directory( $handle, $total );

		return null === $cd ? null : $cd[0];
	}

	/**
	 * Lowest local-header offset of the metadata entries (manifest.json,
	 * metadata.json, checksums.json, database/manifest.json, …).
	 *
	 * @param resource $handle
	 */
	private function zip_metadata_offset( $handle, int $total ): ?int {
		$cd = $this->zip_central_directory( $handle, $total );

		if ( null === $cd ) {
			return null;
		}

		list( $offset, $length ) = $cd;
		fseek( $handle, $offset );
		$directory = (string) fread( $handle, $length );
		$lowest    = $offset;
		$pos       = 0;

		$directory_length = strlen( $directory );

		while ( $pos + 46 <= $directory_length && pack( 'V', 0x02014b50 ) === substr( $directory, $pos, 4 ) ) {
			$name_length  = unpack( 'v', substr( $directory, $pos + 28, 2 ) )[1];
			$extra_length = unpack( 'v', substr( $directory, $pos + 30, 2 ) )[1];
			$comment      = unpack( 'v', substr( $directory, $pos + 32, 2 ) )[1];
			$local        = unpack( 'V', substr( $directory, $pos + 42, 4 ) )[1];
			$name         = substr( $directory, $pos + 46, $name_length );

			if ( 0xFFFFFFFF === $local ) {
				$local = $this->zip64_extra_offset( substr( $directory, $pos + 46 + $name_length, $extra_length ), substr( $directory, $pos, 46 ) );
			}

			if ( ! str_starts_with( $name, 'files/' ) && ! str_starts_with( $name, 'database/schema/' ) && ! str_starts_with( $name, 'database/data/' ) && null !== $local ) {
				$lowest = min( $lowest, (int) $local );
			}

			$pos += 46 + $name_length + $extra_length + $comment;
		}

		return $lowest;
	}

	/**
	 * The local header offset from a ZIP64 extended-information extra
	 * field (it follows any overflowing size fields).
	 */
	private function zip64_extra_offset( string $extra, string $record ): ?int {
		$skip = 0;
		if ( 0xFFFFFFFF === unpack( 'V', substr( $record, 24, 4 ) )[1] ) {
			$skip += 8;
		}
		if ( 0xFFFFFFFF === unpack( 'V', substr( $record, 20, 4 ) )[1] ) {
			$skip += 8;
		}

		$pos          = 0;
		$extra_length = strlen( $extra );
		while ( $pos + 4 <= $extra_length ) {
			$id   = unpack( 'v', substr( $extra, $pos, 2 ) )[1];
			$size = unpack( 'v', substr( $extra, $pos + 2, 2 ) )[1];

			if ( 0x0001 === $id && $size >= $skip + 8 ) {
				return (int) unpack( 'P', substr( $extra, $pos + 4 + $skip, 8 ) )[1];
			}

			$pos += 4 + $size;
		}

		return null;
	}

	private function encrypt_stream( string $source_path, string $tmp_path, string $password ): bool {
		$in = fopen( $source_path, 'rb' );

		if ( false === $in ) {
			return false;
		}

		$out = fopen( $tmp_path, 'wb' );

		if ( false === $out ) {
			fclose( $in );
			return false;
		}

		$cipher_id  = $this->gcm_available() ? self::CIPHER_GCM : self::CIPHER_CBC_HMAC;
		$salt       = random_bytes( self::SALT_LENGTH );
		$key        = $this->derive_key( $password, $salt, self::ITERATIONS );
		$chunk_size = ChunkSizeCalculator::bytes_per_chunk();

		fwrite( $out, self::MAGIC_V2 );
		fwrite( $out, pack( 'C', 2 ) );
		fwrite( $out, pack( 'C', $cipher_id ) );
		fwrite( $out, pack( 'N', self::ITERATIONS ) );
		fwrite( $out, $salt );

		$hmac_ctx = self::CIPHER_CBC_HMAC === $cipher_id
			? hash_init( 'sha256', HASH_HMAC, $this->derive_mac_key( $key ) )
			: null;

		$ok = true;

		while ( ! feof( $in ) ) {
			$plain_chunk = fread( $in, $chunk_size );

			if ( false === $plain_chunk || '' === $plain_chunk ) {
				break;
			}

			if ( self::CIPHER_GCM === $cipher_id ) {
				$nonce        = random_bytes( self::GCM_NONCE_LENGTH );
				$tag          = '';
				$cipher_chunk = openssl_encrypt( $plain_chunk, self::GCM_CIPHER, $key, OPENSSL_RAW_DATA, $nonce, $tag, '', self::GCM_TAG_LENGTH );

				if ( false === $cipher_chunk ) {
					$ok = false;
					break;
				}

				fwrite( $out, pack( 'N', strlen( $cipher_chunk ) ) . $nonce . $cipher_chunk . $tag );
			} else {
				$iv           = random_bytes( self::CBC_IV_LENGTH );
				$cipher_chunk = openssl_encrypt( $plain_chunk, self::CBC_CIPHER, $key, OPENSSL_RAW_DATA, $iv );

				if ( false === $cipher_chunk ) {
					$ok = false;
					break;
				}

				$record = pack( 'N', strlen( $cipher_chunk ) ) . $iv . $cipher_chunk;
				hash_update( $hmac_ctx, $record );
				fwrite( $out, $record );
			}
		}

		if ( $ok && self::CIPHER_CBC_HMAC === $cipher_id ) {
			fwrite( $out, hash_final( $hmac_ctx, true ) );
		}

		fclose( $in );
		fclose( $out );

		return $ok;
	}

	/**
	 * Decrypt an encrypted archive to a new destination file (never
	 * decrypts in place, so the original encrypted backup is preserved).
	 * Streams to a temp file and only renames it into place once every
	 * chunk (GCM) or the whole-file HMAC (CBC) has been authenticated.
	 */
	public function decrypt_file( string $source_path, string $password, string $destination_path ): bool {
		$handle = fopen( $source_path, 'rb' );

		if ( false === $handle ) {
			return false;
		}

		$magic = fread( $handle, 8 );

		if ( self::MAGIC_V1 === $magic ) {
			fclose( $handle );
			return $this->decrypt_file_v1( $source_path, $password, $destination_path );
		}

		if ( self::MAGIC_V2 !== $magic ) {
			fclose( $handle );
			return false;
		}

		$version    = unpack( 'C', fread( $handle, 1 ) )[1] ?? 0;
		$cipher_id  = unpack( 'C', fread( $handle, 1 ) )[1] ?? 0;
		$iterations = unpack( 'N', fread( $handle, 4 ) )[1] ?? 0;
		$salt       = fread( $handle, self::SALT_LENGTH );
		fclose( $handle );

		if ( 2 !== $version || ! in_array( $cipher_id, array( self::CIPHER_GCM, self::CIPHER_CBC_HMAC ), true ) || self::SALT_LENGTH !== strlen( $salt ) ) {
			return false; // Unrecognized/corrupted header.
		}

		$key      = $this->derive_key( $password, $salt, $iterations );
		$tmp_path = $destination_path . '.decrypting.tmp';

		$ok = self::CIPHER_GCM === $cipher_id
			? $this->decrypt_gcm_body( $source_path, $tmp_path, $key )
			: $this->decrypt_cbc_hmac_body( $source_path, $tmp_path, $key );

		if ( ! $ok ) {
			if ( file_exists( $tmp_path ) ) {
				wp_delete_file( $tmp_path );
			}
			return false;
		}

		return rename( $tmp_path, $destination_path );
	}

	/**
	 * GCM: each chunk's tag is verified as it's read, so a wrong password or
	 * tampered chunk is caught before that chunk's plaintext is ever written.
	 */
	private function decrypt_gcm_body( string $source_path, string $tmp_path, string $key ): bool {
		$in = fopen( $source_path, 'rb' );

		if ( false === $in ) {
			return false;
		}

		fseek( $in, self::HEADER_LENGTH );

		$out = fopen( $tmp_path, 'wb' );

		if ( false === $out ) {
			fclose( $in );
			return false;
		}

		$ok = true;

		while ( ! feof( $in ) ) {
			$len_raw = fread( $in, 4 );

			if ( '' === $len_raw ) {
				break; // Clean EOF between chunks.
			}

			if ( 4 !== strlen( $len_raw ) ) {
				$ok = false;
				break;
			}

			$len        = unpack( 'N', $len_raw )[1];
			$nonce      = fread( $in, self::GCM_NONCE_LENGTH );
			$ciphertext = $len > 0 ? fread( $in, $len ) : '';
			$tag        = fread( $in, self::GCM_TAG_LENGTH );

			if ( self::GCM_NONCE_LENGTH !== strlen( $nonce ) || strlen( $ciphertext ) !== $len || self::GCM_TAG_LENGTH !== strlen( $tag ) ) {
				$ok = false;
				break;
			}

			$plain_chunk = openssl_decrypt( $ciphertext, self::GCM_CIPHER, $key, OPENSSL_RAW_DATA, $nonce, $tag );

			if ( false === $plain_chunk ) {
				$ok = false; // Wrong password, or this chunk was tampered with.
				break;
			}

			fwrite( $out, $plain_chunk );
		}

		fclose( $in );
		fclose( $out );

		return $ok;
	}

	/**
	 * CBC+HMAC: verifies the HMAC over the *entire* ciphertext body in a
	 * first pass — nothing is decrypted or written until that succeeds —
	 * then decrypts in a second pass. Classic encrypt-then-MAC, checked in
	 * the safe order (verify before decrypt) rather than decrypting
	 * unauthenticated data.
	 */
	private function decrypt_cbc_hmac_body( string $source_path, string $tmp_path, string $key ): bool {
		$total_size  = filesize( $source_path );
		$body_length = $total_size - self::HEADER_LENGTH - self::HMAC_LENGTH;

		if ( false === $total_size || $body_length < 0 ) {
			return false;
		}

		$chunk_size = ChunkSizeCalculator::bytes_per_chunk();
		$mac_key    = $this->derive_mac_key( $key );

		$in = fopen( $source_path, 'rb' );

		if ( false === $in ) {
			return false;
		}

		// Pass 1: verify.
		fseek( $in, self::HEADER_LENGTH );
		$hmac_ctx  = hash_init( 'sha256', HASH_HMAC, $mac_key );
		$remaining = $body_length;

		while ( $remaining > 0 ) {
			$read = fread( $in, min( $chunk_size, $remaining ) );

			if ( false === $read || '' === $read ) {
				fclose( $in );
				return false;
			}

			hash_update( $hmac_ctx, $read );
			$remaining -= strlen( $read );
		}

		$expected_hmac = fread( $in, self::HMAC_LENGTH );
		$actual_hmac   = hash_final( $hmac_ctx, true );

		if ( self::HMAC_LENGTH !== strlen( $expected_hmac ) || ! hash_equals( $expected_hmac, $actual_hmac ) ) {
			fclose( $in );
			return false; // Wrong password, or the file was tampered with.
		}

		// Pass 2: decrypt, now that the whole body is known-authentic.
		fseek( $in, self::HEADER_LENGTH );

		$out = fopen( $tmp_path, 'wb' );

		if ( false === $out ) {
			fclose( $in );
			return false;
		}

		$remaining = $body_length;
		$ok        = true;

		while ( $remaining > 0 ) {
			$len_raw = fread( $in, 4 );

			if ( 4 !== strlen( $len_raw ) ) {
				$ok = false;
				break;
			}

			$len        = unpack( 'N', $len_raw )[1];
			$iv         = fread( $in, self::CBC_IV_LENGTH );
			$ciphertext = $len > 0 ? fread( $in, $len ) : '';

			if ( self::CBC_IV_LENGTH !== strlen( $iv ) || strlen( $ciphertext ) !== $len ) {
				$ok = false;
				break;
			}

			$plain_chunk = openssl_decrypt( $ciphertext, self::CBC_CIPHER, $key, OPENSSL_RAW_DATA, $iv );

			if ( false === $plain_chunk ) {
				$ok = false;
				break;
			}

			fwrite( $out, $plain_chunk );
			$remaining -= 4 + self::CBC_IV_LENGTH + $len;
		}

		fclose( $in );
		fclose( $out );

		return $ok;
	}

	/**
	 * Legacy whole-file format (pre-streaming, unauthenticated CBC). Kept
	 * only so archives encrypted before this fix can still be restored —
	 * never written by encrypt_file_in_place() going forward.
	 */
	private function decrypt_file_v1( string $source_path, string $password, string $destination_path ): bool {
		$data = file_get_contents( $source_path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_read_file_get_contents

		if ( false === $data || ! str_starts_with( $data, self::MAGIC_V1 ) ) {
			return false;
		}

		$offset     = strlen( self::MAGIC_V1 );
		$salt       = substr( $data, $offset, self::SALT_LENGTH );
		$offset    += self::SALT_LENGTH;
		$iv_length  = openssl_cipher_iv_length( self::CBC_CIPHER );
		$iv         = substr( $data, $offset, $iv_length );
		$offset    += $iv_length;
		$ciphertext = substr( $data, $offset );

		$key   = $this->derive_key( $password, $salt, self::ITERATIONS );
		$plain = openssl_decrypt( $ciphertext, self::CBC_CIPHER, $key, OPENSSL_RAW_DATA, $iv );

		if ( false === $plain ) {
			return false;
		}

		return false !== file_put_contents( $destination_path, $plain ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
	}
}

// phpcs:enable WordPress.WP.AlternativeFunctions

// phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped
