<?php
/**
 * Rollback page — lists restore points created automatically before
 * imports/restores (see RestoreManager::step_rollback_point) and lets the
 * user roll back to one or delete it.
 *
 * Restoring a rollback point reuses the same Restore Engine as restoring
 * any other local backup, since a rollback point is just a pointer to a
 * database-only safety backup.
 *
 * @package WPBP\Admin\Pages
 */

namespace WPBP\Admin\Pages;

use WPBP\Core\Database\BackupsRepository;
use WPBP\Core\Database\RestorePointsRepository;
use WPBP\Security\CapabilityManager;

defined( 'ABSPATH' ) || exit;

class RollbackPage extends AbstractPage {

	public function get_title(): string {
		return __( 'Rollback', 'wrensafe' );
	}

	protected function get_subtitle(): string {
		return __( 'A rollback point is created automatically before every restore, so a restore can always be undone.', 'wrensafe' );
	}

	protected function required_capability(): string {
		return CapabilityManager::RESTORE_BACKUPS;
	}

	private function reason_label( string $reason ): string {
		$labels = array(
			'pre_restore' => __( 'Before restore', 'wrensafe' ),
			'pre_update'  => __( 'Before update', 'wrensafe' ),
			'manual'      => __( 'Manual', 'wrensafe' ),
			'emergency'   => __( 'Emergency', 'wrensafe' ),
		);

		return $labels[ $reason ] ?? ucfirst( $reason );
	}

	protected function render_content(): void {
		$points = RestorePointsRepository::list_all();
		?>
		<div class="wpbp-card">
			<h2><?php esc_html_e( 'Rollback points', 'wrensafe' ); ?></h2>
			<p class="description"><?php esc_html_e( 'Roll back puts the database back exactly as it was just before that restore. You review it first, like any restore.', 'wrensafe' ); ?></p>
			<?php if ( empty( $points ) ) : ?>
				<p><?php esc_html_e( 'No rollback points yet. They are created automatically before your first restore.', 'wrensafe' ); ?></p>
			<?php else : ?>
				<div class="wpbp-backup-browser-notice wpbp-inline-notice wpbp-is-hidden" id="wpbp-rollback-notice" role="status" aria-live="polite"></div>
				<div class="wpbp-bulk-bar">
					<button type="button" class="button button-link-delete" id="wpbp-rollback-bulk-delete" disabled>
						<?php esc_html_e( 'Delete selected', 'wrensafe' ); ?>
					</button>
					<span class="description"><?php esc_html_e( 'Points whose backup is locked are skipped.', 'wrensafe' ); ?></span>
				</div>
				<table class="widefat striped wpbp-rollback-table wpbp-table-stack">
					<thead>
					<tr>
						<th class="wpbp-col-select">
							<input type="checkbox" class="wpbp-rollback-select-all" aria-label="<?php esc_attr_e( 'Select all rollback points', 'wrensafe' ); ?>" />
						</th>
						<th><?php esc_html_e( 'Created', 'wrensafe' ); ?></th>
						<th><?php esc_html_e( 'Reason', 'wrensafe' ); ?></th>
						<th><?php esc_html_e( 'Backup', 'wrensafe' ); ?></th>
						<th><?php esc_html_e( 'Actions', 'wrensafe' ); ?></th>
					</tr>
					</thead>
					<tbody>
					<?php foreach ( $points as $point ) : ?>
						<?php $this->render_row( $point ); ?>
					<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>
		</div>
		<?php
	}

	private function render_row( array $point ): void {
		$backup = ! empty( $point['backup_id'] ) ? BackupsRepository::find( (int) $point['backup_id'] ) : null;
		?>
		<tr data-restore-point-id="<?php echo esc_attr( $point['id'] ); ?>">
			<td class="wpbp-col-select">
				<input type="checkbox" class="wpbp-select-rollback" value="<?php echo esc_attr( $point['id'] ); ?>" aria-label="<?php esc_attr_e( 'Select this rollback point', 'wrensafe' ); ?>" />
			</td>
			<td data-colname="<?php esc_attr_e( 'Created', 'wrensafe' ); ?>"><?php echo esc_html( mysql2date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $point['created_at'] ) ); ?></td>
			<td data-colname="<?php esc_attr_e( 'Reason', 'wrensafe' ); ?>"><?php echo esc_html( $this->reason_label( $point['reason'] ) ); ?></td>
			<td data-colname="<?php esc_attr_e( 'Backup', 'wrensafe' ); ?>">
				<?php if ( $backup ) : ?>
					<?php echo esc_html( $backup['file_name'] ); ?> &middot; <?php echo esc_html( size_format( (int) $backup['size'] ) ); ?>
				<?php else : ?>
					<em><?php esc_html_e( 'Backup unavailable', 'wrensafe' ); ?></em>
				<?php endif; ?>
			</td>
			<td class="wpbp-backup-actions">
				<div class="wpbp-row-actions">
					<?php if ( $backup ) : ?>
						<a class="button button-small wpbp-button-tonal" href="<?php echo esc_url( admin_url( 'admin.php?page=wrensafe-import-restore&backup_id=' . $backup['id'] ) ); ?>"><?php esc_html_e( 'Roll back', 'wrensafe' ); ?></a>
					<?php endif; ?>
					<button type="button" class="button button-small wpbp-icon-button is-danger wpbp-delete-restore-point" title="<?php esc_attr_e( 'Delete', 'wrensafe' ); ?>"><span class="dashicons dashicons-trash" aria-hidden="true"></span><span class="screen-reader-text"><?php esc_html_e( 'Delete this rollback point', 'wrensafe' ); ?></span></button>
				</div>
			</td>
		</tr>
		<?php
	}
}
