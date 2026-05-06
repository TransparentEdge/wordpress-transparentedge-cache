<?php
/**
 * Detects conflicting plugins and scripts that also implement
 * prefetch/prerender functionality.
 *
 * Shows admin notices when conflicts are found. Does not block —
 * the admin decides whether to disable the other mechanism.
 *
 * @package flavor_edge_cache
 */

namespace flavor_edge;

defined( 'ABSPATH' ) || exit;

class TE_Conflict_Detector {

	/**
	 * Known plugins with prefetch/prerender features.
	 *
	 * @var array basename => { name, option_check (optional) }
	 */
	private static $known_plugins = array(
		'speculation-rules/speculation-rules.php' => array(
			'name'    => 'WordPress Speculative Loading',
			'message' => 'Este plugin ya emite sus propias reglas de Speculation Rules. Recomendamos desactivarlo para evitar prefetches duplicados — Transparent Edge Cache incluye exclusiones más completas y coordinación con la CDN.',
		),
		'performance-lab/performance-lab.php' => array(
			'name'    => 'WordPress Performance Lab (Speculative Loading module)',
			'message' => 'Performance Lab puede incluir el módulo Speculative Loading, que emite reglas duplicadas.',
			'option'  => 'perflab_modules_status', // Check if speculation module is active.
		),
		'flying-pages/flying-pages.php' => array(
			'name'    => 'Flying Pages',
			'message' => 'Flying Pages implementa prefetch on viewport vía JavaScript, lo que puede duplicar las peticiones de Speculation Rules.',
		),
		'wp-rocket/wp-rocket.php' => array(
			'name'    => 'WP Rocket (Preload Links)',
			'message' => 'WP Rocket tiene la opción "Preload links" que duplica prefetches.',
			'option'  => 'wp_rocket_settings',
			'check'   => 'preload_links',
		),
		'perfmatters/perfmatters.php' => array(
			'name'    => 'Perfmatters (Instant Page)',
			'message' => 'Perfmatters incluye instant.page que duplica prefetches on hover.',
		),
		'nitropack/main.php' => array(
			'name'    => 'NitroPack',
			'message' => 'NitroPack incluye su propio sistema de prefetch/prerender.',
		),
	);

	/**
	 * Known inline scripts to detect in HTML output.
	 *
	 * @var array
	 */
	private static $known_scripts = array(
		'quicklink',
		'instant.page',
		'instantpage',
		'transparent-fetch',
		'flying-pages',
	);

	/**
	 * Run conflict detection and return warnings.
	 *
	 * @return array Array of warning strings.
	 */
	public static function detect() {
		$warnings       = array();
		$active_plugins = get_option( 'active_plugins', array() );

		foreach ( self::$known_plugins as $basename => $info ) {
			if ( ! in_array( $basename, $active_plugins, true ) ) {
				continue;
			}

			// Optional: check if the specific feature is enabled within the plugin.
			if ( isset( $info['option'] ) && isset( $info['check'] ) ) {
				$option = get_option( $info['option'], array() );
				if ( is_array( $option ) && empty( $option[ $info['check'] ] ) ) {
					continue; // Feature disabled in that plugin.
				}
			}

			$warnings[] = array(
				'plugin'  => $info['name'],
				'message' => $info['message'],
			);
		}

		return $warnings;
	}

	/**
	 * Check for known prefetch scripts in registered scripts.
	 * Called in admin context to provide warnings.
	 *
	 * @return array Array of script identifiers found.
	 */
	public static function detect_scripts() {
		$found = array();

		// Check registered scripts for known identifiers.
		global $wp_scripts;
		if ( ! $wp_scripts ) {
			return $found;
		}

		foreach ( $wp_scripts->registered as $handle => $script ) {
			foreach ( self::$known_scripts as $known ) {
				if ( false !== stripos( $handle, $known ) || false !== stripos( $script->src ?? '', $known ) ) {
					$found[] = $known;
				}
			}
		}

		return array_unique( $found );
	}
}
