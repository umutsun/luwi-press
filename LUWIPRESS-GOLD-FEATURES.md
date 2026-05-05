# LuwiPress Gold — Theme Feature Overview

**Version:** 1.5.1 · **License:** GPLv2+ · **Target:** WooCommerce stores using LuwiPress

LuwiPress Gold is the first **ecosystem-integrated** WordPress theme: built specifically to surface the LuwiPress AI plugin's intelligence on the storefront. Where most themes stop at layout and typography, Gold turns AI Knowledge Graph signals, customer chat, plugin-detection, and live search suggestions into first-class store features the visitor actually feels.

Ships as a lean **289 KB ZIP** with a 12-screen Elementor Kit, six custom Elementor widgets, native Customizer panel, and a slug-conflict migration tool — paired with the LuwiPress core plugin (3.1.45+) for full reciprocal awareness.

---

## 🆕 What's new in 1.5.1

- **Atelier-ledger mobile drawer** — the slide-in mobile menu got a full visual upgrade based on the Tapadum design handoff. Warm cream background (not the restaurant black-with-gold trope), italic-serif category names with a circular plus/minus glyph instead of generic chevrons, hairline-gold rules between rows, and a perf-safe paper-grain dot pattern under everything that gives the panel a "luthier ledger" feel. Stagger entrance for the L1 rows when the drawer opens. AI-aware search field at the top with a gold focus ring, a single-thumb-zone utility row (Account · Cart · Track) with the cart count badge, an "Atelier pick" featured-product card pulled from the page (or the operator-defined `window.LuwiGold.drawer.pick`), and a warm-deeper-bg footer bay for language pills, social row, and contact line. Falls back gracefully on `prefers-reduced-motion`.
- **Hamburger inject across all header rendering paths** — the toggle button now finds a host inside Gold's fallback header, an Elementor Pro Theme Builder header, or any generic theme header — so the drawer works on stores that override the header template.
- **Mega menu now strips WPML / Polylang language switcher items** — operators saw "Español" pop up as a fake top-level menu entry next to real categories. Topbar already surfaces languages as a pill, so the mega-menu walker filters those items out at render time (no menu structure change required).

## 🆕 What's new in 1.5.0

- **Full mobile responsive layer** — Gold finally answers the "how does this look on a phone?" question. A slide-in hamburger drawer (built from your existing nav menu) replaces the desktop mega menu under 900px, with accordion sub-categories, the language switcher pinned to the drawer footer, and a translucent scrim that closes on click. Product detail pages stack the gallery above the summary, the WooCommerce tabs flex-wrap into a clean grid, the cart drawer goes full-width, and the footer columns collapse into a single readable stack. Card meta paddings tighten further at 600px so the image gets maximum width on narrow screens.
- **Smooth float-bar ↔ footer handoff** — the sticky "Add to cart" bar on product pages used to collide with the footer header — visually messy, looked like a layout bug. An IntersectionObserver now watches the footer; the moment it enters the viewport, the float bar fades + slides out smoothly. The bar itself also picked up a frosted backdrop blur and a softer shadow for a more modern "floating panel" look while it is on screen.
- **Mega menu viewport-aware reflow** — wide mega panels (4+ columns) used to clip into the left edge on viewports narrower than the menu's natural width (~1100px). The panel now grows up to a content cap, but is clamped to `viewport - 48px` so it never overflows. Trailing menu items (Blog, Español, etc.) flip their simple dropdowns to right-anchored so they don't push off the edge either.
- **Header menu typography normalisation** — single-word menu entries like "Blog" and "Español" used to render in the WP nav defaults (mixed case, no letter-spacing) while the rest of the menu shipped with Gold's uppercase rhythm. Every top-level link now locks to the same cadence (uppercase, 0.08em tracking, weight 600, 13px) so the bar reads as one consistent strip.
- **Product card meta padding** — chip + title + price used to sit flush against the card's border. They now inset 14/16px (12/14px on mobile) so text breathes inside the card the same way the featured-product card already does, with a small margin between the category chip and the title.

---

## 🎯 Core Modules

### 1. Elementor Kit (12 screens, ready to import)

A complete kit that activates on theme install:

- **Homepage** — hero + featured categories + editorial grid + master profile + journal teaser
- **Shop archive** — banner image + featured sub-category tiles + WC product loop
- **Single product** — gallery + summary + sticky add-to-cart bar + tabs + related rail
- **About** — story page with editorial grid + master profile + timeline
- **Master profile** — artisan / maker bio template (used for `_lwp_gold_maker` field)
- **Journal** — blog post archive + single template
- **Contact** — form-ready page with location + hours blocks
- **404** — branded fallback with search input + popular-categories rail
- **Header** — desktop mega menu + sticky bar + logo + language pill + topbar
- **Footer** — multi-column with newsletter + social + cart links
- **Animations** — entrance / hover / parallax presets
- **Global tokens** — Playfair Display + Inter + JetBrains Mono, gold accent palette

The kit auto-syncs Elementor Site Settings to the Gold palette on activation; operators can re-import or override any screen individually.

### 2. Custom Elementor Widgets (6)

Gold ships its own widget category in the Elementor sidebar:

- **Mega Menu** — adaptive menu builder; renders simple dropdowns when a parent has 1–3 children, full mega panels for 4+ children or third-level nesting. Configurable per-item cardinality threshold.
- **Megabar** — sub-category strip that auto-builds from the WC product taxonomy; collapsible, scrollable on mobile.
- **Hero** — split-column hero with overlay gradient, eyebrow / headline / sub / CTA fields.
- **Editorial Grid** — magazine-style card grid for blog posts or any post type.
- **Master Profile** — artisan bio card with avatar + headline + body + CTA.
- **Timeline** — vertical milestone strip for about / story pages.
- **Info Bar** — three-column trust strip (free shipping / warranty / secure checkout) usable on any page.
- **Product Card** — Gold's product loop card (also used by the WooCommerce loop override) — eyebrow category + Playfair name + italic maker line + gold serif price ladder + sale badge.

All widgets live under `Elementor → LuwiPress Gold` and inherit theme tokens automatically.

### 3. AI Surface (LuwiPress integration)

Activates when LuwiPress core 3.1.45+ is detected — visible on every page:

- **AI search suggestions** — the search overlay queries LuwiPress for natural-language autocompletes pulled from your live catalogue, not just title-prefix matches. "Loud middle eastern drum" finds your darbukas; "starter santur for kids" surfaces the right SKUs.
- **Knowledge-Graph-related rail** — single-product pages render an AI-curated "you may also like" rail driven by the LuwiPress KG (taxonomy proximity + price band + master/maker overlap), not the WC default "same category, random order" stub.
- **Static-copy enrichment** — about / journal / contact pages can pull AI-generated copy from the LuwiPress AI engine via the smart content compiler; placeholders like `{site_name}`, `{primary_category}`, `{master_count}` resolve from live data on render.
- **Customer chat widget** — Gold defers the storefront chat UI to the LuwiPress core widget so the operator owns one chat experience across themes. Theme just ensures placement + responsive sizing.

### 4. Native Customizer Panel (6 sections, live preview)

`Görünüm → Özelleştir → LuwiPress Gold` exposes:

- **Brand** — logo accent letter (the wordmark italic-gold flourish), palette tokens, typography fallbacks
- **Topbar** — location, phone, email, promo strip, track-order URL + label, language pill toggle
- **Mega Menu** — cardinality threshold (when to switch from dropdown to full panel), column count preference, count badges on/off
- **Newsletter** — shortcode dropdown (auto-detects Mailchimp / Brevo / Fluent Forms / native Subscribe), placement
- **Footer** — column count, social links, newsletter card on/off
- **Performance** — critical CSS toggle, deferred JS for non-PDP pages

Live preview reflects every change without page reload. Settings persist as theme mods (no extra DB table).

### 5. Ecosystem Dashboard

`Görünüm → LuwiPress Gold` opens a single-page admin dashboard summarising the active integration:

- **Theme version + LuwiPress core version** side by side, with an "out of sync" warning if the core is older than 3.1.45 (theme companion contract baseline)
- **Capability matrix** — which storefront features are live based on `luwipress_theme_companion` filter contract (AI search · KG rail · YouTube modal · sub-category tiles · master overlay · migration tool)
- **Friendly plugin pills** — green / red status per supported plugin (Elementor, WooCommerce, WPML / Polylang, Rank Math / Yoast, LiteSpeed / WP Rocket, Mailchimp / Brevo)
- **Quick links** — to Elementor Kit import, Customizer, Migration tool, LuwiPress AI settings

### 6. Slug-Conflict Migration Tool

`Görünüm → LuwiPress Migration` — purpose-built for the eCommerce slug-collision pain point that breaks legacy stores moving to WooCommerce:

- **Type A — WC archive collision rename**: when a WooCommerce category slug collides with an existing post-type or page slug. Lossless — the tool walks WPML/Polylang translation pairs in lockstep, renames every conflicting term + post slug, writes redirects from old → new, and stores a full restore manifest.
- **Type B — canonical shadow swap**: when the operator wants to swap which slug becomes canonical (e.g. `/percussion/` → `/percussions/`) without losing SEO equity. Tool rewrites canonicals + WPML language links + redirect chain.
- **Restore button** — every migration is reversible; the tool keeps the manifest until explicitly cleared. WPML-aware throughout.

### 7. WooCommerce Storefront Polish

- **Product card override** — Gold's `content-product.php` template replaces WC's default. Eyebrow category, italic maker line (pulled from `pa_master` / `pa_maker` taxonomy or `_lwp_gold_maker` post-meta), Playfair name, gold serif price ladder, sale percentage badge top-left. Entire card is one anchor → product page (no inline buttons fighting for tap-target).
- **Loop grid normalisation** — `minmax(0, 1fr)` instead of plain `1fr` so cards don't collapse to ~70px wide when WC ships raw `<img width="600">` markup. Responsive 4 → 3 → 2 → 1 across `1100px / 700px / 600px` breakpoints.
- **Pagination → Gold** — circular pills with mono-font numerals, active pill in dark accent.
- **PDP sticky add-to-cart bar** — fades in when the native cart button scrolls off, fades back out when the footer enters view (1.5.0 IntersectionObserver handoff).
- **Sale badge** — small mono-font percentage chip, accent-red, top-left of every card on sale.
- **Cart drawer** — slide-in panel from the right; auto-opens after `added_to_cart` jQuery event, content driven by the live `woocommerce_mini_cart()` fragment so quantity / removal updates over AJAX without page reload.
- **My-account polish** — orders pill statuses, address card grid, account-stat tiles (orders count, lifetime spend, saved addresses).
- **Account popover** — header account icon opens a smart panel: logged-out users get a quick-login form + "Why sign in" perks; logged-in users get greeting + dashboard / orders / addresses / sign-out links. No native WC `[woocommerce_my_account]` redirect needed.
- **YouTube modal** — any link with `data-lwp-yt="VIDEO_ID"` (or a YouTube URL inside the attribute) opens an in-page modal lightbox instead of redirecting to youtube.com. Esc closes; modal is fully accessible.

### 8. Header (no theme builder needed)

When Elementor Pro Theme Builder is **not** active, Gold renders a complete fallback header inline:

- **Topbar** — location / phone / email on the left; language pill + track-order link + promo strip on the right. WPML / Polylang detected automatically; falls back silently on single-language sites.
- **Sticky bar** — logo (custom logo upload OR stylised wordmark with italic-gold accent letter) + adaptive mega menu + icon buttons (search ⌕, account ◯, cart ▣ with live badge count).
- **Search overlay** — full-screen panel with AI suggestions wired in (LuwiPress 3.1.45+).
- **Mobile drawer (1.5.0)** — slide-in from the left under 900px, accordion sub-categories with `+`/`−` indicators, language pills in the drawer footer, animated hamburger icon, scrim overlay, Esc / scrim-click close.

When Elementor Pro Theme Builder **is** active and a Header template is set, Gold yields to it — no double-rendering.

### 9. Performance & Compatibility

- **Lean ZIP** — 281 KB total, 85 files (1.5.0). No bundled jQuery plugins, no heavyweight slider library — uses native CSS scroll-snap + `<details>` accordions where possible.
- **Critical CSS reset** — inlined inside `wp_head` at priority 9999 so the page paints with brand tokens before async stylesheets load.
- **Asset enqueue priority** — theme CSS at priority 9999 wins against generic plugin styles without `!important` arms races.
- **LuwiPress soft-paired** — theme works on its own (Elementor + WooCommerce); LuwiPress just lights up the AI surface when present. No hard-dep activation block.
- **WPML / Polylang aware** — language switcher in topbar + drawer foot, auto-detected; works on monolingual stores too.
- **WooCommerce native** — uses standard hooks (`woocommerce_before_main_content`, `woocommerce_after_shop_loop`, etc.); replaces only `content-product.php` template. Compatible with WC plugins that hook the standard surfaces.
- **Elementor compat layer** — small bridge in `inc/elementor-compat.php` ensures Gold's container settings (full-width, boxed, content-w) play nice with Elementor Pro's `<container>` widget on existing pages.

### 10. Migration Wizard (first-run setup)

`Görünüm → LuwiPress Gold → Wizard` (auto-launches on first activation):

- Detects existing theme on switch; offers to import the matching Elementor Kit screen (homepage, shop, etc.) without overwriting any post that already has Elementor data.
- Sets up the 12-JSON kit + Site Settings + global tokens in one click.
- Resolves slug conflicts via the Migration tool inline (no separate visit needed).
- Skippable; nothing destructive runs without explicit operator confirmation.

---

## 🔌 Plugin Integrations

| Category | Recognised Plugins | What Gold Does |
|----------|--------------------|----------------|
| Page Builder | **Elementor** (free + Pro) | 12-JSON Kit, 6 custom widgets, optional Theme Builder yield |
| Commerce | **WooCommerce** | `content-product.php` override, loop normalisation, cart drawer, account popover |
| Translation | **WPML**, **Polylang** | Language pill auto-detection, drawer foot lang, hreflang via core plugin |
| AI Layer | **LuwiPress** core | AI search · KG rail · static copy · customer chat |
| SEO | Rank Math, Yoast, AIOSEO | Read meta for breadcrumb (no writes from theme — core plugin owns SEO) |
| Cache | LiteSpeed, WP Rocket | Marker-free CSS layers (LS minify-safe), critical CSS inlined at p9999 |

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
- **Mobile-complete (1.5.0)** — full responsive layer covering header, navigation, PDP, shop, cart drawer, footer
- **Migration-safe** — built-in slug-conflict tool handles the most painful WooCommerce takeover scenario losslessly, WPML-aware
- **Performance-aware** — 281 KB ZIP, critical CSS inline, marker-free layered styles compatible with LiteSpeed / WP Rocket minify
- **Customizer-first** — branded, live-preview customisation panel; no extra plugin needed
- **No-lock-in** — uses standard WP / WC / Elementor APIs; deactivation reverts to your previous theme cleanly

---

*Document version 1.5.1 — updated 2026-05-06 · Pairs with LuwiPress core 3.1.45+ (3.1.47 recommended for full Knowledge Graph Autopilot integration). For the LuwiPress core plugin's full feature list, see the separate **LUWIPRESS-FEATURES.md** document.*
