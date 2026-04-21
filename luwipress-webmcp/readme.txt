=== LuwiPress WebMCP ===
Contributors: luwidev
Tags: mcp, ai, automation, claude, anthropic, woocommerce, rest-api
Requires at least: 5.6
Tested up to: 6.9
Requires PHP: 7.4
Stable tag: 1.0.1
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Model Context Protocol (MCP) server for LuwiPress — exposes 130+ tools to AI agents via Streamable HTTP. Requires LuwiPress core.

== Description ==

**LuwiPress WebMCP** turns your LuwiPress install into a Model Context Protocol server. AI agents (Claude Code, OpenAI, custom clients) can call any of the 130+ tools shipped with LuwiPress through a single authenticated HTTP endpoint.

Tools cover content enrichment, SEO, AEO, translation, Elementor, CRM, WooCommerce, taxonomy, menus, media, plugin/theme management, and WordPress core settings — the entire LuwiPress REST surface exposed as MCP tools.

**Requires:** LuwiPress core plugin 3.0.0 or newer. Install and activate LuwiPress first.

= What you get =

* **/wp-json/luwipress/v1/mcp** — MCP endpoint (Streamable HTTP transport, MCP spec draft 2025-03-26)
* **130+ tools** across 26 categories — browse them at **WordPress Admin → LuwiPress → WebMCP**
* **Token-based auth** — reuses the LuwiPress API token; same credential for REST and MCP
* **Origin validation** — DNS rebinding protection for browser-originated clients
* **Session management** — standard MCP session headers, 1-hour TTL

= Example tools =

* `seo_enrich_product` — trigger AI enrichment for one product
* `translation_batch` — translate N posts to multiple languages in one call
* `elementor_read_page`, `elementor_translate_page`, `elementor_sync_structure` — 25 Elementor tools
* `enrich_settings_set`, `translation_settings_set`, `chat_settings_set`, `schedule_settings_set` — remote module configuration
* `cache_purge` — flush LiteSpeed/Rocket/W3TC/Elementor/object caches

== Installation ==

1. Install and activate the core **LuwiPress** plugin first.
2. Upload `luwipress-webmcp.zip` via Plugins → Add New, or unpack to `wp-content/plugins/luwipress-webmcp/`.
3. Activate **LuwiPress WebMCP** in the Plugins screen.
4. Enable the MCP server at **LuwiPress → WebMCP** (if not already enabled).
5. Configure your MCP client with endpoint `https://your-site.com/wp-json/luwipress/v1/mcp` and your LuwiPress API token as a Bearer credential.

== Frequently Asked Questions ==

= Why is this a separate plugin? =

The MCP server adds ~200 KB of tool definitions that most stores don't need. Splitting it out keeps core LuwiPress focused on WooCommerce AI automation while making AI-agent integration an opt-in companion.

= Does it work without the core plugin? =

No. Tools delegate to LuwiPress core classes (AI Engine, Translation, Elementor, etc.). An admin notice appears if core is missing and the MCP endpoint stays inactive.

= How does authentication work? =

Bearer token via `Authorization: Bearer <token>` header or a logged-in WordPress admin session. The token is the same one configured in LuwiPress → Settings → Connection.

== Changelog ==

= 1.0.1 — Post Term Management =
* NEW: `taxonomy_assign_terms` MCP tool — assign/replace/append terms on any post for any taxonomy (post_tag, category, product_tag, product_cat, pa_*). Non-hierarchical terms accept names and auto-create missing ones; hierarchical taxonomies require IDs. Pass `append:true` to add without removing existing terms, or `terms:[]` to clear them all.
* IMPROVED: `content_update_post` now accepts optional `tags` (array of strings) and `categories` (array of IDs). Both replace the existing assignments when provided; omit them to leave term assignments untouched. Fixes the gap where tags/categories could only be set at create time.

= 1.0.0 =
* Initial release — split from LuwiPress core 2.1.0 as part of the 3.0.0 slim-down roadmap.
* Includes all 133 tools previously bundled with core LuwiPress.
* New tools since 2.1.0: `translation_batch`, `cache_purge`, `enrich_settings_get/set`, `translation_settings_get/set`, `chat_settings_get/set`, `schedule_settings_get/set`.
