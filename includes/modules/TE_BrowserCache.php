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
		add_action( 'add_attachment', array( __CLASS__, 'on_media_add' ) );
		add_action( 'edit_attachment', array( __CLASS__, 'on_media_edit' ) );
		add_action( 'delete_attachment', array( __CLASS__, 'on_media_delete' ) );
		add_action( 'upgrader_process_complete', array( __CLASS__, 'on_asset_change' ), 10, 2 );
		add_action( 'switch_theme', array( __CLASS__, 'on_asset_change' ) );

		// Verify what browsers actually receive (see probe()): from the existing
		// health-check cron (throttled) and on demand from the panel.
		add_action( TE_Api::HEALTH_CRON_HOOK, array( __CLASS__, 'maybe_probe' ), 20 );
		add_action( 'admin_init', array( __CLASS__, 'handle_probe_request' ) );
	}

	// -------------------------------------------------------------------------
	// Immutable (content-addressed) files.
	// -------------------------------------------------------------------------

	/** Browser/CDN lifetime for files whose name changes with their content. */
	const IMMUTABLE_MAX_AGE = 31536000;

	/** Option holding the last probe result. */
	const PROBE_OPTION = 'flavor_edge_static_probe';

	/**
	 * URL path of the minify output directory. Every file there is named after
	 * its content (per-asset: source path + mtime; bundles: handles + versions +
	 * mtimes), so a given URL never changes content: it can be cached for a year
	 * and marked immutable, unlike unversioned theme files.
	 *
	 * @return string e.g. /wp-content/cache/flavor-edge/min/
	 */
	public static function immutable_path() {
		$path = (string) wp_parse_url( content_url( TE_Minify::CACHE_DIR ), PHP_URL_PATH );
		return '/' . trim( $path, '/' ) . '/';
	}

	/**
	 * URL path of the self-hosted Google Fonts directory. Its font files are
	 * named font-<md5 of the versioned fonts.gstatic.com URL>.<ext>, so a name
	 * never changes content either (the gf-*.css next to them is rebuilt in
	 * place and is NOT immutable).
	 *
	 * @return string e.g. /wp-content/cache/flavor-edge/fonts/
	 */
	public static function fonts_path() {
		$path = (string) wp_parse_url( content_url( TE_GoogleFonts::CACHE_DIR ), PHP_URL_PATH );
		return '/' . trim( $path, '/' ) . '/';
	}

	/** Regex (PCRE, unanchored path suffix) matching the immutable font files. */
	const FONT_FILE_RE = 'font-[0-9a-f]{32}\\.(woff2?|ttf|otf|eot|svg)$';

	/**
	 * Cache-Control for content-addressed files.
	 *
	 * @return string
	 */
	public static function immutable_cache_control() {
		return 'public, max-age=' . self::IMMUTABLE_MAX_AGE . ', s-maxage=' . self::IMMUTABLE_MAX_AGE . ', immutable';
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
		} else {
			self::write_nginx_conf();
		}
		self::probe();
	}

	// -------------------------------------------------------------------------
	// nginx: rules file kept by the plugin (include it once).
	// -------------------------------------------------------------------------

	/**
	 * Absolute path of the nginx rules file the plugin keeps up to date. nginx
	 * does not read per-directory files, so the server block must include it
	 * once; after that a TTL change only needs an nginx reload, not a new copy
	 * and paste (same approach as W3 Total Cache's nginx.conf).
	 *
	 * @return string
	 */
	public static function nginx_conf_path() {
		return WP_CONTENT_DIR . '/cache/flavor-edge/nginx.conf';
	}

	/**
	 * Write the nginx rules file when its content changed.
	 *
	 * @return bool True when the file is current.
	 */
	public static function write_nginx_conf() {
		$file    = self::nginx_conf_path();
		$content = self::generate_nginx_snippet( true );
		if ( file_exists( $file ) && file_get_contents( $file ) === $content ) {
			return true;
		}
		$dir = dirname( $file );
		if ( ! is_dir( $dir ) ) {
			wp_mkdir_p( $dir );
		}
		if ( ! is_dir( $dir ) || ! is_writable( $dir ) ) {
			return false;
		}
		return TE_ObjectCache::write_atomic( $file, $content );
	}

	/**
	 * Whether the nginx rules file exists and matches the current settings.
	 *
	 * @return bool
	 */
	public static function nginx_conf_current() {
		$file = self::nginx_conf_path();
		return file_exists( $file ) && file_get_contents( $file ) === self::generate_nginx_snippet( true );
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
		self::update_min_dir_htaccess();
		self::update_fonts_dir_htaccess();
		return insert_with_markers( $htaccess, self::HTACCESS_MARKER, explode( "\n", $rules ) );
	}

	/**
	 * Apache: self-hosted font files (content-addressed) served for a year as
	 * immutable; the font CSS keeps the generic static rule.
	 *
	 * @return bool
	 */
	public static function update_fonts_dir_htaccess() {
		$dir = WP_CONTENT_DIR . '/' . TE_GoogleFonts::CACHE_DIR;
		if ( ! is_dir( $dir ) || ! is_writable( $dir ) ) {
			return false;
		}
		$content  = "# Self-hosted font files (Transparent Edge Cache): named after their source, cache for a year.\n";
		$content .= "<IfModule mod_headers.c>\n    <FilesMatch \"^" . self::FONT_FILE_RE . "\">\n        Header set Cache-Control \"" . self::immutable_cache_control() . "\"\n    </FilesMatch>\n</IfModule>\n";
		$file = $dir . '/.htaccess';
		if ( file_exists( $file ) && file_get_contents( $file ) === $content ) {
			return true;
		}
		return false !== file_put_contents( $file, $content );
	}

	/**
	 * Apache: the minify directory gets its own .htaccess so its content-addressed
	 * files are served as immutable for a year (a deeper .htaccess overrides the
	 * generic rule in the root one).
	 *
	 * @return bool
	 */
	public static function update_min_dir_htaccess() {
		$dir = WP_CONTENT_DIR . '/' . TE_Minify::CACHE_DIR;
		if ( ! is_dir( $dir ) || ! is_writable( $dir ) ) {
			return false;
		}
		$content  = "# Serve directly\n<IfModule mod_rewrite.c>\nRewriteEngine Off\n</IfModule>\n";
		$content .= "# Content-addressed files (Transparent Edge Cache): cache for a year.\n";
		$content .= "<IfModule mod_headers.c>\n    Header set Cache-Control \"" . self::immutable_cache_control() . "\"\n</IfModule>\n";
		$file = $dir . '/.htaccess';
		if ( file_exists( $file ) && file_get_contents( $file ) === $content ) {
			return true;
		}
		return false !== file_put_contents( $file, $content );
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
	public static function generate_nginx_snippet( $for_file = false ) {
		$s = TE_Settings::get_all();

		$static_max_age  = (int) $s['static_max_age'];
		$static_s_maxage = (int) $s['static_s_maxage'];

		$domain = wp_parse_url( home_url(), PHP_URL_HOST );

		$conf  = "# ============================================================\n";
		$conf .= "# Transparent Edge Cache Plugin — Static Cache Headers\n";
		$conf .= "# Host: $domain\n";
		if ( $for_file ) {
			$conf .= "# Kept up to date by the plugin. Include it ONCE in the server {} block:\n";
			$conf .= "#   include " . self::nginx_conf_path() . ";\n";
			$conf .= "# then: nginx -t && reload nginx. Reload again after changing the TTLs.\n";
		} else {
			$conf .= "# Generated: " . gmdate( 'Y-m-d H:i:s' ) . " UTC\n";
			$conf .= "#\n";
			$conf .= "# Add this inside your server {} block in Nginx config.\n";
		}
		$conf .= "# Varnish will respect these Cache-Control headers from origin.\n";
		$conf .= "# ============================================================\n\n";

		$conf .= "# NOTES\n";
		$conf .= "# - nginx uses the FIRST matching regex location. If your config already has\n";
		$conf .= "#   a location for these extensions, put the add_header lines in that one.\n";
		$conf .= "# - add_header inside a location replaces the server-level add_header lines\n";
		$conf .= "#   (HSTS, CSP…): repeat them here if you rely on them for static files.\n\n";

		$conf .= "# Files generated by the plugin are named after their content: immutable.\n";
		$conf .= "location ^~ " . self::immutable_path() . " {\n";
		$conf .= "    add_header Cache-Control \"" . self::immutable_cache_control() . "\";\n";
		$conf .= "    access_log off;\n";
		$conf .= "}\n\n";

		$conf .= "# Self-hosted Google Fonts files (named after their versioned source): immutable.\n";
		$conf .= "location ~ ^" . self::fonts_path() . self::FONT_FILE_RE . " {\n";
		$conf .= "    add_header Cache-Control \"" . self::immutable_cache_control() . "\";\n";
		$conf .= "    access_log off;\n";
		$conf .= "}\n\n";

		$conf .= "# Static resources: images, fonts, CSS, JS, video\n";
		$conf .= "location ~* \\.(css|js|jpe?g|png|gif|webp|avif|svg|ico|woff2?|ttf|otf|eot|mp4|webm|pdf)$ {\n";
		$conf .= "    add_header Cache-Control \"public, max-age=$static_max_age, s-maxage=$static_s_maxage\";\n";
		$conf .= "    access_log off;\n";
		$conf .= "}\n";

		return $conf;
	}

	// -------------------------------------------------------------------------
	// Transparent Edge VCL snippet (propose only).
	// -------------------------------------------------------------------------

	/**
	 * VCL for the Transparent Edge dashboard: applies the static TTLs at the
	 * edge when the origin sends no Cache-Control (typical on nginx without the
	 * snippet), and marks the plugin's content-addressed files immutable. The
	 * plugin never deploys VCL.
	 *
	 * @return string
	 */
	public static function generate_vcl_snippet() {
		$s       = TE_Settings::get_all();
		$max_age = (int) $s['static_max_age'];
		$s_max   = max( 1, (int) $s['static_s_maxage'] );
		$domain  = (string) wp_parse_url( home_url(), PHP_URL_HOST );
		$imm     = self::IMMUTABLE_MAX_AGE;
		$path    = str_replace( '.', '\\.', self::immutable_path() ); // VCL (PCRE) regex; paths carry no other metacharacters.

		$vcl  = "# ── Static files: browser + edge lifetime ──────────────────────\n";
		$vcl .= "# Generated by Transparent Edge Cache plugin — PROPOSE ONLY, review first.\n";
		$vcl .= "# Deploy in: Configuration → VCL → vcl_backend_response\n";
		$vcl .= "# 1) Files generated by the plugin (named after their content): 1 year, immutable.\n";
		$vcl .= "# 2) Other static files: only when the origin sends no max-age itself.\n";
		$vcl .= "# ────────────────────────────────────────────────────────────────\n\n";
		$vcl .= "sub vcl_backend_response {\n";
		$vcl .= "    if (bereq.http.host == \"$domain\") {\n";
		$fonts = str_replace( '.', '\\.', self::fonts_path() );
		$vcl .= "        if (bereq.url ~ \"^$path\" || bereq.url ~ \"^$fonts" . self::FONT_FILE_RE . "\") {\n";
		$vcl .= "            set beresp.ttl = {$imm}s;\n";
		$vcl .= "            set beresp.http.Cache-Control = \"public, max-age=$imm, immutable\";\n";
		$vcl .= "        } elsif (bereq.url ~ \"(?i)\\.(css|js|jpe?g|png|gif|webp|avif|svg|ico|woff2?|ttf|otf|eot|mp4|webm|pdf)(\\?.*)?$\"\n";
		$vcl .= "                 && beresp.status == 200\n";
		$vcl .= "                 && beresp.http.Cache-Control !~ \"max-age\") {\n";
		$vcl .= "            set beresp.ttl = {$s_max}s;\n";
		$vcl .= "            set beresp.http.Cache-Control = \"public, max-age=$max_age\";\n";
		$vcl .= "        }\n";
		$vcl .= "    }\n";
		$vcl .= "}\n";
		return $vcl;
	}

	// -------------------------------------------------------------------------
	// Verification: what does a browser actually receive?
	// -------------------------------------------------------------------------

	/**
	 * Request one of the plugin's own static files over HTTP (through the CDN,
	 * as a visitor would) and record the Cache-Control that comes back. Turns
	 * "rules written but not applied" (nginx without the snippet, a missing
	 * mod_headers…) into a visible status instead of a silent assumption.
	 *
	 * @return array Probe result (also stored in PROBE_OPTION).
	 */
	public static function probe() {
		$s      = TE_Settings::get_all();
		$target = self::probe_target();
		$result = array(
			't'        => time(),
			'url'      => $target['url'],
			'expected' => $target['immutable'] ? self::IMMUTABLE_MAX_AGE : (int) $s['static_max_age'],
			'status'   => 0,
			'cc'       => '',
			'max_age'  => null,
			'state'    => 'error',
		);
		$resp = wp_remote_get( add_query_arg( 'fe_probe', time(), $target['url'] ), array(
			'timeout'     => 4,
			'redirection' => 2,
			'headers'     => array( 'Accept' => '*/*' ),
		) );
		if ( ! is_wp_error( $resp ) ) {
			$result['status'] = (int) wp_remote_retrieve_response_code( $resp );
			$result['cc']     = substr( (string) wp_remote_retrieve_header( $resp, 'cache-control' ), 0, 200 );
			if ( preg_match( '/(?:^|[,\s])max-age\s*=\s*(\d+)/i', $result['cc'], $m ) ) {
				$result['max_age'] = (int) $m[1];
			}
			if ( 200 !== $result['status'] ) {
				$result['state'] = 'error';
			} elseif ( null === $result['max_age'] || 0 === $result['max_age'] ) {
				$result['state'] = 'missing';
			} elseif ( $result['max_age'] < $result['expected'] ) {
				$result['state'] = 'lower';
			} else {
				$result['state'] = 'ok';
			}
		} else {
			$result['cc'] = substr( $resp->get_error_message(), 0, 200 );
		}
		update_option( self::PROBE_OPTION, $result, false );
		return $result;
	}

	/**
	 * The file to probe: the newest minified file when there is one (that is
	 * what visitors download), else the plugin's own admin stylesheet.
	 *
	 * @return array { url, immutable }
	 */
	private static function probe_target() {
		$dir   = WP_CONTENT_DIR . '/' . TE_Minify::CACHE_DIR;
		$files = is_dir( $dir ) ? (array) glob( $dir . '/*.{css,js}', GLOB_BRACE ) : array();
		if ( $files ) {
			usort( $files, function ( $a, $b ) { return (int) @filemtime( $b ) - (int) @filemtime( $a ); } );
			return array( 'url' => content_url( TE_Minify::CACHE_DIR . '/' . basename( $files[0] ) ), 'immutable' => true );
		}
		return array( 'url' => FLAVOR_EDGE_URL . 'assets/css/admin.css', 'immutable' => false );
	}

	/**
	 * Health-check hook: re-probe at most every 6 hours.
	 */
	public static function maybe_probe() {
		$last = get_option( self::PROBE_OPTION );
		if ( is_array( $last ) && ! empty( $last['t'] ) && ( time() - (int) $last['t'] ) < 6 * HOUR_IN_SECONDS ) {
			return;
		}
		self::probe();
	}

	/**
	 * "Check now" link in the panel.
	 */
	public static function handle_probe_request() {
		if ( empty( $_GET['flavor_edge_probe_static'] ) || ! current_user_can( 'manage_options' ) ) {
			return;
		}
		if ( ! wp_verify_nonce( $_GET['_wpnonce'] ?? '', 'flavor_edge_probe_static' ) ) {
			return;
		}
		self::probe();
		wp_safe_redirect( remove_query_arg( array( 'flavor_edge_probe_static', '_wpnonce' ) ) . '#cache' );
		exit;
	}

	// -------------------------------------------------------------------------
	// Invalidation hooks for static assets.
	// -------------------------------------------------------------------------

	/** Postmeta key holding the tracked file signature (mtime:size). */
	const MEDIA_SIG_META = '_fe_file_sig';

	/**
	 * Media UPLOAD: a fresh upload has a brand-new URL that was never cached, so
	 * there is nothing to invalidate. Just record the file's signature so a later
	 * same-URL replacement can be detected and purged surgically.
	 *
	 * @param int $attachment_id Attachment ID.
	 */
	public static function on_media_add( $attachment_id ) {
		self::update_media_signature( $attachment_id );
	}

	/**
	 * Media EDIT: honour the rule — invalidate ONLY when the file's bytes actually
	 * changed (a real same-URL replacement or thumbnail regeneration), NEVER on a
	 * metadata-only edit (alt text, caption, title). Core image edits create NEW
	 * files (new URLs), so the old cached URLs are untouched; a same-URL replacement
	 * changes the file's mtime/size, which we detect against the stored signature.
	 *
	 * @param int $attachment_id Attachment ID.
	 */
	public static function on_media_edit( $attachment_id ) {
		$prev = get_post_meta( $attachment_id, self::MEDIA_SIG_META, true );
		$now  = self::media_signature( $attachment_id );

		if ( '' !== $now ) {
			self::update_media_signature( $attachment_id, $now );
		}

		// No baseline yet (image predates this tracking): we cannot confirm the
		// bytes changed, so — invalidating only what changed — we set the baseline
		// and do NOT purge. The next real change is caught.
		if ( '' === $prev ) {
			return;
		}

		// Baseline exists: purge ONLY if the bytes actually changed.
		if ( $now !== $prev ) {
			self::queue_media_urls( $attachment_id );
		}
	}

	/**
	 * Media DELETE: the files are gone, so purge the URL and its thumbnails (they
	 * were cached and would now 404).
	 *
	 * @param int $attachment_id Attachment ID.
	 */
	public static function on_media_delete( $attachment_id ) {
		self::queue_media_urls( $attachment_id );
	}

	/**
	 * Signature of an attachment's main file (mtime:size) — changes iff the bytes
	 * on disk change. Empty string when the file cannot be read.
	 *
	 * @param int $attachment_id Attachment ID.
	 * @return string
	 */
	private static function media_signature( $attachment_id ) {
		$file = get_attached_file( $attachment_id );
		if ( ! $file || ! @file_exists( $file ) ) {
			return '';
		}
		return (string) @filemtime( $file ) . ':' . (string) @filesize( $file );
	}

	/**
	 * Store the current file signature for an attachment.
	 *
	 * @param int         $attachment_id Attachment ID.
	 * @param string|null $sig           Precomputed signature, or null to compute.
	 */
	private static function update_media_signature( $attachment_id, $sig = null ) {
		if ( null === $sig ) {
			$sig = self::media_signature( $attachment_id );
		}
		if ( '' !== $sig ) {
			update_post_meta( $attachment_id, self::MEDIA_SIG_META, $sig );
		}
	}

	/**
	 * Queue the attachment's own URL plus every registered thumbnail size for
	 * eviction (soft, evict-only — static bytes are never refetched; see R7).
	 *
	 * @param int $attachment_id Attachment ID.
	 */
	private static function queue_media_urls( $attachment_id ) {
		$url = wp_get_attachment_url( $attachment_id );
		if ( $url ) {
			TE_Invalidation::queue_urls( array( $url ), 'media #' . $attachment_id );
		}

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
		// Only theme/plugin code changes what pages load (new ?ver= on their CSS/JS).
		// Translation packs and core updates go through the same hook but do not
		// change front-end assets, and a bulk update fires it once per item: purge
		// at most once per request, and only when it can matter. Filterable, so a
		// high-traffic site can turn this off and purge by hand after updates.
		$type = is_array( $options ) && isset( $options['type'] ) ? (string) $options['type'] : ( 'switch_theme' === current_action() ? 'theme' : '' );
		$purge = in_array( $type, array( 'plugin', 'theme' ), true );
		$purge = (bool) apply_filters( 'flavor_edge_purge_on_asset_change', $purge, $type, $options );
		static $done = false;
		if ( $purge && ! $done && TE_Settings::is_connected() ) {
			$done = true;
			$site_tag = 'site-' . get_current_blog_id();
			TE_Api::set_context( 'theme/plugin update' );
			TE_Api::purge_tags( array( $site_tag ), true );
			TE_Api::set_context( '' );
		}

		// Minify bundles: do NOT hard-delete them here. Their filenames are content-
		// addressed, so an asset-version bump (which a plugin/theme update causes)
		// already makes the next render emit NEW bundle names; deleting the OLD ones
		// synchronously is exactly what 404s the HTML the edge is still serving stale
		// against them (the soft purge above serves stale while it revalidates). Leave
		// the old bundles in place and let the background GC reap the orphans after a
		// grace window that outlasts the stale-HTML revalidation.
		if ( class_exists( __NAMESPACE__ . '\\TE_Minify' ) ) {
			TE_Minify::schedule_gc();
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
			$status['message'] = __( 'Nginx: these TTLs are NOT applied automatically — nginx does not read .htaccess. Until the snippet below is installed in the server block (or the VCL snippet in Transparent Edge), browsers get no max-age from the origin.', 'flavor-edge-cache' );
		} else {
			$status['method']  = 'unknown';
			$status['message'] = __( 'Server type not detected. Configure Cache-Control headers in your web server for static files.', 'flavor-edge-cache' );
		}

		$probe = get_option( self::PROBE_OPTION );
		$status['probe'] = is_array( $probe ) ? $probe : null;

		return $status;
	}
}
