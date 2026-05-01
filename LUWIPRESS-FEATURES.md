# LuwiPress — Complete Feature Overview

**Version:** 3.1.35 · **License:** GPLv2+ · **Target:** WooCommerce stores

LuwiPress is a standalone, AI-powered automation plugin for WordPress/WooCommerce. It generates content, optimizes SEO, translates products, and automates store management — integrating seamlessly with existing plugins (Rank Math, WPML, Elementor, etc.) without replacing them.

Shipped as a lean **365 KB core** plus two optional companion plugins — install only what your store needs.

## 🆕 What's new in 3.x

- **WooCommerce is now optional (3.1.38)** — LuwiPress activates and runs on any WordPress site, not just WooCommerce stores. Generic features (content scheduler, customer chat with site-content RAG, AI provider settings, token tracking, generic SEO writer, native AEO writer for any post_type) work standalone. WooCommerce-dependent features (product enrichment, AEO product schema, marketplace publishing, CRM customer segmentation, product Knowledge Graph nodes, review analytics) self-disable when WC is missing. The dashboard's status ribbon shows one pill per integration category — green = friendly plugin detected, red = empty slot — with hover tooltips and one-click access to the WordPress plugin-information modal so the operator can review and install any recommended dependency without leaving the dashboard.
- **Knowledge Graph density + filter feedback (3.1.26)** — stat bar cards shortened (smaller value font, tighter padding) so the graph is visible immediately on 1366px screens without scrolling. Filter / view switches now produce a visible pulse on the graph canvas plus a transient "N products · needs seo" chip in the top-left so the operator can see the action landed even when the redraw is instant. Header subtitle removed (redundant).
- **Knowledge Graph layout refinement (3.1.25)** — Store Health hero is collapsible (compact by default, expand for chips + achievements). Next-wins action cards moved below the graph so operators explore first and act second. Graph stays centered on screen (weak radial pull prevents orphan drift). Long post/page titles trim to ~32 chars + ellipsis with the full title still in hover tooltip. Customers view reworked as a lifecycle chain: central "All customers" hub with the eight segment cohorts pinned left-to-right by lifecycle order (New → Active → Loyal → VIP → At-risk → Dormant → One-time → Lost) — healthy flow on the left, drift on the right, no more disconnected islands.
- **Scheduler delta polling (3.1.24)** — while AI generation is running, the Scheduler used to full-reload the page every 20 seconds, wiping your scroll position and any in-flight form edits. A new `GET /schedule/delta` endpoint now returns only the rows whose status actually changed, and the admin UI patches the DOM in place — status badges, accent colors, error banners, pulse animations all update live without leaving the page. Polling still pauses when the tab is hidden, any modal is open, or nothing is generating.
- **Scheduler confirm modal + focus trap + step error feedback (3.1.19)** — every native `window.confirm()` in the scheduler got replaced with a stylized confirm modal (destructive actions get a red icon + red primary button; warnings get amber). All three modals (outline, plan, confirm) now have proper focus traps so Tab cycles within the modal instead of leaking to the page behind, and focus returns to the triggering element on close. When wizard validation fails — e.g. clicking Next on Step 1 with an empty topics textarea — the offending step number in the sidebar pulses with a red halo so you can see exactly where you got stuck.
- **Scheduler UX refresh (3.1.17)** — the Scheduler page is now a hybrid three-tab layout (Queue · Plans · Create new) with URL-hash state, replacing the long vertical scroll where wizard, queue, and recurring plans competed for attention. The Create wizard uses a left-sidebar step layout (collapses to a horizontal step strip on mobile). The Queue got status sub-tabs, denser rows with circular status badges and clamped titles, a primary-color bulk-action band when selections exist, and action buttons that collapse to a bottom strip on mobile. Recurring plans moved to their own tab with a proper empty state. Depth / publish-mode / language pickers switched from radios to visual-selection cards and chips. All native `alert()` popups routed through the existing toast system; 60+ user-facing strings moved into a `luwipress.i18n` map so the plugin can be localized for Envato buyers outside English.
- **CRM tunable thresholds + AEO Action Queue (3.1.23)** — customer segmentation now bends to your business shape. A new `GET / POST /crm/settings` endpoint exposes all six thresholds (VIP spend, Loyal order count, Active / At-risk / Dormant windows, New-customer window); drop `loyal_orders` from 3 → 2 on a high-ticket store and your 2-order customers immediately join the Loyal / VIP buckets after a refresh. The Knowledge Graph "Next wins" list also grew a new AEO candidate — when HowTo / Schema / Speakable coverage is below 30%, it picks an enriched product still missing that field and surfaces a one-click generate action, turning stat-bar gaps into concrete recommendations.
- **Usage & Logs live UI + LuwiLive primitive (3.1.22)** — the Usage & Logs page now updates in real time: stat cards, 30-day cost sparkline, "Cost by Workflow" bar chart, and daily-budget indicator refresh every 10 seconds without a page reload. Activity log has a "Live" toggle that slides fresh entries in every 15s and a 180ms-debounced search so filtering feels instant. Polling pauses when the tab is hidden so nothing burns API cost in the background. The shared `LuwiLive` helper (count-up, sparkline, bar meter, highlight pulse, visibility-aware polling) is now reusable across Dashboard / Scheduler / Knowledge Graph / Translations pages. The LuwiPress admin menu and dashboard header also swapped the generic Dashicon for the Luwi brand icon.
- **Knowledge Graph gamification (3.1.21)** — the dashboard now answers three operator questions at a glance. **"Where do I click first?"** → an **Action Queue** of 3 ranked suggestion cards scores every possible next-step by impact ÷ effort and surfaces the highest-ROI plays (worst-covered category SEO, language closest to 100%, taxonomy backlogs, media alt-text, top single-product opportunity). **"Am I making progress?"** → **achievement badges** in the Store Health hero light up as coverage crosses thresholds (Bronze 50%, Silver 80%, Gold 95%, Platinum grand-slam). **"What just happened?"** → a live **activity feed** under the graph pulls the last 25 log entries, auto-refreshes every 30 seconds while visible, and flashes "+N new" when fresh events land mid-poll.
- **Knowledge Graph dashboard upgrade (3.1.20)** — the single most-used admin screen now opens with a **Store Health hero**: one weighted score across SEO, enrichment, translation, design, media, and plugins, with a colour band, qualitative description, and per-dimension chips showing where to focus next. A **7-day trend line** sits in the subtitle — `+3.2% SEO, -240 opportunity pts` — so operators can see their work is actually moving the needle. The top stat cards (Products / Posts / SEO Coverage / Enriched / Opportunities) are now **drill filters** that switch the graph view and apply the matching preset in one click, with a visual pulse confirming the action. Stat bar also fits 10 cards on 1366px screens without overflow.
- **Knowledge Graph gamification tightening (3.1.19)** — every improvement action now produces visible feedback. Single-product enrichment and translation share the same live progress monitor as category batches (was "Queued" then nothing for 90 seconds), and the moment the job finishes the detail panel reopens with updated chips, health bar, and opportunity score. A new **Media Health** stat card surfaces missing alt text, orphaned files, and the largest files in your library. The **primary source language** (previously invisible) now appears as a node so your Language view, Taxonomy heatmap, and stats count it; every language also ships its native name (Français / Italiano / Español) instead of just the two-letter code. The `?` keyboard shortcut help is a proper styled panel instead of a browser alert.
- **Customer segment classifier fix (3.1.17)** — on stores where most customers only buy once (normal for high-ticket / niche commerce), the classifier was collapsing everyone into `one_time` and hiding the `active`, `at_risk`, `dormant`, and `lost` cohorts. Segments now respect recency first — a 1-order customer who bought last month stays `active`, one from 3-6 months ago becomes `at_risk`, 6-12 months ago `dormant`. Only customers past the dormant window get relabelled `one_time` ("bought once, never came back"). A new `POST /crm/refresh-segments` endpoint lets operators reclassify everyone on demand (no more waiting for the weekly cron after a threshold change or data import).
- **Content Scheduler overhaul (3.1.14)** — the Scheduler page is now a full 4-step wizard: Topics → Style → Schedule → Review. New **draft-first publish workflow** creates WP drafts ready for editorial review instead of auto-publishing. **Two-phase Outline Approval** for deep/editorial depth: AI drafts an outline, editor approves / edits it in a modal, only then does the full article get written — strictly following the approved structure. Operators can define a **brand voice card** (site + batch-level) that layers tone, forbidden openers, cultural context on top of the depth rules. **AI topic brainstorm** proposes specific publishable titles from a theme, filtered against recent posts so duplicates don't leak in. **Per-topic pipe overrides** let individual rows deviate from batch defaults (`Topic | depth=editorial | words=3000`). **Budget preview** in the Review step shows real per-provider cost before you queue. **One-click draft enrich** resolves internal links + suggests categories/tags from existing taxonomy. **Bulk approve & publish** with per-row checkboxes. **Multilingual duplicate** — one topic becomes N linked articles across WPML/Polylang languages. **Recurring plans** — define a theme + cadence and the editorial calendar fills itself.
- **Content depth presets (3.1.10)** — the Content Scheduler bulk queue now ships with three quality levels: **Standard** (balanced SEO article, 800-1500 words), **Deep** (research-grade explainer with citations, counter-arguments, and a FAQ, 1500-3000 words), and **Editorial** (essay-style with strong voice, cultural/historical context, narrative arc, 2000-3500+ words). The system prompt has been rewritten to forbid AI-boilerplate openers, mandate concrete-over-vague writing, and require internal link placeholders. Operators can also paste a custom system prompt under `luwipress_content_system_prompt` option with `{topic}`, `{tone}`, `{depth}` variable substitution to encode their brand voice.
- **Bulk content queue (3.1.9)** — Content Scheduler now accepts up to 50 topics in one go. Paste your list (one topic per line, optional `| keywords` pipe syntax), pick a start date and publish spacing (e.g. "1 post per day" or "every 6 hours"), and the whole batch is queued. AI generation is staggered so the daily budget doesn't burst; if the cap is hit mid-batch, pending jobs auto-defer by an hour and pick up when there's room. A "Run N pending now" button shortcuts wp-cron for operators who want immediate processing.
- **Batch monitor + CSV round-trip + layout memory (3.1.7)** — three operational power-features round out the Knowledge Graph. When you queue a category-wide enrichment, a live progress bar appears in the corner and tracks the job until it finishes (queued → running → done, with failure counts). The Export dropdown now round-trips: export a CSV of opportunities or missing SEO, edit it offline in Excel or Sheets, re-upload — the new `POST /seo/meta-bulk` endpoint applies up to 500 row updates through your SEO plugin in one call. And when you drag a node to a preferred position, the graph remembers it per view (Products / Posts / Pages / Customers) via localStorage so your layout persists across sessions; a reset button reverts to the auto-layout when you want a fresh start. Design Health now reads "N/A" instead of "0%" on sites without Elementor.
- **Customers view + Elementor audit drill-down (3.1.5)** — the Knowledge Graph gets a fourth view tab focused on customer segments (VIP / Loyal / Active / New / One-Time / At Risk / Dormant / Lost), each with a dedicated detail panel that explains the cohort and recommends a targeted action (win-back campaign, onboarding sequence, VIP perks). The Design Health panel now drills down: click any audited page to see every Elementor issue grouped by severity and type, with affected element IDs and one-click "Open in Elementor" buttons.
- **Knowledge Graph overhaul (3.1.4)** — the interactive store intelligence page got a major upgrade:
  - **Search** — type any product, post, or category name; `/` focuses the input, arrows navigate, Enter zooms straight to the node and opens its detail panel.
  - **Presets** — six one-click filters: All, Needs SEO meta, Not enriched, Thin content, Translation backlog, High opportunity. Filter down 128 products to the 80 that need SEO without writing a query.
  - **Export** — CSV opportunity list, CSV missing SEO (with Edit URLs), JSON raw graph, PNG snapshot. Hand a spreadsheet to a freelancer or import back into your workflow.
  - **Pages view** — third tab showing your site's page hierarchy (home, shop, blog, top-level, children) with issue detection for thin content and orphaned pages.
  - **Order Analytics card** — one click opens a revenue dashboard with 12-month sparkline, top sellers, inventory status (out-of-stock, backorder, on-sale counts), payment method breakdown, and refunds.
  - **Plugin Health card** — readiness score plus per-category plugin detection and prioritised recommendations.
  - **Taxonomy Coverage heatmap** — see at a glance which taxonomies are translated and which aren't, with a "Translate all" button per language.
  - **Category batch actions** — enrich or translate every product in a category with a single click.
  - **Keyboard shortcuts** — `/` search, `r` refresh, `1/2/3` view switch, `Esc` close panel, `?` help.
- **Primary Language reads from your translation plugin (3.1.3)** — Settings → AI Content → Primary Language now shows only the languages WPML / Polylang / TranslatePress has active on your site, with a badge confirming which plugin it read from. Invalid saved values get snapped to your translation plugin's default, so sites can't silently drift with an AI-generation language that doesn't exist on the site.
- **OpenAI-Compatible provider (3.1.2)** — a fourth AI provider slot that talks to any vendor speaking OpenAI's `/chat/completions` schema. Ships with ready-to-use presets for **DeepSeek**, **Moonshot Kimi**, **Groq**, **Together.ai**, plus a **Custom** preset for self-hosted models (Ollama, vLLM, LM Studio). Swap vendors without writing code — pick a preset, paste an API key, done. Off-peak discount hints surfaced in the UI where available (e.g. DeepSeek ~50% off 16:30–00:30 UTC).
- **Secret masking in site-config (3.1.1)** — the `/site-config` endpoint no longer returns the LuwiPress API token or the active AI provider's API key in clear text. Responses now include `api_token_configured` / `api_key_configured` booleans plus `*_hint` fields (last 4 characters) — enough to verify configuration, impossible to reverse.
- **Standalone SEO writer** — LuwiPress now writes its own SEO title + meta description when no third-party SEO plugin (Rank Math, Yoast, AIOSEO, SEOPress) is installed. Run the full enrichment pipeline without adding another plugin.
- **Companion plugin architecture** — WebMCP and Open Claw live in separate plugins, so stores that don't need AI-agent integration or admin AI chat stay lean.
- **Custom enrichment prompts** — define your store's voice, structure, and formatting rules with variable substitution for `{product_title}`, `{category}`, `{focus_keyword}`, etc. Enforce word counts and meta length limits per request.
- **REST-first configuration** — every module's settings are exposable via `GET/POST /<module>/settings` (partial-update). Remote automation and AI agents can reconfigure the plugin without an admin session.
- **CRM simplification** — customer segmentation is now pure WooCommerce. No third-party CRM plugin dependency, no writes to FluentCRM/Mailchimp contact lists. Your CRM plugin keeps ownership of its own data.
- **Translation safety net** — if the AI returns an unparseable response, the translation is rejected and your existing content is left untouched. Pick any language as a clean retranslation source.

---

## 🎯 Core Modules

### 1. AI Content Enrichment
- Generates product descriptions, titles, SEO meta with brand/keyword awareness
- Batch enrichment with background job queue + progress tracking
- AI alt text for product images (SEO + accessibility)
- Schema markup auto-generation (Product, Article, BreadcrumbList)
- Multi-provider: **OpenAI** (GPT-4o Mini), **Anthropic** (Claude Haiku 4.5), **Google** (Gemini 2.0 Flash), **OpenAI-Compatible** (DeepSeek, Kimi, Groq, Together.ai, self-hosted)
- **Provider fallback + retry** on transient errors (rate limits, 5xx, timeouts) — jobs keep running when one provider hiccups, and the fallback chain now includes the OpenAI-Compatible slot
- **Translation-copy protection** — enrichment only writes to the default-language source; WPML/Polylang translation copies are automatically skipped to prevent accidental cross-language overwrites
- **Custom system prompt** — define a store-specific voice, structure, and formatting rules (opening paragraph style, section order, bolding conventions, FAQ placement). Variable substitution supports `{product_title}`, `{category}`, `{focus_keyword}`, `{price}`, `{currency}`, `{site_name}`, `{target_language}`
- **Meta constraints** — configure target word count, meta title max length (40–80), meta description max length (120–200), and an optional CTA sentence automatically appended to every meta description (e.g. "Free EU shipping & 15-day return.")
- **Word-boundary meta trimming** — if the model exceeds your configured limits, descriptions are trimmed at the nearest word boundary before being saved to your SEO plugin

### 2. SEO & Answer Engine Optimization (AEO)
- Auto FAQ schema, HowTo schema, Speakable markup
- Coverage reports (meta titles, descriptions, focus keywords)
- Works with: **Rank Math**, **Yoast**, **All in One SEO**, **SEOPress**
- **Standalone SEO writer fallback** — when no third-party SEO plugin is installed, LuwiPress stores title + meta description in its own meta keys and outputs them in the site `<head>`. You can run the full enrichment pipeline without installing Rank Math or Yoast.
- **Hreflang** — automatic `<link rel="alternate">` tags for multilingual stores. Respects WPML/Polylang native output in `auto` mode; force-on (`always`) or disable (`never`) via settings.
- Detects thin content, missing alt text, stale posts
- Answer-engine ready markup for Google AI Overviews & voice assistants

### 3. Multilingual Translation
- **WPML** + **Polylang** native integration (writes through their API)
- Translates: product descriptions, meta fields, taxonomy, Elementor pages
- SEO keyword awareness — keeps target terms intact per language
- Chunked AI translation for long content (avoids timeouts)
- Background cron processing
- Coverage tracking per language + content type
- **Pick any language as source** — if one language copy of a product is cleaner than the default, retranslate the others from that copy (`source_language` parameter)
- **Fail-safe content protection** — if the AI returns an unparseable response, the translation is rejected and your existing content is left untouched (no risk of raw payloads ending up on your product page)
- **Batch translate by language** — translate up to 200 missing posts in a single call (`POST /translation/batch`). Powers the "Translate N missing products" button on Knowledge Graph language nodes.

### 4. Elementor Mastery (34+ tools)
- Read full page structure via REST API (tree + flat widget view + compact outline)
- Edit widget text, styles, responsive overrides — breakpoint-aware
- Add / delete / move / clone sections, columns, widgets programmatically
- **Section reorder** — reorganize layouts by ID order
- **Find & replace** — text or styles across multiple pages in one call
- **Structure sync** — propagate layout changes to WPML translations (preserves translated texts)
- **Global design tokens** — read/write Elementor Kit colors & typography
- **Template library** — list + apply saved templates across multiple posts
- **Kit CSS management** — append layered rules with named markers, batch-apply to many posts
- **CSS vars** — read/write CSS custom properties on Kit for instant design-token changes
- **Production safety protocol**:
  - Named snapshot before every mutation (`/elementor/snapshot`), rollback by ID (`/elementor/rollback`)
  - Auto-snapshot on reorder, sync-structure, translate operations
  - `/elementor/flush-css` purges per-post Elementor CSS cache after edits
  - Snapshot retention: up to 10 per post, listable via `/elementor/snapshots/{id}`
- Auto-fix spacing issues, responsive audits

### 5. Customer Chat Widget (Frontend AI Assistant)
- Vanilla JS, ~15KB, no frameworks
- **BM25 + Knowledge Graph RAG** — answers from your store data
- Intent classification: product inquiry, shipping, returns, orders, stock, escalation
- **WhatsApp + Telegram escalation** with deep links
- Tone presets (friendly, professional, casual, expert, luxury, custom)
- FAQ short-circuit for common questions
- Policy injection (return, shipping, privacy)
- Session persistence (localStorage)
- **Rate limiting** (30 msg/hour/visitor)
- **Separate daily chat budget** (independent from admin)
- **GDPR compliant** — 90-day auto-cleanup

### 6. Open Claw — Admin AI Assistant — **companion plugin**

Shipped as a separate **LuwiPress Open Claw** plugin from 3.1.0 onward. Chat-style command interface inside WP admin.

- **Local commands** (zero AI cost):
  - `/scan` — site health check
  - `/seo` — SEO gap analysis
  - `/translate` — missing translations
  - `/thin` — thin content detection
  - `/stale` — outdated content
  - `/plugins` — integration status
  - `/aeo` — schema coverage
  - `/revenue` — revenue overview
  - `/products` — product inventory
- **AI commands**:
  - `/enrich` — enrich products
  - `/generate [topic]` — generate blog post
  - `/help` — command help
- Uses core LuwiPress's AI Engine token and daily budget — no separate setup
- Conversation history stored per user (50 messages rolling window)

### 7. Knowledge Graph
A single REST endpoint plus an interactive D3.js admin page that turns your store into a visual intelligence map.

**Data exposed (20+ sections):**
- Products, categories, taxonomy, tags
- SEO coverage, AEO opportunities (FAQ/HowTo/Speakable)
- Translation status per language (ürün + sayfa + taxonomy)
- CRM segments (VIP, at-risk, dormant)
- Media inventory, menu structure, authors
- Order analytics (12-month revenue, repeat rate, payment methods, refunds)
- Plugin health (SEO, translation, email, CRM, cache, support detection)
- Design audit (Elementor Kit CSS coverage, responsive issues)
- **Opportunity scoring** — weighted algorithm flags thin content, missing translations, missing schema

**Interactive admin page (3.1.4 overhaul):**
- **Search** with `/` keyboard shortcut, typeahead over products/posts/categories, auto-zoom to result
- **Presets**: All / Needs SEO / Not enriched / Thin content / Translation backlog / High opportunity
- **Three views**: Products (default), Posts, Pages (with parent-child hierarchy)
- **Eight stat cards**: Products, Posts, SEO coverage, Enriched, Opportunities, Design Health, Plugin Health, 30-day Revenue, Taxonomy Coverage
- **Clickable detail panels**: Revenue dashboard (sparkline + top sellers + inventory + payments + refunds), Plugin Health (readiness score + recommendations), Taxonomy Heatmap (matrix × languages + bulk translate)
- **Category batch actions** — enrich or translate every product in a category with one click
- **Export**: CSV opportunity list, CSV missing SEO (with Edit URLs), JSON, PNG snapshot
- **Keyboard**: `/` search, `r` refresh, `1/2/3` view, `Esc` close, `?` help
- **Cache badge** — tells you whether data is cached or freshly computed
- Auto-invalidation on content and meta changes; Refresh button forces reload

### 8. Content Scheduler & Blog Automation
- **4-step wizard interface** — Topics → Style → Schedule → Review, with a live progress bar, per-step validation, and step-jump navigation for completed steps
- **Draft-first workflow** (default) — AI-generated articles land as reviewable WP drafts with the target publish date baked in; one-click "Review & publish" jumps straight to the post editor
- **Auto-publish mode** — the classic path for hands-off batches: AI finishes, article goes live on the scheduled date with no manual step
- **Content depth presets** — Standard (800–1500 words), Deep (1500–3000 words with research framing + FAQ), Editorial (2000–3500+ words with narrative voice)
- **Two-phase Outline Approval** (deep/editorial only) — AI first drafts a structural outline (title, hook, sections with bullet points, FAQ, closing approach), editor reviews and edits it in a modal, then Phase 2 writes the full article strictly following the approved outline
- **Brand voice card** — site-level default + per-batch override that layers operator-defined voice (audience, forbidden openers, preferred opening style, cultural context, banned terms) on top of the depth rules
- **AI topic brainstorm** — proposes specific publishable titles from a theme you supply, filtered against the last 30 post titles so no duplicates leak in; brand-voice aware and returns a recommended depth tier per suggestion
- **Per-topic pipe overrides** — `Topic | keywords | depth=editorial | words=3000 | tone=creative | image=0 | lang=tr | type=post` lets individual rows deviate from batch defaults without leaving the textarea
- **Bulk queue** — paste up to 50 topics at once, pick a start date and publish cadence (1 post/day, every 6 hours, etc.); AI generation is staggered so the daily budget doesn't burst
- **Budget preview** in the Review step — live cost estimate using real provider/model pricing (per-topic input + output tokens × batch size, plus optional image cost), auto-scales with multilingual duplicate
- **Enrich draft** — one-click button on draft rows runs Internal Linker (resolves `[INTERNAL_LINK: anchor]` markers) and AI taxonomy suggestion (picks from existing categories/tags only — never invents new terms)
- **Bulk approve & publish** — per-row checkboxes + select-all reveal a toolbar: Publish selected (drafts only), Retry, Delete. 12 drafts → 1 click
- **Queue filter tabs** — All / Pending / Generating / Outline review / Ready / Published / Failed
- **Failed retry** — one-click retry on failed rows, clears error and re-queues AI 30s out
- **Multilingual duplicate** — if WPML or Polylang is active, pick additional languages per batch; each picked language gets its own natively-written article linked via the translation plugin's API (one 12-topic batch → 48 linked articles across 4 languages)
- **Recurring plans** — theme + cadence (daily/weekly/biweekly/monthly) + post count + depth + language → LuwiPress auto-brainstorms and queues fresh topics on your schedule; budget-aware defers when the daily cap hits; pause / resume / edit / delete per plan
- **Image generation** — DALL-E 3, DALL-E 2, Gemini Imagen 3 for featured images
- **Custom post type support** — Posts, Pages, Products, or any public CPT
- **"Run N pending now"** shortcut — bypasses wp-cron for operators who want immediate processing

### 9. Review Analytics
- Sentiment analysis on WooCommerce reviews
- AI-drafted professional responses
- Sentiment trend dashboard

### 10. CRM & Customer Segmentation
- **Pure WooCommerce** — segments are computed directly from order history. No third-party CRM plugin is required, and LuwiPress never writes to FluentCRM/Mailchimp/Klaviyo contact lists. Your CRM plugin (if any) keeps ownership of its own automations.
- 8 segments: **VIP**, **Loyal**, **Active**, **New**, **At-Risk**, **Dormant**, **Lost**, **One-Time**
- Per-customer profile with order history + lifetime value + reviews
- Lifecycle event queue (post-purchase thank-you, review request, win-back) for downstream email pipelines
- Configurable thresholds: VIP spend, loyal order count, active/at-risk/dormant windows

### 11. Marketplace Integration (v2.0.8+)
Multi-marketplace publishing:
- **Amazon**, **eBay**, **Trendyol**, **Hepsiburada**, **N11**
- **Alibaba**, **Etsy**, **Walmart**
- Product field mapping per marketplace schema
- Sync status tracking DB table
- Batch publishing

### 12. WebMCP Server (AI Agent Integration) — **companion plugin**

Shipped as a separate **LuwiPress WebMCP** plugin from 3.0.0 onward. Install it alongside the core plugin to expose the Model Context Protocol endpoint. This keeps the core install lean for stores that don't need AI-agent integration.

**130+ MCP tools** for AI agents:
- Streamable HTTP transport (MCP spec draft 2025-03-26)
- Origin validation (DNS rebinding protection)
- Tools for: content, SEO, translation, Elementor, CRM, media, settings, cache, customer insights
- Per-module settings tools (`enrich_settings_get/set`, `translation_settings_get/set`, `chat_settings_get/set`, `schedule_settings_get/set`)
- Token-based authentication — same Bearer token as REST API

---

## 📦 Companion Plugins

Two optional plugins extend LuwiPress core. Install them only if you need the feature.

| Plugin | Purpose | Size |
|--------|---------|------|
| **LuwiPress WebMCP** | MCP server exposing 130+ tools to AI agents. Needed if you use MCP clients (e.g. to let an AI agent manage your store remotely). | ~210 KB uncompressed |
| **LuwiPress Open Claw** | In-admin AI chat assistant with slash commands. Needed if you want natural-language store management from the WordPress admin. | ~26 KB uncompressed |

Both companions require the core LuwiPress plugin. They attach to the same admin menu, reuse the core's authentication, and share the daily AI budget.

---

## 🔐 Security & Performance

### Authentication (5 methods)
- **JWT Tokens** — RFC 7519 compliant, cryptographic secrets
- **Bearer Token** — LuwiPress API token (`lp_xxx`)
- **Session Cookie** — WP admin users
- **HMAC Signatures** — webhook signing
- **IP Whitelist** — restrict source IPs

### Security Features
- SQL injection prevention (parameterized queries)
- XSS protection (esc_html, wp_kses)
- CSRF nonce validation on admin AJAX
- Rate limiting per IP (1000 req/hour default)
- Token revocation by JTI
- Race condition guards (transient locks)
- MIME validation on uploads (10MB max)
- **Cache-layer isolation for authenticated REST responses** — every `luwipress/v1/*` response stamps `Cache-Control: no-store, private` plus a matching LiteSpeed-specific header so upstream page caches (LiteSpeed, Varnish, NGINX FastCGI, Cloudflare cache-everything) cannot replay an admin's body to a later unauthenticated visitor. Sensitive endpoints — CRM revenue, knowledge graph, settings, logs — are never served from a shared cache. Recommended belt-and-braces: also add `wp-json/luwipress` to your full-page cache plugin's "Do Not Cache URIs" list.

### Performance
- Lightweight middleware — heavy lifting on external AI API
- Async WP Cron job queue for long operations
- Transient caching with intelligent invalidation
- BM25 full-text search index (fast product lookup)
- Auto cleanup: logs (30d), tokens (90d), chat (90d)

---

## 💰 Cost Control

- **Daily token budget** — configurable with auto-pause (default $1/day)
- **Emergency stop** — one-click disable of all AI features
- **Per-workflow tracking** — costs broken down by workflow, model, provider
- **Real-time budget check** endpoint for pre-call validation
- **Separate chat budget** — customer chat won't consume admin budget
- **7-day cost history chart** in dashboard
- **Token usage report** with execution ID tracking

---

## 📊 REST API (70+ Endpoints)

All endpoints under namespace `luwipress/v1`.

**Core:** `/status`, `/health`, `/webhook`, `/token-usage`, `/token-stats`
**Products:** `/product/enrich`, `/product/enrich-batch`, `/product/enrich-batch/status`, `/enrich/settings` (GET/POST — remote read/write of the custom prompt and meta constraints)
**SEO:** `/seo/meta`, `/seo/schema`
**AEO:** `/aeo/generate-faq`, `/aeo/generate-howto`, `/aeo/coverage`, `/aeo/save-*`
**Translation:** `/translation/missing`, `/translation/missing-all`, `/translation/request`, `/translation/batch` (bulk N posts × M languages), `/translation/quality-check`, `/translation/settings` (GET/POST)
**Content:** `/content/stale`, `/content/opportunities`, `/content/resolve-links`
**Scheduler:** `/schedule/list`, `/schedule/callback`, `/schedule/settings` (GET/POST)
**Chat:** `/chat/message`, `/chat/session/{id}`, `/chat/config`, `/chat/session/escalate`, `/chat/settings` (GET/POST)
**Elementor (31 endpoints):** `/elementor/page/{id}`, `/outline/{id}`, `/widget`, `/style`, `/bulk-update`, `/add-widget`, `/add-section`, `/delete`, `/move`, `/clone`, `/custom-css`, `/responsive`, `/global-style`, `/sync-styles`, `/snapshot`, `/rollback`, `/snapshots/{id}`, `/audit/{id}`, `/responsive-audit/{id}`, `/auto-fix`, `/translate`, `/translate-queue`, `/global-css`, `/batch-css`, `/kit`, `/flush-css`, `/print-method`, `/google-fonts`, `/reorder-sections`, `/find-replace`, `/sync-structure`, `/css-vars`, `/templates`, `/apply-template`
**CRM:** `/crm/overview`, `/crm/segments`, `/crm/customer/{id}`
**Reviews:** `/review/sentiment-callback`, `/review/analytics`
**Marketplace:** `/marketplace/publish`, `/publish-batch`, `/status/{id}`, `/overview`, `/categories`
**Knowledge Graph:** `/knowledge-graph`
**Site:** `/site-config`, `/logs`
**JWT:** `/jwt-auth/v1/token`, `/validate`, `/refresh`

---

## 🔌 Plugin Integrations (Auto-Detected)

| Category | Supported Plugins |
|----------|-------------------|
| **SEO** | Rank Math · Yoast · All in One SEO · SEOPress |
| **Translation** | WPML · Polylang · TranslatePress |
| **Email / SMTP** | WP Mail SMTP · FluentSMTP · Post SMTP |
| **CRM** | FluentCRM · Mailchimp for WC *(detected only — LuwiPress never writes to their contact lists)* |
| **Customer Support** | Chatwoot |
| **Page Builder** | Elementor (full integration) · Divi (detection) |
| **Cache** | WP Rocket · LiteSpeed · W3 Total Cache (auto-purge) |
| **Analytics** | Google Site Kit · GTM4WP · MonsterInsights |
| **Google Ads** | Google for WooCommerce · Conversios |
| **Meta Ads** | Meta Pixel · Meta for WC · PixelYourSite |
| **Product Feed** | Google Listings & Ads · Product Feed PRO · CTX Feed |

---

## 🗄️ Database Tables

1. `wp_luwipress_token_usage` — AI cost tracking
2. `wp_luwipress_logs` — Activity logs (30d retention)
3. `wp_luwipress_chat_conversations` — Customer chat sessions
4. `wp_luwipress_chat_messages` — Chat message history
5. `wp_luwipress_search_index` — BM25 full-text search
6. `wp_luwipress_marketplace_listings` — Marketplace sync status

---

## 🖥️ Admin Pages

1. **Dashboard** — Hero stats, content health ring, 7-day cost chart, workflow breakdown, translation coverage, recent logs
2. **Settings** — 9 tabs (Connection, General, AI API Keys, AI Content, Translation, CRM, Customer Chat, Marketplaces, Security)
3. **Usage & Logs** — Token tracking, cost breakdown, API call history
4. **Knowledge Graph** — D3.js interactive store intelligence visualization
5. **Content Scheduler** — Blog automation
6. **Translation Manager** — Missing translations by language

Add the companion plugins to light up:
- **Open Claw** menu item (via LuwiPress Open Claw companion)
- **WebMCP** menu item (via LuwiPress WebMCP companion)

---

## ⚡ Technical Specs

| Spec | Detail |
|------|--------|
| **WordPress** | 5.6+ (tested up to 6.9) |
| **PHP** | 7.4+ |
| **WooCommerce** | 5.0+ (optional) |
| **Core bundle size** | ~365 KB (60 files) · WebMCP companion ~210 KB · Open Claw companion ~26 KB |
| **Database** | Automatic cleanup, indexed tables |
| **Caching** | Transient + object cache compatible |
| **Cron** | WP Cron job queue for async work |
| **Unicode** | Full multibyte string support |
| **Image Upload** | 10 MB max, MIME validated |

---

## 🚀 Why LuwiPress?

- **Standalone** — no external dependencies, works with existing stack
- **Cost-conscious** — built-in budget controls prevent runaway AI costs
- **AI-agent ready** — full REST + WebMCP integration for Claude Code, OpenAI, custom agents
- **Multilingual-first** — WPML/Polylang native, not afterthought
- **Elementor power-user tool** — 30+ programmatic editing endpoints
- **Safety net** — snapshots before every destructive operation
- **Enterprise-grade security** — JWT, HMAC, IP whitelist, rate limiting
- **Modular** — 30+ feature classes, enable only what you need

---

*Document version 3.1.39 — updated 2026-04-28 · For technical API documentation, see individual endpoint documentation at `/wp-json/luwipress/v1/` or request the developer reference. For the WebMCP tool catalog, see the separate WebMCP feature overview.*
