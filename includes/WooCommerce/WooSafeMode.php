<?php
/**
 * WooCommerce detection and the table-level exclusion list that keeps a
 * restore from overwriting live transactional data.
 *
 * Modern WooCommerce (HPOS) stores orders, order items, and lookup data in
 * their own dedicated tables, which makes it possible to safely exclude
 * them from a restore's SQL replay without touching wp_posts at all. Sites
 * still on legacy (non-HPOS) order storage keep orders as posts mixed in
 * with every other post type, so table-level exclusion can't protect them
 * — this is a known, surfaced limitation rather than a silent gap.
 *
 * @package WPBP\WooCommerce
 */

namespace WPBP\WooCommerce;

defined( 'ABSPATH' ) || exit;

class WooSafeMode {

	public static function is_active(): bool {
		return class_exists( '\WooCommerce' ) || in_array( 'woocommerce/woocommerce.php', (array) get_option( 'active_plugins', array() ), true );
	}

	public static function is_hpos_enabled(): bool {
		if ( ! function_exists( 'wc_get_container' ) ) {
			return false;
		}

		return get_option( 'woocommerce_custom_orders_table_enabled' ) === 'yes';
	}

	/**
	 * Table name suffixes (without prefix) that hold WooCommerce
	 * transactional/structured data safe to exclude at the table level.
	 */
	public static function protected_table_suffixes(): array {
		return array(
			'wc_orders',
			'wc_orders_meta',
			'wc_order_addresses',
			'wc_order_operational_data',
			'wc_order_product_lookup',
			'wc_order_coupon_lookup',
			'wc_order_tax_lookup',
			'wc_customer_lookup',
			'wc_product_meta_lookup',
			'wc_download_log',
			'woocommerce_order_items',
			'woocommerce_order_itemmeta',
			'woocommerce_sessions',
			'wc_webhooks',
		);
	}

	/**
	 * Full table names (with this site's prefix) to exclude from a restore's
	 * SQL replay when WooCommerce Safe Mode is enabled.
	 */
	public static function get_protected_tables(): array {
		global $wpdb;

		$tables = array();
		foreach ( self::protected_table_suffixes() as $suffix ) {
			$tables[] = $wpdb->prefix . $suffix;
		}

		return $tables;
	}
}
