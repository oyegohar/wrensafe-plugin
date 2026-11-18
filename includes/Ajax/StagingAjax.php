<?php
/**
 * AJAX endpoints for the Staging page: create, process (chunked step),
 * push to live, and delete.
 *
 * @package WPBP\Ajax
 */

namespace WPBP\Ajax;

use WPBP\Core\Database\StagingSitesRepository;
use WPBP\Core\LimitsManager;
use WPBP\Security\CapabilityManager;
use WPBP\Staging\PushToLive;
use WPBP\Staging\StagingJob;
use WPBP\Staging\StagingManager;
use WPBP\Utils\FileSystem;

defined( 'ABSPATH' ) || exit;

// Database access in this file is direct by design: Wrensafe's own tables (or,
// in the backup/restore engine, the site's tables being exported, imported or
// swapped) have no WordPress API, and the data must be read fresh, so it is not
// object-cached. Values go through $wpdb->prepare(); table names come from
// $wpdb->prefix with fixed suffixes and are escaped or quoted as identifiers.
// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

class StagingAjax {

	private static function require_capability(): void {
		if ( ! CapabilityManager::current_user_can( CapabilityManager::MANAGE_STAGING ) ) {
			wp_send_json_error( array( 'message' => __( 'You do not have permission to manage staging sites.', 'wrensafe' ) ), 403 );
		}
	}

	public static function create(): void {
		check_ajax_referer( 'wpbp_admin_ajax', '_wpbp_nonce' );
		self::require_capability();

		if ( ! LimitsManager::is_within_limit( 'staging_sites_count', StagingSitesRepository::count_active() ) ) {
			wp_send_json_error( array( 'message' => sprintf( /* translators: %d: maximum number of staging sites */ __( 'This site allows at most %d staging sites. Delete an existing one first.', 'wrensafe' ), LimitsManager::get_limit( 'staging_sites_count' ) ) ), 400 );
		}

		$layout_problem = \WPBP\Staging\StagingCreator::layout_problem();

		if ( '' !== $layout_problem ) {
			wp_send_json_error( array( 'message' => $layout_problem ), 400 );
		}

		$password_protected = ! empty( $_POST['password_protected'] );

		$job = StagingJob::create(
			array(
				'phase'              => 'init',
				'exclude_users'      => ! empty( $_POST['exclude_users'] ),
				'exclude_comments'   => ! empty( $_POST['exclude_comments'] ),
				'exclude_orders'     => ! empty( $_POST['exclude_orders'] ),
				'password_protected' => $password_protected,
				'staging_username'   => $password_protected ? sanitize_user( wp_unslash( $_POST['staging_username'] ?? '' ) ) : '',
				'staging_password'   => $password_protected ? sanitize_text_field( wp_unslash( $_POST['staging_password'] ?? '' ) ) : '',
			)
		);

		wp_send_json_success( $job->to_array() );
	}

	public static function process(): void {
		check_ajax_referer( 'wpbp_admin_ajax', '_wpbp_nonce' );
		self::require_capability();

		$job_id = isset( $_POST['job_id'] ) ? absint( $_POST['job_id'] ) : 0;
		$job    = $job_id ? StagingJob::get( $job_id ) : null;

		if ( null === $job ) {
			wp_send_json_error( array( 'message' => __( 'Staging job not found.', 'wrensafe' ) ), 404 );
		}

		if ( in_array( $job->status(), array( StagingJob::STATUS_COMPLETED, StagingJob::STATUS_FAILED, StagingJob::STATUS_CANCELLED ), true ) ) {
			wp_send_json_success( $job->to_array() );
		}

		$phase = $job->payload()['phase'] ?? '';

		if ( str_starts_with( $phase, 'push_' ) ) {
			( new PushToLive() )->process_step( $job );
		} else {
			( new StagingManager() )->process_step( $job );
		}

		wp_send_json_success( $job->to_array() );
	}

	public static function push_to_live(): void {
		check_ajax_referer( 'wpbp_admin_ajax', '_wpbp_nonce' );
		self::require_capability();

		$staging_site_id = isset( $_POST['staging_site_id'] ) ? absint( $_POST['staging_site_id'] ) : 0;
		$staging         = $staging_site_id ? StagingSitesRepository::find( $staging_site_id ) : null;

		if ( null === $staging ) {
			wp_send_json_error( array( 'message' => __( 'Staging site not found.', 'wrensafe' ) ), 404 );
		}

		$job = StagingJob::create(
			array(
				'phase'            => 'push_init',
				'staging_site_id'  => $staging_site_id,
				'exclude_users'    => ! empty( $_POST['exclude_users'] ),
				'exclude_comments' => ! empty( $_POST['exclude_comments'] ),
				'exclude_orders'   => ! empty( $_POST['exclude_orders'] ),
			)
		);

		wp_send_json_success( $job->to_array() );
	}

	public static function delete(): void {
		check_ajax_referer( 'wpbp_admin_ajax', '_wpbp_nonce' );
		self::require_capability();

		global $wpdb;

		$id      = isset( $_POST['staging_site_id'] ) ? absint( $_POST['staging_site_id'] ) : 0;
		$staging = $id ? StagingSitesRepository::find( $id ) : null;

		if ( null === $staging ) {
			wp_send_json_error( array( 'message' => __( 'Staging site not found.', 'wrensafe' ) ), 404 );
		}

		$tables = $wpdb->get_col( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $staging['table_prefix'] ) . '%' ) );
		foreach ( $tables as $table ) {
			$wpdb->query( 'DROP TABLE IF EXISTS `' . esc_sql( $table ) . '`' ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.SchemaChange -- drops the deleted staging site's own tables (name from the staging prefix, escaped).
		}

		if ( is_dir( $staging['path'] ) ) {
			FileSystem::delete_directory( $staging['path'] );
		}

		StagingSitesRepository::delete( $id );

		wp_send_json_success();
	}
}
