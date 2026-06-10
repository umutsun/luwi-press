# → luwi-dev window: publish LuwiPress core 3.13.1 (end-to-end auto-update test)

This is the one release that proves the whole auto-update chain. Core auto-update
already shipped in **3.13.0** (live on the customer sites), so once you register
**3.13.1** as the latest `stable` release for slug `luwipress`, every 3.13.0
licensed site will see it under **Dashboard → Updates** and pull the signed ZIP
straight from luwi.dev. (3.13.1 also contains the fix that extends auto-update to
the companion plugins + themes — so after this lands, companion/theme releases
auto-update too.)

## The artifact (plugin side built it; must reach RELEASES_DIR on luwi.dev)
- **file:** `releases/luwipress-v3.13.1.zip` (in the luwipress repo on the plugin-side machine)
- **size:** 1065175 bytes
- **sha256:** `75773018ffd9c94bbf5c824a393b4751fbcbcbfb0892716f1ec1cf7dc43a8b4c`
- ZIP top-level folder is `luwipress/` (contains `luwipress/luwipress.php`) → installs in place over the existing plugin dir. ✓
- **Transfer:** the operator copies this exact ZIP into `RELEASES_DIR` on luwi.dev. After copying, re-check the sha256 matches the value above before signing (the `package_sig` MUST be the Ed25519 signature of these exact bytes).

## Register the release (`releases` table — spec §3/§9)
```
slug         = luwipress
version      = 3.13.1
channel      = stable
zip_path     = <RELEASES_DIR>/luwipress-v3.13.1.zip
sha256       = 75773018ffd9c94bbf5c824a393b4751fbcbcbfb0892716f1ec1cf7dc43a8b4c
package_sig  = base64( ed25519_sign( <the zip bytes> ) )   # sign with LICENSE_ED25519_SK_HEX
min_php      = 7.4
requires_wp  = 5.6
tested_wp    = 7.0
changelog    = <the HTML below>
```
`changelog` HTML (shown in the WP "View details" popup → `sections.changelog`):
```html
<h4>3.13.1</h4>
<ul>
<li>Automatic updates now cover the companion plugins (WebMCP, Agentic, Marketplace) and the LuwiPress themes — not just the core plugin.</li>
<li>Translation Manager now translates Elementor pages instantly while open (no more stuck-looking queue); long articles are hard-split so 2,000+ word posts translate completely; new WP-Cron health indicator.</li>
<li>New: one-click AI menu-label translation ("Translate Menus").</li>
<li>Fixed: Knowledge Graph stuck on the loading skeleton (the heavy "opportunities" scan now runs in the background); Pages view now shows per-language coverage like Posts/Products.</li>
<li>Fixed: misaligned buttons/icons across several admin screens.</li>
</ul>
```

## `/v1/update` must then return (for an entitled, activated site on 3.13.0)
```json
{
  "slug": "luwipress",
  "version": "3.13.1",
  "download_url": "https://luwi.dev/license/v1/download?slug=luwipress&key=…&fingerprint=…&exp=…&sig=…",
  "requires": "5.6",
  "tested": "7.0",
  "requires_php": "7.4",
  "last_updated": "2026-06-09",
  "sections": { "changelog": "…" },
  "package_sig": "…"
}
```
Gating reminder (spec §6.4): resolve license + active activation for the
key/fingerprint, require the `auto_update` entitlement, and return
`{"slug":"luwipress","version":null}` if `release.version <= site.version`. Sign
the response body (X-LuwiPress-License-Sig) exactly as always.

## Verify on the SERVER (you have the key in the DB)
Pick a real activated key for one of the test sites (e.g. the Studio key bound to
`sentragayrimenkul.com` or `tapadum.com`) and its fingerprint, then:
```bash
# Expect: 200, signed, version "3.13.1", download_url + package_sig present.
curl -i "https://luwi.dev/license/v1/update?slug=luwipress&key=<REAL_KEY>&fingerprint=<FP>&version=3.13.0&channel=stable"

# Expect: 200, signed, version:null (no false update once the site is on 3.13.1).
curl -i "https://luwi.dev/license/v1/update?slug=luwipress&key=<REAL_KEY>&fingerprint=<FP>&version=3.13.1&channel=stable"

# Expect: the download URL from call #1 streams a zip; tampering &sig= → 403.
curl -I "<download_url from call #1>"
```
**Reply back to the plugin window with the output of call #1** (status line +
`x-luwipress-license-sig` header presence + the JSON body). That confirms the
server half; the plugin window then verifies the site actually pulls + installs.

## Then the site pulls it (plugin-side verifies)
On a 3.13.0 licensed site: **Settings → License → Re-check** (busts the plugin's
6h manifest cache via the heartbeat), then **Dashboard → Updates → Check again**
→ "LuwiPress — update to 3.13.1" → **Update now**. WordPress downloads the signed
ZIP from luwi.dev, the plugin verifies its Ed25519 `package_sig`, and installs.
