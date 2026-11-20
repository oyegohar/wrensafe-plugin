<?php
/**
 * Central registrar for Wrensafe AJAX endpoints.
 *
 * Each handler is responsible for its own nonce and capability checks;
 * this class only wires `wp_ajax_*` hooks to handler methods.
 *
 * @package WPBP\Ajax
 */

namespace WPBP\Ajax;

defined( 'ABSPATH' ) || exit;

class AjaxRouter {

	/**
	 * Map of ajax action (without the wpbp_ prefix) => [ handler class, method ].
	 */
	private function routes(): array {
		return array(
			'backup_start'               => array( BackupAjax::class, 'start' ),
			'backup_poll'                => array( BackupAjax::class, 'poll' ),
			'backup_process'             => array( BackupAjax::class, 'process' ),
			'backup_cancel'              => array( BackupAjax::class, 'cancel' ),
			'restore_preview'            => array( RestoreAjax::class, 'preview' ),
			'restore_start'              => array( RestoreAjax::class, 'start' ),
			'restore_set_mode'           => array( RestoreAjax::class, 'set_mode' ),
			'restore_poll'               => array( RestoreAjax::class, 'poll' ),
			'restore_process'            => array( RestoreAjax::class, 'process' ),
			'restore_clear_locks'        => array( RestoreAjax::class, 'clear_locks' ),
			'check_backup_dir'           => array( StorageAjax::class, 'check' ),
			'remote_test'                => array( RemoteStorageAjax::class, 'test' ),
			'search_replace_start'       => array( SearchReplaceAjax::class, 'start' ),
			'search_replace_step'        => array( SearchReplaceAjax::class, 'step' ),
			'restore_cancel'             => array( RestoreAjax::class, 'cancel' ),
			'backups_delete'             => array( BackupBrowserAjax::class, 'delete' ),
			'backups_bulk_delete'        => array( BackupBrowserAjax::class, 'bulk_delete' ),
			'backups_verify'             => array( BackupBrowserAjax::class, 'verify' ),
			'backup_verify_start'        => array( BackupBrowserAjax::class, 'verify_start' ),
			'backup_verify_step'         => array( BackupBrowserAjax::class, 'verify_step' ),
			'backup_verify_result'       => array( BackupBrowserAjax::class, 'verify_result' ),
			'backups_toggle_lock'        => array( BackupBrowserAjax::class, 'toggle_lock' ),
			'backups_update_meta'        => array( BackupBrowserAjax::class, 'update_meta' ),
			'upload_init'                => array( UploadAjax::class, 'init' ),
			'upload_status'              => array( UploadAjax::class, 'status' ),
			'upload_chunk'               => array( UploadAjax::class, 'chunk' ),
			'upload_cancel'              => array( UploadAjax::class, 'cancel' ),
			'upload_finalize'            => array( UploadAjax::class, 'finalize' ),
			'upload_unlock'              => array( UploadAjax::class, 'unlock' ),
			'import_url_fetch'           => array( ImportSourceAjax::class, 'url_fetch' ),
			'import_server_path'         => array( ImportSourceAjax::class, 'server_path' ),
			'foreign_analyze'            => array( ForeignImportAjax::class, 'analyze' ),
			'foreign_convert_start'      => array( ForeignImportAjax::class, 'convert_start' ),
			'foreign_convert_process'    => array( ForeignImportAjax::class, 'convert_process' ),
			'foreign_remove_file'        => array( ForeignImportAjax::class, 'remove_file' ),
			'foreign_discard'            => array( ForeignImportAjax::class, 'discard' ),
			'rollback_delete'            => array( RollbackAjax::class, 'delete' ),
			'rollback_bulk_delete'       => array( RollbackAjax::class, 'bulk_delete' ),
			'schedule_save'              => array( ScheduleAjax::class, 'save' ),
			'schedule_delete'            => array( ScheduleAjax::class, 'delete' ),
			'schedule_toggle'            => array( ScheduleAjax::class, 'toggle' ),
			'schedule_run_now'           => array( ScheduleAjax::class, 'run_now' ),
			'migration_generate_key'     => array( MigrationAjax::class, 'generate_key' ),
			'migration_start_transfer'   => array( MigrationAjax::class, 'start_transfer' ),
			'migration_process_transfer' => array( MigrationAjax::class, 'process_transfer' ),
			'staging_create'             => array( StagingAjax::class, 'create' ),
			'staging_process'            => array( StagingAjax::class, 'process' ),
			'staging_push_to_live'       => array( StagingAjax::class, 'push_to_live' ),
			'staging_delete'             => array( StagingAjax::class, 'delete' ),
			'malware_scan'               => array( MalwareScanAjax::class, 'run' ),
			'backup_pause'               => array( BackupAjax::class, 'pause' ),
			'backup_resume'              => array( BackupAjax::class, 'resume' ),
			'backup_compare'             => array( BackupCompareAjax::class, 'compare' ),
			'migration_assistant_scan'   => array( MigrationAssistantAjax::class, 'scan' ),
			'migration_templates'        => array( MigrationAssistantAjax::class, 'templates' ),
			'safe_update_start'          => array( SafeUpdateAjax::class, 'start' ),
			'safe_update_process'        => array( SafeUpdateAjax::class, 'process' ),
			'safe_update_status'         => array( SafeUpdateAjax::class, 'status' ),
			'safe_update_cancel'         => array( SafeUpdateAjax::class, 'cancel' ),
			'safe_update_decide'         => array( SafeUpdateAjax::class, 'decide' ),
			'safe_update_rollback'       => array( SafeUpdateAjax::class, 'rollback' ),
			'safe_update_quick'          => array( SafeUpdateAjax::class, 'quick' ),
			'safe_update_refresh'        => array( SafeUpdateAjax::class, 'refresh' ),
		);
	}

	/**
	 * check_ajax_referer() normally answers a failed nonce with a bare "-1".
	 * For Wrensafe's own actions, answer with a readable JSON message
	 * instead, so the admin UI can tell the user to refresh the page.
	 *
	 * @param string    $action Nonce action.
	 * @param false|int $result Verification result.
	 */
	public static function nonce_failure_response( $action, $result ): void {
		if ( false === $result && is_string( $action ) && 0 === strpos( $action, 'wpbp_' ) ) {
			wp_send_json_error( array( 'message' => __( 'Security check failed. Please refresh the page and try again.', 'wrensafe' ) ), 403 );
		}
	}

	public function register_hooks(): void {
		add_action( 'check_ajax_referer', array( self::class, 'nonce_failure_response' ), 10, 2 );

		/**
		 * Filters the admin-ajax routes (action suffix => callback). Every
		 * route is registered as wp_ajax_wpbp_{suffix}; each callback checks
		 * its own nonce and capability. For add-ons.
		 *
		 * @param array<string,callable> $routes
		 */
		$routes = (array) apply_filters( 'wpbp_ajax_routes', $this->routes() );

		foreach ( $routes as $action => $callback ) {
			add_action( 'wp_ajax_wpbp_' . $action, $callback );
		}

		// The one logged-out route: continuing a restore after the database
		// swap ended the operator's session. RestoreAjax::process() accepts
		// these requests ONLY with the job's 256-bit resume key.
		add_action( 'wp_ajax_nopriv_wpbp_restore_process', array( RestoreAjax::class, 'process' ) );
	}
}
