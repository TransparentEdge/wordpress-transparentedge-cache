<?php
/**
 * Security module coordinator.
 *
 * Initializes the security sub-modules:
 *   - Capabilities (service gating)
 *   - Hardening (local, non-CDN)
 *   - Security headers (VCL recommender)
 *
 * @package flavor_edge_cache
 */

namespace flavor_edge;

defined( 'ABSPATH' ) || exit;

class TE_Security {

	/**
	 * Initialize the security module.
	 */
	public static function init() {
		// Local hardening (WordPress-level, works for everyone).
		TE_Hardening::init();
	}
}
