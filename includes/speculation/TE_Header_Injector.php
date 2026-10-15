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

		$mode = $settings['speculation_injection'] ?? 'inline';

		if ( 'vcl' === $mode ) {
			// Varnish adds the Speculation-Rules header at the edge.
			return;
		}

		if ( 'inline' === $mode ) {
			// Inline the rules in <head>: they ride inside the page HTML that the
			// edge already caches, so there is no separate /wp-json/ request to
			// serve on a cache miss — nothing extra reaches the origin.
			add_action( 'wp_head', array( __CLASS__, 'inject_inline' ), 99 );
			return;
		}

		// 'php' (legacy): emit a Speculation-Rules header pointing at the REST
		// endpoint. The browser fetches that URL on every navigation, so it MUST
		// be cached at the edge or it hits the origin on every miss. Prefer inline.
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

	/**
	 * Inject the speculation rules inline in <head>.
	 *
	 * Preferred mode. The rules travel inside the page HTML that the edge
	 * already caches, so there is no separate /wp-json/ request to serve on a
	 * cache miss — the origin is never hit just to hand out the rules.
	 */
	public static function inject_inline() {
		if ( is_admin() || is_feed() ) {
			return;
		}

		/**
		 * Filter whether Speculation Rules should be injected.
		 *
		 * @param bool $enabled Whether to inject.
		 */
		if ( ! apply_filters( 'flavor_edge_speculation_enabled', true ) ) {
			return;
		}

		$rules = TE_Rules_Generator::generate();
		if ( empty( $rules ) ) {
			return;
		}

		// wp_json_encode escapes forward slashes by default, so a path pattern
		// can never close the <script> element early.
		echo "\n<script type=\"speculationrules\">" . wp_json_encode( $rules ) . "</script>\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	}
}
