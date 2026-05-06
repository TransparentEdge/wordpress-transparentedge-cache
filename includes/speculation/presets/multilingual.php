<?php
/**
 * Multilingual exclusion paths for Speculation Rules.
 * Loaded when WPML or Polylang is detected.
 *
 * Note: For subdirectory-based languages (e.g., /en/, /es/), the same-origin
 * constraint in Speculation Rules already limits prefetches to the current
 * language subdomain. No additional exclusions are needed for subdomains.
 *
 * For subdirectory setups, language switcher links are same-origin and would
 * be prefetched. This is generally fine — the user clicked the switcher
 * intentionally. But we exclude translation editor paths.
 *
 * @package flavor_edge_cache
 */

defined( 'ABSPATH' ) || exit;

$paths = array();

// WPML translation management paths.
if ( defined( 'ICL_SITEPRESS_VERSION' ) ) {
	$paths[] = '/*?lang=*';
}

return $paths;
