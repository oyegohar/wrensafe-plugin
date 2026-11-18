<?php
/**
 * Shared rendering scaffold for every admin page.
 *
 * @package WPBP\Admin\Pages
 */

namespace WPBP\Admin\Pages;

use WPBP\Admin\Icons;

use WPBP\Security\CapabilityManager;

defined( 'ABSPATH' ) || exit;

abstract class AbstractPage {

	/**
	 * Header buttons draw inline SVG instead of the dashicon they name, so
	 * the icon always takes the button's text colour (see Icons).
	 */
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

	/** Brand mark: a rewind arc around a heading arrow (inline, no remote assets). */
	private const BRAND_MARK_SVG = '<svg viewBox="44 48 168 168" width="24" height="24" aria-hidden="true" focusable="false"><path d="M79.5 83.5 A70 70 0 1 1 58 132" fill="none" stroke="currentColor" stroke-width="22" stroke-linecap="round"/><path d="M59.7 103.3 L64.6 68.7 L94.4 98.3 Z" fill="currentColor" stroke="currentColor" stroke-width="10" stroke-linejoin="round"/><g transform="translate(5 4)"><path d="M101 139 L91 109 Q90 101 97 103 L120 128 Z" fill="currentColor"/><ellipse cx="124" cy="140" rx="33" ry="24" fill="currentColor"/><circle cx="151" cy="121" r="16" fill="currentColor"/><path d="M164 116 L180 121 L164 126 Z" fill="currentColor"/><path d="M104 134 Q124 124 142 138 Q126 152 104 134 Z" fill="#1d2875" opacity="0.3"/><circle cx="155" cy="118" r="3.4" fill="#1d2875"/></g></svg>';

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
				<span class="wpbp-brand-mark" aria-hidden="true"><?php echo self::BRAND_MARK_SVG; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static inline SVG constant. ?></span>
				<div class="wpbp-header-text">
					<p class="wpbp-eyebrow">
						<span class="wpbp-eyebrow-name"><?php esc_html_e( 'Wrensafe', 'wrensafe' ); ?></span>
						<span class="wpbp-version"><?php echo esc_html( 'v' . WPBP_VERSION ); ?></span>
					</p>
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
	 * Standard placeholder used by pages whose real functionality lands in a later development phase.
	 */
	protected function render_coming_soon( string $message ): void {
		echo '<div class="wpbp-card wpbp-coming-soon">';
		echo '<p>' . esc_html( $message ) . '</p>';
		echo '</div>';
	}
}
