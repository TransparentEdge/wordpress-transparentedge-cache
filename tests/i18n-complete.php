<?php
/**
 * Standalone test: every translatable string in the code has a Spanish
 * translation, and every translation keeps the same printf placeholders.
 * (Fails as soon as a new string is added without translating it.)
 *
 * Run: php tests/i18n-complete.php   — fix with: python3 tools/i18n-build.py
 *
 * @package flavor_edge_cache
 */

$root    = dirname( __DIR__ );
$strings = json_decode( shell_exec( 'php ' . escapeshellarg( $root . '/tools/i18n-extract.php' ) . ' ' . escapeshellarg( $root ) ), true );
$po      = file_get_contents( $root . '/languages/flavor-edge-cache-es_ES.po' );

// Minimal .po reader: msgctxt / msgid / msgstr, multi-line strings, no plurals needed.
$entries = array();
foreach ( preg_split( "/\n\s*\n/", $po ) as $block ) {
	$e = array();
	$cur = null;
	foreach ( explode( "\n", $block ) as $line ) {
		if ( preg_match( '/^(msgctxt|msgid|msgstr) "(.*)"$/', $line, $m ) ) {
			$cur = $m[1];
			$e[ $cur ] = stripcslashes( $m[2] );
		} elseif ( $cur && preg_match( '/^"(.*)"$/', $line, $m ) ) {
			$e[ $cur ] .= stripcslashes( $m[1] );
		} elseif ( 0 === strpos( $line, '#, ' ) && false !== strpos( $line, 'fuzzy' ) ) {
			$e['fuzzy'] = true;
		}
	}
	if ( isset( $e['msgid'] ) && '' !== $e['msgid'] ) {
		$entries[ ( $e['msgctxt'] ?? '' ) . "\x04" . $e['msgid'] ] = $e;
	}
}

$failures = 0;
$missing  = array();
$badfmt   = array();
$ph       = function ( $s ) {
	preg_match_all( '/%(?:\d+\$)?[sdf]/', str_replace( '%%', '', $s ), $m );
	$list = $m[0];
	sort( $list );
	return $list;
};
foreach ( (array) $strings as $s ) {
	$e = $entries[ ( $s['msgctxt'] ?? '' ) . "\x04" . $s['msgid'] ] ?? null;
	if ( ! $e || '' === ( $e['msgstr'] ?? '' ) || ! empty( $e['fuzzy'] ) ) {
		$missing[] = $s['msgid'];
		continue;
	}
	if ( $ph( $s['msgid'] ) !== $ph( $e['msgstr'] ) ) {
		$badfmt[] = $s['msgid'] . '  →  ' . $e['msgstr'];
	}
}

echo count( $strings ) . " strings in code\n";
if ( $missing ) {
	$failures++;
	echo '  FAIL  ' . count( $missing ) . " without Spanish translation:\n";
	foreach ( array_slice( $missing, 0, 30 ) as $m ) { echo '        - ' . $m . "\n"; }
} else {
	echo "  PASS  every string has a Spanish translation\n";
}
if ( $badfmt ) {
	$failures++;
	echo '  FAIL  ' . count( $badfmt ) . " translations change the placeholders:\n";
	foreach ( $badfmt as $b ) { echo '        - ' . $b . "\n"; }
} else {
	echo "  PASS  placeholders match in every translation\n";
}
$mo  = $root . '/languages/flavor-edge-cache-es_ES.mo';
$bin = is_file( $mo ) ? file_get_contents( $mo ) : '';
$n   = strlen( $bin ) >= 12 ? unpack( 'V', substr( $bin, 8, 4 ) )[1] : 0; // number of strings (header entry included).
if ( $n !== count( $entries ) + 1 ) {
	$failures++;
	echo "  FAIL  .mo has $n entries, .po has " . ( count( $entries ) + 1 ) . " — recompile (python3 tools/i18n-build.py)\n";
} else {
	echo "  PASS  .mo matches .po\n";
}
echo $failures ? "\n$failures FAILED\n" : "\nALL TESTS PASSED\n";
exit( $failures ? 1 : 0 );
