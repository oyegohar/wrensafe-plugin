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
use WPBP\Utils\Errors;

defined( 'ABSPATH' ) || exit;

class Notifier {

	/**
	 * The emails are plain text: names and error messages are sent exactly
	 * as they are, and no mail client treats them as HTML.
	 */
	private const HEADERS = array( 'Content-Type: text/plain; charset=UTF-8' );

	public static function register(): void {
		add_action( 'wpbp_job_finished', array( self::class, 'job_finished' ), 10, 2 );
	}

	/**
	 * @param AbstractJob $job
	 * @param string      $status completed | failed
	 */
	public static function job_finished( $job, $status ): void {
		if ( $job instanceof \WPBP\Updates\SafeUpdateJob ) {
			self::safe_update_finished( $job, (string) $status );
			return;
		}

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
				$lines[] = sprintf( __( 'Turned off so the site keeps working: %1$s (%2$s).', 'wrensafe' ), Errors::plain( (string) ( $item['name'] ?? '' ) ), Errors::plain( (string) ( $item['reason'] ?? '' ) ) );
			}
		}

		/* translators: %s: link to the Activity Logs page */
		$lines[] = sprintf( __( 'Details: %s', 'wrensafe' ), admin_url( 'admin.php?page=wrensafe-logs' ) );
		/* translators: %s: site address */
		$lines[] = sprintf( __( '— Wrensafe on %s', 'wrensafe' ), home_url() );

		wp_mail( $to, sprintf( '[%1$s] %2$s', $site, $subject ), implode( "\n\n", $lines ), self::HEADERS );
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
			sprintf( __( 'Reason: %s', 'wrensafe' ), Errors::plain( $reason ) ),
		);

		if ( $backup ) {
			/* translators: 1: backup file name, 2: file size */
			$lines[] = sprintf( __( 'Backup: %1$s (%2$s)', 'wrensafe' ), $backup['file_name'], size_format( (int) $backup['size'] ) );
		}

		/* translators: %s: link to the Remote storage settings */
		$lines[] = sprintf( __( 'Check the connection: %s', 'wrensafe' ), admin_url( 'admin.php?page=wrensafe-settings&tab=remote' ) );
		/* translators: %s: site address */
		$lines[] = sprintf( __( '— Wrensafe on %s', 'wrensafe' ), home_url() );

		wp_mail( $to, sprintf( '[%1$s] %2$s', $site, __( 'Off-site copy failed', 'wrensafe' ) ), implode( "\n\n", $lines ), self::HEADERS );
	}

	/**
	 * Safe Updates: a failure email when an update was rolled back or
	 * failed (or the run stopped), a success email when everything updated.
	 */
	private static function safe_update_finished( \WPBP\Updates\SafeUpdateJob $job, string $status ): void {
		$payload = $job->payload();
		$summary = \WPBP\Updates\SafeUpdateManager::summary( $payload );
		$failed  = 'failed' === $status || $summary['rolled_back'] > 0 || $summary['failed'] > 0;

		if ( ! $failed && 0 === $summary['updated'] ) {
			return;
		}

		if ( ! Settings::get( $failed ? 'notify_on_failure' : 'notify_on_success', false ) ) {
			return;
		}

		/** This filter is documented in includes/Core/Notifier.php */
		if ( ! apply_filters( 'wpbp_send_notification_email', true, 'safe_update', $failed ? 'failed' : 'completed', $job ) ) {
			return;
		}

		$to = (string) Settings::get( 'notification_email', '' );
		$to = is_email( $to ) ? $to : (string) get_option( 'admin_email' );

		if ( ! is_email( $to ) ) {
			return;
		}

		$site    = wp_specialchars_decode( (string) get_bloginfo( 'name' ), ENT_QUOTES );
		$items   = (array) ( $payload['items'] ?? array() );
		$plugins = true;

		foreach ( $items as $item ) {
			$plugins = $plugins && 'plugin' === (string) ( $item['type'] ?? 'plugin' );
		}

		if ( $plugins ) {
			$subject = $failed ? __( 'Plugin update rolled back or failed', 'wrensafe' ) : __( 'Plugins updated', 'wrensafe' );
		} else {
			$subject = $failed ? __( 'Update rolled back or failed', 'wrensafe' ) : __( 'Updates installed', 'wrensafe' );
		}

		$lines = array( \WPBP\Updates\SafeUpdateManager::summary_text( $summary ) );

		if ( '' !== $job->error_message() ) {
			/* translators: %s: error message */
			$lines[] = sprintf( __( 'Reason: %s', 'wrensafe' ), $job->error_message() );
		}

		$labels = array(
			'updated'     => __( 'updated', 'wrensafe' ),
			'rolled_back' => __( 'rolled back', 'wrensafe' ),
			'failed'      => __( 'failed', 'wrensafe' ),
			'skipped'     => __( 'not updated', 'wrensafe' ),
			'cancelled'   => __( 'not updated', 'wrensafe' ),
		);

		foreach ( $items as $item ) {
			/* translators: 1: name of the plugin (or theme), 2: old version, 3: new version, 4: what happened */
			$line = sprintf( __( '%1$s %2$s → %3$s: %4$s', 'wrensafe' ), $item['name'], $item['from'], $item['to'], $labels[ $item['state'] ] ?? $item['state'] );

			if ( 'updated' !== $item['state'] && '' !== (string) $item['message'] ) {
				$line .= "\n" . $item['message'];
			}

			$lines[] = $line;
		}

		/* translators: %s: link to the Safe Updates page */
		$lines[] = sprintf( __( 'Details: %s', 'wrensafe' ), admin_url( 'admin.php?page=wrensafe-safe-updates' ) );
		/* translators: %s: site address */
		$lines[] = sprintf( __( '— Wrensafe on %s', 'wrensafe' ), home_url() );

		// A plain-text email: every value as text (messages may hold escaped characters).
		wp_mail(
			$to,
			\WPBP\Utils\Errors::plain( sprintf( '[%1$s] %2$s', $site, $subject ) ),
			\WPBP\Utils\Errors::plain( wp_strip_all_tags( implode( "\n\n", $lines ) ) ),
			array( 'Content-Type: text/plain; charset=UTF-8' )
		);
	}

	private static function reason( AbstractJob $job ): string {
		$message = Errors::plain( (string) $job->error_message() );

		return '' !== $message ? $message : __( 'unknown — see the Activity Logs', 'wrensafe' );
	}
}
