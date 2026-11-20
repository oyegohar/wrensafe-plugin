<?php
/**
 * A checksum that can be calculated across several requests on any PHP.
 *
 * The classic checksum is a SHA-256 of the whole file. Calculating it over
 * several requests means keeping the hash state in between, and PHP can
 * only save that state from 8.1 on (a serializable HashContext). On older
 * PHP a multi-GB archive, or one huge file inside it, had to be hashed
 * inside one request, which a 30-second host doesn't allow.
 *
 * The sliced checksum cuts the data into fixed slices (8 MB) and chains
 * their digests: d = sha256( d . slice ), starting with an empty d. Between
 * two slices the whole state is that 32-byte digest and the byte count, so
 * the work can stop at any slice boundary and continue in the next request.
 * It is written as "sha256-slices-v1:<slice bytes>:<64 hex digits>"; a plain
 * 64-digit value is the classic whole-file SHA-256, which every version
 * before 1.2.1 wrote and which 1.2.1 still writes whenever it can (so a
 * backup stays checkable by older versions).
 *
 * @package WPBP\Backup
 */

namespace WPBP\Backup;

defined( 'ABSPATH' ) || exit;

final class SliceHash {

	/** Format name and version, the first part of a sliced checksum. */
	const FORMAT = 'sha256-slices-v1';

	/** Bytes per slice. */
	const SLICE_BYTES = 8388608; // 8 MB.

	private const MIN_SLICE = 1048576; // 1 MB.
	private const MAX_SLICE = 268435456; // 256 MB.

	/** @var int */
	private $slice;

	/** @var string Raw 32-byte digest of the slices so far ('' before the first). */
	private $digest;

	/** @var \HashContext|null The slice being read. */
	private $context = null;

	/** @var int Bytes of the current slice read so far. */
	private $filled = 0;

	/** @var int Bytes hashed in total. */
	private $length;

	public function __construct( int $slice_bytes = self::SLICE_BYTES, string $digest_hex = '', int $length = 0 ) {
		if ( $slice_bytes < self::MIN_SLICE || $slice_bytes > self::MAX_SLICE || $length < 0 || 0 !== $length % $slice_bytes ) {
			throw new \RuntimeException( esc_html__( 'The saved progress of a checksum is unreadable. Start again.', 'wrensafe' ) );
		}

		$this->slice  = $slice_bytes;
		$this->digest = '' === $digest_hex ? '' : (string) hex2bin( $digest_hex );
		$this->length = $length;
	}

	/**
	 * Continue from state() saved by an earlier request.
	 *
	 * @param array<string,mixed> $state
	 */
	public static function resume( array $state ): self {
		$digest = (string) ( $state['digest'] ?? '' );

		if ( '' !== $digest && ! preg_match( '/^[a-f0-9]{64}$/', $digest ) ) {
			throw new \RuntimeException( esc_html__( 'The saved progress of a checksum is unreadable. Start again.', 'wrensafe' ) );
		}

		return new self( (int) ( $state['slice'] ?? 0 ), $digest, (int) ( $state['length'] ?? -1 ) );
	}

	public function update( string $data ): void {
		$offset = 0;
		$total  = strlen( $data );

		while ( $offset < $total ) {
			if ( null === $this->context ) {
				$this->context = hash_init( 'sha256' );
				hash_update( $this->context, $this->digest );
			}

			$take = min( $total - $offset, $this->slice - $this->filled );

			hash_update( $this->context, 0 === $offset && $take === $total ? $data : substr( $data, $offset, $take ) );

			$offset       += $take;
			$this->filled += $take;
			$this->length += $take;

			if ( $this->filled === $this->slice ) {
				$this->digest  = hash_final( $this->context, true );
				$this->context = null;
				$this->filled  = 0;
			}
		}
	}

	/** Whether the data so far ends on a slice boundary (state() can be saved). */
	public function at_boundary(): bool {
		return 0 === $this->filled;
	}

	public function length(): int {
		return $this->length;
	}

	/**
	 * The state to continue from in another request. Only at a slice boundary.
	 *
	 * @return array{slice:int,digest:string,length:int}
	 */
	public function state(): array {
		if ( ! $this->at_boundary() ) {
			throw new \LogicException( 'A sliced checksum can only be saved at a slice boundary.' );
		}

		return array(
			'slice'  => $this->slice,
			'digest' => bin2hex( $this->digest ),
			'length' => $this->length,
		);
	}

	/** The finished checksum, "sha256-slices-v1:<slice>:<hex>". */
	public function result(): string {
		$digest = $this->digest;

		if ( null !== $this->context ) {
			$digest = hash_final( $this->context, true );
		} elseif ( '' === $digest ) {
			$digest = hash( 'sha256', '', true );
		}

		return self::FORMAT . ':' . $this->slice . ':' . bin2hex( $digest );
	}

	/**
	 * Read a recorded checksum: the classic whole-file SHA-256 or a sliced
	 * one. Null when it is neither (an empty or unknown value).
	 *
	 * @return array{sliced:bool,slice:int,hex:string}|null
	 */
	public static function parse( string $checksum ): ?array {
		$checksum = strtolower( trim( $checksum ) );

		if ( 1 === preg_match( '/^[a-f0-9]{64}$/', $checksum ) ) {
			return array(
				'sliced' => false,
				'slice'  => 0,
				'hex'    => $checksum,
			);
		}

		if ( 1 === preg_match( '/^' . preg_quote( self::FORMAT, '/' ) . ':(\d{7,9}):([a-f0-9]{64})$/', $checksum, $match ) ) {
			$slice = (int) $match[1];

			if ( $slice >= self::MIN_SLICE && $slice <= self::MAX_SLICE ) {
				return array(
					'sliced' => true,
					'slice'  => $slice,
					'hex'    => $match[2],
				);
			}
		}

		return null;
	}

	/** Whether $checksum is a sliced checksum (see parse()). */
	public static function is_sliced( string $checksum ): bool {
		$parsed = self::parse( $checksum );

		return null !== $parsed && $parsed['sliced'];
	}

	/** Whether two recorded checksums are the same (case-insensitive, timing-safe). */
	public static function equals( string $expected, string $actual ): bool {
		return '' !== $expected && hash_equals( strtolower( trim( $expected ) ), strtolower( trim( $actual ) ) );
	}

	/**
	 * Whether this PHP can save a whole-file SHA-256 between requests
	 * (a serializable HashContext, PHP 8.1+).
	 */
	public static function can_save_hash_state(): bool {
		return PHP_VERSION_ID >= 80100;
	}

	/**
	 * Extra seconds past the request's deadline that may be spent finishing
	 * something that still yields a classic checksum, instead of switching
	 * to a sliced one. None on hosts with a short time limit.
	 */
	public static function grace_seconds(): float {
		$max = (int) ini_get( 'max_execution_time' );

		return ( $max > 0 && $max < 20 ) ? 0.0 : 4.0;
	}

	/**
	 * Past the deadline: whether the rest ($remaining bytes, at the rate of
	 * this request so far) can't be finished within the grace period.
	 */
	public static function too_long_to_finish( int $remaining, int $done, float $started, float $deadline ): bool {
		$now   = microtime( true );
		$limit = $deadline + self::grace_seconds();

		if ( $now >= $limit ) {
			return true;
		}

		$rate = $done / max( 0.001, $now - $started );

		return $rate <= 0 || $now + $remaining / $rate > $limit;
	}
}
