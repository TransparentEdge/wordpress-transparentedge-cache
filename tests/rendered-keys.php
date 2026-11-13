<?php
/**
 * Standalone test: keys for posts rendered outside the main query (page
 * builders, Query Loop / Latest Posts blocks, widgets).
 *
 * Run: php tests/rendered-keys.php
 *
 * @package flavor_edge_cache
 */

namespace flavor_edge;

define( 'ABSPATH', __DIR__ );
ob_start(); // CLI: keep headers_sent() false, as during a buffered page render.

class TE_Settings { public static function get_all() { return array(); } public static function get( $k, $d = null ) { return $d; } }
function apply_filters( $tag, $value ) { return $value; }
function sanitize_key( $k ) { return strtolower( preg_replace( '/[^a-z0-9_\-]/i', '', $k ) ); }
$GLOBALS['__ctx'] = array( 'page' => true, 'singular' => true, 'qid' => 31 );
function is_page() { return $GLOBALS['__ctx']['page']; }
function is_singular() { return $GLOBALS['__ctx']['singular']; }
function get_queried_object_id() { return $GLOBALS['__ctx']['qid']; }
$GLOBALS['__opt'] = array();
function get_option( $k, $d = false ) { return $GLOBALS['__opt'][ $k ] ?? $d; }
function update_option( $k, $v, $a = null ) { $GLOBALS['__opt'][ $k ] = $v; $GLOBALS['__writes'] = ( $GLOBALS['__writes'] ?? 0 ) + 1; return true; }
eval( 'class WP_Query { public $posts = array(); public $main = false; function is_main_query() { return $this->main; } }' );

require __DIR__ . '/../includes/modules/TE_Headers.php';

$failures = 0;
function check( $label, $cond ) { global $failures; echo ( $cond ? '  PASS  ' : '  FAIL  ' ) . $label . "\n"; if ( ! $cond ) { $failures++; } }
function prop( $name, $value = null ) {
	$p = new \ReflectionProperty( __NAMESPACE__ . '\\TE_Headers', $name );
	$p->setAccessible( true );
	if ( func_num_args() > 1 ) { $p->setValue( null, $value ); }
	return $p->getValue();
}
function reset_state( $collecting = true ) {
	prop( 'rendered_keys', array() );
	prop( 'surrogate_keys', array( 'site-1', 'post-31', 'type-page' ) );
	prop( 'collecting', $collecting );
}
function post( $id, $type = 'post' ) { return (object) array( 'ID' => $id, 'post_type' => $type ); }

$main = new \WP_Query(); $main->main = true;
$GLOBALS['wp_query'] = $main;
$sec = new \WP_Query();

echo "== page with a builder post list ==\n";
reset_state();
TE_Headers::note_rendered_post( post( 31, 'page' ), $main ); // the page itself
TE_Headers::note_rendered_post( post( 101 ), $sec );
TE_Headers::note_rendered_post( post( 102 ), $main );         // setup_postdata() on get_posts(): reports main query
$k = array_keys( prop( 'rendered_keys' ) );
check( 'queried page itself is not added', ! in_array( 'post-31', $k, true ) );
check( 'secondary-loop post keyed', in_array( 'post-101', $k, true ) );
check( 'setup_postdata()/get_posts post keyed despite main $query', in_array( 'post-102', $k, true ) );
check( 'page gets lst-type-post', in_array( 'lst-type-post', $k, true ) );
$out = TE_Headers::flush_rendered_keys( '<html>' );
check( 'output untouched', '<html>' === $out );
$sk = prop( 'surrogate_keys' );
check( 'header keys merged', in_array( 'lst-type-post', $sk, true ) && in_array( 'post-101', $sk, true ) && in_array( 'site-1', $sk, true ) );
check( 'page remembered for the origin page cache', array( 31 ) === TE_Headers::listing_pages_for( 'post' ) );
$w = $GLOBALS['__writes'];
reset_state(); TE_Headers::note_rendered_post( post( 101 ), $sec ); TE_Headers::flush_rendered_keys( '' );
check( 'already-known page → no option write', $w === $GLOBALS['__writes'] );

echo "== single post with related posts ==\n";
$GLOBALS['__ctx'] = array( 'page' => false, 'singular' => true, 'qid' => 500 );
reset_state();
TE_Headers::note_rendered_post( post( 500 ), $main );
TE_Headers::note_rendered_post( post( 501 ), $sec );
$k = array_keys( prop( 'rendered_keys' ) );
check( 'related post keyed', in_array( 'post-501', $k, true ) );
check( 'NO lst-type on a single (publish would purge every article)', ! in_array( 'lst-type-post', $k, true ) );

echo "== archive ==\n";
$GLOBALS['__ctx'] = array( 'page' => false, 'singular' => false, 'qid' => 7 );
$main->posts = array( post( 600 ), post( 601 ) );
reset_state();
TE_Headers::note_rendered_post( post( 600 ), $main );
TE_Headers::note_rendered_post( post( 700 ), $sec );
$k = array_keys( prop( 'rendered_keys' ) );
check( 'main-loop post skipped (already keyed)', ! in_array( 'post-600', $k, true ) );
check( 'widget post keyed', in_array( 'post-700', $k, true ) );

echo "== guards ==\n";
reset_state( false );
TE_Headers::note_rendered_post( post( 800 ), $sec );
check( 'nothing collected outside a cacheable front-end request', array() === prop( 'rendered_keys' ) );
$GLOBALS['__ctx'] = array( 'page' => true, 'singular' => true, 'qid' => 9 );
reset_state();
TE_Headers::note_latest_posts_block( 'x' );
check( 'Latest Posts block on a page → lst-type-post', isset( prop( 'rendered_keys' )['lst-type-post'] ) );

echo $failures ? "\n$failures FAILED\n" : "\nALL TESTS PASSED\n";
exit( $failures ? 1 : 0 );
