# LuwiPress License Server — Implementation Spec (luwi.dev)

> Hand this file to the luwi-dev Claude Code window. It is the **authoritative
> contract** that the LuwiPress core plugin (`includes/class-luwipress-license.php`)
> already implements against. Every value in §1 must match **exactly** or
> activation/heartbeat/updates will silently fail.

> **STATUS (DEPLOYED 2026-06-08):** Live at `https://luwi.dev/license/v1`,
> signing ON, interop self-test + plugin-side signature verification PASS.
> Built in **BRIDGE mode**: Stripe billing is the source of truth; this server
> layers Ed25519 signing + the Envato bridge + the updater on top. Amendments
> vs the original draft (now folded in below):
> - **Native key format is `lwp_<48 hex>`** (NOT `LWP-XXXX-…`). Stripe customers
>   already hold `lwp_` keys; admin-issued keys use the same.
> - **`expires` is `null` for Stripe-native keys** (subscriptions auto-renew; the
>   cutoff comes via `status:"revoked"` on the heartbeat, never an `expires` date).
> - **Stripe plan → tier map (live):** `solo→pro`, `studio→studio`,
>   `agency→marketplace`; `max_activations` = the subscription's `site_limit`.
> - **Envato bridge is DISABLED** until `ENVATO_TOKEN`/`ENVATO_ITEM_ID` are set —
>   purchase-code keys read as `invalid` until then.
> - **No releases published yet** — `/v1/update` returns `{"slug":…,"version":null}`
>   (no update offered) until a ZIP is registered via `publish_release.py`.
> - Production pubkey baked into the plugin: `pXWw2M8qKLWlsB65jw4+zT6GiM8x83XyJ6ca6s2w9Rc=`

---

## 0. Scope

A small HTTP service on luwi.dev (extends the existing Hetzner FastAPI/nginx
stack) that:

1. **Activates** per-site licenses (native keys you issue + CodeCanyon Envato
   purchase codes bridged through the Envato API).
2. **Validates** them on a daily heartbeat (revoke/expiry/refund detection).
3. **Serves licensed auto-updates** (signed manifest + gated, expiring download).

The plugin talks to luwi.dev **only**. GitHub may be a back-office build origin
the server pulls release ZIPs from, but the plugin never contacts GitHub.

---

## 1. INTEROP CONTRACT (must match the plugin byte-for-byte)

### 1.1 Base URL
- Plugin default: **`https://luwi.dev/license/v1`** (no trailing slash).
- nginx on luwi.dev must route `https://luwi.dev/license/` → this app.
- (Overridable plugin-side via the `LUWIPRESS_LICENSE_API` constant, but build
  the server at the default path.)

### 1.2 Response signing — server → plugin (Ed25519, REQUIRED)
- Every JSON response is signed with **Ed25519 (detached)**.
- Signature = `base64( ed25519_sign(raw_response_body_bytes) )`.
- Sent in HTTP header **`X-LuwiPress-License-Sig`** (header name is matched
  case-insensitively by the plugin).
- The plugin verifies with `sodium_crypto_sign_verify_detached(base64_decode(sig), raw_body, pubkey32)`.
- **CRITICAL:** sign the *exact bytes* you write to the body. Do not let the
  framework re-serialize after signing. Build the JSON string once, sign that
  string, return that string verbatim. (See `signed_json()` in §5.)
- **Public key:** generate one Ed25519 keypair (§11). Give the operator the
  **base64 of the 32-byte raw public key** — they bake it into the plugin as the
  `LUWIPRESS_LICENSE_PUBKEY` constant. Keep the secret key server-side only.
- Pubkey must base64-decode to exactly **32 bytes**; signature to **64 bytes**.

### 1.3 Request signing — plugin → server (HMAC, on /validate + /deactivate)
The plugin signs heartbeat/deactivate requests with the **per-activation secret**
the server returned at activation time, using this scheme (from `LuwiPress_HMAC`):

- Headers: **`X-LuwiPress-Signature`** (hex) + **`X-LuwiPress-Timestamp`** (unix seconds).
- `signature = hex( hmac_sha256( key = activation_secret, msg = f"{timestamp}.{raw_request_body}" ) )`
- Replay window: reject if `abs(now - timestamp) > 300` (5 minutes).
- `raw_request_body` is the exact JSON string the plugin sent (compute HMAC over
  the raw bytes, not a re-parsed dict).
- `/activate` is **unsigned** (no activation_secret exists yet).
- If a request is unsigned or fails HMAC on /validate or /deactivate: reject 401.
  (Optional grace: you may accept unsigned during early rollout, but recommended
  to enforce.)

### 1.4 HTTP methods + paths
| Method | Path | Signed req? | Purpose |
|---|---|---|---|
| POST | `/activate` | no | First activation (issues activation_secret) |
| POST | `/validate` | yes (HMAC) | Daily heartbeat |
| POST | `/deactivate` | yes (HMAC) | Free the activation slot |
| GET | `/update` | no | Update manifest (license-gated via query) |
| GET | `/download` | no (URL-signed) | Stream the ZIP (expiring signed URL) |

### 1.5 Verdict object (the shape `/activate` + `/validate` return)
The plugin reads exactly these keys (extra keys are ignored and stored verbatim):

```json
{
  "status": "active",                 // active | expired | invalid | revoked | limit_exceeded
  "tier": "pro",                       // none|starter|pro|studio|marketplace|enterprise
  "entitlements": ["auto_update","webmcp","priority_support"],
  "expires": null,                     // ISO-8601 string, or null for perpetual
  "activation_id": "a1b2c3",           // string (plugin casts to string)
  "activation_secret": "<32+ random>", // ONLY on /activate success — see §1.6
  "max_activations": 1,
  "enforce": true                      // OPTIONAL kill switch — see §1.7
}
```

- `status === "active"` is the ONLY value the plugin treats as licensed. Anything
  else is an authoritative negative → the plugin clears its grace window and
  blocks immediately (when enforcement is on).
- `expires`: the plugin **never** computes expiry client-side from this field —
  it relies entirely on `status`. So `null` (Stripe perpetual / auto-renew) is
  fine and is NOT treated as expired. Subscription end arrives as
  `status:"revoked"` on the next heartbeat.

### 1.6 activation_secret rules
- Return it **only** on `/activate` success. The plugin stores it and uses it to
  HMAC-sign subsequent /validate + /deactivate requests.
- Do **not** return it on /validate (the plugin only overwrites it when present,
  so omitting it keeps the secret stable). Rotating it would break the next
  heartbeat's HMAC.
- ≥ 32 random bytes, URL-safe. Store per-activation row.

### 1.7 enforce (remote kill switch)
- Optional boolean on the verdict. When **explicitly `false`**, the plugin
  disables enforcement entirely for that site (features stay on even if the
  license is inactive). Use it to defuse a misfiring enforcement rollout fleet-
  wide (set per-license or globally → include `"enforce": false` in verdicts).
- Omit it (or `true`) for normal operation.

---

## 2. Tech stack & deployment

- **FastAPI + Uvicorn**, Python 3.11+. New systemd service
  `luwi-license.service` on `127.0.0.1:3150` (mirror the existing
  `luwi-agent-gateway.service` pattern).
- **PostgreSQL** (reuse the box's Postgres; new database/schema `luwipress_license`).
  SQLAlchemy/SQLModel + asyncpg, or plain asyncpg.
- **PyNaCl** for Ed25519, stdlib `hmac`/`hashlib` for the rest.
- **nginx** on luwi.dev: add before the catch-all —
  ```nginx
  location /license/ {
      proxy_pass http://127.0.0.1:3150/;   # strips /license/ -> app sees /v1/...
      proxy_set_header Host $host;
      proxy_set_header X-Real-IP $remote_addr;
      proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
  }
  ```
  (So the app's own routes are `/v1/activate` etc.; nginx maps
  `https://luwi.dev/license/v1/activate` → `127.0.0.1:3150/v1/activate`.)
- **Env / secrets** (`/etc/luwi/license.env`, never in git):
  ```
  LICENSE_ED25519_SK_HEX=<64 hex chars = 32-byte signing seed>
  LICENSE_DOWNLOAD_SECRET=<random 32+ bytes, for signed download URLs>
  ENVATO_TOKEN=<your Envato personal token>
  ENVATO_ITEM_ID=<your CodeCanyon item id>
  LICENSE_ADMIN_TOKEN=<random, for /admin/* endpoints>
  DATABASE_URL=postgresql+asyncpg://.../luwipress_license
  RELEASES_DIR=/srv/luwi/releases        # where signed ZIPs live
  ```

---

## 3. Data model (PostgreSQL DDL)

```sql
CREATE TABLE licenses (
    id                   BIGSERIAL PRIMARY KEY,
    key                  TEXT UNIQUE NOT NULL,          -- native key OR envato purchase code
    type                 TEXT NOT NULL CHECK (type IN ('native','envato')),
    envato_purchase_code TEXT,
    envato_item_id       TEXT,
    buyer                TEXT,
    tier                 TEXT NOT NULL DEFAULT 'pro',
    entitlements         JSONB,                         -- null => derive from tier matrix
    status               TEXT NOT NULL DEFAULT 'active' CHECK (status IN ('active','expired','revoked')),
    expires_at           TIMESTAMPTZ,                   -- null => perpetual (all Envato)
    support_expires_at   TIMESTAMPTZ,                   -- Envato supported_until; NOT used for blocking
    max_activations      INT NOT NULL DEFAULT 1,
    enforce              BOOLEAN,                        -- null=normal; false=kill switch for this license
    notes                TEXT,
    created_at           TIMESTAMPTZ NOT NULL DEFAULT now(),
    updated_at           TIMESTAMPTZ NOT NULL DEFAULT now()
);

CREATE TABLE activations (
    id                BIGSERIAL PRIMARY KEY,
    license_id        BIGINT NOT NULL REFERENCES licenses(id) ON DELETE CASCADE,
    domain_norm       TEXT NOT NULL,                    -- normalized host (see §8)
    fingerprint       TEXT,
    site_url          TEXT,
    plugin_version    TEXT,
    activation_secret TEXT NOT NULL,
    status            TEXT NOT NULL DEFAULT 'active' CHECK (status IN ('active','deactivated')),
    created_at        TIMESTAMPTZ NOT NULL DEFAULT now(),
    last_seen_at      TIMESTAMPTZ NOT NULL DEFAULT now(),
    UNIQUE (license_id, domain_norm)
);

CREATE TABLE releases (
    id           BIGSERIAL PRIMARY KEY,
    slug         TEXT NOT NULL,                          -- luwipress | luwipress-webmcp | ...
    version      TEXT NOT NULL,
    channel      TEXT NOT NULL DEFAULT 'stable' CHECK (channel IN ('stable','beta')),
    zip_path     TEXT NOT NULL,                          -- file under RELEASES_DIR
    sha256       TEXT NOT NULL,
    package_sig  TEXT NOT NULL,                          -- base64 ed25519 of the ZIP bytes
    min_php      TEXT DEFAULT '7.4',
    tested_wp    TEXT,
    requires_wp  TEXT DEFAULT '5.6',
    changelog    TEXT,                                   -- HTML
    published_at TIMESTAMPTZ NOT NULL DEFAULT now(),
    UNIQUE (slug, version, channel)
);

CREATE TABLE validation_log (
    id           BIGSERIAL PRIMARY KEY,
    license_key  TEXT,
    activation_id BIGINT,
    ip           INET,
    verdict      TEXT,
    created_at   TIMESTAMPTZ NOT NULL DEFAULT now()
);
CREATE INDEX ON validation_log (license_key, created_at);
```

---

## 4. Tier matrix (mirror of the plugin's `LuwiPress_License::TIER_MATRIX`)

The server's signed `entitlements` array is authoritative (the plugin uses it
verbatim and only falls back to its own copy when `entitlements` is empty). Keep
these in sync; tune `max_activations` per your pricing.

| tier | entitlements | suggested max_activations |
|---|---|---|
| none | (empty) | 0 |
| starter | `auto_update` | 1 |
| pro | `auto_update, webmcp, priority_support` | 1 |
| studio | `auto_update, webmcp, agentic, priority_support` | 3 |
| marketplace | `auto_update, webmcp, agentic, marketplace, priority_support` | 5 |
| enterprise | `auto_update, webmcp, agentic, marketplace, priority_support, white_label` | 25 |

> Entitlement keys are consumed by: core auto-update (`auto_update`), and the
> companions WebMCP (`webmcp`), Agentic (`agentic`), Marketplace Sync
> (`marketplace`). `priority_support` / `white_label` are informational for now.

**Envato → tier mapping (decide):** recommended `regular → pro`, `extended →
studio`. Envato licenses are **perpetual** (`expires_at = NULL`); `supported_until`
maps to `support_expires_at` and must **NOT** block.

---

## 5. Signing helpers (Python — copy verbatim)

```python
import base64, hmac, hashlib, time, os, json
from nacl.signing import SigningKey

_SK = SigningKey(bytes.fromhex(os.environ["LICENSE_ED25519_SK_HEX"]))  # 32-byte seed

def signed_json(payload: dict, status_code: int = 200):
    """Return a Starlette Response whose body is signed EXACTLY as sent."""
    from starlette.responses import Response
    body = json.dumps(payload, separators=(",", ":"), ensure_ascii=False).encode("utf-8")
    sig = base64.b64encode(_SK.sign(body).signature).decode()   # 64-byte detached -> base64
    return Response(content=body, media_type="application/json",
                    status_code=status_code,
                    headers={"X-LuwiPress-License-Sig": sig})

def verify_request_hmac(activation_secret: str, raw_body: bytes,
                        sig_hex: str, ts: str) -> bool:
    if not sig_hex or not ts:
        return False
    try:
        ts_i = int(ts)
    except ValueError:
        return False
    if abs(time.time() - ts_i) > 300:                 # 5-min replay window
        return False
    msg = (ts + ".").encode("utf-8") + raw_body         # "{timestamp}.{body}"
    expected = hmac.new(activation_secret.encode("utf-8"), msg, hashlib.sha256).hexdigest()
    return hmac.compare_digest(expected, sig_hex)

_DL = os.environ["LICENSE_DOWNLOAD_SECRET"].encode()
def sign_download(slug: str, key: str, fp: str, exp: int) -> str:
    msg = f"{slug}|{key}|{fp}|{exp}".encode()
    return hmac.new(_DL, msg, hashlib.sha256).hexdigest()
```

---

## 6. Endpoints

### 6.1 `POST /v1/activate`  (unsigned request)
Body: `{ "key", "fingerprint", "site_url", "plugin_version" }`

Flow:
1. `domain = normalize(site_url)` (§8).
2. Resolve the license (the plugin pre-validates format `^lwp_[a-f0-9]{48}$` OR the Envato UUID before sending, but the server is the authority):
   - Native key `^lwp_[a-f0-9]{48}$` → resolve against Stripe billing (plan→tier map in the STATUS box). If found → use it.
   - Else if `key` matches the Envato purchase-code regex (`^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$`) → **Envato bridge** (§7; currently disabled → `invalid`); lazy-provision a `licenses` row on a valid sale.
   - Else → `signed_json({"status":"invalid","message":"Unknown license key"}, 404)`.
3. If `license.status == 'revoked'` → `signed_json({"status":"revoked",...}, 200)`.
4. If `license.expires_at` and in the past → `signed_json({"status":"expired",...}, 200)`.
5. Activation slot check:
   - Existing active activation for `(license_id, domain)` → **reuse** (idempotent; update fingerprint/site_url/plugin_version/last_seen; keep its activation_secret).
   - Else if `count(active activations) >= max_activations` → `signed_json({"status":"limit_exceeded","max_activations":N,"active_domains":[...]}, 409)`.
   - Else create a new activation row with a fresh `activation_secret`.
6. Return `signed_json(verdict_active, 200)` with `activation_id`, `activation_secret`, resolved `tier`, `entitlements`, `expires`, `max_activations`.

### 6.2 `POST /v1/validate`  (HMAC-signed request — heartbeat)
Body: `{ "key", "fingerprint", "activation_id", "site_url", "plugin_version" }`

Flow:
1. Load activation by `activation_id` + license by `key`. If missing → `signed_json({"status":"invalid"}, 200)`.
2. **Verify HMAC** with that activation's `activation_secret` (§5). Fail → `401` (the plugin treats this as "unreachable" and keeps its last good verdict — non-destructive).
3. Re-evaluate license: revoked? expired? For **Envato** licenses, re-verify against the Envato API at most **weekly** (cache; respect rate limits) — a now-refunded sale → set `status='revoked'`.
4. Update `last_seen_at`, `plugin_version`. Log to `validation_log`.
5. Return `signed_json(verdict, 200)` — same shape, **omit `activation_secret`**. Include `"enforce": false` only if killing the switch for this license.

### 6.3 `POST /v1/deactivate`  (HMAC-signed request)
Body: `{ "key", "fingerprint", "activation_id" }`
- Verify HMAC. Set the activation row `status='deactivated'` (frees the slot).
- Return `signed_json({"status":"deactivated","ok":true}, 200)`.

### 6.4 `GET /v1/update`  (license-gated manifest)
Query: `slug, key, fingerprint, version, channel(=stable)`

Flow:
1. Resolve license + an active activation for `(license, domain-or-fingerprint)`.
   Not active / not entitled to `auto_update` → `signed_json({"slug":slug,"version":null}, 200)` (plugin shows no update).
2. Look up the latest `releases` row for `(slug, channel)`.
3. If `release.version <= version` (no newer) → `signed_json({"slug":slug,"version":null}, 200)`.
4. Build an expiring signed download URL (TTL ~900s):
   ```
   exp = int(time.time()) + 900
   sig = sign_download(slug, key, fingerprint, exp)
   download_url = f"https://luwi.dev/license/v1/download?slug={slug}&key={key}&fingerprint={fingerprint}&exp={exp}&sig={sig}"
   ```
5. Return `signed_json`:
   ```json
   {
     "slug": "luwipress",
     "version": "3.13.0",
     "download_url": "...",
     "requires": "5.6",
     "tested": "7.0",
     "requires_php": "7.4",
     "last_updated": "2026-06-08",
     "sections": { "changelog": "<p>…</p>" },
     "package_sig": "<base64 ed25519 of the ZIP bytes>"
   }
   ```
   (`package_sig` lets the plugin optionally verify ZIP integrity with the same pubkey.)

### 6.5 `GET /v1/download`  (gated binary stream)
Query: `slug, key, fingerprint, exp, sig`
1. `exp` in the future? else `403`.
2. `sign_download(slug,key,fingerprint,exp) == sig`? (constant-time) else `403`.
3. License active + activation active + entitled to `auto_update`? else `403`.
4. Stream the ZIP from `RELEASES_DIR` with
   `Content-Type: application/zip` + `Content-Disposition: attachment; filename="{slug}-{version}.zip"`.
   (This response is **not** JSON-signed; integrity is the query `sig` + optional `package_sig` from §6.4.)

### 6.6 Admin endpoints (protected by `LICENSE_ADMIN_TOKEN` Bearer)
- `POST /v1/admin/license` → issue a native key: body `{ tier, max_activations, expires_at?, buyer?, entitlements? }` → generates `key` in the canonical **`lwp_<48 hex>`** format, returns it.
- `POST /v1/admin/revoke` → `{ key }` → set `status='revoked'`.
- `POST /v1/admin/reset-activations` → `{ key }` → mark all activations `deactivated` (support: customer migrated servers and burned all slots).
- `GET /v1/admin/licenses?query=` → list/search for support.
- `POST /v1/admin/release` → register a release (or let `publish-release` do it; §9).

---

## 7. Envato purchase-code bridge

```python
import httpx
ENVATO_TOKEN = os.environ["ENVATO_TOKEN"]
ENVATO_ITEM_ID = os.environ["ENVATO_ITEM_ID"]

async def verify_envato(code: str) -> dict | None:
    url = "https://api.envato.com/v3/market/author/sale"
    async with httpx.AsyncClient(timeout=15) as c:
        r = await c.get(url, params={"code": code},
                        headers={"Authorization": f"Bearer {ENVATO_TOKEN}"})
    if r.status_code != 200:
        return None                      # 404 => no/refunded sale
    data = r.json()
    if str(data.get("item", {}).get("id")) != str(ENVATO_ITEM_ID):
        return None                      # sale is for a different item
    return data                          # has buyer, license ("Regular"/"Extended"), supported_until
```

- On `/activate` with a purchase-code-shaped key and no native row: call
  `verify_envato`. Valid → lazy-create `licenses` row: `type='envato'`,
  `envato_purchase_code=code`, `tier` from `data["license"]` (Regular→pro,
  Extended→studio — confirm), `expires_at=NULL`,
  `support_expires_at = data["supported_until"]`, `max_activations` from tier.
- **Cache** the Envato response ~24h to avoid hammering the API.
- **Refund detection:** on weekly re-verify, a 404 / missing sale → `status='revoked'`.
- The Envato token lives **only** in server env — never in the plugin or git.

---

## 8. Domain normalization + activation limits

```python
from urllib.parse import urlparse
def normalize(site_url: str) -> str:
    host = urlparse(site_url if "://" in site_url else "http://" + site_url).hostname or ""
    host = host.lower()
    if host.startswith("www."):
        host = host[4:]
    return host            # strips scheme, port, path, www, lowercases
```
- Per-domain limit keys on `domain_norm` so `https://www.Shop.com/` and
  `http://shop.com:80` count as one. `fingerprint` is a secondary signal (same DB
  + same domain reactivation vs a genuinely new domain).
- `count active activations < max_activations` gates new domains; reactivating an
  existing `domain_norm` is idempotent and consumes no new slot.
- Abuse signal: one key heartbeating from many domains/IPs → inspect
  `validation_log`, throttle or alert.

---

## 9. Release publish flow (how ZIPs reach the `releases` table)

The plugin build stays as-is (`php build-zip.php <version> <slug>` →
`releases/<slug>-v<version>.zip`). Add a publish step (a script OR an
`/v1/admin/release` call) that, for each shipped ZIP:

1. Copy the ZIP into `RELEASES_DIR` on luwi.dev.
2. Compute `sha256` and `package_sig = base64(ed25519_sign(zip_bytes))`.
3. Insert/update the `releases` row `(slug, version, channel, zip_path, sha256,
   package_sig, min_php, tested_wp, requires_wp, changelog)`.

GitHub Actions MAY build + store the ZIP as a **private** release asset; the
server (or the publish script) pulls it with a server-side GitHub token and
registers it. The plugin only ever sees luwi.dev URLs.

---

## 10. Security checklist
- Sign the **exact** response bytes; never re-serialize after signing (§1.2, §5).
- HMAC verify over **raw request body** bytes; constant-time compare; 300s window.
- Ed25519 secret + Envato token + admin token: env only, not in git.
- Download URLs: short TTL (~15 min), HMAC-signed, license re-checked at stream time.
- Rate-limit `/activate` + `/validate` per IP; log to `validation_log`.
- Return signed bodies even on 4xx for activate (`invalid`/`limit_exceeded`) — the
  plugin treats a **signed** 4xx as an authoritative verdict; an **unsigned**
  4xx as "unreachable" (keeps last good verdict).
- Never return `activation_secret` outside `/activate` success.

---

## 11. Keypair generation (run once; hand the pubkey back)

```python
from nacl.signing import SigningKey
import base64
sk = SigningKey.generate()
print("LICENSE_ED25519_SK_HEX =", sk.encode().hex())                       # -> server env (secret)
print("LUWIPRESS_LICENSE_PUBKEY =", base64.b64encode(sk.verify_key.encode()).decode())  # -> plugin constant
```
- Put `LICENSE_ED25519_SK_HEX` in `/etc/luwi/license.env`.
- Give `LUWIPRESS_LICENSE_PUBKEY` (base64, 32-byte raw) to the plugin side — it
  gets baked into the core build as a constant. Until it's set, the plugin
  accepts unsigned responses (dev mode), so you can integration-test before
  finalizing the key.

---

## 12. Interop test plan (validate against the real plugin contract)

Use these to prove the server matches the plugin before wiring the pubkey:

```bash
# 1. activate (expect 200 + X-LuwiPress-License-Sig header + status:active)
curl -i -X POST https://luwi.dev/license/v1/activate \
  -H 'Content-Type: application/json' \
  -d '{"key":"lwp_<48hex>","fingerprint":"abc","site_url":"https://shop.com","plugin_version":"3.13.0"}'

# 2. activate again from a 2nd domain when max=1 (expect 409 + signed status:limit_exceeded)

# 3. validate WITHOUT HMAC headers (expect 401)

# 4. validate WITH correct HMAC (X-LuwiPress-Signature = hex hmac_sha256(secret, "{ts}.{body}"),
#    X-LuwiPress-Timestamp = ts) (expect 200 + status:active, NO activation_secret)

# 5. revoke via admin, then validate (expect 200 + status:revoked)

# 6. update check (expect signed manifest + download_url; older 'version' => version set, newer => null)

# 7. download with a tampered sig (expect 403); with a valid fresh signed URL (expect zip stream)
```

**Acceptance:** the LuwiPress plugin's own verification harness
(`temp/lwp-verify` style) plus a live activation on a staging WP install
(`LUWIPRESS_LICENSE_API` pointed at this server, `LUWIPRESS_LICENSE_PUBKEY` set
to the generated pubkey) must: activate → status active; exceed limit → 409;
revoke → blocks on next heartbeat; signature tamper → rejected.

---

### Quick reference — what the plugin sends/expects (cheat sheet)
- Base: `https://luwi.dev/license/v1`
- Resp sig header: `X-LuwiPress-License-Sig` = base64(ed25519 over raw body)
- Req HMAC headers (validate/deactivate): `X-LuwiPress-Signature` = hex hmac_sha256(activation_secret, "{ts}.{body}"), `X-LuwiPress-Timestamp` = ts; 300s window
- Verdict keys: `status, tier, entitlements[], expires|null, activation_id, activation_secret(activate-only), max_activations, enforce?`
- Entitlement keys: `auto_update, webmcp, agentic, marketplace, priority_support, white_label`
