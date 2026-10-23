<?php
/**
 * WP-CLI commands for Transparent Edge Cache.
 *
 * Thin wrappers over the already-hardened plugin APIs (TE_Api, TE_Preload,
 * TE_ObjectCache, TE_PageCache, TE_Settings) so a site can be operated from
 * deploy pipelines: `wp te-cache purge|warm|status|config`.
 *
 * Registered only under WP-CLI (see transparent-edge-cache.php).
 *
 * @package flavor_edge_cache
 */

namespace flavor_edge;

defined( 'ABSPATH' ) || exit;

/**
 * Manage the Transparent Edge CDN cache from the command line.
 */
class TE_CLI {

	/**
	 * Purge content from the Transparent Edge CDN.
	 *
	 * Uses a soft purge by default (serves stale while revalidating — safe for
	 * high-traffic sites). Exactly one target is required.
	 *
	 * ## OPTIONS
	 *
	 * [--all]
	 * : Purge the whole site (soft, via the site-wide tag). Add --ban for a hard recursive ban.
	 *
	 * [--url=<urls>]
	 * : Purge one or more absolute URLs (comma-separated).
	 *
	 * [--tag=<keys>]
	 * : Purge by Surrogate-Key tag(s) (comma-separated), e.g. post-123,term-5.
	 *
	 * [--ban]
	 * : With --all, force a hard recursive BAN instead of a soft tag purge.
	 *
	 * [--hard]
	 * : Use a hard purge instead of the default soft purge (--url / --tag).
	 *
	 * [--refetch]
	 * : Re-warm each URL right after purging (only with --url).
	 *
	 * ## EXAMPLES
	 *
	 *     wp te-cache purge --all
	 *     wp te-cache purge --url=https://site.tld/a/,https://site.tld/b/ --refetch
	 *     wp te-cache purge --tag=post-123,term-5
	 *
	 * @when after_wp_load
	 *
	 * @param array $args       Positional args (unused).
	 * @param array $assoc_args Flags.
	 */
	public function purge( $args, $assoc_args ) {
		$soft    = empty( $assoc_args['hard'] );
		$refetch = ! empty( $assoc_args['refetch'] );

		if ( ! empty( $assoc_args['all'] ) ) {
			$res = TE_Api::purge_all( ! empty( $assoc_args['ban'] ) );
			$this->report( $res, 'Purge all' );
			return;
		}

		if ( ! empty( $assoc_args['url'] ) ) {
			$urls = $this->split_list( $assoc_args['url'] );
			$res  = TE_Api::purge_urls( $urls, $soft, $refetch );
			$this->report( $res, 'Purge URLs' );
			return;
		}

		if ( ! empty( $assoc_args['tag'] ) ) {
			$tags = $this->split_list( $assoc_args['tag'] );
			$res  = TE_Api::purge_tags( $tags, $soft );
			$this->report( $res, 'Purge tags' );
			return;
		}

		\WP_CLI::error( 'Nothing to purge. Use --all, --url=<url[,url]> or --tag=<key[,key]>.' );
	}

	/**
	 * Warm up (preload) the cache.
	 *
	 * With no flags, queues a background preload (local prioritised build:
	 * home -> section archives -> most-recent posts) and returns immediately.
	 * Use --block to drive it to completion synchronously (for CI/deploy), or
	 * --status to inspect a run in progress.
	 *
	 * ## OPTIONS
	 *
	 * [--block]
	 * : Run the preload to completion in the foreground, showing progress.
	 *
	 * [--status]
	 * : Show the status of the current/last preload and exit.
	 *
	 * [--stop]
	 * : Stop a running preload and exit.
	 *
	 * ## EXAMPLES
	 *
	 *     wp te-cache warm
	 *     wp te-cache warm --block
	 *     wp te-cache warm --status
	 *
	 * @when after_wp_load
	 *
	 * @param array $args       Positional args (unused).
	 * @param array $assoc_args Flags.
	 */
	public function warm( $args, $assoc_args ) {
		if ( ! empty( $assoc_args['status'] ) ) {
			$this->print_preload_status();
			return;
		}

		if ( ! empty( $assoc_args['stop'] ) ) {
			TE_Preload::stop();
			\WP_CLI::success( 'Preload stopped.' );
			return;
		}

		$res = TE_Preload::start( true );
		if ( empty( $res['success'] ) ) {
			\WP_CLI::error( isset( $res['message'] ) ? $res['message'] : 'Could not start the preload.' );
			return;
		}

		$total = isset( $res['urls'] ) ? (int) $res['urls'] : 0;

		if ( empty( $assoc_args['block'] ) ) {
			\WP_CLI::success( sprintf(
				'Preload queued: %d URLs. Running in background — check with: wp te-cache warm --status',
				$total
			) );
			return;
		}

		// --block: drive the same batch processor the cron would run, to completion.
		$progress = \WP_CLI\Utils\make_progress_bar( 'Warming', max( 1, $total ) );
		$done_prev = 0;
		$idle      = 0;
		// Safety cap: far more iterations than batches could ever need, so a wedged
		// origin can never loop forever.
		$cap = $total * 4 + 500;

		for ( $i = 0; $i < $cap; $i++ ) {
			TE_Preload::cron_process_batch();
			$st = TE_Preload::get_status();
			if ( ! is_array( $st ) ) {
				break;
			}

			$done = isset( $st['processed'] ) ? (int) $st['processed'] : 0;
			if ( $done > $done_prev ) {
				$progress->tick( $done - $done_prev );
				$done_prev = $done;
			}

			$state = isset( $st['status'] ) ? $st['status'] : '';
			if ( in_array( $state, array( 'completed', 'stopped', 'stalled' ), true ) ) {
				break;
			}

			// The pacer may pause (off-peak window, unhealthy origin). Don't spin:
			// back off, and give up block mode if it stays paused.
			$waiting = isset( $st['waiting'] ) && '' !== $st['waiting'] ? $st['waiting'] : '';
			if ( $waiting ) {
				$idle++;
				if ( $idle > 20 ) {
					\WP_CLI::warning( sprintf( 'Preload paused (%s); leaving it to the background. Check later with --status.', $waiting ) );
					break;
				}
				usleep( 300000 ); // 0.3s
			} else {
				$idle = 0;
			}
		}

		$progress->finish();
		$this->print_preload_status();
	}

	/**
	 * Show the status of the plugin: API connection, object cache and page cache.
	 *
	 * ## OPTIONS
	 *
	 * [--format=<format>]
	 * : Output format. One of: table, json, yaml, csv. Default: table.
	 *
	 * ## EXAMPLES
	 *
	 *     wp te-cache status
	 *     wp te-cache status --format=json
	 *
	 * @when after_wp_load
	 *
	 * @param array $args       Positional args (unused).
	 * @param array $assoc_args Flags.
	 */
	public function status( $args, $assoc_args ) {
		$format = isset( $assoc_args['format'] ) ? $assoc_args['format'] : 'table';
		$rows   = array();

		// --- API connection / resilience ---
		$circuit = TE_Api::get_circuit_status();
		$health  = TE_Api::get_health_status();
		$rows[]  = array(
			'component' => 'API health',
			'state'     => ! empty( $health['healthy'] ) ? 'healthy' : 'unhealthy',
			'detail'    => ! empty( $health['last_error'] ) ? (string) $health['last_error'] : '-',
		);
		$rows[] = array(
			'component' => 'API circuit breaker',
			'state'     => ! empty( $circuit['open'] ) ? 'OPEN (degraded)' : 'closed',
			'detail'    => ! empty( $circuit['open'] ) ? ( (int) $circuit['cooldown_remaining'] . 's cooldown' ) : '-',
		);

		// --- Object cache ---
		$dropin = TE_ObjectCache::get_dropin_status();
		$stats  = TE_ObjectCache::get_stats();
		if ( empty( $dropin['installed'] ) ) {
			$oc_state  = 'not installed';
			$oc_detail = '-';
		} elseif ( empty( $dropin['ours'] ) ) {
			$oc_state  = 'foreign drop-in';
			$oc_detail = (string) $dropin['other'];
		} elseif ( ! empty( $stats['degraded'] ) ) {
			$oc_state  = 'DEGRADED (not persistent)';
			$oc_detail = 'backend ' . $dropin['backend'] . ' not answering';
		} else {
			$oc_state  = 'active (' . $dropin['backend'] . ')';
			$oc_detail = sprintf(
				'hits %d / misses %d (%s%%)%s',
				isset( $stats['hits'] ) ? (int) $stats['hits'] : 0,
				isset( $stats['misses'] ) ? (int) $stats['misses'] : 0,
				isset( $stats['hit_ratio'] ) ? $stats['hit_ratio'] : 0,
				! empty( $stats['outdated'] ) ? ' — drop-in OUTDATED' : ''
			);
		}
		$rows[] = array( 'component' => 'Object cache', 'state' => $oc_state, 'detail' => $oc_detail );

		// --- Page cache (origin shield) ---
		if ( TE_PageCache::is_active() ) {
			$pc_state = 'active';
		} elseif ( TE_PageCache::is_enabled() ) {
			$pc_state = 'enabled (inactive)';
		} else {
			$pc_state = 'off';
		}
		$rows[] = array( 'component' => 'Page cache', 'state' => $pc_state, 'detail' => '-' );

		\WP_CLI\Utils\format_items( $format, $rows, array( 'component', 'state', 'detail' ) );
	}

	/**
	 * Show the effective plugin configuration (credentials redacted).
	 *
	 * Read-only. Secret credentials (client id / secret) are never printed.
	 *
	 * ## OPTIONS
	 *
	 * [--format=<format>]
	 * : Output format. One of: table, json, yaml, csv. Default: table.
	 *
	 * ## EXAMPLES
	 *
	 *     wp te-cache config
	 *     wp te-cache config --format=json
	 *
	 * @when after_wp_load
	 *
	 * @param array $args       Positional args (unused).
	 * @param array $assoc_args Flags.
	 */
	public function config( $args, $assoc_args ) {
		$format   = isset( $assoc_args['format'] ) ? $assoc_args['format'] : 'table';
		$settings = TE_Settings::get_all();
		if ( ! is_array( $settings ) ) {
			$settings = array();
		}

		// Never leak secrets: redact the encrypted credential fields, plus anything
		// that looks like a secret/password/token.
		$secret_keys = TE_Settings::ENCRYPTED_FIELDS;

		$rows = array();
		ksort( $settings );
		foreach ( $settings as $key => $value ) {
			$is_secret = in_array( $key, (array) $secret_keys, true )
				|| preg_match( '/secret|password|token/i', $key );
			if ( $is_secret ) {
				$display = ( '' === $value || null === $value ) ? '(empty)' : '*** (hidden)';
			} elseif ( is_bool( $value ) ) {
				$display = $value ? 'true' : 'false';
			} elseif ( is_scalar( $value ) || null === $value ) {
				$display = (string) $value;
			} else {
				$display = wp_json_encode( $value );
			}
			$rows[] = array( 'key' => $key, 'value' => $display );
		}

		\WP_CLI\Utils\format_items( $format, $rows, array( 'key', 'value' ) );
	}

	// -------------------------------------------------------------------------
	// Helpers.
	// -------------------------------------------------------------------------

	/**
	 * Split a comma-separated CLI value into a trimmed, non-empty list.
	 *
	 * @param string $value Raw flag value.
	 * @return array
	 */
	private function split_list( $value ) {
		$parts = array_map( 'trim', explode( ',', (string) $value ) );
		return array_values( array_filter( $parts, 'strlen' ) );
	}

	/**
	 * Report the { success, message } result of a purge call to WP-CLI.
	 *
	 * @param mixed  $res   Result from a TE_Api purge method.
	 * @param string $label Human label for the action.
	 */
	private function report( $res, $label ) {
		if ( is_wp_error( $res ) ) {
			\WP_CLI::error( $label . ' failed: ' . $res->get_error_message() );
			return;
		}
		$ok  = is_array( $res ) ? ! empty( $res['success'] ) : (bool) $res;
		$msg = is_array( $res ) && isset( $res['message'] ) ? (string) $res['message'] : '';
		if ( $ok ) {
			\WP_CLI::success( $label . ( '' !== $msg ? ': ' . $msg : ' OK' ) );
		} else {
			\WP_CLI::error( $label . ( '' !== $msg ? ': ' . $msg : ' failed' ) );
		}
	}

	/**
	 * Print the current preload status as a table.
	 */
	private function print_preload_status() {
		$st = TE_Preload::get_status();
		if ( ! is_array( $st ) ) {
			\WP_CLI::log( 'No preload has run yet.' );
			return;
		}
		$rows = array(
			array( 'field' => 'status',     'value' => isset( $st['status'] ) ? (string) $st['status'] : '?' ),
			array( 'field' => 'total',      'value' => isset( $st['total'] ) ? (int) $st['total'] : 0 ),
			array( 'field' => 'processed',  'value' => isset( $st['processed'] ) ? (int) $st['processed'] : 0 ),
			array( 'field' => 'remaining',  'value' => isset( $st['remaining'] ) ? count( (array) $st['remaining'] ) : 0 ),
			array( 'field' => 'batch_size', 'value' => isset( $st['batch_size'] ) ? (int) $st['batch_size'] : 0 ),
			array( 'field' => 'waiting',    'value' => ( isset( $st['waiting'] ) && '' !== $st['waiting'] ) ? (string) $st['waiting'] : '-' ),
		);
		\WP_CLI\Utils\format_items( 'table', $rows, array( 'field', 'value' ) );
	}
}
