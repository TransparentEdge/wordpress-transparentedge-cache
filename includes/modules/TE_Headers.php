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

		$settings = TE_Settings::get_all();

		// Cache-Control with dual TTL.
		$max_age   = (int) $settings['html_max_age'];
		$s_maxage  = (int) $settings['html_s_maxage'];

		header( sprintf(
			'Cache-Control: public, max-age=%d, s-maxage=%d, stale-while-revalidate=60, stale-if-error=86400',
			$max_age,
			$s_maxage
		) );

		// Surrogate-Keys.
		if ( $settings['surrogate_keys'] && ! empty( self::$surrogate_keys ) ) {
			header( 'Surrogate-Keys: ' . implode( ' ', self::$surrogate_keys ) );
		}

		// Vary.
		$vary_parts = array();
		if ( $settings['vary_device'] ) {
			$vary_parts[] = 'X-Device';
		}
		if ( $settings['vary_language'] ) {
			$vary_parts[] = 'Accept-Language';
		}
		$vary_parts = apply_filters( 'flavor_edge_vary_headers', $vary_parts );
		if ( ! empty( $vary_parts ) ) {
			header( 'Vary: ' . implode( ', ', $vary_parts ) );
		}

		// Debug header.
		if ( $settings['debug_mode'] ) {
			header( 'X-Flavor-Edge-Keys: ' . implode( ' ', self::$surrogate_keys ) );
			header( 'X-Flavor-Edge-TTL: max-age=' . $max_age . ', s-maxage=' . $s_maxage );
		}
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

		if ( $request->get_method() === 'GET' && ! is_user_logged_in() ) {
			$response->header(
				'Cache-Control',
				sprintf( 'public, max-age=%d, s-maxage=%d', $settings['html_max_age'], $settings['html_s_maxage'] )
			);

			// Add post-specific surrogate keys for single post endpoints.
			$route = $request->get_route();
			if ( preg_match( '#/wp/v2/(?:posts|pages)/(\d+)#', $route, $matches ) ) {
				$keys = array( 'post-' . $matches[1], 'site-' . get_current_blog_id() );
				$response->header( 'Surrogate-Keys', implode( ' ', $keys ) );
			}
		}

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
		if ( 'GET' !== $_SERVER['REQUEST_METHOD'] && 'HEAD' !== $_SERVER['REQUEST_METHOD'] ) {
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

		// Check excluded cookies.
		$excluded_cookies = array_filter( explode( "\n", TE_Settings::get( 'excluded_cookies', '' ) ) );
		if ( ! empty( $_COOKIE ) && ! empty( $excluded_cookies ) ) {
			foreach ( array_keys( $_COOKIE ) as $cookie_name ) {
				foreach ( $excluded_cookies as $pattern ) {
					$pattern = trim( $pattern );
					if ( $pattern && preg_match( '#' . $pattern . '#', $cookie_name ) ) {
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
			if ( $pattern && preg_match( '#' . $pattern . '#', $request_uri ) ) {
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
}
