<?php
/**
 * Runtime environment compatibility checks.
 *
 * @package WPBP\Core
 */

namespace WPBP\Core;

defined( 'ABSPATH' ) || exit;

class Compatibility {

	const MIN_PHP_VERSION = '7.4';
	const MIN_WP_VERSION  = '6.0';

	/**
	 * Whether the current environment meets the plugin's minimum requirements.
	 */
	public static function is_compatible(): bool {
		global $wp_version;

		if ( version_compare( PHP_VERSION, self::MIN_PHP_VERSION, '<' ) ) {
			return false;
		}

		if ( version_compare( $wp_version, self::MIN_WP_VERSION, '<' ) ) {
			return false;
		}

		return true;
	}

	/**
	 * Human readable incompatibility reason, empty string when compatible.
	 */
	public static function get_incompatibility_message(): string {
		global $wp_version;

		if ( version_compare( PHP_VERSION, self::MIN_PHP_VERSION, '<' ) ) {
			return sprintf(
				/* translators: 1: required PHP version, 2: current PHP version */
				__( 'Wrensafe requires PHP %1$s or higher. Your server is running PHP %2$s.', 'wrensafe' ),
				self::MIN_PHP_VERSION,
				PHP_VERSION
			);
		}

		if ( version_compare( $wp_version, self::MIN_WP_VERSION, '<' ) ) {
			return sprintf(
				/* translators: 1: required WordPress version, 2: current WordPress version */
				__( 'Wrensafe requires WordPress %1$s or higher. Your site is running WordPress %2$s.', 'wrensafe' ),
				self::MIN_WP_VERSION,
				$wp_version
			);
		}

		return '';
	}
}
