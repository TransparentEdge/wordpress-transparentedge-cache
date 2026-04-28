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

		// Check transient first (persists across requests).
		if ( ! $force && null === self::$token ) {
			$cached = get_transient( 'flavor_edge_api_token' );
			if ( $cached ) {
				self::$token = $cached;
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

		// Cache token in transient.
		set_transient( 'flavor_edge_api_token', self::$token, $expires_in - 60 );

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

	/**
	 * Invalidate URLs via purge or soft purge.
	 *
	 * @param array  $urls    Array of full URLs to invalidate.
	 * @param bool   $soft    Use soft purge (serve stale if origin fails).
	 * @param bool   $refetch Warm-up cache after purge.
	 * @return array { success: bool, message: string, urls_sent: int }
	 */
	public static function purge_urls( $urls, $soft = true, $refetch = false ) {
		$token = self::get_token();
		if ( ! $token ) {
			return array( 'success' => false, 'message' => 'No valid API token', 'urls_sent' => 0 );
		}

		$company_id = self::get_credential( 'company_id' );
		if ( empty( $company_id ) ) {
			return array( 'success' => false, 'message' => 'Company ID not configured', 'urls_sent' => 0 );
		}

		// Filter and deduplicate valid URLs.
		$valid_urls = array_unique( array_filter( $urls, function ( $url ) {
			return filter_var( $url, FILTER_VALIDATE_URL );
		} ) );

		if ( empty( $valid_urls ) ) {
			return array( 'success' => false, 'message' => 'No valid URLs to purge', 'urls_sent' => 0 );
		}

		$endpoint = sprintf( '%s/v1/companies/%s/invalidate/', FLAVOR_EDGE_API_BASE, $company_id );

		$payload = array( 'urls' => array_values( $valid_urls ) );

		if ( $soft ) {
			$payload['soft'] = true;
		}
		if ( $refetch ) {
			$payload['refetch'] = true;
		}

		$response = self::api_post( $endpoint, $payload );

		if ( is_wp_error( $response ) ) {
			self::record_failure( 'purge_urls: ' . $response->get_error_message() );
			return array(
				'success'  => false,
				'message'  => $response->get_error_message(),
				'urls_sent' => 0,
			);
		}

		$code = wp_remote_retrieve_response_code( $response );
		$type = $soft ? 'soft_purge' : 'purge';

		if ( 200 !== $code ) {
			self::record_failure( 'purge_urls_http_' . $code );
		} else {
			self::record_success();
		}

		return array(
			'success'   => 200 === $code,
			'message'   => 200 === $code ? 'Purge successful' : 'Purge failed (HTTP ' . $code . ')',
			'urls_sent' => count( $valid_urls ),
		);
	}

	/**
	 * Invalidate by Surrogate-Keys (tags).
	 *
	 * @param array $tags Array of tag strings.
	 * @param bool  $soft Use soft purge.
	 * @return array { success: bool, message: string }
	 */
	public static function purge_tags( $tags, $soft = true ) {
		$token = self::get_token();
		if ( ! $token ) {
			return array( 'success' => false, 'message' => 'No valid API token' );
		}

		$company_id = self::get_credential( 'company_id' );
		if ( empty( $company_id ) ) {
			return array( 'success' => false, 'message' => 'Company ID not configured' );
		}

		$tags = array_unique( array_filter( $tags ) );
		if ( empty( $tags ) ) {
			return array( 'success' => false, 'message' => 'No tags to purge' );
		}

		$endpoint = sprintf( '%s/v1/companies/%s/tag_invalidate/', FLAVOR_EDGE_API_BASE, $company_id );

		$payload = array( 'tags' => array_values( $tags ) );

		if ( $soft ) {
			$payload['soft'] = true;
		}

		$response = self::api_post( $endpoint, $payload );

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
			'message' => 200 === $code ? 'Tag purge successful' : 'Tag purge failed (HTTP ' . $code . ')',
		);
	}

	/**
	 * BAN invalidation (recursive, pattern-based).
	 *
	 * @param string $url_pattern URL pattern to ban (e.g., https://example.com/blog/).
	 * @return array { success: bool, message: string }
	 */
	public static function ban( $url_pattern ) {
		$token = self::get_token();
		if ( ! $token ) {
			return array( 'success' => false, 'message' => 'No valid API token' );
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
	public static function purge_all( $force_ban = false ) {
		if ( $force_ban ) {
			return self::ban( home_url( '/' ) );
		}

		// Soft purge via site-wide tag — safe for high-traffic.
		$site_tag = 'site-' . get_current_blog_id();
		return self::purge_tags( array( $site_tag, 'front-page', 'feed' ), true );
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
	private static function api_post( $endpoint, $payload ) {
		$token = self::get_token();
		if ( ! $token ) {
			return new \WP_Error( 'no_token', 'Could not obtain API token' );
		}

		return wp_remote_post( $endpoint, array(
			'timeout' => 10,
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
