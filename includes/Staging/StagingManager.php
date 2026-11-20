<?php
/**
 * Drives a StagingJob through its phases, one bounded step per call —
 * mirrors BackupManager/RestoreManager's timeout-safe, chunked design.
 *
 * Phases: init -> copy_core -> copy_content -> clone_database ->
 * search_replace -> config -> protect -> finalize.
 *
 * @package WPBP\Staging
 */

namespace WPBP\Staging;

use WPBP\Backup\DatabaseExporter;
use WPBP\Core\Database\StagingSitesRepository;
use WPBP\Restore\SearchReplaceEngine;
use WPBP\Utils\Errors;
use WPBP\Utils\Logger;
use WPBP\Utils\Paths;

defined( 'ABSPATH' ) || exit;

class StagingManager {

	public function process_step( StagingJob $job ): void {
		$payload = $job->payload();
		$phase   = $payload['phase'] ?? 'init';

		try {
			switch ( $phase ) {
				case 'init':
					$this->step_init( $job, $payload );
					break;

				case 'copy_core':
					$this->step_copy_core( $job, $payload );
					break;

				case 'copy_content':
					$this->step_copy_content( $job, $payload );
					break;

				case 'clone_database':
					$this->step_clone_database( $job, $payload );
					break;

				case 'search_replace':
					$this->step_search_replace( $job, $payload );
					break;

				case 'config':
					$this->step_config( $job, $payload );
					break;

				case 'protect':
					$this->step_protect( $job, $payload );
					break;

				case 'finalize':
					$this->step_finalize( $job, $payload );
					break;

				default:
					$job->mark_failed( __( 'Unknown staging phase.', 'wrensafe' ) );
			}
		} catch ( \Throwable $e ) {
			Logger::error( 'Staging job #' . $job->id() . ' failed: ' . Errors::text( $e ), array(), $job->id() );
			$job->mark_failed( Errors::text( $e ) );
		}
	}

	private function step_init( StagingJob $job, array $payload ): void {
		global $wpdb;

		$slug           = substr( md5( wp_generate_password( 12, false ) ), 0, 8 );
		$staging_dir    = Paths::root() . '/staging-' . $slug . '/';
		$staging_prefix = $wpdb->prefix . 'stg_' . $slug . '_';

		wp_mkdir_p( $staging_dir );

		$staging_site_id = StagingSitesRepository::create(
			array(
				'slug'               => $slug,
				'path'               => $staging_dir,
				'url'                => trailingslashit( home_url() ) . 'staging-' . $slug . '/',
				'table_prefix'       => $staging_prefix,
				'status'             => 'creating',
				'password_protected' => ! empty( $payload['password_protected'] ) ? 1 : 0,
				'staging_username'   => $payload['staging_username'] ?? '',
				// Never stored: only its hash, in the staging site's folder
				// (see StagingProtection).
				'staging_password'   => '',
			)
		);

		$payload['staging_site_id'] = $staging_site_id;
		$payload['staging_dir']     = $staging_dir;
		$payload['staging_prefix']  = $staging_prefix;
		$payload['core_offset']     = 0;
		$payload['content_offset']  = 0;
		$payload['table_index']     = 0;
		$payload['phase']           = 'copy_core';

		$job->set_payload( $payload );
		$job->mark_running();
		$job->update_progress( 5, __( 'Preparing staging site…', 'wrensafe' ) );
	}

	private function step_copy_core( StagingJob $job, array $payload ): void {
		$creator = new StagingCreator();

		if ( ! isset( $payload['core_files'] ) ) {
			$payload['core_files'] = $creator->list_core_files();
			$job->set_payload( $payload );
			$job->update_progress( 10, __( 'Indexed WordPress core files…', 'wrensafe' ) );
			return;
		}

		$copied                  = $creator->copy_batch( Paths::root(), $payload['staging_dir'], $payload['core_files'], $payload['core_offset'] );
		$payload['core_offset'] += $copied;

		$total = count( $payload['core_files'] );

		if ( $payload['core_offset'] >= $total ) {
			$payload['phase'] = 'copy_content';
			$job->update_progress( 30, __( 'Core files copied.', 'wrensafe' ) );
		} else {
			$job->update_progress( 10 + (int) ( ( $payload['core_offset'] / max( 1, $total ) ) * 20 ), sprintf( /* translators: 1: number of items processed so far, 2: total number of items */ __( 'Copying core files (%1$d / %2$d)', 'wrensafe' ), $payload['core_offset'], $total ) );
		}

		$job->set_payload( $payload );
	}

	private function step_copy_content( StagingJob $job, array $payload ): void {
		$creator = new StagingCreator();

		if ( ! isset( $payload['content_files'] ) ) {
			$payload['content_files'] = $creator->list_content_files();
			$job->set_payload( $payload );
			$job->update_progress( 32, __( 'Indexed content files…', 'wrensafe' ) );
			return;
		}

		$destination                = StagingCreator::staging_content_dir( $payload['staging_dir'] );
		$copied                     = $creator->copy_batch( Paths::content_dir(), $destination, $payload['content_files'], $payload['content_offset'] );
		$payload['content_offset'] += $copied;

		$total = count( $payload['content_files'] );

		if ( $payload['content_offset'] >= $total ) {
			$payload['phase'] = 'clone_database';
			$job->update_progress( 55, __( 'Content files copied.', 'wrensafe' ) );
		} else {
			$job->update_progress( 32 + (int) ( ( $payload['content_offset'] / max( 1, $total ) ) * 23 ), sprintf( /* translators: 1: number of items processed so far, 2: total number of items */ __( 'Copying content files (%1$d / %2$d)', 'wrensafe' ), $payload['content_offset'], $total ) );
		}

		$job->set_payload( $payload );
	}

	private function step_clone_database( StagingJob $job, array $payload ): void {
		global $wpdb;

		if ( ! isset( $payload['tables'] ) ) {
			$payload['tables'] = ( new DatabaseExporter() )->get_tables( true ); // Staging copies every table, whatever backups leave out.
			$job->set_payload( $payload );
			$job->update_progress( 57, __( 'Preparing database clone…', 'wrensafe' ) );
			return;
		}

		$tables = $payload['tables'];
		$index  = $payload['table_index'];

		if ( $index >= count( $tables ) ) {
			( new StagingCreator() )->apply_exclusions(
				$payload['staging_prefix'],
				! empty( $payload['exclude_users'] ),
				! empty( $payload['exclude_comments'] ),
				! empty( $payload['exclude_orders'] )
			);

			// The staging site reads roles and capabilities under its own prefix.
			( new StagingCreator() )->rename_prefixed_keys( $payload['staging_prefix'], $wpdb->prefix, $payload['staging_prefix'] );

			$payload['phase'] = 'search_replace';
			$job->update_progress( 80, __( 'Database cloned.', 'wrensafe' ) );
			$job->set_payload( $payload );
			return;
		}

		( new StagingCreator() )->clone_table( $tables[ $index ], $wpdb->prefix, $payload['staging_prefix'] );
		++$payload['table_index'];

		$progress = 57 + (int) ( ( $payload['table_index'] / max( 1, count( $tables ) ) ) * 23 );
		$job->update_progress( $progress, sprintf( /* translators: %s: database table name */ __( 'Cloning table %s', 'wrensafe' ), $tables[ $index ] ) );
		$job->set_payload( $payload );
	}

	private function step_search_replace( StagingJob $job, array $payload ): void {
		$staging_site = StagingSitesRepository::find( (int) $payload['staging_site_id'] );
		$old_url      = untrailingslashit( home_url() );
		$new_url      = untrailingslashit( $staging_site['url'] );

		if ( ! isset( $payload['sr_table_index'] ) ) {
			$payload['sr_table_index'] = 0;
			$payload['sr_row_offset']  = 0;
			$job->set_payload( $payload );
			return;
		}

		$tables = $payload['tables'];
		$index  = $payload['sr_table_index'];

		if ( $index >= count( $tables ) ) {
			$payload['phase'] = 'config';
			$job->update_progress( 90, __( 'URLs rewritten for staging.', 'wrensafe' ) );
			$job->set_payload( $payload );
			return;
		}

		$suffix        = substr( $tables[ $index ], strlen( $GLOBALS['wpdb']->prefix ) );
		$staging_table = $payload['staging_prefix'] . $suffix;

		$result = ( new SearchReplaceEngine() )->process_table_chunk( $staging_table, (int) $payload['sr_row_offset'], $old_url, $new_url );

		if ( $result['done'] ) {
			++$payload['sr_table_index'];
			$payload['sr_row_offset'] = 0;
		} else {
			$payload['sr_row_offset'] = $result['next_offset'];
		}

		$job->update_progress( 85, sprintf( /* translators: %s: database table name */ __( 'Replacing URLs in %s', 'wrensafe' ), $staging_table ) );
		$job->set_payload( $payload );
	}

	private function step_config( StagingJob $job, array $payload ): void {
		$staging_site = StagingSitesRepository::find( (int) $payload['staging_site_id'] );

		if ( ! ( new StagingCreator() )->write_staging_config( $payload['staging_dir'], $payload['staging_prefix'], (string) ( $staging_site['url'] ?? '' ) ) ) {
			throw new \RuntimeException( esc_html__( 'The staging site’s wp-config.php could not be written with its own table prefix, so the staging site was not created. Delete it and check that wp-config.php sets $table_prefix.', 'wrensafe' ) );
		}

		( new StagingProtection() )->disable_indexing( $payload['staging_prefix'] );

		$payload['phase'] = 'protect';
		$job->update_progress( 92, __( 'Staging configuration written.', 'wrensafe' ) );
		$job->set_payload( $payload );
	}

	private function step_protect( StagingJob $job, array $payload ): void {
		$protected = empty( $payload['password_protected'] ) || ( new StagingProtection() )->password_protect(
			$payload['staging_dir'],
			(string) $payload['staging_username'],
			(string) $payload['staging_password']
		);

		// The password was only needed to write its hash; don't keep it in the job.
		unset( $payload['staging_password'] );
		$job->set_payload( $payload );

		// A staging site that was asked to be password protected and isn't
		// must not be left open.
		if ( ! $protected ) {
			throw new \RuntimeException( esc_html__( 'The staging site couldn’t be password protected: its folder or wp-config.php isn’t writable. Delete the staging site, check the folder permissions and create it again.', 'wrensafe' ) );
		}

		$payload['phase'] = 'finalize';
		$job->update_progress( 96, __( 'Staging site protected.', 'wrensafe' ) );
		$job->set_payload( $payload );
	}

	private function step_finalize( StagingJob $job, array $payload ): void {
		StagingSitesRepository::update(
			(int) $payload['staging_site_id'],
			array(
				'status'         => 'active',
				'last_synced_at' => current_time( 'mysql' ),
			)
		);

		$job->mark_completed( array( 'staging_site_id' => $payload['staging_site_id'] ) );

		Logger::info( 'Staging site #' . $payload['staging_site_id'] . ' created.', array(), $job->id() );
	}
}
