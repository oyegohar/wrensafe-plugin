<?php
/**
 * Pushes a staging site's current database and content folder back to the
 * live site — the reverse direction of StagingManager's clone.
 *
 * Core WordPress files and wp-config.php are intentionally never pushed
 * back; only content (database + content folder) moves from staging to live.
 *
 * @package WPBP\Staging
 */

namespace WPBP\Staging;

use WPBP\Core\Database\StagingSitesRepository;
use WPBP\Restore\SearchReplaceEngine;
use WPBP\Utils\Logger;
use WPBP\Utils\Paths;

defined( 'ABSPATH' ) || exit;

// Database access in this file is direct by design: Wrensafe's own tables (or,
// in the backup/restore engine, the site's tables being exported, imported or
// swapped) have no WordPress API, and the data must be read fresh, so it is not
// object-cached. Values go through $wpdb->prepare(); table names come from
// $wpdb->prefix with fixed suffixes and are escaped or quoted as identifiers.
// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

class PushToLive {

	public function process_step( StagingJob $job ): void {
		$payload = $job->payload();
		$phase   = $payload['phase'] ?? 'push_init';

		try {
			switch ( $phase ) {
				case 'push_init':
					$this->step_init( $job, $payload );
					break;

				case 'push_database':
					$this->step_database( $job, $payload );
					break;

				case 'push_files':
					$this->step_files( $job, $payload );
					break;

				case 'push_search_replace':
					$this->step_search_replace( $job, $payload );
					break;

				case 'push_finalize':
					$this->step_finalize( $job, $payload );
					break;

				default:
					$job->mark_failed( __( 'Unknown push phase.', 'wrensafe' ) );
			}
		} catch ( \Throwable $e ) {
			Logger::error( 'Staging push job #' . $job->id() . ' failed: ' . $e->getMessage(), array(), $job->id() );
			$job->mark_failed( $e->getMessage() );
		}
	}

	private function step_init( StagingJob $job, array $payload ): void {
		global $wpdb;

		$staging = StagingSitesRepository::find( (int) $payload['staging_site_id'] );

		if ( null === $staging ) {
			$job->mark_failed( __( 'Staging site not found.', 'wrensafe' ) );
			return;
		}

		// Discover tables on the staging side and translate to their live equivalents.
		$staging_tables = $wpdb->get_col(
			$wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $staging['table_prefix'] ) . '%' )
		);

		$payload['staging_prefix'] = $staging['table_prefix'];
		$payload['staging_dir']    = $staging['path'];
		$payload['staging_url']    = $staging['url'];
		$payload['staging_tables'] = $staging_tables;
		$payload['table_index']    = 0;
		$payload['content_offset'] = 0;
		$payload['phase']          = 'push_database';

		$job->set_payload( $payload );
		$job->mark_running();
		$job->update_progress( 5, __( 'Preparing to push staging to live…', 'wrensafe' ) );
	}

	private function step_database( StagingJob $job, array $payload ): void {
		global $wpdb;

		$tables = $payload['staging_tables'];
		$index  = $payload['table_index'];

		if ( $index >= count( $tables ) ) {
			( new StagingCreator() )->apply_exclusions(
				$wpdb->prefix,
				! empty( $payload['exclude_users'] ),
				! empty( $payload['exclude_comments'] ),
				! empty( $payload['exclude_orders'] )
			);

			$payload['phase'] = 'push_files';
			$job->update_progress( 55, __( 'Database pushed to live.', 'wrensafe' ) );
			$job->set_payload( $payload );
			return;
		}

		( new StagingCreator() )->clone_table( $tables[ $index ], $payload['staging_prefix'], $wpdb->prefix );
		++$payload['table_index'];

		$progress = 5 + (int) ( ( $payload['table_index'] / max( 1, count( $tables ) ) ) * 50 );
		$job->update_progress( $progress, sprintf( /* translators: %s: database table name */ __( 'Pushing table %s', 'wrensafe' ), $tables[ $index ] ) );
		$job->set_payload( $payload );
	}

	private function step_files( StagingJob $job, array $payload ): void {
		$creator             = new StagingCreator();
		$staging_content_dir = StagingCreator::staging_content_dir( $payload['staging_dir'] );

		if ( ! isset( $payload['push_files_list'] ) ) {
			$files = array();

			if ( is_dir( $staging_content_dir ) ) {
				$iterator = new \RecursiveIteratorIterator(
					new \RecursiveDirectoryIterator( $staging_content_dir, \FilesystemIterator::SKIP_DOTS )
				);

				foreach ( $iterator as $file_info ) {
					if ( $file_info->isFile() ) {
						$files[] = ltrim( substr( $file_info->getPathname(), strlen( $staging_content_dir ) ), '/\\' );
					}
				}
			}

			$payload['push_files_list'] = $files;
			$job->set_payload( $payload );
			$job->update_progress( 56, __( 'Indexed staging files…', 'wrensafe' ) );
			return;
		}

		$copied                     = $creator->copy_batch( $staging_content_dir, Paths::content_dir(), $payload['push_files_list'], $payload['content_offset'] );
		$payload['content_offset'] += $copied;

		$total = count( $payload['push_files_list'] );

		if ( $payload['content_offset'] >= $total ) {
			$payload['phase'] = 'push_search_replace';
			$job->update_progress( 85, __( 'Files pushed to live.', 'wrensafe' ) );
		} else {
			$job->update_progress( 56 + (int) ( ( $payload['content_offset'] / max( 1, $total ) ) * 29 ), sprintf( /* translators: 1: number of items processed so far, 2: total number of items */ __( 'Pushing files (%1$d / %2$d)', 'wrensafe' ), $payload['content_offset'], $total ) );
		}

		$job->set_payload( $payload );
	}

	private function step_search_replace( StagingJob $job, array $payload ): void {
		global $wpdb;

		if ( ! isset( $payload['sr_table_index'] ) ) {
			$payload['sr_table_index'] = 0;
			$payload['sr_row_offset']  = 0;
			$job->set_payload( $payload );
			return;
		}

		$live_tables = $wpdb->get_col( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $wpdb->prefix ) . '%' ) );
		$index       = $payload['sr_table_index'];

		if ( $index >= count( $live_tables ) ) {
			$payload['phase'] = 'push_finalize';
			$job->update_progress( 95, __( 'URLs rewritten for live.', 'wrensafe' ) );
			$job->set_payload( $payload );
			return;
		}

		$result = ( new SearchReplaceEngine() )->process_table_chunk(
			$live_tables[ $index ],
			(int) $payload['sr_row_offset'],
			untrailingslashit( $payload['staging_url'] ),
			untrailingslashit( home_url() )
		);

		if ( $result['done'] ) {
			++$payload['sr_table_index'];
			$payload['sr_row_offset'] = 0;
		} else {
			$payload['sr_row_offset'] = $result['next_offset'];
		}

		$job->update_progress( 90, sprintf( /* translators: %s: database table name */ __( 'Replacing URLs in %s', 'wrensafe' ), $live_tables[ $index ] ) );
		$job->set_payload( $payload );
	}

	private function step_finalize( StagingJob $job, array $payload ): void {
		StagingSitesRepository::update( (int) $payload['staging_site_id'], array( 'last_synced_at' => current_time( 'mysql' ) ) );

		$job->mark_completed(
			array(
				'staging_site_id' => $payload['staging_site_id'],
				'pushed_to_live'  => true,
			)
		);

		Logger::info( 'Staging site #' . $payload['staging_site_id'] . ' pushed to live.', array(), $job->id() );
	}
}
