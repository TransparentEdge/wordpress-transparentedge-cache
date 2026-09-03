<?php
/**
 * Security tab.
 *
 * @package flavor_edge_cache
 */

defined( 'ABSPATH' ) || exit;

$s          = \flavor_edge\TE_Settings::get_all();
$sec_status = \flavor_edge\TE_Capabilities::get_security_status();
$has_suite  = \flavor_edge\TE_Capabilities::has_security_suite();
?>

<h2><?php esc_html_e( 'Security', 'flavor-edge-cache' ); ?></h2>

<h3><?php esc_html_e( 'Transparent Edge security services', 'flavor-edge-cache' ); ?></h3>
<p class="description"><?php esc_html_e( 'Detected from your Transparent Edge account.', 'flavor-edge-cache' ); ?></p>
<p>
	<?php foreach ( $sec_status as $label => $active ) : ?>
		<span style="display:inline-block;margin-right:14px;">
			<?php echo $active ? '&#9989;' : '&#11036;'; ?> <?php echo esc_html( $label ); ?>
		</span>
	<?php endforeach; ?>
</p>

<hr>

<h3><?php esc_html_e( 'Local hardening', 'flavor-edge-cache' ); ?></h3>
<p class="description"><?php esc_html_e( 'WordPress-level protections. Available for all clients.', 'flavor-edge-cache' ); ?></p>

<table class="form-table">
	<tr>
		<th><?php esc_html_e( 'Block PHP in uploads', 'flavor-edge-cache' ); ?></th>
		<td>
			<label>
				<input type="checkbox" name="harden_block_php_uploads" value="1" <?php checked( ! empty( $s['harden_block_php_uploads'] ) ); ?> />
				<?php esc_html_e( 'Prevent execution of PHP files inside /wp-content/uploads/ (Apache)', 'flavor-edge-cache' ); ?>
			</label>
			<p class="description"><?php esc_html_e( 'On Nginx, add the equivalent rule to your server config (shown after saving).', 'flavor-edge-cache' ); ?></p>
		</td>
	</tr>
	<tr>
		<th><?php esc_html_e( 'Disable XML-RPC', 'flavor-edge-cache' ); ?></th>
		<td>
			<label>
				<input type="checkbox" name="harden_disable_xmlrpc" value="1" <?php checked( ! empty( $s['harden_disable_xmlrpc'] ) ); ?> />
				<?php esc_html_e( 'Disable xmlrpc.php (leave off if you use Jetpack or the WordPress mobile app)', 'flavor-edge-cache' ); ?>
			</label>
		</td>
	</tr>
	<tr>
		<th><?php esc_html_e( 'Limit login attempts', 'flavor-edge-cache' ); ?></th>
		<td>
			<label>
				<input type="checkbox" name="harden_limit_login" value="1" <?php checked( ! empty( $s['harden_limit_login'] ) ); ?> />
				<?php esc_html_e( 'Temporarily block an IP after too many failed logins', 'flavor-edge-cache' ); ?>
			</label>
			<p style="margin-top:6px;">
				<?php esc_html_e( 'Max attempts:', 'flavor-edge-cache' ); ?>
				<input type="number" name="harden_login_max_attempts" min="3" max="20" value="<?php echo esc_attr( $s['harden_login_max_attempts'] ?? 5 ); ?>" style="width:70px;" />
				<span class="description"><?php esc_html_e( 'within a 15-minute window', 'flavor-edge-cache' ); ?></span>
			</p>
		</td>
	</tr>
</table>

<hr>

<h3><?php esc_html_e( 'Security headers', 'flavor-edge-cache' ); ?></h3>
<p class="description"><?php esc_html_e( 'Select the headers you want. The plugin generates a VCL snippet you apply from your Transparent Edge panel (recommended), or emits them from PHP as a fallback.', 'flavor-edge-cache' ); ?></p>

<table class="form-table">
	<tr>
		<th><?php esc_html_e( 'X-Content-Type-Options', 'flavor-edge-cache' ); ?></th>
		<td><label><input type="checkbox" name="sec_header_nosniff" value="1" <?php checked( ! empty( $s['sec_header_nosniff'] ) ); ?> /> nosniff</label></td>
	</tr>
	<tr>
		<th><?php esc_html_e( 'X-Frame-Options', 'flavor-edge-cache' ); ?></th>
		<td><label><input type="checkbox" name="sec_header_frame" value="1" <?php checked( ! empty( $s['sec_header_frame'] ) ); ?> /> SAMEORIGIN</label></td>
	</tr>
	<tr>
		<th><?php esc_html_e( 'Referrer-Policy', 'flavor-edge-cache' ); ?></th>
		<td><label><input type="checkbox" name="sec_header_referrer" value="1" <?php checked( ! empty( $s['sec_header_referrer'] ) ); ?> /> strict-origin-when-cross-origin</label></td>
	</tr>
	<tr>
		<th><?php esc_html_e( 'Permissions-Policy', 'flavor-edge-cache' ); ?></th>
		<td><label><input type="checkbox" name="sec_header_permissions" value="1" <?php checked( ! empty( $s['sec_header_permissions'] ) ); ?> /> <?php esc_html_e( 'Restrict geolocation, microphone, camera', 'flavor-edge-cache' ); ?></label></td>
	</tr>
	<tr>
		<th><?php esc_html_e( 'HSTS', 'flavor-edge-cache' ); ?></th>
		<td>
			<label><input type="checkbox" name="sec_header_hsts" value="1" <?php checked( ! empty( $s['sec_header_hsts'] ) ); ?> /> Strict-Transport-Security</label>
			<p class="description" style="color:#b32d2e;"><?php esc_html_e( 'Only enable if HTTPS is fully working. Requires valid certificates on all subdomains.', 'flavor-edge-cache' ); ?></p>
			<label style="display:block;margin-top:4px;"><input type="checkbox" name="sec_header_hsts_subdomains" value="1" <?php checked( ! empty( $s['sec_header_hsts_subdomains'] ) ); ?> /> includeSubDomains</label>
			<label style="display:block;"><input type="checkbox" name="sec_header_hsts_preload" value="1" <?php checked( ! empty( $s['sec_header_hsts_preload'] ) ); ?> /> preload</label>
		</td>
	</tr>
	<tr>
		<th><?php esc_html_e( 'Content-Security-Policy', 'flavor-edge-cache' ); ?></th>
		<td>
			<label><input type="checkbox" name="sec_header_csp" value="1" <?php checked( ! empty( $s['sec_header_csp'] ) ); ?> /> <?php esc_html_e( 'Enable CSP', 'flavor-edge-cache' ); ?></label>
			<textarea name="sec_header_csp_value" rows="3" class="large-text code" placeholder="default-src 'self'; script-src 'self' 'unsafe-inline' ..."><?php echo esc_textarea( $s['sec_header_csp_value'] ?? '' ); ?></textarea>
			<label style="display:block;margin-top:4px;">
				<input type="checkbox" name="sec_header_csp_enforce" value="1" <?php checked( ! empty( $s['sec_header_csp_enforce'] ) ); ?> />
				<?php esc_html_e( 'Enforce (unchecked = Report-Only, recommended for testing first)', 'flavor-edge-cache' ); ?>
			</label>
		</td>
	</tr>
	<tr>
		<th><?php esc_html_e( 'PHP fallback', 'flavor-edge-cache' ); ?></th>
		<td>
			<label>
				<input type="checkbox" name="sec_headers_php_fallback" value="1" <?php checked( ! empty( $s['sec_headers_php_fallback'] ) ); ?> />
				<?php esc_html_e( 'Emit these headers from WordPress (use if you cannot apply the VCL snippet)', 'flavor-edge-cache' ); ?>
			</label>
		</td>
	</tr>
</table>

<?php
$vcl_snippet = \flavor_edge\TE_Security_Headers::generate_vcl();
if ( $vcl_snippet ) :
	?>
	<h4><?php esc_html_e( 'Recommended VCL snippet', 'flavor-edge-cache' ); ?></h4>
	<p class="description"><?php esc_html_e( 'Copy and deploy from your Transparent Edge dashboard. The plugin never deploys VCL automatically.', 'flavor-edge-cache' ); ?></p>
	<textarea readonly class="large-text code" rows="12" style="font-family:monospace;font-size:12px;background:#f0f0f1;"><?php echo esc_textarea( $vcl_snippet ); ?></textarea>
	<p>
		<button type="button" class="button" onclick="navigator.clipboard.writeText(this.closest('td,div,.te-panel').querySelector('textarea[readonly]').value).then(()=>{this.textContent='&#10003; Copied!';setTimeout(()=>this.textContent='Copy VCL',2000);});">
			<?php esc_html_e( 'Copy VCL', 'flavor-edge-cache' ); ?>
		</button>
	</p>
<?php endif; ?>
