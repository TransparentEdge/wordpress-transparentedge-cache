<?php
/**
 * Origin page cache controller.
 *
 * Wires the storage engine (TE_PageCache_Store) into WordPress:
 *   - SERVE: generates the advanced-cache.php drop-in that serves a cached
 *     document before WordPress boots, and toggles
 *     WP_CACHE in wp-config.php.
 *   - STORE: buffers anonymous cacheable responses and stores them on shutdown.
 *   - INVALIDATE: on any plugin purge, clears the page cache too, so one purge
 *     event flushes edge + origin coherently (the advantage over a standalone
 *     origin cache that only knew about itself).
 *
 * @package flavor_edge_cache
 */

namespace flavor_edge;

defined( 'ABSPATH' ) || exit;

class TE_PageCache {

	/** Markers used to add/remove our WP_CACHE define in wp-config.php. */
	const WPCONFIG_BEGIN = '/* BEGIN Flavor Edge Cache */';
	const WPCONFIG_END   = '/* END Flavor Edge Cache */';

	/** Whether capture already started for this request. */
	private static $buffering = false;

	// -------------------------------------------------------------------------
	// Boot.
	// -------------------------------------------------------------------------

	public static function init() {
		if ( is_admin() ) {
			add_action( 'wp_ajax_flavor_edge_enable_page_cache', array( __CLASS__, 'ajax_enable' ) );
			add_action( 'wp_ajax_flavor_edge_disable_page_cache', array( __CLASS__, 'ajax_disable' ) );
			add_action( 'wp_ajax_flavor_edge_flush_page_cache', array( __CLASS__, 'ajax_flush' ) );

			// Keep the drop-in config in sync with the saved settings.
			if ( self::is_enabled() ) {
				add_action( 'admin_init', array( __CLASS__, 'write_config_file' ) );
			}
		}

		if ( ! self::is_enabled() ) {
			return;
		}

		// STORE: start buffering as early as the main query is resolved.
		add_action( 'template_redirect', array( __CLASS__, 'maybe_buffer' ), 0 );

		// Keep the drop-in config in sync even on WP-CLI / programmatic saves.
		add_action( 'update_option_' . TE_Settings::OPTION_KEY, array( __CLASS__, 'write_config_file' ) );

		// INVALIDATE: a full purge empties everything; a tag or URL purge is
		// surgical, so a busy news site does not keep the shield permanently
		// cold (an empty page cache would defeat its whole purpose).
		add_action( 'flavor_edge_after_purge_all', array( __CLASS__, 'flush_all' ) );
		add_action( 'flavor_edge_after_tag_purge', array( __CLASS__, 'on_tag_purge' ), 10, 2 );
		add_action( 'flavor_edge_after_url_purge', array( __CLASS__, 'on_url_purge' ), 10, 2 );
	}

	public static function is_enabled() {
		return ! empty( TE_Settings::get( 'page_cache_enabled' ) );
	}

	/**
	 * Whether the origin page cache is actually SERVING, not just switched on in
	 * the options. The drop-in only runs when WP_CACHE is true AND our
	 * advanced-cache.php is in place; if wp-config.php could not be written (e.g.
	 * not writable), the option can be on while nothing is served. The dashboard
	 * must reflect that instead of claiming "Enabled".
	 *
	 * @return bool
	 */
	public static function is_active() {
		return self::is_enabled()
			&& defined( 'WP_CACHE' ) && WP_CACHE
			&& self::dropin_is_ours();
	}

	/**
	 * Real activation state for the UI: 'disabled' | 'pending' | 'active', plus
	 * the reason it is only pending so the admin knows exactly what is missing.
	 *
	 * @return array { state: string, wp_cache: bool, dropin: bool, reason: string }
	 */
	public static function activation_state() {
		if ( ! self::is_enabled() ) {
			return array( 'state' => 'disabled', 'wp_cache' => false, 'dropin' => false, 'reason' => '' );
		}
		$wp_cache = defined( 'WP_CACHE' ) && WP_CACHE;
		$dropin   = self::dropin_is_ours();
		if ( $wp_cache && $dropin ) {
			return array( 'state' => 'active', 'wp_cache' => true, 'dropin' => true, 'reason' => '' );
		}
		if ( ! $wp_cache ) {
			$reason = __( "WP_CACHE is not set in wp-config.php, so WordPress never loads the cache. Add near the top: define('WP_CACHE', true);", 'flavor-edge-cache' );
		} else {
			$reason = __( 'The advanced-cache.php drop-in is missing. Toggle the page cache off and on to rewrite it.', 'flavor-edge-cache' );
		}
		return array( 'state' => 'pending', 'wp_cache' => $wp_cache, 'dropin' => $dropin, 'reason' => $reason );
	}

	// -------------------------------------------------------------------------
	// Paths / config.
	// -------------------------------------------------------------------------

	private static function base_dir() {
		return WP_CONTENT_DIR . '/cache/flavor-edge/page';
	}

	private static function store_dir() {
		return self::base_dir() . '/store';
	}

	private static function config_file() {
		return self::base_dir() . '/config.php';
	}

	private static function dropin_file() {
		return WP_CONTENT_DIR . '/advanced-cache.php';
	}

	private static function engine_file() {
		return FLAVOR_EDGE_DIR . 'includes/pagecache/TE_PageCache_Store.php';
	}

	/** Config consumed by the storage engine. */
	private static function store_config() {
		$s = TE_Settings::get_all();
		return array(
			'backend'   => in_array( $s['page_cache_backend'], array( 'disk', 'redis', 'memcached' ), true ) ? $s['page_cache_backend'] : 'disk',
			'ttl'       => (int) $s['page_cache_ttl'],
			'dir'       => self::store_dir(),
			'prefix'    => 'fepc:' . substr( md5( (string) home_url() ), 0, 8 ) . ':',
			'max_bytes' => (int) apply_filters( 'flavor_edge_page_cache_max_bytes', 2097152 ),
		);
	}

	/**
	 * Disk pressure of the page-cache store, for the preload's adaptive pacer:
	 * warming a URL also writes its page-cache entry, so a bulk warm is disk I/O
	 * on the origin, not just HTTP fetches. Only meaningful for the disk backend
	 * — Redis/Memcached write to memory, so they report null (no disk gate).
	 *
	 * @return array { backend: string, free_ratio: float|null, free_bytes: int|null }
	 */
	public static function disk_pressure() {
		$backend = TE_Settings::get( 'page_cache_backend' );
		if ( 'disk' !== $backend ) {
			return array( 'backend' => (string) $backend, 'free_ratio' => null, 'free_bytes' => null );
		}
		$dir   = self::base_dir();
		$probe = is_dir( $dir ) ? $dir : WP_CONTENT_DIR;
		$free  = @disk_free_space( $probe );
		$total = @disk_total_space( $probe );
		$ratio = ( $free && $total ) ? ( (float) $free / (float) $total ) : null;
		return array(
			'backend'    => 'disk',
			'free_ratio' => $ratio,
			'free_bytes' => $free ? (int) $free : null,
		);
	}

	/**
	 * Rolling page-cache write latency in ms, for the preload's adaptive pacer.
	 * The warm writes every entry to the store, so this is a direct read of how
	 * hard the cache backend is being pushed — a second degradation signal next
	 * to origin latency and the disk-free ratio. 0 when unknown (no writes yet).
	 *
	 * @return float
	 */
	public static function write_latency_ms() {
		require_once self::engine_file();
		return TE_PageCache_Store::write_latency_ms();
	}

	/** The hosts we actually serve, for the cache-key host allowlist. */
	private static function known_hosts() {
		$hosts = array();
		foreach ( array( home_url(), site_url() ) as $u ) {
			$h = wp_parse_url( $u, PHP_URL_HOST );
			if ( $h ) {
				$hosts[] = strtolower( $h );
			}
		}
		/**
		 * Filter the host allowlist (add aliases / extra domains here).
		 *
		 * @param string[] $hosts Known hosts.
		 */
		return array_values( array_unique( apply_filters( 'flavor_edge_page_cache_hosts', $hosts ) ) );
	}

	/** Skip rules consumed by request_is_cacheable() / cache_variant(). */
	private static function rules_config() {
		$lines = static function ( $key ) {
			return array_values( array_filter( array_map( 'trim', explode( "\n", (string) TE_Settings::get( $key, '' ) ) ) ) );
		};
		return array(
			'excluded_urls'          => $lines( 'excluded_urls' ),
			'excluded_cookies'       => $lines( 'excluded_cookies' ),
			'accepted_query_strings' => $lines( 'accepted_query_strings' ),
			'hosts'                  => self::known_hosts(),
			'vary_language'          => ! empty( TE_Settings::get( 'vary_language' ) ),
		);
	}

	/** Returns a store instance for the current settings. */
	private static function store() {
		require_once self::engine_file();
		return new TE_PageCache_Store( self::store_config() );
	}

	/**
	 * Write the config.php the drop-in includes. Regenerated whenever settings
	 * change so the pre-WordPress serve path always matches the panel.
	 */
	public static function write_config_file() {
		self::ensure_dirs();

		$config = array(
			'enabled' => self::is_enabled(),
			'engine'  => self::engine_file(),
			'store'   => self::store_config(),
			'rules'   => self::rules_config(),
		);

		$code = "<?php\n// Flavor Edge Cache — page cache config. Generated file, do not edit.\n"
			. "defined( 'ABSPATH' ) || exit;\n"
			. 'return ' . var_export( $config, true ) . ";\n";

		// Atomic write: the serve drop-in `include`s this config PRE-BOOT on every
		// cacheable request, and it is rewritten on every admin_init / settings save,
		// so an in-place write could be read half-formed under load → parse error in
		// a pre-boot include → white screen. Same guarantee as the other drop-ins.
		return TE_ObjectCache::write_atomic( self::config_file(), $code );
	}

	private static function ensure_dirs() {
		foreach ( array( self::base_dir(), self::store_dir() ) as $dir ) {
			if ( ! is_dir( $dir ) ) {
				wp_mkdir_p( $dir );
			}
		}
		// Guards: never serve cache internals directly (Apache honours .htaccess;
		// the index.php stops a directory listing everywhere, Nginx included).
		foreach ( array( self::base_dir(), self::store_dir() ) as $dir ) {
			$index = $dir . '/index.php';
			if ( ! file_exists( $index ) ) {
				@file_put_contents( $index, "<?php // Silence is golden.\n" );
			}
		}
		$ht = self::base_dir() . '/.htaccess';
		if ( ! file_exists( $ht ) ) {
			@file_put_contents( $ht, "Require all denied\n<IfModule !mod_authz_core.c>\nDeny from all\n</IfModule>\n" );
		}
	}

	// -------------------------------------------------------------------------
	// STORE (capture).
	// -------------------------------------------------------------------------

	public static function maybe_buffer() {
		if ( self::$buffering ) {
			return;
		}
		require_once self::engine_file();
		if ( ! TE_PageCache_Store::request_is_cacheable( self::rules_config(), $_SERVER, $_COOKIE ) ) {
			return;
		}
		// Logged-in state is not always visible from cookies alone; double-check.
		if ( is_user_logged_in() ) {
			return;
		}
		self::$buffering = true;
		ob_start( array( __CLASS__, 'capture' ) );
	}

	/**
	 * Output-buffer callback: store a fresh copy, return the HTML unchanged.
	 *
	 * @param string $html Buffered output.
	 * @return string
	 */
	public static function capture( $html ) {
		// Only cache a complete, successful, HTML document for anonymous users.
		if ( '' === $html || http_response_code() !== 200 ) {
			return $html;
		}
		if ( is_user_logged_in() || is_404() || is_search() ) {
			return $html;
		}
		if ( ! self::is_html_response() ) {
			return $html;
		}

		// Never store a response that sets a cookie: Varnish refuses to cache
		// those, and the rendered body may carry a per-visitor token.
		if ( self::response_sets_cookie() ) {
			return $html;
		}

		$rules = self::rules_config();
		$store = self::store();
		$key   = $store->key(
			isset( $_SERVER['HTTP_HOST'] ) ? wp_unslash( $_SERVER['HTTP_HOST'] ) : '',
			TE_PageCache_Store::effective_uri(
				isset( $_SERVER['REQUEST_URI'] ) ? wp_unslash( $_SERVER['REQUEST_URI'] ) : '/',
				$rules['accepted_query_strings']
			),
			TE_PageCache_Store::cache_variant( $rules, $_SERVER )
		);

		$store->set( $key, $html, array(
			'ctype'   => 'text/html; charset=' . get_option( 'blog_charset', 'UTF-8' ),
			'headers' => self::capturable_headers(),
		) );

		return $html;
	}

	private static function is_html_response() {
		foreach ( headers_list() as $h ) {
			if ( stripos( $h, 'content-type:' ) === 0 ) {
				return stripos( $h, 'text/html' ) !== false;
			}
		}
		// No explicit Content-Type → WordPress default is text/html.
		return true;
	}

	private static function response_sets_cookie() {
		foreach ( headers_list() as $h ) {
			if ( stripos( $h, 'set-cookie:' ) === 0 ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * The response headers worth replaying on a drop-in hit, so a cached serve
	 * looks identical to a fresh render to the edge (Cache-Control, Vary, keys).
	 */
	private static function capturable_headers() {
		$keep    = array( 'cache-control', 'vary', 'surrogate-keys', 'link', 'content-language' );
		$headers = array();
		foreach ( headers_list() as $h ) {
			$name = strtolower( trim( strtok( $h, ':' ) ) );
			if ( in_array( $name, $keep, true ) ) {
				$headers[] = $h;
			}
		}
		return $headers;
	}

	// -------------------------------------------------------------------------
	// INVALIDATE.
	// -------------------------------------------------------------------------

	public static function flush_all() {
		if ( ! self::is_enabled() ) {
			return;
		}
		self::store()->flush();
	}

	/**
	 * A tag purge is mapped to the specific URLs it affects (post permalink,
	 * home) and those are deleted — the page cache stays warm for everything
	 * else, which on a constantly-publishing news site is the whole point.
	 * Anything not mapped simply expires on its short TTL.
	 *
	 * @param mixed $result Purge result (unused).
	 * @param array $tags   Surrogate tags that were purged.
	 */
	public static function on_tag_purge( $result, $tags ) {
		if ( ! self::is_enabled() || empty( $tags ) ) {
			return;
		}
		$urls = array();
		foreach ( (array) $tags as $tag ) {
			$tag = (string) $tag;
			if ( preg_match( '/^post-(\d+)$/', $tag, $m ) ) {
				$permalink = get_permalink( (int) $m[1] );
				if ( $permalink ) {
					$urls[] = $permalink;
				}
			} elseif ( 'front-page' === $tag ) {
				$urls[] = home_url( '/' );
			} elseif ( preg_match( '/^term-(\d+)$/', $tag, $m ) ) {
				// Category / tag / any taxonomy archive the post changed. Without this
				// the archive rides the 300s TTL, and an immediate refetch re-caches
				// the stale shield copy into the (long-lived) edge slot.
				$link = get_term_link( (int) $m[1] );
				if ( ! is_wp_error( $link ) ) {
					$urls[] = $link;
				}
			} elseif ( preg_match( '/^author-(\d+)$/', $tag, $m ) ) {
				$urls[] = get_author_posts_url( (int) $m[1] );
			} elseif ( preg_match( '/^type-(.+)$/', $tag, $m ) ) {
				$archive = get_post_type_archive_link( $m[1] );
				if ( $archive ) {
					$urls[] = $archive;
				}
			}
		}
		if ( ! empty( $urls ) ) {
			self::on_url_purge( null, array_unique( $urls ) );
		}
	}

	/**
	 * A URL purge clears that URL across every device bucket.
	 *
	 * @param mixed $result Purge result (unused).
	 * @param array $urls   URLs that were purged.
	 */
	public static function on_url_purge( $result, $urls ) {
		if ( ! self::is_enabled() || empty( $urls ) ) {
			return;
		}
		$store    = self::store();
		$ignored  = self::rules_config()['accepted_query_strings'];
		$variants = self::purge_variants();
		foreach ( (array) $urls as $url ) {
			$parts = wp_parse_url( $url );
			$host  = isset( $parts['host'] ) ? $parts['host'] : (string) wp_parse_url( home_url(), PHP_URL_HOST );
			$uri   = ( isset( $parts['path'] ) ? $parts['path'] : '/' ) . ( isset( $parts['query'] ) ? '?' . $parts['query'] : '' );
			$uri   = TE_PageCache_Store::effective_uri( $uri, $ignored );
			foreach ( $variants as $variant ) {
				$store->delete( $store->key( $host, $uri, $variant ) );
			}
		}
	}

	/**
	 * The cache-key variants a URL can be stored under. The key is a one-way hash
	 * of host|uri|variant, so a surgical purge must reconstruct every variant that
	 * could have been written — there is no prefix delete.
	 *
	 * Device buckets always. When vary_language is on, the variant also carries the
	 * visitor's primary Accept-Language tag, which is free-form; we enumerate the
	 * site's own configured languages (WPML/Polylang) as the realistic set. A
	 * variant from an exotic Accept-Language not in that set rides the short
	 * page-cache TTL (default 300s) and self-heals. The language list is filterable
	 * via `flavor_edge_pc_purge_languages` for exact control. (For URL-based i18n —
	 * each language on its own URL — keep vary_language OFF; those purge normally.)
	 *
	 * @return array Variant strings.
	 */
	private static function purge_variants() {
		$devices = array( 'desktop', 'mobile', 'app' );
		if ( empty( self::rules_config()['vary_language'] ) ) {
			return $devices;
		}
		$langs    = self::site_language_codes();
		$variants = $devices;
		foreach ( $devices as $device ) {
			foreach ( $langs as $lang ) {
				$variants[] = $device . '|' . $lang;
			}
		}
		return array_values( array_unique( $variants ) );
	}

	/**
	 * The site's configured language codes, normalised to cache_variant()'s shape.
	 * Best-effort from WPML and Polylang; empty when neither is active.
	 *
	 * @return array
	 */
	private static function site_language_codes() {
		$codes = array();

		$wpml = apply_filters( 'wpml_active_languages', null, array() );
		if ( is_array( $wpml ) ) {
			foreach ( array_keys( $wpml ) as $code ) {
				$codes[] = $code;
			}
		}
		if ( function_exists( 'pll_languages_list' ) ) {
			foreach ( (array) pll_languages_list() as $code ) {
				$codes[] = $code;
			}
		}

		$out = array();
		foreach ( $codes as $code ) {
			// Mirror cache_variant(): lowercase, first 5 chars, [a-z-] only.
			$code = preg_replace( '/[^a-z-]/', '', strtolower( substr( (string) $code, 0, 5 ) ) );
			if ( '' === $code ) {
				continue;
			}
			$out[] = $code;
			// Browsers commonly send a region subtag (es -> es-es); cover the obvious one.
			if ( 2 === strlen( $code ) && false === strpos( $code, '-' ) ) {
				$out[] = $code . '-' . $code;
			}
		}

		/**
		 * Filter the language codes a surgical purge clears when vary_language is on.
		 *
		 * @param array $out Normalised language codes.
		 */
		return array_values( array_unique( apply_filters( 'flavor_edge_pc_purge_languages', $out ) ) );
	}

	// -------------------------------------------------------------------------
	// Enable / disable (drop-in + WP_CACHE).
	// -------------------------------------------------------------------------

	public static function enable() {
		self::ensure_dirs();
		self::write_config_file();

		// advanced-cache.php drop-in (guard against another plugin's).
		$dropin = self::dropin_file();
		if ( file_exists( $dropin ) && ! self::dropin_is_ours() ) {
			return array(
				'success' => false,
				'message' => __( 'Another advanced-cache.php drop-in is already installed. Remove it first.', 'flavor-edge-cache' ),
			);
		}
		// Written atomically (temp + rename): WordPress includes advanced-cache.php on
		// every request with no lock, so an in-place write could be read half-formed
		// by a concurrent worker → parse error → site-wide white screen.
		if ( ! TE_ObjectCache::write_atomic( $dropin, self::generate_dropin() ) ) {
			return array(
				'success' => false,
				'message' => __( 'Could not write advanced-cache.php. Check permissions on wp-content/.', 'flavor-edge-cache' ),
			);
		}

		$wp_cache = self::set_wp_cache( true );
		if ( ! $wp_cache['success'] ) {
			return $wp_cache; // Drop-in written, but WP_CACHE not active — tell the user why.
		}

		return array(
			'success' => true,
			'message' => __( 'Origin page cache enabled.', 'flavor-edge-cache' ),
		);
	}

	public static function disable() {
		$dropin = self::dropin_file();
		if ( file_exists( $dropin ) && self::dropin_is_ours() ) {
			@unlink( $dropin );
		}
		self::set_wp_cache( false );
		self::flush_all();

		return array( 'success' => true, 'message' => __( 'Origin page cache disabled.', 'flavor-edge-cache' ) );
	}

	public static function dropin_is_ours() {
		$dropin = self::dropin_file();
		if ( ! file_exists( $dropin ) ) {
			return false;
		}
		$head = (string) file_get_contents( $dropin, false, null, 0, 200 );
		return false !== strpos( $head, 'Flavor Edge Cache' );
	}

	// -------------------------------------------------------------------------
	// wp-config.php WP_CACHE handling.
	// -------------------------------------------------------------------------

	private static function wp_config_path() {
		$candidates = array( ABSPATH . 'wp-config.php', dirname( ABSPATH ) . '/wp-config.php' );
		foreach ( $candidates as $path ) {
			// The one level up only counts if it's not another install's root.
			if ( file_exists( $path ) && ( ABSPATH . 'wp-config.php' === $path || ! file_exists( dirname( ABSPATH ) . '/wp-settings.php' ) ) ) {
				return $path;
			}
		}
		return '';
	}

	/**
	 * Ensure WP_CACHE is (true|removed) in wp-config.php via a marked block.
	 * Never rewrites a WP_CACHE the user defined themselves — it reports instead.
	 *
	 * @param bool $enable Desired state.
	 * @return array { success, message }
	 */
	private static function set_wp_cache( $enable ) {
		$path = self::wp_config_path();
		if ( '' === $path || ! is_writable( $path ) ) {
			return array(
				'success' => false,
				'message' => sprintf(
					/* translators: %s: line to add. */
					__( 'wp-config.php is not writable. Add this near the top manually: %s', 'flavor-edge-cache' ),
					"define('WP_CACHE', true);"
				),
			);
		}

		$contents = (string) file_get_contents( $path );

		// A WP_CACHE the user defined themselves (outside our markers) wins.
		$without_ours = self::strip_our_block( $contents );
		if ( preg_match( '/define\s*\(\s*[\'"]WP_CACHE[\'"]\s*,\s*(true|false)/i', $without_ours, $m ) ) {
			$is_true = 0 === strcasecmp( $m[1], 'true' );
			if ( $enable && ! $is_true ) {
				return array(
					'success' => false,
					'message' => __( 'wp-config.php already sets WP_CACHE to false. Change it to true to enable the page cache.', 'flavor-edge-cache' ),
				);
			}
			return array( 'success' => true, 'message' => '' ); // Their define already matches.
		}

		// Remove any previous block of ours, then add one if enabling.
		$new = self::strip_our_block( $contents );
		if ( $enable ) {
			$block = "\n" . self::WPCONFIG_BEGIN . "\nif ( ! defined( 'WP_CACHE' ) ) { define( 'WP_CACHE', true ); }\n" . self::WPCONFIG_END . "\n";
			// Insert right after the opening tag; tolerate a BOM / leading space.
			// A callback avoids any $-/\ interpretation in the replacement.
			$new = preg_replace_callback(
				'/^((?:\xEF\xBB\xBF)?\s*<\?php\b)/',
				static function ( $m ) use ( $block ) {
					return $m[1] . $block;
				},
				$new,
				1,
				$count
			);
			if ( empty( $count ) || false === strpos( (string) $new, self::WPCONFIG_BEGIN ) ) {
				return array(
					'success' => false,
					'message' => __( 'Could not find the <?php opening in wp-config.php. Add this near the top manually: define(\'WP_CACHE\', true);', 'flavor-edge-cache' ),
				);
			}
		}

		if ( $new === $contents ) {
			return array( 'success' => true, 'message' => '' );
		}

		// Transient backup of wp-config.php, only to survive the write below. It
		// lives in the plugin's cache dir (guarded by .htaccess + index.php) behind
		// a PHP guard (<?php exit; __halt_compiler();) so a direct web hit reveals
		// nothing even on Apache; but the real safeguard is that we DELETE it the
		// moment the write succeeds — a lasting full copy of wp-config.php (DB
		// credentials, auth salts) must never sit on disk for a backup/migration
		// plugin, an LFI doing a raw file read, or a mis-served .php to expose.
		self::ensure_dirs();
		$backup = self::base_dir() . '/wp-config-' . substr( md5( $path ), 0, 10 ) . '.bak.php';
		@file_put_contents( $backup, "<?php exit; __halt_compiler();\n" . $contents, LOCK_EX );

		// Atomic write (temp + rename, preserving perms/symlink): wp-config.php is
		// included on every request, so a half-written file is instant site death.
		if ( ! TE_ObjectCache::write_atomic( $path, $new ) ) {
			// Best-effort rollback from the copy we still hold in memory, and keep
			// the guarded backup on disk for manual recovery of this rare failure.
			@file_put_contents( $path, $contents, LOCK_EX );
			return array( 'success' => false, 'message' => __( 'Could not update wp-config.php.', 'flavor-edge-cache' ) );
		}

		// Success: the backup has done its job. Our edit only inserts a marked
		// block (disabling strips it), so nothing needs a full-file backup for
		// rollback — remove it so no copy of the secrets lingers.
		@unlink( $backup );
		return array( 'success' => true, 'message' => '' );
	}

	private static function strip_our_block( $contents ) {
		$begin = preg_quote( self::WPCONFIG_BEGIN, '/' );
		$end   = preg_quote( self::WPCONFIG_END, '/' );
		return preg_replace( '/\n?' . $begin . '.*?' . $end . '\n?/s', "\n", $contents );
	}

	// -------------------------------------------------------------------------
	// Drop-in generation.
	// -------------------------------------------------------------------------

	private static function generate_dropin() {
		return <<<'PHP'
<?php
/**
 * Flavor Edge Cache — advanced-cache.php (origin page cache, SERVE path).
 *
 * Generated file. Do not edit: the plugin regenerates it. Serves a cached
 * document before WordPress boots, so a CDN miss does not regenerate WordPress.
 */

if ( ! defined( 'ABSPATH' ) ) { return; }
if ( ( defined( 'WP_CLI' ) && WP_CLI ) || ( defined( 'DOING_CRON' ) && DOING_CRON ) || PHP_SAPI === 'cli' ) { return; }

$fe_cfg_file = __DIR__ . '/cache/flavor-edge/page/config.php';
if ( ! is_readable( $fe_cfg_file ) ) { return; }
$fe_cfg = include $fe_cfg_file;
if ( ! is_array( $fe_cfg ) || empty( $fe_cfg['enabled'] ) || empty( $fe_cfg['engine'] ) || ! is_readable( $fe_cfg['engine'] ) ) { return; }

require_once $fe_cfg['engine'];
if ( ! class_exists( '\\flavor_edge\\TE_PageCache_Store' ) ) { return; }

if ( ! \flavor_edge\TE_PageCache_Store::request_is_cacheable( $fe_cfg['rules'], $_SERVER, $_COOKIE ) ) { return; }

$fe_store   = new \flavor_edge\TE_PageCache_Store( $fe_cfg['store'] );
$fe_host    = isset( $_SERVER['HTTP_HOST'] ) ? $_SERVER['HTTP_HOST'] : '';
$fe_uri     = \flavor_edge\TE_PageCache_Store::effective_uri( isset( $_SERVER['REQUEST_URI'] ) ? $_SERVER['REQUEST_URI'] : '/', $fe_cfg['rules']['accepted_query_strings'] );
$fe_variant = \flavor_edge\TE_PageCache_Store::cache_variant( $fe_cfg['rules'], $_SERVER );
$fe_hit     = $fe_store->get( $fe_store->key( $fe_host, $fe_uri, $fe_variant ) );

if ( null === $fe_hit ) { return; } // Miss → WordPress boots and the plugin stores the render.

if ( ! headers_sent() ) {
	header( 'Content-Type: ' . $fe_hit['ctype'], true );
	foreach ( $fe_hit['headers'] as $fe_h ) { header( $fe_h, true ); }
	header( 'X-FlavorEdge-PageCache: HIT', true );
	header( 'Age: ' . max( 0, time() - (int) $fe_hit['created'] ), true );
}
echo $fe_hit['html'];
exit;
PHP;
	}

	// -------------------------------------------------------------------------
	// AJAX.
	// -------------------------------------------------------------------------

	public static function ajax_enable() {
		check_ajax_referer( 'flavor_edge_admin', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) { wp_send_json_error( 'Unauthorized' ); }
		TE_Settings::set( 'page_cache_enabled', true );
		wp_send_json( self::enable() );
	}

	public static function ajax_disable() {
		check_ajax_referer( 'flavor_edge_admin', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) { wp_send_json_error( 'Unauthorized' ); }
		TE_Settings::set( 'page_cache_enabled', false );
		wp_send_json( self::disable() );
	}

	public static function ajax_flush() {
		check_ajax_referer( 'flavor_edge_admin', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) { wp_send_json_error( 'Unauthorized' ); }
		self::flush_all();
		wp_send_json_success( __( 'Page cache flushed.', 'flavor-edge-cache' ) );
	}
}
