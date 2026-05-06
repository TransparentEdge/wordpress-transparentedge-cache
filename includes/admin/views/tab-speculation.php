<?php
/**
 * Speculation Rules admin tab.
 *
 * @package flavor_edge_cache
 */

defined( 'ABSPATH' ) || exit;

$settings   = \flavor_edge\TE_Settings::get_all();
$conflicts  = \flavor_edge\TE_Conflict_Detector::detect();
$is_enabled = ! empty( $settings['speculation_enabled'] );
$mode       = $settings['speculation_mode'] ?? 'balanced';
$injection  = $settings['speculation_injection'] ?? 'php';

// Post types.
$all_types     = get_post_types( array( 'public' => true ), 'objects' );
$allowed_types = $settings['speculation_post_types'] ?? array( 'post', 'page' );
if ( ! is_array( $allowed_types ) ) {
	$allowed_types = array( 'post', 'page' );
}

// Generate preview URL.
$preview_url = rest_url( 'te-cache/v1/speculation-rules' );
?>

<!-- Conflict warnings -->
<?php if ( ! empty( $conflicts ) && $is_enabled ) : ?>
	<?php foreach ( $conflicts as $conflict ) : ?>
		<div class="te-notice te-notice-warning" style="margin-bottom: 12px;">
			<strong>⚠ <?php echo esc_html( $conflict['plugin'] ); ?></strong><br>
			<?php echo esc_html( $conflict['message'] ); ?>
		</div>
	<?php endforeach; ?>
<?php endif; ?>

<table class="form-table">
	<tr>
		<th><?php esc_html_e( 'Enable Speculation Rules', 'flavor-edge-cache' ); ?></th>
		<td>
			<label>
				<input type="checkbox" name="speculation_enabled" value="1" <?php checked( $is_enabled ); ?> />
				<?php esc_html_e( 'Inject Speculation Rules header for faster page-to-page navigation', 'flavor-edge-cache' ); ?>
			</label>
			<p class="description">
				<?php esc_html_e( 'Tells modern browsers (Chrome 109+) to prefetch or prerender links before the user clicks. Progressive enhancement — ignored by unsupported browsers.', 'flavor-edge-cache' ); ?>
			</p>
		</td>
	</tr>

	<tr>
		<th><?php esc_html_e( 'Mode', 'flavor-edge-cache' ); ?></th>
		<td>
			<fieldset>
				<label style="display:block; margin-bottom: 8px;">
					<input type="radio" name="speculation_mode" value="conservative" <?php checked( $mode, 'conservative' ); ?> />
					<strong><?php esc_html_e( 'Conservative', 'flavor-edge-cache' ); ?></strong>
					— <?php esc_html_e( 'Prefetch on click/pointerdown only. Lowest origin load. Recommended for high-traffic sites and sites with a dedicated mid-tier.', 'flavor-edge-cache' ); ?>
				</label>
				<label style="display:block; margin-bottom: 8px;">
					<input type="radio" name="speculation_mode" value="balanced" <?php checked( $mode, 'balanced' ); ?> />
					<strong><?php esc_html_e( 'Balanced', 'flavor-edge-cache' ); ?></strong>
					— <?php esc_html_e( 'Prefetch on hover. Good balance between speed and origin load. Recommended for most sites.', 'flavor-edge-cache' ); ?>
				</label>
				<label style="display:block; margin-bottom: 8px;">
					<input type="radio" name="speculation_mode" value="aggressive" <?php checked( $mode, 'aggressive' ); ?> />
					<strong><?php esc_html_e( 'Aggressive', 'flavor-edge-cache' ); ?></strong>
					— <?php esc_html_e( 'Prefetch on hover + prerender on click. Fastest navigation, but runs JavaScript on speculative pages.', 'flavor-edge-cache' ); ?>
					<span style="color: #b32d2e;">⚠</span>
				</label>
			</fieldset>

			<!-- Aggressive mode warning (hidden by default, shown via JS) -->
			<div id="te-aggressive-warning" style="display: <?php echo 'aggressive' === $mode ? 'block' : 'none'; ?>; background: #fff8e5; border-left: 4px solid #dba617; padding: 10px 14px; margin-top: 8px;">
				<strong><?php esc_html_e( '⚠ Important: Aggressive mode runs JavaScript on speculative pages.', 'flavor-edge-cache' ); ?></strong>
				<p style="margin: 6px 0 0;">
					<?php esc_html_e( 'This can trigger analytics, remarketing pixels (Facebook, LinkedIn, Criteo), and third-party API calls before the user actually visits the page. Make sure your analytics implementation filters requests with the Sec-Purpose: prefetch header before enabling this mode.', 'flavor-edge-cache' ); ?>
				</p>
			</div>
		</td>
	</tr>

	<tr>
		<th><?php esc_html_e( 'Post types to prefetch', 'flavor-edge-cache' ); ?></th>
		<td>
			<?php foreach ( $all_types as $pt ) : ?>
				<?php if ( 'attachment' === $pt->name ) continue; ?>
				<label style="margin-right: 16px;">
					<input type="checkbox" name="speculation_post_types[]" value="<?php echo esc_attr( $pt->name ); ?>"
						<?php checked( in_array( $pt->name, $allowed_types, true ) ); ?> />
					<?php echo esc_html( $pt->labels->name ); ?>
				</label>
			<?php endforeach; ?>
			<p class="description">
				<?php esc_html_e( 'Only links to these post types will be prefetched. Unchecked types are excluded from speculation.', 'flavor-edge-cache' ); ?>
			</p>
		</td>
	</tr>

	<tr>
		<th><?php esc_html_e( 'Header injection', 'flavor-edge-cache' ); ?></th>
		<td>
			<fieldset>
				<label style="display:block; margin-bottom: 6px;">
					<input type="radio" name="speculation_injection" value="php" <?php checked( $injection, 'php' ); ?> />
					<?php esc_html_e( 'PHP (origin) — Header added by WordPress', 'flavor-edge-cache' ); ?>
				</label>
				<label style="display:block; margin-bottom: 6px;">
					<input type="radio" name="speculation_injection" value="vcl" <?php checked( $injection, 'vcl' ); ?> />
					<?php esc_html_e( 'VCL (edge) — Header added by Varnish. Recommended if VCL Snippets are enabled.', 'flavor-edge-cache' ); ?>
				</label>
			</fieldset>
			<?php if ( 'vcl' === $injection ) : ?>
				<p class="description" style="margin-top: 8px;">
					<?php esc_html_e( 'Copy the VCL snippet below and deploy it from your Transparent Edge dashboard.', 'flavor-edge-cache' ); ?>
				</p>
			<?php endif; ?>
		</td>
	</tr>

	<?php if ( 'vcl' === $injection ) : ?>
	<tr>
		<th><?php esc_html_e( 'VCL Snippet', 'flavor-edge-cache' ); ?></th>
		<td>
			<textarea readonly class="large-text code" rows="20" style="font-family: monospace; font-size: 12px; background: #f0f0f1;"><?php echo esc_textarea( \flavor_edge\TE_VCL_Snippet::generate() ); ?></textarea>
			<p>
				<button type="button" class="button" onclick="navigator.clipboard.writeText(this.closest('td').querySelector('textarea').value).then(() => { this.textContent='✓ Copied!'; setTimeout(() => this.textContent='📋 Copy VCL', 2000); });">
					📋 <?php esc_html_e( 'Copy VCL', 'flavor-edge-cache' ); ?>
				</button>
			</p>
		</td>
	</tr>
	<?php endif; ?>

	<tr>
		<th><?php esc_html_e( 'Preview', 'flavor-edge-cache' ); ?></th>
		<td>
			<?php if ( $is_enabled ) : ?>
				<a href="<?php echo esc_url( $preview_url ); ?>" target="_blank" class="button">
					<?php esc_html_e( 'View generated JSON', 'flavor-edge-cache' ); ?> ↗
				</a>
				<span class="description" style="margin-left: 8px;">
					<?php esc_html_e( 'Open Chrome DevTools → Application → Speculative loads to verify rules are active.', 'flavor-edge-cache' ); ?>
				</span>
			<?php else : ?>
				<span class="description">
					<?php esc_html_e( 'Enable Speculation Rules to preview the generated JSON.', 'flavor-edge-cache' ); ?>
				</span>
			<?php endif; ?>
		</td>
	</tr>
</table>

<script>
(function() {
	var radios = document.querySelectorAll('input[name="speculation_mode"]');
	var warning = document.getElementById('te-aggressive-warning');

	radios.forEach(function(radio) {
		radio.addEventListener('change', function() {
			warning.style.display = this.value === 'aggressive' ? 'block' : 'none';

			if (this.value === 'aggressive') {
				if (!confirm(<?php echo wp_json_encode(
					__( "⚠ Aggressive mode runs JavaScript on speculative pages.\n\nThis can trigger analytics, remarketing pixels, and third-party API calls before the user visits the page.\n\nMake sure your analytics filters requests with Sec-Purpose: prefetch.\n\nDo you want to continue?", 'flavor-edge-cache' )
				); ?>)) {
					document.querySelector('input[name="speculation_mode"][value="balanced"]').checked = true;
					warning.style.display = 'none';
				}
			}
		});
	});
})();
</script>
