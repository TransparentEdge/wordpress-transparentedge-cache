<?php
/**
 * Self-host Google Fonts module.
 *
 * Intercepts Google Fonts requests, downloads font files locally,
 * and rewrites URLs to serve from the same domain.
 * Benefits: better TTFB (no DNS lookup to fonts.googleapis.com),
 * GDPR compliance (no data sent to Google).
 *
 * Cache dir: wp-content/cache/flavor-edge/fonts/
 *
 * @package flavor_edge_cache
 */

namespace flavor_edge;

defined( 'ABSPATH' ) || exit;

class TE_GoogleFonts {

	const CACHE_DIR = 'cache/flavor-edge/fonts';

	/**
	 * Initialize.
	 */
	public static function init() {
		if ( ! TE_Settings::get( 'enabled' ) || ! TE_Settings::get( 'selfhost_google_fonts' ) ) {
			return;
		}

		if ( is_admin() || wp_doing_ajax() || wp_doing_cron() ) {
			return;
		}

		// Intercept Google Fonts CSS in enqueued styles.
		add_filter( 'style_loader_src', array( __CLASS__, 'intercept_google_fonts_css' ), 5, 2 );

		// Also handle fonts loaded via <link> in the HTML output buffer.
		add_filter( 'flavor_edge_html_output', array( __CLASS__, 'rewrite_html_google_fonts' ), 5 );
	}

	/**
	 * Intercept Google Fonts CSS loaded via wp_enqueue_style.
	 *
	 * @param string $src    Style URL.
	 * @param string $handle Style handle.
	 * @return string Local URL or original.
	 */
	public static function intercept_google_fonts_css( $src, $handle ) {
		if ( ! self::is_google_fonts_url( $src ) ) {
			return $src;
		}

		$local = self::get_local_stylesheet( $src );
		if ( $local ) {
			return $local;
		}

		return $src;
	}

	/**
	 * Rewrite Google Fonts <link> tags in final HTML.
	 * Catches fonts loaded outside wp_enqueue_style (inline, theme).
	 *
	 * @param string $html HTML content.
	 * @return string Modified HTML.
	 */
	public static function rewrite_html_google_fonts( $html ) {
		// Find all Google Fonts CSS links.
		return preg_replace_callback(
			'/<link[^>]+href=["\']([^"\']*fonts\.googleapis\.com\/css2?[^"\']*)["\'][^>]*>/i',
			function ( $matches ) {
				$google_url = $matches[1];

				// Ensure full URL.
				if ( 0 === strpos( $google_url, '//' ) ) {
					$google_url = 'https:' . $google_url;
				}

				$local = self::get_local_stylesheet( $google_url );
				if ( $local ) {
					return str_replace( $matches[1], $local, $matches[0] );
				}

				return $matches[0];
			},
			$html
		);
	}

	/**
	 * Check if a URL is a Google Fonts CSS URL.
	 *
	 * @param string $url URL to check.
	 * @return bool
	 */
	private static function is_google_fonts_url( $url ) {
		return (bool) preg_match( '/fonts\.googleapis\.com\/css/i', $url );
	}

	/**
	 * Get (or create) a local copy of a Google Fonts stylesheet.
	 * Downloads the CSS, parses font URLs, downloads font files,
	 * rewrites CSS to use local paths.
	 *
	 * @param string $google_url Google Fonts CSS URL.
	 * @return string|false Local CSS URL or false on failure.
	 */
	private static function get_local_stylesheet( $google_url ) {
		$cache_dir = WP_CONTENT_DIR . '/' . self::CACHE_DIR;
		$cache_url = content_url( self::CACHE_DIR );

		// Generate a stable filename for this Google Fonts request.
		$hash      = md5( $google_url );
		$css_file  = $cache_dir . '/gf-' . $hash . '.css';
		$css_url   = $cache_url . '/gf-' . $hash . '.css';

		// Serve from cache if exists and less than 30 days old.
		if ( file_exists( $css_file ) && ( time() - filemtime( $css_file ) ) < ( 30 * DAY_IN_SECONDS ) ) {
			return $css_url;
		}

		// Download the Google Fonts CSS.
		// Use a modern User-Agent to get woff2 format.
		$response = wp_remote_get( $google_url, array(
			'timeout'    => 10,
			'user-agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
		) );

		if ( is_wp_error( $response ) || 200 !== wp_remote_retrieve_response_code( $response ) ) {
			return false;
		}

		$css = wp_remote_retrieve_body( $response );
		if ( empty( $css ) ) {
			return false;
		}

		// Create cache directory.
		if ( ! is_dir( $cache_dir ) ) {
			wp_mkdir_p( $cache_dir );
			file_put_contents( $cache_dir . '/index.php', '<?php // Silence is golden.' );
		}

		// Find all font URLs in the CSS and download them.
		$css = preg_replace_callback(
			'/url\((["\']?)(https?:\/\/fonts\.gstatic\.com\/[^"\')\s]+)\1\)/i',
			function ( $matches ) use ( $cache_dir, $cache_url ) {
				$font_url = $matches[2];
				$font_ext = pathinfo( strtok( $font_url, '?' ), PATHINFO_EXTENSION ) ?: 'woff2';
				$font_hash = md5( $font_url );
				$font_file = $cache_dir . '/font-' . $font_hash . '.' . $font_ext;
				$font_local_url = $cache_url . '/font-' . $font_hash . '.' . $font_ext;

				// Download font if not cached.
				if ( ! file_exists( $font_file ) ) {
					$font_response = wp_remote_get( $font_url, array(
						'timeout' => 15,
					) );

					if ( ! is_wp_error( $font_response ) && 200 === wp_remote_retrieve_response_code( $font_response ) ) {
						file_put_contents( $font_file, wp_remote_retrieve_body( $font_response ) );
					} else {
						// Can't download — keep original URL.
						return $matches[0];
					}
				}

				return 'url(' . $font_local_url . ')';
			},
			$css
		);

		// Save the rewritten CSS.
		file_put_contents( $css_file, $css );

		return $css_url;
	}

	/**
	 * Clear the Google Fonts cache.
	 *
	 * @return int Number of files deleted.
	 */
	public static function clear_cache() {
		$cache_dir = WP_CONTENT_DIR . '/' . self::CACHE_DIR;
		$count     = 0;

		if ( ! is_dir( $cache_dir ) ) {
			return 0;
		}

		$files = glob( $cache_dir . '/{gf-*.css,font-*.*}', GLOB_BRACE );
		if ( $files ) {
			foreach ( $files as $file ) {
				unlink( $file );
				$count++;
			}
		}

		return $count;
	}
}
