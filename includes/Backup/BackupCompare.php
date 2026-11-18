<?php
/**
 * Compares two .wbp backups and returns a structured diff.
 *
 * Opens both archives' manifests and checksums files via ArchiveReader, then
 * reports added, removed, and changed files plus metadata differences
 * (plugin versions, theme versions, WordPress version, archive size).
 *
 * @package WPBP\Backup
 */

namespace WPBP\Backup;

use WPBP\Core\Database\BackupsRepository;
use WPBP\Restore\ArchiveReader;

defined( 'ABSPATH' ) || exit;

class BackupCompare {

	/**
	 * Compare two backups by ID.
	 *
	 * @param int $id_a Older backup (baseline).
	 * @param int $id_b Newer backup (comparison target).
	 * @return array{
	 *     added:string[],
	 *     removed:string[],
	 *     modified:string[],
	 *     unchanged_count:int,
	 *     size_diff:int,
	 *     metadata:array,
	 *     plugin_changes:array,
	 *     theme_changes:array,
	 *     wp_version_changed:bool,
	 *     error:string
	 * }
	 */
	public function compare( int $id_a, int $id_b ): array {
		$backup_a = BackupsRepository::find( $id_a );
		$backup_b = BackupsRepository::find( $id_b );

		$empty = array(
			'added'              => array(),
			'removed'            => array(),
			'modified'           => array(),
			'unchanged_count'    => 0,
			'size_diff'          => 0,
			'metadata'           => array(),
			'plugin_changes'     => array(),
			'theme_changes'      => array(),
			'wp_version_changed' => false,
			'error'              => '',
		);

		if ( ! $backup_a || ! file_exists( $backup_a['file_path'] ) ) {
			return array_merge( $empty, array( 'error' => __( 'Baseline backup file not found.', 'wrensafe' ) ) );
		}

		if ( ! $backup_b || ! file_exists( $backup_b['file_path'] ) ) {
			return array_merge( $empty, array( 'error' => __( 'Comparison backup file not found.', 'wrensafe' ) ) );
		}

		$reader_a = new ArchiveReader( $backup_a['file_path'] );
		$reader_b = new ArchiveReader( $backup_b['file_path'] );

		$checksums_a = $reader_a->read_json( 'checksums.json' ) ?? array();
		$checksums_b = $reader_b->read_json( 'checksums.json' ) ?? array();
		$manifest_a  = $reader_a->read_json( 'manifest.json' ) ?? array();
		$manifest_b  = $reader_b->read_json( 'manifest.json' ) ?? array();

		// File diff — checksums.json is {"files": {entry_name: checksum}, "database": ""},
		// so the per-file map (not the "files"/"database" top-level keys) is what's compared.
		$files_a = is_array( $checksums_a['files'] ?? null ) ? $checksums_a['files'] : array();
		$files_b = is_array( $checksums_b['files'] ?? null ) ? $checksums_b['files'] : array();

		$keys_a = array_keys( $files_a );
		$keys_b = array_keys( $files_b );

		$added   = array_values( array_diff( $keys_b, $keys_a ) );
		$removed = array_values( array_diff( $keys_a, $keys_b ) );
		$common  = array_intersect( $keys_a, $keys_b );

		$modified        = array();
		$unchanged_count = 0;

		foreach ( $common as $file ) {
			if ( $files_a[ $file ] !== $files_b[ $file ] ) {
				$modified[] = $file;
			} else {
				++$unchanged_count;
			}
		}

		// Plugin version changes.
		$plugins_a      = $manifest_a['plugins'] ?? array();
		$plugins_b      = $manifest_b['plugins'] ?? array();
		$plugin_changes = $this->compare_versions( $plugins_a, $plugins_b );

		// Theme version changes.
		$themes_a      = $manifest_a['themes'] ?? array();
		$themes_b      = $manifest_b['themes'] ?? array();
		$theme_changes = $this->compare_versions( $themes_a, $themes_b );

		// WordPress version.
		$wp_a               = $manifest_a['wordpress_version'] ?? '';
		$wp_b               = $manifest_b['wordpress_version'] ?? '';
		$wp_version_changed = ( $wp_a !== $wp_b );

		// Size difference.
		$size_a    = (int) ( $backup_a['size'] ?? 0 );
		$size_b    = (int) ( $backup_b['size'] ?? 0 );
		$size_diff = $size_b - $size_a;

		// Metadata summary for display.
		$metadata = array(
			array(
				'label' => __( 'Backup A', 'wrensafe' ),
				'value' => esc_html( $backup_a['file_name'] ) . ' (' . size_format( $size_a ) . ')',
			),
			array(
				'label' => __( 'Backup B', 'wrensafe' ),
				'value' => esc_html( $backup_b['file_name'] ) . ' (' . size_format( $size_b ) . ')',
			),
			array(
				'label' => __( 'WordPress version', 'wrensafe' ),
				'value' => $wp_a . ( $wp_version_changed ? ' → ' . $wp_b : '' ),
			),
		);

		return array(
			'added'              => $added,
			'removed'            => $removed,
			'modified'           => $modified,
			'unchanged_count'    => $unchanged_count,
			'size_diff'          => $size_diff,
			'metadata'           => $metadata,
			'plugin_changes'     => $plugin_changes,
			'theme_changes'      => $theme_changes,
			'wp_version_changed' => $wp_version_changed,
			'error'              => '',
		);
	}

	/**
	 * Compare two plugin/theme version maps and return a list of changes.
	 *
	 * @param array $a Map of name => version from backup A.
	 * @param array $b Map of name => version from backup B.
	 * @return array[] Each entry: {name, old_version, new_version, change: added|removed|updated}
	 */
	private function compare_versions( array $a, array $b ): array {
		$changes = array();

		foreach ( $b as $name => $version_b ) {
			if ( ! isset( $a[ $name ] ) ) {
				$changes[] = array(
					'name'        => $name,
					'old_version' => '',
					'new_version' => $version_b,
					'change'      => 'added',
				);
			} elseif ( $a[ $name ] !== $version_b ) {
				$changes[] = array(
					'name'        => $name,
					'old_version' => $a[ $name ],
					'new_version' => $version_b,
					'change'      => 'updated',
				);
			}
		}

		foreach ( $a as $name => $version_a ) {
			if ( ! isset( $b[ $name ] ) ) {
				$changes[] = array(
					'name'        => $name,
					'old_version' => $version_a,
					'new_version' => '',
					'change'      => 'removed',
				);
			}
		}

		return $changes;
	}
}
