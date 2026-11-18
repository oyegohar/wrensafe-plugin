<?php
/**
 * Pre-migration / general readiness health check with a 0-100 score.
 *
 * @package WPBP\Migration
 */

namespace WPBP\Migration;

use WPBP\Utils\FileSystem;

defined( 'ABSPATH' ) || exit;

// Database access in this file is direct by design: Wrensafe's own tables (or,
// in the backup/restore engine, the site's tables being exported, imported or
// swapped) have no WordPress API, and the data must be read fresh, so it is not
// object-cached. Values go through $wpdb->prepare(); table names come from
// $wpdb->prefix with fixed suffixes and are escaped or quoted as identifiers.
// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

class HealthCheck {

	private int $score    = 100;
	private array $checks = array();

	public function run(): array {
		$this->score  = 100;
		$this->checks = array();

		$this->check_php_version();
		$this->check_database_version();
		$this->check_memory_limit();
		$this->check_execution_time();
		$this->check_disk_space();
		$this->check_storage_writable();
		$this->check_archive_backend();
		$this->check_curl();
		$this->check_openssl();
		$this->check_wp_cron();
		$this->check_ssl();
		$this->check_large_tables();
		$this->check_large_uploads();
		$this->check_cache_folders();
		$this->check_storage_protection();

		return array(
			'score'  => max( 0, $this->score ),
			'checks' => $this->checks,
		);
	}

	private function add( string $label, string $status, string $message, int $deduct = 0 ): void {
		$this->checks[] = array(
			'label'   => $label,
			'status'  => $status,
			'message' => $message,
		);
		$this->score   -= $deduct;
	}

	private function check_php_version(): void {
		// Mirrors WordPress core's own recommendation (PHP 8.3+). Older
		// supported versions (7.4 – 8.2) still run Wrensafe.
		if ( version_compare( PHP_VERSION, '8.3', '>=' ) ) {
			$this->add( __( 'PHP version', 'wrensafe' ), 'pass', PHP_VERSION );
		} else {
			/* translators: %s: PHP version number */
			$this->add( __( 'PHP version', 'wrensafe' ), 'warning', sprintf( __( 'PHP %s works, but PHP 8.3 or newer is recommended for speed and security updates.', 'wrensafe' ), PHP_VERSION ), 5 );
		}
	}

	private function check_database_version(): void {
		global $wpdb;

		$version = $wpdb->db_version();

		if ( version_compare( $version, '5.6', '>=' ) ) {
			$this->add( __( 'Database version', 'wrensafe' ), 'pass', $version );
		} else {
			$this->add( /* translators: %s: database server version */ __( 'Database version', 'wrensafe' ), 'warning', sprintf( __( '%s is older than recommended.', 'wrensafe' ), $version ), 5 );
		}
	}

	private function check_memory_limit(): void {
		$limit = wp_convert_hr_to_bytes( ini_get( 'memory_limit' ) );

		if ( $limit <= 0 ) {
			$this->add( __( 'Memory limit', 'wrensafe' ), 'pass', __( 'Unlimited', 'wrensafe' ) );
		} elseif ( $limit >= 134217728 ) {
			$this->add( __( 'Memory limit', 'wrensafe' ), 'pass', size_format( $limit ) );
		} elseif ( $limit >= 67108864 ) {
			$this->add( __( 'Memory limit', 'wrensafe' ), 'warning', size_format( $limit ) . ' — ' . __( '128M+ recommended.', 'wrensafe' ), 5 );
		} else {
			$this->add( __( 'Memory limit', 'wrensafe' ), 'fail', size_format( $limit ) . ' — ' . __( 'too low for large sites.', 'wrensafe' ), 10 );
		}
	}

	private function check_execution_time(): void {
		$limit = (int) ini_get( 'max_execution_time' );

		if ( 0 === $limit || $limit >= 60 ) {
			$this->add( __( 'Max execution time', 'wrensafe' ), 'pass', 0 === $limit ? __( 'Unlimited', 'wrensafe' ) : $limit . 's' );
		} else {
			$this->add( __( 'Max execution time', 'wrensafe' ), 'warning', $limit . 's — ' . __( 'the chunked engine adapts, but very low limits slow large sites down.', 'wrensafe' ), 5 );
		}
	}

	private function check_disk_space(): void {
		$free = \WPBP\Utils\ServerInfo::disk_free_bytes(); // On the disk backups are written to.

		if ( 0 === $free ) {
			$this->add( __( 'Disk space', 'wrensafe' ), 'warning', __( 'Could not determine free disk space.', 'wrensafe' ), 0 );
			return;
		}

		if ( $free >= 2147483648 ) {
			$this->add( __( 'Disk space', 'wrensafe' ), 'pass', size_format( $free ) . ' ' . __( 'free', 'wrensafe' ) );
		} elseif ( $free >= 524288000 ) {
			$this->add( __( 'Disk space', 'wrensafe' ), 'warning', size_format( $free ) . ' ' . __( 'free — getting low.', 'wrensafe' ), 5 );
		} else {
			$this->add( __( 'Disk space', 'wrensafe' ), 'fail', size_format( $free ) . ' ' . __( 'free — critically low.', 'wrensafe' ), 10 );
		}
	}

	private function check_storage_writable(): void {
		if ( wp_is_writable( FileSystem::storage_dir() ) ) {
			$this->add( __( 'Storage folder writable', 'wrensafe' ), 'pass', FileSystem::storage_dir() );
		} else {
			$this->add( __( 'Storage folder writable', 'wrensafe' ), 'fail', __( 'Wrensafe cannot write to its storage folder.', 'wrensafe' ), 15 );
		}
	}

	private function check_archive_backend(): void {
		if ( class_exists( '\ZipArchive' ) ) {
			$this->add( __( 'Archive engine', 'wrensafe' ), 'pass', 'ZipArchive' );
		} elseif ( class_exists( '\PharData' ) ) {
			$this->add( __( 'Archive engine', 'wrensafe' ), 'warning', __( 'Using Phar fallback — ZipArchive is recommended.', 'wrensafe' ), 3 );
		} else {
			$this->add( __( 'Archive engine', 'wrensafe' ), 'fail', __( 'Neither ZipArchive nor Phar is available — backups cannot be created.', 'wrensafe' ), 20 );
		}
	}

	private function check_curl(): void {
		if ( function_exists( 'curl_version' ) ) {
			$this->add( __( 'cURL', 'wrensafe' ), 'pass', __( 'Available', 'wrensafe' ) );
		} else {
			$this->add( __( 'cURL', 'wrensafe' ), 'warning', __( 'Not available — Direct Migration and importing a backup from a URL won\'t work.', 'wrensafe' ), 5 );
		}
	}

	private function check_openssl(): void {
		if ( extension_loaded( 'openssl' ) ) {
			$this->add( __( 'OpenSSL', 'wrensafe' ), 'pass', __( 'Available', 'wrensafe' ) );
		} else {
			$this->add( __( 'OpenSSL', 'wrensafe' ), 'warning', __( 'Not available — encrypted backups can\'t be created or restored.', 'wrensafe' ), 5 );
		}
	}

	private function check_wp_cron(): void {
		if ( defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON ) {
			$this->add( __( 'WP-Cron', 'wrensafe' ), 'warning', __( 'Disabled — make sure a real server cron calls wp-cron.php, or scheduled backups will not run.', 'wrensafe' ), 5 );
		} else {
			$this->add( __( 'WP-Cron', 'wrensafe' ), 'pass', __( 'Enabled', 'wrensafe' ) );
		}
	}

	private function check_ssl(): void {
		if ( str_starts_with( home_url(), 'https://' ) ) {
			$this->add( __( 'SSL', 'wrensafe' ), 'pass', __( 'HTTPS enabled', 'wrensafe' ) );
		} else {
			$this->add( __( 'SSL', 'wrensafe' ), 'warning', __( 'This site is not using HTTPS — direct migration transfers will be unencrypted.', 'wrensafe' ), 5 );
		}
	}

	private function check_large_tables(): void {
		global $wpdb;

		$threshold = 104857600; // 100MB.

		// information_schema column names are returned UPPER-CASE on MySQL 8 (and
		// some MariaDB configs), so SELECT table_name yields a `TABLE_NAME`
		// property and wp_list_pluck( $rows, 'table_name' ) hit "Undefined
		// property: stdClass::$table_name". Aliasing to a known lower-case name
		// fixes the data shape at the source; the isset() guard below also
		// tolerates any malformed row.
		$large = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT table_name AS wpbp_table_name, data_length + index_length AS wpbp_size FROM information_schema.TABLES WHERE table_schema = %s AND (data_length + index_length) > %d',
				$wpdb->dbname,
				$threshold
			)
		);

		$names = array();
		foreach ( (array) $large as $row ) {
			if ( is_object( $row ) && isset( $row->wpbp_table_name ) ) {
				$names[] = $row->wpbp_table_name;
			}
		}

		if ( empty( $names ) ) {
			$this->add( __( 'Large tables', 'wrensafe' ), 'pass', __( 'None found.', 'wrensafe' ) );
		} else {
			$this->add( /* translators: %s: comma-separated list of table names */ __( 'Large tables', 'wrensafe' ), 'warning', sprintf( __( 'Large tables found: %s. Backups and restores of this site take longer.', 'wrensafe' ), implode( ', ', $names ) ), 3 );
		}
	}

	private function check_large_uploads(): void {
		$uploads = wp_upload_dir();
		$size    = 0;

		if ( is_dir( $uploads['basedir'] ) ) {
			foreach ( new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $uploads['basedir'], \FilesystemIterator::SKIP_DOTS ) ) as $file ) {
				if ( $file->isFile() ) {
					$size += $file->getSize();
				}
			}
		}

		if ( $size < 2147483648 ) {
			$this->add( __( 'Uploads folder size', 'wrensafe' ), 'pass', size_format( $size ) );
		} else {
			$this->add( __( 'Uploads folder size', 'wrensafe' ), 'warning', size_format( $size ) . ' — ' . __( 'large uploads folder, backups may take a while.', 'wrensafe' ), 3 );
		}
	}

	private function check_cache_folders(): void {
		$this->add(
			__( 'Cache folders', 'wrensafe' ),
			'pass',
			__( 'Common cache folders are automatically excluded from backups.', 'wrensafe' )
		);
	}

	private function check_storage_protection(): void {
		$base = FileSystem::storage_dir();

		if ( file_exists( $base . '.htaccess' ) && file_exists( $base . 'index.php' ) ) {
			$this->add( __( 'Backup folder protection', 'wrensafe' ), 'pass', __( 'Protected.', 'wrensafe' ) );
		} else {
			$this->add( __( 'Backup folder protection', 'wrensafe' ), 'fail', __( 'Protection files are missing from the storage folder.', 'wrensafe' ), 10 );
		}
	}
}
