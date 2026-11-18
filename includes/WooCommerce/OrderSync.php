<?php
/**
 * Lightweight post-restore tidy-up for WooCommerce: refreshes lookup
 * tables/caches rather than mutating order data, since Safe Mode already
 * protects orders by excluding their tables from the restore entirely.
 *
 * @package WPBP\WooCommerce
 */

namespace WPBP\WooCommerce;

defined( 'ABSPATH' ) || exit;

class OrderSync {

	public function sync(): void {
		if ( ! WooSafeMode::is_active() ) {
			return;
		}

		// Clear WooCommerce's transients/caches so the admin sees current,
		// untouched order/customer/stock data immediately after a restore.
		if ( function_exists( 'wc_delete_product_transients' ) ) {
			wc_delete_product_transients();
		}

		if ( function_exists( 'wc_cache_helper_get_transient_version' ) ) {
			delete_transient( 'wc_term_counts' );
		}

		do_action( 'wpbp_woocommerce_order_sync' );
	}
}
