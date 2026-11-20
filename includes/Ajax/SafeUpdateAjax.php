<?php
/**
 * AJAX endpoints of Safe Updates: start a run, work on it, stop it, decide
 * on a held-back update, roll back a finished update, and Quick Update's
 * "clear the cache" flag.
 *
 * Every endpoint checks the admin nonce and WordPress's update_plugins
 * capability (which DISALLOW_FILE_MODS also takes away), and the
 * capability of each update type it touches (update_themes for themes).
 *
 * @package WPBP\Ajax
 */

namespace WPBP\Ajax;

use WPBP\Updates\PluginUpdateType;
use WPBP\Updates\SafeUpdateJob;
use WPBP\Updates\SafeUpdateManager;
use WPBP\Updates\SafeUpdates;
use WPBP\Updates\UpdateTypes;

defined( 'ABSPATH' ) || exit;

class SafeUpdateAjax {

	/** The capability every endpoint needs (after the nonce). */
	private static function require_capability(): void {
		if ( ! current_user_can( 'update_plugins' ) ) {
			wp_send_json_error( array( 'message' => __( 'You don’t have permission to update plugins.', 'wrensafe' ) ), 403 );
		}
	}

	/** The current user may update items of this type. */
	private static function require_type( string $type ): void {
		$handler = UpdateTypes::get( $type );

		if ( null === $handler ) {
			wp_send_json_error( array( 'message' => __( 'This kind of update isn’t available. Reload the page.', 'wrensafe' ) ), 400 );
		}

		if ( ! current_user_can( $handler->capability() ) ) {
			wp_send_json_error( array( 'message' => __( 'You don’t have permission to install this update.', 'wrensafe' ) ), 403 );
		}
	}

	/** A yes/no value sent by the script ("1", "true", "0", "false"). */
	private static function is_yes( string $value ): bool {
		return in_array( $value, array( '1', 'true', 'yes', 'on' ), true );
	}

	private static function find_job( int $id ): SafeUpdateJob {
		$job = $id ? SafeUpdateJob::get( $id ) : null;

		if ( null === $job ) {
			wp_send_json_error( array( 'message' => __( 'This update run wasn’t found. Reload the page.', 'wrensafe' ) ), 404 );
		}

		return $job;
	}

	/** The run's report, with the run's secret for this script's next requests (see the guard). */
	private static function send_report( SafeUpdateJob $job ): void {
		$job                = SafeUpdateJob::get( $job->id() ) ?? $job;
		$report             = SafeUpdateManager::report( $job );
		$report['guardKey'] = $job->is_finished() ? '' : SafeUpdates::guard_key();

		wp_send_json_success( $report );
	}

	public static function start(): void {
		check_ajax_referer( 'wpbp_admin_ajax', '_wpbp_nonce' );
		self::require_capability();

		$items  = array();
		$typed  = isset( $_POST['items'] ) ? map_deep( wp_unslash( $_POST['items'] ), 'sanitize_text_field' ) : array();
		$legacy = isset( $_POST['plugins'] ) ? array_map( 'sanitize_text_field', (array) wp_unslash( $_POST['plugins'] ) ) : array();
		$mode   = isset( $_POST['mode'] ) ? sanitize_key( wp_unslash( $_POST['mode'] ) ) : SafeUpdates::default_mode();
		$order  = isset( $_POST['order'] ) ? sanitize_key( wp_unslash( $_POST['order'] ) ) : SafeUpdates::default_order();
		$cache  = isset( $_POST['clear_cache'] ) && self::is_yes( sanitize_key( wp_unslash( $_POST['clear_cache'] ) ) );
		$source = isset( $_POST['source'] ) ? sanitize_key( wp_unslash( $_POST['source'] ) ) : 'page';

		foreach ( is_array( $typed ) ? $typed : array() as $item ) {
			if ( is_array( $item ) && ! empty( $item['id'] ) ) {
				$type    = sanitize_key( (string) ( $item['type'] ?? '' ) );
				$items[] = array(
					'type' => '' !== $type ? $type : PluginUpdateType::KEY,
					'id'   => (string) $item['id'],
				);
			}
		}

		// The list of plugin basenames sent before update types existed.
		foreach ( $legacy as $plugin ) {
			$items[] = array(
				'type' => PluginUpdateType::KEY,
				'id'   => $plugin,
			);
		}

		if ( empty( $items ) ) {
			wp_send_json_error( array( 'message' => __( 'Select at least one update to install.', 'wrensafe' ) ), 400 );
		}

		foreach ( array_unique( wp_list_pluck( $items, 'type' ) ) as $type ) {
			self::require_type( $type );
		}

		$job = SafeUpdateManager::start( $items, $mode, $order, $cache, $source );

		if ( is_wp_error( $job ) ) {
			wp_send_json_error( array( 'message' => $job->get_error_message() ), 409 );
		}

		self::send_report( $job );
	}

	public static function process(): void {
		check_ajax_referer( 'wpbp_admin_ajax', '_wpbp_nonce' );
		self::require_capability();

		$job = self::find_job( isset( $_POST['job_id'] ) ? absint( $_POST['job_id'] ) : 0 );

		SafeUpdateManager::process( $job, true, 15 );

		self::send_report( $job );
	}

	public static function status(): void {
		check_ajax_referer( 'wpbp_admin_ajax', '_wpbp_nonce' );
		self::require_capability();

		self::send_report( self::find_job( isset( $_POST['job_id'] ) ? absint( $_POST['job_id'] ) : 0 ) );
	}

	public static function cancel(): void {
		check_ajax_referer( 'wpbp_admin_ajax', '_wpbp_nonce' );
		self::require_capability();

		$job = self::find_job( isset( $_POST['job_id'] ) ? absint( $_POST['job_id'] ) : 0 );

		if ( ! $job->is_finished() ) {
			SafeUpdateManager::request_cancel( $job );
		}

		self::send_report( $job );
	}

	public static function decide(): void {
		check_ajax_referer( 'wpbp_admin_ajax', '_wpbp_nonce' );
		self::require_capability();

		$job      = self::find_job( isset( $_POST['job_id'] ) ? absint( $_POST['job_id'] ) : 0 );
		$type     = isset( $_POST['type'] ) ? sanitize_key( wp_unslash( $_POST['type'] ) ) : '';
		$id       = isset( $_POST['id'] ) ? sanitize_text_field( wp_unslash( $_POST['id'] ) ) : '';
		$plugin   = isset( $_POST['plugin'] ) ? sanitize_text_field( wp_unslash( $_POST['plugin'] ) ) : '';
		$decision = isset( $_POST['decision'] ) && 'rollback' === sanitize_key( wp_unslash( $_POST['decision'] ) ) ? 'rollback' : 'keep';
		$type     = '' !== $type ? $type : PluginUpdateType::KEY;

		self::require_type( $type );

		$result = SafeUpdateManager::decide( $job, '' !== $id ? $id : $plugin, $decision, $type );

		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ), 409 );
		}

		self::send_report( $job );
	}

	public static function rollback(): void {
		check_ajax_referer( 'wpbp_admin_ajax', '_wpbp_nonce' );
		self::require_capability();

		$job    = self::find_job( isset( $_POST['job_id'] ) ? absint( $_POST['job_id'] ) : 0 );
		$type   = isset( $_POST['type'] ) ? sanitize_key( wp_unslash( $_POST['type'] ) ) : '';
		$id     = isset( $_POST['id'] ) ? sanitize_text_field( wp_unslash( $_POST['id'] ) ) : '';
		$plugin = isset( $_POST['plugin'] ) ? sanitize_text_field( wp_unslash( $_POST['plugin'] ) ) : '';
		$type   = '' !== $type ? $type : PluginUpdateType::KEY;

		self::require_type( $type );

		$result = SafeUpdateManager::roll_back_later( $job, '' !== $id ? $id : $plugin, $type );

		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ), 409 );
		}

		self::send_report( $job );
	}

	/**
	 * Quick Update runs WordPress's own update; this remembers for ten
	 * minutes that this user asked to clear caches afterwards.
	 */
	public static function quick(): void {
		check_ajax_referer( 'wpbp_admin_ajax', '_wpbp_nonce' );
		self::require_capability();

		$key = SafeUpdates::QUICK_CACHE_FLAG . get_current_user_id();

		if ( isset( $_POST['clear_cache'] ) && self::is_yes( sanitize_key( wp_unslash( $_POST['clear_cache'] ) ) ) ) {
			set_transient( $key, 1, 10 * MINUTE_IN_SECONDS );
		} else {
			delete_transient( $key );
		}

		wp_send_json_success();
	}

	/** Ask WordPress.org for updates now (the Safe Updates page's "Check again"). */
	public static function refresh(): void {
		check_ajax_referer( 'wpbp_admin_ajax', '_wpbp_nonce' );
		self::require_capability();

		delete_site_transient( 'update_plugins' );
		wp_update_plugins();

		/**
		 * Fires when the Safe Updates page asks WordPress for updates again
		 * ("Check again"). An update type added by another plugin refreshes
		 * its own list here.
		 */
		do_action( 'wpbp_safe_update_refresh' );

		$count = 0;

		foreach ( SafeUpdateManager::available_by_type() as $list ) {
			$count += count( $list );
		}

		wp_send_json_success( array( 'count' => $count ) );
	}
}
