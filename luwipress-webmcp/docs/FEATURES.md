# LuwiPress WebMCP — Feature Overview

**Version:** 1.0.0 · **License:** GPLv2+ · **Companion to:** LuwiPress core

LuwiPress WebMCP turns your WordPress + WooCommerce store into a **Model Context Protocol** server. AI agents — Claude Code, OpenAI-powered scripts, n8n workflows, or any MCP-compliant client — connect once and get programmatic access to 130+ tools that cover your entire store: content, SEO, translation, page building, customers, media, and settings.

This is the companion plugin that ships alongside LuwiPress core. Install it only if you want AI-agent integration. Most day-to-day WooCommerce AI automation works without it.

---

## 🎯 What WebMCP unlocks

- **"Claude, enrich my 50 thinnest products and translate them to French"** — one conversation, hundreds of operations, no SSH, no custom scripts.
- **n8n workflows that read + write your store** — use LuwiPress as a single MCP node instead of juggling WP REST authentication and request shapes.
- **Site-specific AI agents** — build a store-specific assistant that knows your product catalogue, translation state, CRM segments, and can take action safely under your existing API token.
- **Secure remote automation** — one Bearer token authenticates every tool call. Daily AI budget limits and per-workflow cost tracking apply the same as they do from the admin UI.

---

## 🧰 Tool catalogue (130+ tools, 26 categories)

All LuwiPress REST endpoints surface as MCP tools. Category summary:

### Content & SEO
- Product enrichment (single + batch + status)
- Meta title / description read + write via detected SEO plugin (or LuwiPress fallback)
- Content health scans: thin content, stale, missing SEO, missing alt text
- Blog post creation, update, delete
- Enrichment prompt template + constraints (get / set)

### Answer Engine Optimization (AEO)
- FAQ, HowTo, Speakable schema generation
- AEO coverage reports
- Save FAQ / HowTo / Speakable payloads directly

### Multilingual translation
- List missing translations (per language or all languages at once)
- Single-post translation request (with optional clean-source-language override)
- **Batch translate** — up to 200 posts across multiple languages in one call
- Taxonomy term translation
- Translation status + quality check

### Elementor (25 tools)
- Read full page structure, compact outline, flat widget view
- Edit widgets, styles, responsive overrides
- Add / delete / move / clone sections, columns, widgets
- Section reorder, find & replace, structure sync to translations
- Kit CSS read / write / batch-apply
- Google Fonts, print method, CSS custom properties
- Named snapshot + rollback
- Auto-fix spacing, responsive audits

### Customer Relationship Management
- Store-wide CRM overview
- Customer segment definitions + counts (VIP, Loyal, Active, New, At-Risk, Dormant, Lost, One-Time)
- List customers per segment
- Individual customer profile (orders, lifetime value, reviews)
- Lifecycle event queue (post-purchase, review request, win-back)

### Admin & WordPress core
- WordPress settings read / write (whitelisted)
- WooCommerce settings read / write (whitelisted)
- User management (list, get, create, update, delete)
- Plugin install / activate / deactivate / update / search
- Theme activate + list
- Taxonomy management
- Menu management (create, items, reorder)
- Media upload from URL, list, get, update, delete
- Comment moderation
- Post meta get / set / delete

### Review & content linking
- Review sentiment analytics + summary
- Internal link resolver (suggest + apply)

### Module settings (partial-update)
- Enrichment prompt + constraints
- Translation pipeline
- Customer Chat
- Content Scheduler
- System settings

### Operations
- Site configuration snapshot
- Health check
- Log retrieval
- Token usage stats + budget check
- Cache purge across LiteSpeed / WP Rocket / W3TC / Elementor / object cache

### Customer-facing channels
- Customer Chat session transcripts
- Chatwoot customer lookup + message send
- Email proxy (send via the active SMTP plugin)
- Open Claw command execution

### Search
- Full-text product search (BM25 index)
- Search reindex
- Search stats

### Knowledge Graph
- Full store intelligence snapshot (products, taxonomy, SEO coverage, opportunities, translation status, CRM segments, media, menus, plugins — all in one response)

---

## 🔐 Authentication

- **Bearer token** — reuses the LuwiPress API token configured in LuwiPress → Settings → Connection. One credential for REST and MCP.
- **Origin validation** — DNS rebinding protection for browser-originated clients. Configurable whitelist of allowed origins.
- **Session management** — standard MCP session handshake (`initialize` → session UUID in `Mcp-Session-Id` header, 1-hour TTL).
- **WordPress admin cookie** also works for logged-in administrators, so the admin UI can use the same endpoint without a separate token.

---

## 🚀 Quick start

1. Install and activate **LuwiPress** core.
2. Install and activate **LuwiPress WebMCP**.
3. Grab your API token from LuwiPress → Settings → Connection.
4. Point your MCP client at `https://your-site.com/wp-json/luwipress/v1/mcp`.
5. Pass `Authorization: Bearer <your-token>` on every request.

Verify it's live from Settings → Connection → MCP Server card:
- "WebMCP companion plugin is active. Version 1.0.0" (green)
- **List tools** button → "130+ tools registered"

---

## 🧪 Example call

```bash
curl -X POST https://your-site.com/wp-json/luwipress/v1/mcp \
  -H "Authorization: Bearer YOUR_TOKEN" \
  -H "Content-Type: application/json" \
  -H "Accept: application/json, text/event-stream" \
  -d '{
    "jsonrpc": "2.0",
    "id": 1,
    "method": "tools/call",
    "params": {
      "name": "translation_batch",
      "arguments": {
        "languages": ["fr", "it", "es"],
        "post_type": "product",
        "limit": 50
      }
    }
  }'
```

Response is JSON-RPC 2.0 with tool output in `result.content`.

---

## ⚡ Technical specs

| Spec | Detail |
|------|--------|
| **Transport** | Streamable HTTP (MCP spec draft 2025-03-26) |
| **Endpoint** | Single route at `/wp-json/luwipress/v1/mcp` |
| **Tool count** | 130+ across 26 categories |
| **Pagination** | Built-in — `tools/list` returns 20 per page with cursor |
| **Auth** | Bearer token OR WordPress admin cookie |
| **Session TTL** | 1 hour (transient-stored, auto-cleanup) |
| **Rate limiting** | Inherits LuwiPress core rate limit |
| **Plugin size** | ~210 KB uncompressed · ~39 KB ZIP · 5 files |

---

## 🚀 Why WebMCP?

- **Single endpoint for 130+ operations** — replace dozens of REST calls with one MCP session.
- **AI-agent ready out of the box** — designed for Claude Code, Claude Desktop, OpenAI-based clients, n8n, and any MCP-compliant tooling.
- **Safety inherited from core** — daily AI budget, rate limits, translation-copy protection, snapshot/rollback for Elementor — all active under MCP too.
- **Optional** — stores that don't need AI-agent integration keep a leaner core. The companion pattern lets you add exactly what you need.
- **Reuses your existing credential** — no new API key to manage.

---

*Document version 1.0.0 · Companion to LuwiPress core 3.1.0 or newer.*
