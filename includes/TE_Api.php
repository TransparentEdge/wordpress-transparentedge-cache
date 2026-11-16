<?php
/**
 * Transparent Edge API client.
 *
 * Handles OAuth2 authentication, cache invalidation (purge, soft purge, BAN),
 * tag-based invalidation via Surrogate-Keys, and refetch/warm-up.
 *
 * @package flavor_edge_cache
 */

namespace flavor_edge;

defined( 'ABSPATH' ) || exit;

class TE_Api {

	/**
	 * Circuit breaker: max consecutive failures before tripping.
	 */
	const CIRCUIT_MAX_FAILURES = 3;

	/**
	 * Circuit breaker: cooldown period in seconds (5 minutes).
	 */
	const CIRCUIT_COOLDOWN = 300;

	/**
	 * Transient key for circuit breaker state.
	 */
	const CIRCUIT_TRANSIENT = 'flavor_edge_api_circuit';

	/**
	 * Cached OAuth2 token.
	 *
	 * @var string|null
	 */
	private static $token = null;

	/**
	 * Token expiration timestamp.
	 *
	 * @var int
	 */
	private static $token_expires = 0;

	/**
	 * Get an API credential, with multisite network fallback.
	 *
	 * @param string $key One of: company_id, client_id, client_secret.
	 * @return string
	 */
	private static function get_credential( $key ) {
		if ( is_multisite() && class_exists( __NAMESPACE__ . '\\TE_Multisite' ) ) {
			$creds = TE_Multisite::get_effective_credentials();
			return isset( $creds[ $key ] ) ? $creds[ $key ] : '';
		}
		return TE_Settings::get( $key, '' );
	}

	/**
	 * Get a valid OAuth2 access token, requesting a new one if needed.
	 *
	 * @param bool $force Force a new token request.
	 * @return string|false Access token or false on failure.
	 */
	public static function get_token( $force = false ) {
		// Circuit breaker: if tripped, fail fast.
		if ( self::is_circuit_open() ) {
			self::log( 'Circuit breaker OPEN — skipping API call' );
			return false;
		}

		// Check transient first (persists across requests). Store the expiry with the
		// token so a second get_token() this request reuses it instead of re-authing:
		// without token_expires set, the "still valid" check below (time() < 0) fails
		// and every purge/warm mints a redundant token over the network.
		if ( ! $force && null === self::$token ) {
			$cached = get_transient( 'flavor_edge_api_token' );
			if ( is_array( $cached ) && ! empty( $cached['token'] ) ) {
				self::$token         = $cached['token'];
				self::$token_expires = isset( $cached['expires'] ) ? (int) $cached['expires'] : ( time() + 60 );
				return self::$token;
			}
			if ( is_string( $cached ) && '' !== $cached ) {
				// Legacy format (bare token string): adopt with a short conservative
				// validity so this request reuses it rather than re-authing each call.
				self::$token         = $cached;
				self::$token_expires = time() + 60;
				return self::$token;
			}
		}

		// Return cached token if still valid.
		if ( ! $force && self::$token && time() < self::$token_expires ) {
			return self::$token;
		}

		$client_id     = self::get_credential( 'client_id' );
		$client_secret = self::get_credential( 'client_secret' );

		if ( empty( $client_id ) || empty( $client_secret ) ) {
			self::log( 'Auth failed: missing client_id or client_secret' );
			return false;
		}

		$response = wp_remote_post( FLAVOR_EDGE_API_AUTH, array(
			'timeout' => 5,
			'headers' => array(
				'Content-Type' => 'application/x-www-form-urlencoded',
				'Accept'       => 'application/json',
			),
			'body'    => http_build_query( array(
				'grant_type'    => 'client_credentials',
				'client_id'     => $client_id,
				'client_secret' => $client_secret,
			) ),
		) );

		if ( is_wp_error( $response ) ) {
			self::log( 'Auth request failed: ' . $response->get_error_message() );
			self::record_failure( 'auth_error: ' . $response->get_error_message() );
			return false;
		}

		$code = wp_remote_retrieve_response_code( $response );
		$body = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( 200 !== $code || empty( $body['access_token'] ) ) {
			self::log( sprintf( 'Auth failed with code %d: %s', $code, wp_remote_retrieve_body( $response ) ) );
			self::record_failure( 'auth_http_' . $code );
			return false;
		}

		// Auth succeeded — reset circuit breaker.
		self::record_success();

		self::$token         = $body['access_token'];
		$expires_in          = isset( $body['expires_in'] ) ? (int) $body['expires_in'] : 3600;
		self::$token_expires = time() + $expires_in - 60; // 60s safety margin.

		// Cache token + its absolute expiry in the transient (see the load path above).
		set_transient(
			'flavor_edge_api_token',
			array( 'token' => self::$token, 'expires' => self::$token_expires ),
			max( 1, $expires_in - 60 )
		);

		return self::$token;
	}

	/**
	 * Test API connection.
	 *
	 * @param string $company_id    Company ID.
	 * @param string $client_id     Client ID.
	 * @param string $client_secret Client Secret.
	 * @return array { success: bool, message: string }
	 */
	public static function test_connection( $company_id, $client_id, $client_secret ) {
		$response = wp_remote_post( FLAVOR_EDGE_API_AUTH, array(
			'timeout' => 5,
			'headers' => array(
				'Content-Type' => 'application/x-www-form-urlencoded',
				'Accept'       => 'application/json',
			),
			'body'    => http_build_query( array(
				'grant_type'    => 'client_credentials',
				'client_id'     => $client_id,
				'client_secret' => $client_secret,
			) ),
		) );

		if ( is_wp_error( $response ) ) {
			return array(
				'success' => false,
				'message' => $response->get_error_message(),
			);
		}

		$code = wp_remote_retrieve_response_code( $response );

		if ( 200 !== $code ) {
			return array(
				'success' => false,
				'message' => sprintf(
					/* translators: %d: HTTP status code */
					__( 'Authentication failed (HTTP %d). Check your Client ID and Client Secret.', 'flavor-edge-cache' ),
					$code
				),
			);
		}

		return array(
			'success' => true,
			'message' => __( 'Connection successful! Your credentials are valid.', 'flavor-edge-cache' ),
		);
	}

	// -------------------------------------------------------------------------
	// Purge log: what was purged, why, by whom, with what result.
	// -------------------------------------------------------------------------

	/** Option holding the recent purge log (newest first, not autoloaded). */
	const PURGE_LOG_OPTION = 'flavor_edge_purge_log';

	/** Label of what triggered the purges that follow (set by the caller). */
	private static $log_context = '';

	/** Entries buffered during this request; persisted once, at shutdown. */
	private static $log_buffer = array();

	/**
	 * Label the purges that follow with what triggered them (e.g. "post #123
	 * published", "menu updated", "admin bar: purge all"). Shown in the History tab.
	 *
	 * @param string $label Context label.
	 */
	public static function set_context( $label ) {
		self::$log_context = (string) $label;
	}

	/**
	 * Purge a list of URLs (logged). See purge_urls_raw() for the API call.
	 */
	public static function purge_urls( $urls, $soft = true, $refetch = false, $async = false ) {
		$result = self::purge_urls_raw( $urls, $soft, $refetch, $async );
		self::log_purge( 'urls', (array) $urls, $soft, $refetch, $result );
		return $result;
	}

	/**
	 * Purge by surrogate-key tags (logged). See purge_tags_raw() for the API call.
	 */
	public static function purge_tags( $tags, $soft = true, $async = false ) {
		$result = self::purge_tags_raw( $tags, $soft, $async );
		self::log_purge( 'tags', (array) $tags, $soft, false, $result );
		return $result;
	}

	/**
	 * Ban (hard invalidation by URL pattern) (logged). See ban_raw().
	 */
	public static function ban( $url_pattern ) {
		$result = self::ban_raw( $url_pattern );
		self::log_purge( 'ban', array( (string) $url_pattern ), false, false, $result );
		return $result;
	}

		/**
	 * Translate a result message stored by a version that kept them in English
	 * (before 1.14.0 the API messages were not translatable). Newer entries are
	 * already in the site's language and pass through unchanged.
	 *
	 * @param string $msg Stored message.
	 * @return string
	 */
	public static function message_label( $msg ) {
		$msg   = (string) $msg;
		$fixed = array(
			'Purge successful'          => __( 'Purge successful', 'flavor-edge-cache' ),
			'Tag purge successful'      => __( 'Tag purge successful', 'flavor-edge-cache' ),
			'Prefetch queued'           => __( 'Prefetch queued', 'flavor-edge-cache' ),
			'No valid API token'        => __( 'No valid API token', 'flavor-edge-cache' ),
			'Company ID not configured' => __( 'Company ID not configured', 'flavor-edge-cache' ),
			'No valid URLs to purge'    => __( 'No valid URLs to purge', 'flavor-edge-cache' ),
			'No valid URLs to prefetch' => __( 'No valid URLs to prefetch', 'flavor-edge-cache' ),
			'No tags to purge'          => __( 'No tags to purge', 'flavor-edge-cache' ),
		);
		if ( isset( $fixed[ $msg ] ) ) {
			return $fixed[ $msg ];
		}
		if ( preg_match( '/^(Purge|Tag purge|Prefetch) failed \(HTTP (\d+)\)$/', $msg, $m ) ) {
			$fmt = array(
				/* translators: %d: HTTP status code */
				'Purge'     => __( 'Purge failed (HTTP %d)', 'flavor-edge-cache' ),
				/* translators: %d: HTTP status code */
				'Tag purge' => __( 'Tag purge failed (HTTP %d)', 'flavor-edge-cache' ),
				/* translators: %d: HTTP status code */
				'Prefetch'  => __( 'Prefetch failed (HTTP %d)', 'flavor-edge-cache' ),
			);
			return sprintf( $fmt[ $m[1] ], (int) $m[2] );
		}
		return $msg;
	}

	/**
	 * Human label, in the site's language, for a stored purge-log context. The
	 * context is stored in English (stable data, also printed by WP-CLI) and
	 * translated only for display.
	 *
	 * @param string $ctx Stored context.
	 * @return string
	 */
	public static function context_label( $ctx ) {
		$ctx   = (string) $ctx;
		$fixed = array(
			'auto'                               => __( 'auto', 'flavor-edge-cache' ),
			'request'                            => __( 'request', 'flavor-edge-cache' ),
			'cron'                               => __( 'cron', 'flavor-edge-cache' ),
			'wp-cli'                             => 'WP-CLI',
			'preload'                            => __( 'preload', 'flavor-edge-cache' ),
			'dashboard: purge all'               => __( 'dashboard: purge all', 'flavor-edge-cache' ),
			'admin bar: purge all'               => __( 'admin bar: purge all', 'flavor-edge-cache' ),
			'theme/plugin update'                => __( 'theme/plugin update', 'flavor-edge-cache' ),
			'speculation rules changed'          => __( 'speculation rules changed', 'flavor-edge-cache' ),
			'upgrade to 1.13.0: new cache keys'  => __( 'upgrade to 1.13.0: new cache keys', 'flavor-edge-cache' ),
			'widgets updated'                    => __( 'widgets updated', 'flavor-edge-cache' ),
			'theme switched'                     => __( 'theme switched', 'flavor-edge-cache' ),
		);
		if ( isset( $fixed[ $ctx ] ) ) {
			return $fixed[ $ctx ];
		}
		$out = $ctx;
		if ( 0 === strpos( $out, 'auto: ' ) ) {
			$out = $fixed['auto'] . ': ' . substr( $out, 6 );
		}
		// One pattern per event the invalidation module notes (see TE_Invalidation::note()).
		$rules = array(
			/* translators: %d: post ID */
			'/^admin bar: purge page #(\d+)$/'          => __( 'admin bar: purge page #%1$s', 'flavor-edge-cache' ),
			/* translators: 1: post type, 2: post ID */
			'/(\S+) #(\d+) published\/updated/'         => __( '%1$s #%2$s published/updated', 'flavor-edge-cache' ),
			/* translators: 1: post type, 2: post ID */
			'/(\S+) #(\d+) unpublished\/deleted/'       => __( '%1$s #%2$s unpublished/deleted', 'flavor-edge-cache' ),
			/* translators: %s: comment ID */
			'/comment #(\d+) edited/'                   => __( 'comment #%1$s edited', 'flavor-edge-cache' ),
			/* translators: 1: comment ID, 2: new comment status */
			'/comment #(\d+) (approved|unapproved|spam|trash|hold|approve)\b/' => __( 'comment #%1$s %2$s', 'flavor-edge-cache' ),
			/* translators: %s: comment ID */
			'/comment #(\d+)(?=;|$)/'                   => __( 'comment #%1$s', 'flavor-edge-cache' ),
			/* translators: %s: menu ID */
			'/menu #(\d+) updated/'                     => __( 'menu #%1$s updated', 'flavor-edge-cache' ),
			/* translators: %s: plugin file */
			'/plugin activated: (\S+)/'                 => __( 'plugin activated: %1$s', 'flavor-edge-cache' ),
			/* translators: %s: plugin file */
			'/plugin deactivated: (\S+)/'               => __( 'plugin deactivated: %1$s', 'flavor-edge-cache' ),
			/* translators: 1: taxonomy, 2: term ID */
			'/(\S+) #(\d+) edited/'                     => __( '%1$s #%2$s edited', 'flavor-edge-cache' ),
			/* translators: 1: taxonomy, 2: term ID */
			'/(\S+) #(\d+) deleted/'                    => __( '%1$s #%2$s deleted', 'flavor-edge-cache' ),
			/* translators: %s: product ID */
			'/stock change #(\d+)/'                     => __( 'stock change #%1$s', 'flavor-edge-cache' ),
			/* translators: %s: attachment ID */
			'/media #(\d+)/'                            => __( 'media #%1$s', 'flavor-edge-cache' ),
			/* translators: %s: product ID */
			'/product #(\d+)/'                          => __( 'product #%1$s', 'flavor-edge-cache' ),
			/* translators: %s: number of further events */
			'/\+(\d+) more/'                            => __( '+%1$s more', 'flavor-edge-cache' ),
		);
		foreach ( $rules as $re => $fmt ) {
			$out = preg_replace_callback(
				$re,
				function ( $m ) use ( $fmt ) {
					return vsprintf( $fmt, array_slice( $m, 1 ) );
				},
				$out
			);
		}
		return $out;
	}

/**
	 * Most recent purge-log entries, newest first.
	 *
	 * @param int $limit Max entries.
	 * @return array[] { t, ctx, kind, n, items, soft, refetch, ok, msg, user }
	 */
	public static function get_purge_log( $limit = 100 ) {
		$log = get_option( self::PURGE_LOG_OPTION, array() );
		return is_array( $log ) ? array_slice( $log, 0, max( 1, (int) $limit ) ) : array();
	}

	/**
	 * Buffer one purge-log entry. Bulk warming (context "preload") is logged only
	 * when it fails, so a full-site preload does not push editorial purges out of
	 * the log. Disable entirely with the flavor_edge_purge_log_enabled filter.
	 */
	private static function log_purge( $kind, array $items, $soft, $refetch, $result ) {
		if ( ! function_exists( 'apply_filters' ) || ! apply_filters( 'flavor_edge_purge_log_enabled', true ) ) {
			return;
		}
		$ctx = self::$log_context;
		if ( '' === $ctx ) {
			if ( defined( 'WP_CLI' ) && WP_CLI ) {
				$ctx = 'wp-cli';
			} elseif ( function_exists( 'wp_doing_cron' ) && wp_doing_cron() ) {
				$ctx = 'cron';
			} else {
				$ctx = 'request';
			}
		}
		$ok = is_array( $result ) && ! empty( $result['success'] );
		if ( 'preload' === $ctx && $ok ) {
			return;
		}
		$user = function_exists( 'wp_get_current_user' ) ? wp_get_current_user() : null;

		self::$log_buffer[] = array(
			't'       => time(),
			'ctx'     => substr( $ctx, 0, 200 ),
			'kind'    => $kind,
			'n'       => count( $items ),
			'items'   => array_slice( array_values( array_map( 'strval', $items ) ), 0, 20 ),
			'soft'    => (bool) $soft,
			'refetch' => (bool) $refetch,
			'ok'      => $ok,
			'msg'     => ( is_array( $result ) && isset( $result['message'] ) ) ? substr( (string) $result['message'], 0, 200 ) : '',
			'user'    => ( $user && $user->exists() ) ? $user->user_login : '',
		);

		// One option write per request, after every purge of the request (the
		// invalidation queue itself flushes at shutdown priority 1).
		static $hooked = false;
		if ( ! $hooked && function_exists( 'add_action' ) ) {
			$hooked = true;
			add_action( 'shutdown', array( __CLASS__, 'persist_purge_log' ), 99 );
		}
	}

	/**
	 * Persist the buffered entries (newest first, capped). Concurrent requests may
	 * occasionally lose an entry to a read-modify-write race; acceptable for a
	 * diagnostic log, and it avoids a custom table.
	 */
	public static function persist_purge_log() {
		if ( empty( self::$log_buffer ) ) {
			return;
		}
		$max = max( 10, (int) apply_filters( 'flavor_edge_purge_log_size', 100 ) );
		$log = get_option( self::PURGE_LOG_OPTION, array() );
		if ( ! is_array( $log ) ) {
			$log = array();
		}
		$log              = array_slice( array_merge( array_reverse( self::$log_buffer ), $log ), 0, $max );
		self::$log_buffer = array();
		update_option( self::PURGE_LOG_OPTION, $log, false );
	}


	/**
	 * Invalidate URLs via purge or soft purge.
	 *
	 * @param array  $urls    Array of full URLs to invalidate.
	 * @param bool   $soft    Use soft purge (serve stale if origin fails).
	 * @param bool   $refetch Warm-up cache after purge.
	 * @return array { success: bool, message: string, urls_sent: int }
	 */
	private static function purge_urls_raw( $urls, $soft = true, $refetch = false, $async = false ) {
		$token = self::get_token();
		if ( ! $token ) {
			return array( 'success' => false, 'message' => __( 'No valid API token', 'flavor-edge-cache' ), 'urls_sent' => 0 );
		}

		$company_id = self::get_credential( 'company_id' );
		if ( empty( $company_id ) ) {
			return array( 'success' => false, 'message' => __( 'Company ID not configured', 'flavor-edge-cache' ), 'urls_sent' => 0 );
		}

		// Filter and deduplicate valid URLs.
		$valid_urls = array_unique( array_filter( $urls, function ( $url ) {
			return filter_var( $url, FILTER_VALIDATE_URL );
		} ) );

		if ( empty( $valid_urls ) ) {
			return array( 'success' => false, 'message' => __( 'No valid URLs to purge', 'flavor-edge-cache' ), 'urls_sent' => 0 );
		}

		$endpoint = sprintf( '%s/v1/companies/%s/invalidate/', FLAVOR_EDGE_API_BASE, $company_id );

		$payload = array( 'urls' => array_values( $valid_urls ) );

		if ( $soft ) {
			$payload['soft'] = true;
		}
		if ( $refetch ) {
			$payload['refetch'] = true;
		}

		$response = self::api_post( $endpoint, $payload, $async );

		if ( is_wp_error( $response ) ) {
			self::record_failure( 'purge_urls: ' . $response->get_error_message() );
			return array(
				'success'  => false,
				'message'  => $response->get_error_message(),
				'urls_sent' => 0,
			);
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		// 206 = partial (some URLs invalid, Warning: 199) — still a success for
		// the ones that went through, and must not trip the circuit breaker.
		$ok          = ( 200 === $code || 206 === $code );
		$retry_after = self::parse_retry_after( $response );

		if ( $ok ) {
			self::record_success();
		} else {
			self::record_failure( 'purge_urls_http_' . $code );
		}

		return array(
			'success'     => $ok,
			/* translators: %d: HTTP status code */
			'message'     => $ok ? __( 'Purge successful', 'flavor-edge-cache' ) : sprintf( __( 'Purge failed (HTTP %d)', 'flavor-edge-cache' ), $code ),
			'urls_sent'   => count( $valid_urls ),
			'status'      => $code,
			'retry_after' => $retry_after,
		);
	}

	/**
	 * Parse a Retry-After response header. RFC 7231 allows either delta-seconds
	 * ("120") or an HTTP-date ("Wed, 21 Oct 2026 07:28:00 GMT"); a bare (int) cast
	 * silently turns the date form into 0, so the pacer would ignore the server's
	 * requested wait. Returns seconds-from-now (never negative).
	 *
	 * @param array|\WP_Error $response wp_remote_* response.
	 * @return int
	 */
	private static function parse_retry_after( $response ) {
		$raw = trim( (string) wp_remote_retrieve_header( $response, 'retry-after' ) );
		if ( '' === $raw ) {
			return 0;
		}
		if ( ctype_digit( $raw ) ) {
			return (int) $raw;
		}
		$ts = strtotime( $raw );
		if ( false === $ts ) {
			return 0;
		}
		return max( 0, $ts - time() );
	}

	/**
	 * Prefetch (warm) URLs across the whole edge via the platform CacheWarmUp
	 * service: POST /v1/companies/{id}/prefetch/ with { urls: [...] }.
	 *
	 * NOTE on origin load: prefetch fans out roughly one origin fetch per edge
	 * node per URL (measured ~1/node, no refetcher aggregation). It warms every
	 * node, but the caller MUST throttle it (small batches, pause, health gate)
	 * so a bulk warm — e.g. a whole sitemap — never stampedes a weak origin.
	 * For re-warming content that was just invalidated, prefer purge_urls(...,
	 * refetch:true), whose refetchers aggregate (origin sees ~1-2 total).
	 *
	 * @param array $urls Array of full URLs to prefetch (keep the batch small).
	 * @return array { success: bool, message: string, status: int, urls_sent: int, retry_after: int }
	 */
	public static function prefetch( $urls ) {
		$token = self::get_token();
		if ( ! $token ) {
			return array( 'success' => false, 'message' => __( 'No valid API token', 'flavor-edge-cache' ), 'status' => 0, 'urls_sent' => 0, 'retry_after' => 0 );
		}

		$company_id = self::get_credential( 'company_id' );
		if ( empty( $company_id ) ) {
			return array( 'success' => false, 'message' => __( 'Company ID not configured', 'flavor-edge-cache' ), 'status' => 0, 'urls_sent' => 0, 'retry_after' => 0 );
		}

		$valid_urls = array_values( array_unique( array_filter( (array) $urls, function ( $url ) {
			return filter_var( $url, FILTER_VALIDATE_URL );
		} ) ) );
		if ( empty( $valid_urls ) ) {
			return array( 'success' => false, 'message' => __( 'No valid URLs to prefetch', 'flavor-edge-cache' ), 'status' => 0, 'urls_sent' => 0, 'retry_after' => 0 );
		}

		$endpoint = sprintf( '%s/v1/companies/%s/prefetch/', FLAVOR_EDGE_API_BASE, $company_id );
		$response = self::api_post( $endpoint, array( 'urls' => $valid_urls ) );

		if ( is_wp_error( $response ) ) {
			self::record_failure( 'prefetch: ' . $response->get_error_message() );
			return array( 'success' => false, 'message' => $response->get_error_message(), 'status' => 0, 'urls_sent' => 0, 'retry_after' => 0 );
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		// 429/503 → the platform is rate-limiting us; surface Retry-After so the
		// caller can back off instead of hammering.
		$retry_after = self::parse_retry_after( $response );
		if ( 200 === $code || 206 === $code ) {
			self::record_success();
		} else {
			self::record_failure( 'prefetch_http_' . $code );
		}

		return array(
			'success'     => ( 200 === $code || 206 === $code ),
			/* translators: %d: HTTP status code */
			'message'     => ( 200 === $code || 206 === $code ) ? __( 'Prefetch queued', 'flavor-edge-cache' ) : sprintf( __( 'Prefetch failed (HTTP %d)', 'flavor-edge-cache' ), $code ),
			'status'      => $code,
			'urls_sent'   => count( $valid_urls ),
			'retry_after' => $retry_after,
		);
	}

	/**
	 * Invalidate by Surrogate-Keys (tags).
	 *
	 * @param array $tags Array of tag strings.
	 * @param bool  $soft Use soft purge.
	 * @return array { success: bool, message: string }
	 */
	private static function purge_tags_raw( $tags, $soft = true, $async = false ) {
		$token = self::get_token();
		if ( ! $token ) {
			return array( 'success' => false, 'message' => __( 'No valid API token', 'flavor-edge-cache' ) );
		}

		$company_id = self::get_credential( 'company_id' );
		if ( empty( $company_id ) ) {
			return array( 'success' => false, 'message' => __( 'Company ID not configured', 'flavor-edge-cache' ) );
		}

		$tags = array_unique( array_filter( $tags ) );
		if ( empty( $tags ) ) {
			return array( 'success' => false, 'message' => __( 'No tags to purge', 'flavor-edge-cache' ) );
		}

		$endpoint = sprintf( '%s/v1/companies/%s/tag_invalidate/', FLAVOR_EDGE_API_BASE, $company_id );

		$payload = array( 'tags' => array_values( $tags ) );

		if ( $soft ) {
			$payload['soft'] = true;
		}

		$response = self::api_post( $endpoint, $payload, $async );

		if ( is_wp_error( $response ) ) {
			self::record_failure( 'purge_tags: ' . $response->get_error_message() );
			return array( 'success' => false, 'message' => $response->get_error_message() );
		}

		$code = wp_remote_retrieve_response_code( $response );

		if ( 200 !== $code ) {
			self::record_failure( 'purge_tags_http_' . $code );
		} else {
			self::record_success();
		}

		return array(
			'success' => 200 === $code,
			/* translators: %d: HTTP status code */
			'message' => 200 === $code ? __( 'Tag purge successful', 'flavor-edge-cache' ) : sprintf( __( 'Tag purge failed (HTTP %d)', 'flavor-edge-cache' ), $code ),
		);
	}

	/**
	 * BAN invalidation (recursive, pattern-based).
	 *
	 * @param string $url_pattern URL pattern to ban (e.g., https://example.com/blog/).
	 * @return array { success: bool, message: string }
	 */
	private static function ban_raw( $url_pattern ) {
		$token = self::get_token();
		if ( ! $token ) {
			return array( 'success' => false, 'message' => __( 'No valid API token', 'flavor-edge-cache' ) );
		}

		$company_id = self::get_credential( 'company_id' );
		$endpoint   = sprintf( '%s/v1/companies/%s/invalidate/', FLAVOR_EDGE_API_BASE, $company_id );

		$response = self::api_post( $endpoint, array(
			'urls' => array( $url_pattern ),
			'type' => 'ban',
		) );

		if ( is_wp_error( $response ) ) {
			self::record_failure( 'ban: ' . $response->get_error_message() );
			return array( 'success' => false, 'message' => $response->get_error_message() );
		}

		$code = wp_remote_retrieve_response_code( $response );

		if ( 200 !== $code ) {
			self::record_failure( 'ban_http_' . $code );
		} else {
			self::record_success();
		}

		return array(
			'success' => 200 === $code,
			'message' => 200 === $code ? 'BAN successful' : 'BAN failed (HTTP ' . $code . ')',
		);
	}

	/**
	 * Purge entire site using soft tag invalidation.
	 *
	 * Uses the site-wide Surrogate-Key tag instead of BAN to avoid
	 * thundering herd on the origin. Varnish serves stale content
	 * while revalidating in background.
	 *
	 * @param bool $force_ban Force a hard BAN (dangerous in high-traffic — use only in emergencies).
	 * @return array
	 */
	public static function purge_all( $force_ban = false, $async = false ) {
		if ( $force_ban ) {
			return self::ban( home_url( '/' ) );
		}

		// Soft purge via site-wide tag — safe for high-traffic.
		$site_tag = 'site-' . get_current_blog_id();
		return self::purge_tags( array( $site_tag, 'front-page', 'feed' ), true, $async );
	}

	// -------------------------------------------------------------------------
	// Internal helpers.
	// -------------------------------------------------------------------------

	/**
	 * Authenticated POST request to the TE API.
	 *
	 * @param string $endpoint Full URL.
	 * @param array  $payload  Data to send as JSON.
	 * @return array|\WP_Error
	 */
	private static function api_post( $endpoint, $payload, $async = false ) {
		$token = self::get_token();
		if ( ! $token ) {
			return new \WP_Error( 'no_token', 'Could not obtain API token' );
		}

		// Async (fire-at-shutdown) calls use a short timeout so an editorial save
		// never holds a PHP-FPM worker for the full HTTPS round-trip: a purge not
		// acknowledged in a few seconds is given up on (the circuit breaker and the
		// health-check cron still catch real API outages).
		$async_timeout = (int) apply_filters( 'flavor_edge_async_api_timeout', 4 );

		return wp_remote_post( $endpoint, array(
			'timeout' => $async ? $async_timeout : 10,
			'headers' => array(
				'Authorization' => 'Bearer ' . $token,
				'Content-Type'  => 'application/json',
				'Accept'        => 'application/json',
			),
			'body'    => wp_json_encode( $payload ),
		) );
	}

	/**
	 * Check if the circuit breaker is open (API considered unavailable).
	 *
	 * @return bool True if API calls should be skipped.
	 */
	public static function is_circuit_open() {
		$state = get_transient( self::CIRCUIT_TRANSIENT );
		if ( ! is_array( $state ) ) {
			return false;
		}
		return ( $state['failures'] >= self::CIRCUIT_MAX_FAILURES );
	}

	/**
	 * Record an API failure. Opens the circuit after MAX_FAILURES consecutive failures.
	 *
	 * @param string $reason Short description of the failure.
	 */
	private static function record_failure( $reason = '' ) {
		$state = get_transient( self::CIRCUIT_TRANSIENT );
		if ( ! is_array( $state ) ) {
			$state = array( 'failures' => 0, 'last_failure' => 0, 'reason' => '' );
		}

		$state['failures']++;
		$state['last_failure'] = time();
		$state['reason']       = $reason;

		// Store with cooldown TTL so the circuit auto-closes after COOLDOWN seconds.
		set_transient( self::CIRCUIT_TRANSIENT, $state, self::CIRCUIT_COOLDOWN );

		if ( $state['failures'] >= self::CIRCUIT_MAX_FAILURES ) {
			self::log( sprintf(
				'Circuit breaker OPENED after %d failures (reason: %s). API calls disabled for %d seconds.',
				$state['failures'],
				$reason,
				self::CIRCUIT_COOLDOWN
			) );
			// Clear the cached token so we re-authenticate when the circuit closes.
			delete_transient( 'flavor_edge_api_token' );
			self::$token = null;
		}
	}

	/**
	 * Record an API success. Resets the circuit breaker.
	 */
	private static function record_success() {
		delete_transient( self::CIRCUIT_TRANSIENT );
	}

	/**
	 * Get the current circuit breaker state (for admin display).
	 *
	 * @return array { open: bool, failures: int, last_failure: int, reason: string, cooldown_remaining: int }
	 */
	public static function get_circuit_status() {
		$state = get_transient( self::CIRCUIT_TRANSIENT );
		if ( ! is_array( $state ) ) {
			return array(
				'open'               => false,
				'failures'           => 0,
				'last_failure'       => 0,
				'reason'             => '',
				'cooldown_remaining' => 0,
			);
		}

		$open      = $state['failures'] >= self::CIRCUIT_MAX_FAILURES;
		$remaining = $open ? max( 0, self::CIRCUIT_COOLDOWN - ( time() - $state['last_failure'] ) ) : 0;

		return array(
			'open'               => $open,
			'failures'           => $state['failures'],
			'last_failure'       => $state['last_failure'],
			'reason'             => $state['reason'] ?? '',
			'cooldown_remaining' => $remaining,
		);
	}

	/**
	 * Manually reset the circuit breaker (e.g., from admin panel).
	 */
	public static function reset_circuit() {
		delete_transient( self::CIRCUIT_TRANSIENT );
		delete_transient( 'flavor_edge_api_token' );
		self::$token = null;
		self::log( 'Circuit breaker manually reset' );
	}

	// -------------------------------------------------------------------------
	// Periodic health check.
	// -------------------------------------------------------------------------

	/**
	 * Health check transient key.
	 */
	const HEALTH_TRANSIENT = 'flavor_edge_api_health';

	/**
	 * Consecutive health check failures before marking disconnected.
	 * At 30-minute intervals, 3 failures = 1.5 hours of downtime.
	 */
	const HEALTH_MAX_FAILURES = 3;

	/**
	 * Cron hook name.
	 */
	const HEALTH_CRON_HOOK = 'flavor_edge_health_check';

	/**
	 * Register the health check cron schedule.
	 * Called from the main plugin file on plugins_loaded.
	 */
	public static function schedule_health_check() {
		if ( ! TE_Settings::is_connected() ) {
			return;
		}

		if ( ! wp_next_scheduled( self::HEALTH_CRON_HOOK ) ) {
			wp_schedule_event( time() + 300, 'flavor_edge_30min', self::HEALTH_CRON_HOOK );
		}
	}

	/**
	 * Unschedule the health check cron.
	 */
	public static function unschedule_health_check() {
		wp_clear_scheduled_hook( self::HEALTH_CRON_HOOK );
	}

	/**
	 * Execute the periodic health check.
	 * Tries to obtain a fresh token. Tracks consecutive failures.
	 * After HEALTH_MAX_FAILURES, sets connected=false.
	 * On success, restores connected=true if it was degraded.
	 */
	public static function run_health_check() {
		$settings = TE_Settings::get_all();

		// Skip if credentials are empty.
		if ( empty( $settings['client_id'] ) || empty( $settings['client_secret'] ) ) {
			self::log( 'Health check: skipped — no credentials configured' );
			return;
		}

		// Temporarily reset circuit breaker so the health check can actually reach the API.
		$circuit_was_open = self::is_circuit_open();
		if ( $circuit_was_open ) {
			delete_transient( self::CIRCUIT_TRANSIENT );
		}

		// Force a fresh token request.
		delete_transient( 'flavor_edge_api_token' );
		self::$token = null;
		$token = self::get_token( true );

		$health = get_transient( self::HEALTH_TRANSIENT );
		if ( ! is_array( $health ) ) {
			$health = array( 'consecutive_failures' => 0, 'last_check' => 0, 'last_success' => 0, 'last_error' => '' );
		}

		$health['last_check'] = time();

		if ( false !== $token ) {
			// Success.
			$health['consecutive_failures'] = 0;
			$health['last_success']         = time();
			$health['last_error']           = '';

			// Restore connected flag if it was degraded.
			if ( ! $settings['connected'] ) {
				$settings['connected'] = true;
				TE_Settings::save( $settings );
				self::log( 'Health check: API reachable — connection restored automatically' );
			} else {
				self::log( 'Health check: OK' );
			}

			// Reset circuit breaker since API is confirmed working.
			delete_transient( self::CIRCUIT_TRANSIENT );

		} else {
			// Failure.
			$health['consecutive_failures']++;
			$health['last_error'] = self::get_circuit_status()['reason'] ?: 'Unknown error';

			self::log( sprintf(
				'Health check: FAILED (%d/%d) — %s',
				$health['consecutive_failures'],
				self::HEALTH_MAX_FAILURES,
				$health['last_error']
			) );

			// After N consecutive failures, mark as disconnected.
			if ( $health['consecutive_failures'] >= self::HEALTH_MAX_FAILURES && $settings['connected'] ) {
				$settings['connected'] = false;
				TE_Settings::save( $settings );
				self::log( sprintf(
					'Health check: %d consecutive failures — marking as DISCONNECTED. Local optimizations continue.',
					$health['consecutive_failures']
				) );
			}

			// Re-open circuit breaker if it was open before (health check shouldn't keep it closed).
			if ( $circuit_was_open ) {
				$state = array(
					'failures'     => self::CIRCUIT_MAX_FAILURES,
					'last_failure' => time(),
					'reason'       => 'health_check_failed',
				);
				set_transient( self::CIRCUIT_TRANSIENT, $state, self::CIRCUIT_COOLDOWN );
			}
		}

		// Store health state (TTL = 2 hours, enough to span multiple checks).
		set_transient( self::HEALTH_TRANSIENT, $health, 7200 );
	}

	/**
	 * Get current health check status (for admin display).
	 *
	 * @return array
	 */
	public static function get_health_status() {
		$health = get_transient( self::HEALTH_TRANSIENT );
		if ( ! is_array( $health ) ) {
			return array(
				'consecutive_failures' => 0,
				'last_check'           => 0,
				'last_success'         => 0,
				'last_error'           => '',
				'healthy'              => true,
			);
		}

		$health['healthy'] = $health['consecutive_failures'] < self::HEALTH_MAX_FAILURES;
		return $health;
	}

	/**
	 * Debug log.
	 *
	 * @param string $message Message to log.
	 */
	private static function log( $message ) {
		if ( TE_Settings::get( 'debug_mode' ) || ( defined( 'WP_DEBUG' ) && WP_DEBUG ) ) {
			error_log( '[Flavor Edge Cache] ' . $message ); // phpcs:ignore
		}
	}
}
