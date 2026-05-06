<?php
/**
 * Speculation Rules coordinator.
 *
 * Entry point that initializes all speculation sub-modules.
 * Controlled by the feature flag FLAVOR_EDGE_SPECULATION_RULES_ENABLED
 * and the user setting 'speculation_enabled'.
 *
 * @package flavor_edge_cache
 */

namespace flavor_edge;

defined( 'ABSPATH' ) || exit;

class TE_Speculation_Rules {

	/**
	 * Initialize the Speculation Rules module.
	 */
	public static function init() {
		// Feature flag — allows merging code without exposing the feature.
		if ( defined( 'FLAVOR_EDGE_SPECULATION_RULES_ENABLED' ) && ! FLAVOR_EDGE_SPECULATION_RULES_ENABLED ) {
			return;
		}

		$settings = TE_Settings::get_all();

		if ( empty( $settings['speculation_enabled'] ) ) {
			// Even if disabled, register admin UI so user can enable.
			if ( is_admin() ) {
				add_action( 'admin_init', array( __CLASS__, 'register_admin_assets' ) );
			}
			return;
		}

		// Register REST endpoint.
		add_action( 'rest_api_init', array( 'flavor_edge\\TE_Rules_Rest_Controller', 'register' ) );

		// Header injection (PHP fallback — skipped if VCL injection selected).
		TE_Header_Injector::init();

		// Cache invalidation hooks.
		TE_Rules_Cache_Invalidator::init();

		// Admin assets.
		if ( is_admin() ) {
			add_action( 'admin_init', array( __CLASS__, 'register_admin_assets' ) );
		}
	}

	/**
	 * Register admin CSS/JS for the Speculation Rules tab.
	 */
	public static function register_admin_assets() {
		// Assets are enqueued only on the plugin's admin page.
		// See TE_Admin::enqueue_admin_assets().
	}

	/**
	 * Check if Speculation Rules is available and enabled.
	 *
	 * @return bool
	 */
	public static function is_enabled() {
		if ( defined( 'FLAVOR_EDGE_SPECULATION_RULES_ENABLED' ) && ! FLAVOR_EDGE_SPECULATION_RULES_ENABLED ) {
			return false;
		}
		return (bool) TE_Settings::get( 'speculation_enabled' );
	}

	/**
	 * Check if the feature flag allows the feature to be shown.
	 *
	 * @return bool
	 */
	public static function is_available() {
		if ( defined( 'FLAVOR_EDGE_SPECULATION_RULES_ENABLED' ) && ! FLAVOR_EDGE_SPECULATION_RULES_ENABLED ) {
			return false;
		}
		return true;
	}
}
