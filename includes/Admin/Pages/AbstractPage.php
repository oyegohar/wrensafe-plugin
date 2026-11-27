<?php
/**
 * Shared rendering scaffold for every admin page.
 *
 * @package WPBP\Admin\Pages
 */

namespace WPBP\Admin\Pages;

use WPBP\Admin\Icons;
use WPBP\Admin\Kses;
use WPBP\Security\CapabilityManager;

defined( 'ABSPATH' ) || exit;

abstract class AbstractPage {

	/**
	 * Header buttons draw inline SVG instead of the dashicon they name, so
	 * the icon always takes the button's text colour (see Icons).
	 */
	/** The author's site, for the credit in the brand bar. */
	public const AUTHOR_URL = 'https://www.husnaingohar.com/';

	private const HEADER_ICON_MAP = array(
		'dashicons-plus-alt2'       => 'plus',
		'dashicons-backup'          => 'restore',
		'dashicons-database-import' => 'upload',
		'dashicons-archive'         => 'archive',
		'dashicons-calendar-alt'    => 'calendar',
		'dashicons-admin-generic'   => 'settings',
		'dashicons-download'        => 'download',
		'dashicons-upload'          => 'upload',
		'dashicons-chart-bar'       => 'chart',
	);


	/**
	 * The page title shown in the <h1> and browser title.
	 */
	abstract public function get_title(): string;

	/**
	 * Render the page body content (without the outer wrapper).
	 */
	abstract protected function render_content(): void;

	/**
	 * The capability required to view this page. Pages override this with
	 * their specific capability (see CapabilityManager) instead of every
	 * page being gated by the same blanket 'manage_options'.
	 */
	protected function required_capability(): string {
		return CapabilityManager::VIEW_DASHBOARD;
	}

	/** Brand mark (Cobalt Ice 2.1 symbol: Royal Cobalt over Ice Blue), inline so no image request is needed. */
	public const BRAND_MARK_SVG = '<svg viewBox="0 0 996.01 1000.00" width="44" height="44" aria-hidden="true" focusable="false"><g transform="translate(-91.729 -98.675) scale(0.944259)"><path fill="#2457F5" d="M334.92,687.17C331.30,686.22 325.67,683.89 322.42,681.97C316.62,678.57 268.23,631.12 142.83,505.86C94.08,457.17 98.21,463.47 97.32,436.50C95.51,381.85 107.71,328.19 133.14,279.03C149.54,247.32 170.29,219.41 195.49,195.21C247.81,144.93 307.04,116.62 381.50,106.30C393.72,104.61 408.43,104.50 625.50,104.50L856.50,104.50 871.00,106.70C945.04,117.91 1007.73,149.22 1057.07,199.63C1104.65,248.25 1134.88,306.35 1146.98,372.50C1150.40,391.17 1152.11,420.40 1151.05,442.04C1150.51,453.10 1149.98,456.41 1148.15,460.19C1146.47,463.66 1126.04,484.64 1065.91,544.66C925.03,685.27 929.30,681.20 917.00,686.32C911.17,688.74 894.61,689.68 886.73,688.05C883.56,687.39 877.78,685.25 873.87,683.30C867.45,680.09 863.74,676.68 835.58,648.12C818.43,630.73 790.50,602.10 773.50,584.50C706.47,515.07 676.30,484.60 670.53,480.51C654.91,469.43 643.24,465.67 624.50,465.69C609.51,465.70 600.68,467.62 589.29,473.35C577.25,479.42 577.86,478.83 464.00,594.48C376.08,683.78 381.26,678.76 372.50,683.07C359.98,689.24 347.90,690.56 334.92,687.17Z"/><path fill="#62B6FF" d="M392.00,1163.36C371.99,1162.42 341.22,1156.80 320.50,1150.30C314.02,1148.27 290.35,1138.96 282.00,1135.16C263.99,1126.96 236.70,1109.74 217.00,1094.13C199.44,1080.23 172.01,1051.79 160.92,1036.00C158.79,1032.97 155.12,1027.80 152.75,1024.50C147.73,1017.50 135.54,997.16 131.18,988.50C116.71,959.76 107.10,929.22 100.83,892.00C98.57,878.57 98.56,877.92 98.27,761.00C98.05,668.16 98.25,643.00 99.24,641.10C101.49,636.80 105.51,634.61 111.00,634.69C121.43,634.85 122.47,635.74 175.59,689.73C202.52,717.10 239.60,755.02 257.99,774.00C312.38,830.10 334.21,851.90 339.22,855.13C351.31,862.91 368.45,865.27 382.18,861.03C392.36,857.88 399.36,852.12 420.54,829.48C431.33,817.94 442.26,806.43 444.83,803.91C451.30,797.56 459.68,788.74 489.00,757.50C518.65,725.90 572.74,669.32 588.50,653.42C596.91,644.93 601.03,641.58 606.00,639.15C624.09,630.33 643.95,633.83 658.53,648.43C665.06,654.96 714.41,707.79 730.01,724.93C735.23,730.67 749.40,745.98 761.50,758.96C773.60,771.93 793.62,793.56 806.00,807.02C818.38,820.47 829.85,832.84 831.50,834.49C833.15,836.15 836.70,840.10 839.39,843.27C845.05,849.95 856.59,858.04 863.96,860.49C874.94,864.13 888.11,864.12 899.19,860.44C905.22,858.43 914.56,852.39 920.16,846.85C922.58,844.46 940.75,825.85 960.53,805.50C1024.30,739.91 1039.67,724.26 1083.46,680.32C1125.81,637.84 1126.59,637.12 1132.29,635.49C1142.97,632.44 1150.24,635.71 1151.49,644.13C1152.66,652.07 1151.41,870.69 1150.13,880.00C1142.49,935.89 1126.39,979.61 1097.69,1022.47C1086.94,1038.51 1073.55,1054.49 1056.53,1071.59C1039.69,1088.49 1025.26,1100.18 1004.00,1114.13C971.13,1135.70 931.08,1151.60 892.01,1158.60C864.57,1163.51 875.47,1163.30 629.00,1163.49C502.22,1163.58 395.57,1163.52 392.00,1163.36Z"/></g></svg>';

	/**
	 * One sentence shown under the page title.
	 */
	protected function get_subtitle(): string {
		return '';
	}

	/**
	 * Optional pill inside the title: array( 'label' => string, 'tone' => 'soon'|'beta'|… ).
	 *
	 * @return array<string,string>
	 */
	protected function get_title_pill(): array {
		return array();
	}

	/**
	 * Header buttons (at most two, at most one 'cta'). Each entry:
	 * array( 'label' => string, 'url' => string, 'variant' => 'cta'|'primary'|'secondary'|'ghost', 'icon' => 'dashicons-…', 'cap' => capability ).
	 *
	 * @return array<int,array<string,string>>
	 */
	protected function get_header_actions(): array {
		return array();
	}

	/**
	 * "DashboardPage" → "dashboard", "BackupBrowserPage" → "backup-browser".
	 */
	protected function page_key(): string {
		$short = ( new \ReflectionClass( $this ) )->getShortName();

		return strtolower( (string) preg_replace( '/(?<!^)[A-Z]/', '-$0', (string) preg_replace( '/Page$/', '', $short ) ) );
	}

	/**
	 * Admin URL of one of the plugin's pages.
	 */
	protected static function page_url( string $slug, array $args = array() ): string {
		return add_query_arg( array_merge( array( 'page' => $slug ), $args ), admin_url( 'admin.php' ) );
	}

	/**
	 * Render the full admin page: branded header, then the page content.
	 */
	public function render(): void {
		if ( ! CapabilityManager::current_user_can( $this->required_capability() ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'wrensafe' ) );
		}

		printf( '<div class="wrap wpbp-wrap wpbp-page-%s">', esc_attr( $this->page_key() ) );
		self::render_brand_bar();
		$this->render_header();
		echo '<hr class="wp-header-end">'; // WordPress core moves admin notices to just after this.
		$this->render_content();
		\WPBP\Admin\Onboarding::render_welcome_template();
		echo '</div>';
	}

	private function render_header(): void {
		$variants = array(
			'cta'       => 'button button-primary wpbp-button-cta',
			'primary'   => 'button button-primary',
			'secondary' => 'button',
			'ghost'     => 'button wpbp-button-ghost',
		);
		$pill     = $this->get_title_pill();
		$subtitle = $this->get_subtitle();
		$actions  = array_filter(
			$this->get_header_actions(),
			static function ( array $action ): bool {
				return empty( $action['cap'] ) || CapabilityManager::current_user_can( $action['cap'] );
			}
		);
		?>
		<header class="wpbp-header">
			<div class="wpbp-header-main">
				<div class="wpbp-header-text">
					<h1 class="wpbp-page-title">
						<?php echo esc_html( $this->get_title() ); ?>
						<?php if ( ! empty( $pill['label'] ) ) : ?>
							<span class="wpbp-pill is-<?php echo esc_attr( $pill['tone'] ?? 'soon' ); ?>"><?php echo esc_html( $pill['label'] ); ?></span>
						<?php endif; ?>
					</h1>
					<?php if ( '' !== $subtitle ) : ?>
						<p class="wpbp-page-subtitle"><?php echo esc_html( $subtitle ); ?></p>
					<?php endif; ?>
				</div>
			</div>
			<?php if ( $actions ) : ?>
				<div class="wpbp-header-actions">
					<?php foreach ( $actions as $action ) : ?>
						<a class="<?php echo esc_attr( $variants[ $action['variant'] ?? 'secondary' ] ?? 'button' ); ?>" href="<?php echo esc_url( $action['url'] ); ?>">
							<?php
							if ( ! empty( $action['icon'] ) ) {
								$svg = self::HEADER_ICON_MAP[ $action['icon'] ] ?? '';

								if ( '' !== $svg ) {
									Icons::render( $svg, 18 );
								} else {
									echo '<span class="dashicons ' . esc_attr( $action['icon'] ) . '" aria-hidden="true"></span>';
								}
							}
							?>
							<?php echo esc_html( $action['label'] ); ?>
						</a>
					<?php endforeach; ?>
				</div>
			<?php endif; ?>
		</header>
		<?php
	}

	/**
	 * What the brand bar shows. Add-ons can change it (White Label: another name, no credit).
	 *
	 * @return array{name:string,tagline:string,mark:bool,credit:bool,links:array<int,array{label:string,url:string}>}
	 */
	public static function brand(): array {
		$brand = array(
			'name'    => __( 'Wrensafe', 'wrensafe' ),
			'tagline' => __( 'Backup, restore and migration', 'wrensafe' ),
			'mark'    => true,
			'credit'  => true,
			'links'   => array(
				array(
					'label' => __( 'Docs', 'wrensafe' ),
					'url'   => 'https://wordpress.org/plugins/wrensafe/#faq',
				),
				array(
					'label' => __( 'Help', 'wrensafe' ),
					'url'   => 'https://wordpress.org/support/plugin/wrensafe/',
				),
			),
		);

		/**
		 * Filters the brand bar at the top of Wrensafe's screens.
		 *
		 * @param array $brand 'name', 'tagline', 'mark' (show the logo), 'credit' (show "Made with ♥ by
		 *                     Husnain Gohar") and 'links' (each 'label' and 'url', http or https).
		 */
		$filtered = apply_filters( 'wpbp_admin_brand', $brand );
		$filtered = is_array( $filtered ) ? $filtered : $brand;
		$links    = array();

		foreach ( (array) ( $filtered['links'] ?? array() ) as $link ) {
			$url = is_array( $link ) ? esc_url_raw( (string) ( $link['url'] ?? '' ), array( 'http', 'https' ) ) : '';

			if ( '' !== $url && '' !== (string) ( $link['label'] ?? '' ) ) {
				$links[] = array(
					'label' => (string) $link['label'],
					'url'   => $url,
				);
			}
		}

		return array(
			'name'    => '' !== (string) ( $filtered['name'] ?? '' ) ? (string) $filtered['name'] : $brand['name'],
			'tagline' => (string) ( $filtered['tagline'] ?? '' ),
			'mark'    => ! empty( $filtered['mark'] ),
			'credit'  => ! empty( $filtered['credit'] ),
			'links'   => array_slice( $links, 0, 4 ),
		);
	}

	/** The brand bar: logo, name, version, tagline, help links and the author's credit. */
	public static function render_brand_bar(): void {
		$brand = self::brand();
		$tab   = '<span class="screen-reader-text"> ' . esc_html__( '(opens in a new tab)', 'wrensafe' ) . '</span>';
		// Not on a White Label site, and only for users who can open Settings.
		$support = $brand['credit'] && CapabilityManager::current_user_can( CapabilityManager::MANAGE_SETTINGS );
		?>
		<div class="wpbp-brandbar">
			<div class="wpbp-brandbar-main">
				<?php if ( $brand['mark'] ) : ?>
					<span class="wpbp-brandbar-tile" aria-hidden="true"><?php echo wp_kses( self::BRAND_MARK_SVG, Kses::allowed() ); ?></span>
				<?php endif; ?>
				<div class="wpbp-brandbar-text">
					<p class="wpbp-brandbar-name">
						<?php echo esc_html( $brand['name'] ); ?>
						<span class="wpbp-brandbar-version"><?php echo esc_html( WPBP_VERSION ); ?></span>
					</p>
					<?php if ( '' !== $brand['tagline'] ) : ?>
						<p class="wpbp-brandbar-tagline"><?php echo esc_html( $brand['tagline'] ); ?></p>
					<?php endif; ?>
				</div>
			</div>
			<?php if ( $brand['links'] || $brand['credit'] || $support || \WPBP\Admin\GuidedTours::available() || \WPBP\Admin\Changelog::available() ) : ?>
				<nav class="wpbp-brandbar-links" aria-label="<?php esc_attr_e( 'Help', 'wrensafe' ); ?>">
					<?php if ( \WPBP\Admin\GuidedTours::available() ) : ?>
						<button type="button" class="wpbp-brandbar-guide" data-wpbp-tours-open><span class="dashicons dashicons-location-alt" aria-hidden="true"></span><?php esc_html_e( 'Guided tours', 'wrensafe' ); ?></button>
					<?php endif; ?>
					<?php if ( \WPBP\Admin\Changelog::available() ) : ?>
						<a class="wpbp-brandbar-changelog" href="<?php echo esc_url( self::page_url( ChangelogPage::SLUG ) ); ?>">
							<?php esc_html_e( 'Changelog', 'wrensafe' ); ?>
							<?php if ( \WPBP\Admin\Changelog::has_news() ) : ?>
								<span class="wpbp-brandbar-new" aria-hidden="true"></span>
								<span class="screen-reader-text"><?php esc_html_e( '(new version)', 'wrensafe' ); ?></span>
							<?php endif; ?>
						</a>
					<?php endif; ?>
					<?php foreach ( $brand['links'] as $link ) : ?>
						<a href="<?php echo esc_url( $link['url'] ); ?>" target="_blank" rel="noopener"><?php echo esc_html( $link['label'] ) . wp_kses( $tab, Kses::allowed() ); ?></a>
					<?php endforeach; ?>
					<?php if ( $support ) : ?>
						<a class="wpbp-brandbar-support" href="<?php echo esc_url( self::page_url( SettingsPage::MENU_SLUG, array( 'tab' => 'support-us' ) ) ); ?>"><?php echo wp_kses( \WPBP\Admin\SupportUs::CUP_SVG, Kses::allowed() ); ?><?php esc_html_e( 'Support Us', 'wrensafe' ); ?></a>
					<?php endif; ?>
					<?php if ( $brand['credit'] ) : ?>
						<a class="wpbp-credit" href="<?php echo esc_url( self::AUTHOR_URL ); ?>" target="_blank" rel="noopener">
							<?php
							$heart = '<svg class="wpbp-heart" viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M12 21.35l-1.45-1.32C5.4 15.36 2 12.28 2 8.5 2 5.42 4.42 3 7.5 3c1.74 0 3.41.81 4.5 2.09C13.09 3.81 14.76 3 16.5 3 19.58 3 22 5.42 22 8.5c0 3.78-3.4 6.86-8.55 11.54L12 21.35z"/></svg><span class="screen-reader-text">' . esc_html__( 'love', 'wrensafe' ) . '</span>';
							$name  = '<span class="wpbp-credit-name">' . esc_html__( 'Husnain Gohar', 'wrensafe' ) . '</span>';

							echo wp_kses(
								/* translators: 1: a heart (read as "love"), 2: the author's name */
								sprintf( esc_html__( 'Made with %1$s by %2$s', 'wrensafe' ), $heart, $name ) . $tab,
								Kses::allowed()
							);
							?>
						</a>
					<?php endif; ?>
				</nav>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Standard placeholder used by pages whose real functionality lands in a later development phase.
	 */
	protected function render_coming_soon( string $message ): void {
		echo '<div class="wpbp-card wpbp-coming-soon">';
		echo '<p>' . esc_html( $message ) . '</p>';
		echo '</div>';
	}
}
