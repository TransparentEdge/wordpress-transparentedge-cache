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
		add_action( 'rest_api_init', array( __CLASS__, 'register_health_route' ) );
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
			'batch_size'  => self::BATCH_SIZE, // adaptive pacer ramps this up/down.
			'lat_base'    => 0.0,              // origin baseline latency the pacer tunes against.
			'write_base'  => 0.0,              // page-cache write baseline the pacer also tunes against.
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

		$autotune = (bool) TE_Settings::get( 'preload_autotune' );

		// Probe the origin once per batch. The health gate uses it as a hard
		// pause; the adaptive pacer uses its latency as the "speed up / slow
		// down" signal. Probe when the gate is active OR auto-tune needs it.
		$gate_on = TE_Settings::get( 'preload_health_gate' ) || ! empty( $status['unattended'] );
		$probe   = ( $gate_on || $autotune ) ? self::probe_origin() : array( 'ok' => true, 'latency' => 0.0 );

		// Origin-health GATE (guardrail): hard-pause a struggling origin. Forced
		// on for unattended runs even if the toggle is off.
		if ( $gate_on && empty( $probe['ok'] ) ) {
			$status['last_batch'] = time();
			$status['backoff']    = min( (int) ( isset( $status['backoff'] ) ? $status['backoff'] : 0 ) + 1, 6 );
			$status['waiting']    = 'origin_unhealthy';
			if ( $autotune ) {
				$status['batch_size'] = 1;
			}
			return $status;
		}

		// Disk GATE (guardrail): warming a URL also WRITES its page-cache entry,
		// so a bulk warm is disk I/O on the origin, not just HTTP. A near-full
		// disk must pause the warm. Only the disk backend reports a ratio.
		$disk      = TE_PageCache::disk_pressure();
		$disk_floor = (float) apply_filters( 'flavor_edge_preload_disk_floor', 0.10 );
		if ( null !== $disk['free_ratio'] && $disk['free_ratio'] < $disk_floor ) {
			$status['last_batch'] = time();
			$status['backoff']    = min( (int) ( isset( $status['backoff'] ) ? $status['backoff'] : 0 ) + 1, 6 );
			$status['waiting']    = 'disk_low';
			if ( $autotune ) {
				$status['batch_size'] = 1;
			}
			return $status;
		}

		// Adaptive batch size, always clamped by the guardrails above.
		$bs = $autotune
			? max( 1, (int) ( isset( $status['batch_size'] ) ? $status['batch_size'] : self::BATCH_SIZE ) )
			: self::BATCH_SIZE;

		$batch = array_splice( $status['remaining'], 0, $bs );

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
				if ( $autotune ) {
					$status['batch_size'] = max( 1, (int) floor( $bs / 2 ) ); // multiplicative decrease.
				}
			}
		} else {
			$status['backoff']     = 0;
			$status['retry_after'] = 0;
			if ( $autotune ) {
				// Two degradation signals, each against its own best-case baseline:
				// origin regen latency AND page-cache write latency (the warm writes
				// every entry to the cache disk/backend, so that cost is real too).
				$origin_lat = isset( $probe['latency'] ) ? (float) $probe['latency'] : 0.0;
				$write_lat  = TE_PageCache::write_latency_ms();

				$origin_base = self::track_baseline( isset( $status['lat_base'] ) ? (float) $status['lat_base'] : 0.0, $origin_lat );
				$write_base  = self::track_baseline( isset( $status['write_base'] ) ? (float) $status['write_base'] : 0.0, $write_lat );

				$status['batch_size'] = self::tune_batch_size(
					$bs,
					self::degrade_ratio( $origin_lat, $origin_base ),
					self::degrade_ratio( $write_lat, $write_base ),
					$disk
				);
				$status['lat_base']   = $origin_base;
				$status['write_base'] = $write_base;
			}
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
	 * Register the cheap origin-health endpoint the adaptive pacer probes.
	 * Served at /wp-json/te-cache/v1/health.
	 */
	public static function register_health_route() {
		register_rest_route( 'te-cache/v1', '/health', array(
			'methods'             => 'GET',
			'permission_callback' => '__return_true',
			'callback'            => array( __CLASS__, 'health_endpoint' ),
		) );
	}

	/**
	 * Cheap origin-health endpoint: a trivial DB round-trip + WordPress bootstrap,
	 * NOT a page render. The pacer probes this instead of the home page so that
	 * sensing the origin never regenerates a widget-heavy page (the costliest
	 * thing on a news/e-commerce origin) and tips a fragile origin over.
	 *
	 * @return \WP_REST_Response
	 */
	public static function health_endpoint() {
		global $wpdb;
		$t0    = microtime( true );
		$db_ok = ( '1' === (string) $wpdb->get_var( 'SELECT 1' ) );
		$db_ms = ( microtime( true ) - $t0 ) * 1000.0;

		$resp = new \WP_REST_Response( array(
			'ok'    => $db_ok,
			'ts'    => time(),
			'db_ms' => round( $db_ms, 2 ),
		) );
		$resp->header( 'Cache-Control', 'no-store, private' );
		return $resp;
	}

	/**
	 * Probe live origin health before piling warm work on it.
	 *
	 * Best-effort: hits our own cheap health endpoint with a unique-query,
	 * no-cache request so it reaches the origin (not an edge HIT), and measures
	 * status and latency. Unhealthy = 5xx / unreachable, or slower than the gate.
	 *
	 * Caveat: a site whose VCL normalises away unknown query strings would turn
	 * this into an edge HIT (measuring the edge, not the origin). For those
	 * sites, point the probe at a guaranteed-pass URL with the
	 * flavor_edge_preload_health_url filter. The probe is an extra guard on top
	 * of the real origin protection (refetch aggregation + paced batches +
	 * backoff on rate limits), which does not depend on it.
	 *
	 * @return array { ok: bool, latency: float } Health verdict and probe latency.
	 */
	private static function probe_origin() {
		// Probe the cheap health endpoint, NOT the home page: on a news/e-commerce
		// origin the home regenerates many widgets (very expensive), so sensing it
		// on every batch would hammer the exact origin the warm-up protects.
		$probe_url = apply_filters(
			'flavor_edge_preload_health_url',
			add_query_arg( 'te', (string) microtime( true ), rest_url( 'te-cache/v1/health' ) )
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
			return array( 'ok' => false, 'latency' => (float) $timeout );
		}

		$elapsed     = microtime( true ) - $start;
		$code        = (int) wp_remote_retrieve_response_code( $resp );
		$max_latency = (float) apply_filters( 'flavor_edge_preload_health_max_latency', 2.5 );
		$ok          = ! ( 0 === $code || $code >= 500 || $elapsed > $max_latency );

		return array( 'ok' => $ok, 'latency' => $elapsed );
	}

	/**
	 * AIMD tuning after a successful batch — RELATIVE to the origin's own
	 * baseline latency, not an absolute threshold (a healthy WordPress origin
	 * regenerates a page in ~1s, so an absolute "fast" bar would never ramp).
	 *
	 * We track the origin's best-case latency ($base) and compare each probe to
	 * it: while the origin is not slowing down (within up_tolerance of its
	 * baseline) and disk has headroom, grow the batch additively; when it starts
	 * to degrade (beyond down_tolerance) or disk tightens, cut it in half. This
	 * finds the origin's real capacity by itself, whatever its absolute speed.
	 * The hard guardrails (health gate at max_latency, rate-limit backoff, disk
	 * floor) still sit underneath.
	 *
	 * Two signals, not one: the origin's response latency (how hard regenerating
	 * HTML is hitting it) AND the page-cache write latency (how hard the warm is
	 * hitting the cache disk/backend — we write every warmed entry to it). Each
	 * is judged against its OWN best-case baseline, and the worse of the two
	 * drives the decision — ramp only while BOTH are near their baseline, cut if
	 * EITHER degrades. So on a widget-heavy news home the origin stays the
	 * limiter, and on a cheap-origin / slow-disk box the disk does.
	 *
	 * @param int   $bs           Current batch size.
	 * @param float $origin_ratio Origin latency as a multiple of its baseline (1.0 = at baseline).
	 * @param float $write_ratio  Page-cache write latency as a multiple of its baseline.
	 * @param array $disk         { free_ratio, ... } from TE_PageCache::disk_pressure().
	 * @return int New batch size.
	 */
	private static function tune_batch_size( $bs, $origin_ratio, $write_ratio, $disk ) {
		$max        = (int) apply_filters( 'flavor_edge_preload_batch_max', 20 );
		$up_tol     = (float) apply_filters( 'flavor_edge_preload_up_tolerance', 1.3 );
		$down_tol   = (float) apply_filters( 'flavor_edge_preload_down_tolerance', 1.8 );
		$disk_green = (float) apply_filters( 'flavor_edge_preload_disk_green', 0.20 );

		$disk_ok  = ( null === $disk['free_ratio'] ) || ( $disk['free_ratio'] >= $disk_green );
		$pressure = max( (float) $origin_ratio, (float) $write_ratio ); // worst of the two.

		if ( $pressure <= $up_tol && $disk_ok ) {
			return min( $max, $bs + 1 );             // both near baseline → additive increase.
		}
		if ( $pressure >= $down_tol || ! $disk_ok ) {
			return max( 1, (int) floor( $bs / 2 ) );  // either degrading → multiplicative decrease.
		}
		return $bs;                                   // middle band → hold.
	}

	/**
	 * Track a signal's best-case baseline: adopt a new lower floor at once, let
	 * it drift up only very slowly (2%) so a transient spike reads as degradation
	 * instead of moving the goalposts. A non-positive reading leaves it unchanged.
	 *
	 * @param float $base Current baseline.
	 * @param float $val  Latest reading.
	 * @return float Updated baseline.
	 */
	private static function track_baseline( $base, $val ) {
		if ( $val <= 0 ) {
			return $base;
		}
		if ( $base <= 0 || $val < $base ) {
			return $val;
		}
		return $base + 0.02 * ( $val - $base );
	}

	/**
	 * A reading as a multiple of its baseline (1.0 when either is unknown, so a
	 * missing signal never forces a decision on its own).
	 *
	 * @param float $val  Latest reading.
	 * @param float $base Baseline.
	 * @return float
	 */
	private static function degrade_ratio( $val, $base ) {
		return ( $val > 0 && $base > 0 ) ? ( $val / $base ) : 1.0;
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

		$max = (int) apply_filters( 'flavor_edge_preload_max_urls', self::MAX_URLS );
		return array_slice( array_unique( $urls ), 0, max( 1, $max ) );
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
