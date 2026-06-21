# LuwiPress — AI-Powered WooCommerce Automation

<p align="center">
  <img src="https://luwi.dev/images/luwi-logo.png" alt="Luwi Developments" width="120">
</p>

<p align="center">
  <strong>Dream. Design. Develop.</strong><br>
  by <a href="https://luwi.dev">Luwi Developments LLC</a>
</p>

---

**LuwiPress** is a standalone AI plugin for WooCommerce stores. It generates content,
optimizes SEO, translates products, and automates store management — **with no external
workflow engine required**. The AI engine, job queue, and prompt templates all run
natively inside WordPress; you only need an AI API key.

It works *with* the plugins you already run (Rank Math, WPML, WP Mail SMTP, Elementor, …)
by detecting their settings and layering AI intelligence on top — never writing into
third-party databases.

## What It Does

| Your Plugin Does | LuwiPress Adds (via AI) |
|---|---|
| Rank Math / Yoast store SEO meta | AI-optimized titles, descriptions, FAQ/HowTo schema |
| WPML / Polylang manage languages | SEO-keyword-aware translation through their API |
| WooCommerce collects reviews | AI-drafted professional review responses |
| Elementor builds pages | Read / write / translate pages via REST |
| WordPress schedules posts | AI-generated blog content + images |

## Key Features

- **AI Product Enrichment** — rich descriptions, meta, FAQ schema, alt text
- **SEO-Aware Translation** — via WPML / Polylang with keyword awareness
- **Knowledge Graph** — D3.js store-intelligence visualization + action queue
- **Content Scheduler** — AI blog posts + image generation, draft-first workflow
- **Customer Chat** — storefront AI assistant
- **WebMCP Server** — 130+ Model Context Protocol tools for any MCP client
- **Cost Protection** — daily budget limits, token tracking, per-workflow model selection
- **Plugin Auto-Detection** — SEO / Translation / SMTP / CRM / Cache / Page-builder / Analytics

## The Ecosystem

| Package | Role |
|---|---|
| `luwipress/` | Core plugin — the standalone AI engine (ships as `luwipress-core`) |
| `luwipress-webmcp/` | MCP server companion (130+ tools) |
| `luwipress-marketplace/` | Marketplace publishing companion |
| `luwipress-agentic/` | Agentic middleware (UCP + AP2 commerce) |
| `themes/luwipress-*-elementor/` | Jewel theme family (Gold, Emerald, Amber, Ruby, Onyx, Sapphire) |

## Requirements

- WordPress 5.6+, PHP 7.4+
- WooCommerce 5.0+ (soft dependency — core runs without it)
- AI API key (OpenAI, Anthropic, or Google)
- Optional: one translation plugin (WPML or Polylang) for multilingual features

## Quick Start

1. Install **LuwiPress Core** on your WordPress site
2. Enter your AI API key in **Settings → AI API Keys**
3. Set a daily budget limit (default keeps spend in check)
4. (Optional) Install the WebMCP companion to drive the store from any MCP client
5. Enrich a product, translate a page, or open the Knowledge Graph

## Cost Control

GPT-4o Mini is the default model — far cheaper than premium models:

| Model | Cost per 1M tokens (in / out) |
|---|---|
| GPT-4o Mini | $0.15 / $0.60 |
| Claude Haiku 4.5 | low-cost enrichment default |
| Gemini Flash | $0.10 / $0.40 |

A daily budget limit auto-pauses AI when reached. Local (non-AI) operations always run at zero cost.

## Documentation

Long-form docs live under [`docs/`](docs/):

| Document | Audience |
|---|---|
| [LUWIPRESS-FEATURES.md](docs/LUWIPRESS-FEATURES.md) | Customer-facing feature reference + changelog |
| [LUWIPRESS-ECOSYSTEM.md](docs/LUWIPRESS-ECOSYSTEM.md) | Master ecosystem overview |
| [LUWIPRESS-GOLD-FEATURES.md](docs/LUWIPRESS-GOLD-FEATURES.md) | Gold theme feature surface |
| [LUWIPRESS-CRM-SEGMENTATION.md](docs/LUWIPRESS-CRM-SEGMENTATION.md) | CRM segmentation rules |
| [DESIGN-SYSTEM.md](docs/DESIGN-SYSTEM.md) | Design tokens + component library |
| [WEBMCP-INTEGRATION.md](docs/WEBMCP-INTEGRATION.md) | MCP server integration guide |
| [`docs/history/`](docs/history/) | Archived session / decision history |

> `CLAUDE.md` (repo root) holds the engineering playbook for contributors and AI agents.

## License

GPLv2 or later — [LICENSE](luwipress/LICENSE)

## Contact

- **Email:** hello@luwi.dev
- **Web:** [luwi.dev](https://luwi.dev)
