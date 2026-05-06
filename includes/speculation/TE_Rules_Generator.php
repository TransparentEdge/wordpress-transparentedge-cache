<?php
/**
 * Generates the Speculation Rules JSON structure.
 *
 * Builds the rules array based on site type, active plugins,
 * mode (conservative/balanced/aggressive), and user configuration.
 *
 * @package flavor_edge_cache
 */

namespace flavor_edge;

defined( 'ABSPATH' ) || exit;

class TE_Rules_Generator {

	/**
	 * Generate the complete speculation rules array.
	 *
	 * @return array Speculation Rules compliant structure.
	 */
	public static function generate() {
		$settings = TE_Settings::get_all();
		$mode     = $settings['speculation_mode'] ?? 'balanced';

		if ( 'off' === $mode || empty( $settings['speculation_enabled'] ) ) {
			return array();
		}

		$rules = array(
			'prefetch' => array(),
		);

		// Base prefetch rule (all modes).
		$rules['prefetch'][] = self::build_prefetch_rule( $mode );

		// Aggressive mode adds prerender on high-intent signals.
		if ( 'aggressive' === $mode ) {
			$rules['prerender'] = array();
			$rules['prerender'][] = self::build_prerender_rule();
		}

		/**
		 * Filter the complete speculation rules array.
		 *
		 * @param array  $rules Generated rules.
		 * @param string $mode  Current mode (conservative|balanced|aggressive).
		 */
		return apply_filters( 'flavor_edge_speculation_rules', $rules, $mode );
	}

	/**
	 * Build the main prefetch rule with all exclusions.
	 *
	 * @param string $mode Current mode.
	 * @return array Single prefetch rule.
	 */
	private static function build_prefetch_rule( $mode ) {
		$exclusions = self::collect_exclusions();

		// Map mode to eagerness.
		$eagerness = 'moderate'; // balanced default.
		if ( 'conservative' === $mode ) {
			$eagerness = 'conservative'; // click / pointerdown only.
		}

		/**
		 * Filter the eagerness level.
		 *
		 * @param string $eagerness Current eagerness.
		 * @param string $mode      Current mode.
		 */
		$eagerness = apply_filters( 'flavor_edge_speculation_eagerness', $eagerness, $mode );

		$where_conditions = array(
			array( 'href_matches' => '/*' ),
		);

		// Selector-based exclusions (universal).
		$where_conditions[] = array( 'not' => array( 'selector_matches' => '[rel~=nofollow]' ) );
		$where_conditions[] = array( 'not' => array( 'selector_matches' => '[download]' ) );
		$where_conditions[] = array( 'not' => array( 'selector_matches' => '[target=_blank]' ) );
		$where_conditions[] = array( 'not' => array( 'selector_matches' => '.no-prefetch' ) );

		// Path-based exclusions.
		foreach ( $exclusions as $path ) {
			$where_conditions[] = array( 'not' => array( 'href_matches' => $path ) );
		}

		return array(
			'source'    => 'document',
			'where'     => array( 'and' => $where_conditions ),
			'eagerness' => $eagerness,
		);
	}

	/**
	 * Build the prerender rule for aggressive mode.
	 * Uses conservative eagerness (click only) to limit impact.
	 *
	 * @return array Single prerender rule.
	 */
	private static function build_prerender_rule() {
		$exclusions = self::collect_exclusions();

		$where_conditions = array(
			array( 'href_matches' => '/*' ),
			array( 'not' => array( 'selector_matches' => '[rel~=nofollow]' ) ),
			array( 'not' => array( 'selector_matches' => '[download]' ) ),
			array( 'not' => array( 'selector_matches' => '[target=_blank]' ) ),
			array( 'not' => array( 'selector_matches' => '.no-prerender' ) ),
		);

		foreach ( $exclusions as $path ) {
			$where_conditions[] = array( 'not' => array( 'href_matches' => $path ) );
		}

		return array(
			'source'    => 'document',
			'where'     => array( 'and' => $where_conditions ),
			'eagerness' => 'conservative', // Prerender only on click — never on hover.
		);
	}

	/**
	 * Collect all exclusion paths from presets + plugins + filters.
	 *
	 * @return array Flat array of path patterns to exclude.
	 */
	private static function collect_exclusions() {
		$exclusions = array();

		// 1. Base WordPress exclusions (always).
		$base = include FLAVOR_EDGE_DIR . 'includes/speculation/presets/base.php';
		if ( is_array( $base ) ) {
			$exclusions = array_merge( $exclusions, $base );
		}

		// 2. WooCommerce (if active).
		if ( class_exists( 'WooCommerce' ) ) {
			$wc = include FLAVOR_EDGE_DIR . 'includes/speculation/presets/woocommerce.php';
			if ( is_array( $wc ) ) {
				$exclusions = array_merge( $exclusions, $wc );
			}
		}

		// 3. Multilingual (if detected).
		if ( defined( 'ICL_SITEPRESS_VERSION' ) || defined( 'POLYLANG_VERSION' ) ) {
			$ml = include FLAVOR_EDGE_DIR . 'includes/speculation/presets/multilingual.php';
			if ( is_array( $ml ) ) {
				$exclusions = array_merge( $exclusions, $ml );
			}
		}

		// 4. Per-plugin exclusions.
		$blacklists   = include FLAVOR_EDGE_DIR . 'includes/speculation/presets/plugin-blacklists.php';
		$active_plugins = get_option( 'active_plugins', array() );
		if ( is_array( $blacklists ) ) {
			foreach ( $blacklists as $plugin_basename => $paths ) {
				if ( in_array( $plugin_basename, $active_plugins, true ) ) {
					$exclusions = array_merge( $exclusions, $paths );
				}
			}
		}

		// 5. Post type filtering.
		$allowed_types = TE_Settings::get( 'speculation_post_types' );
		if ( ! empty( $allowed_types ) && is_array( $allowed_types ) ) {
			$all_public = get_post_types( array( 'public' => true ), 'objects' );
			foreach ( $all_public as $pt ) {
				if ( ! in_array( $pt->name, $allowed_types, true ) && 'attachment' !== $pt->name ) {
					$archive_slug = $pt->has_archive ? ( is_string( $pt->has_archive ) ? $pt->has_archive : $pt->rewrite['slug'] ?? $pt->name ) : '';
					if ( $archive_slug ) {
						$exclusions[] = '/' . ltrim( $archive_slug, '/' ) . '/*';
					}
				}
			}
		}

		/**
		 * Filter excluded paths. Add custom exclusions from code.
		 *
		 * @param array $paths Current exclusion paths.
		 */
		$custom = apply_filters( 'flavor_edge_speculation_excluded_paths', array() );
		if ( ! empty( $custom ) ) {
			$exclusions = array_merge( $exclusions, (array) $custom );
		}

		return array_unique( array_filter( $exclusions ) );
	}
}
