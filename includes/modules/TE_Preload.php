<?php
/**
 * Sitemap Preload module.
 *
 * Crawls the sitemap to warm the cache after a full purge, warming each batch
 * with a single aggregated platform call (refetch by default) so the whole
 * edge re-warms without a per-node stampede on the origin.
 *
 * Architecture:
 * - AJAX start() queues URLs and returns immediately.
 * - Admin JS polls ajax_tick() every 3 seconds.
 * - Each tick processes a small batch via one platform warm call.
 * - Origin protection: off-peak window (optional), live origin-health gate,
 *   and exponential backoff (honouring Retry-After) on platform rate limits.
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
			// Unattended: triggered by the system after a full purge with nobody
			// watching. The origin-health gate is forced on for this path so a
			// scheduled/automatic bulk warm can never topple a weak origin.
			self::start( true );
		}
	}

	/**
	 * Queue URLs from sitemap and return immediately.
	 *
	 * @param bool $unattended True when started automatically (no admin
	 *                         watching) — forces the origin-health gate on.
	 * @return array
	 */
	public static function start( $unattended = false ) {
		$urls = self::get_urls_from_sitemap();

		if ( empty( $urls ) ) {
			return array(
				'success' => false,
				'message' => __( 'No URLs found in sitemap.', 'flavor-edge-cache' ),
				'urls'    => 0,
			);
		}

		$status = array(
			'total'       => count( $urls ),
			'remaining'   => $urls,
			'processed'   => 0,
			'errors'      => 0,
			'started'     => time(),
			'last_batch'  => 0,
			'status'      => 'running',
			'backoff'     => 0,
			'retry_after' => 0,
			'waiting'     => '',
			'unattended'  => (bool) $unattended,
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
	 * Persist batch progress without clobbering a stop/complete that landed
	 * concurrently. The AJAX tick writes the status at the end of each batch; if
	 * the user hits Stop while a tick is in flight, that tick must not overwrite
	 * the 'stopped' state with a stale 'running' (which previously left an
	 * orphaned preload — status running but no scheduled event).
	 *
	 * @param array $status In-memory status after a batch.
	 * @return array The status actually in effect.
	 */
	private static function persist_progress( $status ) {
		$current = get_option( self::STATUS_KEY, null );
		if ( is_array( $current ) && isset( $current['status'] )
			&& in_array( $current['status'], array( 'stopped', 'completed' ), true )
			&& 'running' === ( isset( $status['status'] ) ? $status['status'] : '' ) ) {
			// A stop/complete won the race — honour it, drop this batch's write.
			return $current;
		}
		update_option( self::STATUS_KEY, $status, false );
		return $status;
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

		// Rate limit / backoff: skip if too soon since last batch.
		if ( ( time() - $status['last_batch'] ) < self::next_delay( $status ) ) {
			wp_send_json_success( $status );
			return;
		}

		// Process one batch.
		$status = self::do_batch( $status );
		$status = self::persist_progress( $status );

		// Keep cron scheduled as fallback.
		if ( 'running' === $status['status'] && ! wp_next_scheduled( 'flavor_edge_preload_batch' ) ) {
			wp_schedule_single_event( time() + self::next_delay( $status ) + 1, 'flavor_edge_preload_batch' );
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
		$status = self::persist_progress( $status );

		if ( 'running' === $status['status'] && ! empty( $status['remaining'] ) ) {
			wp_schedule_single_event( time() + self::next_delay( $status ) + 1, 'flavor_edge_preload_batch' );
		}
	}

	/**
	 * Process one batch of URLs.
	 *
	 * The batch is warmed with a SINGLE platform call (not one origin GET per
	 * URL): by default an aggregated refetch, so every edge node re-warms while
	 * the refetchers collapse the origin fetches (origin sees ~1-2 per URL
	 * instead of one per node). Before each batch we gate on an off-peak window
	 * (optional) and on live origin health, and we back off on platform rate
	 * limits — so a bulk warm never stampedes a weak origin.
	 *
	 * @param array $status Current status.
	 * @return array Updated status.
	 */
	private static function do_batch( $status ) {
		// Off-peak gate: outside the configured window, wait without sending.
		if ( ! self::within_offpeak_window() ) {
			$status['last_batch'] = time();
			$status['waiting']    = 'offpeak';
			return $status;
		}

		// Origin-health gate: don't add warm load to a struggling origin. Applies
		// to both the manual and the automatic path (both run through here); for
		// an unattended run it is forced on even if the toggle is off, so a
		// scheduled warm can never topple the origin with nobody watching.
		$health_gate = TE_Settings::get( 'preload_health_gate' ) || ! empty( $status['unattended'] );
		if ( $health_gate && ! self::origin_is_healthy() ) {
			$status['last_batch'] = time();
			$status['backoff']    = min( (int) ( isset( $status['backoff'] ) ? $status['backoff'] : 0 ) + 1, 6 );
			$status['waiting']    = 'origin_unhealthy';
			return $status;
		}

		$batch = array_splice( $status['remaining'], 0, self::BATCH_SIZE );

		// One platform call warms the whole batch across all nodes.
		$method = TE_Settings::get( 'preload_method' );
		if ( 'prefetch' === $method ) {
			$result = TE_Api::prefetch( $batch );
		} else {
			$result = TE_Api::purge_urls( $batch, true, true ); // soft purge + aggregated refetch.
		}

		$status['processed'] += count( $batch );
		$status['last_batch']  = time();
		$status['waiting']     = '';

		if ( empty( $result['success'] ) ) {
			$status['errors'] += count( $batch );
			$code = (int) ( isset( $result['status'] ) ? $result['status'] : 0 );
			// Rate limited / platform busy → requeue the batch and back off.
			if ( 429 === $code || 503 === $code ) {
				$status['backoff']     = min( (int) ( isset( $status['backoff'] ) ? $status['backoff'] : 0 ) + 1, 6 );
				$status['retry_after'] = (int) ( isset( $result['retry_after'] ) ? $result['retry_after'] : 0 );
				$status['remaining']   = array_merge( $batch, $status['remaining'] );
			}
		} else {
			$status['backoff']     = 0;
			$status['retry_after'] = 0;
		}

		if ( empty( $status['remaining'] ) ) {
			$status['status']   = 'completed';
			$status['finished'] = time();
			$status['elapsed']  = $status['finished'] - $status['started'];
			wp_clear_scheduled_hook( 'flavor_edge_preload_batch' );
		}

		return $status;
	}

	/**
	 * Delay before the next batch, honouring backoff and gate waits.
	 *
	 * @param array $status Current status.
	 * @return int Seconds.
	 */
	private static function next_delay( $status ) {
		// While gated (off-peak / unhealthy origin) re-check at a slow cadence.
		if ( ! empty( $status['waiting'] ) ) {
			return (int) apply_filters( 'flavor_edge_preload_wait_delay', 120 );
		}
		$backoff = (int) ( isset( $status['backoff'] ) ? $status['backoff'] : 0 );
		if ( $backoff > 0 ) {
			$ra  = (int) ( isset( $status['retry_after'] ) ? $status['retry_after'] : 0 );
			$exp = self::BATCH_DELAY * (int) pow( 2, $backoff );
			return (int) min( max( $exp, $ra ), 300 ); // cap at 5 min.
		}
		return self::BATCH_DELAY;
	}

	/**
	 * Is the current time inside the configured off-peak window?
	 * Returns true (no restriction) when off-peak mode is disabled.
	 *
	 * @return bool
	 */
	private static function within_offpeak_window() {
		if ( ! TE_Settings::get( 'preload_offpeak' ) ) {
			return true;
		}
		$start = (int) TE_Settings::get( 'preload_offpeak_start' );
		$end   = (int) TE_Settings::get( 'preload_offpeak_end' );
		if ( $start === $end ) {
			return true; // empty/degenerate window → no restriction.
		}
		$hour = (int) current_time( 'G' ); // site timezone, 0-23.
		if ( $start < $end ) {
			return ( $hour >= $start && $hour < $end );
		}
		// Window wraps midnight (e.g. 22 → 6).
		return ( $hour >= $start || $hour < $end );
	}

	/**
	 * Probe live origin health before piling warm work on it.
	 *
	 * Best-effort: hits our own host with a unique-query, no-cache request so it
	 * reaches the origin (not an edge HIT), and measures status and latency.
	 * Unhealthy = 5xx / unreachable, or slower than the threshold.
	 *
	 * Caveat: a site whose VCL normalises away unknown query strings would turn
	 * this into an edge HIT (measuring the edge, not the origin). For those
	 * sites, point the probe at a guaranteed-pass URL with the
	 * flavor_edge_preload_health_url filter. The probe is an extra guard on top
	 * of the real origin protection (refetch aggregation + paced batches +
	 * backoff on rate limits), which does not depend on it.
	 *
	 * @return bool
	 */
	private static function origin_is_healthy() {
		$probe_url = apply_filters(
			'flavor_edge_preload_health_url',
			add_query_arg( 'flavor_edge_health', (string) time(), home_url( '/' ) )
		);
		$timeout   = (int) apply_filters( 'flavor_edge_preload_health_timeout', 4 );

		$start = microtime( true );
		$resp  = wp_remote_get( $probe_url, array(
			'timeout'     => $timeout,
			'blocking'    => true,
			'redirection' => 0,
			'user-agent'  => 'WordPress/' . get_bloginfo( 'version' ) . '; ' . home_url( '/' ),
			'headers'     => array(
				'Accept'        => 'text/html,*/*',
				'Cache-Control' => 'no-cache', // bypass the edge so we measure the origin.
			),
		) );

		if ( is_wp_error( $resp ) ) {
			return false;
		}

		$elapsed     = microtime( true ) - $start;
		$code        = (int) wp_remote_retrieve_response_code( $resp );
		$max_latency = (float) apply_filters( 'flavor_edge_preload_health_max_latency', 2.5 );

		if ( 0 === $code || $code >= 500 ) {
			return false;
		}
		if ( $elapsed > $max_latency ) {
			return false;
		}
		return true;
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
				'user-agent'  => 'WordPress/' . get_bloginfo( 'version' ) . '; ' . home_url( '/' ),
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
					'user-agent' => 'WordPress/' . get_bloginfo( 'version' ) . '; ' . home_url( '/' ),
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
	 * Only allows URLs from the same host as the site (SSRF protection).
	 *
	 * @param string $xml XML content.
	 * @return array
	 */
	private static function extract_locs( $xml ) {
		$urls      = array();
		$own_host  = wp_parse_url( home_url(), PHP_URL_HOST );

		if ( preg_match_all( '/<loc>\s*(.*?)\s*<\/loc>/i', $xml, $matches ) ) {
			foreach ( $matches[1] as $url ) {
				$url = trim( html_entity_decode( $url ) );
				if ( ! filter_var( $url, FILTER_VALIDATE_URL ) ) {
					continue;
				}

				// SSRF protection: only allow URLs from our own host.
				$url_host = wp_parse_url( $url, PHP_URL_HOST );
				if ( $url_host !== $own_host ) {
					continue;
				}

				// Block internal/private IP ranges.
				$ip = gethostbyname( $url_host );
				if ( $ip !== $url_host && self::is_private_ip( $ip ) ) {
					continue;
				}

				$urls[] = $url;
			}
		}
		return $urls;
	}

	/**
	 * Check if an IP address is in a private/reserved range.
	 *
	 * @param string $ip IP address.
	 * @return bool
	 */
	private static function is_private_ip( $ip ) {
		return ! filter_var(
			$ip,
			FILTER_VALIDATE_IP,
			FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
		);
	}
}
