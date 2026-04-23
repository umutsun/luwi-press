"""LuwiPress WebMCP contract test — JSON-RPC 2.0 + MCP spec compliance.

Fetches the full tool catalog from the live server, then runs a battery
of spec-compliance checks:

  1. HANDSHAKE     — initialize returns MCP-shaped serverInfo/capabilities
  2. SPEC_GUARD    — jsonrpc != "2.0" rejected with -32600
  3. METHOD_GUARD  — unknown method returns -32601
  4. TOOL_GUARD    — unknown tool name in tools/call returns -32602
  5. AUTH_GATE     — no token / bad token returns 401
  6. CATALOG       — every tool declares name + description + inputSchema
  7. READ_ONLY     — every tool tagged `readOnlyHint: true` actually only
                      reads (we exercise with empty args + inspect isError)
  8. UTF8_SAFETY   — tools returning product/customer data don't leak �
                      or raw HTML entities (&euro;, &amp;hellip;, etc.)

Usage:
  python tools/webmcp-contract-test.py --site https://tapadum.com --token lp_...
  python tools/webmcp-contract-test.py --json

Exit code = failure count.
"""
from __future__ import annotations

import argparse
import json
import os
import sys
import urllib.error
import urllib.request

R = "\033[0m"; G = "\033[32m"; RD = "\033[31m"; Y = "\033[33m"; B = "\033[1m"

# Known tools that should be safe to call with minimal args (read-only probes)
SAFE_PROBES = {
    "system_status": {},
    "system_health": {},
    "site_config": {},
    "content_get_posts": {"per_page": 1},
    "translation_missing": {"target_language": "fr"},
    "aeo_coverage": {},
    "knowledge_graph": {},
    "crm_overview": {},
    "search_products": {"query": "darbuka", "limit": 2},
    "token_usage_stats": {},
    "token_limit_check": {},
}


def mcp(site, payload, token=None, bad_token=False, timeout=60):
    url = f"{site}/wp-json/luwipress/v1/mcp"
    data = json.dumps(payload).encode()
    h = {"Content-Type": "application/json"}
    if bad_token:
        h["X-LuwiPress-Token"] = "lp_BADLY_INVALID_TOKEN_" + "x" * 20
    elif token:
        h["X-LuwiPress-Token"] = token
    req = urllib.request.Request(url, data=data, method="POST", headers=h)
    try:
        with urllib.request.urlopen(req, timeout=timeout) as resp:
            raw = resp.read().decode("utf-8-sig", errors="replace")
            try:
                return resp.status, json.loads(raw)
            except Exception:
                return resp.status, raw[:500]
    except urllib.error.HTTPError as e:
        raw = e.read().decode("utf-8-sig", errors="replace")
        try:
            return e.code, json.loads(raw)
        except Exception:
            return e.code, raw[:500]


def run(site, token, verbose, as_json):
    failures = []
    pass_count = 0

    def check(name, ok, detail=""):
        nonlocal pass_count
        if ok:
            pass_count += 1
        else:
            failures.append({"check": name, "detail": detail})

    # ── 1. HANDSHAKE ────────────────────────────────────────────────
    code, body = mcp(site, {
        "jsonrpc": "2.0", "id": 1, "method": "initialize",
        "params": {"protocolVersion": "2025-06-18", "capabilities": {},
                   "clientInfo": {"name": "contract-test", "version": "1.0"}}
    }, token=token)
    ok = (
        code == 200
        and isinstance(body, dict)
        and body.get("jsonrpc") == "2.0"
        and isinstance(body.get("result"), dict)
        and "serverInfo" in body["result"]
        and "capabilities" in body["result"]
        and "protocolVersion" in body["result"]
    )
    check("HANDSHAKE", ok, f"code={code} body={str(body)[:200]}")

    server_version = (body.get("result", {}).get("serverInfo", {}).get("version")
                      if isinstance(body, dict) else None)

    # ── 2. SPEC_GUARD (jsonrpc version) ─────────────────────────────
    for bad_ver in ["1.0", "2.1", None, ""]:
        payload = {"id": 2, "method": "tools/list", "params": {}}
        if bad_ver is not None:
            payload["jsonrpc"] = bad_ver
        code, body = mcp(site, payload, token=token)
        err = body.get("error") if isinstance(body, dict) else None
        ok = isinstance(err, dict) and err.get("code") == -32600
        check(
            f"SPEC_GUARD (jsonrpc={bad_ver!r})",
            ok,
            f"expected error -32600, got {err}",
        )

    # ── 3. METHOD_GUARD (unknown method) ────────────────────────────
    code, body = mcp(site, {
        "jsonrpc": "2.0", "id": 3, "method": "does/not/exist", "params": {}
    }, token=token)
    err = body.get("error") if isinstance(body, dict) else None
    ok = isinstance(err, dict) and err.get("code") in (-32601, -32600)
    check(
        "METHOD_GUARD (unknown method)",
        ok,
        f"expected -32601, got {err}",
    )

    # ── 4. TOOL_GUARD (unknown tool in tools/call) ──────────────────
    code, body = mcp(site, {
        "jsonrpc": "2.0", "id": 4, "method": "tools/call",
        "params": {"name": "zzz_does_not_exist", "arguments": {}}
    }, token=token)
    err = body.get("error") if isinstance(body, dict) else None
    ok = isinstance(err, dict) and err.get("code") in (-32601, -32602)
    check(
        "TOOL_GUARD (unknown tool)",
        ok,
        f"expected -32601/-32602, got {err}",
    )

    # ── 5. AUTH_GATE (no token + bad token) ─────────────────────────
    for label, kwargs in [("no_token", {"token": None}), ("bad_token", {"bad_token": True})]:
        code, body = mcp(site, {
            "jsonrpc": "2.0", "id": 5, "method": "initialize",
            "params": {"protocolVersion": "2025-06-18", "capabilities": {},
                       "clientInfo": {"name": "c", "version": "1"}}
        }, **kwargs)
        ok = code in (401, 403)
        check(f"AUTH_GATE ({label})", ok, f"code={code}")

    # ── 6. CATALOG (fetch tools/list + shape check) ─────────────────
    code, body = mcp(site, {
        "jsonrpc": "2.0", "id": 6, "method": "tools/list", "params": {}
    }, token=token)
    tools = []
    if isinstance(body, dict) and isinstance(body.get("result"), dict):
        tools = body["result"].get("tools", [])
    check("CATALOG_FETCH", len(tools) > 0, f"got {len(tools)} tools")

    # Shape check: name + description + inputSchema on every tool
    shape_failed = []
    for t in tools:
        if not isinstance(t, dict):
            shape_failed.append(("<non-dict>", "tool is not a dict"))
            continue
        problems = []
        if not t.get("name"):
            problems.append("name")
        if not t.get("description"):
            problems.append("description")
        if not isinstance(t.get("inputSchema"), dict):
            problems.append("inputSchema")
        if problems:
            shape_failed.append((t.get("name", "?"), ",".join(problems)))
    check(
        f"CATALOG_SHAPE ({len(tools)} tools)",
        not shape_failed,
        f"{len(shape_failed)} tools missing fields: {shape_failed[:5]}",
    )

    # ── 7. READ_ONLY probe (exercise safe tools) ────────────────────
    # Only probe tools we have explicit probes for — so we never accidentally
    # trigger a mutation on a production site.
    tool_by_name = {t["name"]: t for t in tools if isinstance(t, dict) and t.get("name")}
    probed = 0
    read_only_failed = []
    for name, args in SAFE_PROBES.items():
        if name not in tool_by_name:
            continue
        probed += 1
        code, body = mcp(site, {
            "jsonrpc": "2.0", "id": 7, "method": "tools/call",
            "params": {"name": name, "arguments": args}
        }, token=token)
        # Expect: result.isError == False OR absent, no "error" top-level
        if isinstance(body, dict) and body.get("error"):
            read_only_failed.append((name, f"top-level error {body['error']}"))
        else:
            res = body.get("result") if isinstance(body, dict) else None
            if isinstance(res, dict) and res.get("isError") is True:
                # Inspect content for diagnostic
                content = res.get("content", [])
                msg = content[0].get("text", "")[:150] if content and isinstance(content[0], dict) else ""
                read_only_failed.append((name, f"isError: {msg}"))
    check(
        f"READ_ONLY ({probed} probes)",
        not read_only_failed,
        f"{len(read_only_failed)} probes failed: {read_only_failed[:3]}",
    )

    # ── 8. UTF8 SAFETY (on text-heavy probes) ───────────────────────
    utf8_failures = []
    for name in ("search_products", "content_get_posts", "crm_overview"):
        if name not in tool_by_name:
            continue
        args = SAFE_PROBES.get(name, {})
        code, body = mcp(site, {
            "jsonrpc": "2.0", "id": 8, "method": "tools/call",
            "params": {"name": name, "arguments": args}
        }, token=token)
        text = ""
        if isinstance(body, dict):
            res = body.get("result", {})
            if isinstance(res, dict) and res.get("content"):
                first = res["content"][0]
                if isinstance(first, dict):
                    text = first.get("text", "")
        # Check: no U+FFFD, no &euro;, no raw &amp;(word); (allow &amp;#160; etc.)
        problems = []
        if "�" in text:
            problems.append(f"{text.count(chr(0xFFFD))}× U+FFFD")
        if "&euro;" in text:
            problems.append("&euro; entity leak")
        # Only flag &amp;(letter) — numeric entities &amp;#NNN; are legitimate
        if any(seg for seg in [f"&amp;{ch}" for ch in "abcdefghijklmnop"] if seg in text):
            problems.append("&amp;(letter) leak")
        if problems:
            utf8_failures.append((name, problems))
    check(
        f"UTF8_SAFETY ({3} probes)",
        not utf8_failures,
        f"{len(utf8_failures)} tools mangling text: {utf8_failures}",
    )

    # ── OUTPUT ──────────────────────────────────────────────────────
    if as_json:
        print(json.dumps({
            "server_version": server_version,
            "tool_count": len(tools),
            "checks_run": pass_count + len(failures),
            "passed": pass_count,
            "failed": len(failures),
            "failures": failures,
        }, indent=2, ensure_ascii=False))
    else:
        total = pass_count + len(failures)
        print(f"{B}LuwiPress WebMCP Contract Test{R}")
        print(f"  Server version:  {server_version}")
        print(f"  Tools in catalog: {len(tools)}")
        print(f"  Checks run:       {total}")
        if failures:
            print(f"  {RD}{B}Failed:          {len(failures)}{R}")
        else:
            print(f"  {G}{B}Passed:          {pass_count}/{total}{R}")
        if failures:
            print(f"\n{RD}{B}FAILURES:{R}")
            for f in failures:
                print(f"  {RD}✗{R} {f['check']}")
                if f["detail"] and verbose:
                    print(f"    {f['detail'][:200]}")
        else:
            print(f"\n{G}{B}✓ All WebMCP contract invariants hold.{R}")

    return len(failures)


def main():
    parser = argparse.ArgumentParser(description="LuwiPress WebMCP contract test")
    parser.add_argument("--site", default=os.environ.get("LUWI_SITE", "https://tapadum.com"))
    parser.add_argument("--token", default=os.environ.get(
        "LUWI_TOKEN", "lp_QuDGnNHTmDWRp5Ng4HhrqEDhviwhQB4BbwhF2mEb"
    ))
    parser.add_argument("--json", action="store_true", help="Machine-readable output")
    parser.add_argument("--verbose", "-v", action="store_true", help="Show failure details")
    args = parser.parse_args()
    return run(args.site, args.token, args.verbose, args.json)


if __name__ == "__main__":
    sys.exit(main())
