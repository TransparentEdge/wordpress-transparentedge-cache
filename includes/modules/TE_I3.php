<?php
/**
 * i3 Image Optimization module.
 *
 * Integrates with Transparent Edge's i3 service for on-the-fly
 * image transformation: WebP/AVIF conversion, quality adjustment,
 * resizing, and max-length enforcement — all at the edge.
 *
 * @package flavor_edge_cache
 */

namespace flavor_edge;

defined( 'ABSPATH' ) || exit;

class TE_I3 {

	/**
	 * Initialize i3 hooks.
	 */
	public static function init() {
		if ( ! TE_Settings::is_module_enabled( 'i3' ) ) {
			return;
		}

		// Add TCDN-i3 headers for image responses served directly by WP.
		add_action( 'template_redirect', array( __CLASS__, 'maybe_add_i3_headers' ) );

		// Add data attributes to images for lazy loading integration.
		add_filter( 'wp_get_attachment_image_attributes', array( __CLASS__, 'enhance_image_attributes' ), 10, 3 );

		// Modify content to add i3 hints.
		add_filter( 'the_content', array( __CLASS__, 'process_content_images' ), 999 );
	}

	/**
	 * Add TCDN-i3-transform headers if this is an image request.
	 * Note: In most setups, images are served by Nginx/Varnish directly
	 * and never hit PHP. This is a fallback for edge cases.
	 */
	public static function maybe_add_i3_headers() {
		if ( headers_sent() || is_admin() ) {
			return;
		}

		$request_uri = isset( $_SERVER['REQUEST_URI'] ) ? $_SERVER['REQUEST_URI'] : '';

		// Only process image URLs.
		if ( ! preg_match( '/\.(jpe?g|png|gif|webp|avif)(\?.*)?$/i', $request_uri ) ) {
			return;
		}

		$transform = self::build_transform_header();
		if ( $transform ) {
			header( 'TCDN-i3-transform: ' . $transform );
		}

		$s = TE_Settings::get_all();
		if ( ! empty( $s['i3_max_age'] ) ) {
			header( 'TCDN-i3-max-age: ' . (int) $s['i3_max_age'] );
		}
		if ( ! empty( $s['i3_s_maxage'] ) ) {
			header( 'TCDN-i3-s-maxage: ' . (int) $s['i3_s_maxage'] );
		}
	}

	/**
	 * Enhance attachment image attributes.
	 *
	 * @param array    $attr       Image attributes.
	 * @param \WP_Post $attachment Attachment post object.
	 * @param mixed    $size       Requested image size.
	 * @return array
	 */
	public static function enhance_image_attributes( $attr, $attachment, $size ) {
		// Mark images with i3 data attributes for potential JS integration.
		$attr['data-te-i3'] = '1';
		return $attr;
	}

	/**
	 * Process images within post content.
	 *
	 * @param string $content Post content.
	 * @return string Modified content.
	 */
	public static function process_content_images( $content ) {
		if ( empty( $content ) || is_admin() ) {
			return $content;
		}

		// Nothing to modify in content for i3 — the transformation happens
		// at the CDN edge via headers. This filter is reserved for future
		// features like adding width/height hints or srcset optimization.

		return $content;
	}

	/**
	 * Build the TCDN-i3-transform header value.
	 *
	 * @return string
	 */
	public static function build_transform_header() {
		$s     = TE_Settings::get_all();
		$parts = array();

		if ( $s['i3_auto_webp'] ) {
			$quality = (int) $s['i3_quality_webp'];
			if ( $quality > 0 && 80 !== $quality && 100 !== $quality ) {
				$parts[] = 'auto_webp:' . $quality . '%';
			} else {
				$parts[] = 'auto_webp';
			}
		}

		// JPEG quality adjustment (independent of WebP — applies to non-WebP clients).
		if ( $s['i3_quality_jpeg'] > 0 && 100 !== (int) $s['i3_quality_jpeg'] ) {
			$parts[] = 'quality:' . (int) $s['i3_quality_jpeg'] . '%';
		}

		if ( ! empty( $s['i3_max_length'] ) ) {
			$parts[] = 'max_length:' . sanitize_text_field( $s['i3_max_length'] );
		}

		return implode( ' ', $parts );
	}

	/**
	 * Generate VCL snippet for i3 configuration.
	 * Displayed in the admin panel for the user to copy and deploy manually
	 * via the Transparent Edge dashboard.
	 *
	 * @param string $domain The site domain.
	 * @return string VCL code.
	 */
	public static function generate_vcl( $domain = '' ) {
		if ( empty( $domain ) ) {
			$domain = wp_parse_url( home_url(), PHP_URL_HOST );
		}

		$s = TE_Settings::get_all();

		$vcl  = "# ============================================================\n";
		$vcl .= "# Transparent Edge Cache Plugin — i3 Image Optimization\n";
		$vcl .= "# Host: $domain\n";
		$vcl .= "# Generated: " . gmdate( 'Y-m-d H:i:s' ) . " UTC\n";
		$vcl .= "#\n";
		$vcl .= "# Deploy this snippet from the Transparent Edge dashboard:\n";
		$vcl .= "#   dashboard.transparentcdn.com → Configuration → VCL\n";
		$vcl .= "# ============================================================\n\n";

		if ( ! $s['i3_enabled'] ) {
			$vcl .= "# i3 is currently DISABLED in the plugin settings.\n";
			$vcl .= "# Enable it and save to generate the VCL snippet.\n";
			return $vcl;
		}

		$vcl .= "sub vcl_recv {\n";
		$vcl .= "    if (req.http.host == \"$domain\") {\n";

		// Build transform value.
		$transform_parts = array();

		if ( $s['i3_auto_webp'] ) {
			$quality = (int) $s['i3_quality_webp'];
			if ( $quality > 0 && 80 !== $quality && 100 !== $quality ) {
				$transform_parts[] = 'auto_webp:' . $quality . '%';
			} else {
				$transform_parts[] = 'auto_webp';
			}
		}

		$jpeg_quality = (int) $s['i3_quality_jpeg'];
		if ( $jpeg_quality > 0 && 100 !== $jpeg_quality ) {
			$transform_parts[] = 'quality:' . $jpeg_quality . '%';
		}

		if ( ! empty( $s['i3_max_length'] ) ) {
			$transform_parts[] = 'max_length:' . sanitize_text_field( $s['i3_max_length'] );
		}

		$transform = implode( ' ', $transform_parts );

		if ( ! empty( $transform ) ) {
			$vcl .= "        # Apply i3 transformations to raster images\n";
			$vcl .= "        if (urlplus.get_extension() ~ \"(?i)(jpe?g|png|gif)\") {\n";
			$vcl .= "            set req.http.TCDN-i3-transform = \"$transform\";\n";

			if ( ! empty( $s['i3_max_age'] ) ) {
				$vcl .= "            set req.http.TCDN-i3-max-age = \"" . (int) $s['i3_max_age'] . "\";\n";
			}
			if ( ! empty( $s['i3_s_maxage'] ) ) {
				$vcl .= "            set req.http.TCDN-i3-s-maxage = \"" . (int) $s['i3_s_maxage'] . "\";\n";
			}

			$vcl .= "        }\n";
		} else {
			$vcl .= "        # No i3 transformations configured.\n";
		}

		$vcl .= "    }\n";
		$vcl .= "}\n";

		return $vcl;
	}
}
