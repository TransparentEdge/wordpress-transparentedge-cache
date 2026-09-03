<?php
/**
 * Local hardening module.
 *
 * WordPress-level protections that do NOT depend on the CDN and work for
 * any client regardless of contracted services:
 *   - Block PHP execution in /wp-content/uploads/
 *   - Disable xmlrpc.php (with optional whitelist)
 *   - Limit login attempts per IP
 *   - Emit security headers from PHP (fallback when VCL is not applied)
 *
 * @package flavor_edge_cache
 */

namespace flavor_edge;

defined( 'ABSPATH' ) || exit;

class TE_Hardening {

	/**
	 * Login attempts transient prefix.
	 */
	const LOGIN_PREFIX = 'flavor_edge_login_';

	/**
	 * Initialize hardening based on settings.
	 */
	public static function init() {
		$s = TE_Settings::get_all();

		// Disable XML-RPC.
		if ( ! empty( $s['harden_disable_xmlrpc'] ) ) {
			add_filter( 'xmlrpc_enabled', '__return_false' );
			add_filter( 'xmlrpc_methods', array( __CLASS__, 'filter_xmlrpc_methods' ) );
		}

		// Limit login attempts.
		if ( ! empty( $s['harden_limit_login'] ) ) {
			add_action( 'wp_login_failed', array( __CLASS__, 'on_login_failed' ) );
			add_filter( 'authenticate', array( __CLASS__, 'check_login_attempts' ), 30, 3 );
		}

		// Emit security headers from PHP (fallback path).
		if ( ! empty( $s['sec_headers_php_fallback'] ) ) {
			add_action( 'send_headers', array( __CLASS__, 'emit_security_headers' ) );
		}
	}

	/**
	 * Neutralize XML-RPC methods.
	 *
	 * @param array $methods Existing methods.
	 * @return array
	 */
	public static function filter_xmlrpc_methods( $methods ) {
		return array();
	}

	/**
	 * Record a failed login attempt.
	 *
	 * @param string $username Attempted username.
	 */
	public static function on_login_failed( $username ) {
		$ip  = self::get_client_ip();
		$key = self::LOGIN_PREFIX . md5( $ip );
		$attempts = (int) get_transient( $key );
		set_transient( $key, $attempts + 1, 900 ); // 15-minute window.
	}

	/**
	 * Block login if too many failed attempts.
	 *
	 * @param mixed  $user     WP_User, WP_Error, or null.
	 * @param string $username Username.
	 * @param string $password Password.
	 * @return mixed
	 */
	public static function check_login_attempts( $user, $username, $password ) {
		if ( empty( $username ) ) {
			return $user;
		}

		$s        = TE_Settings::get_all();
		$max      = (int) ( $s['harden_login_max_attempts'] ?? 5 );
		$ip       = self::get_client_ip();
		$key      = self::LOGIN_PREFIX . md5( $ip );
		$attempts = (int) get_transient( $key );

		if ( $attempts >= $max ) {
			return new \WP_Error(
				'flavor_edge_locked',
				__( 'Too many failed login attempts. Please try again in a few minutes.', 'flavor-edge-cache' )
			);
		}

		return $user;
	}

	/**
	 * Emit security headers from PHP (fallback when VCL is not applied).
	 */
	public static function emit_security_headers() {
		if ( is_admin() || headers_sent() ) {
			return;
		}

		$headers = TE_Security_Headers::get_enabled_headers();
		foreach ( $headers as $name => $value ) {
			header( $name . ': ' . $value );
		}
	}

	/**
	 * Generate the .htaccess rules to block PHP execution in uploads.
	 *
	 * @return string
	 */
	public static function get_uploads_htaccess_rules() {
		return "# BEGIN Flavor Edge - Block PHP in uploads\n"
			. "<FilesMatch \"\\.(?i:php|php3|php4|php5|php7|phtml|pht)$\">\n"
			. "    Require all denied\n"
			. "</FilesMatch>\n"
			. "# END Flavor Edge - Block PHP in uploads\n";
	}

	/**
	 * Write the uploads .htaccess protection file.
	 *
	 * Preserves any existing .htaccess content, inserting/updating only our
	 * marked block. Never clobbers rules from other plugins.
	 *
	 * @return bool True on success.
	 */
	public static function protect_uploads() {
		$upload_dir = wp_upload_dir();
		$basedir    = $upload_dir['basedir'] ?? '';
		if ( empty( $basedir ) || ! is_dir( $basedir ) || ! is_writable( $basedir ) ) {
			return false;
		}

		$htaccess = trailingslashit( $basedir ) . '.htaccess';
		$our_block = self::get_uploads_htaccess_rules();

		$existing = '';
		if ( file_exists( $htaccess ) ) {
			$existing = file_get_contents( $htaccess ); // phpcs:ignore
			if ( false === $existing ) {
				$existing = '';
			}
		}

		// If our block is already present, replace it; otherwise append.
		$pattern = '/# BEGIN Flavor Edge - Block PHP in uploads.*?# END Flavor Edge - Block PHP in uploads\n?/s';
		if ( preg_match( $pattern, $existing ) ) {
			$new_content = preg_replace( $pattern, $our_block, $existing );
		} else {
			$new_content = rtrim( $existing ) . "\n\n" . $our_block;
			$new_content = ltrim( $new_content );
		}

		return false !== file_put_contents( $htaccess, $new_content ); // phpcs:ignore
	}

	/**
	 * Remove only our block from the uploads .htaccess, preserving the rest.
	 * Deletes the file only if it becomes empty.
	 */
	public static function unprotect_uploads() {
		$upload_dir = wp_upload_dir();
		$basedir    = $upload_dir['basedir'] ?? '';
		$htaccess   = trailingslashit( $basedir ) . '.htaccess';
		if ( ! file_exists( $htaccess ) ) {
			return;
		}

		$content = file_get_contents( $htaccess ); // phpcs:ignore
		if ( false === $content ) {
			return;
		}

		$pattern = '/# BEGIN Flavor Edge - Block PHP in uploads.*?# END Flavor Edge - Block PHP in uploads\n?/s';
		$new_content = preg_replace( $pattern, '', $content );
		$new_content = trim( $new_content );

		if ( '' === $new_content ) {
			// Nothing else in the file — safe to remove.
			@unlink( $htaccess ); // phpcs:ignore
		} else {
			// Preserve the rest.
			file_put_contents( $htaccess, $new_content . "\n" ); // phpcs:ignore
		}
	}

	/**
	 * Nginx equivalent snippet (for display — Nginx has no .htaccess).
	 *
	 * @return string
	 */
	public static function get_uploads_nginx_rules() {
		return "location ~* /wp-content/uploads/.*\\.(?:php|php3|php4|php5|php7|phtml|pht)$ {\n"
			. "    deny all;\n"
			. "}\n";
	}

	/**
	 * Get the client IP.
	 *
	 * Behind the Transparent Edge CDN, the real visitor IP is delivered in the
	 * True-Client-IP header. We check it first, then fall back to the standard
	 * forwarded headers. This prevents counting login failures against a shared
	 * edge IP (which could lock out unrelated visitors).
	 *
	 * @return string
	 */
	private static function get_client_ip() {
		$headers = array(
			'HTTP_TRUE_CLIENT_IP',   // Transparent Edge / CDN real client IP.
			'HTTP_X_FORWARDED_FOR',
			'HTTP_X_REAL_IP',
			'REMOTE_ADDR',
		);
		foreach ( $headers as $h ) {
			if ( ! empty( $_SERVER[ $h ] ) ) {
				$ips = explode( ',', sanitize_text_field( wp_unslash( $_SERVER[ $h ] ) ) );
				$ip  = trim( $ips[0] );
				// Basic validation — ignore obviously invalid values.
				if ( filter_var( $ip, FILTER_VALIDATE_IP ) ) {
					return $ip;
				}
			}
		}
		return '0.0.0.0';
	}
}
