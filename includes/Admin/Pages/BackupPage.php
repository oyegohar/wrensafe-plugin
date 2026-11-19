<?php
/**
 * Create Backup wizard page.
 *
 * Drives the real backup pipeline built in the Backup Engine and Archive
 * Writer development phases via AJAX (see assets/admin/js/admin.js).
 *
 * @package WPBP\Admin\Pages
 */

namespace WPBP\Admin\Pages;

use WPBP\Security\CapabilityManager;

defined( 'ABSPATH' ) || exit;

class BackupPage extends AbstractPage {

	public function get_title(): string {
		return __( 'Create Backup', 'wrensafe' );
	}

	protected function get_subtitle(): string {
		return __( 'Choose what to back up. Backups are saved on this server, where you can download, verify or restore them at any time.', 'wrensafe' );
	}

	protected function required_capability(): string {
		return CapabilityManager::CREATE_BACKUPS;
	}

	/**
	 * Backup type cards, see development plan section 8 "Backup Features".
	 */
	private function backup_types(): array {
		return array(
			'full'     => array( __( 'Full website', 'wrensafe' ), __( 'Database, files, themes, plugins, and uploads.', 'wrensafe' ), 'dashicons-admin-site-alt3' ),
			'database' => array( __( 'Database only', 'wrensafe' ), __( 'All database tables.', 'wrensafe' ), 'dashicons-database' ),
			'files'    => array( __( 'Files only', 'wrensafe' ), __( 'Themes, plugins, and uploads, no database.', 'wrensafe' ), 'dashicons-media-document' ),
			'media'    => array( __( 'Media only', 'wrensafe' ), __( 'The uploads folder only.', 'wrensafe' ), 'dashicons-format-image' ),
			'themes'   => array( __( 'Themes only', 'wrensafe' ), __( 'All installed themes.', 'wrensafe' ), 'dashicons-admin-appearance' ),
			'plugins'  => array( __( 'Plugins only', 'wrensafe' ), __( 'All installed plugins.', 'wrensafe' ), 'dashicons-admin-plugins' ),
		);
	}

	protected function render_content(): void {
		// Sweep stale (server-killed) jobs so they surface as failed with a
		// log, never as a silent "running 90%" (throttled internally).
		\WPBP\Backup\BackupManager::fail_stale_jobs();

		?>
		<?php // Backup progress now runs inside a progress modal (Issue 07, Part G). ?>
		<ol class="wpbp-steps">
			<li class="wpbp-step is-active"><?php esc_html_e( '1. Choose type', 'wrensafe' ); ?></li>
			<li class="wpbp-step"><?php esc_html_e( '2. Options', 'wrensafe' ); ?></li>
			<li class="wpbp-step"><?php esc_html_e( '3. Run backup', 'wrensafe' ); ?></li>
		</ol>

		<div class="wpbp-card">
			<h2><?php esc_html_e( 'What would you like to back up?', 'wrensafe' ); ?></h2>
			<div class="wpbp-selectable-grid" id="wpbp-backup-type-grid" role="radiogroup" aria-label="<?php esc_attr_e( 'Backup type', 'wrensafe' ); ?>">
				<?php foreach ( $this->backup_types() as $key => list( $label, $description, $icon ) ) : ?>
					<button type="button" class="wpbp-selectable-card<?php echo 'full' === $key ? ' is-selected' : ''; ?>" data-wpbp-select="backup-type" data-value="<?php echo esc_attr( $key ); ?>" role="radio" aria-checked="<?php echo 'full' === $key ? 'true' : 'false'; ?>">
						<span class="dashicons <?php echo esc_attr( $icon ); ?> wpbp-tile-icon"></span>
						<strong><?php echo esc_html( $label ); ?></strong>
						<span><?php echo esc_html( $description ); ?></span>
					</button>
				<?php endforeach; ?>
			</div>
		</div>

		<?php
		/**
		 * Prints extra backup options after the backup type (for example an
		 * incremental / differential mode added by an add-on).
		 */
		do_action( 'wpbp_backup_page_options' );
		?>

		<div class="wpbp-card">
			<h2><?php esc_html_e( 'Notes and tags', 'wrensafe' ); ?></h2>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><label for="wpbp-backup-note"><?php esc_html_e( 'Note', 'wrensafe' ); ?></label></th>
					<td><input type="text" id="wpbp-backup-note" class="regular-text" placeholder="<?php esc_attr_e( 'e.g. Before plugin update', 'wrensafe' ); ?>" /></td>
				</tr>
				<tr>
					<th scope="row"><label for="wpbp-backup-tag"><?php esc_html_e( 'Tag', 'wrensafe' ); ?></label></th>
					<td><input type="text" id="wpbp-backup-tag" class="regular-text" placeholder="<?php esc_attr_e( 'e.g. client-handover', 'wrensafe' ); ?>" /></td>
				</tr>
				<?php
				// Why encryption can't be used on this server ('' when it can).
				$encryption_off = method_exists( \WPBP\Backup\BackupEncryption::class, 'unavailable_reason' ) ? (string) \WPBP\Backup\BackupEncryption::unavailable_reason() : '';
				?>
				<tr>
					<th scope="row"><?php esc_html_e( 'Encryption', 'wrensafe' ); ?></th>
					<td>
						<div class="wpbp-checkbox-row">
							<input type="checkbox" id="wpbp-backup-encrypt" <?php disabled( '' !== $encryption_off ); ?><?php echo '' !== $encryption_off ? ' aria-describedby="wpbp-backup-encrypt-unavailable"' : ''; ?> />
							<div class="wpbp-checkbox-content">
								<label for="wpbp-backup-encrypt"><?php esc_html_e( 'Encrypt this backup with a password (AES-256)', 'wrensafe' ); ?></label>
								<?php if ( '' !== $encryption_off ) : ?>
									<p class="wpbp-field-description" id="wpbp-backup-encrypt-unavailable"><?php echo esc_html( $encryption_off ); ?></p>
								<?php endif; ?>
								<div id="wpbp-backup-encrypt-fields" class="wpbp-password-field" hidden>
									<span class="wpbp-password-input">
										<input type="password" id="wpbp-backup-encrypt-password" class="regular-text" placeholder="<?php esc_attr_e( 'Password', 'wrensafe' ); ?>" autocomplete="new-password" />
										<button type="button" class="wpbp-password-toggle" data-target="wpbp-backup-encrypt-password" aria-label="<?php esc_attr_e( 'Show password', 'wrensafe' ); ?>" aria-pressed="false">
											<span class="dashicons dashicons-visibility"></span>
										</button>
									</span>
									<p class="wpbp-field-description"><?php esc_html_e( 'This password is never stored anywhere. If you lose it, this backup cannot be recovered.', 'wrensafe' ); ?></p>
								</div>
							</div>
						</div>
					</td>
				</tr>
			</table>
		</div>

		<p>
			<button type="button" id="wpbp-start-backup" class="button button-primary button-hero wpbp-action-button">
				<?php esc_html_e( 'Start backup', 'wrensafe' ); ?>
			</button>
		</p>
		<?php
	}
}
