=== LuwiPress Open Claw ===
Contributors: luwidev
Tags: ai, woocommerce, chat, automation, admin, assistant
Requires at least: 5.6
Tested up to: 6.9
Requires PHP: 7.4
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Admin-side AI chat for LuwiPress. Manage WooCommerce with natural language or slash commands. Requires LuwiPress core.

== Description ==

**Open Claw** is the in-admin AI assistant that sits on top of LuwiPress. Type a natural-language command or use a slash command — simple queries resolve locally (no AI cost), complex ones route through the LuwiPress AI Engine with the provider you configured in the core plugin.

**Requires:** LuwiPress core plugin 3.1.0 or newer.

= What Open Claw does =

* `/scan` — Content health (thin, missing SEO, missing translations, stale)
* `/enrich` — Queue batch enrichment for thin products
* `/translate` — List missing translations per language
* `/generate <topic>` — Draft a new blog post
* `/aeo`, `/reviews`, `/crm`, `/revenue`, `/products`, `/plugins`, `/help`

Or just type — "how many at-risk customers do I have?", "generate a blog post about tuning a darbuka", etc.

= Design =

* No Telegram/WhatsApp bridge. Open Claw runs in the WordPress admin only. Front-end customer chat is handled by the separate Customer Chat widget in LuwiPress core.
* Uses the LuwiPress AI Engine token and daily budget limits — no separate configuration.
* Conversation history stored per-user in options (50 messages rolling window).

== Installation ==

1. Install and activate the core **LuwiPress** plugin first.
2. Upload `luwipress-open-claw.zip` via Plugins → Add New, or unpack to `wp-content/plugins/luwipress-open-claw/`.
3. Activate **LuwiPress Open Claw**.
4. Open it at **WordPress Admin → LuwiPress → Open Claw**.

== Changelog ==

= 1.0.0 =
* Initial release — split from LuwiPress core 3.0.0 as part of the slim-down roadmap. Functionality unchanged.
