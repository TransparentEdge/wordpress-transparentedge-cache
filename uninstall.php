<?php
/**
 * Uninstall: runs when the plugin is DELETED from wp-admin (not on
 * deactivation). Deactivation has already removed the drop-ins, the .htaccess
 * rules and the WP_CACHE define; this removes what the plugin stored: its
 * options and transients (API credentials included), the media signatures, any
 * scheduled event left behind, and the cache directory it created under
 * wp-content/cache/flavor-edge/ (minified files, fonts, page cache, configs).
 *
 * @package flavor_edge_cache
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

/**
 * Remove everything stored for the current site.
 */
function flavor_edge_uninstall_site() {
	global $wpdb;

	// Options and transients: every name the plugin uses starts with flavor_edge_.
	$like = $wpdb->esc_like( 'flavor_edge_' ) . '%';
	$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s", $like ) ); // phpcs:ignore
	$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s", $wpdb->esc_like( '_transient_flavor_edge_' ) . '%', $wpdb->esc_like( '_transient_timeout_flavor_edge_' ) . '%' ) ); // phpcs:ignore

	// Media file signatures used to detect replaced attachments.
	delete_post_meta_by_key( '_fe_file_sig' );

	// Scheduled events, including ones from earlier versions.
	foreach ( array(
		'flavor_edge_flush_queue', 'flavor_edge_upgrade_purge', 'flavor_edge_preload_batch',
		'flavor_edge_warmup_process', 'flavor_edge_health_check', 'flavor_edge_generate_ucss',
		'flavor_edge_minify_gc', 'flavor_edge_pc_trash_gc', 'flavor_edge_gf_build', 'flavor_edge_vuln_scan',
	) as $hook ) {
		wp_unschedule_hook( $hook );
	}

	wp_cache_flush();
}

/**
 * Delete a directory tree (no symlink following).
 *
 * @param string $dir Directory.
 */
function flavor_edge_uninstall_rmdir( $dir ) {
	if ( ! is_dir( $dir ) || is_link( $dir ) ) {
		return;
	}
	$it = new RecursiveIteratorIterator(
		new RecursiveDirectoryIterator( $dir, FilesystemIterator::SKIP_DOTS ),
		RecursiveIteratorIterator::CHILD_FIRST
	);
	foreach ( $it as $item ) {
		if ( $item->isDir() && ! $item->isLink() ) {
			@rmdir( $item->getPathname() );
		} else {
			@unlink( $item->getPathname() );
		}
	}
	@rmdir( $dir );
}

if ( is_multisite() ) {
	$flavor_edge_sites = get_sites( array( 'fields' => 'ids', 'number' => 0 ) );
	foreach ( $flavor_edge_sites as $flavor_edge_blog ) {
		switch_to_blog( $flavor_edge_blog );
		flavor_edge_uninstall_site();
		restore_current_blog();
	}
	// Network-level settings and site transients.
	global $wpdb;
	$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->sitemeta} WHERE meta_key LIKE %s OR meta_key LIKE %s OR meta_key LIKE %s", $wpdb->esc_like( 'flavor_edge_' ) . '%', $wpdb->esc_like( '_site_transient_flavor_edge_' ) . '%', $wpdb->esc_like( '_site_transient_timeout_flavor_edge_' ) . '%' ) ); // phpcs:ignore
} else {
	flavor_edge_uninstall_site();
}

// The plugin's own cache directory (shared by all sites of a network).
flavor_edge_uninstall_rmdir( WP_CONTENT_DIR . '/cache/flavor-edge' );
