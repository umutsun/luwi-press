# Tapadum — LuwiPress 3.1.4 Update Handoff

**Date:** 2026-04-21
**Plugin updates:** LuwiPress core 3.1.3 → **3.1.4**, LuwiPress WebMCP 1.0.1 → **1.0.2**
**Site:** tapadum.com

This document summarises what changed in today's update and highlights the quick wins you can act on this week. Paste it into Claude (or any AI assistant) to get step-by-step help.

---

## 1. What changed

### Knowledge Graph — major overhaul

The **LuwiPress → Knowledge Graph** page is now a full store-intelligence cockpit. Previous version had 4 basic views and no filtering; you now have:

- **Search bar** — press `/` anywhere on the page, type "santur" or "darbuka" → jump straight to that product with detail panel open
- **Preset filters** (dropdown in the header):
  - *All items* (default)
  - *Needs SEO meta* — products missing title/description
  - *Not enriched* — products where AI enrichment hasn't run
  - *Thin content* — products with little body text
  - *Translation backlog* — products missing FR/IT/ES translations (Tapadum is fully covered, should be empty)
  - *High opportunity* — products with opportunity score above 30
- **Three views**: Products · Posts · Pages (new — 63 pages with hierarchy)
- **Export dropdown**:
  - *CSV — Opportunity list* (sorted by priority)
  - *CSV — Missing SEO* (includes Edit URL for each product)
  - *JSON — Raw graph*
  - *PNG — Snapshot* of the current view
- **Three new stat cards you can click**:
  - *Design Health* — opens Elementor design audit (Kit CSS + critical issues)
  - *Plugin Health* — shows your SEO/Translation/Email/Cache plugin stack with recommendations
  - *30-Day Revenue* — opens a revenue dashboard with 12-month sparkline, top sellers, inventory status, payment methods, refunds
  - *Taxonomy Coverage* — opens a heatmap of taxonomy translation status with one-click "Translate all" buttons
- **Keyboard shortcuts**: `/` search, `r` refresh, `1/2/3` view switch, `Esc` close panel, `?` show shortcuts
- **Category detail panel** now has **batch action buttons** — "Enrich all products in this category" and "Translate all to FR/IT/ES". One click queues the work.

### WebMCP companion

- The **"Disabled" badge** on the Settings → Connection tab was wrong; the MCP endpoint was actually live the whole time. Fixed — you'll now see "Enabled" as expected.
- Tool descriptions shown to AI agents have been cleaned up (legacy "n8nPress" references removed).

### Bug fixes worth noting

- Action buttons on the Knowledge Graph detail panel (Enrich / FAQ / HowTo / Translate) sometimes did nothing due to a JavaScript scope issue. **Now they work reliably** and the panel auto-refreshes with the new state after the job queues.
- The graph was re-fetching data on every refresh even when nothing changed. **Now it uses a 5-minute cache and invalidates automatically** when you save a product or run enrichment. A small badge in the header shows whether you're seeing cached or fresh data.

---

## 2. Tapadum — your current state snapshot

Taken live from `/wp-json/luwipress/v1/knowledge-graph` at handoff time:

| Metric | Value |
|---|---|
| Products | 128 |
| Pages | 63 (1 homepage, 1 shop page detected) |
| Blog posts | 57 |
| Lifetime revenue | €107,874.51 across 247 orders |
| 30-day revenue | €5,292.99 (9 orders) |
| Average order value | €436.74 |
| Repeat customer rate | 3.4% (58 customers total, only 2 repeat) |
| SEO coverage | 37.5% — **80 products missing SEO meta** |
| AI enrichment | 20.3% — **102 products not enriched** |
| AEO FAQ | 25.8% |
| HowTo / Schema / Speakable | 0% |
| Translation coverage | FR/IT/ES all 100% (ürün) |
| Taxonomy coverage | 89% — 25 missing translations (mostly product tags) |
| Inventory alerts | 36 out of stock, 4 on backorder, 404 on sale |
| Plugin health | 79% readiness |
| Design health | 84% |
| Top opportunity product | #2789 "9 Bridge Special Santur" (score 54) |

---

## 3. Recommended action plan (this week)

### Priority 1 — Close the SEO gap (80 products)

Why: Every product missing meta title + description is invisible to Google beyond its core brand search. Fixing this has the fastest SEO return.

**Fastest path:**

1. Open *LuwiPress → Knowledge Graph*
2. Header → Preset → **"Needs SEO meta"**
3. Header → Export → **"CSV — Missing SEO"** (downloads a spreadsheet with an Edit URL column)
4. Or: Preset → "Needs SEO meta" + Preset → "High opportunity" = products where SEO fix lifts both discoverability and opportunity score at once

**Batch shortcut via AI:**
- Open any category with low SEO coverage (e.g. "Percussions")
- Click the category bubble → detail panel → **"Enrich all products in this category"**
- AI generates descriptions + SEO meta for every product missing it, up to 50 per batch

### Priority 2 — AI enrichment on 102 products

Why: Thin content is why 102 out of 128 products have below-average conversion signals. Enrichment fills meta + body + FAQ + schema in one pass.

- Preset → "Not enriched" shows them all
- Either category-by-category batch (above) or open individual top-opportunity products and click "AI Enrichment" in the recommendations
- **Daily budget guard is active**, so you can't accidentally overspend. Default cap is $1/day; raise it temporarily under *LuwiPress → Settings → AI API Keys* if you want to process the backlog in one evening.

### Priority 3 — Translate missing product tags (25 terms)

Why: Tag pages are SEO landing pages for long-tail searches like "cumbus FR" or "saz ES". Missing translations mean these URL variants 404 or fallback to English.

- Open *Knowledge Graph → **Taxonomy Coverage card** (new)*
- Heatmap shows product_tag row in orange for all three languages
- Click **"Translate all"** for each language in the Missing Translations section — one click per language, runs async

### Priority 4 — Close the refund loop

Why: 6 refunds worth €1,858 in the last 90 days. Each refund is a customer signal worth investigating.

- *Knowledge Graph → 30-Day Revenue card → Refunds section*
- Then cross-reference with specific product reviews (*LuwiPress → Usage & Logs → Review Analytics*) to see if a product has repeat complaints

### Priority 5 — Repeat-customer program

Why: 3.4% repeat rate is low — you have 58 customers total, only 2 bought again. Even a modest win-back campaign could double your repeat revenue.

- Segmentation data is already in the Knowledge Graph (VIP / at-risk / dormant), exposed via `/wp-json/luwipress/v1/crm/overview`
- The customer segments view in the admin UI isn't built yet (planned for the next release). For now, you can pull data through the REST API or ask an AI agent via WebMCP.

---

## 4. How to use this with an AI assistant (Claude, ChatGPT, etc.)

### Option A — Drop-in prompt

Paste this into Claude / ChatGPT:

> I run a WooCommerce store (tapadum.com) selling ethnic musical instruments. I use a plugin called LuwiPress that exposes a Knowledge Graph REST endpoint with my store's state (products, SEO coverage, translation status, revenue, etc.). Here's my current state summary and top opportunities: [paste this document]
>
> Help me prioritise the next two weeks of store work given that SEO meta is missing on 80 products and enrichment hasn't run on 102. My daily AI budget is small. Where do I start?

### Option B — Connect Claude Code directly via WebMCP

If you have Claude Code (the CLI), your store is already exposed as an MCP server. Point Claude at:

- **Endpoint:** `https://tapadum.com/wp-json/luwipress/v1/mcp`
- **Auth:** Bearer token (ask your developer — it's the same token used for the REST API)

Claude can then call tools like `content_opportunities`, `seo_enrich_batch`, `translation_batch` directly against your live store. The 133 MCP tools cover content, SEO, translation, Elementor, cache, plugins, users, orders — enough for an AI agent to do an entire audit without supervision.

### Option C — One-shot audit via REST

Any AI agent with fetch access can hit:

```
GET https://tapadum.com/wp-json/luwipress/v1/knowledge-graph?fresh=1
  Authorization: Bearer <your-api-token>
```

This returns ~275 KB of structured JSON with everything summarised in this document, plus per-product details.

---

## 5. Known limits & roadmap

**What this update does NOT include:**
- A built-in customer segments UI view (data exists in the API; admin panel coming in next release)
- Elementor page audit drill-down (you see summary scores; individual page diagnostics planned)
- Real-time live updates (the page auto-invalidates cache on save, but doesn't push changes)

**What's coming next:**
- Elementor page-level audit with click-through recommendations
- Customer segments dedicated view
- Additional batch actions (e.g. bulk HowTo schema generation — currently only FAQ has a batch path)

---

## 6. Support

- **Plugin update ZIPs:** `releases/luwipress-v3.1.4.zip` and `releases/luwipress-webmcp-v1.0.2.zip`
- **Detailed technical changelog:** see `readme.txt` inside the core plugin ZIP
- **Full feature reference:** `LUWIPRESS-FEATURES.md` (at the plugin distribution root)
- **Developer contact:** hello@luwi.dev
