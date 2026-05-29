# Agentic Commerce: UCP + AP2 Integration Plan

Status: **IN DEVELOPMENT** (in-tree, uncommitted, no version bump). Started 2026-05-30.
Scope chosen by operator: **Full stack (Phase 1 + 2 + 3, AP2 included)**.

## What we are integrating

1. **UCP — Universal Commerce Protocol** (`developers.google.com/merchant/ucp`)
   Google's open standard for agentic checkout inside AI Mode (Search) and Gemini.
   Merchant stays Merchant of Record. Two integration shapes: **Native Checkout**
   (3 REST endpoints) and **Embedded Checkout** (iframe). Distribution rides existing
   Merchant Center shopping feeds. Feed must signal eligibility (`native_commerce`)
   and compliance (`consumer_notice`). Merchant Center must carry return policy +
   support info. Product feed `id` must map to the Checkout API id (or
   `merchant_item_id`). Speaks REST / MCP / A2A / AP2.

2. **AP2 — Agent Payments Protocol** (`cloud.google.com/blog/.../announcing-agents-to-payments-ap2-protocol`)
   Payment-authorization layer. Core primitive = **Mandates** (crypto-signed,
   VC-backed): **Intent Mandate** (user intent + rules) -> **Cart Mandate**
   (approved exact cart+price) -> payment link -> non-repudiable audit trail.
   **Merchant role is narrow:** verify the Cart Mandate, store the mandate chain
   as an audit trail, fulfil the order. The VC signing is done by the agent +
   payment processor — we verify + persist, we do NOT mint credentials.

## Why it fits LuwiPress now

LuwiPress already has `LuwiPress_ACP_Attribution` — a bridge for Stripe/OpenAI's
**ACP** (the *competing* agentic-commerce standard) orders that bypass the browser.
UCP is Google's equivalent; AP2 is the payment layer beneath it. Same architectural
seam: agent-initiated, browser-bypassed orders that need server-side reconstruction,
audit trails, and idempotent dispatch. We reuse that whole pattern.

## Reused existing infrastructure

| Need | Existing hook |
|---|---|
| Feed/Merchant Center detection | `LuwiPress_Plugin_Detector::detect_product_feed()` / `detect_google_ads()` |
| Agent-bypass order pipeline | `LuwiPress_ACP_Attribution` (cron-async, idempotency meta, audit log, default-OFF) |
| REST + auth | `register_rest_route` + `LuwiPress_Permission::check_token_or_admin` |
| Settings GET/POST partial-update | allowed-key/type map pattern (attribution module) |
| Secret masking in REST | `mask_settings()` presence + last4 |
| MCP tools | `register_tool()` + dispatch map + category label |
| Product->data mapping | marketplace adapter `map_product_data()` |
| JSON-LD | `LuwiPress_Schema_Registry` via `luwipress_schema_registry_init` |
| Order hook | `woocommerce_payment_complete` (already listened) |
| Custom table | `dbDelta` create_table + activation registration |

## Phase 1 — UCP Feed Readiness (this sprint)

New file: `luwipress/includes/class-luwipress-ucp.php` (singleton).

- **Settings** option `luwipress_ucp_settings` (partial-update, default-OFF):
  enabled, merchant_of_record, sandbox, return_cost, return_window_days,
  return_policy_url, support_email, support_phone, support_url, support_hours,
  default_native_commerce, feed_format.
- **Product meta** (register_post_meta on `product`, sanitize at boundary):
  `_luwipress_ucp_native_commerce` (bool), `_luwipress_ucp_consumer_notice` (text),
  `_luwipress_ucp_item_id` (merchant_item_id override).
- **REST** (`luwipress/v1`):
  - `GET|POST /ucp/settings`
  - `GET /ucp/product/{id}` — UCP profile: eligibility, attributes, mapped item_id, validation warnings.
  - `POST /ucp/product/{id}` — set native_commerce / consumer_notice / item_id.
  - `GET /ucp/feed` — supplemental feed of UCP attributes (json|csv|xml), auth-gated.
  - `GET /ucp/eligibility` — coverage report (eligible count + missing-attribute breakdown).
- **Eligibility validator**: per product — price>0, in-stock, image, GTIN-or-(MPN+brand),
  store-level return policy present. Returns graded warnings.
- **Schema**: register `merchant_listing` type (auto_data) emitting `MerchantReturnPolicy`
  + `OfferShippingDetails` on products when UCP enabled + return policy configured.
  Hooked via `luwipress_schema_registry_init` — so UCP module must instantiate
  BEFORE `Schema_Registry::get_instance()` (follow the Vendors early-instantiate pattern).
- **Admin**: `admin/agentic-commerce-page.php`, submenu `luwipress-agentic-commerce`,
  tabs: Overview / UCP Feed / (Checkout — phase 2) / (AP2 — phase 3) / (Transactions — phase 3).
- **WebMCP**: `register_ucp_tools()` — ucp_settings_get/set, ucp_product_profile,
  ucp_product_eligibility_set, ucp_eligibility_report, ucp_feed_preview.

## Phase 2 — UCP Native Checkout API

- New table `wp_luwipress_ucp_sessions` (session_id uuid PK, status, payload JSON, totals, expiry).
- **REST**:
  - `POST /ucp/checkout/session` — create from line items -> WC cart calc (tax/shipping) -> session.
  - `GET /ucp/checkout/session/{id}` — read state.
  - `POST /ucp/checkout/session/{id}` — update (address, shipping option, items) -> recalc.
  - `POST /ucp/checkout/session/{id}/complete` — create WC order, return order ref + status.
- Idempotency per session; sandbox mode short-circuits real order creation.
- WebMCP checkout tools; admin Checkout tab (session log + tester).

## Phase 3 — AP2 Mandate verification + audit trail

New file: `luwipress/includes/class-luwipress-ap2.php` (singleton).

- **Mandate verification**: pluggable verifier. Verify JWS/VC signature against
  configured issuer JWKS when present; else store-and-flag `verification=unverified`.
  Filter `luwipress_ap2_verify_mandate` for custom verifiers / processor SDKs.
- **Storage** (order meta): `_luwipress_ap2_intent_mandate`, `_luwipress_ap2_cart_mandate`,
  `_luwipress_ap2_mandate_chain`, `_luwipress_ap2_verification`.
- **Compose with checkout**: `POST /ucp/checkout/session/{id}/complete` accepts a
  Cart Mandate; AP2 verifies + persists the chain before order creation.
- **REST**: `GET|POST /ap2/settings`, `POST /ap2/mandate/verify` (diagnostic),
  `POST /ap2/checkout/complete` (verified mandate -> order), `GET /ap2/transaction/{order_id}`.
- Reuses ACP attribution audit-log + idempotency pattern.
- WebMCP ap2 tools; admin AP2 + Transactions tabs.

## Conventions honoured

- No version bump until operator authorizes (currently core 3.5.8 / webmcp 1.0.35).
- Default-OFF, sandbox-first, secrets masked in REST.
- WooCommerce soft-dep: REST registers always; WC-bound hooks guarded.
- No BOM, English-only code, `--lp-*` tokens in admin UI.
- LUWIPRESS-FEATURES.md updated only at ship time (matches shipped version).

## Sources
- https://developers.google.com/merchant/ucp
- https://support.google.com/merchants/answer/16837055
- https://cloud.google.com/blog/products/ai-machine-learning/announcing-agents-to-payments-ap2-protocol
- https://goo.gle/ap2 (spec + reference impls)
