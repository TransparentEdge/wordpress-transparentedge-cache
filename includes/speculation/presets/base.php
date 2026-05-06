<?php
/**
 * Base exclusion paths for Speculation Rules.
 * Applied to all WordPress sites regardless of plugins/type.
 *
 * @package flavor_edge_cache
 */

defined( 'ABSPATH' ) || exit;

return array(
	// WordPress admin & system.
	'/wp-admin/*',
	'/wp-login.php*',
	'/wp-cron.php*',
	'/xmlrpc.php*',
	'/wp-json/*',
	'/wp-comments-post.php*',

	// Feeds.
	'/feed/*',
	'/*/feed/*',

	// Search (high cardinality, poor cacheability).
	'/?s=*',
	'/search/*',

	// User enumeration.
	'/?author=*',

	// HubSpot tracking parameters.
	'/*?hs_*',
	'/*?hutk=*',

	// Common logout / action URLs.
	'/*?action=logout*',
	'/*?action=lostpassword*',

	// File downloads.
	'/*?download=*',

	// Print views.
	'/*?print=*',
	'/*?pdf=*',
);
