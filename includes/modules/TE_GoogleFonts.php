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
	/** Cron hook that builds a local font cache off the critical path. */
	const BUILD_HOOK = 'flavor_edge_gf_build';

	public static function init() {
		if ( ! TE_Settings::get( 'enabled' ) || ! TE_Settings::get( 'selfhost_google_fonts' ) ) {
			return;
		}

		// The cache is built in the background (wp-cron), so this handler MUST be
		// registered even during cron — register it before the front-end guard.
		add_action( self::BUILD_HOOK, array( __CLASS__, 'build_cache' ), 10, 1 );

		if ( is_admin() || wp_doing_ajax() || wp_doing_cron() ) {
			return;
		}

		// Intercept Google Fonts CSS in enqueued styles.
		add_filter( 'style_loader_src', array( __CLASS__, 'intercept_google_fonts_css' ), 5, 2 );

		// Also handle fonts loaded via <link> in the final HTML (applied by
		// TE_Frontend::process_html via the flavor_edge_html_output filter).
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
		$hash      = md5( $google_url );
		$css_file  = $cache_dir . '/gf-' . $hash . '.css';
		$css_url   = $cache_url . '/gf-' . $hash . '.css';

		if ( file_exists( $css_file ) ) {
			// Serve the local copy. If it is stale (>30 days) refresh it in the
			// BACKGROUND — never block the request to re-download.
			if ( ( time() - filemtime( $css_file ) ) >= ( 30 * DAY_IN_SECONDS ) ) {
				self::queue_build( $google_url );
			}
			return $css_url;
		}

		// Not cached yet. Do NOT download synchronously here: this runs while the
		// <head> is being built, and a CSS fetch plus several font downloads can
		// exceed max_execution_time → white screen on the first uncached hit. Build
		// it in the background and let this request fall back to Google's URL (return
		// false) until the local copy is ready on a later request.
		self::queue_build( $google_url );
		return false;
	}

	/**
	 * Schedule a one-off background build for a Google Fonts URL, guarded by a lock
	 * so concurrent first-hits do not all queue (or run) the download.
	 *
	 * @param string $google_url Google Fonts CSS URL.
	 */
	private static function queue_build( $google_url ) {
		$lock = 'flavor_edge_gf_lock_' . md5( $google_url );
		if ( get_transient( $lock ) ) {
			return; // A build is already queued/running for this URL.
		}
		set_transient( $lock, 1, 5 * MINUTE_IN_SECONDS );
		if ( ! wp_next_scheduled( self::BUILD_HOOK, array( $google_url ) ) ) {
			wp_schedule_single_event( time() + 1, self::BUILD_HOOK, array( $google_url ) );
		}
	}

	/**
	 * Build the local font cache for a Google Fonts URL. Runs in the background
	 * (wp-cron), off the request critical path, with atomic file writes so a
	 * concurrent reader never sees a half-written CSS/font.
	 *
	 * @param string $google_url Google Fonts CSS URL.
	 */
	public static function build_cache( $google_url ) {
		$lock = 'flavor_edge_gf_lock_' . md5( $google_url );

		if ( ! self::is_google_fonts_url( $google_url ) ) {
			delete_transient( $lock );
			return;
		}

		$cache_dir = WP_CONTENT_DIR . '/' . self::CACHE_DIR;
		$cache_url = content_url( self::CACHE_DIR );
		$hash      = md5( $google_url );
		$css_file  = $cache_dir . '/gf-' . $hash . '.css';

		$response = wp_remote_get( $google_url, array(
			'timeout'     => 10,
			'httpversion' => '1.1',
			'user-agent'  => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
		) );

		if ( is_wp_error( $response ) || 200 !== wp_remote_retrieve_response_code( $response ) ) {
			// Network failure: KEEP the lock as a backoff so an unreachable/blocked
			// Google endpoint doesn't make every front-end hit queue another cron job
			// (each a ~10s outbound fetch). It expires on its own and retries later.
			set_transient( $lock, 1, 5 * MINUTE_IN_SECONDS );
			return;
		}

		$css = wp_remote_retrieve_body( $response );
		if ( empty( $css ) ) {
			set_transient( $lock, 1, 5 * MINUTE_IN_SECONDS ); // backoff, as above.
			return;
		}

		if ( ! is_dir( $cache_dir ) ) {
			wp_mkdir_p( $cache_dir );
			@file_put_contents( $cache_dir . '/index.php', '<?php // Silence is golden.' );
		}

		$css = preg_replace_callback(
			'/url\((["\']?)(https?:\/\/fonts\.gstatic\.com\/[^"\')\s]+)\1\)/i',
			function ( $matches ) use ( $cache_dir, $cache_url ) {
				$font_url = $matches[2];
				$font_ext = strtolower( pathinfo( strtok( $font_url, '?' ), PATHINFO_EXTENSION ) );
				if ( ! in_array( $font_ext, array( 'woff2', 'woff', 'ttf', 'otf', 'eot', 'svg' ), true ) ) {
					$font_ext = 'woff2';
				}
				$font_hash      = md5( $font_url );
				$font_file      = $cache_dir . '/font-' . $font_hash . '.' . $font_ext;
				$font_local_url = $cache_url . '/font-' . $font_hash . '.' . $font_ext;

				if ( ! file_exists( $font_file ) ) {
					$font_response = wp_remote_get( $font_url, array( 'timeout' => 15, 'httpversion' => '1.1' ) );
					if ( ! is_wp_error( $font_response ) && 200 === wp_remote_retrieve_response_code( $font_response ) ) {
						TE_ObjectCache::write_atomic( $font_file, wp_remote_retrieve_body( $font_response ) );
					} else {
						return $matches[0]; // Keep the original URL for this font.
					}
				}

				return 'url(' . $font_local_url . ')';
			},
			$css
		);

		// Atomic write so a request reading the CSS never sees it half-written.
		TE_ObjectCache::write_atomic( $css_file, $css );
		delete_transient( $lock );
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
