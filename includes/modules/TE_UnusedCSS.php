<?php
/**
 * Remove Unused CSS / Critical CSS.
 *
 * Computes the "Used CSS" per template asynchronously, stores it, and serves
 * only the applied rules inline while deferring the rest. Generation never
 * blocks the visitor: the first uncached view queues a background job and
 * serves the original CSS until the Used CSS is ready.
 *
 * Invalidation is coordinated with the CDN via Surrogate-Key so the Used CSS
 * regenerates when theme, plugins, or template content change.
 *
 * @package flavor_edge_cache
 */

namespace flavor_edge;

defined( 'ABSPATH' ) || exit;

class TE_UnusedCSS {

	/**
	 * Cache subdirectory for Used CSS.
	 */
	const CACHE_DIR = 'cache/flavor-edge/ucss';

	/**
	 * Cron hook for async generation.
	 */
	const CRON_HOOK = 'flavor_edge_generate_ucss';

	/**
	 * Option storing generation queue and metadata.
	 */
	const STORE_OPTION = 'flavor_edge_ucss_store';

	/**
	 * Initialize.
	 */
	public static function init() {
		$s = TE_Settings::get_all();
		if ( empty( $s['remove_unused_css'] ) ) {
			return;
		}

		add_action( self::CRON_HOOK, array( __CLASS__, 'process_queue' ) );

		// Only act on the frontend for real page views.
		if ( ! is_admin() ) {
			// High priority so we capture the enqueued styles late in the head.
			add_action( 'wp_enqueue_scripts', array( __CLASS__, 'maybe_apply' ), 9999 );
		}
	}

	/**
	 * Decide whether to serve cached Used CSS or queue generation.
	 */
	public static function maybe_apply() {
		// Skip for logged-in users (they see admin bars, editors, etc.).
		if ( is_user_logged_in() ) {
			return;
		}

		// Skip our own generation request to avoid re-queueing / recursion.
		if ( ! empty( $_SERVER['HTTP_X_FLAVOR_EDGE_UCSS'] ) ) {
			return;
		}

		// Fallback: if cache dir is not writable, do nothing (serve original CSS).
		if ( ! self::is_writable() ) {
			return;
		}

		$key       = self::get_template_key();
		$cache_file = self::get_cache_dir() . '/' . $key . '.css';

		if ( file_exists( $cache_file ) ) {
			// Serve Used CSS: inline it, dequeue the originals, defer full CSS.
			add_action( 'wp_head', array( __CLASS__, 'inline_used_css' ), 1 );
			add_filter( 'style_loader_tag', array( __CLASS__, 'defer_stylesheet' ), 10, 4 );
		} else {
			// Not generated yet — queue background job, serve original CSS this time.
			self::queue_template( $key );
		}
	}

	/**
	 * Inline the cached Used CSS into the head.
	 */
	public static function inline_used_css() {
		$key        = self::get_template_key();
		$cache_file = self::get_cache_dir() . '/' . $key . '.css';
		if ( ! file_exists( $cache_file ) ) {
			return;
		}
		$css = file_get_contents( $cache_file ); // phpcs:ignore
		if ( $css ) {
			// Defense in depth: neutralize any accidental </style> breakout.
			$css = str_ireplace( '</style', '<\/style', $css );
			echo "\n<style id=\"flavor-edge-ucss\">" . $css . "</style>\n"; // phpcs:ignore
		}
	}

	/**
	 * Defer the full stylesheets (load them non-render-blocking).
	 *
	 * @param string $tag    The link tag.
	 * @param string $handle Stylesheet handle.
	 * @param string $href   URL.
	 * @param string $media  Media attribute.
	 * @return string
	 */
	public static function defer_stylesheet( $tag, $handle, $href, $media ) {
		// Skip admin/critical handles that should stay blocking.
		$exclusions = self::get_exclusions();
		foreach ( $exclusions as $exc ) {
			if ( $exc && ( false !== stripos( $handle, $exc ) || false !== stripos( $href, $exc ) ) ) {
				return $tag;
			}
		}

		// Convert to preload + async pattern.
		$deferred = sprintf(
			'<link rel="preload" as="style" href="%s" onload="this.onload=null;this.rel=\'stylesheet\'">'
			. '<noscript><link rel="stylesheet" href="%s"></noscript>',
			esc_url( $href ),
			esc_url( $href )
		);

		return $deferred;
	}

	/**
	 * Queue a template for background Used CSS generation.
	 *
	 * @param string $key Template key.
	 */
	private static function queue_template( $key ) {
		$store = get_option( self::STORE_OPTION, array( 'queue' => array(), 'generated' => array() ) );

		// Avoid re-queueing the same key.
		if ( in_array( $key, $store['queue'], true ) ) {
			return;
		}

		// Capture the current URL to fetch during generation.
		$url = home_url( add_query_arg( array() ) );

		$store['queue'][ $key ] = $url;
		update_option( self::STORE_OPTION, $store, false );

		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_single_event( time() + 10, self::CRON_HOOK );
		}
	}

	/**
	 * Process the generation queue (cron).
	 */
	public static function process_queue() {
		$store = get_option( self::STORE_OPTION, array( 'queue' => array(), 'generated' => array() ) );
		if ( empty( $store['queue'] ) ) {
			return;
		}

		if ( ! self::is_writable() ) {
			return;
		}

		foreach ( $store['queue'] as $key => $url ) {
			$used_css = self::compute_used_css( $url );
			if ( false !== $used_css ) {
				$cache_file = self::get_cache_dir() . '/' . $key . '.css';
				file_put_contents( $cache_file, $used_css ); // phpcs:ignore
				$store['generated'][ $key ] = time();
			}
			unset( $store['queue'][ $key ] );
		}

		update_option( self::STORE_OPTION, $store, false );
	}

	/**
	 * Compute the Used CSS for a URL.
	 *
	 * Fetches the rendered HTML, collects the enqueued stylesheets, and
	 * extracts only the rules whose selectors appear in the DOM. This is a
	 * conservative heuristic: when in doubt, a rule is kept.
	 *
	 * @param string $url URL to analyze.
	 * @return string|false Used CSS, or false on failure.
	 */
	private static function compute_used_css( $url ) {
		$response = wp_remote_get( $url, array(
			'timeout'     => 15,
			'user-agent'  => 'WordPress/' . get_bloginfo( 'version' ) . '; ' . home_url( '/' ),
			'redirection' => 2,
			'headers'     => array(
				// Marker so maybe_apply() skips this request (no re-queue / recursion).
				'X-Flavor-Edge-Ucss' => '1',
			),
		) );

		if ( is_wp_error( $response ) || 200 !== wp_remote_retrieve_response_code( $response ) ) {
			return false;
		}

		$html = wp_remote_retrieve_body( $response );
		if ( empty( $html ) ) {
			return false;
		}

		// Collect stylesheet URLs from the HTML.
		if ( ! preg_match_all( '/<link[^>]+rel=["\']stylesheet["\'][^>]*>/i', $html, $link_tags ) ) {
			return false;
		}

		$css_urls = array();
		foreach ( $link_tags[0] as $tag ) {
			if ( preg_match( '/href=["\']([^"\']+)["\']/i', $tag, $m ) ) {
				$css_urls[] = html_entity_decode( $m[1] );
			}
		}

		// Extract DOM tokens (tags, classes, ids) present in the HTML.
		$tokens = self::extract_dom_tokens( $html );

		$used = '';
		foreach ( $css_urls as $css_url ) {
			$css = self::fetch_css( $css_url );
			if ( $css ) {
				$used .= self::filter_used_rules( $css, $tokens );
			}
		}

		return $used;
	}

	/**
	 * Extract the set of tag names, classes, and IDs present in the HTML.
	 *
	 * @param string $html HTML.
	 * @return array Associative set (token => true).
	 */
	private static function extract_dom_tokens( $html ) {
		$tokens = array();

		// Tag names.
		if ( preg_match_all( '/<([a-z0-9]+)[\s>\/]/i', $html, $m ) ) {
			foreach ( $m[1] as $tag ) {
				$tokens[ strtolower( $tag ) ] = true;
			}
		}

		// Classes.
		if ( preg_match_all( '/class=["\']([^"\']+)["\']/i', $html, $m ) ) {
			foreach ( $m[1] as $class_attr ) {
				foreach ( preg_split( '/\s+/', $class_attr ) as $class ) {
					if ( $class ) {
						$tokens[ '.' . $class ] = true;
					}
				}
			}
		}

		// IDs.
		if ( preg_match_all( '/id=["\']([^"\']+)["\']/i', $html, $m ) ) {
			foreach ( $m[1] as $id ) {
				$tokens[ '#' . $id ] = true;
			}
		}

		return $tokens;
	}

	/**
	 * Keep only CSS rules whose selectors match tokens present in the DOM.
	 * Conservative: keeps at-rules, pseudo/global selectors, and anything ambiguous.
	 *
	 * @param string $css    Raw CSS.
	 * @param array  $tokens DOM token set.
	 * @return string Filtered CSS.
	 */
	private static function filter_used_rules( $css, $tokens ) {
		// Strip comments.
		$css = preg_replace( '!/\*.*?\*/!s', '', $css );

		$output = '';

		// Always keep at-rules that affect rendering globally.
		// Match rule blocks: selector { declarations }
		if ( ! preg_match_all( '/([^{}]+)\{([^{}]*)\}/s', $css, $rules, PREG_SET_ORDER ) ) {
			return $css; // Can't parse — keep everything (safe).
		}

		foreach ( $rules as $rule ) {
			$selector_group = trim( $rule[1] );
			$declarations   = trim( $rule[2] );

			// Keep at-rules and root/global selectors unconditionally.
			if ( '' === $selector_group
				|| 0 === strpos( $selector_group, '@' )
				|| false !== strpos( $selector_group, ':root' )
				|| false !== strpos( $selector_group, 'html' )
				|| false !== strpos( $selector_group, 'body' )
				|| false !== strpos( $selector_group, '*' ) ) {
				$output .= $selector_group . '{' . $declarations . '}';
				continue;
			}

			// Check each selector in the group.
			$keep = false;
			foreach ( explode( ',', $selector_group ) as $selector ) {
				if ( self::selector_is_used( trim( $selector ), $tokens ) ) {
					$keep = true;
					break;
				}
			}

			if ( $keep ) {
				$output .= $selector_group . '{' . $declarations . '}';
			}
		}

		return $output;
	}

	/**
	 * Heuristic: does a selector reference a token present in the DOM?
	 *
	 * @param string $selector Single selector.
	 * @param array  $tokens   DOM token set.
	 * @return bool
	 */
	private static function selector_is_used( $selector, $tokens ) {
		// Extract class tokens (.foo), id tokens (#bar) and tag names.
		if ( preg_match_all( '/\.[a-zA-Z0-9_-]+/', $selector, $classes ) ) {
			foreach ( $classes[0] as $class ) {
				if ( isset( $tokens[ $class ] ) ) {
					return true;
				}
			}
			// If the selector has classes but none matched, it's unused.
			// (unless it also has an ID that matches — checked below).
		}

		if ( preg_match_all( '/#[a-zA-Z0-9_-]+/', $selector, $ids ) ) {
			foreach ( $ids[0] as $id ) {
				if ( isset( $tokens[ $id ] ) ) {
					return true;
				}
			}
		}

		// If no class/id in selector, check tag names (conservative: keep if tag present).
		if ( empty( $classes[0] ) && empty( $ids[0] ) ) {
			if ( preg_match( '/^([a-z0-9]+)/i', $selector, $m ) ) {
				if ( isset( $tokens[ strtolower( $m[1] ) ] ) ) {
					return true;
				}
			}
			// Pseudo-selectors, attribute selectors, combinators without class/id: keep (safe).
			if ( preg_match( '/[:\[>~+]/', $selector ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Fetch a CSS file's contents (local preferred, remote fallback).
	 *
	 * @param string $url CSS URL.
	 * @return string|false
	 */
	private static function fetch_css( $url ) {
		// Strip query string for local path resolution.
		$clean = strtok( $url, '?' );

		// Try local file first.
		$site_url = site_url();
		if ( 0 === strpos( $clean, $site_url ) ) {
			$path = ABSPATH . ltrim( substr( $clean, strlen( $site_url ) ), '/' );
			if ( file_exists( $path ) && is_readable( $path ) ) {
				return file_get_contents( $path ); // phpcs:ignore
			}
		}

		// Only fetch same-host remote CSS (avoid SSRF).
		$own_host = wp_parse_url( home_url(), PHP_URL_HOST );
		if ( wp_parse_url( $url, PHP_URL_HOST ) !== $own_host ) {
			return false;
		}

		$response = wp_remote_get( $url, array( 'timeout' => 10 ) );
		if ( is_wp_error( $response ) || 200 !== wp_remote_retrieve_response_code( $response ) ) {
			return false;
		}
		return wp_remote_retrieve_body( $response );
	}

	/**
	 * Build a cache key identifying the current template type.
	 *
	 * @return string
	 */
	private static function get_template_key() {
		if ( is_front_page() ) { return 'front-page'; }
		if ( is_home() )       { return 'blog-home'; }
		if ( is_singular() ) {
			return 'singular-' . get_post_type();
		}
		if ( is_category() || is_tag() || is_tax() ) { return 'taxonomy'; }
		if ( is_archive() )    { return 'archive'; }
		if ( is_search() )     { return 'search'; }
		if ( is_404() )        { return 'notfound'; }
		return 'generic';
	}

	/**
	 * Get exclusions (handles/urls that should not be deferred).
	 *
	 * @return array
	 */
	private static function get_exclusions() {
		$raw = TE_Settings::get( 'ucss_exclusions', '' );
		return array_filter( array_map( 'trim', explode( "\n", $raw ) ) );
	}

	/**
	 * Cache directory path.
	 *
	 * @return string
	 */
	private static function get_cache_dir() {
		return WP_CONTENT_DIR . '/' . self::CACHE_DIR;
	}

	/**
	 * Check writability, creating the directory if needed.
	 *
	 * @return bool
	 */
	private static function is_writable() {
		$dir = self::get_cache_dir();
		if ( ! file_exists( $dir ) ) {
			if ( ! wp_mkdir_p( $dir ) ) {
				return false;
			}
		}
		return is_writable( $dir );
	}

	/**
	 * Clear all generated Used CSS and reset the store.
	 */
	public static function clear_cache() {
		$dir = self::get_cache_dir();
		if ( is_dir( $dir ) ) {
			$files = glob( $dir . '/*.css' );
			if ( $files ) {
				foreach ( $files as $f ) {
					@unlink( $f ); // phpcs:ignore
				}
			}
		}
		delete_option( self::STORE_OPTION );
	}

	/**
	 * Get the Surrogate-Key tag used for the Used CSS.
	 *
	 * @return string
	 */
	public static function get_surrogate_key() {
		return 'ucss-' . get_current_blog_id();
	}
}
