<?php
/**
 * Activity Logs page.
 *
 * Reads from the file-based Logger. A dedicated wp_wpbp_logs database table
 * replaces this storage in a later development phase.
 *
 * @package WPBP\Admin\Pages
 */

namespace WPBP\Admin\Pages;

use WPBP\Security\CapabilityManager;
use WPBP\Utils\Logger;

defined( 'ABSPATH' ) || exit;

class LogsPage extends AbstractPage {

	public function get_title(): string {
		return __( 'Activity Logs', 'wrensafe' );
	}

	protected function get_subtitle(): string {
		return __( 'Every backup, restore and migration step, with the exact reason when something fails. Passwords and keys are never logged.', 'wrensafe' );
	}

	protected function required_capability(): string {
		return CapabilityManager::VIEW_LOGS;
	}

	/** Levels offered by the filter dropdown (Issue 09). */
	private function levels(): array {
		return array( 'debug', 'info', 'success', 'warning', 'error', 'critical', 'failed' );
	}

	protected function render_content(): void {
		// Filters are read-only view state, so a nonce isn't required here.
		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		$level  = isset( $_GET['level'] ) ? sanitize_key( wp_unslash( $_GET['level'] ) ) : '';
		$search = isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : '';
		// phpcs:enable

		if ( ! in_array( $level, $this->levels(), true ) ) {
			$level = '';
		}

		$per_page = 50;
		$total    = \WPBP\Core\Database\LogsRepository::count_filtered( $level, $search );
		$pages    = max( 1, (int) ceil( $total / $per_page ) );
		$paged    = isset( $_GET['paged'] ) ? min( $pages, max( 1, absint( $_GET['paged'] ) ) ) : 1; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only view state.
		$rows     = \WPBP\Core\Database\LogsRepository::get_filtered( $per_page, $level, $search, ( $paged - 1 ) * $per_page );
		$entries  = array();
		foreach ( $rows as $row ) {
			$entries[] = array(
				'time'    => $row['created_at'],
				'level'   => $row['level'],
				'message' => $row['message'],
			);
		}

		// Root-relative so the browser resolves each link against the current
		// page's scheme+host — an https admin never serves an http download
		// link (Chrome blocks that as an insecure mixed-content download). Same
		// fix as the Backup Browser download link.
		$logs_url   = set_url_scheme( wp_nonce_url( admin_url( 'admin-post.php?action=wpbp_export_logs' ), 'wpbp_logs_export', '_wpbp_nonce' ), 'relative' );
		$bundle_url = set_url_scheme( wp_nonce_url( admin_url( 'admin-post.php?action=wpbp_export_debug_bundle' ), 'wpbp_logs_export', '_wpbp_nonce' ), 'relative' );
		$clear_url  = set_url_scheme( wp_nonce_url( admin_url( 'admin-post.php?action=wpbp_clear_logs' ), 'wpbp_logs_export', '_wpbp_nonce' ), 'relative' );

		if ( isset( $_GET['cleared'] ) ) : // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			?>
			<div class="notice notice-success wpbp-notice"><p><?php esc_html_e( 'Activity logs cleared.', 'wrensafe' ); ?></p></div>
		<?php endif; ?>
		<div class="wpbp-actions" style="margin-bottom:16px;">
			<a class="button" href="<?php echo esc_url( $logs_url ); ?>">
				<span class="dashicons dashicons-download" style="vertical-align:text-bottom;"></span>
				<?php esc_html_e( 'Export logs', 'wrensafe' ); ?>
			</a>
			<a class="button" href="<?php echo esc_url( $bundle_url ); ?>">
				<span class="dashicons dashicons-sos" style="vertical-align:text-bottom;"></span>
				<?php esc_html_e( 'Export debug bundle', 'wrensafe' ); ?>
			</a>
			<a class="button button-link-delete wpbp-clear-logs" href="<?php echo esc_url( $clear_url ); ?>" data-confirm="<?php esc_attr_e( 'Delete all activity log entries? This cannot be undone.', 'wrensafe' ); ?>">
				<?php esc_html_e( 'Clear logs', 'wrensafe' ); ?>
			</a>
			<span class="description"><?php esc_html_e( 'Secrets (passwords, tokens, keys) are masked or omitted.', 'wrensafe' ); ?></span>
		</div>

		<form method="get" class="wpbp-logs-filter" style="margin-bottom:12px;display:flex;gap:8px;align-items:center;flex-wrap:wrap;">
			<input type="hidden" name="page" value="wrensafe-logs" />
			<label for="wpbp-log-level-filter" class="screen-reader-text"><?php esc_html_e( 'Filter by level', 'wrensafe' ); ?></label>
			<select name="level" id="wpbp-log-level-filter">
				<option value=""><?php esc_html_e( 'All levels', 'wrensafe' ); ?></option>
				<?php foreach ( $this->levels() as $option ) : ?>
					<option value="<?php echo esc_attr( $option ); ?>" <?php selected( $level, $option ); ?>><?php echo esc_html( \WPBP\Admin\Labels::log_level( $option ) ); ?></option>
				<?php endforeach; ?>
			</select>
			<input type="search" name="s" value="<?php echo esc_attr( $search ); ?>" placeholder="<?php esc_attr_e( 'Search messages…', 'wrensafe' ); ?>" />
			<button type="submit" class="button"><?php esc_html_e( 'Filter', 'wrensafe' ); ?></button>
			<?php if ( '' !== $level || '' !== $search ) : ?>
				<a class="button" href="<?php echo esc_url( admin_url( 'admin.php?page=wrensafe-logs' ) ); ?>"><?php esc_html_e( 'Reset', 'wrensafe' ); ?></a>
			<?php endif; ?>
		</form>

		<div class="wpbp-card">
			<?php if ( empty( $entries ) ) : ?>
				<p><?php esc_html_e( 'No activity has been recorded yet.', 'wrensafe' ); ?></p>
			<?php else : ?>
				<table class="widefat striped">
					<thead>
					<tr>
						<th><?php esc_html_e( 'Time', 'wrensafe' ); ?></th>
						<th><?php esc_html_e( 'Level', 'wrensafe' ); ?></th>
						<th><?php esc_html_e( 'Message', 'wrensafe' ); ?></th>
					</tr>
					</thead>
					<tbody>
					<?php foreach ( $entries as $entry ) : ?>
						<tr>
							<td class="wpbp-log-time"><?php echo esc_html( $entry['time'] ?? '' ); ?></td>
							<td><span class="wpbp-log-level wpbp-log-level-<?php echo esc_attr( $entry['level'] ?? 'info' ); ?>"><?php echo esc_html( \WPBP\Admin\Labels::log_level( (string) ( $entry['level'] ?? '' ) ) ); ?></span></td>
							<td>
								<?php
								// "restore_url_replacement_completed: all tables processed."
								// → the sentence first, the event code as a small tag.
								$message = (string) ( $entry['message'] ?? '' );
								$code    = '';

								if ( preg_match( '/^([a-z0-9_]+):\s+(.*)$/s', $message, $parts ) ) {
									$code    = $parts[1];
									$message = ucfirst( $parts[2] );
								}

								echo esc_html( $message );

								if ( '' !== $code ) {
									echo ' <code class="wpbp-log-code">' . esc_html( $code ) . '</code>';
								}
								?>
							</td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>

				<?php if ( $pages > 1 ) : ?>
					<div class="wpbp-pagination">
						<span class="wpbp-pagination-count">
							<?php
							/* translators: 1: first entry shown, 2: last entry shown, 3: total entries */
							echo esc_html( sprintf( __( '%1$s–%2$s of %3$s entries', 'wrensafe' ), number_format_i18n( ( $paged - 1 ) * $per_page + 1 ), number_format_i18n( min( $total, $paged * $per_page ) ), number_format_i18n( $total ) ) );
							?>
						</span>
						<?php
						echo wp_kses_post(
							paginate_links(
								array(
									'base'      => add_query_arg( 'paged', '%#%' ),
									'format'    => '',
									'current'   => $paged,
									'total'     => $pages,
									'prev_text' => __( '‹ Previous', 'wrensafe' ),
									'next_text' => __( 'Next ›', 'wrensafe' ),
									'mid_size'  => 1,
								)
							)
						);
						?>
					</div>
				<?php endif; ?>
			<?php endif; ?>
		</div>
		<?php
	}
}
