# luwi.dev — Core Release Slug Rename: `luwipress` → `luwipress-core`

> Hand this to the luwi-dev Claude Code window (license-server box).
> The WordPress side is DONE (luwi-press commit `314aa35`). As of core
> **3.13.3** the plugin requests its update manifest with slug
> **`luwipress-core`** instead of `luwipress`. The install folder on customer
> sites is UNCHANGED (`luwipress/` — WP plugin identity), so this is purely a
> release-key rename on your side.

## 1. What changed on the plugin side

| | before (≤3.13.2) | after (≥3.13.3) |
|---|---|---|
| manifest request | `GET /v1/update?slug=luwipress&…` | `GET /v1/update?slug=luwipress-core&…` |
| GitHub release tag | `v3.13.2` | `v3.13.3` (or `luwipress-core-v3.13.3` — both build) |
| release asset | `luwipress-v3.13.2.zip` | `luwipress-core-v3.13.3.zip` |
| ZIP internal root | `luwipress/` | `luwipress/` (UNCHANGED — install dir) |
| plugin basename | `luwipress/luwipress.php` | UNCHANGED |

Companions and themes are unaffected (their slug == folder as before).

## 2. What the server must do

1. **Serve BOTH slugs during the transition.**
   - Sites still on 3.13.1/3.13.2 keep requesting `slug=luwipress`. They can
     only learn about 3.13.3 through THAT slug. If `luwipress` goes dark, the
     fleet strands on 3.13.2 forever.
   - Sites on 3.13.3+ request `slug=luwipress-core`.
   - Cleanest implementation: treat `luwipress` as an **alias** of
     `luwipress-core` at the `/v1/update` lookup layer (normalize the incoming
     slug, keep one canonical `releases.slug = 'luwipress-core'` row set).
     Alternative: insert each core release row twice (both slugs) — works but
     duplicates data.

2. **Register future core releases under `luwipress-core`.**
   - The GitHub webhook will see assets named `luwipress-core-v{ver}.zip`
     from now on. Make sure the asset-name → slug parser accepts it (it should
     already: slug = basename minus `-v{ver}.zip`).
   - The already-published 3.13.2 row (slug `luwipress`) stays as-is; via the
     alias it also answers `luwipress-core` queries (harmless — 3.13.3+ sites
     are newer than 3.13.2 anyway).

3. **Sanity check after wiring:**
   ```
   # legacy site (3.13.2) must still see the next release:
   curl "…/v1/update?slug=luwipress&key=<real>&fingerprint=…&version=3.13.2&channel=stable"
     → version: 3.13.3 (once published)
   # new site (3.13.3) must see it under the new slug:
   curl "…/v1/update?slug=luwipress-core&key=<real>&fingerprint=…&version=3.13.3&channel=stable"
     → version: null (until 3.13.4)
   ```

4. **Entitlement mapping:** anywhere a license/tier grants per-slug
   entitlements keyed to `luwipress`, apply the same alias so `luwipress-core`
   inherits identical entitlements.

## 3. Also pending on the server (from earlier today)

Three companion releases were tagged and built by CI **before the GitHub
webhook existed**, so they never reached you:
- `luwipress-webmcp-v1.0.45` · `luwipress-agentic-v1.3.4` · `luwipress-marketplace-v1.0.1`

Once the operator adds the Releases webhook (secret from
`/etc/luwi/license.env` → repos `umutsun/luwi-press` + `umutsun/luwi-themes`),
either have GitHub **redeliver** those three release events or register the
assets manually (same procedure as 3.13.2).

## 4. Contract reminder (unchanged)

Asset `{slug}-v{version}.zip`, sha256 + Ed25519 `package_sig` over the exact
ZIP bytes, `status:"active"` only on licensed verdicts, `expires:null` =
perpetual, responses signed with `LICENSE_ED25519_SK_HEX`.

## 5. Retirement criterion for the `luwipress` alias

Keep the alias until every activated site reports `version ≥ 3.13.3` in your
activation/telemetry table (or indefinitely — it costs one mapping line and
prevents stranding any site restored from an old backup).
