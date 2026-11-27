<?php
/**
 * Backup Browser → Restore test: start, step and stop a RestoreTest.
 *
 * @package WPBP\Ajax
 */

namespace WPBP\Ajax;

use WPBP\Restore\RestoreTest;
use WPBP\Security\CapabilityManager;
use WPBP\Utils\Errors;

defined( 'ABSPATH' ) || exit;

class RestoreTestAjax {

	/** Every handler checks the nonce itself, then calls this. */
	private static function require_capability(): void {
		if ( ! CapabilityManager::current_user_can( CapabilityManager::RESTORE_BACKUPS ) ) {
			wp_send_json_error( array( 'message' => __( 'You do not have permission to test restores.', 'wrensafe' ) ), 403 );
		}
	}

	public static function start(): void {
		check_ajax_referer( 'wpbp_admin_ajax', '_wpbp_nonce' );
		self::require_capability();

		$backup_id = isset( $_POST['backup_id'] ) ? absint( $_POST['backup_id'] ) : 0;
		$password  = isset( $_POST['encryption_password'] ) ? (string) filter_var( wp_unslash( $_POST['encryption_password'] ), FILTER_UNSAFE_RAW ) : ''; // Kept exactly as typed (never sanitize_text_field()); never stored, logged or output.

		\WPBP\Admin\NewFeatures::seen_now( 'restore-test' );

		try {
			wp_send_json_success( RestoreTest::start( $backup_id, $password ) );
		} catch ( \Throwable $e ) {
			wp_send_json_error( array( 'message' => Errors::text( $e ) ) );
		}
	}

	public static function step(): void {
		check_ajax_referer( 'wpbp_admin_ajax', '_wpbp_nonce' );
		self::require_capability();

		$state = RestoreTest::step( isset( $_POST['job_id'] ) ? absint( $_POST['job_id'] ) : 0 );

		if ( null === $state ) {
			wp_send_json_error( array( 'message' => __( 'This restore test was not found. Start it again.', 'wrensafe' ) ), 404 );
		}

		wp_send_json_success( $state );
	}

	public static function cancel(): void {
		check_ajax_referer( 'wpbp_admin_ajax', '_wpbp_nonce' );
		self::require_capability();

		RestoreTest::cancel( isset( $_POST['job_id'] ) ? absint( $_POST['job_id'] ) : 0 );
		wp_send_json_success();
	}
}
