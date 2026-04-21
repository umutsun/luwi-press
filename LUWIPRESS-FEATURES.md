# LuwiPress — Complete Feature Overview

**Version:** 3.1.3 · **License:** GPLv2+ · **Target:** WooCommerce stores

LuwiPress is a standalone, AI-powered automation plugin for WordPress/WooCommerce. It generates content, optimizes SEO, translates products, and automates store management — integrating seamlessly with existing plugins (Rank Math, WPML, Elementor, etc.) without replacing them.

Shipped as a lean **365 KB core** plus two optional companion plugins — install only what your store needs.

## 🆕 What's new in 3.x

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
Single REST endpoint exposing 20+ store intelligence sections:
- Products, categories, taxonomy
- SEO coverage, AEO opportunities
- Translation status per language
- CRM segments (VIP, at-risk, dormant)
- Media inventory, menu structure, authors
- Order analytics, content types, plugins detected
- **Opportunity scoring** — weighted algorithm flags thin content, missing translations, missing schema
- Cached with auto-invalidation on content changes
- Powers all AI workflows with prioritized decisions

### 8. Content Scheduler & Blog Automation
- AI-generated blog posts with scheduled publishing
- Image generation: **DALL-E 3**, **DALL-E 2**, **Gemini Imagen 3**
- Featured image auto-assignment
- Custom post type support
- Internal linking suggestions

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

*Document version 3.1.3 — updated 2026-04-21 · For technical API documentation, see individual endpoint documentation at `/wp-json/luwipress/v1/` or request the developer reference. For the WebMCP tool catalog, see the separate WebMCP feature overview.*
