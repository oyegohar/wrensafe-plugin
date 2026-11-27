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
use WPBP\Utils\Errors;
use WPBP\Utils\Logger;
use WPBP\Utils\Str;
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
			Logger::error( 'Staging push job #' . $job->id() . ' failed: ' . Errors::text( $e ), array(), $job->id() );
			$job->mark_failed( Errors::text( $e ) );
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
		$staging_tables = self::pushable_tables(
			(string) $staging['table_prefix'],
			(array) $wpdb->get_col( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $staging['table_prefix'] ) . '%' ) )
		);

		$payload['staging_prefix'] = $staging['table_prefix'];
		$payload['staging_dir']    = $staging['path'];
		$payload['staging_url']    = $staging['url'];
		$payload['staging_tables'] = $staging_tables;
		// Read now: once the staging options table is pushed, home_url() and
		// blog_public return the staging copy's values (its address, and
		// "discourage search engines", which staging always sets).
		$payload['live_url']         = untrailingslashit( home_url() );
		$payload['live_blog_public'] = (string) get_option( 'blog_public', '1' );
		$payload['live_home']        = (string) get_option( 'home' );
		$payload['live_siteurl']     = (string) get_option( 'siteurl' );
		$payload['table_index']      = 0;
		$payload['content_offset']   = 0;
		$payload['phase']            = 'push_database';

		$job->set_payload( $payload );
		$job->mark_running();
		$job->update_progress( 5, __( 'Preparing to push staging to live…', 'wrensafe' ) );
	}

	private function step_database( StagingJob $job, array $payload ): void {
		global $wpdb;

		$tables = $payload['staging_tables'];
		$index  = $payload['table_index'];

		if ( $index >= count( $tables ) ) {
			// The pushed tables carry the staging site's prefixed keys (roles,
			// capabilities): back to this site's prefix.
			( new StagingCreator() )->rename_prefixed_keys( $wpdb->prefix, (string) $payload['staging_prefix'], $wpdb->prefix );

			( new StagingCreator() )->apply_exclusions(
				$wpdb->prefix,
				! empty( $payload['exclude_users'] ),
				! empty( $payload['exclude_comments'] ),
				! empty( $payload['exclude_orders'] )
			);

			// The live site keeps its search engine setting.
			if ( isset( $payload['live_blog_public'] ) ) {
				$wpdb->update( $wpdb->options, array( 'option_value' => (string) $payload['live_blog_public'] ), array( 'option_name' => 'blog_public' ) );
				wp_cache_delete( 'alloptions', 'options' );
				wp_cache_delete( 'blog_public', 'options' );
			}

			$payload['phase'] = 'push_files';
			$job->update_progress( 55, __( 'Database pushed to live.', 'wrensafe' ) );
			$job->set_payload( $payload );
			return;
		}

		$suffix   = substr( (string) $tables[ $index ], strlen( (string) $payload['staging_prefix'] ) );
		$user_id  = get_current_user_id();
		$sessions = null;

		// The push runs in the administrator's browser: keep their login
		// across the users' meta being replaced (as a restore does).
		if ( 'usermeta' === $suffix && $user_id > 0 ) {
			$sessions = $wpdb->get_var( $wpdb->prepare( 'SELECT meta_value FROM %i WHERE user_id = %d AND meta_key = %s LIMIT 1', $wpdb->usermeta, $user_id, 'session_tokens' ) );
		}

		( new StagingCreator() )->clone_table( $tables[ $index ], $payload['staging_prefix'], $wpdb->prefix );
		++$payload['table_index'];

		if ( 'options' === $suffix || 'usermeta' === $suffix ) {
			// Right away, not at the end: a push that stops half-way must not
			// leave the live site without its roles.
			( new StagingCreator() )->rename_prefixed_keys( $wpdb->prefix, (string) $payload['staging_prefix'], $wpdb->prefix );
		}

		if ( 'options' === $suffix ) {
			// The copy names the staging address; until the URLs are replaced
			// at the end, the live site (and the login cookies, named after
			// its address) would point at the staging copy.
			foreach ( array(
				'home'    => (string) ( $payload['live_home'] ?? '' ),
				'siteurl' => (string) ( $payload['live_siteurl'] ?? '' ),
			) as $name => $value ) {
				if ( '' !== $value ) {
					$wpdb->update( $wpdb->options, array( 'option_value' => $value ), array( 'option_name' => $name ) );
				}
			}

			wp_cache_delete( 'alloptions', 'options' );
		}

		if ( null !== $sessions ) {
			$wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE user_id = %d AND meta_key = %s', $wpdb->usermeta, $user_id, 'session_tokens' ) );
			$wpdb->query( $wpdb->prepare( 'INSERT INTO %i ( user_id, meta_key, meta_value ) VALUES ( %d, %s, %s )', $wpdb->usermeta, $user_id, 'session_tokens', (string) $sessions ) );
			wp_cache_delete( $user_id, 'user_meta' );
		}

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
					// The staging site's password guard stays on the staging site.
					if ( $file_info->isFile() && StagingProtection::GUARD_FILE !== $file_info->getFilename() ) {
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

		// Only the tables that were pushed hold the staging address. Every
		// other table with this site's prefix stays as it is: other staging
		// sites' tables ({prefix}stg_…, the one pushed from among them, whose
		// own address must stay the staging one) and Wrensafe's own tables.
		$live_tables = array();

		foreach ( (array) $payload['staging_tables'] as $staging_table ) {
			$live_tables[] = $wpdb->prefix . substr( (string) $staging_table, strlen( (string) $payload['staging_prefix'] ) );
		}

		$index = $payload['sr_table_index'];

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
			(string) ( $payload['live_url'] ?? untrailingslashit( home_url() ) )
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

	/**
	 * The staging tables to copy to the live site: every table with the
	 * staging prefix except the staging copy's own Wrensafe tables (pushing
	 * them would replace the live job list, this push's own job included)
	 * and tables a restore on the staging site left behind.
	 *
	 * @param string[] $tables
	 * @return string[]
	 */
	private static function pushable_tables( string $staging_prefix, array $tables ): array {
		$own = strtolower( $staging_prefix . 'wpbp_' );

		return array_values(
			array_filter(
				array_map( 'strval', $tables ),
				static function ( string $table ) use ( $staging_prefix, $own ): bool {
					return Str::starts_with( $table, $staging_prefix )
						&& ! Str::starts_with( strtolower( $table ), $own )
						&& ! Str::ends_with( $table, '_wpbptmp' )
						&& ! Str::ends_with( $table, '_wpbpold' )
						&& ! Str::ends_with( $table, \WPBP\Restore\RestoreTest::SUFFIX );
				}
			)
		);
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
