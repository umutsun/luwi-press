=== n8nPress — AI-Powered WooCommerce Automation ===
Contributors: luwidev
Tags: woocommerce, ai, seo, translation, automation, n8n, product enrichment
Requires at least: 5.6
Tested up to: 6.9
Requires PHP: 7.4
Stable tag: 1.8.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

AI-powered content enrichment, SEO optimization, and translation automation for WooCommerce stores via n8n workflows.

== Description ==

n8nPress is a **middleware plugin** that connects your WordPress/WooCommerce store to [n8n](https://n8n.io) workflows, adding AI-powered automation that your existing plugins can't do alone.

**It doesn't replace your plugins — it makes them smarter.**

= What n8nPress Adds =

| Your Plugin Does | n8nPress Adds (via AI) |
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
* **Plugin Auto-Detection** — Automatically detects Rank Math, WPML, Polylang, WP Mail SMTP, FluentCRM, Chatwoot
* **Cost Protection** — Daily budget limits, token tracking, per-workflow cost breakdown
* **Multi-Provider AI** — OpenAI (GPT-4o Mini), Anthropic (Claude), Google (Gemini)

= How It Works =

1. Install n8nPress on your WordPress site
2. Connect to your self-hosted [n8n](https://n8n.io) instance
3. Enter your AI API key (OpenAI recommended for best cost/quality ratio)
4. Set a daily budget limit to control costs
5. Use Open Claw AI assistant or dashboard buttons to trigger automations

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

n8nPress includes built-in cost protection:

* **Daily budget limit** — AI features auto-pause when reached ($1.00 default)
* **Token usage tracking** — See exactly which workflows cost money
* **Model selection** — GPT-4o Mini is 20x cheaper than Claude Sonnet
* **Local commands** — /scan, /seo, /translate work without any AI cost
* **No hidden charges** — You bring your own API key, you control spending

= Requirements =

* WordPress 5.6+
* WooCommerce 5.0+ (recommended)
* Self-hosted [n8n](https://n8n.io) instance (free, open-source)
* AI API key (OpenAI, Anthropic, or Google)

= Supported Plugin Integrations =

* **SEO:** Rank Math, Yoast, AIOSEO, SEOPress
* **Translation:** WPML, Polylang, TranslatePress
* **Email:** WP Mail SMTP, FluentSMTP, Post SMTP
* **CRM:** FluentCRM, Mailchimp for WooCommerce
* **Support:** Chatwoot

== Installation ==

1. Upload the `n8npress-bridge` folder to `/wp-content/plugins/`
2. Activate the plugin through the 'Plugins' menu
3. Go to **n8nPress → Settings → Connection** and enter your n8n webhook URL
4. Go to **Settings → AI API Keys** and enter your OpenAI API key
5. Set your daily budget limit (recommended: $1.00/day)
6. Import the n8n workflow templates from the `n8n-workflows/` folder into your n8n instance

== Frequently Asked Questions ==

= Do I need an n8n instance? =

Yes. n8nPress connects to [n8n](https://n8n.io), a free, open-source workflow automation tool. You can self-host it on any VPS for ~$5/month, or use n8n Cloud.

= Which AI provider should I use? =

We recommend **OpenAI GPT-4o Mini** for the best cost/quality ratio. At $0.15 per million input tokens, you can run thousands of operations for under $1/day.

= Will this increase my hosting costs? =

No. n8nPress is lightweight middleware — it doesn't add significant load to your WordPress site. All AI processing happens on your n8n instance.

= Is my data sent to third parties? =

Product data is sent to your n8n instance (which you control) and then to the AI provider you choose (OpenAI, Anthropic, or Google). No data is sent to n8nPress servers.

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

n8nPress is developed by **Luwi Developments LLC** — a boutique AI agency working with founders, startups, and creative teams. We specialize in custom AI workflows, process automation, and intelligent systems.

*Dream. Design. Develop.*

= Free Plugin, Professional Support =

n8nPress is free and open-source. You bring your own n8n instance and AI API key — no subscription, no hidden fees, no vendor lock-in.

Need help getting started? We offer:

* **Free:** Plugin + workflow templates + documentation
* **Setup Service:** We configure n8n, import workflows, and connect everything for you
* **Custom Workflows:** Tailored automation for your specific business needs
* **Ongoing Support:** Monitoring, optimization, and new workflow development

= Resources =

* [Documentation & Guides](https://luwi.dev)
* [GitHub Repository](https://github.com/umutsun/n8npress)
* [n8n Workflow Templates](https://github.com/umutsun/n8npress/tree/main/n8n-workflows)

= Contact =

* **Email:** hello@luwi.dev
* **Web:** [luwi.dev](https://luwi.dev)
* **Services:** Custom AI workflows, WooCommerce automation, n8n consulting
