<?php
/**
 * Blocks front-end visitors with a maintenance notice while a restore is in
 * progress — deliberately *not* by writing WordPress core's `.maintenance`
 * file.
 *
 * Core's wp_maintenance() check runs from wp-settings.php before any plugin
 * (including this one) is loaded, and it applies to every PHP entry point,
 * admin-ajax.php included. A restore is driven by the browser repeatedly
 * calling admin-ajax.php to advance one chunk at a time, so writing the
 * real `.maintenance` file mid-restore blocks the very requests needed to
 * continue it — a deadlock: the site is left "stuck under maintenance"
 * with no further code of ours ever running to clear it. Hooking
 * `template_redirect` instead only affects front-end page rendering; it
 * never fires for wp-admin or admin-ajax.php, so the chunked restore keeps
 * working throughout.
 *
 * @package WPBP\Restore
 */

namespace WPBP\Restore;

defined( 'ABSPATH' ) || exit;

class RestoreSiteMaintenance {

	private const TRANSIENT = 'wpbp_restore_site_maintenance';

	public function register_hooks(): void {
		add_action( 'template_redirect', array( $this, 'maybe_block_frontend' ) );
	}

	public static function enable(): void {
		set_transient( self::TRANSIENT, true, HOUR_IN_SECONDS );
	}

	public static function disable(): void {
		delete_transient( self::TRANSIENT );
	}

	public static function is_enabled(): bool {
		return false !== get_transient( self::TRANSIENT );
	}

	public function maybe_block_frontend(): void {
		if ( ! self::is_enabled() ) {
			return;
		}

		header( 'Retry-After: 600' );

		wp_die(
			esc_html__( 'This site is currently being restored from a backup. Please check back shortly.', 'wrensafe' ),
			esc_html__( 'Maintenance', 'wrensafe' ),
			array( 'response' => 503 )
		);
	}
}
