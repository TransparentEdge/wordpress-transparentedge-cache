<?php
/**
 * Performance Dashboard module.
 *
 * Provides metrics for the admin panel dashboard tab:
 * - CDN hit ratio (from TE API if available, or local estimate)
 * - Object Cache statistics
 * - Minification cache stats
 * - Frontend optimization summary
 *
 * @package flavor_edge_cache
 */

namespace flavor_edge;

defined( 'ABSPATH' ) || exit;

class TE_Dashboard {

	/**
	 * Initialize dashboard hooks.
	 */
	public static function init() {
		if ( ! is_admin() ) {
			return;
		}

		// AJAX endpoint for dashboard data.
		add_action( 'wp_ajax_flavor_edge_dashboard_data', array( __CLASS__, 'ajax_dashboard_data' ) );

		// WordPress dashboard widget.
		add_action( 'wp_dashboard_setup', array( __CLASS__, 'register_dashboard_widget' ) );
	}

	/**
	 * Register a WordPress dashboard widget.
	 */
	public static function register_dashboard_widget() {
		if ( ! current_user_can( 'manage_options' ) || ! TE_Settings::is_connected() ) {
			return;
		}

		wp_add_dashboard_widget(
			'flavor_edge_dashboard',
			'⚡ ' . __( 'Transparent Edge Cache', 'flavor-edge-cache' ),
			array( __CLASS__, 'render_dashboard_widget' )
		);
	}

	/**
	 * Render the WordPress dashboard widget.
	 */
	public static function render_dashboard_widget() {
		$data = self::collect_all_metrics();
		?>
		<div class="te-dashboard-widget">
			<div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;">
				<div>
					<strong><?php esc_html_e( 'CDN Status', 'flavor-edge-cache' ); ?></strong><br>
					<?php
					$status_color = '#0073aa';
					if ( ! $data['cdn']['connected'] ) {
						$status_color = '#dc3232';
					} elseif ( ! empty( $data['cdn']['circuit']['open'] ) ) {
						$status_color = '#dba617';
					}
					?>
					<span style="font-size:24px;font-weight:700;color:<?php echo esc_attr( $status_color ); ?>;">
						<?php echo esc_html( $data['cdn']['status'] ); ?>
					</span>
				</div>
				<div>
					<strong><?php esc_html_e( 'Object Cache', 'flavor-edge-cache' ); ?></strong><br>
					<span style="font-size:24px;font-weight:700;color:<?php echo $data['object_cache']['enabled'] ? '#155724' : '#888'; ?>;">
						<?php echo $data['object_cache']['enabled'] ? esc_html( $data['object_cache']['hit_ratio'] . '%' ) : esc_html__( 'Off', 'flavor-edge-cache' ); ?>
					</span>
				</div>
				<div>
					<strong><?php esc_html_e( 'Minified Files', 'flavor-edge-cache' ); ?></strong><br>
					<?php echo esc_html( $data['minify']['files'] ); ?>
					<span style="color:#666;font-size:12px;">(<?php echo esc_html( size_format( $data['minify']['total_size'] ) ); ?>)</span>
				</div>
				<div>
					<strong><?php esc_html_e( 'Optimizations', 'flavor-edge-cache' ); ?></strong><br>
					<?php echo esc_html( $data['wpo']['active_count'] ); ?>
					<span style="color:#666;font-size:12px;"><?php esc_html_e( 'active', 'flavor-edge-cache' ); ?></span>
				</div>
			</div>
			<p style="margin:12px 0 0;text-align:right;">
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=flavor-edge-cache' ) ); ?>">
					<?php esc_html_e( 'Full Dashboard →', 'flavor-edge-cache' ); ?>
				</a>
			</p>
		</div>
		<?php
	}

	/**
	 * Collect all metrics for the dashboard.
	 *
	 * @return array
	 */
	public static function collect_all_metrics() {
		return array(
			'cdn'          => self::get_cdn_metrics(),
			'object_cache' => TE_ObjectCache::get_stats(),
			'minify'       => TE_Minify::get_cache_stats(),
			'wpo'          => self::get_wpo_summary(),
			'server'       => self::get_server_info(),
		);
	}

	/**
	 * Get CDN metrics.
	 *
	 * @return array
	 */
	private static function get_cdn_metrics() {
		$metrics = array(
			'status'        => 'Unknown',
			'connected'     => TE_Settings::is_connected(),
			'method'        => TE_Settings::get( 'invalidation_method', 'soft' ),
			'html_ttl'      => (int) TE_Settings::get( 'html_s_maxage', 172800 ),
			'static_ttl'    => (int) TE_Settings::get( 'static_s_maxage', 2592000 ),
			'dashboard_url' => TE_Settings::is_connected()
				? 'https://dashboard.transparentcdn.com/' . TE_Settings::get( 'company_id' ) . '/invalidation'
				: '',
			'circuit'       => TE_Api::get_circuit_status(),
			'health'        => TE_Api::get_health_status(),
		);

		if ( $metrics['connected'] ) {
			if ( $metrics['circuit']['open'] ) {
				$metrics['status'] = __( 'Degraded (API temporarily unavailable)', 'flavor-edge-cache' );
			} else {
				$metrics['status'] = __( 'Active', 'flavor-edge-cache' );
			}
		} else {
			$s = TE_Settings::get_all();
			if ( ! empty( $s['client_id'] ) && ! empty( $s['client_secret'] ) ) {
				$metrics['status'] = __( 'Disconnected (API unreachable)', 'flavor-edge-cache' );
			} else {
				$metrics['status'] = __( 'Not connected', 'flavor-edge-cache' );
			}
		}

		return $metrics;
	}

	/**
	 * Get WPO (Web Performance Optimization) summary.
	 *
	 * @return array
	 */
	private static function get_wpo_summary() {
		$s = TE_Settings::get_all();

		$features = array(
			'minify_html'           => array( 'label' => __( 'HTML Minification', 'flavor-edge-cache' ), 'on' => $s['minify_html'] ),
			'minify_css'            => array( 'label' => __( 'CSS Minification', 'flavor-edge-cache' ), 'on' => $s['minify_css'] ),
			'minify_js'             => array( 'label' => __( 'JS Minification', 'flavor-edge-cache' ), 'on' => $s['minify_js'] ),
			'delay_js'              => array( 'label' => __( 'Delay JS', 'flavor-edge-cache' ), 'on' => $s['delay_js'] ),
			'defer_js'              => array( 'label' => __( 'Defer JS', 'flavor-edge-cache' ), 'on' => $s['defer_js'] ),
			'lazyload_images'       => array( 'label' => __( 'Lazy Load Images', 'flavor-edge-cache' ), 'on' => $s['lazyload_images'] ),
			'lazyload_iframes'      => array( 'label' => __( 'Lazy Load Iframes', 'flavor-edge-cache' ), 'on' => $s['lazyload_iframes'] ),
			'preload_lcp'           => array( 'label' => __( 'Preload LCP', 'flavor-edge-cache' ), 'on' => $s['preload_lcp'] ),
			'selfhost_google_fonts' => array( 'label' => __( 'Self-host Google Fonts', 'flavor-edge-cache' ), 'on' => $s['selfhost_google_fonts'] ),
			'i3_enabled'            => array( 'label' => __( 'i3 Image Optimization', 'flavor-edge-cache' ), 'on' => $s['i3_enabled'] ),
			'preload_sitemap'       => array( 'label' => __( 'Sitemap Preload', 'flavor-edge-cache' ), 'on' => $s['preload_sitemap'] ),
		);

		$active = array_filter( $features, function( $f ) { return $f['on']; } );

		return array(
			'features'     => $features,
			'active_count' => count( $active ),
			'total_count'  => count( $features ),
		);
	}

	/**
	 * Get server environment info.
	 *
	 * @return array
	 */
	private static function get_server_info() {
		return array(
			'php_version'     => PHP_VERSION,
			'wordpress'       => get_bloginfo( 'version' ),
			'plugin_version'  => FLAVOR_EDGE_VERSION,
			'memory_limit'    => ini_get( 'memory_limit' ),
			'opcache'         => function_exists( 'opcache_get_status' ) && ! empty( opcache_get_status( false ) ),
			'redis'           => class_exists( 'Redis' ),
			'memcached'       => class_exists( 'Memcached' ),
			'apcu'            => function_exists( 'apcu_enabled' ) && apcu_enabled(),
		);
	}

	/**
	 * AJAX handler for dashboard data.
	 */
	public static function ajax_dashboard_data() {
		check_ajax_referer( 'flavor_edge_admin', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( 'Unauthorized' );
		}

		wp_send_json_success( self::collect_all_metrics() );
	}
}
