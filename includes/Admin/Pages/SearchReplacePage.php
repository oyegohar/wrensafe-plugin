<?php
/**
 * Search & Replace page: find text anywhere in the database and replace it,
 * safely inside serialized and JSON data. A dry run (the default) only
 * counts the rows that would change.
 *
 * The work runs in short AJAX steps (see Ajax\SearchReplaceAjax), so a large
 * database never hits a timeout.
 *
 * @package WPBP\Admin\Pages
 */

namespace WPBP\Admin\Pages;

use WPBP\Ajax\SearchReplaceAjax;
use WPBP\Security\CapabilityManager;

defined( 'ABSPATH' ) || exit;

class SearchReplacePage extends AbstractPage {

	public function get_title(): string {
		return __( 'Search & Replace', 'wrensafe' );
	}

	protected function get_subtitle(): string {
		return __( 'Replace text anywhere in the database, for example an old address after moving the site. Serialized and JSON data stay valid.', 'wrensafe' );
	}

	protected function required_capability(): string {
		return CapabilityManager::RESTORE_BACKUPS;
	}

	protected function get_header_actions(): array {
		return array(
			array(
				'label'   => __( 'Back up the database', 'wrensafe' ),
				'url'     => self::page_url( 'wrensafe-backup' ),
				'variant' => 'secondary',
				'icon'    => 'dashicons-archive',
				'cap'     => CapabilityManager::CREATE_BACKUPS,
			),
		);
	}

	protected function render_content(): void {
		global $wpdb;

		$tables = SearchReplaceAjax::tables();
		$sizes  = array();
		$rows   = $wpdb->get_results( $wpdb->prepare( 'SHOW TABLE STATUS LIKE %s', $wpdb->esc_like( $wpdb->prefix ) . '%' ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- table sizes for this screen; no WordPress API.

		foreach ( (array) $rows as $row ) {
			$sizes[ (string) $row['Name'] ] = (int) $row['Data_length'] + (int) $row['Index_length'];
		}
		?>
		<div class="wpbp-callout is-warning">
			<span class="dashicons dashicons-warning" aria-hidden="true"></span>
			<div class="wpbp-callout-body">
				<p class="wpbp-callout-title"><?php esc_html_e( 'Make a database backup first', 'wrensafe' ); ?></p>
				<p><?php esc_html_e( 'A replacement changes the database directly and can only be undone by restoring a backup. Start with a dry run to see what would change.', 'wrensafe' ); ?></p>
			</div>
		</div>

		<form class="wpbp-card wpbp-sr-form" id="wpbp-sr-form" novalidate>
			<h2><?php esc_html_e( 'Find and replace', 'wrensafe' ); ?></h2>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><label for="wpbp-sr-search"><?php esc_html_e( 'Search for', 'wrensafe' ); ?></label></th>
					<td>
						<input type="text" id="wpbp-sr-search" class="large-text code" autocomplete="off" spellcheck="false" placeholder="<?php echo esc_attr( 'http://old-site.example' ); ?>" required />
						<p class="description"><?php esc_html_e( 'Exact text, case-sensitive.', 'wrensafe' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="wpbp-sr-replace"><?php esc_html_e( 'Replace with', 'wrensafe' ); ?></label></th>
					<td>
						<input type="text" id="wpbp-sr-replace" class="large-text code" autocomplete="off" spellcheck="false" placeholder="<?php echo esc_attr( home_url() ); ?>" />
						<p class="description"><?php esc_html_e( 'Leave empty to remove the text.', 'wrensafe' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Tables', 'wrensafe' ); ?></th>
					<td>
						<p class="wpbp-sr-table-tools">
							<button type="button" class="button-link" id="wpbp-sr-all"><?php esc_html_e( 'Select all', 'wrensafe' ); ?></button>
							<span aria-hidden="true">·</span>
							<button type="button" class="button-link" id="wpbp-sr-none"><?php esc_html_e( 'Select none', 'wrensafe' ); ?></button>
						</p>
						<fieldset class="wpbp-checkbox-list wpbp-table-checklist" id="wpbp-sr-tables">
							<legend class="screen-reader-text"><?php esc_html_e( 'Tables to search', 'wrensafe' ); ?></legend>
							<?php foreach ( $tables as $table ) : ?>
								<label>
									<input type="checkbox" value="<?php echo esc_attr( $table ); ?>" checked />
									<code><?php echo esc_html( $table ); ?></code>
									<span class="wpbp-muted"><?php echo esc_html( size_format( $sizes[ $table ] ?? 0 ) ); ?></span>
								</label>
							<?php endforeach; ?>
						</fieldset>
						<p class="description"><?php esc_html_e( 'Wrensafe’s own tables (backup records and logs) are never changed.', 'wrensafe' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Options', 'wrensafe' ); ?></th>
					<td>
						<fieldset class="wpbp-checkbox-list">
							<legend class="screen-reader-text"><?php esc_html_e( 'Options', 'wrensafe' ); ?></legend>
							<label>
								<input type="checkbox" id="wpbp-sr-dry-run" checked />
								<?php esc_html_e( 'Dry run: only count the rows that would change. Nothing is saved.', 'wrensafe' ); ?>
							</label>
							<label>
								<input type="checkbox" id="wpbp-sr-skip-guid" checked />
								<?php esc_html_e( 'Leave post GUIDs as they are (recommended)', 'wrensafe' ); ?>
							</label>
						</fieldset>
						<p class="description"><?php esc_html_e( 'A GUID is a post’s permanent ID that feed readers use. WordPress recommends never changing it, even when the site moves.', 'wrensafe' ); ?></p>
					</td>
				</tr>
			</table>
			<p class="submit">
				<button type="submit" class="button button-primary" id="wpbp-sr-run"><?php esc_html_e( 'Start dry run', 'wrensafe' ); ?></button>
			</p>
		</form>

		<div class="wpbp-card wpbp-sr-progress wpbp-is-hidden" id="wpbp-sr-progress" aria-live="polite">
			<h2 id="wpbp-sr-progress-title"></h2>
			<div class="wpbp-progress">
				<div class="wpbp-progress-bar"><div class="wpbp-progress-bar-fill" style="width:0%"></div></div>
				<span class="wpbp-progress-percent">0%</span>
			</div>
			<p class="wpbp-progress-status" id="wpbp-sr-status"></p>
			<div id="wpbp-sr-summary"></div>
			<table class="widefat striped wpbp-sr-results wpbp-is-hidden" id="wpbp-sr-results">
				<thead>
					<tr>
						<th scope="col"><?php esc_html_e( 'Table', 'wrensafe' ); ?></th>
						<th scope="col" id="wpbp-sr-rows-heading"><?php esc_html_e( 'Rows', 'wrensafe' ); ?></th>
					</tr>
				</thead>
				<tbody></tbody>
			</table>
			<p class="wpbp-sr-actions wpbp-is-hidden" id="wpbp-sr-actions">
				<button type="button" class="button button-primary" id="wpbp-sr-apply"><?php esc_html_e( 'Replace now', 'wrensafe' ); ?></button>
				<button type="button" class="button" id="wpbp-sr-again"><?php esc_html_e( 'New search', 'wrensafe' ); ?></button>
			</p>
		</div>
		<?php
	}
}
