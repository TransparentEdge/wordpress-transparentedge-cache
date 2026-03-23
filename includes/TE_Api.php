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
			'timeout' => 15,
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
			return false;
		}

		$code = wp_remote_retrieve_response_code( $response );
		$body = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( 200 !== $code || empty( $body['access_token'] ) ) {
			self::log( sprintf( 'Auth failed with code %d: %s', $code, wp_remote_retrieve_body( $response ) ) );
			return false;
		}

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
			'timeout' => 15,
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
			return array(
				'success'  => false,
				'message'  => $response->get_error_message(),
				'urls_sent' => 0,
			);
		}

		$code = wp_remote_retrieve_response_code( $response );
		$type = $soft ? 'soft_purge' : 'purge';


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
			return array( 'success' => false, 'message' => $response->get_error_message() );
		}

		$code = wp_remote_retrieve_response_code( $response );


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
			return array( 'success' => false, 'message' => $response->get_error_message() );
		}

		$code = wp_remote_retrieve_response_code( $response );


		return array(
			'success' => 200 === $code,
			'message' => 200 === $code ? 'BAN successful' : 'BAN failed (HTTP ' . $code . ')',
		);
	}

	/**
	 * Purge entire site (BAN on root).
	 *
	 * @return array
	 */
	public static function purge_all() {
		return self::ban( home_url( '/' ) );
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
			'timeout' => 30,
			'headers' => array(
				'Authorization' => 'Bearer ' . $token,
				'Content-Type'  => 'application/json',
				'Accept'        => 'application/json',
			),
			'body'    => wp_json_encode( $payload ),
		) );
	}

	/**
	 * Log a purge event to the database.
	 *
	 * @param string      $purge_type    Type (purge, soft_purge, ban, tag_purge).
	 * @param string      $method        Method (url, tag, pattern).
	 * @param string      $target        Target URLs or pattern.
	 * @param string|null $tags          Tags (if tag-based).
	 * @param string      $status        Status (success, error, pending).
	 * @param int|null    $response_code HTTP response code.
	 * @param string      $trigger       What triggered this (save_post, manual, etc.).
	 */
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
