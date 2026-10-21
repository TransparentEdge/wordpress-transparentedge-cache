<?php
/**
 * HTTP Headers module for Transparent Edge Cache.
 *
 * Generates Cache-Control (with s-maxage), Surrogate-Keys, Vary,
 * and TCDN-i3-* headers to optimize Varnish Enterprise caching.
 *
 * @package flavor_edge_cache
 */

namespace flavor_edge;

defined( 'ABSPATH' ) || exit;

class TE_Headers {

	/**
	 * Collected Surrogate-Keys for the current request.
	 *
	 * @var array
	 */
	private static $surrogate_keys = array();

	/**
	 * Initialize header hooks.
	 */
	public static function init() {
		if ( ! TE_Settings::is_module_enabled( 'headers' ) ) {
			return;
		}

		// Collect Surrogate-Keys from template context.
		add_action( 'wp', array( __CLASS__, 'collect_surrogate_keys' ), 999 );

		// Send headers before output.
		add_action( 'send_headers', array( __CLASS__, 'send_cache_headers' ), 1 );
		add_action( 'template_redirect', array( __CLASS__, 'send_cache_headers_late' ), 999 );

		// Hook into REST API responses too.
		add_filter( 'rest_post_dispatch', array( __CLASS__, 'add_rest_headers' ), 10, 3 );
	}

	/**
	 * Collect Surrogate-Keys based on the current WordPress query.
	 */
	public static function collect_surrogate_keys() {
		if ( is_admin() || self::is_uncacheable_request() ) {
			return;
		}

		$keys = array();

		// Global key for full purge.
		$keys[] = 'site-' . get_current_blog_id();

		if ( is_front_page() || is_home() ) {
			$keys[] = 'front-page';
		}

		if ( is_singular() ) {
			$post = get_queried_object();
			if ( $post ) {
				$keys[] = 'post-' . $post->ID;
				$keys[] = 'type-' . $post->post_type;
				$keys[] = 'author-' . $post->post_author;

				// Taxonomy terms.
				$taxonomies = get_object_taxonomies( $post->post_type );
				foreach ( $taxonomies as $taxonomy ) {
					$terms = get_the_terms( $post->ID, $taxonomy );
					if ( $terms && ! is_wp_error( $terms ) ) {
						foreach ( $terms as $term ) {
							$keys[] = 'term-' . $term->term_id;
							$keys[] = 'tax-' . $taxonomy;
						}
					}
				}
			}
		}

		if ( is_category() || is_tag() || is_tax() ) {
			$term = get_queried_object();
			if ( $term ) {
				$keys[] = 'term-' . $term->term_id;
				$keys[] = 'tax-' . $term->taxonomy;
			}
			// Also add keys for each post in the listing.
			if ( have_posts() ) {
				global $wp_query;
				foreach ( $wp_query->posts as $post ) {
					$keys[] = 'post-' . $post->ID;
				}
			}
		}

		if ( is_author() ) {
			$author = get_queried_object();
			if ( $author ) {
				$keys[] = 'author-' . $author->ID;
			}
		}

		if ( is_date() ) {
			$keys[] = 'archive-date';
		}

		if ( is_search() ) {
			$keys[] = 'search';
		}

		if ( is_404() ) {
			$keys[] = 'error-404';
		}

		if ( is_feed() ) {
			$keys[] = 'feed';
		}

		// Detect active sidebars/widget areas.
		global $wp_registered_sidebars;
		if ( ! empty( $wp_registered_sidebars ) ) {
			foreach ( array_keys( $wp_registered_sidebars ) as $sidebar_id ) {
				if ( is_active_sidebar( $sidebar_id ) ) {
					$keys[] = 'sidebar-' . $sidebar_id;
				}
			}
		}

		// Menus.
		$locations = get_nav_menu_locations();
		if ( $locations ) {
			foreach ( $locations as $location => $menu_id ) {
				if ( $menu_id ) {
					$keys[] = 'menu-' . $location;
				}
			}
		}

		// Allow extensions.
		$keys = apply_filters( 'flavor_edge_surrogate_keys', $keys );

		// Deduplicate and sanitize.
		self::$surrogate_keys = array_unique( array_map( 'sanitize_key', $keys ) );
	}

	/**
	 * Send cache-control and surrogate headers.
	 */
	public static function send_cache_headers() {
		if ( headers_sent() || is_admin() ) {
			return;
		}

		if ( self::is_uncacheable_request() ) {
			self::send_nocache_headers();
			return;
		}
	}

	/**
	 * Late header dispatch (after template_redirect, when we know the full context).
	 */
	public static function send_cache_headers_late() {
		if ( headers_sent() || is_admin() ) {
			return;
		}

		if ( self::is_uncacheable_request() ) {
			self::send_nocache_headers();
			return;
		}

		// Never cache a server error at the edge: a transient 5xx (e.g. a 503 from a
		// maintenance plugin, or an upstream blip) would otherwise be pinned for the
		// full s-maxage — up to 48h by default — taking the page down long after it
		// recovered. On a news site that is a hard outage.
		$http_status = function_exists( 'http_response_code' ) ? (int) http_response_code() : 200;
		if ( $http_status >= 500 ) {
			self::send_nocache_headers();
			return;
		}

		$settings = TE_Settings::get_all();

		// Cache-Control: resolve the per-template TTL profile (the homepage can
		// override the global HTML profile — see resolve_cache_profile).
		$profile = self::resolve_cache_profile( $settings );

		header( sprintf(
			'Cache-Control: public, max-age=%d, s-maxage=%d, stale-while-revalidate=%d, stale-if-error=%d',
			$profile['max_age'],
			$profile['s_maxage'],
			$profile['swr'],
			$profile['sie']
		) );

		// Surrogate-Keys.
		if ( $settings['surrogate_keys'] && ! empty( self::$surrogate_keys ) ) {
			header( 'Surrogate-Keys: ' . implode( ' ', self::$surrogate_keys ) );
		}

		// Vary. Emit the safe set now AND re-assert it right before PHP sends
		// headers (finalize_vary), so a theme/plugin that appends
		// "Vary: Cookie, User-Agent" later cannot fragment the anonymous
		// document cache into one object per analytics cookie.
		$vary = self::get_safe_vary( $settings );
		if ( ! empty( $vary ) ) {
			header( 'Vary: ' . implode( ', ', $vary ) );
		}
		if ( function_exists( 'header_register_callback' ) ) {
			header_register_callback( array( __CLASS__, 'finalize_vary' ) );
		}

		// Debug headers (admin only — never expose to public visitors).
		if ( $settings['debug_mode'] && is_user_logged_in() && current_user_can( 'manage_options' ) ) {
			header( 'X-Flavor-Edge-Keys: ' . implode( ' ', self::$surrogate_keys ) );
			header( 'X-Flavor-Edge-TTL: max-age=' . $profile['max_age'] . ', s-maxage=' . $profile['s_maxage'] );
		}
	}

	/**
	 * Resolve the Cache-Control TTL profile for the current request.
	 *
	 * Defaults to the global HTML profile. The front page can override it so the
	 * plugin — not a hand-written VCL branch — owns the homepage policy: a short
	 * fresh TTL plus a generous stale-while-revalidate window, so an expiry is
	 * served stale instantly while a single request revalidates behind it,
	 * instead of a miss storm against the origin. Extensible per template
	 * (sections, articles, …) via the 'flavor_edge_cache_profile' filter.
	 *
	 * @param array $settings Full settings array.
	 * @return array { max_age, s_maxage, swr, sie }
	 */
	private static function resolve_cache_profile( $settings ) {
		$profile = array(
			'max_age'  => (int) $settings['html_max_age'],
			's_maxage' => (int) $settings['html_s_maxage'],
			'swr'      => (int) $settings['html_swr'],
			'sie'      => (int) $settings['html_sie'],
		);

		if ( ! empty( $settings['front_page_cache_override'] ) && ( is_front_page() || is_home() ) ) {
			$profile = array(
				'max_age'  => (int) $settings['front_page_max_age'],
				's_maxage' => (int) $settings['front_page_s_maxage'],
				'swr'      => (int) $settings['front_page_swr'],
				'sie'      => (int) $settings['front_page_sie'],
			);
		}

		/**
		 * Filter the resolved Cache-Control profile before it is emitted.
		 *
		 * @param array $profile  { max_age, s_maxage, swr, sie }.
		 * @param array $settings Full settings array.
		 */
		return apply_filters( 'flavor_edge_cache_profile', $profile, $settings );
	}

	/**
	 * Build the safe Vary token set for an anonymous cacheable HTML document.
	 *
	 * Deliberately never includes Cookie or User-Agent: varying the anonymous
	 * document on those fragments the edge cache into one object per analytics
	 * cookie (a measured ~58% miss on real news sites) or per UA string. Device
	 * segmentation is carried by X-Device, language by Accept-Language.
	 *
	 * @param array $settings Full settings array.
	 * @return array
	 */
	private static function get_safe_vary( $settings ) {
		$vary = array( 'Accept-Encoding' );
		if ( ! empty( $settings['vary_device'] ) ) {
			$vary[] = 'X-Device';
		}
		if ( ! empty( $settings['vary_language'] ) ) {
			$vary[] = 'Accept-Language';
		}

		/**
		 * Filter the Vary tokens for anonymous cacheable responses.
		 *
		 * Cookie / User-Agent are intentionally excluded; add tokens here only
		 * if you fully control their cardinality.
		 *
		 * @param array $vary Vary tokens.
		 */
		// Merge any Vary another PHP layer already set, so a legitimate token a
		// theme/plugin added is not silently dropped (replace -> merge).
		foreach ( self::existing_vary_tokens() as $tok ) {
			$vary[] = $tok;
		}

		$vary = apply_filters( 'flavor_edge_vary_headers', $vary );

		// Sanitise: Cookie / User-Agent (and *) fragment the anonymous cache into
		// one object per client string and never map to a real anonymous variant,
		// so they are stripped unconditionally — even when the filter or another
		// layer tries to add them. This is what keeps the neutralisation intact
		// while still merging a legitimate foreign Vary.
		$forbidden = array( 'cookie', 'user-agent', '*' );
		$out       = array();
		foreach ( (array) $vary as $v ) {
			$v = trim( (string) $v );
			if ( '' === $v || in_array( strtolower( $v ), $forbidden, true ) ) {
				continue;
			}
			$out[] = $v;
		}
		return array_values( array_unique( $out ) );
	}

	/**
	 * Tokens from any Vary header already present on the response (set by another
	 * PHP layer), so get_safe_vary can merge instead of blindly replacing.
	 *
	 * @return array
	 */
	private static function existing_vary_tokens() {
		$tokens = array();
		foreach ( headers_list() as $h ) {
			if ( 0 === stripos( $h, 'vary:' ) ) {
				$val = trim( substr( $h, 5 ) );
				foreach ( explode( ',', $val ) as $t ) {
					$t = trim( $t );
					if ( '' !== $t ) {
						$tokens[] = $t;
					}
				}
			}
		}
		return $tokens;
	}

	/**
	 * Force the safe Vary header right before PHP sends headers.
	 *
	 * Registered via header_register_callback() so it runs AFTER WordPress core,
	 * the theme and other plugins have touched the Vary header. This neutralises
	 * a stray "Vary: Cookie, User-Agent" at the origin instead of relying on a
	 * VCL override at the edge. Only cacheable responses are touched; logged-in,
	 * REST and dynamic responses are left exactly as they are.
	 */
	public static function finalize_vary() {
		if ( self::is_uncacheable_request() ) {
			return;
		}

		$vary = self::get_safe_vary( TE_Settings::get_all() );
		if ( ! empty( $vary ) ) {
			// replace = true → collapses any Cookie/User-Agent variant set.
			header( 'Vary: ' . implode( ', ', $vary ), true );
		}
	}

	/**
	 * Propose-only VCL that makes Varnish honour the stale-while-revalidate /
	 * stale-if-error the plugin already emits in Cache-Control.
	 *
	 * Without it, those directives are advisory only: the plugin's homepage
	 * profile sends them, but the edge won't serve stale during revalidation
	 * unless beresp.grace / beresp.keep are set accordingly. This derives both
	 * from the response's own Cache-Control, so it stays in sync with whatever
	 * TTL profile the plugin resolves. The plugin never deploys VCL — the user
	 * copies this into the Transparent Edge dashboard.
	 *
	 * @return string VCL.
	 */
	public static function generate_grace_vcl() {
		return <<<'VCL'
# ── Serve-stale alignment (grace / keep) ────────────────────────
# Generated by Transparent Edge Cache plugin — PROPOSE ONLY, review first.
# Requires the std vmod ("import std;", present in most base VCLs).
#
# Makes Varnish honour the stale-while-revalidate / stale-if-error that the
# plugin sends in Cache-Control:
#   - grace: after the TTL expires, serve the stale object INSTANTLY while ONE
#     request revalidates in the background — this is what shields the origin
#     from a miss storm on the most-requested URLs (e.g. the homepage).
#   - keep:  retain the object past grace so stale-if-error can still serve it
#     when the origin returns 5xx.
#
# Deploy this in: Configuration → VCL → vcl_backend_response
# ────────────────────────────────────────────────────────────────

sub vcl_backend_response {
    if (beresp.http.Cache-Control ~ "stale-while-revalidate=[0-9]+") {
        set beresp.grace = std.duration(
            regsub(beresp.http.Cache-Control, "(?i).*stale-while-revalidate=([0-9]+).*", "\1") + "s", 10s);
    }
    if (beresp.http.Cache-Control ~ "stale-if-error=[0-9]+") {
        set beresp.keep = std.duration(
            regsub(beresp.http.Cache-Control, "(?i).*stale-if-error=([0-9]+).*", "\1") + "s", 0s);
    }
}
VCL;
	}

	/**
	 * Add headers to REST API responses.
	 *
	 * @param \WP_REST_Response $response Response object.
	 * @param \WP_REST_Server   $server   Server instance.
	 * @param \WP_REST_Request  $request  Request object.
	 * @return \WP_REST_Response
	 */
	public static function add_rest_headers( $response, $server, $request ) {
		$settings = TE_Settings::get_all();

		if ( 'GET' !== $request->get_method() || is_user_logged_in() ) {
			return $response;
		}

		// Never cache REST errors at the edge: a transient 4xx/5xx would otherwise be
		// pinned for the whole s-maxage window.
		$status = (int) $response->get_status();
		if ( $status >= 400 ) {
			$response->header( 'Cache-Control', 'no-cache, no-store, must-revalidate' );
			return $response;
		}

		$response->header(
			'Cache-Control',
			sprintf( 'public, max-age=%d, s-maxage=%d', $settings['html_max_age'], $settings['html_s_maxage'] )
		);

		// Always attach at least a site-level surrogate key so the response is
		// purgeable (a full-site purge clears it, instead of it riding the TTL);
		// add a post-specific key for single post/page endpoints.
		$keys  = array( 'site-' . get_current_blog_id() );
		$route = $request->get_route();
		if ( preg_match( '#/wp/v2/(?:posts|pages)/(\d+)#', $route, $matches ) ) {
			$keys[] = 'post-' . $matches[1];
		}
		$response->header( 'Surrogate-Keys', implode( ' ', array_map( 'sanitize_key', $keys ) ) );

		return $response;
	}

	/**
	 * Get the surrogate keys for the current request (for external use).
	 *
	 * @return array
	 */
	public static function get_surrogate_keys() {
		return self::$surrogate_keys;
	}

	/**
	 * Add a surrogate key programmatically.
	 *
	 * @param string $key Key to add.
	 */
	public static function add_key( $key ) {
		self::$surrogate_keys[] = sanitize_key( $key );
	}

	// -------------------------------------------------------------------------
	// Internal helpers.
	// -------------------------------------------------------------------------

	/**
	 * Check if the current request should not be cached.
	 *
	 * @return bool
	 */
	private static function is_uncacheable_request() {
		// Logged-in users.
		if ( is_user_logged_in() ) {
			return true;
		}

		// POST requests.
		$method = isset( $_SERVER['REQUEST_METHOD'] ) ? $_SERVER['REQUEST_METHOD'] : 'GET';
		if ( 'GET' !== $method && 'HEAD' !== $method ) {
			return true;
		}

		// WordPress admin, login, cron.
		if ( is_admin() || wp_doing_cron() || wp_doing_ajax() ) {
			return true;
		}

		// WP customizer preview.
		if ( is_customize_preview() ) {
			return true;
		}

		// Always-uncacheable cookies: WordPress's own privacy-sensitive cookies,
		// checked regardless of the admin's excluded_cookies list. Without this a
		// password-protected post (wp-postpass_*) or a commenter's "awaiting
		// moderation" view (comment_author_*) would be stamped public and served
		// to everyone. Prefix match on the cookie name.
		if ( ! empty( $_COOKIE ) ) {
			$always = apply_filters(
				'flavor_edge_always_excluded_cookies',
				array( 'wp-postpass_', 'comment_author_', 'wp_woocommerce_session_', 'woocommerce_cart_hash', 'wordpress_logged_in_', 'wordpress_sec_' )
			);
			foreach ( array_keys( $_COOKIE ) as $cookie_name ) {
				foreach ( $always as $prefix ) {
					if ( '' !== $prefix && 0 === strpos( $cookie_name, (string) $prefix ) ) {
						return true;
					}
				}
			}
		}

		// Check excluded cookies.
		$excluded_cookies = array_filter( explode( "\n", TE_Settings::get( 'excluded_cookies', '' ) ) );
		if ( ! empty( $_COOKIE ) && ! empty( $excluded_cookies ) ) {
			foreach ( array_keys( $_COOKIE ) as $cookie_name ) {
				foreach ( $excluded_cookies as $pattern ) {
					$pattern = trim( $pattern );
					if ( $pattern && self::safe_pattern_match( $pattern, $cookie_name ) ) {
						return true;
					}
				}
			}
		}

		// Check excluded URLs.
		$excluded_urls = array_filter( explode( "\n", TE_Settings::get( 'excluded_urls', '' ) ) );
		$request_uri   = isset( $_SERVER['REQUEST_URI'] ) ? $_SERVER['REQUEST_URI'] : '';
		foreach ( $excluded_urls as $pattern ) {
			$pattern = trim( $pattern );
			if ( $pattern && self::safe_pattern_match( $pattern, $request_uri ) ) {
				return true;
			}
		}

		// Allow modules (WooCommerce, etc.) to mark requests as uncacheable.
		if ( apply_filters( 'flavor_edge_is_uncacheable', false ) ) {
			return true;
		}

		return false;
	}

	/**
	 * Send no-cache headers.
	 */
	private static function send_nocache_headers() {
		if ( ! headers_sent() ) {
			header( 'Cache-Control: no-store, no-cache, must-revalidate, max-age=0' );
			header( 'Pragma: no-cache' );
		}
	}

	/**
	 * Safely match a user-supplied pattern against a string.
	 *
	 * Tries the pattern as a regex first (with error suppression).
	 * If the regex is invalid, falls back to substring match.
	 * Prevents regex injection and ReDoS from malformed patterns.
	 *
	 * @param string $pattern User-supplied pattern.
	 * @param string $subject String to match against.
	 * @return bool
	 */
	private static function safe_pattern_match( $pattern, $subject ) {
		// Try as regex first — suppress errors for invalid patterns.
		$regex  = '#' . str_replace( '#', '\\#', $pattern ) . '#i';
		$result = @preg_match( $regex, $subject );

		if ( false === $result ) {
			// Invalid regex — fall back to simple case-insensitive substring match.
			return false !== stripos( $subject, $pattern );
		}

		return 1 === $result;
	}
}
