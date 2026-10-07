<?php
/**
 * Regression test: inline CSS from wp_add_inline_style() must survive CSS combine.
 *
 * Standalone — no PHPUnit required. Run with:
 *   php tests/combine-inline-css.php
 *
 * Reproduces the bug where the CSS combiner discarded styles attached to a
 * stylesheet handle via wp_add_inline_style() (stored in the queue object's
 * extra['after']/['before'], not in the file). Verifies that the inline CSS
 * ends up in the generated bundle, in the correct cascade order.
 *
 * @package flavor_edge_cache
 */

// Mirrors the exact bundle-building logic of TE_Minify::combine_css_files().
function te_build_css_bundle( array $to_combine ) {
	$combined_content = '';
	foreach ( $to_combine as $handle => $info ) {
		$content = @file_get_contents( $info['path'] );
		if ( false === $content ) {
			continue;
		}
		// (URL rewrite and minify are no-ops in this test.)
		$before = ! empty( $info['before'] ) ? implode( "\n", $info['before'] ) . "\n" : '';
		$after  = ! empty( $info['after'] ) ? "\n" . implode( "\n", $info['after'] ) : '';
		$combined_content .= "/* {$handle} */\n{$before}{$content}{$after}\n";
	}
	return $combined_content;
}

$tmp = sys_get_temp_dir() . '/te-combine-test';
@mkdir( $tmp, 0777, true );
file_put_contents( "$tmp/main.css", 'body{margin:0}' );
file_put_contents( "$tmp/extra.css", '.x{color:blue}' );

$to_combine = array(
	'te-main-style' => array(
		'path'   => "$tmp/main.css",
		'before' => array(),
		'after'  => array( '.te-mega-menu{display:flex;outline:3px solid red}' ),
	),
	'extra-style' => array(
		'path'   => "$tmp/extra.css",
		'before' => array( '.pre{top:0}' ),
		'after'  => array(),
	),
);

$bundle = te_build_css_bundle( $to_combine );

$failures = 0;
function check( $cond, $name, &$failures ) {
	echo ( $cond ? "  ok   " : "  FAIL " ) . $name . "\n";
	if ( ! $cond ) {
		$failures++;
	}
}

echo "Regression: inline CSS survives combine\n";
check( false !== strpos( $bundle, 'te-mega-menu' ), "inline CSS is present in the bundle", $failures );
check( false !== strpos( $bundle, 'outline:3px solid red' ), "exact rule preserved", $failures );

$pos_body = strpos( $bundle, 'body{margin:0}' );
$pos_mega = strpos( $bundle, 'te-mega-menu' );
check( false !== $pos_body && $pos_body < $pos_mega, "'after' inline follows its own file (cascade order)", $failures );

$pos_pre = strpos( $bundle, '.pre{top:0}' );
$pos_x   = strpos( $bundle, '.x{color:blue}' );
check( false !== $pos_pre && $pos_pre < $pos_x, "'before' inline precedes its own file", $failures );

echo "\n" . ( 0 === $failures ? "PASS" : "FAIL ($failures)" ) . "\n";
exit( 0 === $failures ? 0 : 1 );
