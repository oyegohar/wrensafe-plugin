<?php
/**
 * Changelog: every release of the plugin as a timeline, opened from the link in the header.
 *
 * @package WPBP\Admin\Pages
 */

namespace WPBP\Admin\Pages;

use WPBP\Admin\Changelog;
use WPBP\Security\CapabilityManager;

defined( 'ABSPATH' ) || exit;

class ChangelogPage extends AbstractPage {

	const SLUG = 'wrensafe-changelog';

	public function get_title(): string {
		return __( 'Changelog', 'wrensafe' );
	}

	protected function get_subtitle(): string {
		return __( 'What changed in every version of Wrensafe, newest first.', 'wrensafe' );
	}

	protected function required_capability(): string {
		return CapabilityManager::VIEW_DASHBOARD;
	}

	public function render(): void {
		// Before the header is drawn, so its "new" marker is gone on this page.
		if ( CapabilityManager::current_user_can( $this->required_capability() ) ) {
			Changelog::mark_seen();
		}

		parent::render();
	}

	protected function render_content(): void {
		$releases = Changelog::releases();
		$labels   = Changelog::labels();

		if ( ! $releases ) {
			echo '<div class="wpbp-card"><p>' . esc_html__( 'The changelog file is missing from this copy of the plugin.', 'wrensafe' ) . '</p></div>';
			return;
		}

		$filters = array(
			''         => __( 'All', 'wrensafe' ),
			'new'      => $labels['new'],
			'improved' => $labels['improved'],
			'fixed'    => $labels['fixed'],
			'security' => $labels['security'],
		);
		?>
		<div class="wpbp-cl" id="wpbp-changelog">
			<div class="wpbp-cl-tools" hidden>
				<div class="wpbp-cl-filters" role="group" aria-label="<?php esc_attr_e( 'Show changes of one kind', 'wrensafe' ); ?>">
					<?php foreach ( $filters as $key => $label ) : ?>
						<button type="button" class="wpbp-cl-filter" data-filter="<?php echo esc_attr( $key ); ?>" aria-pressed="<?php echo '' === $key ? 'true' : 'false'; ?>"><?php echo esc_html( $label ); ?></button>
					<?php endforeach; ?>
				</div>
				<label class="wpbp-cl-search">
					<span class="screen-reader-text"><?php esc_html_e( 'Search the changelog', 'wrensafe' ); ?></span>
					<span class="dashicons dashicons-search" aria-hidden="true"></span>
					<input type="search" id="wpbp-cl-search" placeholder="<?php esc_attr_e( 'Search changes', 'wrensafe' ); ?>" autocomplete="off" />
				</label>
			</div>
			<p class="wpbp-cl-result" id="wpbp-cl-result" role="status"></p>

			<ol class="wpbp-cl-timeline">
				<?php foreach ( $releases as $release ) : ?>
					<?php $this->render_release( $release, $labels ); ?>
				<?php endforeach; ?>
			</ol>
			<p class="wpbp-cl-empty" id="wpbp-cl-empty" hidden><?php esc_html_e( 'No changes match. Try another word or show all kinds.', 'wrensafe' ); ?></p>
		</div>
		<?php
	}

	/**
	 * @param array{version:string,date:string,intro:string,entries:array<int,array{type:string,text:string}>} $release
	 * @param array<string,string>                                                                             $labels
	 */
	private function render_release( array $release, array $labels ): void {
		$mine   = WPBP_VERSION === $release['version'];
		$counts = array_count_values( array_column( $release['entries'], 'type' ) );
		?>
		<li class="wpbp-cl-release<?php echo $mine ? ' is-current' : ''; ?>" data-version="<?php echo esc_attr( $release['version'] ); ?>">
			<div class="wpbp-cl-meta">
				<h2 class="wpbp-cl-version" tabindex="-1"><?php echo esc_html( $release['version'] ); ?></h2>
				<?php if ( '' !== $release['date'] ) : ?>
					<time datetime="<?php echo esc_attr( $release['date'] ); ?>"><?php echo esc_html( wp_date( 'M j, Y', (int) strtotime( $release['date'] . ' 12:00:00 UTC' ), new \DateTimeZone( 'UTC' ) ) ); ?></time>
				<?php endif; ?>
				<?php if ( $mine ) : ?>
					<span class="wpbp-cl-yours"><?php esc_html_e( 'Your version', 'wrensafe' ); ?></span>
				<?php endif; ?>
			</div>
			<span class="wpbp-cl-node" aria-hidden="true"></span>
			<article class="wpbp-cl-card">
				<?php if ( count( $release['entries'] ) > 1 ) : ?>
					<p class="wpbp-cl-counts">
						<?php foreach ( $labels as $type => $label ) : ?>
							<?php if ( ! empty( $counts[ $type ] ) ) : ?>
								<span><b><?php echo esc_html( number_format_i18n( $counts[ $type ] ) ); ?></b> <?php echo esc_html( $label ); ?></span>
							<?php endif; ?>
						<?php endforeach; ?>
					</p>
				<?php endif; ?>
				<?php if ( '' !== $release['intro'] ) : ?>
					<p class="wpbp-cl-intro"><?php echo wp_kses( Changelog::format( $release['intro'] ), array( 'code' => array() ) ); ?></p>
				<?php endif; ?>
				<ul class="wpbp-cl-entries">
					<?php foreach ( $release['entries'] as $entry ) : ?>
						<li data-group="<?php echo esc_attr( Changelog::GROUPS[ $entry['type'] ] ?? 'improved' ); ?>">
							<span class="wpbp-cl-tag is-<?php echo esc_attr( $entry['type'] ); ?>"><?php echo esc_html( $labels[ $entry['type'] ] ?? $labels['note'] ); ?></span>
							<span class="wpbp-cl-text"><?php echo wp_kses( Changelog::format( $entry['text'] ), array( 'code' => array() ) ); ?></span>
						</li>
					<?php endforeach; ?>
				</ul>
			</article>
		</li>
		<?php
	}
}
