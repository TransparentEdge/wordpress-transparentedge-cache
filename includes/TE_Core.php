<?php
/**
 * Core orchestrator for Transparent Edge Cache.
 *
 * @package flavor_edge_cache
 */

namespace flavor_edge;

defined( 'ABSPATH' ) || exit;

class TE_Core {

	/**
	 * Singleton instance.
	 *
	 * @var TE_Core|null
	 */
	private static $instance = null;

	/**
	 * Get singleton instance.
	 *
	 * @return TE_Core
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Constructor. Boot modules.
	 */
	private function __construct() {
		$this->load_modules();
		$this->register_admin();
		$this->register_admin_bar();
	}

	/**
	 * Load front-end / universal modules.
	 */
	private function load_modules() {
		// Headers module (runs on every public request).
		TE_Headers::init();

		// Invalidation module (hooks into WP save events).
		TE_Invalidation::init();

		// i3 Image optimization.
		TE_I3::init();

		// Frontend optimization (delay JS, minify HTML, lazy load).
		TE_Frontend::init();

		// Sitemap preload (warm Varnish after purge).
		TE_Preload::init();

		// Heartbeat API control.
		TE_Heartbeat::init();

		// WooCommerce integration (only if WooCommerce is active).
		TE_WooCommerce::init();

		// Multisite compatibility.
		TE_Multisite::init();

		// CSS/JS Minification + Defer.
		TE_Minify::init();

		// Self-host Google Fonts.
		TE_GoogleFonts::init();

		// Object Cache management (admin only).
		TE_ObjectCache::init();

		// Browser Cache (.htaccess rules for static files).
		TE_BrowserCache::init();

		// Performance Dashboard (admin only).
		TE_Dashboard::init();
	}

	/**
	 * Register admin panel.
	 */
	private function register_admin() {
		if ( is_admin() ) {
			TE_Admin::init();
		}
	}

	/**
	 * Register admin bar purge buttons.
	 */
	private function register_admin_bar() {
		add_action( 'admin_bar_menu', array( $this, 'add_admin_bar_menu' ), 100 );
		add_action( 'admin_init', array( $this, 'handle_admin_bar_actions' ) );
		add_action( 'init', array( $this, 'handle_admin_bar_actions' ) ); // Front-end.
	}

	/**
	 * Add purge buttons to admin bar.
	 *
	 * @param \WP_Admin_Bar $wp_admin_bar Admin bar instance.
	 */
	public function add_admin_bar_menu( $wp_admin_bar ) {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		if ( ! TE_Settings::is_connected() ) {
			return;
		}

		// Check circuit breaker state for visual indicator.
		$circuit = TE_Api::get_circuit_status();
		$bar_title = '<span class="ab-icon dashicons dashicons-performance" style="margin-top:2px"></span>';

		if ( $circuit['open'] ) {
			$bar_title .= '<span style="color:#dc3232;">' . __( 'TE Cache ⚠', 'flavor-edge-cache' ) . '</span>';
		} else {
			$bar_title .= __( 'TE Cache', 'flavor-edge-cache' );
		}

		// Parent node.
		$wp_admin_bar->add_node( array(
			'id'    => 'flavor-edge',
			'title' => $bar_title,
			'href'  => admin_url( 'admin.php?page=flavor-edge-cache' ),
		) );

		// Purge All.
		$wp_admin_bar->add_node( array(
			'id'     => 'flavor-edge-purge-all',
			'parent' => 'flavor-edge',
			'title'  => __( 'Purge All Cache', 'flavor-edge-cache' ),
			'href'   => wp_nonce_url( add_query_arg( 'flavor_edge_action', 'purge_all' ), 'flavor_edge_purge' ),
		) );

		// Purge Current Page (only on front-end single pages).
		if ( ! is_admin() && is_singular() ) {
			global $post;
			if ( $post ) {
				$wp_admin_bar->add_node( array(
					'id'     => 'flavor-edge-purge-post',
					'parent' => 'flavor-edge',
					'title'  => __( 'Purge This Page', 'flavor-edge-cache' ),
					'href'   => wp_nonce_url(
						add_query_arg( array(
							'flavor_edge_action' => 'purge_post',
							'flavor_edge_post'   => $post->ID,
						) ),
						'flavor_edge_purge'
					),
				) );
			}
		}

		// Settings link.
		$wp_admin_bar->add_node( array(
			'id'     => 'flavor-edge-settings',
			'parent' => 'flavor-edge',
			'title'  => __( 'Settings', 'flavor-edge-cache' ),
			'href'   => admin_url( 'admin.php?page=flavor-edge-cache' ),
		) );
	}

	/**
	 * Handle admin bar purge actions.
	 */
	public function handle_admin_bar_actions() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		if ( ! isset( $_GET['flavor_edge_action'] ) ) {
			return;
		}

		if ( ! wp_verify_nonce( $_GET['_wpnonce'] ?? '', 'flavor_edge_purge' ) ) {
			return;
		}

		$action = sanitize_text_field( $_GET['flavor_edge_action'] );

		// Rate limit: max 1 purge action per 5 seconds per user.
		$rate_key = 'flavor_edge_rate_' . get_current_user_id();
		if ( in_array( $action, array( 'purge_all', 'purge_post' ), true ) ) {
			if ( get_transient( $rate_key ) ) {
				set_transient( 'flavor_edge_admin_notice',
					__( 'Please wait a few seconds between purge actions.', 'flavor-edge-cache' ), 30 );
				$redirect_url = remove_query_arg( array( 'flavor_edge_action', 'flavor_edge_post', '_wpnonce' ) );
				wp_safe_redirect( $redirect_url );
				exit;
			}
			set_transient( $rate_key, 1, 5 );
		}

		switch ( $action ) {
			case 'purge_all':
				$result = TE_Api::purge_all();
				$msg    = $result['success']
					? __( 'All cache purged successfully.', 'flavor-edge-cache' )
					: __( 'Purge failed: ', 'flavor-edge-cache' ) . $result['message'];
				set_transient( 'flavor_edge_admin_notice', $msg, 30 );
				break;

			case 'purge_post':
				$post_id = (int) ( $_GET['flavor_edge_post'] ?? 0 );
				if ( $post_id ) {
					$tags   = array( 'post-' . $post_id );
					$result = TE_Api::purge_tags( $tags, true );
					$msg    = $result['success']
						? sprintf(
							/* translators: %d: Post ID */
							__( 'Cache purged for post #%d.', 'flavor-edge-cache' ),
							$post_id
						)
						: __( 'Purge failed: ', 'flavor-edge-cache' ) . $result['message'];
					set_transient( 'flavor_edge_admin_notice', $msg, 30 );
				}
				break;

			case 'reset_circuit':
				TE_Api::reset_circuit();
				$token = TE_Api::get_token( true );
				$msg   = $token
					? __( 'API connection restored successfully.', 'flavor-edge-cache' )
					: __( 'API connection still failing. Check your credentials and network.', 'flavor-edge-cache' );
				set_transient( 'flavor_edge_admin_notice', $msg, 30 );
				break;
		}

		// Redirect back to clean the URL.
		$redirect_url = remove_query_arg( array( 'flavor_edge_action', 'flavor_edge_post', '_wpnonce' ) );
		wp_safe_redirect( $redirect_url );
		exit;
	}
}
