<?php
/**
 * The whole-archive checksum, calculated (or checked) in bounded steps.
 *
 * When a backup is made, the classic whole-file SHA-256 is recorded
 * whenever this PHP allows it within bounded requests: always on PHP 8.1+
 * (the hash state is saved between requests), and on older PHP when the
 * archive can be hashed inside one request. Otherwise (a large archive on
 * PHP 7.4/8.0) the sliced checksum (see SliceHash) is recorded, which any
 * PHP can continue from request to request.
 *
 * When a backup is checked, the archive is hashed the way its recorded
 * checksum was made, again in bounded steps where the format allows it.
 *
 * @package WPBP\Backup
 */

namespace WPBP\Backup;

defined( 'ABSPATH' ) || exit;

// Backup archives are streamed in bounded blocks with native file handles
// (fopen/fread): WP_Filesystem has no streaming API. Every path is inside
// Wrensafe's protected storage directory.
// phpcs:disable WordPress.WP.AlternativeFunctions

final class ArchiveChecksum {

	/** Bytes read per iteration (4 MB, a divisor of the slice size). */
	private const READ_BYTES = 4194304;

	/**
	 * Hash the next part of $path. $state starts as an empty array and is
	 * carried between requests by the caller. Returns the checksum once the
	 * whole file is hashed, or null when $deadline came first.
	 *
	 * @param string              $expected '' when a backup is made; the recorded
	 *                                      checksum when one is checked (the file
	 *                                      is then hashed the same way).
	 * @param array<string,mixed> $state
	 * @param float|null          $deadline microtime(true) to stop at, or null for none.
	 */
	public static function step( string $path, string $expected, array &$state, ?float $deadline ): ?string {
		if ( empty( $state['mode'] ) ) {
			$state = self::initial_state( $expected );
		}

		clearstatcache( true, $path );

		if ( ! is_file( $path ) || ! is_readable( $path ) ) {
			throw new \RuntimeException( esc_html__( 'The backup archive could not be read for its checksum (missing or unreadable).', 'wrensafe' ) );
		}

		$size   = (int) filesize( $path );
		$mode   = (string) $state['mode'];
		$whole  = null;
		$slices = null;
		$offset = 0;

		switch ( $mode ) {
			case 'whole':
				// PHP 8.1+: the whole-file hash state is saved between requests.
				$offset = (int) ( $state['offset'] ?? 0 );
				$whole  = $offset > 0 ? self::restore_context( (string) ( $state['context'] ?? '' ) ) : hash_init( 'sha256' );
				break;

			case 'whole_once':
				// Older PHP checking a classic checksum: one pass.
				$whole = hash_init( 'sha256' );
				break;

			case 'adaptive':
				// Older PHP making a backup: both at once, so the classic
				// checksum is kept when the archive is hashed in this request.
				$whole  = hash_init( 'sha256' );
				$slices = new SliceHash();
				break;

			case 'sliced':
				$slices = SliceHash::resume( (array) ( $state['slices'] ?? array() ) );
				$offset = $slices->length();
				break;

			default:
				throw new \RuntimeException( esc_html__( 'The saved progress of a checksum is unreadable. Start again.', 'wrensafe' ) );
		}

		if ( $offset > $size ) {
			throw new \RuntimeException( esc_html__( 'The backup archive changed while its checksum was being calculated. Start again.', 'wrensafe' ) );
		}

		$handle = fopen( $path, 'rb' );

		if ( false === $handle ) {
			throw new \RuntimeException( esc_html__( 'The backup archive could not be opened for its checksum.', 'wrensafe' ) );
		}

		if ( $offset > 0 && 0 !== fseek( $handle, $offset ) ) {
			fclose( $handle );
			throw new \RuntimeException( esc_html__( 'The backup archive could not be read for its checksum (missing or unreadable).', 'wrensafe' ) );
		}

		$started = microtime( true );
		$first   = $offset;

		while ( true ) {
			$buffer = fread( $handle, self::READ_BYTES );

			if ( false === $buffer ) {
				fclose( $handle );
				throw new \RuntimeException( esc_html__( 'A read error occurred while calculating the backup checksum.', 'wrensafe' ) );
			}

			if ( '' === $buffer ) {
				break;
			}

			if ( null !== $whole ) {
				hash_update( $whole, $buffer );
			}

			if ( null !== $slices ) {
				$slices->update( $buffer );
			}

			$offset += strlen( $buffer );

			if ( $offset >= $size || null === $deadline || microtime( true ) < $deadline ) {
				continue;
			}

			if ( 'whole' === $mode ) {
				fclose( $handle );
				$state['offset']  = $offset;
				$state['context'] = self::save_context( $whole );
				return null;
			}

			if ( null === $slices || ! $slices->at_boundary() ) {
				continue;
			}

			if ( 'adaptive' === $mode && ! SliceHash::too_long_to_finish( $size - $offset, $offset - $first, $started, $deadline ) ) {
				continue;
			}

			// Stop here and continue with the sliced checksum only.
			fclose( $handle );
			$state = array(
				'mode'   => 'sliced',
				'slices' => $slices->state(),
			);
			return null;
		}

		fclose( $handle );

		if ( $offset !== $size ) {
			throw new \RuntimeException( esc_html__( 'The backup archive changed while its checksum was being calculated. Start again.', 'wrensafe' ) );
		}

		$state = array();

		return null !== $whole ? hash_final( $whole ) : $slices->result();
	}

	/**
	 * Whether a check of $expected can stop between requests on this PHP
	 * (a sliced checksum always can; a classic one from PHP 8.1 on).
	 */
	public static function can_pause( string $expected ): bool {
		return SliceHash::is_sliced( $expected ) || SliceHash::can_save_hash_state();
	}

	/** Recalculate $path's checksum in the format of $expected, in one go, and compare. */
	public static function matches( string $path, string $expected ): bool {
		if ( null === SliceHash::parse( $expected ) ) {
			return false;
		}

		try {
			$state  = array();
			$actual = (string) self::step( $path, $expected, $state, null );
		} catch ( \Throwable $e ) {
			return false;
		}

		return SliceHash::equals( $expected, $actual );
	}

	/** @return array<string,mixed> */
	private static function initial_state( string $expected ): array {
		if ( '' === $expected ) {
			return array( 'mode' => SliceHash::can_save_hash_state() ? 'whole' : 'adaptive' );
		}

		$parsed = SliceHash::parse( $expected );

		if ( null === $parsed ) {
			throw new \RuntimeException( esc_html__( 'The checksum recorded for this backup is in a format this version of Wrensafe doesn’t know.', 'wrensafe' ) );
		}

		if ( $parsed['sliced'] ) {
			return array(
				'mode'   => 'sliced',
				'slices' => ( new SliceHash( $parsed['slice'] ) )->state(),
			);
		}

		return array( 'mode' => SliceHash::can_save_hash_state() ? 'whole' : 'whole_once' );
	}

	/** Hex: the serialized state holds raw bytes, and it is kept in JSON. */
	private static function save_context( \HashContext $context ): string {
		return bin2hex( serialize( $context ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize -- HashContext state (PHP 8.1+), read back with allowed_classes limited to HashContext.
	}

	private static function restore_context( string $saved ): \HashContext {
		$context = '' !== $saved ? unserialize( (string) hex2bin( $saved ), array( 'allowed_classes' => array( \HashContext::class ) ) ) : false; // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_unserialize -- only HashContext may be instantiated.

		if ( ! $context instanceof \HashContext ) {
			throw new \RuntimeException( esc_html__( 'The saved progress of a checksum is unreadable. Start again.', 'wrensafe' ) );
		}

		return $context;
	}
}

// phpcs:enable WordPress.WP.AlternativeFunctions
