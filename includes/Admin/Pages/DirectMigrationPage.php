<?php
/**
 * Direct Migration page.
 *
 * Two roles, both on this same page: the destination site generates a
 * one-time key (see WPBP\Migration\MigrationKey), and the source site uses
 * that key plus a local backup to push a transfer (see
 * WPBP\Migration\TransferClient / TransferServer).
 *
 * @package WPBP\Admin\Pages
 */

namespace WPBP\Admin\Pages;

use WPBP\Core\Database\BackupsRepository;
use WPBP\Core\LimitsManager;
use WPBP\Security\CapabilityManager;

defined( 'ABSPATH' ) || exit;

class DirectMigrationPage extends AbstractPage {

	public function get_title(): string {
		return __( 'Direct Migration', 'wrensafe' );
	}

	protected function get_subtitle(): string {
		return __( 'Move this site straight to another WordPress site using a one-time migration key, without downloading or uploading files.', 'wrensafe' );
	}

	protected function required_capability(): string {
		return CapabilityManager::MANAGE_DIRECT_MIGRATION;
	}

	protected function render_content(): void {
		$backups = BackupsRepository::list_all();
		?>
		<div class="wpbp-card">
			<h2><?php esc_html_e( '1. Moving a site here? Generate a key', 'wrensafe' ); ?></h2>
			<p><?php esc_html_e( 'Do this on the site that will receive the backup. Enter the key and this site’s address on the other site before the key expires. When the backup arrives, it is listed under Import / Restore → Local backup, ready to review and restore.', 'wrensafe' ); ?></p>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><label for="wpbp-mig-expiry"><?php esc_html_e( 'Key expires after', 'wrensafe' ); ?></label></th>
					<td>
						<select id="wpbp-mig-expiry">
							<option value="15"><?php esc_html_e( '15 minutes', 'wrensafe' ); ?></option>
							<option value="30" selected><?php esc_html_e( '30 minutes', 'wrensafe' ); ?></option>
							<option value="60"><?php esc_html_e( '60 minutes', 'wrensafe' ); ?></option>
						</select>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'IP restriction', 'wrensafe' ); ?></th>
					<td><label><input type="checkbox" id="wpbp-mig-restrict-ip" /> <?php esc_html_e( 'Only accept this key from the IP address that uses it first', 'wrensafe' ); ?></label></td>
				</tr>
			</table>
			<button type="button" id="wpbp-mig-generate" class="button button-primary"><?php esc_html_e( 'Generate migration key', 'wrensafe' ); ?></button>

			<div class="wpbp-card" id="wpbp-mig-key-result" hidden>
				<p>
					<strong><?php esc_html_e( 'Destination site address', 'wrensafe' ); ?>:</strong>
					<code id="wpbp-mig-key-url"></code>
					<button type="button" class="button button-small wpbp-copy" data-copy-target="wpbp-mig-key-url"><?php esc_html_e( 'Copy', 'wrensafe' ); ?></button>
				</p>
				<p>
					<strong><?php esc_html_e( 'Migration key', 'wrensafe' ); ?>:</strong>
					<code id="wpbp-mig-key-token"></code>
					<button type="button" class="button button-small wpbp-copy" data-copy-target="wpbp-mig-key-token"><?php esc_html_e( 'Copy', 'wrensafe' ); ?></button>
				</p>
				<p class="wpbp-limit-note" id="wpbp-mig-key-expiry-note"></p>
				<p class="wpbp-limit-note">
					<span class="dashicons dashicons-warning" style="color:#dba617;vertical-align:text-bottom;"></span>
					<?php esc_html_e( 'Treat this key like a password. It lets one backup be sent to this site, expires on its own, and should only be shared over a secure channel.', 'wrensafe' ); ?>
				</p>
			</div>
		</div>

		<div class="wpbp-card">
			<h2><?php esc_html_e( '2. Moving this site away? Send a backup', 'wrensafe' ); ?></h2>
			<p><?php esc_html_e( 'Do this on the site you are moving, with the key generated on the receiving site.', 'wrensafe' ); ?></p>

			<?php if ( empty( $backups ) ) : ?>
				<p><em><?php esc_html_e( 'Create a backup first from the Create Backup page.', 'wrensafe' ); ?></em></p>
			<?php else : ?>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="wpbp-mig-backup"><?php esc_html_e( 'Backup to send', 'wrensafe' ); ?></label></th>
						<td>
							<select id="wpbp-mig-backup">
								<?php foreach ( $backups as $backup ) : ?>
									<option value="<?php echo esc_attr( $backup['id'] ); ?>">
										<?php echo esc_html( $backup['file_name'] . ' (' . size_format( (int) $backup['size'] ) . ')' ); ?>
									</option>
								<?php endforeach; ?>
							</select>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="wpbp-mig-dest-url"><?php esc_html_e( 'Destination site address', 'wrensafe' ); ?></label></th>
						<td><input type="url" id="wpbp-mig-dest-url" class="regular-text" placeholder="https://destination-site.com" /></td>
					</tr>
					<tr>
						<th scope="row"><label for="wpbp-mig-token"><?php esc_html_e( 'Migration key', 'wrensafe' ); ?></label></th>
						<td><input type="text" id="wpbp-mig-token" class="regular-text" /></td>
					</tr>
				</table>
				<button type="button" id="wpbp-mig-send" class="button button-primary"><?php esc_html_e( 'Start transfer', 'wrensafe' ); ?></button>

				<div class="wpbp-card wpbp-backup-progress" id="wpbp-mig-progress" hidden>
					<div class="wpbp-progress-bar"><div class="wpbp-progress-bar-fill" style="width:0%"></div></div>
					<p class="wpbp-progress-status"></p>
				</div>
			<?php endif; ?>
		</div>
		<?php
	}
}
