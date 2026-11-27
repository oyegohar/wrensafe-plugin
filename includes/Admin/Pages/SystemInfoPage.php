<?php
/**
 * System Info page — raw server facts plus the full Pre-Migration Health
 * Check / Migration Readiness Score (see WPBP\Migration\HealthCheck).
 *
 * @package WPBP\Admin\Pages
 */

namespace WPBP\Admin\Pages;

use WPBP\Migration\HealthCheck;
use WPBP\Migration\MigrationAssistant;
use WPBP\Security\MalwareScanner;

defined( 'ABSPATH' ) || exit;

class SystemInfoPage extends AbstractPage {

	public function get_title(): string {
		return __( 'System Info', 'wrensafe' );
	}

	protected function get_subtitle(): string {
		return __( 'Server limits and health checks that affect backups, restores and migrations.', 'wrensafe' );
	}

	protected function render_content(): void {
		global $wp_version, $wpdb;

		$result      = ( new HealthCheck() )->run();
		$readiness   = MigrationAssistant::readiness(); // The same score as the Dashboard and the Migration Assistant.
		$score       = $readiness['score'];
		$score_class = $score >= 80 ? 'completed' : ( $score >= 50 ? 'verifying' : 'failed' );
		?>
		<div class="wpbp-card">
			<h2><?php esc_html_e( 'Migration readiness score', 'wrensafe' ); ?></h2>
			<p class="wpbp-card-value">
				<span class="wpbp-backup-status wpbp-backup-status-<?php echo esc_attr( $score_class ); ?>" style="font-size:18px;">
					<?php echo esc_html( $score ); ?>/100
				</span>
			</p>
			<p class="description">
				<?php
				// The Migration Assistant needs the restore capability; a view-only user gets the sentence without the link.
				if ( \WPBP\Security\CapabilityManager::current_user_can( \WPBP\Security\CapabilityManager::RESTORE_BACKUPS ) ) {
					printf(
						/* translators: %s: link to the Migration Assistant */
						esc_html__( 'From the Migration Assistant’s scan, which lists what affects moving this site: %s. Below are this server’s details for Wrensafe.', 'wrensafe' ),
						'<a href="' . esc_url( self::page_url( 'wrensafe-migration-assistant' ) ) . '">' . esc_html__( 'Migration Assistant', 'wrensafe' ) . '</a>'
					);
				} else {
					esc_html_e( 'From the Migration Assistant’s scan of this site. Below are this server’s details for Wrensafe.', 'wrensafe' );
				}
				?>
			</p>
			<table class="widefat striped">
				<thead>
				<tr>
					<th><?php esc_html_e( 'Check', 'wrensafe' ); ?></th>
					<th><?php esc_html_e( 'Status', 'wrensafe' ); ?></th>
					<th><?php esc_html_e( 'Details', 'wrensafe' ); ?></th>
				</tr>
				</thead>
				<tbody>
				<?php foreach ( $result['checks'] as $check ) : ?>
					<?php $tone = 'pass' === $check['status'] ? 'is-success' : ( 'warning' === $check['status'] ? 'is-warning' : 'is-danger' ); ?>
					<tr>
						<td><?php echo esc_html( $check['label'] ); ?></td>
						<td><span class="wpbp-pill has-dot <?php echo esc_attr( $tone ); ?>"><?php echo esc_html( \WPBP\Admin\Labels::status( (string) $check['status'] ) ); ?></span></td>
						<td><?php echo esc_html( $check['message'] ); ?></td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
		</div>

		<div class="wpbp-card">
			<h2><?php esc_html_e( 'Server summary', 'wrensafe' ); ?></h2>
			<table class="widefat striped">
				<tbody>
				<?php
				$rows = array(
					__( 'WordPress version', 'wrensafe' )  => $wp_version,
					__( 'PHP version', 'wrensafe' )        => PHP_VERSION,
					__( 'MySQL version', 'wrensafe' )      => $wpdb->db_version(),
					__( 'Memory limit', 'wrensafe' )       => ini_get( 'memory_limit' ),
					__( 'Max execution time', 'wrensafe' ) => ini_get( 'max_execution_time' ) . 's',
					__( 'Upload max file size', 'wrensafe' ) => ini_get( 'upload_max_filesize' ),
					__( 'Post max size', 'wrensafe' )      => ini_get( 'post_max_size' ),
					__( 'Plugin version', 'wrensafe' )     => WPBP_VERSION,
				);
				foreach ( $rows as $label => $value ) :
					?>
					<tr>
						<td><strong><?php echo esc_html( $label ); ?></strong></td>
						<td><?php echo esc_html( (string) $value ); ?></td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
		</div>

		<div class="wpbp-card">
			<h2><?php esc_html_e( 'Security scan', 'wrensafe' ); ?></h2>
			<p><?php esc_html_e( 'Checks core file integrity, suspicious PHP in uploads, file permissions, and other warning signs before you back up or migrate. This is a heuristic warning system, not an antivirus — review findings yourself rather than treating them as proof of infection.', 'wrensafe' ); ?></p>
			<button type="button" id="wpbp-malware-scan-run" class="button button-primary" data-checks="<?php echo esc_attr( (string) wp_json_encode( MalwareScanner::checks() ) ); ?>" data-text="<?php echo esc_attr( (string) wp_json_encode( self::scan_text() ) ); ?>"><?php esc_html_e( 'Run security scan', 'wrensafe' ); ?></button>
			<div id="wpbp-malware-scan-results" class="wpbp-lc-body" aria-live="polite"></div>
		</div>
		<?php
	}

	/** The Security scan's words for the script. @return array<string,string> */
	private static function scan_text(): array {
		return array(
			'button'      => __( 'Scanning…', 'wrensafe' ),
			'again'       => __( 'Scan again', 'wrensafe' ),
			'title'       => __( 'Scanning your site…', 'wrensafe' ),
			/* translators: 1: checks finished, 2: all checks */
			'progress'    => __( '%1$d of %2$d checks done', 'wrensafe' ),
			'soFarOne'    => __( '1 thing to review so far', 'wrensafe' ),
			/* translators: %d: number of findings */
			'soFar'       => __( '%d things to review so far', 'wrensafe' ),
			'clear'       => __( 'No warning signs found', 'wrensafe' ),
			'clearText'   => __( 'Every check came back clean. This is a warning system, so it can’t prove a site is free of malware.', 'wrensafe' ),
			'oneTitle'    => __( '1 thing to review', 'wrensafe' ),
			/* translators: %d: number of findings */
			'manyTitle'   => __( '%d things to review', 'wrensafe' ),
			'reviewText'  => __( 'These are warning signs, not proof of infection. Nothing was changed or deleted.', 'wrensafe' ),
			'partial'     => __( 'Some checks couldn’t run', 'wrensafe' ),
			'partialText' => __( 'The checks that ran found nothing to review. Run the scan again to finish the others.', 'wrensafe' ),
			'nothing'     => __( 'Nothing found', 'wrensafe' ),
			'failed'      => __( 'Couldn’t check', 'wrensafe' ),
			/* translators: %d: number of findings */
			'toReview'    => __( '%d to review', 'wrensafe' ),
			/* translators: %d: number of items */
			'listed'      => __( '%d listed', 'wrensafe' ),
			/* translators: %d: number of items not shown */
			'more'        => __( 'and %d more', 'wrensafe' ),
			'newAdmin'    => __( 'added in the last 7 days', 'wrensafe' ),
			/* translators: %s: date the account was created */
			'since'       => __( 'since %s', 'wrensafe' ),
		);
	}
}
