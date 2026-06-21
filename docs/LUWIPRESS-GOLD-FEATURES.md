# LuwiPress Gold — Theme Feature Overview

**Version:** 1.7.11 · **License:** GPLv2+ · **Target:** WooCommerce stores using LuwiPress

LuwiPress Gold is the first **ecosystem-integrated** WordPress theme: built specifically to surface the LuwiPress AI plugin's intelligence on the storefront. Where most themes stop at layout and typography, Gold turns AI Knowledge Graph signals, customer chat, plugin detection, and live search suggestions into first-class store features the visitor actually feels.

Ships as a **519 KB ZIP** with a 12-screen Elementor Kit, **a 23-widget Elementor suite** under a dedicated "LuwiPress Gold" category, native Customizer panel, branded WooCommerce templates (cart, checkout, my-account, archive, single-product), the **Featured Products registry** (one-click admin-bar toggle on every single-product page), and a 5-pass slug-conflict resolver — paired with the LuwiPress core plugin (3.1.45+) for full reciprocal awareness.

---

## 🆕 What's new in 1.7.x

### 1.7.11 — Widget suite v2 + Featured Products registry + KG hook (2026-05-12)
- **15 new Elementor widgets** under the "LuwiPress Gold" category, taking the total to 23:
  - **Hero Split** — 2-column editorial hero with eyebrow + H1 (italic gold accent via `[em]…[/em]`) + lead + dual CTAs + 3-up stats + image card with live status pill + master quote.
  - **Section Head** — reusable eyebrow + multi-line heading + optional pill-tab row + CTA link; light/dark variant. Used as the heading of every other section so style stays consistent.
  - **Category Grid** — gradient/image-bg category tiles with eyebrow count + h3 + sub line + animated arrow. Auto-pulls from WC top-level `product_cat` or accepts a manual repeater.
  - **Master Grid** — 4-up luthier cards (avatar image or gradient initial fallback) with location + specialty + instrument count; dark variant for the masters band.
  - **Story Split** — 2-column image tile + floating info card + numbered story bullets with a primary CTA. Replaces the old atelier section HTML.
  - **YouTube Channel** — editorial video grid that opens videos in the in-theme lightbox modal (no off-site click). Animated **Subscribe** button — minimal pill with idle red pulse + hover slide-fill animation; respects `prefers-reduced-motion`. Thumbnails auto-fetched from `i.ytimg.com/vi/<ID>/…` so no YouTube Data API key is required.
  - **Instagram Channel** — post grid with thumbnail upload + Instagram brand-gradient **Follow** button (gradient-clipped glyph that flips to white on hover). CTA URL auto-pulls from Customize → Footer → Instagram URL if left blank.
  - **Testimonials** — carousel or grid of customer review cards with star rating + photo + name + location + product. Emits Schema.org `Review` JSON-LD inline so Rank Math / Yoast / AIOSEO pick it up for SERP star snippets.
  - **FAQ Accordion** — collapsible Q&A using native `<details>/<summary>` with `FAQPage` JSON-LD output. Plays with the LuwiPress AEO module so AEO Coverage counts the FAQ presence on this page.
  - **Trust Badges** — horizontal strip of payment / security / certification icons (any Elementor icon library or uploaded image), with hover lift.
  - **Newsletter Signup** — email capture with a **theme-side REST endpoint** (`/wp-json/luwipress-gold/v1/subscribe`), per-IP rate-limit, optional GDPR consent checkbox, and a `luwipress_gold_newsletter_subscribed` action hook so a future CRM bridge plugin (FluentCRM / Mailchimp / Klaviyo) can hand the lead off.
  - **Countdown Timer** — wp_timezone-aware sale/launch countdown with day/hour/minute/second cells. Swaps to a post-expiry message + optional CTA when the target time arrives.
  - **CTA Banner** — full-bleed call-to-action strip with image bg + adjustable overlay strength + headline + 1-2 buttons (primary/ghost variants).
  - **Stat Counter** — big numbers with `IntersectionObserver`-driven scroll-on count-up animation; `prefers-reduced-motion` aware. Optional icon + sub line.
  - **Process Steps** — numbered horizontal cards (1→2→3→4) with connecting arrows, or vertical mode for compact narratives. Each step takes number (or icon) + title + body.
  - **AI Search Bar** — prominent pseudo-input that triggers the theme's existing AI search overlay. Optional quick-search suggestion chips. Auto-hides the "AI" badge when LuwiPress is inactive.
  - **Featured Product** — single hero-style product spotlight with three modes:
    1. **Registry** (default) — auto-renders the most-recently-featured product from the central registry.
    2. **Manual** — operator picks a specific product from the select control.
    3. **Custom** — title + image + URL only, no WooCommerce required (works on non-WC sites or editorial picks).
  - **Featured Strip** — horizontal scrolling strip of every product flipped to "featured". When the registry is empty, falls back to bestseller / on-sale / recent (configurable). Operator notes from the meta box render as gold chips on each card.
  - **KG · Stats** — live counters pulled from the **LuwiPress Knowledge Graph**: products in catalogue, instrument families, master luthiers, countries shipped to. Renders a "Live · LuwiPress KG" badge when active. When LuwiPress is inactive, swaps to an upsell card with a one-click "Install LuwiPress (free) →" button that opens the WP plugin installer pre-filled.
  - **KG · Trending** — top-scored categories or products from the KG ranker (popularity + recency + completeness signals). Optional KG-score badge per card. Same upsell-when-inactive pattern.
- **Featured Products registry** — a central "this product is currently featured" flag any widget can read. Operators flip it from two places:
  - **WP product edit screen** → new sidebar meta box "LuwiPress · Feature this product" with a checkbox, an optional operator note ("Boxing Week pick", "New arrival") and a last-featured-when timestamp.
  - **Frontend admin bar** → on any single-product page the admin sees a `★ Feature this product` / `☆ Unfeature this` button that AJAX-toggles the flag without leaving the page.
  - **Public REST endpoint** `POST /wp-json/luwipress-gold/v1/featured/toggle` (capability-gated to `edit_post`).
  - **Helper API** for theme code and companion plugins: `lwp_gold_is_featured($id)`, `lwp_gold_get_featured_ids($args)`, `lwp_gold_get_featured_products($args)`, `lwp_gold_set_featured($id, $on, $note)`.
  - **Mega Menu Featured slot** now consumes the registry — 3-layer fallback chain: per-menu-item manual override → registry product matching the menu item's category tree (or globally most-recently-featured for custom-URL menus) → existing best-seller auto-pick.
- **LuwiPress install hook (the strategic upsell)** — KG widgets explicitly upsell the free LuwiPress plugin when it is missing, with a one-click "Install LuwiPress (free) →" CTA. The theme remains fully usable without LuwiPress; the KG widgets only activate as a live-data surface when the plugin is detected. This is the deliberate design pattern: **the theme is the hook, the plugin is the value**.
- **Social URLs surfaced in two places** — Instagram, YouTube, Facebook, WhatsApp, TikTok, Pinterest, Twitter / X, LinkedIn URLs now appear under both **Customize → LuwiPress Gold → Footer → Social** AND **LuwiPress admin → Theme → Settings → Social** (via the `luwipress_theme_settings` filter). Both surfaces write to the same `theme_mod_luwipress_gold_social_*` keys, no drift. The YouTube and Instagram widgets fall back to these URLs for their CTA and Subscribe / Follow buttons when the widget's own URL field is left blank.
- **Homepage Elementor Kit fully widgetised** — `elementor-kit/03-homepage.json` has every HTML widget swapped for a proper Elementor widget instance. Re-importing the kit on a fresh install drops 11 typed widget references instead of 11 raw HTML blobs, so operators can edit copy from the widget control panel instead of editing CSS-laden HTML.
- **Theme description + tags refreshed** — `style.css` description now lists the widget suite + Featured Registry + KG hook; `Tested up to: 6.9`; tags extended with `blog, news, custom-colors, footer-widgets, theme-options, rtl-language-support`.

### 1.7.0 → 1.7.10 — Loader UX, sticky shop pagination, theme tools, drift detector, design polish
Cumulative refinements: server-side page loader (no flash of content before loader); real page-ready signal (window.load + font ready + viewport image load + stabilisation tick) with 5-second JS failsafe; mega panel banner head with filled count pill; homepage filter pills auto-wired to client-side category filtering; dynamic theme-version cache-buster on every asset URL plus a `wp_prepare_themes_for_js` filter that appends version to `screenshot.png` so the admin theme picker never serves stale screenshots; mega-menu admin UI for the per-menu-item Featured product field; sticky shop pagination ("Load More" infinite or button mode, prefers-reduced-motion fallback to button); translation drift sweep (Theme tab UI, scores translated post bodies by 10-language stop-word ratio, force-retranslate clears the Elementor already-translated guard meta and re-fires the AI pipeline); 23 maintenance tools registered with the LuwiPress Theme Bridge (Elementor + WC + WPML + SEO + Redirects axes); Migration Wizard; `screenshot.png` v2 with ecosystem-plugin chip rows + friendly-with chip rows (Elementor / WC / WPML / Rank Math / FluentCRM / LiteSpeed) rendered via `mask-image` stencil + brand-gold fill.

---

## 🆕 What's new in 1.6.x

### 1.6.7 — Loader UX overhaul + responsive polish
- **Real page-ready loader** — the page-loader overlay no longer dismisses on a fixed timer. It now waits for the actual readiness signals: window.load fires, web fonts settle, images visible in the initial viewport finish loading, then a small stabilisation tick before fading. The promise the loader makes ("page is ready") is now honoured. A 5-second failsafe in the boot script fires only if the page JavaScript breaks entirely.
- **No more "scrolled into blank space"** — when the loader dismisses, every below-the-fold section is force-revealed at the same instant. Visitors no longer see content fade in mid-scroll as IntersectionObservers catch up.
- **Responsive breadcrumb spacing** — on tablet (≤900 px) and phone (≤600 px), the breadcrumb now sits at a clean 40-56 px below the sticky header instead of touching it. Selectors widened to catch the breadcrumb at any nesting depth (the editorial article wrapper was previously bypassing the rule).

### 1.6.6 — Migration-from-Hello-Elementor template override
- **Posts with the `elementor_header_footer` page template now route through Gold's canvas** instead of Elementor Pro's bare bundled file. Migrated stores no longer end up with single posts that render header + raw content + footer with no `<main>` wrapper, no breadcrumb, no reading-progress hook. Closes the long-running "some posts open, some don't" symptom.

### 1.6.5 — Cache-buster + maintenance-grade polish
- **Theme version is now read dynamically** from `style.css` so a single bump in the Version line propagates to every cache-buster query string. Stores no longer hit "I uploaded the new ZIP but visitors still see the old JS".
- **Mega panel banner** — every mega-menu dropdown now opens with a refined banner head: filled count pill in the brand accent, serif title, "View all →" CTA with currentColor underline animation. Column heads use proper flex layout so counts sit cleanly to the right of category names.
- **Homepage filter pills** — operator-built pill bars (`.tap-pills` HTML widgets sitting above a WC product grid) now wire up to client-side category filtering automatically. No data attributes required on the markup; the theme reads the live category tree, slugifies pill labels, and hides products that don't match the picked branch.
- **Loader safety timer** in the inline boot script (5 s) — guarantees no stuck loader even when the theme JavaScript fails entirely. Pairs with the real-event dismissal in 1.6.7.

### 1.6.4 — WPML/Polylang slug hegemony + commerce flow
- **Cross-language slug-collision discovery** — the redirect resolver now walks WPML's translation table (and Polylang's translation API) so translated page slugs (`/es/percusiones/`, `/fr/vents/`) auto-redirect to the canonical category archive even when the operator kept WooCommerce term slugs in the source language. Closes the gap a literal SQL JOIN couldn't bridge.
- **Render-time URL rewrite in mega menu** — clicking a top-level category in the menu now goes directly to `/product-category/...` even when the operator's stored menu item points at a legacy hub page. No 301 hop, no flash of the old page.
- **Branded empty-cart state** — when the cart is empty, visitors see an editorial fallback (icon + headline + "Browse the collection" CTA + a 3-product best-seller rail) instead of WooCommerce's default text block. Hand-built from the same design tokens as the rest of the store, so it never feels like a third-party widget.
- **Server-side page loader** — the loader overlay is now in the HTML before the first paint (instead of being JS-injected on DOMContentLoaded). No flash-of-content-before-loader on slow product pages with large hero images.
- **Shop-by-category column** in the footer — auto-generated from the top-level WC product categories, with item counts and a "View all collections →" link. Customizer-toggleable; respects the operator's preferred limit (3-12 items).
- **Payment-row brand grouping** — multi-method processors like WooPayments collapse from nine adjacent pills (`Card / Alipay / iDEAL / EPS / …`) to one clean "WooPayments" pill with the methods in a tooltip. Footer reads as a brand list, not a clutter of options.
- **Default trust signals** — on WooCommerce stores with the operator hasn't set custom trust copy, the footer auto-fills "Secure checkout · Worldwide shipping · 30-day returns" so day-one launch never looks bare.

### 1.6.3 — Health pass on top of the 1.6.0 mobile sprint
Hub redirects, slug-collision fuzzy match, smart filter sidebar (replaces the redundant Categories list with WC's native attribute widgets), blog page auto-promotion, Elementor canvas template (root-cause fix for "some old posts won't open"), parent-term archive enrichment, footer enhancements (9 platform SVG social icons + newsletter form + 3 trust signals + auto WC payment row + dedicated Customizer panel), mega menu Customizer panel (threshold + columns + counts + mobile mode + blog auto-inject toggle), archive chip strip default-OFF, "You may also enjoy" duplicate suppression.

### 1.6.0 — Full Mobile Spec Preview v2 implementation
4 new editorial templates (`404.php`, `search.php`, `page-contact.php`, `page-about.php`, `single-master.php`), reading-progress bar on single posts, audio-card play state, sticky checkout bars on cart and checkout, drawer search consolidation (single-search-entry-point UX). 1240-line `@media` layer covering all 13 design sections from the handoff brief. Twelve `apply_filters('luwipress_gold_*')` hooks expose every editorial content array for child-theme overrides.

### 1.5.x — Atelier-ledger drawer + design-handoff polish
Atelier-ledger mobile drawer (warm cream, paper-grain texture, italic-serif categories, ±28 px circular plus/minus glyph, AI search field, 3-column utility row, "Atelier pick" featured-product card, language pills + social row in the footer bay, stagger entrance, prefers-reduced-motion fallback), drawer / scrim z-index hardening above the WordPress admin bar, mobile header switched to a `display: grid; grid-template-columns: auto 1fr auto` layout (hamburger / logo / actions) so the strip never wraps to two rows when JavaScript injects a fourth element, mega menu walker WPML/Polylang strip.

---

## 🎯 Core Modules

### 1. Elementor Kit (12 screens, ready to import)

A complete kit that activates on theme install:

- **Homepage** — hero + featured categories + editorial grid + master profile + journal teaser
- **Shop archive** — banner image + featured sub-category tiles + WC product loop
- **Single product** — gallery + summary + sticky add-to-cart bar + tabs + related rail
- **About** — story page with editorial grid + master profile + timeline
- **Master profile** — artisan / maker bio template (used for `_lwp_gold_maker` field)
- **Journal archive + single** — blog templates with eyebrow, author meta, reading-time, tag pills
- **Contact** — form-ready page with location + hours blocks
- **404** — branded editorial fallback with search input + popular-categories rail
- **Header** — desktop mega menu + sticky bar + logo + language pill + topbar
- **Footer** — multi-column with newsletter + social + cart links + shop categories
- **Animations** — entrance / hover / parallax presets
- **Global tokens** — Playfair Display + Inter + JetBrains Mono, gold accent palette

The kit auto-syncs Elementor Site Settings to the Gold palette on activation; operators can re-import or override any screen individually.

### 2. Custom Elementor Widgets (8)

Gold ships its own widget category in the Elementor sidebar:

- **Mega Menu** — adaptive menu builder with banner head, count pills, "View all →" CTA. Renders simple dropdowns when a parent has 1–3 children, full mega panels for 4+ children or third-level nesting. Configurable per-item cardinality threshold.
- **Megabar** — sub-category strip that auto-builds from the WC product taxonomy; collapsible, scrollable on mobile.
- **Hero** — split-column hero with overlay gradient, eyebrow / headline / sub / CTA fields.
- **Editorial Grid** — magazine-style card grid for blog posts or any post type.
- **Master Profile** — artisan bio card with avatar + headline + body + CTA.
- **Timeline** — vertical milestone strip for about / story pages.
- **Info Bar** — three-column trust strip (free shipping / warranty / secure checkout) usable on any page.
- **Product Card** — Gold's product loop card (also used by the WooCommerce loop override): eyebrow category + Playfair name + italic maker line + gold serif price ladder + sale badge.

All widgets live under `Elementor → LuwiPress Gold` and inherit theme tokens automatically.

### 3. AI Surface (LuwiPress integration)

Activates when LuwiPress core 3.1.45+ is detected — visible on every page:

- **AI search suggestions** — the search overlay queries LuwiPress for natural-language autocompletes pulled from the live catalogue, not just title-prefix matches. "Loud middle-eastern drum" finds darbukas; "starter santur for kids" surfaces the right SKUs.
- **Knowledge-Graph related rail** — single-product pages render an AI-curated "you may also like" rail driven by the LuwiPress KG (taxonomy proximity + price band + master/maker overlap), not the WC default "same category, random order" stub.
- **Static-copy enrichment** — about / journal / contact pages pull AI-generated copy from the LuwiPress AI engine via the smart content compiler; placeholders like `{site_name}`, `{primary_category}`, `{master_count}` resolve from live data on render.
- **Customer chat widget** — Gold defers the storefront chat UI to the LuwiPress core widget so the operator owns one chat experience across themes. Theme just ensures placement + responsive sizing.

### 4. Native Customizer Panel (8 sections, live preview)

`Görünüm → Özelleştir → LuwiPress Gold` exposes:

- **Brand** — logo accent letter (the wordmark italic-gold flourish), palette tokens, typography fallbacks
- **Topbar** — location, phone, email, promo strip, track-order URL + label, language pill toggle
- **Header** — logo type (text / image), sticky toggle, topbar visibility per breakpoint
- **Mega Menu** — menu picker, threshold (when to switch from dropdown to full panel), column count preference, count badges on/off, mobile mode (drawer / accordion), blog auto-inject toggle
- **Footer** — 9 social platform URLs (Instagram, Facebook, YouTube, WhatsApp, Pinterest, TikTok, X, LinkedIn, Spotify), newsletter form action URL + field name + headline + blurb, 3 trust signal lines, auto WC payment-method row toggle, "Shop" categories column toggle + item count limit (3-12)
- **Animation** — page loader on/off, scroll reveal on/off, cart bump on/off
- **Performance** — page/category slug conflict resolver toggle (with live conflict count)

Live preview reflects every change without page reload. Settings persist as theme mods (no extra DB table).

### 5. Ecosystem Dashboard

`Görünüm → LuwiPress Gold` opens a single-page admin dashboard summarising the active integration:

- **Theme version + LuwiPress core version** side by side, with an "out of sync" warning if the core is older than 3.1.45 (theme companion contract baseline)
- **Capability matrix** — which storefront features are live based on the `luwipress_theme_companion` filter contract (AI search · KG rail · YouTube modal · sub-category tiles · master overlay · migration tool)
- **Friendly plugin pills** — green / red status per supported plugin (Elementor, WooCommerce, WPML / Polylang, Rank Math / Yoast, LiteSpeed / WP Rocket, Mailchimp / Brevo)
- **Quick links** — to Elementor Kit import, Customizer, Migration tool, LuwiPress AI settings

### 6. Slug-Conflict Resolver (5-pass discovery)

Purpose-built for the most painful WooCommerce takeover scenario: the legacy site has static page URLs (`/string-instruments/`, `/percussions/`) that should be product-category archives. Gold redirects every visitor to the canonical archive without deleting the legacy pages — they stay in the database, editable from admin, just orphaned from the live navigation flow.

Five discovery passes run in order, each catching a different shape of collision:

1. **Exact slug match** — page slug equals term slug. Catches obvious cases like `/percussions/` vs the `percussions` term.
2. **WPML / Polylang cross-language** — translated page slugs (`/es/percusiones/`, `/fr/vents/`) match against the term in *any* language via the translation table. Without this, multilingual stores miss every translated page.
3. **Plural and prefix fuzzy** — `arabic-oud` page → `arabic-ouds` term, `persian-kamancheh` page → `persian-kamancheh-kemenches` term. Single-candidate gate prevents false positives.
4. **Levenshtein-1 fuzzy** — `classical-kemence` page ↔ `classical-kemenche` term (a one-character translit nuance), `saz-baglama-accessories` page ↔ `saz-baglama-acessories` term (an operator typo). 6-character minimum + single-candidate gate keeps the safety net tight.
5. **Menu-parent inheritance** — a page with no direct term match but registered as a child menu item under a category in the menu inherits its parent's archive. So a `/santur/` editorial page sitting under "String Instruments" in the menu auto-redirects to the `string-instruments` archive.

System pages (cart, checkout, my-account, privacy, front, posts) are detected at runtime and excluded — no operator-set custom slug needs special handling.

Operator opts in via Customizer → LuwiPress Gold → Performance. Reversible by flipping the toggle off.

### 7. WooCommerce Storefront Polish

- **Branded cart-empty page** (1.6.4) — when the cart is empty, visitors see an editorial layout with icon + headline + "Browse the collection" + "My orders" CTAs + 3-product best-seller rail. No more blank middle between header and footer.
- **Product card override** — Gold's `content-product.php` template replaces WC's default. Eyebrow category, italic maker line (pulled from `pa_master` / `pa_maker` taxonomy or `_lwp_gold_maker` post-meta), Playfair name, gold serif price ladder, sale percentage badge top-left. Entire card is one anchor → product page (no inline buttons fighting for tap-target).
- **Loop grid normalisation** — `minmax(0, 1fr)` instead of plain `1fr` so cards don't collapse to ~70 px wide when WC ships raw `<img width="600">` markup. Responsive 4 → 3 → 2 → 1 across `1100px / 700px / 600px` breakpoints.
- **PDP sticky add-to-cart bar** — fades in when the native cart button scrolls off, fades back out when the footer enters view (IntersectionObserver handoff).
- **Sale badge** — small mono-font percentage chip, accent-red, top-left of every card on sale.
- **Cart drawer** — slide-in panel from the right; auto-opens after `added_to_cart` jQuery event, content driven by the live `woocommerce_mini_cart()` fragment so quantity / removal updates over AJAX without page reload.
- **My-account polish** — orders pill statuses, address card grid, account-stat tiles (orders count, lifetime spend, saved addresses).
- **Account popover** — header account icon opens a smart panel: logged-out users get a quick-login form + "Why sign in" perks; logged-in users get greeting + dashboard / orders / addresses / sign-out links. No native WC `[woocommerce_my_account]` redirect needed.
- **Smart filter sidebar** (1.6.3) — auto-discovers WC attribute taxonomies (every `pa_*` your store has registered) and renders them as native WC layered nav widgets, plus price filter + tag cloud + on-sale / in-stock toggles. Replaces the redundant Categories list (already in the mega menu).
- **YouTube modal** — any link with `data-lwp-yt="VIDEO_ID"` opens an in-page modal lightbox instead of redirecting to youtube.com. Esc closes; modal is fully accessible.
- **Pagination → Gold** — circular pills with mono-font numerals, active pill in dark accent.

### 8. Header (no theme builder needed)

When Elementor Pro Theme Builder is **not** active, Gold renders a complete fallback header inline:

- **Topbar** — location / phone / email on the left; language pill + track-order link + promo strip on the right. WPML / Polylang detected automatically; falls back silently on single-language sites.
- **Sticky bar** — logo (custom logo upload OR stylised wordmark with italic-gold accent letter) + adaptive mega menu + icon buttons (search ⌕, account ◯, cart ▣ with live badge count).
- **Search overlay** — full-screen panel with AI suggestions wired in (LuwiPress 3.1.45+).
- **Mobile drawer (1.5.x)** — atelier-ledger styling with paper-grain texture, italic-serif categories, ±28 px plus/minus glyphs, AI search field, 3-column utility row, "Atelier pick" featured-product card. Z-index above WordPress admin bar; sticky headers drop to z-index 1 with `pointer-events: none` while drawer is open.
- **Mobile header layout (1.5.3)** — single-row 3-column grid: hamburger left, logo centre with `max-width: 60vw + max-height: 36px` cap, action icons right. Never wraps to two rows even when JavaScript injects a fourth element.

When Elementor Pro Theme Builder **is** active and a Header template is set, Gold yields to it — no double-rendering.

### 9. Mobile Editorial Templates (1.6.0)

Five templates rebuilt from the design handoff:

- **404.php** — editorial fallback with branded message, search input, popular-categories rail. No "Page not found" generic.
- **search.php** — sticky search bar at top + result-type tabs (Products / Masters / Journal) + image-left result rows. AJAX-ready when active filter is set via `?type=` query var.
- **page-about.php** — story-page layout with editorial grid + master profile blocks + timeline.
- **page-contact.php** — form-ready with location + hours blocks + map placeholder.
- **single-master.php** — artisan / maker profile page with cover image, bio, products by master, related masters.

12 `apply_filters('luwipress_gold_*')` hooks expose every editorial content array for child-theme overrides — operators don't need to fork the templates to localise text.

### 10. Page Loader (real page-ready event driven)

The on-page loader overlay (1.6.4+, real-event-driven in 1.6.7) covers the viewport with the brand mark + spinning arc + progress bar until the page is genuinely ready:

1. Server-rendered into the DOM at `<body>` open — no flash-of-unstyled-content
2. Dismisses on the actual readiness chain: `window.load` → web fonts ready → above-the-fold images complete → 100 ms stabilisation tick
3. Force-reveals every below-the-fold reveal-animation element at the same instant — visitors don't scroll into invisible content
4. 5-second failsafe in the inline boot script — only fires if the JavaScript breaks entirely

Operator can disable globally via Customizer → LuwiPress Gold → Animation → Page loader overlay.

### 11. Performance & Compatibility

- **Lean ZIP** — 379 KB total, 101 files. No bundled jQuery plugins, no heavyweight slider library — uses native CSS scroll-snap + `<details>` accordions where possible.
- **Critical CSS reset** — inlined inside `wp_head` at priority 9999 so the page paints with brand tokens before async stylesheets load.
- **Asset enqueue priority** — theme CSS at priority 9999 wins against generic plugin styles without `!important` arms races.
- **Dynamic version constant** — cache-buster query strings auto-update on every Version line bump in `style.css`. Stores never see "uploaded the new ZIP but visitors stuck on old assets".
- **LuwiPress soft-paired** — theme works on its own (Elementor + WooCommerce); LuwiPress just lights up the AI surface when present. No hard-dep activation block.
- **WPML / Polylang aware** — language switcher in topbar + drawer foot, auto-detected; works on monolingual stores too. Slug-conflict resolver, mega menu, breadcrumbs, all language-prefix-aware.
- **WooCommerce native** — uses standard hooks (`woocommerce_before_main_content`, `woocommerce_after_shop_loop`, etc.); replaces only `content-product.php`, `cart.php`, `cart-empty.php`, `form-checkout.php`, `single-product.php`, `archive-product.php`, `myaccount/*` templates. Compatible with WC plugins that hook the standard surfaces.
- **Elementor Pro template-include override** — posts carrying the `elementor_header_footer` page template (common after Hello Elementor migration) are routed through Gold's `<main>`-wrapped canvas instead of Elementor's bare bundled file.
- **Detected system-page skip list** — WordPress privacy page, WC shop / cart / checkout / my-account / terms, front and posts pages are pulled from runtime configuration, not hardcoded. Translated stores work without operator override.

### 12. Migration Wizard (first-run setup)

`Görünüm → LuwiPress Gold → Wizard` (auto-launches on first activation):

- Detects existing theme on switch; offers to import the matching Elementor Kit screen (homepage, shop, etc.) without overwriting any post that already has Elementor data.
- Sets up the 12-JSON kit + Site Settings + global tokens in one click.
- Resolves slug conflicts inline (no separate Migration tool visit needed).
- Skippable; nothing destructive runs without explicit operator confirmation.

---

## 🔌 Plugin Integrations

| Category | Recognised Plugins | What Gold Does |
|----------|--------------------|----------------|
| Page Builder | **Elementor** (free + Pro) | 12-JSON Kit, 8 custom widgets, optional Theme Builder yield, page-templates override |
| Commerce | **WooCommerce** | 7 template overrides (loop, single, cart, cart-empty, checkout, my-account, archive), branded empty states |
| Translation | **WPML**, **Polylang** | Language pill auto-detection, drawer foot lang, hreflang via core plugin, cross-language slug-conflict resolution |
| AI Layer | **LuwiPress** core | AI search · KG rail · static copy · customer chat |
| SEO | Rank Math, Yoast, AIOSEO | Read meta for breadcrumb (no writes from theme — core plugin owns SEO) |
| Cache | LiteSpeed, WP Rocket | Marker-free CSS layers (LS minify-safe), critical CSS inlined at p9999, no-cache headers stamped on REST 200s |
| Newsletter | Mailchimp, ConvertKit, Brevo, native Subscribe | Footer newsletter form auto-detects field name + action URL conventions |

---

## 📐 Design System (tokens)

Theme exposes a uniform token system at `:root` — usable from any custom CSS:

| Token | Default | Purpose |
|-------|---------|---------|
| `--ink` | `#1b1c1c` | Primary text colour |
| `--ink-soft` | `#4d4635` | Secondary text |
| `--muted` | `#7f7663` | Tertiary / metadata |
| `--primary` | `#735c00` | Gold accent (links, prices, active states) |
| `--primary-light` | `#D4AF37` | Highlight / subtle gold |
| `--sale` | `#a33b3e` | Sale badges, error states |
| `--card` | `#fff` | Card background |
| `--bg-alt` | `#f6f3f2` | Alt background, hover states |
| `--line` | `#e8e2d3` | Default border |
| `--line-light` | `#f0ebe0` | Subtle separator |
| `--serif` | `Playfair Display, Georgia` | Display + product names + prices |
| `--sans` | `Inter, system-ui` | Body + UI |
| `--mono` | `JetBrains Mono, monospace` | Eyebrow / metadata / numerals |
| `--content-w` | `1372px` | Max content width |

All tokens override-able from Customizer → LuwiPress Gold → Brand.

---

## 🚀 Why LuwiPress Gold?

- **Ecosystem-integrated** — surfaces LuwiPress AI features on the storefront automatically; no shortcode or widget setup required
- **Standalone-capable** — runs on Elementor + WooCommerce without LuwiPress; the AI surface just goes silent rather than breaking
- **Mobile-complete** — full responsive layer (1.5.x → 1.6.0) covering header, navigation, PDP, shop, cart drawer, footer, editorial templates
- **Migration-safe** — 5-pass slug-conflict resolver handles the most painful WooCommerce takeover scenario losslessly, WPML- and Polylang-aware. Customer's legacy pages stay in the database — theme just routes visitors to the canonical archive.
- **Performance-aware** — 519 KB ZIP, critical CSS inline, dynamic cache-buster, marker-free layered styles compatible with LiteSpeed / WP Rocket minify
- **Customizer-first** — branded, live-preview customisation panel with 8 sections; no extra plugin needed
- **Loader does what it promises** — page-ready signal is the actual readiness chain, not a stopwatch. Visitors never see the loader vanish to a half-rendered page.
- **No-lock-in** — uses standard WP / WC / Elementor APIs; deactivation reverts to your previous theme cleanly

---

*Document version 1.7.11 — updated 2026-05-12 · Pairs with LuwiPress core 3.1.45+ (3.1.52 recommended for full Knowledge Graph Autopilot + chat session bootstrap fix). For the LuwiPress core plugin's full feature list, see the separate **LUWIPRESS-FEATURES.md** document.*
