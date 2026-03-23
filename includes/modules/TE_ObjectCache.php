<?php
/**
 * Object Cache manager.
 *
 * Detects available backends (Redis, Memcached, APCu), generates and manages
 * the wp-content/object-cache.php drop-in, and provides statistics.
 *
 * This module does NOT implement the actual cache backend — it generates
 * a standard drop-in file that WordPress loads automatically.
 *
 * @package flavor_edge_cache
 */

namespace flavor_edge;

defined( 'ABSPATH' ) || exit;

class TE_ObjectCache {

	/**
	 * Initialize object cache management.
	 */
	public static function init() {
		// Only in admin.
		if ( ! is_admin() ) {
			return;
		}

		// AJAX handlers.
		add_action( 'wp_ajax_flavor_edge_enable_object_cache', array( __CLASS__, 'ajax_enable' ) );
		add_action( 'wp_ajax_flavor_edge_disable_object_cache', array( __CLASS__, 'ajax_disable' ) );
		add_action( 'wp_ajax_flavor_edge_flush_object_cache', array( __CLASS__, 'ajax_flush' ) );
	}

	/**
	 * Detect available cache backends on this server.
	 *
	 * @return array { redis: bool, memcached: bool, apcu: bool, recommended: string|false }
	 */
	public static function detect_backends() {
		$result = array(
			'redis'       => false,
			'redis_server' => false,
			'redis_info'  => '',
			'memcached'   => false,
			'memcached_info' => '',
			'apcu'        => false,
			'apcu_info'   => '',
			'recommended' => false,
		);

		// Redis via phpredis extension.
		if ( class_exists( 'Redis' ) ) {
			$result['redis'] = true;
			$host = defined( 'WP_REDIS_HOST' ) ? WP_REDIS_HOST : '127.0.0.1';
			$port = defined( 'WP_REDIS_PORT' ) ? WP_REDIS_PORT : 6379;
			$pass = defined( 'WP_REDIS_PASSWORD' ) ? WP_REDIS_PASSWORD : '';
			$result['redis_info'] = $host . ':' . $port;

			// Test connection.
			try {
				$redis = new \Redis();
				if ( @$redis->connect( $host, (int) $port, 1 ) ) {
					if ( $pass ) {
						$redis->auth( $pass );
					}
					$info = $redis->info( 'memory' );
					$result['redis_info'] .= ' — ' . size_format( $info['used_memory'] ?? 0 ) . ' used';
					$redis->close();
				}
			} catch ( \Exception $e ) {
				$result['redis_info'] .= ' — connection failed: ' . $e->getMessage();
			}
		} else {
			// phpredis not installed — try to detect Redis server via raw socket.
			$host = defined( 'WP_REDIS_HOST' ) ? WP_REDIS_HOST : '127.0.0.1';
			$port = defined( 'WP_REDIS_PORT' ) ? WP_REDIS_PORT : 6379;
			$pass = defined( 'WP_REDIS_PASSWORD' ) ? WP_REDIS_PASSWORD : '';
			$socket = @fsockopen( $host, (int) $port, $errno, $errstr, 1 );
			if ( $socket ) {
				$redis_alive    = false;
				$needs_auth     = false;
				$missing_ext    = true; // We're in the else branch — phpredis is missing.

				// Try AUTH + PING if password is configured.
				if ( $pass ) {
					fwrite( $socket, "AUTH " . $pass . "\r\n" );
					$auth_response = fgets( $socket, 128 );
					if ( false !== strpos( $auth_response, '+OK' ) ) {
						$redis_alive = true;
					}
				}

				// PING — works if no AUTH needed, or after successful AUTH.
				fwrite( $socket, "PING\r\n" );
				$response = fgets( $socket, 128 );
				fclose( $socket );

				if ( false !== strpos( $response, 'PONG' ) ) {
					$redis_alive = true;
				} elseif ( false !== strpos( $response, 'NOAUTH' ) ) {
					$needs_auth = true;
				}

				if ( $redis_alive ) {
					$result['redis_server'] = true;
					$result['redis_info']   = $host . ':' . $port . ' — ' .
						__( 'Redis server running, but PHP extension (phpredis) is not installed. Install it with: sudo apt install php-redis && sudo systemctl restart php-fpm', 'flavor-edge-cache' );
				} elseif ( $needs_auth ) {
					$result['redis_server'] = true;
					$result['redis_info']   = $host . ':' . $port;
					if ( ! $pass ) {
						$result['redis_needs_auth'] = true;
						$result['redis_info']      .= ' — ' .
							__( 'Redis server requires a password. Add to wp-config.php:', 'flavor-edge-cache' ) .
							" define('WP_REDIS_PASSWORD', 'your-password');";
					} else {
						$result['redis_info'] .= ' — ' .
							__( 'Redis AUTH failed. Check WP_REDIS_PASSWORD in wp-config.php.', 'flavor-edge-cache' );
					}
				}
			}
		}

		// Memcached.
		if ( class_exists( 'Memcached' ) ) {
			$result['memcached'] = true;
			$host = defined( 'WP_MEMCACHED_HOST' ) ? WP_MEMCACHED_HOST : '127.0.0.1';
			$port = defined( 'WP_MEMCACHED_PORT' ) ? WP_MEMCACHED_PORT : 11211;
			$result['memcached_info'] = $host . ':' . $port;
		}

		// APCu.
		if ( function_exists( 'apcu_enabled' ) && apcu_enabled() ) {
			$result['apcu'] = true;
			$info = apcu_cache_info( true );
			$result['apcu_info'] = size_format( $info['mem_size'] ?? 0 ) . ' used';
		}

		// Recommend the best available.
		if ( $result['redis'] ) {
			$result['recommended'] = 'redis';
		} elseif ( $result['memcached'] ) {
			$result['recommended'] = 'memcached';
		} elseif ( $result['apcu'] ) {
			$result['recommended'] = 'apcu';
		}

		return $result;
	}

	/**
	 * Check if our object-cache.php drop-in is installed.
	 *
	 * @return array { installed: bool, ours: bool, backend: string }
	 */
	public static function get_dropin_status() {
		$dropin = WP_CONTENT_DIR . '/object-cache.php';
		$result = array(
			'installed' => false,
			'ours'      => false,
			'backend'   => '',
			'other'     => '',
		);

		if ( ! file_exists( $dropin ) ) {
			return $result;
		}

		$result['installed'] = true;

		// Check if it's ours.
		$header = file_get_contents( $dropin, false, null, 0, 500 );
		if ( false !== strpos( $header, 'Flavor Edge Cache' ) ) {
			$result['ours'] = true;
			if ( false !== strpos( $header, 'Redis' ) ) {
				$result['backend'] = 'redis';
			} elseif ( false !== strpos( $header, 'Memcached' ) ) {
				$result['backend'] = 'memcached';
			} elseif ( false !== strpos( $header, 'APCu' ) ) {
				$result['backend'] = 'apcu';
			}
		} else {
			// Another plugin's drop-in.
			if ( false !== strpos( $header, 'Redis' ) ) {
				$result['other'] = 'Redis Object Cache';
			} elseif ( false !== strpos( $header, 'W3 Total Cache' ) ) {
				$result['other'] = 'W3 Total Cache';
			} else {
				$result['other'] = 'Unknown plugin';
			}
		}

		return $result;
	}

	/**
	 * Get Object Cache statistics.
	 *
	 * @return array
	 */
	public static function get_stats() {
		global $wp_object_cache;

		$stats = array(
			'enabled'    => wp_using_ext_object_cache(),
			'hits'       => 0,
			'misses'     => 0,
			'hit_ratio'  => 0,
			'groups'     => 0,
			'backend'    => 'none',
		);

		if ( ! $stats['enabled'] ) {
			return $stats;
		}

		if ( isset( $wp_object_cache->cache_hits ) ) {
			$stats['hits'] = (int) $wp_object_cache->cache_hits;
		}
		if ( isset( $wp_object_cache->cache_misses ) ) {
			$stats['misses'] = (int) $wp_object_cache->cache_misses;
		}

		$total = $stats['hits'] + $stats['misses'];
		if ( $total > 0 ) {
			$stats['hit_ratio'] = round( ( $stats['hits'] / $total ) * 100, 1 );
		}

		// Detect backend type.
		$dropin = self::get_dropin_status();
		$stats['backend'] = $dropin['backend'] ?: 'external';

		return $stats;
	}

	/**
	 * Generate the object-cache.php drop-in for a specific backend.
	 *
	 * @param string $backend One of: redis, memcached, apcu.
	 * @return string PHP code for the drop-in.
	 */
	public static function generate_dropin( $backend ) {
		switch ( $backend ) {
			case 'redis':
				return self::generate_redis_dropin();
			case 'apcu':
				return self::generate_apcu_dropin();
			default:
				return '<?php // Unsupported backend.';
		}
	}

	/**
	 * Install the object-cache.php drop-in.
	 *
	 * @param string $backend Backend type.
	 * @return array { success, message }
	 */
	public static function install_dropin( $backend ) {
		$dropin = WP_CONTENT_DIR . '/object-cache.php';

		// Check if another plugin's drop-in exists.
		$status = self::get_dropin_status();
		if ( $status['installed'] && ! $status['ours'] ) {
			return array(
				'success' => false,
				'message' => sprintf(
					/* translators: %s: plugin name */
					__( 'Another object cache drop-in is already installed (%s). Remove it first.', 'flavor-edge-cache' ),
					$status['other']
				),
			);
		}

		$code = self::generate_dropin( $backend );

		if ( false === file_put_contents( $dropin, $code ) ) {
			return array(
				'success' => false,
				'message' => __( 'Could not write object-cache.php. Check file permissions on wp-content/.', 'flavor-edge-cache' ),
			);
		}

		return array(
			'success' => true,
			'message' => sprintf(
				/* translators: %s: backend name */
				__( 'Object Cache enabled with %s backend.', 'flavor-edge-cache' ),
				ucfirst( $backend )
			),
		);
	}

	/**
	 * Remove our object-cache.php drop-in.
	 *
	 * @return array { success, message }
	 */
	public static function remove_dropin() {
		$dropin = WP_CONTENT_DIR . '/object-cache.php';
		$status = self::get_dropin_status();

		if ( ! $status['installed'] ) {
			return array( 'success' => true, 'message' => __( 'No drop-in to remove.', 'flavor-edge-cache' ) );
		}

		if ( ! $status['ours'] ) {
			return array(
				'success' => false,
				'message' => __( 'The drop-in was not installed by this plugin.', 'flavor-edge-cache' ),
			);
		}

		if ( @unlink( $dropin ) ) {
			return array( 'success' => true, 'message' => __( 'Object Cache disabled.', 'flavor-edge-cache' ) );
		}

		return array( 'success' => false, 'message' => __( 'Could not remove object-cache.php.', 'flavor-edge-cache' ) );
	}

	// -------------------------------------------------------------------------
	// AJAX handlers.
	// -------------------------------------------------------------------------

	public static function ajax_enable() {
		check_ajax_referer( 'flavor_edge_admin', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) { wp_send_json_error( 'Unauthorized' ); }

		$backend = sanitize_text_field( $_POST['backend'] ?? '' );
		$result  = self::install_dropin( $backend );
		wp_send_json( $result );
	}

	public static function ajax_disable() {
		check_ajax_referer( 'flavor_edge_admin', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) { wp_send_json_error( 'Unauthorized' ); }

		$result = self::remove_dropin();
		wp_send_json( $result );
	}

	public static function ajax_flush() {
		check_ajax_referer( 'flavor_edge_admin', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) { wp_send_json_error( 'Unauthorized' ); }

		wp_cache_flush();
		wp_send_json_success( __( 'Object cache flushed.', 'flavor-edge-cache' ) );
	}

	// -------------------------------------------------------------------------
	// Drop-in generators.
	// -------------------------------------------------------------------------

	private static function generate_redis_dropin() {
		return <<<'PHP'
<?php
/**
 * Object Cache Drop-in — Redis backend.
 * Generated by Flavor Edge Cache plugin.
 *
 * Configuration via wp-config.php constants:
 *   WP_REDIS_HOST     (default: 127.0.0.1)
 *   WP_REDIS_PORT     (default: 6379)
 *   WP_REDIS_PASSWORD (default: null)
 *   WP_REDIS_DATABASE (default: 0)
 *   WP_REDIS_PREFIX   (default: site blog_id)
 */

defined( 'ABSPATH' ) || exit;

function wp_cache_init() {
	global $wp_object_cache;
	$wp_object_cache = new WP_Object_Cache_Redis();
}
function wp_cache_get( $key, $group = 'default', $force = false, &$found = null ) {
	global $wp_object_cache;
	return $wp_object_cache->get( $key, $group, $force, $found );
}
function wp_cache_set( $key, $data, $group = 'default', $expire = 0 ) {
	global $wp_object_cache;
	return $wp_object_cache->set( $key, $data, $group, $expire );
}
function wp_cache_delete( $key, $group = 'default' ) {
	global $wp_object_cache;
	return $wp_object_cache->delete( $key, $group );
}
function wp_cache_flush() {
	global $wp_object_cache;
	return $wp_object_cache->flush();
}
function wp_cache_add( $key, $data, $group = 'default', $expire = 0 ) {
	global $wp_object_cache;
	return $wp_object_cache->add( $key, $data, $group, $expire );
}
function wp_cache_replace( $key, $data, $group = 'default', $expire = 0 ) {
	global $wp_object_cache;
	return $wp_object_cache->replace( $key, $data, $group, $expire );
}
function wp_cache_incr( $key, $offset = 1, $group = 'default' ) {
	global $wp_object_cache;
	return $wp_object_cache->incr( $key, $offset, $group );
}
function wp_cache_decr( $key, $offset = 1, $group = 'default' ) {
	global $wp_object_cache;
	return $wp_object_cache->decr( $key, $offset, $group );
}
function wp_cache_add_global_groups( $groups ) {
	global $wp_object_cache;
	$wp_object_cache->add_global_groups( $groups );
}
function wp_cache_add_non_persistent_groups( $groups ) {
	global $wp_object_cache;
	$wp_object_cache->add_non_persistent_groups( $groups );
}
function wp_cache_switch_to_blog( $blog_id ) {
	global $wp_object_cache;
	$wp_object_cache->switch_to_blog( $blog_id );
}
function wp_cache_close() {
	global $wp_object_cache;
	return $wp_object_cache->close();
}

class WP_Object_Cache_Redis {
	private $redis;
	private $cache      = array();
	private $global_groups = array( 'users', 'userlogins', 'usermeta', 'user_meta', 'useremail', 'userslugs', 'site-transient', 'site-options', 'blog-lookup', 'blog-details', 'site-details', 'global-posts', 'blog-id-cache', 'networks', 'rss' );
	private $non_persistent = array();
	private $blog_prefix = '';
	private $global_prefix = '';
	public $cache_hits   = 0;
	public $cache_misses = 0;

	public function __construct() {
		$this->blog_prefix   = is_multisite() ? get_current_blog_id() . ':' : '';
		$this->global_prefix = defined( 'WP_REDIS_PREFIX' ) ? WP_REDIS_PREFIX : '';
		$this->connect();
	}

	private function connect() {
		if ( ! class_exists( 'Redis' ) ) { return; }
		try {
			$this->redis = new Redis();
			$host = defined( 'WP_REDIS_HOST' ) ? WP_REDIS_HOST : '127.0.0.1';
			$port = defined( 'WP_REDIS_PORT' ) ? WP_REDIS_PORT : 6379;
			$this->redis->connect( $host, (int) $port, 1 );
			if ( defined( 'WP_REDIS_PASSWORD' ) && WP_REDIS_PASSWORD ) {
				$this->redis->auth( WP_REDIS_PASSWORD );
			}
			if ( defined( 'WP_REDIS_DATABASE' ) ) {
				$this->redis->select( (int) WP_REDIS_DATABASE );
			}
		} catch ( Exception $e ) {
			$this->redis = null;
		}
	}

	private function key( $key, $group ) {
		$prefix = in_array( $group, $this->global_groups, true ) ? $this->global_prefix : $this->blog_prefix;
		return $prefix . $group . ':' . $key;
	}

	public function get( $key, $group = 'default', $force = false, &$found = null ) {
		$k = $this->key( $key, $group );
		if ( ! $force && isset( $this->cache[ $k ] ) ) {
			$found = true;
			$this->cache_hits++;
			return $this->cache[ $k ];
		}
		if ( in_array( $group, $this->non_persistent, true ) || ! $this->redis ) {
			$found = isset( $this->cache[ $k ] );
			$found ? $this->cache_hits++ : $this->cache_misses++;
			return $found ? $this->cache[ $k ] : false;
		}
		$val = $this->redis->get( $k );
		if ( false === $val ) {
			$found = false;
			$this->cache_misses++;
			return false;
		}
		$found = true;
		$this->cache_hits++;
		$val = maybe_unserialize( $val );
		$this->cache[ $k ] = $val;
		return $val;
	}

	public function set( $key, $data, $group = 'default', $expire = 0 ) {
		$k = $this->key( $key, $group );
		$this->cache[ $k ] = $data;
		if ( in_array( $group, $this->non_persistent, true ) || ! $this->redis ) { return true; }
		$val = maybe_serialize( $data );
		if ( $expire > 0 ) {
			return $this->redis->setex( $k, $expire, $val );
		}
		return $this->redis->set( $k, $val );
	}

	public function add( $key, $data, $group = 'default', $expire = 0 ) {
		$k = $this->key( $key, $group );
		if ( isset( $this->cache[ $k ] ) ) { return false; }
		if ( $this->redis && ! in_array( $group, $this->non_persistent, true ) ) {
			if ( $this->redis->exists( $k ) ) { return false; }
		}
		return $this->set( $key, $data, $group, $expire );
	}

	public function replace( $key, $data, $group = 'default', $expire = 0 ) {
		$k = $this->key( $key, $group );
		if ( ! isset( $this->cache[ $k ] ) && $this->redis && ! $this->redis->exists( $k ) ) { return false; }
		return $this->set( $key, $data, $group, $expire );
	}

	public function delete( $key, $group = 'default' ) {
		$k = $this->key( $key, $group );
		unset( $this->cache[ $k ] );
		if ( $this->redis && ! in_array( $group, $this->non_persistent, true ) ) {
			$this->redis->del( $k );
		}
		return true;
	}

	public function incr( $key, $offset = 1, $group = 'default' ) {
		$k = $this->key( $key, $group );
		if ( $this->redis && ! in_array( $group, $this->non_persistent, true ) ) {
			$val = $this->redis->incrBy( $k, $offset );
			$this->cache[ $k ] = $val;
			return $val;
		}
		$val = isset( $this->cache[ $k ] ) ? (int) $this->cache[ $k ] + $offset : $offset;
		$this->cache[ $k ] = $val;
		return $val;
	}

	public function decr( $key, $offset = 1, $group = 'default' ) {
		return $this->incr( $key, -$offset, $group );
	}

	public function flush() {
		$this->cache = array();
		if ( $this->redis ) { $this->redis->flushDb(); }
		return true;
	}

	public function close() {
		if ( $this->redis ) { $this->redis->close(); }
		return true;
	}

	public function add_global_groups( $groups ) {
		$groups = (array) $groups;
		$this->global_groups = array_unique( array_merge( $this->global_groups, $groups ) );
	}

	public function add_non_persistent_groups( $groups ) {
		$groups = (array) $groups;
		$this->non_persistent = array_unique( array_merge( $this->non_persistent, $groups ) );
	}

	public function switch_to_blog( $blog_id ) {
		$this->blog_prefix = is_multisite() ? $blog_id . ':' : '';
	}
}
PHP;
	}

	private static function generate_apcu_dropin() {
		return <<<'PHP'
<?php
/**
 * Object Cache Drop-in — APCu backend.
 * Generated by Flavor Edge Cache plugin.
 */

defined( 'ABSPATH' ) || exit;

function wp_cache_init() { global $wp_object_cache; $wp_object_cache = new WP_Object_Cache_APCu(); }
function wp_cache_get( $key, $group = 'default', $force = false, &$found = null ) { global $wp_object_cache; return $wp_object_cache->get( $key, $group, $force, $found ); }
function wp_cache_set( $key, $data, $group = 'default', $expire = 0 ) { global $wp_object_cache; return $wp_object_cache->set( $key, $data, $group, $expire ); }
function wp_cache_delete( $key, $group = 'default' ) { global $wp_object_cache; return $wp_object_cache->delete( $key, $group ); }
function wp_cache_flush() { global $wp_object_cache; return $wp_object_cache->flush(); }
function wp_cache_add( $key, $data, $group = 'default', $expire = 0 ) { global $wp_object_cache; return $wp_object_cache->add( $key, $data, $group, $expire ); }
function wp_cache_replace( $key, $data, $group = 'default', $expire = 0 ) { global $wp_object_cache; return $wp_object_cache->replace( $key, $data, $group, $expire ); }
function wp_cache_incr( $key, $offset = 1, $group = 'default' ) { global $wp_object_cache; return $wp_object_cache->incr( $key, $offset, $group ); }
function wp_cache_decr( $key, $offset = 1, $group = 'default' ) { global $wp_object_cache; return $wp_object_cache->decr( $key, $offset, $group ); }
function wp_cache_add_global_groups( $groups ) { global $wp_object_cache; $wp_object_cache->add_global_groups( $groups ); }
function wp_cache_add_non_persistent_groups( $groups ) { global $wp_object_cache; $wp_object_cache->add_non_persistent_groups( $groups ); }
function wp_cache_switch_to_blog( $blog_id ) { global $wp_object_cache; $wp_object_cache->switch_to_blog( $blog_id ); }
function wp_cache_close() { return true; }

class WP_Object_Cache_APCu {
	private $cache = array();
	private $global_groups = array();
	private $non_persistent = array();
	private $prefix = '';
	public $cache_hits = 0;
	public $cache_misses = 0;

	public function __construct() {
		$this->prefix = is_multisite() ? get_current_blog_id() . ':' : '';
	}

	private function key( $key, $group ) {
		$p = in_array( $group, $this->global_groups, true ) ? '' : $this->prefix;
		return 'fe:' . $p . $group . ':' . $key;
	}

	public function get( $key, $group = 'default', $force = false, &$found = null ) {
		$k = $this->key( $key, $group );
		if ( ! $force && isset( $this->cache[$k] ) ) { $found = true; $this->cache_hits++; return $this->cache[$k]; }
		if ( in_array( $group, $this->non_persistent, true ) ) { $found = isset( $this->cache[$k] ); $found ? $this->cache_hits++ : $this->cache_misses++; return $found ? $this->cache[$k] : false; }
		$val = apcu_fetch( $k, $found );
		$found ? $this->cache_hits++ : $this->cache_misses++;
		if ( $found ) { $this->cache[$k] = $val; }
		return $found ? $val : false;
	}

	public function set( $key, $data, $group = 'default', $expire = 0 ) {
		$k = $this->key( $key, $group ); $this->cache[$k] = $data;
		if ( in_array( $group, $this->non_persistent, true ) ) { return true; }
		return apcu_store( $k, $data, $expire );
	}

	public function add( $key, $data, $group = 'default', $expire = 0 ) {
		$k = $this->key( $key, $group );
		if ( isset( $this->cache[$k] ) ) { return false; }
		if ( ! in_array( $group, $this->non_persistent, true ) && apcu_exists( $k ) ) { return false; }
		return $this->set( $key, $data, $group, $expire );
	}

	public function replace( $key, $data, $group = 'default', $expire = 0 ) {
		$k = $this->key( $key, $group );
		if ( ! isset( $this->cache[$k] ) && ! apcu_exists( $k ) ) { return false; }
		return $this->set( $key, $data, $group, $expire );
	}

	public function delete( $key, $group = 'default' ) {
		$k = $this->key( $key, $group ); unset( $this->cache[$k] ); apcu_delete( $k ); return true;
	}

	public function incr( $key, $offset = 1, $group = 'default' ) {
		$k = $this->key( $key, $group ); $v = apcu_inc( $k, $offset ); $this->cache[$k] = $v; return $v;
	}

	public function decr( $key, $offset = 1, $group = 'default' ) {
		$k = $this->key( $key, $group ); $v = apcu_dec( $k, $offset ); $this->cache[$k] = $v; return $v;
	}

	public function flush() { $this->cache = array(); apcu_clear_cache(); return true; }
	public function add_global_groups( $groups ) { $this->global_groups = array_unique( array_merge( $this->global_groups, (array) $groups ) ); }
	public function add_non_persistent_groups( $groups ) { $this->non_persistent = array_unique( array_merge( $this->non_persistent, (array) $groups ) ); }
	public function switch_to_blog( $blog_id ) { $this->prefix = is_multisite() ? $blog_id . ':' : ''; }
}
PHP;
	}
}
