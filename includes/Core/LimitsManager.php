<?php
/**
 * Optional usage limits. Wrensafe itself sets no limits (every value is
 * UNLIMITED); a site owner or integrator may impose limits through the
 * wpbp_limit_value filter, e.g. on a managed hosting platform.
 *
 * A limit value of -1 means unlimited. A host or integrator can hook the
 * `wpbp_limit_value` filter to set its own limits without
 * any call site needing to change.
 *
 * @package WPBP\Core
 */

namespace WPBP\Core;

defined( 'ABSPATH' ) || exit;

class LimitsManager {

	const UNLIMITED = -1;

	/**
	 * Default limits — all unlimited.
	 */
	private static function registry(): array {
		return array(
			'scheduled_backups_count'         => self::UNLIMITED,
			'cloud_providers_connected'       => self::UNLIMITED,
			'cloud_destinations_per_schedule' => self::UNLIMITED,
			'direct_migrations_per_month'     => self::UNLIMITED,
			'incremental_retention_days'      => self::UNLIMITED,
			'staging_sites_count'             => self::UNLIMITED,
			'manual_backups_count'            => self::UNLIMITED,
			'manual_restores_count'           => self::UNLIMITED,
			'local_backups_count'             => self::UNLIMITED,
		);
	}

	/**
	 * Get the current limit for a given key.
	 */
	public static function get_limit( string $key ): int {
		$registry = self::registry();
		$value    = $registry[ $key ] ?? self::UNLIMITED;

		/**
		 * Filter a Wrensafe usage limit value (-1 = unlimited).
		 * Lets a host or integrator impose limits; Wrensafe sets none.
		 */
		return (int) apply_filters( 'wpbp_limit_value', $value, $key );
	}

	/**
	 * Whether a given limit key is unlimited.
	 */
	public static function is_unlimited( string $key ): bool {
		return self::UNLIMITED === self::get_limit( $key );
	}

	/**
	 * Whether a current usage count is still within the limit.
	 */
	public static function is_within_limit( string $key, int $current_count ): bool {
		if ( self::is_unlimited( $key ) ) {
			return true;
		}

		return $current_count < self::get_limit( $key );
	}

	/**
	 * Return the full limits registry, useful for diagnostics/system info.
	 */
	public static function get_all(): array {
		$limits = array();

		foreach ( array_keys( self::registry() ) as $key ) {
			$limits[ $key ] = self::get_limit( $key );
		}

		return $limits;
	}
}
