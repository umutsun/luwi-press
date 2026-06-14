# Tapadum Kit CSS — previous baseline history (archived from CLAUDE.md 2026-06-10)

> Dated golden-baseline notes 2026-04-21 -> 2026-04-27, incl. V35.5/V36 rollback forensics and the V32 :is() compression lesson. The live CLAUDE.md keeps only the CURRENT baseline (V48) + restore protocol. Baseline CSS files remain in temp/tapadum/kit-golden/.

### Previous baseline — **2026-04-27 (V32+V33 REBUILD + BEGIN markers + V42-V46 mobile/subcat polish series)**

- File: `temp/tapadum/kit-golden/tapadum-kit-golden-2026-04-27.css` — also aliased as `temp/tapadum/kit-golden/tapadum-kit-golden-LATEST.css`
- Size: 401,229 bytes
- Kit ID: 203
- SHA-256 (16): `73aa6964f688e486`
- Metadata: `temp/tapadum/kit-golden/tapadum-kit-golden-2026-04-27.meta.json`
- Rollback snapshots:
  - Pre-V32-rebuild: `kit-pre-v32-rebuild-1777263585.css` (sha `5b368cba542175e3`) — pre-2026-04-27 live state with leaked header IDs in V32 lists.
  - Post-V32-rebuild, pre-markers: sha `ccaa8541670345e7` (-3.3 KB from pre-rebuild). Captured in `kit-with-begin-markers-2026-04-27.css` ancestor.

**What 2026-04-27 CHANGES on top of 2026-04-26:**
- **V32 REBUILD**: Regenerated from `temp/tapadum/correct_scan_v2.json` (header template IDs `7a29fda` + `322f38f` excluded). New TOP list 53 IDs (was 54), new INNER list 50 IDs (was 51), widgets unchanged (213). Build: `temp/tapadum/build_v32_v2.py`.
- **V33 REBUILD**: Same TOP-list update (53 IDs, header excluded) for the mobile full-bleed `width:100vw` rule. V33 mobile gutter fix on product templates kept. Build: `temp/tapadum/build_v33_v2.py`.
- **BEGIN markers backfilled** for V35, V37, V38, V39, V40, V41 (each already had `/* end Vxx */` closer; before this push they had no grep-able BEGIN). Now every active layer has a unique `/* Vxx BEGIN — ... */` opener AND a closer, enabling deterministic strip-and-replace edits in future sessions. +591 bytes for the 6 one-line BEGIN comments. Build: `temp/tapadum/backfill_layer_markers.py`. Verified: comment-strip CSS body unchanged (only +6 newlines). See `feedback_layer_marker_discipline.md`.
- **V42 mobile product-focused card** (operator screenshot fix on /travel-darbuka/). On `@media (max-width: 767px)`: card image is `width:100%; aspect-ratio:1/1; padding:0; background:#fff` (defeats prior 6 stacked rules with `height:220/280/320` + `padding:8-16px` + `background:#fafafa`). Card outer padding 12px→8px. ul.products gutter 16-20px→8px. Title/price tightened. Build: `temp/tapadum/build_v42_mobile_product_focus.py`. Verified: V42 region visible in visitor HTML on subcategory + product-archive pages.
- **V43-V45 mobile card cascade fix series** (2026-04-27). After V42, operator reported cards still narrow. DOM audit revealed Tapadum hub pages have **7-level nested `.e-con-boxed`** wrapping the wc-products widget — each Elementor flexbox container ships with default 10-20px padding, summing to ~80px dead space per side on 360px viewport. V43 zeroed the wc-widget container + tightened inner card padding, used `4/3` aspect ratio. V44 used `:has()` selectors to target containers wrapping wc widgets (V43's `body.archive` selector did NOT match — Tapadum hub pages are `body.page.page-id-XXXX`, not taxonomy archives). V45 took the nuclear option: ALL `.e-con-boxed` and `.e-con-inner` get 6px padding on mobile + `:has(.elementor-widget-text-editor / .elementor-widget-heading)` safeguard adds 12px back for text-only containers. See `feedback_v42_v45_mobile_card_cascade.md` for the multi-step debugging story.
- **V46 subcategory card desktop alignment** (2026-04-27). After V42-V45, operator reported sub-cat tile images stuck to left-center with whitespace, sub-cat row not aligned with product card row on desktop /percussions/. Root cause: PERCUSSIONS-FIX-V2 set `ul.products li.product-category img { padding:16px }` SITEWIDE — on a ~250px desktop card with `aspect-ratio:1/1` this collapsed visible image to ~110px. V46 overrides: subcat image padding 8px desktop / 4px mobile, `<a>` wrapper forced `display:block` (was flex-column flex-start), card outer padding matched to product card (8px). Title pill = red CTA bottom-anchored via `margin:auto 0 0 0`.
- **CLOSES the mobile header bug** (V36 incident, 2026-04-25): V32 was applying `max-width:1372px`, `flex-wrap:nowrap`, `padding:0`, `flex-direction:column` to header sections, causing menu icon to fall behind hero overlay (`top:233 left:161`). V36's in-place regex strip broke desktop logo + chat widget. This rebuild generates the new :is() lists from scratch — surgical replace via start/end marker find, not regex strip across blocks. Lesson per `feedback_v32_v33_rebuild_done.md`.
- **All other layers preserved**: V23/V24/V26 (footer headings + mobile spacing), V34 (hero polish 9 hubs), V38 (53-ID page-level info-bar hide that already excluded header IDs by design), V39 (hero gap close), V40 (intro card styling), V41 (per-page hero IDs), PERCUSSIONS-FIX-V2 (sub-category card parity). All marker comments + @media queries (100 total) intact.
- **Smoke test 8/8**: `temp/tapadum/smoke_test_v32_rebuild.py` checks home, percussions, strings, darbuka, arabic-oud, cart, about, shipping × mobile + desktop UA. Logo + hamburger + chat widget + footer info-bar (`d61d2ed`) all present.
- **Resolves false positive**: 2026-04-26 audit had flagged 10 pages "missing info-bar". Was wrong — `/elementor/outline/{id}` doesn't return header/footer Elementor templates, which inject `da0285e` (header info-bar) + `d61d2ed` (footer info-bar) on every page. See `project_tapadum_infobar_inconsistency_open.md` (now CLOSED).

### Previous baseline — **2026-04-25 (V35 — mobile product grid normalize)**

- File: `temp/tapadum/kit-golden/tapadum-kit-golden-2026-04-25.css`
- Size: 370,791 bytes
- Kit ID: 203
- SHA-256 (16): `25d6819a32c1de73`
- Pre-V35 snapshot: `temp/tapadum/kit-golden/kit-pre-v35-1777112840.css` (sha `b15ad036566a8ca0`)

**What V35 ADDS on top of 2026-04-24:**
- **Mobile product grid normalize** (`@media (max-width: 767px)`) for every WooCommerce hub + subcategory archive (and shop, search, my-account orders by extension). Cascade-winners audit (`temp/tapadum/analyze_mobile_winners.py`) showed top-level Kit was empoze ediyordu: `padding-left/right: 16px` + `margin-left/right: 6px` on `ul.products` + `width: calc(100% - 28px); margin: auto 14px 0` on `li.product` → 360px viewport'ta yanlarda toplam ~44+28=72px ölü boşluk, kart 288px'e sıkışıyordu. V35 mobile blok:
  - `ul.products` (incl. all `[class*="columns-"]`): `padding: 0 8px; margin: 0 auto; width:100%; max-width:100%; gap:16px; grid-template-columns: 1fr` — kesinlikle tek kolon, 8px dengeli yan gutter.
  - `li.product` + `li.product-category`: `width:100%; margin:0; padding:12px; border-radius:10px` — yan margin sıfır, kart full-width.
  - Card image (`li.product img`, `li.product-category img`, `.attachment-woocommerce_thumbnail`, `.wp-post-image`): `width:100%; aspect-ratio: 4/3; object-fit: contain` — ürün ve kategori kartları artık aynı görsel boyutuna sahip (mobilde).
- Generator + push: `temp/tapadum/push_v35.py`. QA: `temp/tapadum/qa_v35_mobile.py` covers 13 hubs+subcats — 13/13 PASS. Desktop / tablet (>767px) UNCHANGED — V35 is mobile-only.

**V35.5 attempted + ROLLED BACK 2026-04-25** — Tried to fix mobile footer info-bar layout (2x2→1x4 stack on max 600px, plus 16px seam relax). Used V32's INNER `:is(51 IDs)` list with `display: flex; flex-direction: column; gap: 14px` on the container + `flex: 0 0 100%` on direct columns. Live result: **mobile header menu disappeared and footer info-bar shrank**. Rolled back via `temp/tapadum/rollback_v35_5.py` to V35-only state. **Confirmed cause** (verified by V36 forensics): V32's INNER list contained header section `322f38f` (and TOP list contained header section `7a29fda`) — V35.5's `flex-direction: column` on those IDs collapsed the mobile header into a vertical stack, hiding the menu icon. See V36 below.

**V36 attempted + ROLLED BACK 2026-04-25** — Tried to fix mobile header bug (customer report `docs/tapadum-mobil-header-yanit-v1.0.md`) by removing `7a29fda` from V32 TOP `:is()` list (54→53 IDs) and `322f38f` from V32 INNER `:is()` list (51→50 IDs) via regex strip across every `:is(...)` block in the V32 region. Forensics had correctly identified that V32 was misclassifying these header IDs as info-bar (verification: `temp/tapadum/verify_header_322f38f.py`). New live sha was `269a02bd404c9f03` / 369854 bytes — looked clean on readback. **Live result on desktop: header logo image disappeared AND customer chat widget upper edge was cut off**. Rolled back via snapshot `kit-pre-v36-1777130678.css` (V35-only state, sha `25d6819a32c1de73`). Panic backup of V36 state: `kit-panic-pre-v36-rollback-1777178895.css` (preserved in case re-analysis shows the regex strip wasn't actually the cause — could be a separate cache layer issue). Lesson: regex-stripping IDs across 54 separate `:is(...)` blocks is too coarse — even though each block was individually valid CSS after stripping, the cumulative effect changed the cascade in ways neither the readback verify nor the contract-test caught. Next attempt should rebuild the V32 region from scratch with the corrected scan (header IDs excluded BEFORE the `:is()` lists are generated), not patch in place. Customer-facing reply still applies for the diagnosis half: `docs/tapadum-mobil-header-yanit-v1.0.md` — but the V36 fix portion needs to be marked as rolled back; the actual fix is pending re-attempt. Mobile header bug remains open.

**Closed issue (resolved 2026-04-27):** Info-bar↔footer seam was reported as inconsistent across templates 2026-04-24. The 2026-04-26 audit (`temp/tapadum/audit_2026_04_26_mobile_render.py`) showed all templates DO have a footer info-bar (`d61d2ed` from footer template `27566`) — yesterday's `/elementor/outline/{id}` audit was missing header + footer Elementor template content. Real outlier: only `shop-for-ethnic-musical-instruments` (31703) has its info-bar at MID position (3 of 7). See `project_tapadum_infobar_inconsistency_open.md` for full closure note.

**Closed issue (resolved 2026-04-27):** Mobile header bug (V36 incident 2026-04-25) — V32 :is() lists were applying info-bar styling to header sections `7a29fda` + `322f38f`, collapsing the mobile menu icon behind hero overlay. Closed via V32+V33 rebuild — see `feedback_v32_v33_rebuild_done.md`.

### Previous baseline — 2026-04-24 (V34 + V33 + V32 — category heroes + mobile info-bar full-bleed)

- File: `temp/tapadum/kit-golden/tapadum-kit-golden-2026-04-24.css`
- Size: 366,551 bytes
- Kit ID: 203
- SHA-256 (16): `b15ad036566a8ca0`

**What this baseline ADDS on top of 2026-04-23:**
- **V33 (rev3) mobile info-bar full-bleed + footer-seam zero + last-section margin-zero**: (a) `@media max-width:900px` rule full-bleeds every TOP info-bar (fixes mobile side-gutters on product template `.elementor-259`). (b) `html body footer.elementor-location-footer.elementor-27566 { margin-top: 0 !important }` (specificity 0,3,1) beats prior `margin-top: 64px / 24px / 16px` rules. (c) Last-section `margin-bottom: 0` widened to multiple wrapper depths (`html body main .elementor-section:last-of-type` etc). Helps SOME pages but does not fully resolve the cross-template inconsistency (see open issue above). Generator: `temp/tapadum/build_v33_patch.py`.
- **V34 category-hero polish**: 9 hub pages (string-instruments, percussions, oud, santur, qanun, winds, bowed-instruments, accessories, tanbur) converted from a 55/45 split-column layout into a single full-width cover with the title centered and overlaid on top. CSS-only — no Elementor data mutation. Pattern: hero section becomes a `display:grid` stack (every direct column shares `grid-column:1 / grid-row:1`), bg-image column stretches with `min-height:320px`/240px/200px responsive, `::after` linear-gradient overlay (`rgba(0,0,0,0.25)→rgba(0,0,0,0.55)`) keeps every cover legible, heading widget restyled `font-size: clamp(32px,5vw,64px); color:#fff; text-shadow: 0 4px 18px rgba(0,0,0,0.45)` with `clamp` shrinking on mobile. Percussions has `text-editor` instead of heading widget — handled with a parallel `:is()` selector. Generator: `temp/tapadum/build_v34_hero.py`. Scan: `temp/tapadum/category_hero_scan.json` (9 hero IDs + 9 bg-column IDs + 8 heading IDs + 1 text-editor ID).
- QA after deploy: `temp/tapadum/qa_v33_v34.py` checks 14 pages × 2 viewports (mobile + desktop) — 28/28 passed (V32/V33/V34 markers + hero IDs + info-bar IDs all present).

### Previous baseline — 2026-04-23 (V32 — info-bar SITE-WIDE standardize, DOM-correct)

- File: `temp/tapadum/kit-golden/tapadum-kit-golden-2026-04-23.css`
- Size: 353,308 bytes — full save (fixed `<=` char trunc via wp_strip_all_tags HTML-tag detection)
- SHA-256 (16): `40393230def5248f`
- Metadata: `temp/tapadum/kit-golden/tapadum-kit-golden-2026-04-23.meta.json`

**What this baseline ADDS on top of 2026-04-22:**
- **V25 + V28 + V29 + V30 + V31 all REMOVED.** Earlier attempts had the **top section vs inner section IDs swapped** — scan regex matched IDs inside `<style>` blocks (CSS selectors) instead of the real HTML DOM. Result: outer black banner got `max-width: 1372px` (making it float narrow in the middle on desktop) while the inner content wrapper got full-width black background. V31 also broke product page which was previously correct. Emergency strip + rebuild was needed.
- **V32: DOM-correct scan.** New tool `temp/tapadum/correct_scan.py` strips `<style>`, `<script>`, `<!-- -->` BEFORE walking ancestors — so it matches real DOM elementor-element-<id> wrappers, not CSS selector text. Ran across 322 URLs:
  - **48 distinct top section IDs** (each page has its own Elementor-cloned info bar — every top section is classic `elementor-top-section`, no Flexbox).
  - **48 distinct inner section IDs** (each page has unique 4-column wrapper).
  - **192 distinct icon-box widget IDs** (4 per page).
  - All pages use classic sections; no `e-parent` / `e-con-full` containers discovered for info-bar.
- V32 rules (strict separation):
  - **Top (outer) sections**: `margin: 0; padding: 24px 16px (16px 8px mobile); background: #000; width: 100%` — **NO max-width**, full-width banner.
  - **Inner wrappers**: `max-width: 1372px; margin: 0 auto; background: transparent` + `.elementor-container { display: flex; flex-wrap: wrap; gap: 0 }` + columns `flex: 1 1 25%` (mobile `50%`).
  - **Widgets**: icon-box wrapper `display: flex; align-items: center; gap: 14px (10 mobile)`; icon `#d83131` at 38px (28 mobile); title white bold 18px (13 mobile); desc slate 13px (11 mobile).
  - Narrow desktop `<=1024px`: 4-col kept, fonts shrink (20px icon, 10.5px title, 9px desc, gap 6px).
  - **2×2 grid kicks in at `<=900px`** (raised from 767px) so ~720px wide viewports flip to 2×2 with 24px icon, 12px title, 10px desc.
  - Narrow mobile `<=480px`: further tightened (20px icon, 11px title, 9.5px desc).
- Flush-to-footer: `{top} + *`, `.elementor-location-footer`, `footer.site-footer`, last-section all get `margin-top/bottom: 0`.
- Generator: `temp/tapadum/build_v32.py` reads `correct_scan.json` → rebuild anytime new pages appear.
- Result: info bar her sayfada — homepage, kategori, alt-kategori, ürün, policy, about — **aynı 4 ikonlu banner**, footer'a yapışık, mobilde 2×2 grid.

**Section reorder 2026-04-23 (paired with V32):** Homepage (7690) + shipping-policy (6905) had info bar NOT as last section — moved to last via `/elementor/reorder-sections`. Sync-structure propagated to 6 WPML translations (24975/19978/16660 + 24782/19934/16759). Rollback snapshots saved. Merged the new WPML info-bar IDs into V32 (54 tops / 51 inners / 213 widgets final).

**V32 responsive final tuning (2026-04-24):**
- Desktop (>1024px): 4-col, 38px icon, 15px bold white title, 12px slate desc, gap 14px, nowrap+ellipsis.
- Tablet (<=1024px): 4-col, 22px icon, 13px title, 11px desc, gap 10px.
- Mobile 2×2 grid kicks in at <=900px: 26px icon, 14px title, 12px desc, gap 12px, section padding 18px/14px.
- Narrow mobile (<=480px): 22px icon, 13px title, 11px desc.
- Title/desc have `white-space: nowrap; overflow: hidden; text-overflow: ellipsis;` + inner icon-box-content gets `min-width: 0; flex: 1 1 auto; overflow: hidden` so narrow columns truncate cleanly instead of wrapping to 2 lines.

**CSS compression lesson (2026-04-24):** The naive approach of joining every widget/section ID with comma (`html body .elementor-element-X,\nhtml body .elementor-element-Y,...`) ballooned V32 to **884 KB**, and the WP option / DB path TRUNCATED live CSS at ~412 KB → all rules past the cut were silently dropped (including the `@media (max-width: 900px)` 2-col mobile rule). Symptom: desktop was fine but mobile stayed 4-col no matter what. Fix: use **`:is(.elementor-element-X, .elementor-element-Y, ...)` grouping** — the 54-ID top selector becomes one compact block used everywhere. V32 block size dropped from **884 KB → 82 KB** (10× smaller), full CSS stored intact, all media queries live. Universal rule for future Kit CSS with many IDs.

### Previous baseline — 2026-04-22 (V28 — info-bar flush-to-footer for ALL 44 section IDs)

- File: `temp/tapadum/kit-golden/tapadum-kit-golden-2026-04-22.css`
- Size: 279,021 bytes, SHA-256[:16]: `57560dde03051179`

**V28:** info-bar flush-to-footer across ALL 44 info bar section IDs (sibling margin-top:0 + section margin-bottom:0 + `.elementor-location-footer` margin-top:0). 88 selectors (44 sections × 2 patterns).

**Production section reorder** (paired with this baseline): 9 EN pages + 24 WPML translations + Single Product template 259 have breadcrumb as first section and info bar as last.

### Previous baseline — 2026-04-21 (evening — V23..V27 layer stack)

- File: `temp/tapadum/kit-golden/tapadum-kit-golden-2026-04-21.css`
- Size: 274,706 bytes
- SHA-256 (16): `b1afe6700d286487`

**What this baseline ADDS on top of the morning baseline (V23..V27):**
- V23: Footer heading red + nested address sub-section spacing tight
- V24: Nuclear heading color rules (`h1..h6 + span.elementor-heading-title`) + mobile column gap 40→8px + logo 32px breathing + section `1f300ef` mobile padding 60→32/24px
- V25: Info bar (Free Shipping / 15 Days Warranty / Global Shipping / 100% Secure) standardized across pages — consistent padding/gap/icon-color/typography; mobile 2×2 grid; flush against footer
- V26: Max-specificity Customer Care heading color + address icon-column width 56px / text-column padding-left 12px
- V27: Elementor `--e-global-color-primary` scoped to `#d83131` inside footer — root-cause fix for persistent blue Customer Care heading (Elementor ships `#6EC1E4` as the default primary, and that was what footer heading widget inherited via the CSS variable)

**What this baseline includes (from earlier — verified visually before save):**
- Header: black bg, logo left + menu right (single row), WPML menu switcher as last menu item, mini-cart widget `cfbc8fb` hidden
- Breadcrumb: modern pill style (bg `#f9fafb`, radius 999px, red bold links, muted separator)
- Hub + 21 subcategory pages: `--content-w: 1372px` grid align, 4/3/2 col responsive
- Content h1-h6 dark `#111827` (body content), footer h1-h6 red `#d83131` (incl h6 for Customer Care)
- Product archive CTA: red bg, white uppercase, ellipsis
- xoo-wsc floating cart visible on all breakpoints
- Footer: deep-black bg (theme wrapper + all descendants), tight spacing (widget 6px, column 14×16, section 20px mobile), 24px top margin
- Shipping info icons red; info section is LAST section on all subcat pages
- Breadcrumb first section on Percussions / Strings / Darbuka / Kamancheh / Mey / Frame Drums / Travel Darbuka (7 EN pages, synced to IT/FR/ES)

