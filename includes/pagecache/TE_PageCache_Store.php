<?php
/**
 * Origin page-cache storage engine.
 *
 * Pure PHP, dependency-free, usable in BOTH contexts:
 *   - the advanced-cache.php drop-in (runs before WordPress boots) to SERVE, and
 *   - the plugin (loaded normally) to STORE and INVALIDATE.
 *
 * Keeping the key algorithm and the backends in one class is what stops the
 * serve path and the store path from ever drifting apart.
 *
 * Backends:
 *   - disk       : zero dependencies, works anywhere. Each lookup is
 *                  a filesystem read, so on slow/network disk or very high
 *                  concurrency the I/O and stat cost shows.
 *   - redis      : in-memory key lookup, no disk I/O — faster under concurrency.
 *                  Reuses the WP_REDIS_* constants. Needs the service.
 *   - memcached  : same idea as redis; flush is generation-based so it never
 *                  nukes a store shared with the object cache. Needs the service.
 *
 * @package flavor_edge_cache
 */

namespace flavor_edge;

defined( 'ABSPATH' ) || exit;

class TE_PageCache_Store {

	/** Bumping this invalidates every previously stored entry (format change). */
	const SCHEMA = 1;

	/** @var string disk|redis|memcached */
	private $backend;

	/** @var int seconds */
	private $ttl;

	/** @var string absolute cache directory (disk backend) */
	private $dir;

	/** @var string key prefix (redis/memcached) */
	private $prefix;

	/** @var \Redis|\Memcached|null lazily opened connection */
	private $conn = null;

	/** @var bool whether we already attempted to connect */
	private $connected = false;

	/** @var int|null cached generation counter (redis/memcached flush) */
	private $generation = null;

	/**
	 * @param array $config {
	 *   @type string backend  disk|redis|memcached (default disk).
	 *   @type int    ttl      seconds (default 300).
	 *   @type string dir      absolute path for the disk backend.
	 *   @type string prefix   key prefix for redis/memcached (default 'fepc:').
	 * }
	 */
	public function __construct( array $config ) {
		$backend         = isset( $config['backend'] ) ? $config['backend'] : 'disk';
		$this->backend   = in_array( $backend, array( 'disk', 'redis', 'memcached' ), true ) ? $backend : 'disk';
		$this->ttl       = max( 1, (int) ( isset( $config['ttl'] ) ? $config['ttl'] : 300 ) );
		$this->dir       = isset( $config['dir'] ) ? rtrim( (string) $config['dir'], '/' ) : '';
		$this->prefix    = isset( $config['prefix'] ) ? (string) $config['prefix'] : 'fepc:';
		// Per-entry cap (0 = unlimited). Guards a weak origin's disk against an
		// attacker forcing huge responses into the cache. Default 2 MiB.
		$this->max_bytes = isset( $config['max_bytes'] ) ? max( 0, (int) $config['max_bytes'] ) : 2097152;
	}

	/** @var int per-entry byte cap (0 = unlimited) */
	private $max_bytes = 2097152;

	// -------------------------------------------------------------------------
	// Key.
	// -------------------------------------------------------------------------

	/**
	 * Build the cache key for a request.
	 *
	 * The device segment mirrors the edge's X-Device split (desktop/mobile/app),
	 * so a phone and a desktop never collide on one cached document.
	 *
	 * @param string $host   Request host.
	 * @param string $uri    Request URI (may include a query string).
	 * @param string $device Device bucket (e.g. desktop|mobile|app).
	 * @return string 64-char hex key.
	 */
	public function key( $host, $uri, $device ) {
		$material = self::SCHEMA . '|' . strtolower( (string) $host ) . '|'
			. $this->normalize_uri( (string) $uri ) . '|' . (string) $device;
		return hash( 'sha256', $material );
	}

	/**
	 * Normalise a URI so equivalent requests share one entry: lowercase path is
	 * NOT forced (paths are case-sensitive), but query parameters are sorted so
	 * ?a=1&b=2 and ?b=2&a=1 map to the same key. Query whitelisting (dropping
	 * utm_*, etc.) is the caller's responsibility before this point.
	 *
	 * @param string $uri Request URI.
	 * @return string
	 */
	private function normalize_uri( $uri ) {
		$hash = strpos( $uri, '#' );
		if ( false !== $hash ) {
			$uri = substr( $uri, 0, $hash );
		}
		$parts = explode( '?', $uri, 2 );
		$path  = $parts[0];
		if ( isset( $parts[1] ) && '' !== $parts[1] ) {
			parse_str( $parts[1], $q );
			if ( ! empty( $q ) ) {
				ksort( $q );
				return $path . '?' . http_build_query( $q );
			}
		}
		return $path;
	}

	// -------------------------------------------------------------------------
	// Public API (dispatch per backend).
	// -------------------------------------------------------------------------

	/**
	 * Fetch an entry.
	 *
	 * @param string $key Cache key.
	 * @return array|null { html, ctype, created } or null on miss/expired.
	 */
	public function get( $key ) {
		switch ( $this->backend ) {
			case 'redis':
				return $this->redis_get( $key );
			case 'memcached':
				return $this->memcached_get( $key );
			default:
				return $this->disk_get( $key );
		}
	}

	/**
	 * Store an entry.
	 *
	 * @param string $key  Cache key.
	 * @param string $html Rendered HTML.
	 * @param array  $meta { ctype } optional.
	 * @return bool
	 */
	public function set( $key, $html, array $meta = array() ) {
		if ( $this->max_bytes > 0 && strlen( $html ) > $this->max_bytes ) {
			return false;
		}
		$entry = array(
			'v'       => self::SCHEMA,
			'created' => time(),
			'ctype'   => isset( $meta['ctype'] ) ? (string) $meta['ctype'] : 'text/html; charset=UTF-8',
			'headers' => ( isset( $meta['headers'] ) && is_array( $meta['headers'] ) ) ? array_values( $meta['headers'] ) : array(),
			'html'    => (string) $html,
		);
		$t0 = microtime( true );
		switch ( $this->backend ) {
			case 'redis':
				$ok = $this->redis_set( $key, $entry );
				break;
			case 'memcached':
				$ok = $this->memcached_set( $key, $entry );
				break;
			default:
				$ok = $this->disk_set( $key, $entry );
				break;
		}
		self::record_write_latency( ( microtime( true ) - $t0 ) * 1000.0 );
		return $ok;
	}

	/** Transient holding the rolling page-cache write latency (ms). */
	const WRITE_MS_KEY = 'flavor_edge_pc_write_ms';

	/**
	 * Record the latency of a page-cache write as a rolling EWMA. Warming a URL
	 * writes its entry here anyway, so the preload's adaptive pacer can sense
	 * disk/backend write pressure from THIS — no extra origin request. It is
	 * cross-request (the writes happen in the refetcher's origin hits, the pacer
	 * reads them from its own run), so it lives in a transient.
	 *
	 * @param float $ms Write duration in milliseconds.
	 */
	private static function record_write_latency( $ms ) {
		if ( $ms < 0 || ! function_exists( 'get_transient' ) ) {
			return;
		}
		// The adaptive pacer only reads this latency DURING a preload. Recording it
		// on every anonymous cache-miss store otherwise is a needless DB write on the
		// disk backend (write amplification on a busy origin), so gate on an active
		// preload. The check is memoised per request.
		static $preloading = null;
		if ( null === $preloading ) {
			$status     = get_option( 'flavor_edge_preload_status', null );
			$preloading = ( is_array( $status ) && isset( $status['status'] ) && 'running' === $status['status'] );
		}
		if ( ! $preloading ) {
			return;
		}
		$prev = (float) get_transient( self::WRITE_MS_KEY );
		$ewma = $prev > 0 ? ( 0.8 * $prev + 0.2 * $ms ) : $ms;
		set_transient( self::WRITE_MS_KEY, $ewma, 600 );
	}

	/**
	 * Current rolling page-cache write latency in ms (0 when unknown — e.g. no
	 * writes yet, or a memory backend where writes are effectively free).
	 *
	 * @return float
	 */
	public static function write_latency_ms() {
		return function_exists( 'get_transient' ) ? (float) get_transient( self::WRITE_MS_KEY ) : 0.0;
	}

	/**
	 * Delete a single entry.
	 *
	 * @param string $key Cache key.
	 * @return bool
	 */
	public function delete( $key ) {
		switch ( $this->backend ) {
			case 'redis':
				$r = $this->redis();
				return $r ? ( false !== $r->del( $this->rkey( $key ) ) ) : false;
			case 'memcached':
				$m = $this->memcached();
				// Must match the generation-scoped key used by get/set (mkey), not
				// the bare rkey — otherwise the surgical delete targets a key that
				// was never written and the stale entry survives until its TTL.
				return $m ? $m->delete( $this->mkey( $key ) ) : false;
			default:
				$f = $this->path( $key );
				return file_exists( $f ) ? @unlink( $f ) : true;
		}
	}

	/**
	 * Flush the entire page cache.
	 *
	 * @return bool
	 */
	public function flush() {
		switch ( $this->backend ) {
			case 'redis':
				return $this->redis_flush();
			case 'memcached':
				return $this->memcached_bump_generation();
			default:
				return $this->disk_flush();
		}
	}

	// -------------------------------------------------------------------------
	// Disk backend.
	// -------------------------------------------------------------------------

	/**
	 * Resolve the on-disk path for a key (sharded by first 2 chars).
	 *
	 * @param string $key Cache key.
	 * @return string
	 */
	private function path( $key ) {
		return $this->dir . '/' . substr( $key, 0, 2 ) . '/' . $key . '.cache';
	}

	private function disk_get( $key ) {
		$file = $this->path( $key );
		$raw  = @file_get_contents( $file );
		if ( false === $raw ) {
			return null;
		}
		$entry = @unserialize( $raw );
		if ( ! is_array( $entry ) || ( $entry['v'] ?? 0 ) !== self::SCHEMA ) {
			return null;
		}
		if ( time() - (int) $entry['created'] > $this->ttl ) {
			@unlink( $file );
			return null;
		}
		return $this->shape( $entry );
	}

	/**
	 * Build the public return shape from a stored entry.
	 *
	 * @param array $entry Stored entry.
	 * @return array { html, ctype, headers, created }
	 */
	private function shape( array $entry ) {
		return array(
			'html'    => $entry['html'],
			'ctype'   => $entry['ctype'],
			'headers' => ( isset( $entry['headers'] ) && is_array( $entry['headers'] ) ) ? $entry['headers'] : array(),
			'created' => $entry['created'],
		);
	}

	private function disk_set( $key, array $entry ) {
		$file = $this->path( $key );
		$sub  = dirname( $file );
		if ( ! is_dir( $sub ) && ! @mkdir( $sub, 0755, true ) && ! is_dir( $sub ) ) {
			return false;
		}
		// Atomic: write to a temp file in the same dir, then rename.
		$tmp = $sub . '/.' . substr( $key, 0, 16 ) . '.' . getmypid() . '.tmp';
		if ( false === @file_put_contents( $tmp, serialize( $entry ), LOCK_EX ) ) {
			return false;
		}
		if ( ! @rename( $tmp, $file ) ) {
			@unlink( $tmp );
			return false;
		}
		return true;
	}

	private function disk_flush() {
		if ( '' === $this->dir || ! is_dir( $this->dir ) ) {
			return true;
		}

		// R3: do NOT unlink the whole tree synchronously inside the request that
		// triggered the flush — on a large store that is a storm of unlink() that
		// blocks the request and hammers a weak origin's disk. Move the store aside
		// with a single rename (O(1)) to a SIBLING dir on the same filesystem,
		// recreate the live dir empty immediately (so a concurrent request just
		// misses and repopulates instead of erroring on a missing dir), and let a
		// background cron delete the moved tree. Fall back to the in-place walk only
		// if the rename is not possible (e.g. permissions).
		$parent = dirname( $this->dir );
		$trash  = $this->dir . '.trash-' . uniqid();

		if ( is_writable( $parent ) && @rename( $this->dir, $trash ) ) {
			@mkdir( $this->dir, 0755, true );
			if ( function_exists( 'wp_schedule_single_event' ) ) {
				// Duplicate schedules dedupe by hook+args, so a burst of flushes
				// still results in a single background sweep.
				wp_schedule_single_event( time() + 10, 'flavor_edge_pc_trash_gc', array( $parent ) );
			}
			return true;
		}

		// Fallback: rename not possible — walk and unlink in place (old behaviour).
		$it = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator( $this->dir, \FilesystemIterator::SKIP_DOTS ),
			\RecursiveIteratorIterator::CHILD_FIRST
		);
		foreach ( $it as $item ) {
			if ( $item->isDir() ) {
				@rmdir( $item->getPathname() );
			} elseif ( '.cache' === substr( $item->getFilename(), -6 ) ) {
				@unlink( $item->getPathname() );
			}
		}
		return true;
	}

	/**
	 * Background GC (wp-cron): delete store directories that a flush moved aside
	 * (<store>.trash-*). Runs OFF the request path, so the heavy unlink storm no
	 * longer blocks the publish that triggered the flush. Bounded per run, and it
	 * only ever removes our own ".trash-" directories.
	 *
	 * @param string $parent Parent directory that holds the .trash-* dirs.
	 */
	public static function gc_trash( $parent ) {
		$parent = (string) $parent;
		if ( '' === $parent || ! is_dir( $parent ) ) {
			return;
		}
		$trash = glob( $parent . '/*.trash-*', GLOB_ONLYDIR );
		if ( empty( $trash ) ) {
			return;
		}
		$budget = function_exists( 'apply_filters' )
			? (int) apply_filters( 'flavor_edge_pc_trash_gc_budget', 20 )
			: 20;
		foreach ( array_slice( $trash, 0, $budget ) as $dir ) {
			// Safety: only ever delete our own moved stores.
			if ( false === strpos( basename( $dir ), '.trash-' ) ) {
				continue;
			}
			$it = new \RecursiveIteratorIterator(
				new \RecursiveDirectoryIterator( $dir, \FilesystemIterator::SKIP_DOTS ),
				\RecursiveIteratorIterator::CHILD_FIRST
			);
			foreach ( $it as $item ) {
				if ( $item->isDir() ) {
					@rmdir( $item->getPathname() );
				} else {
					@unlink( $item->getPathname() );
				}
			}
			@rmdir( $dir );
		}
	}

	// -------------------------------------------------------------------------
	// Redis backend.
	// -------------------------------------------------------------------------

	private function redis() {
		if ( $this->connected ) {
			return $this->conn;
		}
		$this->connected = true;
		$this->conn      = null;
		if ( ! class_exists( '\Redis' ) ) {
			return null;
		}
		try {
			$r    = new \Redis();
			// A page cache on the generic WP_REDIS_* connection shares a DB with
			// every other plugin, so a third-party FLUSHDB (a WPO plugin clearing
			// its own cache on minify, etc.) would take the shield with it.
			// Dedicated FLAVOR_EDGE_PC_REDIS_* let the shield live on its own
			// instance or DB index, out of that blast radius. Falls back to WP_REDIS_*.
			$host = defined( 'FLAVOR_EDGE_PC_REDIS_HOST' ) ? FLAVOR_EDGE_PC_REDIS_HOST : ( defined( 'WP_REDIS_HOST' ) ? WP_REDIS_HOST : '127.0.0.1' );
			$port = defined( 'FLAVOR_EDGE_PC_REDIS_PORT' ) ? (int) FLAVOR_EDGE_PC_REDIS_PORT : ( defined( 'WP_REDIS_PORT' ) ? (int) WP_REDIS_PORT : 6379 );
			// TLS: a dedicated FLAVOR_EDGE_PC_REDIS_TLS wins; otherwise inherit the
			// global WP_REDIS_SCHEME / WP_REDIS_TLS. Verifies the peer by default;
			// override via FLAVOR_EDGE_PC_REDIS_TLS_OPTIONS or WP_REDIS_TLS_OPTIONS.
			if ( defined( 'FLAVOR_EDGE_PC_REDIS_TLS' ) ) {
				$tls = (bool) FLAVOR_EDGE_PC_REDIS_TLS;
			} else {
				$scheme = defined( 'WP_REDIS_SCHEME' ) ? strtolower( (string) WP_REDIS_SCHEME ) : '';
				$tls    = ( 'tls' === $scheme || 'rediss' === $scheme || ( defined( 'WP_REDIS_TLS' ) && WP_REDIS_TLS ) );
			}
			if ( $tls ) {
				$ssl = array( 'verify_peer' => true, 'verify_peer_name' => true );
				if ( defined( 'FLAVOR_EDGE_PC_REDIS_TLS_OPTIONS' ) && is_array( FLAVOR_EDGE_PC_REDIS_TLS_OPTIONS ) ) {
					$ssl = array_merge( $ssl, FLAVOR_EDGE_PC_REDIS_TLS_OPTIONS );
				} elseif ( defined( 'WP_REDIS_TLS_OPTIONS' ) && is_array( WP_REDIS_TLS_OPTIONS ) ) {
					$ssl = array_merge( $ssl, WP_REDIS_TLS_OPTIONS );
				}
				$ok = @$r->connect( 'tls://' . $host, $port, 1.0, null, 0, 0, array( 'stream' => $ssl ) );
			} else {
				$ok = @$r->connect( $host, $port, 1 );
			}
			if ( ! $ok ) {
				return null;
			}
			$pass = defined( 'FLAVOR_EDGE_PC_REDIS_PASSWORD' ) ? FLAVOR_EDGE_PC_REDIS_PASSWORD : ( defined( 'WP_REDIS_PASSWORD' ) ? WP_REDIS_PASSWORD : '' );
			if ( $pass ) {
				$r->auth( $pass );
			}
			if ( defined( 'FLAVOR_EDGE_PC_REDIS_DB' ) ) {
				$r->select( (int) FLAVOR_EDGE_PC_REDIS_DB ); // dedicated DB → immune to a foreign FLUSHDB on DB 0.
			} elseif ( defined( 'WP_REDIS_DATABASE' ) ) {
				$r->select( (int) WP_REDIS_DATABASE );
			}
			$this->conn = $r;
		} catch ( \Throwable $e ) {
			$this->conn = null;
		}
		return $this->conn;
	}

	private function rkey( $key ) {
		return $this->prefix . $key;
	}

	private function redis_get( $key ) {
		$r = $this->redis();
		if ( ! $r ) {
			return null;
		}
		$raw = $r->get( $this->rkey( $key ) );
		if ( false === $raw ) {
			return null;
		}
		$entry = @unserialize( $raw );
		if ( ! is_array( $entry ) || ( $entry['v'] ?? 0 ) !== self::SCHEMA ) {
			return null;
		}
		return $this->shape( $entry );
	}

	private function redis_set( $key, array $entry ) {
		$r = $this->redis();
		if ( ! $r ) {
			return false;
		}
		// Native TTL → expiry is automatic, no stale sweep needed.
		return (bool) $r->setex( $this->rkey( $key ), $this->ttl, serialize( $entry ) );
	}

	private function redis_flush() {
		$r = $this->redis();
		if ( ! $r ) {
			return false;
		}
		// Prefix-scoped delete so we never touch another app's keys in the DB.
		$it = null;
		do {
			$keys = $r->scan( $it, $this->prefix . '*', 500 );
			if ( is_array( $keys ) && $keys ) {
				$r->del( $keys );
			}
		} while ( $it > 0 );
		return true;
	}

	// -------------------------------------------------------------------------
	// Memcached backend (generation-based flush so a shared store survives).
	// -------------------------------------------------------------------------

	private function memcached() {
		if ( $this->connected ) {
			return $this->conn;
		}
		$this->connected = true;
		$this->conn      = null;
		if ( ! class_exists( '\Memcached' ) ) {
			return null;
		}
		try {
			$m    = new \Memcached();
			// Dedicated page-cache server isolates the shield from a third-party
			// flush_all on the generic WP_MEMCACHED_* instance (flush_all is
			// instance-wide and cannot be survived by any key trick). Falls back to it.
			$host = defined( 'FLAVOR_EDGE_PC_MEMCACHED_HOST' ) ? FLAVOR_EDGE_PC_MEMCACHED_HOST : ( defined( 'WP_MEMCACHED_HOST' ) ? WP_MEMCACHED_HOST : '127.0.0.1' );
			$port = defined( 'FLAVOR_EDGE_PC_MEMCACHED_PORT' ) ? (int) FLAVOR_EDGE_PC_MEMCACHED_PORT : ( defined( 'WP_MEMCACHED_PORT' ) ? (int) WP_MEMCACHED_PORT : 11211 );
			$m->addServer( $host, $port );
			$this->conn = $m;
		} catch ( \Throwable $e ) {
			$this->conn = null;
		}
		return $this->conn;
	}

	/**
	 * Current generation counter. Incorporated into every memcached key so that
	 * a flush is a single increment instead of nuking a shared instance.
	 */
	private function generation() {
		if ( null !== $this->generation ) {
			return $this->generation;
		}
		$m = $this->memcached();
		if ( ! $m ) {
			return $this->generation = 0;
		}
		$gen = $m->get( $this->prefix . 'gen' );
		if ( false === $gen ) {
			$gen = 1;
			$m->set( $this->prefix . 'gen', $gen );
		}
		return $this->generation = (int) $gen;
	}

	private function mkey( $key ) {
		return $this->prefix . $this->generation() . ':' . $key;
	}

	private function memcached_get( $key ) {
		$m = $this->memcached();
		if ( ! $m ) {
			return null;
		}
		$entry = $m->get( $this->mkey( $key ) );
		if ( ! is_array( $entry ) || ( $entry['v'] ?? 0 ) !== self::SCHEMA ) {
			return null;
		}
		return $this->shape( $entry );
	}

	private function memcached_set( $key, array $entry ) {
		$m = $this->memcached();
		if ( ! $m ) {
			return false;
		}
		// Memcached reads any expiry > 30 days as an absolute Unix timestamp; a
		// page_cache_ttl set above that (relative seconds) would be read as a 1970
		// timestamp → instant expiry → nothing cached. Convert to absolute time.
		$ttl = (int) $this->ttl;
		if ( $ttl > 2592000 ) {
			$ttl = time() + $ttl;
		}
		return $m->set( $this->mkey( $key ), $entry, $ttl );
	}

	private function memcached_bump_generation() {
		$m = $this->memcached();
		if ( ! $m ) {
			return false;
		}
		if ( false === $m->increment( $this->prefix . 'gen', 1 ) ) {
			$m->set( $this->prefix . 'gen', 2 );
		}
		$this->generation = null;
		return true;
	}

	// -------------------------------------------------------------------------
	// Static request helpers — shared by the drop-in (serve) and the plugin
	// (store) so the two paths can never disagree about what is cacheable.
	// -------------------------------------------------------------------------

	/**
	 * Device bucket for a request, mirroring the edge X-Device split.
	 *
	 * Prefers the X-Device header the edge forwards; falls back to a User-Agent
	 * sniff so it still works when the origin is hit directly.
	 *
	 * @param array $server $_SERVER-like array.
	 * @return string desktop|mobile|app
	 */
	public static function detect_device( array $server ) {
		$xd = isset( $server['HTTP_X_DEVICE'] ) ? strtolower( trim( $server['HTTP_X_DEVICE'] ) ) : '';
		if ( 'app' === $xd || 'desktop' === $xd || 'mobile' === $xd ) {
			return $xd;
		}
		if ( 'tablet' === $xd ) {
			return 'mobile';
		}
		$ua = isset( $server['HTTP_USER_AGENT'] ) ? $server['HTTP_USER_AGENT'] : '';
		if ( $ua && preg_match( '/(android|iphone|ipod|mobile|blackberry|iemobile|opera mini)/i', $ua ) ) {
			return 'mobile';
		}
		return 'desktop';
	}

	/**
	 * Full cache variant for a request: the device bucket, plus the primary
	 * Accept-Language tag when the site varies HTML by language (so a miss never
	 * serves the wrong language). Used as the key's third segment by BOTH the
	 * serve (drop-in) and store (plugin) paths so they stay in lockstep.
	 *
	 * @param array $cfg    Rules config (expects 'vary_language').
	 * @param array $server $_SERVER-like array.
	 * @return string
	 */
	public static function cache_variant( array $cfg, array $server ) {
		$variant = self::detect_device( $server );
		if ( ! empty( $cfg['vary_language'] ) && ! empty( $server['HTTP_ACCEPT_LANGUAGE'] ) ) {
			$lang = strtolower( substr( trim( strtok( $server['HTTP_ACCEPT_LANGUAGE'], ',' ) ), 0, 5 ) );
			$variant .= '|' . preg_replace( '/[^a-z-]/', '', $lang );
		}
		// Opt-in content-negotiation variant. When the edge sets the configured
		// header to "1" (e.g. X-Accept-Markdown, which the edge MUST strip from the
		// client request first and set itself from its own Accept negotiation), the
		// representation is stored and served under its own key so it never collides
		// with the default (HTML) one. Off unless variant_header is configured. The
		// serve and store paths both call this, so the key matches on both sides.
		if ( ! empty( $cfg['variant_header'] ) ) {
			$h = 'HTTP_' . strtr( strtoupper( $cfg['variant_header'] ), '-', '_' );
			if ( isset( $server[ $h ] ) && '1' === $server[ $h ] ) {
				$variant .= '|md';
			}
		}
		return $variant;
	}

	/**
	 * Strip the "ignored" query params (utm_*, fbclid, …) from a URI, returning
	 * the path used for the key. Any other param is a real content selector and
	 * is left for request_is_cacheable() to decide on.
	 *
	 * @param string   $uri     Raw REQUEST_URI.
	 * @param string[] $ignored Query keys to drop before keying.
	 * @return string
	 */
	public static function effective_uri( $uri, array $ignored, $allowed = null ) {
		$hash = strpos( $uri, '#' );
		if ( false !== $hash ) {
			$uri = substr( $uri, 0, $hash );
		}
		$parts = explode( '?', $uri, 2 );
		$path  = $parts[0];
		if ( ! isset( $parts[1] ) || '' === $parts[1] ) {
			return $path;
		}
		parse_str( $parts[1], $q );
		if ( is_array( $allowed ) ) {
			// Allowlist mode (opt-in): keep ONLY the params that are known to change
			// the page, drop everything else. A junk/unknown param then normalises to
			// the clean URL and is served from cache instead of forcing a full render,
			// which closes the "?zz=1 busts the cache" amplification. Only safe where
			// every content-varying param is listed — hence opt-in, off by default.
			$q = array_intersect_key( $q, array_flip( $allowed ) );
		} else {
			// Default mode: drop the configured ignored (marketing) params only.
			foreach ( $ignored as $k ) {
				unset( $q[ $k ] );
			}
		}
		if ( empty( $q ) ) {
			return $path;
		}
		ksort( $q );
		return $path . '?' . http_build_query( $q );
	}

	/**
	 * Decide whether a request may be served from / stored in the page cache.
	 *
	 * Conservative on purpose: never caches wp-admin/login/cron/REST, never a
	 * request carrying an excluded cookie (logged-in, cart…), never an excluded
	 * URL, and — to avoid cache pollution — never a URL that still carries a
	 * query string after the ignored (marketing) params are removed.
	 *
	 * @param array $cfg    { excluded_urls[], excluded_cookies[], accepted_query_strings[] }.
	 * @param array $server $_SERVER-like array.
	 * @param array $cookies $_COOKIE-like array.
	 * @return bool
	 */
	public static function request_is_cacheable( array $cfg, array $server, array $cookies ) {
		// GET only. HEAD is excluded so a HEAD never overwrites a GET entry and
		// the serve path never echoes a body for a HEAD.
		if ( 'GET' !== ( isset( $server['REQUEST_METHOD'] ) ? $server['REQUEST_METHOD'] : 'GET' ) ) {
			return false;
		}

		// Host allowlist: only cache requests for a host we actually serve, so a
		// spoofed Host header cannot pollute the key space or fill the disk.
		$hosts = (array) ( $cfg['hosts'] ?? array() );
		if ( ! empty( $hosts ) ) {
			$host = strtolower( isset( $server['HTTP_HOST'] ) ? $server['HTTP_HOST'] : '' );
			if ( ! in_array( $host, $hosts, true ) ) {
				return false;
			}
		}

		$uri = isset( $server['REQUEST_URI'] ) ? $server['REQUEST_URI'] : '/';

		// Hard skips: WordPress admin surface and dynamic endpoints.
		if ( preg_match( '#^/(wp-admin|wp-login\.php|wp-cron\.php|xmlrpc\.php|wp-json)(/|\?|$)#', $uri ) ) {
			return false;
		}

		// Excluded URLs (regex, falling back to substring).
		foreach ( (array) ( $cfg['excluded_urls'] ?? array() ) as $pattern ) {
			if ( '' !== $pattern && self::pattern_match( $pattern, $uri ) ) {
				return false;
			}
		}

		// Any excluded cookie present → treat as personalised, skip.
		if ( ! empty( $cookies ) ) {
			$excluded = (array) ( $cfg['excluded_cookies'] ?? array() );
			foreach ( array_keys( $cookies ) as $name ) {
				foreach ( $excluded as $pattern ) {
					if ( '' !== $pattern && self::pattern_match( $pattern, $name ) ) {
						return false;
					}
				}
			}
		}

		// Query string handling.
		// Default mode: a URL that still carries a query string after the ignored
		// (marketing) params are removed is skipped, to avoid cache pollution.
		// Allowlist mode (opt-in): effective_uri() drops every unknown param and
		// keeps only the content-varying ones, so a residual query is normally no
		// reason to bypass — the request is cacheable under its normalised key.
		// BUT a dynamic param (search `s`, or one the site configures) must never
		// collapse onto the clean URL: that would serve — and poison — the clean
		// page. Those bypass the cache, exactly as search already does by default.
		$qpos = strpos( $uri, '?' );
		if ( false !== $qpos ) {
			parse_str( substr( $uri, $qpos + 1 ), $q );
			if ( empty( $cfg['query_normalize'] ) ) {
				foreach ( (array) ( $cfg['accepted_query_strings'] ?? array() ) as $k ) {
					unset( $q[ $k ] );
				}
				if ( ! empty( $q ) ) {
					return false;
				}
			} else {
				foreach ( (array) ( $cfg['bypass_query_strings'] ?? array() ) as $k ) {
					if ( isset( $q[ $k ] ) ) {
						return false;
					}
				}
			}
		}

		return true;
	}

	/**
	 * Safe pattern match: try as a regex, fall back to a substring match on an
	 * invalid pattern. Mirrors TE_Headers so both layers agree.
	 *
	 * @param string $pattern User-supplied pattern.
	 * @param string $subject Subject string.
	 * @return bool
	 */
	public static function pattern_match( $pattern, $subject ) {
		$regex  = '#' . str_replace( '#', '\\#', $pattern ) . '#i';
		$result = @preg_match( $regex, $subject );
		if ( false === $result ) {
			return false !== stripos( $subject, $pattern );
		}
		return 1 === $result;
	}
}
