<?php
/**
 * Migration Assistant page — guided pre-migration scanner.
 *
 * The scan + recommendation runs through admin.js (initMigrationAssistant),
 * which calls the wpbp_migration_assistant_scan AJAX action with the standard
 * _wpbp_nonce. The on-page JS used to send the nonce under the wrong POST key,
 * so the request 403'd and the spinner never cleared — that is fixed here.
 *
 * @package WPBP\Admin\Pages
 */

namespace WPBP\Admin\Pages;

use WPBP\Admin\Icons;
use WPBP\Migration\MigrationAssistant;
use WPBP\Migration\MigrationTemplates;
use WPBP\Security\CapabilityManager;

defined( 'ABSPATH' ) || exit;

class MigrationAssistantPage extends AbstractPage {

	public function get_title(): string {
		return __( 'Migration Assistant', 'wrensafe' );
	}

	protected function get_subtitle(): string {
		return __( 'Scans this site and its server, then recommends the safest way to migrate it. Nothing is changed or moved.', 'wrensafe' );
	}

	protected function required_capability(): string {
		return CapabilityManager::RESTORE_BACKUPS;
	}

	protected function render_content(): void {
		$templates = MigrationTemplates::all();
		$latest    = MigrationAssistant::latest_result();

		// Printed before the (footer) admin script via the Scripts API rather
		// than a raw <script> tag. < > & and quotes are written as \u escapes,
		// so no scanned value (a file or plugin name) can end the script.
		wp_add_inline_script( 'wpbp-admin', 'window.wpbpMaLatest = ' . wp_json_encode( $latest, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT ) . ';', 'before' );
		?>

		<!-- Start: what it does, and the two ways to go on -->
		<div id="wpbp-ma-step-choose" class="wpbp-card wpbp-ma-start">
			<div class="wpbp-card-head">
				<span class="wpbp-icon-tile" aria-hidden="true"><?php Icons::render( 'search', 20 ); ?></span>
				<div>
					<h2><?php esc_html_e( 'Check this site before you move it', 'wrensafe' ); ?></h2>
					<p class="description"><?php esc_html_e( 'The scan checks PHP and WordPress versions, server limits, database and uploads size, and HTTPS.', 'wrensafe' ); ?></p>
				</div>
			</div>
			<div class="wpbp-ma-choices">
				<button type="button" class="wpbp-ma-choice is-recommended" id="wpbp-ma-start-scan">
					<span class="wpbp-icon-tile" aria-hidden="true"><?php Icons::render( 'server', 20 ); ?></span>
					<span class="wpbp-ma-choice-text">
						<strong><?php esc_html_e( 'Scan my site', 'wrensafe' ); ?></strong>
						<span><?php esc_html_e( 'Get a readiness score, the recommended method and the steps to follow. Takes a few seconds.', 'wrensafe' ); ?></span>
					</span>
					<span class="wpbp-pill is-info"><?php esc_html_e( 'Recommended', 'wrensafe' ); ?></span>
				</button>
				<button type="button" class="wpbp-ma-choice" id="wpbp-ma-choose-template">
					<span class="wpbp-icon-tile is-neutral" aria-hidden="true"><?php Icons::render( 'archive', 20 ); ?></span>
					<span class="wpbp-ma-choice-text">
						<strong><?php esc_html_e( 'Browse migration templates', 'wrensafe' ); ?></strong>
						<span><?php esc_html_e( 'Start a backup set up for a common situation, such as a client handover or moving a store.', 'wrensafe' ); ?></span>
					</span>
				</button>
			</div>
		</div>

		<!-- Scan progress -->
		<div id="wpbp-ma-progress" class="wpbp-card" hidden>
			<div class="wpbp-card-head">
				<span class="wpbp-icon-tile" aria-hidden="true"><?php Icons::render( 'search', 20 ); ?></span>
				<h2><?php esc_html_e( 'Scanning your site…', 'wrensafe' ); ?></h2>
			</div>
			<div class="wpbp-progress">
				<div class="wpbp-progress-bar"><div class="wpbp-progress-bar-fill" style="width:0%"></div></div>
				<span class="wpbp-progress-percent">0%</span>
			</div>
			<p class="wpbp-progress-status wpbp-ma-current-step"></p>
			<ul class="wpbp-verify-checklist wpbp-ma-checklist"></ul>
		</div>

		<!-- Scan results / recommendation -->
		<div id="wpbp-ma-results" hidden>
			<section class="wpbp-card wpbp-ma-hero" aria-labelledby="wpbp-ma-method-heading">
				<div class="wpbp-ma-hero-main">
					<div class="wpbp-ma-score-ring" id="wpbp-ma-score-ring">
						<div class="wpbp-ma-score-inner">
							<span id="wpbp-ma-score-value">—</span>
							<small><?php esc_html_e( '/ 100', 'wrensafe' ); ?></small>
						</div>
					</div>
					<div class="wpbp-ma-summary-meta">
						<p class="wpbp-ma-readiness-label" id="wpbp-ma-readiness-label"></p>
						<h2 id="wpbp-ma-method-heading">
							<span class="wpbp-ma-method-kicker"><?php esc_html_e( 'Recommended:', 'wrensafe' ); ?></span>
							<span id="wpbp-ma-method-label"></span>
						</h2>
						<p class="wpbp-ma-last-scanned" id="wpbp-ma-last-scanned"></p>
					</div>
					<div class="wpbp-ma-hero-actions">
						<button type="button" class="button button-primary" id="wpbp-ma-start-backup"><?php esc_html_e( 'Create backup now', 'wrensafe' ); ?></button>
						<button type="button" class="button" id="wpbp-ma-rescan"><?php Icons::render( 'refresh', 16 ); ?><?php esc_html_e( 'Scan again', 'wrensafe' ); ?></button>
					</div>
				</div>
				<dl class="wpbp-ma-facts" id="wpbp-ma-facts"></dl>
			</section>

			<div class="wpbp-grid wpbp-ma-cards">
				<section class="wpbp-card">
					<div class="wpbp-card-head">
						<span class="wpbp-icon-tile" aria-hidden="true"><?php Icons::render( 'lightbulb', 20 ); ?></span>
						<h3><?php esc_html_e( 'Why this method', 'wrensafe' ); ?></h3>
					</div>
					<p id="wpbp-ma-reason"></p>
				</section>
				<section class="wpbp-card wpbp-ma-warnings-card" id="wpbp-ma-warnings">
					<div class="wpbp-card-head wpbp-ma-has-warnings">
						<span class="wpbp-icon-tile is-warning" aria-hidden="true"><?php Icons::render( 'alert', 20 ); ?></span>
						<h3><?php esc_html_e( 'Check before you migrate', 'wrensafe' ); ?></h3>
					</div>
					<div class="wpbp-card-head wpbp-ma-no-warnings" hidden>
						<span class="wpbp-icon-tile is-success" aria-hidden="true"><?php Icons::render( 'check-circle', 20 ); ?></span>
						<h3><?php esc_html_e( 'Nothing to fix', 'wrensafe' ); ?></h3>
					</div>
					<ul id="wpbp-ma-warnings-list" class="wpbp-ma-warnings-list"></ul>
					<p class="wpbp-ma-no-warnings" hidden><?php esc_html_e( 'The scan found nothing on this server that gets in the way of a migration.', 'wrensafe' ); ?></p>
				</section>
			</div>

			<section class="wpbp-card wpbp-ma-steps-card">
				<div class="wpbp-card-head">
					<span class="wpbp-icon-tile" aria-hidden="true"><?php Icons::render( 'list-checks', 20 ); ?></span>
					<div>
						<h3><?php esc_html_e( 'Next steps', 'wrensafe' ); ?></h3>
						<p class="description" id="wpbp-ma-steps-progress"></p>
					</div>
				</div>
				<ol id="wpbp-ma-steps-list" class="wpbp-ma-steps-list"></ol>
			</section>

			<div class="wpbp-actions wpbp-ma-more">
				<?php do_action( 'wpbp_migration_assistant_actions' ); ?>
				<button type="button" class="button" id="wpbp-ma-results-templates"><?php esc_html_e( 'Browse migration templates', 'wrensafe' ); ?></button>
				<a class="button" href="<?php echo esc_url( admin_url( 'admin.php?page=wrensafe-browser' ) ); ?>"><?php esc_html_e( 'Open Backup Browser', 'wrensafe' ); ?></a>
			</div>
		</div>

		<!-- Templates picker -->
		<div id="wpbp-ma-templates" class="wpbp-card" hidden>
			<div class="wpbp-card-head">
				<span class="wpbp-icon-tile" aria-hidden="true"><?php Icons::render( 'archive', 20 ); ?></span>
				<div>
					<h2><?php esc_html_e( 'Migration templates', 'wrensafe' ); ?></h2>
					<p class="description"><?php esc_html_e( 'Choose a template to start a backup set up for that situation.', 'wrensafe' ); ?></p>
				</div>
			</div>
			<div class="wpbp-template-grid">
				<?php foreach ( $templates as $tmpl ) : ?>
					<button type="button" class="wpbp-template-card"
						data-key="<?php echo esc_attr( $tmpl['key'] ); ?>"
						data-type="<?php echo esc_attr( $tmpl['backup_type'] ); ?>"
						data-note="<?php echo esc_attr( sprintf( $tmpl['note_template'], wp_date( 'Y-m-d H:i' ) ) ); ?>">
						<span class="dashicons <?php echo esc_attr( $tmpl['icon'] ); ?>" aria-hidden="true"></span>
						<strong><?php echo esc_html( $tmpl['label'] ); ?></strong>
						<span><?php echo esc_html( $tmpl['description'] ); ?></span>
					</button>
				<?php endforeach; ?>
			</div>
			<div class="wpbp-button-row">
				<button type="button" class="button" id="wpbp-ma-back-from-templates"><?php esc_html_e( '← Back', 'wrensafe' ); ?></button>
			</div>
		</div>

		<!-- Template confirmed — redirect to backup -->
		<div id="wpbp-ma-template-chosen" class="wpbp-card" hidden>
			<div class="wpbp-card-head">
				<span class="wpbp-icon-tile is-success" aria-hidden="true"><?php Icons::render( 'check-circle', 20 ); ?></span>
				<p><?php esc_html_e( 'Backup started. Opening Create Backup to show its progress…', 'wrensafe' ); ?></p>
			</div>
		</div>
		<?php
	}
}
