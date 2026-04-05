# n8nPress — AI-Powered WooCommerce Automation

<p align="center">
  <img src="https://luwi.dev/images/luwi-logo.png" alt="Luwi Developments" width="120">
</p>

<p align="center">
  <strong>Dream. Design. Develop.</strong><br>
  by <a href="https://luwi.dev">Luwi Developments LLC</a>
</p>

---

**n8nPress** is a WordPress middleware plugin that connects WooCommerce to [n8n](https://n8n.io) workflows, adding AI-powered automation your existing plugins can't do alone.

## What It Does

| Your Plugin Does | n8nPress Adds (via AI) |
|---|---|
| Rank Math stores SEO meta | AI generates optimized titles, descriptions, FAQ schema |
| WPML manages languages | AI translates with SEO keyword awareness |
| WooCommerce collects reviews | AI drafts professional review responses |
| WordPress schedules posts | AI generates fresh blog content + images |

## Features

- **AI Product Enrichment** — Rich descriptions, meta, FAQ schema, alt text
- **SEO-Aware Translation** — Via WPML/Polylang with keyword awareness
- **Open Claw AI Assistant** — Chat interface with `/slash` commands
- **Content Scheduling** — AI blog posts + DALL-E images
- **Cost Protection** — Daily budget limits, token tracking, model selection
- **Plugin Auto-Detection** — Rank Math, WPML, WP Mail SMTP, FluentCRM, Chatwoot

## Quick Start

1. Install n8nPress on your WordPress site
2. Set up a self-hosted [n8n](https://n8n.io) instance
3. Import workflow templates from `n8n-workflows/`
4. Enter your AI API key in Settings → AI API Keys
5. Set daily budget limit (default: $1.00/day)

## Requirements

- WordPress 5.6+, PHP 7.4+
- WooCommerce 5.0+ (recommended)
- Self-hosted n8n instance
- AI API key (OpenAI, Anthropic, or Google)

## Cost Control

GPT-4o Mini is the default model — 20x cheaper than Claude Sonnet:

| Model | Cost per 1M tokens (in/out) |
|---|---|
| GPT-4o Mini | $0.15 / $0.60 |
| Claude Sonnet 4 | $3.00 / $15.00 |
| Gemini Flash | $0.10 / $0.40 |

Daily budget limit auto-pauses AI when reached. Local commands always work with zero cost.

## License

GPLv2 or later — [LICENSE](n8npress-bridge/LICENSE)

## Contact

- **Email:** hello@luwi.dev
- **Web:** [luwi.dev](https://luwi.dev)
