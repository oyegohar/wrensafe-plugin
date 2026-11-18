<?php
/**
 * Backup Migration (backup-backup): one .zip — the site under wordpress/,
 * one SQL file per table under db_tables/, and bmi_backup_manifest.json.
 *
 * Each table file creates the table under a temporary name
 * ("1794399448_wp_posts") and names the real table in a header comment;
 * sql_table_renames() maps them back.
 *
 * The manifest also holds the source site's wp-config values (database
 * password, salts): only the fields below are read, nothing else is kept.
 *
 * @package WPBP\Import\Foreign
 */

namespace WPBP\Import\Foreign\Formats;

use WPBP\Import\Foreign\AbstractFormat;

defined( 'ABSPATH' ) || exit;

// Exception messages reach the browser as JSON shown with jQuery .text().
// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped

class BackupMigration extends AbstractFormat {

	public function key(): string {
		return 'backup-migration';
	}

	public function name(): string {
		return 'Backup Migration';
	}

	public function matches( array $files ): bool {
		return null !== $this->archive( $files );
	}

	public function describe( array $files ): array {
		$path = $this->archive( $files );

		if ( null === $path ) {
			throw new \RuntimeException( __( 'No Backup Migration archive was found.', 'wrensafe' ) );
		}

		$manifest = json_decode( (string) self::zip_entry( $path, 'bmi_backup_manifest.json' ), true );
		$manifest = is_array( $manifest ) ? $manifest : array();

		if ( ! empty( $manifest['is_encrypted'] ) ) {
			throw new \RuntimeException( __( 'This Backup Migration backup is encrypted. Make an unencrypted backup in Backup Migration, then upload that one.', 'wrensafe' ) );
		}

		$url = untrailingslashit( (string) ( $manifest['dbdomain'] ?? '' ) );

		return array(
			'sources'  => array(
				array(
					'type' => 'zip',
					'file' => $path,
					'base' => 'root',
				),
			),
			'meta'     => array_filter(
				array(
					'site_url'     => $url,
					'home_url'     => $url,
					'prefix'       => (string) ( $manifest['config']['table_prefix'] ?? '' ),
					'abspath'      => (string) ( $manifest['config']['ABSPATH'] ?? '' ),
					'is_multisite' => ! empty( $manifest['multisite'] ),
					'created_at'   => preg_match( '/^(\d{4}-\d{2}-\d{2}) (\d{2}:\d{2}:\d{2})$/', (string) ( $manifest['date'] ?? '' ), $m ) ? $m[1] . 'T' . $m[2] : '',
				)
			),
			'warnings' => array(),
		);
	}

	public function classify( array $source, string $path ): array {
		if ( preg_match( '#^db_tables/[^/]+\.sql$#i', $path ) ) {
			return array( 'sql' );
		}

		if ( 0 === strpos( $path, 'wordpress/' ) ) {
			return $this->root_target( substr( $path, 10 ) );
		}

		return array( 'skip' );
	}

	public function sql_table_renames( string $head ): array {
		if ( preg_match( '/REAL_TABLE_NAME:\s*`([^`]+)`/', $head, $real ) && preg_match( '/PRE_TABLE_NAME:\s*`([^`]+)`/', $head, $pre ) ) {
			return array( $pre[1] => $real[1] );
		}

		return array();
	}

	/** @param array<string,string> $files */
	private function archive( array $files ): ?string {
		foreach ( $files as $name => $path ) {
			if ( 'zip' === self::ext( $name ) && ( preg_match( '/^BM_.+\.zip$/i', $name ) || null !== self::zip_entry( $path, 'bmi_backup_manifest.json' ) ) ) {
				return $path;
			}
		}

		return null;
	}
}

// phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped
