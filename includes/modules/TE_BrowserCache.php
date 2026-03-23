<?php
/**
 * Browser Cache module.
 *
 * Manages Cache-Control headers for static files across different web servers.
 * Auto-detects Apache vs Nginx and acts accordingly:
 *
 * - Apache: Writes mod_expires + mod_headers rules to .htaccess.
 * - Nginx: Shows config snippet in admin panel (Nginx config is read-only from PHP).
 * - Both: Plugin handles invalidation when assets change (media, themes, plugins).
 *
 * For files the plugin generates (minified CSS/JS, cached fonts), headers
 * are sent via PHP directly.
 *
 * @package flavor_edge_cache
 */

namespace flavor_edge;

defined( 'ABSPATH' ) || exit;

class TE_BrowserCache {

	const HTACCESS_MARKER = 'Flavor Edge Cache';

	/**
	 * Initialize.
	 */
	public static function init() {
		if ( ! TE_Settings::get( 'enabled' ) || ! TE_Settings::get( 'headers_enabled' ) ) {
			return;
		}

		// Write .htaccess rules on settings save (Apache only).
		add_action( 'flavor_edge_settings_saved', array( __CLASS__, 'on_settings_saved' ) );

		// Invalidation hooks: purge assets when media/themes/plugins change.
		add_action( 'add_attachment', array( __CLASS__, 'on_media_change' ) );
		add_action( 'edit_attachment', array( __CLASS__, 'on_media_change' ) );
		add_action( 'delete_attachment', array( __CLASS__, 'on_media_change' ) );
		add_action( 'upgrader_process_complete', array( __CLASS__, 'on_asset_change' ), 10, 2 );
		add_action( 'switch_theme', array( __CLASS__, 'on_asset_change' ) );

		// Send Cache-Control for plugin-generated files (minified CSS/JS, cached fonts).
		add_action( 'template_redirect', array( __CLASS__, 'send_static_headers_for_plugin_files' ), 0 );
	}

	// -------------------------------------------------------------------------
	// Server detection.
	// -------------------------------------------------------------------------

	/**
	 * Detect the web server type.
	 *
	 * @return string 'apache', 'nginx', 'litespeed', or 'unknown'.
	 */
	public static function detect_server() {
		$software = isset( $_SERVER['SERVER_SOFTWARE'] ) ? strtolower( $_SERVER['SERVER_SOFTWARE'] ) : '';

		if ( false !== strpos( $software, 'apache' ) ) {
			return 'apache';
		}
		if ( false !== strpos( $software, 'nginx' ) ) {
			return 'nginx';
		}
		if ( false !== strpos( $software, 'litespeed' ) ) {
			return 'litespeed'; // LiteSpeed supports .htaccess.
		}

		// Fallback: check for .htaccess existence.
		if ( file_exists( ABSPATH . '.htaccess' ) ) {
			return 'apache';
		}

		return 'unknown';
	}

	/**
	 * Whether the server supports .htaccess.
	 *
	 * @return bool
	 */
	public static function supports_htaccess() {
		$server = self::detect_server();
		return in_array( $server, array( 'apache', 'litespeed' ), true );
	}

	// -------------------------------------------------------------------------
	// Settings saved handler.
	// -------------------------------------------------------------------------

	/**
	 * On settings save: update .htaccess if Apache, otherwise do nothing.
	 *
	 * @param array $settings Saved settings.
	 */
	public static function on_settings_saved( $settings = array() ) {
		if ( self::supports_htaccess() ) {
			self::update_htaccess();
		}
	}

	// -------------------------------------------------------------------------
	// Apache (.htaccess).
	// -------------------------------------------------------------------------

	/**
	 * Generate .htaccess rules.
	 *
	 * @return string
	 */
	public static function generate_htaccess_rules() {
		$s = TE_Settings::get_all();

		$static_max_age  = (int) $s['static_max_age'];
		$static_s_maxage = (int) $s['static_s_maxage'];

		$rules  = "# Static file cache headers controlled by Transparent Edge Cache plugin.\n";
		$rules .= "# Varnish respects these headers from origin.\n\n";

		$rules .= "<IfModule mod_expires.c>\n";
		$rules .= "    ExpiresActive On\n";
		$rules .= "    ExpiresByType image/jpeg \"access plus 1 year\"\n";
		$rules .= "    ExpiresByType image/png \"access plus 1 year\"\n";
		$rules .= "    ExpiresByType image/gif \"access plus 1 year\"\n";
		$rules .= "    ExpiresByType image/webp \"access plus 1 year\"\n";
		$rules .= "    ExpiresByType image/svg+xml \"access plus 1 year\"\n";
		$rules .= "    ExpiresByType font/woff2 \"access plus 1 year\"\n";
		$rules .= "    ExpiresByType font/woff \"access plus 1 year\"\n";
		$rules .= "    ExpiresByType application/font-woff2 \"access plus 1 year\"\n";
		$rules .= "    ExpiresByType text/css \"access plus 1 year\"\n";
		$rules .= "    ExpiresByType application/javascript \"access plus 1 year\"\n";
		$rules .= "    ExpiresByType video/mp4 \"access plus 1 year\"\n";
		$rules .= "</IfModule>\n\n";

		$rules .= "<IfModule mod_headers.c>\n";
		$rules .= "    <FilesMatch \"\\.(css|js|jpe?g|png|gif|webp|avif|svg|ico|woff2?|ttf|otf|eot|mp4|webm|pdf)$\">\n";
		$rules .= "        Header set Cache-Control \"public, max-age=$static_max_age, s-maxage=$static_s_maxage\"\n";
		$rules .= "    </FilesMatch>\n";
		$rules .= "</IfModule>\n";

		return $rules;
	}

	/**
	 * Write rules to .htaccess.
	 *
	 * @return bool
	 */
	public static function update_htaccess() {
		if ( ! self::supports_htaccess() ) {
			return false;
		}

		$htaccess = ABSPATH . '.htaccess';
		if ( ! file_exists( $htaccess ) || ! is_writable( $htaccess ) ) {
			return false;
		}

		$rules = self::generate_htaccess_rules();
		return insert_with_markers( $htaccess, self::HTACCESS_MARKER, explode( "\n", $rules ) );
	}

	/**
	 * Remove rules from .htaccess.
	 *
	 * @return bool
	 */
	public static function remove_htaccess() {
		$htaccess = ABSPATH . '.htaccess';
		if ( ! file_exists( $htaccess ) || ! is_writable( $htaccess ) ) {
			return false;
		}
		return insert_with_markers( $htaccess, self::HTACCESS_MARKER, array() );
	}

	/**
	 * Check if our rules are in .htaccess.
	 *
	 * @return bool
	 */
	public static function is_htaccess_installed() {
		$htaccess = ABSPATH . '.htaccess';
		if ( ! file_exists( $htaccess ) ) {
			return false;
		}
		$content = file_get_contents( $htaccess );
		return false !== strpos( $content, '# BEGIN ' . self::HTACCESS_MARKER );
	}

	// -------------------------------------------------------------------------
	// Nginx config snippet.
	// -------------------------------------------------------------------------

	/**
	 * Generate Nginx config snippet.
	 *
	 * @return string
	 */
	public static function generate_nginx_snippet() {
		$s = TE_Settings::get_all();

		$static_max_age  = (int) $s['static_max_age'];
		$static_s_maxage = (int) $s['static_s_maxage'];

		$domain = wp_parse_url( home_url(), PHP_URL_HOST );

		$conf  = "# ============================================================\n";
		$conf .= "# Transparent Edge Cache Plugin — Static Cache Headers\n";
		$conf .= "# Host: $domain\n";
		$conf .= "# Generated: " . gmdate( 'Y-m-d H:i:s' ) . " UTC\n";
		$conf .= "#\n";
		$conf .= "# Add this inside your server {} block in Nginx config.\n";
		$conf .= "# Varnish will respect these Cache-Control headers from origin.\n";
		$conf .= "# ============================================================\n\n";

		$conf .= "# Static resources: images, fonts, CSS, JS, video\n";
		$conf .= "location ~* \\.(css|js|jpe?g|png|gif|webp|avif|svg|ico|woff2?|ttf|otf|eot|mp4|webm|pdf)$ {\n";
		$conf .= "    add_header Cache-Control \"public, max-age=$static_max_age, s-maxage=$static_s_maxage\";\n";
		$conf .= "    access_log off;\n";
		$conf .= "}\n";

		return $conf;
	}

	// -------------------------------------------------------------------------
	// Plugin-generated files: send headers via PHP.
	// -------------------------------------------------------------------------

	/**
	 * Send Cache-Control headers for plugin-generated static files.
	 * These files ARE served through PHP rewrite, so we can set headers.
	 */
	public static function send_static_headers_for_plugin_files() {
		if ( is_admin() || headers_sent() ) {
			return;
		}

		$request_uri = isset( $_SERVER['REQUEST_URI'] ) ? $_SERVER['REQUEST_URI'] : '';

		// Our minified files and cached fonts.
		if ( false !== strpos( $request_uri, '/cache/flavor-edge/' ) ) {
			$s = TE_Settings::get_all();
			$static_max_age  = (int) $s['static_max_age'];
			$static_s_maxage = (int) $s['static_s_maxage'];

			header( "Cache-Control: public, max-age=$static_max_age, s-maxage=$static_s_maxage" );
		}
	}

	// -------------------------------------------------------------------------
	// Invalidation hooks for static assets.
	// -------------------------------------------------------------------------

	/**
	 * Handle media upload/edit/delete.
	 *
	 * @param int $attachment_id Attachment ID.
	 */
	public static function on_media_change( $attachment_id ) {
		$url = wp_get_attachment_url( $attachment_id );
		if ( $url ) {
			TE_Invalidation::queue_urls( array( $url ) );
		}

		// Also invalidate thumbnails.
		$metadata = wp_get_attachment_metadata( $attachment_id );
		if ( ! empty( $metadata['sizes'] ) ) {
			$upload_dir = wp_get_upload_dir();
			$base_url   = trailingslashit( $upload_dir['baseurl'] );
			$subdir     = ! empty( $metadata['file'] ) ? trailingslashit( dirname( $metadata['file'] ) ) : '';

			foreach ( $metadata['sizes'] as $size ) {
				if ( ! empty( $size['file'] ) ) {
					TE_Invalidation::queue_urls( array( $base_url . $subdir . $size['file'] ) );
				}
			}
		}
	}

	/**
	 * Handle theme/plugin update — invalidate all CSS/JS.
	 *
	 * @param mixed $upgrader Upgrader object.
	 * @param array $options  Upgrade options.
	 */
	public static function on_asset_change( $upgrader = null, $options = array() ) {
		// BAN all CSS/JS in Varnish.
		if ( TE_Settings::is_connected() ) {
			$domain = wp_parse_url( home_url(), PHP_URL_HOST );
			TE_Api::ban( $domain . '/.*\.(css|js)(\?.*)?$' );
		}

		// Clear minify cache (regenerates with new content).
		if ( class_exists( __NAMESPACE__ . '\\TE_Minify' ) ) {
			TE_Minify::clear_cache();
		}
	}

	// -------------------------------------------------------------------------
	// Admin status helpers.
	// -------------------------------------------------------------------------

	/**
	 * Get the browser cache status for the admin panel.
	 *
	 * @return array { server, method, installed, message }
	 */
	public static function get_status() {
		$server = self::detect_server();

		$status = array(
			'server'    => $server,
			'method'    => 'none',
			'installed' => false,
			'message'   => '',
		);

		if ( self::supports_htaccess() ) {
			$status['method']    = 'htaccess';
			$status['installed'] = self::is_htaccess_installed();
			$status['message']   = $status['installed']
				? __( 'Cache rules active in .htaccess. Varnish respects these TTLs from origin.', 'flavor-edge-cache' )
				: __( 'Rules not in .htaccess. Save settings to write them, or check file permissions.', 'flavor-edge-cache' );
		} elseif ( 'nginx' === $server ) {
			$status['method']  = 'nginx';
			$status['message'] = __( 'Nginx detected. Copy the config snippet below and include it in your server {} block.', 'flavor-edge-cache' );
		} else {
			$status['method']  = 'unknown';
			$status['message'] = __( 'Server type not detected. Configure Cache-Control headers in your web server for static files.', 'flavor-edge-cache' );
		}

		return $status;
	}
}
