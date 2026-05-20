# Vendor Response — Tapadum Issue Checklist 2026-05-18

**From:** Umut Demirci (Luwi Developments LLC · hello@luwi.dev)
**To:** Özgür (Tapadum)
**Date:** 2026-05-19
**Re:** `tapadum-vendor-issue-checklist-2026-05-18.md`

## Summary

Thank you for the detailed checklist — the structured numbering made the triage fast. Verdict on the 19 items:

- **9 items are already CLOSED** in shipped releases (3.1.42 → 3.2.1).
- **6 items are CLOSED today in Release 1** (LuwiPress 3.2.2 + WebMCP 1.0.22 + LuwiPress Gold 1.7.34).
- **3 items move to Release 2** (planned within ~1–2 weeks).
- **1 item (A3) — your claim is not reproducible against current code or live data**; we ran a fresh test and the meta keys you reported as untranslated are in fact being translated. Detail in §A3 below.

**Release 1 artefacts** (built and ready for upload):

| File | Size | SHA-256[:16] |
|---|---|---|
| `luwipress-v3.2.2.zip` | 705 KB | `85bf508b401d1bd4` |
| `luwipress-webmcp-v1.0.22.zip` | 83 KB | `528cbfb5f721ea91` |
| `luwipress-gold-1.7.34.zip` | 549 KB | `75b621b4029a4481` |

---

## A. Current Open Issues

### A1 — Vendor-BUG-004 `taxonomy_update_term` WPML slug collision — ✅ CONFIRMED FIXED

Confirmed in WebMCP **1.0.19** (commit `def8a11`), still active in 1.0.22.

- **Two-layer behavior is intended and stable.**
- The `method` field IS returned in the response so you can log which path ran:
  - description-only update → `"method": "direct_description_write"`
  - full update (name/slug/parent) → `"method": "wp_update_term"`
- **No additional edge cases** beyond the Clay Darbuka cluster (IT 237 / FR 298 / ES 362) that you've already mapped. The fix is term-class-agnostic — it applies to every WPML translation term across every taxonomy.
- Recommended sweep test for the TASK-K-002 IT/FR/ES SEO description run: pick one term per (taxonomy, language) tuple, description-only update, confirm `method: direct_description_write`. If you see `wp_update_term` for a description-only call, that would be a regression — please flag it.

### A2 — Translation-term description MCP-read gap — ✅ CLOSED in WebMCP 1.0.22

You were correct: there was no MCP read path for a translation term's core `description`. Closed two ways:

1. **New tool: `taxonomy_term_get(term_id, taxonomy?, lang?)`** — returns full core fields including `description`, `parent`, `count`, `link`. When `lang` is passed, resolves to the WPML/Polylang sibling first via `icl_object_id` / `pll_get_term`, then reads. So both call patterns work:
   ```
   taxonomy_term_get(237)                            // direct read of IT term 237
   taxonomy_term_get(46, lang="it")                  // read EN term 46's IT sibling (resolves to 237)
   ```
   Response shape: `{ term_id, taxonomy, slug, name, description, parent, count, link, lang, resolved_via }`. `resolved_via` is `"direct"`, `"wpml"`, or `"polylang"`.

2. **`wpml_term_translation_get`** now includes `description` in each sibling entry — for the bulk listing case where you want all sibling descriptions in one round-trip.

Both safe to call before `taxonomy_update_term` — read-only, idempotent.

### A3 — FR-005 `translation_request` `_luwipress_faq` + `rank_math_focus_keyword` — ⚠️ CANNOT REPRODUCE

The translation scope is broader than your session-95 observation suggests. Both meta keys you flagged ARE in `LuwiPress_Translation`'s translatable set:

- `class-luwipress-webmcp.php:657–661` — `rank_math_focus_keyword`, `_luwipress_faq`, `_luwipress_schema`, `_luwipress_howto`, `_luwipress_speakable` are all in the hardcoded list passed through the AI translator.

**Live verification on `new.tapadum.com` (2026-05-19, fresh MCP probe):**

1. **`rank_math_focus_keyword`** — EN product 6189 (Tap198, "Electric Silent Walnut Oud with Guitar Pegs MG-E1") carries `"silent electric oud"`. FR sibling 32706 carries **`"oud électrique silencieux"`** — fully French, correct gender agreement, word order. The word "silent" you reported as staying English is in fact rendered as "silencieux" in the live record. Not a translation bug.
2. **`_luwipress_faq`** — EN source 6189 itself has **no `_luwipress_faq` meta set** (the source product was never AEO-FAQ-enriched). When the EN source has no FAQ, there's nothing for `translation_request` to translate to FR — the FR sibling correctly has no FAQ either. This isn't a translation bug; it's a coverage gap on the EN side.
3. **System-wide schema parity** — `translation_sync_audit` over the whole catalog (504 products × 3 target languages) returned **0 `schema_parity` findings**. All 68 EN-FAQ products have their FR/IT/ES sibling FAQs populated. If `translation_request` were skipping `_luwipress_faq`, we would expect ~204 sibling-FAQ gaps; we see zero.

**Conclusion:** The reproduction on session 95 / Tap198 6189 → 32706 FR was confounded — the EN source had no FAQ to translate, so the FR copy correctly had no FAQ either. We could not find evidence of `translation_request` skipping these meta keys on any live product.

**Action requested from your side:** if you can locate a product where the EN source has `_luwipress_faq` set AND the FR/IT/ES sibling has empty `_luwipress_faq` after a `translation_request` call, please share the source product ID + the timestamp of the translation_request call. That would be a real reproduction case and we'd dig in. Until then, treating A3 as **not actionable**.

### A4 — ISSUE-030 Clone `_luwipress_faq` migration coverage — 🟡 SCHEDULED FOR RELEASE 2

Confirmed: the theme `migration.php` handles page archival + slug shadow swaps, but does not port `_luwipress_*` post meta between sites. There is no core-side meta-key port tool either.

**Release 2 (next ~1–2 weeks):** new MCP tool `meta_key_port` — given (source_post_id, target_post_id, meta_keys[]), it copies the meta values from source to target with sanitization preserved for serialized formats (so `_luwipress_faq`'s JSON-array shape survives). Bulk variant `meta_key_port_batch` for site-wide sweeps.

**Until Release 2 ships:** if the swap-day is close, the cleanest stopgap is the Hub-side `meta_get`+`meta_set` loop you already proposed. Both MCP tools are available, no version dependency. Happy to coordinate the loop if useful — for the ~30 FAQ-enriched products, this is one Python script or a sequence of MCP calls.

---

## B. Theme / Frontend Bugs

### B1 — Hub-BUG-009 Upsell + Related Products stacking — ✅ FIXED IN LuwiPress Gold 1.7.34

Root cause confirmed: the theme had no `remove_action` on WC's default `woocommerce_output_related_products` hook (priority 20) when manual upsells were set on a product. Both sections rendered, stacking visually.

**Fix (`themes/luwipress-gold-elementor/inc/wc-pdp-hooks.php`):** new `template_redirect` (priority 20) handler — when `$product->get_upsell_ids()` is non-empty, runs `remove_action('woocommerce_after_single_product_summary', 'woocommerce_output_related_products', 20)`. Result: manual upsells display alone; algorithmic Related Products falls back to render only when no manual upsells are set.

**Operator opt-out:** the new filter `luwipress_gold_suppress_related_when_upsells` (default `true`) lets you keep both sections rendering if you want a long-tail recommendations row alongside the curated upsells:
```php
add_filter( 'luwipress_gold_suppress_related_when_upsells', '__return_false' );
```

**Recommended verification post-deploy:**
1. Set `_upsell_ids = "12818,7285,7270"` on product 6189 (your session-95 case).
2. `cache_purge` then visit the FR PDP.
3. Confirm only "You may also like…" renders, no "Related Products" block.
4. Clear the upsells (`_upsell_ids = ""`), purge, refresh — Related Products should come back automatically.

### B2 — Hub-BUG-008 Double FAQPage JSON-LD — ✅ CLOSED in core 3.1.53

Already fixed in core 3.1.53 (live on the clone now). The legacy `wp_footer` priority 20 emitter was removed; only the canonical `wp_head` priority 5 emitter (`class-luwipress-aeo.php:30`) remains. Codebase audit confirms a single FAQPage emitter site-wide.

**Recommended:** hard-refresh a FAQ-enriched product page in incognito and re-run Google Rich Results Test. You should see exactly one FAQPage block in the source. If you still see two, please share the URL — there may be a third-party plugin emitting one too (Rank Math has its own FAQPage emitter for blocks-based content).

---

## C. v1.3 Status-Sync (your stale list, marked CLOSED/OPEN)

| ID | Item | Verdict | Fix version |
|----|------|---------|-------------|
| **C1** | BUG-005 `aeo_save_faq` "Invalid product ID" | ✅ CLOSED | core 3.1.42 |
| | BUG-006 `aeo_generate_faq` writes nothing | ✅ CLOSED | core 3.1.42 |
| | BUG-007 `seo_enrich_product` doesn't regen FAQ | ✅ CLOSED (`force_regen_faq=true`) | core 3.1.42 |
| **C2** | BUG-001 `crm_segment_customers` empty segment | ✅ CLOSED | core 3.1.42 |
| **C3** | BUG-002 `woo_list_orders` timeout (date_before) | ✅ CLOSED — `date_before` now filters the query | webmcp 1.0.22 |
| **C4** | BUG-003 CRM segmentation coverage | ✅ CLOSED — all 8 segments compute, thresholds configurable | core 3.1.23 + 3.1.42 |
| **C5** | BUG-004 `woo_list_orders` missing `orderby` / `order` | ✅ CLOSED — both params added with whitelist | webmcp 1.0.22 |
| **C6** | BUG-008 KG `top_products.id` / `issue_count` null | ✅ CLOSED | core 3.1.42 |
| | BUG-009 KG `top_sellers` empty names (trashed products) | ✅ CLOSED — trashed products now filtered | core 3.1.42 |
| **C7** | BUG-010 `content_stale` cutoff 1970 | ✅ CLOSED — cutoff is `strtotime("-{$days} days")`, default 180 | core 3.1.42 |
| **C8** | BUG-013 `translation_taxonomy` silent fail | ✅ CLOSED — structured `reason` field in every failure log | core 3.1.42-hotfix3 |
| **C9** | FR-002 `crm_segment_customers` pagination + export | 🟡 SCHEDULED for Release 2 | webmcp 1.1.0 |
| **C10** | FR-003 Customer Chat session MCP tools | 🟡 SCHEDULED for Release 2 | webmcp 1.1.0 |
| **C11** | IMP-002 BM25 include posts + pages | 🟡 SCHEDULED for Release 2 | webmcp 1.1.0 |
| **C12** | IMP-004 `email.method: php_mail` (Easy WP SMTP undetected) | ✅ CLOSED — `EASY_WP_SMTP_VERSION` + `SWPSMTP_VERSION_NUM` constants checked | core 3.2.2 |
| | IMP-008 `product_feed: none` (RexTheme PFM undetected) | ✅ CLOSED — `WPPFM_VERSION` / `WPPFM_PRO_VERSION` / `Rex_Product_Feed` class checked | core 3.2.2 |
| | IMP-004 `meta_ads: none` (Meta Pixel for WP undetected) | ✅ CLOSED — `STARTER_PLUGIN_VERSION` constant detected | core 3.1.x |
| **C13** | IMP-005 CRM thresholds not exposed | ✅ CLOSED — `crm_settings_get` MCP tool + `/crm/settings` REST | core 3.1.23 |

**Stale-doc resync:** your v1.3 (2026-04-28) copy is superseded by this response. The vendor-side canonical document at this point is the unified status above plus the changelogs in each plugin's `readme.txt`. We don't maintain a separate `luwipress-bugs-and-feedbacks.md` on our side anymore — the changelog + `LUWIPRESS-FEATURES.md` + this response cover the same ground without drifting.

---

## Release 1 — What Ships Today

3 ZIPs, lint clean, PHPStan clean, build-zip preflight clean:

- **LuwiPress core 3.2.2** (`85bf508b401d1bd4`, 705 KB)
  - C12: Easy WP SMTP 2.x + 1.x detection
  - C12: RexTheme PFM Free + PRO detection

- **LuwiPress WebMCP 1.0.22** (`528cbfb5f721ea91`, 83 KB)
  - C3: `woo_list_orders` `date_before` now filters the query
  - C5: `woo_list_orders` `orderby` + `order` parameters added
  - A2: new `taxonomy_term_get` MCP tool (read-before-write companion)
  - A2: `wpml_term_translation_get` now includes `description` per sibling

- **LuwiPress Gold theme 1.7.34** (`75b621b4029a4481`, 549 KB)
  - B1: conditional `remove_action` so manual upsells suppress algorithmic Related Products
  - B1: opt-out filter `luwipress_gold_suppress_related_when_upsells`

Upload via Hostinger Plugins / Appearance → Themes UI as usual. No DB migration, no breaking changes — all patches are backward-compatible.

## Release 2 — Roadmap (~1–2 weeks)

- **A4:** new `meta_key_port` + `meta_key_port_batch` MCP tools for cross-site meta migration (`_luwipress_faq`, `_luwipress_howto`, `_luwipress_schema`, etc.)
- **C9:** `crm_segment_customers` pagination (`page`, `per_page`) + CSV export (`format=csv`)
- **C10:** Customer Chat MCP suite — `chat_sessions_list`, `chat_session_get`, `chat_messages_search`
- **C11:** BM25 search expansion — `search_reindex(include=["product","post","page"])` + `search_products(post_types=[...])`

Estimated combined effort: 10–12 hours. We'll ship as **WebMCP 1.1.0** + **LuwiPress core 3.3.0** (minor bumps reflect the new feature scope).

---

## Open Items on Your Side

1. **A3 reproduction case** — if you can find a product where EN source has `_luwipress_faq` set AND FR sibling has empty `_luwipress_faq` after a `translation_request` run, please share the source ID + call timestamp. We'd want to investigate the prompt/serialization path. Until then we'll keep A3 as "not actionable, monitoring."
2. **A4 stopgap** — if the DNS swap is near, the Hub-side `meta_get` + `meta_set` loop closes the gap immediately without waiting for Release 2. ~30 FAQ products × 3 languages = ~90 calls; we can do this together if useful.
3. **B1 + B2 verification** — please run the recommended checks (B1 = toggle `_upsell_ids` on a test product after the theme upload; B2 = rich-results retest on a FAQ product) and confirm both behave as expected on the clone.

Happy to jump on Hangouts for any of these if it's faster than async.

— Umut
