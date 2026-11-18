<?php
/**
 * Built-in migration/backup templates.
 *
 * Templates are pre-configured backup job parameters with a human-readable
 * label and description so users can start common tasks in one click without
 * touching advanced settings.
 *
 * @package WPBP\Migration
 */

namespace WPBP\Migration;

defined( 'ABSPATH' ) || exit;

class MigrationTemplates {

	/**
	 * All built-in templates.
	 *
	 * Each entry:
	 *   key           — machine-readable slug
	 *   label         — display name
	 *   description   — one-line explanation shown in the UI
	 *   backup_type   — passed directly to BackupJob
	 *   chain_mode    — 'full', 'incremental', or 'differential'
	 *   note_template — sprintf pattern for the auto-generated backup note (%s = date)
	 *   tag           — optional backup tag applied automatically
	 *   icon          — dashicons class for the UI card
	 */
	public static function all(): array {
		/**
		 * Filters the backup templates (key => template), so an add-on can
		 * offer its own (for example a cloud-only backup).
		 *
		 * @param array<string,array<string,mixed>> $templates
		 */
		return (array) apply_filters( 'wpbp_migration_templates', self::built_in() );
	}

	/** @return array<string,array<string,mixed>> */
	private static function built_in(): array {
		return array(
			'full_website'     => array(
				'key'           => 'full_website',
				'label'         => __( 'Full website backup', 'wrensafe' ),
				'description'   => __( 'Complete backup of all files and the database — the safest option before any major change.', 'wrensafe' ),
				'backup_type'   => 'full',
				'chain_mode'    => 'full',
				'note_template' => /* translators: %s: date and time the backup was created */ __( 'Full website backup — %s', 'wrensafe' ),
				'tag'           => 'full',
				'icon'          => 'dashicons-backup',
			),
			'woocommerce_safe' => array(
				'key'           => 'woocommerce_safe',
				'label'         => __( 'WooCommerce store backup', 'wrensafe' ),
				'description'   => __( 'Full backup of a store. When you restore it, WooCommerce Safe Mode keeps the live orders, customers and stock.', 'wrensafe' ),
				'backup_type'   => 'full',
				'chain_mode'    => 'full',
				'note_template' => /* translators: %s: date and time the backup was created */ __( 'WooCommerce safe migration backup — %s', 'wrensafe' ),
				'tag'           => 'woocommerce',
				'icon'          => 'dashicons-cart',
				'woo_safe_mode' => true,
			),
			'database_only'    => array(
				'key'           => 'database_only',
				'label'         => __( 'Database-only backup', 'wrensafe' ),
				'description'   => __( 'Exports only the database — fast and compact, ideal for saving content without touching files.', 'wrensafe' ),
				'backup_type'   => 'database',
				'chain_mode'    => 'full',
				'note_template' => /* translators: %s: date and time the backup was created */ __( 'Database-only backup — %s', 'wrensafe' ),
				'tag'           => 'database',
				'icon'          => 'dashicons-database',
			),
			'media_only'       => array(
				'key'           => 'media_only',
				'label'         => __( 'Media-only backup', 'wrensafe' ),
				'description'   => __( 'Backs up the uploads folder — useful for preserving media before a theme or plugin migration.', 'wrensafe' ),
				'backup_type'   => 'media',
				'chain_mode'    => 'full',
				'note_template' => /* translators: %s: date and time the backup was created */ __( 'Media-only backup — %s', 'wrensafe' ),
				'tag'           => 'media',
				'icon'          => 'dashicons-format-image',
			),
			'staging_to_live'  => array(
				'key'           => 'staging_to_live',
				'label'         => __( 'Staging site backup', 'wrensafe' ),
				'description'   => __( 'Full backup of a staging site after testing, to restore on the live site.', 'wrensafe' ),
				'backup_type'   => 'full',
				'chain_mode'    => 'full',
				'note_template' => /* translators: %s: date and time the backup was created */ __( 'Staging-to-live backup — %s', 'wrensafe' ),
				'tag'           => 'staging',
				'icon'          => 'dashicons-randomize',
			),
			'client_handover'  => array(
				'key'           => 'client_handover',
				'label'         => __( 'Client handover backup', 'wrensafe' ),
				'description'   => __( 'Full backup with notes, ready to deliver to a client after a project completes.', 'wrensafe' ),
				'backup_type'   => 'full',
				'chain_mode'    => 'full',
				'note_template' => /* translators: %s: date and time the backup was created */ __( 'Client handover backup — %s', 'wrensafe' ),
				'tag'           => 'handover',
				'icon'          => 'dashicons-businessman',
			),
			'before_update'    => array(
				'key'           => 'before_update',
				'label'         => __( 'Before-update backup', 'wrensafe' ),
				'description'   => __( 'Quick database backup before applying plugin, theme, or core updates.', 'wrensafe' ),
				'backup_type'   => 'database',
				'chain_mode'    => 'full',
				'note_template' => /* translators: %s: date and time the backup was created */ __( 'Pre-update safety backup — %s', 'wrensafe' ),
				'tag'           => 'pre_update',
				'icon'          => 'dashicons-update',
			),
			'emergency'        => array(
				'key'           => 'emergency',
				'label'         => __( 'Emergency backup', 'wrensafe' ),
				'description'   => __( 'Full backup to take right away when something just broke, so you have a backup to go back to before you start fixing it.', 'wrensafe' ),
				'backup_type'   => 'full',
				'chain_mode'    => 'full',
				'note_template' => /* translators: %s: date and time the backup was created */ __( 'Emergency backup — %s', 'wrensafe' ),
				'tag'           => 'emergency',
				'icon'          => 'dashicons-warning',
			),
		);
	}

	/**
	 * Get a single template by key, or null if not found.
	 *
	 * @return array|null
	 */
	public static function get( string $key ): ?array {
		return self::all()[ $key ] ?? null;
	}

	/**
	 * Build a BackupJob payload array from a template key.
	 * Returns null if the template key is unknown.
	 */
	public static function to_job_payload( string $key ): ?array {
		$template = self::get( $key );

		if ( null === $template ) {
			return null;
		}

		return array(
			'backup_type' => $template['backup_type'],
			'chain_mode'  => $template['chain_mode'],
			'note'        => sprintf( $template['note_template'], wp_date( 'Y-m-d H:i' ) ),
			'tag'         => $template['tag'],
		);
	}
}
