# Design System: Editorial Artisan

## 1. Overview & Creative North Star
The "Creative North Star" for this design system is **The Digital Luthier**. 

Much like the crafting of a fine violin or a hand-hewn cello, this system rejects the "mass-produced" look of standard web grids. It is an editorial-first experience that prioritizes the tactile nature of artisan musical instruments. We move beyond "e-commerce template" logic by utilizing **intentional asymmetry**, where images are allowed to breathe across staggered columns, and **high-contrast typography scales** that mimic the layout of a premium lifestyle journal. The goal is to make the user feel as though they are browsing a high-end atelier in Cremona or a boutique studio in London, rather than a digital shop.

---

## 2. Colors: The Tonal Palette
The palette is rooted in the "warmth of the workshop." We utilize the specific material qualities of brass, linen, and aged wood.

*   **Primary (`#735c00` / `#D4AF37`):** The "Burnished Gold." Use this for high-impact brand moments and key calls to action.
*   **Secondary (`#545e76`):** The "Patina Navy." Provides a sophisticated anchor to the warmer tones, used for grounding elements.
*   **Tertiary (`#a33b3e`):** The "Crimson Resin." Reserved exclusively for sale states and urgent notifications, mimicking the wax seal on a premium document.
*   **Neutral Surfaces:** A sophisticated range from `surface-container-lowest` (#ffffff) to `surface-dim` (#dcd9d9).

### The "No-Line" Rule
**Explicit Instruction:** Prohibit 1px solid borders for sectioning. Boundaries must be defined solely through background color shifts. For example, a "Product Details" section should transition from `surface` (#fcf9f8) to `surface-container-low` (#f6f3f2) to denote a change in context. Lines feel industrial; tonal shifts feel organic.

### Surface Hierarchy & Nesting
Treat the UI as a series of physical layers—like stacked sheets of fine vellum paper. 
- Use `surface-container-lowest` for the primary "paper" cards.
- Place them atop a `surface-container` background to create natural separation.
- **Glass & Gradient Rule:** For floating headers or quick-view modals, use semi-transparent `surface` colors with a `backdrop-blur` (12px-20px). Use subtle linear gradients from `primary` to `primary-container` on buttons to give them the "glow" of polished brass.

---

## 3. Typography: The Editorial Voice
We use typography to bridge the gap between classic craftsmanship and modern precision.

*   **Display & Headlines (Playfair Display):** The "Artisan’s Mark." These should be used with generous letter spacing (tracking) and a high scale ratio. The serif nature conveys history and authority. 
    *   *Usage:* `display-lg` (3.5rem) should be used for hero statements, often placed asymmetrically to break the grid.
*   **Body & Labels (Inter):** The "Technical Manual." A clean, highly legible sans-serif that represents the precision required in instrument making. 
    *   *Usage:* `body-lg` for product descriptions; `label-sm` in uppercase with 0.1em tracking for technical specifications (e.g., "SITKA SPRUCE TOP").

---

## 4. Elevation & Depth: Tonal Layering
In this design system, we do not use "shadows" in the traditional sense; we use **Ambient Light.**

*   **The Layering Principle:** Stack `surface-container-highest` elements sparingly to draw focus. A product card should not have a border; it should simply be a slightly lighter or darker "sheet" than the section behind it.
*   **Ambient Shadows:** If a floating effect is required (e.g., a hover lift), use a shadow with a blur value of 40px+ and an opacity of 4-6%. The shadow color must be a tinted version of `on-surface` (#1b1c1c), never pure grey.
*   **The "Ghost Border" Fallback:** For accessibility in form fields, use the `outline-variant` token at **15% opacity**. It should be felt, not seen.
*   **Glassmorphism:** Use for persistent elements like the "Cart" drawer. This allows the beautiful photography of wood grains and textures to bleed through the UI, keeping the user immersed in the product.

---

## 5. Components

### Buttons
*   **Primary:** Solid `primary` background, `on-primary` (white) text. 8px radius. On hover: a 2px vertical "lift" and a subtle glow using `surface-tint`.
*   **Secondary:** No background. An `outline-variant` "Ghost Border" (20% opacity). High-contrast `on-surface` text.

### Cards & Lists
*   **Forbidden:** Divider lines.
*   **Direction:** Use vertical whitespace (referencing the 8px Spacing Scale) to separate list items. For product grids, use asymmetrical image aspect ratios (e.g., a 4:5 ratio next to a 1:1 ratio) to create a "scrapbook" editorial feel.

### Input Fields
*   **Style:** Minimalist. Only a bottom "Ghost Border" at 20% opacity. Labels use `label-md` in `on-surface-variant`. Error states use `tertiary` (#a33b3e) text, never a bright "neon" red.

### Artisan-Specific Components
*   **The "Spec" Drawer:** A slide-out panel using `surface-container-low` with a heavy backdrop blur. Uses `monospace` typography (5% weight) for technical measurements (e.g., nut width, string tension).
*   **The Material Chip:** A selection chip that shows a circular macro-texture of the wood or metal (e.g., "Flamed Maple") alongside the text label.

---

## 6. Do’s and Don’ts

### Do:
*   **Embrace Whitespace:** If you think there is enough space, add 16px more. Luxury is defined by what you *don't* fill.
*   **Layer Surfaces:** Use the `surface-container` tiers to create hierarchy.
*   **Align Asymmetrically:** Offset text blocks from center-aligned images to create visual tension and interest.

### Don't:
*   **Don't use 100% Black:** Even in dark mode, use the warm charcoal (`#1A1614`). Pure black is too digital/cold.
*   **Don't use Heavy Borders:** If you need a line, use a 5% opacity `on-surface` fill to create a "groove" rather than a stroke.
*   **Don't over-animate:** Hover effects should be "slow and heavy," mimicking the weight of a physical object being lifted. Use `cubic-bezier(0.23, 1, 0.32, 1)` for all transitions.