<?php
/**
 * Activity Logs page: a timeline of Wrensafe's own work (Admin\ActivityTimeline), and every entry as a table
 * ("All entries", for support). Read from the wpbp_logs table.
 *
 * @package WPBP\Admin\Pages
 */

namespace WPBP\Admin\Pages;

use WPBP\Admin\ActivityTimeline;
use WPBP\Admin\Icons;
use WPBP\Security\CapabilityManager;

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
		// The view, filters and page are read-only view state, so a nonce isn't required here.
		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		$view   = isset( $_GET['view'] ) && 'entries' === sanitize_key( wp_unslash( $_GET['view'] ) ) ? 'entries' : 'timeline';
		$kind   = isset( $_GET['kind'] ) ? sanitize_key( wp_unslash( $_GET['kind'] ) ) : '';
		$search = isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : '';
		$before = isset( $_GET['before'] ) ? absint( $_GET['before'] ) : 0;
		// phpcs:enable

		$this->render_actions( $view );

		if ( 'entries' === $view ) {
			$this->render_entries();
			return;
		}

		$this->render_timeline( in_array( $kind, ActivityTimeline::KINDS, true ) ? $kind : '', $search, $before );
	}

	/** Export, debug bundle, clear, and the switch between the timeline and every entry. */
	private function render_actions( string $view ): void {
		// Root-relative so the browser resolves each link against the current
		// page's scheme+host — an https admin never serves an http download
		// link (Chrome blocks that as an insecure mixed-content download). Same
		// fix as the Backup Browser download link.
		$logs_url   = set_url_scheme( wp_nonce_url( admin_url( 'admin-post.php?action=wpbp_export_logs' ), 'wpbp_logs_export', '_wpbp_nonce' ), 'relative' );
		$bundle_url = set_url_scheme( wp_nonce_url( admin_url( 'admin-post.php?action=wpbp_export_debug_bundle' ), 'wpbp_logs_export', '_wpbp_nonce' ), 'relative' );
		$clear_url  = set_url_scheme( wp_nonce_url( admin_url( 'admin-post.php?action=wpbp_clear_logs' ), 'wpbp_logs_export', '_wpbp_nonce' ), 'relative' );
		$views      = array(
			'timeline' => __( 'Timeline', 'wrensafe' ),
			'entries'  => __( 'All entries', 'wrensafe' ),
		);

		if ( isset( $_GET['cleared'] ) ) : // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			?>
			<div class="notice notice-success wpbp-notice"><p><?php esc_html_e( 'Activity logs cleared.', 'wrensafe' ); ?></p></div>
		<?php endif; ?>
		<div class="wpbp-tl-bar">
			<nav class="wpbp-tl-views" aria-label="<?php esc_attr_e( 'Activity Logs view', 'wrensafe' ); ?>">
				<?php foreach ( $views as $key => $label ) : ?>
					<a href="<?php echo esc_url( 'timeline' === $key ? admin_url( 'admin.php?page=wrensafe-logs' ) : admin_url( 'admin.php?page=wrensafe-logs&view=entries' ) ); ?>"<?php echo $key === $view ? ' aria-current="page"' : ''; ?>><?php echo esc_html( $label ); ?></a>
				<?php endforeach; ?>
			</nav>
			<div class="wpbp-tl-tools">
				<a class="button" href="<?php echo esc_url( $logs_url ); ?>"><span class="dashicons dashicons-download" aria-hidden="true"></span><?php esc_html_e( 'Export logs', 'wrensafe' ); ?></a>
				<a class="button" href="<?php echo esc_url( $bundle_url ); ?>"><span class="dashicons dashicons-sos" aria-hidden="true"></span><?php esc_html_e( 'Export debug bundle', 'wrensafe' ); ?></a>
				<a class="button button-link-delete wpbp-clear-logs" href="<?php echo esc_url( $clear_url ); ?>" data-confirm="<?php esc_attr_e( 'Delete all activity log entries? This cannot be undone.', 'wrensafe' ); ?>"><?php esc_html_e( 'Clear logs', 'wrensafe' ); ?></a>
			</div>
		</div>
		<p class="description wpbp-tl-note"><?php esc_html_e( 'Secrets (passwords, tokens, keys) are masked or omitted.', 'wrensafe' ); ?></p>
		<?php
	}

	/** The timeline: totals for the last 30 days, filters, then each day's work. */
	private function render_timeline( string $kind, string $search, int $before ): void {
		$page   = ActivityTimeline::page( $kind, $search, $before );
		$totals = ActivityTimeline::totals();
		$base   = admin_url( 'admin.php?page=wrensafe-logs' );
		$kinds  = array(
			''         => __( 'All', 'wrensafe' ),
			'backup'   => __( 'Backups', 'wrensafe' ),
			'restore'  => __( 'Restores', 'wrensafe' ),
			'update'   => __( 'Safe Updates', 'wrensafe' ),
			'move'     => __( 'Moves & staging', 'wrensafe' ),
			'tools'    => __( 'Fix Links & tools', 'wrensafe' ),
			'problems' => __( 'Problems', 'wrensafe' ),
		);
		$tiles  = array(
			array( 'backup', 'archive', $totals['backups'], __( 'Backups made', 'wrensafe' ) ),
			array( 'restore', 'restore', $totals['restores'], __( 'Restores', 'wrensafe' ) ),
			array( 'update', 'refresh', $totals['updates'], __( 'Safe Updates runs', 'wrensafe' ) ),
			array( 'problems', 'alert', $totals['problems'], __( 'Problems', 'wrensafe' ) ),
		);
		?>
		<div class="wpbp-tl" id="wpbp-timeline">
			<section class="wpbp-tl-totals" aria-label="<?php esc_attr_e( 'The last 30 days', 'wrensafe' ); ?>">
				<p class="wpbp-tl-totals-title"><?php esc_html_e( 'Last 30 days', 'wrensafe' ); ?></p>
				<ul>
					<?php foreach ( $tiles as list( $key, $icon, $count, $label ) ) : ?>
						<li class="wpbp-tl-total is-<?php echo esc_attr( $key ); ?><?php echo 'problems' === $key && $count > 0 ? ' has-problems' : ''; ?>">
							<a href="<?php echo esc_url( add_query_arg( 'kind', $key, $base ) ); ?>">
								<span class="wpbp-tl-total-icon" aria-hidden="true"><?php Icons::render( $icon, 18 ); ?></span>
								<span class="wpbp-tl-total-count"><?php echo esc_html( number_format_i18n( $count ) ); ?></span>
								<span class="wpbp-tl-total-label"><?php echo esc_html( $label ); ?></span>
							</a>
						</li>
					<?php endforeach; ?>
				</ul>
			</section>

			<div class="wpbp-tl-filters">
				<nav class="wpbp-tl-kinds" aria-label="<?php esc_attr_e( 'Show one kind of activity', 'wrensafe' ); ?>">
					<?php foreach ( $kinds as $key => $label ) : ?>
						<a class="wpbp-tl-kind<?php echo 'problems' === $key ? ' is-problems' : ''; ?>" href="
						<?php
						echo esc_url(
							'' === $key ? remove_query_arg( 'kind', add_query_arg( 's', $search, $base ) ) : add_query_arg(
								array(
									'kind' => $key,
									's'    => $search,
								),
								$base
							)
						);
						?>
												"<?php echo $key === $kind ? ' aria-current="true"' : ''; ?>><?php echo esc_html( $label ); ?></a>
					<?php endforeach; ?>
				</nav>
				<form method="get" class="wpbp-tl-search" role="search">
					<input type="hidden" name="page" value="wrensafe-logs" />
					<?php if ( '' !== $kind ) : ?>
						<input type="hidden" name="kind" value="<?php echo esc_attr( $kind ); ?>" />
					<?php endif; ?>
					<label class="screen-reader-text" for="wpbp-tl-search-input"><?php esc_html_e( 'Search the activity', 'wrensafe' ); ?></label>
					<span class="dashicons dashicons-search" aria-hidden="true"></span>
					<input type="search" id="wpbp-tl-search-input" name="s" value="<?php echo esc_attr( $search ); ?>" placeholder="<?php esc_attr_e( 'Search the activity', 'wrensafe' ); ?>" />
				</form>
			</div>

			<?php if ( ! $page['items'] ) : ?>
				<div class="wpbp-tl-empty">
					<span class="wpbp-tl-empty-icon" aria-hidden="true"><?php Icons::render( 'clock', 22 ); ?></span>
					<p><strong><?php echo esc_html( '' === $kind && '' === $search && ! $before ? __( 'No activity yet', 'wrensafe' ) : __( 'Nothing matches', 'wrensafe' ) ); ?></strong></p>
					<p><?php echo esc_html( '' === $kind && '' === $search && ! $before ? __( 'Backups, restores, updates and moves show up here as they happen.', 'wrensafe' ) : __( 'Try another word, or show all kinds of activity.', 'wrensafe' ) ); ?></p>
					<?php if ( $page['older'] ) : ?>
						<p><a class="button" href="
						<?php
						echo esc_url(
							add_query_arg(
								array(
									'kind'   => $kind,
									's'      => $search,
									'before' => $page['older'],
								),
								$base
							)
						);
						?>
													"><?php esc_html_e( 'Look further back', 'wrensafe' ); ?></a></p>
					<?php endif; ?>
				</div>
			<?php else : ?>
				<?php $this->render_days( $page['items'] ); ?>
				<?php if ( $page['older'] ) : ?>
					<p class="wpbp-tl-more"><a class="button" href="
					<?php
					echo esc_url(
						add_query_arg(
							array(
								'kind'   => $kind,
								's'      => $search,
								'before' => $page['older'],
							),
							$base
						)
					);
					?>
																	"><?php esc_html_e( 'Show older activity', 'wrensafe' ); ?></a></p>
				<?php endif; ?>
			<?php endif; ?>
		</div>
		<?php
	}

	/** @param array<int,array<string,mixed>> $items Newest first. */
	private function render_days( array $items ): void {
		$today     = current_time( 'Y-m-d' );
		$yesterday = gmdate( 'Y-m-d', strtotime( $today . ' 12:00:00' ) - DAY_IN_SECONDS );
		$days      = array();

		foreach ( $items as $item ) {
			$days[ substr( (string) $item['time'], 0, 10 ) ][] = $item;
		}

		foreach ( $days as $day => $day_items ) :
			if ( $today === $day ) {
				$heading = __( 'Today', 'wrensafe' );
			} elseif ( $yesterday === $day ) {
				$heading = __( 'Yesterday', 'wrensafe' );
			} else {
				$heading = mysql2date( __( 'l, F j, Y', 'wrensafe' ), $day . ' 12:00:00' );
			}
			?>
			<section class="wpbp-tl-day">
				<h2 class="wpbp-tl-day-title"><?php echo esc_html( $heading ); ?></h2>
				<ol class="wpbp-tl-list">
					<?php foreach ( $day_items as $item ) : ?>
						<?php $this->render_item( $item ); ?>
					<?php endforeach; ?>
				</ol>
			</section>
			<?php
		endforeach;
	}

	/** @param array<string,mixed> $item */
	private function render_item( array $item ): void {
		$icons    = array(
			'backup'  => 'archive',
			'restore' => 'restore',
			'update'  => 'refresh',
			'move'    => 'globe',
			'tools'   => 'list-checks',
		);
		$statuses = array(
			'completed' => __( 'Done', 'wrensafe' ),
			'failed'    => __( 'Failed', 'wrensafe' ),
			'warning'   => __( 'Needs a look', 'wrensafe' ),
			'cancelled' => __( 'Cancelled', 'wrensafe' ),
			'running'   => __( 'Running', 'wrensafe' ),
		);
		$status   = (string) $item['status'];
		$steps    = (array) $item['steps'];
		?>
		<li class="wpbp-tl-item is-<?php echo esc_attr( $status ); ?>" data-kind="<?php echo esc_attr( (string) $item['kind'] ); ?>" data-last="<?php echo esc_attr( (string) $item['last_id'] ); ?>">
			<span class="wpbp-tl-node" aria-hidden="true"><?php Icons::render( $icons[ $item['kind'] ] ?? 'info', 16 ); ?></span>
			<article class="wpbp-tl-card">
				<header class="wpbp-tl-head">
					<h3 class="wpbp-tl-title"><?php echo esc_html( (string) $item['title'] ); ?></h3>
					<?php if ( isset( $statuses[ $status ] ) ) : ?>
						<span class="wpbp-tl-status"><?php echo esc_html( $statuses[ $status ] ); ?></span>
					<?php endif; ?>
					<time class="wpbp-tl-time" datetime="<?php echo esc_attr( mysql2date( 'c', (string) $item['time'] ) ); ?>"><?php echo esc_html( mysql2date( (string) get_option( 'time_format' ), (string) $item['time'] ) ); ?></time>
				</header>
				<p class="wpbp-tl-summary"><?php echo esc_html( (string) $item['summary'] ); ?></p>
				<?php if ( $item['facts'] ) : ?>
					<p class="wpbp-tl-facts">
						<?php foreach ( (array) $item['facts'] as $fact ) : ?>
							<span><?php echo esc_html( (string) $fact ); ?></span>
						<?php endforeach; ?>
					</p>
				<?php endif; ?>
				<?php if ( $steps ) : ?>
					<details class="wpbp-tl-steps">
						<summary>
							<?php
							/* translators: %s: number of steps */
							echo esc_html( sprintf( _n( 'Show the %s step', 'Show all %s steps', count( $steps ), 'wrensafe' ), number_format_i18n( count( $steps ) ) ) );
							?>
						</summary>
						<ol>
							<?php foreach ( $steps as $step ) : ?>
								<?php $code = ActivityTimeline::code( (string) $step['message'] ); ?>
								<li class="is-<?php echo esc_attr( (string) $step['level'] ); ?>">
									<time datetime="<?php echo esc_attr( mysql2date( 'c', (string) $step['created_at'] ) ); ?>"><?php echo esc_html( mysql2date( 'H:i:s', (string) $step['created_at'] ) ); ?></time>
									<span class="wpbp-tl-step-level"><?php echo esc_html( \WPBP\Admin\Labels::log_level( (string) $step['level'] ) ); ?></span>
									<span class="wpbp-tl-step-text">
										<?php echo esc_html( ActivityTimeline::sentence( (string) $step['message'] ) ); ?>
										<?php if ( '' !== $code ) : ?>
											<code><?php echo esc_html( $code ); ?></code>
										<?php endif; ?>
									</span>
								</li>
							<?php endforeach; ?>
						</ol>
					</details>
				<?php endif; ?>
			</article>
		</li>
		<?php
	}

	/** Every entry as it was written, with the level filter (for support and debugging). */
	private function render_entries(): void {
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
		?>
		<form method="get" class="wpbp-logs-filter">
			<input type="hidden" name="page" value="wrensafe-logs" />
			<input type="hidden" name="view" value="entries" />
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
				<a class="button" href="<?php echo esc_url( admin_url( 'admin.php?page=wrensafe-logs&view=entries' ) ); ?>"><?php esc_html_e( 'Reset', 'wrensafe' ); ?></a>
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
