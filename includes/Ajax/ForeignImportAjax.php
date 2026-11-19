<?php
/**
 * Import / Restore → Other backup plugins: analyse an uploaded set, convert
 * it in steps, and hand the result to the restore review screen.
 *
 * @package WPBP\Ajax
 */

namespace WPBP\Ajax;

use WPBP\Import\Foreign\ForeignConverter;
use WPBP\Import\Foreign\ForeignImportJob;
use WPBP\Import\Foreign\ImportSet;
use WPBP\Security\CapabilityManager;
use WPBP\Utils\Errors;
use WPBP\Utils\FileSystem;

defined( 'ABSPATH' ) || exit;

class ForeignImportAjax {

	/** Every handler checks the nonce itself, then calls this. */
	private static function require_capability(): void {
		if ( ! CapabilityManager::current_user_can( CapabilityManager::RESTORE_BACKUPS ) ) {
			wp_send_json_error( array( 'message' => __( 'You do not have permission to import backups.', 'wrensafe' ) ), 403 );
		}
	}

	/** The import set id from the request, or a JSON error. */
	private static function valid_set_id( string $id ): string {
		if ( ! ImportSet::valid_id( $id ) ) {
			wp_send_json_error( array( 'message' => __( 'Upload the backup files first.', 'wrensafe' ) ), 400 );
		}

		return $id;
	}

	/**
	 * Which plugin made the set, and what it contains — or a JSON error.
	 * Anything thrown while looking into the files (a damaged archive, a PHP
	 * extension the backup needs and this server lacks) becomes a readable
	 * message, never an HTTP 500.
	 *
	 * @param array<string,string> $files
	 * @return array{0:\WPBP\Import\Foreign\AbstractFormat,1:array}
	 */
	private static function plan_or_die( array $files ): array {
		$error = '';

		try {
			$format = ForeignConverter::detect( $files );
			$plan   = null === $format ? array() : ForeignConverter::describe( $format, $files );
		} catch ( \Throwable $e ) {
			$format = null;
			$error  = Errors::text( $e );
		}

		if ( null === $format ) {
			wp_send_json_error( array( 'message' => '' !== $error ? $error : __( 'Wrensafe doesn’t recognize these files as a WordPress backup.', 'wrensafe' ) ) );
		}

		return array( $format, $plan );
	}

	/** What is in the uploaded set, and which plugin made it. */
	public static function analyze(): void {
		check_ajax_referer( 'wpbp_admin_ajax', '_wpbp_nonce' );
		self::require_capability();

		$id    = self::valid_set_id( isset( $_POST['set_id'] ) ? sanitize_key( wp_unslash( $_POST['set_id'] ) ) : '' );
		$files = ImportSet::files( $id );

		if ( empty( $files ) ) {
			wp_send_json_error( array( 'message' => __( 'No uploaded files were found. Upload the backup again.', 'wrensafe' ) ), 400 );
		}

		list( $format, $plan ) = self::plan_or_die( $files );

		$meta = $plan['meta'];

		wp_send_json_success(
			array(
				'format'     => $format->key(),
				'name'       => $format->name(),
				'site_url'   => (string) ( $meta['home_url'] ?? ( $meta['site_url'] ?? '' ) ),
				'wp_version' => (string) ( $meta['wp_version'] ?? '' ),
				'files'      => array_map(
					static function ( $name, $path ) {
						return array(
							'name' => $name,
							'size' => (int) filesize( $path ),
						);
					},
					array_keys( $files ),
					array_values( $files )
				),
				'total'      => ImportSet::size( $id ),
				'warnings'   => array_values( $plan['warnings'] ),
				'multisite'  => ! empty( $meta['is_multisite'] ),
			)
		);
	}

	public static function convert_start(): void {
		check_ajax_referer( 'wpbp_admin_ajax', '_wpbp_nonce' );
		self::require_capability();

		$id    = self::valid_set_id( isset( $_POST['set_id'] ) ? sanitize_key( wp_unslash( $_POST['set_id'] ) ) : '' );
		$files = ImportSet::files( $id );

		if ( empty( $files ) ) {
			wp_send_json_error( array( 'message' => __( 'No uploaded files were found. Upload the backup again.', 'wrensafe' ) ), 400 );
		}

		list( $format, $plan ) = self::plan_or_die( $files );

		$error = '';

		try {
			$job = ForeignConverter::start( $id, array( 'format' => $format->key() ) + $plan );
		} catch ( \Throwable $e ) {
			$job   = null;
			$error = Errors::text( $e );
		}

		if ( null === $job ) {
			wp_send_json_error( array( 'message' => $error ) );
		}

		wp_send_json_success( array( 'job_id' => $job->id() ) );
	}

	public static function convert_process(): void {
		check_ajax_referer( 'wpbp_admin_ajax', '_wpbp_nonce' );
		self::require_capability();

		$job_id = isset( $_POST['job_id'] ) ? absint( $_POST['job_id'] ) : 0;

		// One step at a time per job: a request the browser retried after a
		// timeout must not run alongside the step that is still going.
		// flock() is released by PHP even if the request dies.
		$lock_file = FileSystem::uploads_dir() . 'import-job-' . $job_id . '.lock';
		$lock      = $job_id ? fopen( $lock_file, 'c' ) : false; // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- lock file in protected storage.
		$locked    = false !== $lock && flock( $lock, LOCK_EX | LOCK_NB );

		// Read the job only once the lock is held: the step before may just have saved it.
		$job = $job_id ? ForeignImportJob::get( $job_id ) : null;

		if ( null === $job ) {
			wp_send_json_error( array( 'message' => __( 'Import job not found.', 'wrensafe' ) ), 404 );
		}

		if ( ! $locked ) {
			wp_send_json_success( array( 'busy' => true ) + $job->to_array() );
		}

		if ( 'running' === $job->status() ) {
			if ( function_exists( 'set_time_limit' ) ) {
				@set_time_limit( 0 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.PHP.DiscouragedPHPFunctions.runtime_configuration_set_time_limit, Squiz.PHP.DiscouragedFunctions.Discouraged -- each step is time-budgeted anyway.
			}

			ForeignConverter::step( $job );
		}

		flock( $lock, LOCK_UN );
		fclose( $lock ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose

		if ( 'running' !== $job->status() ) {
			wp_delete_file( $lock_file );
		}

		$data = $job->to_array();

		if ( 'completed' === $job->status() ) {
			$data['restore'] = $job->result();
		}

		wp_send_json_success( $data );
	}

	/** Take one file back out of the set (uploaded by mistake). */
	public static function remove_file(): void {
		check_ajax_referer( 'wpbp_admin_ajax', '_wpbp_nonce' );
		self::require_capability();

		$id   = self::valid_set_id( isset( $_POST['set_id'] ) ? sanitize_key( wp_unslash( $_POST['set_id'] ) ) : '' );
		$name = isset( $_POST['file'] ) ? ImportSet::clean_name( sanitize_text_field( wp_unslash( $_POST['file'] ) ) ) : '';

		ImportSet::remove( $id, $name );
		wp_send_json_success();
	}

	public static function discard(): void {
		check_ajax_referer( 'wpbp_admin_ajax', '_wpbp_nonce' );
		self::require_capability();

		ImportSet::delete( self::valid_set_id( isset( $_POST['set_id'] ) ? sanitize_key( wp_unslash( $_POST['set_id'] ) ) : '' ) );
		wp_send_json_success();
	}
}
