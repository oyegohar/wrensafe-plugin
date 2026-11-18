<?php
/**
 * Thin wrapper around wp_send_json_* for consistent AJAX response shape.
 *
 * Using this class instead of inline wp_send_json_* calls means you can
 * grep "Response::" to find every AJAX response in the plugin.
 *
 * @package WPBP\Utils
 */

namespace WPBP\Utils;

defined( 'ABSPATH' ) || exit;

class Response {

	/**
	 * Send a success response and halt execution.
	 *
	 * @param array $data Optional key→value payload.
	 */
	public static function success( array $data = array() ): void {
		wp_send_json_success( $data );
	}

	/**
	 * Send an error response and halt execution.
	 *
	 * @param string $message Human-readable error message (already translatable by the caller).
	 * @param int    $status  HTTP status code.
	 */
	public static function error( string $message, int $status = 400 ): void {
		wp_send_json_error( array( 'message' => $message ), $status );
	}

	/**
	 * Send a 403 Forbidden error and halt.
	 */
	public static function forbidden( string $message = '' ): void {
		self::error( $message ? $message : __( 'You do not have permission to perform this action.', 'wrensafe' ), 403 );
	}

	/**
	 * Send a 404 Not Found error and halt.
	 */
	public static function not_found( string $message = '' ): void {
		self::error( $message ? $message : __( 'The requested resource was not found.', 'wrensafe' ), 404 );
	}

	/**
	 * Send a 422 Unprocessable Content error for validation failures.
	 */
	public static function validation_error( string $message ): void {
		self::error( $message, 422 );
	}
}
