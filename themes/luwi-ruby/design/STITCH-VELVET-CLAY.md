# Design System Specification: The Tactile Ethereal

## 1. Overview & Creative North Star: "The Digital Porcelain"
This design system moves away from the aggressive, flat utility of modern SaaS and toward a "Digital Porcelain" aesthetic. The Creative North Star is **Tactile Minimalism**—an experience that feels sculpted rather than coded. 

We break the "template" look by rejecting rigid grids in favor of **intentional asymmetry** and **tonal depth**. By utilizing "Claymorphism" (soft, voluminous 3D shapes) and a sophisticated editorial type scale, we create a signature identity that feels premium, bespoke, and human. The interface should feel like a series of soft, matte physical objects resting on a high-end paper surface.

---

## 2. Colors & Surface Philosophy
The palette utilizes muted lavender, sage, and dusty rose not as accents, but as structural foundations.

### Tonal Hierarchy
- **Primary (Lavender):** `#655982` — Used for focal points and primary actions.
- **Secondary (Sage):** `#4b6559` — Used for organic growth, success states, and secondary grounding.
- **Tertiary (Dusty Rose):** `#7b5556` — Used for warmth, human elements, and delicate highlights.

### The "No-Line" Rule
**Explicit Instruction:** Designers are prohibited from using 1px solid borders for sectioning or containment. Boundaries must be defined solely through:
1.  **Background Color Shifts:** Placing a `surface_container_low` card on a `surface` background.
2.  **Tonal Transitions:** Using the subtle difference between `surface_bright` and `surface_dim`.

### The "Glass & Gradient" Rule
To elevate the claymorphic feel, use **Signature Textures**. Main CTAs should not be flat; they should use a subtle linear gradient from `primary` to `primary_container` (Top-Left to Bottom-Right) to simulate a soft light source hitting a curved surface. For floating overlays, use **Glassmorphism**: 
- **Fill:** `surface` at 70% opacity.
- **Backdrop Blur:** 20px–40px.

---

## 3. Typography: The Editorial Voice
We use **Plus Jakarta Sans** exclusively. Its geometric yet friendly curves perfectly complement our rounded UI.

| Level | Token | Size | Weight | Tracking | Purpose |
| :--- | :--- | :--- | :--- | :--- | :--- |
| **Display** | `display-lg` | 3.5rem | 700 | -0.02em | Hero statements, high-impact editorial moments. |
| **Headline** | `headline-md` | 1.75rem | 600 | -0.01em | Section headers. Use `primary` color for a "ink-on-paper" feel. |
| **Title** | `title-lg` | 1.375rem | 500 | 0 | Card titles and prominent UI labels. |
| **Body** | `body-lg` | 1rem | 400 | 0 | General reading. High line-height (1.6) for breathability. |
| **Label** | `label-md` | 0.75rem | 600 | +0.05em | Uppercase status indicators and small metadata. |

---

## 4. Elevation & Depth: The Layering Principle
We abandon traditional "drop shadows" in favor of **Tonal Layering** and **Ambient Occlusion**.

- **Surface Nesting:** Depth is achieved by stacking tiers. Place a `surface_container_lowest` object on a `surface_container_high` background to create a "recessed" or "inset" look.
- **Claymorphic Shadows:** For floating elements, use "Ambient Shadows." These must be extra-diffused. 
    - *Example:* `box-shadow: 0 20px 40px rgba(48, 50, 57, 0.06);` (using a tinted version of `on_surface`).
- **The Ghost Border:** If accessibility requires a stroke, use `outline_variant` at **15% opacity**. Never use 100% opaque borders.
- **Roundedness:** Use the `xl` (3rem) or `lg` (2rem) tokens for main containers. The `full` (9999px) token is reserved for buttons and chips to maintain the "pill" aesthetic.

---

## 5. Components

### Buttons (The Sculpted Pill)
- **Primary:** Gradient from `primary` to `primary_dim`. `full` roundedness. No border. On hover, increase the inner "glow" (inner shadow) to simulate a 3D press.
- **Secondary:** `secondary_container` fill with `on_secondary_container` text.
- **Tertiary:** No fill. `primary` text. Use a soft `surface_variant` hover state.

### Cards & Containers (The Soft Volume)
- **Rule:** Forbid divider lines. Use vertical whitespace (32px or 48px) to separate content.
- **Styling:** Use `surface_container_low` with `lg` (2rem) corners. For a claymorphic look, apply a subtle 2px inner white highlight on the top-left edge.

### Input Fields
- **Default State:** `surface_container_highest` background, `xl` roundedness. 
- **Active State:** Soft `primary` glow (8px blur, 10% opacity) and a `primary` label. No harsh black outlines.

### Chips & Tags
- Small, `full` rounded pills using `tertiary_container` for accentuation. Use `label-md` typography.

### Navigation (The Floating Dock)
- Use a floating bottom or side navigation with a 20px backdrop blur and `surface` at 80% opacity. This creates an "object-oriented" UI rather than a fixed "website" layout.

---

## 6. Do's and Don'ts

### Do
- **Do** embrace negative space. If a layout feels crowded, increase the padding to the next scale (e.g., from `lg` to `xl`).
- **Do** use asymmetrical layouts. Offset images or text blocks by 24px to create a more dynamic, "editorial" rhythm.
- **Do** use the `primary_fixed` tones in Dark Mode to ensure pastel softness is maintained without losing legibility.

### Don't
- **Don't** use pure black `#000000` or pure grey. Use `on_surface` or `on_background` which are tinted with the brand's lavender/slate tones.
- **Don't** use 90-degree corners. Everything in this system must feel "huggable" and soft.
- **Don't** use standard dividers. If you must separate items in a list, use a subtle background shift on every other item or increased whitespace.

### Accessibility Note
While the palette is soft and pastel, ensure that all text (`on_surface` on `surface`) maintains a contrast ratio of at least 4.5:1. Use the `primary_dim` and `secondary_dim` tokens for text elements that require extra prominence against light backgrounds.