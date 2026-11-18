<?php
/**
 * Shared tail end of every import source (browser upload, cloud, URL,
 * server path): validate the archive, run the deep integrity check, and
 * create the RestoreJob — so each source only has to worry about getting
 * a local .wbp file in place, not re-implementing validation.
 *
 * @package WPBP\Restore
 */

namespace WPBP\Restore;

defined( 'ABSPATH' ) || exit;

class ImportValidator {

	/**
	 * @return array{success:bool,message?:string,job_id?:int,manifest?:array,metadata?:array,changes?:array,site_url?:string,destination_prefix?:string}
	 */
	public static function validate_and_create_job( string $local_path, string $source, array $extra_payload = array() ): array {
		$reader = new ArchiveReader( $local_path );
		$result = $reader->validate();

		// Encrypted: keep the file and create the restore job, then the
		// browser asks for the password (wpbp_upload_unlock), as for uploads.
		if ( ! $result['valid'] && ! empty( $result['requires_password'] ) ) {
			$job = RestoreJob::create(
				array_merge(
					array(
						'source'        => $source,
						'uploaded_file' => $local_path,
						'encrypted'     => true,
					),
					$extra_payload
				)
			);

			return array(
				'success'           => false,
				'message'           => __( 'This backup is encrypted. Enter its password to continue.', 'wrensafe' ),
				'requires_password' => true,
				'job_id'            => $job->id(),
			);
		}

		if ( ! $result['valid'] ) {
			return array(
				'success' => false,
				'message' => $result['message'],
			);
		}

		// Structure only. The full per-entry checksum check is not done here:
		// on a multi-GB archive it can't fit in one request, and every restore
		// runs it anyway — in resumable steps, before anything is changed
		// (RestoreManager::step_verify).

		$changes = $reader->get_change_summary();

		$job = RestoreJob::create(
			array_merge(
				array(
					'source'        => $source,
					'uploaded_file' => $local_path,
					'manifest'      => $result['manifest'],
					'metadata'      => $result['metadata'],
				),
				$extra_payload
			)
		);

		global $wpdb;

		return array(
			'success'            => true,
			'job_id'             => $job->id(),
			'manifest'           => $result['manifest'],
			'metadata'           => $result['metadata'],
			'changes'            => $changes,
			'site_url'           => untrailingslashit( home_url() ),
			'destination_prefix' => $wpdb->prefix,
			'destination_wp'     => get_bloginfo( 'version' ),
			'destination_php'    => PHP_VERSION,
		);
	}
}
