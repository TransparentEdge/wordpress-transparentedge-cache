<?php
/**
 * Standalone regression test for the origin page-cache storage engine (disk).
 *
 * Covers the key algorithm (device split, query-order independence) and the
 * disk backend round-trip, expiry and flush. Redis/Memcached paths need a live
 * service and are exercised in integration, not here.
 *
 * No PHPUnit required. Run:  php tests/page-cache-store.php
 *
 * @package flavor_edge_cache
 */

namespace flavor_edge;

define( 'ABSPATH', __DIR__ );

require __DIR__ . '/../includes/pagecache/TE_PageCache_Store.php';

$failures = 0;
function check( $label, $cond ) {
	global $failures;
	if ( $cond ) {
		echo "  PASS  $label\n";
	} else {
		echo "  FAIL  $label\n";
		$failures++;
	}
}

$dir = sys_get_temp_dir() . '/fe-pagecache-test-' . getmypid();
@mkdir( $dir, 0755, true );

$store = new TE_PageCache_Store( array( 'backend' => 'disk', 'dir' => $dir, 'ttl' => 300 ) );

echo "== key ==\n";
$k1 = $store->key( 'www.example.com', '/noticias/', 'desktop' );
$k2 = $store->key( 'www.example.com', '/noticias/', 'desktop' );
$k3 = $store->key( 'www.example.com', '/noticias/', 'mobile' );
check( 'stable for same inputs', $k1 === $k2 );
check( 'device changes the key (desktop != mobile)', $k1 !== $k3 );
check( 'key is 64-char hex', 1 === preg_match( '/^[0-9a-f]{64}$/', $k1 ) );

$q1 = $store->key( 'h', '/p?b=2&a=1', 'desktop' );
$q2 = $store->key( 'h', '/p?a=1&b=2', 'desktop' );
check( 'query order does not matter', $q1 === $q2 );

$host1 = $store->key( 'HOST', '/p', 'd' );
$host2 = $store->key( 'host', '/p', 'd' );
check( 'host is case-insensitive', $host1 === $host2 );

echo "== disk round-trip ==\n";
$key  = $store->key( 'www.example.com', '/', 'desktop' );
$html = '<!doctype html><html><body>portada</body></html>';
check( 'set returns true', true === $store->set( $key, $html, array( 'ctype' => 'text/html; charset=UTF-8' ) ) );

$hit = $store->get( $key );
check( 'get returns an array', is_array( $hit ) );
check( 'html round-trips intact', isset( $hit['html'] ) && $hit['html'] === $html );
check( 'ctype round-trips', isset( $hit['ctype'] ) && 'text/html; charset=UTF-8' === $hit['ctype'] );

check( 'miss on unknown key returns null', null === $store->get( $store->key( 'www.example.com', '/nope', 'desktop' ) ) );

echo "== expiry ==\n";
// Write an entry whose created timestamp is well past the TTL, at the exact
// path the store uses, and confirm get() treats it as a miss and removes it.
$ekey  = $store->key( 'www.example.com', '/old', 'desktop' );
$epath = $dir . '/' . substr( $ekey, 0, 2 ) . '/' . $ekey . '.cache';
@mkdir( dirname( $epath ), 0755, true );
file_put_contents( $epath, serialize( array(
	'v'       => TE_PageCache_Store::SCHEMA,
	'created' => time() - 10000,
	'ctype'   => 'text/html; charset=UTF-8',
	'html'    => 'stale',
) ) );
check( 'expired entry is a miss', null === $store->get( $ekey ) );
check( 'expired entry is swept from disk', ! file_exists( $epath ) );

echo "== delete + flush ==\n";
$store->set( $key, $html );
check( 'delete removes the entry', true === $store->delete( $key ) && null === $store->get( $key ) );

$store->set( $store->key( 'h', '/a', 'desktop' ), 'a' );
$store->set( $store->key( 'h', '/b', 'mobile' ), 'b' );
$store->flush();
check( 'flush empties the cache', null === $store->get( $store->key( 'h', '/a', 'desktop' ) )
	&& null === $store->get( $store->key( 'h', '/b', 'mobile' ) ) );

echo "== headers round-trip ==\n";
$hkey = $store->key( 'www.example.com', '/con-cabeceras', 'desktop' );
$store->set( $hkey, '<html></html>', array(
	'ctype'   => 'text/html; charset=UTF-8',
	'headers' => array( 'Cache-Control: public, s-maxage=300', 'Vary: Accept-Encoding, X-Device' ),
) );
$hhit = $store->get( $hkey );
check( 'headers stored and returned', isset( $hhit['headers'] ) && in_array( 'Cache-Control: public, s-maxage=300', $hhit['headers'], true ) );
check( 'Vary header survives', in_array( 'Vary: Accept-Encoding, X-Device', $hhit['headers'], true ) );

echo "== detect_device ==\n";
check( 'X-Device header wins (app)', 'app' === TE_PageCache_Store::detect_device( array( 'HTTP_X_DEVICE' => 'app' ) ) );
check( 'tablet folds into mobile', 'mobile' === TE_PageCache_Store::detect_device( array( 'HTTP_X_DEVICE' => 'tablet' ) ) );
check( 'UA fallback → mobile', 'mobile' === TE_PageCache_Store::detect_device( array( 'HTTP_USER_AGENT' => 'Mozilla/5.0 (iPhone)' ) ) );
check( 'default desktop', 'desktop' === TE_PageCache_Store::detect_device( array( 'HTTP_USER_AGENT' => 'Mozilla/5.0 (Windows NT 10.0)' ) ) );

echo "== effective_uri ==\n";
$ignored = array( 'utm_source', 'utm_medium', 'fbclid' );
check( 'ignored marketing params dropped',
	'/noticias/' === TE_PageCache_Store::effective_uri( '/noticias/?utm_source=x&fbclid=y', $ignored ) );
check( 'real param kept + sorted',
	'/l/?a=1&page=2' === TE_PageCache_Store::effective_uri( '/l/?page=2&a=1&utm_medium=z', $ignored ) );

echo "== request_is_cacheable ==\n";
$cfg = array(
	'excluded_urls'          => array( '^/mi-cuenta' ),
	'excluded_cookies'       => array( 'wordpress_logged_in_', 'woocommerce_items_in_cart' ),
	'accepted_query_strings' => array( 'utm_source', 'fbclid' ),
);
check( 'plain GET to / is cacheable',
	true === TE_PageCache_Store::request_is_cacheable( $cfg, array( 'REQUEST_METHOD' => 'GET', 'REQUEST_URI' => '/' ), array() ) );
check( 'POST is not cacheable',
	false === TE_PageCache_Store::request_is_cacheable( $cfg, array( 'REQUEST_METHOD' => 'POST', 'REQUEST_URI' => '/' ), array() ) );
check( 'wp-admin is not cacheable',
	false === TE_PageCache_Store::request_is_cacheable( $cfg, array( 'REQUEST_METHOD' => 'GET', 'REQUEST_URI' => '/wp-admin/' ), array() ) );
check( 'wp-json is not cacheable',
	false === TE_PageCache_Store::request_is_cacheable( $cfg, array( 'REQUEST_METHOD' => 'GET', 'REQUEST_URI' => '/wp-json/wp/v2/posts' ), array() ) );
check( 'logged-in cookie → not cacheable',
	false === TE_PageCache_Store::request_is_cacheable( $cfg, array( 'REQUEST_METHOD' => 'GET', 'REQUEST_URI' => '/' ), array( 'wordpress_logged_in_abc' => '1' ) ) );
check( 'excluded URL → not cacheable',
	false === TE_PageCache_Store::request_is_cacheable( $cfg, array( 'REQUEST_METHOD' => 'GET', 'REQUEST_URI' => '/mi-cuenta/pedidos' ), array() ) );
check( 'only marketing query → still cacheable',
	true === TE_PageCache_Store::request_is_cacheable( $cfg, array( 'REQUEST_METHOD' => 'GET', 'REQUEST_URI' => '/?utm_source=x&fbclid=y' ), array() ) );
check( 'real residual query → not cacheable (pollution guard)',
	false === TE_PageCache_Store::request_is_cacheable( $cfg, array( 'REQUEST_METHOD' => 'GET', 'REQUEST_URI' => '/?s=buscar' ), array() ) );

echo "== review fixes: host allowlist / GET-only ==\n";
$cfgh = array(
	'excluded_urls'          => array(),
	'excluded_cookies'       => array(),
	'accepted_query_strings' => array(),
	'hosts'                  => array( 'www.example.com' ),
);
check( 'known host is cacheable',
	true === TE_PageCache_Store::request_is_cacheable( $cfgh, array( 'REQUEST_METHOD' => 'GET', 'REQUEST_URI' => '/', 'HTTP_HOST' => 'www.example.com' ), array() ) );
check( 'spoofed host rejected (anti disk-fill)',
	false === TE_PageCache_Store::request_is_cacheable( $cfgh, array( 'REQUEST_METHOD' => 'GET', 'REQUEST_URI' => '/', 'HTTP_HOST' => 'evil.attacker.test' ), array() ) );
check( 'HEAD is no longer cacheable',
	false === TE_PageCache_Store::request_is_cacheable( $cfgh, array( 'REQUEST_METHOD' => 'HEAD', 'REQUEST_URI' => '/', 'HTTP_HOST' => 'www.example.com' ), array() ) );
check( 'empty host allowlist → not enforced',
	true === TE_PageCache_Store::request_is_cacheable( array( 'hosts' => array() ), array( 'REQUEST_METHOD' => 'GET', 'REQUEST_URI' => '/', 'HTTP_HOST' => 'anything' ), array() ) );

echo "== review fixes: per-entry size cap ==\n";
$capped = new TE_PageCache_Store( array( 'backend' => 'disk', 'dir' => $dir, 'ttl' => 300, 'max_bytes' => 100 ) );
$ckey = $capped->key( 'h', '/big', 'desktop' );
check( 'oversized entry is not stored', false === $capped->set( $ckey, str_repeat( 'x', 200 ) ) );
check( 'small entry under the cap is stored', true === $capped->set( $ckey, 'small' ) );

echo "== review fixes: cache_variant (device + language) ==\n";
$noLang = array( 'vary_language' => false );
$yesLang = array( 'vary_language' => true );
check( 'without vary_language → device only',
	'desktop' === TE_PageCache_Store::cache_variant( $noLang, array( 'HTTP_ACCEPT_LANGUAGE' => 'es-ES,es;q=0.9' ) ) );
check( 'with vary_language → device|lang',
	'desktop|es-es' === TE_PageCache_Store::cache_variant( $yesLang, array( 'HTTP_ACCEPT_LANGUAGE' => 'es-ES,es;q=0.9' ) ) );
check( 'different language → different variant',
	TE_PageCache_Store::cache_variant( $yesLang, array( 'HTTP_ACCEPT_LANGUAGE' => 'en-US' ) )
	!== TE_PageCache_Store::cache_variant( $yesLang, array( 'HTTP_ACCEPT_LANGUAGE' => 'es-ES' ) ) );

// Cleanup.
$it = new \RecursiveIteratorIterator(
	new \RecursiveDirectoryIterator( $dir, \FilesystemIterator::SKIP_DOTS ),
	\RecursiveIteratorIterator::CHILD_FIRST
);
foreach ( $it as $item ) {
	$item->isDir() ? @rmdir( $item->getPathname() ) : @unlink( $item->getPathname() );
}
@rmdir( $dir );

echo "\n" . ( $failures ? "$failures FAILURE(S)\n" : "ALL TESTS PASSED\n" );
exit( $failures ? 1 : 0 );
