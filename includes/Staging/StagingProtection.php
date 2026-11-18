<?php
/**
 * Password protection and no-index enforcement for a staging site.
 *
 * Password protection uses HTTP Basic Auth via .htaccess, which works on
 * Apache/LiteSpeed. Nginx hosts need server-level rules instead — this is
 * called out in the Staging page UI.
 *
 * @package WPBP\Staging
 */

namespace WPBP\Staging;

defined( 'ABSPATH' ) || exit;

// Database access in this file is direct by design: Wrensafe's own tables (or,
// in the backup/restore engine, the site's tables being exported, imported or
// swapped) have no WordPress API, and the data must be read fresh, so it is not
// object-cached. Values go through $wpdb->prepare(); table names come from
// $wpdb->prefix with fixed suffixes and are escaped or quoted as identifiers.
// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

class StagingProtection {

	/**
	 * Write .htaccess + .htpasswd for HTTP Basic Auth protection.
	 */
	public function password_protect( string $staging_path, string $username, string $password ): void {
		$htpasswd_path = $staging_path . '.htpasswd';

		// bcrypt ($2y$, random salt) is understood by Apache 2.4's basic auth.
		$hashed = password_hash( $password, PASSWORD_BCRYPT );
		file_put_contents( $htpasswd_path, $username . ':' . $hashed . "\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents

		$htaccess_path = $staging_path . '.htaccess';
		$existing      = file_exists( $htaccess_path ) ? file_get_contents( $htaccess_path ) : ''; // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_read_file_get_contents

		$auth_block = "AuthType Basic\nAuthName \"Staging Site\"\nAuthUserFile " . $htpasswd_path . "\nRequire valid-user\n";

		file_put_contents( $htaccess_path, $auth_block . "\n" . $existing ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
	}

	public function remove_password_protection( string $staging_path ): void {
		$htpasswd_path = $staging_path . '.htpasswd';

		if ( file_exists( $htpasswd_path ) ) {
			wp_delete_file( $htpasswd_path );
		}
	}

	/**
	 * Disable search engine indexing on the staging copy by setting
	 * blog_public = 0 in its own (already-cloned) options table — WordPress
	 * core then automatically emits the noindex meta tag.
	 */
	public function disable_indexing( string $staging_prefix ): void {
		global $wpdb;

		$table = $staging_prefix . 'options';

		$wpdb->query(
			$wpdb->prepare(
				'UPDATE `' . esc_sql( $table ) . '` SET option_value = %s WHERE option_name = %s', // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				'0',
				'blog_public'
			)
		);
	}
}
