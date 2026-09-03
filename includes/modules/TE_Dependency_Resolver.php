<?php
/**
 * Script dependency resolver.
 *
 * WordPress tracks dependencies between scripts (a script's ->deps array).
 * When an optimization (delay, defer, combine) is applied to some scripts but
 * not others, the dependency chain can break: if script B depends on script A
 * and B is NOT delayed but A IS, then B runs before A exists → runtime error
 * (e.g. "Backbone is not defined").
 *
 * This resolver computes the set of script handles that must be PROTECTED from
 * an optimization because a non-optimized script depends on them (directly or
 * transitively). It also maps those handles to URL fragments so the existing
 * HTML-based optimizers can honor them as exclusions.
 *
 * The rule enforced: a script may be optimized only if every script that
 * depends on it is also optimized. Equivalently, if a script is excluded from
 * an optimization, all of its dependencies are protected too.
 *
 * @package flavor_edge_cache
 */

namespace flavor_edge;

defined( 'ABSPATH' ) || exit;

class TE_Dependency_Resolver {

	/**
	 * Given a list of "seed" handles that are NOT being optimized (excluded),
	 * return the full set of handles that must be protected — the seeds plus
	 * all their transitive dependencies.
	 *
	 * @param array $excluded_handles Handles known to be excluded from the optimization.
	 * @return array Protected handles (including transitive deps).
	 */
	public static function expand_protected_handles( $excluded_handles ) {
		$wp_scripts = wp_scripts();
		if ( ! $wp_scripts || empty( $wp_scripts->registered ) ) {
			return $excluded_handles;
		}

		$protected = array();
		$stack     = array_values( $excluded_handles );

		while ( ! empty( $stack ) ) {
			$handle = array_pop( $stack );
			if ( isset( $protected[ $handle ] ) ) {
				continue;
			}
			$protected[ $handle ] = true;

			// Add this handle's dependencies to the stack.
			if ( isset( $wp_scripts->registered[ $handle ] ) ) {
				$deps = $wp_scripts->registered[ $handle ]->deps;
				if ( is_array( $deps ) ) {
					foreach ( $deps as $dep ) {
						if ( ! isset( $protected[ $dep ] ) ) {
							$stack[] = $dep;
						}
					}
				}
			}
		}

		return array_keys( $protected );
	}

	/**
	 * Compute the protected handles for an optimization, given which handles
	 * are being optimized.
	 *
	 * A handle is a "breaker" (its dependencies must be protected) when it is
	 * enqueued/registered but is NOT in the optimized set. We seed the
	 * expansion with all such handles so their dependencies are protected.
	 *
	 * @param array $optimized_handles Handles that ARE being optimized (delayed/deferred/combined).
	 * @return array Handles that must be protected from the optimization.
	 */
	public static function compute_protected( $optimized_handles ) {
		$wp_scripts = wp_scripts();
		if ( ! $wp_scripts || empty( $wp_scripts->registered ) ) {
			return array();
		}

		$optimized_set = array_flip( $optimized_handles );

		// Seeds: every registered/enqueued handle that is NOT optimized.
		// Their dependencies must remain available (not optimized away).
		$seeds = array();
		$queue = ! empty( $wp_scripts->queue ) ? $wp_scripts->queue : array_keys( $wp_scripts->registered );

		// Walk the full enqueued set including dependencies to be thorough.
		$all_handles = self::flatten_queue( $queue, $wp_scripts );

		foreach ( $all_handles as $handle ) {
			if ( ! isset( $optimized_set[ $handle ] ) ) {
				$seeds[] = $handle;
			}
		}

		// Protected = seeds + their transitive dependencies.
		$protected = self::expand_protected_handles( $seeds );

		// Remove the seeds themselves from the result if they weren't going to be
		// optimized anyway — the caller only needs the dependency handles. But
		// keeping them is harmless (they're already excluded). We return the deps
		// that are within the optimized set, i.e. the ones we're rescuing.
		$rescued = array();
		foreach ( $protected as $handle ) {
			if ( isset( $optimized_set[ $handle ] ) ) {
				$rescued[] = $handle;
			}
		}

		return $rescued;
	}

	/**
	 * Flatten a queue into the full list of handles including dependencies.
	 *
	 * @param array $queue      Enqueued handles.
	 * @param object $wp_scripts WP_Scripts instance.
	 * @return array
	 */
	private static function flatten_queue( $queue, $wp_scripts ) {
		$result = array();
		$stack  = array_values( $queue );

		while ( ! empty( $stack ) ) {
			$handle = array_pop( $stack );
			if ( isset( $result[ $handle ] ) ) {
				continue;
			}
			$result[ $handle ] = true;

			if ( isset( $wp_scripts->registered[ $handle ] ) ) {
				$deps = $wp_scripts->registered[ $handle ]->deps;
				if ( is_array( $deps ) ) {
					foreach ( $deps as $dep ) {
						if ( ! isset( $result[ $dep ] ) ) {
							$stack[] = $dep;
						}
					}
				}
			}
		}

		return array_keys( $result );
	}

	/**
	 * Map a set of script handles to URL fragments (their src paths).
	 *
	 * The HTML-based optimizers match against script src URLs, so we translate
	 * protected handles into src fragments they can use as exclusions.
	 *
	 * @param array $handles Script handles.
	 * @return array URL fragments (basenames / paths) to exclude.
	 */
	public static function handles_to_src_fragments( $handles ) {
		$wp_scripts = wp_scripts();
		if ( ! $wp_scripts ) {
			return array();
		}

		$fragments = array();
		foreach ( $handles as $handle ) {
			if ( ! isset( $wp_scripts->registered[ $handle ] ) ) {
				continue;
			}
			$src = $wp_scripts->registered[ $handle ]->src;
			if ( ! $src ) {
				continue;
			}

			// Normalize: strip query string and domain, keep a distinctive path fragment.
			$src = strtok( $src, '?' );
			$path = wp_parse_url( $src, PHP_URL_PATH );
			if ( $path ) {
				// Use the filename plus its parent dir for specificity.
				$basename = basename( $path );
				if ( $basename ) {
					$fragments[] = $basename;
				}
			}
		}

		return array_values( array_unique( array_filter( $fragments ) ) );
	}

	/**
	 * Convenience: given the handles being optimized, return the src fragments
	 * that must be added to the optimizer's exclusion list to keep the
	 * dependency tree consistent.
	 *
	 * @param array $optimized_handles Handles being optimized.
	 * @return array Src fragments to add to exclusions.
	 */
	public static function protected_src_fragments( $optimized_handles ) {
		$rescued = self::compute_protected( $optimized_handles );
		return self::handles_to_src_fragments( $rescued );
	}

	/**
	 * Build the list of handles an HTML-src-based optimizer will act on,
	 * by matching enqueued scripts against the optimizer's current exclusions.
	 *
	 * A handle is considered "optimized" if its src does NOT match any exclusion
	 * pattern. This lets us reason about the tree even though the optimizer
	 * itself works on HTML.
	 *
	 * @param array $exclusion_patterns Substring patterns that exclude a script.
	 * @return array Handles that would be optimized.
	 */
	public static function handles_matching_optimization( $exclusion_patterns ) {
		$wp_scripts = wp_scripts();
		if ( ! $wp_scripts || empty( $wp_scripts->registered ) ) {
			return array();
		}

		$patterns = array_map( 'strtolower', array_filter( $exclusion_patterns ) );
		$optimized = array();

		$queue = ! empty( $wp_scripts->queue ) ? $wp_scripts->queue : array_keys( $wp_scripts->registered );
		$all   = self::flatten_queue( $queue, $wp_scripts );

		foreach ( $all as $handle ) {
			if ( ! isset( $wp_scripts->registered[ $handle ] ) ) {
				continue;
			}
			$src = $wp_scripts->registered[ $handle ]->src;
			if ( ! $src ) {
				continue; // Inline/alias handles: skip.
			}
			$src_lower = strtolower( $src ) . ' ' . strtolower( $handle );

			$is_excluded = false;
			foreach ( $patterns as $p ) {
				if ( '' !== $p && false !== strpos( $src_lower, $p ) ) {
					$is_excluded = true;
					break;
				}
			}
			if ( ! $is_excluded ) {
				$optimized[] = $handle;
			}
		}

		return $optimized;
	}
}
