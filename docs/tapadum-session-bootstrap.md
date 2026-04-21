# Tapadum — Session Bootstrap for AI Assistants

> **This file is the single source of truth for every new Claude (or other AI) session working with the Tapadum store.**
>
> Upload it to your Claude project once. On every new conversation, you can simply say:
> *"Read the session bootstrap and wait for the token."*
> Claude will then have everything it needs except the rotating secret.

**Store:** Tapadum Music Store · https://tapadum.com
**Plugin:** LuwiPress 3.1.6 + WebMCP companion 1.0.2
**Last reviewed:** 2026-04-21

---

## 🔐 How the token works (READ FIRST)

**The Bearer token is NOT stored in this file. It never will be.** That is a deliberate security decision.

**On each session:**
1. The human operator will paste the token into the chat.
2. Store it only in working memory for the duration of the session.
3. Never write the token to any file, log, or persistent output.
4. Never ask for the token again inside the same session — remember it from the first paste.

**When to ask for the token:**
- Only the first time you actually need to call an MCP tool.
- Never ask preemptively (don't ask "what's the token?" at the start of a conversation that might not even need it).

**If a tool call returns 401:**
- The token was rotated. Ask the operator once for the new one.
- Do not retry with the old token.

**Polite ask:**
> "I need to call the Tapadum MCP server. Please paste the Bearer token. It'll stay in this conversation only."

---

## 🔌 Connection configuration

| Field | Value |
|---|---|
| MCP endpoint | `https://tapadum.com/wp-json/luwipress/v1/mcp` |
| Transport | Streamable HTTP (JSON-RPC 2.0 over POST) |
| Auth header | `Authorization: Bearer <token>` |
| Content-Type | `application/json` |
| Accept | `application/json, text/event-stream` |

### ⚠️ Environment constraint check — do this first

Before anything else, identify your execution environment:

- **`claude.ai` web (with "Connectors" enabled)** → use the **Native MCP Connector** path (preferred). The operator adds Tapadum once in *Settings → Connectors*, then every session sees Tapadum tools automatically with no token exchange in chat.
- **`claude.ai` web without Connectors** → you probably cannot reach `tapadum.com` directly from the sandbox. Fall back to the **Operator-runs-curl** path: you compose the command, operator runs it in their terminal, pastes the JSON response back.
- **Claude Desktop app** → direct MCP via `claude_desktop_config.json` (see below). Most robust.
- **Claude Code CLI** → direct MCP via `claude mcp add` (see below).
- **Anthropic API / Managed Agents** → direct HTTPS works; token in the `Authorization` header on every request.

**Don't silently fail:** if a tool call throws a network error mentioning "sandbox" or "network not allowed", state the constraint to the operator and offer the two options (Connector install or operator-runs-curl) — don't loop retries.

### Claude.ai web — Native MCP Connector (preferred if available)

1. Open https://claude.ai in a browser, log in.
2. *Settings → Connectors → "Add custom connector"* (feature availability depends on the Claude plan).
3. Fill in:
   - **Name:** `tapadum`
   - **URL:** `https://tapadum.com/wp-json/luwipress/v1/mcp`
   - **Auth:** Bearer token (paste current token — stored encrypted by Anthropic, not in chat).
4. Save. Open any new chat — you'll see Tapadum tools appear automatically.

With this setup, the model never has to ask for a token in-chat, and sandboxed environments inherit the connector's network path.

### Claude Desktop config (one-time setup, operator-side)

The operator edits `claude_desktop_config.json` once with their token, then never has to paste it again.

**Config file location:**

| Platform | Path |
|---|---|
| macOS | `~/Library/Application Support/Claude/claude_desktop_config.json` |
| Windows | `%APPDATA%\Claude\claude_desktop_config.json`<br>(typically `C:\Users\<you>\AppData\Roaming\Claude\claude_desktop_config.json`) |
| Linux | `~/.config/Claude/claude_desktop_config.json` |

**Shortcut from inside Claude Desktop:** *Settings → Developer → "Edit Config"* opens the correct file directly.

**Paste this block** (merge with existing `mcpServers` if you have other servers):

```json
{
  "mcpServers": {
    "tapadum": {
      "url": "https://tapadum.com/wp-json/luwipress/v1/mcp",
      "transport": "http",
      "headers": {
        "Authorization": "Bearer PASTE_YOUR_TOKEN_HERE"
      }
    }
  }
}
```

**Steps:**
1. Close Claude Desktop completely (quit, not just close window).
2. Open the config file at the path above (create it if missing — it's plain JSON).
3. Paste the `mcpServers.tapadum` block and replace `PASTE_YOUR_TOKEN_HERE` with your current token.
4. Save the file.
5. Launch Claude Desktop. Open any new chat → the connection icon should show `tapadum` in the MCP list.
6. Sanity check in chat: *"List the first five tools from the Tapadum MCP server."*

**With this setup, Claude never needs to ask for the token in chat** — Desktop handles auth on every request.

**If the connection doesn't show:** check the developer logs under *Settings → Developer → "Open Logs Folder"*. Most common cause: invalid JSON (missing comma when merging with existing servers). Validate with any JSON linter.

### Claude Code (CLI)

```bash
claude mcp add tapadum \
  --transport http \
  --url https://tapadum.com/wp-json/luwipress/v1/mcp \
  --header "Authorization: Bearer <operator-pastes-once>"
```

### Raw HTTP (for custom scripts the operator might ask you to draft)

```bash
curl -X POST https://tapadum.com/wp-json/luwipress/v1/mcp \
  -H "Authorization: Bearer <token>" \
  -H "Content-Type: application/json" \
  -d '{"jsonrpc":"2.0","id":1,"method":"tools/list"}'
```

---

## 🧭 Capability map — 128 tools, 29 categories

You have this catalogue available as soon as the connection is live. Don't enumerate every tool to the operator; know the categories and pick the right tool for the job.

| Category | Count | Purpose |
|---|---|---|
| `elementor_*` | 22 | Read/edit/translate/snapshot/rollback every Elementor page. Widgets, sections, Kit CSS. |
| `translation_*` | 10 | Request AI translation, check coverage, batch-translate, missing taxonomy terms. |
| `content_*` | 7 | CRUD for posts & products, search, thin/stale content scan. |
| `woo_*` | 7 | WooCommerce orders, coupons, reports. |
| `plugins_*` | 7 | Detect SEO/translation/email/cache plugins, read their config. |
| `aeo_*` | 6 | FAQ / HowTo / Speakable schema generation and coverage check. |
| `admin_*` | 6 | Users, roles, general admin ops. |
| `taxonomy_*` | 6 | Categories, tags, custom taxonomies — create, edit, translate. |
| `seo_*` | 5 | Write SEO meta (detects Rank Math / Yoast / AIOSEO / SEOPress), trigger enrichment. |
| `crm_*` | 5 | Customer segments (VIP / Loyal / Active / New / One-Time / At Risk / Dormant / Lost). **Read-only.** |
| `media_*` | 5 | Upload / list / delete media, add alt text. |
| `menu_*` | 5 | Read and edit WP navigation menus. |
| `comment_*` | 4 | Moderate comments. |
| `settings_*` | 4 | Site-wide settings (whitelist-protected — can't touch `siteurl`, `home`, `admin_email`). |
| `system_*` | 3 | `system_status`, `system_health`, `system_logs` — diagnostic. |
| `token_*` | 3 | AI token usage and budget reporting. |
| `meta_*` | 3 | Read/write post meta. |
| `search_*` | 3 | Full-text search across content. |
| `review_*` | 2 | Product review analytics, AI-drafted responses. |
| `linker_*` | 2 | Internal link suggestions and resolution. |
| `enrich_*` | 2 | AI product enrichment settings read/write. |
| `chat_*` | 2 | Customer-facing chat widget settings. |
| `schedule_*` | 2 | Scheduled content automation. |
| `themes_*` | 2 | Theme management. |
| `site_config` | 1 | Full environment snapshot (one-call overview). |
| `cache_purge` | 1 | Clear LiteSpeed / WP Rocket / W3TC / Elementor CSS. |
| `send_email` | 1 | Send via `wp_mail()` — uses the site's configured SMTP. |
| `workflow_result` | 1 | Report async job result back. |
| `knowledge_graph` | 1 | **Start here** — full store intelligence in one call. |

To inspect any tool's parameters at runtime, call `tools/list` and read the `inputSchema`.

---

## 🛡️ Safety rules — non-negotiable

These rules apply to every session. They are not suggestions.

### Never do without explicit human approval

- `content_delete_post` with `force_delete: true`
- `taxonomy_delete_term` at scale (more than 5 terms)
- `settings_update` on anything
- `media_delete` on files referenced by published products
- Any `elementor_*` write without first calling `elementor_snapshot`
- `translation_batch` with `limit > 50`
- `seo_enrich_batch` with more than 10 products in a single call

### Always do before a write operation

1. Read current state (`content_get_posts`, `seo_rank_math_meta`, `elementor_get_page`, etc.)
2. Describe what you will change and why
3. Wait for explicit "yes, proceed" from the operator
4. Execute the write
5. Verify by re-reading the same field
6. Report before/after

### Preferred safe defaults

- **Batch size:** 10 products for enrichment, 20 for translation.
- **Languages:** one at a time, not all three in a single batch.
- **Cache:** call `cache_purge` after any structural Elementor change.
- **Budget check:** call `token_usage` before big batches. Tapadum's daily AI budget is intentionally small.
- **Elementor edits on production:** always snapshot first, with a named note.

---

## 🎯 Tapadum baseline (as of 2026-04-21)

Your Claude should ground every recommendation in these numbers. Refresh with `knowledge_graph` at the start of any real task.

- **128 products** across 57 categories
- **57 blog posts · 63 pages**
- **SEO coverage: 37.5%** — 80 products missing meta title/description
- **AI enrichment: 20.3%** — 102 products not yet through the pipeline
- **AEO: 25.8% FAQ, 0% HowTo, 0% Speakable**
- **Translation: FR / IT / ES all 100%** for products · 25 missing taxonomy translations (mostly tags)
- **Lifetime: €107,874 across 247 orders · AOV €436**
- **Last 30 days: €5,292 / 9 orders**
- **Repeat customer rate: 3.4%** — 58 customers total, only 2 repeat. **53 are One-Time.**
- **Inventory: 36 out of stock, 4 on backorder, 404 on sale**
- **Design health: 84%** — homepage (post 7690) has 8 Elementor issues
- **Plugin readiness: 79%**
- **Highest-opportunity product: #2789** "9 Bridge Special Santur" (score 54)

---

## 🧠 Recommended session opener

When a new conversation starts and the operator says something like *"let's work on Tapadum"*, your default flow is:

1. Confirm you've read this bootstrap.
2. Ask the operator **only**:
   - Is Claude Desktop / Claude Code already configured with the token? (If yes, skip 3.)
   - Otherwise: "Please paste the MCP token when you're ready."
3. Once authenticated, run a one-shot health check:
   - `system_status` → confirm LuwiPress ≥ 3.1.6
   - `knowledge_graph` (sections=`products,store,plugins`) → ground yourself in current state
4. Summarise what changed since the baseline in this file. If the summary matches, proceed. If it diverges significantly, flag it.
5. Ask the operator: *"What do you want to work on today?"* and suggest two or three high-value tasks based on current state (see next section).

---

## 📋 High-value task library

When the operator asks *"what should we do?"*, offer these ordered by impact/effort:

### Quick wins (under 10 minutes)

- **SEO gap audit** — `knowledge_graph` filter to "Needs SEO meta", group by category, rank by opportunity score. Produce a 10-row priority list.
- **Missing taxonomy translations** — `translation_taxonomy_missing` per language. Offer to batch-translate if fewer than 10 terms are missing.
- **Homepage Elementor audit** — `elementor_get_page` on post 7690, compare against the Design Audit's flagged issues, propose fixes without executing.

### Medium tasks (30 minutes)

- **Enrich 10 high-opportunity products** — start with score > 40, single language (FR), monitor token spend.
- **FAQ schema on top 20 products** — `aeo_generate_faq` one at a time with review between each.
- **Single-category deep dive** — pick one underperforming category, audit every product, produce a remediation plan.

### Strategic work (multi-session)

- **Win-back campaign for 53 One-Time customers** — pull customer list via `crm/overview`, draft email content, defer to operator for actual send.
- **HowTo schema rollout** — 128 products, no HowTo yet. Design the prompt, pilot on 5, review, then batch.
- **Translation parity for blog posts** — 57 posts have product-style translation coverage but blog-side may be weaker. Audit first, batch second.
- **Elementor homepage overhaul** — the homepage scores 36% health. Snapshot, propose a rebuild in increments, execute one section per session.

---

## 🧯 Troubleshooting cheat sheet

| Symptom | Fix |
|---|---|
| `401 Unauthorized` | Token was rotated. Ask operator once for the new value. |
| `403 Forbidden` | Origin header rejected (DNS-rebinding protection). Remove the `Origin` header, or ask operator to allow the caller's domain. |
| Tool call succeeds but no visible change on the live site | `cache_purge` with `targets: ["all"]`. Tapadum runs LiteSpeed. |
| Enrichment stuck at "processing" | Check `token_usage` — the daily AI budget may be exhausted. Operator raises it via *WP Admin → LuwiPress → Settings → AI API Keys*. |
| Translation output looks empty / identical to source | AI returned unparseable JSON. Translations are rejected in that case, not overwritten. Operator can inspect logs at *WP Admin → LuwiPress → Usage & Logs*. |
| Elementor edit broke the layout | `elementor_rollback` with the most recent snapshot ID. Every mutation auto-creates a snapshot. |
| MCP returns HTML instead of JSON | WordPress maintenance mode, or a plugin is intercepting the REST route. Operator disables recently-activated plugins. |

---

## 🤖 Recommended system-prompt snippet (for Claude Projects "custom instructions")

```
You are Tapadum's store AI. Tapadum sells ethnic musical instruments in the EU
(tapadum.com). You have programmatic access via the LuwiPress WebMCP server.

Before doing anything, read `tapadum-session-bootstrap.md` in the project files —
it contains the endpoint, tool catalogue, safety rules, and baseline numbers.

Never store the MCP Bearer token to any file or log. Accept it only inside a
live conversation; forget it on session end. If the operator uses Claude
Desktop with MCP configured, you don't need to ask for the token at all.

Follow the safety rules in the bootstrap file exactly. Always read current
state before writing. Always snapshot before editing Elementor pages. Always
report before/after on writes. Batch conservatively.

When the operator opens a session, don't recite the bootstrap to them — just
confirm you've loaded it, do the health check, and ask what they want to work
on today.
```

---

## 📎 Related files

- `LUWIPRESS-FEATURES.md` — full feature overview of the plugin
- `luwipress-webmcp/docs/FEATURES.md` — WebMCP companion tool catalog
- `docs/knowledge-graph-roadmap.md` — roadmap and audit notes (developer-facing)
- `docs/tapadum-handoff-3.1.4.md` — previous update summary (superseded but useful context)

---

## 📞 Escalation

If something goes wrong that this file doesn't cover:

- **Plugin maintainer:** Luwi Developments LLC — hello@luwi.dev
- **Operator:** [Tapadum team member — fill in]

**Do not attempt destructive recovery without human approval.** If the store appears broken after an operation you performed, escalate immediately rather than "fixing" further.
