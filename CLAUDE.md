# n8npress — AI-Powered Middleware for Multilingual WooCommerce

## Mission

Increase organic traffic for multilingual WooCommerce stores through AI-powered
content enrichment, SEO optimization, and translation automation.

**n8npress is middleware** — it befriends popular WordPress plugins (Rank Math, WPML,
WP Mail SMTP, etc.), reads their settings, and adds AI automation they can't do alone.
It never duplicates functionality that existing plugins already provide.

## Architecture

```
WordPress/WooCommerce (osenben.com)
├── Rank Math / Yoast        (SEO — we READ/WRITE their meta)
├── WPML / Polylang          (Translation — we SAVE through their API)
├── WP Mail SMTP / FluentSMTP (Email — we SEND via wp_mail())
└── n8npress-bridge          (our plugin — middleware to n8n)
      ├── /site-config       → exposes all WP settings to n8n
      ├── /send-email        → wp_mail() proxy for workflows
      ├── /content/opportunities → what AI can fix (thin, stale, untranslated)
      └── /product/enrich    → AI enrichment callback
            ↕ REST API
      n8n.luwi.dev (workflows)
      ├── AI Product Enricher     (Claude AI — descriptions, meta, FAQ, schema)
      ├── AEO Generator           (FAQ/HowTo/Speakable structured data)
      ├── Content Scheduler        (AI blog posts + DALL-E images)
      ├── Translation Pipeline     (SEO-aware, saves to WPML/Polylang)
      └── AI Review Responder      (auto-reply to product reviews)
```

## Plugin Structure

```
n8npress/
├── n8npress-bridge/              <- Main plugin
│   ├── includes/
│   │   ├── class-n8npress-plugin-detector.php  <- Detects WPML/Yoast/SMTP/etc
│   │   ├── class-n8npress-site-config.php      <- GET /site-config endpoint
│   │   ├── class-n8npress-email-proxy.php      <- POST /send-email endpoint
│   │   ├── class-n8npress-api.php              <- Core REST API + CRUD
│   │   ├── class-n8npress-auth.php             <- JWT authentication
│   │   ├── class-n8npress-ai-content.php       <- AI enrichment pipeline
│   │   ├── class-n8npress-aeo.php              <- Answer Engine Optimization
│   │   ├── class-n8npress-translation.php      <- Translation bridge
│   │   ├── class-n8npress-content-scheduler.php
│   │   └── ... (logger, settings, security, hmac, workflow-tracker)
│   └── admin/                    <- WordPress admin pages
├── n8npress-seo-off/             <- SEO core (webhook queue, logging)
├── n8npress-seo-woo/             <- WooCommerce event hooks
└── n8n-workflows/                <- 5 workflow JSON templates
```

## Supported Plugin Integrations

n8npress **detects and integrates with** these plugins via `N8nPress_Plugin_Detector`:

| Category | Supported Plugins | How we integrate |
|----------|-------------------|------------------|
| SEO | Rank Math, Yoast, AIOSEO, SEOPress | Read/write their meta keys |
| Translation | WPML, Polylang, TranslatePress | Save translations via their API |
| Email/SMTP | WP Mail SMTP, FluentSMTP, Post SMTP | Send via `wp_mail()` — their config applies |
| CRM | FluentCRM, Mailchimp for WC | Detect presence, avoid duplication |
| WooCommerce | Native settings | Read currency, tax, stock thresholds, shipping |

## REST API Endpoints

### Core
- `POST /n8npress/v1/webhook` — Main webhook handler (JWT required)
- `GET  /n8npress/v1/status` — Plugin status
- `GET  /n8npress/v1/health` — Health check

### Bridge Services
- `GET  /n8npress/v1/site-config` — Full WP + WC + plugin environment snapshot
- `POST /n8npress/v1/send-email` — Send email via wp_mail() (supports WC template)
- `GET  /n8npress/v1/content/opportunities` — Content gaps AI can fill

### Content & SEO
- `POST /n8npress/v1/product/enrich` — Trigger AI enrichment
- `POST /n8npress/v1/product/enrich-callback` — Receive enriched data
- `POST /n8npress/v1/aeo/save-faq` — Save FAQ schema
- `POST /n8npress/v1/aeo/save-howto` — Save HowTo schema
- `GET  /n8npress/v1/aeo/coverage` — Schema coverage report
- `GET  /n8npress/v1/content/stale` — Stale content list
- `GET  /n8npress/v1/translation/missing` — Untranslated content
- `POST /n8npress/v1/translation/callback` — Save translated content

### Authentication
- `POST /jwt-auth/v1/token` — Generate JWT
- `POST /jwt-auth/v1/token/validate` — Validate JWT
- `POST /jwt-auth/v1/token/refresh` — Refresh JWT

## What n8nPress Adds (vs Existing Plugins)

| Existing Plugin Does | n8nPress Adds (via n8n + AI) |
|----------------------|------------------------------|
| Rank Math stores SEO meta | AI generates optimized titles, descriptions, FAQ |
| WPML manages language structure | AI translates with SEO intent (keyword-aware) |
| WooCommerce collects reviews | AI drafts professional review responses |
| WordPress schedules posts | AI generates fresh blog content + images |
| SEO plugins add basic schema | AI generates FAQ/HowTo/Speakable schema |
| SMTP plugins handle delivery | n8n workflows send email through existing SMTP |

## n8n Workflows (5)

| Workflow | Trigger | Purpose |
|----------|---------|---------|
| `workflow-product-enricher.json` | Webhook | AI product description/meta/schema generation |
| `workflow-aeo-generator.json` | Daily 10:00 | FAQ/HowTo/Speakable schema for products |
| `workflow-content-scheduler.json` | Webhook | AI blog post + DALL-E image generation |
| `workflow-translation-pipeline.json` | Webhook/Daily | SEO-aware multi-language translation |
| `workflow-ai-review-responder.json` | Every 6h | Auto-respond to WooCommerce reviews |

All workflows call `GET /site-config` first to read WP settings, and use
`POST /send-email` instead of their own SMTP.

## Environment
- Test/dev site: `osenben.com`
- n8n instance: `n8n.luwi.dev`
- Deploy plugins to `wp-content/plugins/` on target WordPress site

## Current Client: Tapadum
Music instrument stores:
- salamuzik.com, ethnicmussical.com, sultaninstrument.com, sensdelorient.com

## Code Conventions
- PHP: WordPress coding standards, singleton pattern for main classes
- Prefix: `n8npress_` for options, `N8nPress_` for classes
- All code in **English only** (no Turkish in variables, comments, node names)
- Sanitize all input: `sanitize_text_field()`, `wp_kses_post()`, `intval()`
- Use `wp_remote_post()` for outbound HTTP, never `curl` directly
- Email: Always through `wp_mail()` / email proxy endpoint, never direct SMTP
- SEO meta: Always through Plugin Detector (reads/writes correct plugin's meta keys)
- Translations: Always through Plugin Detector (saves via WPML/Polylang API)
- Log via `N8nPress_Logger::log($message, $level, $context)`
- n8n workflows: JSON format, Europe/Istanbul timezone

## Skills Available

### n8n Skills (`.claude/skills/n8n-*`)
- **n8n-mcp-tools-expert** — Tool selection, node discovery, workflow management
- **n8n-expression-syntax** — `{{}}` patterns, `$json`, `$node`, `$env` variables
- **n8n-workflow-patterns** — 5 core patterns: webhook, HTTP API, database, AI agent, scheduled
- **n8n-validation-expert** — Error interpretation, false positives, auto-fix
- **n8n-node-configuration** — Operation-aware setup for 525+ nodes
- **n8n-code-javascript** — Code node JS patterns
- **n8n-code-python** — Code node Python patterns

### WordPress Skills (`.claude/skills/wp-*`)
- **wp-performance-review** — 50+ anti-pattern detection, 8 categories

### Slash Commands (`.claude/commands/`)
- `/wp-perf-review` — Full WordPress performance analysis with fixes
- `/wp-perf` — Quick critical patterns scan
