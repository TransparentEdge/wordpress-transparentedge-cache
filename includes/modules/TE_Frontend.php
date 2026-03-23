<?php
/**
 * Frontend Optimization module.
 *
 * Delay JS execution, Minify HTML, enhanced Lazy Load,
 * Preload LCP image, DNS Prefetch / Preconnect.
 *
 * @package flavor_edge_cache
 */

namespace flavor_edge;

defined( 'ABSPATH' ) || exit;

class TE_Frontend {

	/**
	 * Initialize frontend optimization hooks.
	 */
	public static function init() {
		if ( ! TE_Settings::get( 'enabled' ) ) {
			return;
		}

		// Don't optimize admin, AJAX, REST API, cron, or previews.
		if ( is_admin() || wp_doing_ajax() || wp_doing_cron() || is_customize_preview() ) {
			return;
		}

		// Skip REST API requests.
		if ( defined( 'REST_REQUEST' ) && REST_REQUEST ) {
			return;
		}

		$s = TE_Settings::get_all();

		// HTML Minification + Delay JS + Lazy Load via output buffer.
		if ( $s['minify_html'] || $s['delay_js'] || $s['lazyload_images'] || $s['lazyload_iframes'] || $s['preload_lcp'] ) {
			add_action( 'template_redirect', array( __CLASS__, 'start_output_buffer' ), 0 );
		}

		// DNS Prefetch / Preconnect (auto-detect + manual).
		add_action( 'wp_head', array( __CLASS__, 'add_dns_prefetch' ), 1 );
	}

	/**
	 * Start output buffering to process final HTML.
	 */
	public static function start_output_buffer() {
		// Double-check: don't buffer non-HTML responses.
		if ( is_user_logged_in() || wp_doing_ajax() || is_feed() || is_robots() ) {
			return;
		}

		ob_start( array( __CLASS__, 'process_html' ) );
	}

	/**
	 * Process the final HTML output.
	 *
	 * @param string $html Full HTML output.
	 * @return string Modified HTML.
	 */
	public static function process_html( $html ) {
		if ( empty( $html ) || false === strpos( $html, '</html>' ) ) {
			return $html;
		}

		$s = TE_Settings::get_all();

		// 1. Lazy Load images and iframes.
		if ( $s['lazyload_images'] ) {
			$html = self::apply_lazyload_images( $html );
		}
		if ( $s['lazyload_iframes'] ) {
			$html = self::apply_lazyload_iframes( $html );
		}

		// 2. Preload LCP image.
		if ( $s['preload_lcp'] ) {
			$html = self::apply_preload_lcp( $html );
		}

		// 3. Delay JS execution.
		if ( $s['delay_js'] ) {
			$html = self::apply_delay_js( $html, $s['delay_js_exclusions'] );
		}

		// 4. Minify HTML (last, after all other modifications).
		if ( $s['minify_html'] ) {
			$html = self::apply_minify_html( $html );
		}

		return $html;
	}

	// -------------------------------------------------------------------------
	// Lazy Load.
	// -------------------------------------------------------------------------

	/**
	 * Add loading="lazy" to images that don't already have it.
	 * Skip the first image (likely LCP).
	 *
	 * @param string $html HTML content.
	 * @return string
	 */
	private static function apply_lazyload_images( $html ) {
		$count      = 0;
		$skip_first = TE_Settings::get( 'preload_lcp' ) ? 2 : 0; // Skip first 2 images (above the fold).

		$html = preg_replace_callback( '/<img\b([^>]*)>/i', function ( $matches ) use ( &$count, $skip_first ) {
			$attrs = $matches[1];
			$full  = $matches[0];

			// Skip if already has loading attribute.
			if ( preg_match( '/\bloading\s*=/i', $attrs ) ) {
				return $full;
			}

			// Skip exclusion markers.
			if ( preg_match( '/data-no-lazy|skip-lazy|no-lazy/i', $attrs ) ) {
				return $full;
			}

			// Skip SVGs (vector, tiny payload, no benefit from lazy loading).
			if ( preg_match( '/\.svg[\s"\'?]/i', $attrs ) ) {
				return $full;
			}

			$count++;

			// First N images are above the fold — mark as eager for LCP.
			if ( $count <= $skip_first ) {
				return '<img loading="eager" fetchpriority="high" ' . ltrim( $attrs ) . '>';
			}

			return '<img loading="lazy" ' . ltrim( $attrs ) . '>';
		}, $html );

		return $html;
	}

	/**
	 * Add loading="lazy" to iframes.
	 *
	 * @param string $html HTML content.
	 * @return string
	 */
	private static function apply_lazyload_iframes( $html ) {
		return preg_replace_callback( '/<iframe\b([^>]*)>/i', function ( $matches ) {
			$attrs = $matches[1];

			if ( preg_match( '/\bloading\s*=/i', $attrs ) ) {
				return $matches[0];
			}

			return '<iframe loading="lazy"' . $attrs . '>';
		}, $html );
	}

	// -------------------------------------------------------------------------
	// Preload LCP Image.
	// -------------------------------------------------------------------------

	/**
	 * Detect the first large image and add a preload link in <head>.
	 *
	 * @param string $html HTML content.
	 * @return string
	 */
	private static function apply_preload_lcp( $html ) {
		// Find first <img> in the <body> that has a src.
		if ( ! preg_match( '/<body[^>]*>(.*)/is', $html, $body_match ) ) {
			return $html;
		}

		$body_content = $body_match[1];

		// Find first significant raster image (skip SVGs, icons, data URIs, tiny images).
		if ( preg_match_all( '/<img\b[^>]*\bsrc\s*=\s*["\']([^"\']+)["\'][^>]*>/i', $body_content, $all_matches ) ) {
			$img_url = null;

			foreach ( $all_matches[1] as $candidate ) {
				// Skip data URIs.
				if ( 0 === strpos( $candidate, 'data:' ) ) {
					continue;
				}
				// Skip SVGs (not raster, usually icons/logos).
				if ( preg_match( '/\.svg(\?|$)/i', $candidate ) ) {
					continue;
				}
				// Skip tiny icons (favicon, etc.).
				if ( preg_match( '/favicon|icon|logo-short/i', $candidate ) ) {
					continue;
				}
				// Found a good candidate.
				$img_url = $candidate;
				break;
			}

			if ( $img_url ) {
				$preload = '<link rel="preload" as="image" href="' . esc_attr( $img_url ) . '"';
				$preload .= ' fetchpriority="high">' . "\n";

				// Insert before </head>.
				$html = str_replace( '</head>', $preload . '</head>', $html );
			}
		}

		return $html;
	}

	// -------------------------------------------------------------------------
	// Delay JS.
	// -------------------------------------------------------------------------

	/**
	 * Delay JavaScript execution until user interaction.
	 * Changes type="text/javascript" to type="flavoredge-delay" and adds
	 * a small inline script that restores them on first interaction.
	 *
	 * @param string $html       HTML content.
	 * @param string $exclusions Newline-separated list of patterns to exclude from delay.
	 * @return string
	 */
	private static function apply_delay_js( $html, $exclusions = '' ) {
		// Build exclusion patterns.
		$exclude_patterns = array_filter( array_map( 'trim', explode( "\n", $exclusions ) ) );

		// Always exclude critical scripts.
		$always_exclude = array(
			'jquery.min.js',
			'jquery.js',
			'jquery-migrate',
			'wp-includes/js/dist',
			'flavor-edge',       // Our own scripts.
			'admin-bar',
			'wp-embed',
			'wp-polyfill',
		);

		$exclude_patterns = array_merge( $always_exclude, $exclude_patterns );

		// Process <script> tags.
		$html = preg_replace_callback(
			'/<script\b([^>]*)>(.*?)<\/script>/is',
			function ( $matches ) use ( $exclude_patterns ) {
				$attrs   = $matches[1];
				$content = $matches[2];
				$full    = $matches[0];

				// Skip scripts without src (inline scripts) that are small.
				if ( ! preg_match( '/\bsrc\s*=\s*["\']([^"\']+)["\']/i', $attrs, $src_match ) ) {
					// Only delay inline scripts if they're substantial (>500 bytes).
					if ( strlen( trim( $content ) ) < 500 ) {
						return $full;
					}
				}

				// Skip if already has type that isn't text/javascript.
				if ( preg_match( '/\btype\s*=\s*["\'](?!text\/javascript)[^"\']+["\']/i', $attrs ) ) {
					return $full;
				}

				// Check exclusions using simple substring match (fast, no regex issues).
				$full_lower = strtolower( $full );
				foreach ( $exclude_patterns as $pattern ) {
					if ( ! empty( $pattern ) && false !== strpos( $full_lower, strtolower( $pattern ) ) ) {
						return $full;
					}
				}

				// Replace or add type attribute.
				if ( preg_match( '/\btype\s*=\s*["\']text\/javascript["\']/i', $attrs ) ) {
					$new_attrs = preg_replace( '/\btype\s*=\s*["\']text\/javascript["\']/i', 'type="flavoredge-delay"', $attrs );
				} else {
					$new_attrs = $attrs . ' type="flavoredge-delay"';
				}

				return '<script' . $new_attrs . '>' . $content . '</script>';
			},
			$html
		);

		// Add the restore script before </body>.
		$restore_script = self::get_delay_restore_script();
		$html = str_replace( '</body>', $restore_script . "\n</body>", $html );

		return $html;
	}

	/**
	 * Get the inline script that restores delayed scripts on user interaction.
	 *
	 * @return string
	 */
	private static function get_delay_restore_script() {
		return '<script id="flavoredge-delay-restore">
(function(){
var d=false;
function r(){
if(d)return;d=true;
var s=document.querySelectorAll("script[type=\"flavoredge-delay\"]");
var i=0;
function n(){
if(i>=s.length)return;
var o=s[i],c=document.createElement("script");
if(o.src){c.src=o.src;c.onload=c.onerror=function(){i++;n()}}
else{c.textContent=o.textContent;i++;setTimeout(n,1)}
Array.from(o.attributes).forEach(function(a){if(a.name!=="type")c.setAttribute(a.name,a.value)});
c.type="text/javascript";
o.parentNode.replaceChild(c,o);
if(!o.src){/* inline already executed */}
}
n();
}
["mouseover","click","keydown","touchstart","scroll"].forEach(function(e){
document.addEventListener(e,r,{once:true,passive:true})
});
if(document.readyState==="complete"){setTimeout(r,5000)}
else{window.addEventListener("load",function(){setTimeout(r,5000)})}
})();
</script>';
	}

	// -------------------------------------------------------------------------
	// HTML Minification.
	// -------------------------------------------------------------------------

	/**
	 * Minify HTML by removing unnecessary whitespace and comments.
	 *
	 * @param string $html HTML content.
	 * @return string
	 */
	private static function apply_minify_html( $html ) {
		// Don't minify if it looks like XML/RSS.
		if ( preg_match( '/^<\?xml/i', ltrim( $html ) ) ) {
			return $html;
		}

		// Preserve content in <pre>, <script>, <style>, <textarea> tags.
		$preserved = array();
		$index     = 0;

		$html = preg_replace_callback(
			'/<(pre|script|style|textarea)\b[^>]*>.*?<\/\1>/is',
			function ( $matches ) use ( &$preserved, &$index ) {
				$key                = '<!--FLAVOR_PRESERVE_' . $index . '-->';
				$preserved[ $key ] = $matches[0];
				$index++;
				return $key;
			},
			$html
		);

		// Remove HTML comments (except IE conditionals and preserved markers).
		$html = preg_replace( '/<!--(?!FLAVOR_PRESERVE_|\[if).*?-->/s', '', $html );

		// Collapse whitespace between tags.
		$html = preg_replace( '/>\s+</', '> <', $html );

		// Remove leading whitespace on lines.
		$html = preg_replace( '/^\s+/m', '', $html );

		// Collapse multiple spaces/newlines into one space.
		$html = preg_replace( '/\s{2,}/', ' ', $html );

		// Restore preserved content.
		$html = str_replace( array_keys( $preserved ), array_values( $preserved ), $html );

		return $html;
	}

	// -------------------------------------------------------------------------
	// DNS Prefetch.
	// -------------------------------------------------------------------------

	/**
	 * Add dns-prefetch and preconnect hints.
	 */
	public static function add_dns_prefetch() {
		$domains = array();

		// Auto-detect common third-party services.
		$auto = self::detect_third_party_domains();
		$domains = array_merge( $domains, $auto );

		// Manual entries.
		$manual = array_filter( array_map( 'trim', explode( "\n", TE_Settings::get( 'dns_prefetch_urls', '' ) ) ) );
		$domains = array_merge( $domains, $manual );

		// Deduplicate and remove own domain.
		$own_host = wp_parse_url( home_url(), PHP_URL_HOST );
		$domains = array_unique( array_filter( $domains ) );
		$domains = array_filter( $domains, function( $d ) use ( $own_host ) {
			$host = wp_parse_url( $d, PHP_URL_HOST );
			return $host && $host !== $own_host;
		});

		foreach ( $domains as $url ) {
			$url = esc_url( $url );
			if ( ! empty( $url ) ) {
				echo '<link rel="dns-prefetch" href="' . $url . '">' . "\n";
				echo '<link rel="preconnect" href="' . $url . '" crossorigin>' . "\n";
			}
		}
	}

	/**
	 * Auto-detect third-party domains based on active plugins.
	 *
	 * @return array List of origin URLs.
	 */
	public static function detect_third_party_domains() {
		$domains = array();
		$active  = get_option( 'active_plugins', array() );

		// Google Fonts (unless self-hosted).
		if ( ! TE_Settings::get( 'selfhost_google_fonts' ) ) {
			$domains[] = 'https://fonts.googleapis.com';
			$domains[] = 'https://fonts.gstatic.com';
		}

		// Google Analytics / Tag Manager.
		$ga_plugins = array(
			'google-analytics-for-wordpress/googleanalytics.php',
			'google-site-kit/google-site-kit.php',
			'ga-google-analytics/ga-google-analytics.php',
			'gtm4wp/gtm4wp.php',
		);
		foreach ( $ga_plugins as $p ) {
			if ( in_array( $p, $active, true ) ) {
				$domains[] = 'https://www.googletagmanager.com';
				$domains[] = 'https://www.google-analytics.com';
				break;
			}
		}

		// Facebook Pixel.
		if ( in_array( 'pixelyoursite/pixelyoursite.php', $active, true ) ||
		     in_array( 'facebook-for-woocommerce/facebook-for-woocommerce.php', $active, true ) ) {
			$domains[] = 'https://connect.facebook.net';
		}

		// HubSpot.
		if ( in_array( 'leadin/leadin.php', $active, true ) ) {
			$domains[] = 'https://js.hs-scripts.com';
			$domains[] = 'https://js.hsforms.net';
		}

		// WooCommerce payment gateways.
		if ( class_exists( 'WooCommerce' ) ) {
			$domains[] = 'https://js.stripe.com';
		}

		// Gravatar (if comments with avatars enabled).
		if ( get_option( 'show_avatars' ) ) {
			$domains[] = 'https://secure.gravatar.com';
		}

		return apply_filters( 'flavor_edge_auto_prefetch_domains', $domains );
	}
}
