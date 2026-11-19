<?php
/**
 * AJAX endpoints for the Direct Migration page: generating a key on the
 * destination site, and starting/advancing a transfer on the source site.
 *
 * @package WPBP\Ajax
 */

namespace WPBP\Ajax;

use WPBP\Core\LimitsManager;
use WPBP\Core\Settings;
use WPBP\Migration\MigrationKey;
use WPBP\Migration\MigrationTransferJob;
use WPBP\Migration\TransferClient;
use WPBP\Security\CapabilityManager;
use WPBP\Utils\Str;

defined( 'ABSPATH' ) || exit;

class MigrationAjax {

	private static function require_capability(): void {
		if ( ! CapabilityManager::current_user_can( CapabilityManager::MANAGE_DIRECT_MIGRATION ) ) {
			wp_send_json_error( array( 'message' => __( 'You do not have permission to manage migrations.', 'wrensafe' ) ), 403 );
		}
	}

	/**
	 * Destination side: generate a one-time migration key to hand to the source site.
	 */
	public static function generate_key(): void {
		check_ajax_referer( 'wpbp_admin_ajax', '_wpbp_nonce' );
		self::require_capability();

		if ( Settings::get( 'migration_require_https', true ) && ! is_ssl() ) {
			wp_send_json_error( array( 'message' => __( 'This site accepts direct migrations only over HTTPS. Turn on HTTPS for this site, or turn off the HTTPS requirement in Wrensafe → Settings → Direct Migration.', 'wrensafe' ) ), 400 );
		}

		// The page offers 15, 30 and 60 minutes; anything else is kept between
		// 5 minutes and 7 days.
		$minutes = isset( $_POST['expiry_minutes'] ) ? min( MigrationKey::MAX_EXPIRY_MINUTES, max( MigrationKey::MIN_EXPIRY_MINUTES, absint( $_POST['expiry_minutes'] ) ) ) : 30;

		// "Only accept this key from the IP address that uses it first": the
		// receiving site records the sending server's address on the key's
		// first accepted request (MigrationKey::verify()). The browser's
		// address here is not the sending server's.
		$restrict_ip = empty( $_POST['restrict_ip'] ) ? '' : MigrationKey::FIRST_SENDER;

		$result = ( new MigrationKey() )->generate( $minutes, $restrict_ip );

		\WPBP\Utils\Logger::info( 'Direct migration key generated (expires in ' . $minutes . ' min' . ( $restrict_ip ? ', locked to the first sending server' : '' ) . ') by user #' . get_current_user_id() . '.' );

		wp_send_json_success(
			array(
				'token'       => $result['token'],
				'site_url'    => home_url(),
				'expires_in'  => $minutes,
				'expiry_note' => sprintf(
					/* translators: %d: number of minutes */
					_n( 'This key expires in %d minute and can be used only once.', 'This key expires in %d minutes and can be used only once.', $minutes, 'wrensafe' ),
					$minutes
				),
			)
		);
	}

	/**
	 * Source side: start pushing a local backup to a destination site.
	 */
	public static function start_transfer(): void {
		check_ajax_referer( 'wpbp_admin_ajax', '_wpbp_nonce' );
		self::require_capability();

		if ( ! LimitsManager::is_within_limit( 'direct_migrations_per_month', MigrationTransferJob::count_completed_this_month() ) ) {
			wp_send_json_error( array( 'message' => sprintf( /* translators: %d: maximum number of direct migrations per month */ __( 'This site allows at most %d direct migrations per month.', 'wrensafe' ), LimitsManager::get_limit( 'direct_migrations_per_month' ) ) ), 400 );
		}

		$backup_id       = isset( $_POST['backup_id'] ) ? absint( $_POST['backup_id'] ) : 0;
		$destination_url = isset( $_POST['destination_url'] ) ? sanitize_url( wp_unslash( $_POST['destination_url'] ) ) : '';
		// Keys are lower-case hex; a key pasted in capitals still works.
		$token = isset( $_POST['token'] ) ? strtolower( sanitize_text_field( wp_unslash( $_POST['token'] ) ) ) : '';

		if ( ! $backup_id || '' === $destination_url || '' === $token ) {
			wp_send_json_error( array( 'message' => __( 'Backup, destination URL, and migration key are all required.', 'wrensafe' ) ), 400 );
		}

		// Ask the receiving site first: is the key valid, and does it accept
		// this connection (HTTP or HTTPS)? Explains a problem before anything is sent.
		$preflight = ( new TransferClient() )->preflight( $destination_url, $token );

		if ( ! $preflight['ok'] ) {
			wp_send_json_error( array( 'message' => $preflight['message'] ), 400 );
		}

		$job = MigrationTransferJob::create(
			array(
				'backup_id'       => $backup_id,
				'destination_url' => $destination_url,
				'token'           => $token,
				'verify_ssl'      => Str::starts_with( strtolower( $destination_url ), 'https://' ),
				'rest_style'      => $preflight['rest_style'],
			)
		);

		wp_send_json_success( array_merge( $job->to_array(), array( 'preflight' => $preflight['message'] ) ) );
	}

	public static function process_transfer(): void {
		check_ajax_referer( 'wpbp_admin_ajax', '_wpbp_nonce' );
		self::require_capability();

		$job_id = isset( $_POST['job_id'] ) ? absint( $_POST['job_id'] ) : 0;
		$job    = $job_id ? MigrationTransferJob::get( $job_id ) : null;

		if ( null === $job ) {
			wp_send_json_error( array( 'message' => __( 'Migration job not found.', 'wrensafe' ) ), 404 );
		}

		if ( in_array( $job->status(), array( MigrationTransferJob::STATUS_COMPLETED, MigrationTransferJob::STATUS_FAILED, MigrationTransferJob::STATUS_CANCELLED ), true ) ) {
			wp_send_json_success( $job->to_array() );
		}

		( new TransferClient() )->process_step( $job );

		wp_send_json_success( $job->to_array() );
	}
}
