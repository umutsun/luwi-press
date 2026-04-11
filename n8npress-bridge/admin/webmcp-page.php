<?php
/**
 * n8nPress WebMCP Admin Page
 *
 * Shows MCP server status, endpoint URL, tool catalog, connection tester,
 * and configuration options.
 *
 * @since 1.10.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}
if ( ! current_user_can( 'manage_options' ) ) {
    wp_die( __( 'You do not have permission to access this page.', 'n8npress' ) );
}

// Handle settings save
if ( isset( $_POST['n8npress_webmcp_save'] ) && check_admin_referer( 'n8npress_webmcp_settings' ) ) {
    update_option( 'n8npress_webmcp_enabled', ! empty( $_POST['webmcp_enabled'] ) );
    update_option( 'n8npress_webmcp_allowed_origins', sanitize_textarea_field( $_POST['webmcp_allowed_origins'] ?? '' ) );
    echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'WebMCP settings saved.', 'n8npress' ) . '</p></div>';
}

$enabled      = N8nPress_WebMCP::is_enabled();
$endpoint_url = rest_url( 'n8npress/v1/mcp' );
$api_token    = get_option( 'n8npress_seo_api_token', '' );
$origins      = get_option( 'n8npress_webmcp_allowed_origins', '' );

// Get tool catalog
$tool_count = 0;
$catalog    = array();
if ( $enabled && class_exists( 'N8nPress_WebMCP' ) ) {
    $webmcp     = N8nPress_WebMCP::get_instance();
    $tool_count = $webmcp->get_tool_count();
    $catalog    = $webmcp->get_tool_catalog();
}
?>

<div class="wrap n8npress-dashboard">
    <h1 class="n8npress-title">
        <span class="dashicons dashicons-rest-api"></span>
        WebMCP Server
        <span class="n8npress-version">MCP 2025-03-26</span>
    </h1>

    <p class="description" style="font-size: 14px; margin-bottom: 20px;">
        <?php esc_html_e( 'Model Context Protocol server — lets AI agents discover and use all n8nPress capabilities over a single HTTP endpoint.', 'n8npress' ); ?>
    </p>

    <!-- Status Cards -->
    <div class="n8npress-cards">
        <div class="n8npress-card">
            <div class="card-icon" style="background: <?php echo $enabled ? '#22c55e' : '#ef4444'; ?>;">
                <span class="dashicons dashicons-<?php echo $enabled ? 'yes-alt' : 'dismiss'; ?>"></span>
            </div>
            <div class="card-content">
                <div class="card-value"><?php echo $enabled ? 'Active' : 'Disabled'; ?></div>
                <div class="card-label"><?php esc_html_e( 'Server Status', 'n8npress' ); ?></div>
            </div>
        </div>

        <div class="n8npress-card">
            <div class="card-icon" style="background: #3b82f6;">
                <span class="dashicons dashicons-admin-tools"></span>
            </div>
            <div class="card-content">
                <div class="card-value"><?php echo esc_html( $tool_count ); ?></div>
                <div class="card-label"><?php esc_html_e( 'MCP Tools', 'n8npress' ); ?></div>
            </div>
        </div>

        <div class="n8npress-card">
            <div class="card-icon" style="background: #8b5cf6;">
                <span class="dashicons dashicons-database"></span>
            </div>
            <div class="card-content">
                <div class="card-value">3 + Templates</div>
                <div class="card-label"><?php esc_html_e( 'MCP Resources', 'n8npress' ); ?></div>
            </div>
        </div>

        <div class="n8npress-card">
            <div class="card-icon" style="background: #f59e0b;">
                <span class="dashicons dashicons-shield"></span>
            </div>
            <div class="card-content">
                <div class="card-value"><?php echo ! empty( $api_token ) ? 'Configured' : 'Missing'; ?></div>
                <div class="card-label"><?php esc_html_e( 'Auth Token', 'n8npress' ); ?></div>
            </div>
        </div>
    </div>

    <!-- Endpoint & Connection -->
    <div class="n8npress-section">
        <h2><span class="dashicons dashicons-admin-links"></span> <?php esc_html_e( 'MCP Endpoint', 'n8npress' ); ?></h2>

        <table class="form-table">
            <tr>
                <th><?php esc_html_e( 'Endpoint URL', 'n8npress' ); ?></th>
                <td>
                    <code id="mcp-endpoint-url" style="font-size: 14px; padding: 8px 12px; background: #1e293b; color: #22d3ee; border-radius: 6px; display: inline-block;"><?php echo esc_html( $endpoint_url ); ?></code>
                    <button type="button" class="button button-small" onclick="navigator.clipboard.writeText('<?php echo esc_js( $endpoint_url ); ?>');this.textContent='Copied!';setTimeout(()=>this.textContent='Copy',1500)">Copy</button>
                </td>
            </tr>
            <tr>
                <th><?php esc_html_e( 'Protocol', 'n8npress' ); ?></th>
                <td><code>MCP 2025-03-26 — Streamable HTTP Transport</code></td>
            </tr>
            <tr>
                <th><?php esc_html_e( 'Authentication', 'n8npress' ); ?></th>
                <td>
                    <code>Authorization: Bearer &lt;token&gt;</code> or <code>X-n8nPress-Token: &lt;token&gt;</code><br>
                    <span class="description"><?php esc_html_e( 'Use the API token from Settings page. WordPress admin cookie also works for browser clients.', 'n8npress' ); ?></span>
                </td>
            </tr>
        </table>

        <!-- Connection Test -->
        <h3 style="margin-top: 20px;"><?php esc_html_e( 'Quick Test', 'n8npress' ); ?></h3>
        <p class="description"><?php esc_html_e( 'Send an MCP initialize request to verify the server is responding:', 'n8npress' ); ?></p>
        <button type="button" class="button button-primary" id="webmcp-test-btn" <?php disabled( ! $enabled ); ?>>
            <span class="dashicons dashicons-controls-play" style="margin-top: 4px;"></span>
            <?php esc_html_e( 'Test MCP Connection', 'n8npress' ); ?>
        </button>
        <div id="webmcp-test-result" style="margin-top: 12px; display: none;">
            <pre style="background: #0f172a; color: #e2e8f0; padding: 16px; border-radius: 8px; font-size: 13px; overflow-x: auto; max-height: 300px;"></pre>
        </div>
    </div>

    <!-- Tool Catalog -->
    <div class="n8npress-section">
        <h2><span class="dashicons dashicons-admin-tools"></span> <?php esc_html_e( 'Tool Catalog', 'n8npress' ); ?></h2>
        <p class="description"><?php esc_html_e( 'All n8nPress capabilities exposed as MCP tools, grouped by domain:', 'n8npress' ); ?></p>

        <?php if ( ! empty( $catalog ) ) : ?>
        <div style="display: grid; grid-template-columns: repeat(auto-fill, minmax(340px, 1fr)); gap: 16px; margin-top: 16px;">
            <?php
            $category_labels = array(
                'system'      => array( 'System & Monitoring', 'dashicons-dashboard' ),
                'site'        => array( 'Site Configuration', 'dashicons-admin-site' ),
                'content'     => array( 'Content Management', 'dashicons-edit' ),
                'seo'         => array( 'SEO & Enrichment', 'dashicons-chart-line' ),
                'aeo'         => array( 'AEO (Schema)', 'dashicons-editor-code' ),
                'translation' => array( 'Translation', 'dashicons-translation' ),
                'crm'         => array( 'CRM & Analytics', 'dashicons-groups' ),
                'send'        => array( 'Email', 'dashicons-email' ),
                'claw'        => array( 'Open Claw (AI Agent)', 'dashicons-superhero' ),
                'chatwoot'    => array( 'Chatwoot', 'dashicons-format-chat' ),
                'workflow'    => array( 'Workflows', 'dashicons-randomize' ),
                'token'       => array( 'Token Usage', 'dashicons-chart-pie' ),
                'review'      => array( 'Review Analytics', 'dashicons-star-filled' ),
                'linker'      => array( 'Internal Linking', 'dashicons-admin-links' ),
                'knowledge'   => array( 'Knowledge Graph', 'dashicons-networking' ),
            );
            foreach ( $catalog as $category => $tools ) :
                $label = $category_labels[ $category ] ?? array( ucfirst( $category ), 'dashicons-admin-generic' );
            ?>
            <div style="background: #fff; border: 1px solid #e2e8f0; border-radius: 8px; padding: 16px;">
                <h3 style="margin: 0 0 10px; font-size: 14px;">
                    <span class="dashicons <?php echo esc_attr( $label[1] ); ?>" style="color: #6366f1;"></span>
                    <?php echo esc_html( $label[0] ); ?>
                    <span style="float: right; color: #94a3b8; font-weight: normal; font-size: 12px;"><?php echo count( $tools ); ?> tools</span>
                </h3>
                <ul style="margin: 0; padding: 0; list-style: none;">
                    <?php foreach ( $tools as $tool ) : ?>
                    <li style="padding: 4px 0; font-size: 13px; border-bottom: 1px solid #f1f5f9;">
                        <code style="font-size: 12px; color: #3b82f6;"><?php echo esc_html( $tool['name'] ); ?></code>
                        <br><span style="color: #64748b; font-size: 12px;"><?php echo esc_html( $tool['description'] ); ?></span>
                    </li>
                    <?php endforeach; ?>
                </ul>
            </div>
            <?php endforeach; ?>
        </div>
        <?php else : ?>
        <p><em><?php esc_html_e( 'WebMCP is disabled. Enable it to see available tools.', 'n8npress' ); ?></em></p>
        <?php endif; ?>
    </div>

    <!-- Usage Examples -->
    <div class="n8npress-section">
        <h2><span class="dashicons dashicons-editor-code"></span> <?php esc_html_e( 'Usage Examples', 'n8npress' ); ?></h2>

        <h3>Claude Desktop / claude_desktop_config.json</h3>
        <pre style="background: #0f172a; color: #e2e8f0; padding: 16px; border-radius: 8px; font-size: 13px; overflow-x: auto;">{
  "mcpServers": {
    "n8npress": {
      "url": "<?php echo esc_html( $endpoint_url ); ?>",
      "headers": {
        "Authorization": "Bearer <?php echo esc_html( $api_token ? substr( $api_token, 0, 8 ) . '...' : 'YOUR_TOKEN' ); ?>"
      }
    }
  }
}</pre>

        <h3 style="margin-top: 16px;">cURL — Initialize + List Tools</h3>
        <pre style="background: #0f172a; color: #e2e8f0; padding: 16px; border-radius: 8px; font-size: 13px; overflow-x: auto;"># Initialize session
curl -X POST <?php echo esc_html( $endpoint_url ); ?> \
  -H "Authorization: Bearer YOUR_TOKEN" \
  -H "Content-Type: application/json" \
  -H "Accept: application/json, text/event-stream" \
  -d '{"jsonrpc":"2.0","id":1,"method":"initialize","params":{"protocolVersion":"2025-03-26","clientInfo":{"name":"curl","version":"1.0"}}}'

# List all tools
curl -X POST <?php echo esc_html( $endpoint_url ); ?> \
  -H "Authorization: Bearer YOUR_TOKEN" \
  -H "Content-Type: application/json" \
  -H "Mcp-Session-Id: SESSION_ID_FROM_ABOVE" \
  -d '{"jsonrpc":"2.0","id":2,"method":"tools/list"}'

# Call a tool
curl -X POST <?php echo esc_html( $endpoint_url ); ?> \
  -H "Authorization: Bearer YOUR_TOKEN" \
  -H "Content-Type: application/json" \
  -H "Mcp-Session-Id: SESSION_ID_FROM_ABOVE" \
  -d '{"jsonrpc":"2.0","id":3,"method":"tools/call","params":{"name":"system_health","arguments":{}}}'</pre>
    </div>

    <!-- Settings -->
    <div class="n8npress-section">
        <h2><span class="dashicons dashicons-admin-settings"></span> <?php esc_html_e( 'Settings', 'n8npress' ); ?></h2>

        <form method="post">
            <?php wp_nonce_field( 'n8npress_webmcp_settings' ); ?>

            <table class="form-table">
                <tr>
                    <th><?php esc_html_e( 'Enable WebMCP Server', 'n8npress' ); ?></th>
                    <td>
                        <label>
                            <input type="checkbox" name="webmcp_enabled" value="1" <?php checked( $enabled ); ?>>
                            <?php esc_html_e( 'Expose MCP endpoint at /n8npress/v1/mcp', 'n8npress' ); ?>
                        </label>
                        <p class="description"><?php esc_html_e( 'When disabled, the MCP endpoint returns 404.', 'n8npress' ); ?></p>
                    </td>
                </tr>
                <tr>
                    <th><?php esc_html_e( 'Allowed Origins', 'n8npress' ); ?></th>
                    <td>
                        <textarea name="webmcp_allowed_origins" rows="4" cols="60" class="large-text code"><?php echo esc_textarea( $origins ); ?></textarea>
                        <p class="description"><?php esc_html_e( 'Additional allowed origins for CORS/DNS rebinding protection (one per line). Your site URL is always allowed.', 'n8npress' ); ?></p>
                    </td>
                </tr>
            </table>

            <p class="submit">
                <input type="submit" name="n8npress_webmcp_save" class="button button-primary" value="<?php esc_attr_e( 'Save Settings', 'n8npress' ); ?>">
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
    btn.textContent = 'Testing...';
    result.style.display = 'block';
    pre.textContent = 'Sending MCP initialize request...';

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
                    clientInfo: { name: 'n8npress-admin', version: '1.0' }
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
    btn.innerHTML = '<span class="dashicons dashicons-controls-play" style="margin-top:4px;"></span> Test MCP Connection';
});
</script>
