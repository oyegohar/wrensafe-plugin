<?php
/**
 * Export handlers for the Activity Logs page: "Export Logs" (recent log
 * entries as JSON) and "Export Debug Bundle" (logs + environment + safe
 * settings/jobs/cloud summary for support).
 *
 * Both run through admin-post (so they can stream a downloadable file) with a
 * nonce + capability check. Secrets are never included — settings values whose
 * keys look sensitive, cloud account emails, and absolute paths are masked.
 *
 * @package WPBP\Admin
 */

namespace WPBP\Admin;

use WPBP\Core\Database\BackupsRepository;
use WPBP\Core\Database\JobsRepository;
use WPBP\Core\Settings;
use WPBP\Security\CapabilityManager;
use WPBP\Utils\Logger;
use WPBP\Utils\ServerInfo;

defined( 'ABSPATH' ) || exit;

class LogsExport {

	public function register_hooks(): void {
		add_action( 'admin_post_wpbp_export_logs', array( $this, 'export_logs' ) );
		add_action( 'admin_post_wpbp_export_debug_bundle', array( $this, 'export_debug_bundle' ) );
		add_action( 'admin_post_wpbp_clear_logs', array( $this, 'clear_logs' ) );
	}

	/**
	 * Clear every Activity Log entry (Issue 09). Nonce + capability gated;
	 * the button in LogsPage requires a JS confirmation first.
	 */
	public function clear_logs(): void {
		$this->guard();

		// Deleting the audit trail needs more than read access to it.
		if ( ! CapabilityManager::current_user_can( CapabilityManager::MANAGE_SETTINGS ) ) {
			wp_die( esc_html__( 'You do not have permission to clear the activity logs.', 'wrensafe' ), 403 );
		}

		\WPBP\Core\Database\LogsRepository::clear();
		Logger::warning( 'Activity logs cleared by user #' . get_current_user_id() . '.' );

		wp_safe_redirect( admin_url( 'admin.php?page=wrensafe-logs&cleared=1' ) );
		exit;
	}

	private function guard(): void {
		check_admin_referer( 'wpbp_logs_export', '_wpbp_nonce' );

		if ( ! CapabilityManager::current_user_can( CapabilityManager::VIEW_LOGS ) ) {
			wp_die( esc_html__( 'You do not have permission to export logs.', 'wrensafe' ), 403 );
		}
	}

	public function export_logs(): void {
		$this->guard();

		$payload = array(
			'exported_at' => gmdate( 'c' ),
			'site_url'    => home_url(),
			'entries'     => Logger::get_recent_entries( 1000 ),
		);

		$this->stream_json( 'wpbp-activity-logs-' . gmdate( 'Ymd-His' ) . '.json', $payload );
	}

	public function export_debug_bundle(): void {
		$this->guard();

		$bundle = array(
			'exported_at'       => gmdate( 'c' ),
			'site_url'          => home_url(),
			'plugin_version'    => defined( 'WPBP_VERSION' ) ? WPBP_VERSION : '',
			'wordpress_version' => ServerInfo::wordpress_version(),
			'php_version'       => ServerInfo::php_version(),
			'environment'       => array(
				'memory_limit'       => ServerInfo::memory_limit(),
				'max_execution_time' => ServerInfo::max_execution_time(),
				'max_upload_size'    => size_format( ServerInfo::max_upload_size() ),
				'post_max_size'      => ServerInfo::post_max_size(),
				'disk_free'          => size_format( ServerInfo::disk_free_bytes() ),
				'has_zip'            => ServerInfo::has_zip_archive(),
				'has_curl'           => ServerInfo::has_curl(),
				'has_openssl'        => ServerInfo::has_openssl(),
				'wp_cron_disabled'   => ServerInfo::wp_cron_disabled(),
				'ssl_enabled'        => ServerInfo::ssl_enabled(),
			),
			'settings_summary'  => $this->safe_settings(),
			'recent_backups'    => $this->recent_backups(),
			'recent_jobs'       => array(
				'backup'  => $this->recent_jobs( 'backup' ),
				'restore' => $this->recent_jobs( 'restore' ),
			),
			'recent_logs'       => Logger::get_recent_entries( 300 ),
		);

		Logger::info( 'Debug bundle exported by user #' . get_current_user_id() . '.' );

		$this->stream_json( 'wpbp-debug-bundle-' . gmdate( 'Ymd-His' ) . '.json', $bundle );
	}

	/**
	 * Settings with any sensitive-looking value masked.
	 */
	private function safe_settings(): array {
		$out = array();

		foreach ( Settings::get_all() as $key => $value ) {
			if ( is_scalar( $value ) && $this->is_sensitive_key( (string) $key ) ) {
				$out[ $key ] = $this->mask( (string) $value );
			} else {
				$out[ $key ] = $value;
			}
		}

		return $out;
	}

	private function recent_backups(): array {
		$rows = array();

		foreach ( BackupsRepository::list_all( 20 ) as $b ) {
			$rows[] = array(
				'id'           => (int) $b['id'],
				'file_name'    => $b['file_name'] ?? '',
				'backup_type'  => $b['backup_type'] ?? '',
				'size'         => isset( $b['size'] ) ? size_format( (int) $b['size'] ) : '',
				'status'       => $b['status'] ?? '',
				'is_encrypted' => ! empty( $b['is_encrypted'] ),
				'is_locked'    => ! empty( $b['is_locked'] ),
				'created_at'   => $b['created_at'] ?? '',
			);
		}

		return $rows;
	}

	private function recent_jobs( string $type ): array {
		$rows = array();

		foreach ( JobsRepository::list_by_type( $type, 20 ) as $j ) {
			$rows[] = array(
				'id'         => (int) $j['id'],
				'status'     => $j['status'] ?? '',
				'progress'   => isset( $j['progress'] ) ? (int) $j['progress'] : null,
				'created_at' => $j['created_at'] ?? '',
				'updated_at' => $j['updated_at'] ?? '',
			);
		}

		return $rows;
	}

	private function is_sensitive_key( string $key ): bool {
		return (bool) preg_match( '/(pass|secret|token|key|email|client_id|uuid)/i', $key );
	}

	/**
	 * Mask a value, keeping a little context: abcd********wxyz.
	 */
	private function mask( string $value ): string {
		$value = trim( $value );

		if ( '' === $value ) {
			return '';
		}

		if ( strlen( $value ) <= 8 ) {
			return str_repeat( '*', strlen( $value ) );
		}

		return substr( $value, 0, 4 ) . '********' . substr( $value, -4 );
	}

	private function stream_json( string $filename, array $data ): void {
		nocache_headers();
		header( 'Content-Type: application/json; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="' . sanitize_file_name( $filename ) . '"' );

		echo wp_json_encode( $data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		exit;
	}
}
