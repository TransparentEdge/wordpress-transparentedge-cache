<?php
/**
 * Main admin page template.
 *
 * @package flavor_edge_cache
 * @var array $settings    Current settings.
 * @var bool  $connected   Whether the API is connected.
 * @var bool  $show_wizard Whether to show the setup wizard.
 * @var array $site_info   Detected site type info.
 */

defined( 'ABSPATH' ) || exit;
?>
<div class="wrap te-admin">

	<div class="te-header">
		<h1>
			<span class="te-logo">⚡</span>
			<?php esc_html_e( 'Transparent Edge Cache', 'flavor-edge-cache' ); ?>
			<span class="te-version">v<?php echo esc_html( FLAVOR_EDGE_VERSION ); ?></span>
		</h1>
		<?php if ( $connected ) : ?>
			<span class="te-badge te-badge--success"><?php esc_html_e( 'Connected', 'flavor-edge-cache' ); ?></span>
		<?php else : ?>
			<span class="te-badge te-badge--warning"><?php esc_html_e( 'Not Connected', 'flavor-edge-cache' ); ?></span>
		<?php endif; ?>
	</div>

	<div id="te-notices"></div>

	<?php if ( $show_wizard ) : ?>
	<!-- SETUP WIZARD -->
	<div id="te-wizard" class="te-wizard-panel">
		<h2>🚀 <?php esc_html_e( 'Quick Setup', 'flavor-edge-cache' ); ?></h2>

		<div class="te-wizard-detected">
			<p>
				<?php esc_html_e( 'We detected your site as:', 'flavor-edge-cache' ); ?>
				<strong><?php echo esc_html( $site_info['label'] ); ?></strong>
				<?php if ( ! empty( $site_info['plugins'] ) ) : ?>
					<span class="te-detected-plugins">(<?php echo esc_html( implode( ', ', $site_info['plugins'] ) ); ?>)</span>
				<?php endif; ?>
				<?php if ( $site_info['multilingual'] ) : ?>
					— <span class="te-detected-multilingual"><?php esc_html_e( 'Multilingual', 'flavor-edge-cache' ); ?></span>
				<?php endif; ?>
			</p>
		</div>

		<!-- Step 1: Site type confirmation -->
		<div class="te-wizard-step" data-step="1">
			<h3><?php esc_html_e( 'Step 1: Confirm your site type', 'flavor-edge-cache' ); ?></h3>
			<p class="te-description"><?php esc_html_e( 'We\'ll optimize cache settings based on your site type.', 'flavor-edge-cache' ); ?></p>
			<div class="te-wizard-types">
				<label class="te-wizard-type <?php echo 'blog' === $site_info['type'] ? 'selected' : ''; ?>">
					<input type="radio" name="wizard_type" value="blog" <?php checked( 'blog', $site_info['type'] ); ?>>
					<strong>📝 <?php esc_html_e( 'Blog / Magazine', 'flavor-edge-cache' ); ?></strong>
					<span><?php esc_html_e( 'Long CDN TTL, Delay JS enabled', 'flavor-edge-cache' ); ?></span>
				</label>
				<label class="te-wizard-type <?php echo 'corporate' === $site_info['type'] ? 'selected' : ''; ?>">
					<input type="radio" name="wizard_type" value="corporate" <?php checked( 'corporate', $site_info['type'] ); ?>>
					<strong>🏢 <?php esc_html_e( 'Corporate / Institutional', 'flavor-edge-cache' ); ?></strong>
					<span><?php esc_html_e( 'Long CDN TTL, balanced config', 'flavor-edge-cache' ); ?></span>
				</label>
				<label class="te-wizard-type <?php echo 'woocommerce' === $site_info['type'] ? 'selected' : ''; ?>">
					<input type="radio" name="wizard_type" value="woocommerce" <?php checked( 'woocommerce', $site_info['type'] ); ?>>
					<strong>🛒 <?php esc_html_e( 'WooCommerce / Online Store', 'flavor-edge-cache' ); ?></strong>
					<span><?php esc_html_e( 'Short TTL, cart/checkout excluded', 'flavor-edge-cache' ); ?></span>
				</label>
				<label class="te-wizard-type <?php echo 'membership' === $site_info['type'] ? 'selected' : ''; ?>">
					<input type="radio" name="wizard_type" value="membership" <?php checked( 'membership', $site_info['type'] ); ?>>
					<strong>🔐 <?php esc_html_e( 'Membership / LMS', 'flavor-edge-cache' ); ?></strong>
					<span><?php esc_html_e( 'Short TTL, safe JS settings', 'flavor-edge-cache' ); ?></span>
				</label>
			</div>
		</div>

		<!-- Step 2: API credentials -->
		<div class="te-wizard-step" data-step="2">
			<h3><?php esc_html_e( 'Step 2: Connect to Transparent Edge', 'flavor-edge-cache' ); ?></h3>
			<p class="te-description"><?php esc_html_e( 'Enter your credentials from the TE dashboard (Settings → API).', 'flavor-edge-cache' ); ?></p>
			<table class="form-table">
				<tr>
					<th><label for="wizard_company_id"><?php esc_html_e( 'Company ID', 'flavor-edge-cache' ); ?></label></th>
					<td><input type="text" id="wizard_company_id" class="regular-text" /></td>
				</tr>
				<tr>
					<th><label for="wizard_client_id"><?php esc_html_e( 'Client ID', 'flavor-edge-cache' ); ?></label></th>
					<td><input type="text" id="wizard_client_id" class="regular-text" /></td>
				</tr>
				<tr>
					<th><label for="wizard_client_secret"><?php esc_html_e( 'Client Secret', 'flavor-edge-cache' ); ?></label></th>
					<td><input type="password" id="wizard_client_secret" class="regular-text" /></td>
				</tr>
			</table>
		</div>

		<p class="te-wizard-actions">
			<button type="button" id="te-wizard-apply" class="button button-primary button-hero">
				<?php esc_html_e( 'Connect & Configure', 'flavor-edge-cache' ); ?>
			</button>
			<button type="button" id="te-wizard-skip" class="button button-link">
				<?php esc_html_e( 'Skip wizard, configure manually', 'flavor-edge-cache' ); ?>
			</button>
			<span id="te-wizard-status"></span>
		</p>
	</div>
	<?php endif; ?>

	<div class="te-tabs">
		<button class="te-tab active" data-tab="dashboard"><?php esc_html_e( 'Dashboard', 'flavor-edge-cache' ); ?></button>
		<button class="te-tab" data-tab="connection"><?php esc_html_e( 'Connection', 'flavor-edge-cache' ); ?></button>
		<button class="te-tab" data-tab="cache"><?php esc_html_e( 'Cache', 'flavor-edge-cache' ); ?></button>
		<button class="te-tab" data-tab="invalidation"><?php esc_html_e( 'Invalidation', 'flavor-edge-cache' ); ?></button>
		<button class="te-tab" data-tab="i3"><?php esc_html_e( 'i3 Images', 'flavor-edge-cache' ); ?></button>
		<button class="te-tab" data-tab="optimization"><?php esc_html_e( 'Optimization', 'flavor-edge-cache' ); ?></button>
		<?php if ( \flavor_edge\TE_Speculation_Rules::is_available() ) : ?>
		<button class="te-tab" data-tab="speculation"><?php esc_html_e( 'Speculation Rules', 'flavor-edge-cache' ); ?></button>
		<?php endif; ?>
		<button class="te-tab" data-tab="advanced"><?php esc_html_e( 'Advanced', 'flavor-edge-cache' ); ?></button>
		<button class="te-tab" data-tab="log"><?php esc_html_e( 'Invalidation', 'flavor-edge-cache' ); ?></button>
	</div>

	<form id="te-settings-form">

		<!-- DASHBOARD TAB -->
		<div class="te-panel active" data-panel="dashboard">
			<h2><?php esc_html_e( 'Performance Dashboard', 'flavor-edge-cache' ); ?></h2>
			<div id="te-dashboard-grid" style="display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:16px;margin-bottom:20px;">
				<div class="te-dash-card">
					<h4><?php esc_html_e( 'CDN Status', 'flavor-edge-cache' ); ?></h4>
					<span class="te-dash-value" style="color:<?php echo $connected ? '#155724' : '#a00'; ?>;">
						<?php echo $connected ? esc_html__( '✓ Active', 'flavor-edge-cache' ) : esc_html__( '✗ Not connected', 'flavor-edge-cache' ); ?>
					</span>
					<span class="te-dash-detail">
						<?php if ( $connected ) : ?>
							CDN TTL: <?php echo esc_html( $settings['html_s_maxage'] ); ?>s
							| <?php echo esc_html( ucfirst( $settings['invalidation_method'] ) ); ?> purge
						<?php endif; ?>
					</span>
				</div>
				<div class="te-dash-card">
					<h4><?php esc_html_e( 'Object Cache', 'flavor-edge-cache' ); ?></h4>
					<?php
					$oc_stats = \flavor_edge\TE_ObjectCache::get_stats();
					$oc_dropin = \flavor_edge\TE_ObjectCache::get_dropin_status();
					$backends = \flavor_edge\TE_ObjectCache::detect_backends();
					?>
					<span class="te-dash-value" style="color:<?php echo $oc_stats['enabled'] ? '#155724' : '#888'; ?>;">
						<?php echo $oc_stats['enabled'] ? esc_html( $oc_stats['hit_ratio'] . '% hit ratio' ) : esc_html__( 'Disabled', 'flavor-edge-cache' ); ?>
					</span>
					<span class="te-dash-detail">
						<?php if ( $oc_stats['enabled'] ) : ?>
							<?php echo esc_html( number_format( $oc_stats['hits'] ) ); ?> hits / <?php echo esc_html( number_format( $oc_stats['misses'] ) ); ?> misses
						<?php elseif ( $backends['recommended'] ) : ?>
							<?php echo esc_html( ucfirst( $backends['recommended'] ) . ' available' ); ?>
						<?php elseif ( ! empty( $backends['redis_server'] ) ) : ?>
							<?php if ( ! empty( $backends['redis_needs_auth'] ) ) : ?>
								<span style="color:#856404;">⚠ <?php esc_html_e( 'Redis server detected, but requires a password.', 'flavor-edge-cache' ); ?></span>
								<br><code style="font-size:11px;background:#f0f0f0;padding:2px 6px;">define('WP_REDIS_PASSWORD', 'your-password');</code>
								<br><span class="description" style="font-size:11px;"><?php esc_html_e( 'Add this to wp-config.php, then install phpredis:', 'flavor-edge-cache' ); ?></span>
								<br><code style="font-size:11px;background:#f0f0f0;padding:2px 6px;">sudo apt install php-redis && sudo systemctl restart php-fpm</code>
							<?php else : ?>
								<span style="color:#856404;">⚠ <?php esc_html_e( 'Redis server detected, but PHP extension (phpredis) is not installed.', 'flavor-edge-cache' ); ?></span>
								<br><code style="font-size:11px;background:#f0f0f0;padding:2px 6px;">sudo apt install php-redis && sudo systemctl restart php-fpm</code>
							<?php endif; ?>
						<?php else : ?>
							<?php esc_html_e( 'No backend detected', 'flavor-edge-cache' ); ?>
						<?php endif; ?>
					</span>
					<?php if ( ! $oc_stats['enabled'] && ! empty( $backends['recommended'] ) ) : ?>
						<button type="button" class="button button-small te-enable-object-cache" data-backend="<?php echo esc_attr( $backends['recommended'] ); ?>" style="margin-top:8px;">
							<?php printf( esc_html__( 'Enable %s', 'flavor-edge-cache' ), esc_html( ucfirst( $backends['recommended'] ) ) ); ?>
						</button>
					<?php elseif ( $oc_stats['enabled'] && $oc_dropin['ours'] ) : ?>
						<button type="button" class="button button-small te-flush-object-cache" style="margin-top:8px;"><?php esc_html_e( 'Flush', 'flavor-edge-cache' ); ?></button>
						<button type="button" class="button button-small te-disable-object-cache" style="margin-top:8px;"><?php esc_html_e( 'Disable', 'flavor-edge-cache' ); ?></button>
					<?php endif; ?>
				</div>
				<div class="te-dash-card">
					<h4><?php esc_html_e( 'Minify Cache', 'flavor-edge-cache' ); ?></h4>
					<?php $minify_stats = \flavor_edge\TE_Minify::get_cache_stats(); ?>
					<span class="te-dash-value"><?php echo esc_html( $minify_stats['files'] ); ?> <?php esc_html_e( 'files', 'flavor-edge-cache' ); ?></span>
					<span class="te-dash-detail">
						<?php echo esc_html( $minify_stats['css_files'] ); ?> CSS, <?php echo esc_html( $minify_stats['js_files'] ); ?> JS
						— <?php echo esc_html( size_format( $minify_stats['total_size'] ) ); ?>
					</span>
					<?php if ( $minify_stats['files'] > 0 ) : ?>
						<button type="button" class="button button-small te-clear-minify-cache" style="margin-top:8px;"><?php esc_html_e( 'Clear Minify Cache', 'flavor-edge-cache' ); ?></button>
					<?php endif; ?>
				</div>
				<div class="te-dash-card">
					<h4><?php esc_html_e( 'WPO Features', 'flavor-edge-cache' ); ?></h4>
					<?php
					$wpo_count = 0;
					$wpo_features = array( 'minify_html', 'minify_css', 'minify_js', 'delay_js', 'defer_js', 'lazyload_images', 'lazyload_iframes', 'preload_lcp', 'selfhost_google_fonts', 'i3_enabled' );
					foreach ( $wpo_features as $f ) { if ( $settings[ $f ] ) $wpo_count++; }
					?>
					<span class="te-dash-value"><?php echo esc_html( $wpo_count . '/' . count( $wpo_features ) ); ?></span>
					<span class="te-dash-detail"><?php esc_html_e( 'optimizations active', 'flavor-edge-cache' ); ?></span>
				</div>
				<div class="te-dash-card">
					<h4><?php esc_html_e( 'Invalidation History', 'flavor-edge-cache' ); ?></h4>
					<?php if ( $connected ) : ?>
						<a href="https://dashboard.transparentcdn.com/<?php echo esc_attr( $settings['company_id'] ); ?>/invalidation" target="_blank" class="button button-small" style="margin-top:4px;">
							<?php esc_html_e( 'View in TE Dashboard →', 'flavor-edge-cache' ); ?>
						</a>
						<span class="te-dash-detail" style="margin-top:6px;"><?php esc_html_e( 'All purge events are logged in the Transparent Edge dashboard.', 'flavor-edge-cache' ); ?></span>
					<?php else : ?>
						<span class="te-dash-detail"><?php esc_html_e( 'Connect to view invalidation history.', 'flavor-edge-cache' ); ?></span>
					<?php endif; ?>
				</div>
				<div class="te-dash-card">
					<h4><?php esc_html_e( 'Server', 'flavor-edge-cache' ); ?></h4>
					<span class="te-dash-detail">
						PHP <?php echo esc_html( PHP_VERSION ); ?> |
						WP <?php echo esc_html( get_bloginfo( 'version' ) ); ?> |
						Plugin v<?php echo esc_html( FLAVOR_EDGE_VERSION ); ?>
					</span>
				</div>
			</div>
		</div>

		<!-- CONNECTION TAB -->
		<div class="te-panel" data-panel="connection">
			<h2><?php esc_html_e( 'API Connection', 'flavor-edge-cache' ); ?></h2>
			<p class="te-description"><?php esc_html_e( 'Enter your Transparent Edge credentials. You can find them in the TE dashboard under Settings → API.', 'flavor-edge-cache' ); ?></p>

			<table class="form-table">
				<tr>
					<th><?php esc_html_e( 'Plugin Enabled', 'flavor-edge-cache' ); ?></th>
					<td><label><input type="checkbox" name="enabled" value="1" <?php checked( $settings['enabled'] ); ?> /> <?php esc_html_e( 'Enable Transparent Edge Cache (master switch)', 'flavor-edge-cache' ); ?></label></td>
				</tr>
				<tr>
					<th><label for="company_id"><?php esc_html_e( 'Company ID', 'flavor-edge-cache' ); ?></label></th>
					<td><input type="text" id="company_id" name="company_id" value="<?php echo esc_attr( $settings['company_id'] ); ?>" class="regular-text" /></td>
				</tr>
				<tr>
					<th><label for="client_id"><?php esc_html_e( 'Client ID', 'flavor-edge-cache' ); ?></label></th>
					<td><input type="text" id="client_id" name="client_id" value="<?php echo esc_attr( $settings['client_id'] ); ?>" class="regular-text" /></td>
				</tr>
				<tr>
					<th><label for="client_secret"><?php esc_html_e( 'Client Secret', 'flavor-edge-cache' ); ?></label></th>
					<td><input type="password" id="client_secret" name="client_secret" value="<?php echo esc_attr( $settings['client_secret'] ); ?>" class="regular-text" /></td>
				</tr>
			</table>

			<p class="te-actions">
				<button type="button" id="te-test-connection" class="button button-secondary">
					<?php esc_html_e( 'Test Connection', 'flavor-edge-cache' ); ?>
				</button>
				<span id="te-connection-status"></span>
			</p>

			<?php if ( $connected ) : ?>
			<div class="te-quick-actions">
				<h3><?php esc_html_e( 'Quick Actions', 'flavor-edge-cache' ); ?></h3>
				<button type="button" id="te-purge-all" class="button button-primary button-hero">
					<?php esc_html_e( '🗑️ Purge All Cache', 'flavor-edge-cache' ); ?>
				</button>
				<button type="button" id="te-preload-start" class="button button-secondary button-hero">
					<?php esc_html_e( '🔄 Preload Cache via Sitemap', 'flavor-edge-cache' ); ?>
				</button>
				<button type="button" id="te-preload-stop" class="button button-secondary" style="display:none;">
					<?php esc_html_e( '⏹ Stop Preload', 'flavor-edge-cache' ); ?>
				</button>
				<span id="te-preload-status"></span>
			</div>
			<?php endif; ?>
		</div>

		<!-- CACHE TAB -->
		<div class="te-panel" data-panel="cache">
			<h2><?php esc_html_e( 'Cache Headers', 'flavor-edge-cache' ); ?></h2>
			<p class="te-description"><?php esc_html_e( 'Control how Varnish and browsers cache your content. s-maxage is the CDN TTL (Varnish), max-age is for visitors\' browsers.', 'flavor-edge-cache' ); ?></p>

			<table class="form-table">
				<tr>
					<th><?php esc_html_e( 'Enable Cache Headers', 'flavor-edge-cache' ); ?></th>
					<td><label><input type="checkbox" name="headers_enabled" value="1" <?php checked( $settings['headers_enabled'] ); ?> /> <?php esc_html_e( 'Send Cache-Control, Surrogate-Keys, and Vary headers', 'flavor-edge-cache' ); ?></label></td>
				</tr>

				<tr>
					<th colspan="2"><h3 style="margin:0"><?php esc_html_e( 'Dynamic Content (HTML)', 'flavor-edge-cache' ); ?></h3></th>
				</tr>
				<tr>
					<th colspan="2">
						<p class="description" style="font-weight:normal;margin:0;"><?php esc_html_e( 'Pages, posts, archives, REST API — generated by PHP. These headers are sent by the plugin directly.', 'flavor-edge-cache' ); ?></p>
					</th>
				</tr>
				<tr>
					<th><label for="html_s_maxage"><?php esc_html_e( 'CDN TTL (s-maxage)', 'flavor-edge-cache' ); ?></label></th>
					<td>
						<input type="number" id="html_s_maxage" name="html_s_maxage" value="<?php echo esc_attr( $settings['html_s_maxage'] ); ?>" min="0" class="small-text" />
						<span class="description"><?php esc_html_e( 'seconds. Default: 172800 (48h). How long Varnish keeps HTML pages.', 'flavor-edge-cache' ); ?></span>
					</td>
				</tr>
				<tr>
					<th><label for="html_max_age"><?php esc_html_e( 'Browser TTL (max-age)', 'flavor-edge-cache' ); ?></label></th>
					<td>
						<input type="number" id="html_max_age" name="html_max_age" value="<?php echo esc_attr( $settings['html_max_age'] ); ?>" min="0" class="small-text" />
						<span class="description"><?php esc_html_e( 'seconds. Default: 3600 (1h). Reduces unnecessary checks to CDN nodes.', 'flavor-edge-cache' ); ?></span>
					</td>
				</tr>

				<tr>
					<th colspan="2"><h3 style="margin:0"><?php esc_html_e( 'Static Resources (CSS, JS, images, fonts, video)', 'flavor-edge-cache' ); ?></h3></th>
				</tr>
				<tr>
					<th colspan="2">
						<p class="description" style="font-weight:normal;margin:0;"><?php esc_html_e( 'Static files are served by the web server, not PHP. The plugin sets Cache-Control headers so Varnish respects these TTLs from the origin. When assets change (media uploads, theme/plugin updates), the plugin invalidates them automatically.', 'flavor-edge-cache' ); ?></p>
					</th>
				</tr>
				<tr>
					<th><label for="static_s_maxage"><?php esc_html_e( 'CDN TTL (s-maxage)', 'flavor-edge-cache' ); ?></label></th>
					<td>
						<input type="number" id="static_s_maxage" name="static_s_maxage" value="<?php echo esc_attr( $settings['static_s_maxage'] ); ?>" min="0" class="small-text" />
						<span class="description"><?php esc_html_e( 'seconds. Default: 2592000 (30 days).', 'flavor-edge-cache' ); ?></span>
					</td>
				</tr>
				<tr>
					<th><label for="static_max_age"><?php esc_html_e( 'Browser TTL (max-age)', 'flavor-edge-cache' ); ?></label></th>
					<td>
						<input type="number" id="static_max_age" name="static_max_age" value="<?php echo esc_attr( $settings['static_max_age'] ); ?>" min="0" class="small-text" />
						<span class="description"><?php esc_html_e( 'seconds. Default: 86400 (1 day).', 'flavor-edge-cache' ); ?></span>
					</td>
				</tr>
				<tr>
					<td colspan="2">
						<?php $bc_status = \flavor_edge\TE_BrowserCache::get_status(); ?>
						<p>
							<strong><?php esc_html_e( 'Server:', 'flavor-edge-cache' ); ?></strong>
							<?php echo esc_html( ucfirst( $bc_status['server'] ) ); ?> —
							<?php if ( $bc_status['installed'] ) : ?>
								<span style="color:#155724;">✓ <?php echo esc_html( $bc_status['message'] ); ?></span>
							<?php elseif ( 'nginx' === $bc_status['server'] ) : ?>
								<span style="color:#0073aa;"><?php echo esc_html( $bc_status['message'] ); ?></span>
							<?php else : ?>
								<span style="color:#856404;">⚠ <?php echo esc_html( $bc_status['message'] ); ?></span>
							<?php endif; ?>
						</p>

						<?php if ( 'nginx' === $bc_status['server'] || 'unknown' === $bc_status['server'] ) : ?>
						<details style="margin-top:8px;">
							<summary style="cursor:pointer;font-weight:600;font-size:13px;color:#0073aa;">
								<?php esc_html_e( 'Nginx config snippet (add to your server {} block)', 'flavor-edge-cache' ); ?>
							</summary>
							<div style="position:relative;margin-top:8px;">
								<pre id="te-nginx-snippet"><code><?php echo esc_html( \flavor_edge\TE_BrowserCache::generate_nginx_snippet() ); ?></code></pre>
								<button type="button" class="button button-secondary te-copy-btn" data-target="te-nginx-snippet" style="position:absolute;top:8px;right:8px;">
									<?php esc_html_e( '📋 Copy', 'flavor-edge-cache' ); ?>
								</button>
							</div>
						</details>
						<?php endif; ?>

						<p class="description" style="margin-top:8px;">
							<?php esc_html_e( 'Static asset invalidation is automatic: media uploads, theme and plugin updates trigger Varnish purge. WordPress version strings (?ver=X) on CSS/JS handle browser cache-busting.', 'flavor-edge-cache' ); ?>
						</p>
					</td>
				</tr>

				<tr>
					<th colspan="2"><h3 style="margin:0"><?php esc_html_e( 'Cache Variants', 'flavor-edge-cache' ); ?></h3></th>
				</tr>
				<tr>
					<th><?php esc_html_e( 'Surrogate-Keys', 'flavor-edge-cache' ); ?></th>
					<td><label><input type="checkbox" name="surrogate_keys" value="1" <?php checked( $settings['surrogate_keys'] ); ?> /> <?php esc_html_e( 'Tag every page with content identifiers for surgical cache invalidation', 'flavor-edge-cache' ); ?></label></td>
				</tr>
				<tr>
					<th><?php esc_html_e( 'Vary by Device', 'flavor-edge-cache' ); ?></th>
					<td><label><input type="checkbox" name="vary_device" value="1" <?php checked( $settings['vary_device'] ); ?> /> <?php esc_html_e( 'Separate cache for mobile and desktop (uses X-Device header)', 'flavor-edge-cache' ); ?></label></td>
				</tr>
				<tr>
					<th><?php esc_html_e( 'Vary by Language', 'flavor-edge-cache' ); ?></th>
					<td><label><input type="checkbox" name="vary_language" value="1" <?php checked( $settings['vary_language'] ); ?> /> <?php esc_html_e( 'Separate cache per language (enable if using WPML/Polylang)', 'flavor-edge-cache' ); ?></label></td>
				</tr>
			</table>
		</div>

		<!-- INVALIDATION TAB -->
		<div class="te-panel" data-panel="invalidation">
			<h2><?php esc_html_e( 'Cache Invalidation', 'flavor-edge-cache' ); ?></h2>
			<p class="te-description"><?php esc_html_e( 'Configure when and how cached content is automatically purged from the CDN.', 'flavor-edge-cache' ); ?></p>

			<table class="form-table">
				<tr>
					<th><?php esc_html_e( 'Auto-invalidation', 'flavor-edge-cache' ); ?></th>
					<td><label><input type="checkbox" name="invalidation_enabled" value="1" <?php checked( $settings['invalidation_enabled'] ); ?> /> <?php esc_html_e( 'Automatically purge cache when content changes', 'flavor-edge-cache' ); ?></label></td>
				</tr>
				<tr>
					<th><?php esc_html_e( 'Invalidation Method', 'flavor-edge-cache' ); ?></th>
					<td>
						<select name="invalidation_method">
							<option value="soft" <?php selected( $settings['invalidation_method'], 'soft' ); ?>><?php esc_html_e( 'Soft Purge (recommended — serves stale if origin fails)', 'flavor-edge-cache' ); ?></option>
							<option value="hard" <?php selected( $settings['invalidation_method'], 'hard' ); ?>><?php esc_html_e( 'Hard Purge (immediately removes from cache)', 'flavor-edge-cache' ); ?></option>
						</select>
					</td>
				</tr>
				<tr>
					<th><?php esc_html_e( 'Refetch after purge', 'flavor-edge-cache' ); ?></th>
					<td><label><input type="checkbox" name="refetch_enabled" value="1" <?php checked( $settings['refetch_enabled'] ); ?> /> <?php esc_html_e( 'Warm up cache immediately after invalidation (no cold MISSes)', 'flavor-edge-cache' ); ?></label></td>
				</tr>
				<tr>
					<th colspan="2"><h3 style="margin:0"><?php esc_html_e( 'Purge triggers', 'flavor-edge-cache' ); ?></h3></th>
				</tr>
				<tr>
					<th><?php esc_html_e( 'Posts & Pages', 'flavor-edge-cache' ); ?></th>
					<td><label><input type="checkbox" name="purge_on_post" value="1" <?php checked( $settings['purge_on_post'] ); ?> /> <?php esc_html_e( 'Purge when a post, page, or CPT is published, updated, or deleted', 'flavor-edge-cache' ); ?></label></td>
				</tr>
				<tr>
					<th><?php esc_html_e( 'Comments', 'flavor-edge-cache' ); ?></th>
					<td><label><input type="checkbox" name="purge_on_comment" value="1" <?php checked( $settings['purge_on_comment'] ); ?> /> <?php esc_html_e( 'Purge when a new comment is approved or edited', 'flavor-edge-cache' ); ?></label></td>
				</tr>
				<tr>
					<th><?php esc_html_e( 'Menus', 'flavor-edge-cache' ); ?></th>
					<td><label><input type="checkbox" name="purge_on_menu" value="1" <?php checked( $settings['purge_on_menu'] ); ?> /> <?php esc_html_e( 'Purge when a navigation menu is updated', 'flavor-edge-cache' ); ?></label></td>
				</tr>
				<tr>
					<th><?php esc_html_e( 'Widgets', 'flavor-edge-cache' ); ?></th>
					<td><label><input type="checkbox" name="purge_on_widget" value="1" <?php checked( $settings['purge_on_widget'] ); ?> /> <?php esc_html_e( 'Purge when sidebar widgets are updated', 'flavor-edge-cache' ); ?></label></td>
				</tr>
				<tr>
					<th><?php esc_html_e( 'Theme Switch', 'flavor-edge-cache' ); ?></th>
					<td><label><input type="checkbox" name="purge_on_theme_switch" value="1" <?php checked( $settings['purge_on_theme_switch'] ); ?> /> <?php esc_html_e( 'Full purge when the active theme is changed', 'flavor-edge-cache' ); ?></label></td>
				</tr>
				<tr>
					<th><?php esc_html_e( 'Plugin Changes', 'flavor-edge-cache' ); ?></th>
					<td><label><input type="checkbox" name="purge_on_plugin_change" value="1" <?php checked( $settings['purge_on_plugin_change'] ); ?> /> <?php esc_html_e( 'Full purge when any plugin is activated or deactivated', 'flavor-edge-cache' ); ?></label></td>
				</tr>
				<tr>
					<th colspan="2"><h3 style="margin:0"><?php esc_html_e( 'Cache warming', 'flavor-edge-cache' ); ?></h3></th>
				</tr>
				<tr>
					<th><?php esc_html_e( 'Preload via Sitemap', 'flavor-edge-cache' ); ?></th>
					<td><label><input type="checkbox" name="preload_sitemap" value="1" <?php checked( $settings['preload_sitemap'] ); ?> /> <?php esc_html_e( 'Crawl sitemap.xml after a full purge to warm the CDN cache (no cold MISSes)', 'flavor-edge-cache' ); ?></label></td>
				</tr>
			</table>
		</div>

		<!-- i3 TAB -->
		<div class="te-panel" data-panel="i3">
			<h2><?php esc_html_e( 'i3 Image Optimization', 'flavor-edge-cache' ); ?></h2>
			<p class="te-description"><?php esc_html_e( 'Optimize images on-the-fly at the CDN edge. No processing on your server, zero latency.', 'flavor-edge-cache' ); ?></p>

			<table class="form-table">
				<tr>
					<th><?php esc_html_e( 'Enable i3', 'flavor-edge-cache' ); ?></th>
					<td><label><input type="checkbox" name="i3_enabled" value="1" <?php checked( $settings['i3_enabled'] ); ?> /> <?php esc_html_e( 'Activate on-the-fly image optimization', 'flavor-edge-cache' ); ?></label></td>
				</tr>
				<tr>
					<th><?php esc_html_e( 'Auto WebP', 'flavor-edge-cache' ); ?></th>
					<td><label><input type="checkbox" name="i3_auto_webp" value="1" <?php checked( $settings['i3_auto_webp'] ); ?> /> <?php esc_html_e( 'Automatically convert images to WebP for supported browsers', 'flavor-edge-cache' ); ?></label></td>
				</tr>
				<tr>
					<th><label for="i3_quality_jpeg"><?php esc_html_e( 'JPEG Quality', 'flavor-edge-cache' ); ?></label></th>
					<td>
						<input type="range" id="i3_quality_jpeg" name="i3_quality_jpeg" value="<?php echo esc_attr( $settings['i3_quality_jpeg'] ); ?>" min="30" max="100" oninput="document.getElementById('i3_quality_jpeg_val').textContent=this.value" />
						<span id="i3_quality_jpeg_val"><?php echo esc_html( $settings['i3_quality_jpeg'] ); ?></span>%
					</td>
				</tr>
				<tr>
					<th><label for="i3_quality_webp"><?php esc_html_e( 'WebP Quality', 'flavor-edge-cache' ); ?></label></th>
					<td>
						<input type="range" id="i3_quality_webp" name="i3_quality_webp" value="<?php echo esc_attr( $settings['i3_quality_webp'] ); ?>" min="30" max="100" oninput="document.getElementById('i3_quality_webp_val').textContent=this.value" />
						<span id="i3_quality_webp_val"><?php echo esc_html( $settings['i3_quality_webp'] ); ?></span>%
					</td>
				</tr>
				<tr>
					<th><label for="i3_max_length"><?php esc_html_e( 'Max image size', 'flavor-edge-cache' ); ?></label></th>
					<td>
						<input type="text" id="i3_max_length" name="i3_max_length" value="<?php echo esc_attr( $settings['i3_max_length'] ); ?>" class="small-text" placeholder="e.g., 1m" />
						<span class="description"><?php esc_html_e( 'e.g., 500k, 1m. Progressive quality reduction to stay under limit.', 'flavor-edge-cache' ); ?></span>
					</td>
				</tr>
			</table>

			<?php if ( $connected ) : ?>
			<div class="te-vcl-preview">
				<h3><?php esc_html_e( 'VCL Snippet — Deploy in Transparent Edge Dashboard', 'flavor-edge-cache' ); ?></h3>
				<p class="te-description">
					<?php esc_html_e( 'Copy this VCL snippet and deploy it from your Transparent Edge dashboard (Configuration → VCL). Save your i3 settings above first, then copy the generated snippet.', 'flavor-edge-cache' ); ?>
				</p>
				<div style="position:relative;">
					<pre id="te-vcl-code"><code><?php echo esc_html( \flavor_edge\TE_I3::generate_vcl() ); ?></code></pre>
					<button type="button" id="te-copy-vcl" class="button button-secondary" style="position:absolute;top:8px;right:8px;">
						<?php esc_html_e( '📋 Copy VCL', 'flavor-edge-cache' ); ?>
					</button>
				</div>
				<p class="te-description">
					<strong><?php esc_html_e( 'Steps:', 'flavor-edge-cache' ); ?></strong>
					<?php
					printf(
						/* translators: 1: dashboard URL */
						esc_html__( '1. Copy the snippet above. 2. Go to %s. 3. Add the snippet to your VCL configuration. 4. Deploy.', 'flavor-edge-cache' ),
						'<a href="https://dashboard.transparentcdn.com" target="_blank">dashboard.transparentcdn.com</a>'
					);
					?>
				</p>
			</div>
			<?php endif; ?>
		</div>

		<!-- OPTIMIZATION TAB -->
		<div class="te-panel" data-panel="optimization">
			<h2><?php esc_html_e( 'Frontend Optimization', 'flavor-edge-cache' ); ?></h2>
			<p class="te-description"><?php esc_html_e( 'Optimize Core Web Vitals (LCP, INP, CLS) by controlling how assets are loaded, minified, and served.', 'flavor-edge-cache' ); ?></p>

			<table class="form-table">
				<tr>
					<th colspan="2"><h3 style="margin:0"><?php esc_html_e( 'CSS', 'flavor-edge-cache' ); ?></h3></th>
				</tr>
				<tr>
					<th><?php esc_html_e( 'Minify CSS', 'flavor-edge-cache' ); ?></th>
					<td>
						<label><input type="checkbox" name="minify_css" value="1" <?php checked( $settings['minify_css'] ); ?> />
						<?php esc_html_e( 'Minify CSS files (removes comments, whitespace). Cached to disk.', 'flavor-edge-cache' ); ?></label>
						<p class="description"><?php esc_html_e( 'Already-minified files (.min.css) and external CSS are skipped.', 'flavor-edge-cache' ); ?></p>
					</td>
				</tr>
				<tr>
					<th><?php esc_html_e( 'Combine CSS', 'flavor-edge-cache' ); ?></th>
					<td>
						<label><input type="checkbox" name="combine_css" value="1" <?php checked( $settings['combine_css'] ); ?> />
						<?php esc_html_e( 'Combine local CSS files into a single file (reduces HTTP requests)', 'flavor-edge-cache' ); ?></label>
						<p class="description"><?php esc_html_e( 'Concatenates all local stylesheets into one file. External and conditional CSS are preserved. Includes minification automatically.', 'flavor-edge-cache' ); ?></p>
					</td>
				</tr>

				<tr>
					<th colspan="2"><h3 style="margin:0"><?php esc_html_e( 'JavaScript', 'flavor-edge-cache' ); ?></h3></th>
				</tr>
				<tr>
					<th><?php esc_html_e( 'Minify JS', 'flavor-edge-cache' ); ?></th>
					<td>
						<label><input type="checkbox" name="minify_js" value="1" <?php checked( $settings['minify_js'] ); ?> />
						<?php esc_html_e( 'Minify JS files (removes comments, extra whitespace). Cached to disk.', 'flavor-edge-cache' ); ?></label>
						<p class="description"><?php esc_html_e( 'Already-minified files (.min.js), jQuery, and external scripts are skipped. Conservative approach to avoid breaking JS.', 'flavor-edge-cache' ); ?></p>
					</td>
				</tr>
				<tr>
					<th><?php esc_html_e( 'Combine JS', 'flavor-edge-cache' ); ?></th>
					<td>
						<label><input type="checkbox" name="combine_js" value="1" <?php checked( $settings['combine_js'] ); ?> />
						<?php esc_html_e( 'Combine local JS files into a single file (reduces HTTP requests)', 'flavor-edge-cache' ); ?></label>
						<p class="description"><?php esc_html_e( 'Only footer scripts are combined. jQuery, WP core, and external scripts stay separate. Includes minification automatically.', 'flavor-edge-cache' ); ?></p>
					</td>
				</tr>
				<tr>
					<th><label for="combine_js_exclusions"><?php esc_html_e( 'Combine JS Exclusions', 'flavor-edge-cache' ); ?></label></th>
					<td>
						<textarea id="combine_js_exclusions" name="combine_js_exclusions" rows="3" class="large-text code"><?php echo esc_textarea( $settings['combine_js_exclusions'] ); ?></textarea>
						<p class="description"><?php esc_html_e( 'One handle or pattern per line. Scripts matching these will NOT be combined.', 'flavor-edge-cache' ); ?></p>
					</td>
				</tr>
				<tr>
					<th><?php esc_html_e( 'Defer JS', 'flavor-edge-cache' ); ?></th>
					<td>
						<label><input type="checkbox" name="defer_js" value="1" <?php checked( $settings['defer_js'] ); ?> />
						<?php esc_html_e( 'Add defer attribute to JS files (load without blocking HTML parsing)', 'flavor-edge-cache' ); ?></label>
						<p class="description"><?php esc_html_e( 'Improves LCP. jQuery and core WP scripts are excluded automatically.', 'flavor-edge-cache' ); ?></p>
					</td>
				</tr>
				<tr>
					<th><label for="defer_js_exclusions"><?php esc_html_e( 'Defer JS Exclusions', 'flavor-edge-cache' ); ?></label></th>
					<td>
						<textarea id="defer_js_exclusions" name="defer_js_exclusions" rows="3" class="large-text code"><?php echo esc_textarea( $settings['defer_js_exclusions'] ); ?></textarea>
						<p class="description"><?php esc_html_e( 'One handle or pattern per line. Scripts matching these will NOT get defer.', 'flavor-edge-cache' ); ?></p>
					</td>
				</tr>
				<tr>
					<th><?php esc_html_e( 'Delay JS Execution', 'flavor-edge-cache' ); ?></th>
					<td>
						<label><input type="checkbox" name="delay_js" value="1" <?php checked( $settings['delay_js'] ); ?> />
						<?php esc_html_e( 'Delay non-critical JavaScript until user interaction (mouse, scroll, touch)', 'flavor-edge-cache' ); ?></label>
						<p class="description"><?php esc_html_e( 'More aggressive than defer — scripts don\'t load at all until user interacts. Best for INP.', 'flavor-edge-cache' ); ?></p>
					</td>
				</tr>
				<tr>
					<th><label for="delay_js_exclusions"><?php esc_html_e( 'Delay JS Exclusions', 'flavor-edge-cache' ); ?></label></th>
					<td>
						<textarea id="delay_js_exclusions" name="delay_js_exclusions" rows="3" class="large-text code"><?php echo esc_textarea( $settings['delay_js_exclusions'] ); ?></textarea>
						<p class="description"><?php esc_html_e( 'One pattern per line. jQuery is always excluded.', 'flavor-edge-cache' ); ?></p>
					</td>
				</tr>

				<tr>
					<th colspan="2"><h3 style="margin:0"><?php esc_html_e( 'Images & Media', 'flavor-edge-cache' ); ?></h3></th>
				</tr>
				<tr>
					<th><?php esc_html_e( 'Lazy Load Images', 'flavor-edge-cache' ); ?></th>
					<td>
						<label><input type="checkbox" name="lazyload_images" value="1" <?php checked( $settings['lazyload_images'] ); ?> />
						<?php esc_html_e( 'Add loading="lazy" to below-the-fold images', 'flavor-edge-cache' ); ?></label>
					</td>
				</tr>
				<tr>
					<th><?php esc_html_e( 'Lazy Load Iframes', 'flavor-edge-cache' ); ?></th>
					<td>
						<label><input type="checkbox" name="lazyload_iframes" value="1" <?php checked( $settings['lazyload_iframes'] ); ?> />
						<?php esc_html_e( 'Add loading="lazy" to iframes (YouTube, Google Maps, etc.)', 'flavor-edge-cache' ); ?></label>
					</td>
				</tr>
				<tr>
					<th><?php esc_html_e( 'Preload LCP Image', 'flavor-edge-cache' ); ?></th>
					<td>
						<label><input type="checkbox" name="preload_lcp" value="1" <?php checked( $settings['preload_lcp'] ); ?> />
						<?php esc_html_e( 'Detect the first image and add a preload hint in <head> for faster LCP', 'flavor-edge-cache' ); ?></label>
					</td>
				</tr>

				<tr>
					<th colspan="2"><h3 style="margin:0"><?php esc_html_e( 'HTML', 'flavor-edge-cache' ); ?></h3></th>
				</tr>
				<tr>
					<th><?php esc_html_e( 'Minify HTML', 'flavor-edge-cache' ); ?></th>
					<td>
						<label><input type="checkbox" name="minify_html" value="1" <?php checked( $settings['minify_html'] ); ?> />
						<?php esc_html_e( 'Remove comments, extra whitespace, and blank lines from HTML', 'flavor-edge-cache' ); ?></label>
					</td>
				</tr>

				<tr>
					<th colspan="2"><h3 style="margin:0"><?php esc_html_e( 'Fonts & Network', 'flavor-edge-cache' ); ?></h3></th>
				</tr>
				<tr>
					<th><?php esc_html_e( 'Self-host Google Fonts', 'flavor-edge-cache' ); ?></th>
					<td>
						<label><input type="checkbox" name="selfhost_google_fonts" value="1" <?php checked( $settings['selfhost_google_fonts'] ); ?> />
						<?php esc_html_e( 'Download Google Fonts and serve them locally (better TTFB + GDPR)', 'flavor-edge-cache' ); ?></label>
						<p class="description"><?php esc_html_e( 'Eliminates DNS lookup to fonts.googleapis.com and fonts.gstatic.com. Fonts are cached in wp-content/cache/.', 'flavor-edge-cache' ); ?></p>
					</td>
				</tr>
				<tr>
					<th><label for="dns_prefetch_urls"><?php esc_html_e( 'Additional DNS Prefetch', 'flavor-edge-cache' ); ?></label></th>
					<td>
						<?php
						$auto_domains = \flavor_edge\TE_Frontend::detect_third_party_domains();
						if ( ! empty( $auto_domains ) ) :
						?>
						<p style="margin-top:0;margin-bottom:8px;">
							<strong><?php esc_html_e( 'Auto-detected:', 'flavor-edge-cache' ); ?></strong>
							<?php echo esc_html( implode( ', ', array_map( function( $d ) { return wp_parse_url( $d, PHP_URL_HOST ); }, $auto_domains ) ) ); ?>
							<br><span class="description"><?php esc_html_e( 'These domains are prefetched automatically based on your active plugins. No action needed.', 'flavor-edge-cache' ); ?></span>
						</p>
						<?php endif; ?>
						<textarea id="dns_prefetch_urls" name="dns_prefetch_urls" rows="3" class="large-text code" placeholder="https://cdn.example.com&#10;https://api.third-party.com"><?php echo esc_textarea( $settings['dns_prefetch_urls'] ); ?></textarea>
						<p class="description"><?php esc_html_e( 'Add any third-party origins not detected above (one per line). DNS is resolved and connections are opened before the browser needs them.', 'flavor-edge-cache' ); ?></p>
					</td>
				</tr>
			</table>
		</div>

		<?php if ( \flavor_edge\TE_Speculation_Rules::is_available() ) : ?>
		<!-- SPECULATION RULES TAB -->
		<div class="te-panel" data-panel="speculation">
			<h2><?php esc_html_e( 'Speculation Rules', 'flavor-edge-cache' ); ?></h2>
			<p class="description" style="margin-bottom: 16px;">
				<?php esc_html_e( 'Accelerate page-to-page navigation by telling the browser to prefetch or prerender links before the user clicks.', 'flavor-edge-cache' ); ?>
			</p>
			<?php include FLAVOR_EDGE_DIR . 'includes/admin/views/tab-speculation.php'; ?>
		</div>
		<?php endif; ?>

		<!-- ADVANCED TAB -->
		<div class="te-panel" data-panel="advanced">
			<h2><?php esc_html_e( 'Advanced Settings', 'flavor-edge-cache' ); ?></h2>

			<table class="form-table">
				<tr>
					<th><label for="excluded_urls"><?php esc_html_e( 'Excluded URLs', 'flavor-edge-cache' ); ?></label></th>
					<td>
						<textarea id="excluded_urls" name="excluded_urls" rows="5" class="large-text code"><?php echo esc_textarea( $settings['excluded_urls'] ); ?></textarea>
						<p class="description"><?php esc_html_e( 'One pattern per line. Regex supported. These URLs will never be cached.', 'flavor-edge-cache' ); ?></p>
					</td>
				</tr>
				<tr>
					<th><label for="excluded_cookies"><?php esc_html_e( 'Excluded Cookies', 'flavor-edge-cache' ); ?></label></th>
					<td>
						<textarea id="excluded_cookies" name="excluded_cookies" rows="5" class="large-text code"><?php echo esc_textarea( $settings['excluded_cookies'] ); ?></textarea>
						<p class="description"><?php esc_html_e( 'One pattern per line. If a visitor has any of these cookies, the page won\'t be cached.', 'flavor-edge-cache' ); ?></p>
					</td>
				</tr>
				<tr>
					<th><label for="accepted_query_strings"><?php esc_html_e( 'Ignored Query Strings', 'flavor-edge-cache' ); ?></label></th>
					<td>
						<textarea id="accepted_query_strings" name="accepted_query_strings" rows="5" class="large-text code"><?php echo esc_textarea( $settings['accepted_query_strings'] ); ?></textarea>
						<p class="description"><?php esc_html_e( 'One per line. These query parameters (UTM, fbclid, etc.) are stripped from the cache key.', 'flavor-edge-cache' ); ?></p>
						<?php if ( $connected ) : ?>
						<div class="te-vcl-preview" style="margin-top:12px;">
							<details>
								<summary style="cursor:pointer;font-weight:600;font-size:12px;color:#0073aa;">
									<?php esc_html_e( 'VCL Snippet for Query String Stripping (deploy in TE dashboard)', 'flavor-edge-cache' ); ?>
								</summary>
								<div style="position:relative;margin-top:8px;">
									<pre id="te-vcl-qs"><code><?php echo esc_html( \flavor_edge\TE_Admin::generate_querystring_vcl() ); ?></code></pre>
									<button type="button" class="button button-secondary te-copy-btn" data-target="te-vcl-qs" style="position:absolute;top:8px;right:8px;">
										<?php esc_html_e( '📋 Copy VCL', 'flavor-edge-cache' ); ?>
									</button>
								</div>
							</details>
						</div>
						<?php endif; ?>
					</td>
				</tr>

				<?php if ( class_exists( 'WooCommerce' ) ) : ?>
				<tr>
					<th colspan="2"><h3><?php esc_html_e( 'WooCommerce', 'flavor-edge-cache' ); ?></h3></th>
				</tr>
				<tr>
					<th><?php esc_html_e( 'Exclude from cache', 'flavor-edge-cache' ); ?></th>
					<td>
						<label><input type="checkbox" name="woo_exclude_cart" value="1" <?php checked( $settings['woo_exclude_cart'] ); ?> /> <?php esc_html_e( 'Cart page', 'flavor-edge-cache' ); ?></label><br>
						<label><input type="checkbox" name="woo_exclude_checkout" value="1" <?php checked( $settings['woo_exclude_checkout'] ); ?> /> <?php esc_html_e( 'Checkout page', 'flavor-edge-cache' ); ?></label><br>
						<label><input type="checkbox" name="woo_exclude_account" value="1" <?php checked( $settings['woo_exclude_account'] ); ?> /> <?php esc_html_e( 'My Account page', 'flavor-edge-cache' ); ?></label>
					</td>
				</tr>
				<tr>
					<th><?php esc_html_e( 'Stock changes', 'flavor-edge-cache' ); ?></th>
					<td><label><input type="checkbox" name="woo_purge_stock" value="1" <?php checked( $settings['woo_purge_stock'] ); ?> /> <?php esc_html_e( 'Purge product page when stock changes', 'flavor-edge-cache' ); ?></label></td>
				</tr>
				<?php endif; ?>

				<tr>
					<th colspan="2"><h3><?php esc_html_e( 'Heartbeat Control', 'flavor-edge-cache' ); ?></h3></th>
				</tr>
				<tr>
					<th><?php esc_html_e( 'Heartbeat Behavior', 'flavor-edge-cache' ); ?></th>
					<td>
						<select name="heartbeat_behavior">
							<option value="default" <?php selected( $settings['heartbeat_behavior'], 'default' ); ?>><?php esc_html_e( 'Default (WordPress controls it)', 'flavor-edge-cache' ); ?></option>
							<option value="reduce" <?php selected( $settings['heartbeat_behavior'], 'reduce' ); ?>><?php esc_html_e( 'Reduce frequency (recommended)', 'flavor-edge-cache' ); ?></option>
							<option value="disable_everywhere" <?php selected( $settings['heartbeat_behavior'], 'disable_everywhere' ); ?>><?php esc_html_e( 'Disable everywhere (saves max resources)', 'flavor-edge-cache' ); ?></option>
						</select>
						<p class="description"><?php esc_html_e( 'The Heartbeat API sends AJAX requests every 15-60s for autosave, post locking, etc. Reducing it saves server resources.', 'flavor-edge-cache' ); ?></p>
					</td>
				</tr>
				<tr>
					<th><label for="heartbeat_interval"><?php esc_html_e( 'Heartbeat Interval', 'flavor-edge-cache' ); ?></label></th>
					<td>
						<input type="number" id="heartbeat_interval" name="heartbeat_interval" value="<?php echo esc_attr( $settings['heartbeat_interval'] ); ?>" min="15" max="120" class="small-text" />
						<span class="description"><?php esc_html_e( 'seconds (15-120). Default WP: 15-60. Recommended: 60 or 120.', 'flavor-edge-cache' ); ?></span>
					</td>
				</tr>
				<tr>
					<th><?php esc_html_e( 'Disable in Admin', 'flavor-edge-cache' ); ?></th>
					<td>
						<label><input type="checkbox" name="heartbeat_disable_admin" value="1" <?php checked( $settings['heartbeat_disable_admin'] ); ?> />
						<?php esc_html_e( 'Disable Heartbeat in the admin dashboard (except post editor)', 'flavor-edge-cache' ); ?></label>
					</td>
				</tr>
				<tr>
					<th><?php esc_html_e( 'Disable in Post Editor', 'flavor-edge-cache' ); ?></th>
					<td>
						<label><input type="checkbox" name="heartbeat_disable_editor" value="1" <?php checked( $settings['heartbeat_disable_editor'] ); ?> />
						<?php esc_html_e( 'Disable Heartbeat in the post editor (warning: disables autosave)', 'flavor-edge-cache' ); ?></label>
						<p class="description" style="color:#a00;"><?php esc_html_e( 'Use with caution. Without autosave, unsaved work is lost if the browser crashes.', 'flavor-edge-cache' ); ?></p>
					</td>
				</tr>

				<tr>
					<th colspan="2"><h3><?php esc_html_e( 'Debug', 'flavor-edge-cache' ); ?></h3></th>
				</tr>
				<tr>
					<th><?php esc_html_e( 'Debug Mode', 'flavor-edge-cache' ); ?></th>
					<td><label><input type="checkbox" name="debug_mode" value="1" <?php checked( $settings['debug_mode'] ); ?> /> <?php esc_html_e( 'Add debug headers (X-Flavor-Edge-Keys, X-Flavor-Edge-TTL) to responses', 'flavor-edge-cache' ); ?></label></td>
				</tr>
			</table>
		</div>

		<!-- INVALIDATION HISTORY TAB -->
		<div class="te-panel" data-panel="log">
			<h2><?php esc_html_e( 'Invalidation History', 'flavor-edge-cache' ); ?></h2>
			<p class="te-description"><?php esc_html_e( 'All invalidation events are registered in the Transparent Edge dashboard via the API. No local log needed.', 'flavor-edge-cache' ); ?></p>

			<?php if ( $connected ) : ?>
			<div style="text-align:center;padding:40px 20px;">
				<p style="font-size:16px;color:#555;margin-bottom:20px;">
					<?php esc_html_e( 'Purge events, timestamps, and results are available in your Transparent Edge dashboard:', 'flavor-edge-cache' ); ?>
				</p>
				<a href="https://dashboard.transparentcdn.com/<?php echo esc_attr( $settings['company_id'] ); ?>/invalidation" target="_blank" class="button button-primary button-hero">
					<?php esc_html_e( 'View Invalidation History →', 'flavor-edge-cache' ); ?>
				</a>
				<p style="margin-top:16px;font-size:13px;color:#888;">
					dashboard.transparentcdn.com/<?php echo esc_html( $settings['company_id'] ); ?>/invalidation
				</p>
			</div>
			<?php else : ?>
			<p><?php esc_html_e( 'Connect to the API first to access your invalidation history.', 'flavor-edge-cache' ); ?></p>
			<?php endif; ?>
		</div>

		<!-- SAVE BUTTON (visible on all tabs except log) -->
		<div class="te-save-bar">
			<button type="submit" id="te-save-settings" class="button button-primary button-large">
				<?php esc_html_e( 'Save Settings', 'flavor-edge-cache' ); ?>
			</button>
			<span id="te-save-status"></span>
		</div>

	</form>
</div>
