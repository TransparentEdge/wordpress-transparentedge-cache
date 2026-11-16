<?php
/**
 * Extract translatable strings (text domain flavor-edge-cache) from the plugin
 * with PHP's own tokenizer, so single/double quotes, escapes and concatenated
 * literals are read exactly like PHP reads them.
 *
 * Usage: php tools/i18n-extract.php [plugin-dir] > strings.json
 * Output: JSON list of { msgid, msgid_plural?, msgctxt?, refs[] }.
 *
 * @package flavor_edge_cache
 */

$root   = rtrim( $argv[1] ?? dirname( __DIR__ ), '/' );
$domain = 'flavor-edge-cache';
// function => [ msgid arg index, plural index|null, context index|null, domain index ].
$fns = array(
	'__' => array( 0, null, null, 1 ), '_e' => array( 0, null, null, 1 ),
	'esc_html__' => array( 0, null, null, 1 ), 'esc_html_e' => array( 0, null, null, 1 ),
	'esc_attr__' => array( 0, null, null, 1 ), 'esc_attr_e' => array( 0, null, null, 1 ),
	'_x' => array( 0, null, 1, 2 ), '_ex' => array( 0, null, 1, 2 ),
	'esc_html_x' => array( 0, null, 1, 2 ), 'esc_attr_x' => array( 0, null, 1, 2 ),
	'_n' => array( 0, 1, null, 3 ), '_nx' => array( 0, 1, 3, 4 ),
	'_n_noop' => array( 0, 1, null, 2 ), '_nx_noop' => array( 0, 1, 2, 3 ),
);

$out = array();
$it  = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $root, FilesystemIterator::SKIP_DOTS ) );
foreach ( $it as $file ) {
	$path = $file->getPathname();
	$rel  = substr( $path, strlen( $root ) + 1 );
	if ( '.php' !== substr( $path, -4 ) || preg_match( '#^(tests|tools|vendor|node_modules)/#', $rel ) ) {
		continue;
	}
	$tokens = token_get_all( file_get_contents( $path ) );
	$n      = count( $tokens );
	for ( $i = 0; $i < $n; $i++ ) {
		$t = $tokens[ $i ];
		if ( ! is_array( $t ) || T_STRING !== $t[0] && ( ! defined( 'T_NAME_FULLY_QUALIFIED' ) || T_NAME_FULLY_QUALIFIED !== $t[0] ) ) {
			continue;
		}
		$name = ltrim( $t[1], '\\' );
		if ( ! isset( $fns[ $name ] ) ) {
			continue;
		}
		// Previous significant token must not be -> :: or "function".
		$p = $i - 1;
		while ( $p >= 0 && is_array( $tokens[ $p ] ) && T_WHITESPACE === $tokens[ $p ][0] ) { $p--; }
		if ( $p >= 0 && is_array( $tokens[ $p ] ) && in_array( $tokens[ $p ][0], array( T_OBJECT_OPERATOR, T_DOUBLE_COLON, T_FUNCTION ), true ) ) {
			continue;
		}
		$j = $i + 1;
		while ( $j < $n && is_array( $tokens[ $j ] ) && T_WHITESPACE === $tokens[ $j ][0] ) { $j++; }
		if ( $j >= $n || '(' !== $tokens[ $j ] ) {
			continue;
		}
		// Collect top-level args: each arg is either a pure literal (possibly
		// concatenated) or null when it contains anything else.
		$args = array(); $cur = ''; $pure = true; $has = false; $depth = 0;
		for ( $k = $j + 1; $k < $n; $k++ ) {
			$x = $tokens[ $k ];
			if ( '(' === $x || '[' === $x ) { $depth++; $pure = false; continue; }
			if ( ')' === $x || ']' === $x ) {
				if ( 0 === $depth ) { $args[] = ( $pure && $has ) ? $cur : null; break; }
				$depth--; continue;
			}
			if ( ',' === $x && 0 === $depth ) { $args[] = ( $pure && $has ) ? $cur : null; $cur = ''; $pure = true; $has = false; continue; }
			if ( is_array( $x ) ) {
				if ( in_array( $x[0], array( T_WHITESPACE, T_COMMENT, T_DOC_COMMENT ), true ) ) { continue; }
				if ( T_CONSTANT_ENCAPSED_STRING === $x[0] ) { $cur .= eval( 'return ' . $x[1] . ';' ); $has = true; continue; }
			}
			if ( '.' === $x ) { continue; }
			$pure = false;
		}
		list( $mi, $pi, $ci, $di ) = $fns[ $name ];
		if ( ( $args[ $di ] ?? null ) !== $domain || ! isset( $args[ $mi ] ) ) {
			continue;
		}
		$key = ( null !== $ci ? ( $args[ $ci ] ?? '' ) . "\x04" : '' ) . $args[ $mi ];
		if ( ! isset( $out[ $key ] ) ) {
			$out[ $key ] = array( 'msgid' => $args[ $mi ], 'refs' => array() );
			if ( null !== $pi && isset( $args[ $pi ] ) ) { $out[ $key ]['msgid_plural'] = $args[ $pi ]; }
			if ( null !== $ci && isset( $args[ $ci ] ) ) { $out[ $key ]['msgctxt'] = $args[ $ci ]; }
		}
		$out[ $key ]['refs'][] = $rel . ':' . $t[2];
	}
}
echo json_encode( array_values( $out ), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ), "\n";
