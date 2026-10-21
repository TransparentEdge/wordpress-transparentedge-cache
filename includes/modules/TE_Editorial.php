<?php
/**
 * Editorial write controls (opt-in).
 *
 * A busy newsroom generates a steady stream of *write* load that no cache can
 * absorb: every autosave tick and every stored revision is an INSERT/UPDATE on
 * the posts table, on the logged-in path that bypasses page cache entirely. W3
 * Total Cache leaves this to wp-config constants; we expose it in the UI so an
 * editor-heavy site can throttle it without touching code.
 *
 * Both controls are OFF by default and change nothing until enabled — we never
 * silently alter how an editor's work is saved.
 *
 * @package flavor_edge_cache
 */

namespace flavor_edge;

defined( 'ABSPATH' ) || exit;

class TE_Editorial {

	/**
	 * Register the editorial controls when opted in.
	 */
	public static function init() {
		if ( ! TE_Settings::get( 'enabled' ) ) {
			return;
		}
		if ( ! TE_Settings::get( 'editorial_tune', false ) ) {
			return; // Opt-in: do nothing unless the admin turned it on.
		}

		// Cap stored revisions per post. 0 disables revisions entirely; a positive
		// value keeps that many; -1 (our "leave alone") never registers the filter.
		$revisions = (int) TE_Settings::get( 'editorial_revisions', -1 );
		if ( $revisions >= 0 ) {
			add_filter( 'wp_revisions_to_keep', array( __CLASS__, 'limit_revisions' ), 99, 2 );
		}

		// Lengthen the autosave interval. Core reads AUTOSAVE_INTERVAL when it
		// registers the autosave script, so we must define it before admin scripts
		// enqueue and only if the site has not already set it in wp-config.
		add_action( 'init', array( __CLASS__, 'maybe_set_autosave_interval' ), 1 );
	}

	/**
	 * Return the configured revision cap.
	 *
	 * @param int      $num  Revisions WordPress would otherwise keep.
	 * @param \WP_Post $post The post being saved (unused; cap is global).
	 * @return int
	 */
	public static function limit_revisions( $num, $post = null ) {
		$limit = (int) TE_Settings::get( 'editorial_revisions', -1 );
		if ( $limit < 0 ) {
			return $num; // Safety net: "leave alone" sentinel slipped through.
		}
		return $limit;
	}

	/**
	 * Define AUTOSAVE_INTERVAL (seconds) if the site has not already.
	 */
	public static function maybe_set_autosave_interval() {
		if ( defined( 'AUTOSAVE_INTERVAL' ) ) {
			return; // Respect an explicit wp-config value.
		}
		$interval = (int) TE_Settings::get( 'editorial_autosave', 0 );
		if ( $interval <= 0 ) {
			return; // 0 = leave WordPress default (60s).
		}
		// Clamp to something sane: never below WP's 60s floor, never beyond 10 min.
		$interval = max( 60, min( 600, $interval ) );
		define( 'AUTOSAVE_INTERVAL', $interval );
	}
}
