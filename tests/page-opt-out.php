<?php
/**
 * Standalone test: a page that opted out of caching is never made public.
 *
 * Run: php tests/page-opt-out.php
 *
 * @package flavor_edge_cache
 */

namespace flavor_edge;

define( 'ABSPATH', __DIR__ . '/' );
function apply_filters( $tag, $value ) { return $value; }
class TE_Settings { public static function get( $k, $d = null ) { return $d; } public static function get_all() { return array(); } public static function is_module_enabled( $m ) { return true; } }
require __DIR__ . '/../includes/modules/TE_Headers.php';

$failures = 0;
function check( $label, $cond ) { global $failures; echo ( $cond ? '  PASS  ' : '  FAIL  ' ) . $label . "\n"; if ( ! $cond ) { $failures++; } }

check( 'no Cache-Control yet → cacheable', '' === TE_Headers::opt_out_reason( array( 'Content-Type: text/html' ) ) );
check( 'public Cache-Control → cacheable', '' === TE_Headers::opt_out_reason( array( 'Cache-Control: public, max-age=60' ) ) );
check( 'nocache_headers() → opted out', '' !== TE_Headers::opt_out_reason( array( 'Cache-Control: no-cache, must-revalidate, max-age=0, no-store, private' ) ) );
check( 'PHP session limiter (no-store) → opted out', '' !== TE_Headers::opt_out_reason( array( 'Cache-Control: no-store, no-cache, must-revalidate' ) ) );
check( 'private → opted out', '' !== TE_Headers::opt_out_reason( array( 'Cache-Control: private' ) ) );
check( 'max-age=0 → opted out', '' !== TE_Headers::opt_out_reason( array( 'Cache-Control: max-age=0' ) ) );
check( 'max-age=0300 is not max-age=0', '' === TE_Headers::opt_out_reason( array( 'Cache-Control: public, max-age=0300' ) ) );
check( 's-maxage=60 alone → cacheable', '' === TE_Headers::opt_out_reason( array( 'Cache-Control: public, s-maxage=60' ) ) );
define( 'DONOTCACHEPAGE', true );
check( 'DONOTCACHEPAGE → opted out', 'DONOTCACHEPAGE' === TE_Headers::opt_out_reason( array() ) );

echo $failures ? "\n$failures FAILED\n" : "\nALL TESTS PASSED\n";
exit( $failures ? 1 : 0 );
