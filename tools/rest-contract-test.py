"""LuwiPress REST contract test — endpoint invariants.

Discovers LuwiPress REST endpoints by parsing register_rest_route() calls
in the codebase, then exercises each one against a live site and asserts
three universal invariants:

  A. AUTH GATE        — Endpoints whose permission_callback is not
                         __return_true must return 401/403 without a token.
  B. CACHE HEADER     — All luwipress/v1/* responses (except known-public
                         routes) must include Cache-Control: no-store.
  C. VALIDATION       — Endpoints with required args must return 400 when
                         those args are missing on a POST/PUT/DELETE.

Usage:
  python tools/rest-contract-test.py --site https://tapadum.com --token lp_...
  python tools/rest-contract-test.py --json

Exit code = failure count (0 = all green).
"""
from __future__ import annotations

import argparse
import json
import os
import random
import re
import sys
import urllib.error
import urllib.request
from pathlib import Path

REPO_ROOT = Path(__file__).resolve().parent.parent
SCAN_DIRS = [REPO_ROOT / "luwipress" / "includes"]
PUBLIC_ROUTES = {
    "/luwipress/v1/status",
    "/luwipress/v1/health",
    "/luwipress/v1/chat/config",
}

# ANSI
R = "\033[0m"; G = "\033[32m"; RD = "\033[31m"; Y = "\033[33m"; B = "\033[1m"


def discover_routes():
    """Parse register_rest_route() calls and collect route metadata.

    Returns a list of dicts:
      { route, methods, permission, required_args, file, line }
    """
    results = []
    route_rx = re.compile(
        r"register_rest_route\s*\(\s*['\"]luwipress/v1['\"]\s*,\s*['\"]([^'\"]+)['\"]\s*,\s*(.*?)\)\s*;",
        re.DOTALL,
    )

    for root in SCAN_DIRS:
        for php_file in root.rglob("*.php"):
            try:
                src = php_file.read_text(encoding="utf-8", errors="replace")
            except Exception:
                continue
            for match in route_rx.finditer(src):
                route = match.group(1)
                body = match.group(2)
                # Heuristic extraction of method(s) and permission
                methods = re.findall(r"['\"](GET|POST|PUT|DELETE|PATCH)['\"]", body)
                methods = list(set(methods)) or ["GET"]
                perm_match = re.search(r"permission_callback['\"]?\s*=>\s*([^,\n]+)", body)
                permission = (perm_match.group(1).strip() if perm_match else "?").strip()
                # Find required args: 'required' => true
                required = []
                # Chop out an 'args' block if present
                args_block = re.search(r"['\"]args['\"]\s*=>\s*array\s*\((.*?)\)\s*,?\s*\)", body, re.DOTALL)
                if args_block:
                    inner = args_block.group(1)
                    # Name => array( ... 'required' => true ... )
                    for arg_match in re.finditer(
                        r"['\"](\w+)['\"]\s*=>\s*array\s*\(([^)]*)\)", inner, re.DOTALL
                    ):
                        arg_name = arg_match.group(1)
                        arg_body = arg_match.group(2)
                        if re.search(r"['\"]required['\"]\s*=>\s*true", arg_body):
                            required.append(arg_name)

                # Path param (regex) present?
                has_path_param = "(?P<" in route

                rel = str(php_file.relative_to(REPO_ROOT)).replace(os.sep, "/")
                results.append({
                    "route": "/luwipress/v1" + route,
                    "methods": methods,
                    "permission": permission,
                    "required_args": required,
                    "has_path_param": has_path_param,
                    "file": rel,
                })
    return results


def http(method, url, headers=None, body=None, timeout=20):
    data = json.dumps(body).encode() if body is not None else None
    h = {"Accept": "application/json"}
    if headers:
        h.update(headers)
    if data is not None:
        h["Content-Type"] = "application/json"
    req = urllib.request.Request(url, data=data, method=method, headers=h)
    try:
        with urllib.request.urlopen(req, timeout=timeout) as resp:
            return resp.status, dict(resp.headers), resp.read().decode("utf-8-sig", errors="replace")
    except urllib.error.HTTPError as e:
        return e.code, dict(e.headers), e.read().decode("utf-8-sig", errors="replace")
    except urllib.error.URLError as e:
        return 0, {}, f"URLError: {e.reason}"


def run(site: str, token: str, verbose: bool, as_json: bool):
    routes = discover_routes()
    # Skip routes with path parameters (can't safely exercise without real IDs)
    testable = [r for r in routes if not r["has_path_param"]]

    results = []
    for rt in testable:
        route_path = rt["route"].replace("/luwipress/v1", "")
        method = rt["methods"][0]
        is_public = rt["route"] in PUBLIC_ROUTES or rt["permission"] == "'__return_true'"
        is_get = method == "GET"
        cb = random.randint(100_000, 999_999)
        url_path = f"/wp-json/luwipress/v1{route_path}?cb={cb}" if is_get else f"/wp-json/luwipress/v1{route_path}"

        # ── A. AUTH GATE (only for GET — POST validation runs separately)
        if is_get and not is_public:
            code, _, body = http("GET", site + url_path)
            # Pass conditions:
            #   - 401/403 (ideal auth gate response), OR
            #   - 400 with rest_missing_callback_param (args validation
            #     happens before auth in WP core; attacker cannot extract
            #     data without supplying valid args AND auth).
            ok = code in (401, 403) or (
                code == 400 and "rest_missing_callback_param" in body
            )
            results.append({
                "check": "AUTH_GATE",
                "route": rt["route"],
                "expected": "401/403 without token (or 400 missing-param)",
                "actual": code,
                "pass": ok,
                "detail": body[:150] if not ok else "",
            })

        # ── B. CACHE HEADER (only applicable to auth'd 200s)
        if is_get and not is_public:
            code, headers, _ = http("GET", site + url_path, {"X-LuwiPress-Token": token})
            cc = (headers.get("Cache-Control") or headers.get("cache-control") or "").lower()
            if code == 200:
                ok = "no-store" in cc
                results.append({
                    "check": "CACHE_HEADER",
                    "route": rt["route"],
                    "expected": "Cache-Control: no-store",
                    "actual": headers.get("Cache-Control") or "(missing)",
                    "pass": ok,
                    "detail": "",
                })

                # ── B2. REPLAY BLOCK — header alone isn't enough; upstream
                # LiteSpeed / Varnish / CDN may ignore it. Prove the fix by
                # re-hitting the same URL *without* auth and asserting the
                # cache does NOT serve the authenticated body.
                anon_code, anon_headers, _ = http("GET", site + url_path)
                ls_cache = (anon_headers.get("X-LiteSpeed-Cache")
                            or anon_headers.get("x-litespeed-cache") or "").lower()
                # Pass if: anon is 401/403 (auth gate holds), OR
                #          anon is 400 (missing-param gate), OR
                #          it's 200 but *definitely* not served from cache
                #          (no LS hit header). Any 200 + LS hit = real leak.
                is_leak = (anon_code == 200) and ("hit" in ls_cache)
                results.append({
                    "check": "REPLAY_BLOCK",
                    "route": rt["route"],
                    "expected": "anon replay returns 401/403 (or upstream non-cache)",
                    "actual": f"{anon_code} ls_cache={ls_cache or '-'}",
                    "pass": not is_leak,
                    "detail": f"LEAK — auth'd body replayed to anonymous caller" if is_leak else "",
                })

        # ── C. VALIDATION (POST/PUT/DELETE endpoints with required args)
        if method in ("POST", "PUT", "DELETE", "PATCH") and rt["required_args"] and not is_public:
            code, _, body = http(
                method,
                site + url_path,
                {"X-LuwiPress-Token": token},
                body={},
            )
            # Expected: 400 with rest_missing_callback_param OR at least non-500
            ok = code == 400 and "rest_missing_callback_param" in body
            # Relaxed: some handlers do validation themselves — accept 400 with
            # JSON body indicating missing param
            if not ok and code == 400:
                try:
                    parsed = json.loads(body)
                    if isinstance(parsed, dict) and isinstance(parsed.get("data"), dict):
                        if "params" in parsed["data"]:
                            ok = True
                except Exception:
                    pass
            results.append({
                "check": "VALIDATION",
                "route": rt["route"],
                "expected": f"400 when {rt['required_args']} missing",
                "actual": code,
                "pass": ok,
                "detail": body[:200] if not ok else "",
            })

    # Summary
    failed = [r for r in results if not r["pass"]]
    by_check = {}
    for r in results:
        k = r["check"]
        by_check.setdefault(k, {"pass": 0, "fail": 0})
        if r["pass"]:
            by_check[k]["pass"] += 1
        else:
            by_check[k]["fail"] += 1

    if as_json:
        print(json.dumps({
            "routes_discovered": len(routes),
            "routes_tested": len(testable),
            "routes_skipped_path_param": len(routes) - len(testable),
            "checks": len(results),
            "by_check": by_check,
            "failures": failed,
        }, indent=2, ensure_ascii=False))
    else:
        print(f"{B}LuwiPress REST Contract Test{R}")
        print(f"  Routes discovered:        {len(routes)}")
        print(f"  Routes tested:            {len(testable)} (skipped {len(routes) - len(testable)} with path params)")
        print(f"  Checks executed:          {len(results)}")
        print()
        for check, counts in by_check.items():
            total = counts["pass"] + counts["fail"]
            color = G if counts["fail"] == 0 else (Y if counts["fail"] < 3 else RD)
            print(f"  {color}{check:14s}{R} {counts['pass']:>3}/{total:<3} pass, {counts['fail']} fail")
        if failed:
            print(f"\n{RD}{B}FAILURES:{R}")
            for f in failed[:20]:
                print(f"  {RD}✗{R} [{f['check']}] {f['route']}")
                print(f"    expected: {f['expected']}")
                print(f"    actual:   {f['actual']}")
                if f.get("detail") and verbose:
                    print(f"    detail:   {f['detail'][:200]}")
            if len(failed) > 20:
                print(f"  ... and {len(failed) - 20} more (rerun with --json for full list)")
        else:
            print(f"\n{G}{B}✓ All REST contract invariants hold.{R}")

    return len(failed)


def main():
    parser = argparse.ArgumentParser(description="LuwiPress REST contract test")
    parser.add_argument("--site", default=os.environ.get("LUWI_SITE", "https://tapadum.com"))
    parser.add_argument("--token", default=os.environ.get(
        "LUWI_TOKEN", "lp_QuDGnNHTmDWRp5Ng4HhrqEDhviwhQB4BbwhF2mEb"
    ))
    parser.add_argument("--json", action="store_true", help="Machine-readable output")
    parser.add_argument("--verbose", "-v", action="store_true", help="Show failure details")
    parser.add_argument("--discover-only", action="store_true",
                        help="Only list discovered routes; don't run checks")
    args = parser.parse_args()

    if args.discover_only:
        routes = discover_routes()
        print(f"Discovered {len(routes)} routes:")
        for r in routes:
            print(f"  [{','.join(r['methods']):<15s}] {r['route']:<50s} perm={r['permission']} required={r['required_args']}")
        return 0

    return run(args.site, args.token, args.verbose, args.json)


if __name__ == "__main__":
    sys.exit(main())
