<?php
/**
 * AJAX endpoints for two import sources on the Import / Restore page: a
 * direct URL, and a path already on this server. Browser upload and
 * local-backup restore are handled by UploadAjax and RestoreAjax.
 *
 * Each source ends the same way — download/locate a local .wbp file, then
 * hand it to ImportValidator, which validates the archive, runs the deep
 * integrity check, and creates the RestoreJob — so the response shape
 * matches UploadAjax::finalize() and the restore review screen works
 * unchanged for every source.
 *
 * @package WPBP\Ajax
 */

namespace WPBP\Ajax;

use WPBP\Restore\ImportValidator;
use WPBP\Security\CapabilityManager;
use WPBP\Security\PathValidator;
use WPBP\Utils\FileSystem;
use WPBP\Utils\Paths;

defined( 'ABSPATH' ) || exit;

class ImportSourceAjax {

	private const MAX_IMPORT_BYTES = 5368709120; // 5GB — matches UploadAjax's ceiling.

	private static function require_capability(): void {
		if ( ! CapabilityManager::current_user_can( CapabilityManager::RESTORE_BACKUPS ) ) {
			wp_send_json_error( array( 'message' => __( 'You do not have permission to import backups.', 'wrensafe' ) ), 403 );
		}
	}

	public static function new_local_path( string $prefix ): string {
		FileSystem::ensure_protected_directory( FileSystem::uploads_dir() );

		return FileSystem::uploads_dir() . $prefix . '-' . bin2hex( random_bytes( 16 ) ) . '.wbp';
	}

	/**
	 * Download a .wbp file from a direct URL and start an import. HTTPS is
	 * required by default; an HTTP URL is allowed only after the browser
	 * re-submits with allow_http=1, having shown the user a warning.
	 */
	public static function url_fetch(): void {
		check_ajax_referer( 'wpbp_admin_ajax', '_wpbp_nonce' );
		self::require_capability();

		$url = isset( $_POST['url'] ) ? sanitize_url( wp_unslash( $_POST['url'] ) ) : '';

		if ( '' === $url || ! wp_http_validate_url( $url ) ) {
			wp_send_json_error( array( 'message' => __( 'Please enter a valid URL.', 'wrensafe' ) ), 400 );
		}

		if ( ! str_ends_with( strtolower( (string) wp_parse_url( $url, PHP_URL_PATH ) ), '.wbp' ) ) {
			wp_send_json_error( array( 'message' => __( 'The URL must point to a .wbp file.', 'wrensafe' ) ), 400 );
		}

		if ( ! str_starts_with( $url, 'https://' ) && empty( $_POST['allow_http'] ) ) {
			wp_send_json_error(
				array(
					'message'                    => __( 'This URL is not HTTPS — the archive would be downloaded over an insecure connection. Continue anyway?', 'wrensafe' ),
					'requires_http_confirmation' => true,
				)
			); // A question for the browser, not a failure: status 200 so it reaches the confirm dialog.
		}

		$local_path = self::new_local_path( 'url-import' );

		$response = wp_remote_get(
			$url,
			array(
				'timeout'             => 120,
				'stream'              => true,
				'filename'            => $local_path,
				// Stop at the size limit instead of filling the disk first.
				'limit_response_size' => self::MAX_IMPORT_BYTES + 1,
			)
		);

		if ( is_wp_error( $response ) ) {
			if ( file_exists( $local_path ) ) {
				wp_delete_file( $local_path );
			}
			wp_send_json_error( array( 'message' => $response->get_error_message() ) );
		}

		$code = wp_remote_retrieve_response_code( $response );

		if ( $code < 200 || $code >= 300 ) {
			if ( file_exists( $local_path ) ) {
				wp_delete_file( $local_path );
			}
			/* translators: %d: HTTP response code */
			wp_send_json_error( array( 'message' => sprintf( __( 'Download failed (HTTP %d).', 'wrensafe' ), $code ) ) );
		}

		if ( file_exists( $local_path ) && filesize( $local_path ) > self::MAX_IMPORT_BYTES ) {
			wp_delete_file( $local_path );
			wp_send_json_error( array( 'message' => __( 'File exceeds the maximum allowed import size.', 'wrensafe' ) ) );
		}

		$validated = ImportValidator::validate_and_create_job( $local_path, 'url_import' );

		if ( empty( $validated['success'] ) ) {
			// An encrypted backup keeps its file for the password step.
			if ( empty( $validated['requires_password'] ) ) {
				wp_delete_file( $local_path );
			}

			wp_send_json_error( $validated ); // message, and requires_password + job_id for an encrypted backup.
		}

		wp_send_json_success( $validated );
	}

	/**
	 * Import a .wbp file already on this server. Restricted to true
	 * administrators (not just Team Access RESTORE_BACKUPS holders), since
	 * reading an arbitrary server path is more sensitive than the other
	 * import sources, and to a path inside wp-content — which is also
	 * where this plugin's own protected storage lives.
	 */
	public static function server_path(): void {
		check_ajax_referer( 'wpbp_admin_ajax', '_wpbp_nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Importing from a server path requires administrator access.', 'wrensafe' ) ), 403 );
		}

		$path = isset( $_POST['path'] ) ? sanitize_text_field( wp_unslash( $_POST['path'] ) ) : '';

		if ( '' === $path || ! str_ends_with( strtolower( $path ), '.wbp' ) ) {
			wp_send_json_error( array( 'message' => __( 'Please enter the full path to a .wbp file.', 'wrensafe' ) ), 400 );
		}

		$real_path = realpath( $path );

		if ( false === $real_path || ! is_file( $real_path ) ) {
			wp_send_json_error( array( 'message' => __( 'File not found.', 'wrensafe' ) ), 404 );
		}

		if ( ! PathValidator::assert_within( $real_path, Paths::content_dir() ) ) {
			wp_send_json_error(
				array(
					'message' => sprintf(
						/* translators: %s: the site's content folder, usually wp-content */
						__( 'For security, the file must be inside this site\'s content folder (%s).', 'wrensafe' ),
						Paths::display( Paths::content_dir() )
					),
				),
				403
			);
		}

		$validated = ImportValidator::validate_and_create_job( $real_path, 'server_path_import' );

		if ( empty( $validated['success'] ) ) {
			wp_send_json_error( $validated ); // message, and requires_password + job_id for an encrypted backup.
		}

		wp_send_json_success( $validated );
	}
}
