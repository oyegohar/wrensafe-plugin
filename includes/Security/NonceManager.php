<?php
/**
 * Nonces for Wrensafe's actions, with the "wpbp_" action prefix. Each
 * handler verifies its nonce with check_ajax_referer() or
 * check_admin_referer().
 *
 * @package WPBP\Security
 */

namespace WPBP\Security;

defined( 'ABSPATH' ) || exit;

class NonceManager {

	private const ACTION_PREFIX = 'wpbp_';

	private static function prefixed_action( string $action ): string {
		return self::ACTION_PREFIX . $action;
	}

	/**
	 * Create a nonce for a given action.
	 */
	public static function create( string $action ): string {
		return wp_create_nonce( self::prefixed_action( $action ) );
	}

	/**
	 * Output a hidden nonce field for a form.
	 */
	public static function field( string $action, string $name = '_wpbp_nonce' ): void {
		wp_nonce_field( self::prefixed_action( $action ), $name );
	}
}
