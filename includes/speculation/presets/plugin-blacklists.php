<?php
/**
 * Plugin-specific exclusion paths for Speculation Rules.
 *
 * Maps plugin basename to an array of paths that should be excluded
 * from prefetch/prerender. Only exclusions for active plugins are applied.
 *
 * Keep this file updated with each release as plugins change their URL structures.
 *
 * @package flavor_edge_cache
 */

defined( 'ABSPATH' ) || exit;

return array(

	// BuddyPress / BuddyBoss.
	'buddypress/bp-loader.php' => array(
		'/members/*/messages/*',
		'/activity/*/delete/*',
		'/groups/*/leave-group/*',
		'/groups/*/request-membership/*',
	),

	// bbPress.
	'bbpress/bbpress.php' => array(
		'/forum/*/reply/*',
		'/forum/*/edit/*',
		'/topic/*/reply/*',
		'/topic/*/edit/*',
	),

	// MemberPress.
	'memberpress/memberpress.php' => array(
		'/account/*',
		'/login/*',
		'/register/*',
		'/thankyou/*',
	),

	// Paid Memberships Pro.
	'paid-memberships-pro/paid-memberships-pro.php' => array(
		'/membership-account/*',
		'/membership-checkout/*',
		'/membership-cancel/*',
		'/membership-invoice/*',
	),

	// LearnPress.
	'learnpress/learnpress.php' => array(
		'/lp-quiz/*',
		'/lp-course/*/quiz/*',
		'/lp-course/*/take-course/*',
	),

	// LearnDash.
	'sfwd-lms/sfwd_lms.php' => array(
		'/quizzes/*/start/*',
		'/quizzes/*/resume/*',
		'/courses/*/take-course/*',
	),

	// Easy Digital Downloads.
	'easy-digital-downloads/easy-digital-downloads.php' => array(
		'/checkout/*',
		'/purchase-history/*',
		'/purchase-confirmation/*',
		'/*?edd_action=*',
	),

	// Gravity Forms.
	'gravityforms/gravityforms.php' => array(
		'/*?gf_*',
	),

	// WPForms.
	'wpforms-lite/wpforms.php' => array(
		'/*?wpforms_*',
	),
	'wpforms/wpforms.php' => array(
		'/*?wpforms_*',
	),

	// Restrict Content Pro.
	'restrict-content-pro/restrict-content-pro.php' => array(
		'/register/*',
		'/your-membership/*',
		'/change-password/*',
	),

	// WooCommerce Subscriptions (extends WC exclusions).
	'woocommerce-subscriptions/woocommerce-subscriptions.php' => array(
		'/*?change_subscription_to=*',
		'/*?subscription_renewal=*',
	),

	// LifterLMS.
	'lifterlms/lifterlms.php' => array(
		'/dashboard/*',
		'/*?llms-checkout=*',
	),

	// WordPress Core Speculative Loading (conflict — handled by Conflict_Detector).
	// Not an exclusion, but listed here for documentation purposes.
);
