<?php
/**
 * System Info page — raw server facts plus the full Pre-Migration Health
 * Check / Migration Readiness Score (see WPBP\Migration\HealthCheck).
 *
 * @package WPBP\Admin\Pages
 */

namespace WPBP\Admin\Pages;

use WPBP\Migration\HealthCheck;

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
		$score       = $result['score'];
		$score_class = $score >= 80 ? 'completed' : ( $score >= 50 ? 'verifying' : 'failed' );
		?>
		<div class="wpbp-card">
			<h2><?php esc_html_e( 'Migration readiness score', 'wrensafe' ); ?></h2>
			<p class="wpbp-card-value">
				<span class="wpbp-backup-status wpbp-backup-status-<?php echo esc_attr( $score_class ); ?>" style="font-size:18px;">
					<?php echo esc_html( $score ); ?>/100
				</span>
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
			<button type="button" id="wpbp-malware-scan-run" class="button button-primary"><?php esc_html_e( 'Run security scan', 'wrensafe' ); ?></button>
			<div id="wpbp-malware-scan-results"></div>
		</div>
		<?php
	}
}
