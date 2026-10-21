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
	const LOCK_KEY = 'flavor_edge_preload_lock'; // FIX 2 (T2.9): cross-process batch lock.
	const BATCH_SIZE = 3;
	const BATCH_DELAY = 2;
	const MAX_URLS = 500;
	const LOCK_TTL = 30;      // FIX 2 (T2.9): seconds a batch lock stays valid; a stale lock auto-expires so a crash never wedges the warm.
	const STALL_LIMIT = 30;   // FIX 3 (T2.10): consecutive no-progress cycles before an unattended warm gives up (filterable).

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
		$urls = self::get_urls();

		if ( empty( $urls ) ) {
			return array(
				'success' => false,
				'message' => __( 'No URLs found to preload.', 'flavor-edge-cache' ),
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
			'no_progress' => 0,                // FIX 3 (T2.10): consecutive no-progress cycles → give up at STALL_LIMIT.
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

		// FIX 2 (T2.9): take the cross-process lock so this admin tick and the
		// WP-Cron fallback can't warm/splice the same batch at once (double work,
		// double-counted telemetry). Held and fresh → skip, just report status.
		if ( ! self::acquire_lock() ) {
			wp_send_json_success( $status );
			return;
		}

		// Process one batch.
		$status = self::do_batch( $status );
		$status = self::persist_progress( $status );
		self::release_lock();

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

		// FIX 2 (T2.9): same cross-process lock as the AJAX tick — if the admin
		// tick is mid-batch, skip this cron run rather than double-process.
		if ( ! self::acquire_lock() ) {
			return;
		}

		$status = self::do_batch( $status );
		$status = self::persist_progress( $status );
		self::release_lock();

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
			// FIX 3 (T2.10): a gated-off unhealthy origin is a no-progress cycle —
			// count it so a permanently down/unreachable origin eventually stalls.
			return self::mark_no_progress( $status );
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
			// FIX 3 (T2.10): a persistently near-full disk also stalls progress —
			// count it toward give-up so the warm cannot reschedule forever.
			return self::mark_no_progress( $status );
		}

		// Adaptive batch size, always clamped by the guardrails above.
		$bs = $autotune
			? max( 1, (int) ( isset( $status['batch_size'] ) ? $status['batch_size'] : self::BATCH_SIZE ) )
			: self::BATCH_SIZE;

		$batch       = array_splice( $status['remaining'], 0, $bs );
		$batch_count = count( $batch );

		// One platform call warms the whole batch across all nodes.
		$method = TE_Settings::get( 'preload_method' );
		if ( 'prefetch' === $method ) {
			$result = TE_Api::prefetch( $batch );
		} else {
			$result = TE_Api::purge_urls( $batch, true, true ); // soft purge + aggregated refetch.
		}

		$status['last_batch'] = time();
		$status['waiting']    = '';

		$success = ! empty( $result['success'] );
		$code    = (int) ( isset( $result['status'] ) ? $result['status'] : 0 );

		// The warm endpoint reports partially-invalid URLs with 206 (mapped to
		// success), NEVER a per-URL 4xx. So any non-success here is an API-level
		// problem (token/creds 401/403, wrong company id 404, bad payload 400, rate
		// limit 429/503, 5xx, or a connection/no-token error) and is transient for
		// warming: keep the batch queued and retry; repeated failures escalate to
		// 'stalled' instead of silently dropping warm batches as cold.
		if ( ! $success ) {
			// Keep the batch: push it back to the front of the queue, back off, and
			// record the no-progress cycle (FIX 3 turns repeated ones into 'stalled').
			$status['remaining'] = array_merge( $batch, $status['remaining'] );
			$status['backoff']   = min( (int) ( isset( $status['backoff'] ) ? $status['backoff'] : 0 ) + 1, 6 );
			if ( 429 === $code || 503 === $code ) {
				$status['retry_after'] = (int) ( isset( $result['retry_after'] ) ? $result['retry_after'] : 0 );
			}
			if ( $autotune ) {
				$status['batch_size'] = max( 1, (int) floor( $bs / 2 ) ); // multiplicative decrease.
			}
			return self::mark_no_progress( $status );
		}

		// Batch warmed (only a success reaches here — every failure returned above).
		// Consume it, clamped (FIX 1) so processed can never exceed total and the
		// admin UI can never show >100%.
		$status['processed']   = min( (int) $status['total'], (int) $status['processed'] + $batch_count );
		$status['no_progress'] = 0; // FIX 3 (T2.10): real forward progress → reset the give-up counter.

		// Warmed successfully → the infrastructure is healthy, clear backoff and let
		// the pacer ramp.
		$status['backoff']     = 0;
		$status['retry_after'] = 0;

		if ( $success && $autotune ) {
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

		if ( empty( $status['remaining'] ) ) {
			$status['status']   = 'completed';
			$status['finished'] = time();
			$status['elapsed']  = $status['finished'] - $status['started'];
			wp_clear_scheduled_hook( 'flavor_edge_preload_batch' );
		}

		return $status;
	}

	/**
	 * FIX 3 (T2.10): record one no-progress / paused cycle and, once too many pile
	 * up in a row, give up — set status 'stalled' and clear the scheduled batch
	 * cron so an unattended warm against a permanently unhealthy/unreachable origin
	 * (loopback blocked, persistent 5xx, disk wedged) can never reschedule forever.
	 * The counter is reset to 0 in do_batch() whenever real progress is made.
	 *
	 * @param array $status In-memory status.
	 * @return array Updated status (possibly 'stalled').
	 */
	private static function mark_no_progress( $status ) {
		$status['no_progress'] = (int) ( isset( $status['no_progress'] ) ? $status['no_progress'] : 0 ) + 1;
		$limit = (int) apply_filters( 'flavor_edge_preload_max_stall', self::STALL_LIMIT );
		if ( $limit > 0 && $status['no_progress'] >= $limit ) {
			$status['status']   = 'stalled';
			$status['waiting']  = '';
			$status['finished'] = time();
			$status['elapsed']  = $status['finished'] - (int) $status['started'];
			wp_clear_scheduled_hook( 'flavor_edge_preload_batch' );
		}
		return $status;
	}

	/**
	 * FIX 2 (T2.9): acquire a short-lived cross-process lock so the AJAX tick and
	 * the WP-Cron fallback cannot warm/splice the same batch at the same time
	 * (double work + double-counted telemetry). Idiomatic get_option/update_option
	 * with a unique-token read-back so the loser of a concurrent race backs off. A
	 * fresh lock (< LOCK_TTL) is left alone; a stale one is reclaimed, so a crash
	 * mid-batch auto-expires instead of wedging the preload forever.
	 *
	 * @return bool True if this process now holds the lock.
	 */
	private static function acquire_lock() {
		$ttl  = (int) apply_filters( 'flavor_edge_preload_lock_ttl', self::LOCK_TTL );
		$now  = time();
		$lock = get_option( self::LOCK_KEY, null );

		// Someone else holds a fresh lock → do not touch it, skip this tick.
		if ( is_array( $lock ) && isset( $lock['t'] ) && ( $now - (int) $lock['t'] ) < $ttl ) {
			return false;
		}

		// Free or stale: claim it with a unique token, then read back to confirm we
		// won any concurrent race (last writer wins; only that writer proceeds).
		$token = uniqid( '', true );
		update_option( self::LOCK_KEY, array( 't' => $now, 'id' => $token ), false );

		$check = get_option( self::LOCK_KEY, null );
		return ( is_array( $check ) && isset( $check['id'] ) && $check['id'] === $token );
	}

	/**
	 * FIX 2 (T2.9): release the cross-process batch lock held by acquire_lock().
	 */
	private static function release_lock() {
		delete_option( self::LOCK_KEY );
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
			'httpversion' => '1.1', // WP defaults to HTTP/1.0, which the edge can drop over IPv4.
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
	 * Build the preload URL set.
	 *
	 * Prefers a LOCAL, prioritised build (home → section archives → most-recent
	 * posts) assembled from WordPress itself — no HTTP, so it never hairpins out
	 * to the CDN and back to the origin, and the busiest URLs are warmed first
	 * instead of "whatever the sitemap lists first". The sitemap-over-HTTP path
	 * stays as a fallback for decoupled/headless front ends or sites whose URLs
	 * do not come out of the standard WP queries.
	 *
	 * @return array
	 */
	public static function get_urls() {
		$max = max( 1, (int) apply_filters( 'flavor_edge_preload_max_urls', self::MAX_URLS ) );

		$urls = self::build_local_urls( $max );

		// Only reach for the sitemap (HTTP, hairpin-prone) if the local build came
		// up short AND did not even fill the cap — e.g. a headless front end or
		// content outside WP's own queries. A deliberately tiny cap is not "short".
		$min = (int) apply_filters( 'flavor_edge_preload_local_min', 5 );
		if ( count( $urls ) < $min && count( $urls ) < $max ) {
			$sitemap = self::get_urls_from_sitemap();
			if ( count( $sitemap ) > count( $urls ) ) {
				// Keep local priority order first, then top up from the sitemap.
				$urls = array_slice( array_values( array_unique( array_merge( $urls, $sitemap ) ) ), 0, $max );
			}
		}

		/**
		 * Filter the final preload URL list (after local build + sitemap fallback).
		 *
		 * @param array $urls Ordered, de-duplicated, capped URL list.
		 */
		return apply_filters( 'flavor_edge_preload_urls', $urls );
	}

	/**
	 * Assemble the preload URLs locally, in warm-priority order, from WordPress.
	 *
	 * Order: home / front page → posts page → busiest public term archives →
	 * most-recent published content. No network calls.
	 *
	 * @param int $max Hard cap on the number of URLs.
	 * @return array
	 */
	private static function build_local_urls( $max ) {
		$urls = array();
		$push = function ( $u ) use ( &$urls, $max ) {
			if ( ! is_string( $u ) || '' === $u ) {
				return;
			}
			$u = strtok( $u, '#' ); // Drop fragments; they are never separate cache entries.
			if ( count( $urls ) < $max && ! in_array( $u, $urls, true ) ) {
				$urls[] = $u;
			}
		};

		// 1. Home.
		$push( home_url( '/' ) );

		// 2. Static front page + blog posts page, when configured that way.
		if ( 'page' === get_option( 'show_on_front' ) ) {
			$pf = (int) get_option( 'page_on_front' );
			$pp = (int) get_option( 'page_for_posts' );
			if ( $pf ) {
				$push( get_permalink( $pf ) );
			}
			if ( $pp ) {
				$push( get_permalink( $pp ) );
			}
		}

		// 3. Section archives — busiest public term pages first (categories, etc.).
		$term_cap = (int) apply_filters( 'flavor_edge_preload_terms', 40 );
		if ( $term_cap > 0 && count( $urls ) < $max ) {
			$taxes = get_taxonomies( array( 'public' => true ), 'names' );
			unset( $taxes['post_format'] ); // Formats are not sections.
			if ( ! empty( $taxes ) ) {
				$terms = get_terms( array(
					'taxonomy'   => array_values( $taxes ),
					'orderby'    => 'count',
					'order'      => 'DESC',
					'number'     => $term_cap,
					'hide_empty' => true,
				) );
				if ( ! is_wp_error( $terms ) ) {
					foreach ( $terms as $term ) {
						$link = get_term_link( $term );
						if ( ! is_wp_error( $link ) ) {
							$push( $link );
						}
						if ( count( $urls ) >= $max ) {
							break;
						}
					}
				}
			}
		}

		// 4. Most-recent published content across public post types (posts, pages, CPTs).
		if ( count( $urls ) < $max ) {
			$types = get_post_types( array( 'public' => true ), 'names' );
			unset( $types['attachment'] );
			$recent = (int) apply_filters( 'flavor_edge_preload_recent_posts', $max );
			$query  = new \WP_Query( array(
				'post_type'           => array_values( $types ),
				'post_status'         => 'publish',
				'posts_per_page'      => max( 1, min( $recent, $max ) ),
				'orderby'             => 'date',
				'order'               => 'DESC',
				'ignore_sticky_posts' => true,
				'no_found_rows'       => true,
				// Default fields (not 'ids') so WP primes the post cache in one query;
				// get_permalink() below then hits that cache instead of one query per URL.
				'update_post_meta_cache' => false,
				'update_post_term_cache' => false,
			) );
			foreach ( $query->posts as $post ) {
				$push( get_permalink( $post ) );
				if ( count( $urls ) >= $max ) {
					break;
				}
			}
		}

		return array_slice( $urls, 0, $max );
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

		$timeout = (int) apply_filters( 'flavor_edge_preload_sitemap_timeout', 20 );

		$body = '';
		foreach ( $sitemap_urls as $candidate ) {
			$response = wp_remote_get( $candidate, array(
				'timeout'     => $timeout,
				'httpversion' => '1.1', // WP defaults to HTTP/1.0; the TE edge can drop it over IPv4 → timeout → "no URLs".
				'user-agent'  => 'WordPress/' . get_bloginfo( 'version' ) . '; ' . home_url( '/' ),
				'redirection' => 3,
			) );

			if ( is_wp_error( $response ) ) {
				continue;
			}

			$resp_body = wp_remote_retrieve_body( $response );

			// Accept only an ACTUAL sitemap, not any HTTP 200: /wp-sitemap.xml may 301
			// to the Yoast index, and a themed 200 HTML page is not a sitemap.
			if ( ! empty( $resp_body )
				&& ( false !== strpos( $resp_body, '<sitemapindex' ) || false !== strpos( $resp_body, '<urlset' ) ) ) {
				$body = $resp_body;
				break;
			}
		}

		if ( empty( $body ) ) {
			return $urls;
		}

		$max = max( 1, (int) apply_filters( 'flavor_edge_preload_max_urls', self::MAX_URLS ) );

		if ( false !== strpos( $body, '<sitemapindex' ) ) {
			// A Yoast index can hold 50+ child sitemaps; fetching all of them in one
			// AJAX request times out (a single child can take seconds). Fetch children
			// only until we have enough URLs for the cap, then stop.
			foreach ( self::extract_locs( $body ) as $sub_url ) {
				$sub = wp_remote_get( $sub_url, array(
					'timeout'     => $timeout,
					'httpversion' => '1.1',
					'user-agent'  => 'WordPress/' . get_bloginfo( 'version' ) . '; ' . home_url( '/' ),
				) );
				if ( ! is_wp_error( $sub ) ) {
					$sub_body = wp_remote_retrieve_body( $sub );
					if ( ! empty( $sub_body ) && false !== strpos( $sub_body, '<urlset' ) ) {
						$urls = array_merge( $urls, self::extract_locs( $sub_body ) );
					}
				}
				if ( count( $urls ) >= $max ) {
					break;
				}
			}
		} else {
			$urls = self::extract_locs( $body );
		}

		return array_slice( array_unique( $urls ), 0, $max );
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

				// SSRF protection: only allow URLs from our own host. (A per-URL
				// gethostbyname + private-IP check used to sit here too, but it blocked
				// EVERY URL on CDN origins that resolve their own hostname to a private
				// IP for hairpin avoidance — and this host match already bounds us to
				// our own site, so it was redundant as well as harmful.)
				if ( wp_parse_url( $url, PHP_URL_HOST ) !== $own_host ) {
					continue;
				}

				$urls[] = $url;
			}
		}
		return $urls;
	}

}
