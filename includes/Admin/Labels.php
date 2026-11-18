<?php
/**
 * Display names for the values Wrensafe stores (backup types, statuses,
 * log levels), so every screen shows the same translated words.
 *
 * @package Wrensafe
 */

namespace WPBP\Admin;

defined( 'ABSPATH' ) || exit;

/**
 * Translated labels for stored keys.
 */
final class Labels {

	/**
	 * A backup type: full, database, files, media, themes, plugins.
	 */
	public static function backup_type( string $type ): string {
		$labels = array(
			'full'     => __( 'Full website', 'wrensafe' ),
			'database' => __( 'Database', 'wrensafe' ),
			'files'    => __( 'Files', 'wrensafe' ),
			'media'    => __( 'Media', 'wrensafe' ),
			'uploads'  => __( 'Media', 'wrensafe' ),
			'themes'   => __( 'Themes', 'wrensafe' ),
			'plugins'  => __( 'Plugins', 'wrensafe' ),
			'custom'   => __( 'Custom', 'wrensafe' ),
		);

		return $labels[ $type ] ?? ucfirst( $type );
	}

	/**
	 * A backup chain mode: full or incremental.
	 */
	public static function chain_mode( string $mode ): string {
		$labels = array(
			'full'        => __( 'Full', 'wrensafe' ),
			'incremental' => __( 'Incremental', 'wrensafe' ),
		);

		return $labels[ $mode ] ?? ucfirst( $mode );
	}

	/**
	 * A backup, job, staging site or check status.
	 */
	public static function status( string $status ): string {
		$labels = array(
			'completed' => __( 'Completed', 'wrensafe' ),
			'failed'    => __( 'Failed', 'wrensafe' ),
			'running'   => __( 'Running', 'wrensafe' ),
			'pending'   => __( 'Pending', 'wrensafe' ),
			'cancelled' => __( 'Cancelled', 'wrensafe' ),
			'corrupted' => __( 'Damaged', 'wrensafe' ),
			'verifying' => __( 'Verifying', 'wrensafe' ),
			'creating'  => __( 'Being created', 'wrensafe' ),
			'active'    => __( 'Active', 'wrensafe' ),
			'pass'      => __( 'Pass', 'wrensafe' ),
			'warning'   => __( 'Warning', 'wrensafe' ),
			'fail'      => __( 'Fail', 'wrensafe' ),
		);

		return $labels[ $status ] ?? ucfirst( $status );
	}

	/**
	 * An Activity Logs level.
	 */
	public static function log_level( string $level ): string {
		$labels = array(
			'debug'    => __( 'Debug', 'wrensafe' ),
			'info'     => __( 'Info', 'wrensafe' ),
			'success'  => __( 'Success', 'wrensafe' ),
			'warning'  => __( 'Warning', 'wrensafe' ),
			'error'    => __( 'Error', 'wrensafe' ),
			'critical' => __( 'Critical', 'wrensafe' ),
			'failed'   => __( 'Failed', 'wrensafe' ),
		);

		return $labels[ $level ] ?? ucfirst( $level );
	}
}
