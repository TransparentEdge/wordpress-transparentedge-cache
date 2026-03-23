<?php
/**
 * WooCommerce Integration module.
 *
 * Handles cache exclusions, Surrogate-Keys for products/categories,
 * automatic purge on product/order/coupon changes, and cart/checkout
 * fragment handling.
 *
 * @package flavor_edge_cache
 */

namespace flavor_edge;

defined( 'ABSPATH' ) || exit;

class TE_WooCommerce {

	/**
	 * Initialize WooCommerce integration.
	 * Only loads if WooCommerce is active.
	 */
	public static function init() {
		if ( ! class_exists( 'WooCommerce' ) ) {
			return;
		}

		if ( ! TE_Settings::get( 'enabled' ) ) {
			return;
		}

		// Additional Surrogate-Keys for WooCommerce content.
		add_filter( 'flavor_edge_surrogate_keys', array( __CLASS__, 'add_woo_surrogate_keys' ) );

		// Exclude WooCommerce dynamic pages and AJAX from cache.
		add_filter( 'flavor_edge_is_uncacheable', array( __CLASS__, 'check_woo_uncacheable' ) );

		// Product lifecycle hooks.
		add_action( 'woocommerce_update_product', array( __CLASS__, 'on_product_save' ), 10, 1 );
		add_action( 'woocommerce_new_product', array( __CLASS__, 'on_product_save' ), 10, 1 );

		// Stock changes (already in TE_Invalidation, but we add more granularity here).
		add_action( 'woocommerce_product_set_stock_status', array( __CLASS__, 'on_stock_status_change' ), 10, 3 );

		// Order completion affects stock and potentially product visibility.
		add_action( 'woocommerce_order_status_completed', array( __CLASS__, 'on_order_completed' ) );
		add_action( 'woocommerce_order_status_cancelled', array( __CLASS__, 'on_order_cancelled' ) );
		add_action( 'woocommerce_order_status_refunded', array( __CLASS__, 'on_order_refunded' ) );

		// Coupon changes (can affect displayed prices with auto-apply coupons).
		add_action( 'woocommerce_coupon_options_save', array( __CLASS__, 'on_coupon_save' ), 10, 2 );

		// Product review (comment on product).
		add_action( 'comment_post', array( __CLASS__, 'on_product_review' ), 10, 3 );

		// Ensure WooCommerce AJAX and REST cart operations are never cached.
		add_action( 'woocommerce_add_to_cart', array( __CLASS__, 'prevent_cache_on_cart_action' ) );

		// Sale price schedule (products going on/off sale).
		add_action( 'woocommerce_scheduled_sales', array( __CLASS__, 'on_scheduled_sales' ) );
	}

	/**
	 * Add WooCommerce-specific Surrogate-Keys.
	 *
	 * @param array $keys Existing keys.
	 * @return array
	 */
	public static function add_woo_surrogate_keys( $keys ) {
		if ( ! function_exists( 'is_shop' ) ) {
			return $keys;
		}

		// Shop page.
		if ( is_shop() ) {
			$keys[] = 'woo-shop';
		}

		// Product page.
		if ( is_product() ) {
			$product = wc_get_product( get_the_ID() );
			if ( $product ) {
				$keys[] = 'woo-product-' . $product->get_id();

				// Product categories.
				$cat_ids = $product->get_category_ids();
				foreach ( $cat_ids as $cat_id ) {
					$keys[] = 'woo-cat-' . $cat_id;
				}

				// Product tags.
				$tag_ids = $product->get_tag_ids();
				foreach ( $tag_ids as $tag_id ) {
					$keys[] = 'woo-tag-' . $tag_id;
				}

				// On sale marker.
				if ( $product->is_on_sale() ) {
					$keys[] = 'woo-on-sale';
				}

				// Stock status.
				$keys[] = 'woo-stock-' . $product->get_stock_status();
			}
		}

		// Product category archive.
		if ( is_product_category() ) {
			$term = get_queried_object();
			if ( $term ) {
				$keys[] = 'woo-cat-' . $term->term_id;
			}
		}

		// Product tag archive.
		if ( is_product_tag() ) {
			$term = get_queried_object();
			if ( $term ) {
				$keys[] = 'woo-tag-' . $term->term_id;
			}
		}

		return $keys;
	}

	/**
	 * Check if the current request is a WooCommerce dynamic page that should not be cached.
	 *
	 * @param bool $is_uncacheable Current uncacheable status.
	 * @return bool
	 */
	public static function check_woo_uncacheable( $is_uncacheable ) {
		if ( $is_uncacheable ) {
			return true;
		}

		$s = TE_Settings::get_all();

		// Cart page.
		if ( $s['woo_exclude_cart'] && function_exists( 'is_cart' ) && is_cart() ) {
			return true;
		}

		// Checkout page.
		if ( $s['woo_exclude_checkout'] && function_exists( 'is_checkout' ) && is_checkout() ) {
			return true;
		}

		// My Account page.
		if ( $s['woo_exclude_account'] && function_exists( 'is_account_page' ) && is_account_page() ) {
			return true;
		}

		// WooCommerce AJAX endpoints.
		if ( isset( $_GET['wc-ajax'] ) ) {
			return true;
		}

		// Order received / thank you page.
		if ( function_exists( 'is_order_received_page' ) && is_order_received_page() ) {
			return true;
		}

		return false;
	}

	// -------------------------------------------------------------------------
	// Product lifecycle.
	// -------------------------------------------------------------------------

	/**
	 * Handle product save/update.
	 *
	 * @param int $product_id Product ID.
	 */
	public static function on_product_save( $product_id ) {
		$product = wc_get_product( $product_id );
		if ( ! $product ) {
			return;
		}

		self::purge_product_and_related( $product );
	}

	/**
	 * Handle stock status change.
	 *
	 * @param int        $product_id  Product ID.
	 * @param string     $stock_status New stock status.
	 * @param \WC_Product $product     Product object.
	 */
	public static function on_stock_status_change( $product_id, $stock_status, $product ) {
		self::purge_product_and_related( $product );
	}

	/**
	 * Handle order completion (stock reduced).
	 *
	 * @param int $order_id Order ID.
	 */
	public static function on_order_completed( $order_id ) {
		self::purge_order_products( $order_id );
	}

	/**
	 * Handle order cancellation (stock restored).
	 *
	 * @param int $order_id Order ID.
	 */
	public static function on_order_cancelled( $order_id ) {
		self::purge_order_products( $order_id );
	}

	/**
	 * Handle order refund (stock restored).
	 *
	 * @param int $order_id Order ID.
	 */
	public static function on_order_refunded( $order_id ) {
		self::purge_order_products( $order_id );
	}

	/**
	 * Handle coupon save.
	 *
	 * @param int      $post_id Coupon post ID.
	 * @param \WC_Coupon $coupon  Coupon object.
	 */
	public static function on_coupon_save( $post_id, $coupon ) {
		// If coupon affects all products, purge shop.
		TE_Invalidation::queue_tags( array( 'woo-shop', 'woo-on-sale' ) );
	}

	/**
	 * Handle product review (comment on a product).
	 *
	 * @param int    $comment_id       Comment ID.
	 * @param string $comment_approved Approval status.
	 * @param array  $commentdata      Comment data.
	 */
	public static function on_product_review( $comment_id, $comment_approved, $commentdata ) {
		if ( 1 !== $comment_approved && '1' !== $comment_approved ) {
			return;
		}

		$comment = get_comment( $comment_id );
		if ( ! $comment || ! $comment->comment_post_ID ) {
			return;
		}

		// Only handle product reviews, not regular comments.
		if ( 'product' !== get_post_type( $comment->comment_post_ID ) ) {
			return;
		}

		TE_Invalidation::queue_tags( array( 'woo-product-' . $comment->comment_post_ID ) );
	}

	/**
	 * Prevent caching when cart action occurs.
	 */
	public static function prevent_cache_on_cart_action() {
		if ( ! headers_sent() ) {
			header( 'Cache-Control: no-store, no-cache, must-revalidate, max-age=0' );
		}
	}

	/**
	 * Handle scheduled sales (products going on/off sale via cron).
	 */
	public static function on_scheduled_sales() {
		// Purge all products that were affected by the sale schedule.
		TE_Invalidation::queue_tags( array( 'woo-shop', 'woo-on-sale' ) );
	}

	// -------------------------------------------------------------------------
	// Helpers.
	// -------------------------------------------------------------------------

	/**
	 * Purge a product and all related pages (categories, tags, shop).
	 *
	 * @param \WC_Product $product Product object.
	 */
	private static function purge_product_and_related( $product ) {
		$tags = array(
			'post-' . $product->get_id(),
			'woo-product-' . $product->get_id(),
			'woo-shop',
			'front-page',
		);

		$warmup_urls = array(
			get_permalink( $product->get_id() ),
		);

		// Product categories.
		$cat_ids = $product->get_category_ids();
		foreach ( $cat_ids as $cat_id ) {
			$tags[] = 'woo-cat-' . $cat_id;
			$tags[] = 'term-' . $cat_id;

			$link = get_term_link( $cat_id, 'product_cat' );
			if ( ! is_wp_error( $link ) ) {
				$warmup_urls[] = $link;
			}
		}

		// Product tags.
		$tag_ids = $product->get_tag_ids();
		foreach ( $tag_ids as $tag_id ) {
			$tags[] = 'woo-tag-' . $tag_id;
			$tags[] = 'term-' . $tag_id;

			$link = get_term_link( $tag_id, 'product_tag' );
			if ( ! is_wp_error( $link ) ) {
				$warmup_urls[] = $link;
			}
		}

		// Shop page warm-up.
		$shop_id = function_exists( 'wc_get_page_id' ) ? wc_get_page_id( 'shop' ) : 0;
		if ( $shop_id > 0 ) {
			$warmup_urls[] = get_permalink( $shop_id );
		}

		// If product is a variation, also purge parent.
		if ( $product->is_type( 'variation' ) ) {
			$parent_id = $product->get_parent_id();
			if ( $parent_id ) {
				$tags[]        = 'post-' . $parent_id;
				$tags[]        = 'woo-product-' . $parent_id;
				$warmup_urls[] = get_permalink( $parent_id );
			}
		}

		TE_Invalidation::queue_tags( $tags );

		// Queue warm-up URLs via the public API.
		if ( TE_Settings::get( 'refetch_enabled' ) ) {
			// Use a lightweight direct warm-up (limited to 10 URLs for product changes).
			$warmup_urls = array_slice( array_unique( array_filter( $warmup_urls ) ), 0, 10 );
			foreach ( $warmup_urls as $url ) {
				TE_Invalidation::queue_urls( array( $url ) );
			}
		}
	}

	/**
	 * Purge all products in an order.
	 *
	 * @param int $order_id Order ID.
	 */
	private static function purge_order_products( $order_id ) {
		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			return;
		}

		$tags        = array();
		$warmup_urls = array();

		foreach ( $order->get_items() as $item ) {
			$product_id = $item->get_product_id();
			if ( $product_id ) {
				$tags[]        = 'post-' . $product_id;
				$tags[]        = 'woo-product-' . $product_id;
				$warmup_urls[] = get_permalink( $product_id );

				// Also purge product categories.
				$product = wc_get_product( $product_id );
				if ( $product ) {
					foreach ( $product->get_category_ids() as $cat_id ) {
						$tags[] = 'woo-cat-' . $cat_id;
						$link   = get_term_link( $cat_id, 'product_cat' );
						if ( ! is_wp_error( $link ) ) {
							$warmup_urls[] = $link;
						}
					}
				}
			}
		}

		if ( ! empty( $tags ) ) {
			$tags[] = 'woo-shop';
			TE_Invalidation::queue_tags( array_unique( $tags ) );
		}

		// Warm-up (limited).
		if ( TE_Settings::get( 'refetch_enabled' ) && ! empty( $warmup_urls ) ) {
			$warmup_urls = array_slice( array_unique( array_filter( $warmup_urls ) ), 0, 10 );
			foreach ( $warmup_urls as $url ) {
				TE_Invalidation::queue_urls( array( $url ) );
			}
		}
	}
}
