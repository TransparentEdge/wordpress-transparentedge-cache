<?php
/**
 * Standalone test: uninstall removes the plugin's options, transients, meta,
 * scheduled events and cache directory — and nothing outside it.
 *
 * Run: php tests/uninstall.php
 *
 * @package flavor_edge_cache
 */

$base = sys_get_temp_dir() . '/fe-uninstall-' . getmypid();
@mkdir( $base . '/wp-content/cache/flavor-edge/min', 0777, true );
@mkdir( $base . '/wp-content/cache/other-plugin', 0777, true );
@mkdir( $base . '/outside', 0777, true );
file_put_contents( $base . '/wp-content/cache/flavor-edge/min/a.js', 'x' );
file_put_contents( $base . '/wp-content/cache/flavor-edge/nginx.conf', 'x' );
file_put_contents( $base . '/wp-content/cache/other-plugin/keep.txt', 'x' );
file_put_contents( $base . '/outside/keep.txt', 'x' );
@symlink( $base . '/outside', $base . '/wp-content/cache/flavor-edge/link' );

define( 'WP_UNINSTALL_PLUGIN', 'transparent-edge-cache/transparent-edge-cache.php' );
define( 'WP_CONTENT_DIR', $base . '/wp-content' );

class FakeWpdb {
	public $options = 'wp_options';
	public $sitemeta = 'wp_sitemeta';
	public $queries = array();
	public function esc_like( $s ) { return addcslashes( $s, '_%\\' ); }
	public function prepare( $q, ...$a ) { foreach ( $a as $v ) { $q = preg_replace( '/%s/', "'" . $v . "'", $q, 1 ); } return $q; }
	public function query( $q ) { $this->queries[] = $q; return 1; }
}
$GLOBALS['wpdb'] = new FakeWpdb();
$GLOBALS['__meta'] = array();
$GLOBALS['__hooks'] = array();
function is_multisite() { return false; }
function delete_post_meta_by_key( $k ) { $GLOBALS['__meta'][] = $k; return true; }
function wp_unschedule_hook( $h ) { $GLOBALS['__hooks'][] = $h; return 0; }
function wp_cache_flush() { return true; }

require __DIR__ . '/../uninstall.php';

$failures = 0;
function check( $label, $cond ) { global $failures; echo ( $cond ? '  PASS  ' : '  FAIL  ' ) . $label . "\n"; if ( ! $cond ) { $failures++; } }
$q = implode( "\n", $GLOBALS['wpdb']->queries );
check( 'options flavor_edge_* deleted (escaped LIKE)', false !== strpos( $q, "option_name LIKE 'flavor\\_edge\\_%'" ) );
check( 'transients deleted', false !== strpos( $q, "_transient\\_flavor\\_edge\\_%" ) && false !== strpos( $q, "_transient\\_timeout\\_flavor\\_edge\\_%" ) );
check( 'media signature meta deleted', in_array( '_fe_file_sig', $GLOBALS['__meta'], true ) );
check( 'scheduled events cleared (incl. old vuln scan)', in_array( 'flavor_edge_health_check', $GLOBALS['__hooks'], true ) && in_array( 'flavor_edge_vuln_scan', $GLOBALS['__hooks'], true ) );
check( 'cache directory removed', ! file_exists( $base . '/wp-content/cache/flavor-edge' ) );
check( 'other plugins\' cache untouched', file_exists( $base . '/wp-content/cache/other-plugin/keep.txt' ) );
check( 'symlink target NOT followed', file_exists( $base . '/outside/keep.txt' ) );

@unlink( $base . '/wp-content/cache/other-plugin/keep.txt' ); @rmdir( $base . '/wp-content/cache/other-plugin' ); @rmdir( $base . '/wp-content/cache' ); @rmdir( $base . '/wp-content' );
@unlink( $base . '/outside/keep.txt' ); @rmdir( $base . '/outside' ); @rmdir( $base );
echo $failures ? "\n$failures FAILED\n" : "\nALL TESTS PASSED\n";
exit( $failures ? 1 : 0 );
