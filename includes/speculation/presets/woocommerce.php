<?php
/**
 * WooCommerce exclusion paths for Speculation Rules.
 * Only loaded when WooCommerce is active.
 *
 * CRITICAL: Prefetching add-to-cart, AJAX, or checkout endpoints
 * can create phantom carts/orders. These exclusions are mandatory.
 *
 * @package flavor_edge_cache
 */

defined( 'ABSPATH' ) || exit;

$paths = array(
	// Query-string based actions (always present).
	'/*?add-to-cart=*',
	'/*?remove_item=*',
	'/*?wc-ajax=*',
	'/*?empty-cart*',
	'/*?pay_for_order=*',
);

// Dynamic paths from WooCommerce page settings.
if ( function_exists( 'wc_get_cart_url' ) ) {
	$paths[] = untrailingslashit( wp_parse_url( wc_get_cart_url(), PHP_URL_PATH ) ) . '*';
}
if ( function_exists( 'wc_get_checkout_url' ) ) {
	$paths[] = untrailingslashit( wp_parse_url( wc_get_checkout_url(), PHP_URL_PATH ) ) . '*';
}
if ( function_exists( 'wc_get_page_permalink' ) ) {
	$account_url = wc_get_page_permalink( 'myaccount' );
	if ( $account_url ) {
		$paths[] = untrailingslashit( wp_parse_url( $account_url, PHP_URL_PATH ) ) . '*';
	}
}

return $paths;
