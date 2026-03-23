<?php
/**
 * WordPress Heartbeat API control.
 *
 * The Heartbeat API sends AJAX requests every 15-60 seconds for features like
 * autosave, post locking, and login expiration. On busy sites this creates
 * unnecessary load on the origin server.
 *
 * This module allows controlling the heartbeat frequency or disabling it
 * entirely in the admin dashboard, post editor, and/or frontend.
 *
 * @package flavor_edge_cache
 */

namespace flavor_edge;

defined( 'ABSPATH' ) || exit;

class TE_Heartbeat {

	/**
	 * Initialize heartbeat control.
	 */
	public static function init() {
		if ( ! TE_Settings::get( 'enabled' ) ) {
			return;
		}

		$behavior = TE_Settings::get( 'heartbeat_behavior', 'default' );

		if ( 'default' === $behavior ) {
			return;
		}

		// Disable heartbeat entirely in specific locations.
		add_action( 'init', array( __CLASS__, 'maybe_disable_heartbeat' ), 1 );

		// Modify heartbeat frequency.
		add_filter( 'heartbeat_settings', array( __CLASS__, 'modify_frequency' ) );
	}

	/**
	 * Deregister heartbeat script where configured.
	 */
	public static function maybe_disable_heartbeat() {
		$behavior       = TE_Settings::get( 'heartbeat_behavior', 'default' );
		$admin_disabled = TE_Settings::get( 'heartbeat_disable_admin', false );
		$editor_disabled = TE_Settings::get( 'heartbeat_disable_editor', false );

		if ( 'disable_everywhere' === $behavior ) {
			wp_deregister_script( 'heartbeat' );
			return;
		}

		// Disable in admin dashboard (but not in post editor).
		if ( $admin_disabled && is_admin() ) {
			global $pagenow;
			// Keep heartbeat alive in post editor for autosave/locking.
			if ( ! in_array( $pagenow, array( 'post.php', 'post-new.php' ), true ) ) {
				wp_deregister_script( 'heartbeat' );
			}
		}

		// Disable in post editor (risky — disables autosave).
		if ( $editor_disabled && is_admin() ) {
			global $pagenow;
			if ( in_array( $pagenow, array( 'post.php', 'post-new.php' ), true ) ) {
				wp_deregister_script( 'heartbeat' );
			}
		}
	}

	/**
	 * Modify heartbeat frequency.
	 *
	 * @param array $settings Heartbeat settings.
	 * @return array Modified settings.
	 */
	public static function modify_frequency( $settings ) {
		$interval = (int) TE_Settings::get( 'heartbeat_interval', 60 );

		if ( $interval > 0 ) {
			// WordPress minimum is 15, maximum 120.
			$settings['interval'] = max( 15, min( 120, $interval ) );
		}

		return $settings;
	}
}
