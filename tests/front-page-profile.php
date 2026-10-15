<?php
/**
 * Standalone regression test for the front-page cache profile and the safe Vary.
 *
 * Covers the v1.6.0 "Origin Shield" changes in TE_Headers:
 *   - resolve_cache_profile(): the homepage can own its own TTL profile, and
 *     existing installs (override off) keep the exact previous behaviour,
 *     including the SWR/SIE values that used to be hardcoded.
 *   - get_safe_vary(): the anonymous document Vary never contains Cookie or
 *     User-Agent (the fragmentation that caused ~58% homepage miss on real sites).
 *
 * No PHPUnit required. Run:  php tests/front-page-profile.php
 *
 * @package flavor_edge_cache
 */

namespace flavor_edge;

define( 'ABSPATH', __DIR__ );

// ---------------------------------------------------------------------------
// Controllable WordPress context (stubs resolved inside the flavor_edge ns).
// ---------------------------------------------------------------------------
$GLOBALS['__is_front_page'] = false;
$GLOBALS['__is_home']       = false;

function is_front_page() { return (bool) $GLOBALS['__is_front_page']; }
function is_home() { return (bool) $GLOBALS['__is_home']; }
function apply_filters( $tag, $value ) { return $value; }

/** Minimal stub so the class can be loaded in isolation. */
class TE_Settings {
	public static $data = array();
	public static function get_all() { return self::$data; }
}

require __DIR__ . '/../includes/modules/TE_Headers.php';

// ---------------------------------------------------------------------------
// Tiny assert harness.
// ---------------------------------------------------------------------------
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

function call_private( $method, ...$args ) {
	$m = new \ReflectionMethod( __NAMESPACE__ . '\\TE_Headers', $method );
	$m->setAccessible( true );
	return $m->invoke( null, ...$args );
}

$base = array(
	'html_max_age'              => 3600,
	'html_s_maxage'             => 172800,
	'html_swr'                  => 60,
	'html_sie'                  => 86400,
	'front_page_cache_override' => false,
	'front_page_max_age'        => 30,
	'front_page_s_maxage'       => 300,
	'front_page_swr'            => 300,
	'front_page_sie'            => 86400,
	'vary_device'               => true,
	'vary_language'             => false,
);

echo "== resolve_cache_profile ==\n";

// 1. Override OFF → global profile, even on the front page (no regression).
$GLOBALS['__is_front_page'] = true;
$p = call_private( 'resolve_cache_profile', $base );
check( 'override off → global s-maxage 172800', 172800 === $p['s_maxage'] );
check( 'override off → global swr 60 (ex-hardcoded)', 60 === $p['swr'] );
check( 'override off → global sie 86400 (ex-hardcoded)', 86400 === $p['sie'] );

// 2. Override ON + front page → the dedicated homepage profile.
$s = $base;
$s['front_page_cache_override'] = true;
$p = call_private( 'resolve_cache_profile', $s );
check( 'override on + home → s-maxage 300', 300 === $p['s_maxage'] );
check( 'override on + home → max-age 30', 30 === $p['max_age'] );
check( 'override on + home → swr 300', 300 === $p['swr'] );
check( 'override on + home → sie 86400', 86400 === $p['sie'] );

// 3. Override ON but NOT the front page → global profile (sections/articles).
$GLOBALS['__is_front_page'] = false;
$GLOBALS['__is_home']       = false;
$p = call_private( 'resolve_cache_profile', $s );
check( 'override on + not home → global s-maxage 172800', 172800 === $p['s_maxage'] );

echo "== get_safe_vary ==\n";

$v = call_private( 'get_safe_vary', $base );
check( 'includes Accept-Encoding', in_array( 'Accept-Encoding', $v, true ) );
check( 'includes X-Device when vary_device on', in_array( 'X-Device', $v, true ) );
check( 'NEVER includes Cookie', ! in_array( 'Cookie', $v, true ) );
check( 'NEVER includes User-Agent', ! in_array( 'User-Agent', $v, true ) );

$s2 = $base;
$s2['vary_language'] = true;
$v2 = call_private( 'get_safe_vary', $s2 );
check( 'includes Accept-Language when vary_language on', in_array( 'Accept-Language', $v2, true ) );

$s3 = $base;
$s3['vary_device']   = false;
$s3['vary_language'] = false;
$v3 = call_private( 'get_safe_vary', $s3 );
check( 'always keeps at least Accept-Encoding', array( 'Accept-Encoding' ) === $v3 );

echo "== generate_grace_vcl (B2) ==\n";
$grace = TE_Headers::generate_grace_vcl();
check( 'sets beresp.grace', false !== strpos( $grace, 'set beresp.grace' ) );
check( 'sets beresp.keep', false !== strpos( $grace, 'set beresp.keep' ) );
check( 'derives from stale-while-revalidate', false !== strpos( $grace, 'stale-while-revalidate=[0-9]+' ) );
check( 'derives from stale-if-error', false !== strpos( $grace, 'stale-if-error=[0-9]+' ) );
check( 'targets vcl_backend_response', false !== strpos( $grace, 'sub vcl_backend_response' ) );
check( 'marked PROPOSE ONLY', false !== strpos( $grace, 'PROPOSE ONLY' ) );

echo "\n" . ( $failures ? "$failures FAILURE(S)\n" : "ALL TESTS PASSED\n" );
exit( $failures ? 1 : 0 );
