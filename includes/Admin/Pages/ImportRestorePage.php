<?php
/**
 * Import / Restore wizard page.
 *
 * The "Upload a .wbp File" source drives a real chunked upload, and a
 * "Restore" link from the Backup Browser drives a real local restore —
 * both end at the same Archive Summary + confirmation + Restore Now flow,
 * powered by the Restore Engine (see assets/admin/js/admin.js).
 *
 * @package WPBP\Admin\Pages
 */

namespace WPBP\Admin\Pages;

use WPBP\Admin\Icons;
use WPBP\Core\Database\BackupsRepository;
use WPBP\Security\CapabilityManager;
use WPBP\Utils\Paths;
use WPBP\WooCommerce\WooSafeMode;

defined( 'ABSPATH' ) || exit;

class ImportRestorePage extends AbstractPage {

	public function get_title(): string {
		return __( 'Import / Restore', 'wrensafe' );
	}

	protected function get_subtitle(): string {
		return __( 'Restore a backup from this server, upload a .wbp archive from another site, or bring in a backup made by another backup plugin.', 'wrensafe' );
	}

	protected function get_header_actions(): array {
		return array(
			array(
				'label'   => __( 'Backup Browser', 'wrensafe' ),
				'url'     => self::page_url( 'wrensafe-browser' ),
				'variant' => 'secondary',
				'icon'    => 'dashicons-archive',
				'cap'     => \WPBP\Security\CapabilityManager::VIEW_BACKUPS,
			),
		);
	}

	protected function required_capability(): string {
		return CapabilityManager::RESTORE_BACKUPS;
	}

	private function import_sources(): array {
		$sources = array(
			'upload'  => array( __( 'Upload a .wbp file', 'wrensafe' ), __( 'Drag and drop or browse for a backup file.', 'wrensafe' ), 'dashicons-upload' ),
			'plugins' => array( __( 'Other Backup Plugins', 'wrensafe' ), __( 'From All-in-One WP Migration, UpdraftPlus, Duplicator and 7 more.', 'wrensafe' ), 'dashicons-admin-plugins' ),
			'local'   => array( __( 'Local backup', 'wrensafe' ), __( 'Restore from a backup already in the Backup Browser.', 'wrensafe' ), 'dashicons-archive' ),
			'url'     => array( __( 'From URL', 'wrensafe' ), __( 'Import a .wbp file from a direct URL.', 'wrensafe' ), 'dashicons-admin-links' ),
		);

		// Importing from an arbitrary server path is restricted to true
		// administrators (see ImportSourceAjax::server_path()) — hide the
		// option entirely for a Team Access user who'd just be rejected.
		if ( CapabilityManager::is_site_administrator() ) {
			$sources['server'] = array( __( 'Server path', 'wrensafe' ), __( 'Import a .wbp file already on this server.', 'wrensafe' ), 'dashicons-portfolio' );
		}

		/**
		 * Filters the import sources: key => array( label, description,
		 * dashicon ). An add-on that adds a source prints its panel on the
		 * wpbp_import_source_panels action, as an element with
		 * class "wpbp-import-source-panel" and data-source="{key}".
		 *
		 * @param array<string,array{0:string,1:string,2:string}> $sources
		 */
		return (array) apply_filters( 'wpbp_import_sources', $sources );
	}

	protected function render_content(): void {
		\WPBP\Admin\Onboarding::page_viewed( 'restore' );

		$backup_id     = isset( $_GET['backup_id'] ) ? absint( $_GET['backup_id'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$local_backup  = $backup_id ? BackupsRepository::find( $backup_id ) : null;
		$active_source = $local_backup ? 'local' : 'upload';
		?>
		<ol class="wpbp-steps">
			<li class="wpbp-step is-active"><?php esc_html_e( '1. Choose source', 'wrensafe' ); ?></li>
			<li class="wpbp-step"><?php esc_html_e( '2. Upload or locate', 'wrensafe' ); ?></li>
			<li class="wpbp-step"><?php esc_html_e( '3. Restore', 'wrensafe' ); ?></li>
		</ol>

		<div class="wpbp-card">
			<h2><?php esc_html_e( 'Where is the backup coming from?', 'wrensafe' ); ?></h2>
			<div class="wpbp-selectable-grid" id="wpbp-import-source-grid" role="radiogroup" aria-label="<?php esc_attr_e( 'Import source', 'wrensafe' ); ?>">
				<?php foreach ( $this->import_sources() as $key => list( $label, $description, $icon ) ) : ?>
					<button type="button" class="wpbp-selectable-card<?php echo $key === $active_source ? ' is-selected' : ''; ?>" data-wpbp-select="import-source" data-value="<?php echo esc_attr( $key ); ?>" role="radio" aria-checked="<?php echo $key === $active_source ? 'true' : 'false'; ?>">
						<span class="dashicons <?php echo esc_attr( $icon ); ?> wpbp-tile-icon"></span>
						<strong><?php echo esc_html( $label ); ?></strong>
						<span><?php echo esc_html( $description ); ?></span>
					</button>
				<?php endforeach; ?>
			</div>
		</div>

		<div class="wpbp-card wpbp-import-source-panel" data-source="upload" id="wpbp-upload-card" <?php echo 'upload' === $active_source ? '' : 'hidden'; ?>>
			<div class="wpbp-dropzone" id="wpbp-dropzone" role="button" tabindex="0" aria-label="<?php esc_attr_e( 'Upload a backup file', 'wrensafe' ); ?>">
				<span class="dashicons dashicons-upload"></span>
				<p>
					<?php esc_html_e( 'Drag and drop a .wbp or .wpress file here, or', 'wrensafe' ); ?>
					<button type="button" class="button-link wpbp-browse-trigger"><?php esc_html_e( 'click to browse', 'wrensafe' ); ?></button>.
				</p>
				<p class="description"><?php esc_html_e( 'Have a backup from another plugin? Choose Other Backup Plugins above.', 'wrensafe' ); ?></p>
				<input type="file" id="wpbp-upload-input" accept=".wbp,.wpress" />
			</div>
			<div class="wpbp-upload-panel" id="wpbp-upload-progress" hidden>
				<div class="wpbp-upload-file">
					<span class="wpbp-upload-file-icon"><?php Icons::render( 'archive', 22 ); ?></span>
					<div class="wpbp-upload-file-text">
						<strong class="wpbp-upload-name"></strong>
						<span class="wpbp-upload-meta"></span>
					</div>
					<span class="wpbp-upload-percent" aria-hidden="true">0%</span>
					<button type="button" class="button wpbp-upload-cancel"><?php esc_html_e( 'Cancel', 'wrensafe' ); ?></button>
					<button type="button" class="button wpbp-upload-retry" hidden><?php Icons::render( 'refresh', 16 ); ?><?php esc_html_e( 'Try again', 'wrensafe' ); ?></button>
				</div>
				<div class="wpbp-meter" role="progressbar" aria-label="<?php esc_attr_e( 'Upload progress', 'wrensafe' ); ?>" aria-valuemin="0" aria-valuemax="100" aria-valuenow="0">
					<div class="wpbp-meter-fill"></div>
				</div>
				<p class="wpbp-upload-status" role="status" aria-live="polite">
					<span class="wpbp-status-dot" aria-hidden="true"></span>
					<span class="wpbp-progress-status"></span>
				</p>
			</div>
		</div>

		<?php $this->render_foreign_panel(); ?>

		<?php $local_backups = BackupsRepository::list_all(); ?>
		<div class="wpbp-card wpbp-import-source-panel" data-source="local" id="wpbp-local-restore-card" <?php echo 'local' === $active_source ? '' : 'hidden'; ?>>
			<h2><?php esc_html_e( 'Restore a local backup', 'wrensafe' ); ?></h2>
			<?php if ( empty( $local_backups ) ) : ?>
				<p class="wpbp-limit-note"><?php esc_html_e( 'No local backups found. Create a backup first.', 'wrensafe' ); ?></p>
			<?php else : ?>
				<div class="wpbp-backup-browser-notice wpbp-inline-notice wpbp-is-hidden" role="status" aria-live="polite"></div>
				<table class="widefat striped wpbp-local-backups-table wpbp-table-stack">
					<thead>
					<tr>
						<th><?php esc_html_e( 'Backup', 'wrensafe' ); ?></th>
						<th><?php esc_html_e( 'Type', 'wrensafe' ); ?></th>
						<th><?php esc_html_e( 'Size', 'wrensafe' ); ?></th>
						<th><?php esc_html_e( 'Created', 'wrensafe' ); ?></th>
						<th><?php esc_html_e( 'Status', 'wrensafe' ); ?></th>
						<th><?php esc_html_e( 'Actions', 'wrensafe' ); ?></th>
					</tr>
					</thead>
					<tbody>
					<?php
					foreach ( $local_backups as $backup ) :
						$locked      = ! empty( $backup['is_locked'] );
						$highlighted = $local_backup && (int) $local_backup['id'] === (int) $backup['id'];
						?>
						<tr data-backup-id="<?php echo esc_attr( $backup['id'] ); ?>" data-locked="<?php echo $locked ? '1' : '0'; ?>" data-encrypted="<?php echo ! empty( $backup['is_encrypted'] ) ? '1' : '0'; ?>"<?php echo $highlighted ? ' class="wpbp-row-highlight"' : ''; ?>>
							<td>
								<strong><?php echo esc_html( $backup['file_name'] ); ?></strong>
								<div class="wpbp-backup-meta"><?php echo esc_html( $backup['source_url'] ); ?></div>
							</td>
							<td data-colname="<?php esc_attr_e( 'Type', 'wrensafe' ); ?>"><?php echo esc_html( \WPBP\Admin\Labels::backup_type( (string) $backup['backup_type'] ) ); ?></td>
							<td data-colname="<?php esc_attr_e( 'Size', 'wrensafe' ); ?>"><?php echo esc_html( size_format( (int) $backup['size'] ) ); ?></td>
							<td data-colname="<?php esc_attr_e( 'Created', 'wrensafe' ); ?>"><?php echo wp_kses( \WPBP\Utils\Time::local_time_markup( (string) $backup['created_at'] ), \WPBP\Admin\Kses::allowed() ); ?></td>
							<td data-colname="<?php esc_attr_e( 'Status', 'wrensafe' ); ?>">
								<span class="wpbp-backup-status wpbp-backup-status-<?php echo esc_attr( $backup['status'] ); ?>"><?php echo esc_html( \WPBP\Admin\Labels::status( (string) $backup['status'] ) ); ?></span>
								<?php if ( ! empty( $backup['is_encrypted'] ) ) : ?>
									<span class="wpbp-pill" title="<?php esc_attr_e( 'Enter its password below before restoring.', 'wrensafe' ); ?>"><span class="dashicons dashicons-shield" aria-hidden="true"></span><?php esc_html_e( 'Encrypted', 'wrensafe' ); ?></span>
								<?php endif; ?>
								<?php if ( $locked ) : ?>
									<span class="wpbp-pill wpbp-locked-pill" title="<?php esc_attr_e( 'Locked — unlock it in the Backup Browser to restore.', 'wrensafe' ); ?>"><span class="dashicons dashicons-lock" aria-hidden="true"></span><?php esc_html_e( 'Locked', 'wrensafe' ); ?></span>
								<?php endif; ?>
							</td>
							<td class="wpbp-backup-actions">
								<div class="wpbp-row-actions">
									<button type="button" class="button button-small wpbp-local-verify"><?php esc_html_e( 'Verify', 'wrensafe' ); ?></button>
									<button type="button" class="button button-small button-primary wpbp-local-restore"<?php echo $locked ? ' disabled title="' . esc_attr__( 'Locked — unlock it in the Backup Browser to restore.', 'wrensafe' ) . '"' : ''; ?>><?php esc_html_e( 'Review and restore', 'wrensafe' ); ?></button>
								</div>
							</td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
				<p class="wpbp-password-field">
					<label for="wpbp-local-restore-password"><?php esc_html_e( 'Backup password (only if encrypted)', 'wrensafe' ); ?></label><br />
					<input type="password" id="wpbp-local-restore-password" class="regular-text" placeholder="<?php esc_attr_e( 'Leave blank for unencrypted backups', 'wrensafe' ); ?>" />
				</p>
			<?php endif; ?>
		</div>

		<div class="wpbp-card wpbp-import-source-panel" data-source="url" id="wpbp-url-import-card" hidden>
			<h2><?php esc_html_e( 'Import from URL', 'wrensafe' ); ?></h2>
			<p>
				<label for="wpbp-url-import-input"><?php esc_html_e( 'Direct .wbp URL', 'wrensafe' ); ?></label><br />
				<input type="url" id="wpbp-url-import-input" class="regular-text" placeholder="https://example.com/backup.wbp" />
				<button type="button" class="button" id="wpbp-url-import-fetch"><?php esc_html_e( 'Fetch', 'wrensafe' ); ?></button>
			</p>
			<p class="wpbp-limit-note"><?php esc_html_e( 'HTTPS URLs are used by default. An HTTP URL will ask for confirmation before downloading.', 'wrensafe' ); ?></p>
		</div>

		<?php if ( CapabilityManager::is_site_administrator() ) : ?>
		<div class="wpbp-card wpbp-import-source-panel" data-source="server" id="wpbp-server-path-import-card" hidden>
			<h2><?php esc_html_e( 'Import from a server path', 'wrensafe' ); ?></h2>
			<p>
				<label for="wpbp-server-path-import-input"><?php esc_html_e( 'Full path to a .wbp file', 'wrensafe' ); ?></label><br />
				<input type="text" id="wpbp-server-path-import-input" class="regular-text" placeholder="<?php echo esc_attr( Paths::uploads_dir() . '/site-backup.wbp' ); ?>" />
				<button type="button" class="button" id="wpbp-server-path-import-fetch"><?php esc_html_e( 'Import', 'wrensafe' ); ?></button>
			</p>
			<p class="wpbp-limit-note"><span>
				<?php
				/* translators: %s: the site's content folder, usually wp-content */
				echo esc_html( sprintf( __( 'Administrator only. The path must be inside this site\'s content folder (%s).', 'wrensafe' ), Paths::display( Paths::content_dir() ) ) );
				?>
			</span></p>
		</div>
		<?php endif; ?>

		<?php
		/**
		 * Prints the panels of import sources added through
		 * wpbp_import_sources (see import_sources()).
		 */
		do_action( 'wpbp_import_source_panels' );
		?>

		<div class="wpbp-card wpbp-summary" id="wpbp-archive-summary" hidden>
			<div class="wpbp-summary-head">
				<span class="wpbp-summary-icon"><?php Icons::render( 'archive', 24 ); ?></span>
				<div class="wpbp-summary-heading">
					<h2 class="wpbp-summary-title"><?php esc_html_e( 'Review before you restore', 'wrensafe' ); ?></h2>
					<p class="wpbp-summary-sub" id="wpbp-summary-sub"></p>
				</div>
				<span class="wpbp-pill is-brand" id="wpbp-summary-type"></span>
			</div>

			<div class="wpbp-route" id="wpbp-summary-route">
				<div class="wpbp-route-site">
					<span class="wpbp-route-label"><?php esc_html_e( 'From backup', 'wrensafe' ); ?></span>
					<strong class="wpbp-route-url" data-route="source-url"></strong>
					<span class="wpbp-route-meta" data-route="source-meta"></span>
				</div>
				<span class="wpbp-route-arrow"><?php Icons::render( 'arrow-right', 20 ); ?></span>
				<div class="wpbp-route-site is-destination">
					<span class="wpbp-route-label"><?php esc_html_e( 'To this site', 'wrensafe' ); ?></span>
					<strong class="wpbp-route-url" data-route="destination-url"></strong>
					<span class="wpbp-route-meta" data-route="destination-meta"></span>
				</div>
			</div>

			<ul class="wpbp-summary-notes" id="wpbp-summary-notes"></ul>

			<h3 class="wpbp-section-title"><?php esc_html_e( 'What’s in this backup', 'wrensafe' ); ?></h3>
			<div class="wpbp-summary-stats" id="wpbp-summary-stats"></div>
			<div class="wpbp-summary-chips" id="wpbp-summary-chips"></div>

			<h3 class="wpbp-section-title"><?php esc_html_e( 'What do you want to restore?', 'wrensafe' ); ?></h3>
			<div class="wpbp-selectable-grid wpbp-restore-modes" id="wpbp-restore-mode-grid" role="radiogroup" aria-label="<?php esc_attr_e( 'Restore mode', 'wrensafe' ); ?>">
				<button type="button" class="wpbp-selectable-card is-selected" data-wpbp-select="restore-mode" data-value="full" role="radio" aria-checked="true">
					<span class="dashicons dashicons-update wpbp-tile-icon" aria-hidden="true"></span>
					<strong><?php esc_html_e( 'Full restore', 'wrensafe' ); ?></strong>
					<span><?php esc_html_e( 'Database and all files.', 'wrensafe' ); ?></span>
				</button>
				<button type="button" class="wpbp-selectable-card" data-wpbp-select="restore-mode" data-value="database" role="radio" aria-checked="false">
					<span class="dashicons dashicons-database wpbp-tile-icon" aria-hidden="true"></span>
					<strong><?php esc_html_e( 'Database only', 'wrensafe' ); ?></strong>
					<span><?php esc_html_e( 'Posts, pages, settings and users.', 'wrensafe' ); ?></span>
				</button>
				<button type="button" class="wpbp-selectable-card" data-wpbp-select="restore-mode" data-value="files" role="radio" aria-checked="false">
					<span class="dashicons dashicons-media-default wpbp-tile-icon" aria-hidden="true"></span>
					<strong><?php esc_html_e( 'Files only', 'wrensafe' ); ?></strong>
					<span><?php esc_html_e( 'Everything except the database.', 'wrensafe' ); ?></span>
				</button>
				<button type="button" class="wpbp-selectable-card" data-wpbp-select="restore-mode" data-value="uploads" role="radio" aria-checked="false">
					<span class="dashicons dashicons-format-image wpbp-tile-icon" aria-hidden="true"></span>
					<strong><?php esc_html_e( 'Media only', 'wrensafe' ); ?></strong>
					<span><?php esc_html_e( 'The uploads folder.', 'wrensafe' ); ?></span>
				</button>
				<button type="button" class="wpbp-selectable-card" data-wpbp-select="restore-mode" data-value="themes" role="radio" aria-checked="false">
					<span class="dashicons dashicons-admin-appearance wpbp-tile-icon" aria-hidden="true"></span>
					<strong><?php esc_html_e( 'Themes only', 'wrensafe' ); ?></strong>
					<span><?php esc_html_e( 'Every theme in the backup.', 'wrensafe' ); ?></span>
				</button>
				<button type="button" class="wpbp-selectable-card" data-wpbp-select="restore-mode" data-value="plugins" role="radio" aria-checked="false">
					<span class="dashicons dashicons-admin-plugins wpbp-tile-icon" aria-hidden="true"></span>
					<strong><?php esc_html_e( 'Plugins only', 'wrensafe' ); ?></strong>
					<span><?php esc_html_e( 'Every plugin in the backup.', 'wrensafe' ); ?></span>
				</button>
			</div>

			<?php if ( WooSafeMode::is_active() ) : ?>
				<p class="wpbp-restore-confirm">
					<label>
						<input type="checkbox" id="wpbp-woo-safe-mode" <?php checked( (bool) \WPBP\Core\Settings::get( 'woo_safe_mode_default', true ) ); ?> />
						<?php esc_html_e( 'WooCommerce Safe Mode — protect live orders, customers, and stock data during this restore.', 'wrensafe' ); ?>
					</label>
					<?php if ( ! WooSafeMode::is_hpos_enabled() ) : ?>
						<br /><span class="wpbp-limit-note"><?php esc_html_e( 'Your store uses legacy order storage — Safe Mode fully protects WooCommerce\'s lookup tables, but please review orders after restoring.', 'wrensafe' ); ?></span>
					<?php endif; ?>
				</p>
			<?php endif; ?>

			<div class="wpbp-next-steps">
				<h4><?php esc_html_e( 'What happens when you restore', 'wrensafe' ); ?></h4>
				<ol id="wpbp-next-steps-list">
					<li data-step="verify"><?php Icons::render( 'shield', 16 ); ?><span><?php esc_html_e( 'Every file in the backup is checked against its checksum first — a damaged backup is rejected before anything changes.', 'wrensafe' ); ?></span></li>
					<li data-step="rollback"><?php Icons::render( 'restore', 16 ); ?><span><?php esc_html_e( 'A rollback point of this site’s current database is saved, so you can undo the restore.', 'wrensafe' ); ?></span></li>
					<li data-step="maintenance"><?php Icons::render( 'clock', 16 ); ?><span><?php esc_html_e( 'Visitors see a short maintenance page while the restore runs. Keep this tab open until it finishes.', 'wrensafe' ); ?></span></li>
					<li data-step="login"><?php Icons::render( 'lock', 16 ); ?><span><?php esc_html_e( 'A database restore replaces user accounts, so you’ll sign in again afterwards — with a login from the backup.', 'wrensafe' ); ?></span></li>
				</ol>
			</div>

			<label class="wpbp-confirm-check">
				<input type="checkbox" id="wpbp-confirm-restore" />
				<span><?php esc_html_e( 'I understand this replaces the selected parts of this website with the backup.', 'wrensafe' ); ?></span>
			</label>

			<div class="wpbp-summary-actions">
				<button type="button" id="wpbp-start-restore" class="button button-primary button-hero" disabled>
					<?php Icons::render( 'restore', 18 ); ?>
					<?php esc_html_e( 'Restore now', 'wrensafe' ); ?>
				</button>
				<button type="button" class="button button-link wpbp-summary-reset" id="wpbp-summary-reset"><?php esc_html_e( 'Choose a different backup', 'wrensafe' ); ?></button>
			</div>

			<div class="wpbp-card wpbp-restore-progress" id="wpbp-restore-progress" hidden>
				<div class="wpbp-progress-bar"><div class="wpbp-progress-bar-fill" style="width:0%"></div></div>
				<p class="wpbp-progress-status"></p>
			</div>
		</div>
		<?php
	}
	/**
	 * Import / Restore → Other Backup Plugins: upload the files another
	 * plugin made, then Wrensafe converts and restores them
	 * (assets/admin/js/admin.js → initForeignImport, Ajax\ForeignImportAjax).
	 */
	private function render_foreign_panel(): void {
		// Name, which files to upload, monogram, file types. Other plugins'
		// logos are their trademarks, so each card shows a Wrensafe-styled
		// monogram of the name and the file types to upload instead.
		$formats = array(
			array( 'All-in-One WP Migration', __( 'The .wpress file.', 'wrensafe' ), 'AIO', array( '.wpress' ) ),
			array( 'UpdraftPlus', __( 'Every file of one backup: the database .gz file and the plugins, themes, uploads and others .zip files.', 'wrensafe' ), 'UP', array( '.gz', '.zip' ) ),
			array( 'Duplicator', __( 'The archive file (…_archive.zip or …_archive.daf). The installer.php file isn’t needed.', 'wrensafe' ), 'DU', array( '.zip', '.daf' ) ),
			array( 'WPvivid', __( 'The …_backup_all.zip file, or every part of one backup.', 'wrensafe' ), 'WV', array( '.zip' ) ),
			array( 'BackWPup', __( 'The backup archive.', 'wrensafe' ), 'BW', array( '.zip', '.tar', '.tar.gz' ) ),
			array( 'Backup Migration', __( 'The BM_….zip backup file.', 'wrensafe' ), 'BM', array( '.zip' ) ),
			array( 'Total Upkeep', __( 'The boldgrid-backup-….zip file of a full backup.', 'wrensafe' ), 'TU', array( '.zip' ) ),
			array( 'Solid Backups (BackupBuddy)', __( 'The .zip file of a full backup.', 'wrensafe' ), 'SB', array( '.zip' ) ),
			array( 'WP Migrate', __( 'A full-site export, or a database export.', 'wrensafe' ), 'WM', array( '.zip', '.sql', '.sql.gz' ) ),
			array( 'Jetpack VaultPress Backup', __( 'The backup download.', 'wrensafe' ), 'JP', array( '.tar.gz' ) ),
			array( __( 'Any other backup', 'wrensafe' ), __( 'A database dump, a .zip of the wp‑content folder, or both.', 'wrensafe' ), '', array( '.sql', '.zip' ) ),
		);
		?>
		<div class="wpbp-card wpbp-import-source-panel wpbp-foreign" data-source="plugins" id="wpbp-foreign-card" hidden>
			<h2><?php esc_html_e( 'Restore a backup made by another plugin', 'wrensafe' ); ?></h2>
			<p class="wpbp-foreign-lead"><?php esc_html_e( 'Upload the backup files just as the other plugin saved them. Wrensafe reads them, shows you what’s inside, and restores them with the same safety checks as its own backups. The other plugin doesn’t need to be installed.', 'wrensafe' ); ?></p>

			<ul class="wpbp-foreign-formats">
				<?php foreach ( $formats as list( $name, $hint, $monogram, $extensions ) ) : ?>
					<li>
						<?php if ( '' !== $monogram ) : ?>
							<span class="wpbp-foreign-logo<?php echo strlen( $monogram ) > 2 ? ' is-long' : ''; ?>" aria-hidden="true"><?php echo esc_html( $monogram ); ?></span>
						<?php else : ?>
							<span class="wpbp-foreign-logo is-generic" aria-hidden="true"><?php Icons::render( 'file', 22 ); ?></span>
						<?php endif; ?>
						<span class="wpbp-foreign-format-text">
							<strong><?php echo esc_html( $name ); ?></strong>
							<span><?php echo esc_html( $hint ); ?></span>
							<span class="wpbp-foreign-exts">
								<span class="screen-reader-text"><?php esc_html_e( 'File types:', 'wrensafe' ); ?></span>
								<?php foreach ( $extensions as $extension ) : ?>
									<code><?php echo esc_html( $extension ); ?></code>
								<?php endforeach; ?>
							</span>
						</span>
					</li>
				<?php endforeach; ?>
			</ul>

			<div class="wpbp-dropzone wpbp-foreign-dropzone" id="wpbp-foreign-dropzone" role="button" tabindex="0" aria-label="<?php esc_attr_e( 'Upload backup files', 'wrensafe' ); ?>">
				<span class="dashicons dashicons-upload" aria-hidden="true"></span>
				<p>
					<?php esc_html_e( 'Drag the backup files here, or', 'wrensafe' ); ?>
					<button type="button" class="button-link wpbp-browse-trigger"><?php esc_html_e( 'click to browse', 'wrensafe' ); ?></button>.
				</p>
				<p class="description"><?php esc_html_e( 'You can add several files. They upload one after another and pick up where they stopped if the connection drops.', 'wrensafe' ); ?></p>
				<input type="file" id="wpbp-foreign-input" multiple accept=".<?php echo esc_attr( implode( ',.', \WPBP\Import\Foreign\ImportSet::EXTENSIONS ) ); ?>" />
			</div>

			<ul class="wpbp-foreign-files" id="wpbp-foreign-files" aria-live="polite" hidden></ul>

			<div class="wpbp-foreign-result" id="wpbp-foreign-result" hidden></div>
		</div>
		<?php
	}
}
