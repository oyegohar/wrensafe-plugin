<?php
/**
 * Picks chunk sizes (bytes, rows, or statements) that fit comfortably
 * within this server's memory_limit and max_execution_time, instead of
 * every caller hardcoding a constant that might be too large for tight
 * shared hosting or unnecessarily small for a generous VPS.
 *
 * @package WPBP\Utils
 */

namespace WPBP\Utils;

use WPBP\Core\Settings;

defined( 'ABSPATH' ) || exit;

class ChunkSizeCalculator {

	private const DEFAULT_BYTES = 4 * 1024 * 1024; // 4MB.
	private const MIN_BYTES     = 256 * 1024; // 256KB.
	private const MAX_BYTES     = 16 * 1024 * 1024; // 16MB.

	/**
	 * Bytes to read/write per chunk for streaming file operations
	 * (encryption, archive extraction, cloud transfer). Respects an
	 * Advanced-settings override if the site admin has set one.
	 */
	public static function bytes_per_chunk(): int {
		$override = (int) Settings::get( 'chunk_size_bytes', 0 );

		if ( $override > 0 ) {
			return max( self::MIN_BYTES, min( self::MAX_BYTES, $override ) );
		}

		$memory_limit = self::memory_limit_bytes();

		if ( $memory_limit <= 0 ) {
			return self::DEFAULT_BYTES;
		}

		// Stay well under the limit even with a few chunk-sized buffers
		// alive at once (read buffer, cipher buffer, output buffer, etc.).
		$budget = (int) ( $memory_limit * 0.1 );

		return max( self::MIN_BYTES, min( self::MAX_BYTES, $budget ) );
	}

	/**
	 * Database rows per chunk, scaled down from $fallback when memory or
	 * execution-time budgets are tight (typical of shared hosting).
	 */
	public static function rows_per_chunk( int $fallback = 500 ): int {
		return self::is_constrained() ? max( 50, (int) ( $fallback / 4 ) ) : $fallback;
	}

	/**
	 * SQL statements per chunk during import, same scaling as rows_per_chunk().
	 */
	public static function statements_per_chunk( int $fallback = 100 ): int {
		return self::is_constrained() ? max( 20, (int) ( $fallback / 4 ) ) : $fallback;
	}

	/**
	 * Whether this server looks memory- or time-constrained — the signal
	 * used to scale row/statement chunk sizes down from their defaults.
	 */
	private static function is_constrained(): bool {
		$memory_limit = self::memory_limit_bytes();
		$time_limit   = (int) ini_get( 'max_execution_time' );

		return ( $memory_limit > 0 && $memory_limit < 128 * 1024 * 1024 )
			|| ( $time_limit > 0 && $time_limit < 30 );
	}

	/**
	 * PHP's memory_limit in bytes, or 0 when unlimited/unknown.
	 */
	private static function memory_limit_bytes(): int {
		$limit = ini_get( 'memory_limit' );

		if ( false === $limit || '' === $limit || '-1' === $limit ) {
			return 0;
		}

		return self::to_bytes( $limit );
	}

	private static function to_bytes( string $value ): int {
		$value  = trim( $value );
		$unit   = strtolower( substr( $value, -1 ) );
		$number = (float) $value;

		switch ( $unit ) {
			case 'g':
				return (int) ( $number * 1024 * 1024 * 1024 );
			case 'm':
				return (int) ( $number * 1024 * 1024 );
			case 'k':
				return (int) ( $number * 1024 );
			default:
				return (int) $number;
		}
	}
}
