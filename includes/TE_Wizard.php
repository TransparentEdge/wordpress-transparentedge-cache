<?php
/**
 * Setup Wizard for first-time configuration.
 *
 * Detects site type (blog, corporate, WooCommerce, membership),
 * auto-configures optimal defaults, and guides the user through
 * API connection.
 *
 * @package flavor_edge_cache
 */

namespace flavor_edge;

defined( 'ABSPATH' ) || exit;

class TE_Wizard {

	/**
	 * Check if the wizard should be shown.
	 *
	 * @return bool
	 */
	public static function should_show() {
		return ! TE_Settings::is_connected() && ! get_option( 'flavor_edge_wizard_dismissed' );
	}

	/**
	 * Dismiss the wizard.
	 */
	public static function dismiss() {
		update_option( 'flavor_edge_wizard_dismissed', true );
	}

	/**
	 * Detect what type of site this is.
	 *
	 * @return array {
	 *   type: string,        One of: woocommerce, membership, blog, corporate
	 *   label: string,       Human-readable label
	 *   plugins: array,      Detected relevant plugins
	 *   multilingual: bool,  Whether WPML/Polylang is active
	 *   multisite: bool,     Whether this is a multisite install
	 * }
	 */
	public static function detect_site_type() {
		$detected = array(
			'type'         => 'corporate',
			'label'        => __( 'Corporate / Institutional', 'flavor-edge-cache' ),
			'plugins'      => array(),
			'multilingual' => false,
			'multisite'    => is_multisite(),
		);

		// WooCommerce.
		if ( class_exists( 'WooCommerce' ) || in_array( 'woocommerce/woocommerce.php', get_option( 'active_plugins', array() ), true ) ) {
			$detected['type']      = 'woocommerce';
			$detected['label']     = __( 'WooCommerce / Online Store', 'flavor-edge-cache' );
			$detected['plugins'][] = 'WooCommerce';
		}

		// Membership / LMS plugins.
		$membership_plugins = array(
			'memberpress/memberpress.php'                      => 'MemberPress',
			'paid-memberships-pro/paid-memberships-pro.php'    => 'PMPro',
			'restrict-content/restrictcontent.php'             => 'Restrict Content',
			'learnpress/learnpress.php'                        => 'LearnPress',
			'learndash/sfwd_lms.php'                           => 'LearnDash',
			'tutor/tutor.php'                                  => 'Tutor LMS',
			'buddypress/bp-loader.php'                         => 'BuddyPress',
			'bbpress/bbpress.php'                              => 'bbPress',
		);

		$active_plugins = get_option( 'active_plugins', array() );
		foreach ( $membership_plugins as $file => $name ) {
			if ( in_array( $file, $active_plugins, true ) ) {
				$detected['type']      = 'membership';
				$detected['label']     = __( 'Membership / LMS', 'flavor-edge-cache' );
				$detected['plugins'][] = $name;
			}
		}

		// Blog detection: if it has lots of posts and no ecommerce/membership.
		if ( 'corporate' === $detected['type'] ) {
			$post_count = wp_count_posts( 'post' );
			if ( isset( $post_count->publish ) && $post_count->publish > 20 ) {
				$detected['type']  = 'blog';
				$detected['label'] = __( 'Blog / Magazine', 'flavor-edge-cache' );
			}
		}

		// Multilingual.
		if ( defined( 'ICL_SITEPRESS_VERSION' ) ) {
			$detected['multilingual'] = true;
			$detected['plugins'][]    = 'WPML';
		}
		if ( defined( 'POLYLANG_VERSION' ) ) {
			$detected['multilingual'] = true;
			$detected['plugins'][]    = 'Polylang';
		}

		// Page builders (useful for compatibility notes).
		$builders = array(
			'elementor/elementor.php'                         => 'Elementor',
			'js_composer/js_composer.php'                      => 'WPBakery',
			'divi-builder/divi-builder.php'                    => 'Divi',
		);
		foreach ( $builders as $file => $name ) {
			if ( in_array( $file, $active_plugins, true ) ) {
				$detected['plugins'][] = $name;
			}
		}

		// Also check theme for Divi.
		$theme = wp_get_theme();
		if ( $theme && false !== strpos( strtolower( $theme->get( 'Name' ) ), 'divi' ) ) {
			$detected['plugins'][] = 'Divi Theme';
		}

		return $detected;
	}

	/**
	 * Get recommended settings for a site type.
	 *
	 * @param string $type        Site type.
	 * @param bool   $multilingual Whether multilingual is detected.
	 * @return array Key-value pairs to override in settings.
	 */
	public static function get_recommendations( $type, $multilingual = false ) {
		$rec = array(
			'enabled'              => true,
			'headers_enabled'      => true,
			'surrogate_keys'       => true,
			'vary_device'          => true,
			'invalidation_enabled' => true,
			'invalidation_method'  => 'soft',
			'refetch_enabled'      => true,
			'purge_on_post'        => true,
			'purge_on_comment'     => true,
			'purge_on_menu'        => true,
			'purge_on_widget'      => true,
			'purge_on_theme_switch' => true,
			'lazyload_images'      => true,
			'lazyload_iframes'     => true,
			'preload_lcp'          => true,
			'minify_html'          => true,
			'vary_language'        => $multilingual,
		);

		switch ( $type ) {
			case 'woocommerce':
				$rec['html_s_maxage']        = 14400;    // 4h — products change more often.
				$rec['html_max_age']         = 600;      // 10min browser.
				$rec['woo_exclude_cart']     = true;
				$rec['woo_exclude_checkout'] = true;
				$rec['woo_exclude_account']  = true;
				$rec['woo_purge_stock']      = true;
				$rec['delay_js']             = false;     // Safer off by default for ecommerce.
				$rec['excluded_cookies']     = "wordpress_logged_in_\nwoocommerce_items_in_cart\nwoocommerce_cart_hash\nwp_woocommerce_session_";
				break;

			case 'membership':
				$rec['html_s_maxage']    = 14400;     // 4h.
				$rec['html_max_age']     = 600;       // 10min.
				$rec['delay_js']         = false;
				$rec['excluded_cookies'] = "wordpress_logged_in_\nwp_woocommerce_session_";
				break;

			case 'blog':
				$rec['html_s_maxage'] = 172800;  // 48h — blog content is stable.
				$rec['html_max_age']  = 3600;    // 1h.
				$rec['delay_js']      = true;    // Safe for blogs, big INP impact.
				break;

			case 'corporate':
			default:
				$rec['html_s_maxage'] = 172800;  // 48h.
				$rec['html_max_age']  = 3600;    // 1h.
				$rec['delay_js']      = true;
				break;
		}

		return $rec;
	}

	/**
	 * Apply wizard recommendations to settings.
	 *
	 * @param string $type         Site type.
	 * @param bool   $multilingual Multilingual detected.
	 * @param array  $credentials  { company_id, client_id, client_secret }
	 * @return bool Success.
	 */
	public static function apply( $type, $multilingual, $credentials ) {
		$settings = TE_Settings::get_all();

		// Apply credentials.
		$settings['company_id']    = sanitize_text_field( $credentials['company_id'] ?? '' );
		$settings['client_id']     = sanitize_text_field( $credentials['client_id'] ?? '' );
		$settings['client_secret'] = sanitize_text_field( $credentials['client_secret'] ?? '' );
		$settings['connected']     = true;

		// Apply recommendations.
		$recommendations = self::get_recommendations( $type, $multilingual );
		$settings = array_merge( $settings, $recommendations );

		TE_Settings::save( $settings );

		// Mark wizard as completed.
		update_option( 'flavor_edge_wizard_completed', true );
		update_option( 'flavor_edge_wizard_dismissed', true );
		delete_transient( 'flavor_edge_api_token' );

		return true;
	}
}
