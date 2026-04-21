# Tapadum — Claude Test & Integration Guide

**For:** Tapadum's Claude assistant
**Target site:** https://tapadum.com
**LuwiPress version:** 3.1.6 · WebMCP companion: 1.0.2
**Date:** 2026-04-21

This document tells your Claude (or any MCP-compatible AI client) exactly how to connect to the Tapadum store, what it can do, and what to test first. Hand this entire document to the AI and it will be able to operate the store autonomously.

---

## 1. Connection — the one thing that must work

Tapadum exposes a **Model Context Protocol (MCP)** server via HTTP. Any MCP-compatible client (Claude Desktop, Claude Code CLI, OpenAI Responses API with MCP, custom agents) can connect.

### MCP endpoint

```
URL:   https://tapadum.com/wp-json/luwipress/v1/mcp
Transport: Streamable HTTP (JSON-RPC 2.0 over POST)
Auth:  Bearer token
```

### Bearer token

**The token is not included in this document by design.** It's rotated periodically and is treated as a session-only secret.

- **Preferred setup:** configure the token once in Claude Desktop / Claude Code (see examples below). Claude then never has to see or ask for it.
- **Ad-hoc setup:** paste the token into the chat when Claude needs to call a tool. It should stay in working memory only — never written to files, project notes, or persistent output.

You can rotate the token at any time from *WordPress Admin → LuwiPress → Settings → Connection → "Generate"*. The old token stops working immediately.

**For Claude's operating rules on the token**, see the project's session bootstrap file (`tapadum-session-bootstrap.md`). That is the document uploaded to the Claude project — this one (test guide) is for one-off testing and onboarding.

### Connect from Claude Desktop

Edit `claude_desktop_config.json`:

```json
{
  "mcpServers": {
    "tapadum": {
      "url": "https://tapadum.com/wp-json/luwipress/v1/mcp",
      "transport": "http",
      "headers": {
        "Authorization": "Bearer <paste-your-current-token-here>"
      }
    }
  }
}
```

Restart Claude Desktop. You should see `tapadum` in the MCP servers panel with 128 tools available.

### Connect from Claude Code (CLI)

```bash
claude mcp add tapadum \
  --transport http \
  --url https://tapadum.com/wp-json/luwipress/v1/mcp \
  --header "Authorization: Bearer <paste-your-current-token-here>"
```

Then in a Claude Code session:
```
/mcp list-tools tapadum
```
You should see 128 tools.

### Connect from any other client

Any JSON-RPC 2.0 HTTP client works. Sample raw request:

```bash
curl -X POST https://tapadum.com/wp-json/luwipress/v1/mcp \
  -H "Authorization: Bearer <your-current-token>" \
  -H "Content-Type: application/json" \
  -d '{"jsonrpc":"2.0","id":1,"method":"tools/list"}'
```

---

## 2. Smoke test — do this first

Before anything creative, confirm the pipes are clean. Run these in order.

### Test 1 — Connection works

**Claude prompt:**
> Call the `system_status` tool on the Tapadum MCP server and tell me what version of LuwiPress is running.

**Expected:** `version: 3.1.6` (or newer). If this fails, the token is wrong or the MCP endpoint is down.

### Test 2 — Data retrieval works

**Claude prompt:**
> Get the knowledge graph summary for Tapadum via MCP. How many products, posts, and pages does the store have? What's the SEO coverage?

**Expected:** 128 products, 57 posts, 63 pages, SEO coverage around 37.5%.

### Test 3 — Read permissions work

**Claude prompt:**
> Use the `content_get_posts` tool to fetch 3 products from Tapadum. Show me their titles and SKUs.

**Expected:** Actual product data (Darbuka / Santur / Bendir etc.).

### Test 4 — Write permissions work (idempotent)

**Claude prompt:**
> Look up product ID 2789 via `content_get_posts`. If found, report its current SEO meta title without modifying it.

**Expected:** Current title for "9 Bridge Special Santur" (highest-opportunity product).

✅ If all four tests pass, the integration is healthy. Everything below is safe to run.

---

## 3. What your Claude can do (128 tools, 29 categories)

| Category | Tool count | What it does |
|---|---|---|
| **elementor** | 22 | Read, edit, translate, snapshot, rollback every Elementor page. Full programmatic access to widgets, sections, Kit CSS. |
| **translation** | 10 | Request AI translations, check coverage, batch-translate missing products, list missing taxonomy terms. |
| **content** | 7 | Create/update/delete posts & products, search content, scan for thin/stale content. |
| **woo** | 7 | WooCommerce orders, coupons, reports. |
| **plugins** | 7 | Detect installed plugins (SEO, translation, email, cache), read their configuration. |
| **aeo** | 6 | FAQ schema, HowTo schema, Speakable schema — generate and check coverage. |
| **admin** | 6 | Users, roles, general admin operations. |
| **taxonomy** | 6 | Manage categories, tags, and any custom taxonomy (including translating terms). |
| **seo** | 5 | Write SEO meta (Rank Math / Yoast / AIOSEO / SEOPress auto-detected), trigger enrichment. |
| **crm** | 5 | Customer segmentation (VIP / Loyal / Active / New / One-Time / At Risk / Dormant / Lost). Read-only — Claude never writes to FluentCRM/Mailchimp. |
| **media** | 5 | Upload / list / delete media, add alt text. |
| **menu** | 5 | Read and edit WordPress navigation menus. |
| **comment** | 4 | Moderate comments. |
| **settings** | 4 | Read / write site-wide settings (whitelist enforced — no `siteurl`/`home`/`admin_email` writes). |
| **system** | 3 | `system_status`, `system_health`, `system_logs` — diagnostic. |
| **token** | 3 | AI token usage reporting. |
| **meta** | 3 | Read/write post meta. |
| **search** | 3 | Full-text search across content. |
| **review** | 2 | Product review analytics and AI-drafted responses. |
| **linker** | 2 | Internal link suggestions & resolution. |
| **enrich** | 2 | AI product enrichment settings read/write. |
| **chat** | 2 | Customer-facing chat widget settings. |
| **schedule** | 2 | Scheduled content automation. |
| **themes** | 2 | Theme management. |
| **site_config** | 1 | Full environment snapshot. |
| **cache_purge** | 1 | Clear LiteSpeed / WP Rocket / W3TC / Elementor CSS in one call. |
| **send_email** | 1 | Send email via WordPress `wp_mail()` (uses your configured SMTP plugin). |
| **workflow_result** | 1 | Async job result reporting. |
| **knowledge_graph** | 1 | Full store intelligence graph (products + posts + pages + taxonomies + customers + analytics). |

To discover what a specific tool does at runtime, call `tools/list` and read the `description` + `inputSchema` of each tool.

---

## 4. First real tasks — what to try

These are ordered by value-per-effort for Tapadum specifically.

### Task A — SEO gap audit

**Prompt:**
> Use `knowledge_graph` to find Tapadum products missing SEO meta. Group them by category. Show me the five categories with the worst coverage and tell me how many products in each category are missing title, description, or focus keyword.

**What this tests:** Read path, opportunity scoring, categorisation. Zero side effects.

### Task B — Single product enrichment dry-run

**Prompt:**
> Pick Tapadum product ID 2789 ("9 Bridge Special Santur"). Tell me its current SEO title, description, focus keyword, and whether it has FAQ / HowTo schema. Do not modify anything. Then tell me exactly what you would write if I asked you to optimise it.

**What this tests:** Read-before-write hygiene. Claude should cite current state before proposing changes.

### Task C — Actually run enrichment on one product

**Prompt:**
> I authorise running `seo_enrich_product` on product ID 2789. Queue the job and, after 30 seconds, report back what AI content was generated. Then show me the before/after SEO meta so I can review it.

**What this tests:** Job queue, AI dispatch, async callback, cache invalidation. Monitor the *LuwiPress → Usage & Logs* page during this to see the token spend.

### Task D — Translation coverage gap closure

**Prompt:**
> Use `translation_taxonomy_missing` to find which Tapadum product tags are missing French translations. If there are fewer than 10, translate them all via `translation_request_taxonomy` and confirm completion.

**What this tests:** Taxonomy translation pipeline. Tapadum has ~7 missing tag translations per language.

### Task E — Batch operation with safety rails

**Prompt:**
> Audit the Tapadum "Percussions" category. For every product in that category that is missing SEO meta AND has opportunity score above 30, propose an enrichment plan. Do not execute yet — just show the list of product IDs and the reasons.

**What this tests:** Filter composition, dry-run discipline, priority ranking.

### Task F — Elementor snapshot + safe edit

**Prompt:**
> I want to change the H1 on the Tapadum homepage (post ID 7690). Before touching anything, take a named Elementor snapshot with the label "pre-H1-edit-$(date)". Tell me the snapshot ID. Then propose — but do not apply — the H1 text you would write.

**What this tests:** Snapshot workflow, rollback readiness. Elementor edits on production require this discipline.

### Task G — Inventory and revenue intelligence

**Prompt:**
> Use `knowledge_graph` to tell me:
> - Which 5 products drove the most revenue in the last 12 months?
> - How many products are out of stock right now?
> - What's my refund rate over the last 90 days?
> - Which payment method do most customers prefer?

**What this tests:** Reading the Order Analytics + Store Intelligence sections.

### Task H — Customer segment analysis

**Prompt:**
> The Tapadum store has a 3.4% repeat customer rate. Pull the customer segment data via MCP. What would a realistic win-back campaign look like for the "One-Time" segment (53 customers)? Propose subject lines, timing, and discount percentage. Do not send anything.

**What this tests:** CRM data retrieval, creative reasoning grounded in real numbers.

---

## 5. Safety rules for the AI

> Paste this section into the AI's system prompt or agent instructions.

**Never do these without explicit human approval:**
- `content_delete_post` with `force_delete: true`
- `taxonomy_delete_term` at scale (>5 terms)
- `settings_update` on anything (settings tool is admin-whitelisted but still sensitive)
- `media_delete` on files referenced by a published product
- Any `elementor_*` write operation without first calling `elementor_snapshot`
- `translation_batch` with `limit > 50`

**Always do these before a write operation:**
1. Read current state (`content_get_posts`, `seo_rank_math_meta`, `elementor_get_page`, etc.)
2. Describe what you're about to change and why
3. Wait for explicit "yes, proceed" from the human
4. Execute
5. Verify (re-read to confirm the change took effect)
6. Report with before/after

**Preferred safe defaults:**
- Batch size: 10 products per run, not 50
- Translation: one language at a time
- Cache purge: call `cache_purge` after any structural Elementor change
- AI spend: check `token_usage` before big batches; the Tapadum daily budget is set low on purpose

---

## 6. Where to look when something goes wrong

| Symptom | Where to look |
|---|---|
| `401 Unauthorized` on any MCP call | Bearer token mistyped or rotated. Get the current token from *WP Admin → LuwiPress → Settings → Connection*. |
| `403 Forbidden` | `Origin` header is sending an unallowed domain (DNS rebinding protection). Remove the `Origin` header or add the caller's domain to the allow-list. |
| Tool call succeeds but nothing visible changes | Check cache — Tapadum uses LiteSpeed. Call `cache_purge` with `targets: ["all"]`. |
| Product enrichment stuck in "processing" | Check *WP Admin → LuwiPress → Usage & Logs*. Common cause: daily budget reached (raise it or wait for midnight). |
| Translations appear empty / identical | Check *WP Admin → LuwiPress → Translation Manager* — the AI provider may be returning unparseable responses. Translations are rejected in that case (not overwritten). |
| Elementor edit broke the layout | Roll back with `elementor_rollback`. Every mutation creates a snapshot automatically, but you should also take named snapshots before deliberate edits. |
| MCP endpoint returns HTML instead of JSON | WordPress maintenance mode is on, or a plugin is intercepting the REST route. Disable recently-activated plugins first. |

---

## 7. Tapadum's current snapshot (2026-04-21)

Your Claude should read this as the baseline before making recommendations:

- **128 products** across 57 categories
- **37.5% SEO coverage** — 80 products need meta titles and descriptions
- **20.3% enriched** — 102 products haven't been through the AI enrichment pipeline
- **25.8% FAQ coverage**, 0% HowTo, 0% Speakable schema
- Translation: French, Italian, Spanish all at 100% for products
- Taxonomy: 25 missing translations (mostly product tags)
- **Lifetime revenue: €107,874** across 247 orders · AOV €436
- **Last 30 days:** €5,292 from 9 orders
- **Repeat customer rate: 3.4%** — 58 total customers, only 2 repeat
- **Top opportunity product: #2789** "9 Bridge Special Santur" (opportunity_score 54)
- **Inventory:** 36 out of stock, 4 on backorder, 404 on sale
- **Design health: 84%** — the homepage (post 7690) has 8 Elementor issues worth fixing
- **Plugin readiness: 79%**

---

## 8. Claude system prompt suggestion (ready to copy)

```
You are Tapadum's store AI. Tapadum sells ethnic musical instruments in the EU
via WooCommerce at tapadum.com. You have programmatic access to the store via
the LuwiPress WebMCP server (endpoint and Bearer token provided separately).

Your job is to help the Tapadum team:
- Improve SEO coverage (currently 37.5%)
- Complete AI enrichment on ~100 products
- Translate missing taxonomy terms
- Design and execute customer retention campaigns targeting the 53 "One-Time"
  customers (repeat rate is only 3.4%)
- Fix Elementor design issues flagged by the Design Audit

Hard rules:
- Never call destructive operations (delete, force_delete, bulk settings_update)
  without explicit approval
- Always snapshot Elementor pages before editing them
- Always read current state before writing
- Batch sizes: max 10 for enrichment, max 20 for translation, one language per run
- Confirm before spending AI tokens; respect the configured daily budget
- Report in before/after format after every write operation

Preferred toolset:
- `knowledge_graph` for situational awareness
- `content_opportunities` for prioritisation
- `seo_enrich_product` and `seo_enrich_batch` for content
- `translation_batch` for language gaps
- `aeo_generate_faq` / `aeo_generate_howto` for schema
- `elementor_snapshot` / `elementor_rollback` for page edits
- `cache_purge` after any structural change
```

---

## 9. Contact

- **Plugin vendor:** Luwi Developments LLC — hello@luwi.dev
- **Feature documentation:** `LUWIPRESS-FEATURES.md` (ships with the plugin)
- **WebMCP tool catalog:** ships in `luwipress-webmcp/docs/FEATURES.md`
- **Roadmap & session log:** `docs/knowledge-graph-roadmap.md`

Send this document's path to your AI assistant. Then paste Section 8 as the system prompt and let it loose on Task A from Section 4. You're ready.
