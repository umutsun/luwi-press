# Luwi Ecosystem — Complete Project Overview

**Mission:** A standalone, AI-powered automation suite for WooCommerce + WordPress stores. Generates content, optimizes SEO, translates products, automates store management — works alongside existing plugins (Rank Math, WPML, Elementor, FluentCRM, LiteSpeed…) instead of replacing them.

**Last updated:** 2026-05-18 · **License:** GPLv2+ · **Repository:** [umutsun/luwi-press](https://github.com/umutsun/luwi-press) (core) + [umutsun/luwi-themes](https://github.com/umutsun/luwi-themes) (themes)

---

## 1. Ecosystem Map

The Luwi family ships as **four plugins + three themes** — install only what your store needs.

| Slug | Type | Version | Role |
|---|---|---|---|
| **luwipress** | Plugin (Core) | **3.1.59** | AI engine, REST API, Knowledge Graph, translation bridge, SEO/AEO writer, content scheduler, customer chat |
| **luwipress-webmcp** | Plugin (Companion) | **1.0.19** | Model Context Protocol server — 178 tools exposing the core to AI agents |
| **luwipress-marketplace-sync** | Plugin (Companion) | **1.0.1** | Publishing to 8 marketplaces (Amazon, eBay, Trendyol, Hepsiburada, N11, Etsy, Walmart, Alibaba) |
| **luwipress-agentic** | Plugin (Companion) | **1.1.0** | Agentic middleware — uniform admin chat, pluggable backend (Open Claw, Hermes, custom) |
| **luwipress-gold** | Theme | **1.7.33** | Editorial / luxury e-commerce theme (music, instruments, artisan goods) |
| **luwipress-emerald** | Theme | **1.0.0** | B2B / consulting / agency / knowledge-work theme |
| **luwipress-ruby** | Theme | **1.0.0** | Bold retail / lifestyle theme |

### Dependency graph

```
WordPress 6.0+
    └── luwipress (core)                          ◄── all other Luwi products require this
            ├── luwipress-webmcp                  ◄── AI agent surface
            ├── luwipress-marketplace-sync        ◄── third-party marketplace publishing
            ├── luwipress-agentic                 ◄── agentic middleware (pluggable agent backend)
            └── theme (gold | emerald | ruby)     ◄── any one — full surface
                    └── 23-tool maintenance suite (slug-resolver, drift sweep, schema audit, ...)

Optional friends (auto-detected, never required):
    WooCommerce · WPML/Polylang · Rank Math/Yoast · Elementor · FluentCRM
    LiteSpeed · WP Mail SMTP · Google for WooCommerce · Meta Pixel · ...
```

**Hard dependencies stay minimal:** WooCommerce + one translation plugin (WPML / Polylang). Everything else is soft — detected at runtime, no installs forced on the operator.

---

## 2. LuwiPress Core (`luwipress` v3.1.59)

**Tagline:** AI engine that does the boring work — enrichment, SEO, translation, schema — and stays out of your stylesheet.

### 2.1 Architecture

```
luwipress/
├── luwipress.php                              ← bootstrap (singleton)
├── includes/                                  ← 35+ feature classes
│   ├── class-luwipress-ai-engine.php          → multi-provider AI dispatch
│   ├── class-luwipress-ai-provider.php        → provider interface
│   ├── class-luwipress-prompts.php            → per-task prompt templates
│   ├── class-luwipress-api.php                → core REST + CRUD
│   ├── class-luwipress-auth.php               → JWT auth
│   ├── class-luwipress-permission.php         → 5-method centralised auth gate
│   ├── class-luwipress-ai-content.php         → product enrichment pipeline
│   ├── class-luwipress-aeo.php                → FAQ / HowTo / Speakable schema
│   ├── class-luwipress-translation.php        → WPML / Polylang bridge + batch
│   ├── class-luwipress-translation-sync.php   → 4-axis cross-language audit
│   ├── class-luwipress-knowledge-graph.php    → store intelligence graph
│   ├── class-luwipress-kg-signals.php         → event ledger
│   ├── class-luwipress-kg-opportunities.php   → ROI-ranked candidate engine
│   ├── class-luwipress-kg-autopilot.php       → cron-driven auto-action loop
│   ├── class-luwipress-elementor.php          → page read/write/translate (46 endpoints)
│   ├── class-luwipress-content-scheduler.php  → AI blog post wizard
│   ├── class-luwipress-customer-chat.php      → storefront chat + RAG
│   ├── class-luwipress-crm-bridge.php         → customer segmentation (read-only)
│   ├── class-luwipress-review-analytics.php   → review sentiment
│   ├── class-luwipress-plugin-detector.php    → soft-dep + theme detector
│   ├── class-luwipress-site-config.php        → environment snapshot
│   ├── class-luwipress-seo-writer.php         → native SEO writer + hreflang
│   ├── class-luwipress-slug-resolver.php      → 6-pass collision rescue
│   ├── class-luwipress-theme-bridge.php       → theme tool registry
│   ├── class-luwipress-email-proxy.php        → wp_mail() proxy
│   ├── class-luwipress-token-tracker.php      → AI cost + budget enforcement
│   ├── class-luwipress-image-handler.php      → DALL-E generation
│   ├── class-luwipress-internal-linker.php    → AI cross-linking
│   ├── class-luwipress-search-index.php       → on-site search builder
│   ├── class-luwipress-acp-attribution.php    → ACP / GA4 / Meta CAPI / Google Ads
│   ├── class-luwipress-abilities.php          → WP Abilities API bridge
│   ├── class-luwipress-logger.php             → DB activity log
│   ├── class-luwipress-hmac.php               → webhook signing
│   ├── class-luwipress-security.php           → REST cache stamp + IP allowlist
│   ├── class-luwipress-job-queue.php          → wp_cron async runner
│   └── providers/                             → OpenAI / Anthropic / Google
├── admin/                                     ← 7 admin pages
│   ├── admin-page.php                         → dashboard
│   ├── settings-page.php                      → multi-tab settings
│   ├── knowledge-graph-page.php               → D3.js interactive graph
│   ├── translation-page.php                   → Translation Manager + Sync Audit
│   ├── usage-page.php                         → token + cost analytics (live)
│   ├── scheduler-page.php                     → content wizard (4-step)
│   └── theme-page.php                         → companion theme tool surface
└── assets/                                    ← admin CSS/JS (design tokens)
```

### 2.2 Feature surface

#### AI Engine
- **Three providers** routable per-workflow: OpenAI (default `gpt-4o-mini`), Anthropic (default `claude-haiku-4-5`), Google (default `gemini-2.0-flash`).
- **Per-task prompt templates** in `LuwiPress_Prompts`.
- **JSON envelope parsing** with markdown-fence extraction.
- **Token tracking** with per-day / per-month budget caps + workflow-level cost breakdown.

#### Content Enrichment
- Per-product enrichment via `/product/enrich` (Job Queue async).
- Batch enrichment with stagger pacing so the budget never bursts.
- **Brand voice card** (site + batch-level) layered on top of depth rules.
- **AI topic brainstorm** with duplicate filter against existing posts.
- **Per-topic pipe overrides:** `Topic | depth=editorial | words=3000`.

#### SEO + AEO
- Reads + writes **Rank Math / Yoast / AIOSEO / SEOPress** meta keys.
- Native fallback writer + automatic hreflang merge for translations.
- **AEO (Answer Engine Optimization):** FAQ, HowTo, Speakable schema generation; auto-detects post language (3.1.55).
- **FAQPage JSON-LD single-emit** (3.1.53 — closed double-emit bug).

#### Translation
- **WPML, Polylang, TranslatePress** all supported through their official APIs.
- **Translation Sync Audit** (3.1.54) — 4-axis cross-language consistency dashboard:
  - **Drift** — translation body slid back toward source language (10-language stop-word ratio).
  - **Outdated** — source edited after translation was last synced.
  - **Structural gap** — Elementor section count mismatch source ↔ translation.
  - **Schema parity** — FAQ / HowTo / Speakable missing on translation siblings.
- **Force re-translate** clears Elementor "already-translated" guard meta.
- **Ghost-Elementor routing fix** (3.1.50) — blog posts with nominal `_elementor_data` but real `post_content` now translate via the standard path.
- Per-translation snapshot ledger for restore.

#### Knowledge Graph
- **D3.js interactive graph** of products / posts / pages / customers.
- **Store Health hero** — weighted single-number score (SEO, enrichment, translation, design, media, plugins) + qualitative subtitle + 7-day trend delta.
- **Action Queue** — ranked ROI-scored next-step cards (snooze / dismiss / in-progress states).
- **Achievement badges** (Bronze 50% / Silver 80% / Gold 95% / Platinum grand-slam).
- **Activity feed** with 30s poll + visibility-aware pause.
- **Search** — `/` focuses, arrows navigate, Enter zooms to node.
- **Presets** — All, Needs SEO meta, Not enriched, Thin content, Translation backlog, High opportunity.
- **Export round-trip** — CSV opportunities / missing SEO with Edit URLs, edit offline, re-upload via `/seo/meta-bulk` (up to 500 rows).
- **Pages view + Customers view + Taxonomy heatmap.**
- **Order Analytics** — 12-month sparkline, top sellers, inventory, payment breakdown.
- **Plugin Health** card.
- **Keyboard shortcuts** — `/` search, `r` refresh, `1/2/3` view switch, `Esc` close, `?` help.
- **Autopilot** (3.1.47) — confidence-gated auto-dispatch with per-workflow daily caps, dry-run first, per-entity 24h idempotency.

#### Slug Collision Resolver (3.1.56–3.1.58)
- Six-pass discovery: exact → WPML cross-language → plural-prefix fuzzy → Levenshtein-1 → menu-parent inheritance → empty-term ancestor fallback.
- **Nested-leaf rescue** for `/blog/<dead-slug>/` retries leaf in map (3.1.58).
- **Pre-swap redirect audit** (3.1.59) — `slug_resolver_redirect_audit` MCP tool sweeps every menu URL, decodes `X-LWP-SR:` traces, returns structured `redirect_loop / 404 / multi_hop_chain` findings. **Mandatory pre-DNS-swap gate** for legacy migrations.

#### Content Scheduler
- **4-step wizard:** Topics → Style → Schedule → Review.
- **Draft-first** workflow (no auto-publish).
- **Two-phase outline approval** for deep / editorial depth.
- **Depth presets:** Standard (800–1500w) / Deep (1500–3000w, citations + FAQ) / Editorial (2000–3500w+, narrative).
- **Recurring plans** — define theme + cadence, calendar fills itself.
- **Bulk approve & publish** with per-row checkboxes.
- **Multilingual duplicate** — one topic becomes N linked articles across WPML/Polylang.
- **Delta polling** — only changed rows update, no full reload.

#### Customer Chat (storefront)
- **Site-content RAG** — answers ground in your products + pages.
- **Clarify / follow-up chips** with KG-grounded selection.
- **Typo detection** via metaphone + Levenshtein dual-gate.
- **AI-translated localization** for chip vocab (auto-warmed per active language).
- **WhatsApp deep-link CTA.**
- **429 storm fix** (3.1.52) — session bootstrap GET no longer consumes per-IP rate budget.
- **Icon-only launcher** (3.1.51) — 56×56 circular, 3.6s pulse, `prefers-reduced-motion` aware.

#### CRM (read-only, no third-party writes)
- **8 segments:** New, Active, Loyal, VIP, At-risk, Dormant, One-time, Lost.
- **Tunable thresholds** — VIP spend, Loyal order count, Active / At-risk / Dormant windows.
- **Manual refresh** endpoint for on-demand reclassification.
- **Suspicious bot audit** (3.1.42) — 6-signal scoring (random email, disposable domain, zero orders…).

#### ACP / Server-side Attribution (3.1.43–3.1.44)
- **Stripe Agentic Commerce Protocol** — AI agent orders skip the browser, so GA4 / Meta Pixel / Google Ads see nothing.
- **Multi-cast dispatch** on every WooCommerce payment hook:
  - GA4 Measurement Protocol.
  - Meta Conversions API.
  - Google Ads Enhanced Conversions (hashed email/phone/name as `userIdentifiers`).
- AI agent name segmentation (ChatGPT vs Perplexity vs ...).
- OAuth refresh token rotation cached 50min (10min headroom).
- Default-OFF + debug mode (logs payloads instead of firing).

### 2.3 REST API (`luwipress/v1`, ~160 endpoints)

Categories: Core · AI Content · AEO · Translation · Translation Sync · Elementor (46 endpoints) · Knowledge Graph · KG Autopilot · KG Signals · KG Opportunities · CRM · Review Analytics · Slug Resolver · Theme Bridge · Site Config · Attribution · Logs · Auth · Cache · Schedule · Token usage.

**REST cache headers are AUTOMATIC** — `rest_post_dispatch` filter stamps `Cache-Control: no-store, private` + LiteSpeed bypass + `DONOTCACHEPAGE` on every `luwipress/v1/*` 200. Public routes go through an allowlist inside `stamp_rest_cache_headers`.

### 2.4 Code conventions (enforced)
- **WordPress is optional ≠ WooCommerce is required.** WC is soft-dep (3.1.38+). Guard `function_exists('wc_get_product')` at every call site.
- **No BOM in PHP files.** `build-zip.php` hard-fails on UTF-8 BOM.
- **No admin_notices in LuwiPress pages.** Suppressed via `in_admin_header p1000` because WP injects them between `<h1>` and first `<div>`, breaking layout.
- **No hardcoded colors in PHP/HTML.** Use CSS classes (`luwipress-card--primary`) or `--lp-*` design tokens.
- **No AI/tool names in UI.** "Claude" / "GPT" never visible to operators — Luwi branding only.
- **REST permission gate** — every route through `LuwiPress_Permission::check_token_or_admin($request)`.

---

## 3. LuwiPress WebMCP (`luwipress-webmcp` v1.0.19)

**Tagline:** Speak to your store in plain language. Any MCP-compatible client (Claude Desktop, Cursor, n8n, custom agents) can drive the entire LuwiPress surface.

### 3.1 What it is

A **JSON-RPC 2.0 MCP server** exposing **178 tools** at `/wp-json/luwipress/v1/mcp`. Tools mirror the REST API but with stricter argument schemas and openWorld/destructive flags so AI agents can reason about safety.

### 3.2 Tool catalog (selection)

| Category | Sample tools |
|---|---|
| Products | `search_products`, `content_update_post`, `content_get_post`, `content_stale` |
| Enrichment | `enrich_product`, `enrich_batch`, `enrich_status` |
| SEO | `seo_write_meta`, `seo_enrich_product`, `taxonomy_meta_get/set/delete` |
| AEO | `aeo_generate_faq`, `aeo_generate_howto`, `aeo_coverage`, `aeo_save_faq` |
| Translation | `translation_request`, `translation_missing`, `translation_outdated`, `translation_quality_check`, `translation_language_drift`, `translation_force_retranslate` |
| Translation Sync | `translation_sync_audit`, `translation_sync_fix`, `translation_sync_settings`, `translation_sync_settings_set` |
| Elementor (60+) | `elementor_page`, `elementor_outline`, `elementor_widget`, `elementor_translate`, `elementor_bulk_update`, `elementor_add_widget`, `elementor_copy_section`, `elementor_snapshot`, `elementor_rollback`, `elementor_kit_get/set`, `elementor_global_css`, `elementor_batch_css`, `elementor_templates_list/create/clone/conditions_get/set/delete` |
| Slug Resolver | `slug_resolver_diag`, `slug_resolver_map`, `slug_resolver_force_rebuild`, `slug_resolver_override_set`, `slug_resolver_settings_set`, `slug_resolver_redirect_audit` |
| Taxonomies | `taxonomy_get_term`, `taxonomy_update_term`, `taxonomy_translate`, `taxonomy_meta_*` |
| KG | `kg_full`, `kg_summary`, `kg_action_queue`, `kg_autopilot_settings/run_now/log`, `kg_events` |
| CRM | `crm_overview`, `crm_segments`, `crm_segment_customers`, `crm_settings`, `crm_suspicious_bots`, `crm_refresh_segments` |
| Theme | `theme_tools_list`, `theme_tool_run`, `theme_tool_restore`, `theme_tool_backups`, `theme_settings_get/set` |
| Attribution | `attribution_settings_get/set`, `attribution_log_recent`, `attribution_test_send` |
| Cache | `cache_purge` (targets: all / page / object / opcache / kit / elementor) |
| Site | `status`, `health`, `site_config`, `plugin_detector` |

### 3.3 Vendor-BUG-004 fix (1.0.19)

`taxonomy_update_term` previously rejected every WPML translation-term update with "slug already in use" because `wp_update_term()` re-runs `wp_unique_term_slug()` which isn't WPML-aware. Two-layer fix:
- **Description-only path** bypasses `wp_update_term` entirely — direct `$wpdb->update` on `wp_term_taxonomy` + `clean_term_cache` + `edited_term` / `edited_{taxonomy}` action emission. Response carries `method: "direct_description_write"`.
- **Full-update path** calls `$sitepress->switch_lang($term_lang)` before `wp_update_term`, restores afterward — slug-uniqueness check now scopes to siblings in the term's own language. Response carries `method: "wp_update_term"`.

### 3.4 Activation gate
Refuses to activate when core LuwiPress is not present. Clear "install core first" message.

---

## 4. LuwiPress Marketplace Sync (`luwipress-marketplace-sync` v1.0.1)

**Tagline:** Publish your WooCommerce products to 8 marketplaces from one dashboard.

### 4.1 Adapters

| Marketplace | Adapter | Status |
|---|---|---|
| Amazon | `class-luwipress-marketplace-amazon.php` | SP-API |
| eBay | `class-luwipress-marketplace-ebay.php` | Trading API |
| Trendyol | `class-luwipress-marketplace-trendyol.php` | Seller API |
| Hepsiburada | `class-luwipress-marketplace-hepsiburada.php` | Seller API |
| N11 | `class-luwipress-marketplace-n11.php` | SOAP |
| Etsy | `class-luwipress-marketplace-etsy.php` | OAuth v3 |
| Walmart | `class-luwipress-marketplace-walmart.php` | Marketplace API |
| Alibaba | `class-luwipress-marketplace-alibaba.php` | OpenAPI |

### 4.2 Admin UI
- Hero stat row: **live channels / configured / untouched / adapters**.
- `lp-pill` Live / Ready / Off status badges.
- Brand-identity dot colors per adapter (Amazon orange, eBay red, Trendyol orange, …).
- All credential storage encrypted via WP options + `wp_salt()`.

### 4.3 Carved out of core 3.1.44 — stores that don't sell on marketplaces stay ~80 KB lighter.

---

## 5. LuwiPress Agentic (`luwipress-agentic` v1.1.0)

**Tagline:** Agentic middleware — one uniform admin chat surface, pluggable agent backend. Pick Open Claw, Hermes, or point at your own endpoint.

### 5.1 Surface
- Admin-only chat panel + sidebar with backend-runtime picker (capability-gated).
- Two HTTP adapters ship by default: **Open Claw** (`https://oc.luwi.dev/agent`) and **Hermes** (`https://hermes.luwi.dev/agent`); both endpoints overrideable per install.
- Operators set per-backend access token + optional custom endpoint in the in-page "Backend Runtime" panel.
- Backend is the only thing that changes — same chat UI, same wire shape (`messages` / `context` / `tools` → `response` / `tool_calls`), same conversation history, same slash commands (`/scan`, `/enrich`, `/translate`, `/aeo`, `/help`, ...).
- Third-party adapters register via `do_action( 'luwipress_agent_register', $host )` — no plugin fork needed.

### 5.2 Activation gate
Same as WebMCP — refuses to activate without core.

---

## 6. Themes

All three themes share a **common backbone**: LuwiPress core bridge, WebMCP-compatible tool registry, Wizard onboarding, 23-tool maintenance suite, ecosystem dashboard, AI surface, featured-products registry, mega-menu admin, footer enhancements, page loader, smart filters, slug-collision shim, WooCommerce fallbacks, and custom Elementor widgets — each re-skinned for a different vertical.

### 6.1 Common backbone (`inc/`)

```
inc/
├── setup.php                              ← theme setup, supports, image sizes
├── enqueue.php                            ← assets pipeline
├── elementor-compat.php                   ← Elementor / Pro compat
├── elementor-kit-sync.php                 ← Kit JSON import on activation
├── luwipress-bridge.php                   ← core ↔ theme contract
├── featured-products.php                  ← term-meta featured-product registry
├── mega-menu-admin.php                    ← admin metabox per menu item
├── mega-menu-customizer.php               ← Customizer panel
├── friendly-plugins.php                   ← soft-dep detection
├── ai-surface.php                         ← AI search + chat hooks
├── wc-pdp-hooks.php                       ← PDP enhancements
├── wc-pdp-gallery-override.php            ← gallery patches
├── archive-parent-enrichment.php          ← hub page enrichment
├── smart-filters.php                      ← archive filter sidebar
├── wc-page-fallback.php                   ← WC page auto-promote
├── blog-page-fallback.php                 ← blog page auto-promote
├── template-redirects.php                 ← slug-resolver compat shim
├── layout-fixes.php                       ← layout polish
├── footer-enhancements.php                ← socials / newsletter / trust / payments
├── page-loader.php                        ← server-side loader (no flash)
├── mobile-card-width-override.php         ← mobile 90vw uniformity
├── customizer/                            ← brand color tokens → CSS vars
├── maintenance/                           ← 23-tool surface
│   ├── class-elementor-shell-tool.php
│   ├── class-maintenance-tools.php
│   ├── class-fix-tools.php
│   ├── class-extra-audit-tools.php
│   ├── class-seo-audit-tools.php
│   ├── class-language-drift-tool.php
│   ├── elementor-template-force.php
│   └── seo-enforcement.php
├── wizard/                                ← onboarding flow
├── widgets/                               ← 28 custom Elementor widgets
└── patterns/                              ← block patterns
```

### 6.2 Maintenance tool suite (23 tools, common to all themes)

| Tool | Purpose |
|---|---|
| `elementor_shell_cleanup` | Strip empty `_elementor_data` shells from non-Elementor pages |
| `slug_conflict_audit` | Find page ↔ archive slug collisions |
| `wpml_translation_drift` | Cross-language structural diff |
| `legacy_canvas_migration` | Migrate Hello Elementor canvas pages |
| `kit_css_health` | Kit CSS size / truncation headroom |
| `orphan_media_scan` | Unreferenced media files |
| `wpml_structure_sync` | Propagate Elementor sections to translations |
| `unwanted_landing_pages` | SEO orphan trash queue |
| `subcategory_template_parity` | Detect missing subcategory templates |
| `triangle_health` | Page ↔ post ↔ product triangulation |
| `wpml_term_repair` | Fix broken WPML term translations |
| `menu_translation_propagate` | Propagate menu changes across languages |
| `product_translation_completeness` | Missing translation surface |
| `wc_template_assignment` | WC page assignment checker |
| `broken_internal_links` | Internal 404 scanner |
| `empty_term_archives` | 410 candidate detection |
| `wpml_string_translation_pending` | String translation backlog |
| `page_speed_signals` | Lighthouse-ish signals |
| `canonical_audit` ⭐ | Canonical URL parity |
| `hreflang_reciprocity_audit` ⭐ | Bidirectional hreflang check |
| `redirect_chain_detector` ⭐ | Multi-hop redirect detection |
| `sitemap_indexation_parity` ⭐ | Sitemap ↔ Google index parity |
| `seo_triangle_health` ⭐ | Overall SEO health composite |

Each tool exposes **scan / execute / restore** primitives; WPML-aware tools auto-expand `trid` siblings; backups persist 20-deep in `luwipress_theme_tool_backups`.

### 6.3 Custom Elementor widgets (28, all token-driven)

`AI Search · Category Grid · Countdown · CTA Banner · Editorial Grid · FAQ · Featured Product · Featured Strip · Hero · Hero Split · Info Bar · Instagram Channel · KG Stats · KG Trending · Master Grid · Master Profile · Mega Menu · Megabar · Newsletter · Process Steps · Product Card · Section Head · Stat Counter · Story Split · Testimonials · Timeline · Trust Badges · YouTube Channel`

All inherit theme brand color via `--primary` CSS variable — recolor the entire surface from Customizer → Brand.

### 6.4 LuwiPress Gold (`luwipress-gold` v1.7.33)

**Vertical:** Editorial luxury / artisan retail (music, instruments, jewelry, handcraft).

**Design language:** Cormorant Garamond (serif) + Inter (sans), gold palette `#735c00` primary, cream backgrounds, atelier-ledger mobile drawer, sticky shrinking header, deep-black footer, paper-grain dot textures.

**Tapadum live state:** new.tapadum.com (DNS swap pending) — 4-language WPML store, ~3,000 products, full ecosystem (core 3.1.59 + WebMCP 1.0.19 + Marketplace Sync + Open Claw + Gold 1.7.33 + Kit CSS V69 overlay 5,287 B).

**Highlights:**
- WC-specific overrides (`woo-overrides.css` 118 KB) for music-store PDPs.
- Mobile 90vw uniform card width across home / archive / blog.
- Mega-menu Customizer panel (threshold / columns / counts / blog auto-inject).
- 9 social icons + newsletter + 3 trust signals + payment-row brand dedup.

### 6.5 LuwiPress Emerald (`luwipress-emerald` v1.0.0) — NEW 2026-05-18

**Vertical:** B2B / consulting / agency / knowledge-work.

**Design language:** Inter (sans) + JetBrains Mono (numerics), **emerald jewel palette** `#047857` primary / `#065F46` hover / `#ECFDF5` soft, 1280px container, 12-step spacing scale (`--sp-1..--sp-32`), three-tier shadows tinted `rgba(4,47,33,*)`, sticky shrinking header, left-slide mobile nav, slide-in WC cart drawer, deep-ink CTA band with animated radial sheen, reveal-on-scroll motion with `prefers-reduced-motion` fallback.

**Built from:** Claude Design **Acme / Emerald** handoff bundle (23 reference HTML pages + 6 chrome partials + Acme-Design-System.html). Source archived at `temp/Luwipress Emerald Elementor Theme/`.

**Port mechanics:** bulk-copied Gold's `inc/` + `assets/css/` + `assets/js/` + `assets/wizard-css/` + `assets/wizard-js/` + `template-parts/` + `woocommerce/` + `elementor-kit/` + home/single/page-about/page-contact templates, sed-renamed namespace tokens across every PHP/CSS/JS/JSON/MD/TXT file:

```
LUWIPRESS_GOLD_*       → LUWIPRESS_EMERALD_*
luwipress_gold_*       → luwipress_emerald_*
lwp_gold_*             → lwp_emerald_*
LWP_GOLD_*             → LWP_EMERALD_*
LuwiPress_Gold_*       → LuwiPress_Emerald_*
LuwiGold               → LuwiEmerald
lwp-gold-*             → lwp-emerald-*       (CSS classes)
--lwp-gold-*           → --lwp-emerald-*     (CSS custom props)
--gold,#XXX            → --primary,#XXX
```

**Dropped intentionally (Gold-specific):**
- `assets/css/widgets.css` (274 KB — Gold `.lwp-*` widget styles, Cormorant Garamond, gold palette — conflicts with Emerald `.emerald-*` design system).
- `assets/css/woo-overrides.css` (118 KB — Tapadum music-store overrides).

Emerald ships only `tokens.css` (38 KB) for the design system layer.

**Customizer Brand tokens** (10): primary / primary_hover / primary_soft / accent / sale / success / ink / bg / bg_alt / black. Any change cascades through every `.emerald-*` component instantly via `:root { --primary: …; … }` written from `inc/customizer/output-css.php`.

**Screenshot:** 1200×900 RGB 559 KB — rendered via headless `chrome.exe --headless=new --window-size=1200,900` from `C:/tmp/emerald-screenshot/template.html`: emerald gradient logo mark, "Emerald" wordmark Inter 88px 600, ecosystem chips emerald-soft (LuwiPress Core / WebMCP / Marketplace Sync / Open Claw), friendly-with chips neutral (Elementor / WooCommerce / WPML / Rank Math / FluentCRM / LiteSpeed), palette swatches at bottom.

**Quality gate:** 107 PHP files all `php -l` clean, 0 BOM bytes. **142 files shipped.** ZIP `releases/luwipress-emerald-1.0.0.zip` (sha-16 `df9c28939e77bfa4`, 946 KB).

### 6.6 LuwiPress Ruby (`luwipress-ruby` v1.0.0) — NEW 2026-05-18

**Vertical:** Bold retail / lifestyle / fashion.

**Design language:** Ruby palette + editorial typography. Built via same port mechanic as Emerald — Gold backbone + sed-rename + ruby design tokens.

ZIP `releases/luwipress-ruby-1.0.0.zip` (1,476,787 bytes).

---

## 7. Plugin & Theme Integrations (auto-detected)

LuwiPress **detects and integrates with** friendly plugins via `LuwiPress_Plugin_Detector` — never replaces, never duplicates.

| Category | Supported plugins | How we integrate |
|---|---|---|
| **SEO** | Rank Math, Yoast, AIOSEO, SEOPress | Read/write their meta keys |
| **Translation** | WPML, Polylang, TranslatePress | Save translations via their API |
| **Email / SMTP** | WP Mail SMTP, FluentSMTP, Post SMTP | Send via `wp_mail()` |
| **CRM** | FluentCRM, Mailchimp for WC | Detect presence, **never write** (avoid duplication) |
| **Cache** | WP Rocket, LiteSpeed, W3 Total Cache | Purge on content update |
| **Page Builder** | Elementor, Elementor Pro, Divi | Read/write/translate via `LuwiPress_Elementor` (46 endpoints) |
| **Analytics** | Google Site Kit, GTM4WP, MonsterInsights | Detect GTM / GA4 presence |
| **Google Ads** | Google for WooCommerce, Conversios | Detect Merchant Center + conversion tracking |
| **Meta Ads** | Meta Pixel, Meta for WooCommerce, PixelYourSite | Detect Pixel + CAPI + catalog |
| **Product Feed** | Google Listings & Ads, Product Feed PRO, CTX Feed | Detect feed sync status |

**Guiding principles:**
1. Hard deps stay minimal — WooCommerce + one translation plugin.
2. **Never write to third-party plugin databases** — read their meta, call their hooks, never push into FluentCRM / Mailchimp / Klaviyo contact lists.
3. **REST-first settings** — every module exposes `GET/POST /<module>/settings` (partial-update) for uniform remote management.

---

## 8. Release & Quality Pipeline

### 8.1 Build

```bash
# Plugins
php build-zip.php <version> <slug>
  # slugs: luwipress | luwipress-webmcp | luwipress-marketplace-sync | luwipress-agentic
  # output: releases/<slug>-v<version>.zip

# Themes
php build-theme-zip.php <version> [<source-slug>] [<output-slug>]
  # source: themes/<source-slug>-elementor/
  # output: releases/<output-slug>-<version>.zip
```

### 8.2 Pre-flight gate

```bash
./tools/release-preflight.sh
# composes: lint + PHPStan + security-audit + quality-check
#         + REST contract (69 invariants × 53 routes)
#         + WebMCP contract (13 invariants × tool catalog)
```

Refuses to build if any new finding lands above the `tools/.security-baseline.json` / `tools/.quality-baseline.json` baseline.

### 8.3 Post-deploy verification

```bash
tools/rest-contract-test.py        # validates live endpoints
temp/e2e-test/verify_*.py          # end-to-end probes
```

Cite the `sha-256[:16]` of the deployed ZIP in commit notes.

### 8.4 Version bump policy (strictly enforced)

**NEVER bump a version unless the user explicitly says so.** Triggers: "bump", "release", "yayınla", "hazır", "versiyon ver", or naming a specific version. **Absence of objection is NOT permission.**

- **Patch (x.x.N):** batch multiple fixes per session — max one bump per session.
- **Minor (x.N.0):** new module / new REST surface / meaningful new capability.
- **Major (N.0.0):** breaking changes (rare).

Companions bump independently from core.

---

## 9. Site Surface (operational)

### 9.1 Active environments

| Site | Profile | Role |
|---|---|---|
| **new.tapadum.com** | Active workspace | New work validated here first — staging copy of production catalog |
| **tapadum.com** | Legacy production | Real customer traffic — promote only after `new.tapadum.com` green |
| **birikimengineering.com** | WC-less test profile | 3.1.38+ soft-dep validation site |
| **osenben.com** | Generic dev (legacy) | Stale; minimal use |

### 9.2 Endpoints

```
new.tapadum.com         /wp-json/luwipress/v1/mcp   (active workspace)
tapadum.com             /wp-json/luwipress/v1/mcp   (legacy prod)
birikimengineering.com  /wp-json/luwipress/v1/mcp   (WC-less)
```

### 9.3 Safety rules

- **Never run destructive ops** (enrich-batch with real options, `/retranslate-all`, `/reorder-sections`, Kit CSS rewrites) against `tapadum.com` (legacy production) without explicit user confirmation.
- **Always snapshot before Elementor structural changes.** `POST /elementor/snapshot {post_id, note}` returns `snapshot_id`. Auto-snapshot endpoints (`reorder-sections`, `sync-structure`, `translate`) return their snapshot ID in the response.
- **Kit CSS uses `append: false` ALWAYS.** The `append: true` path reads stale LiteSpeed Object Cache and silently overwrites newer content. Concat full CSS locally, push as full replace.
- **LiteSpeed minify strips `/* comments */`** — use unique selectors or section IDs as anchors when locating layers later.
- **`:has()` inside `@media`** — Safari + some Chromium builds drop the whole `@media` rule silently. Avoid.
- **Kit CSS DB truncates around ~418 KB.** Headroom check before adding layers.

---

## 10. AI Provider Cost Model

| Provider | Default model | Typical workflow | Approx cost / 1K tokens |
|---|---|---|---|
| OpenAI | `gpt-4o-mini` | Translation, general enrichment | $0.00015 in / $0.0006 out |
| Anthropic | `claude-haiku-4-5` | Enrichment, AEO schema | $0.001 in / $0.005 out |
| Google | `gemini-2.0-flash` | Budget-friendly bulk | $0.000075 in / $0.0003 out |

**Token Tracker** enforces:
- Per-day cap.
- Per-month cap.
- Per-workflow breakdown.
- Live sparkline in Usage & Logs admin page.

---

## 11. Documentation Map

| File | Audience | Purpose |
|---|---|---|
| `LUWI-ECOSYSTEM.md` | Everyone | **This file — master ecosystem overview** |
| `LUWIPRESS-FEATURES.md` | Customers | Per-version changelog + customer-friendly feature explainer |
| `LUWIPRESS-GOLD-FEATURES.md` | Gold theme buyers | Theme-specific feature surface |
| `DESIGN-SYSTEM.md` | Designers | Token reference, component library |
| `CLAUDE.md` | Developers / AI agents | Architecture conventions, repo layout, code rules |
| `README.md` | Developers | Repo entry point |
| Per-theme `FEATURES.md` | Theme buyers | Theme-specific capabilities |
| Per-theme `CLAUDE-DESIGN-PROMPT.md` | AI design agents | Design-bundle handoff specs |

---

## 12. Roadmap Snapshot (as of 2026-05-18)

### Just shipped (2026-05-18)
- **LuwiPress Emerald v1.0.0** — B2B / consulting theme, 142 files, ships as ZIP slug `luwipress-emerald`.
- **LuwiPress Ruby v1.0.0** — bold retail / lifestyle theme.
- **WebMCP 1.0.19** — closes Vendor-BUG-004 (WPML translation-term slug-collision).

### Pending operator action
- **Tapadum DNS swap** — pre-swap audit clean (216/216 URLs HTTP 200, 0 loops, 0 404s). Swap-day checklist documented.
- **Emerald deploy** — upload ZIP via Appearance → Themes → Upload, activate, run wizard.

### Next-session candidates
1. **Emerald Elementor kit JSONs** derived from the 23 reference HTML pages (homepage hero / solutions / journal / team / account / cart / checkout).
2. **WC template re-skin** with `.emerald-*` classes (currently inherits Gold's `.lwp-*`).
3. **Emerald screenshot v2** with actual rendered homepage.
4. **FAQ sibling sweep** — 89 EN-FAQ products each have 0–3 missing FR/IT/ES sibling FAQs (~267 total).
5. **Tiered ecosystem packaging** — License + capability matrix for Theme / Starter / Pro / Studio / Marketplace / Enterprise tiers.

### Paused tracks
- `themes/luwipress-gold/` — DEAD branch v1.0.0, superseded by `themes/luwipress-gold-elementor/`.
- `themes/luwi-elementor`, `luwi-emerald`, `luwi-gold`, `luwi-ruby`, `stitch-3d-minimalist` — parked legacy stubs.

---

## 13. Quick Reference

```bash
# Build a plugin ZIP
php build-zip.php 3.1.60 luwipress

# Build a theme ZIP
php build-theme-zip.php 1.7.34                                                    # Gold (default)
php build-theme-zip.php 1.0.1 luwipress-emerald-elementor luwipress-emerald       # Emerald
php build-theme-zip.php 1.0.1 luwipress-ruby-elementor luwipress-ruby             # Ruby

# Pre-flight before any release
./tools/release-preflight.sh

# Post-deploy verification
python tools/rest-contract-test.py
python tools/webmcp-contract-test.py

# Site-specific MCP endpoints
curl https://new.tapadum.com/wp-json/luwipress/v1/status \
  -H "Authorization: Bearer lp_..."

# Kit CSS golden baseline restore
python temp/tapadum/kit-golden/restore-golden-baseline.py [YYYY-MM-DD]
```

---

*Master ecosystem overview · v1.0 · 2026-05-18 · Maintained alongside `LUWIPRESS-FEATURES.md`*
