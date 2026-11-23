<?php
/**
 * AJAX endpoints of Settings → Import / Export. Each checks the admin nonce and
 * the permission to manage settings. See WPBP\Core\SettingsTransfer.
 *
 * @package WPBP\Ajax
 */

namespace WPBP\Ajax;

use WPBP\Core\SettingsTransfer;
use WPBP\Security\CapabilityManager;

defined( 'ABSPATH' ) || exit;

class SettingsToolsAjax {

	private static function require_capability(): void {
		if ( ! CapabilityManager::current_user_can( CapabilityManager::MANAGE_SETTINGS ) ) {
			wp_send_json_error( array( 'message' => __( 'You do not have permission to change Wrensafe’s settings.', 'wrensafe' ) ), 403 );
		}
	}

	/** @param mixed $result */
	private static function send( $result ): void {
		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ) );
		}

		wp_send_json_success( is_array( $result ) ? $result : array( 'changed' => (int) $result ) );
	}

	public static function export(): void {
		check_ajax_referer( 'wpbp_admin_ajax', '_wpbp_nonce' );
		self::require_capability();

		wp_send_json_success(
			array(
				'filename' => SettingsTransfer::file_name(),
				'content'  => SettingsTransfer::export(),
			)
		);
	}

	public static function preview(): void {
		check_ajax_referer( 'wpbp_admin_ajax', '_wpbp_nonce' );
		self::require_capability();

		$settings = isset( $_POST['settings'] ) && is_array( $_POST['settings'] ) ? map_deep( wp_unslash( $_POST['settings'] ), 'sanitize_textarea_field' ) : array();
		$meta     = isset( $_POST['meta'] ) && is_array( $_POST['meta'] ) ? map_deep( wp_unslash( $_POST['meta'] ), 'sanitize_text_field' ) : array();

		self::send( SettingsTransfer::preview( $settings, $meta ) );
	}

	public static function import(): void {
		check_ajax_referer( 'wpbp_admin_ajax', '_wpbp_nonce' );
		self::require_capability();

		$settings = isset( $_POST['settings'] ) && is_array( $_POST['settings'] ) ? map_deep( wp_unslash( $_POST['settings'] ), 'sanitize_textarea_field' ) : array();
		$meta     = isset( $_POST['meta'] ) && is_array( $_POST['meta'] ) ? map_deep( wp_unslash( $_POST['meta'] ), 'sanitize_text_field' ) : array();
		$groups   = isset( $_POST['groups'] ) && is_array( $_POST['groups'] ) ? array_map( 'sanitize_key', wp_unslash( $_POST['groups'] ) ) : array();

		self::send( SettingsTransfer::import( $settings, $meta, $groups ) );
	}

	public static function reset(): void {
		check_ajax_referer( 'wpbp_admin_ajax', '_wpbp_nonce' );
		self::require_capability();

		self::send( SettingsTransfer::reset() );
	}

	public static function undo(): void {
		check_ajax_referer( 'wpbp_admin_ajax', '_wpbp_nonce' );
		self::require_capability();

		self::send( SettingsTransfer::undo() );
	}
}
