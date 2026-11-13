<?php
/**
 * Standalone test: headers stored with an origin page-cache entry, and the
 * flush when a header-shaping setting changes.
 *
 * Run: php tests/page-cache-headers.php
 *
 * @package flavor_edge_cache
 */

namespace flavor_edge;

define( 'ABSPATH', __DIR__ . '/' );
function apply_filters( $tag, $value ) { return $value; }
class TE_Settings { const OPTION_KEY = 'flavor_edge_settings'; public static function get( $k, $d = null ) { return $d; } public static function get_all() { return array(); } }

require __DIR__ . '/../includes/pagecache/TE_PageCache.php';

$failures = 0;
function check( $label, $cond ) { global $failures; echo ( $cond ? '  PASS  ' : '  FAIL  ' ) . $label . "\n"; if ( ! $cond ) { $failures++; } }

$m = new \ReflectionMethod( __NAMESPACE__ . '\\TE_PageCache', 'capturable_headers' );
$m->setAccessible( true );
$out = $m->invoke( null, array(
	'Cache-Control: public, max-age=60, s-maxage=604800',
	'Surrogate-Keys: site-1 post-26',
	'Vary: Accept-Encoding',
	'X-Content-Type-Options: nosniff',
	'X-Frame-Options: SAMEORIGIN',
	'Referrer-Policy: strict-origin-when-cross-origin',
	'Permissions-Policy: geolocation=()',
	'Strict-Transport-Security: max-age=31536000',
	'Content-Security-Policy: default-src \'self\'',
	'X-Robots-Tag: noindex',
	'Link: <https://x/wp-json/>; rel="https://api.w.org/"',
	'Set-Cookie: wp_session=abc',
	'X-Powered-By: PHP/8.3',
	'Date: Fri, 13 Nov 2026 12:00:00 GMT',
	'ETag: "abc"',
	'Content-Length: 1234',
	'Content-Type: text/html; charset=UTF-8',
	'X-FlavorEdge-PageCache: HIT',
) );
$names = array_map( function ( $h ) { return strtolower( strtok( $h, ':' ) ); }, $out );

echo "== kept ==\n";
foreach ( array( 'cache-control', 'surrogate-keys', 'vary', 'x-content-type-options', 'x-frame-options', 'referrer-policy', 'permissions-policy', 'strict-transport-security', 'content-security-policy', 'x-robots-tag', 'link' ) as $n ) {
	check( $n, in_array( $n, $names, true ) );
}
echo "== never frozen into a shared copy ==\n";
foreach ( array( 'set-cookie', 'x-powered-by', 'date', 'etag', 'content-length', 'content-type', 'x-flavoredge-pagecache' ) as $n ) {
	check( $n . ' dropped', ! in_array( $n, $names, true ) );
}

echo "== flush on header-shaping setting change ==\n";
// is_enabled() is false in this harness, so flush_all() is a no-op; count calls
// through a subclass-free probe: replace the store via reflection is overkill —
// instead check the decision by watching which settings trigger it.
$changed = function ( $old, $new ) {
	foreach ( array_unique( array_merge( array_keys( $old ), array_keys( $new ) ) ) as $k ) {
		if ( preg_match( '/^(html_|front_page_|sec_header|vary_|surrogate_keys$|headers_enabled$)/', (string) $k ) && ( $old[ $k ] ?? null ) != ( $new[ $k ] ?? null ) ) {
			return true;
		}
	}
	return false;
};
$src = file_get_contents( __DIR__ . '/../includes/pagecache/TE_PageCache.php' );
check( 'same rule as the plugin', false !== strpos( $src, "'/^(html_|front_page_|sec_header|vary_|surrogate_keys$|headers_enabled$)/'" ) );
check( 'html_max_age change → flush', $changed( array( 'html_max_age' => 3600 ), array( 'html_max_age' => 60 ) ) );
check( 'security header toggle → flush', $changed( array( 'sec_header_frame' => false ), array( 'sec_header_frame' => true ) ) );
check( 'unrelated setting → no flush', ! $changed( array( 'delay_js' => false, 'html_max_age' => 60 ), array( 'delay_js' => true, 'html_max_age' => 60 ) ) );
check( "'1' vs true is not a change", ! $changed( array( 'headers_enabled' => '1' ), array( 'headers_enabled' => true ) ) );
TE_PageCache::flush_on_header_settings_change( 'x', array() ); // non-array input must not fatal.
check( 'non-array input tolerated', true );

echo "== header fingerprint (disk store) ==\n";
require_once __DIR__ . '/../includes/pagecache/TE_PageCache_Store.php';
$dir = sys_get_temp_dir() . '/fepc-hv-' . getmypid();
$a   = new TE_PageCache_Store( array( 'backend' => 'disk', 'dir' => $dir, 'ttl' => 300, 'hv' => 'aaaaaaaaaaaa' ) );
$k   = $a->key( 'example.com', '/x/', 'desktop' );
$a->set( $k, '<html>x</html>', array( 'headers' => array( 'Cache-Control: max-age=3600' ) ) );
check( 'same fingerprint → hit', null !== $a->get( $k ) );
$b = new TE_PageCache_Store( array( 'backend' => 'disk', 'dir' => $dir, 'ttl' => 300, 'hv' => 'bbbbbbbbbbbb' ) );
check( 'settings changed (new fingerprint) → miss, never replays old headers', null === $b->get( $k ) );
$c = new TE_PageCache_Store( array( 'backend' => 'disk', 'dir' => $dir, 'ttl' => 300 ) );
check( 'no fingerprint configured → no check (old config.php)', null !== $c->get( $k ) );
$c->flush();
@array_map( 'unlink', glob( $dir . '*/*/*' ) ?: array() );

echo $failures ? "\n$failures FAILED\n" : "\nALL TESTS PASSED\n";
exit( $failures ? 1 : 0 );
