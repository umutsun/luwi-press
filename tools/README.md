# LuwiPress quality-gate tooling

Five tools that enforce code quality, security and release integrity. Run locally before every release and in CI on every PR.

## What's here

| Tool | Purpose | Runtime | Exit code |
|---|---|---|---|
| [`security-audit.php`](security-audit.php) | Pattern-scan for SQLi / XSS / eval / hardcoded secrets / dangerous functions | PHP | count of `CRITICAL`+`HIGH` findings |
| [`quality-check.php`](quality-check.php) | Project conventions: English-only, `--lp-*` tokens, i18n textdomain, prefix discipline, debug leaks | PHP | count of `CRITICAL`+`HIGH` findings |
| [`rest-contract-test.py`](rest-contract-test.py) | Every REST endpoint asserts: auth gate, `Cache-Control: no-store`, required-arg validation | Python (live site) | count of failed checks |
| [`webmcp-contract-test.py`](webmcp-contract-test.py) | JSON-RPC 2.0 compliance + 138-tool catalog shape + UTF-8 hygiene + safe-probe round-trip | Python (live site) | count of failed checks |
| [`release-preflight.sh`](release-preflight.sh) | Gate before `build-zip.php`: version consistency + PHP lint + secret/debug scan + audit baselines | Bash | count of failed checks |

## Quick start

```bash
# ── Static (no network, run on every save / pre-commit) ────────
php tools/security-audit.php --diff          # only new findings vs baseline
php tools/quality-check.php  --diff

# ── Live against production (run after every deploy) ──────────
python tools/rest-contract-test.py
python tools/webmcp-contract-test.py

# ── Release gate (run before build-zip.php) ───────────────────
./tools/release-preflight.sh 3.1.36 luwipress
```

Override the target site / token with env vars or CLI flags:

```bash
LUWI_SITE=https://osenben.com LUWI_TOKEN=xyz python tools/rest-contract-test.py
python tools/webmcp-contract-test.py --site https://tapadum.com --token lp_...
```

---

## Detailed reference

### `security-audit.php`

Static pattern scan tuned for WordPress plugins. Every rule carries a `skip_if` pattern so trusted helpers (`sanitize_*`, `wp_unslash`, `$wpdb->prepare`, WP core table properties) don't trip false positives.

```bash
php tools/security-audit.php                  # full severity report
php tools/security-audit.php --only=CRITICAL  # filter
php tools/security-audit.php --json           # machine-readable
php tools/security-audit.php --baseline       # accept current state (check in .security-baseline.json)
php tools/security-audit.php --diff           # only findings NOT in baseline
```

**Rules** (15 total):

| Rule | Severity | What it catches |
|---|---|---|
| `eval_used`, `create_function_used` | CRITICAL | Arbitrary code execution |
| `unserialize_user_input` | CRITICAL | Object injection / POP chain |
| `sql_raw_concat` | CRITICAL | Raw SQL with `{$var}` (unless `$wpdb->` core property) |
| `exec_family` | CRITICAL | `system`, `shell_exec`, `passthru`, etc |
| `hardcoded_token`, `hardcoded_openai_key` | CRITICAL | Accidentally committed secrets |
| `include_user_input` | CRITICAL | LFI |
| `superglobal_unsanitized` | HIGH | Direct `$_GET/$_POST` without `sanitize_*` or `isset()` |
| `missing_prepare_wildcard` | HIGH | SQL statement with `{$var}` — demands `$wpdb->prepare()` |
| `http_no_sslverify_off`, `file_get_contents_user_input` | HIGH | MITM / SSRF |
| `output_unescaped_echo` | MEDIUM | `echo $var` without `esc_*` |
| `ajax_missing_nonce_marker`, `md5_sha1_for_hashing`, `weak_random`, `print_r_var_dump_output` | LOW | Review hints |

**Inline suppression:** add a comment containing `luwipress-audit:ignore` on the offending line.

### `quality-check.php`

Codebase hygiene per `CLAUDE.md`. Covers PHP + CSS + JS.

```bash
php tools/quality-check.php
php tools/quality-check.php --only=HIGH --diff
```

**Rules** (10 total):

| Rule | Severity | What it catches |
|---|---|---|
| `branding_claude_leak` | HIGH | "Claude", "ChatGPT", "GPT-4" exposed in user-facing strings |
| `branding_stitch_leak` | MEDIUM | Google Stitch reference leaking into UI |
| `turkish_in_string` | MEDIUM | Turkish characters (`ğüşıöçİ` etc) in code/comments |
| `hardcoded_hex_color` | MEDIUM | `style="color:#hex"` — use `--lp-*` tokens instead |
| `i18n_missing_textdomain` | MEDIUM | `__('foo')` without `'luwipress'` text domain |
| `todo_marker` | LOW | `TODO/FIXME/XXX/HACK` pending |
| `debug_leak` | LOW | `print_r/var_dump/var_export` not guarded by `WP_DEBUG` |
| `option_missing_prefix`, `hook_missing_prefix` | LOW | Non-`luwipress_` options/hooks |
| `die_exit_abuse` | LOW | `die()/exit()` instead of `wp_die()` |

**Inline suppression:** `luwipress-quality:ignore` on the line.

### `rest-contract-test.py`

Parses `register_rest_route()` calls in the codebase, then exercises each endpoint on a live site against three invariants:

- **AUTH_GATE** — non-public endpoints must return 401/403 without a token (or 400 with `rest_missing_callback_param`, since WP core runs args validation before permission check on GET routes with required args)
- **CACHE_HEADER** — all authenticated 200 responses must carry `Cache-Control: no-store` (the P0 fix from 3.1.36 — this test is its permanent regression guard)
- **VALIDATION** — POST/PUT/DELETE routes with `'required' => true` args must return 400 when the args are missing

```bash
python tools/rest-contract-test.py                         # default: tapadum.com
python tools/rest-contract-test.py --site https://X --token lp_...
python tools/rest-contract-test.py --discover-only         # list routes, don't hit network
python tools/rest-contract-test.py --json                  # machine-readable
python tools/rest-contract-test.py -v                      # show failure detail
```

Skips routes with path parameters (`/crm/segment/(?P<segment>...)`) since those need real IDs — exercise those with targeted tests if needed.

### `webmcp-contract-test.py`

JSON-RPC 2.0 + MCP spec compliance, plus a UTF-8 hygiene probe. 13 checks in total.

| Check | Why |
|---|---|
| HANDSHAKE | `initialize` returns the MCP-shaped envelope (`serverInfo.version`, `capabilities`, `protocolVersion`) |
| SPEC_GUARD × 4 | Server rejects `jsonrpc: "1.0"`, `"2.1"`, missing, and empty with `-32600 Invalid Request` (the 1.0.5 fix) |
| METHOD_GUARD | Unknown method returns `-32601` |
| TOOL_GUARD | Unknown tool name in `tools/call` returns `-32601`/`-32602` |
| AUTH_GATE × 2 | No token + bad token both return 401 |
| CATALOG_FETCH | `tools/list` returns a non-empty array |
| CATALOG_SHAPE | Every tool has `name`, `description`, `inputSchema` |
| READ_ONLY | Known-safe tools (`system_status`, `knowledge_graph`, `search_products`, …) probed with minimal args — none should return `isError: true` |
| UTF8_SAFETY | Text-heavy tools (`search_products`, `content_get_posts`, `crm_overview`) don't leak `U+FFFD` replacement chars or raw `&euro;` / `&amp;(letter)` HTML entities |

```bash
python tools/webmcp-contract-test.py
python tools/webmcp-contract-test.py -v --json
```

**Safety:** only tools in the `SAFE_PROBES` whitelist get exercised. Adding a new tool to the probe list requires verifying it's read-only — never add mutation tools there.

### `release-preflight.sh`

Pre-flight gate run before `php build-zip.php <version> <slug>`. Composes the other tools plus a few ZIP-layer checks.

```bash
# Full check across both plugins
./tools/release-preflight.sh

# Check specific slug at specific version (exits non-zero on mismatch)
./tools/release-preflight.sh 3.1.36 luwipress
./tools/release-preflight.sh 1.0.5  luwipress-webmcp
```

Six sections:

1. **Version consistency** — plugin header `Version:`, `LUWIPRESS_VERSION` / `LUWIPRESS_WEBMCP_VERSION` constant, readme `Stable tag`, and changelog `= X.Y.Z =` entry all match. Catches "bumped the header but forgot the readme" mistakes.
2. **PHP lint** — `php -l` against every `.php` file in `luwipress/` + `luwipress-webmcp/`.
3. **Debug markers** — `console.log` in JS, `var_dump/print_r` in PHP. Use `// debug-ok` to whitelist intentional ones.
4. **Secret scan** — hardcoded `lp_*` LuwiPress tokens, `sk-ant-*` / `sk-proj-*` Anthropic/OpenAI keys, `AIza*` Google keys.
5. **Scaffolding** — `luwipress.php` + `readme.txt` present.
6. **Audit baselines** — runs `security-audit.php --diff --only=CRITICAL` and `quality-check.php --diff --only=HIGH` to ensure no NEW issues crept in vs the committed baseline.

Exit code = count of failed checks. `WARN`-level items don't block the build.

---

## Baselines (`.security-baseline.json`, `.quality-baseline.json`)

Both static audits support a baseline-and-diff workflow so legacy findings don't drown new ones:

```bash
# Accept the current state as known-good noise (commit the .json file)
php tools/security-audit.php --baseline
php tools/quality-check.php  --baseline
git add tools/.security-baseline.json tools/.quality-baseline.json

# From now on, PRs only fail on NEW findings
php tools/security-audit.php --diff
```

**Shrink the baseline over time** — treat it like a tech-debt backlog, not a permanent whitelist. Goal: baseline → 0.

---

## CI integration (GitHub Actions)

The live workflow lives at [`.github/workflows/quality.yml`](../.github/workflows/quality.yml) — it runs the `static` job on every PR and the `contract` job only on `master`/`staging` branches (needs `STAGING_SITE` + `STAGING_TOKEN` secrets configured in the repo settings). The block below is the same workflow inline for reference:

```yaml
name: quality
on: [push, pull_request]

jobs:
  static:
    runs-on: ubuntu-latest
    steps:
      - uses: actions/checkout@v4
      - uses: shivammathur/setup-php@v2
        with: { php-version: '8.1' }
      - name: Security audit (diff)
        run: php tools/security-audit.php --diff --only=CRITICAL
      - name: Quality check (diff)
        run: php tools/quality-check.php  --diff --only=HIGH
      - name: Release preflight (version + lint + secrets)
        run: ./tools/release-preflight.sh

  contract:
    # Only on protected branches (staging/prod) — hits a live site
    if: github.ref == 'refs/heads/master'
    runs-on: ubuntu-latest
    steps:
      - uses: actions/checkout@v4
      - uses: actions/setup-python@v5
        with: { python-version: '3.11' }
      - name: REST contract
        env: { LUWI_SITE: ${{ secrets.STAGING_SITE }}, LUWI_TOKEN: ${{ secrets.STAGING_TOKEN }} }
        run: python tools/rest-contract-test.py
      - name: WebMCP contract
        env: { LUWI_SITE: ${{ secrets.STAGING_SITE }}, LUWI_TOKEN: ${{ secrets.STAGING_TOKEN }} }
        run: python tools/webmcp-contract-test.py
```

---

## What these tools DON'T do

- **Unit tests** — these are black-box / static scanners. For behaviour-level testing add PHPUnit + Brain Monkey under `tests/` (next quality step).
- **Mutation / functional testing** — live contract tests don't exercise POSTs that would mutate data. Enrich/translate/snapshot flows still need manual E2E.
- **Licence / supply-chain audits** — no `composer.lock` audit, no SAST on bundled vendor. If we add Composer in the future, add `composer audit` to CI.
- **Performance regression detection** — KG query timings, token cost per workflow, etc. Worth a dedicated `perf-check.py` once performance becomes a customer-visible regression vector.

## Roadmap

The next premium-grade step is **PHPUnit + fixtures** under `tests/`, using Brain Monkey to mock WP functions so tests stay fast and XAMPP-independent. When that lands, add a `unit:` job to the CI workflow above. See the session notes in `docs/` for the 10-test critical-path list.
