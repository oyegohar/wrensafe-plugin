<?php
/**
 * AJAX endpoints for the Migration Assistant wizard.
 *
 * @package WPBP\Ajax
 */

namespace WPBP\Ajax;

use WPBP\Migration\MigrationAssistant;
use WPBP\Migration\MigrationTemplates;
use WPBP\Security\CapabilityManager;

defined( 'ABSPATH' ) || exit;

class MigrationAssistantAjax {

	/**
	 * Run the site scan and return recommendation data.
	 */
	public static function scan(): void {
		check_ajax_referer( 'wpbp_admin_ajax', '_wpbp_nonce' );

		if ( ! CapabilityManager::current_user_can( CapabilityManager::RESTORE_BACKUPS ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'wrensafe' ) ), 403 );
		}

		\WPBP\Utils\Logger::info( 'Migration Assistant scan started by user #' . get_current_user_id() . '.' );

		$assistant = new MigrationAssistant();
		$scan      = $assistant->scan();
		$recommend = $assistant->recommend( $scan );

		// Persist so the result survives a page reload until the next re-scan.
		$result = MigrationAssistant::save_latest_result( $scan, $recommend );

		\WPBP\Utils\Logger::info( 'Migration Assistant scan completed (score ' . ( $recommend['score'] ?? '?' ) . '/100, method ' . ( $recommend['method'] ?? '?' ) . ').' );

		wp_send_json_success(
			array(
				'scan'       => $scan,
				'recommend'  => $recommend,
				'scanned_at' => $result['scanned_at'],
			)
		);
	}

	/**
	 * Return the list of available migration templates.
	 */
	public static function templates(): void {
		check_ajax_referer( 'wpbp_admin_ajax', '_wpbp_nonce' );

		if ( ! CapabilityManager::current_user_can( CapabilityManager::RESTORE_BACKUPS ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'wrensafe' ) ), 403 );
		}

		wp_send_json_success( array( 'templates' => array_values( MigrationTemplates::all() ) ) );
	}
}
