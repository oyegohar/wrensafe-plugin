<?php
/**
 * Backup Browser page — lists local .wbp backups with download, verify,
 * lock, note/tag, and delete actions (see assets/admin/js/admin.js).
 *
 * @package WPBP\Admin\Pages
 */

namespace WPBP\Admin\Pages;

use WPBP\Core\Database\BackupsRepository;
use WPBP\Core\Settings;
use WPBP\Security\CapabilityManager;

defined( 'ABSPATH' ) || exit;

class BackupBrowserPage extends AbstractPage {

	public function get_title(): string {
		return __( 'Backup Browser', 'wrensafe' );
	}

	protected function get_subtitle(): string {
		return __( 'Every backup stored on this server. Verify an archive before you restore it, and lock the ones you never want deleted.', 'wrensafe' );
	}

	protected function get_header_actions(): array {
		return array(
			array(
				'label'   => __( 'Create backup', 'wrensafe' ),
				'url'     => self::page_url( 'wrensafe-backup' ),
				'variant' => 'primary',
				'icon'    => 'dashicons-plus-alt2',
				'cap'     => \WPBP\Security\CapabilityManager::CREATE_BACKUPS,
			),
		);
	}

	protected function required_capability(): string {
		return CapabilityManager::VIEW_BACKUPS;
	}

	protected function render_content(): void {
		$backups = BackupsRepository::list_all();

		// The post-restore logout redirect lands here with restore=success.
		if ( isset( $_GET['restore'] ) && 'success' === sanitize_key( wp_unslash( $_GET['restore'] ) ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			?>
			<div class="notice notice-success wpbp-notice">
				<p><?php esc_html_e( 'Restore completed successfully. You have been logged in again.', 'wrensafe' ); ?></p>
			</div>
			<?php
		}
		?>
		<?php $completed_count = BackupsRepository::count_completed(); ?>
		<div class="wpbp-card">
			<h2 class="wpbp-browser-heading">
				<?php esc_html_e( 'Local backups', 'wrensafe' ); ?>
				<span class="wpbp-count-badge" id="wpbp-backup-count-badge" aria-label="<?php echo esc_attr( sprintf( /* translators: %d: number of completed backups */ _n( '%d completed backup', '%d completed backups', $completed_count, 'wrensafe' ), $completed_count ) ); ?>"><?php echo esc_html( (string) $completed_count ); ?></span>
			</h2>
			<?php if ( empty( $backups ) ) : ?>
				<p><?php esc_html_e( 'No backups yet. Create one from the Create Backup page.', 'wrensafe' ); ?></p>
			<?php else : ?>
				<p class="description wpbp-verify-help">
					<?php esc_html_e( 'Verify checks the backup archive, manifest, database files, and file checksums to confirm the backup is not corrupted before restore.', 'wrensafe' ); ?>
				</p>
				<div class="wpbp-backup-browser-notice wpbp-inline-notice wpbp-is-hidden" role="status" aria-live="polite"></div>

				<div class="wpbp-bulk-bar">
					<button type="button" class="button wpbp-bulk-delete" disabled>
						<?php esc_html_e( 'Delete selected', 'wrensafe' ); ?>
					</button>
					<span class="description"><?php esc_html_e( 'Locked backups are skipped.', 'wrensafe' ); ?></span>
				</div>
				<table class="widefat striped wpbp-backups-table wpbp-table-stack">
					<thead>
					<tr>
						<th class="wpbp-col-select">
							<input type="checkbox" class="wpbp-select-all" title="<?php esc_attr_e( 'Select all', 'wrensafe' ); ?>" aria-label="<?php esc_attr_e( 'Select all backups', 'wrensafe' ); ?>" />
						</th>
						<th><?php esc_html_e( 'Backup', 'wrensafe' ); ?></th>
						<th><?php esc_html_e( 'Type', 'wrensafe' ); ?></th>
						<th><?php esc_html_e( 'Size', 'wrensafe' ); ?></th>
						<th><?php esc_html_e( 'Created', 'wrensafe' ); ?></th>
						<th><?php esc_html_e( 'Status', 'wrensafe' ); ?></th>
						<th><?php esc_html_e( 'Actions', 'wrensafe' ); ?></th>
					</tr>
					</thead>
					<tbody>
					<?php foreach ( $backups as $backup ) : ?>
						<?php $this->render_row( $backup ); ?>
					<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>
		</div>
		<?php
	}

	private function render_row( array $backup ): void {
		// Make the download link ROOT-RELATIVE (/wp-admin/admin-post.php?…) so
		// the browser resolves it against the exact scheme+host of the page it's
		// on. admin_url() derives its scheme from the siteurl option (often
		// http on LocalWP) and from is_ssl(), which reads false when SSL is
		// terminated upstream — so on an HTTPS-served admin the default http://
		// download link triggers Chrome's "insecure download blocked"
		// (mixed-content download). A relative URL sidesteps scheme detection
		// entirely and is always same-origin.
		$download_url = set_url_scheme(
			wp_nonce_url(
				add_query_arg(
					array(
						'action'    => 'wpbp_download_backup',
						'backup_id' => $backup['id'],
					),
					admin_url( 'admin-post.php' )
				),
				'wpbp_download_backup',
				'_wpbp_nonce'
			),
			'relative'
		);

		$restore_url = add_query_arg(
			array(
				'page'      => 'wrensafe-import-restore',
				'backup_id' => $backup['id'],
			),
			admin_url( 'admin.php' )
		);
		?>
		<tr data-backup-id="<?php echo esc_attr( $backup['id'] ); ?>" data-locked="<?php echo ! empty( $backup['is_locked'] ) ? '1' : '0'; ?>" data-encrypted="<?php echo ! empty( $backup['is_encrypted'] ) ? '1' : '0'; ?>">
			<td class="wpbp-col-select">
				<input type="checkbox" class="wpbp-select-backup" value="<?php echo esc_attr( $backup['id'] ); ?>" aria-label="<?php esc_attr_e( 'Select this backup', 'wrensafe' ); ?>" />
			</td>
			<td>
				<strong><?php echo esc_html( $backup['file_name'] ); ?></strong>
				<?php
				// Site · WP x / PHP y — parts that aren't known (an encrypted
				// backup received by Direct Migration) are left out.
				$meta = array_filter( array( (string) $backup['source_url'] ) );

				if ( '' !== (string) $backup['wp_version'] && '' !== (string) $backup['php_version'] ) {
					/* translators: 1: WordPress version, 2: PHP version */
					$meta[] = sprintf( __( 'WP %1$s / PHP %2$s', 'wrensafe' ), $backup['wp_version'], $backup['php_version'] );
				}
				?>
				<?php if ( $meta ) : ?>
					<div class="wpbp-backup-meta"><?php echo esc_html( implode( ' · ', $meta ) ); ?></div>
				<?php endif; ?>
				<?php $this->render_note_tags( $backup ); ?>
			</td>
			<td>
				<?php echo esc_html( \WPBP\Admin\Labels::backup_type( (string) $backup['backup_type'] ) ); ?>
				<?php if ( 'full' !== ( $backup['chain_mode'] ?? 'full' ) ) : ?>
					<br /><span class="wpbp-limit-note" style="padding:1px 6px;"><?php echo esc_html( \WPBP\Admin\Labels::chain_mode( (string) $backup['chain_mode'] ) ); ?></span>
				<?php endif; ?>
			</td>
			<td><?php echo esc_html( size_format( (int) $backup['size'] ) ); ?></td>
			<td><?php echo \WPBP\Utils\Time::local_time_markup( $backup['created_at'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></td>
			<td>
				<span class="wpbp-backup-status wpbp-backup-status-<?php echo esc_attr( $backup['status'] ); ?>"><?php echo esc_html( \WPBP\Admin\Labels::status( (string) $backup['status'] ) ); ?></span>
				<span class="dashicons dashicons-lock wpbp-lock-icon<?php echo ! empty( $backup['is_locked'] ) ? '' : ' wpbp-is-hidden'; ?>" title="<?php esc_attr_e( 'Locked backup', 'wrensafe' ); ?>"></span>
				<?php if ( ! empty( $backup['is_encrypted'] ) ) : ?>
					<span class="dashicons dashicons-shield" title="<?php esc_attr_e( 'Encrypted', 'wrensafe' ); ?>"></span>
				<?php endif; ?>
			</td>
			<?php
			$locked            = ! empty( $backup['is_locked'] );
			$downloads_allowed = ! $locked || Settings::get( 'allow_locked_backup_downloads', false );
			?>
			<td class="wpbp-backup-actions">
				<div class="wpbp-row-actions">
					<a class="button button-small wpbp-button-tonal wpbp-restore-backup-link<?php echo $locked ? ' disabled' : ''; ?>" href="<?php echo esc_url( $restore_url ); ?>" <?php echo $locked ? 'aria-disabled="true"' : ''; ?>><?php esc_html_e( 'Restore', 'wrensafe' ); ?></a>
					<a class="button button-small wpbp-icon-button wpbp-download-backup<?php echo ( $locked && ! $downloads_allowed ) ? ' disabled' : ''; ?>" href="<?php echo esc_url( $download_url ); ?>" <?php echo ( $locked && ! $downloads_allowed ) ? 'aria-disabled="true"' : ''; ?> title="<?php esc_attr_e( 'Download', 'wrensafe' ); ?>"><span class="dashicons dashicons-download" aria-hidden="true"></span><span class="screen-reader-text"><?php esc_html_e( 'Download', 'wrensafe' ); ?></span></a>
					<details class="wpbp-menu">
						<summary class="button button-small wpbp-icon-button" title="<?php esc_attr_e( 'More actions', 'wrensafe' ); ?>"><span class="dashicons dashicons-ellipsis" aria-hidden="true"></span><span class="screen-reader-text"><?php esc_html_e( 'More actions', 'wrensafe' ); ?></span></summary>
						<div class="wpbp-menu-panel">
							<button type="button" class="wpbp-menu-item wpbp-verify-backup" title="<?php esc_attr_e( 'Checks the archive, manifest, database files and file checksums to confirm the backup is not corrupted.', 'wrensafe' ); ?>"><?php esc_html_e( 'Verify integrity', 'wrensafe' ); ?></button>
							<button type="button" class="wpbp-menu-item wpbp-toggle-lock"><?php echo $locked ? esc_html__( 'Unlock', 'wrensafe' ) : esc_html__( 'Lock', 'wrensafe' ); ?></button>
							<hr class="wpbp-menu-separator">
							<button type="button" class="wpbp-menu-item is-danger wpbp-delete-backup" <?php echo $locked ? 'disabled' : ''; ?>><?php esc_html_e( 'Delete', 'wrensafe' ); ?></button>
						</div>
					</details>
				</div>
			</td>
		</tr>
		<?php
	}

	/**
	 * Read-only note + tag-pill display with a pencil button that reveals an
	 * inline editor (Part D). Tags are stored as a comma-separated string in the
	 * existing schema; each is shown as a chip. Editing/saving happens over AJAX
	 * (see assets/admin/js/admin.js) with no page reload.
	 */
	private function render_note_tags( array $backup ): void {
		$note = (string) ( $backup['note'] ?? '' );
		$tags = array_values( array_filter( array_map( 'trim', explode( ',', (string) ( $backup['tags'] ?? '' ) ) ) ) );
		?>
		<div class="wpbp-meta" data-note="<?php echo esc_attr( $note ); ?>" data-tags="<?php echo esc_attr( implode( ', ', $tags ) ); ?>">
			<div class="wpbp-meta-display">
				<div class="wpbp-meta-note">
					<span class="dashicons dashicons-edit-page"></span>
					<span class="wpbp-meta-note-text<?php echo '' === $note ? ' is-empty' : ''; ?>">
						<?php echo '' === $note ? esc_html__( 'No note', 'wrensafe' ) : esc_html( $note ); ?>
					</span>
					<button type="button" class="button-link wpbp-meta-edit" aria-label="<?php esc_attr_e( 'Edit note and tags', 'wrensafe' ); ?>" title="<?php esc_attr_e( 'Edit note and tags', 'wrensafe' ); ?>">
						<span class="dashicons dashicons-edit"></span>
					</button>
				</div>
				<div class="wpbp-meta-tags">
					<?php if ( empty( $tags ) ) : ?>
						<span class="wpbp-meta-tags-empty"><span class="dashicons dashicons-tag"></span><?php esc_html_e( 'No tags', 'wrensafe' ); ?></span>
					<?php else : ?>
						<?php foreach ( $tags as $tag ) : ?>
							<span class="wpbp-tag-pill"><span class="dashicons dashicons-tag"></span><?php echo esc_html( $tag ); ?></span>
						<?php endforeach; ?>
					<?php endif; ?>
				</div>
			</div>
			<div class="wpbp-meta-editor wpbp-is-hidden">
				<label class="wpbp-meta-field">
					<span><?php esc_html_e( 'Note', 'wrensafe' ); ?></span>
					<input type="text" class="wpbp-backup-note regular-text" value="<?php echo esc_attr( $note ); ?>" placeholder="<?php esc_attr_e( 'e.g. Before plugin update', 'wrensafe' ); ?>" />
				</label>
				<label class="wpbp-meta-field">
					<span><?php esc_html_e( 'Tags (comma separated)', 'wrensafe' ); ?></span>
					<input type="text" class="wpbp-backup-tags regular-text" value="<?php echo esc_attr( implode( ', ', $tags ) ); ?>" placeholder="<?php esc_attr_e( 'e.g. client, staging, before-update', 'wrensafe' ); ?>" />
				</label>
				<div class="wpbp-meta-editor-actions">
					<button type="button" class="button button-small button-primary wpbp-save-meta"><?php esc_html_e( 'Save', 'wrensafe' ); ?></button>
					<button type="button" class="button button-small wpbp-cancel-meta"><?php esc_html_e( 'Cancel', 'wrensafe' ); ?></button>
				</div>
			</div>
		</div>
		<?php
	}
}
