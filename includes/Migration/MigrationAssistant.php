<?php
/**
 * Smart Migration Assistant — scans the current site and recommends the
 * safest migration method for non-technical users.
 *
 * The assistant does not migrate anything itself; it returns a structured
 * recommendation the admin UI wizard can present step-by-step.
 *
 * @package WPBP\Migration
 */

namespace WPBP\Migration;

use WPBP\Utils\ServerInfo;

defined( 'ABSPATH' ) || exit;

// Database access in this file is direct by design: Wrensafe's own tables (or,
// in the backup/restore engine, the site's tables being exported, imported or
// swapped) have no WordPress API, and the data must be read fresh, so it is not
// object-cached. Values go through $wpdb->prepare(); table names come from
// $wpdb->prefix with fixed suffixes and are escaped or quoted as identifiers.
// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

class MigrationAssistant {

	// Migration method keys.
	const METHOD_DIRECT = 'direct';
	const METHOD_CLOUD  = 'cloud';
	const METHOD_MANUAL = 'manual';

	/** Site option holding the latest scan result so it persists across reloads. */
	const LATEST_RESULT_OPTION = 'wpbp_latest_migration_assistant_result';

	/** A scan older than this is run again where the readiness score is shown outside the Migration Assistant (one day). */
	const READINESS_MAX_AGE = 86400;

	/**
	 * The one migration readiness score Wrensafe shows: the Dashboard, System Info and the Migration Assistant all use
	 * its latest scan. With no scan yet, or one over a day old, the scan runs again here (quick: server limits, sizes
	 * from the database and a few folder levels) and is saved, so the Migration Assistant shows the same result.
	 *
	 * @return array{score:int,warnings:string[],scanned_at:int}
	 */
	public static function readiness(): array {
		$latest = self::latest_result();

		if ( null === $latest || (int) ( $latest['scanned_at'] ?? 0 ) < time() - self::READINESS_MAX_AGE ) {
			$assistant = new self();
			$scan      = $assistant->scan();
			$latest    = self::save_latest_result( $scan, $assistant->recommend( $scan ) );
		}

		return array(
			'score'      => (int) ( $latest['recommend']['score'] ?? 0 ),
			'warnings'   => array_values( array_map( 'strval', (array) ( $latest['recommend']['warnings'] ?? array() ) ) ),
			'scanned_at' => (int) ( $latest['scanned_at'] ?? 0 ),
		);
	}

	/**
	 * Persist the latest scan + recommendation so the Migration Assistant page
	 * can show it again until the user re-scans.
	 */
	public static function save_latest_result( array $scan, array $recommend ): array {
		$result = array(
			'scan'       => $scan,
			'recommend'  => $recommend,
			'scanned_at' => time(),
		);

		update_option( self::LATEST_RESULT_OPTION, $result, false );

		return $result;
	}

	/**
	 * The latest stored scan result, or null if the site has never been scanned.
	 *
	 * @return array|null
	 */
	public static function latest_result(): ?array {
		$result = get_option( self::LATEST_RESULT_OPTION );

		if ( ! is_array( $result ) || empty( $result['recommend'] ) ) {
			return null;
		}

		// Rebuild the advice from the stored scan, so it shows in the current
		// wording and the site's current language.
		$needed = array( 'disk_free_bytes', 'has_curl', 'has_multisite', 'has_woocommerce', 'has_zip', 'max_execution_time', 'memory_limit_bytes', 'site_size_bytes', 'ssl_enabled' );

		if ( isset( $result['scan'] ) && is_array( $result['scan'] ) && ! array_diff_key( array_flip( $needed ), $result['scan'] ) ) {
			$result['recommend'] = ( new self() )->recommend( $result['scan'] );
		}

		return $result;
	}

	// -----------------------------------------------------------------------
	// Public API.
	// -----------------------------------------------------------------------

	/**
	 * Scan the current site and collect facts relevant to migration planning.
	 *
	 * @return array{
	 *     memory_limit_bytes:int,
	 *     max_execution_time:int,
	 *     disk_free_bytes:int,
	 *     site_size_bytes:int,
	 *     db_size_bytes:int,
	 *     has_woocommerce:bool,
	 *     has_multisite:bool,
	 *     has_openssl:bool,
	 *     has_zip:bool,
	 *     has_curl:bool,
 *     has_http:bool,
	 *     table_count:int,
	 *     large_tables:string[],
	 *     upload_size_bytes:int,
	 *     wp_version:string,
	 *     php_version:string,
	 *     ssl_enabled:bool,
	 * }
	 */
	public function scan(): array {
		global $wpdb;

		$site_size    = $this->estimate_site_size();
		$upload_size  = $this->estimate_uploads_size();
		$db_size      = $this->db_size_bytes();
		$large_tables = $this->large_tables();

		return array(
			'memory_limit_bytes' => ServerInfo::memory_limit_bytes(),
			'max_execution_time' => ServerInfo::max_execution_time(),
			'disk_free_bytes'    => ServerInfo::disk_free_bytes(),
			'site_size_bytes'    => $site_size,
			'db_size_bytes'      => $db_size,
			'has_woocommerce'    => class_exists( 'WooCommerce' ),
			'has_multisite'      => is_multisite(),
			'has_openssl'        => ServerInfo::has_openssl(),
			'has_zip'            => ServerInfo::can_zip(),
			'has_curl'           => ServerInfo::has_curl(),
			// The HTTP API falls back to PHP streams without cURL.
			'has_http'           => wp_http_supports( array( 'ssl' ) ),
			'table_count'        => (int) $wpdb->get_var( 'SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE()' ),
			'large_tables'       => $large_tables,
			'upload_size_bytes'  => $upload_size,
			'wp_version'         => ServerInfo::wordpress_version(),
			'php_version'        => ServerInfo::php_version(),
			'ssl_enabled'        => ServerInfo::ssl_enabled(),
		);
	}

	/**
	 * Based on a scan result, recommend a migration method and return
	 * an ordered list of wizard steps plus any warnings.
	 *
	 * @param array $scan Result of scan().
	 * @return array{
	 *     method:string,
	 *     method_label:string,
	 *     warnings:string[],
	 *     steps:array[],
	 *     recommended_backup_type:string,
	 * }
	 */
	public function recommend( array $scan ): array {
		$warnings = array();
		$method   = self::METHOD_MANUAL;

		// Build warnings list.
		// -1 (no limit) and 0 (unknown) are not low limits.
		if ( $scan['memory_limit_bytes'] > 0 && $scan['memory_limit_bytes'] < 128 * MB_IN_BYTES ) {
			$warnings[] = __( 'The PHP memory limit is below 128 MB. A large site may run out of memory during a backup.', 'wrensafe' );
		}

		if ( $scan['max_execution_time'] > 0 && $scan['max_execution_time'] < 60 ) {
			$warnings[] = __( 'The PHP time limit is under 60 seconds. Wrensafe works in short steps, so backups still finish, but a higher limit makes large backups faster.', 'wrensafe' );
		}

		// 0 means the server doesn't report free space.
		if ( $scan['disk_free_bytes'] > 0 && $scan['disk_free_bytes'] < $scan['site_size_bytes'] * 2 ) {
			$warnings[] = __( 'There may not be enough free disk space for the site and a full backup at the same time.', 'wrensafe' );
		}

		if ( ! $scan['has_zip'] && ! class_exists( 'PharData' ) ) {
			$warnings[] = __( 'This server has neither ZipArchive nor PharData, so Wrensafe can’t create backup files here. Ask your host to turn on the PHP zip extension.', 'wrensafe' );
		}

		if ( ! self::can_send_requests( $scan ) ) {
			$warnings[] = __( 'This server can’t make HTTPS requests (PHP has neither cURL nor OpenSSL), so Direct Migration and importing a backup from a URL won’t work.', 'wrensafe' );
		}

		if ( $scan['has_multisite'] ) {
			$warnings[] = __( 'This is a WordPress Multisite network. Wrensafe supports single sites only, and restoring a Multisite database is blocked.', 'wrensafe' );
		}

		if ( ! $scan['ssl_enabled'] ) {
			$warnings[] = __( 'This site doesn’t use HTTPS, so a direct migration would send the backup unencrypted. Turn on HTTPS first, or download the backup and import it on the new site.', 'wrensafe' );
		}

		// Choose method.
		if ( self::can_send_requests( $scan ) && $scan['ssl_enabled'] ) {
			$method = self::METHOD_DIRECT;
		}

		/**
		 * Filters the recommended migration method (direct, cloud, manual).
		 * The free plugin recommends a direct transfer when the servers
		 * allow it and download → upload otherwise; an add-on can
		 * recommend a cloud-assisted migration.
		 *
		 * @param string $method
		 * @param array  $scan   Scan results.
		 */
		$method = (string) apply_filters( 'wpbp_migration_method', $method, $scan );

		$method_labels = array(
			self::METHOD_DIRECT => __( 'Direct site-to-site migration', 'wrensafe' ),
			self::METHOD_CLOUD  => __( 'Cloud-assisted migration', 'wrensafe' ),
			self::METHOD_MANUAL => __( 'Manual backup and import', 'wrensafe' ),
		);

		$recommended_type = $scan['has_woocommerce'] ? 'full' : (
			$scan['site_size_bytes'] > 500 * MB_IN_BYTES ? 'database' : 'full'
		);

		return array(
			'method'                  => $method,
			'method_label'            => $method_labels[ $method ],
			'score'                   => $this->readiness_score( $scan ),
			'reason'                  => $this->reason_for( $method ),
			'warnings'                => $warnings,
			'steps'                   => $this->wizard_steps( $method ),
			'recommended_backup_type' => $recommended_type,
		);
	}

	/**
	 * A 0–100 migration-readiness score, penalising the conditions that make
	 * a clean automated migration harder. Inability to write any archive at
	 * all is the heaviest penalty since it blocks backups entirely.
	 */
	private function readiness_score( array $scan ): int {
		$score = 100;

		if ( $scan['memory_limit_bytes'] > 0 && $scan['memory_limit_bytes'] < 128 * MB_IN_BYTES ) {
			$score -= 10;
		}

		if ( $scan['max_execution_time'] > 0 && $scan['max_execution_time'] < 60 ) {
			$score -= 5;
		}

		if ( $scan['disk_free_bytes'] > 0 && $scan['disk_free_bytes'] < $scan['site_size_bytes'] * 2 ) {
			$score -= 10;
		}

		if ( ! $scan['has_zip'] && ! class_exists( 'PharData' ) ) {
			$score -= 40;
		}

		if ( ! self::can_send_requests( $scan ) ) {
			$score -= 15;
		}

		if ( ! $scan['ssl_enabled'] ) {
			$score -= 10;
		}

		if ( $scan['has_multisite'] ) {
			$score -= 5;
		}

		return (int) max( 0, min( 100, $score ) );
	}

	private function reason_for( string $method ): string {
		switch ( $method ) {
			case self::METHOD_DIRECT:
				return __( 'This site uses HTTPS and this server can make HTTPS requests, so sending the backup straight to the new site is the fastest option.', 'wrensafe' );
			case self::METHOD_CLOUD:
				return __( 'This site doesn’t use HTTPS, so sending the backup through cloud storage is safer than an unencrypted direct transfer.', 'wrensafe' );
			default:
				return __( 'A direct transfer needs HTTPS on this site and a server that can make HTTPS requests, and one of them is missing. Download a backup and import it on the new site instead. This works on any server.', 'wrensafe' );
		}
	}

	// -----------------------------------------------------------------------
	// Private helpers.
	// -----------------------------------------------------------------------

	/**
	 * Whether this server can send the HTTPS requests Direct Migration and URL
	 * imports make. WordPress uses cURL when it is there and PHP streams
	 * otherwise. Scans saved before 1.1.4 only have has_curl.
	 */
	private static function can_send_requests( array $scan ): bool {
		return isset( $scan['has_http'] ) ? (bool) $scan['has_http'] : ! empty( $scan['has_curl'] );
	}

	private function wizard_steps( string $method ): array {
		$steps = array(
			array(
				'id'    => 'scan',
				'label' => __( 'Scan the site', 'wrensafe' ),
				'done'  => true,
			),
			array(
				'id'    => 'detect',
				'label' => __( 'Check server limits', 'wrensafe' ),
				'done'  => true,
			),
			array(
				'id'    => 'recommend',
				'label' => __( 'Recommend a migration method', 'wrensafe' ),
				'done'  => true,
			),
			array(
				'id'    => 'backup',
				'label' => __( 'Create a backup', 'wrensafe' ),
				'done'  => false,
			),
		);

		if ( self::METHOD_DIRECT === $method ) {
			$steps[] = array(
				'id'    => 'key',
				'label' => __( 'Generate a migration key on the new site', 'wrensafe' ),
				'done'  => false,
			);
			$steps[] = array(
				'id'    => 'transfer',
				'label' => __( 'Send the backup to the new site', 'wrensafe' ),
				'done'  => false,
			);
		} elseif ( self::METHOD_CLOUD === $method ) {
			$steps[] = array(
				'id'    => 'upload',
				'label' => __( 'Upload the backup to cloud storage', 'wrensafe' ),
				'done'  => false,
			);
			$steps[] = array(
				'id'    => 'download',
				'label' => __( 'Download it on the new site', 'wrensafe' ),
				'done'  => false,
			);
		} else {
			$steps[] = array(
				'id'    => 'download',
				'label' => __( 'Download the backup to your computer', 'wrensafe' ),
				'done'  => false,
			);
			$steps[] = array(
				'id'    => 'upload',
				'label' => __( 'Upload it on the new site’s Import / Restore page', 'wrensafe' ),
				'done'  => false,
			);
		}

		$steps[] = array(
			'id'    => 'replace',
			'label' => __( 'Restore it there (links and paths are updated for the new address)', 'wrensafe' ),
			'done'  => false,
		);
		$steps[] = array(
			'id'    => 'test',
			'label' => __( 'Check the new site', 'wrensafe' ),
			'done'  => false,
		);
		$steps[] = array(
			'id'    => 'finalize',
			'label' => __( 'When everything works, point your domain to the new site', 'wrensafe' ),
			'done'  => false,
		);

		return $steps;
	}

	private function estimate_site_size(): int {
		$size = 0;

		foreach ( array( \WPBP\Utils\Paths::root(), \WPBP\Utils\Paths::content_dir() ) as $dir ) {
			if ( is_dir( $dir ) ) {
				$size += $this->directory_size( $dir, 3 );
			}
		}

		return $size;
	}

	private function estimate_uploads_size(): int {
		$uploads = \WPBP\Utils\Paths::uploads_dir();
		return '' !== $uploads && is_dir( $uploads ) ? $this->directory_size( $uploads, 4 ) : 0;
	}

	/**
	 * Estimate directory size without recursing more than $max_depth levels.
	 */
	private function directory_size( string $dir, int $max_depth ): int {
		if ( $max_depth <= 0 || ! is_dir( $dir ) ) {
			return 0;
		}

		$size = 0;
		$fh   = opendir( $dir );

		if ( ! $fh ) {
			return 0;
		}

		for ( $entry = readdir( $fh ); false !== $entry; $entry = readdir( $fh ) ) {
			if ( '.' === $entry || '..' === $entry ) {
				continue;
			}

			$path = $dir . '/' . $entry;

			if ( is_file( $path ) ) {
				$size += (int) filesize( $path );
			} elseif ( is_dir( $path ) ) {
				$size += $this->directory_size( $path, $max_depth - 1 );
			}
		}

		closedir( $fh );
		return $size;
	}

	private function db_size_bytes(): int {
		global $wpdb;

		$result = $wpdb->get_var(
			'SELECT SUM(data_length + index_length) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE()'
		);

		return (int) $result;
	}

	/**
	 * Return names of tables over 50 MB.
	 *
	 * @return string[]
	 */
	private function large_tables(): array {
		global $wpdb;

		$rows = $wpdb->get_results(
			'SELECT TABLE_NAME, ROUND((data_length + index_length) / 1048576, 1) AS size_mb
			 FROM information_schema.TABLES
			 WHERE TABLE_SCHEMA = DATABASE() AND (data_length + index_length) > 52428800
			 ORDER BY (data_length + index_length) DESC
			 LIMIT 10',
			ARRAY_A
		);

		return array_column( (array) $rows, 'TABLE_NAME' );
	}
}
