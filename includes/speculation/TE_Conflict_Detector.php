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
		),
		'performance-lab/performance-lab.php' => array(
			'name'    => 'WordPress Performance Lab (Speculative Loading module)',
			'option'  => 'perflab_modules_status', // Check if speculation module is active.
		),
		'flying-pages/flying-pages.php' => array(
			'name'    => 'Flying Pages',
		),
		'wp-rocket/wp-rocket.php' => array(
			'name'    => 'WP Rocket (Preload Links)',
			'option'  => 'wp_rocket_settings',
			'check'   => 'preload_links',
		),
		'perfmatters/perfmatters.php' => array(
			'name'    => 'Perfmatters (Instant Page)',
		),
		'nitropack/main.php' => array(
			'name'    => 'NitroPack',
		),
	);

	/**
	 * Translated explanation for each known plugin (kept out of the static
	 * property because a property initializer cannot call __()).
	 *
	 * @param string $basename Plugin basename.
	 * @return string
	 */
	private static function message_for( $basename ) {
		$messages = array(
			'speculation-rules/speculation-rules.php' => __( 'This plugin already outputs its own Speculation Rules. We recommend deactivating it to avoid duplicate prefetches — Transparent Edge Cache includes more complete exclusions and coordination with the CDN.', 'flavor-edge-cache' ),
			'performance-lab/performance-lab.php' => __( 'Performance Lab may include the Speculative Loading module, which outputs duplicate rules.', 'flavor-edge-cache' ),
			'flying-pages/flying-pages.php' => __( 'Flying Pages prefetches links on viewport with JavaScript, which can duplicate Speculation Rules requests.', 'flavor-edge-cache' ),
			'wp-rocket/wp-rocket.php' => __( 'WP Rocket has the “Preload links” option, which duplicates prefetches.', 'flavor-edge-cache' ),
			'perfmatters/perfmatters.php' => __( 'Perfmatters includes instant.page, which duplicates prefetches on hover.', 'flavor-edge-cache' ),
			'nitropack/main.php' => __( 'NitroPack includes its own prefetch/prerender system.', 'flavor-edge-cache' ),
		);
		return isset( $messages[ $basename ] ) ? $messages[ $basename ] : '';
	}

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
				'message' => self::message_for( $basename ),
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
