# LuwiPress — Strategic Roadmap (2026-05-24)

**Status:** Approved by operator 2026-05-24 after session-end strategic analysis.

**Honest read:** Architecture is "ready to ship," commercial layer is "not yet built." The debt is not technical — it's **go-to-market**. Closing this gap is the only thing between current state and real competitive positioning.

---

## Current strengths (acknowledged, do not regress)

- 229 MCP tools / 60+ REST endpoints / Schema Registry / Translation Sync Audit / Slug Resolver / Frontend Inspector
- MCP-first positioning (Rank Math, Yoast, WPML do not have MCP servers — 12-18 month head-start)
- Plugin Detector pattern (additive, not replacement — easier sale)
- Multi-provider AI engine (no OpenAI lock-in)
- Theme ecosystem (Gold + Emerald)

---

## Tier 1 — Pre-launch blockers (30 days)

**Without these you cannot enter the market.**

### 1.1 Theme fully widgetized
- 29 HTML widgets remaining across 8 kit JSONs (header / footer / shop / single-product / journal-archive / journal-single / 404)
- ~15 new `lwp-*` widgets to build: topbar, logo, header-actions, search-overlay, footer-brand, footer-column (repeater), footer-bottom, shop-hero, shop-filters, shop-toolbar, sp-gallery, sp-buy, sp-tabs, pullquote, byline-card, reading-progress, 404-hero, contact-form
- Estimated: multi-day sprint, ~2000 LOC PHP + CSS
- **Critical:** customer install today sees broken Elementor UI on these pages → cannot edit via sidebar

### 1.2 Generic vertical demo site
- Tapadum is real customer work, not a demo (musical instruments niche too narrow)
- Publish 1 clean generic-vertical demo (cafe/agency/jewelry — pick one fast)
- Hosted under demo.luwi.dev or similar
- All features visible (AI chat, KG, schemas, multi-language)

### 1.3 Pricing + distribution model
- Memory notes "Lite free / Studio $499 / Agency $1499" — need to ship:
  - WebMCP Lite mode (standalone, no core required) → WP.org submission for freemium funnel
  - License management infrastructure (EDD/Freemius/custom)
  - Pricing page on luwi.dev with comparison matrix
- **Decision pending:** CodeCanyon vs self-hosted commerce
  - CodeCanyon: fast, 50% cut, brand lock-in
  - Self-hosted: longer runway, full moat

### 1.4 5-7 vertical templates
- restaurant/cafe, salon, gym/fitness, photographer, agency, jeweler, real-estate
- Each = kit JSON + AI prompt preset + screenshot.png + demo content
- Without ≥5 verticals, addressable market <2% of CodeCanyon WC buyers

---

## Tier 2 — Competitive differentiation (31-90 days)

### 2.1 Wizard = "10-minute store launch"
- AI-driven demo-content-in-place: domain → niche pick → AI generates 10 products + 5 blog posts + category tree
- Comparable to Astra/Kadence/OceanWP wizards but AI-native
- Current wizard exists but isn't shaped as a magnetic onboarding experience

### 2.2 Admin design polish
- SaaS-grade visual quality (compare to Rank Math, Solid Affiliate, modern Freemius products)
- Currently dense + functional — needs "I just bought, this feels professional" moment
- UI/UX sprint = high ROI for perceived value

### 2.3 Documentation + landing page (luwi.dev)
- Published REST API docs
- "LuwiPress vs Rank Math" / "vs Astra+Yoast bundle" / "vs Hostinger AI Builder" comparison pages
- Customer case studies (Tapadum + future early adopters)
- Free tools as lead magnets ("WC site audit", "SEO health check")

### 2.4 AI quality/safety customer-visible surface
- Brand voice persistence card (exists but invisible) → admin dashboard component
- Hallucination guard (cite-sources mode) → ship
- GMC compliance audit (3.4.1 ✓) → expose in dashboard, not just MCP
- Confidence scores on AI output ("this text scored 87/100")

### 2.5 Agency multi-site dashboard
- Manage 30+ client sites from one panel
- Cross-site KG aggregation
- Fleet-wide AI campaign deployment
- Positioning: "ManageWP with AI/SEO layer"

---

## Tier 3 — Moat building (90+ days)

- Visual AI design (Stitch integration operator-facing)
- Native A/B testing with AI winner pick
- Visual search + customer chat → real sales assistant
- Voice-first AI search (accessibility + mobile)
- Competitive intelligence tool ("scan rival, find gaps, generate plan")
- Review request automation (TrustMary-class AI + WC + email pipeline)

---

## Tier 4 — Trust signals (parallel track, low-priority but necessary)

- Third-party security audit + published score
- Performance benchmarks (Lighthouse on Gold-built sites, public)
- CI status badge + PHPStan score on landing page
- Customer testimonials (start with Tapadum case study after DNS swap)

---

## Decision points the operator must resolve

1. **Customer profile:** Agency-first or freelancer-first? Pricing tiers + feature priorities flip on this.
2. **Resource model:** One-developer build is technically possible but not for all 4 tiers. Need part-time UI designer + technical writer + sales/marketing partner.
3. **Distribution:** CodeCanyon vs self-hosted vs hybrid (start CodeCanyon, migrate later).
4. **Brand-build velocity:** Push hard now (3-month all-in) or grind slow (12-month organic)? Funding sets this.

---

## Recommended next-sprint priorities (after current commit clears)

If operator chooses "ship Tier 1 first" (default recommendation):

1. **Sprint N+1 (1-2 weeks):** Theme Phase B — header/footer kit JSON migration + 7 new widgets (topbar, logo, header-actions, search-overlay, footer-brand, footer-column, footer-bottom). Bump 1.7.37.

2. **Sprint N+2 (1 week):** Theme Phase C — shop + single-product kit JSON migration + 6 new widgets. Bump 1.7.38.

3. **Sprint N+3 (1 week):** Theme Phase D — journal-archive + journal-single + 404 kit JSON migration + 4 new widgets. Bump 1.7.39 → 1.8.0 (full widgetization milestone).

4. **Sprint N+4 (1 week):** Generic vertical demo site setup + Lite mode WebMCP build + WP.org submission package.

5. **Sprint N+5 (1 week):** First non-music vertical template (proposal: agency/consulting — easiest pivot from Emerald).

This puts shippable v1.0 at ~5-week horizon if operator stays full-time on it.

---

## Reference for next sessions

When session opens, check this file FIRST. Active sprint should always reference its Tier from here. New work that doesn't fit any Tier → either push to backlog or restructure roadmap.

Strategic re-evaluation cadence: monthly. Drift from this plan is OK if intentional — but it should be a decision, not a slide.
