<?php
/**
 * Settings → AI assistants → Connection: an Application Password for the chosen client, and whether an assistant has
 * called yet.
 *
 * @package WPBP\Ajax
 */

namespace WPBP\Ajax;

use WPBP\Admin\McpClients;
use WPBP\Core\Database\LogsRepository;
use WPBP\Mcp\Abilities;
use WPBP\Security\CapabilityManager;
use WPBP\Utils\Logger;

defined( 'ABSPATH' ) || exit;

class McpConnectAjax {

	/** Every handler checks the nonce itself, then calls this. */
	private static function require_capability(): void {
		if ( ! Abilities::enabled() || ! CapabilityManager::current_user_can( CapabilityManager::MANAGE_SECURITY ) ) {
			wp_send_json_error( array( 'message' => __( 'You do not have permission to do that.', 'wrensafe' ) ), 403 );
		}
	}

	/**
	 * A new Application Password for the current user, named after the client. WordPress shows it once; Wrensafe
	 * doesn't keep it.
	 */
	public static function password(): void {
		check_ajax_referer( 'wpbp_admin_ajax', '_wpbp_nonce' );
		self::require_capability();

		$user    = wp_get_current_user();
		$clients = McpClients::all();
		$key     = isset( $_POST['client'] ) ? sanitize_key( wp_unslash( $_POST['client'] ) ) : '';

		if ( ! isset( $clients[ $key ] ) || isset( $clients[ $key ]['later'] ) || ! current_user_can( 'edit_user', $user->ID ) || ! wp_is_application_passwords_available_for_user( $user ) ) {
			wp_send_json_error( array( 'message' => __( 'An Application Password can’t be made for this account here. Make one under Users → Profile.', 'wrensafe' ) ), 400 );
		}

		/* translators: %s: AI client name */
		$name = sprintf( __( 'Wrensafe AI · %s', 'wrensafe' ), $clients[ $key ]['name'] );

		if ( \WP_Application_Passwords::application_name_exists_for_user( $user->ID, $name ) ) {
			$name .= ' · ' . wp_date( 'Y-m-d H:i' );
		}

		$made = \WP_Application_Passwords::create_new_application_password( $user->ID, array( 'name' => $name ) );

		if ( is_wp_error( $made ) ) {
			wp_send_json_error( array( 'message' => $made->get_error_message() ), 400 );
		}

		Logger::info( 'ai_password_created: Application Password “' . $name . '” made for ' . $user->user_login . ' under Settings → AI assistants.' );

		wp_send_json_success(
			array(
				'password' => \WP_Application_Passwords::chunk_password( $made[0] ),
				'name'     => $name,
			)
		);
	}

	/** An assistant's newest call after the given log entry, if any. */
	public static function last_call(): void {
		check_ajax_referer( 'wpbp_admin_ajax', '_wpbp_nonce' );
		self::require_capability();

		$row = LogsRepository::first_after( isset( $_POST['after'] ) ? absint( $_POST['after'] ) : 0, 'ai_action:' );

		wp_send_json_success(
			null === $row ? array( 'found' => false ) : array(
				'found' => true,
				'text'  => \WPBP\Admin\ActivityTimeline::sentence( (string) $row['message'] ),
				'time'  => mysql2date( (string) get_option( 'time_format' ), (string) $row['created_at'] ),
			)
		);
	}
}
