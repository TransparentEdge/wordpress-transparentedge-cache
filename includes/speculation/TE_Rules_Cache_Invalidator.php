<?php
/**
 * Coordinates invalidation of the Speculation Rules JSON.
 *
 * Listens for events that change the rules (plugin activation,
 * permalink change, WC settings, etc.) and triggers:
 * 1. Transient cache deletion (forces regeneration on next request).
 * 2. Surrogate-key purge on the edge (removes the cached JSON from CDN).
 *
 * @package flavor_edge_cache
 */

namespace flavor_edge;

defined( 'ABSPATH' ) || exit;

class TE_Rules_Cache_Invalidator {

	/**
	 * Register hooks that trigger invalidation.
	 */
	public static function init() {
		if ( empty( TE_Settings::get( 'speculation_enabled' ) ) ) {
			return;
		}

		// Permalink structure changed.
		add_action( 'update_option_permalink_structure', array( __CLASS__, 'invalidate' ) );

		// Plugin activated or deactivated (exclusion set changes).
		add_action( 'activated_plugin', array( __CLASS__, 'invalidate' ) );
		add_action( 'deactivated_plugin', array( __CLASS__, 'invalidate' ) );

		// Theme changed.
		add_action( 'switch_theme', array( __CLASS__, 'invalidate' ) );

		// Navigation menu updated.
		add_action( 'wp_update_nav_menu', array( __CLASS__, 'invalidate' ) );

		// Plugin settings saved (mode, post types, etc.).
		add_action( 'flavor_edge_settings_saved', array( __CLASS__, 'invalidate' ) );

		// WooCommerce settings saved (cart/checkout page IDs may change).
		add_action( 'woocommerce_settings_saved', array( __CLASS__, 'invalidate' ) );
	}

	/**
	 * Invalidate the speculation rules cache.
	 * Clears the local transient and purges the edge cache.
	 */
	public static function invalidate() {
		// 1. Clear local transient (forces regeneration on next request).
		TE_Rules_Rest_Controller::invalidate_cache();

		// 2. Purge the JSON from edge via Surrogate-Key.
		if ( TE_Settings::is_connected() ) {
			$tag = 'speculation-rules-' . get_current_blog_id();
			TE_Api::purge_tags( array( $tag ), true );
		}

		/**
		 * Fires after the speculation rules JSON has been invalidated.
		 *
		 * @param int $blog_id Current blog ID.
		 */
		do_action( 'flavor_edge_speculation_invalidated', get_current_blog_id() );
	}
}
