<?php
/**
 * Injects the Speculation-Rules HTTP header from PHP (origin fallback).
 *
 * When VCL injection is not available or not configured,
 * this module adds the header directly from WordPress.
 *
 * @package flavor_edge_cache
 */

namespace flavor_edge;

defined( 'ABSPATH' ) || exit;

class TE_Header_Injector {

	/**
	 * Initialize the header injector.
	 */
	public static function init() {
		$settings = TE_Settings::get_all();

		if ( empty( $settings['speculation_enabled'] ) ) {
			return;
		}

		// Only inject via PHP if VCL injection is not selected.
		if ( 'vcl' === ( $settings['speculation_injection'] ?? 'php' ) ) {
			return;
		}

		add_action( 'send_headers', array( __CLASS__, 'inject_header' ) );
	}

	/**
	 * Add the Speculation-Rules header to HTML responses.
	 */
	public static function inject_header() {
		// Skip admin, AJAX, cron, REST API, and non-HTML contexts.
		if ( is_admin() || wp_doing_ajax() || wp_doing_cron() ) {
			return;
		}

		// Skip REST requests (the JSON endpoint itself should not have this header).
		if ( defined( 'REST_REQUEST' ) && REST_REQUEST ) {
			return;
		}

		// Skip feed requests.
		if ( is_feed() ) {
			return;
		}

		if ( headers_sent() ) {
			return;
		}

		/**
		 * Filter whether Speculation Rules header should be injected.
		 *
		 * @param bool $enabled Whether to inject.
		 */
		if ( ! apply_filters( 'flavor_edge_speculation_enabled', true ) ) {
			return;
		}

		$url = rest_url( 'te-cache/v1/speculation-rules' );
		header( sprintf( 'Speculation-Rules: "%s"', esc_url_raw( $url ) ), false );
	}
}
