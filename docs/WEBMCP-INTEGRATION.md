# n8nPress WebMCP Integration

## Overview

n8nPress v1.10.0 implements a **Model Context Protocol (MCP)** server using the
**Streamable HTTP transport** (spec version `2025-03-26`). This exposes all n8nPress
capabilities as MCP tools that any MCP-compatible AI agent can discover and invoke
over a single HTTP endpoint.

**Endpoint:** `https://your-site.com/wp-json/n8npress/v1/mcp`

## What is WebMCP?

Traditional MCP servers use `stdio` transport — the client launches a subprocess and
communicates via stdin/stdout. **WebMCP** (Streamable HTTP transport) removes this
requirement: the server is a regular HTTP endpoint that accepts JSON-RPC messages
via POST, making it accessible from:

- Claude Desktop (native MCP support)
- Any AI agent SDK (Anthropic Agent SDK, LangChain, etc.)
- Browser-based AI applications
- n8n workflows (via HTTP Request node)
- Custom integrations

## Architecture

```
AI Agent (Claude, GPT, custom)
    │
    │  POST /wp-json/n8npress/v1/mcp
    │  JSON-RPC 2.0 over HTTP
    │
    ▼
┌─────────────────────────────────────┐
│  N8nPress_WebMCP                    │
│  ┌───────────────────────────────┐  │
│  │ Permission Check              │  │
│  │ (Bearer token / Cookie / HMAC)│  │
│  └───────────┬───────────────────┘  │
│              ▼                      │
│  ┌───────────────────────────────┐  │
│  │ JSON-RPC Dispatcher           │  │
│  │ initialize / tools/list /     │  │
│  │ tools/call / resources/read   │  │
│  └───────────┬───────────────────┘  │
│              ▼                      │
│  ┌───────────────────────────────┐  │
│  │ Tool Registry (40+ tools)     │  │
│  │ Wraps existing REST endpoints │  │
│  │ with MCP schema + annotations │  │
│  └───────────┬───────────────────┘  │
│              ▼                      │
│  Existing n8nPress modules:         │
│  API, AI Content, AEO, Translation, │
│  CRM, Email, Chatwoot, Open Claw,   │
│  Knowledge Graph, Internal Linker    │
└─────────────────────────────────────┘
```

## Quick Start

### 1. Claude Desktop

Add to `claude_desktop_config.json`:

```json
{
  "mcpServers": {
    "n8npress": {
      "url": "https://your-site.com/wp-json/n8npress/v1/mcp",
      "headers": {
        "Authorization": "Bearer YOUR_API_TOKEN"
      }
    }
  }
}
```

### 2. Claude Code

Add to `.claude/settings.json` or project-level settings:

```json
{
  "mcpServers": {
    "n8npress": {
      "type": "url",
      "url": "https://your-site.com/wp-json/n8npress/v1/mcp",
      "headers": {
        "Authorization": "Bearer YOUR_API_TOKEN"
      }
    }
  }
}
```

### 3. JavaScript (Browser or Node.js)

```javascript
// Include webmcp-client.js
const client = new N8nPressWebMCP(
  'https://your-site.com/wp-json/n8npress/v1/mcp',
  'YOUR_API_TOKEN'
);

// Connect (initialize handshake)
const serverInfo = await client.connect();
console.log(serverInfo.serverInfo.name); // "n8npress-webmcp"

// List tools
const tools = await client.listTools();
console.log(`${tools.length} tools available`);

// Call a tool
const health = await client.callTool('system_health');
console.log(health.data); // { status: "healthy", checks: {...} }

// Read a resource
const config = await client.readResource('n8npress://site-config');
console.log(config[0].data);

// Disconnect
await client.disconnect();
```

### 4. cURL

```bash
# Initialize
curl -X POST https://your-site.com/wp-json/n8npress/v1/mcp \
  -H "Authorization: Bearer TOKEN" \
  -H "Content-Type: application/json" \
  -H "Accept: application/json, text/event-stream" \
  -d '{"jsonrpc":"2.0","id":1,"method":"initialize","params":{"protocolVersion":"2025-03-26","clientInfo":{"name":"curl","version":"1.0"}}}'

# Call a tool
curl -X POST https://your-site.com/wp-json/n8npress/v1/mcp \
  -H "Authorization: Bearer TOKEN" \
  -H "Content-Type: application/json" \
  -H "Mcp-Session-Id: SESSION_ID" \
  -d '{"jsonrpc":"2.0","id":2,"method":"tools/call","params":{"name":"content_opportunities","arguments":{}}}'
```

## Authentication

Three methods supported (in priority order):

1. **WordPress Cookie** — Admin users with `manage_options` capability
2. **Bearer Token** — `Authorization: Bearer <n8npress_seo_api_token>`
3. **Custom Header** — `X-n8nPress-Token: <token>`

The API token is configured in **n8nPress → Settings**.

## MCP Capabilities

| Capability   | Supported | Details |
|-------------|-----------|---------|
| Tools        | Yes       | 40+ tools wrapping all REST endpoints |
| Resources    | Yes       | 3 static + 3 parameterized templates |
| Logging      | Yes       | Server emits structured log messages |
| Completions  | Yes       | Post ID autocompletion for resource templates |
| Prompts      | No        | Planned for v1.11 |
| Sampling     | No        | Client-side only |

## Tool Catalog

### System & Monitoring
| Tool | Description | Annotations |
|------|-------------|-------------|
| `system_status` | Plugin version, features, server info | readOnly, idempotent |
| `system_health` | Database, filesystem, memory check | readOnly, idempotent |
| `system_logs` | Recent log entries (filterable) | readOnly, idempotent |
| `site_config` | Full WP + WC + plugin snapshot | readOnly, idempotent |

### Content Management
| Tool | Description | Annotations |
|------|-------------|-------------|
| `content_get_posts` | Search/filter posts, pages, products | readOnly, idempotent |
| `content_create_post` | Create new post/page | write |
| `content_update_post` | Update existing post | write, idempotent |
| `content_delete_post` | Trash or permanently delete | **destructive**, idempotent |
| `content_opportunities` | Content gaps analysis | readOnly |
| `content_stale` | Stale content needing refresh | readOnly |

### SEO & AI Enrichment
| Tool | Description | Annotations |
|------|-------------|-------------|
| `seo_enrich_product` | AI product enrichment via n8n | write, **openWorld** |
| `seo_enrich_batch` | Batch AI enrichment | write, **openWorld** |
| `seo_batch_status` | Batch job status | readOnly |
| `seo_rank_math_meta` | Read Rank Math/Yoast meta | readOnly |

### AEO (Answer Engine Optimization)
| Tool | Description | Annotations |
|------|-------------|-------------|
| `aeo_generate_faq` | AI FAQ schema generation | write, **openWorld** |
| `aeo_generate_howto` | AI HowTo schema generation | write, **openWorld** |
| `aeo_coverage` | Schema coverage report | readOnly |

### Translation
| Tool | Description | Annotations |
|------|-------------|-------------|
| `translation_missing` | Missing translations per language | readOnly |
| `translation_missing_all` | All missing across all languages | readOnly |
| `translation_request` | Request AI translation | write, **openWorld** |
| `translation_status` | Translation status per post | readOnly |
| `translation_quality_check` | Quality audit on translation | write, **openWorld** |
| `translation_taxonomy` | Translate taxonomy terms | write, **openWorld** |
| `translation_taxonomy_missing` | Missing taxonomy translations | readOnly |

### CRM & Analytics
| Tool | Description | Annotations |
|------|-------------|-------------|
| `crm_overview` | Customer stats, revenue | readOnly |
| `crm_segments` | Customer segments list | readOnly |
| `crm_segment_customers` | Customers in a segment | readOnly |
| `crm_customer_profile` | Detailed customer profile | readOnly |

### Email
| Tool | Description | Annotations |
|------|-------------|-------------|
| `send_email` | Send email via wp_mail() | write, **openWorld** |

### Open Claw (AI Agent)
| Tool | Description | Annotations |
|------|-------------|-------------|
| `claw_execute` | Execute AI agent action | write, **openWorld** |
| `claw_channels` | List communication channels | readOnly |

### Chatwoot
| Tool | Description | Annotations |
|------|-------------|-------------|
| `chatwoot_customer_lookup` | Look up customer | readOnly |
| `chatwoot_send_message` | Send conversation message | write, **openWorld** |
| `chatwoot_status` | Integration status | readOnly |

### Others
| Tool | Description | Annotations |
|------|-------------|-------------|
| `token_usage_stats` | AI token spending stats | readOnly |
| `token_limit_check` | Daily spending limit check | readOnly |
| `token_recent_calls` | Recent AI API calls | readOnly |
| `review_analytics` | Review sentiment analytics | readOnly |
| `review_summary` | AI review summary | readOnly |
| `linker_resolve` | Find internal link opportunities | write, **openWorld** |
| `linker_unresolved` | Unresolved link suggestions | readOnly |
| `knowledge_graph` | Site entity graph | readOnly |
| `workflow_report_result` | Report workflow result | write |
| `content_schedule_list` | Scheduled content items | readOnly |

## Resources

### Static Resources
| URI | Description |
|-----|-------------|
| `n8npress://site-config` | Full environment snapshot |
| `n8npress://health` | Server health status |
| `n8npress://aeo-coverage` | AEO schema coverage |

### Resource Templates (Parameterized)
| URI Template | Description |
|-------------|-------------|
| `n8npress://post/{post_id}` | Read any post/product by ID |
| `n8npress://seo-meta/{post_id}` | SEO meta for a post |
| `n8npress://translation-status/{post_id}` | Translation status per post |

## Security

Per the MCP spec's security requirements:

1. **Origin Validation** — Prevents DNS rebinding attacks; validates `Origin` header
2. **Authentication Required** — All requests must have valid credentials
3. **Rate Limiting** — Inherited from n8nPress core rate limiter
4. **Input Sanitization** — All tool inputs sanitized via WordPress functions
5. **Session Management** — UUID-based sessions with 1-hour TTL

## Extending WebMCP

Third-party plugins can register custom MCP tools:

```php
add_action( 'n8npress_webmcp_register_tools', function( $webmcp ) {
    $webmcp->register_tool( 'my_custom_tool', array(
        'description' => 'Does something custom',
        'inputSchema' => array(
            'type'       => 'object',
            'properties' => array(
                'param1' => array( 'type' => 'string', 'description' => 'A parameter' ),
            ),
            'required'   => array( 'param1' ),
        ),
        'annotations' => array(
            'title'        => 'My Custom Tool',
            'readOnlyHint' => true,
        ),
    ), function( $args ) {
        return array( 'result' => 'Hello ' . $args['param1'] );
    });
});
```

## Files

| File | Purpose |
|------|---------|
| `includes/class-n8npress-webmcp.php` | MCP server — JSON-RPC dispatcher, tool registry, resource handler |
| `admin/webmcp-page.php` | WordPress admin page — status, tool catalog, connection tester |
| `assets/js/webmcp-client.js` | Browser-side MCP client class |
