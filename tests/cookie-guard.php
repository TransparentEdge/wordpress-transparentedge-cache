<?php
/**
 * Standalone regression test for TE_Cookie_Guard.
 *
 * Covers the keep-list regex builder and the generated cookie-normalisation
 * VCL (correct backreference and end-anchor, allowlist present).
 *
 * No PHPUnit required. Run:  php tests/cookie-guard.php
 *
 * @package flavor_edge_cache
 */

namespace flavor_edge;

define( 'ABSPATH', __DIR__ );

class TE_Settings {
	public static $cookies = "wordpress_logged_in_\nwoocommerce_items_in_cart";
	public static function get( $key, $default = '' ) {
		return 'excluded_cookies' === $key ? self::$cookies : $default;
	}
}

require __DIR__ . '/../includes/modules/TE_Cookie_Guard.php';

$failures = 0;
function check( $label, $cond ) {
	global $failures;
	echo ( $cond ? "  PASS  " : "  FAIL  " ) . $label . "\n";
	if ( ! $cond ) {
		$failures++;
	}
}

echo "== build_keep_regex ==\n";
$re = TE_Cookie_Guard::build_keep_regex( array( 'wordpress_logged_in_', 'woocommerce_items_in_cart' ) );
check( 'alternation of the two prefixes', 'wordpress_logged_in_[^=]*|woocommerce_items_in_cart[^=]*' === $re );
check( 'empty allowlist → never-match token', '(?!)' === TE_Cookie_Guard::build_keep_regex( array() ) );
check( 'blank lines ignored', 'a[^=]*' === TE_Cookie_Guard::build_keep_regex( array( '', '  a  ', '' ) ) );
check( 'metacharacters escaped', false !== strpos( TE_Cookie_Guard::build_keep_regex( array( 'a.b' ) ), 'a\.b' ) );

echo "== generate_vcl_snippet ==\n";
$vcl = TE_Cookie_Guard::generate_vcl_snippet();
check( 'keeps the allowlist in the regex', false !== strpos( $vcl, 'wordpress_logged_in_[^=]*|woocommerce_items_in_cart[^=]*' ) );
check( 'uses the \\1 backreference (not \\$1)', false !== strpos( $vcl, '"; \1="' ) );
check( 'end-anchor is literal $ (not escaped \\$)', false !== strpos( $vcl, '[; ]+$"' ) );
check( 'does NOT contain an escaped-dollar anchor', false === strpos( $vcl, '[; ]+\\$' ) );
check( 'unsets an empty Cookie header', false !== strpos( $vcl, 'unset req.http.Cookie' ) );
check( 'targets vcl_recv', false !== strpos( $vcl, 'sub vcl_recv' ) );
check( 'marked PROPOSE ONLY', false !== strpos( $vcl, 'PROPOSE ONLY' ) );

echo "\n" . ( $failures ? "$failures FAILURE(S)\n" : "ALL TESTS PASSED\n" );
exit( $failures ? 1 : 0 );
