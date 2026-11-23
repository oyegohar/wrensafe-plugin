<?php
/**
 * Renders the "Support Us" card shown on the dashboard and the settings page.
 *
 * Every link comes from a constant (filterable with wpbp_support_link) rather than a
 * hardcoded URL — a button/link is only rendered once the site admin has
 * actually set one, so nothing ever points at a placeholder domain.
 *
 * @package WPBP\Admin
 */

namespace WPBP\Admin;

defined( 'ABSPATH' ) || exit;

class SupportUs {

	/** The intro video on YouTube (the one on the WordPress.org plugin page). */
	const VIDEO_ID = 'uYh1AkgG6Kk';

	/**
	 * A support link: the matching constant (blank by default, so the
	 * button stays hidden), filterable for add-ons and agencies.
	 */
	private static function link( string $option_key, string $fallback_constant ): string {
		$url = defined( $fallback_constant ) ? (string) constant( $fallback_constant ) : '';

		/**
		 * Filters a Support Us link (support_url, feature_request_url,
		 * report_issue_url or community_url). Return '' to hide the button.
		 */
		return (string) apply_filters( 'wpbp_support_link', $url, $option_key );
	}

	public static function support_url(): string {
		return self::link( 'support_url', 'WPBP_SUPPORT_URL' );
	}

	public static function feature_request_url(): string {
		return self::link( 'feature_request_url', 'WPBP_FEATURE_REQUEST_URL' );
	}

	public static function report_issue_url(): string {
		return self::link( 'report_issue_url', 'WPBP_REPORT_ISSUE_URL' );
	}

	public static function community_url(): string {
		return self::link( 'community_url', 'WPBP_COMMUNITY_URL' );
	}

	/**
	 * The WordPress.org reviews URL is always real (templated from this
	 * plugin's own slug), so it never needs an override.
	 */
	public static function review_url(): string {
		return defined( 'WPBP_REVIEW_URL' ) ? WPBP_REVIEW_URL : '';
	}

	/**
	 * The intro video, as a picture stored in the plugin: the YouTube player loads only when the
	 * administrator presses play (assets/admin/js/support-video.js). Not with White Label.
	 */
	public static function render_video(): void {
		if ( ! Pages\AbstractPage::brand()['credit'] ) {
			return;
		}

		$title = __( 'Wrensafe intro video', 'wrensafe' );
		?>
		<div class="wpbp-card wpbp-video-card">
			<h2><?php esc_html_e( 'See Wrensafe in action', 'wrensafe' ); ?></h2>
			<p><?php esc_html_e( 'A short tour of backups, restores, migration and Safe Updates.', 'wrensafe' ); ?></p>
			<div class="wpbp-video">
				<button type="button" class="wpbp-video-play" data-video="<?php echo esc_attr( self::VIDEO_ID ); ?>" data-title="<?php echo esc_attr( $title ); ?>" aria-label="<?php esc_attr_e( 'Play the Wrensafe intro video (loads from YouTube)', 'wrensafe' ); ?>">
					<img src="<?php echo esc_url( WPBP_PLUGIN_URL . 'assets/admin/images/intro-video-hd.jpg' ); ?>" width="2560" height="1440" alt="" loading="lazy" />
					<span class="wpbp-video-icon" aria-hidden="true"></span>
				</button>
			</div>
			<p class="description"><?php esc_html_e( 'The video plays from YouTube. Nothing is loaded from YouTube until you press play.', 'wrensafe' ); ?></p>
		</div>
		<?php
	}

	/**
	 * Render the Support Us card: the review request with any other links that are configured. With
	 * White Label there is no request to review Wrensafe, only the site's own links (if any).
	 */
	public static function render_card(): void {
		$links = array_values(
			array_filter(
				array(
					array( self::support_url(), __( 'Support Us', 'wrensafe' ) ),
					array( self::feature_request_url(), __( 'Request a feature', 'wrensafe' ) ),
					array( self::report_issue_url(), __( 'Report an issue', 'wrensafe' ) ),
					array( self::community_url(), __( 'Join the community', 'wrensafe' ) ),
				),
				static function ( array $link ): bool {
					return '' !== $link[0];
				}
			)
		);

		if ( Pages\AbstractPage::brand()['credit'] ) {
			ReviewRequest::render( false, $links );
			return;
		}

		if ( ! $links ) {
			return;
		}
		?>
		<div class="wpbp-card wpbp-support-card">
			<h2><?php esc_html_e( 'Support', 'wrensafe' ); ?></h2>
			<div class="wpbp-support-buttons">
				<?php foreach ( $links as $link ) : ?>
					<a class="button" href="<?php echo esc_url( $link[0] ); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html( $link[1] ); ?></a>
				<?php endforeach; ?>
			</div>
		</div>
		<?php
	}
}
