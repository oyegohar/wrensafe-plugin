<?php
/**
 * Safe Updates page: choose plugins (and the items of any other update type
 * an add-on registers, each in its own section) with updates, choose how to
 * update them (Quick or Safe Update, one by one or all at once), follow the
 * run, and roll back an update from its saved copy later.
 *
 * The run itself is driven by assets/admin/js/safe-updates.js through the
 * Ajax\SafeUpdateAjax endpoints, and continued by WP-Cron when the page is
 * closed.
 *
 * @package WPBP\Admin\Pages
 */

namespace WPBP\Admin\Pages;

use WPBP\Admin\Kses;
use WPBP\Core\Database\JobsRepository;
use WPBP\Core\Settings;
use WPBP\Updates\PluginUpdateType;
use WPBP\Updates\SafeUpdateJob;
use WPBP\Updates\SafeUpdateManager;
use WPBP\Updates\SafeUpdates;
use WPBP\Updates\UpdateType;
use WPBP\Updates\UpdateTypes;

defined( 'ABSPATH' ) || exit;

class SafeUpdatesPage extends AbstractPage {

	public function get_title(): string {
		return __( 'Safe Updates', 'wrensafe' );
	}

	protected function get_subtitle(): string {
		if ( count( UpdateTypes::all() ) > 1 ) {
			return __( 'Install updates with a safety net: Wrensafe saves a copy of each one, checks your site after the update and puts the previous version back if the site shows an error.', 'wrensafe' );
		}

		return __( 'Update plugins with a safety net: Wrensafe saves a copy of each plugin, checks your site after the update and puts the previous version back if the site shows an error.', 'wrensafe' );
	}

	protected function required_capability(): string {
		return 'update_plugins';
	}

	protected function get_header_actions(): array {
		return array(
			array(
				'label'   => __( 'Update settings', 'wrensafe' ),
				'url'     => self::page_url( 'wrensafe-settings', array( 'tab' => 'updates' ) ),
				'variant' => 'secondary',
				'icon'    => 'dashicons-admin-generic',
				'cap'     => \WPBP\Security\CapabilityManager::MANAGE_SETTINGS,
			),
		);
	}

	protected function render_content(): void {
		$blocked = SafeUpdates::blocked_reason();
		$types   = UpdateTypes::all();
		$lists   = SafeUpdateManager::available_by_type();
		$total   = array_sum( array_map( 'count', $lists ) );
		$several = count( $types ) > 1;
		$running = SafeUpdateManager::running_job();
		$modes   = SafeUpdates::modes();
		$mode    = SafeUpdates::default_mode();
		$order   = SafeUpdates::default_order();
		$checked = get_site_transient( 'update_plugins' );
		$last    = is_object( $checked ) && ! empty( $checked->last_checked ) ? (int) $checked->last_checked : 0;
		$plugin  = $types[ PluginUpdateType::KEY ]->strings();
		?>
		<?php if ( '' !== $blocked ) : ?>
			<div class="wpbp-callout is-warning">
				<span class="dashicons dashicons-warning" aria-hidden="true"></span>
				<div class="wpbp-callout-body">
					<p class="wpbp-callout-title"><?php esc_html_e( 'Safe Updates can’t run on this site', 'wrensafe' ); ?></p>
					<p><?php echo esc_html( $blocked ); ?></p>
				</div>
			</div>
		<?php endif; ?>

		<div class="wpbp-card wpbp-su-run-card" id="wpbp-su-run"<?php echo $running ? '' : ' hidden'; ?> data-job="<?php echo esc_attr( $running ? (string) $running->id() : '' ); ?>">
			<h2><?php esc_html_e( 'Updating now', 'wrensafe' ); ?></h2>
			<div class="wpbp-su-run" aria-live="polite"></div>
		</div>

		<form class="wpbp-card wpbp-su-form<?php echo $several ? ' has-sections' : ''; ?>" id="wpbp-su-form"<?php echo $running ? ' hidden' : ''; ?>>
			<div class="wpbp-su-card-head">
				<h2>
					<?php echo esc_html( $several ? __( 'Available updates', 'wrensafe' ) : (string) $plugin['section'] ); ?>
					<span class="wpbp-count-badge"><?php echo esc_html( number_format_i18n( $total ) ); ?></span>
				</h2>
				<div class="wpbp-su-card-tools">
					<?php if ( $last ) : ?>
						<span class="description">
							<?php
							/* translators: %s: time since, e.g. "5 minutes" */
							echo esc_html( sprintf( __( 'Checked %s ago', 'wrensafe' ), human_time_diff( $last ) ) );
							?>
						</span>
					<?php endif; ?>
					<button type="button" class="button" id="wpbp-su-refresh"><?php esc_html_e( 'Check again', 'wrensafe' ); ?></button>
				</div>
			</div>

			<?php if ( 0 === $total && ! $several ) : ?>
				<div class="wpbp-empty is-compact">
					<span class="dashicons dashicons-yes-alt" aria-hidden="true"></span>
					<p><?php echo esc_html( (string) $plugin['empty'] ); ?></p>
				</div>
			<?php else : ?>
				<?php foreach ( $types as $key => $type ) : ?>
					<?php $this->render_type_section( $type, $lists[ $key ] ?? array(), '' === $blocked, $several ); ?>
				<?php endforeach; ?>

				<?php if ( $total > 0 ) : ?>
				<div class="wpbp-su-options">
					<fieldset class="wpbp-su-modes">
						<legend><?php esc_html_e( 'How to update', 'wrensafe' ); ?></legend>
						<?php foreach ( $modes as $key => $item ) : ?>
							<label class="wpbp-su-mode is-<?php echo esc_attr( $key ); ?>">
								<input type="radio" name="wpbp_su_mode" value="<?php echo esc_attr( $key ); ?>" <?php checked( $mode, $key ); ?> />
								<span class="wpbp-su-mode-icon"><?php echo wp_kses( self::mode_icon( $key ), Kses::allowed() ); ?></span>
								<span class="wpbp-su-mode-body">
									<span class="wpbp-su-mode-title">
										<?php echo esc_html( $item['label'] ); ?>
										<?php if ( '' !== $item['badge'] ) : ?>
											<span class="wpbp-su-badge"><?php echo esc_html( $item['badge'] ); ?></span>
										<?php endif; ?>
									</span>
									<span class="wpbp-su-mode-text"><?php echo esc_html( $item['description'] ); ?></span>
								</span>
							</label>
						<?php endforeach; ?>
					</fieldset>

					<fieldset class="wpbp-su-order">
						<legend><?php echo esc_html( $several ? __( 'Several updates', 'wrensafe' ) : __( 'Several plugins', 'wrensafe' ) ); ?></legend>
						<label>
							<input type="radio" name="wpbp_su_order" value="one_by_one" <?php checked( $order, 'one_by_one' ); ?> />
							<span><strong><?php esc_html_e( 'One by one', 'wrensafe' ); ?></strong> &mdash; <span class="wpbp-su-order-help" data-text="orderOneHelp"><?php echo esc_html( (string) $plugin['orderOneHelp'] ); ?></span></span>
						</label>
						<label>
							<input type="radio" name="wpbp_su_order" value="all_at_once" <?php checked( $order, 'all_at_once' ); ?> />
							<span><strong><?php esc_html_e( 'All at once', 'wrensafe' ); ?></strong> &mdash; <span class="wpbp-su-order-help" data-text="orderAllHelp"><?php echo esc_html( (string) $plugin['orderAllHelp'] ); ?></span></span>
						</label>
					</fieldset>

					<label class="wpbp-su-cache">
						<input type="checkbox" id="wpbp-su-clear-cache" <?php checked( (bool) Settings::get( 'safe_updates_clear_cache', true ) ); ?> />
						<span><?php esc_html_e( 'Clear the cache after updating', 'wrensafe' ); ?></span>
					</label>

					<?php if ( ! empty( get_option( 'wpbp_backup_before_updates', array() )['manual_updates'] ) ) : ?>
						<p class="description">
							<?php
							$type = 'full' === ( get_option( 'wpbp_backup_before_updates', array() )['backup_type'] ?? '' ) ? __( 'a full backup', 'wrensafe' ) : __( 'a database backup', 'wrensafe' );
							/* translators: %s: "a full backup" or "a database backup" */
							echo esc_html( sprintf( __( 'Before the first update, Wrensafe makes %s (Settings → Updates → Back up before updates).', 'wrensafe' ), $type ) );
							?>
						</p>
					<?php endif; ?>

					<div class="wpbp-su-submit">
						<button type="submit" class="button button-primary" id="wpbp-su-start" disabled><?php echo esc_html( (string) $plugin['updateSelected'] ); ?></button>
						<span class="wpbp-su-form-error wpbp-inline-notice is-error" role="alert" hidden></span>
					</div>
				</div>
				<?php endif; ?>
			<?php endif; ?>
		</form>

		<?php $this->render_history(); ?>
		<?php
	}

	/**
	 * One update type's updates: the table (or "up to date"), under its own
	 * heading when several types are registered.
	 *
	 * @param array<string,array<string,mixed>> $available
	 */
	private function render_type_section( UpdateType $type, array $available, bool $can_run, bool $several ): void {
		$key       = $type->key();
		$strings   = array_merge( $this->type_strings_fallback(), $type->strings() );
		$updatable = array_filter(
			$available,
			static function ( array $item ): bool {
				return '' === $item['problem'];
			}
		);
		$select_id = PluginUpdateType::KEY === $key ? 'wpbp-su-select-all' : 'wpbp-su-select-all-' . $key;
		?>
		<?php if ( $several ) : ?>
		<section class="wpbp-su-section" data-type="<?php echo esc_attr( $key ); ?>">
			<h3 class="wpbp-su-section-title">
				<?php echo esc_html( $type->label() ); ?>
				<span class="wpbp-count-badge"><?php echo esc_html( number_format_i18n( count( $available ) ) ); ?></span>
			</h3>
		<?php endif; ?>

		<?php if ( empty( $available ) ) : ?>
			<?php if ( $several ) : ?>
				<p class="wpbp-su-section-empty"><span class="dashicons dashicons-yes-alt" aria-hidden="true"></span> <?php echo esc_html( (string) $strings['empty'] ); ?></p>
			<?php endif; ?>
		<?php else : ?>
			<table class="widefat striped wpbp-su-table wpbp-table-stack" data-type="<?php echo esc_attr( $key ); ?>">
				<thead>
				<tr>
					<th class="wpbp-col-select">
						<input type="checkbox" id="<?php echo esc_attr( $select_id ); ?>" class="wpbp-su-select-all" data-type="<?php echo esc_attr( $key ); ?>" aria-label="<?php echo esc_attr( (string) $strings['selectAll'] ); ?>"<?php disabled( empty( $updatable ) || ! $can_run ); ?> />
					</th>
					<th><?php echo esc_html( (string) $strings['column'] ); ?></th>
					<th><?php esc_html_e( 'Installed', 'wrensafe' ); ?></th>
					<th><?php esc_html_e( 'New version', 'wrensafe' ); ?></th>
					<th><?php esc_html_e( 'Notes', 'wrensafe' ); ?></th>
				</tr>
				</thead>
				<tbody>
				<?php foreach ( $available as $id => $item ) : ?>
					<?php $this->render_update_row( $key, (string) $id, $item, $can_run, (string) $strings['column'] ); ?>
				<?php endforeach; ?>
				</tbody>
			</table>
		<?php endif; ?>

		<?php if ( $several ) : ?>
		</section>
		<?php endif; ?>
		<?php
	}

	/** Texts a type doesn't give (see UpdateType::strings()). */
	private function type_strings_fallback(): array {
		return array(
			'column'    => __( 'Name', 'wrensafe' ),
			'empty'     => __( 'Everything is up to date.', 'wrensafe' ),
			'selectAll' => __( 'Select everything that can be updated', 'wrensafe' ),
		);
	}

	/** The icon of a mode card (the same as the update window's). */
	private static function mode_icon( string $key ): string {
		$paths = array(
			'quick' => '<path d="M13 2 4.5 13.5H11L10 22l8.5-11.5H12L13 2Z"/>',
			'safe'  => '<path d="M12 2.5 4.5 5.4v5.9c0 4.6 3.1 8.8 7.5 10.2 4.4-1.4 7.5-5.6 7.5-10.2V5.4L12 2.5Z"/><path d="m8.6 12.2 2.4 2.4 4.6-4.8"/>',
			'mode'  => '<circle cx="12" cy="12" r="8.5"/><path d="m8.6 12.2 2.4 2.4 4.6-4.8"/>',
		);

		return '<svg viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">' . ( $paths[ $key ] ?? $paths['mode'] ) . '</svg>';
	}

	private function render_update_row( string $type, string $id, array $item, bool $can_run, string $column ): void {
		$usable = $can_run && '' === $item['problem'];
		$notes  = array();

		if ( '' !== $item['problem'] ) {
			$notes[] = $item['problem'];
		}

		if ( '' !== (string) $item['tested'] ) {
			global $wp_version;

			$tested = (string) $item['tested'];

			if ( version_compare( implode( '.', array_slice( explode( '.', $tested ), 0, 2 ) ), implode( '.', array_slice( explode( '.', (string) $wp_version ), 0, 2 ) ), '<' ) ) {
				/* translators: %s: WordPress version */
				$notes[] = sprintf( __( 'Tested up to WordPress %s by its author.', 'wrensafe' ), $tested );
			}
		}

		if ( empty( $item['active'] ) ) {
			$notes[] = __( 'Inactive', 'wrensafe' );
		}
		?>
		<tr data-type="<?php echo esc_attr( $type ); ?>" data-id="<?php echo esc_attr( $id ); ?>"<?php echo PluginUpdateType::KEY === $type ? ' data-plugin="' . esc_attr( $id ) . '"' : ''; ?><?php echo $usable ? '' : ' class="is-disabled"'; ?>>
			<td class="wpbp-col-select">
				<input type="checkbox" class="wpbp-su-select" value="<?php echo esc_attr( $id ); ?>" data-type="<?php echo esc_attr( $type ); ?>"
					aria-label="<?php echo esc_attr( sprintf( /* translators: %s: name of the plugin (or theme) */ __( 'Select %s', 'wrensafe' ), $item['name'] ) ); ?>"<?php disabled( ! $usable ); ?> />
			</td>
			<td data-colname="<?php echo esc_attr( $column ); ?>"><strong><?php echo esc_html( $item['name'] ); ?></strong></td>
			<td data-colname="<?php esc_attr_e( 'Installed', 'wrensafe' ); ?>"><?php echo esc_html( $item['from'] ); ?></td>
			<td data-colname="<?php esc_attr_e( 'New version', 'wrensafe' ); ?>"><?php echo esc_html( $item['to'] ); ?></td>
			<td data-colname="<?php esc_attr_e( 'Notes', 'wrensafe' ); ?>" class="wpbp-su-notes"><?php echo esc_html( implode( ' ', $notes ) ); ?></td>
		</tr>
		<?php
	}

	private function render_history(): void {
		$rows = JobsRepository::list_by_type( SafeUpdateJob::TYPE, 10 );
		?>
		<div class="wpbp-card wpbp-su-history" id="wpbp-su-history">
			<h2><?php esc_html_e( 'Recent updates', 'wrensafe' ); ?></h2>
			<p class="description">
				<?php
				/* translators: %d: number of days */
				echo esc_html( sprintf( _n( 'An update can be rolled back for %d day, while its saved copy is kept.', 'An update can be rolled back for %d days, while its saved copy is kept.', (int) Settings::get( 'safe_updates_keep_days', 14 ), 'wrensafe' ), (int) Settings::get( 'safe_updates_keep_days', 14 ) ) );
				?>
			</p>
			<?php if ( empty( $rows ) ) : ?>
				<p class="wpbp-su-history-empty"><?php esc_html_e( 'No updates through Wrensafe yet.', 'wrensafe' ); ?></p>
			<?php else : ?>
				<div class="wpbp-su-history-list">
					<?php
					foreach ( $rows as $row ) {
						$job = SafeUpdateJob::get( (int) $row['id'] );

						if ( null !== $job && $job->is_finished() ) {
							$this->render_run( SafeUpdateManager::report( $job ) );
						}
					}
					?>
				</div>
			<?php endif; ?>
		</div>
		<?php
	}

	/** One finished run (the script renders the same markup for a run that just ended). */
	private function render_run( array $run ): void {
		$labels = array(
			'updated'     => __( 'Updated', 'wrensafe' ),
			'rolled_back' => __( 'Rolled back', 'wrensafe' ),
			'failed'      => __( 'Failed', 'wrensafe' ),
			'skipped'     => __( 'Skipped', 'wrensafe' ),
			'cancelled'   => __( 'Not updated (stopped)', 'wrensafe' ),
		);
		?>
		<details class="wpbp-su-history-run" data-job="<?php echo esc_attr( (string) $run['id'] ); ?>">
			<summary>
				<span class="wpbp-su-history-date"><?php echo esc_html( $run['date'] ); ?></span>
				<span class="wpbp-su-history-mode"><?php echo esc_html( $run['modeName'] ); ?></span>
				<span class="wpbp-su-pills">
					<?php foreach ( $run['pills'] as $pill ) : ?>
						<span class="wpbp-su-pill is-<?php echo esc_attr( $pill['tone'] ); ?>"><?php echo esc_html( $pill['text'] ); ?></span>
					<?php endforeach; ?>
				</span>
			</summary>
			<?php if ( '' !== $run['error'] ) : ?>
				<p class="wpbp-inline-notice is-error"><?php echo esc_html( $run['error'] ); ?></p>
			<?php endif; ?>
			<ul class="wpbp-su-items">
				<?php foreach ( $run['items'] as $item ) : ?>
					<li class="wpbp-su-item is-<?php echo esc_attr( $item['state'] ); ?>" data-type="<?php echo esc_attr( $item['type'] ); ?>" data-id="<?php echo esc_attr( $item['id'] ); ?>"<?php echo '' !== $item['plugin'] ? ' data-plugin="' . esc_attr( $item['plugin'] ) . '"' : ''; ?>>
						<span class="wpbp-su-item-icon" aria-hidden="true"></span>
						<span class="wpbp-su-item-main">
							<span class="wpbp-su-item-name"><?php echo esc_html( $item['name'] ); ?></span>
							<span class="wpbp-su-item-versions"><?php echo esc_html( $item['from'] . ' → ' . $item['to'] ); ?></span>
							<span class="wpbp-su-item-state"><?php echo esc_html( $labels[ $item['state'] ] ?? $item['state'] ); ?></span>
							<?php if ( ! empty( $item['cause'] ) ) : ?>
								<?php $this->render_cause( $item['cause'] ); ?>
							<?php elseif ( '' !== $item['message'] ) : ?>
								<span class="wpbp-su-item-message"><?php echo esc_html( $item['message'] ); ?></span>
							<?php endif; ?>
							<?php $this->render_details( $item['details'] ); ?>
							<?php if ( $item['canRollBack'] ) : ?>
								<button type="button" class="button button-small wpbp-su-rollback" data-job="<?php echo esc_attr( (string) $run['id'] ); ?>" data-type="<?php echo esc_attr( $item['type'] ); ?>" data-id="<?php echo esc_attr( $item['id'] ); ?>"<?php echo '' !== $item['plugin'] ? ' data-plugin="' . esc_attr( $item['plugin'] ) . '"' : ''; ?> data-name="<?php echo esc_attr( $item['name'] ); ?>" data-version="<?php echo esc_attr( $item['from'] ); ?>">
									<?php
									/* translators: %s: version number */
									echo esc_html( sprintf( __( 'Roll back to %s', 'wrensafe' ), $item['from'] ) );
									?>
								</button>
							<?php endif; ?>
						</span>
					</li>
				<?php endforeach; ?>
			</ul>
			<?php if ( ! empty( $run['cleared'] ) ) : ?>
				<p class="description">
					<?php
					/* translators: %s: list of caches */
					echo esc_html( sprintf( __( 'Caches cleared: %s.', 'wrensafe' ), implode( ', ', $run['cleared'] ) ) );
					?>
				</p>
			<?php endif; ?>
		</details>
		<?php
	}

	/**
	 * Why an item wasn't updated or was rolled back, and what to do. The script renders the same markup.
	 *
	 * @param array<string,string> $cause
	 */
	private function render_cause( array $cause ): void {
		?>
		<span class="wpbp-su-cause">
			<span class="wpbp-su-cause-label"><?php echo esc_html( $cause['label'] ); ?></span>
			<span class="wpbp-su-cause-text"><?php echo esc_html( $cause['text'] ); ?></span>
			<?php if ( '' !== ( $cause['fix'] ?? '' ) ) : ?>
				<span class="wpbp-su-cause-fix"><strong><?php esc_html_e( 'What to do:', 'wrensafe' ); ?></strong> <?php echo esc_html( $cause['fix'] ); ?></span>
			<?php endif; ?>
			<?php if ( '' !== ( $cause['detail'] ?? '' ) ) : ?>
				<span class="wpbp-su-cause-detail"><?php esc_html_e( 'Technical detail:', 'wrensafe' ); ?> <?php echo esc_html( $cause['detail'] ); ?></span>
			<?php endif; ?>
		</span>
		<?php
	}

	/**
	 * An item's details (see the wpbp_safe_update_item_report filter): a
	 * note, linked pictures and links. The script renders the same markup.
	 *
	 * @param array{note?:string,images?:array,links?:array} $details
	 */
	private function render_details( array $details ): void {
		if ( empty( $details ) ) {
			return;
		}
		?>
		<span class="wpbp-su-item-details">
			<?php if ( ! empty( $details['note'] ) ) : ?>
				<span class="wpbp-su-item-note"><?php echo esc_html( $details['note'] ); ?></span>
			<?php endif; ?>
			<?php if ( ! empty( $details['images'] ) ) : ?>
				<span class="wpbp-su-item-images">
					<?php foreach ( $details['images'] as $image ) : ?>
						<a class="wpbp-su-item-image" href="<?php echo esc_url( $image['url'] ); ?>" target="_blank" rel="noopener noreferrer">
							<img src="<?php echo esc_url( $image['url'] ); ?>" alt="<?php echo esc_attr( $image['label'] ); ?>" loading="lazy" />
							<span><?php echo esc_html( $image['label'] ); ?></span>
						</a>
					<?php endforeach; ?>
				</span>
			<?php endif; ?>
			<?php if ( ! empty( $details['links'] ) ) : ?>
				<span class="wpbp-su-item-links">
					<?php foreach ( $details['links'] as $link ) : ?>
						<a href="<?php echo esc_url( $link['url'] ); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html( '' !== $link['label'] ? $link['label'] : $link['url'] ); ?></a>
					<?php endforeach; ?>
				</span>
			<?php endif; ?>
		</span>
		<?php
	}
}
