<?php
/**
 * AJAX endpoints for deleting rollback restore points (single + bulk). The
 * underlying backup file is left alone — it remains manageable from the
 * Backup Browser independently of the rollback pointer.
 *
 * @package WPBP\Ajax
 */

namespace WPBP\Ajax;

use WPBP\Core\Database\BackupsRepository;
use WPBP\Core\Database\RestorePointsRepository;
use WPBP\Security\CapabilityManager;
use WPBP\Utils\Logger;

defined( 'ABSPATH' ) || exit;

class RollbackAjax {

	private static function require_capability(): void {
		if ( ! CapabilityManager::current_user_can( CapabilityManager::RESTORE_BACKUPS ) ) {
			wp_send_json_error( array( 'message' => __( 'You don’t have permission to delete rollback points.', 'wrensafe' ) ), 403 );
		}
	}

	/**
	 * A rollback point is protected when its underlying backup is locked —
	 * deleting the pointer to a locked safety backup would defeat the lock.
	 */
	private static function is_protected( array $point ): bool {
		if ( empty( $point['backup_id'] ) ) {
			return false;
		}

		$backup = BackupsRepository::find( (int) $point['backup_id'] );

		return null !== $backup && ! empty( $backup['is_locked'] );
	}

	public static function delete(): void {
		check_ajax_referer( 'wpbp_admin_ajax', '_wpbp_nonce' );
		self::require_capability();

		$id    = isset( $_POST['restore_point_id'] ) ? absint( $_POST['restore_point_id'] ) : 0;
		$point = $id ? RestorePointsRepository::find( $id ) : null;

		if ( null === $point ) {
			wp_send_json_error( array( 'message' => __( 'Rollback point not found.', 'wrensafe' ) ), 404 );
		}

		if ( self::is_protected( $point ) ) {
			wp_send_json_error( array( 'message' => __( 'This rollback point is protected because its backup is locked. Unlock the backup first.', 'wrensafe' ) ), 400 );
		}

		RestorePointsRepository::delete( $id );
		Logger::info( 'Rollback point #' . $id . ' deleted by user #' . get_current_user_id() . '.' );

		wp_send_json_success();
	}

	/**
	 * Delete several rollback points in one request (Issue 09). Points whose
	 * backup is locked are skipped, never deleted.
	 */
	public static function bulk_delete(): void {
		check_ajax_referer( 'wpbp_admin_ajax', '_wpbp_nonce' );
		self::require_capability();

		$ids = isset( $_POST['restore_point_ids'] ) ? array_map( 'absint', (array) wp_unslash( $_POST['restore_point_ids'] ) ) : array();
		$ids = array_values( array_unique( array_filter( array_map( 'absint', $ids ) ) ) );

		if ( empty( $ids ) ) {
			wp_send_json_error( array( 'message' => __( 'No rollback points were selected.', 'wrensafe' ) ), 400 );
		}

		$deleted = array();
		$skipped = 0;

		foreach ( $ids as $id ) {
			$point = RestorePointsRepository::find( $id );

			if ( null === $point ) {
				continue;
			}

			if ( self::is_protected( $point ) ) {
				++$skipped;
				continue;
			}

			RestorePointsRepository::delete( $id );
			$deleted[] = $id;
		}

		Logger::info( 'Rollback points bulk delete by user #' . get_current_user_id() . ': ' . count( $deleted ) . ' deleted, ' . $skipped . ' protected/skipped.' );

		$message = sprintf(
			/* translators: 1: number deleted, 2: number skipped because protected */
			_n( 'Deleted %1$d rollback point. Skipped %2$d protected.', 'Deleted %1$d rollback points. Skipped %2$d protected.', count( $deleted ), 'wrensafe' ),
			count( $deleted ),
			$skipped
		);

		wp_send_json_success(
			array(
				'deleted_ids' => $deleted,
				'skipped'     => $skipped,
				'message'     => $message,
			)
		);
	}
}
