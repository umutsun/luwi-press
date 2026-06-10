# luwi.dev — Publish Releases So Auto-Update Actually Delivers

> Hand this to the luwi-dev Claude Code window (the license-server box). The
> WordPress side is now DONE: as of core **3.13.1** the plugin requests update
> manifests **per slug** for the core plugin, every installed companion, and
> every LuwiPress theme. But the dashboard will keep showing "no updates" until
> the server has a **release row newer than what's installed** for each slug.
> This file is the missing server-side half.

## 0. Why nothing shows yet
`GET /license/v1/update?slug=<slug>&...` currently returns a signed
`{"slug":"<slug>","version":null}` for every slug — verified live for
`luwipress`, `luwipress-webmcp`, `luwipress-agentic`, `luwipress-marketplace`,
`luwipress-gold`, `luwipress-emerald`. `version:null` = "no release registered
that is newer than the site's installed version." So: register releases.

## 1. Slugs the plugin now asks for (must match `releases.slug` byte-for-byte)
The slug = the plugin **folder name** (for plugins) or the theme **stylesheet**
(for themes). These are the exact strings the plugin sends:

| slug | type | ZIP built by | installed on tapadum |
|---|---|---|---|
| `luwipress`             | plugin | `php build-zip.php <ver> luwipress`             | 3.13.0 → 3.13.1 after this deploy |
| `luwipress-webmcp`      | plugin | `php build-zip.php <ver> luwipress-webmcp`      | 1.0.44 |
| `luwipress-agentic`     | plugin | `php build-zip.php <ver> luwipress-agentic`     | 1.3.4 |
| `luwipress-marketplace` | plugin | `php build-zip.php <ver> luwipress-marketplace` | 1.0.1 (not on tapadum) |
| `luwipress-gold`        | theme  | `php build-theme-zip.php <ver>`                 | 1.10.22 (active) |
| `luwipress-emerald`     | theme  | `php build-theme-zip.php <ver> luwipress-emerald-elementor luwipress-emerald` | 1.0.0 |
| `luwipress-ruby`        | theme  | (theme repo) | 1.0.0 |

> The plugin discovers themes by the `luwipress-` stylesheet prefix and
> companions by an installed-basename map, so any slug above only matters if
> that package is actually installed on a site. Register releases only for the
> slugs you actually ship.

## 2. What a release row needs (recap of spec §3 `releases` + §9)
For each ZIP you publish:
```
slug         TEXT   -- one of the slugs in §1 (folder/stylesheet)
version      TEXT   -- e.g. "3.13.1", "1.0.45", "1.10.23"
channel      TEXT   -- "stable" (the plugin default; "beta" via the luwipress_update_channel filter)
zip_path     TEXT   -- file under RELEASES_DIR
sha256       TEXT   -- hex sha256 of the ZIP bytes
package_sig  TEXT   -- base64( ed25519_sign(zip_bytes) ) using LICENSE_ED25519_SK_HEX
min_php      TEXT   -- "7.4"
tested_wp    TEXT   -- "7.0"
requires_wp  TEXT   -- "5.6"
changelog    TEXT   -- HTML (shown in the "View details" popup → sections.changelog)
```
`package_sig` is **critical**: the plugin's `verify_package_download()` re-checks
the downloaded ZIP's bytes against this signature with the baked-in pubkey
(`pXWw2M8qKLWlsB65jw4+zT6GiM8x83XyJ6ca6s2w9Rc=`) BEFORE installing. Sign the
**exact bytes** of the ZIP that lands in `RELEASES_DIR`.

## 3. The manifest `/v1/update` must return (spec §6.4 — already implemented)
When a newer release exists for an entitled, activated site:
```json
{
  "slug": "luwipress-webmcp",
  "version": "1.0.45",
  "download_url": "https://luwi.dev/license/v1/download?slug=luwipress-webmcp&key=...&fingerprint=...&exp=...&sig=...",
  "requires": "5.6",
  "tested": "7.0",
  "requires_php": "7.4",
  "last_updated": "2026-06-09",
  "sections": { "changelog": "<p>…</p>" },
  "package_sig": "<base64 ed25519 of the ZIP bytes>"
}
```
Plugin field mapping (no server change needed, just confirm keys):
`version`, `download_url`, `package_sig`, `requires`, `tested`, `requires_php`,
`last_updated`, `sections`. (The plugin also honours an optional `homepage`
key for the details link; default is `https://luwi.dev/luwipress`.)

**Gating reminder (spec §6.4):** resolve the license + an active activation for
`(license, domain-or-fingerprint)`, require the `auto_update` entitlement, and
return `version:null` if `release.version <= site.version`. Do NOT gate the
update endpoint on the per-companion entitlement (webmcp/agentic/marketplace) —
a site keeps its installed binaries current even when a feature is locked; the
feature gate is enforced separately in-plugin.

## 4. Publish steps (per spec §9)
For each ZIP to ship (use `publish_release.py` or `POST /v1/admin/release`):
1. Copy the ZIP into `RELEASES_DIR` on luwi.dev.
2. `sha256 = sha256(zip_bytes)` ; `package_sig = base64(ed25519_sign(zip_bytes))`.
3. Insert/update the `releases` row `(slug, version, 'stable', zip_path, sha256, package_sig, min_php, tested_wp, requires_wp, changelog)`.

Minimum set to make tapadum's installed packages auto-updatable going forward —
register the CURRENT shipped version of each as the stable floor, then every
future bump auto-delivers:
- `luwipress` **3.13.1** — ZIP `releases/luwipress-v3.13.1.zip` (sha256[:16] `1aa2064749044a59`, 105 files). **This is the auto-update fix itself** — register it (operator is also uploading it to tapadum manually this round).
- `luwipress-webmcp` 1.0.44, `luwipress-agentic` 1.3.4, `luwipress-marketplace` 1.0.1 — register current versions.
- `luwipress-gold` 1.10.22, `luwipress-emerald` 1.0.0, `luwipress-ruby` 1.0.0 — register current versions.

To **demonstrate** an update landing in the dashboard, register one slug at a
version ABOVE installed (e.g. a `luwipress-webmcp` 1.0.45 or `luwipress-gold`
1.10.23 build) and watch it appear on tapadum within the WP update-check window
(or force it: Dashboard → Updates → "Check again").

## 5. Verify end-to-end (with a REAL entitled key — run on the server, which has it)
tapadum's activation fingerprint is
`6156eef36d7ddb647a7aa5d7eb8f660e9c8a1380e63d0ae1ebad72751ab07c3e`
(tier `studio`, entitlements include `auto_update`). Its key is the `****ccf1`
license in the DB.

```bash
# 1. Manifest for a slug where a NEWER release is registered -> expect version set + download_url + package_sig
curl -i "https://luwi.dev/license/v1/update?slug=luwipress-webmcp&key=<REAL_KEY>&fingerprint=6156eef3...&version=1.0.44&channel=stable"

# 2. Same slug, version == latest -> expect version:null (no false update)
curl -i "https://luwi.dev/license/v1/update?slug=luwipress-webmcp&key=<REAL_KEY>&fingerprint=6156eef3...&version=1.0.45&channel=stable"

# 3. The download_url from (1): fresh -> zip stream; tamper the sig -> 403
curl -I "<download_url from step 1>"

# 4. On tapadum (WP admin): Dashboard -> Updates -> Check again; the luwi packages
#    with a newer registered release now list an available update with a working
#    "update now" (package downloads from luwi.dev, ed25519-verified, installs).
```

**Acceptance:** at least one luwi plugin AND one luwi theme show a one-click
update on tapadum that downloads + installs from luwi.dev with the signature
check passing.
