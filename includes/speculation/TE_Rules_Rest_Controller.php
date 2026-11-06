<?php
/**
 * REST controller for the Speculation Rules JSON endpoint.
 *
 * Serves the generated rules at /wp-json/te-cache/v1/speculation-rules
 * with proper content-type, caching headers, and rate limiting.
 *
 * @package flavor_edge_cache
 */

namespace flavor_edge;

defined( 'ABSPATH' ) || exit;

class TE_Rules_Rest_Controller {

	/**
	 * Transient key for cached rules JSON.
	 */
	const CACHE_KEY = 'flavor_edge_speculation_json';

	/**
	 * Transient TTL — matches s-maxage sent to edge.
	 */
	const CACHE_TTL = 3600;

	/**
	 * Rate limit transient prefix.
	 */
	const RATE_PREFIX = 'flavor_edge_spec_rate_';

	/**
	 * Max requests per minute per IP to the endpoint.
	 */
	const RATE_LIMIT = 10;

	/**
	 * Register the REST route.
	 */
	public static function register() {
		register_rest_route( 'te-cache/v1', '/speculation-rules', array(
			'methods'             => \WP_REST_Server::READABLE,
			'callback'            => array( __CLASS__, 'serve_rules' ),
			'permission_callback' => '__return_true',
		) );
	}

	/**
	 * Serve the speculation rules JSON.
	 *
	 * @param \WP_REST_Request $request REST request.
	 * @return \WP_REST_Response
	 */
	public static function serve_rules( $request ) {
		// Rate limiting (by IP, per minute).
		$ip       = self::get_client_ip();
		$rate_key = self::RATE_PREFIX . md5( $ip );
		$hits     = (int) get_transient( $rate_key );

		if ( $hits >= self::RATE_LIMIT ) {
			return new \WP_REST_Response(
				array( 'error' => 'Rate limit exceeded' ),
				429
			);
		}

		set_transient( $rate_key, $hits + 1, 60 );

		// Try cached version first.
		$rules = get_transient( self::CACHE_KEY );

		if ( false === $rules ) {
			// Generate fresh rules and cache.
			$rules = TE_Rules_Generator::generate();
			set_transient( self::CACHE_KEY, $rules, self::CACHE_TTL );
		}

		$response = new \WP_REST_Response( $rules );
		$response->header( 'Content-Type', 'application/speculationrules+json' );
		$response->header( 'Cache-Control', 'public, max-age=3600, s-maxage=3600' );
		$response->header( 'Surrogate-Keys', 'speculation-rules-' . get_current_blog_id() );
		$response->header( 'Access-Control-Allow-Origin', '*' );

		return $response;
	}

	/**
	 * Invalidate the cached rules (called when configuration changes).
	 */
	public static function invalidate_cache() {
		delete_transient( self::CACHE_KEY );
	}

	/**
	 * Build the per-caller identity used for rate limiting.
	 *
	 * Combines the socket IP (REMOTE_ADDR, which cannot be spoofed) with the
	 * forwarded client IP. Behind the edge, REMOTE_ADDR is the edge node and the
	 * forwarded header carries the real client, so per-client buckets are preserved;
	 * on a direct origin hit, including the unspoofable socket IP stops a caller from
	 * rotating buckets purely by forging X-Forwarded-For. (The real backstop is that
	 * the rules themselves are cached in a transient, so generate() runs at most once
	 * per CACHE_TTL regardless of request volume — this limit is only a courtesy.)
	 *
	 * @return string
	 */
	private static function get_client_ip() {
		$remote = ! empty( $_SERVER['REMOTE_ADDR'] )
			? trim( sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) )
			: '0.0.0.0';

		$forwarded = '';
		foreach ( array( 'HTTP_X_FORWARDED_FOR', 'HTTP_X_REAL_IP' ) as $header ) {
			if ( ! empty( $_SERVER[ $header ] ) ) {
				$ips       = explode( ',', sanitize_text_field( wp_unslash( $_SERVER[ $header ] ) ) );
				$forwarded = trim( $ips[0] );
				break;
			}
		}

		return $remote . '|' . $forwarded;
	}
}
