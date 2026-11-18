<?php
/**
 * Source-side driver that pushes a local backup to a destination site's
 * migration REST API, one bounded chunk per call (timeout-safe, mirrors
 * BackupManager/RestoreManager's step pattern).
 *
 * @package WPBP\Migration
 */

namespace WPBP\Migration;

use WPBP\Core\Database\BackupsRepository;
use WPBP\Core\Settings;
use WPBP\Utils\Logger;

defined( 'ABSPATH' ) || exit;

// Backup archives and upload chunks are streamed in bounded blocks with native
// file handles (fopen/fread/fwrite/rename): WP_Filesystem has no streaming API.
// Every path is inside Wrensafe's protected storage directory, a PHP upload
// temp file, or validated by WPBP\Security\PathValidator.
// phpcs:disable WordPress.WP.AlternativeFunctions

class TransferClient {

	const DEFAULT_CHUNK_SIZE = 1048576; // 1MB — used until the destination advertises its own ceiling.

	public function process_step( MigrationTransferJob $job ): void {
		$payload = $job->payload();
		$phase   = $payload['phase'] ?? 'init';

		try {
			switch ( $phase ) {
				case 'init':
					$this->step_init( $job, $payload );
					break;

				case 'transfer':
					$this->step_transfer( $job, $payload );
					break;

				case 'finalize':
					$this->step_finalize( $job, $payload );
					break;

				default:
					$job->mark_failed( __( 'Unknown migration phase.', 'wrensafe' ) );
			}
		} catch ( \Throwable $e ) {
			Logger::error( 'Migration transfer #' . $job->id() . ' failed: ' . $e->getMessage(), array(), $job->id() );
			$job->mark_failed( $e->getMessage() );
		}
	}

	/**
	 * The destination's REST address. /wp-json/ needs pretty permalinks; a
	 * site on "Plain" permalinks answers ?rest_route= instead (step_init()
	 * switches to that form when /wp-json/ returns 404).
	 */
	private function endpoint( array $payload, string $path ): string {
		$base  = untrailingslashit( (string) $payload['destination_url'] );
		$route = '/wpbp/v1/migration/' . $path;

		return 'plain' === ( $payload['rest_style'] ?? '' ) ? add_query_arg( 'rest_route', $route, $base . '/' ) : $base . '/wp-json' . $route;
	}

	private function step_init( MigrationTransferJob $job, array $payload ): void {
		$backup = BackupsRepository::find( (int) $payload['backup_id'] );

		if ( null === $backup || ! file_exists( $backup['file_path'] ) ) {
			$job->mark_failed( __( 'Source backup file not found.', 'wrensafe' ) );
			return;
		}

		if ( Settings::get( 'migration_require_https', true ) && ! str_starts_with( $payload['destination_url'], 'https://' ) ) {
			$job->mark_failed( __( 'The destination URL must use HTTPS. Turn on HTTPS on the destination site, or turn off the HTTPS requirement in Wrensafe → Settings → Direct Migration.', 'wrensafe' ) );
			return;
		}

		$init = array(
			'body'      => array(
				'token'       => $payload['token'],
				'filename'    => $backup['file_name'],
				'total_size'  => (string) $backup['size'],
				// Shown on the destination's backup list. An encrypted backup
				// can't be read there without its password.
				'backup_type' => (string) ( $backup['backup_type'] ?? '' ),
				'source_url'  => home_url(),
			),
			'timeout'   => 30,
			'sslverify' => ! empty( $payload['verify_ssl'] ),
		);

		$response = wp_remote_post( $this->endpoint( $payload, 'init' ), $init );

		// No /wp-json/ on the destination (Plain permalinks): use ?rest_route=.
		if ( ! is_wp_error( $response ) && 404 === wp_remote_retrieve_response_code( $response ) ) {
			$payload['rest_style'] = 'plain';
			$response              = wp_remote_post( $this->endpoint( $payload, 'init' ), $init );
		}

		if ( is_wp_error( $response ) || 200 !== wp_remote_retrieve_response_code( $response ) ) {
			$job->mark_failed( $this->error_message( $response, __( 'Destination site rejected the migration key.', 'wrensafe' ) ) );
			return;
		}

		// The destination advertises the largest chunk it's willing to
		// buffer — use whichever is smaller of that and our own local
		// preference, since the two sites are independently configured.
		$body                  = json_decode( wp_remote_retrieve_body( $response ), true );
		$server_max            = (int) ( $body['max_chunk_size'] ?? 0 );
		$local_preference      = max( 1, (int) Settings::get( 'migration_chunk_size_mb', 2 ) ) * 1024 * 1024;
		$payload['chunk_size'] = $server_max > 0 ? min( $server_max, $local_preference ) : $local_preference;

		$payload['file_path']  = $backup['file_path'];
		$payload['total_size'] = (int) $backup['size'];
		$payload['offset']     = 0;
		$payload['checksum']   = hash_file( 'sha256', $backup['file_path'] );
		$payload['phase']      = 'transfer';

		$job->set_payload( $payload );
		$job->mark_running();
		$job->update_progress( 5, __( 'Connected to destination site.', 'wrensafe' ) );
	}

	private function step_transfer( MigrationTransferJob $job, array $payload ): void {
		$chunk_size = max( 1, (int) ( $payload['chunk_size'] ?? self::DEFAULT_CHUNK_SIZE ) );

		$handle = fopen( $payload['file_path'], 'rb' );
		fseek( $handle, $payload['offset'] );
		$chunk = fread( $handle, $chunk_size );
		fclose( $handle );

		$chunk_index = (int) ( $payload['offset'] / $chunk_size );
		$signature   = ( new MigrationKey() )->sign_chunk( $payload['token'], $chunk_index, $chunk );

		$response = wp_remote_post(
			$this->endpoint( $payload, 'chunk' ),
			array(
				'body'      => array(
					'token'       => $payload['token'],
					'chunk_index' => (string) $chunk_index,
					'signature'   => $signature,
					'chunk'       => base64_encode( $chunk ), // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
				),
				'timeout'   => 60,
				'sslverify' => ! empty( $payload['verify_ssl'] ),
			)
		);

		if ( is_wp_error( $response ) || 200 !== wp_remote_retrieve_response_code( $response ) ) {
			$job->mark_failed( $this->error_message( $response, __( 'Chunk transfer failed. You can resume by running the migration again.', 'wrensafe' ) ) );
			return;
		}

		$payload['offset'] += strlen( $chunk );

		if ( $payload['offset'] >= $payload['total_size'] ) {
			$payload['phase'] = 'finalize';
			$job->update_progress( 95, __( 'Verifying transfer…', 'wrensafe' ) );
		} else {
			$progress = 5 + (int) ( ( $payload['offset'] / max( 1, $payload['total_size'] ) ) * 90 );
			$job->update_progress( $progress, __( 'Transferring backup…', 'wrensafe' ) );
		}

		$job->set_payload( $payload );
	}

	private function step_finalize( MigrationTransferJob $job, array $payload ): void {
		$response = wp_remote_post(
			$this->endpoint( $payload, 'finalize' ),
			array(
				'body'      => array(
					'token'    => $payload['token'],
					'checksum' => $payload['checksum'],
				),
				'timeout'   => 30,
				'sslverify' => ! empty( $payload['verify_ssl'] ),
			)
		);

		if ( is_wp_error( $response ) || 200 !== wp_remote_retrieve_response_code( $response ) ) {
			$job->mark_failed( $this->error_message( $response, __( 'The destination site could not verify the transferred archive.', 'wrensafe' ) ) );
			return;
		}

		$job->mark_completed( array( 'backup_id' => $payload['backup_id'] ) );

		Logger::info( 'Migration transfer #' . $job->id() . ' completed.', array(), $job->id() );
	}

	private function error_message( $response, string $fallback ): string {
		if ( is_wp_error( $response ) ) {
			return $response->get_error_message();
		}

		$body = json_decode( wp_remote_retrieve_body( $response ), true );

		return $body['message'] ?? $fallback;
	}
}

// phpcs:enable WordPress.WP.AlternativeFunctions
