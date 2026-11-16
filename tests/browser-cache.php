<?php
/**
 * Standalone test: static-file snippets (nginx, VCL) and the immutable path.
 *
 * Run: php tests/browser-cache.php
 *
 * @package flavor_edge_cache
 */

namespace flavor_edge;

define( 'ABSPATH', __DIR__ . '/' );
define( 'WP_CONTENT_DIR', __DIR__ . '/nonexistent' );
define( 'FLAVOR_EDGE_URL', 'https://www.example.com/wp-content/plugins/transparent-edge-cache/' );

class TE_Settings {
	public static function get_all() { return array( 'static_max_age' => 86400, 'static_s_maxage' => 2592000 ); }
	public static function get( $k, $d = null ) { return $d; }
}
class TE_Minify { const CACHE_DIR = 'cache/flavor-edge/min'; }
class TE_GoogleFonts { const CACHE_DIR = 'cache/flavor-edge/fonts'; }
class TE_Api { const HEALTH_CRON_HOOK = 'flavor_edge_health_check'; }
function wp_parse_url( $u, $c = -1 ) { return parse_url( $u, $c ); }
function content_url( $p = '' ) { return 'https://www.example.com/wp-content/' . $p; }
function home_url( $p = '/' ) { return 'https://www.example.com' . $p; }

require __DIR__ . '/../includes/modules/TE_BrowserCache.php';

$failures = 0;
function check( $label, $cond ) { global $failures; echo ( $cond ? '  PASS  ' : '  FAIL  ' ) . $label . "\n"; if ( ! $cond ) { $failures++; } }

check( 'immutable path is the minify dir', '/wp-content/cache/flavor-edge/min/' === TE_BrowserCache::immutable_path() );
check( 'immutable Cache-Control', 'public, max-age=31536000, s-maxage=31536000, immutable' === TE_BrowserCache::immutable_cache_control() );

$n = TE_BrowserCache::generate_nginx_snippet();
check( 'nginx: prefix location for the minify dir (wins over regex)', false !== strpos( $n, 'location ^~ /wp-content/cache/flavor-edge/min/ {' ) );
check( 'nginx: immutable header there', false !== strpos( $n, 'add_header Cache-Control "public, max-age=31536000, s-maxage=31536000, immutable";' ) );
check( 'nginx: generic static rule keeps the configured TTLs', false !== strpos( $n, 'max-age=86400, s-maxage=2592000' ) );
check( 'nginx: warns about add_header inheritance', false !== strpos( $n, 'replaces the server-level add_header' ) );

$v = TE_BrowserCache::generate_vcl_snippet();
check( 'vcl: scoped to the site host', false !== strpos( $v, 'bereq.http.host == "www.example.com"' ) );
check( 'vcl: minify dir → 1 year immutable', false !== strpos( $v, 'bereq.url ~ "^/wp-content/cache/flavor-edge/min/"' ) && false !== strpos( $v, 'max-age=31536000, immutable' ) );
check( 'vcl: other statics only when origin sent no max-age', false !== strpos( $v, 'beresp.http.Cache-Control !~ "max-age"' ) );
check( 'vcl: only 200 responses', false !== strpos( $v, 'beresp.status == 200' ) );
check( 'vcl: edge TTL = static_s_maxage, browser = static_max_age', false !== strpos( $v, 'set beresp.ttl = 2592000s;' ) && false !== strpos( $v, '"public, max-age=86400"' ) );
check( 'vcl: braces balanced', substr_count( $v, '{' ) === substr_count( $v, '}' ) );

echo "== self-hosted fonts immutable ==\n";
check( 'fonts path', '/wp-content/cache/flavor-edge/fonts/' === TE_BrowserCache::fonts_path() );
$re = '#' . TE_BrowserCache::FONT_FILE_RE . '#';
check( 'font file matches', 1 === preg_match( $re, 'font-' . md5( 'x' ) . '.woff2' ) );
check( 'font CSS does not', 0 === preg_match( $re, 'gf-' . md5( 'x' ) . '.css' ) );
check( 'nginx: fonts regex location before generic static', strpos( $n, 'location ~ ^/wp-content/cache/flavor-edge/fonts/font-' ) < strpos( $n, 'location ~* \\.(css' ) );
check( 'vcl: fonts in the immutable branch', false !== strpos( $v, 'bereq.url ~ "^/wp-content/cache/flavor-edge/fonts/font-[0-9a-f]{32}' ) );

echo "== nginx rules file ==\n";
$f = TE_BrowserCache::generate_nginx_snippet( true );
check( 'file version carries the include line', false !== strpos( $f, 'include ' . WP_CONTENT_DIR . '/cache/flavor-edge/nginx.conf;' ) );
check( 'file version has no timestamp (no rewrite churn)', false === strpos( $f, 'Generated:' ) );
check( 'same rules as the copy-paste snippet', substr( $f, strpos( $f, '# NOTES' ) ) === substr( $n, strpos( $n, '# NOTES' ) ) );

echo $failures ? "\n$failures FAILED\n" : "\nALL TESTS PASSED\n";
exit( $failures ? 1 : 0 );
