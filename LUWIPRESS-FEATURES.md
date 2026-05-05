# LuwiPress — Complete Feature Overview

**Version:** 3.1.44 · **License:** GPLv2+ · **Target:** WooCommerce stores

LuwiPress is a standalone, AI-powered automation plugin for WordPress/WooCommerce. It generates content, optimizes SEO, translates products, and automates store management — integrating seamlessly with existing plugins (Rank Math, WPML, Elementor, etc.) without replacing them.

Shipped as a lean **365 KB core** plus two optional companion plugins — install only what your store needs.

## 🆕 What's new in 3.x

- **Knowledge Graph Autopilot (3.1.47)** — the dashboard's Action Queue can now act on its own. Every cycle, the Autopilot reads ranked candidates from the new opportunity engine, filters them against a confidence threshold the operator sets, enforces per-workflow daily caps (so a runaway can't drain the AI budget), and dispatches the actual workflow asynchronously. Ships **default-OFF**, and the very first run after activation is a **dry-run** — the system logs every "would-dispatch" decision so the operator can verify the plan before flipping to live. Per-entity idempotency means the same product won't get re-enriched within a 24-hour window. New REST endpoints (`/knowledge-graph/autopilot/settings`, `/run-now`, `/log`) and a daily cron expose the full state. v1 ships with `enrich` workflow live; `seo` + `translate` candidates record a `pending_implementation` outcome ready for v2 wiring. A new "AI Autopilot" panel on the Knowledge Graph dashboard surfaces the toggle, caps, and recent dispatch log inline with the rest of the gamification suite.
- **Knowledge Graph signal layer + opportunity engine v2 (3.1.46)** — the middleware backbone behind the Autopilot. Every product enrichment, SEO meta write, and translation request now records a structured `kg_event` row (with full context payload — entity, snapshot, score-delta) and busts the KG cache deterministically so the next dashboard load reflects the change. A new `/knowledge-graph/events` REST endpoint exposes a filtered stream (with optional 24-hour summary aggregation) for downstream consumers. The Action Queue gains two server-computed candidate types: **RECENTLY_REGRESSED** (entity coverage that lost ground in the last 7 days, computed against the existing 30-day summary ring) and **STALE_ENRICHED** (products enriched > 90 days ago whose source content has been edited since). Each candidate carries a `why` payload — primary signal, supporting signals, baseline comparison — so the UI can explain *why* an action ranks where it does instead of just showing a number. Operators can **snooze** (default 24h, max 30d), **dismiss**, or mark **in-progress** any v2 candidate from the queue cards; state persists across sessions and auto-prunes after 30 days.
- **Theme companion contract + reciprocal awareness (3.1.45)** — LuwiPress now formally recognises an active "official theme" via the new `LuwiPress_Plugin_Detector::detect_theme()` and exposes a "✓ Theme paired" pill on the admin dashboard when a paired theme is active. A new `luwipress_theme_companion` filter contract lets the active theme advertise which storefront features it ships (AI search surface, Knowledge-Graph related rail, slug-conflict migration tool, etc.) so the plugin admin and other companions can introspect capability matrix without scraping CSS classes. Backbone for tiered ecosystem packaging. First-class action hooks (`luwipress_after_product_enrich`, `_seo_meta_write`, `_translation_request`) replace generic `save_post_*` inference — themes and companion plugins now subscribe to clean, semantic signals.
- **Marketplace + Open Claw companion split (3.1.44)** — two more modules carved out of core. **Marketplace publishing** (Amazon, eBay, Trendyol, Hepsiburada, N11, Etsy, Walmart, Alibaba) is now the dedicated **LuwiPress Marketplace Sync** companion plugin — only stores that actually sell on third-party marketplaces install it, keeping the core ~80 KB lighter for everyone else. **Open Claw** (the admin-side natural-language AI chat assistant) is similarly its own companion. All saved credentials, REST endpoints, and option keys carry over without migration — install the companion and previously-stored API keys light up instantly. The core's settings page sheds the Marketplaces tab; the dashboard's "Ask AI" link becomes a soft reference that only renders when the Open Claw companion is active. Stores that ignore both verticals see no functional change other than a smaller core ZIP.
- **WordPress Abilities API bridge (3.1.43)** — LuwiPress now mirrors its 150+ MCP tools into the WordPress 6.9+ Abilities API (`wp_register_ability`) automatically. AI clients that use the WP-native registry (the upcoming WordPress 7.0 AI client, the WooCommerce MCP adapter, third-party agent platforms that read `wp_get_abilities()`) will discover every LuwiPress capability under the `luwipress/` namespace alongside their existing tools — no second integration to wire up. Read-only abilities default to public; mutating abilities are private until the operator opts them in. The existing WebMCP endpoint stays online for backward compatibility, so stores running on WP 6.8 and earlier are unaffected. WooCommerce MCP namespace inclusion is wired via the standard `woocommerce_mcp_include_ability` filter, letting an agent query a single WC MCP endpoint and reach LuwiPress tools through it.
- **ACP attribution bridge (3.1.43)** — Stripe's Agentic Commerce Protocol (the December 2025 launch with WooCommerce as a launch partner) lets AI shopping agents complete checkouts server-to-server. Those orders bypass the browser entirely — no GA4 gtag fires, no Meta Pixel cookie, no Google Ads gclid lands — so analytics + ad platforms see nothing and Smart Bidding starts misreading "this campaign isn't converting." LuwiPress now reconstructs the conversion server-side: every WooCommerce payment hook fires a multi-cast dispatch to GA4 Measurement Protocol and Meta Conversions API with the buyer's hashed identifiers, the AI agent name from the ACP `affiliate_attribution` block (so you can segment ChatGPT vs Perplexity vs others in your reporting), and a stable `event_id` for cross-channel deduplication. Ships default-OFF with a debug mode that logs payloads instead of firing — credentials get tested against Meta's Events Manager test code before going live. Settings + audit log are managed via `GET/POST /attribution/settings`, `GET /attribution/log`, `POST /attribution/test`, and the four matching MCP tools (`attribution_settings_get`, `attribution_settings_set`, `attribution_log_recent`, `attribution_test_send`).
- **Google Ads Enhanced Conversions for AI orders (3.1.44)** — extends the ACP attribution bridge with a third channel: server-side click conversion upload to Google Ads via the v17 REST API. Because ACP orders carry no GCLID, LuwiPress sends the conversion as an *enhanced conversion* — hashed email + phone + first/last name as `userIdentifiers` — which Google's docs explicitly support: "you can, and should, send all relevant data for a given conversion, even if you don't have a GCLID for it." OAuth tokens are refreshed on demand and cached for 50 minutes (10-minute headroom under Google's 1-hour expiry); refresh-token rotation is handled automatically so the operator pastes credentials once. Ad campaigns that drive AI-agent traffic finally close the loop in Smart Bidding instead of being silently downgraded as non-converting.
- **Theme Builder template MCP (3.1.42)** — six new endpoints + MCP tools for managing Elementor Pro Theme Builder templates from the API: `elementor_templates_list`, `elementor_template_create`, `elementor_template_clone`, `elementor_template_conditions_get`, `elementor_template_conditions_set`, `elementor_template_delete`. AI agents can now enumerate every template (header / footer / single-post / single-product / archive / single-404 / search-results / cart / checkout / my-account / popup / kit / page / section), scaffold a missing template by cloning an existing one, set display conditions (`include/general`, `include/post`, `include/product_archive`, etc.), and delete obsolete templates safely (active header / footer / kit are protected unless `force=true`). Closes the gap that left LuwiPress AI workflows blind to Theme Builder; the operator can now ask "create a Single Post template cloned from Single Product, then apply it to all posts" in one MCP call instead of opening admin manually.
- **Suspicious bot / fake customer audit (3.1.42)** — new `GET /crm/suspicious-bots` read-only endpoint scores every customer record on six bot signals: random-looking email local part (`dman7387@gmail.com`), disposable e-mail domain, zero orders, registered <30 days with no purchase, empty first/last name, never logged in. Returns a flagged list with score, reasons, and registration metadata so the operator can review + delete via the standard WP Users admin. The audit endpoint never deletes anything itself; an opt-in `purge` action is planned for the next release after threshold tuning. Tested on a 6,366-customer store: 6,247 flagged with zero false positives against the segmented buyer list.
- **Snapshot rollback safety closed (3.1.42)** — three rounds of investigation finally pinned the root cause of the long-standing snapshot/rollback corruption (the Darbuka incident on 2026-05-01 was a fatal example): `update_post_meta` strips slashes deep into nested string values inside the snapshot array, so the legacy `data` field stored Elementor JSON without the escapes its parser depends on. New snapshots now store a `data_b64` payload alongside the legacy field — base64 is opaque to slash sanitisation, round-trips byte-perfect. Rollback prefers `data_b64` when present and writes via direct `$wpdb` to bypass the WP meta API entirely. Snapshot/rollback is now production-safe again.
- **Customer feedback batch hotfix (3.1.42)** — eight bugs from the 2026-04-28 Tapadum feedback package closed in one release. `crm_segment_customers` returns proper customer JSON (was always `Unknown segment`); `aeo_save_faq` and `aeo_generate_faq` save FAQs (were always `Invalid product ID`); `seo_enrich_product` accepts `force_regen_faq=true` to clear cached FAQ before AI call; `content_stale` returns the right cutoff (was always `1970-01-01`); Knowledge Graph `top_products` exposes `id` and `issue_count` (were null); KG `top_sellers` filters out trashed products (was 50% empty rows on Tapadum); Elementor cron now skips pages with no translatable text instead of re-queuing forever; taxonomy translation failures now report a structured `reason` field instead of failing silently. Knowledge Graph opportunity scoring also drops the `missing_speakable` and `missing_howto` signals that Google deprecated late 2024 — Tapadum's score went from 2,934 → 2,423 (roughly 17% noise removed).
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

### 4. Elementor Mastery (38+ tools)
- Read full page structure via REST API (tree + flat widget view + compact outline + **deep outline** with backgrounds)
- **Inspect helpers** — find any element by ID with full ancestor chain, find by text without DOM scraping, deep outline including container/widget hierarchy
- Edit widget text, styles, responsive overrides — breakpoint-aware
- Add / delete / move / clone sections, columns, widgets programmatically
- **Section reorder** — reorganize layouts by ID order
- **Cross-post section copy** — copy a top-level section into another post with deep ID regeneration + auto-snapshot (sister to clone, but cross-post)
- **Find & replace** — text or styles across multiple pages in one call
- **Structure sync** — propagate layout changes to WPML translations (preserves translated texts)
- **Global design tokens** — read/write Elementor Kit colors & typography
- **Template library** — list + apply saved templates across multiple posts
- **Kit CSS management** — append layered rules with named markers, batch-apply to many posts, **preflight size check** before pushing (prevents silent option-size truncation)
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

Shipped as a separate **LuwiPress Open Claw** plugin (reactivated as a first-class active companion in 3.1.44). Chat-style command interface inside WP admin.

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

### 11. Marketplace Integration — **companion plugin (since 3.1.44)**
Shipped as a separate **LuwiPress Marketplace Sync** plugin from 3.1.44 onward. Stores that don't sell on third-party marketplaces no longer carry the dormant adapter code in core.
- Multi-marketplace publishing: **Amazon**, **eBay**, **Trendyol**, **Hepsiburada**, **N11**, **Alibaba**, **Etsy**, **Walmart**
- Product field mapping per marketplace schema, batch publishing, per-product sync status
- Standalone "LuwiPress → Marketplaces" submenu attaches under the core LuwiPress parent menu — operator finds it where they expect
- All credentials and the sync table (`wp_luwipress_marketplace_listings`) carry over from earlier LuwiPress installs without migration
- REST endpoints (`/wp-json/luwipress/v1/marketplace/*`) live on the companion; they only register when the companion is active

### 12. WebMCP Server (AI Agent Integration) — **companion plugin**

Shipped as a separate **LuwiPress WebMCP** plugin from 3.0.0 onward. Install it alongside the core plugin to expose the Model Context Protocol endpoint. This keeps the core install lean for stores that don't need AI-agent integration.

**160 MCP tools** for AI agents (as of WebMCP 1.0.11 / server 2.0.3):
- Streamable HTTP transport (MCP spec 2025-03-26)
- Origin validation (DNS rebinding protection per spec)
- Tools for: content, SEO, AEO, translation, Elementor, CRM, media, settings, cache, customer insights, Theme Builder templates, ACP attribution, search index, plugin/theme management, taxonomy, comments, menus, post meta
- Per-module settings tools (`enrich_settings_get/set`, `translation_settings_get/set`, `chat_settings_get/set`, `schedule_settings_get/set`, `attribution_settings_get/set`, `crm_settings_get/set`)
- **Theme orchestration tools (since 1.0.11):** `theme_status` (active theme + capability flags), `theme_customizer_dump` (all `theme_mod`s with optional prefix filter), `theme_customizer_get` / `theme_customizer_set` (read/write a single Customizer setting with key-shape-aware sanitization), `theme_ecosystem_status` (full storefront ecosystem snapshot — which AI surfaces are live, which friendly plugins are detected, today's AI token spend). Lets an AI orchestrator drive every theme-side process end-to-end without leaving the MCP transport.
- Tool annotations (`readOnlyHint`, `destructiveHint`, `idempotentHint`, `openWorldHint`) so AI clients can reason about side-effects before invoking
- Token-based authentication — same Bearer token as REST API
- **JSON-RPC strictness** — rejects non-2.0 jsonrpc payloads with -32600
- **Activation gate** — companion refuses to activate when core LuwiPress is not active and surfaces a clear "install core first" page; soft-paused notice appears only on the Plugins screen if core is later deactivated

### 13. ACP Attribution Bridge (since 3.1.43)

Reconstructs server-side conversion tracking for orders that arrive via Stripe's **Agentic Commerce Protocol** (the December 2025 launch with WooCommerce as a launch partner). ACP orders bypass the browser entirely — no GA4 gtag, no Meta Pixel cookie, no Google Ads gclid — so analytics platforms see nothing and Smart Bidding starts misreading "this campaign isn't converting." The bridge fills the gap.

- **Three dispatch channels** — GA4 Measurement Protocol, Meta Conversions API, Google Ads Enhanced Conversions
- **AI agent name extraction** — pulls `affiliate_attribution.provider` / `publisher_id` / `campaign_id` from the ACP order metadata so you can segment ChatGPT vs Perplexity vs Claude in your reporting
- **Hashed user identifiers** — email, phone, first/last name (SHA-256) never leave your server in plain text; sent to Meta CAPI and Google Ads Enhanced Conversions for high match quality without GCLID
- **Stable `event_id`** for cross-channel deduplication so a Pixel event and a CAPI event for the same order are not double-counted
- **Async dispatch** via `wp_schedule_single_event` — checkout pages don't block on outbound HTTP to ad platforms (worst-case 15-20 s saved)
- **Idempotency** — `_luwipress_attribution_dispatched` order meta prevents double-fires when WC fires multiple status hooks for one order
- **Default OFF + debug mode** — credentials live in `/attribution/settings`; debug mode logs payloads to the audit table instead of firing real events; flip to live only after the operator validates with Meta's `test_event_code`
- **Audit log** retains last 200 dispatches with channel-by-channel result codes and AI agent provider so the operator can verify the bridge is firing
- **Google Ads OAuth refresh** is cached for 50 minutes (10-minute headroom under Google's 1-hour expiry) and rotated automatically; Manager (MCC) accounts supported via the optional `login_customer_id` setting
- **Endpoints**: `GET|POST /attribution/settings`, `GET /attribution/log`, `POST /attribution/test`, `POST /attribution/dispatch {order_id, force}`
- **MCP tools**: `attribution_settings_get`, `attribution_settings_set`, `attribution_log_recent`, `attribution_test_send`

### 14. WordPress Abilities API Bridge (since 3.1.43)

Mirrors LuwiPress's MCP tool registry into the **WordPress 6.9+ Abilities API** (`wp_register_ability`) so AI clients that read `wp_get_abilities()` — the upcoming WordPress 7.0 native AI client, the WooCommerce MCP adapter, third-party agent platforms — discover every LuwiPress capability through the standard WordPress registry without a second integration.

- **Auto-mirror** — every WebMCP tool registers as `luwipress/<tool-name>` ability on the `wp_abilities_api_init` hook
- **Soft-skip on older WordPress** — no-op on WP < 6.9 (no Abilities API present); the bridge never errors
- **Read/write capability gating** — read-only abilities default to `read` (any logged-in user), mutating abilities default to `manage_options` (admin only); per-ability override available via the `luwipress_abilities_public_overrides` option
- **Public/private flag** per ability — read-only tools default to public (visible in REST and the WC MCP namespace), mutating tools default to private; the operator opts each one in
- **Token-based auth stays in WebMCP** — Abilities API gives `permission_callback` only the input args (no request headers), so token-gated workflows continue to use the WebMCP endpoint; both registries run side-by-side
- **WooCommerce MCP namespace inclusion** — the standard `woocommerce_mcp_include_ability` filter wires LuwiPress public abilities into the WC MCP server (the official adapter shipped Feb 2026), so a single WC MCP endpoint reaches LuwiPress tools

---

## 📦 Companion Plugins

Three optional plugins extend LuwiPress core. Install only the ones you actually need — the core stays lean for stores that ignore these verticals.

| Plugin | Version | Purpose | Size |
|--------|---------|---------|------|
| **LuwiPress WebMCP** | 1.0.10 | MCP server exposing 155 tools to AI agents (Claude, ChatGPT, custom clients) over Streamable HTTP. Needed if you let an AI agent manage your store. | ~58 KB ZIP |
| **LuwiPress Marketplace Sync** | 1.0.1 | Multi-marketplace publishing (Amazon, eBay, Trendyol, Hepsiburada, N11, Etsy, Walmart, Alibaba). Needed if you sell on third-party marketplaces; otherwise dormant code stays out of your install. | ~29 KB ZIP |
| **LuwiPress Open Claw** | 1.0.1 | In-admin AI chat assistant with slash commands. Needed if you want natural-language store management from the WordPress admin. | ~12 KB ZIP |

**All three companions:**
- Require the core LuwiPress plugin (3.1.43+) to be active. Each refuses to activate when core is missing and surfaces a clear "install core first" page rather than a silent no-op.
- Attach to the same `LuwiPress` admin menu — operators find each feature where they expect it.
- Reuse core's authentication (Bearer token, JWT, session cookie, HMAC) and share the daily AI budget where applicable.
- Show a soft "paused" notice **only on the Plugins screen** (never on the dashboard or LuwiPress admin pages) when core is deactivated mid-flight.

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

## 📊 REST API (130+ Endpoints)

All endpoints under namespace `luwipress/v1`. As of 3.1.44 the live route count on a typical install is around **129–135** depending on which companion plugins are active.

**Core:** `/status`, `/health`, `/webhook`, `/token-usage`, `/token-stats`, `/token-recent`
**Products:** `/product/enrich`, `/product/enrich-batch`, `/product/enrich-batch/status`, `/enrich/settings` (GET/POST — remote read/write of the custom prompt and meta constraints)
**SEO:** `/seo/meta`, `/seo/schema`, `/seo/meta-bulk`
**AEO:** `/aeo/generate-faq`, `/aeo/generate-howto`, `/aeo/coverage`, `/aeo/save-*`
**Translation:** `/translation/missing`, `/translation/missing-all`, `/translation/request`, `/translation/batch` (bulk N posts × M languages), `/translation/quality-check`, `/translation/outdated`, `/translation/settings` (GET/POST)
**Content:** `/content/stale`, `/content/opportunities`, `/content/resolve-links`
**Scheduler:** `/schedule/list`, `/schedule/delta`, `/schedule/callback`, `/schedule/settings` (GET/POST)
**Chat:** `/chat/message`, `/chat/session/{id}`, `/chat/config`, `/chat/session/escalate`, `/chat/settings` (GET/POST)
**Elementor (35+ endpoints):** `/elementor/page/{id}`, `/outline/{id}`, `/widget`, `/style`, `/bulk-update`, `/add-widget`, `/add-section`, `/delete`, `/move`, `/clone`, `/copy-section`, `/custom-css`, `/responsive`, `/global-style`, `/sync-styles`, `/snapshot`, `/rollback`, `/snapshots/{id}`, `/audit/{id}`, `/responsive-audit/{id}`, `/auto-fix`, `/translate`, `/translate-queue`, `/global-css`, `/batch-css`, `/kit`, `/flush-css`, `/print-method`, `/google-fonts`, `/reorder-sections`, `/find-replace`, `/sync-structure`, `/css-vars`, `/templates`, `/template/create`, `/template/clone`, `/template/conditions` (GET/POST), `/template/delete`, `/apply-template`, `/purge-page-cache`
**CRM:** `/crm/overview`, `/crm/segments`, `/crm/segment/{slug}`, `/crm/customer/{id}`, `/crm/refresh-segments`, `/crm/suspicious-bots`, `/crm/lifecycle-queue`, `/crm/settings` (GET/POST)
**Reviews:** `/review/sentiment-callback`, `/review/analytics`
**Knowledge Graph:** `/knowledge-graph`
**Attribution (3.1.43+):** `/attribution/settings` (GET/POST), `/attribution/log`, `/attribution/test`, `/attribution/dispatch`
**Cache:** `/cache/purge`
**Site:** `/site-config`, `/logs`
**JWT:** `/jwt-auth/v1/token`, `/validate`, `/refresh`

**Companion-provided (only register when companion is active):**
- **Marketplace Sync** (1.0.1+): `/marketplace/publish`, `/marketplace/publish-batch`, `/marketplace/status/{id}`, `/marketplace/overview`, `/marketplace/test`, `/marketplace/categories`

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

Core LuwiPress creates 5 tables on activation (idempotent dbDelta — re-runs safely):

1. `wp_luwipress_token_usage` — AI cost tracking
2. `wp_luwipress_logs` — Activity logs (30 d retention)
3. `wp_luwipress_chat_conversations` — Customer chat sessions (90 d retention)
4. `wp_luwipress_chat_messages` — Chat message history (90 d retention)
5. `wp_luwipress_search_index` — BM25 full-text search index

**Companion-managed tables:**
- `wp_luwipress_marketplace_listings` — Marketplace sync status. Created and owned by the **LuwiPress Marketplace Sync** companion (3.1.44+). Existing rows from earlier core-bundled marketplace versions are picked up unchanged — no migration needed.

**Option-stored audit log:**
- `luwipress_attribution_log` (WP option, rolling last 200 entries) — ACP attribution dispatches. Promoted to a custom table in a future release if volume warrants.

---

## 🖥️ Admin Pages

Core LuwiPress ships these admin pages out of the box:

1. **Dashboard** — Store Health hero, Action Queue ("next wins" — top 3 ROI-scored cards), achievement badges, 8-card stat bar, knowledge graph canvas, live activity feed (30 s polling), 7-day trend deltas
2. **Settings** — 8 tabs (Connection, General, AI API Keys, AI Content, Translation, CRM, Customer Chat, Security)
3. **Usage & Logs** — Token tracking, 30-day cost sparkline, workflow bar chart, daily-budget meter, live activity log with debounced search (10 s polling, pauses when tab hidden)
4. **Knowledge Graph** — D3.js interactive store intelligence visualization (Products / Posts / Pages / Customers views)
5. **Content Scheduler** — 4-step wizard (Topics → Style → Schedule → Review), recurring plans, queue with delta polling
6. **Translation Manager** — Missing translations by language, outdated-translation panel with one-click sync/re-translate

**Companion plugins add their own submenu items** under the same `LuwiPress` parent menu when active:
- **LuwiPress → Marketplaces** (via LuwiPress Marketplace Sync companion) — credentials grid for 8 marketplaces with live status badges and search filter
- **LuwiPress → Open Claw** (via LuwiPress Open Claw companion) — admin AI chat assistant
- **LuwiPress → WebMCP** (via LuwiPress WebMCP companion) — MCP server config + tool catalog

---

## ⚡ Technical Specs

| Spec | Detail |
|------|--------|
| **WordPress** | 5.6+ (tested up to 6.9; Abilities API mirror activates on 6.9+) |
| **PHP** | 7.4+ |
| **WooCommerce** | 5.0+ (soft dependency since 3.1.38 — generic features run WC-less) |
| **Core bundle size** | ~545 KB ZIP (59 files) — WebMCP companion ~58 KB · Marketplace Sync companion ~29 KB · Open Claw companion ~12 KB |
| **Database** | 6 indexed tables, automatic cleanup (logs 30 d, tokens 90 d, chat 90 d) |
| **Caching** | Transient + object-cache compatible; REST responses stamp `Cache-Control: no-store` to prevent page-cache leaks of authenticated bodies |
| **Cron** | WP Cron job queue for async work; ACP attribution dispatch is queued (not inline) so checkout pages never block on outbound HTTP |
| **Unicode** | Full multibyte string support (Turkish, Arabic, CJK, RTL languages tested) |
| **Image Upload** | 10 MB max, MIME validated |

---

## 🚀 Why LuwiPress?

- **Standalone** — no external dependencies; integrates with the SEO/translation/email/cache plugins you already use instead of replacing them
- **Cost-conscious** — built-in budget controls (daily cap, emergency stop, real-time check, separate chat budget) prevent runaway AI costs
- **AI-agent ready** — full REST + WebMCP (155 tools) + WordPress 6.9 Abilities API mirror; works with Claude, ChatGPT, the upcoming WP 7.0 native AI client, and the WooCommerce MCP adapter
- **Agentic-commerce-ready** — first WordPress AI plugin with native ACP attribution bridge: GA4 + Meta CAPI + Google Ads Enhanced Conversions for Stripe Agentic Commerce orders that bypass the browser
- **Multilingual-first** — WPML / Polylang / TranslatePress native, not afterthought; outdated-translation detection with one-click sync
- **Elementor power-user tool** — 35+ programmatic editing endpoints, including Theme Builder template management and snapshot-protected mutations
- **Safety net** — snapshots before every destructive operation; idempotent dispatch with deduplication; default-OFF settings for outbound integrations
- **Enterprise-grade security** — JWT, HMAC, IP whitelist, rate limiting, REST cache-layer isolation, secret masking in API responses
- **Modular** — companion-plugin architecture (WebMCP, Marketplace Sync, Open Claw) keeps the core lean for stores that ignore those verticals; activation gates prevent broken-state installs
- **Soft WC dependency** — runs WC-less since 3.1.38; generic content/chat/scheduler features work on any WordPress site

---

*Document version 3.1.47 — updated 2026-05-06 · WebMCP companion at 1.0.11 (theme orchestration tools). LuwiPress Gold theme at 1.5.0 — first ecosystem-integrated theme; AI search suggestions, Knowledge-Graph-curated related products, an admin ecosystem dashboard at `Görünüm → LuwiPress Gold`, an enriched WooCommerce archive (banner image + featured sub-category tiles), a generic post archive (categories, tags, authors, dates) reusing the journal card, a `[data-lwp-yt]` YouTube modal, and a lossless slug-conflict migration tool at `Görünüm → LuwiPress Migration` (Type A WC archive collision rename + Type B canonical shadow swap, WPML-aware, fully restorable). 1.5.0 adds a full mobile responsive layer (slide-in drawer with accordion sub-categories, viewport-aware mega menu, PDP gallery + summary stack, footer column collapse), a smooth float-bar ↔ footer fade-out handoff via IntersectionObserver, header menu typography normalisation across all top-level entries, and inset product-card meta padding for a visibly tighter card rhythm. Customer chat is rendered by the LuwiPress core plugin's existing widget. For technical API documentation, see individual endpoint documentation at `/wp-json/luwipress/v1/` or request the developer reference. For the WebMCP tool catalog, see the separate WebMCP feature overview. For the LuwiPress Gold theme's full feature list, see the separate **LUWIPRESS-GOLD-FEATURES.md** document (also bundled inside the theme ZIP as `FEATURES.md`).*
