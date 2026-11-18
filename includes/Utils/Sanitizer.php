<?php
/**
 * Centralised sanitization helpers for common Wrensafe inputs.
 *
 * Wraps WordPress primitives to give call sites a single import rather than
 * scattered inline sanitize_*() calls, making audits easier.
 *
 * @package WPBP\Utils
 */

namespace WPBP\Utils;

defined( 'ABSPATH' ) || exit;

class Sanitizer {

	private const BACKUP_TYPES = array( 'full', 'database', 'files', 'media', 'themes', 'plugins', 'uploads', 'custom' );
	private const CHAIN_MODES  = array( 'full', 'incremental', 'differential' );

	/**
	 * Return a valid backup type or the supplied default.
	 */
	public static function backup_type( string $value, string $fallback = 'full' ): string {
		$value = sanitize_key( $value );
		return in_array( $value, self::BACKUP_TYPES, true ) ? $value : $fallback;
	}

	/**
	 * Return a valid chain mode or 'full'.
	 */
	public static function chain_mode( string $value ): string {
		$value = sanitize_key( $value );
		return in_array( $value, self::CHAIN_MODES, true ) ? $value : 'full';
	}

	/**
	 * Sanitize a filesystem path: strip null bytes and normalize directory separators.
	 * Does NOT validate that the path is within an allowed root — use PathValidator for that.
	 */
	public static function path( string $path ): string {
		$path = str_replace( chr( 0 ), '', $path );
		$path = str_replace( '\\', '/', $path );
		return $path;
	}

	/**
	 * Sanitize an email address, falling back to the admin email if empty/invalid.
	 */
	public static function email_with_fallback( string $email ): string {
		$clean = sanitize_email( $email );
		return $clean ? $clean : (string) get_option( 'admin_email' );
	}

	/**
	 * Sanitize a positive integer, clamped to a minimum.
	 */
	public static function positive_int( $value, int $min = 1, int $fallback = 1 ): int {
		$value = absint( $value );
		return $value >= $min ? $value : $fallback;
	}

	/**
	 * Sanitize a cloud provider key (alphanumeric + underscore).
	 */
	public static function provider_key( string $value ): string {
		return sanitize_key( $value );
	}

	/**
	 * Sanitize a free-text note (no HTML allowed).
	 */
	public static function note( string $value ): string {
		return sanitize_text_field( wp_unslash( $value ) );
	}

	/**
	 * Sanitize a backup tag (alphanumeric, hyphens, underscores, max 64 chars).
	 */
	public static function tag( string $value ): string {
		return substr( sanitize_key( $value ), 0, 64 );
	}

	/**
	 * Turn a database table name into a safe archive filename component.
	 * Used to keep schema/data filenames stable and collision-free between
	 * the backup writer and the restore reader.
	 */
	public static function table_slug( string $table ): string {
		$slug = preg_replace( '/[^A-Za-z0-9_\-]/', '_', $table );

		return '' !== $slug ? $slug : 'table';
	}
}
