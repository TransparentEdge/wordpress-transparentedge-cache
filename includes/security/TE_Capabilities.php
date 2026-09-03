<?php
/**
 * Detects which Transparent Edge services a company has contracted.
 *
 * Used to gate security features: rate limiting, bot mitigation, and
 * automated anomaly response require the corresponding contracted service.
 *
 * @package flavor_edge_cache
 */

namespace flavor_edge;

defined( 'ABSPATH' ) || exit;

class TE_Capabilities {

	/**
	 * Service IDs on the Transparent Edge platform.
	 */
	const SERVICE_WAF            = 4;
	const SERVICE_BOTMITIGATION  = 27;
	const SERVICE_PERIMETRICAL   = 36;
	const SERVICE_ANTIDDOS       = 40;

	/**
	 * Transient key for cached services.
	 */
	const CACHE_KEY = 'flavor_edge_company_services';

	/**
	 * Cache TTL (1 hour).
	 */
	const CACHE_TTL = 3600;

	/**
	 * Get the set of contracted service IDs for the configured company.
	 *
	 * @param bool $force_refresh Bypass cache.
	 * @return array Array of integer service IDs. Empty on failure.
	 */
	public static function get_services( $force_refresh = false ) {
		if ( ! $force_refresh ) {
			$cached = get_transient( self::CACHE_KEY );
			if ( is_array( $cached ) ) {
				return $cached;
			}
		}

		$services = self::fetch_services();

		// Cache even an empty result to avoid hammering the API when unconfigured,
		// but use a shorter TTL for empty results so recovery is quick.
		$ttl = empty( $services ) ? 300 : self::CACHE_TTL;
		set_transient( self::CACHE_KEY, $services, $ttl );

		return $services;
	}

	/**
	 * Fetch services from the Transparent Edge API.
	 *
	 * @return array Array of integer service IDs.
	 */
	private static function fetch_services() {
		$company_id = (int) TE_Settings::get( 'company_id' );
		if ( ! $company_id ) {
			return array();
		}

		$token = TE_Api::get_token();
		if ( ! $token ) {
			return array();
		}

		$response = wp_remote_get(
			FLAVOR_EDGE_API_BASE . '/v1/companies/services/',
			array(
				'timeout' => 5,
				'headers' => array(
					'Authorization' => 'Bearer ' . $token,
					'Accept'        => 'application/json',
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			return array();
		}

		if ( 200 !== wp_remote_retrieve_response_code( $response ) ) {
			return array();
		}

		$data = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( ! is_array( $data ) ) {
			return array();
		}

		// The endpoint returns a list of services, each with a company_list.
		// A service is contracted if our company_id appears in its company_list.
		$ids = array();
		foreach ( $data as $service ) {
			if ( ! is_array( $service ) ) {
				continue;
			}
			$company_list = isset( $service['company_list'] ) && is_array( $service['company_list'] )
				? $service['company_list']
				: array();
			if ( in_array( $company_id, array_map( 'intval', $company_list ), true ) ) {
				if ( isset( $service['id'] ) ) {
					$ids[] = (int) $service['id'];
				}
			}
		}

		return $ids;
	}

	/**
	 * Whether the company has a given service.
	 *
	 * @param int $service_id Service ID constant.
	 * @return bool
	 */
	public static function has_service( $service_id ) {
		return in_array( (int) $service_id, self::get_services(), true );
	}

	/**
	 * Whether the company has WAF (required for rate limiting).
	 *
	 * @return bool
	 */
	public static function has_waf() {
		return self::has_service( self::SERVICE_WAF );
	}

	/**
	 * Whether the company has Bot Mitigation.
	 *
	 * @return bool
	 */
	public static function has_bot_mitigation() {
		return self::has_service( self::SERVICE_BOTMITIGATION );
	}

	/**
	 * Whether the company has the full Perimetrical suite.
	 *
	 * @return bool
	 */
	public static function has_perimetrical() {
		return self::has_service( self::SERVICE_PERIMETRICAL );
	}

	/**
	 * Whether the company has any active security service that enables
	 * active-protection features (rate limit, bot mitigation, anomaly response).
	 *
	 * @return bool
	 */
	public static function has_security_suite() {
		$services = self::get_services();
		foreach ( array( self::SERVICE_WAF, self::SERVICE_BOTMITIGATION, self::SERVICE_PERIMETRICAL ) as $needed ) {
			if ( in_array( $needed, $services, true ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Clear the cached services (e.g., after reconfiguring credentials).
	 */
	public static function clear_cache() {
		delete_transient( self::CACHE_KEY );
	}

	/**
	 * Human-readable list of contracted security services (for admin display).
	 *
	 * @return array label => bool
	 */
	public static function get_security_status() {
		return array(
			'WAF'            => self::has_waf(),
			'Bot Mitigation' => self::has_bot_mitigation(),
			'Perimetrical'   => self::has_perimetrical(),
			'Anti-DDoS'      => self::has_service( self::SERVICE_ANTIDDOS ),
		);
	}
}
