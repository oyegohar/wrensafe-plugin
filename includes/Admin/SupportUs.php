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

	/** A cup of coffee with a heart: the tip card and the header's Support Us link. */
	const CUP_SVG = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="M7.3 6.3c-.75-.7-.75-1.5 0-2.2s.75-1.5 0-2.2M10.5 6.3c-.75-.7-.75-1.5 0-2.2s.75-1.5 0-2.2M13.7 6.3c-.75-.7-.75-1.5 0-2.2s.75-1.5 0-2.2"/><path d="M3.8 8.5h13.4v5.2a6.3 6.3 0 0 1-6.3 6.3h-.8a6.3 6.3 0 0 1-6.3-6.3V8.5z"/><path d="M17.2 10.5h1.3a2.6 2.6 0 0 1 0 5.2h-1.6"/><path d="M2.8 21.2h15.4"/><path fill="currentColor" stroke="none" d="M10.5 12.4c-.55-.62-1.6-.62-2.05.05-.4.6-.22 1.35.35 1.82l1.7 1.43 1.7-1.43c.57-.47.75-1.22.35-1.82-.45-.67-1.5-.67-2.05-.05z"/></svg>';

	public static function register(): void {
		add_filter( 'plugin_row_meta', array( self::class, 'row_link' ), 10, 2 );
	}

	/**
	 * A support link: the matching constant (blank by default, so the
	 * button stays hidden), filterable for add-ons and agencies.
	 */
	private static function link( string $option_key, string $fallback_constant ): string {
		$url = defined( $fallback_constant ) ? (string) constant( $fallback_constant ) : '';

		/**
		 * Filters a Support Us link (support_url, tip_url, feature_request_url,
		 * report_issue_url or community_url). Return '' to hide the button.
		 */
		return (string) apply_filters( 'wpbp_support_link', $url, $option_key );
	}

	public static function support_url(): string {
		return self::link( 'support_url', 'WPBP_SUPPORT_URL' );
	}

	/** The optional tip: a Stripe payment page, opened only when someone selects the link. */
	public static function tip_url(): string {
		return self::link( 'tip_url', 'WPBP_TIP_URL' );
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
	 * "Send a tip" in Wrensafe's row on the Plugins screen. Not with White Label.
	 *
	 * @param string[] $links The row's links.
	 * @param string   $file  The plugin in this row.
	 * @return string[]
	 */
	public static function row_link( array $links, string $file ): array {
		$url = self::tip_url();

		if ( WPBP_PLUGIN_BASENAME !== $file || '' === $url || ! Pages\AbstractPage::brand()['credit'] ) {
			return $links;
		}

		$links[] = sprintf( '<a href="%s" target="_blank" rel="noopener noreferrer">%s</a>', esc_url( $url ), esc_html__( 'Send a tip', 'wrensafe' ) );

		return $links;
	}

	/** The tip card under Settings → Support Us. Not with White Label. */
	public static function render_tip(): void {
		$url = self::tip_url();

		if ( '' === $url || ! Pages\AbstractPage::brand()['credit'] ) {
			return;
		}

		$tab = '<span class="screen-reader-text"> ' . esc_html__( '(opens in a new tab)', 'wrensafe' ) . '</span>';
		?>
		<div class="wpbp-card wpbp-tip-card" id="wpbp-tip">
			<span class="wpbp-tip-art" aria-hidden="true"><?php echo wp_kses( self::CUP_SVG, Kses::allowed() ); ?></span>
			<div class="wpbp-tip-body">
				<h2><?php esc_html_e( 'Enjoying Wrensafe? Buy us a coffee', 'wrensafe' ); ?></h2>
				<p><?php esc_html_e( 'Wrensafe is free. If it saved your site or an afternoon of work, a small tip helps pay for new features, testing on real sites and answers in the support forum.', 'wrensafe' ); ?></p>

				<?php $amounts = self::tip_amounts( $url ); ?>
				<?php if ( $amounts ) : ?>
					<ul class="wpbp-tip-amounts" aria-label="<?php esc_attr_e( 'Choose a tip', 'wrensafe' ); ?>">
						<?php foreach ( $amounts as $amount ) : ?>
							<li>
								<a class="wpbp-tip-amount" href="<?php echo esc_url( $amount['url'] ); ?>" target="_blank" rel="noopener noreferrer">
									<span class="wpbp-tip-sum"><?php echo esc_html( $amount['sum'] ); ?></span>
									<span class="wpbp-tip-what"><?php echo esc_html( $amount['label'] ); ?></span>
									<?php echo wp_kses( $tab, Kses::allowed() ); ?>
								</a>
							</li>
						<?php endforeach; ?>
					</ul>
				<?php endif; ?>

				<div class="wpbp-tip-foot">
					<a class="<?php echo esc_attr( $amounts ? 'wpbp-tip-other' : 'button button-primary wpbp-button-cta' ); ?>" href="<?php echo esc_url( $url ); ?>" target="_blank" rel="noopener noreferrer">
						<?php echo esc_html( $amounts ? __( 'Choose your own amount', 'wrensafe' ) : __( 'Send a tip', 'wrensafe' ) ); ?>
						<?php echo wp_kses( $tab, Kses::allowed() ); ?>
					</a>
					<span class="wpbp-tip-secure"><span class="dashicons dashicons-lock" aria-hidden="true"></span><?php esc_html_e( 'Secure checkout by Stripe', 'wrensafe' ); ?></span>
				</div>
				<p class="description"><?php esc_html_e( 'Tips go to Tech and Webs, the agency behind Wrensafe. A tip doesn’t unlock features and isn’t a charitable donation.', 'wrensafe' ); ?></p>
			</div>
		</div>
		<?php
	}

	/**
	 * Ready-made amounts, when the tip link is a Stripe Payment Link (it opens with the amount filled in,
	 * and can still be changed there).
	 *
	 * @return array<int,array{sum:string,label:string,url:string}>
	 */
	private static function tip_amounts( string $url ): array {
		if ( 'buy.stripe.com' !== wp_parse_url( $url, PHP_URL_HOST ) ) {
			return array();
		}

		$amounts = array();
		$labels  = array(
			3  => __( 'A coffee', 'wrensafe' ),
			5  => __( 'Coffee and cake', 'wrensafe' ),
			10 => __( 'A team lunch', 'wrensafe' ),
			25 => __( 'A big thank you', 'wrensafe' ),
		);

		foreach ( $labels as $dollars => $label ) {
			$amounts[] = array(
				/* translators: %s: an amount in US dollars, such as 5 */
				'sum'   => sprintf( __( '$%s', 'wrensafe' ), number_format_i18n( $dollars ) ),
				'label' => $label,
				'url'   => add_query_arg( 'prefilled_amount', $dollars * 100, $url ),
			);
		}

		return $amounts;
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
