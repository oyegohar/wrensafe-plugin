<?php
/**
 * The review request: a card on Wrensafe's Dashboard and Support Us tab, and a notice at a good moment.
 *
 * The notice appears after a restore that worked, or after a few backups and two weeks of use, on the
 * Dashboard, Plugins, Updates and Wrensafe screens. It is per user and always closes: "Maybe later" brings it
 * back after 30 days, "I already did" (or leaving a review) ends it. It never shows right after something
 * failed (a restore, a backup or its off-site copy, an update run, a transfer, an import or a staging job), while work
 * runs, next to the Fix Links notice, or on a site using White Label.
 *
 * @package WPBP\Admin
 */

namespace WPBP\Admin;

use WPBP\Admin\Pages\AbstractPage;
use WPBP\Admin\Pages\FixLinksPage;
use WPBP\Core\Database\BackupsRepository;
use WPBP\Core\Database\JobsRepository;
use WPBP\Restore\LinkCheck;
use WPBP\Security\CapabilityManager;
use WPBP\Security\NonceManager;
use WPBP\Updates\SafeUpdateManager;
use WPBP\Updates\SafeUpdateJob;

defined( 'ABSPATH' ) || exit;

class ReviewRequest {

	/** User meta: array( 'state' => 'later'|'done', 'until' => timestamp ). */
	const META = 'wpbp_review';

	/** "Maybe later" asks again after this many days. */
	const LATER_DAYS = 30;

	/** Without a restore: this many backups, the first one at least MIN_DAYS old. */
	const MIN_BACKUPS = 3;
	const MIN_DAYS    = 14;

	/** @var bool|null This request's answer (asked by both the styles and the notice). */
	private static $ask = null;

	const STAR = '<svg viewBox="0 0 24 24" width="16" height="16" aria-hidden="true" focusable="false"><path fill="currentColor" d="M12 2.6l2.9 5.9 6.5.95-4.7 4.58 1.11 6.47L12 17.45l-5.81 3.05 1.11-6.47L2.6 9.45l6.5-.95L12 2.6z"/></svg>';

	public static function register(): void {
		// On Wrensafe's screens it comes first. On WordPress's own screens it follows WordPress's warnings (priorities
		// 1 to 5: recovery mode, updates, paused plugins) and comes before other plugins' notices.
		add_action( 'admin_notices', array( self::class, 'render_notice' ), PHP_INT_MIN );
		add_action( 'admin_notices', array( self::class, 'render_core_notice' ), 6 );
		add_action( 'admin_enqueue_scripts', array( self::class, 'enqueue' ) );
	}

	/** Screens the notice can show on. Wrensafe's Dashboard and Settings have the card instead. */
	private static function shows_on( string $id ): bool {
		if ( in_array( $id, array( 'dashboard', 'plugins', 'update-core' ), true ) ) {
			return true;
		}

		return AdminMenu::is_plugin_screen( $id )
			&& 'toplevel_page_' . AdminMenu::TOP_SLUG !== $id
			&& false === strpos( $id, 'wrensafe-settings' )
			&& false === strpos( $id, FixLinksPage::SLUG );
	}

	/** Whether this user is asked now. */
	private static function should_ask(): bool {
		if ( null !== self::$ask ) {
			return self::$ask;
		}

		self::$ask = false;

		if ( is_network_admin() || is_user_admin() || ! CapabilityManager::current_user_can( CapabilityManager::VIEW_DASHBOARD ) || '' === SupportUs::review_url() ) {
			return false;
		}

		// White Label: the site's users shouldn't be asked to review Wrensafe.
		if ( ! AbstractPage::brand()['credit'] ) {
			return false;
		}

		$state = get_user_meta( get_current_user_id(), self::META, true );

		if ( is_array( $state ) && ( 'done' === ( $state['state'] ?? '' ) || (int) ( $state['until'] ?? 0 ) > time() ) ) {
			return false;
		}

		self::$ask = ! LinkCheck::needs_notice() && ! self::work_running() && self::good_moment();

		return self::$ask;
	}

	/** An update, backup, restore or transfer is under way: its result isn't known yet, and its progress comes first. */
	private static function work_running(): bool {
		return null !== SafeUpdateManager::running_job() || JobsRepository::count_active() > 0;
	}

	/** A restore worked, or Wrensafe has been in use for a while, and nothing is wrong right now. */
	private static function good_moment(): bool {
		if ( null !== MenuBadges::for_page( 'wrensafe-schedule' ) || self::last_work_went_wrong() ) {
			return false;
		}

		foreach ( JobsRepository::list_by_type( 'restore', 20 ) as $job ) {
			$status = (string) $job['status'];

			if ( 'failed' === $status ) {
				return false; // The last restore that ended didn't work: not the moment to ask.
			}

			// A restore a Safe Updates run made to undo a broken update is no success to celebrate.
			if ( 'completed' === $status && false === strpos( (string) $job['payload'], '"source":"safe_update"' ) ) {
				return true;
			}
		}

		$oldest = BackupsRepository::oldest_completed_at();

		return BackupsRepository::count_completed() >= self::MIN_BACKUPS
			&& '' !== $oldest
			&& strtotime( $oldest ) <= strtotime( current_time( 'mysql' ) ) - self::MIN_DAYS * DAY_IN_SECONDS;
	}

	/**
	 * The latest backup, update run, transfer, import or staging job that ended didn't go well: it failed, a backup's
	 * off-site copy failed, or an update run had an update fail or put back. Not the moment to ask for a review.
	 */
	private static function last_work_went_wrong(): bool {
		foreach ( array( 'backup', SafeUpdateJob::TYPE, 'migration_transfer', 'import', 'staging' ) as $type ) {
			foreach ( JobsRepository::list_by_type( $type, 5 ) as $job ) {
				$status = (string) $job['status'];

				if ( 'failed' === $status ) {
					return true;
				}

				if ( 'completed' !== $status ) {
					continue; // Still running, or cancelled by the user: look at the one before.
				}

				$result = json_decode( (string) ( $job['result'] ?? '' ), true );
				$result = is_array( $result ) ? $result : array();

				if ( 'failed' === (string) ( $result['remote_copy'] ?? '' ) ) {
					return true;
				}

				$summary = (array) ( $result['summary'] ?? array() );

				if ( (int) ( $summary['failed'] ?? 0 ) > 0 || (int) ( $summary['rolled_back'] ?? 0 ) > 0 ) {
					return true;
				}

				break; // The latest one that ended went well.
			}
		}

		return false;
	}

	public static function enqueue( string $hook_suffix ): void {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		$id     = $screen ? (string) $screen->id : $hook_suffix;
		$notice = self::shows_on( $id ) && self::should_ask();

		if ( ! $notice && ! AdminMenu::is_plugin_screen( $id ) ) {
			return;
		}

		wp_enqueue_style( 'wpbp-review', WPBP_PLUGIN_URL . 'assets/admin/css/review.css', array(), WPBP_VERSION );
		wp_style_add_data( 'wpbp-review', 'rtl', 'replace' );

		if ( false !== strpos( $id, 'wrensafe-settings' ) ) {
			wp_enqueue_script( 'wpbp-support-video', WPBP_PLUGIN_URL . 'assets/admin/js/support-video.js', array(), WPBP_VERSION, true );
		}

		if ( $notice ) {
			wp_enqueue_script( 'wpbp-review', WPBP_PLUGIN_URL . 'assets/admin/js/review.js', array( 'jquery' ), WPBP_VERSION, true );
			wp_localize_script(
				'wpbp-review',
				'wpbpReview',
				array(
					'ajaxUrl' => admin_url( 'admin-ajax.php' ),
					'nonce'   => NonceManager::create( 'admin_ajax' ),
				)
			);
		}
	}

	/** The notice on Wrensafe's own screens. */
	public static function render_notice(): void {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;

		if ( $screen && AdminMenu::is_plugin_screen( (string) $screen->id ) && self::shows_on( (string) $screen->id ) && self::should_ask() ) {
			self::render( true );
		}
	}

	/** The notice on the WordPress Dashboard, Plugins and Updates screens. */
	public static function render_core_notice(): void {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;

		if ( $screen && ! AdminMenu::is_plugin_screen( (string) $screen->id ) && self::shows_on( (string) $screen->id ) && self::should_ask() ) {
			self::render( true, array(), true );
		}
	}

	/**
	 * The card.
	 *
	 * @param bool                            $notice As a notice (with "Maybe later" and "I already did").
	 * @param array<int,array{0:string,1:string}> $links  Other links for the card: URL, label.
	 * @param bool                            $core   On a WordPress screen: WordPress places it under the page title with
	 *                                                the other notices, so it stays hidden until it is there (no jump).
	 */
	public static function render( bool $notice, array $links = array(), bool $core = false ): void {
		$count = BackupsRepository::count_completed();
		$url   = SupportUs::review_url();
		$text  = $count > 0
			? sprintf(
				/* translators: %s: number of backups */
				_n( 'Wrensafe is keeping %s backup of this site. A short review on WordPress.org helps other site owners find it.', 'Wrensafe is keeping %s backups of this site. A short review on WordPress.org helps other site owners find it.', $count, 'wrensafe' ),
				number_format_i18n( $count )
			)
			: __( 'If Wrensafe helps you protect or move your website, a short review on WordPress.org helps other site owners find it.', 'wrensafe' );
		?>
		<div class="<?php echo esc_attr( $notice ? ( $core ? 'notice is-dismissible wpbp-notice wpbp-review' : 'notice inline is-dismissible wpbp-notice wpbp-review' ) : 'wpbp-card wpbp-review' ); ?>"<?php echo $notice ? ' id="wpbp-review"' : ''; ?><?php echo $core ? ' data-wpbp-reveal="1" style="display:none"' : ''; ?>>
			<span class="wpbp-review-mark" aria-hidden="true"><?php echo wp_kses( AbstractPage::BRAND_MARK_SVG, Kses::allowed() ); ?></span>
			<div class="wpbp-review-body">
				<span class="wpbp-review-stars" aria-hidden="true"><?php echo wp_kses( str_repeat( self::STAR, 5 ), Kses::allowed() ); ?></span>
				<p class="wpbp-review-title"><?php esc_html_e( 'Enjoying Wrensafe?', 'wrensafe' ); ?></p>
				<p class="wpbp-review-text"><?php echo esc_html( $text ); ?></p>
			</div>
			<div class="wpbp-review-actions">
				<?php if ( '' !== $url ) : ?>
					<a class="button wpbp-review-go" href="<?php echo esc_url( $url . '#new-post' ); ?>" target="_blank" rel="noopener noreferrer">
						<?php esc_html_e( 'Leave a review', 'wrensafe' ); ?>
						<span class="screen-reader-text"><?php esc_html_e( '(opens in a new tab)', 'wrensafe' ); ?></span>
					</a>
				<?php endif; ?>
				<?php if ( $notice ) : ?>
					<button type="button" class="button wpbp-review-later"><?php esc_html_e( 'Maybe later', 'wrensafe' ); ?></button>
					<button type="button" class="button wpbp-review-done"><?php esc_html_e( 'I already did', 'wrensafe' ); ?></button>
				<?php endif; ?>
				<?php foreach ( $links as $link ) : ?>
					<a class="button" href="<?php echo esc_url( (string) $link[0] ); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html( (string) $link[1] ); ?></a>
				<?php endforeach; ?>
			</div>
		</div>
		<?php
	}

	/** AJAX: "Maybe later", "I already did", or the review link was opened. */
	public static function choice(): void {
		check_ajax_referer( 'wpbp_admin_ajax', '_wpbp_nonce' );

		if ( ! CapabilityManager::current_user_can( CapabilityManager::VIEW_DASHBOARD ) ) {
			wp_send_json_error( array( 'message' => __( 'You do not have permission to do this.', 'wrensafe' ) ), 403 );
		}

		$choice = isset( $_POST['choice'] ) ? sanitize_key( wp_unslash( $_POST['choice'] ) ) : '';

		if ( ! in_array( $choice, array( 'later', 'done' ), true ) ) {
			wp_send_json_error( array( 'message' => __( 'Unknown choice.', 'wrensafe' ) ), 400 );
		}

		// "Maybe later" asks once more; the second time it is the last.
		$old   = get_user_meta( get_current_user_id(), self::META, true );
		$times = is_array( $old ) ? (int) ( $old['times'] ?? ( 'later' === ( $old['state'] ?? '' ) ? 1 : 0 ) ) : 0;

		if ( 'later' === $choice && $times >= 1 ) {
			$choice = 'done';
		}

		update_user_meta(
			get_current_user_id(),
			self::META,
			array(
				'state' => $choice,
				'until' => 'later' === $choice ? time() + self::LATER_DAYS * DAY_IN_SECONDS : 0,
				'times' => $times + ( 'later' === $choice ? 1 : 0 ),
			)
		);

		wp_send_json_success();
	}
}
