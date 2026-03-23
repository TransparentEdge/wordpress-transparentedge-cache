<?php
/**
 * WordPress Multisite compatibility module.
 *
 * Handles network-wide activation, per-site settings with network defaults,
 * network admin page for shared API credentials, and proper Surrogate-Keys
 * per blog.
 *
 * @package flavor_edge_cache
 */

namespace flavor_edge;

defined( 'ABSPATH' ) || exit;

class TE_Multisite {

	/**
	 * Network-level option key.
	 */
	const NETWORK_OPTION_KEY = 'flavor_edge_network_settings';

	/**
	 * Initialize multisite hooks.
	 * Called only when is_multisite() is true.
	 */
	public static function init() {
		if ( ! is_multisite() ) {
			return;
		}

		// Network admin menu.
		add_action( 'network_admin_menu', array( __CLASS__, 'register_network_menu' ) );

		// Handle network admin save.
		add_action( 'wp_ajax_flavor_edge_save_network_settings', array( __CLASS__, 'ajax_save_network_settings' ) );

		// On new blog creation, apply network defaults.
		add_action( 'wp_initialize_site', array( __CLASS__, 'on_new_site' ), 10, 1 );

		// Add Surrogate-Key for the specific blog.
		add_filter( 'flavor_edge_surrogate_keys', array( __CLASS__, 'add_blog_key' ) );

		// Add purge current site to admin bar for network admins.
		add_action( 'admin_bar_menu', array( __CLASS__, 'add_network_admin_bar' ), 101 );
	}

	/**
	 * Register network admin menu.
	 */
	public static function register_network_menu() {
		add_menu_page(
			__( 'TE Cache Network', 'flavor-edge-cache' ),
			__( 'TE Cache', 'flavor-edge-cache' ),
			'manage_network_options',
			'flavor-edge-network',
			array( __CLASS__, 'render_network_page' ),
			'dashicons-performance',
			80
		);
	}

	/**
	 * Render the network admin page.
	 */
	public static function render_network_page() {
		$network_settings = self::get_network_settings();
		$sites            = get_sites( array( 'number' => 100 ) );

		?>
		<div class="wrap te-admin">
			<h1>⚡ <?php esc_html_e( 'Transparent Edge Cache — Network Settings', 'flavor-edge-cache' ); ?></h1>
			<p class="te-description">
				<?php esc_html_e( 'Configure shared API credentials for all sites in the network. Individual sites can override these settings.', 'flavor-edge-cache' ); ?>
			</p>

			<form id="te-network-settings-form">
				<h2><?php esc_html_e( 'Shared API Credentials', 'flavor-edge-cache' ); ?></h2>
				<p class="te-description">
					<?php esc_html_e( 'These credentials are used as defaults for all sites. Each site can override them in their own TE Cache settings.', 'flavor-edge-cache' ); ?>
				</p>

				<table class="form-table">
					<tr>
						<th><label for="network_company_id"><?php esc_html_e( 'Company ID', 'flavor-edge-cache' ); ?></label></th>
						<td><input type="text" id="network_company_id" name="company_id" value="<?php echo esc_attr( $network_settings['company_id'] ); ?>" class="regular-text" /></td>
					</tr>
					<tr>
						<th><label for="network_client_id"><?php esc_html_e( 'Client ID', 'flavor-edge-cache' ); ?></label></th>
						<td><input type="text" id="network_client_id" name="client_id" value="<?php echo esc_attr( $network_settings['client_id'] ); ?>" class="regular-text" /></td>
					</tr>
					<tr>
						<th><label for="network_client_secret"><?php esc_html_e( 'Client Secret', 'flavor-edge-cache' ); ?></label></th>
						<td><input type="password" id="network_client_secret" name="client_secret" value="<?php echo esc_attr( $network_settings['client_secret'] ); ?>" class="regular-text" /></td>
					</tr>
				</table>

				<h2><?php esc_html_e( 'Network Defaults', 'flavor-edge-cache' ); ?></h2>
				<table class="form-table">
					<tr>
						<th><?php esc_html_e( 'Inherit credentials', 'flavor-edge-cache' ); ?></th>
						<td>
							<label>
								<input type="checkbox" name="force_credentials" value="1" <?php checked( $network_settings['force_credentials'] ); ?> />
								<?php esc_html_e( 'Force all sites to use the network API credentials (sites cannot override)', 'flavor-edge-cache' ); ?>
							</label>
						</td>
					</tr>
					<tr>
						<th><?php esc_html_e( 'Activate on all sites', 'flavor-edge-cache' ); ?></th>
						<td>
							<label>
								<input type="checkbox" name="auto_activate" value="1" <?php checked( $network_settings['auto_activate'] ); ?> />
								<?php esc_html_e( 'Automatically enable the plugin on newly created sites', 'flavor-edge-cache' ); ?>
							</label>
						</td>
					</tr>
				</table>

				<h2><?php esc_html_e( 'Sites Overview', 'flavor-edge-cache' ); ?></h2>
				<table class="widefat striped">
					<thead>
						<tr>
							<th><?php esc_html_e( 'Site', 'flavor-edge-cache' ); ?></th>
							<th><?php esc_html_e( 'Domain', 'flavor-edge-cache' ); ?></th>
							<th><?php esc_html_e( 'Plugin Status', 'flavor-edge-cache' ); ?></th>
							<th><?php esc_html_e( 'Connected', 'flavor-edge-cache' ); ?></th>
						</tr>
					</thead>
					<tbody>
					<?php foreach ( $sites as $site ) : ?>
						<?php
						switch_to_blog( $site->blog_id );
						$site_settings = TE_Settings::get_all( true );
						$site_connected = TE_Settings::is_connected();
						$site_enabled   = $site_settings['enabled'];
						restore_current_blog();
						?>
						<tr>
							<td>#<?php echo esc_html( $site->blog_id ); ?></td>
							<td>
								<a href="<?php echo esc_url( get_admin_url( $site->blog_id, 'admin.php?page=flavor-edge-cache' ) ); ?>">
									<?php echo esc_html( $site->domain . $site->path ); ?>
								</a>
							</td>
							<td>
								<?php if ( $site_enabled ) : ?>
									<span style="color:#155724;">✓ <?php esc_html_e( 'Active', 'flavor-edge-cache' ); ?></span>
								<?php else : ?>
									<span style="color:#888;">— <?php esc_html_e( 'Inactive', 'flavor-edge-cache' ); ?></span>
								<?php endif; ?>
							</td>
							<td>
								<?php if ( $site_connected ) : ?>
									<span style="color:#155724;">✓</span>
								<?php else : ?>
									<span style="color:#a00;">✗</span>
								<?php endif; ?>
							</td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>

				<p class="submit">
					<button type="button" id="te-save-network" class="button button-primary">
						<?php esc_html_e( 'Save Network Settings', 'flavor-edge-cache' ); ?>
					</button>
					<button type="button" id="te-purge-all-network" class="button button-secondary">
						<?php esc_html_e( 'Purge All Sites', 'flavor-edge-cache' ); ?>
					</button>
					<span id="te-network-status"></span>
				</p>
			</form>
		</div>

		<script>
		jQuery(function($){
			$('#te-save-network').on('click', function(){
				var $btn = $(this), $status = $('#te-network-status');
				$btn.prop('disabled',true);
				$status.text('Saving...');
				$.post(ajaxurl, {
					action: 'flavor_edge_save_network_settings',
					nonce: '<?php echo esc_js( wp_create_nonce( 'flavor_edge_network' ) ); ?>',
					company_id: $('#network_company_id').val(),
					client_id: $('#network_client_id').val(),
					client_secret: $('#network_client_secret').val(),
					force_credentials: $('input[name=force_credentials]').is(':checked') ? '1' : '',
					auto_activate: $('input[name=auto_activate]').is(':checked') ? '1' : ''
				}, function(res){
					$btn.prop('disabled',false);
					$status.text(res.success ? '✓ Saved' : '✗ Error').css('color', res.success ? '#155724' : '#a00');
					setTimeout(function(){ $status.text(''); }, 3000);
				});
			});

			$('#te-purge-all-network').on('click', function(){
				if (!confirm('Purge cache for ALL sites in the network?')) return;
				var $btn = $(this), $status = $('#te-network-status');
				$btn.prop('disabled',true);
				$status.text('Purging all sites...');
				$.post(ajaxurl, {
					action: 'flavor_edge_purge_all',
					nonce: '<?php echo esc_js( wp_create_nonce( 'flavor_edge_admin' ) ); ?>'
				}, function(res){
					$btn.prop('disabled',false);
					$status.text(res.success ? '✓ All sites purged' : '✗ Error').css('color', res.success ? '#155724' : '#a00');
					setTimeout(function(){ $status.text(''); }, 3000);
				});
			});
		});
		</script>
		<?php
	}

	/**
	 * Save network settings via AJAX.
	 */
	public static function ajax_save_network_settings() {
		check_ajax_referer( 'flavor_edge_network', 'nonce' );

		if ( ! current_user_can( 'manage_network_options' ) ) {
			wp_send_json_error( 'Unauthorized' );
		}

		$settings = array(
			'company_id'        => sanitize_text_field( $_POST['company_id'] ?? '' ),
			'client_id'         => sanitize_text_field( $_POST['client_id'] ?? '' ),
			'client_secret'     => sanitize_text_field( $_POST['client_secret'] ?? '' ),
			'force_credentials' => ! empty( $_POST['force_credentials'] ),
			'auto_activate'     => ! empty( $_POST['auto_activate'] ),
		);

		update_site_option( self::NETWORK_OPTION_KEY, $settings );

		wp_send_json_success();
	}

	/**
	 * Get network-level settings.
	 *
	 * @return array
	 */
	public static function get_network_settings() {
		$defaults = array(
			'company_id'        => '',
			'client_id'         => '',
			'client_secret'     => '',
			'force_credentials' => false,
			'auto_activate'     => true,
		);

		$saved = get_site_option( self::NETWORK_OPTION_KEY, array() );

		return wp_parse_args( $saved, $defaults );
	}

	/**
	 * Get the effective API credentials for the current site.
	 * If network forces credentials, use those. Otherwise, use site-level.
	 *
	 * @return array { company_id, client_id, client_secret }
	 */
	public static function get_effective_credentials() {
		if ( ! is_multisite() ) {
			return array(
				'company_id'    => TE_Settings::get( 'company_id' ),
				'client_id'     => TE_Settings::get( 'client_id' ),
				'client_secret' => TE_Settings::get( 'client_secret' ),
			);
		}

		$network = self::get_network_settings();

		if ( $network['force_credentials'] && ! empty( $network['company_id'] ) ) {
			return array(
				'company_id'    => $network['company_id'],
				'client_id'     => $network['client_id'],
				'client_secret' => $network['client_secret'],
			);
		}

		// Site-level credentials, falling back to network.
		$site_company = TE_Settings::get( 'company_id' );
		$site_client  = TE_Settings::get( 'client_id' );

		if ( ! empty( $site_company ) && ! empty( $site_client ) ) {
			return array(
				'company_id'    => $site_company,
				'client_id'     => TE_Settings::get( 'client_id' ),
				'client_secret' => TE_Settings::get( 'client_secret' ),
			);
		}

		// Fall back to network.
		return array(
			'company_id'    => $network['company_id'],
			'client_id'     => $network['client_id'],
			'client_secret' => $network['client_secret'],
		);
	}

	/**
	 * Apply network defaults to a newly created site.
	 *
	 * @param \WP_Site $site New site object.
	 */
	public static function on_new_site( $site ) {
		$network = self::get_network_settings();

		if ( ! $network['auto_activate'] ) {
			return;
		}

		switch_to_blog( $site->blog_id );

		$defaults             = TE_Settings::defaults();
		$defaults['enabled']  = true;
		$defaults['connected'] = ! empty( $network['company_id'] );

		if ( ! empty( $network['company_id'] ) ) {
			$defaults['company_id']    = $network['company_id'];
			$defaults['client_id']     = $network['client_id'];
			$defaults['client_secret'] = $network['client_secret'];
		}

		TE_Settings::save( $defaults );

		restore_current_blog();
	}

	/**
	 * Add blog-specific Surrogate-Key.
	 *
	 * @param array $keys Existing keys.
	 * @return array
	 */
	public static function add_blog_key( $keys ) {
		// Already added in TE_Headers as 'site-{blog_id}'.
		// Add a network-wide key for purging all sites at once.
		$keys[] = 'network-' . get_current_network_id();
		return $keys;
	}

	/**
	 * Add network purge option to admin bar.
	 *
	 * @param \WP_Admin_Bar $wp_admin_bar Admin bar instance.
	 */
	public static function add_network_admin_bar( $wp_admin_bar ) {
		if ( ! current_user_can( 'manage_network_options' ) ) {
			return;
		}

		if ( ! TE_Settings::is_connected() ) {
			return;
		}

		// Only add if the main TE Cache node exists.
		$node = $wp_admin_bar->get_node( 'flavor-edge' );
		if ( ! $node ) {
			return;
		}

		$wp_admin_bar->add_node( array(
			'id'     => 'flavor-edge-purge-site',
			'parent' => 'flavor-edge',
			'title'  => sprintf(
				/* translators: %s: blog domain */
				__( 'Purge This Site (%s)', 'flavor-edge-cache' ),
				wp_parse_url( home_url(), PHP_URL_HOST )
			),
			'href'   => wp_nonce_url(
				add_query_arg( array(
					'flavor_edge_action' => 'purge_site',
					'flavor_edge_blog'   => get_current_blog_id(),
				) ),
				'flavor_edge_purge'
			),
		) );
	}
}
