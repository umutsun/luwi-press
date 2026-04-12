<?php
/**
 * LuwiPress WebMCP Admin Page
 *
 * MCP server status, endpoint URL, tool catalog, connection tester, settings.
 *
 * @since 1.10.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
if ( ! current_user_can( 'manage_options' ) ) {
	wp_die( esc_html__( 'You do not have permission to access this page.', 'luwipress' ) );
}

// Handle settings save
if ( isset( $_POST['luwipress_webmcp_save'] ) && check_admin_referer( 'luwipress_webmcp_settings' ) ) {
	update_option( 'luwipress_webmcp_enabled', ! empty( $_POST['webmcp_enabled'] ) );
	update_option( 'luwipress_webmcp_allowed_origins', sanitize_textarea_field( $_POST['webmcp_allowed_origins'] ?? '' ) );
	echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'WebMCP settings saved.', 'luwipress' ) . '</p></div>';
}

$enabled      = LuwiPress_WebMCP::is_enabled();
$endpoint_url = rest_url( 'luwipress/v1/mcp' );
$api_token    = get_option( 'luwipress_seo_api_token', '' );
$origins      = get_option( 'luwipress_webmcp_allowed_origins', '' );

// Get tool catalog
$tool_count = 0;
$catalog    = array();
if ( $enabled && class_exists( 'LuwiPress_WebMCP' ) ) {
	$webmcp     = LuwiPress_WebMCP::get_instance();
	$tool_count = $webmcp->get_tool_count();
	$catalog    = $webmcp->get_tool_catalog();
}

$category_labels = array(
	'system'      => array( 'System & Monitoring', 'dashicons-dashboard', '#16a34a' ),
	'site'        => array( 'Site Configuration', 'dashicons-admin-site', '#2563eb' ),
	'content'     => array( 'Content Management', 'dashicons-edit', '#8b5cf6' ),
	'seo'         => array( 'SEO & Enrichment', 'dashicons-chart-line', '#ec4899' ),
	'aeo'         => array( 'AEO (Schema)', 'dashicons-editor-code', '#f59e0b' ),
	'translation' => array( 'Translation', 'dashicons-translation', '#0ea5e9' ),
	'crm'         => array( 'CRM & Analytics', 'dashicons-groups', '#14b8a6' ),
	'send'        => array( 'Email', 'dashicons-email', '#f97316' ),
	'claw'        => array( 'Open Claw (AI)', 'dashicons-superhero', '#6366f1' ),
	'token'       => array( 'Token Usage', 'dashicons-chart-pie', '#a855f7' ),
	'review'      => array( 'Review Analytics', 'dashicons-star-filled', '#eab308' ),
	'linker'      => array( 'Internal Linking', 'dashicons-admin-links', '#06b6d4' ),
	'knowledge'   => array( 'Knowledge Graph', 'dashicons-networking', '#10b981' ),
);
?>

<div class="wrap n8npress-dashboard">

	<!-- Header -->
	<div class="mcp-header">
		<div class="mcp-header-left">
			<h1 class="mcp-title">
				<svg width="28" height="28" viewBox="0 0 28 28" fill="none">
					<rect width="28" height="28" rx="8" fill="var(--n8n-primary)"/>
					<path d="M8 14h12M14 8v12" stroke="#fff" stroke-width="2.5" stroke-linecap="round"/>
					<circle cx="8" cy="14" r="2" fill="#fff"/><circle cx="20" cy="14" r="2" fill="#fff"/>
					<circle cx="14" cy="8" r="2" fill="#fff"/><circle cx="14" cy="20" r="2" fill="#fff"/>
				</svg>
				WebMCP Server
			</h1>
			<span class="mcp-protocol-badge">MCP 2025-03-26</span>
		</div>
		<div class="mcp-header-right">
			<span class="mcp-status-dot <?php echo $enabled ? 'dot-active' : 'dot-inactive'; ?>"></span>
			<span class="mcp-status-text"><?php echo $enabled ? esc_html__( 'Active', 'luwipress' ) : esc_html__( 'Disabled', 'luwipress' ); ?></span>
		</div>
	</div>

	<!-- Stats -->
	<div class="mcp-stats">
		<div class="mcp-stat" style="--accent: <?php echo $enabled ? 'var(--n8n-success)' : 'var(--n8n-error)'; ?>;">
			<span class="dashicons dashicons-<?php echo $enabled ? 'yes-alt' : 'dismiss'; ?>" style="color: var(--accent);"></span>
			<div>
				<strong><?php echo $enabled ? esc_html__( 'Active', 'luwipress' ) : esc_html__( 'Disabled', 'luwipress' ); ?></strong>
				<span><?php esc_html_e( 'Server Status', 'luwipress' ); ?></span>
			</div>
		</div>
		<div class="mcp-stat" style="--accent: var(--n8n-primary);">
			<span class="dashicons dashicons-admin-tools" style="color: var(--accent);"></span>
			<div>
				<strong><?php echo esc_html( $tool_count ); ?></strong>
				<span><?php esc_html_e( 'MCP Tools', 'luwipress' ); ?></span>
			</div>
		</div>
		<div class="mcp-stat" style="--accent: var(--n8n-blue);">
			<span class="dashicons dashicons-database" style="color: var(--accent);"></span>
			<div>
				<strong>3 + Templates</strong>
				<span><?php esc_html_e( 'MCP Resources', 'luwipress' ); ?></span>
			</div>
		</div>
		<div class="mcp-stat" style="--accent: <?php echo ! empty( $api_token ) ? 'var(--n8n-success)' : 'var(--n8n-warning)'; ?>;">
			<span class="dashicons dashicons-shield" style="color: var(--accent);"></span>
			<div>
				<strong><?php echo ! empty( $api_token ) ? esc_html__( 'Configured', 'luwipress' ) : esc_html__( 'Missing', 'luwipress' ); ?></strong>
				<span><?php esc_html_e( 'Auth Token', 'luwipress' ); ?></span>
			</div>
		</div>
	</div>

	<!-- Endpoint -->
	<div class="n8npress-card">
		<h2>
			<span class="dashicons dashicons-admin-links" style="color:var(--n8n-primary);"></span>
			<?php esc_html_e( 'MCP Endpoint', 'luwipress' ); ?>
		</h2>
		<div class="mcp-endpoint-box">
			<div class="mcp-endpoint-row">
				<label><?php esc_html_e( 'URL', 'luwipress' ); ?></label>
				<div class="mcp-endpoint-url">
					<code id="mcp-endpoint-url"><?php echo esc_html( $endpoint_url ); ?></code>
					<button type="button" class="mcp-copy-btn" onclick="navigator.clipboard.writeText('<?php echo esc_js( $endpoint_url ); ?>');this.textContent='Copied!';setTimeout(()=>this.textContent='Copy',1500)">Copy</button>
				</div>
			</div>
			<div class="mcp-endpoint-row">
				<label><?php esc_html_e( 'Protocol', 'luwipress' ); ?></label>
				<code class="mcp-code-inline">Streamable HTTP Transport</code>
			</div>
			<div class="mcp-endpoint-row">
				<label><?php esc_html_e( 'Auth', 'luwipress' ); ?></label>
				<div>
					<code class="mcp-code-inline">Authorization: Bearer &lt;token&gt;</code>
					<span class="mcp-or"><?php esc_html_e( 'or', 'luwipress' ); ?></span>
					<code class="mcp-code-inline">X-Luwipress-Token: &lt;token&gt;</code>
				</div>
			</div>
		</div>

		<div class="mcp-test-section">
			<button type="button" class="tm-btn tm-btn-primary" id="webmcp-test-btn" <?php disabled( ! $enabled ); ?>>
				<span class="dashicons dashicons-controls-play"></span>
				<?php esc_html_e( 'Test MCP Connection', 'luwipress' ); ?>
			</button>
			<div id="webmcp-test-result" class="mcp-test-result" style="display:none;">
				<pre></pre>
			</div>
		</div>
	</div>

	<!-- Tool Catalog -->
	<?php if ( ! empty( $catalog ) ) : ?>
	<div class="n8npress-card">
		<h2>
			<span class="dashicons dashicons-admin-tools" style="color:var(--n8n-primary);"></span>
			<?php esc_html_e( 'Tool Catalog', 'luwipress' ); ?>
			<span class="mcp-tool-count"><?php echo esc_html( $tool_count ); ?> <?php esc_html_e( 'tools', 'luwipress' ); ?></span>
		</h2>
		<div class="mcp-catalog-grid">
			<?php foreach ( $catalog as $category => $tools ) :
				$label = $category_labels[ $category ] ?? array( ucfirst( $category ), 'dashicons-admin-generic', 'var(--n8n-gray)' );
			?>
			<div class="mcp-tool-group">
				<div class="mcp-group-header">
					<span class="dashicons <?php echo esc_attr( $label[1] ); ?>" style="color:<?php echo esc_attr( $label[2] ); ?>;"></span>
					<strong><?php echo esc_html( $label[0] ); ?></strong>
					<span class="mcp-group-count"><?php echo count( $tools ); ?></span>
				</div>
				<ul class="mcp-tool-list">
					<?php foreach ( $tools as $tool ) : ?>
					<li>
						<code><?php echo esc_html( $tool['name'] ); ?></code>
						<span><?php echo esc_html( $tool['description'] ); ?></span>
					</li>
					<?php endforeach; ?>
				</ul>
			</div>
			<?php endforeach; ?>
		</div>
	</div>
	<?php endif; ?>

	<!-- Usage Examples -->
	<div class="n8npress-card">
		<h2>
			<span class="dashicons dashicons-editor-code" style="color:var(--n8n-primary);"></span>
			<?php esc_html_e( 'Usage Examples', 'luwipress' ); ?>
		</h2>

		<div class="mcp-example">
			<h3>Claude Desktop / claude_desktop_config.json</h3>
			<pre class="mcp-code-block">{
  "mcpServers": {
    "luwipress": {
      "url": "<?php echo esc_html( $endpoint_url ); ?>",
      "headers": {
        "Authorization": "Bearer <?php echo esc_html( $api_token ? substr( $api_token, 0, 8 ) . '...' : 'YOUR_TOKEN' ); ?>"
      }
    }
  }
}</pre>
		</div>

		<div class="mcp-example">
			<h3>cURL</h3>
			<pre class="mcp-code-block"># Initialize session
curl -X POST <?php echo esc_html( $endpoint_url ); ?> \
  -H "Authorization: Bearer YOUR_TOKEN" \
  -H "Content-Type: application/json" \
  -H "Accept: application/json, text/event-stream" \
  -d '{"jsonrpc":"2.0","id":1,"method":"initialize","params":{"protocolVersion":"2025-03-26","clientInfo":{"name":"curl","version":"1.0"}}}'

# List all tools
curl -X POST <?php echo esc_html( $endpoint_url ); ?> \
  -H "Authorization: Bearer YOUR_TOKEN" \
  -H "Content-Type: application/json" \
  -H "Mcp-Session-Id: SESSION_ID" \
  -d '{"jsonrpc":"2.0","id":2,"method":"tools/list"}'</pre>
		</div>
	</div>

	<!-- Settings -->
	<div class="n8npress-card">
		<h2>
			<span class="dashicons dashicons-admin-settings" style="color:var(--n8n-primary);"></span>
			<?php esc_html_e( 'Settings', 'luwipress' ); ?>
		</h2>
		<form method="post">
			<?php wp_nonce_field( 'luwipress_webmcp_settings' ); ?>
			<table class="form-table">
				<tr>
					<th><?php esc_html_e( 'Enable WebMCP', 'luwipress' ); ?></th>
					<td>
						<label>
							<input type="checkbox" name="webmcp_enabled" value="1" <?php checked( $enabled ); ?>>
							<?php esc_html_e( 'Expose MCP endpoint at /luwipress/v1/mcp', 'luwipress' ); ?>
						</label>
					</td>
				</tr>
				<tr>
					<th><?php esc_html_e( 'Allowed Origins', 'luwipress' ); ?></th>
					<td>
						<textarea name="webmcp_allowed_origins" rows="3" cols="50" class="large-text code"><?php echo esc_textarea( $origins ); ?></textarea>
						<p class="description"><?php esc_html_e( 'Additional CORS origins (one per line). Your site URL is always allowed.', 'luwipress' ); ?></p>
					</td>
				</tr>
			</table>
			<p class="submit">
				<button type="submit" name="luwipress_webmcp_save" class="tm-btn tm-btn-primary">
					<span class="dashicons dashicons-saved"></span>
					<?php esc_html_e( 'Save Settings', 'luwipress' ); ?>
				</button>
			</p>
		</form>
	</div>
</div>

<script>
document.getElementById('webmcp-test-btn')?.addEventListener('click', async function() {
	const btn = this;
	const result = document.getElementById('webmcp-test-result');
	const pre = result.querySelector('pre');

	btn.disabled = true;
	btn.innerHTML = '<span class="dashicons dashicons-update" style="animation:spin 1s linear infinite;"></span> Testing...';
	result.style.display = 'block';
	pre.textContent = 'Sending MCP initialize request...';
	pre.style.color = '';

	try {
		const res = await fetch('<?php echo esc_js( $endpoint_url ); ?>', {
			method: 'POST',
			headers: {
				'Content-Type': 'application/json',
				'Accept': 'application/json, text/event-stream',
				'X-WP-Nonce': '<?php echo esc_js( wp_create_nonce( 'wp_rest' ) ); ?>'
			},
			body: JSON.stringify({
				jsonrpc: '2.0',
				id: 1,
				method: 'initialize',
				params: {
					protocolVersion: '2025-03-26',
					capabilities: {},
					clientInfo: { name: 'luwipress-admin', version: '1.0' }
				}
			})
		});

		const data = await res.json();
		pre.textContent = JSON.stringify(data, null, 2);
		pre.style.color = data.result ? '#22c55e' : '#ef4444';
	} catch (err) {
		pre.textContent = 'Error: ' + err.message;
		pre.style.color = '#ef4444';
	}

	btn.disabled = false;
	btn.innerHTML = '<span class="dashicons dashicons-controls-play"></span> <?php echo esc_js( __( 'Test MCP Connection', 'luwipress' ) ); ?>';
});
</script>
