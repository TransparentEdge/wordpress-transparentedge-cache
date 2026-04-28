<?php
/**
 * CSS & JS Minification + Defer module.
 *
 * Minifies CSS/JS files on the fly and caches results to disk.
 * Also handles defer attribute for JS files.
 *
 * Cache dir: wp-content/cache/flavor-edge/min/
 * Cache key: md5 of file content + mtime → only reprocesses on change.
 *
 * @package flavor_edge_cache
 */

namespace flavor_edge;

defined( 'ABSPATH' ) || exit;

class TE_Minify {

	/**
	 * Cache directory (relative to wp-content).
	 */
	const CACHE_DIR = 'cache/flavor-edge/min';

	/**
	 * Initialize minification hooks.
	 */
	public static function init() {
		if ( ! TE_Settings::get( 'enabled' ) ) {
			return;
		}

		// Don't minify for logged-in users, admin, AJAX, cron.
		if ( is_admin() || wp_doing_ajax() || wp_doing_cron() ) {
			return;
		}
		if ( defined( 'REST_REQUEST' ) && REST_REQUEST ) {
			return;
		}

		$s = TE_Settings::get_all();

		// Combine CSS (must run BEFORE minify to catch all files).
		if ( $s['combine_css'] ) {
			add_action( 'wp_print_styles', array( __CLASS__, 'combine_css_files' ), 9999 );
		}

		// Combine JS (footer scripts only for safety).
		if ( $s['combine_js'] ) {
			add_action( 'wp_print_footer_scripts', array( __CLASS__, 'combine_js_files' ), 1 );
		}

		// Defer JS via wp_script_loader_tag.
		if ( $s['defer_js'] ) {
			add_filter( 'script_loader_tag', array( __CLASS__, 'add_defer_attribute' ), 10, 3 );
		}

		// Minify CSS via style_loader_src.
		if ( $s['minify_css'] ) {
			add_filter( 'style_loader_src', array( __CLASS__, 'minify_css_src' ), 10, 2 );
		}

		// Minify JS via script_loader_src.
		if ( $s['minify_js'] ) {
			add_filter( 'script_loader_src', array( __CLASS__, 'minify_js_src' ), 10, 2 );
		}

		// Admin AJAX: clear minify cache.
		add_action( 'wp_ajax_flavor_edge_clear_minify_cache', array( __CLASS__, 'ajax_clear_cache' ) );
	}

	// -------------------------------------------------------------------------
	// Defer JS.
	// -------------------------------------------------------------------------

	/**
	 * Add defer attribute to script tags.
	 *
	 * @param string $tag    Full script tag.
	 * @param string $handle Script handle.
	 * @param string $src    Script source URL.
	 * @return string Modified tag.
	 */
	public static function add_defer_attribute( $tag, $handle, $src ) {
		// Skip if already has defer or async.
		if ( false !== strpos( $tag, ' defer' ) || false !== strpos( $tag, ' async' ) ) {
			return $tag;
		}

		// Skip inline scripts (no src).
		if ( empty( $src ) ) {
			return $tag;
		}

		// Never defer jQuery and critical scripts.
		$exclude = array(
			'jquery-core', 'jquery', 'jquery-migrate',
			'wp-polyfill', 'wp-hooks', 'wp-i18n',
		);

		// User exclusions.
		$user_exclude = array_filter( array_map( 'trim', explode( "\n", TE_Settings::get( 'defer_js_exclusions', '' ) ) ) );
		$exclude = array_merge( $exclude, $user_exclude );

		foreach ( $exclude as $pattern ) {
			if ( ! empty( $pattern ) && ( $handle === $pattern || false !== strpos( $src, $pattern ) ) ) {
				return $tag;
			}
		}

		// Add defer.
		$tag = str_replace( ' src=', ' defer src=', $tag );

		return $tag;
	}

	// -------------------------------------------------------------------------
	// CSS Minification.
	// -------------------------------------------------------------------------

	/**
	 * Minify a CSS file and return the cached URL.
	 *
	 * @param string $src    CSS source URL.
	 * @param string $handle Style handle.
	 * @return string Minified URL or original if minification fails.
	 */
	public static function minify_css_src( $src, $handle ) {
		// Skip external URLs.
		$local_path = self::url_to_path( $src );
		if ( ! $local_path ) {
			return $src;
		}

		// Skip already minified files.
		if ( preg_match( '/\.min\.css$/i', $local_path ) ) {
			return $src;
		}

		// Skip admin styles.
		if ( false !== strpos( $src, '/wp-admin/' ) ) {
			return $src;
		}

		$cached = self::get_cached_file( $local_path, 'css' );
		if ( $cached ) {
			return $cached['url'];
		}

		// Minify.
		$content = file_get_contents( $local_path );
		if ( false === $content || strlen( $content ) < 50 ) {
			return $src;
		}

		// Rewrite relative URLs to absolute before moving to cache dir.
		$content = self::rewrite_css_urls( $content, $local_path );

		$minified = self::minify_css_content( $content );

		$saved = self::save_cached_file( $local_path, $minified, 'css' );
		if ( $saved ) {
			return $saved['url'];
		}

		return $src;
	}

	/**
	 * Rewrite relative URLs in CSS to absolute URLs.
	 *
	 * When we move a CSS file from its original location to the cache dir,
	 * relative url() references (fonts, images, etc.) break. This method
	 * converts them to absolute URLs based on the original file's location.
	 *
	 * @param string $css         CSS content.
	 * @param string $source_path Original file path.
	 * @return string CSS with absolute URLs.
	 */
	private static function rewrite_css_urls( $css, $source_path ) {
		$source_dir = dirname( $source_path );

		// Convert source directory to URL.
		$source_dir_url = self::path_to_url( $source_dir );
		if ( ! $source_dir_url ) {
			return $css;
		}

		// Ensure trailing slash.
		$source_dir_url = trailingslashit( $source_dir_url );

		// Rewrite url() references that are relative.
		return preg_replace_callback(
			'/url\(\s*(["\']?)([^"\')\s][^)]*)\1\s*\)/i',
			function ( $matches ) use ( $source_dir_url ) {
				$quote = $matches[1];
				$url   = trim( $matches[2] );

				// Skip absolute URLs, data URIs, and protocol-relative.
				if ( preg_match( '/^(https?:|\/\/|data:|#)/i', $url ) ) {
					return $matches[0];
				}

				// Skip absolute paths.
				if ( 0 === strpos( $url, '/' ) ) {
					return $matches[0];
				}

				// Convert relative to absolute.
				$absolute = $source_dir_url . $url;

				return 'url(' . $quote . $absolute . $quote . ')';
			},
			$css
		);
	}

	/**
	 * Convert a local file path to a URL.
	 *
	 * @param string $path File path.
	 * @return string|false URL or false.
	 */
	private static function path_to_url( $path ) {
		// Normalize path separators.
		$path = wp_normalize_path( $path );

		$content_dir = wp_normalize_path( WP_CONTENT_DIR );
		$abspath     = wp_normalize_path( ABSPATH );

		if ( 0 === strpos( $path, $content_dir ) ) {
			$relative = substr( $path, strlen( $content_dir ) );
			return content_url( $relative );
		}

		if ( 0 === strpos( $path, $abspath ) ) {
			$relative = substr( $path, strlen( $abspath ) );
			return site_url( '/' . $relative );
		}

		return false;
	}

	/**
	 * Minify CSS content.
	 *
	 * @param string $css Raw CSS.
	 * @return string Minified CSS.
	 */
	public static function minify_css_content( $css ) {
		// Remove comments.
		$css = preg_replace( '/\/\*.*?\*\//s', '', $css );
		// Remove whitespace around selectors and properties.
		$css = preg_replace( '/\s*([{}:;,>~+])\s*/', '$1', $css );
		// Remove remaining whitespace.
		$css = preg_replace( '/\s{2,}/', ' ', $css );
		// Remove trailing semicolons before }.
		$css = str_replace( ';}', '}', $css );
		// Trim.
		$css = trim( $css );

		return $css;
	}

	// -------------------------------------------------------------------------
	// JS Minification.
	// -------------------------------------------------------------------------

	/**
	 * Minify a JS file and return the cached URL.
	 *
	 * @param string $src    JS source URL.
	 * @param string $handle Script handle.
	 * @return string Minified URL or original.
	 */
	public static function minify_js_src( $src, $handle ) {
		$local_path = self::url_to_path( $src );
		if ( ! $local_path ) {
			return $src;
		}

		// Skip already minified files.
		if ( preg_match( '/\.min\.js$/i', $local_path ) ) {
			return $src;
		}

		// Skip admin scripts.
		if ( false !== strpos( $src, '/wp-admin/' ) ) {
			return $src;
		}

		// Skip jQuery core (always minified already).
		if ( false !== strpos( $src, 'jquery' ) ) {
			return $src;
		}

		$cached = self::get_cached_file( $local_path, 'js' );
		if ( $cached ) {
			return $cached['url'];
		}

		$content = file_get_contents( $local_path );
		if ( false === $content || strlen( $content ) < 50 ) {
			return $src;
		}

		$minified = self::minify_js_content( $content );

		$saved = self::save_cached_file( $local_path, $minified, 'js' );
		if ( $saved ) {
			return $saved['url'];
		}

		return $src;
	}

	/**
	 * Minify JS content (safe, conservative approach).
	 *
	 * Does NOT remove single-line comments aggressively (regex, URLs).
	 * Focuses on whitespace and block comments.
	 *
	 * @param string $js Raw JS.
	 * @return string Minified JS.
	 */
	public static function minify_js_content( $js ) {
		// Remove block comments (but not conditional compilation).
		$js = preg_replace( '/\/\*(?!@cc_on).*?\*\//s', '', $js );
		// Remove single-line comments (only at start of line or after semicolons/braces).
		$js = preg_replace( '/(?<=^|[;{}()\n])\s*\/\/[^\n]*$/m', '', $js );
		// Collapse multiple whitespace to single.
		$js = preg_replace( '/[ \t]{2,}/', ' ', $js );
		// Remove empty lines.
		$js = preg_replace( '/\n{2,}/', "\n", $js );
		// Trim lines.
		$js = implode( "\n", array_map( 'trim', explode( "\n", $js ) ) );
		// Remove empty lines again.
		$js = preg_replace( '/\n{2,}/', "\n", $js );
		// Trim.
		$js = trim( $js );

		return $js;
	}

	// -------------------------------------------------------------------------
	// CSS/JS Combine (reduce HTTP requests).
	// -------------------------------------------------------------------------

	/**
	 * Scripts/styles to never combine (core WP, jQuery, etc.).
	 */
	private static $combine_exclude = array(
		'jquery-core', 'jquery', 'jquery-migrate',
		'wp-polyfill', 'wp-hooks', 'wp-i18n', 'wp-a11y',
		'admin-bar', 'wp-embed', 'wp-emoji',
	);

	/**
	 * Combine all local, non-conditional CSS files into one.
	 */
	public static function combine_css_files() {
		global $wp_styles;
		if ( empty( $wp_styles ) || empty( $wp_styles->queue ) ) {
			return;
		}

		$to_combine = array();
		$combined_content = '';
		$hash_parts = array();
		$s = TE_Settings::get_all();

		foreach ( $wp_styles->queue as $handle ) {
			if ( ! isset( $wp_styles->registered[ $handle ] ) ) {
				continue;
			}
			$dep = $wp_styles->registered[ $handle ];

			// Skip: no src, conditional, external, admin, already processed.
			if ( empty( $dep->src ) ) { continue; }
			if ( ! empty( $dep->extra['conditional'] ) ) { continue; }
			if ( isset( $dep->extra['alt'] ) && $dep->extra['alt'] ) { continue; }

			$local_path = self::url_to_path( $dep->src );
			if ( ! $local_path ) { continue; } // External — skip.

			// Skip admin styles.
			if ( false !== strpos( $dep->src, '/wp-admin/' ) ) { continue; }

			$to_combine[ $handle ] = array(
				'path' => $local_path,
				'src'  => $dep->src,
				'ver'  => $dep->ver ?: filemtime( $local_path ),
			);
			$hash_parts[] = $handle . ':' . ( $dep->ver ?: filemtime( $local_path ) );
		}

		if ( count( $to_combine ) < 2 ) {
			return; // No point combining 1 file.
		}

		// Cache key based on handles + versions.
		$cache_key = 'combined-css-' . substr( md5( implode( '|', $hash_parts ) ), 0, 12 );
		$cache_file = self::get_cache_dir() . '/' . $cache_key . '.css';
		$cache_url  = self::get_cache_url() . '/' . $cache_key . '.css';

		// Generate combined file if not cached.
		if ( ! file_exists( $cache_file ) ) {
			foreach ( $to_combine as $handle => $info ) {
				$content = @file_get_contents( $info['path'] );
				if ( false === $content ) { continue; }

				// Rewrite relative URLs.
				$content = self::rewrite_css_urls( $content, $info['path'] );

				// Minify if enabled.
				if ( $s['minify_css'] ) {
					$content = self::minify_css_content( $content );
				}

				$combined_content .= "/* {$handle} */\n{$content}\n";
			}

			// Ensure cache dir exists.
			if ( ! is_dir( self::get_cache_dir() ) ) {
				wp_mkdir_p( self::get_cache_dir() );
			}
			file_put_contents( $cache_file, $combined_content );
		}

		// Dequeue originals and enqueue combined.
		foreach ( array_keys( $to_combine ) as $handle ) {
			wp_dequeue_style( $handle );
			wp_deregister_style( $handle );
		}

		wp_enqueue_style( 'flavor-edge-combined-css', $cache_url, array(), null, 'all' );
	}

	/**
	 * Combine local JS files (footer scripts only, for safety).
	 */
	public static function combine_js_files() {
		global $wp_scripts;
		if ( empty( $wp_scripts ) || empty( $wp_scripts->queue ) ) {
			return;
		}

		$to_combine = array();
		$combined_content = '';
		$hash_parts = array();
		$s = TE_Settings::get_all();

		// User exclusions for combine.
		$user_exclude = array_filter( array_map( 'trim', explode( "\n", TE_Settings::get( 'combine_js_exclusions', '' ) ) ) );
		$all_exclude = array_merge( self::$combine_exclude, $user_exclude );

		foreach ( $wp_scripts->queue as $handle ) {
			if ( ! isset( $wp_scripts->registered[ $handle ] ) ) {
				continue;
			}
			$dep = $wp_scripts->registered[ $handle ];

			// Skip: no src, conditional, header scripts.
			if ( empty( $dep->src ) ) { continue; }
			if ( ! empty( $dep->extra['conditional'] ) ) { continue; }

			// Only footer scripts (group=1 means footer).
			if ( empty( $dep->extra['group'] ) ) { continue; }

			// Skip excluded handles and patterns.
			$skip = false;
			foreach ( $all_exclude as $pattern ) {
				if ( $handle === $pattern || ( ! empty( $pattern ) && false !== strpos( $dep->src, $pattern ) ) ) {
					$skip = true;
					break;
				}
			}
			if ( $skip ) { continue; }

			$local_path = self::url_to_path( $dep->src );
			if ( ! $local_path ) { continue; } // External.

			if ( false !== strpos( $dep->src, '/wp-admin/' ) ) { continue; }

			$to_combine[ $handle ] = array(
				'path'   => $local_path,
				'src'    => $dep->src,
				'ver'    => $dep->ver ?: filemtime( $local_path ),
				'before' => isset( $dep->extra['before'] ) ? $dep->extra['before'] : array(),
				'after'  => isset( $dep->extra['after'] ) ? $dep->extra['after'] : array(),
				'data'   => isset( $dep->extra['data'] ) ? $dep->extra['data'] : '',
			);
			$hash_parts[] = $handle . ':' . ( $dep->ver ?: filemtime( $local_path ) );
		}

		if ( count( $to_combine ) < 2 ) {
			return;
		}

		$cache_key = 'combined-js-' . substr( md5( implode( '|', $hash_parts ) ), 0, 12 );
		$cache_file = self::get_cache_dir() . '/' . $cache_key . '.js';
		$cache_url  = self::get_cache_url() . '/' . $cache_key . '.js';

		// Collect inline scripts that need to stay.
		$inline_before = '';
		$inline_after  = '';

		if ( ! file_exists( $cache_file ) ) {
			foreach ( $to_combine as $handle => $info ) {
				$content = @file_get_contents( $info['path'] );
				if ( false === $content ) { continue; }

				// Minify if enabled.
				if ( $s['minify_js'] ) {
					$content = self::minify_js_content( $content );
				}

				$combined_content .= "/* {$handle} */\n{$content};\n";
			}

			if ( ! is_dir( self::get_cache_dir() ) ) {
				wp_mkdir_p( self::get_cache_dir() );
			}
			file_put_contents( $cache_file, $combined_content );
		}

		// Collect wp_localize_script data and inline scripts before dequeuing.
		foreach ( $to_combine as $handle => $info ) {
			if ( ! empty( $info['data'] ) ) {
				$inline_before .= $info['data'] . "\n";
			}
			if ( ! empty( $info['before'] ) ) {
				$inline_before .= implode( "\n", (array) $info['before'] ) . "\n";
			}
			if ( ! empty( $info['after'] ) ) {
				$inline_after .= implode( "\n", (array) $info['after'] ) . "\n";
			}
		}

		// Dequeue originals.
		foreach ( array_keys( $to_combine ) as $handle ) {
			wp_dequeue_script( $handle );
		}

		// Enqueue combined file.
		wp_enqueue_script( 'flavor-edge-combined-js', $cache_url, array( 'jquery' ), null, true );

		// Re-attach inline scripts.
		if ( ! empty( $inline_before ) ) {
			wp_add_inline_script( 'flavor-edge-combined-js', $inline_before, 'before' );
		}
		if ( ! empty( $inline_after ) ) {
			wp_add_inline_script( 'flavor-edge-combined-js', $inline_after, 'after' );
		}
	}

	// -------------------------------------------------------------------------
	// File cache management.
	// -------------------------------------------------------------------------

	/**
	 * Get the cache directory path.
	 *
	 * @return string Absolute path.
	 */
	public static function get_cache_dir() {
		return WP_CONTENT_DIR . '/' . self::CACHE_DIR;
	}

	/**
	 * Get the cache directory URL.
	 *
	 * @return string
	 */
	public static function get_cache_url() {
		return content_url( self::CACHE_DIR );
	}

	/**
	 * Convert a URL to a local file path.
	 *
	 * @param string $url URL to convert.
	 * @return string|false Local path or false if not local.
	 */
	private static function url_to_path( $url ) {
		// Strip query string.
		$url = strtok( $url, '?' );

		// Check if it's a local URL.
		$site_url    = site_url();
		$content_url = content_url();
		$includes_url = includes_url();

		$path = false;

		if ( 0 === strpos( $url, $content_url ) ) {
			$relative = substr( $url, strlen( $content_url ) );
			$path     = WP_CONTENT_DIR . $relative;
		} elseif ( 0 === strpos( $url, $includes_url ) ) {
			$relative = substr( $url, strlen( $includes_url ) );
			$path     = ABSPATH . WPINC . '/' . $relative;
		} elseif ( 0 === strpos( $url, $site_url ) ) {
			$relative = substr( $url, strlen( $site_url ) );
			$path     = ABSPATH . ltrim( $relative, '/' );
		}

		// Handle protocol-relative URLs.
		if ( ! $path && 0 === strpos( $url, '//' ) ) {
			$full_url = 'https:' . $url;
			return self::url_to_path( $full_url );
		}

		// Handle root-relative URLs.
		if ( ! $path && 0 === strpos( $url, '/' ) ) {
			$path = ABSPATH . ltrim( $url, '/' );
		}

		if ( $path && file_exists( $path ) && is_readable( $path ) ) {
			// Path traversal protection: ensure resolved path is within WordPress.
			$real = realpath( $path );
			$abspath_real = realpath( ABSPATH );
			if ( false === $real || false === $abspath_real || 0 !== strpos( $real, $abspath_real ) ) {
				return false;
			}
			return $real;
		}

		return false;
	}

	/**
	 * Get a cached version of a file if it exists and is fresh.
	 *
	 * @param string $source_path Original file path.
	 * @param string $type        'css' or 'js'.
	 * @return array|false { path, url } or false if not cached.
	 */
	private static function get_cached_file( $source_path, $type ) {
		$cache_key  = self::get_cache_key( $source_path );
		$cache_file = self::get_cache_dir() . '/' . $cache_key . '.' . $type;

		if ( ! file_exists( $cache_file ) ) {
			return false;
		}

		// Check if source has been modified since cache was created.
		if ( filemtime( $source_path ) > filemtime( $cache_file ) ) {
			return false;
		}

		return array(
			'path' => $cache_file,
			'url'  => self::get_cache_url() . '/' . $cache_key . '.' . $type,
		);
	}

	/**
	 * Save a minified file to cache.
	 *
	 * @param string $source_path Original file path.
	 * @param string $content     Minified content.
	 * @param string $type        'css' or 'js'.
	 * @return array|false { path, url } or false on failure.
	 */
	private static function save_cached_file( $source_path, $content, $type ) {
		$cache_dir = self::get_cache_dir();

		// Create cache directory if needed.
		if ( ! is_dir( $cache_dir ) ) {
			wp_mkdir_p( $cache_dir );

			// Add .htaccess for direct serving.
			$htaccess = $cache_dir . '/.htaccess';
			if ( ! file_exists( $htaccess ) ) {
				file_put_contents( $htaccess, "# Serve directly\n<IfModule mod_rewrite.c>\nRewriteEngine Off\n</IfModule>\n" );
			}

			// Add index.php for security.
			$index = $cache_dir . '/index.php';
			if ( ! file_exists( $index ) ) {
				file_put_contents( $index, '<?php // Silence is golden.' );
			}
		}

		$cache_key  = self::get_cache_key( $source_path );
		$cache_file = $cache_dir . '/' . $cache_key . '.' . $type;

		if ( false === file_put_contents( $cache_file, $content ) ) {
			return false;
		}

		return array(
			'path' => $cache_file,
			'url'  => self::get_cache_url() . '/' . $cache_key . '.' . $type,
		);
	}

	/**
	 * Generate a cache key for a source file.
	 *
	 * @param string $source_path File path.
	 * @return string Cache key (filename-safe hash).
	 */
	private static function get_cache_key( $source_path ) {
		$basename = pathinfo( $source_path, PATHINFO_FILENAME );
		// Include plugin version so cache auto-invalidates on update.
		$hash     = substr( md5( $source_path . filemtime( $source_path ) . FLAVOR_EDGE_VERSION ), 0, 8 );
		$basename = preg_replace( '/[^a-zA-Z0-9_-]/', '_', $basename );
		$basename = substr( $basename, 0, 40 );

		return $basename . '-' . $hash;
	}

	// -------------------------------------------------------------------------
	// Cache management.
	// -------------------------------------------------------------------------

	/**
	 * Clear the minification cache.
	 *
	 * @return array { files_deleted, bytes_freed }
	 */
	public static function clear_cache() {
		$cache_dir     = self::get_cache_dir();
		$files_deleted = 0;
		$bytes_freed   = 0;

		if ( ! is_dir( $cache_dir ) ) {
			return array( 'files_deleted' => 0, 'bytes_freed' => 0 );
		}

		$files = glob( $cache_dir . '/*.{css,js}', GLOB_BRACE );
		if ( $files ) {
			foreach ( $files as $file ) {
				$bytes_freed += filesize( $file );
				unlink( $file );
				$files_deleted++;
			}
		}

		return array(
			'files_deleted' => $files_deleted,
			'bytes_freed'   => $bytes_freed,
		);
	}

	/**
	 * Get cache statistics.
	 *
	 * @return array { files, total_size, css_files, js_files }
	 */
	public static function get_cache_stats() {
		$cache_dir = self::get_cache_dir();
		$stats     = array(
			'files'      => 0,
			'total_size' => 0,
			'css_files'  => 0,
			'js_files'   => 0,
		);

		if ( ! is_dir( $cache_dir ) ) {
			return $stats;
		}

		$files = glob( $cache_dir . '/*.{css,js}', GLOB_BRACE );
		if ( $files ) {
			foreach ( $files as $file ) {
				$stats['files']++;
				$stats['total_size'] += filesize( $file );
				if ( preg_match( '/\.css$/', $file ) ) {
					$stats['css_files']++;
				} else {
					$stats['js_files']++;
				}
			}
		}

		return $stats;
	}

	/**
	 * AJAX handler to clear minify cache.
	 */
	public static function ajax_clear_cache() {
		check_ajax_referer( 'flavor_edge_admin', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( 'Unauthorized' );
		}

		$result = self::clear_cache();
		wp_send_json_success( $result );
	}
}
