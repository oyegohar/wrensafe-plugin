<?php
/**
 * Renders the "Support Us" card shown on the dashboard, settings page, and footer.
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
	 * Render the full Support Us card. Only buttons whose URL is actually
	 * configured are shown.
	 */
	public static function render_card(): void {
		$buttons = array(
			array( self::support_url(), __( 'Support Us', 'wrensafe' ), true ),
			array( self::review_url(), __( 'Leave a review', 'wrensafe' ), false ),
			array( self::feature_request_url(), __( 'Request a feature', 'wrensafe' ), false ),
			array( self::report_issue_url(), __( 'Report an issue', 'wrensafe' ), false ),
			array( self::community_url(), __( 'Join the community', 'wrensafe' ), false ),
		);
		?>
		<div class="wpbp-card wpbp-support-card">
			<h2><?php esc_html_e( 'Enjoying Wrensafe?', 'wrensafe' ); ?></h2>
			<p>
				<?php esc_html_e( 'If Wrensafe helps you protect or move your website, a short review on WordPress.org helps other site owners find it.', 'wrensafe' ); ?>
			</p>
			<div class="wpbp-support-buttons">
				<?php foreach ( $buttons as list( $url, $label, $is_primary ) ) : ?>
					<?php if ( '' !== $url ) : ?>
						<a class="button<?php echo $is_primary ? ' button-primary' : ''; ?>" href="<?php echo esc_url( $url ); ?>" target="_blank" rel="noopener noreferrer">
							<?php echo esc_html( $label ); ?>
						</a>
					<?php endif; ?>
				<?php endforeach; ?>
			</div>
		</div>
		<?php
	}

	/**
	 * Render a small inline credit line for use in the admin footer.
	 */
	public static function render_footer_text(): string {
		$url = self::support_url();

		if ( '' === $url ) {
			return __( 'Enjoying Wrensafe?', 'wrensafe' );
		}

		return sprintf(
			/* translators: %s: Support Us link */
			__( 'Enjoying Wrensafe? %s', 'wrensafe' ),
			'<a href="' . esc_url( $url ) . '" target="_blank" rel="noopener noreferrer">' . esc_html__( 'Support development', 'wrensafe' ) . '</a>'
		);
	}
}
