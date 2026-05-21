=== LuwiPress WebMCP ===
Contributors: luwidev
Tags: mcp, ai, automation, claude, anthropic, woocommerce, rest-api
Requires at least: 5.6
Tested up to: 7.0
Requires PHP: 7.4
Stable tag: 1.0.29
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

= 1.0.29 — tools/list page-size 200 → 500 (Vendor-FR-006/FR-007 root cause) =
* **FIX `handle_tools_list()` page size 200 → 500.** When the WebMCP catalog grew past 200 tools in 1.0.28 (added `search_products`, `search_reindex`, `search_stats`, `taxonomy_meta_set`, `taxonomy_meta_delete`, `webmcp_deploy_audit`), the last ~12 tools landed on cursor page 2. Several popular clients (notably `mcp-remote` and certain Claude Desktop builds) don't follow `nextCursor` reliably — they take the first page and stop, leaving the page-2 tools invisible. This is the actual root cause behind the Tapadum Vendor-FR-006/FR-007 reports: `webmcp_deploy_audit` showed the tools registered server-side, but operator-side `tool_search` couldn't find them because the client never paginated past 200. CANLI-vs-KLON asymmetry explained too — CANLI has fewer active modules so its catalog is under 200, fits in page 1, while KLON's full 212-tool catalog overflowed.
* New page_size accommodates catalog growth to ~500 tools before pagination matters again. The cursor mechanism is preserved (still emits `nextCursor` when the catalog exceeds 500) — clients that DO paginate keep working.

= 1.0.28 — Standalone "Lite mode" — runs without LuwiPress Core (WP.org submission path) =
* **NEW Lite mode**: WebMCP now activates and runs without the LuwiPress Core plugin. The `Requires Plugins: luwipress` header is dropped; the activation hook no longer `wp_die()`s. When Core is absent the companion registers only the WordPress-native tool categories (system / plugins / themes / menus / taxonomies / comments / media + WooCommerce and Elementor when those plugins are present) — roughly 50–70 tools depending on the host site. The 13 AI-pipeline categories (content / seo / aeo / translation / crm / knowledge_graph / scheduler / token / review / linker / search / attribution / etc.) silently skip via the existing `class_exists()` gates inside each category's register method, and the `webmcp_deploy_audit` tool reports exactly which classes are missing so the operator can see whether they want Core for the unlocked categories.
* **NEW Permission shim**: when `LuwiPress_Permission` isn't defined (Core absent), `class-luwipress-permission-shim.php` loads a drop-in with the exact same static surface (`is_admin` / `check_token` / `check_token_or_admin` / `require_token` / `is_token_authenticated`). Reads the same option name Core uses (`luwipress_seo_api_token`) when present, falling back to a WebMCP-owned option (`luwipress_webmcp_api_token`) on pure-Lite installs. Operators who move between Lite ↔ Core keep their existing token.
* **NEW Lite-mode top-level menu**: when Core is absent, WebMCP creates its own `dashicons-rest-api` top-level menu ("LuwiPress MCP", position 68 — just below "Plugins"). When Core is present the companion still attaches as a submenu of the LuwiPress parent, exactly as before.
* **NEW Lite-mode banner** on the WebMCP admin page: explains the standalone surface and links to luwi.dev/luwipress for the full ~210-tool catalog. Non-alarming, dismissible-style design.
* **REPLACED** the old "WebMCP is paused" Plugins-screen warning with a friendlier "Lite mode active" info notice that names what IS available rather than what isn't.
* Net effect: WebMCP is now eligible for direct WordPress.org distribution as a standalone MCP server — the previous hard dependency made .org submission impossible (Core isn't on .org so users couldn't install the dependency through the directory). Existing Pro / paired installs see zero behaviour change.

= 1.0.27 — Deploy integrity audit surface (Vendor-FR-006/007 closure) =
* **NEW `webmcp_deploy_audit`**: read-only MCP tool that surfaces the per-category tool-registration outcome. Returns `{webmcp_version, core_version, total_tools, categories: {<name>: {method, added, skipped, gate_class?, gate_class_exists?}}, missing_classes: [...]}`. When an expected tool is missing from the catalog (e.g. `taxonomy_meta_set`, `search_products`), this is the first thing to call — a `skipped: true, gate_class_exists: false` entry confirms a partial ZIP deploy (main plugin file at the new version but a gated module's class file is stale or missing). Closes the diagnosis gap Vendor reported in FR-006 + FR-007 where silent `class_exists()` skips left operators with no server-side log access wondering whether the catalog was cached, version-mismatched, or genuinely missing.
* **NEW boot warning**: `register_all_tools()` now logs a single `warning` via `LuwiPress_Logger` when any class-gated category registers 0 tools because its required class is unavailable. Survives ahead of operator-driven `webmcp_deploy_audit` calls so deploy issues surface in the LuwiPress activity log immediately.
* **Refactor**: `register_all_tools()` rewritten to iterate a category → method map so each registration block is wrapped in a count diff. Execution order preserved exactly — no observable change for healthy deploys.

= 1.0.26 — Bot Shield comment review tools + WordPress 7.0 Abilities API alignment (paired with core 3.3.0) =
* **NEW `bot_shield_comments_recent`**: list the last 100 bot-suspect comment events caught by core 3.3.0's Bot Shield comment review layer. Returns score, action taken (moderated/spam/rejected), the matched signals (link density / spam tokens / author shape / duplicate / IP already blocked / URL-only body), and a 240-char snippet. IPs are masked (last octet zeroed) for GDPR.
* **NEW `bot_shield_comments_test`**: dry-run a comment payload against the current scorer. Returns `{verdict, score, threshold, signals, mode}`. Verdicts: allow / moderate / spam / reject. Tune thresholds + spam-token lists without risking live submissions.
* **COMPAT WP 7.0**: paired release with core LuwiPress 3.3.0. WebMCP HTTP endpoint stays as the primary surface for headless / agentic clients (Open Claw, Hermes, direct MCP HTTP); core's Abilities bridge (`LuwiPress_Abilities`) mirrors the same tool registry into the native WP 7.0 Abilities API so WP-native consumers (Claude Code, Cursor, Automattic's official MCP Adapter plugin) discover the catalog without a separate auth layer.
* **NEW admin info-box**: when WP 7.0 Abilities API is detected on the site, the WebMCP admin page surfaces a "Dual-registry" panel — operator sees at a glance whether the official MCP Adapter is active and whether LuwiPress tools are mirrored through both surfaces. `Tested up to: 7.0`.

= 1.0.25 — Parallel redirect audit + KG Action Queue surface + multi-post-type reindex =
* **slug_resolver_redirect_audit timeout fix (Hub-BUG-010)**: handler refactored to round-based `curl_multi_init` parallel batching. Each round fires up to `batch_size` (default 20, max 50) pending URLs concurrently; URLs that 3xx queue their next hop for the following round. Wall-time for the Tapadum 216-URL menu sweep drops from ~4 minutes (MCP timeout) to ~10-20 seconds. New params `parallel` (default true) and `batch_size` (default 20) expose tuning; set `parallel: false` to force the legacy sequential `wp_remote_get` loop when debugging transport issues or when curl is unavailable. Response `stats` now carries `mode` (parallel/sequential), `batch_size`, `rounds`, and `elapsed_ms` for audit trail.
* **NEW `kg_candidates`**: Action Queue v2 candidate list. Returns ROI-ranked opportunities derived from KG signals — RECENTLY_REGRESSED, STALE_ENRICHED, MISSING_FAQ, MISSING_HOWTO, MISSING_SCHEMA, plus theme-companion candidates injected via `luwipress_kg_action_queue_external_candidates`. Each item carries impact, effort_min, roi, tier, confidence_score (0-100), and a `why` block with primary/supporting signals + baseline comparison. Distinct from `content_opportunities` (legacy static 5-category sweep): use that for missing_seo_meta / missing_translations / stale_content / thin_content / missing_alt_text; use `kg_candidates` for signal-driven Action Queue feed.
* **NEW `kg_events`**: KG event stream (raw rows) or per-window aggregate summary. Event types: enrich, seo, translate, schema_added. Pass `summary=true` + `hours=N` for count-by-type aggregate. Use to verify the signal layer is actually recording activity — if event counts are zero, the v2 candidate types will be empty regardless of generator state.
* **search_reindex** accepts a `post_types` argument (e.g. `["product","post","page"]`) that overrides the indexable set AND persists it as the new default option. Pairs with core 3.2.10's multi-post-type BM25 surface to enable chat RAG over blog and page content (IMP-002).
* **search_products** description clarifies the multi-post-type result shape: each row carries `post_type`; non-product rows have empty `price`/`stock`/`sku`.
* Requires core LuwiPress 3.2.10+ for the multi-post-type index extension.

= 1.0.24 — Bulk SEO meta + Customer Chat session/history MCP tools + linker_resolve clarity =
* **NEW `seo_meta_bulk`**: wrap of `POST /seo/meta-bulk` (server-cap 500 rows/request). Bulk-write SEO meta (title, description, focus keyword) across up to 500 posts in a single call. Per-row partial-update: missing fields leave existing values untouched. Returns `{ applied, skipped, error_rows[], total }`. Closes a Tapadum silent-regression gap — the REST endpoint had shipped since 3.1.7 but never had an MCP wrapper.
* **NEW Customer Chat surface (3 tools, FR-003)**:
  * `chat_sessions_list` — paginated session listing with filters (status, escalated_only, customer_email partial, since-datetime). Each row carries session_id, customer_*, status, escalated_to, page_url, ip_address, timestamps, message_count.
  * `chat_session_get(session_id)` — full transcript (up to 50 messages, oldest-first) with status + escalation state.
  * `chat_messages_search(query)` — plain-LIKE content search with snippet centring (≤240 chars around match) + role + since filters. For chat-tone reviews, pain-point analysis, and brand-voice audits across translated chat traffic.
* **`linker_resolve` description honesty**: catalog description rewritten to "Process the pre-computed internal-linking backlog for a single post" — the tool is a backlog processor, NOT an on-demand AI call. Returns `{ resolved, remaining }`. Empty-backlog responses are `{resolved:0, remaining:0}`, expected. Pairs with `linker_unresolved` to see what's waiting. Behaviour unchanged.
* Requires core LuwiPress 3.2.9+ for the chat surface (REST endpoints land in core).

= 1.0.23 — WPML/Polylang post-side sibling resolver =
* **NEW `translation_post_siblings`**: post-side counterpart to `taxonomy_term_get`'s sibling resolver. Pass any sibling post/page/product ID (source or translation) and receive the full `{lang: post_id}` pair map — works under both WPML and Polylang. Returns `plugin`, `input_lang`, `source_lang`, `default_lang`, and (under WPML) the `trid` for diagnostics. Closes a Tapadum-reported gap (vendor-FR §6.18): cross-language SEO sweeps, retranslation drift cleanup, and any audit that needs DB-level pair resolution no longer require XML exports + fuzzy slug matching. The `taxonomy_term_get` pattern, applied to posts.
* **`translation_status` description clarified**: catalog description now reads "Site-wide translation queue aggregate" — the misleading `post_id` parameter (declared in schema but silently ignored by the handler) has been removed. For per-post sibling resolution use `translation_post_siblings`. Behavior unchanged; contract honest.

= 1.0.22 — Read-before-write taxonomy term + woo_list_orders polish =
* **NEW `taxonomy_term_get`**: read-before-write companion to `taxonomy_update_term`. Returns the full core fields (`name`, `slug`, `description`, `parent`, `count`, `link`) of a single term by ID. Optional `lang` parameter resolves to the WPML/Polylang sibling in that language before reading. Closes a Tapadum-reported gap (A2) — translation-term descriptions were unreadable from MCP, breaking the "read current description before rewriting" discipline.
* **`wpml_term_translation_get`** now includes `description` in each sibling entry — same read-gap fix at the bulk-translation-listing level.
* **`woo_list_orders`** — `date_before` parameter now actually filters the query (was declared in the schema but ignored by the builder, forcing full-table scans on wide ranges). Added `orderby` (date / modified / id / title / menu_order / rand) and `order` (ASC / DESC) parameters so callers can sort without post-processing. Closes Tapadum feedback gaps C3 (BUG-002) and C5 (BUG-004).

= 1.0.21 — Cookie Consent + Bot Shield MCP tools (12 tools) =
* **NEW Cookie Consent tools (5)** — `cookie_consent_settings_get`, `cookie_consent_settings_set` (partial-update), `cookie_consent_stats` (per-category accept-rate), `cookie_consent_log` (paginated consent records), `cookie_consent_policy_generate` (AI-generates a site-specific cookie policy paragraph using the LuwiPress Plugin Detector's detected tags as context).
* **NEW Bot Shield tools (7)** — `bot_shield_stats`, `bot_shield_settings_get`, `bot_shield_settings_set`, `bot_shield_blocks_list`, `bot_shield_block` (manual block; `destructiveHint: true`), `bot_shield_unblock`, `bot_shield_test` (dry-run a UA/IP/path triple against the current rule set without firing — verdict: allow|deny + reason).
* Requires core LuwiPress 3.2.0+.

= 1.0.20 — Bot Account Cleaner MCP tools (7 tools) =
* **NEW (`bot_account_scan`)**: trigger a fresh bot-account scan; scores every subscriber/customer user across the signal stack and writes rows into `wp_luwipress_bot_account_scores`. Returns aggregate counts {scanned, flagged, protected, threshold}.
* **NEW (`bot_account_list`)**: paginated list of flagged accounts (score ≥ threshold), each row carrying user_id, score, signals → weight map, status, login, email, display_name, registration date, roles. Optional `min_score` override to query at a different cutoff.
* **NEW (`bot_account_score`)**: compute (without persisting) the bot-likelihood score for a single user id. Returns {score 0-100, signals map, protected bool, reason?}. Useful for spot-checking why a specific user was/was-not flagged before bulk action.
* **NEW (`bot_account_delete`)**: bulk delete with hard-coded safety. Dry-run by default; pass `confirm=true` to actually execute. Server re-scores each user inline and refuses any protected account (admin/editor/shop_manager role, whitelisted, has WC orders) regardless of caller intent. Carries `destructiveHint: true` so MCP clients can warn before invocation.
* **NEW (`bot_account_whitelist`)**: add or remove a user from the permanent whitelist (action: add|remove). Whitelisted users are hidden from scans and blocked from deletion via this surface.
* **NEW (`bot_account_stats`)**: aggregate dashboard payload — counts by status, flagged total, threshold, score-bucket histogram (high 80+, medium 60-79, low 40-59, noise <40), whitelist size, last-scan summary.
* **NEW (`bot_account_settings_set`)**: partial-update scanner config — threshold (0-100), min_age_days (grace period), scan_batch_size (50-5000), allowed_roles array. Protected roles + WC-order guard are safety invariants and not operator-tweakable.
* Requires core LuwiPress 3.1.60+.

= 1.0.19 — taxonomy_update_term WPML translation-term fix (Vendor-BUG-004) =
* **FIX (`taxonomy_update_term`)**: WPML translation terms could not be updated — even a description-only call returned `The slug "<x>" is already in use by another term`. Root cause: `wp_update_term()` always re-runs `wp_unique_term_slug()`, which is not WPML-language-aware and falsely flags the sibling-language slug as a collision. Fix is two-layer: (a) description-only updates now bypass `wp_update_term` entirely via direct `$wpdb->update()` on `wp_term_taxonomy` + `clean_term_cache()` + `edited_term` action (the common SEO-description sweep path); (b) full updates (name/slug/parent) set WPML language context via `$sitepress->switch_lang($term_lang)` before `wp_update_term()` and restore the previous language after, so the slug-uniqueness check scopes to the term's own language siblings. Response now includes a `method` field (`direct_description_write` | `wp_update_term`). Reported by Tapadum Hub session 102 during the Clay Darbuka category SEO pilot — closes the 156 translation-term description backlog blocker.

= 1.0.18 — Redirect audit + WPML term-translation lookup + WPML-aware menu_add_item =
* **NEW (`slug_resolver_redirect_audit`)**: One-shot redirect-chain sweeper. Scrapes every link out of one or more nav menus (or arbitrary URLs), follows redirects up to `max_hops` with redirects disabled at each step, decodes any `X-LWP-SR:` trace headers, and surfaces issues: redirect_loop / 404 / multi_hop_chain / server_error / transport_error. Replaces hand-rolled audit scripts per migration. Use before any DNS swap or after editing the slug-resolver map.
* **NEW (`wpml_term_translation_get`)**: Read-only WPML/Polylang sibling-term discovery. Returns every language's `term_id` + slug + name + count + archive link for the source term. Closes the "what is the ES equivalent of EN term 99" loop without admin UI.
* **FIX (`menu_add_item`)**: Now accepts a `lang` arg and sets WPML language context (`wpml_switch_language` → insert → `wpml_set_element_language_details`) so the new nav_menu_item is attached to the right language. Previous behaviour created an orphan post that wasn't visible in the target menu. Result also includes an `attached` boolean — if false the response includes a `hint` pointing the operator at the cause.

= 1.0.17 — Slug Resolver MCP tools (5 tools) =
* **NEW (`slug_resolver_diag`)**: Runtime diagnostic snapshot (toggle, hook attachment, map size, WPML/Polylang detect, sample slug probes). Use for cross-customer migration troubleshooting without server log access.
* **NEW (`slug_resolver_map`)**: Full slug→target redirect map (auto + overrides + composed) for audit before flipping enable.
* **NEW (`slug_resolver_force_rebuild`)**: Bust transient + re-run six discovery passes.
* **NEW (`slug_resolver_override_set`)**: Explicit operator override — slug → term_id | URL | true | false | null.
* **NEW (`slug_resolver_settings_set`)**: Site-wide enable/disable toggle.
* **REQUIRES**: LuwiPress core 3.1.56+.

= 1.0.16 — Translation Sync Audit MCP tools =
* **NEW (`translation_sync_audit`)**: Unified cross-language sync audit. Detects drift / outdated / structural_gap / schema_parity findings under one call. Read-only.
* **NEW (`translation_sync_fix`)**: Execute the appropriate fix action for one or more finding_ids returned by audit. Server re-resolves the finding server-side (does not trust client fix_args) and routes to force-retranslate / sync-structure / copy-schema as appropriate. Async by default.
* **NEW (`translation_sync_settings`, `translation_sync_settings_set`)**: Read/write the drift threshold, hourly sweep toggle, and autofix toggle for the new wp_cron sweep.
* **REQUIRES**: LuwiPress core 3.1.54+ (the `LuwiPress_Translation_Sync` orchestrator class).

= 1.0.15 — Taxonomy term meta tools =
* **NEW (`taxonomy_meta_get`)**: Read term meta on any registered taxonomy term (e.g. Rank Math SEO meta on `product_cat` archives, `post_tag`, or `pa_*` attribute taxonomies). Parallels `meta_get` for posts. Returns all public meta when `key` is omitted; returns a single value when `key` is set.
* **NEW (`taxonomy_meta_set`)**: Write a term meta value via `update_term_meta()`. Keys containing `description` or `_desc` go through `wp_kses_post`; everything else through `sanitize_text_field`. WPML: each language variant of a term has its own `term_id` — call once per language with the appropriate ID.
* **NEW (`taxonomy_meta_delete`)**: Delete a term meta key. Symmetric to `meta_delete` for posts.
* **USE CASE**: Batch Rank Math title / description / focus_keyword across category archives (TASK-K-002: 52 product_cat archives across 4 languages × 13 categories — previously required WP Admin manual entry, now scriptable).

= 1.0.14 — content_update_post + seo_write_meta bug fixes =
* **FIX (`content_update_post`)**: `post_excerpt` now uses `wp_kses_post()` instead of `sanitize_text_field()`. Excerpts written through the tool no longer have HTML tags (e.g. `<ul>`, `<li>`, `<strong>`) stripped. Discovered during 2026-05-11 Tapadum migration when WC product excerpts with bullet-list formatting collapsed into flat paragraphs on the target site.
* **FIX (`seo_write_meta`)**: Partial-update semantics now respected — only the Rank Math meta fields explicitly passed in the call are forwarded to the handler. Previously, missing args were coerced to `''` which caused the handler to clear existing values on the target post (e.g. a caller that sent only `meta_title` + `meta_description` ended up wiping `focus_keyword`). Same migration session surfaced this.
* **NOTE**: No schema or surface changes; both fixes are internal to the MCP tool wrappers. Existing callers that explicitly send all three SEO fields are unaffected.

= 1.0.13 — Translation language drift detection + force-retranslate MCP tools =
* **NEW (`translation_language_drift`)**: Detect translated posts whose body content is still in the source language — the silent failure mode that makes existence-based coverage report 100% even when blogs are broken English. Read-only scan; returns flagged posts with target-language score. Pairs with core 3.1.49+.
* **NEW (`translation_force_retranslate`)**: Bypass missing-only gating. Pass an explicit list of source-language post IDs + target languages to overwrite. Clears the Elementor "already-translated" guard meta and re-fires the AI pipeline. Async wp_cron path automatic for batches > 5 work units.

= 1.0.12 — Theme Tools framework MCP surface =
* **NEW (`theme_tools_list`)**: List every maintenance tool the active companion theme has registered with the LuwiPress Theme Bridge (paired with core 3.1.48+). Returns tool id, label, category, capability, wpml-aware flag, destructive flag, available actions.
* **NEW (`theme_tool_run`)**: Run a registered tool with action="scan" (read-only) or "execute" (mutating). Auto-expands WPML/Polylang siblings on execute when the tool is `wpml_aware:true`. Returns the tool's native output: candidates list (scan) or mutated count + backup_id (execute).
* **NEW (`theme_tool_restore`)**: Restore from a backup taken by a previous theme_tool_run execute. Replays the captured pre-mutation payload. Backups pruned to the last 20 per site.
* **NEW (`theme_tool_backups`)**: List backups for a tool (or all tools when tool_id omitted). Each entry has id, timestamp, post IDs.
* **NEW (`theme_settings_get`)**: Read every theme_mod proxy registered via the Theme Bridge — id, theme_mod key, label, type, default, current value, group.
* **NEW (`theme_settings_set`)**: Update a bridged theme setting by id (NOT the raw theme_mod key — use theme_customizer_set for that). Bridge validates type, clamps numeric ranges, rejects unknown ids.


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
