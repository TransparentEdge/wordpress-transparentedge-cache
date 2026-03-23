<?php
/**
 * Sitemap Preload module.
 *
 * Crawls sitemap.xml to warm Varnish cache after a full purge.
 * Uses a background queue with rate limiting to avoid overloading the origin.
 *
 * Architecture:
 * - AJAX start() queues URLs and returns immediately.
 * - Admin JS polls ajax_tick() every 3 seconds.
 * - Each tick processes a small batch (3 URLs) with rate limiting.
 * - WP Cron runs as fallback if admin closes the page.
 * - Can be stopped at any time.
 *
 * @package flavor_edge_cache
 */

namespace flavor_edge;

defined( 'ABSPATH' ) || exit;

class TE_Preload {

	const STATUS_KEY = 'flavor_edge_preload_status';
	const BATCH_SIZE = 3;
	const BATCH_DELAY = 2;
	const MAX_URLS = 500;

	/**
	 * Initialize preload hooks.
	 */
	public static function init() {
		add_action( 'flavor_edge_preload_batch', array( __CLASS__, 'cron_process_batch' ) );
		add_action( 'flavor_edge_after_purge_all', array( __CLASS__, 'on_purge_all' ) );
		add_action( 'wp_ajax_flavor_edge_preload_tick', array( __CLASS__, 'ajax_tick' ) );
	}

	/**
	 * Auto-trigger after full purge if enabled.
	 *
	 * @param array $result Purge result.
	 */
	public static function on_purge_all( $result ) {
		if ( ! TE_Settings::get( 'preload_sitemap' ) ) {
			return;
		}
		if ( isset( $result['success'] ) && $result['success'] ) {
			self::start();
		}
	}

	/**
	 * Queue URLs from sitemap and return immediately.
	 *
	 * @return array
	 */
	public static function start() {
		$urls = self::get_urls_from_sitemap();

		if ( empty( $urls ) ) {
			return array(
				'success' => false,
				'message' => __( 'No URLs found in sitemap.', 'flavor-edge-cache' ),
				'urls'    => 0,
			);
		}

		$status = array(
			'total'      => count( $urls ),
			'remaining'  => $urls,
			'processed'  => 0,
			'errors'     => 0,
			'started'    => time(),
			'last_batch' => 0,
			'status'     => 'running',
		);

		update_option( self::STATUS_KEY, $status, false );

		// Cron fallback in case admin page is closed.
		wp_clear_scheduled_hook( 'flavor_edge_preload_batch' );
		wp_schedule_single_event( time() + self::BATCH_DELAY + 1, 'flavor_edge_preload_batch' );

		return array(
			'success' => true,
			'message' => sprintf(
				/* translators: %d: number of URLs */
				__( 'Preload queued: %d URLs. Warming in background...', 'flavor-edge-cache' ),
				count( $urls )
			),
			'urls' => count( $urls ),
		);
	}

	/**
	 * Stop a running preload.
	 */
	public static function stop() {
		$status = get_option( self::STATUS_KEY, null );
		if ( $status ) {
			$status['status'] = 'stopped';
			update_option( self::STATUS_KEY, $status, false );
		}
		wp_clear_scheduled_hook( 'flavor_edge_preload_batch' );
	}

	/**
	 * AJAX tick — called by admin JS polling every 3s.
	 * Processes one batch if rate limit allows, returns current status.
	 */
	public static function ajax_tick() {
		check_ajax_referer( 'flavor_edge_admin', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( 'Unauthorized' );
		}

		$status = get_option( self::STATUS_KEY, null );

		if ( ! $status || 'running' !== $status['status'] ) {
			wp_send_json_success( $status );
			return;
		}

		// Rate limit: skip if too soon since last batch.
		if ( ( time() - $status['last_batch'] ) < self::BATCH_DELAY ) {
			wp_send_json_success( $status );
			return;
		}

		// Process one batch.
		$status = self::do_batch( $status );
		update_option( self::STATUS_KEY, $status, false );

		// Keep cron scheduled as fallback.
		if ( 'running' === $status['status'] && ! wp_next_scheduled( 'flavor_edge_preload_batch' ) ) {
			wp_schedule_single_event( time() + self::BATCH_DELAY + 1, 'flavor_edge_preload_batch' );
		}

		wp_send_json_success( $status );
	}

	/**
	 * WP Cron fallback — processes one batch per cron tick.
	 */
	public static function cron_process_batch() {
		$status = get_option( self::STATUS_KEY, null );

		if ( ! $status || 'running' !== $status['status'] || empty( $status['remaining'] ) ) {
			return;
		}

		$status = self::do_batch( $status );
		update_option( self::STATUS_KEY, $status, false );

		if ( 'running' === $status['status'] && ! empty( $status['remaining'] ) ) {
			wp_schedule_single_event( time() + self::BATCH_DELAY + 1, 'flavor_edge_preload_batch' );
		}
	}

	/**
	 * Process one batch of URLs (sequential, rate-limited).
	 *
	 * @param array $status Current status.
	 * @return array Updated status.
	 */
	private static function do_batch( $status ) {
		$batch = array_splice( $status['remaining'], 0, self::BATCH_SIZE );

		foreach ( $batch as $url ) {
			$response = wp_remote_get( $url, array(
				'timeout'     => 5,
				'blocking'    => true,
				'user-agent'  => 'Flavor-Edge-Preload/1.0',
				'redirection' => 0,
				'headers'     => array( 'Accept' => 'text/html,image/webp,*/*' ),
			) );

			$status['processed']++;
			if ( is_wp_error( $response ) ) {
				$status['errors']++;
			}
		}

		$status['last_batch'] = time();

		if ( empty( $status['remaining'] ) ) {
			$status['status']   = 'completed';
			$status['finished'] = time();
			$status['elapsed']  = $status['finished'] - $status['started'];
			wp_clear_scheduled_hook( 'flavor_edge_preload_batch' );
		}

		return $status;
	}

	/**
	 * Get preload status.
	 *
	 * @return array|null
	 */
	public static function get_status() {
		return get_option( self::STATUS_KEY, null );
	}

	/**
	 * Extract URLs from the site's sitemap.
	 *
	 * @return array
	 */
	public static function get_urls_from_sitemap() {
		$urls = array();

		$sitemap_urls = array(
			home_url( '/wp-sitemap.xml' ),
			home_url( '/sitemap_index.xml' ),
			home_url( '/sitemap.xml' ),
			home_url( '/?sitemap=1' ),
		);

		$body = '';
		foreach ( $sitemap_urls as $candidate ) {
			$response = wp_remote_get( $candidate, array(
				'timeout'     => 10,
				'user-agent'  => 'Flavor-Edge-Preload/1.0',
				'redirection' => 3,
			) );

			if ( is_wp_error( $response ) ) {
				continue;
			}

			$code      = wp_remote_retrieve_response_code( $response );
			$resp_body = wp_remote_retrieve_body( $response );

			if ( 200 === $code
				|| ( ! empty( $resp_body ) && false !== strpos( $resp_body, '<sitemapindex' ) )
				|| ( ! empty( $resp_body ) && false !== strpos( $resp_body, '<urlset' ) ) ) {
				$body = $resp_body;
				break;
			}
		}

		if ( empty( $body ) ) {
			return $urls;
		}

		if ( false !== strpos( $body, '<sitemapindex' ) ) {
			foreach ( self::extract_locs( $body ) as $sub_url ) {
				$sub = wp_remote_get( $sub_url, array(
					'timeout'    => 10,
					'user-agent' => 'Flavor-Edge-Preload/1.0',
				) );
				if ( ! is_wp_error( $sub ) ) {
					$sub_body = wp_remote_retrieve_body( $sub );
					if ( ! empty( $sub_body ) && false !== strpos( $sub_body, '<urlset' ) ) {
						$urls = array_merge( $urls, self::extract_locs( $sub_body ) );
					}
				}
			}
		} else {
			$urls = self::extract_locs( $body );
		}

		return array_slice( array_unique( $urls ), 0, self::MAX_URLS );
	}

	/**
	 * Extract <loc> values from sitemap XML.
	 *
	 * @param string $xml XML content.
	 * @return array
	 */
	private static function extract_locs( $xml ) {
		$urls = array();
		if ( preg_match_all( '/<loc>\s*(.*?)\s*<\/loc>/i', $xml, $matches ) ) {
			foreach ( $matches[1] as $url ) {
				$url = trim( html_entity_decode( $url ) );
				if ( filter_var( $url, FILTER_VALIDATE_URL ) ) {
					$urls[] = $url;
				}
			}
		}
		return $urls;
	}
}
