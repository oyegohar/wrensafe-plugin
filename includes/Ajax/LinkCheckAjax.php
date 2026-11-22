<?php
/**
 * AJAX endpoints of the link and media check (Wrensafe → Fix Links, and the
 * notice after a restore). Each checks the admin nonce and the permission to
 * restore backups, the same as Search & Replace: the fix changes the database.
 *
 * @package WPBP\Ajax
 */

namespace WPBP\Ajax;

use WPBP\Admin\Pages\FixLinksPage;
use WPBP\Restore\LinkCheck;
use WPBP\Security\CapabilityManager;

defined( 'ABSPATH' ) || exit;

class LinkCheckAjax {

	/** @var bool Whether the results are drawn for the notice (short) rather than the screen. */
	private static $compact = false;

	/** Every handler checks the nonce itself, then calls this. */
	private static function require_capability(): void {
		if ( ! CapabilityManager::current_user_can( CapabilityManager::RESTORE_BACKUPS ) ) {
			wp_send_json_error( array( 'message' => __( 'You do not have permission to check or fix links.', 'wrensafe' ) ), 403 );
		}
	}

	/** Start a check with the old addresses on the screen (filled in after a restore, and editable). */
	public static function start(): void {
		check_ajax_referer( 'wpbp_admin_ajax', '_wpbp_nonce' );
		self::require_capability();

		self::$compact = isset( $_POST['compact'] ) && '1' === sanitize_key( wp_unslash( $_POST['compact'] ) );

		$typed = isset( $_POST['addresses'] ) ? sanitize_textarea_field( wp_unslash( $_POST['addresses'] ) ) : '';
		$state = LinkCheck::state();
		$list  = (array) preg_split( '/[\s,]+/', $typed, -1, PREG_SPLIT_NO_EMPTY );

		$started = LinkCheck::start( $list, (int) ( $state['restore'] ?? 0 ) );

		if ( is_wp_error( $started ) ) {
			wp_send_json_error( array( 'message' => $started->get_error_message() ) );
		}

		self::send( $started );
	}

	/** One step of the check or of the fix. */
	public static function step(): void {
		check_ajax_referer( 'wpbp_admin_ajax', '_wpbp_nonce' );
		self::require_capability();

		self::$compact = isset( $_POST['compact'] ) && '1' === sanitize_key( wp_unslash( $_POST['compact'] ) );

		$result = LinkCheck::step( isset( $_POST['token'] ) ? sanitize_key( wp_unslash( $_POST['token'] ) ) : '' );

		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ) );
		}

		self::send_report( $result );
	}

	public static function fix(): void {
		check_ajax_referer( 'wpbp_admin_ajax', '_wpbp_nonce' );
		self::require_capability();

		self::$compact = isset( $_POST['compact'] ) && '1' === sanitize_key( wp_unslash( $_POST['compact'] ) );

		$result = LinkCheck::start_fix();

		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ) );
		}

		self::send_report( $result );
	}

	public static function dismiss(): void {
		check_ajax_referer( 'wpbp_admin_ajax', '_wpbp_nonce' );
		self::require_capability();

		LinkCheck::dismiss();

		wp_send_json_success();
	}

	/** @param array<string,mixed> $state */
	private static function send( array $state ): void {
		self::send_report( LinkCheck::report( $state ) );
	}

	/** The report, with its results drawn for the screen (escaped there). */
	private static function send_report( array $report ): void {
		$report['html'] = FixLinksPage::results_html( $report, self::$compact );

		wp_send_json_success( $report );
	}
}
