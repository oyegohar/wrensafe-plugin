<?php
/**
 * File and database cloning primitives used to build a staging copy.
 *
 * A staging site lives in its own subdirectory with a full copy of
 * WordPress core and wp-content, but reuses the *same* database — its
 * tables just use a different prefix. This avoids needing separate
 * database credentials and keeps everything inside one hosting account.
 *
 * @package WPBP\Staging
 */

namespace WPBP\Staging;

use WPBP\Utils\Paths;

defined( 'ABSPATH' ) || exit;

// Database access in this file is direct by design: Wrensafe's own tables (or,
// in the backup/restore engine, the site's tables being exported, imported or
// swapped) have no WordPress API, and the data must be read fresh, so it is not
// object-cached. Every query goes through $wpdb->prepare(), with table names as
// identifier placeholders (%i).
// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange

class StagingCreator {

	private const FILES_PER_CHUNK = 50;

	/**
	 * Why this site can't get a staging copy, or '' when it can.
	 *
	 * A staging site is a copy inside a subfolder of the WordPress folder,
	 * with its content folder at the same place relative to it. That needs
	 * the content folder inside the WordPress folder, and the plugins,
	 * must-use plugins, themes and uploads folders inside the content folder.
	 */
	public static function layout_problem(): string {
		$content = Paths::content_dir();
		$inside  = '' !== Paths::relative_to_root( $content );

		foreach ( array( Paths::plugins_dir(), Paths::mu_plugins_dir(), Paths::themes_dir(), Paths::uploads_dir() ) as $dir ) {
			if ( '' !== $dir && ! Paths::is_within( $dir, $content ) ) {
				$inside = false;
			}
		}

		return $inside ? '' : __( 'Staging needs the content folder (wp-content) inside the WordPress folder, with the plugins, themes and uploads folders inside it. This site keeps them elsewhere, so a staging copy can’t be made safely.', 'wrensafe' );
	}

	/**
	 * The staging site's content folder: at the same place inside its
	 * folder as this site's content folder is inside the WordPress folder.
	 */
	public static function staging_content_dir( string $staging_dir ): string {
		return untrailingslashit( $staging_dir ) . '/' . Paths::relative_to_root( Paths::content_dir() );
	}

	/**
	 * List every file in the WordPress folder except the content folder
	 * (copied separately), staging sites and Wrensafe's own files, as
	 * "relative/path" strings.
	 */
	public function list_core_files(): array {
		$skip = array( Paths::relative_to_root( Paths::content_dir() ) );

		// Other staging sites live in the WordPress folder too.
		foreach ( (array) glob( Paths::root() . '/staging-*', GLOB_ONLYDIR ) as $dir ) {
			$skip[] = basename( (string) $dir );
		}

		return $this->list_files_excluding( Paths::root(), array( '.git', 'node_modules' ), array_filter( $skip ) );
	}

	/**
	 * List every file in the content folder except Wrensafe's own storage
	 * folder and the page cache (cache/ at the top of the content folder;
	 * plugins ship code in folders called "cache" too, so not by name).
	 */
	public function list_content_files(): array {
		return $this->list_files_excluding( Paths::content_dir(), array( '.git', 'node_modules' ), array( 'cache', WPBP_STORAGE_DIR_NAME, WPBP_LEGACY_STORAGE_DIR_NAME ) );
	}

	/**
	 * @param string[] $excluded_dirs  Folder names skipped wherever they are.
	 * @param string[] $excluded_paths Paths relative to $root skipped with everything inside.
	 */
	private function list_files_excluding( string $root, array $excluded_dirs, array $excluded_paths = array() ): array {
		$files = array();

		if ( ! is_dir( $root ) ) {
			return $files;
		}

		$iterator = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator( $root, \FilesystemIterator::SKIP_DOTS )
		);

		// Wrensafe's storage folder may be anywhere inside the site.
		$storage = array(
			trailingslashit( wp_normalize_path( \WPBP\Utils\FileSystem::storage_dir() ) ),
			trailingslashit( wp_normalize_path( \WPBP\Utils\FileSystem::default_storage_dir() ) ),
		);

		foreach ( $iterator as $file_info ) {
			if ( ! $file_info->isFile() ) {
				continue;
			}

			$normalized = wp_normalize_path( $file_info->getPathname() );

			if ( 0 === strpos( $normalized, $storage[0] ) || 0 === strpos( $normalized, $storage[1] ) ) {
				continue;
			}

			$relative = ltrim( substr( $normalized, strlen( wp_normalize_path( $root ) ) ), '/' );
			$segments = explode( '/', $relative );

			if ( array_intersect( $segments, $excluded_dirs ) ) {
				continue;
			}

			foreach ( $excluded_paths as $excluded ) {
				if ( 0 === strpos( $relative, $excluded . '/' ) ) {
					continue 2;
				}
			}

			$files[] = $relative;
		}

		return $files;
	}

	/**
	 * Copy one bounded batch of files from $source_root to $destination_root.
	 *
	 * @return int Number of files copied in this batch.
	 */
	public function copy_batch( string $source_root, string $destination_root, array $files, int $offset ): int {
		$count = 0;
		$total = count( $files );

		while ( $count < self::FILES_PER_CHUNK && ( $offset + $count ) < $total ) {
			$relative    = $files[ $offset + $count ];
			$source      = untrailingslashit( $source_root ) . '/' . $relative;
			$destination = untrailingslashit( $destination_root ) . '/' . $relative;

			wp_mkdir_p( dirname( $destination ) );
			copy( $source, $destination );

			++$count;
		}

		return $count;
	}

	/**
	 * Clone one table's structure and data into a new table under a
	 * different prefix, e.g. wp_posts -> wp_staging_abc123_posts.
	 */
	public function clone_table( string $source_table, string $live_prefix, string $staging_prefix ): string {
		global $wpdb;

		$suffix        = substr( $source_table, strlen( $live_prefix ) );
		$staging_table = $staging_prefix . $suffix;

		$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', $staging_table ) );
		$wpdb->query( $wpdb->prepare( 'CREATE TABLE %i LIKE %i', $staging_table, $source_table ) );
		$wpdb->query( $wpdb->prepare( 'INSERT INTO %i SELECT * FROM %i', $staging_table, $source_table ) );

		return $staging_table;
	}

	/**
	 * Apply "exclude orders/users/comments" options by truncating the
	 * relevant staging tables after cloning, keeping the schema intact.
	 */
	public function apply_exclusions( string $staging_prefix, bool $exclude_users, bool $exclude_comments, bool $exclude_orders ): void {
		global $wpdb;

		// The keys and salts WordPress may keep in the options table: the
		// staging site makes its own on first use.
		$names = Paths::SECRET_KEY_NAMES;
		$wpdb->query(
			$wpdb->prepare(
				'DELETE FROM %i WHERE option_name IN (' . implode( ', ', array_fill( 0, count( $names ), '%s' ) ) . ')',
				array_merge( array( $staging_prefix . 'options' ), $names )
			)
		);

		if ( $exclude_comments ) {
			$this->truncate_if_exists( $staging_prefix . 'comments' );
			$this->truncate_if_exists( $staging_prefix . 'commentmeta' );
		}

		if ( $exclude_users ) {
			// Keep schema but remove everyone except the current admin to avoid locking staging out entirely.
			$current_user_id = get_current_user_id();
			$wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE ID != %d', $staging_prefix . 'users', $current_user_id ) );
			$wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE user_id != %d', $staging_prefix . 'usermeta', $current_user_id ) );
		}

		if ( $exclude_orders ) {
			$this->truncate_if_exists( $staging_prefix . 'wc_orders' );
			$this->truncate_if_exists( $staging_prefix . 'wc_order_stats' );
			$this->truncate_if_exists( $staging_prefix . 'woocommerce_order_items' );
		}
	}

	private function truncate_if_exists( string $table ): void {
		global $wpdb;

		// esc_like(): "_" in a table name is a LIKE wildcard otherwise.
		$exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) );

		if ( $exists === $table ) {
			$wpdb->query( $wpdb->prepare( 'TRUNCATE TABLE %i', $table ) );
		}
	}

	/**
	 * Write the staging site's wp-config.php: a copy of this site's, on the
	 * same server, with the staging table prefix, its own folder and address
	 * wherever the original names this site's folder or address, and its own
	 * new authentication keys and salts (so a login cookie of one site is
	 * never valid on the other). The database settings stay the same, since
	 * staging shares the live database.
	 */
	public function write_staging_config( string $destination_path, string $staging_prefix, string $staging_url = '' ): bool {
		$source = Paths::config_file();

		if ( '' === $source ) {
			return false;
		}

		$contents = (string) file_get_contents( $source ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_read_file_get_contents

		// A PHP expression up to the end of its statement: plain text and
		// complete quoted strings (which may contain ";"), as in
		// 'abc', getenv( 'X' ) or getenv_docker( 'X', 'wp_' ).
		$expression = '(?:[^;\'"]|\'(?:\\\\.|[^\'\\\\])*\'|"(?:\\\\.|[^"\\\\])*")*';

		// The staging table prefix, whatever form the original takes. Without
		// it the staging copy would run on the live site's tables, so the
		// staging site is not created then.
		$count    = 0;
		$contents = (string) preg_replace(
			'/\$table_prefix\s*=' . $expression . ';/',
			'$table_prefix = \'' . $staging_prefix . '\';',
			$contents,
			1,
			$count
		);

		if ( 1 !== $count ) {
			return false;
		}

		// Folder settings written as full paths (WP_CONTENT_DIR, …).
		$contents = str_replace( Paths::root() . '/', trailingslashit( wp_normalize_path( $destination_path ) ), $contents );

		// Address settings (WP_HOME, WP_SITEURL, WP_CONTENT_URL, …).
		if ( '' !== $staging_url ) {
			$contents = (string) preg_replace_callback(
				'/^\s*define\s*\(\s*[\'"](?:WP_HOME|WP_SITEURL|WP_CONTENT_URL|WP_PLUGIN_URL|WPMU_PLUGIN_URL)[\'"].*$/m',
				static function ( array $line ) use ( $staging_url ): string {
					return str_replace( untrailingslashit( home_url() ), untrailingslashit( $staging_url ), $line[0] );
				},
				$contents
			);
		}

		// New keys and salts, whatever form the original values take.
		// wp_generate_password() never returns a quote or a backslash, so
		// each value is safe inside the quoted string.
		$contents = (string) preg_replace_callback(
			'/define\s*\(\s*([\'"])(' . implode( '|', array_map( 'strtoupper', Paths::SECRET_KEY_NAMES ) ) . ')\1\s*,' . $expression . ';/',
			static function ( array $found ): string {
				return "define( '" . $found[2] . "', '" . wp_generate_password( 64, true, true ) . "' );";
			},
			$contents
		);

		return false !== file_put_contents( trailingslashit( $destination_path ) . 'wp-config.php', $contents ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
	}
}
