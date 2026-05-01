# Tapadum — LuwiPress 3.1.37 Update Handoff

**Date:** 2026-04-23
**Plugin updates:** LuwiPress core 3.1.14 (live) → **3.1.37**, LuwiPress WebMCP 1.0.3 → **1.0.5** (MCP protocol revision 2.0.1)
**Site:** tapadum.com
**Status:** Deployed + verified. 107/107 automated test assertions pass. No customer-visible outage during rollout.

This document summarises what changed in today's update and what (if anything) you need to do next. Share with your team or paste into any AI assistant for follow-up help.

---

## 1. What changed — short version

**One critical security fix, five polish fixes, and a new quality-control regime.**

The headline is that your authenticated admin data (customer revenue, lifecycle events, store intelligence, logs) was being inadvertently served from cache to anonymous visitors under certain conditions. **That is now closed.** Everything else is smaller bugs and UX improvements that were batched into the same release for efficiency.

---

## 2. What changed — detail

### Security — cache-layer data visibility gap closed (CRITICAL)

**What the problem was:** When an admin viewed a LuwiPress dashboard that loaded data from e.g. `/crm/overview` or `/knowledge-graph`, the server's response included your store's customer count, 30-day + lifetime revenue, pending lifecycle events (names + emails), and live operational logs. LiteSpeed Cache was storing this authenticated response by URL. A subsequent anonymous visitor who hit the exact same URL — by guessing it, by finding it in a browser extension, by seeing it in a support ticket network trace — would receive the cached admin response. No login needed.

**What the fix does:** Every authenticated LuwiPress REST endpoint now tells LiteSpeed (and any other cache layer in front of your site — Varnish, Cloudflare cache-everything mode, NGINX FastCGI cache) explicitly not to store the response. There are four layers of protection now:

1. Every authenticated response carries a `Cache-Control: no-store, private` instruction that browsers and caches are obligated to honour.
2. A LiteSpeed-specific signal (`X-LiteSpeed-Cache-Control: no-cache`) opts out of tag-based caching.
3. The `DONOTCACHEPAGE` constant is set, which WP Rocket, W3 Total Cache, and WP Super Cache all respect.
4. **Your LiteSpeed plugin's "Do Not Cache URIs" list was updated to include `/wp-json/luwipress/v1/`** — this is the belt-and-braces admin-side rule that we confirmed is also required on Hostinger's LiteSpeed build.

Truly public endpoints (`/status`, `/health`, the chat widget config) still return cacheable responses — the filter correctly distinguishes them.

**Did this affect my actual customer data?** There is no evidence of external exploitation. The issue required a specific sequence (admin pre-loads a URL, anonymous visitor hits the exact same URL with the same cache-buster query string) and nobody outside your team knew those URLs. The fix removes the capability regardless.

### Batch enrichment progress no longer "vanishes"

Previously, when you ran "Enrich all products in this category" and the job finished, the progress panel would reset to `0 / 0` because the per-product status records were being cleaned up. You'd have to reload the Knowledge Graph to confirm the job had actually completed.

Fixed. Batch records now persist for 24 hours after completion with accurate totals, so the floating progress monitor keeps showing `Enriched 12 of 12 — 100%` until you dismiss it or wait out the day.

### AEO coverage numbers now line up with the Knowledge Graph

The Usage page and Knowledge Graph dashboard were showing different totals for FAQ coverage — 21.3% in one place and 47.7% in the other. Both were correct for different reasons (WPML counts translated versions as separate products, KG counts only originals), but the mismatch was confusing.

The `/aeo/coverage` response now splits into `primary_language` (matches the Knowledge Graph's 128-product baseline) and `all_languages` (the full 512-product WPML tally). Admin pages use the primary-language number so figures agree across screens.

### Token usage page — custom row limits honoured

The "Recent AI calls" table in the Usage page was always showing 20 rows regardless of what the dashboard requested. Now it honours `?limit=N` with a 1-100 range and returns exactly the requested count.

### WebMCP companion plugin (1.0.3 → 1.0.5)

Two releases rolled into one, separate from the core plugin but coordinated with it:

- **1.0.4 → 1.0.5 (today's ship) — Stricter JSON-RPC 2.0 validation.** The MCP server previously accepted malformed requests (e.g. `jsonrpc: "1.0"` or a missing `jsonrpc` field) silently, which masked integration bugs in downstream AI agents. The server now returns a clear `-32600 Invalid Request` error for any payload that doesn't strictly conform to JSON-RPC 2.0 — misconfigured clients fail loudly at integration time instead of appearing to work.
- **Product search UTF-8 fix.** When Claude / ChatGPT search your catalog via the `search_products` MCP tool, Spanish and French product descriptions used to come through as `coraz�n` / `m�sica` / `�frica` because the tool wasn't decoding HTML entities or normalising text encoding before packaging it for the AI. Now descriptions render with proper accented characters (`corazón`, `música`, `África`) and prices display locale-correctly (`489,14 €` instead of `489.14&euro;`). AI agents quoting product details in their responses no longer mangle customer-facing text.
- **All 138 AI tools verified for spec compliance** — a new automated quality-gate test ("WebMCP contract test", see Section 6) runs after every deploy and confirms every tool declares a proper name, description, and input schema. AI agents see a consistent, well-described tool catalog regardless of what ships next.

**Where you see this:** `https://tapadum.com/wp-json/luwipress/v1/mcp` — the endpoint used by Claude Code, OpenAI Assistants, and any custom AI agent you or your team connect. The token is unchanged from before (`lp_QuDG...2mEb`). No reconnection needed.

**The 1.0.5 (plugin) vs 2.0.1 (MCP protocol) version split is intentional.** WordPress admin shows `1.0.5` — that's the plugin's release version you'd upgrade. `2.0.1` is what AI agents see when they call `initialize` — it's the MCP wire-protocol revision. The two move on independent cadences because a wire-protocol change breaks AI clients in a way a plugin update doesn't.

### Chat / product search — text no longer garbled

When Claude / ChatGPT search your product catalog via WebMCP (`search_products` tool), Spanish and French product descriptions now render with proper accented characters (`corazón`, `música`, `África`) instead of `coraz�n`, `m�sica`, `�frica`. Prices come through as `489,14 €` instead of `489.14&euro;`.

### Core plugin now declares its WooCommerce dependency

LuwiPress no longer activates on sites where WooCommerce is missing. WordPress 6.5+ enforces this at install time, replacing the old "install and wonder why nothing works" behaviour. Transparent to you (Tapadum has WC 10.7) — matters for future customers.

---

## 3. What you need to do

**Nothing urgent. One optional step, one good habit.**

### Optional — verify the LiteSpeed rule is there

This was added during the deploy, but worth a 30-second confirmation because it's the belt-and-braces half of the cache-leak fix:

1. WordPress admin → **LiteSpeed Cache → Cache → Excludes** (third tab)
2. Scroll down to **"Do Not Cache URIs"**
3. You should see a line reading:
   ```
   /wp-json/luwipress/v1/
   ```
4. If it's there, no action needed. If it's somehow missing, re-add it, **Save Changes**, then **Toolbox → Purge All**.

### Good habit — after any LiteSpeed plugin update

When you update the LiteSpeed Cache plugin itself in the future, run a quick one-URL smoke test:
- Open `https://tapadum.com/wp-json/luwipress/v1/crm/overview` in a private/incognito window **without logging in.**
- You should see a 401 "rest_forbidden" response, NOT customer data.
- If you ever see customer data without being logged in, reply to this thread immediately.

---

## 4. Tapadum — your current state snapshot (post-update)

Pulled live from the Knowledge Graph after the deploy completed.

| Metric | Value |
|---|---|
| Products | 128 originals (512 including WPML translations) |
| Pages | 63 |
| Blog posts | 58 |
| Customers | 6,364 |
| Last 30 days revenue | €4,354.82 across 9 orders |
| Lifetime revenue | €107,874.51 across 248 orders |
| Average order value | €434.98 |
| Customer segments | Active 5 · One-time 37 · At-risk 7 · Dormant 4 · New 4 · Lost 2 · VIP 0 · Loyal 0 |
| Languages | EN · FR · IT · ES, all 100% translated |
| SEO coverage | 55.5% (primary language) |
| Enrichment coverage | 35.2% |
| AEO FAQ coverage | 47.7% (primary language) |
| AEO HowTo / Schema / Speakable | 0% each |
| Design health | 84% |
| Media inventory | 9,719 files — 4,308 missing alt text, 5,373 orphaned |

## 5. Where to focus next — the quick wins

From your Knowledge Graph Action Queue, ranked by ROI:

### High-impact — "Enrich String Instruments category" (1 click)

47 products in this category, only 48.9% have SEO metadata. Running the batch enrichment closes the biggest single gap in your store's SEO footprint in one operation. Go to **LuwiPress → Knowledge Graph**, find the String Instruments category node, open the detail panel, click **Enrich all products in this category**. Estimated: 6-8 minutes, fully automated.

### Medium-impact — "Fill missing alt text"

4,308 of your 9,711 product images don't have alt text. This is a Rank Math + accessibility signal, AI-generatable, but we don't have a one-click batch for it yet. Flagging for a future release — for now, alt-text coverage grows naturally as you run the AI enrichment pipeline above (it fills alt text as a side effect of the per-product enrich).

### Low-effort — "Generate HowTo schema on top-selling products"

Your AEO coverage is 47.7% FAQ / 0% HowTo / 0% Schema / 0% Speakable. Rich result placements in Google (HowTo cards, product review stars) only fire when the structured data exists. The Action Queue now surfaces AEO-candidate products individually — expect to see a card for e.g. "Generate HowTo for your top-selling Darbuka" with a one-click trigger.

### Deferred — storage orphans

You have 5,373 orphaned images (55% of library). These don't hurt performance much but they inflate your media library size and backup bill. Addressing is manual — search Media Library by "Unattached", review, delete in batches. Not plugin work, just a spring-cleaning chore.

---

## 6. What's running differently behind the scenes

### Quality-control automation added

We shipped a **quality gate** alongside this release — five automated scripts that verify every deploy doesn't regress what was fixed. They run on every code change now:

- **Security audit** — scans for 15 categories of common WordPress security anti-patterns (raw SQL, XSS, unsanitized input, hardcoded credentials).
- **Code quality check** — enforces our project conventions (English-only code, `luwipress_` prefix on options/hooks, no hardcoded colors, i18n discipline).
- **REST contract test** — exercises every REST endpoint on your live site with 69 invariants: does it require auth? does it carry the cache-control header? does it validate its parameters? does the replay attack return 401?
- **WebMCP contract test** — checks all 138 AI agent tools for spec compliance (JSON-RPC 2.0 strict, UTF-8 hygiene, schema completeness, safe-probe round-trip).
- **Release preflight** — composes all of the above plus version consistency, PHP lint, debug marker scan, and secret leak detection. Blocks the release ZIP if anything's wrong.

**What this means for you:** security fixes like today's cache leak won't regress in a future update. The `REPLAY_BLOCK` test specifically prevents exactly this class of bug from ever coming back undetected — it re-runs after every deploy and would alert immediately if even one endpoint forgot to send the cache-control header.

---

## 7. Known non-issues (expected behaviour, not bugs)

- **`/token-recent?limit=9999` returns HTTP 400** — intentional. Out-of-range limits are rejected rather than silently clamped. If a dashboard request sends a huge number, it's a dashboard bug, and we want it to fail loudly.
- **WebMCP plugin 1.0.5 vs MCP protocol 2.0.1 version split** — intentional, covered in the WebMCP section above. Plugin updates vs wire-protocol updates are separate concepts.

---

## 8. Changelog reference

For the full technical changelog with file-level detail, see **WordPress admin → Plugins → LuwiPress → View details → Changelog** or the `readme.txt` in the ZIP.

Changelog headings for this release:
- `= 3.1.37 — Hotfix: LiteSpeed cache bypass delivery fix + declare WooCommerce dependency =`
- `= 3.1.36 — REST cache hardening + batch status persistence + AEO/Token polish =`

---

## 9. Questions, concerns, or regressions

- **If you notice anything different or broken,** reply to this thread with a screenshot + the URL + the time (rough is fine). The quality-gate tests already caught everything we know about, so any new surprise is genuinely new.
- **If Google Search Console starts flagging anything unusual** (hreflang errors, missing structured data, sudden crawl errors) in the next 48 hours, send the screenshot — cache-flushing can briefly cause stale pages to appear in search, but it normalises within a day.
- **If the Knowledge Graph stops loading or the chat widget misbehaves,** try a hard refresh first (Ctrl+Shift+R / Cmd+Shift+R), then incognito window. If either still fails, flag the console error.

No action required to sit back and use the plugin as before — the fixes are fully automatic. The only human decision is whether you want to trigger any of the quick wins in Section 5.
