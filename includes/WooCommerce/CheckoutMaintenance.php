<?php
/**
 * Blocks WooCommerce checkout only (rest of the site stays browsable)
 * while a restore is in progress, instead of full-site maintenance mode.
 *
 * @package WPBP\WooCommerce
 */

namespace WPBP\WooCommerce;

defined( 'ABSPATH' ) || exit;

class CheckoutMaintenance {

	private const TRANSIENT = 'wpbp_checkout_maintenance';

	public function register_hooks(): void {
		add_action( 'template_redirect', array( $this, 'maybe_block_checkout' ) );
	}

	public function enable(): void {
		set_transient( self::TRANSIENT, true, HOUR_IN_SECONDS );
	}

	public function disable(): void {
		delete_transient( self::TRANSIENT );
	}

	public function is_enabled(): bool {
		return false !== get_transient( self::TRANSIENT );
	}

	public function maybe_block_checkout(): void {
		if ( ! get_transient( self::TRANSIENT ) ) {
			return;
		}

		if ( ! function_exists( 'is_checkout' ) || ! is_checkout() ) {
			return;
		}

		wp_die(
			esc_html__( 'Checkout is temporarily unavailable while the site is being restored. Please try again shortly.', 'wrensafe' ),
			esc_html__( 'Checkout unavailable', 'wrensafe' ),
			array( 'response' => 503 )
		);
	}
}
