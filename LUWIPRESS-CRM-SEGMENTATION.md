# LuwiPress CRM Segmentation — Rules & Coverage

How `crm_segment_customers` decides which lifecycle bucket each WooCommerce customer lands in, what the default thresholds are, and why coverage can look sparse on a fresh install.

## The eight lifecycle segments

LuwiPress classifies every WooCommerce customer into exactly one of these buckets. The decision tree runs top-down — first match wins.

| Segment | Condition (all must hold) |
|---|---|
| **VIP** | Lifetime spend ≥ `vip_spend` AND order count ≥ `loyal_orders` |
| **Loyal** | Order count ≥ `loyal_orders` AND last order within `active_days` (not already VIP) |
| **New** | First order within `new_days` AND last order within `active_days` |
| **Active** | Last order within `active_days` (not New, not Loyal, not VIP) |
| **At Risk** | Last order > `active_days` ago, ≤ `at_risk_days` ago |
| **Dormant** | Last order > `at_risk_days` ago, ≤ `dormant_days` ago |
| **Lost** | Last order > `dormant_days` ago AND order count > 1 |
| **One Time** | Single-order customer past the dormant window (relabel of Lost) |

`one_time` is intentionally narrow: a 1-order customer who is still within an actionable recency window (Active / At Risk / Dormant) stays in that recency bucket, because there's a real re-engagement play to run. Only after the dormant window closes does a 1-order customer get demoted to "bought once, never came back."

## Default thresholds

| Setting | Default | What it controls | When to change |
|---|---|---|---|
| `vip_spend` | 1000 (store currency) | Lifetime spend gate for VIP | High-AOV stores raise it; subscription stores lower it |
| `loyal_orders` | 3 | Order count for Loyal/VIP | High-ticket / low-frequency stores drop to **2** |
| `active_days` | 90 | "Last seen recently" window | Daily-purchase categories (groceries) shrink to 30 |
| `at_risk_days` | 180 | Grace period before Dormant | Slow-cycle goods (instruments, furniture) raise to 270 |
| `dormant_days` | 365 | Last chance before Lost | Annual-purchase products (gifts) raise to 540 |
| `new_days` | 30 | First-time customer window | Long-onboarding products raise to 60-90 |

All thresholds are tunable per-store via `POST /luwipress/v1/crm/settings` (or the `crm_settings_set` MCP tool) — partial-update, only send the keys you want to change. Read current values with `GET /crm/settings`.

## Why coverage can look sparse

`crm_segment_customers` only classifies WooCommerce customers who have **at least one order linked to them**. The classifier reads `last_order_date` and `first_order_date` directly from the `wp_wc_customer_lookup` table; users without those values are silently dropped before the decision tree runs.

Common reasons your `total_customers` number is much larger than the segmented count:

1. **Imported users without orders** — accounts created by a migration script, by FluentCRM, or by guest-to-customer conversion never linked to a placed order. They show up in user count but have no `last_order_date`. Either import their order history or accept that they're out of scope until they buy.
2. **Subscriber-role users** — newsletter signups via FluentCRM / Mailchimp populate `wp_users` but don't create WC orders. The segmentation surface is **WC-customer-only by design** (as of 3.2.4 the Plugin Detector stopped probing third-party CRMs altogether — LuwiPress is its own content + segmentation surface, not a unification layer over multiple lists).
3. **Guest checkouts** — checkouts completed without account creation produce an order but no `customer_id`. WC's `wc_customer_lookup` ignores them; LuwiPress segmentation inherits that gap.
4. **Trashed / deleted orders** — orders moved to trash drop out of `wc_customer_lookup` aggregates. If a customer's only order was trashed, they vanish from the segment count.
5. **`loyal_orders` higher than your repeat rate** — VIP/Loyal will be empty if `loyal_orders=3` is set but 95% of your customers have placed only 1-2 orders. Lower the threshold or accept that those segments are aspirational.

## Recommended workflow

1. **Inspect raw counts first.** `GET /crm/overview` returns `total_customers` (raw WC users with orders) and per-segment counts. If the raw number itself is low, the gap is upstream of LuwiPress (imports, guest checkouts).
2. **Tune thresholds to your sales cycle.** Most stores ship with defaults tuned for fast-moving consumer goods. Instruments, furniture, B2B — anything with multi-month sales cycles — should at minimum raise `at_risk_days` and `dormant_days` and drop `loyal_orders` to 2.
3. **Export cohorts as CSV** for downstream tools. `GET /crm/segment-customers?segment=at_risk&format=csv` returns a CSV you can pipe into FluentCRM, Mailchimp, Klaviyo, or any email tool. LuwiPress does not push into third-party contact lists — by design, since 3.2.4. The CSV handoff is the seam.
4. **Schedule periodic re-segmentation** if your defaults moved. `POST /crm/refresh-segments` re-runs the classifier across the whole catalog and writes the updated segment to each user's meta (`_luwipress_crm_segment`).

## Quick API reference

| Action | REST | MCP tool |
|---|---|---|
| Get current thresholds | `GET /crm/settings` | `crm_settings_get` |
| Update thresholds (partial) | `POST /crm/settings` | `crm_settings_set` |
| Get segment counts + customer totals | `GET /crm/overview` | `crm_overview` |
| List customers in one segment | `GET /crm/segment-customers?segment=X` | `crm_segment_customers` |
| Force re-classification across all customers | `POST /crm/refresh-segments` | `crm_refresh_segments` |

Threshold writes apply on the **next** segmentation run, not retroactively. After tuning `loyal_orders` from 3 to 2, call `POST /crm/refresh-segments` (or wait for the daily cron) to surface the newly-eligible Loyal/VIP customers.

---

*If you've audited segment counts and the gap still doesn't match expectations, the bottleneck is upstream of LuwiPress segmentation. Start with `wp wc customer list --format=count` to confirm what WC itself sees, then `SELECT COUNT(*) FROM wp_wc_customer_lookup WHERE last_order_date IS NOT NULL` for the segmentation-eligible subset.*
