<?php
/**
 * AJAX endpoint for the Backup Compare tool.
 *
 * @package WPBP\Ajax
 */

namespace WPBP\Ajax;

use WPBP\Backup\BackupCompare;
use WPBP\Security\CapabilityManager;

defined( 'ABSPATH' ) || exit;

class BackupCompareAjax {

	public static function compare(): void {
		check_ajax_referer( 'wpbp_admin_ajax', '_wpbp_nonce' );

		if ( ! CapabilityManager::current_user_can( CapabilityManager::VIEW_BACKUPS ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'wrensafe' ) ), 403 );
		}

		$id_a = isset( $_POST['backup_id_a'] ) ? absint( $_POST['backup_id_a'] ) : 0;
		$id_b = isset( $_POST['backup_id_b'] ) ? absint( $_POST['backup_id_b'] ) : 0;

		if ( ! $id_a || ! $id_b || $id_a === $id_b ) {
			wp_send_json_error( array( 'message' => __( 'Please select two different backups to compare.', 'wrensafe' ) ), 400 );
		}

		$result = ( new BackupCompare() )->compare( $id_a, $id_b );

		if ( '' !== $result['error'] ) {
			wp_send_json_error( array( 'message' => $result['error'] ), 404 );
		}

		wp_send_json_success( $result );
	}
}
