<?php
/**
 * "Clear the cache after updating": empties the page caches of the common
 * caching plugins (through each plugin's own public function or action) and
 * WordPress's object cache, so visitors see pages built with the updated
 * plugins.
 *
 * @package WPBP\Updates
 */

namespace WPBP\Updates;

use WPBP\Utils\Errors;
use WPBP\Utils\Logger;

defined( 'ABSPATH' ) || exit;

class CacheCleaner {

	/**
	 * Clear every cache Wrensafe knows how to clear.
	 *
	 * @return string[] Names of the caches cleared.
	 */
	public static function clear_all( ?int $job_id = null ): array {
		$cleared = array();
		$run     = static function ( string $name, callable $clear ) use ( &$cleared, $job_id ): void {
			try {
				$clear();
				$cleared[] = $name;
			} catch ( \Throwable $e ) {
				Logger::warning( 'Clearing the ' . $name . ' cache failed: ' . Errors::text( $e ), array(), $job_id );
			}
		};

		$functions = array(
			'WP Rocket'            => 'rocket_clean_domain',
			'W3 Total Cache'       => 'w3tc_flush_all',
			'WP Super Cache'       => 'wp_cache_clear_cache',
			'SiteGround Optimizer' => 'sg_cachepress_purge_cache',
		);

		foreach ( $functions as $name => $function ) {
			if ( function_exists( $function ) ) {
				$run( $name, $function );
			}
		}

		if ( function_exists( 'wpfc_clear_all_cache' ) ) {
			$run(
				'WP Fastest Cache',
				static function () {
					wpfc_clear_all_cache( true );
				}
			);
		}

		if ( class_exists( 'autoptimizeCache' ) && is_callable( array( 'autoptimizeCache', 'clearall' ) ) ) {
			$run( 'Autoptimize', array( 'autoptimizeCache', 'clearall' ) );
		}

		// Plugins that clear their cache on an action of their own.
		$actions = array(
			'LiteSpeed Cache' => 'litespeed_purge_all',
			'Cache Enabler'   => 'cache_enabler_clear_complete_cache',
			'Breeze'          => 'breeze_clear_all_cache',
			'Hummingbird'     => 'wphb_clear_page_cache',
			'Nginx Helper'    => 'rt_nginx_helper_purge_all',
		);

		foreach ( $actions as $name => $action ) {
			if ( has_action( $action ) ) {
				$run(
					$name,
					static function () use ( $action ) {
						do_action( $action ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.DynamicHooknameFound -- the caching plugin's own action.
					}
				);
			}
		}

		// Elementor builds CSS files from its widgets; an updated add-on can change them.
		if ( class_exists( '\Elementor\Plugin' ) && isset( \Elementor\Plugin::$instance->files_manager ) && is_callable( array( \Elementor\Plugin::$instance->files_manager, 'clear_cache' ) ) ) {
			$run(
				'Elementor CSS',
				static function () {
					\Elementor\Plugin::$instance->files_manager->clear_cache();
				}
			);
		}

		wp_cache_flush();
		$cleared[] = __( 'object cache', 'wrensafe' );

		/**
		 * Fires when Wrensafe clears caches after updates. Hook in to clear
		 * a cache Wrensafe doesn't know (a host's own, a CDN).
		 */
		do_action( 'wpbp_safe_update_clear_cache' );

		Logger::info( 'Caches cleared after updates: ' . implode( ', ', $cleared ) . '.', array(), $job_id );

		return $cleared;
	}
}
