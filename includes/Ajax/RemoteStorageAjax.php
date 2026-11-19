<?php
/**
 * Settings → Remote storage → Test connection.
 *
 * @package WPBP\Ajax
 */

namespace WPBP\Ajax;

use WPBP\Core\Settings;
use WPBP\Remote\RemoteStorage;
use WPBP\Security\CapabilityManager;

defined( 'ABSPATH' ) || exit;

class RemoteStorageAjax {

	/**
	 * Test the settings in the form (not yet saved). A blank password uses
	 * the saved one.
	 */
	public static function test(): void {
		check_ajax_referer( 'wpbp_admin_ajax', '_wpbp_nonce' );

		if ( ! CapabilityManager::current_user_can( CapabilityManager::MANAGE_SETTINGS ) ) {
			wp_send_json_error( array( 'message' => __( 'You do not have permission to change settings.', 'wrensafe' ) ), 403 );
		}

		// The form's fields as sent (filter_input() reads them before
		// WordPress adds slashes), less control characters. Passwords must
		// stay exactly as typed, so the fields aren't run through
		// sanitize_text_field() here: RemoteStorage::sanitize() cleans each
		// one for its type, as when the form is saved.
		$form     = filter_input( INPUT_POST, Settings::OPTION_NAME, FILTER_UNSAFE_RAW, FILTER_REQUIRE_ARRAY | FILTER_FLAG_STRIP_LOW );
		$posted   = is_array( $form ) && isset( $form['remote'] ) && is_array( $form['remote'] ) ? $form['remote'] : array();
		$settings = RemoteStorage::sanitize( $posted );

		if ( '' === $settings['type'] ) {
			wp_send_json_error( array( 'message' => __( 'Choose where to copy backups first.', 'wrensafe' ) ) );
		}

		$reason = RemoteStorage::unavailable_reason( $settings['type'] );

		if ( '' !== $reason ) {
			wp_send_json_error( array( 'message' => $reason ) );
		}

		try {
			$result = RemoteStorage::target( $settings )->test();
		} catch ( \Throwable $e ) {
			$result = array(
				'ok'      => false,
				'message' => RemoteStorage::error_text( $e ),
			);
		}

		if ( empty( $result['ok'] ) ) {
			wp_send_json_error( array( 'message' => (string) $result['message'] ) );
		}

		wp_send_json_success( array( 'message' => (string) $result['message'] ) );
	}
}
