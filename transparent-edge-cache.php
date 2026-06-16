<?php
/**
 * Plugin Name:       Transparent Edge Cache
 * Plugin URI:        https://www.transparentedge.eu/
 * Description:       Plugin de caché y optimización para Transparent Edge CDN. Invalidación inteligente por Surrogate-Keys, Soft Purge, Refetch, optimización de imágenes i3 y control avanzado de headers HTTP para Varnish Enterprise.
 * Version:           1.3.1
 * Requires at least: 5.8
 * Requires PHP:      7.4
 * Author:            Transparent Edge Services
 * Author URI:        https://www.transparentedge.eu/
 * License:           GPL v2 or later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       flavor-edge-cache
 * Domain Path:       /languages
 *
 * @package flavor_edge_cache
 */

defined( 'ABSPATH' ) || exit;

// Plugin constants.
define( 'FLAVOR_EDGE_VERSION', '1.3.1' );
define( 'FLAVOR_EDGE_FILE', __FILE__ );
define( 'FLAVOR_EDGE_DIR', plugin_dir_path( __FILE__ ) );
define( 'FLAVOR_EDGE_URL', plugin_dir_url( __FILE__ ) );
define( 'FLAVOR_EDGE_BASENAME', plugin_basename( __FILE__ ) );

// API constants.
define( 'FLAVOR_EDGE_API_BASE', 'https://api.transparentcdn.com' );
define( 'FLAVOR_EDGE_API_AUTH', FLAVOR_EDGE_API_BASE . '/v1/oauth2/access_token/' );

/**
 * Autoloader for plugin classes.
 */
spl_autoload_register( function ( $class ) {
	$prefix = 'flavor_edge\\';
	$len    = strlen( $prefix );

	if ( strncasecmp( $prefix, $class, $len ) !== 0 ) {
		return;
	}

	$relative = substr( $class, $len );
	$relative = str_replace( '\\', '/', $relative );

	// Map class names to file paths.
	$paths = array(
		FLAVOR_EDGE_DIR . 'includes/' . $relative . '.php',
		FLAVOR_EDGE_DIR . 'includes/modules/' . $relative . '.php',
		FLAVOR_EDGE_DIR . 'includes/admin/' . $relative . '.php',
		FLAVOR_EDGE_DIR . 'includes/speculation/' . $relative . '.php',
	);

	foreach ( $paths as $path ) {
		if ( file_exists( $path ) ) {
			require_once $path;
			return;
		}
	}
});

/**
 * Initialize the plugin.
 */
function flavor_edge_init() {
	// Load text domain.
	load_plugin_textdomain( 'flavor-edge-cache', false, dirname( FLAVOR_EDGE_BASENAME ) . '/languages' );

	// Auto-clear minify cache on plugin update.
	$stored_version = get_option( 'flavor_edge_version', '0' );
	if ( $stored_version !== FLAVOR_EDGE_VERSION ) {
		update_option( 'flavor_edge_version', FLAVOR_EDGE_VERSION );
		if ( class_exists( 'flavor_edge\\TE_Minify' ) ) {
			\flavor_edge\TE_Minify::clear_cache();
		}
		// Clear minify dir directly as fallback.
		$cache_dir = WP_CONTENT_DIR . '/cache/flavor-edge/min';
		if ( is_dir( $cache_dir ) ) {
			$files = glob( $cache_dir . '/*.{css,js}', GLOB_BRACE );
			if ( $files ) {
				foreach ( $files as $f ) {
					@unlink( $f );
				}
			}
		}
	}

	// Boot the core.
	\flavor_edge\TE_Core::instance();

	// Schedule periodic health check.
	\flavor_edge\TE_Api::schedule_health_check();
}
add_action( 'plugins_loaded', 'flavor_edge_init' );

/**
 * Register custom cron schedule (30 minutes).
 *
 * @param array $schedules Existing schedules.
 * @return array
 */
function flavor_edge_cron_schedules( $schedules ) {
	$schedules['flavor_edge_30min'] = array(
		'interval' => 1800,
		'display'  => __( 'Every 30 minutes (Transparent Edge)', 'flavor-edge-cache' ),
	);
	return $schedules;
}
add_filter( 'cron_schedules', 'flavor_edge_cron_schedules' );

// Register health check cron action.
add_action( \flavor_edge\TE_Api::HEALTH_CRON_HOOK, array( 'flavor_edge\\TE_Api', 'run_health_check' ) );

/**
 * Activation hook.
 *
 * @param bool $network_wide Whether the plugin is being network-activated.
 */
function flavor_edge_activate( $network_wide = false ) {
	if ( is_multisite() && $network_wide ) {
		// Network activation: run on each site.
		$sites = get_sites( array( 'number' => 1000, 'fields' => 'ids' ) );
		foreach ( $sites as $blog_id ) {
			switch_to_blog( $blog_id );
			flavor_edge_activate_single();
			restore_current_blog();
		}
	} else {
		flavor_edge_activate_single();
	}
}

/**
 * Single-site activation tasks.
 */
function flavor_edge_activate_single() {
	// Set default options if not present.
	if ( false === get_option( 'flavor_edge_settings' ) ) {
		update_option( 'flavor_edge_settings', \flavor_edge\TE_Settings::defaults() );
	}

	// Clean up legacy purge log table (no longer needed — TE dashboard has the log).
	flavor_edge_drop_legacy_tables();

	// Write browser cache .htaccess rules.
	if ( class_exists( 'flavor_edge\\TE_BrowserCache' ) ) {
		\flavor_edge\TE_BrowserCache::update_htaccess();
	}

	// Flush rewrite rules.
	flush_rewrite_rules();
}
register_activation_hook( __FILE__, 'flavor_edge_activate' );

/**
 * Drop legacy purge log table (replaced by TE dashboard).
 */
function flavor_edge_drop_legacy_tables() {
	global $wpdb;
	$table = $wpdb->prefix . 'te_purge_log';
	$wpdb->query( "DROP TABLE IF EXISTS $table" ); // phpcs:ignore
}

/**
 * Deactivation hook.
 *
 * @param bool $network_wide Whether the plugin is being network-deactivated.
 */
function flavor_edge_deactivate( $network_wide = false ) {
	if ( is_multisite() && $network_wide ) {
		$sites = get_sites( array( 'number' => 1000, 'fields' => 'ids' ) );
		foreach ( $sites as $blog_id ) {
			switch_to_blog( $blog_id );
			flavor_edge_deactivate_single();
			restore_current_blog();
		}
	} else {
		flavor_edge_deactivate_single();
	}
}

/**
 * Single-site deactivation tasks.
 */
function flavor_edge_deactivate_single() {
	wp_clear_scheduled_hook( 'flavor_edge_flush_queue' );
	wp_clear_scheduled_hook( 'flavor_edge_preload_batch' );
	wp_clear_scheduled_hook( 'flavor_edge_warmup_process' );
	wp_clear_scheduled_hook( \flavor_edge\TE_Api::HEALTH_CRON_HOOK );

	// Remove .htaccess rules.
	if ( class_exists( 'flavor_edge\\TE_BrowserCache' ) ) {
		\flavor_edge\TE_BrowserCache::remove_htaccess();
	}

	flush_rewrite_rules();
}
register_deactivation_hook( __FILE__, 'flavor_edge_deactivate' );

/**
 * When a new site is created in a multisite network, run activation.
 */
add_action( 'wp_initialize_site', function( $site ) {
	if ( ! is_plugin_active_for_network( FLAVOR_EDGE_BASENAME ) ) {
		return;
	}
	switch_to_blog( $site->blog_id );
	flavor_edge_activate_single();
	restore_current_blog();
}, 20 );
