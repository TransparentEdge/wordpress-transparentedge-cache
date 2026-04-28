<?php
/**
 * Settings management for Transparent Edge Cache.
 *
 * @package flavor_edge_cache
 */

namespace flavor_edge;

defined( 'ABSPATH' ) || exit;

class TE_Settings {

	/**
	 * Option key in wp_options.
	 */
	const OPTION_KEY = 'flavor_edge_settings';

	/**
	 * Cached settings.
	 *
	 * @var array|null
	 */
	private static $settings = null;

	/**
	 * Return default settings.
	 *
	 * @return array
	 */
	public static function defaults() {
		return array(
			// Connection.
			'company_id'            => '',
			'client_id'             => '',
			'client_secret'         => '',
			'connected'             => false,

			// Global toggle.
			'enabled'               => true,

			// Headers module.
			'headers_enabled'       => true,
			'html_s_maxage'         => 172800,  // 48h in Varnish.
			'html_max_age'          => 3600,    // 1h in browser.
			'static_s_maxage'       => 2592000,  // 30 days in Varnish.
			'static_max_age'        => 86400,    // 1 day in browser.
			'surrogate_keys'        => true,
			'vary_device'           => true,
			'vary_language'         => false,    // Auto-enabled when WPML/Polylang detected.

			// Invalidation module.
			'invalidation_enabled'  => true,
			'invalidation_method'   => 'soft',   // 'soft' (default) or 'hard'.
			'refetch_enabled'       => true,
			'purge_on_post'         => true,
			'purge_on_comment'      => true,
			'purge_on_menu'         => true,
			'purge_on_widget'       => true,
			'purge_on_theme_switch' => true,
			'purge_on_plugin_change' => true,
			'debounce_seconds'      => 2,

			// i3 Image optimization.
			'i3_enabled'            => false,
			'i3_auto_webp'          => true,
			'i3_quality_jpeg'       => 80,
			'i3_quality_webp'       => 80,
			'i3_max_length'         => '',       // e.g., '1m' for 1MB.
			'i3_s_maxage'           => 2592000,  // 30 days.
			'i3_max_age'            => 86400,    // 1 day.

			// Frontend optimization.
			'minify_html'           => false,
			'minify_css'            => false,
			'minify_js'             => false,
			'combine_css'           => false,
			'combine_js'            => false,
			'combine_js_exclusions' => '',
			'delay_js'              => false,
			'delay_js_exclusions'   => '',
			'defer_js'              => false,
			'defer_js_exclusions'   => '',
			'lazyload_images'       => true,
			'lazyload_iframes'      => true,
			'preload_lcp'           => false,
			'selfhost_google_fonts' => false,
			'dns_prefetch_urls'     => '',

			// Preload.
			'preload_sitemap'       => true,      // Warm Varnish after full purge via sitemap.

			// WooCommerce.
			'woo_exclude_cart'      => true,
			'woo_exclude_checkout'  => true,
			'woo_exclude_account'   => true,
			'woo_purge_stock'       => true,

			// Advanced.
			'excluded_urls'         => '',       // One per line, regex supported.
			'excluded_cookies'      => "wordpress_logged_in_\nwoocommerce_items_in_cart",
			'debug_mode'            => false,
			'accepted_query_strings' => "utm_source\nutm_medium\nutm_campaign\nutm_content\nutm_term\nutm_id\nfbclid\ngclid\ngclsrc\nmsclkid\nmc_cid\nmc_eid\n_ga\n_gl\nref",

			// Heartbeat control.
			'heartbeat_behavior'       => 'reduce',   // 'default', 'reduce', 'disable_everywhere'.
			'heartbeat_disable_admin'  => true,        // Disable in admin (except editor).
			'heartbeat_disable_editor' => false,       // Disable in post editor (risky).
			'heartbeat_interval'       => 60,          // Seconds (15-120). Default WP is 15-60.
		);
	}

	/**
	 * Credential fields that should be encrypted at rest.
	 */
	const ENCRYPTED_FIELDS = array( 'client_id', 'client_secret' );

	/**
	 * Encryption prefix to identify encrypted values.
	 */
	const ENC_PREFIX = 'enc:';

	/**
	 * Get all settings.
	 *
	 * @param bool $force_refresh Force reload from database.
	 * @return array
	 */
	public static function get_all( $force_refresh = false ) {
		if ( null === self::$settings || $force_refresh ) {
			$saved          = get_option( self::OPTION_KEY, array() );
			self::$settings = wp_parse_args( $saved, self::defaults() );

			// Decrypt credentials in memory, auto-migrate unencrypted values.
			$needs_save = false;
			foreach ( self::ENCRYPTED_FIELDS as $field ) {
				if ( ! empty( self::$settings[ $field ] ) ) {
					if ( 0 === strpos( self::$settings[ $field ], self::ENC_PREFIX ) ) {
						// Encrypted — decrypt for runtime use.
						self::$settings[ $field ] = self::decrypt( self::$settings[ $field ] );
					} else {
						// Legacy plaintext — flag for migration.
						$needs_save = true;
					}
				}
			}

			// Auto-encrypt legacy plaintext credentials.
			if ( $needs_save ) {
				self::save( self::$settings );
			}
		}
		return self::$settings;
	}

	/**
	 * Get a single setting.
	 *
	 * @param string $key     Setting key.
	 * @param mixed  $default Default value.
	 * @return mixed
	 */
	public static function get( $key, $default = null ) {
		$settings = self::get_all();
		if ( array_key_exists( $key, $settings ) ) {
			return $settings[ $key ];
		}
		return $default;
	}

	/**
	 * Update a single setting.
	 *
	 * @param string $key   Setting key.
	 * @param mixed  $value Setting value.
	 */
	public static function set( $key, $value ) {
		$settings         = self::get_all();
		$settings[ $key ] = $value;
		self::save( $settings );
	}

	/**
	 * Save all settings.
	 * Encrypts sensitive credentials before writing to the database.
	 *
	 * @param array $settings Full settings array.
	 */
	public static function save( $settings ) {
		// Keep plaintext in memory cache.
		self::$settings = $settings;

		// Encrypt credentials before writing to DB.
		$to_store = $settings;
		foreach ( self::ENCRYPTED_FIELDS as $field ) {
			if ( ! empty( $to_store[ $field ] ) && 0 !== strpos( $to_store[ $field ], self::ENC_PREFIX ) ) {
				$to_store[ $field ] = self::encrypt( $to_store[ $field ] );
			}
		}

		update_option( self::OPTION_KEY, $to_store );
	}

	/**
	 * Check if plugin is properly connected to TE API.
	 *
	 * @return bool
	 */
	public static function is_connected() {
		$s = self::get_all();
		return ! empty( $s['company_id'] ) && ! empty( $s['client_id'] ) && ! empty( $s['client_secret'] ) && $s['connected'];
	}

	/**
	 * Check if a module is enabled.
	 *
	 * @param string $module Module name.
	 * @return bool
	 */
	public static function is_module_enabled( $module ) {
		if ( ! self::get( 'enabled' ) ) {
			return false;
		}

		switch ( $module ) {
			case 'headers':
				return self::get( 'headers_enabled' );
			case 'invalidation':
				return self::is_connected() && self::get( 'invalidation_enabled' );
			case 'i3':
				return self::is_connected() && self::get( 'i3_enabled' );
			default:
				return false;
		}
	}

	/**
	 * Detect site type and auto-configure.
	 *
	 * @param string $site_type One of: blog, corporate, woocommerce, membership.
	 */
	public static function auto_configure( $site_type ) {
		$settings = self::get_all();

		switch ( $site_type ) {
			case 'woocommerce':
				$settings['woo_exclude_cart']     = true;
				$settings['woo_exclude_checkout'] = true;
				$settings['woo_exclude_account']  = true;
				$settings['woo_purge_stock']      = true;
				$settings['html_s_maxage']        = 3600;     // 1h: ecommerce content changes more.
				$settings['excluded_cookies']     = "wordpress_logged_in_\nwoocommerce_items_in_cart\nwoocommerce_cart_hash\nwp_woocommerce_session_";
				break;

			case 'membership':
				$settings['html_s_maxage']   = 3600;
				$settings['excluded_cookies'] = "wordpress_logged_in_\nwp_woocommerce_session_";
				break;

			case 'blog':
				$settings['html_s_maxage'] = 86400; // 24h: blogs don't change that fast.
				$settings['minify_html']   = true;
				$settings['delay_js']      = true;
				break;

			case 'corporate':
			default:
				$settings['html_s_maxage'] = 86400;
				$settings['minify_html']   = true;
				break;
		}

		// Auto-detect multilingual plugins.
		if ( defined( 'ICL_SITEPRESS_VERSION' ) || defined( 'POLYLANG_VERSION' ) ) {
			$settings['vary_language'] = true;
		}

		self::save( $settings );
	}

	// -------------------------------------------------------------------------
	// Credential encryption (AES-256-CBC).
	// -------------------------------------------------------------------------

	/**
	 * Encrypt a value for storage.
	 *
	 * @param string $plaintext Value to encrypt.
	 * @return string Encrypted value with prefix, or original if encryption unavailable.
	 */
	private static function encrypt( $plaintext ) {
		if ( ! function_exists( 'openssl_encrypt' ) ) {
			return $plaintext; // Graceful fallback — store plaintext.
		}

		$key = self::get_encryption_key();
		$iv  = openssl_random_pseudo_bytes( 16 );

		$encrypted = openssl_encrypt( $plaintext, 'aes-256-cbc', $key, OPENSSL_RAW_DATA, $iv );
		if ( false === $encrypted ) {
			return $plaintext;
		}

		// Format: enc:base64(iv + ciphertext)
		return self::ENC_PREFIX . base64_encode( $iv . $encrypted );
	}

	/**
	 * Decrypt a stored value.
	 *
	 * @param string $stored Encrypted value with prefix.
	 * @return string Decrypted plaintext, or empty string on failure.
	 */
	private static function decrypt( $stored ) {
		if ( ! function_exists( 'openssl_decrypt' ) ) {
			// Can't decrypt — return without prefix as best effort.
			return substr( $stored, strlen( self::ENC_PREFIX ) );
		}

		$key  = self::get_encryption_key();
		$data = base64_decode( substr( $stored, strlen( self::ENC_PREFIX ) ) );

		if ( false === $data || strlen( $data ) < 17 ) {
			return ''; // Corrupted.
		}

		$iv         = substr( $data, 0, 16 );
		$ciphertext = substr( $data, 16 );

		$decrypted = openssl_decrypt( $ciphertext, 'aes-256-cbc', $key, OPENSSL_RAW_DATA, $iv );

		return ( false !== $decrypted ) ? $decrypted : '';
	}

	/**
	 * Derive the encryption key from WordPress salts.
	 * Uses AUTH_KEY + SECURE_AUTH_SALT (unique per installation).
	 *
	 * @return string 32-byte key.
	 */
	private static function get_encryption_key() {
		$material = ( defined( 'AUTH_KEY' ) ? AUTH_KEY : 'flavor-edge-default' )
		          . ( defined( 'SECURE_AUTH_SALT' ) ? SECURE_AUTH_SALT : 'fallback-salt' );
		return hash( 'sha256', $material, true ); // 32 bytes for AES-256.
	}
}
