<?php
/**
 * Triggers an automatic backup immediately before WordPress core, plugin,
 * or theme updates — both auto-updates and manual updates.
 *
 * The backup runs synchronously by looping BackupManager::process_step()
 * within the same request. A transient lock prevents concurrent backups when
 * multiple items update at once (e.g. a batch auto-update run).
 *
 * @package WPBP\Updates
 */

namespace WPBP\Updates;

use WPBP\Backup\BackupJob;
use WPBP\Backup\BackupManager;
use WPBP\Core\FeatureManager;
use WPBP\Utils\Logger;

defined( 'ABSPATH' ) || exit;

class BackupBeforeUpdates {

	private const LOCK_TRANSIENT = 'wpbp_pre_update_backup_running';
	private const MAX_STEPS      = 8000;

	public function register_hooks(): void {
		if ( ! FeatureManager::is_enabled( 'backup_before_updates' ) ) {
			return;
		}

		add_action( 'pre_auto_update', array( $this, 'handle_auto_update' ), 10, 1 );
		add_filter( 'upgrader_pre_install', array( $this, 'handle_manual_update' ), 10, 2 );
	}

	/**
	 * Fires before each WordPress auto-update.
	 *
	 * @param string $type 'core', 'plugin', 'theme', or 'translation'.
	 */
	public function handle_auto_update( string $type ): void {
		$settings = get_option( 'wpbp_backup_before_updates', array() );

		$enabled_types = array_filter(
			array(
				'core'   => ! empty( $settings['auto_core'] ),
				'plugin' => ! empty( $settings['auto_plugins'] ),
				'theme'  => ! empty( $settings['auto_themes'] ),
			)
		);

		if ( ! isset( $enabled_types[ $type ] ) ) {
			return;
		}

		$this->run_pre_update_backup( 'auto_' . $type );
	}

	/**
	 * Fires before a manual upgrade package is installed.
	 * Return value must pass through unchanged — we are observing, not blocking.
	 *
	 * @param bool|WP_Error $response Upstream response.
	 * @param array         $hook_extra  Extra information about the upgrade.
	 */
	public function handle_manual_update( $response, array $hook_extra ) {
		$settings = get_option( 'wpbp_backup_before_updates', array() );

		if ( empty( $settings['manual_updates'] ) ) {
			return $response;
		}

		$type = $hook_extra['type'] ?? 'plugin';
		$this->run_pre_update_backup( 'manual_' . $type );

		return $response;
	}

	/**
	 * Run a synchronous pre-update backup and log the outcome.
	 *
	 * Skips if another backup is already in progress (transient lock) or if
	 * the selected backup type is 'none'.
	 */
	private function run_pre_update_backup( string $trigger ): void {
		$settings    = get_option( 'wpbp_backup_before_updates', array() );
		$backup_type = sanitize_key( $settings['backup_type'] ?? 'database' );

		if ( 'none' === $backup_type ) {
			return;
		}

		if ( get_transient( self::LOCK_TRANSIENT ) ) {
			Logger::info( 'Pre-update backup skipped — another backup is already running.', array( 'trigger' => $trigger ) );
			return;
		}

		set_transient( self::LOCK_TRANSIENT, 1, 10 * MINUTE_IN_SECONDS );

		Logger::info(
			'Starting pre-update backup.',
			array(
				'trigger'     => $trigger,
				'backup_type' => $backup_type,
			)
		);

		$job = BackupJob::create(
			array(
				'backup_type' => $backup_type,
				'note'        => sprintf(
					/* translators: %s: update trigger label */
					__( 'Automatic pre-update backup (%s)', 'wrensafe' ),
					$trigger
				),
				'tag'         => 'pre_update',
			)
		);

		$manager = new BackupManager();
		$steps   = 0;

		while (
			! in_array( $job->status(), array( BackupJob::STATUS_COMPLETED, BackupJob::STATUS_FAILED, BackupJob::STATUS_CANCELLED ), true )
			&& $steps < self::MAX_STEPS
		) {
			$manager->process_step( $job );
			++$steps;
		}

		delete_transient( self::LOCK_TRANSIENT );

		if ( BackupJob::STATUS_COMPLETED === $job->status() ) {
			Logger::info(
				'Pre-update backup completed.',
				array(
					'job_id' => $job->id(),
					'steps'  => $steps,
				)
			);
		} else {
			Logger::error(
				'Pre-update backup did not complete.',
				array(
					'job_id' => $job->id(),
					'status' => $job->status(),
					'steps'  => $steps,
				)
			);
		}
	}
}
