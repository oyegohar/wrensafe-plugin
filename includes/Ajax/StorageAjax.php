<?php
/**
 * Settings → Backup: "Check folder" for the backup folder field.
 *
 * @package WPBP\Ajax
 */

namespace WPBP\Ajax;

use WPBP\Security\CapabilityManager;
use WPBP\Utils\FileSystem;

defined( 'ABSPATH' ) || exit;

/**
 * Checks a folder without changing anything (the folder is only created
 * and used when the settings are saved).
 */
class StorageAjax {

	public static function check(): void {
		check_ajax_referer( 'wpbp_admin_ajax', '_wpbp_nonce' );

		if ( ! CapabilityManager::current_user_can( CapabilityManager::MANAGE_SETTINGS ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'wrensafe' ) ), 403 );
		}

		$path = isset( $_POST['path'] ) ? sanitize_text_field( wp_unslash( $_POST['path'] ) ) : '';

		if ( '' === trim( $path ) ) {
			$path = FileSystem::default_storage_dir();
		}

		$check = FileSystem::check_storage_dir( $path, false );

		if ( $check['ok'] && $check['inside_site'] ) {
			$check['message'] .= ' ' . __( 'It is inside the website folder, so Wrensafe blocks web access to it (Apache and LiteSpeed; Nginx needs a server rule). A folder outside the website folder is safest.', 'wrensafe' );
		}

		wp_send_json_success( $check );
	}
}
