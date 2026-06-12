# luwi.dev — Publish 3.14.x releases so auto-update finally delivers (hand to the license-server box)

> **Read `docs/luwi-dev-publish-releases-handoff.md` and `docs/luwi-dev-license-server-spec.md` first** — they define the release table, the signed-manifest contract, and the per-slug request the WordPress side makes. This file is the *delta*: what's confirmed still broken (2026-06-12) and exactly which new releases to register so `demo.flybydeniz.com` can auto-update from the WP admin Updates screen.

## 0. Confirmed live state (2026-06-12)

The update server is **up and answering**, but has **zero release rows** for the current slugs:

```
GET https://luwi.dev/license/v1/update?slug=luwipress-core
  → 200  {"slug":"luwipress-core","version":null}        # ← no release registered
POST https://luwi.dev/license/v1/update
  → 405 Method Not Allowed                                # GET-only, as designed
```

`version:null` (and, critically, **no `x-luwipress-license-sig` header**) means the WordPress client's `update_check()` rejects it (`signature_ok()` fails / `version` empty) and the WP admin Updates screen shows **nothing**. This is the same "commercial blocker" noted in CLAUDE.md — the GitHub side is done, the server side never got its release rows + signing webhook.

**Net:** GitHub Releases assets exist (tag-and-forget CI builds them on every tag push — verified for the tags below). luwi.dev just isn't registering/serving them. Wire the webhook (or register rows manually) and auto-update lights up.

## 1. Releases to register NOW (this session's shipped tags)

All tags are pushed and their GitHub Release assets built. Register one `releases` row per line. `slug` must match **byte-for-byte** what the plugin sends (folder name for plugins, stylesheet for themes).

| slug | version | git tag | GitHub Release asset | local sha256[:16] |
|---|---|---|---|---|
| `luwipress-core`   | `3.14.4`  | `v3.14.4` (repo `umutsun/luwi-press`)                       | `luwipress-core-v3.14.4.zip`   | `42075b2c1d7e2399` |
| `luwipress-webmcp` | `1.0.46`  | `luwipress-webmcp-v1.0.46` (repo `umutsun/luwi-press`)      | `luwipress-webmcp-v1.0.46.zip` | `78f660bb756d4316` |
| `luwipress-amber`  | `1.1.10`  | `luwipress-amber-v1.1.10` (repo `umutsun/luwi-themes`)      | `luwipress-amber-1.1.10.zip`*  | `1ab22d5186db1b71` |

\* The theme repo's CI asset is named `luwipress-amber-v1.1.10.zip` (with the `v`); the locally-built file under `releases/` is `luwipress-amber-1.1.10.zip` (no `v`, matching the 17-release theme convention). Same bytes/version — just note the filename when wiring `download_url`.

> Intermediate core tags `v3.14.0 … v3.14.3` also exist but are superseded by `3.14.4`. You only need the **newest** row per slug for auto-update to offer the update; older rows are optional history.

### Dual-slug reminder (core)
Sites on **≤ 3.13.2** still request the legacy slug `luwipress`; sites on **≥ 3.13.3** request `luwipress-core`. `demo.flybydeniz.com` is now on 3.14.x → it asks for **`luwipress-core`**. Keep serving BOTH slugs until the fleet is past 3.13.2 (see `docs/luwi-dev-core-slug-rename-handoff.md`). For flybydeniz specifically, the `luwipress-core` row is the one that matters.

## 2. The exact response the plugin requires (recap so the row is correct)

`update_check()` (luwipress/includes/class-luwipress-license.php:1493) sends:
```
GET {LUWIPRESS_LICENSE_API}/update?slug=<slug>&key=<license_key>&fingerprint=<fp>&version=<installed>&channel=stable
```
and **only** accepts the response when **all** of these hold (lines 1523-1534):
- HTTP `200`
- Response header `x-luwipress-license-sig: <base64 Ed25519 detached signature OVER THE RAW JSON BODY>`
- The signature verifies against the client's pinned public key (`LUWIPRESS_LICENSE_PUBKEY` / `luwipress_license_pubkey` filter; `signature_ok()` at line 797 — Ed25519, 32-byte pubkey, 64-byte sig, `sodium_crypto_sign_verify_detached`)
- Body is JSON with a non-empty `version` **and** non-empty `download_url`

Manifest fields the plugin reads (filter_plugin/theme_update_transient, lines 1299-1344):
```json
{
  "slug":         "luwipress-core",
  "version":      "3.14.4",                 // REQUIRED, must be > installed (version_compare)
  "download_url": "https://luwi.dev/.../luwipress-core-v3.14.4.zip?expires=...&sig=...",  // REQUIRED, expiring signed URL
  "homepage":     "https://luwi.dev/luwipress",   // optional → 'url'
  "tested":       "7.0",                    // optional
  "requires":     "5.6",                    // optional
  "requires_php": "7.4",                    // optional (defaults to 7.4)
  "package_sig":  "<base64 Ed25519 over the ZIP bytes>"  // optional but recommended (verify_package_download, line 1598)
}
```
Notes:
- The **manifest** is signed via the `x-luwipress-license-sig` header (over the JSON body). The **ZIP** is independently gated by the expiring `download_url` and, if present, the `package_sig` Ed25519 check before WP installs it.
- `version: null` is the server's "no newer release" answer — the client treats it as "no update," which is correct when there genuinely isn't one, but here it's because the row is missing.

## 3. Minimum to make flybydeniz auto-update work end-to-end

1. **Register the three rows in §1** (at minimum the `luwipress-core` 3.14.4 row — that's what flybydeniz polls; add webmcp/amber rows to update those too).
2. Ensure `/update` **signs the body** with the private key whose public half is pinned in the plugin (`LUWIPRESS_LICENSE_PUBKEY`). If the keypair was never generated, that's the real first step (spec §9) — without it the client rejects every manifest.
3. Point each row's `download_url` at the matching **GitHub Release asset** (or a luwi.dev-proxied signed URL), e.g. `https://github.com/umutsun/luwi-press/releases/download/v3.14.4/luwipress-core-v3.14.4.zip`. If you front it with an expiring signed URL, keep the asset reachable for the WP HTTP fetch.
4. **(Recommended) wire the tag→row webhook** so future tag pushes auto-register rows — eliminates this manual step for every release. CLAUDE.md's tag-and-forget flow assumes this exists; it doesn't yet.

## 4. How to verify after wiring (run from anywhere)

```bash
# Should return version:3.14.4 + a signed header (note: needs a real key= for the entitlement check;
# an unkeyed probe still shows whether a release row exists for the slug).
curl -si "https://luwi.dev/license/v1/update?slug=luwipress-core&version=3.13.0" \
  | grep -iE "x-luwipress-license-sig|version"
```
Then on `demo.flybydeniz.com`: WP admin → Dashboard → Updates (or the LuwiPress dashboard "updates found" pill) should list **LuwiPress Core 3.14.4** with a working "update now". The plugin caches manifests 6h (`UPDATE_CACHE_PREFIX`), so use the dashboard "Check again" / clear the `luwipress_lic_upd_*` transients to force a fresh poll.

## 5. What is NOT the problem (already verified on the WP side)
- The plugin requests the correct slug (`luwipress-core` on 3.14.x) — confirmed.
- The endpoint is reachable and GET-only — confirmed (200 / 405).
- GitHub Release assets are built by CI on tag push — the tags above are all pushed.
- The blocker is purely server-side: **no release rows + (likely) no signing keypair** on luwi.dev.
