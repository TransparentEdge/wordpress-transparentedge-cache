<?php
/**
 * Admin panel for Transparent Edge Cache.
 *
 * @package flavor_edge_cache
 */

namespace flavor_edge;

defined( 'ABSPATH' ) || exit;

class TE_Admin {

	/**
	 * Initialize admin hooks.
	 */
	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'register_menu' ) );
		add_action( 'admin_notices', array( __CLASS__, 'show_notices' ) );
		add_action( 'wp_ajax_flavor_edge_test_connection', array( __CLASS__, 'ajax_test_connection' ) );
		add_action( 'wp_ajax_flavor_edge_save_settings', array( __CLASS__, 'ajax_save_settings' ) );
		add_action( 'wp_ajax_flavor_edge_purge_all', array( __CLASS__, 'ajax_purge_all' ) );
		add_action( 'wp_ajax_flavor_edge_wizard_detect', array( __CLASS__, 'ajax_wizard_detect' ) );
		add_action( 'wp_ajax_flavor_edge_wizard_apply', array( __CLASS__, 'ajax_wizard_apply' ) );
		add_action( 'wp_ajax_flavor_edge_preload_start', array( __CLASS__, 'ajax_preload_start' ) );
		add_action( 'wp_ajax_flavor_edge_preload_stop', array( __CLASS__, 'ajax_preload_stop' ) );
		add_action( 'wp_ajax_flavor_edge_preload_status', array( __CLASS__, 'ajax_preload_status' ) );
		add_action( 'wp_ajax_flavor_edge_reset_circuit', array( __CLASS__, 'ajax_reset_circuit' ) );

		// Settings link in plugin list.
		add_filter( 'plugin_action_links_' . FLAVOR_EDGE_BASENAME, array( __CLASS__, 'plugin_action_links' ) );
	}

	/**
	 * The hook suffix returned by add_menu_page.
	 *
	 * @var string
	 */
	private static $page_hook = '';

	/**
	 * Register admin menu.
	 */
	public static function register_menu() {
		self::$page_hook = add_menu_page(
			__( 'Transparent Edge Cache', 'flavor-edge-cache' ),
			__( 'TE Cache', 'flavor-edge-cache' ),
			'manage_options',
			'flavor-edge-cache',
			array( __CLASS__, 'render_page' ),
			'dashicons-performance',
			80
		);

		// Hook enqueue directly to OUR page's load event — guaranteed to match.
		if ( self::$page_hook ) {
			add_action( 'load-' . self::$page_hook, array( __CLASS__, 'enqueue_on_load' ) );
		}
	}

	/**
	 * Called when our admin page loads — enqueue assets here.
	 */
	public static function enqueue_on_load() {
		// This only fires on our page, no hook matching needed.
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'do_enqueue' ) );
	}

	/**
	 * Actually enqueue the assets (called only on our page).
	 */
	public static function do_enqueue() {

		wp_enqueue_style(
			'flavor-edge-admin',
			FLAVOR_EDGE_URL . 'assets/css/admin.css',
			array(),
			FLAVOR_EDGE_VERSION
		);

		wp_enqueue_script(
			'flavor-edge-admin',
			FLAVOR_EDGE_URL . 'assets/js/admin.js',
			array( 'jquery' ),
			FLAVOR_EDGE_VERSION,
			true
		);

		wp_localize_script( 'flavor-edge-admin', 'flavorEdge', array(
			'ajaxUrl' => admin_url( 'admin-ajax.php' ),
			'nonce'   => wp_create_nonce( 'flavor_edge_admin' ),
			'strings' => array(
				'testing'      => __( 'Testing connection...', 'flavor-edge-cache' ),
				'saving'       => __( 'Saving...', 'flavor-edge-cache' ),
				'saved'        => __( 'Settings saved!', 'flavor-edge-cache' ),
				'purging'      => __( 'Purging all cache...', 'flavor-edge-cache' ),
				'purged'       => __( 'All cache purged!', 'flavor-edge-cache' ),
				'error'        => __( 'An error occurred.', 'flavor-edge-cache' ),
				'confirmPurge' => __( 'Are you sure you want to purge all cached content?', 'flavor-edge-cache' ),
			),
		) );
	}

	/**
	 * Render the main admin page.
	 */
	public static function render_page() {
		$settings    = TE_Settings::get_all();
		$connected   = TE_Settings::is_connected();
		$show_wizard = TE_Wizard::should_show();
		$site_info   = TE_Wizard::detect_site_type();

		$view_path = FLAVOR_EDGE_DIR . 'includes/admin/views/main-page.php';

		if ( file_exists( $view_path ) ) {
			include $view_path;
		} else {
			echo '<div class="wrap"><div class="notice notice-error"><p>';
			echo esc_html__( 'Transparent Edge Cache: View file not found. Please reinstall the plugin.', 'flavor-edge-cache' );
			echo '</p></div></div>';
		}
	}

	/**
	 * Show admin notices.
	 */
	public static function show_notices() {
		$notice = get_transient( 'flavor_edge_admin_notice' );
		if ( $notice ) {
			delete_transient( 'flavor_edge_admin_notice' );
			$class = ( false !== strpos( $notice, 'failed' ) || false !== strpos( $notice, 'error' ) ) ? 'notice-error' : 'notice-success';
			printf( '<div class="notice %s is-dismissible"><p>%s</p></div>', esc_attr( $class ), esc_html( $notice ) );
		}

		// Circuit breaker warning.
		if ( TE_Settings::is_connected() ) {
			$circuit = TE_Api::get_circuit_status();
			if ( $circuit['open'] ) {
				$reset_url = wp_nonce_url(
					add_query_arg( 'flavor_edge_action', 'reset_circuit' ),
					'flavor_edge_purge'
				);
				printf(
					'<div class="notice notice-error"><p><strong>%s</strong> %s <a href="%s" class="button button-small" style="margin-left:8px;">%s</a></p></div>',
					esc_html__( '⚠ Transparent Edge API no disponible.', 'flavor-edge-cache' ),
					sprintf(
						/* translators: %d: seconds remaining */
						esc_html__( 'Las funciones de invalidación CDN están suspendidas temporalmente (reintento automático en %d seg). Las optimizaciones locales (minify, lazy load, etc.) siguen activas.', 'flavor-edge-cache' ),
						$circuit['cooldown_remaining']
					),
					esc_url( $reset_url ),
					esc_html__( 'Reintentar ahora', 'flavor-edge-cache' )
				);
			} elseif ( $circuit['failures'] > 0 ) {
				printf(
					'<div class="notice notice-warning is-dismissible"><p>%s</p></div>',
					sprintf(
						/* translators: %d: number of failures */
						esc_html__( 'Transparent Edge API: %d fallo(s) recientes de conexión. El plugin sigue funcionando, pero revisa la configuración si el problema persiste.', 'flavor-edge-cache' ),
						$circuit['failures']
					)
				);
			}
		}

		// Show setup prompt if not connected.
		if ( ! TE_Settings::is_connected() ) {
			$screen  = get_current_screen();
			$s       = TE_Settings::get_all();
			$has_creds = ! empty( $s['client_id'] ) && ! empty( $s['client_secret'] );

			if ( $screen && 'toplevel_page_flavor-edge-cache' !== $screen->id ) {
				if ( $has_creds && ! $s['connected'] ) {
					// Had credentials but health check marked as disconnected.
					$health    = TE_Api::get_health_status();
					$reset_url = wp_nonce_url(
						add_query_arg( 'flavor_edge_action', 'reset_circuit' ),
						'flavor_edge_purge'
					);
					printf(
						'<div class="notice notice-error"><p><strong>%s</strong> %s <a href="%s" class="button button-small" style="margin-left:8px;">%s</a> <a href="%s" style="margin-left:8px;">%s</a></p></div>',
						esc_html__( '⚠ Transparent Edge API desconectada.', 'flavor-edge-cache' ),
						esc_html__( 'La API ha sido inalcanzable durante múltiples verificaciones. Las optimizaciones locales siguen activas. La conexión se restaurará automáticamente cuando la API vuelva a estar disponible.', 'flavor-edge-cache' ),
						esc_url( $reset_url ),
						esc_html__( 'Reintentar ahora', 'flavor-edge-cache' ),
						esc_url( admin_url( 'admin.php?page=flavor-edge-cache' ) ),
						esc_html__( 'Ver configuración', 'flavor-edge-cache' )
					);
				} else {
					// Never configured.
					printf(
						'<div class="notice notice-warning"><p>%s <a href="%s">%s</a></p></div>',
						esc_html__( 'Transparent Edge Cache needs to be configured.', 'flavor-edge-cache' ),
						esc_url( admin_url( 'admin.php?page=flavor-edge-cache' ) ),
						esc_html__( 'Set it up now →', 'flavor-edge-cache' )
					);
				}
			}
		}
	}

	/**
	 * Add settings link to plugin list.
	 *
	 * @param array $links Existing action links.
	 * @return array
	 */
	public static function plugin_action_links( $links ) {
		$url          = admin_url( 'admin.php?page=flavor-edge-cache' );
		$settings_link = sprintf( '<a href="%s">%s</a>', esc_url( $url ), esc_html__( 'Settings', 'flavor-edge-cache' ) );
		array_unshift( $links, $settings_link );
		return $links;
	}

	// -------------------------------------------------------------------------
	// AJAX handlers.
	// -------------------------------------------------------------------------

	/**
	 * Test API connection.
	 */
	public static function ajax_test_connection() {
		check_ajax_referer( 'flavor_edge_admin', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( 'Unauthorized' );
		}

		$company_id    = sanitize_text_field( $_POST['company_id'] ?? '' );
		$client_id     = sanitize_text_field( $_POST['client_id'] ?? '' );
		$client_secret = sanitize_text_field( $_POST['client_secret'] ?? '' );

		$result = TE_Api::test_connection( $company_id, $client_id, $client_secret );

		if ( $result['success'] ) {
			// Save credentials on successful test.
			$settings                  = TE_Settings::get_all();
			$settings['company_id']    = $company_id;
			$settings['client_id']     = $client_id;
			$settings['client_secret'] = $client_secret;
			$settings['connected']     = true;
			TE_Settings::save( $settings );

			// Clear any cached token.
			delete_transient( 'flavor_edge_api_token' );
		}

		wp_send_json( $result );
	}

	/**
	 * Save settings via AJAX.
	 */
	public static function ajax_save_settings() {
		check_ajax_referer( 'flavor_edge_admin', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( 'Unauthorized' );
		}

		$raw      = $_POST['settings'] ?? array();
		$settings = TE_Settings::get_all();

		// Map form fields to settings.
		$checkboxes = array(
			'enabled', 'headers_enabled', 'surrogate_keys', 'vary_device', 'vary_language',
			'invalidation_enabled', 'refetch_enabled',
			'purge_on_post', 'purge_on_comment', 'purge_on_menu', 'purge_on_widget', 'purge_on_theme_switch',
			'i3_enabled', 'i3_auto_webp',
			'minify_html', 'minify_css', 'minify_js', 'combine_css', 'combine_js', 'delay_js', 'defer_js',
			'lazyload_images', 'lazyload_iframes', 'preload_lcp', 'selfhost_google_fonts',
			'woo_exclude_cart', 'woo_exclude_checkout', 'woo_exclude_account', 'woo_purge_stock',
			'preload_sitemap',
			'heartbeat_disable_admin', 'heartbeat_disable_editor',
			'debug_mode',
		);

		foreach ( $checkboxes as $key ) {
			$settings[ $key ] = ! empty( $raw[ $key ] );
		}

		$numbers = array(
			'html_s_maxage', 'html_max_age', 'static_s_maxage', 'static_max_age',
			'i3_quality_jpeg', 'i3_quality_webp', 'i3_s_maxage', 'i3_max_age',
			'debounce_seconds', 'heartbeat_interval',
		);

		foreach ( $numbers as $key ) {
			if ( isset( $raw[ $key ] ) ) {
				$settings[ $key ] = absint( $raw[ $key ] );
			}
		}

		$text_fields = array(
			'invalidation_method', 'i3_max_length', 'delay_js_exclusions', 'defer_js_exclusions', 'combine_js_exclusions',
			'excluded_urls', 'excluded_cookies', 'dns_prefetch_urls', 'accepted_query_strings',
			'heartbeat_behavior',
		);

		foreach ( $text_fields as $key ) {
			if ( isset( $raw[ $key ] ) ) {
				$settings[ $key ] = sanitize_textarea_field( $raw[ $key ] );
			}
		}

		// Connection credentials (also saved via Test Connection, but handle save too).
		$credential_fields = array( 'company_id', 'client_id', 'client_secret' );
		foreach ( $credential_fields as $key ) {
			if ( isset( $raw[ $key ] ) && '' !== $raw[ $key ] ) {
				$settings[ $key ] = sanitize_text_field( $raw[ $key ] );
			}
		}

		TE_Settings::save( $settings );

		do_action( 'flavor_edge_settings_saved', $settings );

		wp_send_json_success( __( 'Settings saved!', 'flavor-edge-cache' ) );
	}

	/**
	 * Purge all via AJAX.
	 */
	public static function ajax_purge_all() {
		check_ajax_referer( 'flavor_edge_admin', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( 'Unauthorized' );
		}

		$result = TE_Api::purge_all();
		wp_send_json( $result );
	}

	/**
	 * Wizard: detect site type.
	 */
	public static function ajax_wizard_detect() {
		check_ajax_referer( 'flavor_edge_admin', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( 'Unauthorized' );
		}

		$detection = TE_Wizard::detect_site_type();
		$detection['recommendations'] = TE_Wizard::get_recommendations(
			$detection['type'],
			$detection['multilingual']
		);

		wp_send_json_success( $detection );
	}

	/**
	 * Wizard: apply configuration.
	 */
	public static function ajax_wizard_apply() {
		check_ajax_referer( 'flavor_edge_admin', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( 'Unauthorized' );
		}

		$type         = sanitize_text_field( $_POST['site_type'] ?? 'corporate' );
		$multilingual = ! empty( $_POST['multilingual'] );
		$credentials  = array(
			'company_id'    => sanitize_text_field( $_POST['company_id'] ?? '' ),
			'client_id'     => sanitize_text_field( $_POST['client_id'] ?? '' ),
			'client_secret' => sanitize_text_field( $_POST['client_secret'] ?? '' ),
		);

		// Test connection first.
		$test = TE_Api::test_connection(
			$credentials['company_id'],
			$credentials['client_id'],
			$credentials['client_secret']
		);

		if ( ! $test['success'] ) {
			wp_send_json_error( $test['message'] );
		}

		TE_Wizard::apply( $type, $multilingual, $credentials );

		wp_send_json_success( __( 'Configuration applied! Your site is now optimized.', 'flavor-edge-cache' ) );
	}

	/**
	 * Start sitemap preload.
	 */
	public static function ajax_preload_start() {
		check_ajax_referer( 'flavor_edge_admin', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( 'Unauthorized' );
		}

		$result = TE_Preload::start();
		wp_send_json( $result );
	}

	/**
	 * Stop sitemap preload.
	 */
	public static function ajax_preload_stop() {
		check_ajax_referer( 'flavor_edge_admin', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( 'Unauthorized' );
		}

		TE_Preload::stop();
		wp_send_json_success( __( 'Preload stopped.', 'flavor-edge-cache' ) );
	}

	/**
	 * Get preload status.
	 */
	public static function ajax_preload_status() {
		check_ajax_referer( 'flavor_edge_admin', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( 'Unauthorized' );
		}

		$status = TE_Preload::get_status();
		wp_send_json_success( $status );
	}

	/**
	 * AJAX handler: reset circuit breaker.
	 */
	public static function ajax_reset_circuit() {
		check_ajax_referer( 'flavor_edge_admin', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( 'Unauthorized' );
		}

		TE_Api::reset_circuit();

		// Try to re-authenticate immediately.
		$token = TE_Api::get_token( true );

		wp_send_json_success( array(
			'reconnected' => false !== $token,
			'circuit'     => TE_Api::get_circuit_status(),
		) );
	}

	/**
	 * Generate VCL snippet for query string stripping.
	 *
	 * @return string VCL code.
	 */
	public static function generate_querystring_vcl() {
		$qs = array_filter( array_map( 'trim', explode( "\n", TE_Settings::get( 'accepted_query_strings', '' ) ) ) );

		if ( empty( $qs ) ) {
			return '# No query strings configured for stripping.';
		}

		$domain = wp_parse_url( home_url(), PHP_URL_HOST );

		// Group params: those starting with common prefixes (utm_, mc_, _g) go into a regex,
		// individual params use query_delete.
		$regex_parts   = array();
		$individual    = array();

		foreach ( $qs as $param ) {
			// Group common tracking prefixes into regex patterns.
			if ( preg_match( '/^(utm_|mc_|_g)/', $param ) ) {
				$regex_parts[] = preg_quote( $param, '/' );
			} else {
				$individual[] = $param;
			}
		}

		$vcl  = "# ============================================================\n";
		$vcl .= "# Transparent Edge Cache Plugin — Query String Stripping\n";
		$vcl .= "# Host: $domain\n";
		$vcl .= "# Generated: " . gmdate( 'Y-m-d H:i:s' ) . " UTC\n";
		$vcl .= "#\n";
		$vcl .= "# Uses urlplus vmod (Varnish Enterprise).\n";
		$vcl .= "# Parse once → delete tracking params → write once.\n";
		$vcl .= "# Strips tracking parameters so they don't create duplicate cache entries.\n";
		$vcl .= "# Deploy from: dashboard.transparentcdn.com → Configuration → VCL\n";
		$vcl .= "# ============================================================\n\n";

		$vcl .= "sub vcl_recv {\n";
		$vcl .= "    if (req.http.host == \"$domain\" && req.url ~ \"\\?\") {\n";
		$vcl .= "        # Parse URL once.\n";
		$vcl .= "        urlplus.parse(req.url);\n\n";

		// Regex batch delete for grouped prefixes.
		if ( ! empty( $regex_parts ) ) {
			$regex = '^(' . implode( '|', $regex_parts ) . ')$';
			$vcl .= "        # Remove tracking params by regex (batch).\n";
			$vcl .= "        urlplus.query_delete_regex(\"$regex\");\n\n";
		}

		// Individual deletes for standalone params.
		if ( ! empty( $individual ) ) {
			$vcl .= "        # Remove individual tracking params.\n";
			foreach ( $individual as $param ) {
				$vcl .= "        urlplus.query_delete(\"$param\");\n";
			}
			$vcl .= "\n";
		}

		$vcl .= "        # Reconstruct URL.\n";
		$vcl .= "        set req.url = urlplus.write();\n";
		$vcl .= "    }\n";
		$vcl .= "}\n";

		return $vcl;
	}

	/**
	 * Generate VCL snippet for cache TTLs (static vs dynamic).
	 *
	 * @return string VCL code.
	 */
	public static function generate_ttl_vcl() {
		$s = TE_Settings::get_all();
		$domain = wp_parse_url( home_url(), PHP_URL_HOST );

		$html_max_age   = (int) $s['html_max_age'];
		$html_s_maxage  = (int) $s['html_s_maxage'];
		$static_max_age = (int) $s['static_max_age'];
		$static_s_maxage = (int) $s['static_s_maxage'];

		$vcl  = "# ============================================================\n";
		$vcl .= "# Transparent Edge Cache Plugin — Cache TTLs\n";
		$vcl .= "# Host: $domain\n";
		$vcl .= "# Generated: " . gmdate( 'Y-m-d H:i:s' ) . " UTC\n";
		$vcl .= "#\n";
		$vcl .= "# Separates static resources (long TTL) from dynamic HTML (short TTL).\n";
		$vcl .= "# Deploy from: dashboard.transparentcdn.com → Configuration → VCL\n";
		$vcl .= "# ============================================================\n\n";

		$vcl .= "sub vcl_backend_response {\n";
		$vcl .= "    if (beresp.status == 200 && bereq.http.host == \"$domain\") {\n\n";

		$vcl .= "        # Static resources: CSS, JS, images, fonts, video\n";
		$vcl .= "        if (urlplus.get_extension() ~ \"(?i)^(axd|bmp|css|eot|gif|ico|img|jpe?g|js|json|mp[34]|mkv|ot[ft]|png|svg|tga|ttf|txt|webp|wmf|woff2?|woff|xml)$\"\n";
		$vcl .= "                || beresp.http.Content-Type ~ \"image/\"\n";
		$vcl .= "                || beresp.http.Content-Type ~ \"text/(css|plain)\"\n";
		$vcl .= "                || beresp.http.Content-Type ~ \"(application|text)/(x-)?javascript\"\n";
		$vcl .= "                || beresp.http.Content-Type ~ \"font/\"\n";
		$vcl .= "                || beresp.http.Content-Type ~ \"video/\") {\n";
		$vcl .= "            set beresp.http.Cache-Control = \"max-age=$static_max_age, s-maxage=$static_s_maxage\";\n";
		$vcl .= "            set beresp.ttl = " . $static_s_maxage . "s;\n";
		$vcl .= "        }\n\n";

		$vcl .= "        # Dynamic content: HTML pages\n";
		$vcl .= "        if (urlplus.get_extension() ~ \"(?i)(html?)\" || beresp.http.Content-Type ~ \"text/html\") {\n";
		$vcl .= "            set beresp.http.Cache-Control = \"max-age=$html_max_age, s-maxage=$html_s_maxage, stale-while-revalidate=60, stale-if-error=86400\";\n";
		$vcl .= "            set beresp.ttl = " . $html_s_maxage . "s;\n";
		$vcl .= "        }\n\n";

		$vcl .= "    }\n";
		$vcl .= "}\n";

		return $vcl;
	}
}
