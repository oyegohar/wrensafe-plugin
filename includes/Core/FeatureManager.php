<?php
/**
 * Central feature-gate registry.
 *
 * Every feature below is enabled in the free plugin. A future
 * Integrators can hook the `wpbp_feature_enabled` filter to turn off
 * features by plan without any call site needing to change.
 *
 * @package WPBP\Core
 */

namespace WPBP\Core;

defined( 'ABSPATH' ) || exit;

class FeatureManager {

	/**
	 * Registry of known feature keys and their default state.
	 * Every feature is enabled by default.
	 */
	private static function registry(): array {
		return array(
			'full_backup'            => true,
			'database_backup'        => true,
			'files_backup'           => true,
			'media_backup'           => true,
			'custom_folder_backup'   => true,
			'chunked_import'         => true,
			'restore'                => true,
			'selective_restore'      => true,
			'rollback'               => true,
			'scheduled_backups'      => true,
			'direct_migration'       => true,
			'staging_sites'          => true,
			'woocommerce_safe_mode'  => true,
			'backup_compare'         => true,
			'visual_restore_preview' => true,
			'migration_health_check' => true,
			'malware_scanner'        => true,
			'backup_encryption'      => true,
			'backup_before_updates'  => true,
		);
	}

	/**
	 * Whether a given feature is currently enabled.
	 */
	public static function is_enabled( string $feature ): bool {
		$registry = self::registry();
		$enabled  = $registry[ $feature ] ?? false;

		/**
		 * Filter whether a Wrensafe feature is enabled.
		 * Lets a host or integrator turn individual features off.
		 */
		return (bool) apply_filters( 'wpbp_feature_enabled', $enabled, $feature );
	}

	/**
	 * Return the full feature registry, useful for diagnostics/system info.
	 */
	public static function get_all(): array {
		$features = array();

		foreach ( array_keys( self::registry() ) as $feature ) {
			$features[ $feature ] = self::is_enabled( $feature );
		}

		return $features;
	}
}
