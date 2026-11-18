<?php
/**
 * Staging page — create a one-click staging copy and push it back to live
 * (see WPBP\Staging\StagingManager / PushToLive).
 *
 * @package WPBP\Admin\Pages
 */

namespace WPBP\Admin\Pages;

use WPBP\Core\Database\StagingSitesRepository;
use WPBP\Core\LimitsManager;
use WPBP\Security\CapabilityManager;

defined( 'ABSPATH' ) || exit;

class StagingPage extends AbstractPage {

	public function get_title(): string {
		return __( 'Staging', 'wrensafe' );
	}

	protected function get_subtitle(): string {
		return __( 'Create a private copy of this site to test updates and changes before they reach visitors.', 'wrensafe' );
	}

	protected function required_capability(): string {
		return CapabilityManager::MANAGE_STAGING;
	}

	protected function render_content(): void {
		$sites          = StagingSitesRepository::list_all();
		$layout_problem = \WPBP\Staging\StagingCreator::layout_problem();
		?>
		<?php if ( empty( $sites ) ) : ?>
			<div class="wpbp-card">
				<h2><?php esc_html_e( 'Create a staging site', 'wrensafe' ); ?></h2>
				<p><?php esc_html_e( 'Creates a full copy of your site in a subdirectory, sharing the same database with its own table prefix.', 'wrensafe' ); ?></p>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><?php esc_html_e( 'Leave out', 'wrensafe' ); ?></th>
						<td>
							<fieldset class="wpbp-checkbox-list">
								<legend class="screen-reader-text"><?php esc_html_e( 'Leave out of the staging copy', 'wrensafe' ); ?></legend>
								<label><input type="checkbox" id="wpbp-stg-exclude-users" /> <?php esc_html_e( 'Users (keeps only the current admin)', 'wrensafe' ); ?></label>
								<label><input type="checkbox" id="wpbp-stg-exclude-comments" /> <?php esc_html_e( 'Comments', 'wrensafe' ); ?></label>
								<label><input type="checkbox" id="wpbp-stg-exclude-orders" /> <?php esc_html_e( 'WooCommerce orders', 'wrensafe' ); ?></label>
							</fieldset>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Password protection', 'wrensafe' ); ?></th>
						<td>
							<label><input type="checkbox" id="wpbp-stg-password-protect" /> <?php esc_html_e( 'Require a login to view the staging site', 'wrensafe' ); ?></label>
							<p class="wpbp-limit-note"><?php esc_html_e( 'Uses HTTP Basic Auth via .htaccess (Apache/LiteSpeed). Nginx hosts need a server-level rule instead.', 'wrensafe' ); ?></p>
							<div id="wpbp-stg-password-fields" hidden>
								<input type="text" id="wpbp-stg-username" placeholder="<?php esc_attr_e( 'Username', 'wrensafe' ); ?>" /><br />
								<input type="text" id="wpbp-stg-password" placeholder="<?php esc_attr_e( 'Password', 'wrensafe' ); ?>" />
							</div>
						</td>
					</tr>
				</table>
				<?php if ( '' !== $layout_problem ) : ?>
					<div class="notice notice-warning inline wpbp-notice"><p><?php echo esc_html( $layout_problem ); ?></p></div>
				<?php endif; ?>
				<button type="button" id="wpbp-stg-create" class="button button-primary"<?php disabled( '' !== $layout_problem ); ?>><?php esc_html_e( 'Create staging site', 'wrensafe' ); ?></button>

				<div class="wpbp-card wpbp-backup-progress" id="wpbp-stg-progress" hidden>
					<div class="wpbp-progress-bar"><div class="wpbp-progress-bar-fill" style="width:0%"></div></div>
					<p class="wpbp-progress-status"></p>
				</div>
			</div>
		<?php else : ?>
			<?php foreach ( $sites as $site ) : ?>
				<div class="wpbp-card" data-staging-id="<?php echo esc_attr( $site['id'] ); ?>">
					<h2><?php esc_html_e( 'Staging site', 'wrensafe' ); ?></h2>
					<table class="widefat striped">
						<tbody>
						<tr><td><strong><?php esc_html_e( 'URL', 'wrensafe' ); ?></strong></td><td><a href="<?php echo esc_url( $site['url'] ); ?>" target="_blank"><?php echo esc_html( $site['url'] ); ?></a></td></tr>
						<tr><td><strong><?php esc_html_e( 'Status', 'wrensafe' ); ?></strong></td><td><span class="wpbp-backup-status wpbp-backup-status-<?php echo esc_attr( 'active' === $site['status'] ? 'completed' : 'verifying' ); ?>"><?php echo esc_html( \WPBP\Admin\Labels::status( (string) $site['status'] ) ); ?></span></td></tr>
						<tr><td><strong><?php esc_html_e( 'Password protected', 'wrensafe' ); ?></strong></td><td><?php echo $site['password_protected'] ? esc_html__( 'Yes', 'wrensafe' ) : esc_html__( 'No', 'wrensafe' ); ?></td></tr>
						<tr><td><strong><?php esc_html_e( 'Last synced', 'wrensafe' ); ?></strong></td><td><?php echo $site['last_synced_at'] ? esc_html( mysql2date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $site['last_synced_at'] ) ) : '—'; ?></td></tr>
						</tbody>
					</table>

					<p>
						<button type="button" class="button button-primary wpbp-stg-push-live"><?php esc_html_e( 'Push staging to live', 'wrensafe' ); ?></button>
						<button type="button" class="button button-link-delete wpbp-stg-delete"><?php esc_html_e( 'Delete staging site', 'wrensafe' ); ?></button>
					</p>

					<div class="wpbp-card wpbp-backup-progress" id="wpbp-stg-progress" hidden>
						<div class="wpbp-progress-bar"><div class="wpbp-progress-bar-fill" style="width:0%"></div></div>
						<p class="wpbp-progress-status"></p>
					</div>
				</div>
			<?php endforeach; ?>
		<?php endif; ?>
		<?php
	}
}
