=== LuwiPress — AI-Powered WooCommerce Automation ===
Contributors: luwidev
Tags: woocommerce, ai, seo, translation, automation, product enrichment, multilingual
Requires at least: 5.6
Tested up to: 6.9
Requires PHP: 7.4
Stable tag: 3.1.9
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

AI-powered content enrichment, SEO optimization, and translation automation for WooCommerce stores for WooCommerce stores.

== Description ==

LuwiPress is a **standalone AI plugin** for WooCommerce stores — generates content, optimizes SEO, translates products, and automates store management without external dependencies.

**It doesn't replace your plugins — it makes them smarter.**

= What LuwiPress Adds =

| Your Plugin Does | LuwiPress Adds (via AI) |
|---|---|
| Rank Math stores SEO meta | AI generates optimized titles, descriptions, FAQ schema |
| WPML manages languages | AI translates with SEO keyword awareness |
| WooCommerce collects reviews | AI drafts professional review responses |
| WordPress schedules posts | AI generates fresh blog content + images |
| SEO plugins add basic schema | AI generates FAQ/HowTo/Speakable schema |

= Key Features =

* **AI Product Enrichment** — Generate rich descriptions, meta titles, FAQ schema, alt text
* **SEO-Aware Translation** — Translate with keyword density awareness via WPML/Polylang
* **Answer Engine Optimization (AEO)** — FAQ, HowTo, Speakable structured data
* **Content Scheduling** — AI blog posts with DALL-E generated images
* **AI Review Responder** — Auto-respond to WooCommerce product reviews
* **Open Claw AI Assistant** — Chat interface for managing your store with natural language
* **CRM Lifecycle** — Customer segmentation (VIP, at-risk, dormant) + win-back campaigns
* **WebMCP Server** — MCP Streamable HTTP server exposing 115+ tools to AI agents (Claude Desktop, Claude Code, any MCP client)
* **Plugin Auto-Detection** — Automatically detects Rank Math, WPML, Polylang, WP Mail SMTP, FluentCRM, Chatwoot
* **Cost Protection** — Daily budget limits, token tracking, per-workflow cost breakdown
* **Multi-Provider AI** — OpenAI (GPT-4o Mini), Anthropic (Claude), Google (Gemini)

= How It Works =

1. Install LuwiPress on your WordPress site
2. Enter your AI API key (OpenAI recommended for best cost/quality ratio)
3. Set a daily budget limit to control costs
4. Use Open Claw AI assistant or dashboard buttons to trigger automations — all AI calls run natively in WordPress, no external workflow engine required

= Open Claw AI Assistant =

Built-in chat interface with slash commands:

* `/scan` — Content opportunity scan
* `/seo` — Products missing SEO meta
* `/translate` — Start translation pipeline
* `/enrich` — Batch AI enrichment
* `/thin` — Thin content products
* `/stale` — Stale content list
* `/generate [topic]` — Generate blog post
* `/aeo` — AEO schema coverage
* `/help` — All available commands

= Cost Control =

LuwiPress includes built-in cost protection:

* **Daily budget limit** — AI features auto-pause when reached ($1.00 default)
* **Token usage tracking** — See exactly which workflows cost money
* **Model selection** — GPT-4o Mini is 20x cheaper than Claude Sonnet
* **Local commands** — /scan, /seo, /translate work without any AI cost
* **No hidden charges** — You bring your own API key, you control spending

= Requirements =

* WordPress 5.6+
* WooCommerce 5.0+ (recommended)
* AI API key (OpenAI, Anthropic, Google, or any OpenAI-compatible endpoint)

= Supported Plugin Integrations =

* **SEO:** Rank Math, Yoast, AIOSEO, SEOPress
* **Translation:** WPML, Polylang, TranslatePress
* **Email:** WP Mail SMTP, FluentSMTP, Post SMTP
* **CRM:** FluentCRM, Mailchimp for WooCommerce
* **Support:** Chatwoot

== Installation ==

1. Upload the `luwipress` folder to `/wp-content/plugins/`
2. Activate the plugin through the 'Plugins' menu
3. Go to **Settings → AI API Keys** and enter your OpenAI, Anthropic, Google, or OpenAI-compatible API key
4. Set your daily budget limit (recommended: $1.00/day)
5. (Optional) Install the **LuwiPress WebMCP** companion plugin if you want to expose the store to AI agents over the Model Context Protocol

== Frequently Asked Questions ==

= Do I need any external workflow engine? =

No. LuwiPress is fully standalone as of 2.0.1 — the AI engine, job queue, and prompt templates all run natively inside WordPress. No n8n, no Make, no Zapier required. You only need an AI API key.

= Which AI provider should I use? =

We recommend **OpenAI GPT-4o Mini** for the best cost/quality ratio. At $0.15 per million input tokens, you can run thousands of operations for under $1/day.

= Will this increase my hosting costs? =

No. LuwiPress is lightweight — async jobs run through WP-Cron and most AI calls complete in under a second of PHP time. The heavy lifting happens on the AI provider's side, not your server.

= Is my data sent to third parties? =

Product data is sent directly from your WordPress site to the AI provider you choose (OpenAI, Anthropic, Google, or an OpenAI-compatible endpoint you configure). No data is sent to LuwiPress servers or any intermediate service.

= Can I use this without WooCommerce? =

Yes, but WooCommerce-specific features (product enrichment, review responses) won't be available. Content scheduling, translation, and Open Claw still work.

= How do I control costs? =

Set a daily budget limit in Settings → AI API Keys. When reached, all AI features pause until the next day. Local commands (/scan, /seo, etc.) always work with zero cost.

== Screenshots ==

1. Dashboard with AI Token Usage tracking
2. Open Claw AI Assistant chat interface
3. Plugin auto-detection showing Rank Math, WPML, WP Mail SMTP
4. Settings page with model selection and daily budget limit
5. Translation coverage manager
6. Activity log with workflow results

== Changelog ==

= 3.1.9 — Content Scheduler Bulk Queue + KG Dropdown Fix =
* NEW: Content Scheduler → "Bulk Queue" section. Paste up to 50 topics (one per line) with optional `| keywords` pipe syntax on each line. Set a start date, a publish spacing (e.g. "1 day" or "6 hours"), an AI stagger interval (default: 5 minutes between generation runs so you don't burst your AI budget), shared tone / word count / language / post type, and the whole batch is queued in one click. Each topic becomes an individual schedule row with its own `wp_schedule_single_event` worker, so AI calls are spread out automatically. A "Run N pending now" button lets operators kick the queue forward without waiting for wp-cron.
* NEW: Budget-aware deferral — if the daily AI budget is exhausted when a scheduled generation fires, the task automatically reschedules itself for 1 hour later instead of failing. Operators can raise the cap and the queue picks up from where it left off.
* NEW: `luwipress_generate_single` cron hook — single-item AI generation handler reused by the bulk queue and available for any third-party code that wants to queue content from its own context.
* FIX: Knowledge Graph Preset/Export dropdowns — when one dropdown was open and the other was clicked, the first one wouldn't close. `e.stopPropagation()` was blocking the document-level outside-click listener used by sibling dropdowns. Now only one dropdown is ever open; a new opener explicitly closes its peers.

= 3.1.8 — Dead Code Removal (n8n residue cleanup) =
* CLEANUP: Removed the `LuwiPress_AI_Engine::MODE_N8N` constant and the `forward_to_n8n()` stub entirely. These had been deprecated since 2.0.1 (n8n integration removed, all AI handled natively) but were kept as stubs for backward compatibility. They were unreachable in practice — `get_mode()` always returned `'local'` — so removing them has no runtime effect.
* CLEANUP: Five dead `if ( MODE_N8N === $mode )` branches in AEO generation, single-product enrichment, batch enrichment, per-post translation, and taxonomy translation call sites are gone. Each had lived alongside the real local-AI path since 2.0.1.
* CLEANUP: `LuwiPress_AI_Engine::get_mode()` and the `MODE_LOCAL` constant also removed — no callers left in the core plugin.
* CLEANUP: `luwipress_processing_mode` option is no longer written on save or read on load. `luwipress_seo_webhook_url` no longer surfaces in `/site-config` responses. The old Translation Manager "Webhook" engine badge was dropped — the badge now always reads "Local AI".
* CLEANUP: `LuwiPress_AI_Content::$webhook_url` and `$webhook_api_token` properties (only ever written, never read) removed.
* NO BREAKING CHANGE for end users: the affected code paths were already dead. If a third-party integration was still calling `forward_to_n8n()` or `get_mode()` directly, it would have been receiving a deprecation error / `'local'` constant anyway. File a ticket if you hit unexpected fatal errors — there's an easy shim to add back.

= 3.1.7 — Batch Monitor, CSV Round-trip, Layout Memory =
* NEW: Enrichment batch monitor — when you queue "Enrich all products in category" (or any `/product/enrich-batch` call), a floating progress panel appears in the bottom-right corner with a live striped progress bar, elapsed time, and per-status chips (queued / running / done / failed). Polls `/product/enrich-batch/status` every 3 seconds until complete, then refreshes the graph automatically.
* NEW: CSV round-trip for SEO meta — the Export dropdown now has an "Upload CSV → apply SEO meta" option. Export the "CSV — Opportunity list" or "CSV — Missing SEO" file, edit it offline (Excel, Sheets, any tool), then re-upload. The frontend parses the CSV (flexible column detection: `ID` / `post_id`, `SEO Title` / `Meta Title` / `Title`, `Meta Desc` / `Description`, `Focus Keyword`), previews the count, and dispatches the batch to the new `POST /seo/meta-bulk` endpoint. Up to 500 rows per request. Writes go through the detected SEO plugin (Rank Math / Yoast / AIOSEO / SEOPress) exactly as the single-row `/seo/meta` endpoint does.
* NEW: Knowledge Graph layout memory — when you drag a node to a preferred position, the position is pinned and saved to localStorage per view (Products / Posts / Pages / Customers). Reopen the graph and your layout is where you left it. A new reset button (↺) in the zoom controls clears the saved layout for the current view and re-flows the simulation.
* IMPROVED: Design Health stat card now reads "N/A" instead of "0%" on sites without Elementor installed. The card becomes non-interactive (no click-through, no hover cue) when the metric doesn't apply. Server-side `design_audit.elementor_available` flag surfaces this cleanly.
* IMPROVED: `/knowledge-graph` endpoint description updated to list `design_audit` as a valid section (it was always accepted by the handler, but the args description was missing it).

= 3.1.6 — Knowledge Graph Asset Split (performance) =
* IMPROVED: Knowledge Graph admin page is now served as a separate `assets/js/knowledge-graph.js` (~93 KB) + `assets/css/knowledge-graph.css` (~26 KB) bundle. These load only on the Knowledge Graph page, not on every LuwiPress admin screen. Page templates drop from 2,466 lines to 174 lines; `admin.css` drops 1,000+ lines (~22% smaller) for every non-KG admin page load. Config (REST URL, API token, nonce) is injected via `wp_localize_script` as `window.lpKgConfig` — no more inline PHP-generated JSON.
* NO BREAKING CHANGE: no functional or behaviour change — refactor only. The KG page behaves exactly like 3.1.5.

= 3.1.5 — Customers & Elementor Audit Drill-down =
* NEW: Knowledge Graph fourth view — **Customers**. Shows eight customer segments (VIP, Loyal, Active, New, One-Time, At Risk, Dormant, Lost) as color-coded nodes sized by cohort count. Each segment has its own detail panel with segment definition, priority action, and targeted recommendations (e.g. win-back campaign for One-Time buyers, reactivation offer for Dormant, VIP perk program for VIPs). Keyboard shortcut `4` added for quick switch.
* NEW: Design Audit drill-down — click any page row in the Design Health panel to open a dedicated Elementor audit view for that page. Issues are grouped by severity (critical/warning/info) and then by issue type, with affected element IDs listed as code chips. "Open in Elementor" and "View live" buttons jump you straight to the editor or the rendered page.
* IMPROVED: Design Health panel's per-page results are now scannable summaries (severity counts instead of full issue dumps). The deep list lives in the drill-down where you can act on it.
* IMPROVED: Knowledge Graph fetch now includes the `crm` section so customer segment data is available without a second request.
* IMPROVED: Keyboard shortcut help (`?` dialog) updated to include the fourth view.

= 3.1.4 — Knowledge Graph Overhaul =
* NEW: Knowledge Graph search — typeahead over products, posts, and categories. Keyboard: `/` to focus, `↑↓` to navigate, Enter to select. Selected node auto-zooms and opens its detail panel.
* NEW: Knowledge Graph presets — filter dropdown with six built-in views: All items, Needs SEO meta, Not enriched, Thin content, Translation backlog, High opportunity (score > 30). Active preset shown as a badge.
* NEW: Knowledge Graph export — four formats: CSV opportunity list (opportunity_score desc), CSV missing SEO meta (with Edit URL column), JSON raw graph, PNG viewport snapshot. All with UTF-8 BOM for spreadsheet compatibility.
* NEW: Pages view — third tab in the graph; parent-child hierarchy with `child_of` edges. Homepage, shop, blog, top-level, and child pages get distinct colours and sizes. Page detail panel shows role badge, template, content length, and recommendations (thin content, orphan pages).
* NEW: Order Analytics stat card — click to open a Revenue panel with today/7-day/30-day revenue snapshot, AOV, 12-month SVG sparkline, customer retention health bar, top 5 sellers, inventory status, payment method breakdown, and refunds (last 90 days).
* NEW: Plugin Health stat card — readiness score plus per-category detected plugins (SEO, translation, email, CRM, cache, support) and prioritised recommendations.
* NEW: Taxonomy Coverage heatmap — click the new stat card to see a matrix of taxonomy type × language with colour-coded coverage (≥95% green, 70-94% orange, 40-69% dark, <40% red). Missing terms listed per language with a "Translate all" button that dispatches one request per taxonomy type.
* NEW: Category batch actions — from any category detail panel, queue every product in that category for enrichment or translation to a specific language. Frontend collects product IDs from the cached graph; backend `/translation/batch` now accepts an optional `post_ids` whitelist parameter.
* NEW: Cache badge in the graph header — shows whether the current data was served from cache (`cached`) or freshly computed (`fresh (Nms)`). The Refresh button bypasses cache; normal page loads now hit the cache reliably.
* NEW: Keyboard shortcuts — `/` search, `r` refresh, `1/2/3` view switch (Products/Posts/Pages), `Esc` close detail panel, `?` show shortcut help.
* IMPROVED: Knowledge Graph force simulation now adapts to node count (alphaDecay, collision padding, charge strength) so large catalogues (1000+ products) converge in a few seconds instead of 10+.
* IMPROVED: Cache invalidation is now triggered when LuwiPress or SEO-plugin meta keys change, not only on `save_post`. Async AI enrichment writes no longer leave stale graph data.
* IMPROVED: `GET /knowledge-graph` accepts both `section` and `sections` parameters. Previous admin requests silently loaded all 20 sections because of a parameter mismatch.
* FIX: Recommendation action buttons on the Knowledge Graph detail panel were silently failing — the refresh callback referenced functions that lived in an inner scope. The action buttons (Enrich, FAQ, HowTo, Translate) now execute reliably and the panel re-opens with fresh data.
* FIX: WebMCP companion status on Settings → Connection tab now reflects the companion's own default (`enabled` when the option has not been persisted yet). Previously the admin UI showed "Disabled" even though the MCP endpoint was live.
* FIX: Dashboard header "Open Claw AI" button replaced with Knowledge Graph shortcut — the Open Claw page was split out in 3.1.0 and the old button led nowhere. OpenAI-Compatible provider now recognised by the AI-key detector and provider label map, so 3.1.2 users no longer see "No AI key" when only that provider is configured. Plugin pills deduplicated across Google Ads / Meta Ads / Product Feed categories — a single plugin covering two categories (e.g. Google Listings & Ads) now shows once.
* CLEANUP: Legacy `n8nPress` / `n8n workflow` references removed from all user-facing surfaces (admin UI, plugin metadata, LICENSE, readme). Internal identifiers also renamed (`$n8n_webhook_url` → `$webhook_url`, `check_n8n_token()` → `check_api_token()`). No breaking change — `MODE_N8N` and `forward_to_n8n()` kept as deprecated stubs for backward compatibility.

= 3.1.3 — Primary Language Dropdown Uses Detected Languages =
* IMPROVED: Settings → AI Content → Primary Language now reads the active languages from WPML, Polylang, or TranslatePress when one is installed, instead of the old 11-language hardcoded list. You can only pick a language your site actually serves, removing a foot-gun where Tapadum-style sites had `tr` selected while the site ran on EN+FR+IT+ES.
* NEW: Badge above the dropdown shows which translation plugin was detected and how many languages it exposes. Default language from the translation plugin is surfaced as a hint so the admin knows what the source-of-truth is.
* NEW: Save handler validates the submitted language against the detected active list; an invalid submission snaps to the translation plugin's default language instead of silently persisting a mismatched value.
* NEW: When a previously-saved language is no longer active (e.g. site disabled a language in WPML), a warning line appears inline on the dropdown so admins can fix it explicitly.
* FALLBACK: If no translation plugin is present, the original list (11 common locales) still ships as the fallback. No breaking change for single-language sites.

= 3.1.2 — Multi-Provider Expansion (OpenAI-Compatible) =
* NEW: Fourth AI provider slot — **OpenAI-Compatible** — a single provider class that talks to any vendor exposing OpenAI's `/chat/completions` schema. Ships with presets for **DeepSeek**, **Moonshot Kimi**, **Groq**, and **Together.ai**, plus a **Custom** preset for self-hosted LLMs (Ollama, vLLM, LM Studio, text-generation-webui).
* NEW: Settings → AI Content now exposes the OpenAI-Compatible card with preset dropdown, API key, optional custom base URL, and model selector. Switching preset updates the base URL and model list instantly. Off-peak discount hints (e.g. DeepSeek's ~50% discount window 16:30–00:30 UTC) are shown under the model picker.
* IMPROVED: Fallback chain extended to include `openai-compatible` — if the primary provider fails transiently (429, 5xx, network, 404-on-model), the engine now considers the OpenAI-Compatible provider too.
* IMPROVED: `/site-config` response masks the OpenAI-Compatible API key with the same last-4-char hint pattern introduced in 3.1.1. No breaking change to other secret fields.
* NO BREAKING CHANGE: Existing OpenAI, Anthropic, and Google configurations work unchanged. Provider interface is unchanged — third-party code calling `LuwiPress_AI_Engine::dispatch()` needs no updates. Adding a new OpenAI-compatible vendor in the future is a one-line preset addition, no new class required.

= 3.1.1 — Security: Secret Masking in Site Config =
* SECURITY: `GET /site-config` no longer returns the LuwiPress API token or the active AI provider's API key in clear text. The response now exposes `api_token_configured` / `api_key_configured` booleans plus `*_hint` fields showing only the last 4 characters, which is sufficient to verify configuration without leaking the full secret. Any existing tokens/keys that may have been surfaced to third parties (automation logs, MCP transcripts, remote clients) should be rotated.
* NO BREAKING CHANGE to other fields — `site`, `woocommerce`, `plugins`, and the non-secret `luwipress.*` fields are unchanged. Consumers that previously read `luwipress.api_token` or `luwipress.ai.api_key` must switch to the `_configured` boolean; the full secret was never intended to be consumed by external clients.

= 3.1.0 — Open Claw Companion Split =
* BREAKING: The Open Claw admin AI assistant has moved to a separate companion plugin (`luwipress-open-claw`). Sites that use Open Claw must install the companion to keep the LuwiPress → Open Claw menu working. Conversation history and all settings are preserved.
* NEW: Migration notice — admin users on sites without the companion see a dismissable banner pointing them to the plugin.
* NEW: Core dropped ~26 KB / 500 LOC. Core is now focused on WooCommerce AI automation; admin chat is opt-in via companion.
* CLEANUP: Settings tab "Open Claw" removed (was already reduced to a info card pointing at the admin page — now that page moves too).
* UI: Settings → Advertising tab removed (all Google Ads / Meta Ads option fields were dead — no code ever consumed them).
* UI: Settings → CRM info message sadeleşti; FluentCRM/Mailchimp mention removed.
* UI: Open Claw admin sidebar no longer references Telegram/WhatsApp channel status (those channels were removed in the 2.0.0 slim rewrite).

= 3.0.0 — Companion Plugin Split (breaking) =
* BREAKING: WebMCP has moved to a separate companion plugin (`luwipress-webmcp`). Stores that relied on the MCP endpoint must install the companion to keep AI-agent integration working. `luwipress_webmcp_enabled` + `luwipress_webmcp_allowed_origins` options are preserved across the split.
* NEW: Core plugin dropped ~211 KB / 4,172 LOC. Core is now focused on WooCommerce AI automation; MCP is opt-in via companion.
* NEW: One-time migration notice — admin users on sites that had WebMCP enabled see a dismissable banner pointing them to the companion plugin.
* NEW: `translation_batch` MCP tool (mirrors `POST /translation/batch`) and 4 module-settings MCP tool pairs (`enrich_settings_*`, `translation_settings_*`, `chat_settings_*`, `schedule_settings_*`). Ships with the companion.
* NEW: `cache_purge` MCP tool (mirrors `POST /cache/purge`). Ships with the companion.
* REFACTOR: Module settings methods unified across modules — `AI_Content`, `Translation`, `Customer_Chat`, `Content_Scheduler` all expose `handle_get_settings()` and `handle_set_settings()` now. Makes future automation uniform.

= 2.1.0 — SEO Writer Fallback & Batch Translation =
* NEW: Standalone SEO writer fallback — when no third-party SEO plugin (Rank Math, Yoast, AIOSEO, SEOPress) is detected, LuwiPress now writes its own `_luwipress_seo_title`/`_description`/`_focus_keyword` meta and outputs `<title>` + `<meta name="description">` in `wp_head`. Removes the hidden hard-requirement on a third-party SEO plugin for stores that want LuwiPress to own their SEO metadata end-to-end.
* MERGE: Hreflang module folded into the SEO writer (`class-luwipress-hreflang.php` removed; logic moved to `class-luwipress-seo-writer.php`). Single responsibility, same behaviour — mode `auto`/`always`/`never` preserved.
* NEW: `POST /translation/batch` — translate N untranslated posts for one or more target languages in a single call. Fixes the dead "Translate N missing products" button on language nodes in the Knowledge Graph.

= 2.0.11 — Plugin Slimming =
* SLIM: Theme Manager + Demo Import modules removed from plugin (63 KB / 2,082 lines / 5+ endpoints). Theme setup now lives in the themes themselves.
* SLIM: CRM Bridge — FluentCRM/Mailchimp write paths removed. Segmentation and lifecycle events are now pure-WooCommerce; no third-party CRM plugins are written to, the plugin only reports on its own WooCommerce-derived data.
* NEW: Settings REST pattern — `GET/POST /translation/settings`, `/chat/settings`, `/schedule/settings` (partial-update, admin auth), mirroring the existing `/enrich/settings`. Remote automation (n8n, Claude Code, companion plugins) can now manage module configuration without SSH/WP-CLI.

= 2.0.10 — Translation & Enrichment Hardening =
* NEW: Enrichment prompt customization — Settings → AI tab now exposes a custom system prompt textarea with variable substitution (`{product_title}`, `{category}`, `{focus_keyword}`, `{price}`, `{currency}`, `{site_name}`, `{target_language}`) for store-specific output structure
* NEW: Enrichment constraints — configurable target word count, meta title max (40–80), meta description max (120–200), and optional CTA sentence auto-appended to every meta description
* NEW: `/product/enrich` `options` now accepts `custom_instructions`, `target_words`, `meta_title_max`, `meta_desc_max`, `focus_keyword` for per-request overrides
* NEW: `GET /enrich/settings` and `POST /enrich/settings` — read/write the enrichment prompt and constraints remotely (admin auth; `POST` is partial-update, only keys present in the body are touched)
* NEW: Callback-side meta trimming — AI-generated meta title/description are trimmed to configured limits (word-boundary aware) before being saved to the active SEO plugin
* FIX: Translation — when AI JSON response fails to parse, translation is now rejected and marked failed instead of writing raw payload text into post_content (prevents corrupted product descriptions)
* FIX: Translation — added `source_language` parameter to `/translation/request` so a clean sibling language can be used as source when the default-language copy is corrupted
* FIX: Translation — self-retranslate path: when the post being retranslated is already in the target language, content is now rewritten in place via `wp_update_post` + `clean_post_cache` instead of silently skipped
* NEW: Enrich — WPML language guard on `/product/enrich`, `/product/enrich-batch`, and enrich-callback endpoints. Enrichment writes default-language content; writes to translation copies are now rejected with `language_mismatch` (409). Set `allow_translation_target=true` in the callback body to override.
* NEW: Batch enrich now auto-skips translation copies with a warning log entry instead of queueing them
* NEW: `LuwiPress_Translation::get_post_wpml_language()` helper — centralised handling of WPML language detection (array/object/null response shapes)
* IMPROVED: Translation log line now includes source language override + resolved source post id for traceability

= 2.0.9 — Theme Library & Smart Setup =
* NEW: Theme Library — multi-theme catalog with install/activate/setup from plugin
* NEW: Theme Manager engine — remote ZIP install via Theme_Upgrader, one-click activate
* NEW: 4-step setup wizard — Requirements → Install → Activate → Setup Store
* NEW: 3 themes in catalog — Luwi Elementor (available), Luwi Minimal & Boutique (coming soon)
* NEW: Smart setup — WC store info auto-fills Contact page (email, phone, address)
* NEW: Color presets picker — Burnished Gold, Forest Green, Deep Navy, Dusty Rose
* NEW: Full production safety — snapshot before changes, one-click rollback to previous state
* NEW: Health check after setup — verifies site responds with HTTP 200
* NEW: WooCommerce page verification — Shop/Cart/Checkout/MyAccount auto-created if missing
* NEW: Checkpoint system — setup progress saved, resumable on failure
* NEW: Blog/Journal starter page added (7 pages total)
* NEW: Cleanup — trash starter pages + reset homepage/blog in one click
* IMPROVED: Demo content — Luwi palette, Playfair Display headings, Luwi widgets
* IMPROVED: Elementor guard — import disabled when Elementor not active
* IMPROVED: Confirmation dialogs before theme activation and page import

= 2.0.8 — Marketplace Integration =
* NEW: Multi-marketplace product publishing (Amazon, eBay, Trendyol, Hepsiburada, N11, Alibaba, Etsy, Walmart)
* NEW: Marketplace adapter pattern — interface-based, each marketplace is a separate adapter class
* NEW: REST API endpoints — publish, publish-batch, status, overview, test, categories (luwipress/v1/marketplace/*)
* NEW: Marketplace listings DB table with sync status tracking
* NEW: Settings tab — search-enabled grid UI with per-marketplace credentials and enable toggle
* NEW: Product data mapping — WooCommerce fields automatically mapped to each marketplace's format
* NEW: Design-system-aligned minimal card UI with autocomplete search, status badges (Live/Ready/Off)

= 2.0.6 — Rebrand Cleanup =
* REBRAND: All CSS variables renamed --n8n-* → --lp-*
* REBRAND: All HTML classes renamed n8np-* → lp-*
* REBRAND: JS functions and WebMCP client class renamed
* REBRAND: MCP resource URIs changed n8npress:// → luwipress://
* REBRAND: readme.txt rewritten — standalone AI plugin description
* REBRAND: Removed all n8n references from user-facing UI
* IMPROVED: Coverage calculation — only counts real translations linked to EN sources
* IMPROVED: Fix Orphans tool detects EN-registered non-English posts
* IMPROVED: MCP content tools bypass WPML language filter

= 2.0.7 — Customer Chat Widget + WhatsApp/Telegram Escalation =
* NEW: AI-powered customer chat widget (frontend, vanilla JS, <15KB)
* NEW: RAG pipeline — FAQ short-circuit, product search via Knowledge Graph, store policy injection
* NEW: Intent classification — product inquiry, shipping, returns, order status, stock check, escalation
* NEW: WhatsApp/Telegram escalation via deep links — customer connects directly with team
* NEW: Channel choice dialog when both WhatsApp and Telegram are configured
* NEW: Customer Chat settings tab — greeting, colors, position, policies, budget, rate limiting
* NEW: Separate daily AI budget for customer chat (independent from admin budget)
* NEW: Rate limiting per visitor (30 messages/hour default, configurable)
* NEW: Custom DB tables for conversations and messages (not wp_options)
* NEW: GDPR compliance — auto-cleanup after 90 days, delete customer data endpoint
* NEW: Session persistence via localStorage — conversation survives page navigation
* NEW: Order status lookup for logged-in WooCommerce customers
* NEW: Auto-escalation suggestion after configurable message count

= 2.0.5 — Full Site X-Ray & WordPress Control =
* NEW: Knowledge Graph — 8 new sections: posts, pages, taxonomies, media inventory, menus, product attributes, authors, order analytics
* NEW: 44 MCP tools for full WordPress control — users, orders, coupons, media, comments, settings, plugins, themes, menus, taxonomies, custom fields
* NEW: Blog post nodes with SEO status, categories, tags, author, staleness detection
* NEW: Page hierarchy nodes with template detection, parent/child relationships
* NEW: Media inventory — missing alt text count, orphaned media, file size analysis
* NEW: Navigation menu structure with full item hierarchy
* NEW: WooCommerce order analytics — 12-month revenue trend, repeat customer rate, payment methods, refund tracking
* NEW: Author nodes with per-type content counts and activity tracking
* NEW: Product attribute nodes with term listing
* NEW: Content taxonomy discovery — all registered taxonomies with top terms
* FIX: MCP translation_missing tool parameter mismatch (language → target_language)
* FIX: MCP translation_taxonomy_missing tool parameter mismatch (language → target_languages)
* IMPROVED: Cache invalidation broadened to save_post, delete_post, created_term, delete_term
* IMPROVED: Settings tools use whitelist — siteurl, home, admin_email blocked for security

= 2.0.4 — Translation Pipeline Hardening =
* SECURITY: SQL injection fix — parameterized IN() placeholders in wpdb->prepare()
* SECURITY: XSS fix — esc_html() on translated titles in AJAX responses
* SECURITY: Race condition fix — transient lock on WPML translation creation
* SECURITY: Nonce field added to Translation Manager form
* FIX: Fatal error — replaced new WP_Post() with get_post()
* FIX: Slug generation — translated posts now get sanitized slugs from translated title
* FIX: Excerpt extraction — blog listing excerpts from first text-editor widget
* FIX: WPML element_type dynamic (was hardcoded post_product for pages/posts)
* FIX: WooCommerce meta copy guarded to product post_type only
* FIX: Category taxonomy dynamic per post_type (was hardcoded product_cat)
* FIX: Cron exception handling — try-catch prevents stuck "translating" status
* NEW: Auto-snapshot before Elementor page translation (rollback support)
* NEW: WP Revision integration — pre-translation revision saved, auto-revert on failure
* NEW: Translation progress polling — AJAX endpoint for background job status
* NEW: JS poller in Translation Manager for Elementor background jobs
* NEW: Per-term taxonomy translation with live progress bar
* NEW: "Re-translate Broken" maintenance tool — fixes empty title / numeric slug posts
* IMPROVED: REST API accepts post_id param (backward compat product_id)
* IMPROVED: Response key items (backward compat products)

= 2.0.3 — Elementor Chunked Translation & KG Redesign =
* NEW: Elementor page translation with chunked AI (background WP Cron)
* NEW: ElementsKit heading widget support (ekit_heading_title, ekit_heading_extra_title)
* NEW: Knowledge Graph redesign — product health bar, category/language panels, hover tooltips
* FIX: Translation pipeline source language from WPML (was using target language option)
* FIX: Taxonomy translation source_language_code fix
* FIX: Grey overlay backdrop removed from KG sidebar panel

= 2.0.2 — Translation Manager & Elementor Tools =
* NEW: Translation Manager UI — step-based dashboard with coverage tracking
* NEW: 20+ Elementor WebMCP tools — translate, style, bulk-update, snapshot, rollback
* NEW: Elementor page outline endpoint for AI agents

= 2.0.1 — Standalone AI Engine =
* NEW: Multi-provider AI Engine (OpenAI, Anthropic, Google)
* NEW: Job Queue with WP Cron for async processing
* NEW: Token Tracker with budget enforcement
* REMOVED: n8n dependency — all AI calls are now native

= 2.0.0 — LuwiPress Rebrand =
* REBRAND: n8nPress → LuwiPress
* NEW: Standalone plugin architecture (no external dependencies)
* NEW: Plugin Detector — auto-detects SEO, Translation, SMTP, CRM plugins

= 1.10.0 — WebMCP: MCP Server for AI Agents =
* NEW: MCP Streamable HTTP server at /luwipress/v1/mcp (spec 2025-03-26)
* NEW: 40+ MCP tools across 14 categories (content, SEO, AEO, translation, CRM, email, etc.)
* NEW: MCP resources — site-config, health, AEO coverage + parameterized templates (post, seo-meta, translation-status)
* NEW: MCP tool annotations — readOnlyHint, destructiveHint, idempotentHint, openWorldHint
* NEW: MCP session management with UUID sessions and 1-hour TTL
* NEW: Origin validation (DNS rebinding protection per MCP spec)
* NEW: Cursor-based pagination for tools/list
* NEW: Argument autocompletion for resource templates (completion/complete)
* NEW: Knowledge Graph endpoint — AI-consumable entity graph with WPML-aware translation status
* NEW: WebMCP admin page with tool catalog, connection tester, and usage examples
* NEW: Browser-side MCP client class (webmcp-client.js)
* NEW: Extensibility hook — luwipress_webmcp_register_tools for third-party tools
* NEW: WEBMCP-INTEGRATION.md documentation for AI agent tool discovery
* IMPROVED: Site config permission now accepts logged-in admins (cookie + nonce auth)

= 1.9.0 — Security & Code Quality =
* SECURITY: JWT secret now uses random_bytes(32) instead of wp_generate_password (C2)
* SECURITY: Token revocation mechanism — revoke compromised JWT tokens by jti (C3)
* SECURITY: Image sideload validates MIME type (jpeg/png/webp/gif) and max size 10MB (C4)
* SECURITY: Admin pages check current_user_can('manage_options') on direct access (H4)
* SECURITY: Enrichment uses transient lock to prevent race conditions (H6)
* SECURITY: HMAC secret uses cryptographically secure random_bytes (C2)
* FIX: Meta copy uses whitelist (WC product fields only) instead of blacklist (H2)
* FIX: Daily auto-cleanup cron for logs, tokens, workflow stats (H3)
* FIX: Translation Manager taxonomy count excludes orphan WPML records
* FIX: Knowledge Graph default language uses get_locale() instead of hardcoded 'tr' (M5)

= 1.8.4 =
* FIX: Knowledge Graph now uses WPML icl_translations for translation status (was using meta only)
* FIX: Taxonomy translation WPML registration uses SQL fallback for REST context
* FIX: Taxonomy callback validates term existence before saving
* FIX: Removed orphan term fallback that caused false positives in taxonomy counts
* NEW: Test suite for translation system (tests/test-translation-system.php)

= 1.8.3 =
* NEW: AI image generation for products and posts (DALL-E 3, DALL-E 2, Gemini Imagen 3)
* NEW: Image provider setting in AI Content tab (with per-image pricing display)
* NEW: Gemini 2.5 Flash and Gemini 2.5 Pro model options
* NEW: Image provider and AI provider sent in _meta block to n8n workflows
* IMPROVED: Enrich callback auto-sideloads generated images as featured image
* IMPROVED: Token tracker includes image generation pricing

= 1.8.2 =
* FIX: Post Categories and Post Tags now appear in Translation Manager (WPML fallback for unregistered taxonomies)
* FIX: Taxonomy term count excludes WPML translation copies (was double-counting)
* FIX: Auto-register unregistered taxonomy terms in WPML before translation
* FIX: Knowledge Graph plugins section loads product data for SEO coverage calculation
* IMPROVED: AIOSEO and SEOPress focus keyword meta key support added
* IMPROVED: Translation Manager Bulk Translation section removed (redundant with per-language buttons)
* NEW: Fix Category Assignments maintenance button (re-assigns translated products to correct categories)

= 1.8.1 =
* NEW: Knowledge Graph endpoint (GET /knowledge-graph) — AI-consumable graph of products, categories, languages, SEO/AEO coverage, and opportunity scores
* FIX: SEO meta writing now uses Plugin Detector (was hardcoded Yoast+RankMath — AIOSEO/SEOPress now work)
* FIX: Polylang translation create — new posts are now created with full WC meta, images, taxonomies, SEO meta (was update-only)
* FIX: FAQPage JSON-LD schema now output in wp_head (was missing — Google can now see FAQ data)
* FIX: Cache invalidation added to all callbacks (WP Rocket, LiteSpeed, W3 Total Cache)
* FIX: Translation SEO meta reading now uses Plugin Detector (was hardcoded keys)

= 1.8.0 =
* Merged Logs + Token Usage into unified "Usage & Logs" page with tabs
* Added Quick Actions to dashboard (Translate, Enrich, Open Claw, Usage)
* Removed emoji from menu items
* Removed API Endpoints section from dashboard (developer info, not user-facing)
* Dashboard now shows compact activity feed + AI spend summary
* Grouped logs by date for better readability
* Fixed translation image copy on both create and update paths
* Fixed draft translations not being published
* Fixed large product translation (dynamic max_tokens scaling)
* Fixed batch translation item matching by product ID (not index)

= 1.7.4 =
* Fixed translation coverage percentage (exclude WPML translations from total count)
* Fixed batch translation workflow (SplitInBatches → native multi-item processing)
* Fixed n8n webhook registration (webhookId required for production URLs)
* Fixed n8n credential binding (credentials block required in workflow JSON)
* Fixed WP REST API for product data (WC API returns empty with Bearer token)
* Fixed product image sharing across WPML languages

= 1.7.3 =
* Fixed translation pipeline — OpenAI API, correct callback fields, data flow
* Fixed product image copy for WPML translations (shared across languages)
* Added category/tag translation coverage to Translation Manager
* Removed vendor-specific naming — all AI references are now generic
* Changed all default AI model/provider to GPT-4o Mini / OpenAI
* Fixed callback body construction (HTML content breaking JSON)
* Added Prepare Callback code nodes to safely build JSON bodies

= 1.7.0 =
* Added AI Token Usage tracking with daily/monthly cost breakdown
* Added daily budget limit with auto-pause when exceeded
* Added model selection (GPT-4o Mini, Claude, Gemini)
* Added cost protection guards to all AI-calling functions
* Switched default AI model to GPT-4o Mini (20x cheaper)
* Added dedup check to prevent duplicate enrichment requests
* Fixed cron race condition — disabled crons properly unschedule
* Added workflow result feedback with token reporting

= 1.6.3 =
* Added slash commands to Open Claw (/scan, /seo, /translate, etc.)
* Added command autocomplete popup
* Fixed activity log timestamp field mismatch
* Fixed log cleanup (Clear All Logs)
* Improved log messages with query context
* Added WooCommerce translation support warning for Polylang/WPML
* Removed Turkish text from intent patterns (English-only)

= 1.6.2 =
* Fixed Open Claw 404 on Scan Opportunities and Missing SEO Meta
* Added local resolution for content scans (no n8n dependency)
* Fixed translation pipeline language parameter handling
* Added DB auto-upgrade on plugin update

= 1.6.1 =
* Fixed dashboard icon alignment
* Added Chatwoot integration with Telegram notifications
* Moved Elementor/LiteSpeed to status bar

== Upgrade Notice ==

= 1.7.0 =
Critical cost protection update. Adds daily budget limits and switches to GPT-4o Mini (20x cheaper). Strongly recommended.

== Additional Information ==

LuwiPress is developed by **Luwi Developments LLC** — a boutique AI agency working with founders, startups, and creative teams. We specialize in custom AI workflows, process automation, and intelligent systems.

*Dream. Design. Develop.*

= Free Plugin, Professional Support =

LuwiPress is free and open-source. You bring your own AI API key — no subscription, no hidden fees, no vendor lock-in, no external services in the loop.

Need help getting started? We offer:

* **Free:** Plugin + documentation
* **Setup Service:** We configure the AI engine, migrate existing SEO/translation data, and get your store automations running
* **Custom Automations:** Tailored AI pipelines for your specific business needs
* **Ongoing Support:** Monitoring, optimization, and new automation development

= Resources =

* [Documentation & Guides](https://luwi.dev)
* [GitHub Repository](https://github.com/umutsun/luwipress)

= Contact =

* **Email:** hello@luwi.dev
* **Web:** [luwi.dev](https://luwi.dev)
* **Services:** Custom AI automations, WooCommerce optimization, store intelligence
