=== LuwiPress Agentic ===
Contributors: luwidev
Tags: ai, agent, woocommerce, chat, automation, admin, assistant, middleware
Requires at least: 5.6
Tested up to: 6.9
Requires PHP: 7.4
Stable tag: 1.3.1
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Agentic middleware for LuwiPress. One admin chat surface, pluggable agent backend — Open Claw, Hermes, or your own endpoint.

== Description ==

**LuwiPress Agentic** is the in-admin agentic layer for LuwiPress. It gives store operators a single chat surface for managing WooCommerce content, SEO, translations, enrichment, CRM, and more — and lets them choose which agent runtime drives that surface.

**Two backends ship by default:**

* **Open Claw** — hosted at `https://oc.luwi.dev/agent` (override-able). General-purpose LuwiPress operator agent.
* **Hermes** — hosted at `https://hermes.luwi.dev/agent` (override-able). Tool-calling agent runtime.

Either backend can be pointed at a self-hosted or on-prem deployment. The admin UI is identical regardless of which one is active — that's the point of the middleware layer.

**Requires:** LuwiPress core plugin 3.1.0 or newer.

= What you can do with it =

* `/scan` — Content health (thin, missing SEO, missing translations, stale)
* `/enrich` — Queue batch enrichment for thin products
* `/translate` — List missing translations per language
* `/generate <topic>` — Draft a new blog post
* `/aeo`, `/reviews`, `/crm`, `/revenue`, `/products`, `/plugins`, `/help`

Or just type — "how many at-risk customers do I have?", "generate a blog post about tuning a darbuka", etc.

= Design =

* Admin-only — no Telegram/WhatsApp bridge. Front-end customer chat is handled separately by the Customer Chat widget in LuwiPress core.
* Backend-agnostic — same uniform request shape goes to whichever runtime you select (Open Claw, Hermes, future adapters).
* Pluggable — third parties can register additional adapters via the `luwipress_agent_register` action.
* Conversation history stored per-user in options (50 messages rolling window).

== Installation ==

1. Install and activate the core **LuwiPress** plugin first.
2. Upload `luwipress-agentic.zip` via Plugins → Add New, or unpack to `wp-content/plugins/luwipress-agentic/`.
3. Activate **LuwiPress Agentic**.
4. Open it at **WordPress Admin → LuwiPress → Agentic**.
5. In the right-hand "Backend Runtime" panel, pick your active agent and enter the access token. Endpoints default to the hosted services; override them if you self-host.

== Changelog ==

= 1.3.1 — Hotfix: Commerce hub fatal (tab array shape) =
* **Fix:** the Agentic Commerce admin page (`LuwiPress → Commerce`) threw a critical error — the modernized tab strip iterates each tab as an array (`$meta['icon']` / `$meta['label']`), but the `$tabs` definition was still the flat string shape from before the UI refresh, so PHP 8 raised a fatal on string-offset access. The header rendered, then the page body died. The `$tabs` array is now the icon-keyed shape the strip expects, and the `$lp_logo_url` used by the branded header is defined. All five tabs render cleanly. No backend / REST / data change — UCP and AP2 endpoints were unaffected throughout (returned HTTP 200 the whole time).

= 1.3.0 — Agentic Commerce hub (Google UCP + AP2), moved in from core =
* **New `LuwiPress → Commerce` hub.** The Agentic Commerce modules (introduced in core 3.6.0) now live here so the core plugin stays lean. Requires core LuwiPress 3.6.2+. Modernized admin UI: LuwiPress-branded `lp-header` with logo + icon-based `lp-hub-tabs` strip (Overview / UCP Feed / Checkout / AP2 / Transactions), reusing the core LuwiPress admin design system.
* **`LuwiPress_UCP`** — Google Universal Commerce Protocol feed readiness: `native_commerce` / `consumer_notice` / `merchant_item_id` product meta, return-policy + support settings, per-product eligibility validator + store-wide coverage report, supplemental feed (JSON/CSV/XML). REST `/ucp/settings`, `/ucp/eligibility`, `/ucp/product/{id}`, `/ucp/feed`.
* **`LuwiPress_UCP_Checkout`** — UCP native checkout (session create/update/complete) backed by WooCommerce `checkout-draft` orders so totals are WooCommerce's own. Sandbox-first. Table `wp_luwipress_ucp_sessions`. REST `/ucp/checkout/session(/{id}(/complete))`.
* **`LuwiPress_AP2`** — Agent Payments Protocol mandate audit trail: verify a presented Cart Mandate (structure / expiry / issuer / amount-match), persist the Intent → Cart chain on the order, pluggable signature verifier via `luwipress_ap2_verify_mandate`. REST `/ap2/settings`, `/ap2/mandate/verify`, `/ap2/transaction/{id}`, `/ap2/log`, `/ap2/checkout/complete`.
* **WebMCP:** the 15 `ucp_*` / `ap2_*` tools (WebMCP companion 1.0.36) register against these classes — they activate when this companion is active and silently skip when it isn't.
* **Data continuity:** the move reuses the exact same table + option + order-meta names, so a site upgrading from core 3.6.0/3.6.1 keeps all UCP/AP2 settings, sessions, and mandate history. The checkout sessions table is ensured on version advance (not just on activation), so a ZIP-replace update creates it cleanly.

= 1.2.0 — WebMCP tool-calling agentic loop =
* **Real agentic loop.** When the active backend supports OpenAI function-calling, the plugin now sends the WebMCP read-only tool catalog to the LLM with every dispatch. The LLM picks the right tool, the plugin executes it locally against WebMCP's registered handler (no HTTP round-trip), feeds the result back as a `tool` role message, and loops up to 5 turns until the LLM produces a final answer. Result: questions like "kaç müşteri var" / "kaç bot hesabı flagged" / "FAQ coverage" / "stoğu biten ürünler" now return **actual site data** instead of generic "ben göremiyorum" responses.
* **Safety invariant — read-only only.** Only WebMCP tools whose `annotations.readOnlyHint=true` are exposed to autonomous LLM invocation. Destructive tools (delete users, bulk enrich, retranslate, etc.) are NEVER picked up by the LLM. Operators still trigger destructive ops via explicit admin UI flows.
* **Catalog cap** at 40 tools per dispatch (cached 1h in transient) to keep prompt size + cost reasonable. Increase or filter the catalog via the registry as the WebMCP surface grows.
* **Wire format extended** — `messages[]` now also accepts `tool` role (with `tool_call_id`) and `assistant` messages with `tool_calls[]` metadata. HTTP adapter passes them through to the gateway; gateway forwards to OpenAI in native function-calling shape.
* **Multi-turn cap:** `MAX_TOOL_TURNS=5` guards against runaway tool-call loops.
* Requires the upgraded `luwi-agent-gateway` service (returns OpenAI-native `tool_calls` in `{function_name, arguments}` shape with backward-compatible `{action, params}` aliases).

= 1.1.2 — Endpoint hygiene + reload-less Active backend switch =
* **Endpoint normalizer** in the HTTP adapter — when operators paste a bare host into the Endpoint field (e.g. `https://hermes.luwi.dev` or `https://hermes.luwi.dev/`), the adapter now auto-appends `/agent` so the request actually reaches the wire-format handler instead of the host's catch-all page. Custom self-hosted endpoints with explicit paths (e.g. `https://my.host/api/v1/chat`) are preserved untouched. Trailing slashes get trimmed. Invalid URLs pass through so error handling stays explicit.
* **Reload-less Active backend switch** — saving a card with the "active" radio checked now also live-updates the chat-page header pill (`#agentic-active-pill`), chat-panel model label (`#agentic-active-label`), and the sidebar "Active: X — chat goes to this runtime" text via DOM patches when those elements are visible on the same screen. Operators no longer have to Ctrl+F5 the chat page after switching backends. Selectors are no-ops on the standalone settings page.
* The Endpoint input is repopulated from the server's normalized value after Save, so what the operator sees matches what the plugin will actually call.

= 1.1.1 =
* Standalone "Agentic Settings" sidebar entry retired. Backend runtime configuration now lives as the **Agentic** tab inside LuwiPress → Settings (single source of configuration). The old URL `?page=luwipress-agentic-settings` redirects automatically.
* Requires core LuwiPress 3.2.4+ for the `luwipress_settings_render_tab_nav` / `_content` extension points.

= 1.1.0 =
* Rebrand: LuwiPress Open Claw → LuwiPress Agentic. Plugin now positions itself as the middleware between the admin chat surface and any agent backend.
* Open Claw adapter converted to HTTP — same request shape as Hermes, default endpoint `https://oc.luwi.dev/agent` (override per install). The local AI Engine path is retired in favour of the hosted agent.
* Hermes adapter gains a default endpoint (`https://hermes.luwi.dev/agent`) so operators only need to provide a token to get started.
* Admin page sidebar gains a "Backend Runtime" panel: pick the active adapter, set per-adapter endpoint + token, save without reloading.
* Internals: option keys `luwipress_agent_open_claw` + `luwipress_agent_hermes` + `luwipress_agent_active` are the source of truth — third-party adapters keep registering via `luwipress_agent_register`.

= 1.0.0 =
* Initial release — split from LuwiPress core 3.0.0 as part of the slim-down roadmap. Functionality unchanged.
