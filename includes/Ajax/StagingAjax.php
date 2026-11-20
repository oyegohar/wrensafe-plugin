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
use WPBP\Utils\Paths;
use WPBP\Utils\Str;

defined( 'ABSPATH' ) || exit;

// Database access in this file is direct by design: Wrensafe's own tables (or,
// in the backup/restore engine, the site's tables being exported, imported or
// swapped) have no WordPress API, and the data must be read fresh, so it is not
// object-cached. Every query goes through $wpdb->prepare(), with table names as
// identifier placeholders (%i).
// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange

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

		if ( $password_protected ) {
			$staging_user     = sanitize_user( wp_unslash( $_POST['staging_username'] ?? '' ) );
			$staging_password = sanitize_text_field( wp_unslash( $_POST['staging_password'] ?? '' ) );

			if ( '' === $staging_user || strlen( $staging_password ) < 8 ) {
				wp_send_json_error( array( 'message' => __( 'Enter a user name and a password of at least 8 characters for the staging site.', 'wrensafe' ) ), 400 );
			}
		}

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

		if ( Str::starts_with( (string) $phase, 'push_' ) ) {
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

		$folder = self::staging_folder( $staging );
		$prefix = self::staging_prefix( $staging );

		// Only the tables of this staging site ({prefix}stg_{slug}_…) are
		// dropped, never this site's own.
		if ( '' !== $prefix ) {
			$tables = (array) $wpdb->get_col( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $prefix ) . '%' ) );

			foreach ( $tables as $table ) {
				$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', $table ) );
			}
		}

		if ( '' !== $folder && is_dir( $folder ) && ! is_link( $folder ) ) {
			FileSystem::delete_directory( $folder );
		}

		StagingSitesRepository::delete( $id );

		wp_send_json_success();
	}

	/**
	 * The staging site's folder, or '' when the stored path isn't exactly
	 * {WordPress folder}/staging-{slug}: nothing else is ever deleted.
	 *
	 * @param array<string,mixed> $staging Staging site row.
	 */
	public static function staging_folder( array $staging ): string {
		$slug = self::valid_slug( $staging );

		if ( '' === $slug ) {
			return '';
		}

		$expected = Paths::root() . '/staging-' . $slug;
		$stored   = untrailingslashit( wp_normalize_path( (string) ( $staging['path'] ?? '' ) ) );

		return $stored === $expected ? $expected : '';
	}

	/**
	 * The staging site's table prefix, or '' when the stored prefix isn't
	 * exactly {this site's prefix}stg_{slug}_.
	 *
	 * @param array<string,mixed> $staging Staging site row.
	 */
	public static function staging_prefix( array $staging ): string {
		global $wpdb;

		$slug     = self::valid_slug( $staging );
		$expected = $wpdb->prefix . 'stg_' . $slug . '_';

		return '' !== $slug && (string) ( $staging['table_prefix'] ?? '' ) === $expected ? $expected : '';
	}

	/** @param array<string,mixed> $staging Staging site row. */
	private static function valid_slug( array $staging ): string {
		$slug = (string) ( $staging['slug'] ?? '' );

		return 1 === preg_match( '/^[a-z0-9]{1,32}$/', $slug ) ? $slug : '';
	}
}
