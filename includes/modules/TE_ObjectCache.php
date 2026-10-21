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
		add_action( 'wp_ajax_flavor_edge_replace_object_cache', array( __CLASS__, 'ajax_replace' ) );

		// Turnkey: auto-enable on a live memory backend, and surface honest status.
		add_action( 'admin_init', array( __CLASS__, 'maybe_auto_enable' ) );
		add_action( 'admin_notices', array( __CLASS__, 'admin_notices' ) );
	}

	/**
	 * Auto-install our object-cache drop-in when a live in-memory backend exists
	 * and nothing (of ours) is set up yet. Opt-out via the object_cache_auto
	 * setting. Never overwrites a foreign drop-in — it flags a notice instead.
	 * The backend round-trips are rate-limited so this stays cheap on admin_init.
	 *
	 * @param bool $force Skip the rate-limit (used on activation).
	 */
	public static function maybe_auto_enable( $force = false ) {
		if ( ! TE_Settings::get( 'object_cache_auto', true ) ) {
			return;
		}
		$status = self::get_dropin_status();
		if ( $status['installed'] && $status['ours'] ) {
			return; // already ours (kept current by maybe_upgrade_dropin on upgrade).
		}
		if ( ! $force && get_transient( 'flavor_edge_oc_autocheck' ) ) {
			return;
		}
		set_transient( 'flavor_edge_oc_autocheck', 1, HOUR_IN_SECONDS );

		if ( $status['installed'] && ! $status['ours'] ) {
			update_option( 'flavor_edge_oc_foreign', $status['other'] ); // flag for a notice; never silently overwrite.
			return;
		}

		$live = self::detect_backends();
		if ( empty( $live['recommended'] ) ) {
			return; // no live memory backend — needs a PHP extension + service.
		}
		$res = self::install_dropin( $live['recommended'] );
		if ( ! empty( $res['success'] ) ) {
			update_option( 'flavor_edge_oc_autoenabled', $live['recommended'] );
			delete_option( 'flavor_edge_oc_foreign' );
		}
	}

	/**
	 * Honest admin notices: auto-enabled (once), a foreign drop-in blocking us,
	 * and — critically — a drop-in whose backend is down (installed but NOT
	 * persisting, which the hit counter alone would hide).
	 */
	public static function admin_notices() {
		$screen    = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		$on_plugin = $screen && in_array( $screen->id, array( 'toplevel_page_flavor-edge-cache', 'dashboard' ), true );

		$auto = get_option( 'flavor_edge_oc_autoenabled' );
		if ( $auto ) {
			delete_option( 'flavor_edge_oc_autoenabled' );
			printf(
				'<div class="notice notice-success is-dismissible"><p>%s</p></div>',
				esc_html( sprintf(
					/* translators: %s: backend name */
					__( 'Transparent Edge Cache: object cache enabled automatically with the %s backend.', 'flavor-edge-cache' ),
					ucfirst( (string) $auto )
				) )
			);
		}

		if ( ! $on_plugin ) {
			return;
		}

		$foreign = get_option( 'flavor_edge_oc_foreign' );
		if ( $foreign ) {
			printf(
				'<div class="notice notice-warning"><p>%s</p></div>',
				esc_html( sprintf(
					/* translators: %s: other plugin name */
					__( 'Transparent Edge Cache: an object-cache.php from "%s" is in place and is blocking our object cache. Remove it, or replace it from TE Cache → Cache.', 'flavor-edge-cache' ),
					$foreign
				) )
			);
		}

		$stats = self::get_stats();
		if ( ! empty( $stats['degraded'] ) ) {
			printf(
				'<div class="notice notice-error"><p><strong>%s</strong></p></div>',
				esc_html( sprintf(
					/* translators: %s: backend name */
					__( 'Transparent Edge Cache: the object cache drop-in (%s) is installed but its server is not answering — it is NOT caching persistently (every request recomputes against the database). Start the service or switch backend.', 'flavor-edge-cache' ),
					$stats['backend']
				) )
			);
		}
	}

	/**
	 * Detect available cache backends on this server.
	 *
	 * @return array { redis: bool, memcached: bool, apcu: bool, recommended: string|false }
	 */
	public static function detect_backends( $force = false ) {
		// Per-request memo: admin_notices, get_stats and the dashboard card each call
		// this on a single admin load, and each call opens real Redis/Memcached
		// sockets. Probe once per request unless a caller needs a fresh read.
		static $memo = null;
		if ( ! $force && null !== $memo ) {
			return $memo;
		}

		$result = array(
			'redis'       => false,
			'redis_server' => false,
			'redis_needs_auth' => false,
			'redis_down'  => false,
			'redis_info'  => '',
			'memcached'   => false,
			'memcached_server' => false,
			'memcached_info' => '',
			'apcu'        => false,
			'apcu_info'   => '',
			'recommended' => false,
		);

		// Redis via phpredis extension.
		if ( class_exists( 'Redis' ) ) {
			$host = defined( 'WP_REDIS_HOST' ) ? WP_REDIS_HOST : '127.0.0.1';
			$port = defined( 'WP_REDIS_PORT' ) ? WP_REDIS_PORT : 6379;
			$pass = defined( 'WP_REDIS_PASSWORD' ) ? WP_REDIS_PASSWORD : '';
			$result['redis_info'] = $host . ':' . $port;

			// The extension alone proves nothing: a box can ship phpredis with no
			// Redis daemon running. Only a live round-trip (connect + PING) marks it
			// available, or we would green-light a backend that caches nothing.
			try {
				$redis = new \Redis();
				if ( @$redis->connect( $host, (int) $port, 1 ) ) {
					// The daemon answered. AUTH (if a password is configured) and PING
					// can still fail on a password-protected server — the common case
					// in production. phpredis throws on those, so catch them here and
					// surface an actionable hint instead of a bare "unavailable".
					try {
						if ( $pass ) {
							$redis->auth( $pass );
						}
						if ( $redis->ping() ) {
							$result['redis'] = true;
							$info = $redis->info( 'memory' );
							$result['redis_info'] .= ' — ' . size_format( $info['used_memory'] ?? 0 ) . ' used';
						}
					} catch ( \Exception $auth_e ) {
						// Reached the daemon; only authentication is the blocker.
						$result['redis_server'] = true;
						$msg = $auth_e->getMessage();
						if ( ! $pass && false !== stripos( $msg, 'NOAUTH' ) ) {
							$result['redis_needs_auth'] = true;
							$result['redis_info']      .= ' — ' .
								__( 'Redis server requires a password. Add to wp-config.php:', 'flavor-edge-cache' ) .
								" define('WP_REDIS_PASSWORD', 'your-password');";
						} elseif ( false !== stripos( $msg, 'NOAUTH' ) || false !== stripos( $msg, 'WRONGPASS' ) || false !== stripos( $msg, 'invalid password' ) || false !== stripos( $msg, 'without any password' ) ) {
							$result['redis_info'] .= ' — ' .
								__( 'Redis AUTH failed. Check WP_REDIS_PASSWORD in wp-config.php.', 'flavor-edge-cache' );
						} else {
							$result['redis_info'] .= ' — connection failed: ' . $msg;
						}
					}
					$redis->close();
				} else {
					// Reached nothing: the extension is present but the daemon is down
					// or on another host/port — NOT an "extension missing" situation.
					$result['redis_down'] = true;
					$result['redis_info'] .= ' — ' . __( 'phpredis is installed but no Redis server answered here. Start Redis, or point WP_REDIS_HOST / WP_REDIS_PORT at a running one.', 'flavor-edge-cache' );
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

		// Memcached — like Redis, require BOTH the extension AND a live server.
		// addServer() never connects, so the class existing tells us nothing; we
		// force a set+get round-trip and only then call it available. Otherwise a
		// box with the client library but no daemon would show a green tick and
		// silently cache nothing.
		if ( class_exists( 'Memcached' ) ) {
			$host = defined( 'WP_MEMCACHED_HOST' ) ? WP_MEMCACHED_HOST : '127.0.0.1';
			$port = defined( 'WP_MEMCACHED_PORT' ) ? (int) WP_MEMCACHED_PORT : 11211;
			$result['memcached_info'] = $host . ':' . $port;
			try {
				$mc = new \Memcached();
				$mc->setOption( \Memcached::OPT_CONNECT_TIMEOUT, 500 ); // ms, fail fast.
				$mc->setOption( \Memcached::OPT_POLL_TIMEOUT, 500 );
				$mc->addServer( $host, $port );
				if ( @$mc->set( 'fe_pc_probe', 'ok', 5 ) && 'ok' === @$mc->get( 'fe_pc_probe' ) ) {
					$result['memcached'] = true;
					$ver = @$mc->getVersion();
					if ( is_array( $ver ) ) {
						$v = reset( $ver );
						if ( $v && '255.255.255' !== $v ) {
							$result['memcached_info'] .= ' — ' . $v;
						}
					}
				} else {
					$result['memcached_info'] .= ' — ' . __( 'the Memcached PHP extension is installed but no server answered here. Start memcached, or point WP_MEMCACHED_HOST / WP_MEMCACHED_PORT at a running one.', 'flavor-edge-cache' );
				}
				@$mc->quit();
			} catch ( \Throwable $e ) {
				$result['memcached_info'] .= ' — probe failed: ' . $e->getMessage();
			}
		} else {
			// Extension missing — see if a daemon is nonetheless running (text-protocol
			// "version" probe) so we can tell the admin to install the extension rather
			// than imply there is no server at all. Mirrors the Redis socket detection.
			$host = defined( 'WP_MEMCACHED_HOST' ) ? WP_MEMCACHED_HOST : '127.0.0.1';
			$port = defined( 'WP_MEMCACHED_PORT' ) ? (int) WP_MEMCACHED_PORT : 11211;
			$socket = @fsockopen( $host, (int) $port, $errno, $errstr, 1 );
			if ( $socket ) {
				@fwrite( $socket, "version\r\n" );
				$response = @fgets( $socket, 128 );
				@fclose( $socket );
				if ( false !== strpos( (string) $response, 'VERSION' ) ) {
					$result['memcached_server'] = true;
					$result['memcached_info']   = $host . ':' . $port . ' — ' .
						__( 'Memcached server running, but the PHP extension is not installed. Install it with: sudo apt install php-memcached && sudo systemctl restart php-fpm', 'flavor-edge-cache' );
				}
			}
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

		$memo = $result;
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
			'version'   => '',
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
			if ( preg_match( '/flavor-edge-oc-version:\s*([0-9][0-9.]*)/', $header, $m ) ) {
				$result['version'] = $m[1];
			}
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
			'version'    => '',
			'persistent' => true,  // assume yes; flipped below if our backend is dead.
			'degraded'   => false, // drop-in installed but its backend is not answering.
			'outdated'   => false, // our drop-in is from an older plugin version.
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

		// Detect backend + whether it is ACTUALLY a live persistent store. The
		// drop-in silently falls back to a per-request array when its backend is
		// down, and still increments cache_hits — so the hit counter can look
		// healthy while nothing persists. Verify the backend independently (a live
		// round-trip) instead of trusting the counter.
		$dropin = self::get_dropin_status();
		$stats['backend'] = $dropin['backend'] ?: 'external';
		$stats['version'] = $dropin['version'];

		if ( $dropin['ours'] && $dropin['backend'] ) {
			$live                = self::detect_backends();
			$stats['persistent'] = ! empty( $live[ $dropin['backend'] ] );
			$stats['degraded']   = ! $stats['persistent'];
			$stats['outdated']   = ( '' !== $dropin['version'] && $dropin['version'] !== FLAVOR_EDGE_VERSION );
		}

		return $stats;
	}

	/**
	 * Regenerate our object-cache.php drop-in when it is stale after a plugin
	 * upgrade. The drop-in is self-contained, so fixes shipped in a new plugin
	 * version never reach an already-installed drop-in until it is rewritten.
	 * Called from the plugin's version-change hook; only touches a drop-in that
	 * is ours and keeps the backend the user already chose.
	 *
	 * @return bool True if the drop-in was regenerated.
	 */
	public static function maybe_upgrade_dropin() {
		$status = self::get_dropin_status();
		if ( ! $status['installed'] || ! $status['ours'] || ! $status['backend'] ) {
			return false;
		}
		if ( $status['version'] === FLAVOR_EDGE_VERSION ) {
			return false; // already current.
		}
		$code = self::generate_dropin( $status['backend'] );
		if ( false === strpos( $code, 'function wp_cache_init' ) ) {
			return false; // never write an invalid drop-in.
		}
		return self::write_atomic( WP_CONTENT_DIR . '/object-cache.php', $code );
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
				$code = self::generate_redis_dropin();
				break;
			case 'memcached':
				$code = self::generate_memcached_dropin();
				break;
			case 'apcu':
				$code = self::generate_apcu_dropin();
				break;
			default:
				return '<?php // Unsupported backend.';
		}

		// Stamp the plugin version into the drop-in so a plugin upgrade can detect a
		// stale drop-in and regenerate it (the drop-in is self-contained, so our fixes
		// do NOT reach it otherwise). Marker is read by get_dropin_status().
		$stamp = "<?php\n// flavor-edge-oc-version: " . FLAVOR_EDGE_VERSION . "\n";
		return preg_replace( '/^<\?php\r?\n/', $stamp, $code, 1 );
	}

	/**
	 * Install the object-cache.php drop-in.
	 *
	 * @param string $backend Backend type.
	 * @return array { success, message }
	 */
	public static function install_dropin( $backend, $force = false ) {
		$dropin = WP_CONTENT_DIR . '/object-cache.php';

		// Check if another plugin's drop-in exists.
		$status = self::get_dropin_status();
		if ( $status['installed'] && ! $status['ours'] ) {
			if ( ! $force ) {
				return array(
					'success' => false,
					'foreign' => $status['other'],
					'message' => sprintf(
						/* translators: %s: plugin name */
						__( 'Another object cache drop-in is already installed (%s). Remove it first, or replace it.', 'flavor-edge-cache' ),
						$status['other']
					),
				);
			}
			// Replacing a foreign drop-in: back it up (behind a PHP guard) first.
			self::backup_foreign_dropin( $dropin );
		}

		$code = self::generate_dropin( $backend );

		// Never write a stub/invalid drop-in: WordPress would call wp_cache_init()
		// on it and fatal on every request (an unrecoverable site-wide WSOD).
		if ( false === strpos( $code, 'function wp_cache_init' ) ) {
			return array(
				'success' => false,
				/* translators: %s: backend name */
				'message' => sprintf( __( 'Object cache backend "%s" is not supported.', 'flavor-edge-cache' ), $backend ),
			);
		}

		if ( ! self::write_atomic( $dropin, $code ) ) {
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
	 * Write a drop-in (a file WordPress includes on every request) atomically.
	 *
	 * WordPress `include`s object-cache.php / advanced-cache.php without any lock,
	 * so writing them in place with file_put_contents lets a concurrent worker read
	 * a half-written file → parse error → site-wide white screen for the write
	 * window. We write to a temp file in the SAME directory and rename() over the
	 * target: rename is atomic on POSIX, so a reader always sees either the old or
	 * the new file whole, never a mix. opcache is invalidated so the new code is
	 * actually picked up. Falls back to a direct write only if tempnam fails.
	 *
	 * @param string $path Target path (same dir must be writable).
	 * @param string $code File contents.
	 * @return bool
	 */
	public static function write_atomic( $path, $code ) {
		$direct = static function () use ( $path, $code ) {
			$ok = ( false !== @file_put_contents( $path, $code, LOCK_EX ) );
			if ( $ok && function_exists( 'opcache_invalidate' ) ) {
				@opcache_invalidate( $path, true );
			}
			return $ok;
		};

		// A symlinked target (some setups symlink wp-config.php) must be written
		// THROUGH the link; a rename would replace the link with a regular file.
		if ( is_link( $path ) ) {
			return $direct();
		}

		// Preserve the target's existing permissions. Forcing 0644 on wp-config.php
		// (often 0640/0600 to hide its secrets) would make it world-readable.
		$perms = file_exists( $path ) ? ( @fileperms( $path ) & 0777 ) : 0644;
		if ( ! $perms ) {
			$perms = 0644;
		}

		$tmp = @tempnam( dirname( $path ), '.fe-tmp-' );
		if ( false === $tmp ) {
			return $direct(); // Could not stage a temp file next to the target.
		}
		// tempnam() silently falls back to the system temp dir when the target dir
		// is not writable; a cross-filesystem rename is not atomic (and fails with
		// EXDEV on Linux), so only use the rename path when the temp is truly beside
		// the target. Otherwise fall back to a direct write.
		if ( dirname( $tmp ) !== dirname( $path ) ) {
			@unlink( $tmp );
			return $direct();
		}
		if ( false === @file_put_contents( $tmp, $code, LOCK_EX ) ) {
			@unlink( $tmp );
			return false;
		}
		@chmod( $tmp, $perms );
		if ( ! @rename( $tmp, $path ) ) {
			@unlink( $tmp );
			return false;
		}
		if ( function_exists( 'opcache_invalidate' ) ) {
			@opcache_invalidate( $path, true );
		}
		return true;
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

	/**
	 * Replace a foreign object-cache.php (e.g. a W3TC leftover) with ours, after
	 * backing the foreign one up. Explicit, user-initiated — never automatic.
	 */
	public static function ajax_replace() {
		check_ajax_referer( 'flavor_edge_admin', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( 'Unauthorized' );
		}
		$backend = sanitize_text_field( wp_unslash( $_POST['backend'] ?? '' ) );
		if ( '' === $backend ) {
			$live    = self::detect_backends();
			$backend = $live['recommended'] ?: '';
		}
		$result = self::install_dropin( $backend, true );
		if ( ! empty( $result['success'] ) ) {
			delete_option( 'flavor_edge_oc_foreign' );
		}
		wp_send_json( $result );
	}

	/**
	 * Back up a foreign object-cache.php into the plugin's cache dir behind a PHP
	 * guard (so a direct web hit reveals nothing) before we overwrite it.
	 *
	 * @param string $dropin Path to the current object-cache.php.
	 */
	private static function backup_foreign_dropin( $dropin ) {
		$dir = WP_CONTENT_DIR . '/cache/flavor-edge';
		if ( ! is_dir( $dir ) ) {
			wp_mkdir_p( $dir );
		}
		$contents = @file_get_contents( $dropin );
		if ( false !== $contents ) {
			@file_put_contents( $dir . '/object-cache-foreign.bak.php', "<?php exit; __halt_compiler();\n" . $contents, LOCK_EX );
		}
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
	private $salt = '';
	public $cache_hits   = 0;
	public $cache_misses = 0;

	public function __construct() {
		$this->blog_prefix   = is_multisite() ? get_current_blog_id() . ':' : '';
		$this->global_prefix = defined( 'WP_REDIS_PREFIX' ) ? WP_REDIS_PREFIX : '';
		$this->salt = 'feoc:' . substr( md5( ( defined( 'WP_CACHE_KEY_SALT' ) ? WP_CACHE_KEY_SALT : '' ) . ( defined( 'DB_NAME' ) ? DB_NAME : '' ) . '|' . ( isset( $GLOBALS['table_prefix'] ) ? $GLOBALS['table_prefix'] : '' ) ), 0, 12 ) . ':';
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
		return $this->salt . $prefix . $group . ':' . $key;
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
		if ( $this->redis ) {
				// Prefix-scoped delete; NEVER flushDb(), which wipes the whole DB
				// (page-cache shield and any co-tenant, production included).
				$it = null;
				do {
					$keys = $this->redis->scan( $it, $this->salt . '*', 500 );
					if ( is_array( $keys ) && $keys ) { $this->redis->del( $keys ); }
				} while ( $it > 0 );
			}
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
	private $salt = '';
	public $cache_hits = 0;
	public $cache_misses = 0;

	public function __construct() {
		$this->prefix = is_multisite() ? get_current_blog_id() . ':' : '';
		// Per-install namespace so two installs on one PHP-FPM pool never collide
		// and our flush stays inside our own keys.
		$this->salt = 'feoc:' . substr( md5(
			( defined( 'WP_CACHE_KEY_SALT' ) ? WP_CACHE_KEY_SALT : '' )
			. ( defined( 'DB_NAME' ) ? DB_NAME : '' ) . '|'
			. ( isset( $GLOBALS['table_prefix'] ) ? $GLOBALS['table_prefix'] : '' )
		), 0, 12 ) . ':';
	}

	private function key( $key, $group ) {
		$p = in_array( $group, $this->global_groups, true ) ? '' : $this->prefix;
		return $this->salt . $p . $group . ':' . $key;
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

	public function flush() {
		$this->cache = array();
		// Delete only OUR keys — never apcu_clear_cache(), which wipes the entire
		// APCu store (every other plugin and install sharing this PHP pool).
		if ( class_exists( 'APCUIterator' ) ) {
			@apcu_delete( new APCUIterator( '/^' . preg_quote( $this->salt, '/' ) . '/' ) );
		}
		return true;
	}
	public function add_global_groups( $groups ) { $this->global_groups = array_unique( array_merge( $this->global_groups, (array) $groups ) ); }
	public function add_non_persistent_groups( $groups ) { $this->non_persistent = array_unique( array_merge( $this->non_persistent, (array) $groups ) ); }
	public function switch_to_blog( $blog_id ) { $this->prefix = is_multisite() ? $blog_id . ':' : ''; }
}
PHP;
	}

	private static function generate_memcached_dropin() {
		return <<<'PHP'
<?php
/**
 * Object Cache Drop-in — Memcached backend.
 * Generated by Flavor Edge Cache plugin.
 *
 * Configuration via wp-config.php constants:
 *   WP_MEMCACHED_HOST (default: 127.0.0.1)
 *   WP_MEMCACHED_PORT (default: 11211)
 */

defined( 'ABSPATH' ) || exit;

function wp_cache_init() { global $wp_object_cache; $wp_object_cache = new WP_Object_Cache_Memcached(); }
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

class WP_Object_Cache_Memcached {
	private $mc;
	private $salt = '';
	private $gen = null;
	private $cache = array();
	private $global_groups = array( 'users', 'userlogins', 'usermeta', 'user_meta', 'site-transient', 'site-options', 'blog-lookup', 'blog-details', 'global-posts', 'networks', 'rss' );
	private $non_persistent = array();
	private $prefix = '';
	public $cache_hits = 0;
	public $cache_misses = 0;

	public function __construct() {
		$this->prefix = is_multisite() ? get_current_blog_id() . ':' : '';
		$this->salt = 'feoc:' . substr( md5( ( defined( 'WP_CACHE_KEY_SALT' ) ? WP_CACHE_KEY_SALT : '' ) . ( defined( 'DB_NAME' ) ? DB_NAME : '' ) . '|' . ( isset( $GLOBALS['table_prefix'] ) ? $GLOBALS['table_prefix'] : '' ) ), 0, 12 ) . ':';
		if ( class_exists( 'Memcached' ) ) {
			try {
				$this->mc = new Memcached();
				$host = defined( 'WP_MEMCACHED_HOST' ) ? WP_MEMCACHED_HOST : '127.0.0.1';
				$port = defined( 'WP_MEMCACHED_PORT' ) ? (int) WP_MEMCACHED_PORT : 11211;
				$this->mc->addServer( $host, $port );
			} catch ( Exception $e ) {
				$this->mc = null;
			}
		}
	}

	private function key( $key, $group ) {
		$p = in_array( $group, $this->global_groups, true ) ? '' : $this->prefix;
		return $this->salt . $this->gen() . ':' . md5( $p . $group . ':' . $key );
	}

	public function get( $key, $group = 'default', $force = false, &$found = null ) {
		$k = $this->key( $key, $group );
		if ( ! $force && isset( $this->cache[ $k ] ) ) { $found = true; $this->cache_hits++; return $this->cache[ $k ]; }
		if ( in_array( $group, $this->non_persistent, true ) || ! $this->mc ) { $found = isset( $this->cache[ $k ] ); $found ? $this->cache_hits++ : $this->cache_misses++; return $found ? $this->cache[ $k ] : false; }
		$val = $this->mc->get( $k );
		$found = ( Memcached::RES_NOTFOUND !== $this->mc->getResultCode() );
		$found ? $this->cache_hits++ : $this->cache_misses++;
		if ( $found ) { $this->cache[ $k ] = $val; }
		return $found ? $val : false;
	}

	private function exp( $expire ) {
		$e = (int) $expire;
		// Memcached treats any expiry > 30 days as an absolute Unix timestamp; WP
		// passes relative seconds, so a long TTL (e.g. a year) would be read as a
		// 1971 timestamp and the item would never persist. Convert to absolute time.
		return ( $e > 2592000 ) ? ( time() + $e ) : $e;
	}

	public function set( $key, $data, $group = 'default', $expire = 0 ) {
		$k = $this->key( $key, $group ); $this->cache[ $k ] = $data;
		if ( in_array( $group, $this->non_persistent, true ) || ! $this->mc ) { return true; }
		return $this->mc->set( $k, $data, $this->exp( $expire ) );
	}

	public function add( $key, $data, $group = 'default', $expire = 0 ) {
		$k = $this->key( $key, $group );
		if ( isset( $this->cache[ $k ] ) ) { return false; }
		if ( $this->mc && ! in_array( $group, $this->non_persistent, true ) ) {
			$ok = $this->mc->add( $k, $data, $this->exp( $expire ) );
			if ( $ok ) { $this->cache[ $k ] = $data; }
			return $ok;
		}
		return $this->set( $key, $data, $group, $expire );
	}

	public function replace( $key, $data, $group = 'default', $expire = 0 ) {
		$k = $this->key( $key, $group );
		if ( $this->mc && ! in_array( $group, $this->non_persistent, true ) ) {
			$ok = $this->mc->replace( $k, $data, $this->exp( $expire ) );
			if ( $ok ) { $this->cache[ $k ] = $data; }
			return $ok;
		}
		return isset( $this->cache[ $k ] ) ? $this->set( $key, $data, $group, $expire ) : false;
	}

	public function delete( $key, $group = 'default' ) {
		$k = $this->key( $key, $group ); unset( $this->cache[ $k ] );
		if ( $this->mc && ! in_array( $group, $this->non_persistent, true ) ) { $this->mc->delete( $k ); }
		return true;
	}

	public function incr( $key, $offset = 1, $group = 'default' ) {
		$k = $this->key( $key, $group );
		if ( $this->mc && ! in_array( $group, $this->non_persistent, true ) ) {
			$val = $this->mc->increment( $k, max( 0, (int) $offset ) );
			if ( false === $val ) {
				// Key missing: the initial-value arg is ignored under the text
				// protocol, so seed and persist it explicitly instead of only
				// keeping it in the per-request array.
				$seed = isset( $this->cache[ $k ] ) ? ( (int) $this->cache[ $k ] + (int) $offset ) : (int) $offset;
				if ( $this->mc->set( $k, $seed ) ) { $val = $seed; }
			}
			if ( false !== $val ) { $this->cache[ $k ] = $val; return $val; }
		}
		$val = isset( $this->cache[ $k ] ) ? (int) $this->cache[ $k ] + (int) $offset : (int) $offset;
		$this->cache[ $k ] = $val; return $val;
	}

	public function decr( $key, $offset = 1, $group = 'default' ) {
		$k = $this->key( $key, $group );
		if ( $this->mc && ! in_array( $group, $this->non_persistent, true ) ) {
			$val = $this->mc->decrement( $k, max( 0, (int) $offset ) );
			if ( false === $val ) {
				$seed = max( 0, ( isset( $this->cache[ $k ] ) ? (int) $this->cache[ $k ] : 0 ) - (int) $offset );
				if ( $this->mc->set( $k, $seed ) ) { $val = $seed; }
			}
			if ( false !== $val ) { $this->cache[ $k ] = $val; return $val; }
		}
		$val = isset( $this->cache[ $k ] ) ? max( 0, (int) $this->cache[ $k ] - (int) $offset ) : 0;
		$this->cache[ $k ] = $val; return $val;
	}

	public function flush() {
		$this->cache = array();
		// Bump OUR generation only. NEVER Memcached::flush(): that is instance-wide
		// and would take the page-cache shield and every other tenant (a production
		// site sharing this memcached included) with it.
		if ( $this->mc ) {
			if ( false === $this->mc->increment( $this->salt . 'gen' ) ) {
				$this->mc->set( $this->salt . 'gen', 2 );
			}
			$this->gen = null;
		}
		return true;
	}

	private function gen() {
		if ( null !== $this->gen ) { return $this->gen; }
		if ( ! $this->mc ) { return $this->gen = 0; }
		$g = $this->mc->get( $this->salt . 'gen' );
		if ( Memcached::RES_NOTFOUND === $this->mc->getResultCode() ) {
			$this->mc->add( $this->salt . 'gen', 1 );
			$g = $this->mc->get( $this->salt . 'gen' );
		}
		return $this->gen = max( 1, (int) $g );
	}
	public function add_global_groups( $groups ) { $this->global_groups = array_unique( array_merge( $this->global_groups, (array) $groups ) ); }
	public function add_non_persistent_groups( $groups ) { $this->non_persistent = array_unique( array_merge( $this->non_persistent, (array) $groups ) ); }
	public function switch_to_blog( $blog_id ) { $this->prefix = is_multisite() ? $blog_id . ':' : ''; }
}
PHP;
	}
}
