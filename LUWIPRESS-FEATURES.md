# LuwiPress — Complete Feature Overview

**Version:** 2.0.9 · **License:** GPLv2+ · **Target:** WooCommerce stores

LuwiPress is a standalone, AI-powered automation plugin for WordPress/WooCommerce. It generates content, optimizes SEO, translates products, and automates store management — integrating seamlessly with existing plugins (Rank Math, WPML, Elementor, etc.) without replacing them.

---

## 🎯 Core Modules

### 1. AI Content Enrichment
- Generates product descriptions, titles, SEO meta with brand/keyword awareness
- Batch enrichment with background job queue + progress tracking
- AI alt text for product images (SEO + accessibility)
- Schema markup auto-generation (Product, Article, BreadcrumbList)
- Multi-provider: **OpenAI** (GPT-4o Mini), **Anthropic** (Claude Haiku 4.5), **Google** (Gemini 2.0 Flash)

### 2. SEO & Answer Engine Optimization (AEO)
- Auto FAQ schema, HowTo schema, Speakable markup
- Coverage reports (meta titles, descriptions, focus keywords)
- Works with: **Rank Math**, **Yoast**, **All in One SEO**, **SEOPress**
- Detects thin content, missing alt text, stale posts
- Answer-engine ready markup for Google AI Overviews & voice assistants

### 3. Multilingual Translation
- **WPML** + **Polylang** native integration (writes through their API)
- Translates: product descriptions, meta fields, taxonomy, Elementor pages
- SEO keyword awareness — keeps target terms intact per language
- Chunked AI translation for long content (avoids timeouts)
- Background cron processing
- Coverage tracking per language + content type

### 4. Elementor Mastery (30+ tools)
- Read full page structure via REST API
- Edit widget text, styles, responsive overrides
- **Section reorder** — reorganize layouts programmatically
- **Find & replace** — text or styles across multiple pages
- **Structure sync** — propagate layout changes to WPML translations (preserves translated texts)
- **Global design tokens** — read/write Elementor Kit colors & typography
- **Template library** — list + apply saved templates
- Snapshot/rollback safety net (max 10 per post)
- Kit CSS management (global custom CSS)
- Batch CSS to multiple posts by ID or post type
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

### 6. Open Claw — Admin AI Assistant
Chat-style command interface inside WP admin.
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
- Detects FluentCRM, Mailchimp for WC integrations
- Segments: **VIP** (top spenders), **At-Risk** (no recent orders), **Dormant**
- Per-customer profile with order history + lifetime value
- Lifecycle email workflow support

### 11. Marketplace Integration (v2.0.8+)
Multi-marketplace publishing:
- **Amazon**, **eBay**, **Trendyol**, **Hepsiburada**, **N11**
- **Alibaba**, **Etsy**, **Walmart**
- Product field mapping per marketplace schema
- Sync status tracking DB table
- Batch publishing

### 12. Theme Library
3 production-ready themes (v1.0.0+):
- **Luwi Gold** — Burnished brass, editorial serif, museum gallery aesthetic
- **Luwi Emerald** — Deep forest greens, organic shapes, eco-conscious
- **Luwi Ruby** — Deep ruby reds, champagne gold, art-deco luxury

All themes include: Elementor, WooCommerce, WPML/RTL, dark mode, custom palettes, typography pairs, demo content JSON, one-click setup.

### 13. Demo Import
- One-click demo content import per theme
- Pre-built pages, products, menus, theme settings
- Safe import (no production data overwrite)

### 14. WebMCP Server (AI Agent Integration)
**115+ MCP tools** for AI agents (Claude Code, OpenAI, etc.):
- Streamable HTTP transport (MCP spec draft 2025-03-26)
- Origin validation (DNS rebinding protection)
- Tools for: content, SEO, translation, Elementor, CRM, media, settings
- Resource templates for parameterized queries
- Token-based authentication

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
**Products:** `/product/enrich`, `/product/enrich-batch`, `/product/enrich-batch/status`
**SEO:** `/seo/meta`, `/seo/schema`
**AEO:** `/aeo/generate-faq`, `/aeo/generate-howto`, `/aeo/coverage`, `/aeo/save-*`
**Translation:** `/translation/missing`, `/translation/request`, `/translation/quality-check`
**Content:** `/content/stale`, `/content/opportunities`, `/content/resolve-links`
**Chat:** `/chat/message`, `/chat/session/{id}`, `/chat/config`, `/chat/session/escalate`
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
| **CRM** | FluentCRM · Mailchimp for WC |
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
2. **Settings** — 11 tabs (AI providers, API keys, budgets, chat tone, webhooks, IP whitelist, etc.)
3. **Open Claw** — AI chat assistant with slash commands
4. **Usage & Logs** — Token tracking, cost breakdown, API call history
5. **Knowledge Graph** — D3.js interactive store intelligence visualization
6. **WebMCP** — MCP tool catalog, connection tester
7. **Theme Manager** — Install, activate, setup Luwi themes
8. **Content Scheduler** — Blog automation
9. **Translation Manager** — Missing translations by language

---

## ⚡ Technical Specs

| Spec | Detail |
|------|--------|
| **WordPress** | 5.6+ (tested up to 6.9) |
| **PHP** | 7.4+ |
| **WooCommerce** | 5.0+ (optional) |
| **Bundle Size** | ~416 KB (68 files) |
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

*Document version 2.0.9 · For technical API documentation, see individual endpoint documentation at `/wp-json/luwipress/v1/` or request the developer reference.*
