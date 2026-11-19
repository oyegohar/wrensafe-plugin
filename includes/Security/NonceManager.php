<?php
/**
 * Centralized nonce creation and verification for all Wrensafe actions.
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
	 * Verify a nonce value for a given action.
	 */
	public static function verify( string $action, string $nonce ): bool {
		return (bool) wp_verify_nonce( $nonce, self::prefixed_action( $action ) );
	}

	/**
	 * Output a hidden nonce field for a form.
	 */
	public static function field( string $action, string $name = '_wpbp_nonce' ): void {
		wp_nonce_field( self::prefixed_action( $action ), $name );
	}

	/**
	 * Verify a nonce taken from $_REQUEST and die with an error if invalid.
	 * Intended for AJAX/admin-post handlers added in later phases.
	 */
	public static function verify_request_or_die( string $action, string $request_key = '_wpbp_nonce' ): void {
		$valid = isset( $_REQUEST[ $request_key ] ) && wp_verify_nonce( sanitize_text_field( wp_unslash( $_REQUEST[ $request_key ] ) ), self::prefixed_action( $action ) );

		if ( ! $valid ) {
			wp_die( esc_html__( 'Security check failed. Please refresh the page and try again.', 'wrensafe' ), 403 );
		}
	}
}
