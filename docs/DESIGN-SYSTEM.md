# Luwi Design System v1.0 — Multi-Theme Architecture

> One framework. Three personalities. Total WordPress control.

## Philosophy

Every Luwi theme shares the **same skeleton** — identical CSS variable names, component
classes, Elementor widgets, WooCommerce templates, and accessibility layer. What changes
is the **personality layer**: colors, typography, shape language, motion, shadows, and
visual treatments. A user can switch from Gold to Emerald without breaking a single
page — only the mood changes.

---

## 1. Shared Skeleton (Invariants)

These are **identical** across Gold, Emerald, and Ruby:

### Token Categories (CSS Custom Properties)
```
--luwi-primary, --luwi-primary-hover, --luwi-primary-light, --luwi-primary-bg
--luwi-primary-container, --luwi-accent, --luwi-accent-hover
--luwi-text, --luwi-text-light, --luwi-text-muted, --luwi-text-inverse
--luwi-bg, --luwi-bg-alt, --luwi-surface, --luwi-surface-hover, --luwi-surface-dim
--luwi-border, --luwi-border-light
--luwi-success, --luwi-error, --luwi-warning, --luwi-info, --luwi-sale
--luwi-font-heading, --luwi-font-headline, --luwi-font-body, --luwi-font-mono
--luwi-shadow-sm/md/lg/xl/hover/inner
--luwi-radius-sm/md/lg/xl/full
--luwi-ease, --luwi-ease-spring, --luwi-duration-fast/normal/slow/image
```

### Typography Scale (identical sizes)
```
xs: 0.75rem | sm: 0.875rem | base: 1rem | lg: 1.125rem | xl: 1.375rem
2xl: 1.625rem | 3xl: 2.25rem | 4xl: 3rem | 5xl: 3.75rem
```

### Spacing Scale (identical)
```
xs: 0.25rem | sm: 0.5rem | md: 1rem | lg: 1.5rem | xl: 2.5rem
2xl: 4rem | 3xl: 6rem | 4xl: 8rem
```

### Layout (identical)
```
container-sm: 720px | container-md: 960px | container-lg: 1200px | container-xl: 1400px
header-height: 72px
```

### Z-Index (identical)
```
dropdown: 100 | sticky: 200 | overlay: 300 | modal: 400 | toast: 500
```

### Component API (identical class names)
```css
.luwi-btn, .luwi-btn--primary, .luwi-btn--outline, .luwi-btn--accent, .luwi-btn--sm, .luwi-btn--lg
.luwi-card, .luwi-card__image, .luwi-card__body
.luwi-input, .luwi-input--boxed
.luwi-container, .luwi-container--wide, .luwi-container--narrow
.luwi-section, .luwi-section--alt
.luwi-glass
.luwi-label, .luwi-headline
.luwi-hover-lift, .luwi-img-zoom
.luwi-reveal-pending, .luwi-revealed
.luwi-color-mode-toggle
.luwi-back-to-top
```

### Accessibility (identical)
- WCAG 2.1 AA contrast ratios
- Skip link, screen-reader-text
- :focus-visible keyboard outlines
- prefers-reduced-motion support
- data-reduce-motion manual toggle
- data-high-contrast mode
- forced-colors media query

### Dark Mode System (identical mechanism)
- `data-color-mode="dark"` attribute on `<html>`
- `@media (prefers-color-scheme: dark)` fallback
- All themes override same token set in dark mode

### Elementor Widgets (shared)
- Widget category: `luwi-widgets`
- Base class: `Luwi_Widget_Base`
- Widgets: Trust Badges, Color Mode Toggle, Countdown, Category Showcase, Product Card

### WooCommerce Templates (shared)
- archive-product, single-product, content-product
- cart, cart-empty, mini-cart
- form-checkout, thankyou, payment
- dashboard, form-login, orders, view-order, form-edit-account, form-edit-address

---

## 2. Personality Layer (What Changes Per Theme)

Each theme defines its unique character through these "personality dials":

| Dial | What It Controls |
|------|-----------------|
| **Color Palette** | primary, accent, bg warmth, dark mode tint, sale color |
| **Typography Pairing** | heading font + body font combination |
| **Shape Language** | border-radius scale (sharp / crisp / rounded / organic) |
| **Motion Character** | easing curve, duration, hover behavior |
| **Shadow Philosophy** | tint color, diffusion, elevation scale |
| **Hero Archetype** | layout pattern (centered / split / overlay / typographic) |
| **Card Treatment** | shadow style, border accent, hover animation |
| **Button DNA** | fill style, hover transform, shape |
| **Input Style** | underline / boxed / floating-label |
| **Separator Style** | gradient fade / thin line / none |
| **Glass Effect** | backdrop-blur tint color + opacity |

---

## 3. Theme Catalog

### LUWI GOLD — Artisan Heritage

**Mood:** Museum gallery, aged wood, luthier workshop, burnished brass.

| Token | Light | Dark |
|-------|-------|------|
| `--luwi-primary` | `#735c00` Burnished Gold | `#D4AF37` Bright Brass |
| `--luwi-primary-hover` | `#574500` | `#e9c349` |
| `--luwi-primary-light` | `#D4AF37` Polished Brass | `#ffe088` Soft Gold Glow |
| `--luwi-primary-bg` | `rgba(115,92,0,0.07)` | `rgba(212,175,55,0.12)` |
| `--luwi-primary-container` | `#ffe088` | `#574500` |
| `--luwi-accent` | `#545e76` Patina Navy | `#bbc6e2` Soft Navy |
| `--luwi-accent-hover` | `#3c475d` | `#8a9bc0` |
| `--luwi-text` | `#1b1c1c` | `#dcd9d9` |
| `--luwi-text-light` | `#4d4635` | `#d0c5af` |
| `--luwi-text-muted` | `#7f7663` | `#7f7663` |
| `--luwi-text-inverse` | `#f3f0f0` | `#1b1c1c` |
| `--luwi-bg` | `#fcf9f8` Warm Parchment | `#1A1614` Warm Charcoal |
| `--luwi-bg-alt` | `#f6f3f2` | `#231f1b` |
| `--luwi-surface` | `#ffffff` | `#2a2522` |
| `--luwi-surface-hover` | `#f0eded` | `#352f2a` |
| `--luwi-surface-dim` | `#dcd9d9` | `#1b1c1c` |
| `--luwi-border` | `#d0c5af` Warm | `#4d4635` |
| `--luwi-border-light` | `#eae7e7` | `#352f2c` |
| `--luwi-sale` | `#a33b3e` Crimson Resin | `#e05a5d` |

**Typography:**
- Heading: `'Playfair Display', Georgia, serif`
- Headline: `'Noto Serif', 'Playfair Display', Georgia, serif`
- Body: `'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif`

**Personality:**
- Shape: Crisp — `radius-sm: 4px, md: 8px, lg: 16px, xl: 24px`
- Motion: "Slow and heavy" — `cubic-bezier(0.23, 1, 0.32, 1)`, 500ms hover
- Shadows: Warm amber-tinted `rgba(115,92,0,*)`, grounded elevation
- Hero: Centered editorial, brass gradient CTA
- Cards: No-border, tonal surface shift, shadow-lift hover (+translateY -3px)
- Buttons: Brass gradient glow `linear-gradient(135deg, primary, primary-light)`
- Inputs: Underline (bottom-border only), glow on focus
- Separator: Gradient fade `linear-gradient(to right, transparent, border, transparent)`
- Glass: `rgba(252,249,248,0.7)` warm parchment blur
- Scrollbar: Warm-tinted thin

---

### LUWI EMERALD — Botanical Modern

**Mood:** Greenhouse garden, morning dew, artisan herbs, organic textures.

| Token | Light | Dark |
|-------|-------|------|
| `--luwi-primary` | `#1a5c2a` Deep Forest | `#4a9e5c` Sage Glow |
| `--luwi-primary-hover` | `#0f4420` | `#6abb7b` |
| `--luwi-primary-light` | `#4a9e5c` Sage | `#7fd48f` Mint Glow |
| `--luwi-primary-bg` | `rgba(26,92,42,0.07)` | `rgba(74,158,92,0.12)` |
| `--luwi-primary-container` | `#c8ecd0` | `#0f4420` |
| `--luwi-accent` | `#b5654a` Warm Terracotta | `#d4927a` Soft Clay |
| `--luwi-accent-hover` | `#8f4a35` | `#c07a62` |
| `--luwi-text` | `#1a1c1a` | `#dce0dc` |
| `--luwi-text-light` | `#3d4a3f` | `#b8c4ba` |
| `--luwi-text-muted` | `#6b7a6d` | `#6b7a6d` |
| `--luwi-text-inverse` | `#f0f5f1` | `#1a1c1a` |
| `--luwi-bg` | `#f5faf6` Soft Mint | `#0f1a12` Deep Moss |
| `--luwi-bg-alt` | `#eef5f0` | `#172119` |
| `--luwi-surface` | `#ffffff` | `#1e2d22` |
| `--luwi-surface-hover` | `#e8f0ea` | `#2a3d2e` |
| `--luwi-surface-dim` | `#d4ddd6` | `#141e16` |
| `--luwi-border` | `#b8cdb9` Sage | `#3a5040` |
| `--luwi-border-light` | `#dce8dd` | `#2a3d2e` |
| `--luwi-sale` | `#c0392b` Berry Red | `#e06050` |

**Typography:**
- Heading: `'DM Serif Display', Georgia, serif`
- Headline: `'DM Serif Display', Georgia, serif`
- Body: `'Plus Jakarta Sans', -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif`

**Personality:**
- Shape: Organic — `radius-sm: 8px, md: 12px, lg: 20px, xl: 32px`
- Motion: "Breathing" — `cubic-bezier(0.4, 0, 0.2, 1)`, 400ms, scale-pulse hover
- Shadows: Green-tinted `rgba(26,92,42,*)`, soft neumorphic, diffused
- Hero: Split-screen (text left, image right with organic blob mask)
- Cards: Soft neumorphic shadow + green left-border accent (3px solid primary-light)
- Buttons: Forest-to-sage gradient `linear-gradient(135deg, primary, primary-light)`
- Inputs: Boxed with subtle green bg tint, rounded corners
- Separator: Thin 1px solid at 15% opacity (no gradient — organic simplicity)
- Glass: `rgba(245,250,246,0.75)` mint blur
- Scrollbar: Green-tinted thin

---

### LUWI RUBY — Bold Luxe

**Mood:** Velvet theater, art deco, midnight champagne, confident sophistication.

| Token | Light | Dark |
|-------|-------|------|
| `--luwi-primary` | `#9b1b30` Deep Ruby | `#e8475e` Bright Ruby |
| `--luwi-primary-hover` | `#7a1526` | `#f06a7e` |
| `--luwi-primary-light` | `#e8475e` Rose | `#ffa0b0` Soft Rose Glow |
| `--luwi-primary-bg` | `rgba(155,27,48,0.07)` | `rgba(232,71,94,0.12)` |
| `--luwi-primary-container` | `#ffd9df` | `#7a1526` |
| `--luwi-accent` | `#c9a96e` Champagne Gold | `#e0c88e` Soft Gold |
| `--luwi-accent-hover` | `#a8893e` | `#d4b870` |
| `--luwi-text` | `#1c1a1b` | `#e0dde0` |
| `--luwi-text-light` | `#4a3f44` | `#c4b8be` |
| `--luwi-text-muted` | `#7a6d73` | `#7a6d73` |
| `--luwi-text-inverse` | `#f5f0f2` | `#1c1a1b` |
| `--luwi-bg` | `#faf8f9` Cool Blush | `#18141a` Midnight Plum |
| `--luwi-bg-alt` | `#f4f0f2` | `#211c24` |
| `--luwi-surface` | `#ffffff` | `#2a242e` |
| `--luwi-surface-hover` | `#f0ebee` | `#352e3a` |
| `--luwi-surface-dim` | `#ddd8db` | `#1c1720` |
| `--luwi-border` | `#d4c4ca` Rose Grey | `#4d3f48` |
| `--luwi-border-light` | `#eae4e7` | `#352e3a` |
| `--luwi-sale` | `#d4342a` Flame Red | `#f06050` |

**Typography:**
- Heading: `'Libre Baskerville', 'Times New Roman', serif`
- Headline: `'Libre Baskerville', 'Times New Roman', serif`
- Body: `'Source Sans 3', -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif`

**Personality:**
- Shape: Sharp luxe — `radius-sm: 2px, md: 4px, lg: 8px, xl: 16px`
- Motion: "Dramatic" — `cubic-bezier(0.16, 1, 0.3, 1)`, 350ms, border-glow hover
- Shadows: Plum-tinted `rgba(28,20,26,*)`, dramatic elevation, deep xl
- Hero: Full-bleed image overlay with centered text, dark gradient veil
- Cards: Thin champagne-gold top-border (2px), deep shadow, slight scale hover (+1.02)
- Buttons: Solid ruby, champagne shimmer hover, sharp corners
- Inputs: Floating-label, thin bottom-border, ruby focus glow
- Separator: None — uses full-bleed bg-alt sections (theatrical scene change)
- Glass: `rgba(250,248,249,0.7)` cool blush blur
- Scrollbar: Plum-tinted thin

---

## 4. Token Mapping — Quick Reference

| Token | Gold | Emerald | Ruby |
|-------|------|---------|------|
| `--luwi-primary` | `#735c00` | `#1a5c2a` | `#9b1b30` |
| `--luwi-primary-light` | `#D4AF37` | `#4a9e5c` | `#e8475e` |
| `--luwi-accent` | `#545e76` | `#b5654a` | `#c9a96e` |
| `--luwi-bg` | `#fcf9f8` | `#f5faf6` | `#faf8f9` |
| `--luwi-bg (dark)` | `#1A1614` | `#0f1a12` | `#18141a` |
| `--luwi-sale` | `#a33b3e` | `#c0392b` | `#d4342a` |
| `--luwi-border` | `#d0c5af` | `#b8cdb9` | `#d4c4ca` |
| `--luwi-font-heading` | Playfair Display | DM Serif Display | Libre Baskerville |
| `--luwi-font-body` | Inter | Plus Jakarta Sans | Source Sans 3 |
| `--luwi-radius-sm` | 4px | 8px | 2px |
| `--luwi-radius-md` | 8px | 12px | 4px |
| `--luwi-radius-lg` | 16px | 20px | 8px |
| `--luwi-radius-xl` | 24px | 32px | 16px |
| `--luwi-ease` | `(0.23,1,0.32,1)` | `(0.4,0,0.2,1)` | `(0.16,1,0.3,1)` |
| `--luwi-duration-slow` | 500ms | 400ms | 350ms |
| Shadow tint | warm amber | green | plum |
| Hero style | centered editorial | split-screen | full-bleed overlay |
| Card accent | none (shadow only) | green left-border | gold top-border |
| Button style | brass gradient | forest gradient | solid ruby |
| Input style | underline | boxed rounded | floating-label |

---

## 5. File Structure Per Theme

```
luwi-{name}/
├── style.css                     ← WP header only (Theme Name, Text Domain)
├── assets/css/
│   ├── tokens.css                ← THEME-SPECIFIC: all --luwi-* values + dark mode
│   ├── personality.css           ← THEME-SPECIFIC: hero, card, button, input overrides
│   ├── base.css                  ← SHARED: reset, typography, layout, components
│   ├── woocommerce.css           ← SHARED: WooCommerce styles
│   ├── widgets.css               ← SHARED: Elementor widget styles
│   ├── responsive.css            ← SHARED: breakpoints
│   └── plugins.css               ← SHARED: 3rd party plugin compat
├── assets/js/
│   └── theme.js                  ← SHARED: color mode, mobile menu, sticky header
├── inc/
│   ├── class-luwi-theme-setup.php
│   ├── class-luwi-woocommerce.php
│   ├── class-luwi-customizer.php
│   ├── class-luwi-assets.php
│   └── class-luwi-elementor.php
├── widgets/                       ← SHARED widget classes
├── woocommerce/                   ← SHARED template overrides
├── template-parts/                ← SHARED templates
└── functions.php                  ← Constants, text domain, setup
```

### Separation Principle

- `tokens.css` — **Unique per theme.** Only `:root {}` and `[data-color-mode="dark"] {}`
  with all CSS custom property values. This is the "DNA" of the theme.
- `personality.css` — **Unique per theme.** Component-level overrides that can't be
  expressed through tokens alone (e.g., Gold's gradient-fade hr, Emerald's blob shapes,
  Ruby's floating-label inputs, hero layout variants).
- Everything else — **Shared.** Consumes tokens via `var(--luwi-*)`.

---

## 6. Theme Registry Integration

```json
{
  "themes": {
    "luwi-gold": {
      "name": "Luwi Gold",
      "personality": "Artisan Heritage",
      "palette_family": "warm",
      "coming_soon": false
    },
    "luwi-emerald": {
      "name": "Luwi Emerald",
      "personality": "Botanical Modern",
      "palette_family": "cool-natural",
      "coming_soon": false
    },
    "luwi-ruby": {
      "name": "Luwi Ruby",
      "personality": "Bold Luxe",
      "palette_family": "cool-dramatic",
      "coming_soon": false
    }
  }
}
```

---

## 7. Plugin Admin Design System (`--lp-*`)

The LuwiPress plugin admin uses its own design system, separate from themes.
Plugin UI always uses indigo (`#6366f1`) as its brand color regardless of active theme.

### Plugin Token Categories
```
--lp-primary, --lp-primary-light, --lp-primary-dark, --lp-primary-50, --lp-primary-100, --lp-primary-900
--lp-primary-hover, --lp-primary-active
--lp-success, --lp-success-bg, --lp-success-light
--lp-error, --lp-error-bg
--lp-warning, --lp-warning-bg
--lp-info, --lp-info-bg, --lp-blue
--lp-gray, --lp-gray-light, --lp-gray-dark
--lp-gray-50, --lp-gray-100, --lp-gray-200, --lp-gray-300, --lp-gray-700, --lp-gray-800, --lp-gray-900
--lp-text, --lp-text-secondary
--lp-bg, --lp-surface, --lp-surface-secondary, --lp-surface-hover
--lp-border, --lp-border-light
--lp-shadow-sm, --lp-shadow-md, --lp-shadow-lg, --lp-shadow-glow
--space-xs through --space-3xl
--font-sans, --font-mono
--text-xs through --text-2xl
--ease-out, --ease-spring, --duration-fast/normal/slow
--radius-sm through --radius-full
```

### Plugin vs Theme — When to Use Which
| Context | Prefix | Example |
|---------|--------|---------|
| WordPress admin pages | `--lp-*` | Dashboard, Settings, KG |
| Front-end storefront | `--luwi-*` | Shop, Cart, Product pages |
| Customer chat widget | `--lp-*` | Uses plugin CSS namespace |

---

## 8. Design Principles

1. **Token-first**: Every visual decision is a CSS custom property. No hardcoded colors.
2. **Personality via override**: Shared base consumes `var()`. Theme tokens supply values.
3. **Switch without breaking**: Changing themes = swapping `tokens.css` + `personality.css`.
4. **Accessibility is non-negotiable**: Every palette meets 4.5:1 contrast (AA).
5. **Dark mode is a first-class citizen**: Not an afterthought — designed with same care.
6. **No tool names in UI**: Never expose "Stitch", "Claude", "GPT" — use "Luwi" branding.
7. **base.css has NO token definitions**: All CSS custom properties live in `tokens.css` only.
8. **No hardcoded rgba in shared CSS**: Use `var(--luwi-shadow-*)` tokens, never theme-specific rgba values.
