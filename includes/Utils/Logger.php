<?php
/**
 * Activity logger, backed by the wp_wpbp_logs database table.
 *
 * @package WPBP\Utils
 */

namespace WPBP\Utils;

use WPBP\Core\Database\LogsRepository;
use WPBP\Core\Settings;

defined( 'ABSPATH' ) || exit;

class Logger {

	const LEVEL_DEBUG    = 'debug';
	const LEVEL_INFO     = 'info';
	const LEVEL_SUCCESS  = 'success';
	const LEVEL_WARNING  = 'warning';
	const LEVEL_ERROR    = 'error';
	const LEVEL_CRITICAL = 'critical';
	const LEVEL_FAILED   = 'failed';

	public static function log( string $level, string $message, array $context = array(), ?int $job_id = null ): void {
		if ( ! self::records( $level ) ) {
			return;
		}

		LogsRepository::insert( $level, $message, $context, $job_id );
	}

	/**
	 * Settings → General "Activity logging" switches off routine entries
	 * (info, success); warnings, errors and failures are always recorded.
	 * Debug entries need Settings → Advanced "Debug logging".
	 */
	private static function records( string $level ): bool {
		if ( self::LEVEL_DEBUG === $level ) {
			return (bool) Settings::get( 'enable_debug_logging', false );
		}

		if ( self::LEVEL_INFO === $level || self::LEVEL_SUCCESS === $level ) {
			return (bool) Settings::get( 'enable_activity_log', true );
		}

		return true;
	}

	public static function info( string $message, array $context = array(), ?int $job_id = null ): void {
		self::log( self::LEVEL_INFO, $message, $context, $job_id );
	}

	public static function warning( string $message, array $context = array(), ?int $job_id = null ): void {
		self::log( self::LEVEL_WARNING, $message, $context, $job_id );
	}

	public static function error( string $message, array $context = array(), ?int $job_id = null ): void {
		self::log( self::LEVEL_ERROR, $message, $context, $job_id );
	}

	/**
	 * Richer levels stored as their own values (Issue 09) so Activity Logs
	 * can filter on them — the level column is a plain VARCHAR, so no schema
	 * change is needed.
	 */
	public static function success( string $message, array $context = array(), ?int $job_id = null ): void {
		self::log( self::LEVEL_SUCCESS, $message, $context, $job_id );
	}

	public static function debug( string $message, array $context = array(), ?int $job_id = null ): void {
		self::log( self::LEVEL_DEBUG, $message, $context, $job_id );
	}

	public static function critical( string $message, array $context = array(), ?int $job_id = null ): void {
		self::log( self::LEVEL_CRITICAL, $message, $context, $job_id );
	}

	/**
	 * A failed operation (backup/restore/verify/stale job). Kept distinct
	 * from 'error' so failure outcomes are directly filterable.
	 */
	public static function failed( string $message, array $context = array(), ?int $job_id = null ): void {
		self::log( self::LEVEL_FAILED, $message, $context, $job_id );
	}

	/**
	 * Return the most recent log entries, newest first, shaped for display.
	 */
	public static function get_recent_entries( int $limit = 100 ): array {
		$rows    = LogsRepository::get_recent( $limit );
		$entries = array();

		foreach ( $rows as $row ) {
			$entries[] = array(
				'time'    => $row['created_at'],
				'level'   => $row['level'],
				'message' => $row['message'],
				'context' => $row['context'] ? json_decode( $row['context'], true ) : array(),
			);
		}

		return $entries;
	}
}
