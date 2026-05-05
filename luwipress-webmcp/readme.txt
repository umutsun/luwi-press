=== LuwiPress WebMCP ===
Contributors: luwidev
Tags: mcp, ai, automation, claude, anthropic, woocommerce, rest-api
Requires at least: 5.6
Tested up to: 6.9
Requires PHP: 7.4
Stable tag: 1.0.11
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Model Context Protocol (MCP) server for LuwiPress — exposes 140+ tools to AI agents via Streamable HTTP. Requires LuwiPress core. Pairs with LuwiPress Gold theme.

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

= 1.0.5 — JSON-RPC 2.0 strict mode + clean UTF-8 in product search =
* FIX: **`search_products` no longer mangles non-ASCII text.** Spanish, French, Italian and Turkish characters in product titles, descriptions and category names used to come through as `coraz�n` / `m�sica` / `�frica` because the tool wasn't decoding HTML entities or normalising the encoding before handing the payload to the MCP transport. Output is now run through `html_entity_decode` with UTF-8, so LLMs receive clean readable text.
* FIX: **Prices in `search_products` results are now properly formatted** (e.g. `489,14 €` instead of `489.14&euro;`). Previously the tool concatenated the raw `_price` postmeta with the currency symbol; now it routes through `wc_price()` so locale-correct thousands and decimal separators are applied and the entity reference is decoded to its real character.
* FIX: **Strict JSON-RPC 2.0 conformance.** Requests that omit the `jsonrpc` field, or set it to anything other than `"2.0"`, are now rejected with error code `-32600 Invalid Request` instead of being silently processed. Brings the server into line with the JSON-RPC 2.0 / MCP spec and helps misconfigured clients fail loudly during integration.

= 1.0.4 — Tool audit + Kit CSS wrappers =
* FIX: `tools/list` page size raised from 20 to 200 so MCP clients that don't auto-follow `nextCursor` (such as the Claude.ai connector) see the full tool catalog on the first page. Before this change such clients only saw the first 20 tools in registration order.
* NEW: `elementor_sync_structure` — propagate structural layout changes from a source page to its WPML/Polylang translation copies; preserves translated text by default.
* NEW: `elementor_kit_info` — read Kit ID, breakpoints, and custom-CSS presence.
* NEW: `elementor_kit_css_get` — read the current Kit (global) CSS layer.
* NEW: `elementor_kit_css_set` — write the full Kit CSS payload. Forces `append:false` at the wrapper level to prevent stale-cache append regressions.
* NEW: `translation_fix_elementor` — repair WPML/Polylang translated posts whose Elementor data was dropped or mis-copied.
* REMOVED: `claw_execute`, `claw_channels` — Open Claw is parked; tools were dead code.
* REMOVED: `chatwoot_customer_lookup`, `chatwoot_send_message`, `chatwoot_status` — Chatwoot bridge is not shipped in current core; tools were dead code.

= 1.0.3 — Content depth presets + bulk scheduler =
* NEW: Content Scheduler bulk-queue MCP tools — brainstorm and queue multiple posts in a single call.
* NEW: Content depth preset parameter exposed on content/scheduler tools (standard / deep / editorial).
* FIX: Knowledge Graph dropdown hotfix applied to tools that surface KG filters.

= 1.0.2 — Customers view + Elementor Audit =
* NEW: CRM tools surface segment drill-down data matching the new Customers view.
* NEW: Elementor Audit drill-down exposes per-page spacing/responsive findings.

= 1.0.11 — Theme orchestration =
* NEW: `theme_status` — full inventory of the active theme (slug, version, parent, RequiresPlugins) plus capability flags (LuwiPress active, WC active, Elementor active, customer chat enabled) and friendly-plugin detector results in one call.
* NEW: `theme_customizer_dump` — flat key-value map of every theme_mod for the active theme, optionally filtered by prefix (e.g. `luwipress_gold_`) so an AI orchestrator reads only theme-owned settings without WP core noise.
* NEW: `theme_customizer_get` — read a single theme_mod with type info + default detection.
* NEW: `theme_customizer_set` — write a single theme_mod with key-shape-aware sanitization (URL keys go through esc_url_raw; scalar keys through sanitize_text_field). Reads back the saved value so the caller can verify.
* NEW: `theme_ecosystem_status` — single-call ecosystem snapshot mirroring the LuwiPress Gold "Appearance -> LuwiPress Gold" admin dashboard: which storefront AI surfaces are live (search, chat, KG-related rail), every detected friendly plugin and what it gains, plus today's AI token spend.
* PAIRS WITH: LuwiPress Gold theme 1.3.0 — every Customizer-driven feature on the theme (topbar promo, logo accent, journal subtitle, footer blurb, social URLs) is now AI-orchestrable end-to-end through this tool set.

= 1.0.1 — Post Term Management =
* NEW: `taxonomy_assign_terms` MCP tool — assign/replace/append terms on any post for any taxonomy (post_tag, category, product_tag, product_cat, pa_*). Non-hierarchical terms accept names and auto-create missing ones; hierarchical taxonomies require IDs. Pass `append:true` to add without removing existing terms, or `terms:[]` to clear them all.
* IMPROVED: `content_update_post` now accepts optional `tags` (array of strings) and `categories` (array of IDs). Both replace the existing assignments when provided; omit them to leave term assignments untouched. Fixes the gap where tags/categories could only be set at create time.

= 1.0.0 =
* Initial release — split from LuwiPress core 2.1.0 as part of the 3.0.0 slim-down roadmap.
* Includes all 133 tools previously bundled with core LuwiPress.
* New tools since 2.1.0: `translation_batch`, `cache_purge`, `enrich_settings_get/set`, `translation_settings_get/set`, `chat_settings_get/set`, `schedule_settings_get/set`.
