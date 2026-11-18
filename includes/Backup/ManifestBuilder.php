<?php
/**
 * Builds the manifest.json and metadata.json documents embedded in every
 * .wbp archive.
 *
 * @package WPBP\Backup
 */

namespace WPBP\Backup;

use WPBP\Utils\Paths;

defined( 'ABSPATH' ) || exit;

class ManifestBuilder {

	/**
	 * Wrensafe's own .wbp format version. Bump this when the archive
	 * layout changes, and teach the Restore Engine to handle older versions.
	 */
	const FORMAT_VERSION = '1.0';

	/**
	 * Version of the per-table SQL dump encoding. 2 = values escaped without
	 * $wpdb->prepare() (literal "%" and real NULLs). Archives without this key
	 * were written by 0.9.1-beta or earlier and carry wpdb placeholder tokens
	 * in place of every "%", which the importer repairs on the way in.
	 */
	const SQL_DUMP_VERSION = 2;

	public function build_manifest( array $context ): array {
		return array(
			'format_version'    => self::FORMAT_VERSION,
			'sql_dump_version'  => self::SQL_DUMP_VERSION,
			'generator'         => 'Wrensafe ' . WPBP_VERSION,
			'archive_filename'  => $context['archive_filename'],
			'backup_type'       => $context['backup_type'],
			'created_at'        => gmdate( 'c' ),
			'contains_database' => $context['needs_db'],
			'contains_files'    => $context['needs_files'],
		);
	}

	public function build_metadata( array $context ): array {
		global $wpdb;

		$uploads = wp_upload_dir();

		return array(
			'site_url'        => home_url(),
			// Backward-compatible additions (Issue 08): the full set of source
			// URLs/paths a cross-site restore must rewrite to the destination's
			// values so the destination site keeps its own domain and paths.
			'site_url_option' => site_url(),
			'uploads_baseurl' => (string) ( $uploads['baseurl'] ?? '' ),
			'uploads_basedir' => (string) ( $uploads['basedir'] ?? '' ),
			'abspath'         => trailingslashit( Paths::root() ),
			'content_dir'     => Paths::content_dir(),
			// Where this site keeps its plugins, must-use plugins and themes,
			// so a restore onto a site with another folder layout puts every
			// file in the destination's matching folder (FileRestorer).
			'plugins_dir'     => Paths::plugins_dir(),
			'mu_plugins_dir'  => Paths::mu_plugins_dir(),
			'themes_dir'      => Paths::themes_dir(),
			'site_name'       => get_bloginfo( 'name' ),
			'wp_version'      => get_bloginfo( 'version' ),
			'php_version'     => PHP_VERSION,
			'mysql_version'   => $wpdb->db_version(),
			'table_prefix'    => $wpdb->prefix,
			'is_multisite'    => is_multisite(),
			'file_count'      => $context['file_count'] ?? 0,
			'total_size'      => $context['total_size'] ?? 0,
			'tables_exported' => $context['tables_exported'] ?? 0,
			'note'            => $context['note'] ?? '',
			'tag'             => $context['tag'] ?? '',
		);
	}

	public function build_report( array $context ): array {
		return array(
			'backup_type'     => $context['backup_type'],
			'file_count'      => $context['file_count'] ?? 0,
			'total_size'      => $context['total_size'] ?? 0,
			'tables_exported' => $context['tables_exported'] ?? 0,
			'created_at'      => gmdate( 'c' ),
			'warnings'        => $context['warnings'] ?? array(),
		);
	}
}
