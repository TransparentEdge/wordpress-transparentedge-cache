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
	 * Whether runtime hooks have already been registered (guards against
	 * double-registration). (T2.11)
	 *
	 * @var bool
	 */
	private static $hooks_registered = false;

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
			'html_swr'              => 60,      // stale-while-revalidate (was hardcoded).
			'html_sie'              => 86400,   // stale-if-error airbag (was hardcoded).
			// Front-page cache profile (v1.6.0). Lets the plugin own the homepage
			// TTL instead of depending on a hand-written VCL branch. Off by default
			// → the homepage keeps using the global HTML profile above, so existing
			// installs see no behaviour change until it's switched on.
			'front_page_cache_override' => false,
			'front_page_max_age'        => 30,     // browser: breaking-news fresh.
			'front_page_s_maxage'       => 300,    // edge fresh window (news home).
			'front_page_swr'            => 300,    // serve stale instantly, revalidate behind.
			'front_page_sie'            => 86400,  // airbag: serve day-old home on origin 5xx.
			// Origin page cache (v1.6.0): serves rendered anonymous HTML before
			// WordPress boots, so a CDN miss does NOT regenerate WordPress.
			'page_cache_enabled'    => false,
			'page_cache_backend'    => 'disk',   // 'disk' | 'redis' | 'memcached'.
			'object_cache_auto'     => true,     // auto-install the object-cache drop-in when a live memory backend exists.
			'page_cache_ttl'        => 300,      // seconds; fallback store for edge misses.
			'static_s_maxage'       => 2592000,  // 30 days in Varnish.
			'static_max_age'        => 86400,    // 1 day in browser.
			'surrogate_keys'        => true,
			'vary_device'           => false,   // Opt-in. The plugin serves the same HTML to all devices (device split is done at the edge / by CSS), so Vary: X-Device only duplicates cache objects without a real variant.
			'vary_language'         => false,   // Opt-in. Only needed if the origin returns different content for the SAME URL by Accept-Language (rare). WPML/Polylang put the language in the URL, so it is not needed there.

			// Invalidation module.
			'invalidation_enabled'  => true,
			'invalidation_method'   => 'soft',   // 'soft' (default) or 'hard'.
			'refetch_enabled'       => true,     // Master switch: warm the cache after an automated purge.
			'warmup_method'         => 'refetch', // How to warm: 'refetch' (platform, all nodes, origin-aggregated — default) or 'http' (origin-side GET fallback).
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

			// Preload (bulk sitemap warm after a full purge).
			'preload_sitemap'       => true,      // Opt-in: warm the whole sitemap after a full purge.
			'preload_method'        => 'refetch', // 'refetch' (evict+repopulate, origin-aggregated — default, origin-safe) or 'prefetch' (populate without evicting, but fans out ~1 origin fetch/node — only for strong origins).
			'preload_autotune'      => true,      // Adaptive pacer: ramp batch size up while the origin is fast and disk/backend healthy, halve it on rate-limit, slow origin or low disk. Guardrails (health gate, backoff, off-peak) are unchanged.
			'preload_health_gate'   => true,      // Probe origin health before each batch; pause and back off if the origin is slow or erroring.
			'preload_offpeak'       => false,     // Restrict bulk preload to an off-peak window (site timezone).
			'preload_offpeak_start' => 1,         // Off-peak window start hour (0-23).
			'preload_offpeak_end'   => 6,         // Off-peak window end hour (0-23).

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
			'query_normalize'       => false,    // Opt-in. Serve from cache ignoring UNKNOWN query params (keeps only the allowlist). Closes the "?zz=1 busts the cache" vector. OFF by default: only safe where every content-varying param is in the allowlist (unsafe on faceted / WooCommerce-filter sites).
			'allowed_query_strings' => '',       // One per line. Extra params (beyond WP core) that DO change the page and must keep their own cache entry. Only used when query_normalize is on.
			'variant_header'        => '',       // Opt-in. Name of the edge-set request header (e.g. X-Accept-Markdown) that selects an alternate cached representation. Empty = off. REQUIRES the edge to strip any client-supplied copy and set it itself.

			// Heartbeat control.
			'heartbeat_behavior'       => 'reduce',   // 'default', 'reduce', 'disable_everywhere'.
			'heartbeat_disable_admin'  => true,        // Disable in admin (except editor).
			'heartbeat_disable_editor' => false,       // Disable in post editor (risky).
			'heartbeat_interval'       => 60,          // Seconds (15-120). Default WP is 15-60.

			// Editorial write controls (opt-in; off = WordPress defaults untouched).
			'editorial_tune'       => false,       // Master opt-in for the two controls below.
			'editorial_revisions'  => -1,          // -1 leave alone, 0 off, N keep N revisions.
			'editorial_autosave'   => 0,           // 0 leave WP default (60s), else seconds (clamped 60-600).

			// Speculation Rules.
			'speculation_enabled'    => false,           // Default off for upgrades; on for new installs via wizard.
			'speculation_mode'       => 'balanced',      // 'conservative', 'balanced', 'aggressive'.
			'speculation_post_types' => array( 'post', 'page' ),
			'speculation_injection'  => 'inline',        // 'inline' (<head>, no endpoint), 'php' (header→REST), 'vcl' (edge).

			// Remove Unused CSS (v1.5.0).
			'remove_unused_css'      => false,
			'ucss_exclusions'        => '',

			// Image optimization extras (v1.5.0).
			'lazyload_bg_images'     => false,

			// Mobile cache (v1.5.0).
			'mobile_cache'           => false,

			// Security: vulnerability scanner (v1.6.0).

			// Security: local hardening (v1.6.0).
			'harden_disable_xmlrpc'     => false,
			'harden_limit_login'        => false,
			'harden_login_max_attempts' => 5,
			'harden_block_php_uploads'  => false,

			// Security: headers (v1.6.0). VCL recommender + optional PHP fallback.
			'sec_headers_php_fallback'     => false,
			'sec_header_hsts'              => false,
			'sec_header_hsts_maxage'       => 31536000,
			'sec_header_hsts_subdomains'   => false,
			'sec_header_hsts_preload'      => false,
			'sec_header_nosniff'           => true,
			'sec_header_referrer'          => true,
			'sec_header_referrer_value'    => 'strict-origin-when-cross-origin',
			'sec_header_permissions'       => false,
			'sec_header_permissions_value' => 'geolocation=(), microphone=(), camera=()',
			'sec_header_frame'             => true,
			'sec_header_frame_value'       => 'SAMEORIGIN',
			'sec_header_csp'               => false,
			'sec_header_csp_value'         => '',
			'sec_header_csp_enforce'       => false,
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
	 * Register runtime hooks once. On multisite, the memoized $settings cache
	 * belongs to one blog; switch_to_blog()/restore_current_blog() must reset it
	 * so the next get_all() reloads the active blog's settings. (T2.11)
	 */
	public static function init() {
		if ( self::$hooks_registered ) {
			return;
		}
		self::$hooks_registered = true;

		if ( function_exists( 'is_multisite' ) && is_multisite() ) {
			add_action( 'switch_blog', array( __CLASS__, 'flush_cache' ) );
		}
	}

	/**
	 * Flush the in-memory settings cache so the next get_all() reloads from the
	 * database. Hooked to switch_blog on multisite. (T2.11)
	 */
	public static function flush_cache() {
		self::$settings = null;
	}

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
			$needs_save       = false;
			$creds_unreadable = false;
			foreach ( self::ENCRYPTED_FIELDS as $field ) {
				if ( ! empty( self::$settings[ $field ] ) ) {
					if ( 0 === strpos( self::$settings[ $field ], self::ENC_PREFIX ) ) {
						// Encrypted — decrypt for runtime use.
						$decrypted = self::decrypt( self::$settings[ $field ] );
						// (T2.16) An encrypted credential that decrypts to '' means the
						// WP salts changed (AUTH_KEY/SECURE_AUTH_SALT rotated); the plugin
						// would otherwise lose its connection silently. Flag it so an
						// admin notice (rendered elsewhere) can warn about it.
						if ( '' === $decrypted ) {
							$creds_unreadable = true;
						}
						self::$settings[ $field ] = $decrypted;
					} else {
						// Legacy plaintext — flag for migration.
						$needs_save = true;
					}
				}
			}

			// (T2.16) Surface or clear the "credentials unreadable" flag, only when it
			// actually changes, to avoid needless option writes on every load.
			$flag_set = (bool) get_option( 'flavor_edge_creds_unreadable' );
			if ( $creds_unreadable && ! $flag_set ) {
				update_option( 'flavor_edge_creds_unreadable', true );
			} elseif ( ! $creds_unreadable && $flag_set ) {
				delete_option( 'flavor_edge_creds_unreadable' );
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

		// (T2.16) Credentials were just (re)written with the current encryption key,
		// so they are readable again — clear any stale "unreadable" flag.
		delete_option( 'flavor_edge_creds_unreadable' );
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

		// Multilingual plugins present: still do NOT enable Vary: Accept-Language (URL carries the language).
		if ( defined( 'ICL_SITEPRESS_VERSION' ) || defined( 'POLYLANG_VERSION' ) ) {
			$settings['vary_language'] = false; // never force-on: WPML/Polylang carry the language in the URL, so Accept-Language only fragments the cache.
		}

		self::save( $settings );
	}

	// -------------------------------------------------------------------------
	// Credential encryption (AES-256-CBC).
	// -------------------------------------------------------------------------

	/**
	 * Public wrapper around encrypt() so sibling modules (e.g. TE_Multisite) can
	 * protect credentials with the exact same scheme. (T2.13)
	 *
	 * @param string $plaintext Value to encrypt.
	 * @return string Encrypted value with ENC_PREFIX, or original on failure.
	 */
	public static function encrypt_value( $plaintext ) {
		// Idempotent: never double-encrypt a value that is already encrypted (a
		// network-settings form may resubmit the stored, already-prefixed value).
		if ( is_string( $plaintext ) && 0 === strpos( $plaintext, self::ENC_PREFIX ) ) {
			return $plaintext;
		}
		return self::encrypt( $plaintext );
	}

	/**
	 * Public wrapper around decrypt(). Returns '' on failure. (T2.13)
	 *
	 * @param string $stored Encrypted value with ENC_PREFIX.
	 * @return string Decrypted plaintext.
	 */
	public static function decrypt_value( $stored ) {
		return self::decrypt( $stored );
	}

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

// Register the multisite settings-cache flush hook once at load. (T2.11)
TE_Settings::init();
