<?php
/**
 * Standalone regression test for Delay JS matching, the combined/minified
 * scripts, the force list, preconnect exclusions and the minify grace window.
 *
 * Run: php tests/delay-js.php
 *
 * @package flavor_edge_cache
 */

namespace flavor_edge;

define( 'ABSPATH', __DIR__ );

class TE_Settings {
	public static $data = array();
	public static function get( $k, $d = null ) { return array_key_exists( $k, self::$data ) ? self::$data[ $k ] : $d; }
	public static function get_all() { return self::$data; }
}
function apply_filters( $tag, $value ) { return $value; }
function wp_parse_url( $u, $c = -1 ) { return parse_url( $u, $c ); }
function get_option( $k, $d = false ) { return array(); }
$GLOBALS['__scripts'] = (object) array( 'registered' => array(
	'contact-form-7' => (object) array( 'src' => '/wp-content/plugins/contact-form-7/includes/js/index.js' ),
	'theme-main'     => (object) array( 'src' => '/wp-content/themes/t/js/main.js' ),
) );
// WordPress defines wp_scripts() globally (function_exists() checks the global name).
eval( 'function wp_scripts() { return $GLOBALS["__scripts"]; }' );

require __DIR__ . '/../includes/modules/TE_Frontend.php';
require __DIR__ . '/../includes/modules/TE_Minify.php';

$failures = 0;
function check( $label, $cond ) {
	global $failures;
	echo ( $cond ? '  PASS  ' : '  FAIL  ' ) . $label . "\n";
	if ( ! $cond ) { $failures++; }
}
function delay( $html, $ex = '', $force = '' ) {
	$m = new \ReflectionMethod( __NAMESPACE__ . '\\TE_Frontend', 'apply_delay_js' );
	$m->setAccessible( true );
	return $m->invoke( null, $html, $ex, $force );
}
function delayed( $out, $needle ) {
	// The tag that contains $needle carries type="flavoredge-delay".
	foreach ( preg_split( '#</script>#', $out ) as $tag ) {
		if ( false !== strpos( $tag, $needle ) ) {
			return false !== strpos( $tag, 'type="flavoredge-delay"' );
		}
	}
	return null;
}

$gtm = "<script>(function(w,d,s,l,i){w[l]=w[l]||[];w[l].push({'gtm.start':new Date().getTime(),event:'gtm.js'});var f=d.getElementsByTagName(s)[0],j=d.createElement(s),dl=l!='dataLayer'?'&l='+l:'';j.async=true;j.src='https://www.googletagmanager.com/gtm.js?id='+i+dl;f.parentNode.insertBefore(j,f);})(window,document,'script','dataLayer','GTM-XXXX');</script>";
$page = '<html><head>'
	. '<script src="/wp-includes/js/jquery/jquery.min.js" id="jquery-core-js"></script>'
	. $gtm
	. '<script id="flavor-edge-combined-js-js-before">var cfg={a:1};</script>'
	. '<script src="/wp-content/cache/flavor-edge/min/combined-js-0123456789ab.js" id="flavor-edge-combined-js-js"></script>'
	. '<script id="flavor-edge-combined-js-js-after">initMenu();</script>'
	. '<script src="/wp-content/cache/flavor-edge/min/index-deadbeef.js" id="contact-form-7-js"></script>'
	. '<script src="/wp-content/cache/flavor-edge/min/main-cafe.js" id="theme-main-js"></script>'
	. '<script src="/.well-known/tcdn/bb.js" id="bb"></script>'
	. '<script type="application/ld+json">{"a":1}</script>'
	. '<script>(function(){var els=document.querySelectorAll("[data-flavor-bg]");' . str_repeat( ' ', 600 ) . '})();</script>'
	. '</head><body></body></html>';

echo "== defaults ==\n";
$o = delay( $page );
check( 'combined bundle is delayed', true === delayed( $o, 'combined-js-0123456789ab.js' ) );
check( 'bundle "after" inline follows the bundle', true === delayed( $o, 'initMenu()' ) );
check( 'bundle "before" inline stays eager', false === delayed( $o, 'var cfg' ) );
check( 'minified per-asset script is delayed', true === delayed( $o, 'main-cafe.js' ) );
check( 'jQuery never delayed', false === delayed( $o, 'jquery.min.js' ) );
check( 'GTM snippet (<500 B) not delayed by default', false === delayed( $o, 'GTM-XXXX' ) );
check( 'JSON-LD untouched', false === delayed( $o, 'ld+json' ) );
check( 'own lazy-bg loader never delayed', false === delayed( $o, 'data-flavor-bg' ) );
check( 'restore runtime appended once', 1 === substr_count( $o, 'flavoredge-delay-restore' ) );

echo "== force list ==\n";
$o = delay( $page, '', "googletagmanager.com/gtm.js" );
check( 'GTM snippet delayed when forced', true === delayed( $o, 'GTM-XXXX' ) );
$o = delay( $page, 'GTM-XXXX', "googletagmanager.com/gtm.js" );
check( 'exclusion beats force', false === delayed( $o, 'GTM-XXXX' ) );

echo "== exclusions ==\n";
$o = delay( $page, 'contact-form-7/includes' );
check( 'pattern on ORIGINAL src protects the minified copy', false === delayed( $o, 'index-deadbeef.js' ) );
$o = delay( $page, 'battlebot' );
check( '"battlebot" does not match the bb.js tag (documented rule)', true === delayed( $o, 'tcdn/bb.js' ) );
$o = delay( $page, 'tcdn/bb.js' );
check( '"tcdn/bb.js" does', false === delayed( $o, 'tcdn/bb.js' ) );
$o = delay( $page, 'flavor-edge-combined-js' );
check( 'bundle excluded by its handle → its after inline stays eager too', false === delayed( $o, 'combined-js-0123456789ab.js' ) && false === delayed( $o, 'initMenu()' ) );

echo "== preconnect exclusions ==\n";
TE_Settings::$data = array( 'preconnect_exclusions' => "www.googletagmanager.com\nhttps://connect.facebook.net/\n" );
$r = TE_Frontend::remove_excluded_hint_domains( array( 'https://www.googletagmanager.com', 'https://www.google-analytics.com', 'https://connect.facebook.net' ) );
check( 'host and origin forms both excluded', array( 'https://www.google-analytics.com' ) === $r );
TE_Settings::$data = array();
check( 'empty list keeps all', 2 === count( TE_Frontend::remove_excluded_hint_domains( array( 'https://a.com', 'https://b.com' ) ) ) );

echo "== minify grace ==\n";
TE_Settings::$data = array( 'html_s_maxage' => 172800, 'html_swr' => 60, 'html_sie' => 86400, 'html_max_age' => 3600, 'front_page_s_maxage' => 300, 'front_page_swr' => 300, 'front_page_sie' => 86400, 'front_page_max_age' => 30 );
check( 'grace = 48h + 24h sie + 1h + 1h margin', ( 172800 + 86400 + 3600 + 3600 ) === TE_Minify::grace() );
TE_Settings::$data = array( 'html_s_maxage' => 600, 'html_max_age' => 60 );
check( 'short TTLs → floor of one day', 86400 === TE_Minify::grace() );

echo $failures ? "\n$failures FAILED\n" : "\nALL TESTS PASSED\n";
exit( $failures ? 1 : 0 );
