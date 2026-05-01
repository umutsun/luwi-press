=== LuwiPress — AI-Powered WooCommerce Automation ===
Contributors: luwidev
Tags: woocommerce, ai, seo, translation, automation, product enrichment, multilingual
Requires at least: 5.6
Tested up to: 6.9
Requires PHP: 7.4
Stable tag: 3.1.39
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

AI-powered content enrichment, SEO optimization, and translation automation. WooCommerce is recommended for the full feature set; the plugin runs without it for content + chat workflows.

== Description ==

LuwiPress is a **standalone AI plugin** for WooCommerce stores — generates content, optimizes SEO, translates products, and automates store management without external dependencies.

**It doesn't replace your plugins — it makes them smarter.**

= What LuwiPress Adds =

| Your Plugin Does | LuwiPress Adds (via AI) |
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
* **WebMCP Server** — MCP Streamable HTTP server exposing 115+ tools to AI agents (Claude Desktop, Claude Code, any MCP client)
* **Plugin Auto-Detection** — Automatically detects Rank Math, WPML, Polylang, WP Mail SMTP, FluentCRM, Chatwoot
* **Cost Protection** — Daily budget limits, token tracking, per-workflow cost breakdown
* **Multi-Provider AI** — OpenAI (GPT-4o Mini), Anthropic (Claude), Google (Gemini)

= How It Works =

1. Install LuwiPress on your WordPress site
2. Enter your AI API key (OpenAI recommended for best cost/quality ratio)
3. Set a daily budget limit to control costs
4. Use Open Claw AI assistant or dashboard buttons to trigger automations — all AI calls run natively in WordPress, no external workflow engine required

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

LuwiPress includes built-in cost protection:

* **Daily budget limit** — AI features auto-pause when reached ($1.00 default)
* **Token usage tracking** — See exactly which workflows cost money
* **Model selection** — GPT-4o Mini is 20x cheaper than Claude Sonnet
* **Local commands** — /scan, /seo, /translate work without any AI cost
* **No hidden charges** — You bring your own API key, you control spending

= Requirements =

* WordPress 5.6+
* WooCommerce 5.0+ (recommended)
* AI API key (OpenAI, Anthropic, Google, or any OpenAI-compatible endpoint)

= Supported Plugin Integrations =

* **SEO:** Rank Math, Yoast, AIOSEO, SEOPress
* **Translation:** WPML, Polylang, TranslatePress
* **Email:** WP Mail SMTP, FluentSMTP, Post SMTP
* **CRM:** FluentCRM, Mailchimp for WooCommerce
* **Support:** Chatwoot

== Installation ==

1. Upload the `luwipress` folder to `/wp-content/plugins/`
2. Activate the plugin through the 'Plugins' menu
3. Go to **Settings → AI API Keys** and enter your OpenAI, Anthropic, Google, or OpenAI-compatible API key
4. Set your daily budget limit (recommended: $1.00/day)
5. (Optional) Install the **LuwiPress WebMCP** companion plugin if you want to expose the store to AI agents over the Model Context Protocol

== Frequently Asked Questions ==

= Do I need any external workflow engine? =

No. LuwiPress is fully standalone as of 2.0.1 — the AI engine, job queue, and prompt templates all run natively inside WordPress. No n8n, no Make, no Zapier required. You only need an AI API key.

= Which AI provider should I use? =

We recommend **OpenAI GPT-4o Mini** for the best cost/quality ratio. At $0.15 per million input tokens, you can run thousands of operations for under $1/day.

= Will this increase my hosting costs? =

No. LuwiPress is lightweight — async jobs run through WP-Cron and most AI calls complete in under a second of PHP time. The heavy lifting happens on the AI provider's side, not your server.

= Is my data sent to third parties? =

Product data is sent directly from your WordPress site to the AI provider you choose (OpenAI, Anthropic, Google, or an OpenAI-compatible endpoint you configure). No data is sent to LuwiPress servers or any intermediate service.

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

= 3.1.39 — Cross-post section copy endpoint =
* NEW: **`POST /elementor/copy-section`** — copies a top-level Elementor section from one post into another at a given position, with deep ID regeneration and an automatic snapshot on the target before mutation. Sister endpoint to `/elementor/clone` (which only operates within a single post). Use this to remediate WPML drift cases where a translation is structurally missing a section (e.g. breadcrumb) without touching the rest of the translated body — the existing `/elementor/sync-structure` rebuilds the whole tree and can lose translated text when target is missing leading sections (verified 2026-04-28 on Tapadum FR pilot). Payload: `{source_post_id, source_section_id, target_post_id, target_position}` — `target_position: 0` prepends, `-1` or out-of-range appends. Response includes `new_section_id` (regenerated), `snapshot_id` (rollback target), `target_position` (final insertion index).

= 3.1.38 — WooCommerce is now optional + dashboard ribbon UI =
*Post-3.1.38 in-place hotfixes (folded into 3.1.38, no version bump per project policy):*
* FIX: **Shortcode-only pages (Cart / Checkout / My Account) no longer fail translation.** WooCommerce shortcode pages contain no Elementor widget text — only the shortcode that renders the cart/checkout UI at runtime. Previously these pages threw `no_text: No translatable text found on this page` and stayed eternally in the missing-list. Now `translate_page` falls back to creating a structure-only WPML translation pair (clones source `_elementor_data`, no AI calls, $0 cost) so WPML knows the language pair exists and the missing-list clears. Operator can still localize shortcode args via WPML String Translation if needed.
* FIX: **WPML hook race re-stamp loop.** Auto-cleanup used to DELETE bad icl_translations rows when WPML overwrote `language_code` on freshly-created translation posts. Result: source kept appearing in missing-list -> re-translated -> WPML mis-stamped again -> infinite loop (one full create+translate per Translation Manager page render). Now `create_translation_post` writes `_luwipress_translation_source` + `_luwipress_translation_language` meta on every translation post, and auto-cleanup uses those to RE-STAMP the row with correct language_code + source_language_code instead of deleting it. Loop closed permanently.
* FIX: **Translation cascade-duplicate eradication.** WPML hook race could overwrite the language_code of freshly-created translation posts to 'en' immediately after insert, causing them to look like fresh EN sources on the next missing-list query and get re-translated infinitely. Five-layer defense: (1) REST/AJAX guard rejects non-source posts, (2) cron worker guard catches stale queued jobs, (3) inline `translate_page` chokepoint with auto-cleanup of bad icl_translations rows, (4) Translation Manager auto-cleanup on every page render, (5) source-list SQL `NOT EXISTS` against `_luwipress_elementor_translated=1` meta as the strong source-vs-translation discriminator (the meta is only set on translation posts, so its presence means "not a source" regardless of what icl_translations says).
* NEW: **Outdated translation detection (semi-automatic).** Every successful translation stamps the target post with `_luwipress_synced_source_modified` (source's modified timestamp at sync time). New `GET /translation/outdated` REST endpoint returns sources whose `post_modified_gmt > stamp`, grouped by source with per-language `lag_hours`. Translation Manager renders an amber panel listing each outdated source with two one-click actions: **Sync structure** (copies new structure from source while preserving existing translations for unchanged texts — safe, no AI cost) and **Re-translate** (full re-translation, costs AI tokens — confirmation prompt).
* FIX: **Fix Orphans Type 4 — multi-EN-row trid cleanup.** New maintenance routine catches the "two posts in one trid both flagged source_language_code=NULL" case that existing Type 1/2/3 detection couldn't handle. Oldest row stays as legit EN source; later rows have their WPML metadata removed (post itself preserved).
* FIX: **dispatch() return-shape fatal.** `LuwiPress_AI_Engine::dispatch()` returns an array `{content, input_tokens, ...}`, not a bare string. Two call-sites (Elementor title fallback, taxonomy single-term translate) were doing `trim($result)` directly which threw `trim(): Argument #1 must be of type string, array given` — silently aborting the translation post-processing pipeline mid-write. Now extracts `$result['content']` first.
* IMPROVEMENT: **Maintenance Tools UI minimal redesign.** Collapsed by default into a single line; opens to a compact icon-grid (7 utilities × ~140px tiles). Hover for full description tooltip. Replaces the previous 7-card vertical stack that pushed actual translation rows below the fold.
* IMPROVEMENT: **Translation Manager fully bypasses admin-ajax cache.** Inline missing-list is server-side pre-fetched at page render — JS reads from `lpInlineMissing` and never round-trips through admin-ajax for the missing-fetcher. The "X missing in DB but unreachable" amber state can no longer be caused by stale AJAX responses.
* IMPROVEMENT: **WPML hook race protection in `create_translation_post`.** Direct DB insert of icl_translations is now followed by explicit WPML cache invalidation + verify-then-force-correct loop.

*Original 3.1.38 changelog:*
* CHANGE: **WooCommerce is no longer a hard install-time requirement.** The `Requires Plugins: woocommerce` header was removed. LuwiPress now activates and runs on any WordPress site. WC-dependent features (product enrichment, AEO product schema, marketplace publishing, CRM customer segmentation, product Knowledge Graph nodes, review analytics) self-disable when WooCommerce is missing; the rest of the plugin (content scheduler, customer chat with site-content RAG, AI provider settings, token tracking, generic SEO/AEO writer for any post_type) keeps working.
* NEW: **Status ribbon on the dashboard** — one compact pill per integration category (AI key, WooCommerce, SEO, Translation, SMTP, Cache, Page Builder, Analytics, Google Ads, Meta Pixel, Product Feed). Green dot = friendly plugin detected, red dot = empty slot. Hover any pill for the "what does this enable, what's recommended" tooltip. Click a red pill to open the standard WordPress plugin-information modal (screenshots, description, Install Now button — operator decides). Click a green pill to open the detected plugin's settings page. Single chip row replaces three legacy UI surfaces (banner notices, install prompts, plugin docs links).
* NEW: **Endpoint registration is WC-aware.** Product-bound REST routes (`/product/enrich`, `/product/enrich-callback`, `/product/enrich-batch`, `/product/enrich-batch/status`) only register when WooCommerce is active, so `/wp-json` route map reflects what the install can actually do. Generic routes (`/seo/schema`, `/seo/faq`, `/enrich/settings`, `/content/stale`) always register.
* NEW: **`LuwiPress::is_wc_active()` helper** — single source of truth wrapping `class_exists('WooCommerce')`. Used at module level to gate WC-only hooks (product meta box, `woocommerce_new_product` auto-enrich, etc.).
* IMPROVEMENT: **AEO save handlers + Review Analytics now guard `wc_get_product` calls** so a misconfigured cron or external integration calling them on a WC-less site returns a clean 501 / null instead of a fatal undefined-function error.
* IMPROVEMENT: **Admin notices are suppressed inside LuwiPress admin pages.** Third-party notices (theme TGM "recommended plugins" prompts, WP core update nags, plugin promo banners) used to inject between the dashboard `<h1>` and content body, physically pushing the hero around. They now hide on LuwiPress screens (Dashboard, Knowledge Graph, Settings, Translations, Content Scheduler, Usage & Logs, WebMCP) and reappear on native WP screens (Plugins list, WP Dashboard home) where the operator expects them.
* IMPROVEMENT: **Dashboard menu position fixed** — the LuwiPress entry now sits right under the WordPress Dashboard menu (top of sidebar), no longer fighting other plugins for slots in the 25-55 position cluster where heavy themes register their custom-post-type menus.
* IMPROVEMENT: **Header pills consistency** — Knowledge Graph shortcut, version badge, and Settings cog in the dashboard header all share the same compact pill styling as the status ribbon below; tooltips hover-overlay correctly without being trapped under stat-card stacking contexts.
* FIX: **Build pipeline now hard-fails on UTF-8 BOM.** Editors that silently re-save PHP files with a BOM (Notepad++ save-with-BOM, some FTP file managers) used to ship 3 bytes of stdout that broke WordPress activation with "headers already sent" / "plugin generated unexpected output". `build-zip.php` now scans every PHP file's first 3 bytes and refuses to produce the ZIP when a BOM is detected, with the offending file path listed.
* FIX: **Module instantiation timing** — modules now instantiate synchronously inside the plugin constructor instead of via a late-priority hook that could race with `admin_menu` and produce a parent menu without its submenus on first activation.

= 3.1.37 — Hotfix: LiteSpeed cache bypass delivery fix + declare WooCommerce dependency =
* SECURITY: **Hotfix for 3.1.36's REST cache hardening.** The 3.1.36 release added `X-LiteSpeed-Cache-Control: no-cache` as a PHP-level response header via `header()`, but on some hosts (Hostinger LSWS + LiteSpeed Cache) `rest_post_dispatch` fires after PHP response headers have been flushed, so the `header()` call was a silent no-op. As a result, LiteSpeed continued to cache authenticated REST responses by URL and could replay the cached body to subsequent anonymous requests. 3.1.37 routes the signal through `WP_REST_Response::header()` instead, which is applied by WordPress REST's own header-flush stage and reaches LiteSpeed reliably. **All `luwipress/v1/*` authenticated endpoints now carry the LS bypass header correctly. Existing customers on LiteSpeed: after upgrading, run a one-time `Toolbox → Purge All` in the LiteSpeed Cache admin so previously cached entries are flushed.**
* IMPROVEMENT: **Core plugin now declares its WooCommerce dependency** via the `Requires Plugins: woocommerce` plugin header. WordPress 6.5+ honours this tag and prevents activation when WooCommerce is missing, replacing the old "install and figure out later" behaviour with a clear install-time gate. The runtime `class_exists('WooCommerce')` guards are unchanged — this is a UX improvement for hosts running WP 6.5+, not a functional change.

= 3.1.36 — REST cache hardening + batch status persistence + AEO/Token polish =
* SECURITY: **Closed a cache-layer data visibility gap on authenticated REST responses.** All `luwipress/v1/*` endpoints now stamp `Cache-Control: no-store, private, must-revalidate` plus a matching `X-LiteSpeed-Cache-Control: no-cache, no-store, private` header on every response, so upstream page caches (LiteSpeed, Varnish, NGINX FastCGI, Cloudflare in cache-everything mode) cannot replay an admin's authenticated body to a subsequent unauthenticated visitor. Truly public routes (`/status`, `/health`, `/chat/config`) keep their existing cacheable behaviour. **Recommended additional defence-in-depth:** if your host runs LiteSpeed Cache or another full-page cache, also add `wp-json/luwipress` to the "Do Not Cache URIs" list — the headers shipped here are the primary fix, the rule is belt-and-braces.
* FIX: **`/product/enrich-batch/status` now keeps reporting `total / completed / progress` after the batch finishes.** Previously, once every product in a batch flipped to `completed` and downstream workflows replaced the status postmeta, the status endpoint returned `total: 0, progress: 0` — making it look like the batch had vanished. The endpoint now persists a 24-hour batch-level summary at queue time and merges it back into the response, so `Enrich all products in category` (and the floating progress monitor) keep showing accurate post-completion numbers.
* FIX: **`/aeo/coverage` now exposes both single-language and multilingual numbers** so the figures in the Usage page line up with the Knowledge Graph instead of looking 4× higher just because WPML/Polylang translations are counted as separate posts. The response gains two new blocks — `primary_language` (WPML/Polylang originals only — matches the Knowledge Graph total) and `all_languages` (every published product across every language — the previous behaviour). Top-level fields are unchanged for back-compat.
* FIX: **`/token-recent?limit=N` query parameter is now honoured.** The endpoint hard-coded `limit=20` regardless of what the caller asked for; admin dashboards that requested e.g. 5 rows had to client-side slice. Limit is now read from the query string and clamped to `1..100` with a default of 20.

= 3.1.35 — Knowledge Graph: queued actions lock until refresh =
* FIX: **Queued action buttons no longer re-enable while the backend is still processing.** When you hit "Translate all" on a taxonomy batch (or Enrich category, or any kgAction), the button used to flip to "Queued (2/2)" and then reset itself to its original label 3–25 seconds later — inviting the operator to mash it again while the first batch was still running. Now the button stays locked at its success state (dimmed, cursor `not-allowed`, no hover lift) until the panel refreshes; the refresh re-renders the whole panel from fresh data, producing a new button that reflects the current state (usually disabled because there's nothing left to do). Only error paths reset the button and prompt "retry?" so failed calls are still recoverable.
* FIX: **Taxonomy Translate all** reports the actual terms queued (`Queued (12 terms)` instead of `Queued (2/2)` which conflated taxonomy types with terms). Refresh delay bumped 8→12s to give slower WPML string-translation endpoints a chance to land.
* UI: **Disabled kg-btn styling** — dimmed opacity + `cursor: not-allowed`, no hover translate lift. Makes the locked state visually unmistakable instead of a button that looks interactive but does nothing.

= 3.1.34 — Knowledge Graph: stat card hover truly shift-free =
* FIX: **Stat card height is now identical at rest and on hover.** The previous fix moved the cue ("Needs enrich →" / "Show all →") from a height transition to an opacity fade, but because the cue was centered in a flex column its baseline shifted ~2px when it appeared, dragging the graph with it. Cue is now rendered at its full height unconditionally with `visibility: hidden` + `opacity: 0` at rest — hover flips both to visible. The card's bounding box never changes; the graph canvas sits at the exact same y-position whether your cursor is on the stats or not.

= 3.1.33 — Settings → API Keys: compact 2-column layout + Knowledge Graph stat hover stability =
* FIX: **Knowledge Graph stat cards no longer shift layout on hover.** The "Show all →" / "Needs SEO →" action cues used to slide in on hover with a max-height transition, which pushed the graph canvas down by ~15px every time the cursor crossed a card — visibly jittery and annoying when you're trying to scan the graph. Cue space is now reserved up front (fade-in only, no height change) and the card has a fixed `min-height` so its bounding box never changes. Hover only swaps the border colour and adds a subtle shadow.
* UI: **Provider card went compact.** Pill height trimmed (padding 16→12px), pill font 15→14px, pill body gets one tight label + vendor line instead of a spacious two-line stack. Card padding 28→22px. Result: the four provider pills + active key + model all fit on one viewport height on a 1366px screen — no more scrolling to see the model picker.
* UI: **Key input + model picker now share a 2-column layout on desktop.** Left column: API key input + "Get your API key →" link. Right column: model grid (single-column list of cards for tight vertical alignment). On tablet/mobile (<880px) they stack back to one column. Custom (OpenAI-Compatible) provider keeps its single-column stack since it needs preset + base URL + key + model in sequence.
* UI: **Cost Protection card removed** from the API Keys tab. Four bullet points reiterating what the Daily Budget Limit already does below — redundant marketing copy that pushed useful settings below the fold. The Cost & Limits card above already delivers the same information directly through the actual controls.
* UI: API key input now fills its column width instead of capping at 520px — long keys (OpenAI sk-proj tokens regularly hit 160+ chars) fit without truncation.

= 3.1.32 — Knowledge Graph: legend removed =
* UI: **The static legend box in the graph's bottom-left corner is gone.** It listed eight node-type / status colors that operators don't actually reference in practice — hovering a node already shows its type + key stats in the tooltip, which is more informative. Removing it gives the graph canvas an extra ~200×180px of clean space and the view no longer has a floating UI element competing with the data.

= 3.1.31 — Knowledge Graph: customers radial layout + minimal labels =
* UI: **Customers view now lays out as a radial cluster.** The "All customers" hub is anchored at the center (fixed y-force, 80% strength) and every populated segment sits on a circle around it at a computed angle (clockwise from 12 o'clock, evenly spaced by lifecycle order). Reads like a clock: New at the top, walking clockwise through the health path, ending at Lost. Reliably stays inside the viewport regardless of how many cohorts are populated. Segment radius 14–28px, hub 28px.
* UI: **Node labels shrunk one more notch** — regular labels 9.5→8px, anchor labels (categories / languages / hubs) 11→10px. Combined with a slight opacity drop (0.85 for regular), the graph now reads as "shapes first, text on demand."
* UI: **Post and page labels hidden at rest, revealed on hover.** The full title is still always available in the tooltip; the persistent on-canvas label was causing a wall-of-overlap in Posts view where 57 titles competed for the same pixels. Hover a node → its label fades in. Product nodes never had labels — same principle.
* UI: **Hubs are tooltip-only.** The "All customers" / "Site pages" hubs now explain themselves via tooltip ("click a segment around the hub for a breakdown") and don't open a detail panel on click — they're visual anchors, not drill targets.

= 3.1.30 — Settings → API Keys: model picker lives with the provider =
* UI: **Model selection moved inside the provider card.** Before, you picked a provider (e.g. Gemini) at the top and then had to scroll to a separate "AI Model & Cost Control" card with a dropdown showing all three providers' models — so you could end up with "Provider: Gemini" and "Model: GPT-4o Mini" saved together. The new layout puts a **per-provider model card grid** right under the API key input. Only the active provider's models are shown, and switching pills auto-selects that provider's recommended default. Cross-provider mismatch is no longer possible.
* UI: **Model cards use the same visual-selection language as the scheduler's depth cards.** Title + cost (per-1M tokens, input/output) in monospace, green "Recommended" badge on the default pick, primary-tinted gradient + check badge when selected.
* UI: The "AI Model & Cost Control" card was split — model moved up to the provider card; the remaining card renamed to **"Cost & Limits"** (daily budget + max output tokens only).
* UX: **Cross-provider migration handled silently.** If a previously-saved `luwipress_ai_model` belongs to a provider you're not currently using, the UI falls back to the new provider's recommended default and a hidden `luwipress_ai_model` field syncs on every provider-pill or model-radio change — so the form submit always carries a model that matches the active provider.

= 3.1.29 — Knowledge Graph visual clarity pass =
* UI: **Orphan nodes no longer drift to the edge of the canvas.** Categories with zero products (like "Tongue Drum" on Tapadum) and unconnected pages used to float 800-1500px away from the main cluster because nothing pulled them back. Radial centering force is now ~2× stronger and every tick clamps each node inside a 40px inner margin — the graph stays a compact, coherent shape regardless of orphan density.
* UI: **Node labels shrunk** — regular labels 11→9.5px and posts/pages trim at 22 chars instead of 32. Categories, languages, and hub nodes keep the bigger 11px label so they stand out as navigational anchors. Full titles still show in the hover tooltip. Result: the "wall of overlapping blog post titles" from earlier screenshots is gone.
* UI: **Pages view has structure.** Pages are mostly top-level on a typical WP site (parent_id=0), so the old parent-child-only graph rendered 60+ isolated dots. Now there's a virtual "Site pages" hub that every orphan page attaches to — the view reads as a tree with front/shop/blog as secondary anchors and everything else clustering around the hub. Still clickable → opens the individual page detail panel as before.
* UI: **Customers view rebalanced.** Empty segment buckets (`vip=0`, `loyal=0` on low-repeat stores) are hidden so the view isn't cluttered with zero-count nodes. Segment radii use a square-root scale so the biggest cohort (e.g. 37 one-time) doesn't dwarf smaller ones. The hub is now bigger and anchored above the chain; segments sit in a clean horizontal line below, distributed by lifecycle order (New → Active → At-risk → Dormant → One-time → Lost).

= 3.1.28 — Knowledge Graph: Store Health collapsed into the header =
* UI: **Store Health is now a pill inside the page header**, sitting right next to the "Knowledge Graph" title — score + mini progress bar + chevron, nothing more. Clicking the pill slides down a detail panel (qualitative subtitle + weakest-first dimension chips + achievement badges) under the header. Reclaims ~40px of vertical space at rest so the graph sits ~110px higher on the page.
* UI: The dedicated Store Health banner that used to consume a full row is gone. Details only exist when you want them.

= 3.1.27 — Settings → API Keys simplified + Knowledge Graph density tightened further =
* UI: **Knowledge Graph Store Health banner is now truly one line.** Dropped the explicit "Store Health" label (score prominence tells the story already) and moved the Details toggle out to the right edge of the banner. Score 32→26px at rest, padding 10→6px top + 14→7px bottom. Expanding the toggle reveals the full dimension chips + achievement badges as before — but at rest the banner is ~40% shorter.
* UI: **Stat bar one-notch denser.** Value 18→16px, padding 6→5px, gap + bottom margin also trimmed. The "Show all →" / "Needs SEO →" action cues are now hidden at rest and slide in on hover so the card is cleanest when you're just scanning numbers.
* UI: **API Keys tab rebuilt as a single provider-driven card.** The old layout stacked three cards (AI Provider radios, three-row API Keys table, OpenAI-Compatible) and asked the user to hold context across all of them — "I picked Claude, so why are there three key fields?" The new layout has **one card**: four provider pills (Claude / GPT / Gemini / Custom) at the top, and only the selected provider's key input below. Switch pill → key field fades in, others disappear.
* UI: **API Keys tab rebuilt as a single provider-driven card.** The old layout stacked three cards (AI Provider radios, three-row API Keys table, OpenAI-Compatible) and asked the user to hold context across all of them — "I picked Claude, so why are there three key fields?" The new layout has **one card**: four provider pills (Claude / GPT / Gemini / Custom) at the top, and only the selected provider's key input below. Switch pill → key field fades in, others disappear.
* UI: **Provider pills use the same visual-selection language as the scheduler's depth/mode cards** — soft primary-tinted gradient when active, a circular check badge in the top-right corner, and a green saved-key check next to the provider name when a key is already stored.
* UI: **Input row is modern** — pill-shaped input with monospace font, soft focus ring, matching "show/hide key" toggle. Each provider has a "Get your API key →" link pointing straight to the vendor console (Anthropic, OpenAI, Google AI Studio).
* UI: **Custom (OpenAI-Compatible) no longer lives in a separate card.** Pick the Custom pill and you get preset dropdown, optional base URL (auto-toggled for self-hosted), key, and model picker — all in one flow.
* UX: **Fallback keys preserved without clutter.** If you have keys saved for providers you're not currently using, they're collapsed into a subtle "N other saved keys" details block below the main card, showing just the vendor name + masked key (first 7 + last 4 chars). Expand to peek, switch pill to activate.

= 3.1.26 — Knowledge Graph density + filter feedback =
* UI: **Stat bar cards tightened** — value font 22→18px, padding 10/6→6/7px, label 10→9.5px, row gap 8→6px. Collectively shaves ~25% off the top-of-page footprint so the graph is immediately visible without scrolling on 1366px screens.
* UI: **"Interactive store intelligence map" subtitle removed** from the KG header — redundant with the page title, took horizontal space away from the search box and controls.
* UI: **Filter / view switch now produces visible feedback.** When you click a stat card or switch views (Products → Posts → Pages → Customers), the graph canvas pulses a soft primary-tinted ring and a floating "N products" / "57 posts · needs seo" chip appears in the top-left corner for ~2.4s. Confirms the filter applied even when the redraw is instant.
* UI: Stat card "View details →" / "Top wins →" / etc. hints now fade in on hover (opacity 0.6 → 1) so the card is cleaner at rest but the action cue still signals clickability.

= 3.1.25 — Knowledge Graph layout refinement: collapsible hero + Next Wins below + cleaner graph =
* UI: **Store Health hero is collapsible** — compact by default (score + one-line summary + colour bar), with a "Details" toggle that expands into the full dimension chips and achievement badges. Keeps the graph above the fold on 1366px screens; expand only when you want the full breakdown.
* UI: **Action Queue ("Next wins") moved below the graph.** Operators now explore the store first (graph is immediately visible), then land on the ranked next-step cards underneath — matches the natural "look, then act" reading order and lets KG mutations feel live under your fingers.
* UI: **Graph layout tightened.** Added weak radial pull (`forceX` + `forceY`) so orphan categories and unconnected product nodes no longer drift to the canvas edge — the whole graph stays neatly centered without sacrificing cluster spread.
* UI: **Long node labels are now trimmed to ~32 characters with an ellipsis.** Blog post and page titles could reach 60+ characters and overlap into a wall of text; the hover tooltip still shows the full title on demand. Categories, languages, and segments keep their full names (they're already short).
* UI: **Customers view reworked as a lifecycle chain.** A central "All customers" hub node anchors the view, and the eight segment cohorts now pin left-to-right by lifecycle order (New → Active → Loyal → VIP → At-risk → Dormant → One-time → Lost). Every segment connects back to the hub so the graph is no longer a set of disconnected islands. Makes the retention story visible at a glance — healthy cohorts on the left, drift segments on the right.

= 3.1.24 — Scheduler delta polling (no more full reloads) =
* NEW: **`GET /schedule/delta`** REST endpoint returns only the rows whose status changed since a given timestamp (plus optional `ids` whitelist for active rows). The admin UI now uses this to update row badges, status tags, and error banners **in place** without a full page reload — so your scroll position, bulk-selection checkboxes, and open form state survive the poll.
* UX: When a generating item finishes it may reveal new action buttons (Enrich, Review & publish) — if no rows are selected and the user is still near the top of the page, a soft reload kicks in to surface them. Otherwise the in-place update is enough.
* NEW: `luwipress.nonce_rest` (wp_rest nonce) is now localized alongside `ajax_url` so admin-only REST endpoints can be called from admin-enqueued JS without a second auth round-trip.

= 3.1.23 — CRM threshold settings + AEO Action Queue candidate =
* NEW: **`GET / POST /crm/settings` endpoint** exposes all six customer-segment thresholds (`vip_spend`, `loyal_orders`, `active_days`, `at_risk_days`, `dormant_days`, `new_days`) so operators can tune them without touching the database. High-ticket / low-frequency stores (think furniture, instruments) can drop `loyal_orders` from 3 → 2 and immediately see their 2-order customers appear in the Loyal / VIP buckets. Partial-update pattern — absent keys keep their current value. Response hints the operator to call `/crm/refresh-segments` to reclassify.
* NEW: **Action Queue — AEO Schema candidate.** The Knowledge Graph "Next wins" list now spots AEO gaps too: when HowTo / Schema / Speakable coverage is below 30%, it picks an already-enriched product still missing that field and surfaces a one-click generate action. Rich results (HowTo cards, review snippets) only fire when the structured data exists — this candidate turns that into a concrete recommendation instead of a number in the stat bar.

= 3.1.22 — Usage & Logs live UI + TM design system + Luwi brand icon =
* NEW: **Usage & Logs page is now alive.** Stat cards, "Cost by Workflow" breakdown, 30-day cost sparkline, and daily-budget indicator all update every 10 seconds without reloading the page. Values count up smoothly, changed numbers briefly highlight, and the budget card pulses in warning red once spend crosses 90% of the daily limit.
* NEW: **Cost by Workflow is now a visual bar chart** instead of a plain table. Top 8 workflows are sorted by spend with coloured horizontal bars, call count, and token count inline — easy to spot at a glance which workflow is the cost driver this month.
* NEW: **30-day cost trend sparkline** inline under the stat cards. Tiny SVG that summarises daily spend curve so you can see weekend dips and campaign spikes without leaving the page.
* NEW: **Activity log "Live" mode.** Open the Logs tab, flip the "Live" toggle at the top right, and new log entries slide in from the top every 15 seconds — no more F5 to see the enrichment you just triggered. Toggle state is remembered per browser. Polling automatically pauses when the browser tab is in the background to save API cost.
* NEW: **Instant client-side log search.** Typing in the search box filters the visible entries after a 180ms debounce — no form submit, no URL change. Combined with level pills (All / Errors / Warnings / Info) you can drill into the exact entries you need in under a second.
* NEW: **Shared `LuwiLive` UI primitive.** The polling, count-up, sparkline, bar meter, and highlight-pulse helpers now live as a reusable client-side module, ready to plug into the Dashboard, Scheduler, Knowledge Graph, and Translations pages in upcoming releases so everywhere feels equally alive.
* NEW: **Luwi brand icon now appears in the WordPress admin menu bar and on the LuwiPress dashboard header**, replacing the generic Dashicon placeholder used previously. White variant on the dark menu, full colour on the dashboard.
* FIX: **Design system CSS that was missing.** The `tm-*` classes the Usage and Translation pages relied on (`tm-stats` grid, `tm-stat-card`, `tm-header`, `tm-table`, `tm-btn`, step cards, progress bars) had HTML markup but no matching styles, so both pages rendered as unstyled lists. The full TM design system is now defined with proper grid layouts, buttons, tables, empty states, and hover polish.
* FIX: **Button icons were sitting off-center** inside queue row actions ("Enrich", "Review & publish", "Edit", "View") — WP admin's default `.button` cascade made `.dashicons` inherit odd line-height. Added a scoped normalization: every `.sched-shell .button` is now `inline-flex` with `line-height: 1` and a sized dashicon so icon + label align on the same baseline regardless of button size.
* FIX: **Huge off-center `+` glyph on the empty-state "Create a plan" button** — caused by a `.sched-empty .dashicons { font-size: 48px }` rule leaking into nested buttons. Scoped to direct-child selector (`.sched-empty > .dashicons`) so the central hero icon keeps its size while nested buttons render normally.
* UI: **Third-party admin notices (LiteSpeed "Purged all caches", cache plugin banners, etc.) are now suppressed on the Scheduler page.** They duplicate the feedback our own toast system already delivers and pushed the header off-screen. LuwiPress's own notices can opt in via the `.luwipress-keep-notice` class.

= 3.1.21 — Knowledge Graph gamification: Next Wins + Achievements + Activity feed =
* NEW: **Action Queue ("Next wins")** — three ranked suggestion cards at the top of the Knowledge Graph page answer the question "where should I click first?" Candidates are scored by impact ÷ effort (affected_count × metric_weight divided by estimated minutes): worst-covered category SEO, language closest to 100% translation, taxonomy terms missing translation, media alt-text gaps, and top-opportunity single product. Each card has a tier stripe (high/medium/low) and a one-click CTA that either opens the relevant detail panel or fires the action directly.
* NEW: **Achievement badges** in the Store Health hero. Bronze → Silver → Gold → Platinum milestones light up automatically when coverage thresholds cross: "SEO > 80%", "Enrichment > 95%", "All languages 100%", "100+ products", "500+ products", etc. Top grand-slam "💎 Grand slam" badge fires when SEO + enrichment + translation + taxonomy all hit ≥95%. Dedupe keeps only the highest tier per category so the row stays clean.
* NEW: **Activity feed** below the graph canvas. Pulls the last 25 log entries from `/logs` (enrichment completions, translation batches, AEO jobs, errors) and auto-refreshes every 30s while the tab is visible. Relative timestamps (`2m ago`, `1h ago`), colour-coded level badges (error/warning/success/info), and an instant "+N new" flash when fresh entries land between polls. Action queue + category batch firings trigger an immediate poll so operators see their work reflected without waiting.

= 3.1.20 — Knowledge Graph dashboard upgrade: Store Health hero + clickable stats + weekly trend =
* NEW: **Store Health hero banner** at the top of the Knowledge Graph page. Single-number weighted average across SEO / enrichment / translation / taxonomy / design / media / plugin health, colour-coded (red / amber / green) with a qualitative description ("your store is in solid shape" vs "significant gaps"). Breakdown chips below surface the weakest dimensions first so the operator knows where one click gives the biggest lift.
* NEW: **Weekly progress trend.** A rolling 30-day snapshot of core coverage numbers is captured on every KG refresh (once per day, stored in `luwipress_kg_summary_history`). The hero subtitle shows the last-7-days delta — `+3.2% SEO, +1.5% enriched, -240 opportunity pts` — so operators see their work actually moving the needle.
* NEW: **Top stat cards are now drill filters.** Products / Posts / SEO Coverage / Enriched / Opportunities cards were purely decorative — now each one switches the graph view and applies the matching preset in one click (Opportunities → Top wins; SEO Coverage → Needs SEO; Enriched → Needs enrich; Products → show all products; Posts → show all posts). Visual pulse on click confirms the filter applied.
* IMPROVED: **Stat bar fits on 1366px screens.** The 10-card grid was overflowing on typical admin widths once Media Health was added; minimum column width dropped from 108→96px so every card renders in a single row at 1366+ and wraps gracefully on narrower screens.
* IMPROVED: `summary.trend` is now part of the standard KG payload — callers (remote dashboards, WebMCP agents) can consume the 7-day delta without a second call.

= 3.1.19 — Scheduler confirm modal + KG gamification tightening =
* UI: **Native `window.confirm()` dialogs replaced with a stylized confirm modal** throughout the scheduler (delete item, bulk delete/retry/publish, regenerate outline, delete recurring plan, run pending now). Variant-aware — destructive actions get a red icon + red primary button; info/warning get amber. Matches the rest of the scheduler's visual language.
* A11Y: **Focus trap in all three modals** (outline review, recurring plan, new confirm). Tab/Shift-Tab now cycles within the modal instead of leaking to the page behind. When modal closes, focus returns to whichever element originally opened it.
* UX: **Wizard step sidebar flashes red on validation error.** When you click Next on Step 1 with an empty topics textarea (or on Step 3 without a date), the offending step number in the sidebar briefly pulses with a red halo so you can see exactly where you got stuck — the field still highlights too, but the sidebar hint is the new signal.
* UX: Escape key now closes whichever modal is topmost (confirm > outline > plan) instead of all simultaneously — no more accidental data loss when you hit Esc on a confirm dialog stacked over an outline edit.
* UX: "Generate ideas" button in brainstorm panel is now i18n-wired (was hardcoded English in two spots). New keys added: `lbl_confirm`, `lbl_confirm_delete`, `lbl_cancel`, `lbl_generate_ideas`, plus confirm modal titles.
* NEW: **Knowledge Graph — Media Health card.** New clickable stat card surfaces the total image count, missing-alt-text count, orphaned (detached) files, and the top 5 largest files. Panel health bar tracks alt-text coverage; recommendations link straight to the Media library with the right filter applied. Huge for SEO + accessibility audits that were previously invisible.
* NEW: **Knowledge Graph — Primary (source) language node.** The source language used to be invisible — only translation targets showed up. Now the source language renders as a "Source language — all products originate here" node so the Language view / Taxonomy heatmap / stats count it. Every language node now ships native + English names (Français / Italiano / Español / …) instead of just the two-letter code.
* FIX: **Knowledge Graph — single-product enrich / translate actions now show live progress.** Previously the button said "Queued" then the panel refreshed after 8 seconds showing no change (AI takes 30-90s). The single-product path is now routed through the batch endpoint so the floating progress monitor shows up, polls every 3 seconds, and reopens the same detail panel with fresh chips / health bar / opportunity counter the moment the job finishes. Fixes the gamification loop — operators now see their work reflected immediately.
* FIX: **Knowledge Graph — `?` shortcut help is now a proper panel.** Replaced the browser `alert()` dialog with a styled panel listing every shortcut with `<kbd>` chips plus a tips section pointing to the clickable stat cards.
* FIX: **Knowledge Graph — cache badge no longer shows decimal milliseconds.** `fresh (272.7ms)` now reads `fresh (273ms)`.

= 3.1.18 — Scheduler UI polish (scroll + bulk bar + brainstorm button) =
* FIX: Phantom vertical scrollbar on the main tab strip — caused by `overflow-x: auto` allowing vertical overflow too. Locked to `overflow-y: hidden` and hid the horizontal scrollbar visually (still scrollable via touch/wheel).
* FIX: Bulk selection toolbar was staying visible at page load when no rows were selected. WordPress admin stylesheet leaks `display: flex` that was overriding the HTML `hidden` attribute. Added explicit `[hidden] { display: none !important }` scoped to the scheduler shell.
* UI: "Brainstorm with AI" button now has explicit styling (subtle primary-tinted border, matching icon color, proper height) instead of inheriting muddy WP admin button defaults. Hover gives a gentle primary wash.

= 3.1.17 — Content Scheduler UX refresh + CRM segment classifier fix =
* FIX: **Customer segments were collapsing into `one_time` on most stores.** The classifier had an override that forced every 1-order customer to `one_time` regardless of recency — which silently hid `active`, `at_risk`, `dormant`, and `lost` cohorts on stores where most customers only buy once (normal for high-ticket / niche commerce). A 1-order customer is now segmented on recency first: recent → `active`, 3-6 months → `at_risk`, 6-12 months → `dormant`. Only customers past the `dormant_days` threshold (default 365) get relabelled as `one_time` — so the bucket actually means "bought once, never came back" instead of hiding every recent 1-order customer. Re-run segments via the new refresh endpoint below to reclassify existing customers.
* NEW: **`POST /crm/refresh-segments` endpoint.** Manually trigger a full segment recompute + lifecycle-event regeneration without waiting for the weekly cron. Useful after threshold changes, data imports, or classifier fixes like this one. Returns the new counts + execution time. Requires admin / token.
* IMPROVED: `POST /translation/batch` now advertises its optional `post_ids` whitelist argument in the route's `args` block (the handler has accepted it since 3.1.6, but it was undocumented). Scopes a batch to specific posts — used by the Knowledge Graph category panel's "Translate this category to X" action.
* FIX: **Knowledge Graph category panel — Recommendations weren't clickable.** "Improve SEO Coverage" / "AI Enrich Products" / "Translate to X" cards rendered as inert info cards while a separate "Batch Actions" section duplicated the same operations as real buttons. Recommendations are now the action buttons — one click on "AI Enrich Products" queues the whole category. Deduped to one button per action/language so you don't see three cards that do the same thing.
* UI: **Content Scheduler rebuilt around a hybrid tab layout** — three main tabs at the top (Queue · Plans · Create new) with URL-hash state, replacing the long vertical scroll where wizard, queue, and recurring plans competed for attention. Each tab only shows what it needs; the page no longer feels "crowded."
* UI: **Create wizard switched to a left-sidebar step layout** with descriptive hints under each step label ("What to write about", "Voice, depth, language", "Dates & cadence", "Confirm & queue"). On mobile the sidebar collapses to a horizontal step strip. Progress bar sits under the sidebar, always visible.
* UI: **Queue list redesigned** — status sub-tabs (Pending / Generating / Outline review / Ready / Published / Failed) sit above a denser, cleaner row list. Each row gets a circular status badge, title with 2-line clamp (no more overflow), metadata chips (mode / type / lang / depth / tone / words / date), and action buttons that collapse to a bottom strip on mobile.
* UI: **Bulk toolbar** now stands out with a primary-color band across the list when selections exist ("N selected"), high-contrast action buttons, and a disabled state on "Publish selected" when no drafts are selected — so you always know what's actionable.
* UI: **Recurring plans got their own tab** with a proper empty-state CTA, cleaner card layout, inline pause/resume/edit/delete. No longer buried beneath the queue.
* UI: **Depth cards (Standard / Deep / Editorial) and publish-mode cards** now use visual selection (border highlight + checkmark badge) instead of radio buttons. Multilingual language chips also become visual pills that turn primary-color-filled when selected.
* UX: **All native `alert()` / `confirm()` popups replaced with toast notifications** (leveraging the existing `luwipress_toast` system) so the scheduler speaks with one voice and respects site branding. Validation errors now highlight the offending field with a red border + focus ring, not just a fading notice.
* UX: **Smart polling** — while topics are generating, the page used to full-reload every 15s and wipe your modal edits + scroll position. Now it polls every 20s, pauses when the tab is hidden, and pauses when any modal is open. No more losing your outline edit mid-review.
* UX: **Full i18n coverage** — every scheduler string now goes through the WordPress translation system (`luwipress.i18n` map with 60+ keys) so the plugin can be localized for Envato buyers outside English.
* UX: Accessibility pass — both modals now have matching ARIA attributes (`aria-labelledby`, `aria-modal`, labeled close buttons). Wizard step sidebar items are keyboard-focusable and respond to Enter/Space. Single consistent responsive breakpoint system (960px tablet, 600px mobile) replacing the five-value mix.

= 3.1.16 — CRM segment classifier fix + chat header contrast + taller widget =
* FIX: **Customer segments were collapsing into `one_time` on most stores.** The classifier had an override that forced every 1-order customer to `one_time` regardless of recency — which silently hid `active`, `at_risk`, `dormant`, and `lost` cohorts on stores where most customers only buy once (normal for high-ticket / niche commerce). A 1-order customer is now segmented on recency first: recent → `active`, 3-6 months → `at_risk`, 6-12 months → `dormant`. Only customers past the `dormant_days` threshold (default 365) get relabelled as `one_time` — so the bucket actually means "bought once, never came back" instead of hiding every recent 1-order customer. Re-run segments via the new refresh endpoint below to reclassify existing customers.
* NEW: **`POST /crm/refresh-segments` endpoint.** Manually trigger a full segment recompute + lifecycle-event regeneration without waiting for the weekly cron. Useful after threshold changes, data imports, or classifier fixes like this one. Returns the new counts + execution time. Requires admin / token.
* IMPROVED: `POST /translation/batch` now advertises its optional `post_ids` whitelist argument in the route's `args` block (the handler has accepted it since 3.1.6, but it was undocumented). Scopes a batch to specific posts — used by the Knowledge Graph category panel's "Translate this category to X" action.
* FIX: **Knowledge Graph category panel — Recommendations weren't clickable.** "Improve SEO Coverage" / "AI Enrich Products" / "Translate to X" cards rendered as inert info cards while a separate "Batch Actions" section duplicated the same operations as real buttons. Recommendations are now the action buttons — one click on "AI Enrich Products" queues the whole category. Deduped to one button per action/language so you don't see three cards that do the same thing.
* UI: WhatsApp icon button in the chat header now has a 2.5px white border and uses a white pulse ring instead of green. The plain green ring was invisible on red / dark-primary headers — the white halo now reads against any brand color. Button bumped 32→38px, inner WhatsApp glyph 16→18px. Hover adds a thicker white glow.
* UI: The "online" status dot next to the subtitle also gained a white outline + white pulse for the same contrast reason — it was fading into the red gradient.
* UI: Close button matched the WhatsApp button size (30→34px), glyph 18→20px, and the gap between them widened slightly.
* UI: Chat widget feels noticeably more generous — window width 360→390px, max-height 520→720px, body min-height 240→420px and max 340→540px. Earlier pass over-minimized the message area; this restores breathing room for 5–6 turns before scroll kicks in.

= 3.1.15 — Customer chat UI polish =
* UI: Removed the bottom "Prefer WhatsApp?" CTA bar — it crowded the chat area and duplicated intent. The WhatsApp escalation now lives solely as a compact icon-only circle in the header (32px, official brand green, breathing pulse) next to the close button. Minimal footprint, still obvious.
* UI: Chat window refresh — wider (360px), softer 16px corner radius, layered shadow. Header got a subtle gradient and a live "online" dot next to the subtitle so customers feel the assistant is present. Body background is a gentle two-stop off-white so message bubbles pop without fighting for attention.
* UI: Message bubbles — gradient on customer messages, tinted shadow matching the brand color, slightly larger radius (16px) with a 4px "tail" corner, and a crisp 1px border for assistant bubbles so white-on-white doesn't melt into the body. Font bumped to 13.5px, line-height tightened.
* UI: Input area — larger pill input (22px radius) with a soft focus ring instead of hard border, placeholder color softened, and the send button now lifts on hover with a brand-tinted shadow.
* UI: Toggle pill (the entry point before the window opens) also got the gradient + lifted shadow treatment.

= 3.1.14 — Content Scheduler Overhaul (Wizard + Draft-First + Outline Approval + Recurring) =
* NEW: **Content Scheduler wizard.** The Scheduler page is now a 4-step flow: Topics → Style → Schedule → Review. Forward/back navigation with a progress bar, step-jump for completed steps, inline validation. The previous "New Content" + "Bulk Queue" forms are consolidated — the wizard always uses the bulk endpoint and handles 1-50 topics uniformly.
* NEW: **Draft-first publish workflow.** Step 3 choice: "Save as draft for review" (new default) or "Auto-publish on schedule". Draft mode creates the WP post as a reviewable draft the moment AI generation completes (target publish date baked into `post_date`), with a primary "Review & publish" button on each queue row that jumps straight to the WP editor.
* NEW: **Two-phase outline approval for deep / editorial depth.** Enable "Review outline before writing" in Step 2 and deep/editorial articles enter a new `outline_pending` status: AI drafts a structured outline (title, hook, sections with bullet points, FAQ, closing approach), the editor opens a modal to add / remove / reorder / edit sections, and only then does Phase 2 write the full article — strictly following the approved outline. Regenerate / reject paths built in.
* NEW: **Brand voice card.** Site-level `luwipress_brand_voice_card` option plus a batch-level override textarea in Step 2. Brand voice layers on top of the depth rules (audience profile, forbidden openers, preferred opening style, cultural context, banned terms). Admins can promote a batch voice to the site default in one click. Custom system prompts can reference it via the `{brand_voice}` token.
* NEW: **AI topic brainstorm.** Button on Step 1 opens an inline panel — AI proposes specific publishable titles from a theme you supply, filtered against the last 30 post titles so no duplicates leak in. Brand-voice aware. Each suggestion returns a recommended depth tier; picks drop into the textarea with per-topic override syntax already applied.
* NEW: **Per-topic pipe overrides.** Inline syntax `Topic | keywords | depth=editorial | words=3000 | tone=creative | image=0 | lang=tr | type=post` lets individual rows override the batch defaults without leaving the textarea. Legacy `Topic | keyword` syntax preserved.
* NEW: **Budget preview in the Review step.** Live cost estimate using the real provider / model pricing tables (per-topic input + output tokens × batch size, plus optional image cost). Scales automatically with multilingual duplicate, so 12 topics × 4 languages shows the true 48-article cost.
* NEW: **Enrich draft.** One-click button on draft rows runs Internal Linker (resolves `[INTERNAL_LINK: anchor]` markers into real anchors) and AI taxonomy suggestion (picks from EXISTING categories/tags only — never invents new terms).
* NEW: **Bulk approve & publish toolbar.** Per-row checkboxes + select-all in the queue reveal a sticky toolbar: Publish selected (drafts only), Retry, Delete. 12 drafts → 1 click.
* NEW: **Queue filter tabs + Failed retry.** All / Pending / Generating / Outline review / Ready / Published / Failed — one-click scoping. Failed rows get a Retry button that clears the error, flips the row back to pending, and re-queues AI 30 seconds out. No more delete-and-re-add.
* NEW: **Multilingual duplicate.** If WPML or Polylang is active, Step 2 exposes chip selectors for the site's active languages. Each picked language gets its own natively-written article (not a machine translation) linked to the primary via the translation plugin's API. A single 12-topic batch can become 48 linked articles across 4 languages without extra setup.
* NEW: **Recurring plans.** New admin card: define a theme + cadence (daily / weekly / biweekly / monthly) + post count + depth + language + publish mode, and LuwiPress auto-brainstorms and queues fresh topics on your schedule. Budget-aware (defers when the daily cap hits), pause / resume / edit / delete per plan. The editorial calendar fills itself.
* NEW: **6 new AJAX endpoints** power the overhaul: `retry_schedule`, `estimate_batch_cost`, `enrich_schedule_draft`, `bulk_schedule_action`, `get_outline` / `save_outline` / `regenerate_outline`, `brainstorm_topics`, `save_recurring_plan` / `delete_recurring_plan` / `toggle_recurring_plan`.
* IMPROVED: Status model now includes `outline_pending` alongside `pending`, `generating`, `ready`, `published`, `failed`. Status cards + queue filters updated to match.
* IMPROVED: Scheduler queue row now shows a mode badge (DRAFT / AUTO), depth badge, language, tone, target words, and the scheduled publish date — all at a glance.
* FIX: Customer chat occasionally answered "I don't have specific information about our products" even for simple queries ("what do you sell?", "im looking for caglama"). Root cause was a chain of edge cases: the Knowledge Graph transient cache could store an empty snapshot (e.g. if the handler errored during warm-up) and `false !== $data` happily returned that empty array for a full hour; KG product nodes also weren't matched by slug, so a customer typing the slug ("caglama") missed products whose displayed titles used localised forms ("Çağlama Box — Kutu Saz"). `get_kg_data()` now treats cached-but-empty as a miss, a new `synthesize_fallback_snapshot()` builds a minimal KG-shaped blob directly from WP/WC (top 20 categories + 20 recent products) whenever the KG handler returns nothing, `search_products_from_kg()` now also scores against product slugs (weight 2), and the system prompt explicitly forbids the "no info" cop-out — if nothing matches, the AI must surface 3–5 relevant categories with a one-liner pitch each.

= 3.1.13 — Knowledge Graph gamification + Customer Chat WhatsApp CTA =
* NEW: **Customer chat WhatsApp CTA.** The ambiguous phone-icon button in the chat header has been replaced with a branded green "WhatsApp" pill (icon + label + subtle pulse). A persistent CTA bar sits above the text input: "Prefer WhatsApp? Talk to our team directly." with a proper green WhatsApp brand button. Conversion-focused: the shortest path to a human conversation is always visible, not discoverable. Gracefully hides when no WhatsApp number is configured.
* NEW: **Auto zoom-fit on the Knowledge Graph** once the force simulation settles. Previously, tabs with few nodes (Customers: 8 segments) or widely-dispersed nodes would leave half the graph off-screen until the user panned — now everything fits the viewport automatically. The zoom-fit button also now fits to node bounds instead of resetting to identity.
* NEW: **Every KG recommendation is clickable.** Posts, Pages, and Customer Segments previously rendered recommendations as inert `<div>` elements — visually shown but not actionable, breaking the "click to improve" gamification loop. Now each rec is either an AI action (translate, enrich), an editor link (opens the WP post/page editor in a new tab), or a concrete action (CSV export for segment outreach).
* NEW: **Segment CSV export.** Click "Launch win-back campaign" / "Send onboarding sequence" / "Re-engagement email" on any customer segment and the full cohort (customer_id, name, email, order_count, total_spent, last_order, days_since_last_order, segment) downloads as a BOM-prefixed UTF-8 CSV — ready to paste into Mailchimp, Klaviyo, FluentCRM, or any email tool. LuwiPress never writes to third-party CRM plugins — this is the operator's handoff point.
* NEW: **Preset counts on the dropdown.** Each preset option now shows how many nodes it matches in parentheses — "Needs SEO (80)", "Not enriched (102)", "Thin content (3)", "Translation backlog (40)", "High opportunity (100)" — so operators can triage at a glance without committing to a filter.
* FIX: Preset/Export dropdowns on the Knowledge Graph admin page actually close now when a peer opens. Root cause was CSS, not JS — the `.kg-dropdown-menu { display: flex }` rule has the same specificity as the user-agent's `[hidden] { display: none }`, so flex won the cascade and kept the menu visible even after JS set `hidden=true`. Added `.kg-dropdown-menu[hidden] { display: none }` (specificity 0,2,0) so the hide intent is honored.
* IMPROVED: Stats bar sized down — nine metric cards now fit on a single row at standard admin widths instead of wrapping to a second row. Graph canvas sits directly below without being pushed below the fold.
* IMPROVED: Global KG search now covers Pages and Customer Segments alongside Products / Posts / Categories.
* IMPROVED: Hover tooltips extended to Post / Page / Customer-Segment nodes — Post shows word count + author + SEO status; Page shows role + content length + template; Segment shows customer count + share %.

= 3.1.12 — Knowledge Graph gamification loop =
* NEW: **Auto zoom-fit** once the force simulation settles. Previously, tabs with few nodes (Customers: 8 segments) or widely-dispersed nodes would leave half the graph off-screen until the user panned — now everything fits the viewport automatically. The zoom-fit button also now fits to node bounds instead of resetting to identity, which is far more useful.
* NEW: **Every recommendation is clickable.** Posts, Pages, and Customer Segments previously rendered recommendations as inert `<div>` elements — visually shown but not actionable, breaking the "click to improve" gamification loop. Now each rec is either an AI action (translate, enrich), an editor link (opens the WP post/page editor in a new tab for SEO meta / featured image / content expansion), or a concrete action (CSV export for segment outreach). Post "Add SEO Meta / Featured Image / Expand Content / Refresh Content", Page "Expand content / Orphan page", and all Segment recommendations now drive the user somewhere.
* NEW: **Segment CSV export.** Click "Launch win-back campaign" / "Send onboarding sequence" / "Re-engagement email" on any customer segment and the full cohort (customer_id, name, email, order_count, total_spent, last_order, days_since_last_order, segment) downloads as a BOM-prefixed UTF-8 CSV — ready to paste into Mailchimp, Klaviyo, FluentCRM, or any email tool. LuwiPress never writes to third-party CRM plugins — this is the operator's handoff point.
* NEW: **Preset counts on the dropdown.** Each preset option now shows how many nodes it matches in parentheses — "Needs SEO (80)", "Not enriched (102)", "Thin content (3)", "Translation backlog (40)", "High opportunity (100)", "All items (185)" — so operators can triage at a glance without committing to a filter.

= 3.1.11 — Knowledge Graph polish =
* FIX: Preset/Export dropdowns on the Knowledge Graph admin page actually close now when a peer opens. Root cause was CSS, not JS — the `.kg-dropdown-menu { display: flex }` rule has the same specificity as the user-agent's `[hidden] { display: none }`, so flex won the cascade and kept the menu visible even after JS set `hidden=true`. Added `.kg-dropdown-menu[hidden] { display: none }` (specificity 0,2,0) so the hide intent is honored.
* IMPROVED: Stats bar sized down — nine metric cards now fit on a single row at standard admin widths instead of wrapping to a second row (min card width 140→108px, padding/font tightened). Graph canvas sits directly below without being pushed below the fold.
* IMPROVED: Global search now covers Pages and Customer Segments alongside Products / Posts / Categories. Typing in the header search pulls up any content type with its role (Homepage, Shop, Blog, Top-level) or cohort (VIP, at-risk, etc.).
* IMPROVED: Hover tooltips extended to Post / Page / Customer-Segment nodes — Post shows word count + author + SEO status; Page shows role + content length + template; Segment shows customer count + share %.

= 3.1.10 — Content Depth Presets + Dropdown Hotfix =
* NEW: Content Scheduler "Content depth" preset on the bulk queue form — three levels:
  - **Standard**: balanced SEO article (800-1500 words, clear structure).
  - **Deep**: long-form explainer with research framing, examples, citations, counter-arguments, "Key takeaways", and a 3-5 question FAQ (1500-3000 words).
  - **Editorial**: essay-style with strong voice, cultural/historical context, narrative arc, original perspective, quote-worthy sentences, memorable closing line (2000-3500+ words).
  `max_tokens` scales automatically (4096 → 6000 → 8000).
* NEW: Rewritten default content system prompt — stricter anti-filler rules ("no 'in today's fast-paced world' openings"), concrete-over-vague mandate, internal link placeholder syntax, no-markdown-fence JSON output.
* NEW: Operator-level custom system prompt support — set `luwipress_content_system_prompt` option to override the default entirely. Variable substitution available: `{topic}`, `{language}`, `{tone}`, `{word_count}`, `{keywords}`, `{site_name}`, `{depth}`.
* FIX: Knowledge Graph dropdowns still occasionally stayed open in pairs — strengthened the sibling-close logic to walk `.kg-dropdown` roots (not just menus), flip both `menu.hidden` and the sibling's `aria-expanded` in one pass.

= 3.1.9 — Content Scheduler Bulk Queue + KG Dropdown Fix =
* NEW: Content Scheduler → "Bulk Queue" section. Paste up to 50 topics (one per line) with optional `| keywords` pipe syntax on each line. Set a start date, a publish spacing (e.g. "1 day" or "6 hours"), an AI stagger interval (default: 5 minutes between generation runs so you don't burst your AI budget), shared tone / word count / language / post type, and the whole batch is queued in one click. Each topic becomes an individual schedule row with its own `wp_schedule_single_event` worker, so AI calls are spread out automatically. A "Run N pending now" button lets operators kick the queue forward without waiting for wp-cron.
* NEW: Budget-aware deferral — if the daily AI budget is exhausted when a scheduled generation fires, the task automatically reschedules itself for 1 hour later instead of failing. Operators can raise the cap and the queue picks up from where it left off.
* NEW: `luwipress_generate_single` cron hook — single-item AI generation handler reused by the bulk queue and available for any third-party code that wants to queue content from its own context.
* FIX: Knowledge Graph Preset/Export dropdowns — when one dropdown was open and the other was clicked, the first one wouldn't close. `e.stopPropagation()` was blocking the document-level outside-click listener used by sibling dropdowns. Now only one dropdown is ever open; a new opener explicitly closes its peers.

= 3.1.8 — Dead Code Removal (n8n residue cleanup) =
* CLEANUP: Removed the `LuwiPress_AI_Engine::MODE_N8N` constant and the `forward_to_n8n()` stub entirely. These had been deprecated since 2.0.1 (n8n integration removed, all AI handled natively) but were kept as stubs for backward compatibility. They were unreachable in practice — `get_mode()` always returned `'local'` — so removing them has no runtime effect.
* CLEANUP: Five dead `if ( MODE_N8N === $mode )` branches in AEO generation, single-product enrichment, batch enrichment, per-post translation, and taxonomy translation call sites are gone. Each had lived alongside the real local-AI path since 2.0.1.
* CLEANUP: `LuwiPress_AI_Engine::get_mode()` and the `MODE_LOCAL` constant also removed — no callers left in the core plugin.
* CLEANUP: `luwipress_processing_mode` option is no longer written on save or read on load. `luwipress_seo_webhook_url` no longer surfaces in `/site-config` responses. The old Translation Manager "Webhook" engine badge was dropped — the badge now always reads "Local AI".
* CLEANUP: `LuwiPress_AI_Content::$webhook_url` and `$webhook_api_token` properties (only ever written, never read) removed.
* NO BREAKING CHANGE for end users: the affected code paths were already dead. If a third-party integration was still calling `forward_to_n8n()` or `get_mode()` directly, it would have been receiving a deprecation error / `'local'` constant anyway. File a ticket if you hit unexpected fatal errors — there's an easy shim to add back.

= 3.1.7 — Batch Monitor, CSV Round-trip, Layout Memory =
* NEW: Enrichment batch monitor — when you queue "Enrich all products in category" (or any `/product/enrich-batch` call), a floating progress panel appears in the bottom-right corner with a live striped progress bar, elapsed time, and per-status chips (queued / running / done / failed). Polls `/product/enrich-batch/status` every 3 seconds until complete, then refreshes the graph automatically.
* NEW: CSV round-trip for SEO meta — the Export dropdown now has an "Upload CSV → apply SEO meta" option. Export the "CSV — Opportunity list" or "CSV — Missing SEO" file, edit it offline (Excel, Sheets, any tool), then re-upload. The frontend parses the CSV (flexible column detection: `ID` / `post_id`, `SEO Title` / `Meta Title` / `Title`, `Meta Desc` / `Description`, `Focus Keyword`), previews the count, and dispatches the batch to the new `POST /seo/meta-bulk` endpoint. Up to 500 rows per request. Writes go through the detected SEO plugin (Rank Math / Yoast / AIOSEO / SEOPress) exactly as the single-row `/seo/meta` endpoint does.
* NEW: Knowledge Graph layout memory — when you drag a node to a preferred position, the position is pinned and saved to localStorage per view (Products / Posts / Pages / Customers). Reopen the graph and your layout is where you left it. A new reset button (↺) in the zoom controls clears the saved layout for the current view and re-flows the simulation.
* IMPROVED: Design Health stat card now reads "N/A" instead of "0%" on sites without Elementor installed. The card becomes non-interactive (no click-through, no hover cue) when the metric doesn't apply. Server-side `design_audit.elementor_available` flag surfaces this cleanly.
* IMPROVED: `/knowledge-graph` endpoint description updated to list `design_audit` as a valid section (it was always accepted by the handler, but the args description was missing it).

= 3.1.6 — Knowledge Graph Asset Split (performance) =
* IMPROVED: Knowledge Graph admin page is now served as a separate `assets/js/knowledge-graph.js` (~93 KB) + `assets/css/knowledge-graph.css` (~26 KB) bundle. These load only on the Knowledge Graph page, not on every LuwiPress admin screen. Page templates drop from 2,466 lines to 174 lines; `admin.css` drops 1,000+ lines (~22% smaller) for every non-KG admin page load. Config (REST URL, API token, nonce) is injected via `wp_localize_script` as `window.lpKgConfig` — no more inline PHP-generated JSON.
* NO BREAKING CHANGE: no functional or behaviour change — refactor only. The KG page behaves exactly like 3.1.5.

= 3.1.5 — Customers & Elementor Audit Drill-down =
* NEW: Knowledge Graph fourth view — **Customers**. Shows eight customer segments (VIP, Loyal, Active, New, One-Time, At Risk, Dormant, Lost) as color-coded nodes sized by cohort count. Each segment has its own detail panel with segment definition, priority action, and targeted recommendations (e.g. win-back campaign for One-Time buyers, reactivation offer for Dormant, VIP perk program for VIPs). Keyboard shortcut `4` added for quick switch.
* NEW: Design Audit drill-down — click any page row in the Design Health panel to open a dedicated Elementor audit view for that page. Issues are grouped by severity (critical/warning/info) and then by issue type, with affected element IDs listed as code chips. "Open in Elementor" and "View live" buttons jump you straight to the editor or the rendered page.
* IMPROVED: Design Health panel's per-page results are now scannable summaries (severity counts instead of full issue dumps). The deep list lives in the drill-down where you can act on it.
* IMPROVED: Knowledge Graph fetch now includes the `crm` section so customer segment data is available without a second request.
* IMPROVED: Keyboard shortcut help (`?` dialog) updated to include the fourth view.

= 3.1.4 — Knowledge Graph Overhaul =
* NEW: Knowledge Graph search — typeahead over products, posts, and categories. Keyboard: `/` to focus, `↑↓` to navigate, Enter to select. Selected node auto-zooms and opens its detail panel.
* NEW: Knowledge Graph presets — filter dropdown with six built-in views: All items, Needs SEO meta, Not enriched, Thin content, Translation backlog, High opportunity (score > 30). Active preset shown as a badge.
* NEW: Knowledge Graph export — four formats: CSV opportunity list (opportunity_score desc), CSV missing SEO meta (with Edit URL column), JSON raw graph, PNG viewport snapshot. All with UTF-8 BOM for spreadsheet compatibility.
* NEW: Pages view — third tab in the graph; parent-child hierarchy with `child_of` edges. Homepage, shop, blog, top-level, and child pages get distinct colours and sizes. Page detail panel shows role badge, template, content length, and recommendations (thin content, orphan pages).
* NEW: Order Analytics stat card — click to open a Revenue panel with today/7-day/30-day revenue snapshot, AOV, 12-month SVG sparkline, customer retention health bar, top 5 sellers, inventory status, payment method breakdown, and refunds (last 90 days).
* NEW: Plugin Health stat card — readiness score plus per-category detected plugins (SEO, translation, email, CRM, cache, support) and prioritised recommendations.
* NEW: Taxonomy Coverage heatmap — click the new stat card to see a matrix of taxonomy type × language with colour-coded coverage (≥95% green, 70-94% orange, 40-69% dark, <40% red). Missing terms listed per language with a "Translate all" button that dispatches one request per taxonomy type.
* NEW: Category batch actions — from any category detail panel, queue every product in that category for enrichment or translation to a specific language. Frontend collects product IDs from the cached graph; backend `/translation/batch` now accepts an optional `post_ids` whitelist parameter.
* NEW: Cache badge in the graph header — shows whether the current data was served from cache (`cached`) or freshly computed (`fresh (Nms)`). The Refresh button bypasses cache; normal page loads now hit the cache reliably.
* NEW: Keyboard shortcuts — `/` search, `r` refresh, `1/2/3` view switch (Products/Posts/Pages), `Esc` close detail panel, `?` show shortcut help.
* IMPROVED: Knowledge Graph force simulation now adapts to node count (alphaDecay, collision padding, charge strength) so large catalogues (1000+ products) converge in a few seconds instead of 10+.
* IMPROVED: Cache invalidation is now triggered when LuwiPress or SEO-plugin meta keys change, not only on `save_post`. Async AI enrichment writes no longer leave stale graph data.
* IMPROVED: `GET /knowledge-graph` accepts both `section` and `sections` parameters. Previous admin requests silently loaded all 20 sections because of a parameter mismatch.
* FIX: Recommendation action buttons on the Knowledge Graph detail panel were silently failing — the refresh callback referenced functions that lived in an inner scope. The action buttons (Enrich, FAQ, HowTo, Translate) now execute reliably and the panel re-opens with fresh data.
* FIX: WebMCP companion status on Settings → Connection tab now reflects the companion's own default (`enabled` when the option has not been persisted yet). Previously the admin UI showed "Disabled" even though the MCP endpoint was live.
* FIX: Dashboard header "Open Claw AI" button replaced with Knowledge Graph shortcut — the Open Claw page was split out in 3.1.0 and the old button led nowhere. OpenAI-Compatible provider now recognised by the AI-key detector and provider label map, so 3.1.2 users no longer see "No AI key" when only that provider is configured. Plugin pills deduplicated across Google Ads / Meta Ads / Product Feed categories — a single plugin covering two categories (e.g. Google Listings & Ads) now shows once.
* CLEANUP: Legacy `n8nPress` / `n8n workflow` references removed from all user-facing surfaces (admin UI, plugin metadata, LICENSE, readme). Internal identifiers also renamed (`$n8n_webhook_url` → `$webhook_url`, `check_n8n_token()` → `check_api_token()`). No breaking change — `MODE_N8N` and `forward_to_n8n()` kept as deprecated stubs for backward compatibility.

= 3.1.3 — Primary Language Dropdown Uses Detected Languages =
* IMPROVED: Settings → AI Content → Primary Language now reads the active languages from WPML, Polylang, or TranslatePress when one is installed, instead of the old 11-language hardcoded list. You can only pick a language your site actually serves, removing a foot-gun where Tapadum-style sites had `tr` selected while the site ran on EN+FR+IT+ES.
* NEW: Badge above the dropdown shows which translation plugin was detected and how many languages it exposes. Default language from the translation plugin is surfaced as a hint so the admin knows what the source-of-truth is.
* NEW: Save handler validates the submitted language against the detected active list; an invalid submission snaps to the translation plugin's default language instead of silently persisting a mismatched value.
* NEW: When a previously-saved language is no longer active (e.g. site disabled a language in WPML), a warning line appears inline on the dropdown so admins can fix it explicitly.
* FALLBACK: If no translation plugin is present, the original list (11 common locales) still ships as the fallback. No breaking change for single-language sites.

= 3.1.2 — Multi-Provider Expansion (OpenAI-Compatible) =
* NEW: Fourth AI provider slot — **OpenAI-Compatible** — a single provider class that talks to any vendor exposing OpenAI's `/chat/completions` schema. Ships with presets for **DeepSeek**, **Moonshot Kimi**, **Groq**, and **Together.ai**, plus a **Custom** preset for self-hosted LLMs (Ollama, vLLM, LM Studio, text-generation-webui).
* NEW: Settings → AI Content now exposes the OpenAI-Compatible card with preset dropdown, API key, optional custom base URL, and model selector. Switching preset updates the base URL and model list instantly. Off-peak discount hints (e.g. DeepSeek's ~50% discount window 16:30–00:30 UTC) are shown under the model picker.
* IMPROVED: Fallback chain extended to include `openai-compatible` — if the primary provider fails transiently (429, 5xx, network, 404-on-model), the engine now considers the OpenAI-Compatible provider too.
* IMPROVED: `/site-config` response masks the OpenAI-Compatible API key with the same last-4-char hint pattern introduced in 3.1.1. No breaking change to other secret fields.
* NO BREAKING CHANGE: Existing OpenAI, Anthropic, and Google configurations work unchanged. Provider interface is unchanged — third-party code calling `LuwiPress_AI_Engine::dispatch()` needs no updates. Adding a new OpenAI-compatible vendor in the future is a one-line preset addition, no new class required.

= 3.1.1 — Security: Secret Masking in Site Config =
* SECURITY: `GET /site-config` no longer returns the LuwiPress API token or the active AI provider's API key in clear text. The response now exposes `api_token_configured` / `api_key_configured` booleans plus `*_hint` fields showing only the last 4 characters, which is sufficient to verify configuration without leaking the full secret. Any existing tokens/keys that may have been surfaced to third parties (automation logs, MCP transcripts, remote clients) should be rotated.
* NO BREAKING CHANGE to other fields — `site`, `woocommerce`, `plugins`, and the non-secret `luwipress.*` fields are unchanged. Consumers that previously read `luwipress.api_token` or `luwipress.ai.api_key` must switch to the `_configured` boolean; the full secret was never intended to be consumed by external clients.

= 3.1.0 — Open Claw Companion Split =
* BREAKING: The Open Claw admin AI assistant has moved to a separate companion plugin (`luwipress-open-claw`). Sites that use Open Claw must install the companion to keep the LuwiPress → Open Claw menu working. Conversation history and all settings are preserved.
* NEW: Migration notice — admin users on sites without the companion see a dismissable banner pointing them to the plugin.
* NEW: Core dropped ~26 KB / 500 LOC. Core is now focused on WooCommerce AI automation; admin chat is opt-in via companion.
* CLEANUP: Settings tab "Open Claw" removed (was already reduced to a info card pointing at the admin page — now that page moves too).
* UI: Settings → Advertising tab removed (all Google Ads / Meta Ads option fields were dead — no code ever consumed them).
* UI: Settings → CRM info message sadeleşti; FluentCRM/Mailchimp mention removed.
* UI: Open Claw admin sidebar no longer references Telegram/WhatsApp channel status (those channels were removed in the 2.0.0 slim rewrite).

= 3.0.0 — Companion Plugin Split (breaking) =
* BREAKING: WebMCP has moved to a separate companion plugin (`luwipress-webmcp`). Stores that relied on the MCP endpoint must install the companion to keep AI-agent integration working. `luwipress_webmcp_enabled` + `luwipress_webmcp_allowed_origins` options are preserved across the split.
* NEW: Core plugin dropped ~211 KB / 4,172 LOC. Core is now focused on WooCommerce AI automation; MCP is opt-in via companion.
* NEW: One-time migration notice — admin users on sites that had WebMCP enabled see a dismissable banner pointing them to the companion plugin.
* NEW: `translation_batch` MCP tool (mirrors `POST /translation/batch`) and 4 module-settings MCP tool pairs (`enrich_settings_*`, `translation_settings_*`, `chat_settings_*`, `schedule_settings_*`). Ships with the companion.
* NEW: `cache_purge` MCP tool (mirrors `POST /cache/purge`). Ships with the companion.
* REFACTOR: Module settings methods unified across modules — `AI_Content`, `Translation`, `Customer_Chat`, `Content_Scheduler` all expose `handle_get_settings()` and `handle_set_settings()` now. Makes future automation uniform.

= 2.1.0 — SEO Writer Fallback & Batch Translation =
* NEW: Standalone SEO writer fallback — when no third-party SEO plugin (Rank Math, Yoast, AIOSEO, SEOPress) is detected, LuwiPress now writes its own `_luwipress_seo_title`/`_description`/`_focus_keyword` meta and outputs `<title>` + `<meta name="description">` in `wp_head`. Removes the hidden hard-requirement on a third-party SEO plugin for stores that want LuwiPress to own their SEO metadata end-to-end.
* MERGE: Hreflang module folded into the SEO writer (`class-luwipress-hreflang.php` removed; logic moved to `class-luwipress-seo-writer.php`). Single responsibility, same behaviour — mode `auto`/`always`/`never` preserved.
* NEW: `POST /translation/batch` — translate N untranslated posts for one or more target languages in a single call. Fixes the dead "Translate N missing products" button on language nodes in the Knowledge Graph.

= 2.0.11 — Plugin Slimming =
* SLIM: Theme Manager + Demo Import modules removed from plugin (63 KB / 2,082 lines / 5+ endpoints). Theme setup now lives in the themes themselves.
* SLIM: CRM Bridge — FluentCRM/Mailchimp write paths removed. Segmentation and lifecycle events are now pure-WooCommerce; no third-party CRM plugins are written to, the plugin only reports on its own WooCommerce-derived data.
* NEW: Settings REST pattern — `GET/POST /translation/settings`, `/chat/settings`, `/schedule/settings` (partial-update, admin auth), mirroring the existing `/enrich/settings`. Remote automation (n8n, Claude Code, companion plugins) can now manage module configuration without SSH/WP-CLI.

= 2.0.10 — Translation & Enrichment Hardening =
* NEW: Enrichment prompt customization — Settings → AI tab now exposes a custom system prompt textarea with variable substitution (`{product_title}`, `{category}`, `{focus_keyword}`, `{price}`, `{currency}`, `{site_name}`, `{target_language}`) for store-specific output structure
* NEW: Enrichment constraints — configurable target word count, meta title max (40–80), meta description max (120–200), and optional CTA sentence auto-appended to every meta description
* NEW: `/product/enrich` `options` now accepts `custom_instructions`, `target_words`, `meta_title_max`, `meta_desc_max`, `focus_keyword` for per-request overrides
* NEW: `GET /enrich/settings` and `POST /enrich/settings` — read/write the enrichment prompt and constraints remotely (admin auth; `POST` is partial-update, only keys present in the body are touched)
* NEW: Callback-side meta trimming — AI-generated meta title/description are trimmed to configured limits (word-boundary aware) before being saved to the active SEO plugin
* FIX: Translation — when AI JSON response fails to parse, translation is now rejected and marked failed instead of writing raw payload text into post_content (prevents corrupted product descriptions)
* FIX: Translation — added `source_language` parameter to `/translation/request` so a clean sibling language can be used as source when the default-language copy is corrupted
* FIX: Translation — self-retranslate path: when the post being retranslated is already in the target language, content is now rewritten in place via `wp_update_post` + `clean_post_cache` instead of silently skipped
* NEW: Enrich — WPML language guard on `/product/enrich`, `/product/enrich-batch`, and enrich-callback endpoints. Enrichment writes default-language content; writes to translation copies are now rejected with `language_mismatch` (409). Set `allow_translation_target=true` in the callback body to override.
* NEW: Batch enrich now auto-skips translation copies with a warning log entry instead of queueing them
* NEW: `LuwiPress_Translation::get_post_wpml_language()` helper — centralised handling of WPML language detection (array/object/null response shapes)
* IMPROVED: Translation log line now includes source language override + resolved source post id for traceability

= 2.0.9 — Theme Library & Smart Setup =
* NEW: Theme Library — multi-theme catalog with install/activate/setup from plugin
* NEW: Theme Manager engine — remote ZIP install via Theme_Upgrader, one-click activate
* NEW: 4-step setup wizard — Requirements → Install → Activate → Setup Store
* NEW: 3 themes in catalog — Luwi Elementor (available), Luwi Minimal & Boutique (coming soon)
* NEW: Smart setup — WC store info auto-fills Contact page (email, phone, address)
* NEW: Color presets picker — Burnished Gold, Forest Green, Deep Navy, Dusty Rose
* NEW: Full production safety — snapshot before changes, one-click rollback to previous state
* NEW: Health check after setup — verifies site responds with HTTP 200
* NEW: WooCommerce page verification — Shop/Cart/Checkout/MyAccount auto-created if missing
* NEW: Checkpoint system — setup progress saved, resumable on failure
* NEW: Blog/Journal starter page added (7 pages total)
* NEW: Cleanup — trash starter pages + reset homepage/blog in one click
* IMPROVED: Demo content — Luwi palette, Playfair Display headings, Luwi widgets
* IMPROVED: Elementor guard — import disabled when Elementor not active
* IMPROVED: Confirmation dialogs before theme activation and page import

= 2.0.8 — Marketplace Integration =
* NEW: Multi-marketplace product publishing (Amazon, eBay, Trendyol, Hepsiburada, N11, Alibaba, Etsy, Walmart)
* NEW: Marketplace adapter pattern — interface-based, each marketplace is a separate adapter class
* NEW: REST API endpoints — publish, publish-batch, status, overview, test, categories (luwipress/v1/marketplace/*)
* NEW: Marketplace listings DB table with sync status tracking
* NEW: Settings tab — search-enabled grid UI with per-marketplace credentials and enable toggle
* NEW: Product data mapping — WooCommerce fields automatically mapped to each marketplace's format
* NEW: Design-system-aligned minimal card UI with autocomplete search, status badges (Live/Ready/Off)

= 2.0.6 — Rebrand Cleanup =
* REBRAND: All CSS variables renamed --n8n-* → --lp-*
* REBRAND: All HTML classes renamed n8np-* → lp-*
* REBRAND: JS functions and WebMCP client class renamed
* REBRAND: MCP resource URIs changed n8npress:// → luwipress://
* REBRAND: readme.txt rewritten — standalone AI plugin description
* REBRAND: Removed all n8n references from user-facing UI
* IMPROVED: Coverage calculation — only counts real translations linked to EN sources
* IMPROVED: Fix Orphans tool detects EN-registered non-English posts
* IMPROVED: MCP content tools bypass WPML language filter

= 2.0.7 — Customer Chat Widget + WhatsApp/Telegram Escalation =
* NEW: AI-powered customer chat widget (frontend, vanilla JS, <15KB)
* NEW: RAG pipeline — FAQ short-circuit, product search via Knowledge Graph, store policy injection
* NEW: Intent classification — product inquiry, shipping, returns, order status, stock check, escalation
* NEW: WhatsApp/Telegram escalation via deep links — customer connects directly with team
* NEW: Channel choice dialog when both WhatsApp and Telegram are configured
* NEW: Customer Chat settings tab — greeting, colors, position, policies, budget, rate limiting
* NEW: Separate daily AI budget for customer chat (independent from admin budget)
* NEW: Rate limiting per visitor (30 messages/hour default, configurable)
* NEW: Custom DB tables for conversations and messages (not wp_options)
* NEW: GDPR compliance — auto-cleanup after 90 days, delete customer data endpoint
* NEW: Session persistence via localStorage — conversation survives page navigation
* NEW: Order status lookup for logged-in WooCommerce customers
* NEW: Auto-escalation suggestion after configurable message count

= 2.0.5 — Full Site X-Ray & WordPress Control =
* NEW: Knowledge Graph — 8 new sections: posts, pages, taxonomies, media inventory, menus, product attributes, authors, order analytics
* NEW: 44 MCP tools for full WordPress control — users, orders, coupons, media, comments, settings, plugins, themes, menus, taxonomies, custom fields
* NEW: Blog post nodes with SEO status, categories, tags, author, staleness detection
* NEW: Page hierarchy nodes with template detection, parent/child relationships
* NEW: Media inventory — missing alt text count, orphaned media, file size analysis
* NEW: Navigation menu structure with full item hierarchy
* NEW: WooCommerce order analytics — 12-month revenue trend, repeat customer rate, payment methods, refund tracking
* NEW: Author nodes with per-type content counts and activity tracking
* NEW: Product attribute nodes with term listing
* NEW: Content taxonomy discovery — all registered taxonomies with top terms
* FIX: MCP translation_missing tool parameter mismatch (language → target_language)
* FIX: MCP translation_taxonomy_missing tool parameter mismatch (language → target_languages)
* IMPROVED: Cache invalidation broadened to save_post, delete_post, created_term, delete_term
* IMPROVED: Settings tools use whitelist — siteurl, home, admin_email blocked for security

= 2.0.4 — Translation Pipeline Hardening =
* SECURITY: SQL injection fix — parameterized IN() placeholders in wpdb->prepare()
* SECURITY: XSS fix — esc_html() on translated titles in AJAX responses
* SECURITY: Race condition fix — transient lock on WPML translation creation
* SECURITY: Nonce field added to Translation Manager form
* FIX: Fatal error — replaced new WP_Post() with get_post()
* FIX: Slug generation — translated posts now get sanitized slugs from translated title
* FIX: Excerpt extraction — blog listing excerpts from first text-editor widget
* FIX: WPML element_type dynamic (was hardcoded post_product for pages/posts)
* FIX: WooCommerce meta copy guarded to product post_type only
* FIX: Category taxonomy dynamic per post_type (was hardcoded product_cat)
* FIX: Cron exception handling — try-catch prevents stuck "translating" status
* NEW: Auto-snapshot before Elementor page translation (rollback support)
* NEW: WP Revision integration — pre-translation revision saved, auto-revert on failure
* NEW: Translation progress polling — AJAX endpoint for background job status
* NEW: JS poller in Translation Manager for Elementor background jobs
* NEW: Per-term taxonomy translation with live progress bar
* NEW: "Re-translate Broken" maintenance tool — fixes empty title / numeric slug posts
* IMPROVED: REST API accepts post_id param (backward compat product_id)
* IMPROVED: Response key items (backward compat products)

= 2.0.3 — Elementor Chunked Translation & KG Redesign =
* NEW: Elementor page translation with chunked AI (background WP Cron)
* NEW: ElementsKit heading widget support (ekit_heading_title, ekit_heading_extra_title)
* NEW: Knowledge Graph redesign — product health bar, category/language panels, hover tooltips
* FIX: Translation pipeline source language from WPML (was using target language option)
* FIX: Taxonomy translation source_language_code fix
* FIX: Grey overlay backdrop removed from KG sidebar panel

= 2.0.2 — Translation Manager & Elementor Tools =
* NEW: Translation Manager UI — step-based dashboard with coverage tracking
* NEW: 20+ Elementor WebMCP tools — translate, style, bulk-update, snapshot, rollback
* NEW: Elementor page outline endpoint for AI agents

= 2.0.1 — Standalone AI Engine =
* NEW: Multi-provider AI Engine (OpenAI, Anthropic, Google)
* NEW: Job Queue with WP Cron for async processing
* NEW: Token Tracker with budget enforcement
* REMOVED: n8n dependency — all AI calls are now native

= 2.0.0 — LuwiPress Rebrand =
* REBRAND: n8nPress → LuwiPress
* NEW: Standalone plugin architecture (no external dependencies)
* NEW: Plugin Detector — auto-detects SEO, Translation, SMTP, CRM plugins

= 1.10.0 — WebMCP: MCP Server for AI Agents =
* NEW: MCP Streamable HTTP server at /luwipress/v1/mcp (spec 2025-03-26)
* NEW: 40+ MCP tools across 14 categories (content, SEO, AEO, translation, CRM, email, etc.)
* NEW: MCP resources — site-config, health, AEO coverage + parameterized templates (post, seo-meta, translation-status)
* NEW: MCP tool annotations — readOnlyHint, destructiveHint, idempotentHint, openWorldHint
* NEW: MCP session management with UUID sessions and 1-hour TTL
* NEW: Origin validation (DNS rebinding protection per MCP spec)
* NEW: Cursor-based pagination for tools/list
* NEW: Argument autocompletion for resource templates (completion/complete)
* NEW: Knowledge Graph endpoint — AI-consumable entity graph with WPML-aware translation status
* NEW: WebMCP admin page with tool catalog, connection tester, and usage examples
* NEW: Browser-side MCP client class (webmcp-client.js)
* NEW: Extensibility hook — luwipress_webmcp_register_tools for third-party tools
* NEW: WEBMCP-INTEGRATION.md documentation for AI agent tool discovery
* IMPROVED: Site config permission now accepts logged-in admins (cookie + nonce auth)

= 1.9.0 — Security & Code Quality =
* SECURITY: JWT secret now uses random_bytes(32) instead of wp_generate_password (C2)
* SECURITY: Token revocation mechanism — revoke compromised JWT tokens by jti (C3)
* SECURITY: Image sideload validates MIME type (jpeg/png/webp/gif) and max size 10MB (C4)
* SECURITY: Admin pages check current_user_can('manage_options') on direct access (H4)
* SECURITY: Enrichment uses transient lock to prevent race conditions (H6)
* SECURITY: HMAC secret uses cryptographically secure random_bytes (C2)
* FIX: Meta copy uses whitelist (WC product fields only) instead of blacklist (H2)
* FIX: Daily auto-cleanup cron for logs, tokens, workflow stats (H3)
* FIX: Translation Manager taxonomy count excludes orphan WPML records
* FIX: Knowledge Graph default language uses get_locale() instead of hardcoded 'tr' (M5)

= 1.8.4 =
* FIX: Knowledge Graph now uses WPML icl_translations for translation status (was using meta only)
* FIX: Taxonomy translation WPML registration uses SQL fallback for REST context
* FIX: Taxonomy callback validates term existence before saving
* FIX: Removed orphan term fallback that caused false positives in taxonomy counts
* NEW: Test suite for translation system (tests/test-translation-system.php)

= 1.8.3 =
* NEW: AI image generation for products and posts (DALL-E 3, DALL-E 2, Gemini Imagen 3)
* NEW: Image provider setting in AI Content tab (with per-image pricing display)
* NEW: Gemini 2.5 Flash and Gemini 2.5 Pro model options
* NEW: Image provider and AI provider sent in _meta block to n8n workflows
* IMPROVED: Enrich callback auto-sideloads generated images as featured image
* IMPROVED: Token tracker includes image generation pricing

= 1.8.2 =
* FIX: Post Categories and Post Tags now appear in Translation Manager (WPML fallback for unregistered taxonomies)
* FIX: Taxonomy term count excludes WPML translation copies (was double-counting)
* FIX: Auto-register unregistered taxonomy terms in WPML before translation
* FIX: Knowledge Graph plugins section loads product data for SEO coverage calculation
* IMPROVED: AIOSEO and SEOPress focus keyword meta key support added
* IMPROVED: Translation Manager Bulk Translation section removed (redundant with per-language buttons)
* NEW: Fix Category Assignments maintenance button (re-assigns translated products to correct categories)

= 1.8.1 =
* NEW: Knowledge Graph endpoint (GET /knowledge-graph) — AI-consumable graph of products, categories, languages, SEO/AEO coverage, and opportunity scores
* FIX: SEO meta writing now uses Plugin Detector (was hardcoded Yoast+RankMath — AIOSEO/SEOPress now work)
* FIX: Polylang translation create — new posts are now created with full WC meta, images, taxonomies, SEO meta (was update-only)
* FIX: FAQPage JSON-LD schema now output in wp_head (was missing — Google can now see FAQ data)
* FIX: Cache invalidation added to all callbacks (WP Rocket, LiteSpeed, W3 Total Cache)
* FIX: Translation SEO meta reading now uses Plugin Detector (was hardcoded keys)

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

LuwiPress is developed by **Luwi Developments LLC** — a boutique AI agency working with founders, startups, and creative teams. We specialize in custom AI workflows, process automation, and intelligent systems.

*Dream. Design. Develop.*

= Free Plugin, Professional Support =

LuwiPress is free and open-source. You bring your own AI API key — no subscription, no hidden fees, no vendor lock-in, no external services in the loop.

Need help getting started? We offer:

* **Free:** Plugin + documentation
* **Setup Service:** We configure the AI engine, migrate existing SEO/translation data, and get your store automations running
* **Custom Automations:** Tailored AI pipelines for your specific business needs
* **Ongoing Support:** Monitoring, optimization, and new automation development

= Resources =

* [Documentation & Guides](https://luwi.dev)
* [GitHub Repository](https://github.com/umutsun/luwipress)

= Contact =

* **Email:** hello@luwi.dev
* **Web:** [luwi.dev](https://luwi.dev)
* **Services:** Custom AI automations, WooCommerce optimization, store intelligence
