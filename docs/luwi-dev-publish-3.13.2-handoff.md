# → luwi-dev window: publish LuwiPress core 3.13.2 (live auto-update test)

tapadum.com is already on **3.13.1**, so we cut **3.13.2** to demonstrate a real
in-dashboard pull. Core auto-update has shipped since 3.13.0 (live), so once you
register 3.13.2 as the latest `stable` release for slug `luwipress`, tapadum
(3.13.1, Studio license, `auto_update` entitled) will offer it under
**Dashboard → Updates** and pull the signed ZIP from luwi.dev. 3.13.2 also carries
the fix that extends auto-update to the companion plugins + themes.

## Artifact (transfer this exact ZIP into RELEASES_DIR, then sign its bytes)
- **file:** `releases/luwipress-v3.13.2.zip` (plugin-side repo)
- **size:** 1065283 bytes
- **sha256:** `7bbe3e345813f5f356fc68eb6b6ffd6c63ce08331c4260e974cca0c032cf065d`
- top-level folder `luwipress/` (has `luwipress/luwipress.php`, version constant = 3.13.2) → installs in place. ✓
- Re-verify the sha256 after copying to luwi.dev, **then** compute `package_sig`
  over those exact bytes.

## `releases` row
```
slug         = luwipress
version      = 3.13.2
channel      = stable
zip_path     = <RELEASES_DIR>/luwipress-v3.13.2.zip
sha256       = 7bbe3e345813f5f356fc68eb6b6ffd6c63ce08331c4260e974cca0c032cf065d
package_sig  = base64( ed25519_sign(<zip bytes>) )   # LICENSE_ED25519_SK_HEX
min_php      = 7.4
requires_wp  = 5.6
tested_wp    = 7.0
changelog    = <HTML below>
```
```html
<h4>3.13.2</h4>
<ul>
<li>Automatic updates now cover the companion plugins (WebMCP, Agentic, Marketplace) and the LuwiPress themes — not just the core plugin.</li>
<li>Fixed: Knowledge Graph stuck on the loading skeleton (the heavy "opportunities" scan now runs in the background and is cached).</li>
<li>Knowledge Graph Pages view now shows per-language coverage like Posts/Products.</li>
<li>Fixed: misaligned buttons/icons across several admin screens.</li>
<li>New: one-click AI menu-label translation ("Translate Menus").</li>
</ul>
```

## Verify on the server (you hold tapadum's key in the DB)
tapadum fingerprint = `6156eef36d7ddb647a7aa5d7eb8f660e9c8a1380e63d0ae1ebad72751ab07c3e`
(Studio key ending `…ccf1`).
```bash
# Expect 200, signed (X-LuwiPress-License-Sig), version "3.13.2", download_url + package_sig present:
curl -i "https://luwi.dev/license/v1/update?slug=luwipress&key=<tapadum REAL key>&fingerprint=6156eef36d7ddb647a7aa5d7eb8f660e9c8a1380e63d0ae1ebad72751ab07c3e&version=3.13.1&channel=stable"

# Expect 200 + version:null (no false update once on 3.13.2):
curl -i "https://luwi.dev/license/v1/update?slug=luwipress&key=<tapadum REAL key>&fingerprint=6156eef36d7ddb647a7aa5d7eb8f660e9c8a1380e63d0ae1ebad72751ab07c3e&version=3.13.2&channel=stable"

# Expect: download_url from call #1 streams a zip; tampering &sig= → 403:
curl -I "<download_url from call #1>"
```
**Reply to the plugin window with call #1's output** (status line + presence of the
`x-luwipress-license-sig` header + the JSON body). Then the plugin window drives the
site-side pull on tapadum and confirms install (`/status` → 3.13.2).

## Optional follow-on (proves the companion/theme half once tapadum is on 3.13.2)
Register a newer companion or theme release (e.g. `luwipress-webmcp` 1.0.45 or
`luwipress-gold` 1.10.23) and tapadum will offer/pull it too — that exercises the
3.13.2 generalization beyond the core slug.
