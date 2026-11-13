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
	 * Keys discovered while the page renders (secondary loops: page-builder post
	 * lists, Query Loop blocks, "recent posts" widgets). See note_rendered_post().
	 *
	 * @var array
	 */
	private static $rendered_keys = array();

	/**
	 * True once collect_surrogate_keys() ran for a cacheable front-end request.
	 *
	 * @var bool
	 */
	private static $collecting = false;

	/** Option holding pages that list posts outside the main query (for the origin page cache). */
	const LISTING_PAGES_OPTION = 'flavor_edge_listing_pages';

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

		// Posts rendered by secondary loops (page builders, Query Loop block…).
		add_action( 'the_post', array( __CLASS__, 'note_rendered_post' ), 10, 2 );
		add_filter( 'render_block_core/latest-posts', array( __CLASS__, 'note_latest_posts_block' ) );

		// Hook into REST API responses too.
		add_filter( 'rest_post_dispatch', array( __CLASS__, 'add_rest_headers' ), 10, 3 );
	}

	/**
	 * Record a post rendered OUTSIDE the main query — e.g. a post list built with
	 * Elementor, WPBakery, Divi, a Query Loop block or a "recent posts" widget on
	 * an ordinary page. The main query cannot see those lists, so without this a
	 * page that lists posts would carry no listing key and publishing a post would
	 * not refresh it.
	 *
	 *  - post-<id> of each such post (shared cap with the main listing), so
	 *    editing one refreshes every page that shows it;
	 *  - on PAGES only, lst-type-<type>, so publishing a post of that type
	 *    refreshes them. Not on singles of other types: a "related posts" block on
	 *    every article would otherwise tie all articles to every publish (that is
	 *    the opt-in publish_refresh_singles behaviour).
	 *
	 * @param \WP_Post       $post  Post being set up.
	 * @param \WP_Query|null $query Query running the loop.
	 */
	public static function note_rendered_post( $post, $query = null ) {
		if ( ! self::$collecting || ! is_object( $post ) || empty( $post->ID ) ) {
			return;
		}
		// Posts of the main query are already keyed by collect_surrogate_keys().
		// (Checked by ID, not by $query: setup_postdata() on a get_posts() result
		// reports the MAIN query even though the post is not part of it.)
		global $wp_query;
		if ( (int) $post->ID === (int) get_queried_object_id() ) {
			return;
		}
		if ( $wp_query instanceof \WP_Query && ! empty( $wp_query->posts ) && ! is_singular() ) {
			foreach ( $wp_query->posts as $main ) {
				if ( isset( $main->ID ) && (int) $main->ID === (int) $post->ID ) {
					return;
				}
			}
		}
		$max = (int) apply_filters( 'flavor_edge_listing_post_keys_max', 100 );
		if ( count( self::$rendered_keys ) < $max ) {
			self::$rendered_keys[ 'post-' . (int) $post->ID ] = true;
		}
		if ( is_page() && ! empty( $post->post_type ) && 'page' !== $post->post_type ) {
			self::$rendered_keys[ 'lst-type-' . sanitize_key( $post->post_type ) ] = true;
		}
	}

	/**
	 * The core "Latest Posts" block lists posts without running a loop.
	 *
	 * @param string $content Block HTML.
	 * @return string
	 */
	public static function note_latest_posts_block( $content ) {
		if ( self::$collecting && is_page() ) {
			self::$rendered_keys['lst-type-post'] = true;
		}
		return $content;
	}

	/**
	 * Output-buffer callback (innermost buffer, so it runs before the origin page
	 * cache captures the response): re-send Surrogate-Keys with the keys found
	 * while rendering. Headers cannot change once output was flushed (a theme
	 * calling flush()); then the response keeps the keys known up front.
	 *
	 * @param string $html Buffered output.
	 * @return string Unchanged output.
	 */
	public static function flush_rendered_keys( $html ) {
		if ( empty( self::$rendered_keys ) || headers_sent() ) {
			return $html;
		}
		$new = array_diff( array_keys( self::$rendered_keys ), self::$surrogate_keys );
		if ( ! $new ) {
			return $html;
		}
		self::$surrogate_keys = array_values( array_unique( array_merge( self::$surrogate_keys, array_map( 'sanitize_key', $new ) ) ) );
		header( 'Surrogate-Keys: ' . implode( ' ', self::$surrogate_keys ), true );
		if ( headers_list() && preg_grep( '/^X-Flavor-Edge-Keys:/i', headers_list() ) ) {
			header( 'X-Flavor-Edge-Keys: ' . implode( ' ', self::$surrogate_keys ), true );
		}
		self::remember_listing_page( $new );
		return $html;
	}

	/**
	 * The origin page cache invalidates by URL, so it must know which pages carry
	 * a builder-made lst-type-<type> key. Remember them (a small, non-autoloaded
	 * map, written only when a new page appears) — see listing_pages_for().
	 *
	 * @param array $keys Keys added at render time.
	 */
	private static function remember_listing_page( $keys ) {
		$types = array();
		foreach ( $keys as $k ) {
			if ( 0 === strpos( $k, 'lst-type-' ) ) {
				$types[] = substr( $k, 9 );
			}
		}
		$id = (int) get_queried_object_id();
		if ( ! $types || ! $id ) {
			return;
		}
		$map     = get_option( self::LISTING_PAGES_OPTION, array() );
		$map     = is_array( $map ) ? $map : array();
		$changed = false;
		foreach ( $types as $type ) {
			$list = isset( $map[ $type ] ) ? (array) $map[ $type ] : array();
			if ( ! in_array( $id, $list, true ) ) {
				array_unshift( $list, $id );
				$map[ $type ] = array_slice( $list, 0, 50 );
				$changed      = true;
			}
		}
		if ( $changed ) {
			update_option( self::LISTING_PAGES_OPTION, $map, false );
		}
	}

	/**
	 * Page IDs known to list posts of a type outside the main query.
	 *
	 * @param string $type Post type.
	 * @return int[]
	 */
	public static function listing_pages_for( $type ) {
		$map = get_option( self::LISTING_PAGES_OPTION, array() );
		return ( is_array( $map ) && isset( $map[ $type ] ) ) ? array_map( 'intval', (array) $map[ $type ] ) : array();
	}

	/**
	 * Public (viewable) taxonomies of a post type — the only ones whose terms take
	 * part in surrogate keys. Non-viewable taxonomies are internal bookkeeping (WPML
	 * translation_priority, editorial flags, …): they are not shown to readers and
	 * are often attached to every object, so keying on them would tie unrelated
	 * pages together. Shared by the headers (emit) and invalidation (purge) sides
	 * so both always agree. Filterable via flavor_edge_surrogate_taxonomies.
	 *
	 * @param string $post_type Post type.
	 * @return string[] Taxonomy names.
	 */
	public static function viewable_taxonomies( $post_type ) {
		$out = array();
		foreach ( get_object_taxonomies( $post_type ) as $taxonomy ) {
			if ( function_exists( 'is_taxonomy_viewable' ) ? is_taxonomy_viewable( $taxonomy ) : true ) {
				$out[] = $taxonomy;
			}
		}
		return (array) apply_filters( 'flavor_edge_surrogate_taxonomies', $out, $post_type );
	}

	/**
	 * Collect Surrogate-Keys based on the current WordPress query.
	 */
	public static function collect_surrogate_keys() {
		if ( is_admin() || self::is_uncacheable_request() ) {
			return;
		}

		$keys = array();

		// Key model (v2). Two kinds of keys, kept strictly apart:
		//  - MEMBERSHIP keys say what a page DISPLAYS: post-<id>, term-<id> (a term's
		//    name/description), author-<id> (a byline). They are purged when that
		//    ENTITY itself changes (the post is edited, a term is renamed…).
		//  - LISTING keys say what a page LISTS: front-page, lst-type-<type>,
		//    lst-term-<id>, lst-author-<id>, archive-date, feed. They are purged when
		//    a post is published/updated, so the pages that list it refresh.
		// Publishing post P purges post-<P> plus the LISTING keys of P's type, author
		// and terms — never another post's membership keys. Before v2 singles also
		// carried type-/tax-/term-/author- and those same tags were purged on every
		// publish, so one publish invalidated every page sharing any of them, which
		// in practice was the whole site.

		// Global key for full purge.
		$keys[] = 'site-' . get_current_blog_id();

		if ( is_front_page() || is_home() ) {
			$keys[] = 'front-page';
		}
		if ( is_home() ) {
			// The posts page lists the latest posts.
			$keys[] = 'lst-type-post';
		}

		if ( is_singular() ) {
			$post = get_queried_object();
			if ( $post ) {
				$keys[] = 'post-' . $post->ID;
				// Membership only: purged on publish ONLY when the site opts into
				// refreshing every single of the type (themes whose singles show a
				// "latest news" block). See the publish_refresh_singles setting.
				$keys[] = 'type-' . $post->post_type;
				$keys[] = 'author-' . $post->post_author;

				// Terms the post displays — public (viewable) taxonomies only. Internal
				// taxonomies (e.g. WPML's translation_priority, present on every page)
				// would otherwise tie unrelated pages together.
				foreach ( self::viewable_taxonomies( $post->post_type ) as $taxonomy ) {
					$terms = get_the_terms( $post->ID, $taxonomy );
					if ( $terms && ! is_wp_error( $terms ) ) {
						foreach ( $terms as $term ) {
							$keys[] = 'term-' . $term->term_id;
						}
					}
				}
			}
		}

		if ( is_category() || is_tag() || is_tax() ) {
			$term = get_queried_object();
			if ( $term && isset( $term->term_id ) ) {
				$keys[] = 'term-' . $term->term_id;     // shows the term's name/description.
				$keys[] = 'lst-term-' . $term->term_id; // lists the term's posts.
			}
		}

		if ( is_post_type_archive() ) {
			foreach ( (array) get_query_var( 'post_type' ) as $post_type ) {
				if ( is_string( $post_type ) && '' !== $post_type ) {
					$keys[] = 'lst-type-' . $post_type;
				}
			}
		}

		if ( is_author() ) {
			$author = get_queried_object();
			if ( $author && isset( $author->ID ) ) {
				$keys[] = 'author-' . $author->ID;
				$keys[] = 'lst-author-' . $author->ID;
			}
		}

		if ( is_date() ) {
			$keys[] = 'archive-date';
		}

		// Every listing (home, archives, search, feeds) also carries post-<id> of the
		// posts it shows, so editing one of them refreshes the listings that show it.
		// Capped to keep the header small on long listings.
		if ( ! is_singular() && have_posts() ) {
			global $wp_query;
			$max = (int) apply_filters( 'flavor_edge_listing_post_keys_max', 100 );
			foreach ( array_slice( (array) $wp_query->posts, 0, max( 0, $max ) ) as $listed ) {
				if ( isset( $listed->ID ) ) {
					$keys[] = 'post-' . $listed->ID;
				}
			}
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
		self::$collecting     = true;
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
			// Buffer the body so keys found while it renders (secondary loops) can
			// still be added to the header before it is sent.
			if ( self::$collecting ) {
				ob_start( array( __CLASS__, 'flush_rendered_keys' ) );
			}
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

		// A 404 must not ride the long HTML TTL: a URL that 404s now may be published
		// (or republished) a minute later, and bot floods of missing URLs should not
		// sit in the cache for days. Short edge/browser life; stale-if-error kept so
		// an origin error still serves the last copy. Filterable.
		if ( is_404() ) {
			$profile = (array) apply_filters(
				'flavor_edge_404_cache_profile',
				array(
					'max_age'  => 60,
					's_maxage' => 60,
					'swr'      => 30,
					'sie'      => (int) $profile['sie'],
				),
				$settings
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
	 * Public so the origin page cache (TE_PageCache) can apply the exact same
	 * "uncacheable" verdict before it STORES a response — otherwise the shield's
	 * pre-boot-safe subset could cache what this layer (and the edge) treat as
	 * private (WooCommerce cart/checkout, anything a module marks via the
	 * flavor_edge_is_uncacheable filter, the customizer preview, …).
	 *
	 * @return bool
	 */
	public static function is_uncacheable_request() {
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
