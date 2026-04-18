# Luwi Elementor — Design System

> Use this file with Google Stitch (stitch.withgoogle.com) as the DESIGN.md context.
> Paste the content into Stitch's design system field or reference it in prompts.

## Brand Identity

- **Name**: Luwi Elementor
- **Type**: Premium e-commerce WordPress theme
- **Market**: Artisan products, musical instruments, boutique stores
- **Vibe**: Warm craftsmanship meets modern minimalism
- **Cultural**: Turkish/Middle Eastern artistry with global appeal

## Color Palette

### Light Mode
```
primary:       — main CTA buttons, links, active states
primary-hover: — button hover state
accent:        — secondary actions, eyebrows, highlights
text:          — headings, body text (near-black, warm)
text-light:    — secondary text, captions
text-muted:    — placeholders, disabled text
bg:            — page background (warm off-white, NOT pure white)
bg-alt:        — alternating section backgrounds (slightly darker)
surface:       — cards, modals, dropdowns (pure white)
border:        — dividers, input borders
sale:          #dc2626 — sale badges, discount prices (always red)
success:       #16a34a — stock indicators, success messages
warning:       #f59e0b — low stock, attention
error:         #dc2626 — form errors, out of stock
```

### Dark Mode
```
bg:            — deep dark (not pure black, warm-tinted)
bg-alt:        — slightly lighter dark
surface:       — card backgrounds (dark surface)
text:          — light text on dark
border:        — subtle dark borders
```

### Palette Direction
- Warm neutrals as base (cream, sand, stone — NOT cold grays)
- Bold but sophisticated accent (explore: terracotta, indigo, forest green, warm gold)
- Minimal pure black/white — use near-black (#1c1917) and warm white (#f8f6f3)
- Accent used sparingly — only CTAs and key interactive elements
- Sale red is constant across modes (#dc2626)

## Typography

### Font Pairing
- **Headings**: Elegant serif — Playfair Display (current), or explore: Cormorant, DM Serif Display, Source Serif 4
- **Body**: Clean sans-serif — Inter (current), or explore: DM Sans, Plus Jakarta Sans, Outfit
- **Monospace** (code/prices optional): JetBrains Mono, Fira Code

### Scale
```
xs:   12px / 0.75rem    — badges, fine print
sm:   14px / 0.875rem   — captions, meta, nav links
base: 16px / 1rem       — body text
lg:   18px / 1.125rem   — lead paragraphs
xl:   22px / 1.375rem   — h5, card titles
2xl:  26px / 1.625rem   — h4, section subtitles
3xl:  36px / 2.25rem    — h3, section titles
4xl:  48px / 3rem       — h2, page titles
5xl:  60px / 3.75rem    — h1, hero headings
```

### Weights
```
light:    300  — decorative large text
regular:  400  — body text
medium:   500  — nav links, UI labels
semibold: 600  — headings, button text
bold:     700  — eyebrows, emphasis
```

### Line Height
```
tight:   1.2   — headings
snug:    1.35  — subheadings
normal:  1.6   — body text
relaxed: 1.75  — long-form content
```

## Spacing Scale

```
xs:  4px    — icon gaps, inline spacing
sm:  8px    — tight component padding
md:  16px   — standard padding, form gaps
lg:  24px   — card padding, section gaps
xl:  40px   — between components
2xl: 64px   — between sections
3xl: 96px   — major section dividers
```

## Shadows

```
sm:    0 1px 2px rgba(0,0,0,0.04)     — subtle depth (inputs, small cards)
md:    0 4px 12px rgba(0,0,0,0.06)    — cards at rest
lg:    0 8px 24px rgba(0,0,0,0.10)    — elevated elements (dropdowns)
xl:    0 16px 48px rgba(0,0,0,0.12)   — modals, overlays
hover: 0 12px 32px rgba(0,0,0,0.14)   — card hover lift
```

## Border Radius

```
sm:   4px     — buttons, badges, inputs
md:   8px     — cards, containers
lg:   16px    — large cards, hero overlays
xl:   24px    — pill shapes, featured cards
full: 9999px  — circles, pill buttons
```

## Layout

```
container-sm: 720px    — narrow content (blog posts)
container-md: 960px    — medium content
container-lg: 1200px   — standard content width
container-xl: 1400px   — wide content (hero, shop grid)
```

### Grid
- 12-column grid, 24px gap
- Product grid: 3 columns desktop, 2 tablet, 1-2 mobile
- Blog grid: 3 columns desktop, 2 tablet, 1 mobile
- Footer: 3-4 columns

## Components

### Buttons
- **Primary**: Filled background, white text, uppercase, letter-spacing
- **Secondary**: Outline border, colored text
- **Ghost**: Text only, underline on hover
- **Accent**: For sale/promotion CTAs
- All buttons: medium font weight, 8px radius, subtle hover lift

### Product Cards
- 1:1 square image with hover zoom (scale 1.05)
- Title: 2-line max, truncation
- Price: semibold, sale price in red with strikethrough original
- Full-width "Add to Cart" button at bottom
- Sale badge: top-left corner, small pill
- Card: subtle border + shadow, hover = lift + stronger shadow

### Navigation
- Logo left, centered menu, right actions (search, account, cart, lang switcher)
- Menu: uppercase, small font, wide letter-spacing
- Sticky on scroll with subtle shadow
- Mobile: hamburger → fullscreen overlay

### Hero Sections
- Full-width background image with dark gradient overlay
- Large serif heading + sans-serif subheading
- CTA button (outline white or filled primary)
- Min-height: 75vh desktop, 50vh mobile

### Forms
- 1px border, 8px radius
- Focus: primary color border + subtle glow ring
- Labels above inputs, medium weight

## Principles

1. **Generous whitespace** — sections breathe, content is not cramped
2. **Warm palette** — cream/sand base, not sterile white/gray
3. **Depth via shadows** — 4-level shadow system, hover interactions
4. **Premium typography** — serif headings signal quality and craftsmanship
5. **Mobile-first** — every component designed for touch first
6. **Accessibility** — WCAG 2.1 AA contrast, focus indicators, reduced motion support
7. **Performance** — no decorative bloat, lightweight CSS, no jQuery
8. **Dark mode native** — not an afterthought, designed in parallel

## Reference Sites

### Competitors (Musical Instruments)
- salamuzik.com — warm/cultural feel, "Born In Istanbul" heritage
- ethnicmussical.com
- sultaninstrument.com
- sensdelorient.com

### Luxury E-Commerce Benchmarks
- aesop.com — master of whitespace and warm neutrals
- byredo.com — premium product presentation
- bellroy.com — clean product cards, warm tones
- hardgraft.com — artisan craftsmanship aesthetic
