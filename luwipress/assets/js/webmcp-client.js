/**
 * n8nPress WebMCP Client
 *
 * Browser-side MCP client for the Streamable HTTP transport.
 * Handles the full MCP lifecycle: initialize → tools/list → tools/call.
 *
 * Usage:
 *   const client = new N8nPressWebMCP('https://site.com/wp-json/n8npress/v1/mcp', 'your-api-token');
 *   await client.connect();
 *   const tools = await client.listTools();
 *   const result = await client.callTool('system_health', {});
 *
 * @since 1.10.0
 */

class N8nPressWebMCP {

    /**
     * @param {string} endpoint  MCP endpoint URL
     * @param {string} token     API token for Bearer auth (or null for cookie auth)
     */
    constructor(endpoint, token = null) {
        this.endpoint = endpoint;
        this.token = token;
        this.sessionId = null;
        this.serverInfo = null;
        this.capabilities = null;
        this.requestId = 0;
        this.connected = false;
    }

    /* ─────────────── Lifecycle ─────────────── */

    /**
     * Initialize the MCP session.
     * Must be called before any other method.
     *
     * @param {object} clientInfo  Optional client info override
     * @returns {object} Server capabilities and info
     */
    async connect(clientInfo = null) {
        const response = await this._send('initialize', {
            protocolVersion: '2025-03-26',
            capabilities: {
                roots: { listChanged: false },
            },
            clientInfo: clientInfo || {
                name: 'n8npress-webmcp-client',
                version: '1.10.0',
            },
        });

        if (response.error) {
            throw new Error(`MCP init failed: ${response.error.message}`);
        }

        this.serverInfo = response.result.serverInfo;
        this.capabilities = response.result.capabilities;

        // Extract session ID from response header (set by _send)
        this.connected = true;

        // Send initialized notification
        await this._notify('notifications/initialized');

        return response.result;
    }

    /**
     * Close the MCP session.
     */
    async disconnect() {
        if (!this.sessionId) return;

        try {
            await fetch(this.endpoint, {
                method: 'DELETE',
                headers: this._headers(),
            });
        } catch (e) {
            // Best effort
        }

        this.sessionId = null;
        this.connected = false;
    }

    /* ─────────────── Tools ─────────────── */

    /**
     * List all available MCP tools.
     * Automatically paginates through all pages.
     *
     * @returns {Array} Full list of tool definitions
     */
    async listTools() {
        this._ensureConnected();

        const allTools = [];
        let cursor = null;

        do {
            const params = cursor ? { cursor } : {};
            const response = await this._send('tools/list', params);

            if (response.error) {
                throw new Error(`tools/list failed: ${response.error.message}`);
            }

            allTools.push(...(response.result.tools || []));
            cursor = response.result.nextCursor || null;
        } while (cursor);

        return allTools;
    }

    /**
     * Call an MCP tool by name.
     *
     * @param {string} name       Tool name
     * @param {object} arguments  Tool arguments
     * @returns {object} Tool result with content array
     */
    async callTool(name, args = {}) {
        this._ensureConnected();

        const response = await this._send('tools/call', { name, arguments: args });

        if (response.error) {
            throw new Error(`tools/call [${name}] failed: ${response.error.message}`);
        }

        const result = response.result;

        // Parse text content back to JSON if possible
        if (result.content && result.content[0] && result.content[0].type === 'text') {
            try {
                result.data = JSON.parse(result.content[0].text);
            } catch (e) {
                result.data = result.content[0].text;
            }
        }

        if (result.isError) {
            const err = new Error(result.data?.error || result.content?.[0]?.text || 'Tool execution error');
            err.toolResult = result;
            throw err;
        }

        return result;
    }

    /* ─────────────── Resources ─────────────── */

    /**
     * List available MCP resources.
     *
     * @returns {Array} Resource definitions
     */
    async listResources() {
        this._ensureConnected();

        const response = await this._send('resources/list', {});

        if (response.error) {
            throw new Error(`resources/list failed: ${response.error.message}`);
        }

        return response.result.resources || [];
    }

    /**
     * List resource templates (parameterized URIs).
     *
     * @returns {Array} Resource template definitions
     */
    async listResourceTemplates() {
        this._ensureConnected();

        const response = await this._send('resources/templates/list', {});

        if (response.error) {
            throw new Error(`resources/templates/list failed: ${response.error.message}`);
        }

        return response.result.resourceTemplates || [];
    }

    /**
     * Read a specific resource by URI.
     *
     * @param {string} uri  Resource URI (e.g., 'n8npress://health')
     * @returns {object} Resource contents
     */
    async readResource(uri) {
        this._ensureConnected();

        const response = await this._send('resources/read', { uri });

        if (response.error) {
            throw new Error(`resources/read [${uri}] failed: ${response.error.message}`);
        }

        const contents = response.result.contents || [];
        if (contents.length > 0 && contents[0].text) {
            try {
                contents[0].data = JSON.parse(contents[0].text);
            } catch (e) {
                // Keep as text
            }
        }

        return contents;
    }

    /* ─────────────── Utilities ─────────────── */

    /**
     * Send a ping to verify the connection.
     *
     * @returns {boolean} true if server responds
     */
    async ping() {
        try {
            const response = await this._send('ping', {});
            return !response.error;
        } catch (e) {
            return false;
        }
    }

    /**
     * Get autocompletion values for a resource template argument.
     *
     * @param {string} uriTemplate  The resource template URI
     * @param {string} argName      Argument name (e.g., 'post_id')
     * @param {string} argValue     Current partial value
     * @returns {object} Completion values
     */
    async complete(uriTemplate, argName, argValue) {
        this._ensureConnected();

        const response = await this._send('completion/complete', {
            ref: { type: 'ref/resource', uri: uriTemplate },
            argument: { name: argName, value: argValue },
        });

        if (response.error) {
            throw new Error(`completion failed: ${response.error.message}`);
        }

        return response.result.completion || { values: [] };
    }

    /* ─────────────── Internal ─────────────── */

    /**
     * Send a JSON-RPC request (expects a response).
     */
    async _send(method, params) {
        const id = ++this.requestId;
        const body = {
            jsonrpc: '2.0',
            id,
            method,
            params,
        };

        const res = await fetch(this.endpoint, {
            method: 'POST',
            headers: this._headers(),
            body: JSON.stringify(body),
        });

        // Capture session ID from response
        const sessionHeader = res.headers.get('Mcp-Session-Id');
        if (sessionHeader) {
            this.sessionId = sessionHeader;
        }

        if (!res.ok && res.status !== 202) {
            throw new Error(`HTTP ${res.status}: ${res.statusText}`);
        }

        if (res.status === 202) {
            return { result: null };
        }

        return await res.json();
    }

    /**
     * Send a JSON-RPC notification (no response expected).
     */
    async _notify(method, params = {}) {
        const body = {
            jsonrpc: '2.0',
            method,
            params,
        };

        await fetch(this.endpoint, {
            method: 'POST',
            headers: this._headers(),
            body: JSON.stringify(body),
        });
    }

    /**
     * Build request headers.
     */
    _headers() {
        const headers = {
            'Content-Type': 'application/json',
            'Accept': 'application/json, text/event-stream',
        };

        if (this.token) {
            headers['Authorization'] = `Bearer ${this.token}`;
        }

        if (this.sessionId) {
            headers['Mcp-Session-Id'] = this.sessionId;
        }

        // WordPress nonce (if available from wp_localize_script)
        if (typeof n8npress !== 'undefined' && n8npress.nonce) {
            headers['X-WP-Nonce'] = n8npress.nonce;
        }

        return headers;
    }

    _ensureConnected() {
        if (!this.connected) {
            throw new Error('Not connected. Call connect() first.');
        }
    }
}

// Export for module environments
if (typeof module !== 'undefined' && module.exports) {
    module.exports = N8nPressWebMCP;
}
