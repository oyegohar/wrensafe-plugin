<?php
/**
 * Settings → Notifications: email the site owner when a backup or restore
 * fails (on by default) or completes (off by default). Covers manual and
 * automatic backups and restores, through the wpbp_job_finished action.
 *
 * @package WPBP\Core
 */

namespace WPBP\Core;

use WPBP\Core\Database\BackupsRepository;

defined( 'ABSPATH' ) || exit;

class Notifier {

	public static function register(): void {
		add_action( 'wpbp_job_finished', array( self::class, 'job_finished' ), 10, 2 );
	}

	/**
	 * @param AbstractJob $job
	 * @param string      $status completed | failed
	 */
	public static function job_finished( $job, $status ): void {
		if ( ! $job instanceof AbstractJob || ! in_array( $job->type(), array( 'backup', 'restore' ), true ) ) {
			return;
		}

		$failed = 'failed' === $status;

		if ( ! $failed && 'completed' !== $status ) {
			return;
		}

		$type    = $job->type();
		$payload = $job->payload();

		// The backup taken before a restore is part of that restore, which reports the outcome.
		if ( 'backup' === $type && ! $failed && 0 === strpos( (string) ( $payload['note'] ?? '' ), 'Automatic rollback point' ) ) {
			return;
		}

		if ( ! Settings::get( $failed ? 'notify_on_failure' : 'notify_on_success', $failed ) ) {
			return;
		}

		/**
		 * Filters whether Wrensafe sends this email (an add-on with its own
		 * alerts can take over).
		 *
		 * @param bool        $send
		 * @param string      $type   backup | restore
		 * @param string      $status completed | failed
		 * @param AbstractJob $job
		 */
		if ( ! apply_filters( 'wpbp_send_notification_email', true, $type, $status, $job ) ) {
			return;
		}

		$to = (string) Settings::get( 'notification_email', '' );
		$to = is_email( $to ) ? $to : (string) get_option( 'admin_email' );

		if ( ! is_email( $to ) ) {
			return;
		}

		$site      = wp_specialchars_decode( (string) get_bloginfo( 'name' ), ENT_QUOTES );
		$automatic = ! empty( $payload['schedule_id'] );
		$lines     = array();

		if ( 'backup' === $type && $failed ) {
			$subject = $automatic ? __( 'Automatic backup failed', 'wrensafe' ) : __( 'Backup failed', 'wrensafe' );
			/* translators: %s: error message */
			$lines[] = sprintf( __( 'Reason: %s', 'wrensafe' ), self::reason( $job ) );
		} elseif ( 'backup' === $type ) {
			$subject = $automatic ? __( 'Automatic backup completed', 'wrensafe' ) : __( 'Backup completed', 'wrensafe' );
			$backup  = BackupsRepository::find( (int) ( $job->result()['backup_id'] ?? 0 ) );

			if ( $backup ) {
				/* translators: 1: backup file name, 2: file size */
				$lines[] = sprintf( __( 'Backup: %1$s (%2$s)', 'wrensafe' ), $backup['file_name'], size_format( (int) $backup['size'] ) );
			}
		} elseif ( $failed ) {
			$subject = __( 'Restore failed', 'wrensafe' );
			/* translators: %s: error message */
			$lines[] = sprintf( __( 'Reason: %s', 'wrensafe' ), self::reason( $job ) );
			$lines[] = __( 'The site was put back as it was before the restore wherever possible. A rollback point is listed under Wrensafe → Rollback.', 'wrensafe' );
		} else {
			$subject = __( 'Restore completed', 'wrensafe' );
			$lines[] = __( 'The site was restored from a backup.', 'wrensafe' );

			foreach ( (array) ( $job->result()['deactivated'] ?? array() ) as $item ) {
				/* translators: 1: plugin name, 2: why it was turned off */
				$lines[] = sprintf( __( 'Turned off so the site keeps working: %1$s (%2$s).', 'wrensafe' ), (string) ( $item['name'] ?? '' ), (string) ( $item['reason'] ?? '' ) );
			}
		}

		/* translators: %s: link to the Activity Logs page */
		$lines[] = sprintf( __( 'Details: %s', 'wrensafe' ), admin_url( 'admin.php?page=wrensafe-logs' ) );
		/* translators: %s: site address */
		$lines[] = sprintf( __( '— Wrensafe on %s', 'wrensafe' ), home_url() );

		wp_mail( $to, sprintf( '[%1$s] %2$s', $site, $subject ), implode( "\n\n", $lines ) );
	}

	/**
	 * Email that a backup couldn't be copied to remote storage (when failure
	 * emails are on). The backup itself completed.
	 */
	public static function remote_copy_failed( int $backup_id, string $reason ): void {
		if ( ! Settings::get( 'notify_on_failure', false ) ) {
			return;
		}

		$to = (string) Settings::get( 'notification_email', '' );
		$to = is_email( $to ) ? $to : (string) get_option( 'admin_email' );

		if ( ! is_email( $to ) ) {
			return;
		}

		$backup = BackupsRepository::find( $backup_id );
		$site   = wp_specialchars_decode( (string) get_bloginfo( 'name' ), ENT_QUOTES );
		$lines  = array(
			__( 'A backup was made, but copying it to your remote storage failed. The backup is safe on this server.', 'wrensafe' ),
			/* translators: %s: error message */
			sprintf( __( 'Reason: %s', 'wrensafe' ), $reason ),
		);

		if ( $backup ) {
			/* translators: 1: backup file name, 2: file size */
			$lines[] = sprintf( __( 'Backup: %1$s (%2$s)', 'wrensafe' ), $backup['file_name'], size_format( (int) $backup['size'] ) );
		}

		/* translators: %s: link to the Remote storage settings */
		$lines[] = sprintf( __( 'Check the connection: %s', 'wrensafe' ), admin_url( 'admin.php?page=wrensafe-settings&tab=remote' ) );
		/* translators: %s: site address */
		$lines[] = sprintf( __( '— Wrensafe on %s', 'wrensafe' ), home_url() );

		wp_mail( $to, sprintf( '[%1$s] %2$s', $site, __( 'Off-site copy failed', 'wrensafe' ) ), implode( "\n\n", $lines ) );
	}

	private static function reason( AbstractJob $job ): string {
		$message = (string) $job->error_message();

		return '' !== $message ? $message : __( 'unknown — see the Activity Logs', 'wrensafe' );
	}
}
