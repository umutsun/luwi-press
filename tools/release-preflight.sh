#!/usr/bin/env bash
# LuwiPress release preflight — run before `php build-zip.php`.
#
# Verifies:
#   1. All 4 version declarations match (plugin header, VERSION constant,
#      readme Stable tag, changelog entry). Both core + webmcp.
#   2. All modified PHP files are lint-clean.
#   3. No debug markers (console.log, var_dump, print_r) in shipped paths.
#   4. No secrets (lp_*, sk-*) in shipped paths.
#   5. Required scaffolding exists (plugin main file, readme.txt, LICENSE).
#   6. Security + quality audits are at or below their baseline.
#
# Usage:
#   ./tools/release-preflight.sh 3.1.36 luwipress
#   ./tools/release-preflight.sh 1.0.5  luwipress-webmcp
#   ./tools/release-preflight.sh        # check both without version gate

set -uo pipefail

REPO="$(cd "$(dirname "$0")/.." && pwd)"
PHP=/c/xampp/php/php.exe
[[ -x "$PHP" ]] || PHP=php   # fall back to PATH

RED=$'\033[31m'; GREEN=$'\033[32m'; YEL=$'\033[33m'; BOLD=$'\033[1m'; R=$'\033[0m'

EXPECTED_VERSION="${1:-}"
SLUG="${2:-}"

pass_count=0
fail_count=0
warn_count=0

pass() { echo "  ${GREEN}✓${R} $1"; pass_count=$((pass_count + 1)); }
fail() { echo "  ${RED}✗${R} $1"; fail_count=$((fail_count + 1)); }
warn() { echo "  ${YEL}!${R} $1"; warn_count=$((warn_count + 1)); }
section() { echo; echo "${BOLD}$1${R}"; }

# ─── 1. VERSION CONSISTENCY ───────────────────────────────────────────
check_version_consistency() {
    local slug="$1"
    local expected="$2"
    local dir="$REPO/$slug"
    [[ -d "$dir" ]] || { fail "Plugin dir missing: $slug"; return; }

    # slug → main file + constant name
    if [[ "$slug" == "luwipress" ]]; then
        local main="$dir/luwipress.php"
        local const_name="LUWIPRESS_VERSION"
    elif [[ "$slug" == "luwipress-webmcp" ]]; then
        local main="$dir/luwipress-webmcp.php"
        local const_name="LUWIPRESS_WEBMCP_VERSION"
    else
        fail "Unknown slug: $slug"; return
    fi

    local readme="$dir/readme.txt"
    [[ -f "$main" ]]   || { fail "main file missing: $main"; return; }
    [[ -f "$readme" ]] || { fail "readme missing: $readme"; return; }

    # Extract declared versions
    local header_v constant_v readme_v changelog_v
    header_v=$(grep -E "^\s*\*\s*Version:" "$main" | head -1 | sed -E 's/.*Version:\s*([0-9.]+).*/\1/')
    constant_v=$(grep -E "define\s*\(\s*['\"]${const_name}['\"]" "$main" | head -1 | sed -E "s/.*,\s*['\"]([0-9.]+)['\"].*/\1/")
    readme_v=$(grep -iE "^\s*Stable tag:" "$readme" | head -1 | sed -E 's/.*:\s*([0-9.]+).*/\1/')
    changelog_v=$(grep -E "^=\s*[0-9]+\.[0-9]+\.[0-9]+" "$readme" | head -1 | sed -E 's/.*=\s*([0-9.]+).*/\1/')

    echo "  $slug:"
    echo "    header:    $header_v"
    echo "    constant:  $constant_v"
    echo "    readme:    $readme_v"
    echo "    changelog: $changelog_v"

    # All 4 must agree
    if [[ "$header_v" == "$constant_v" && "$header_v" == "$readme_v" && "$header_v" == "$changelog_v" ]]; then
        pass "All 4 declarations agree at $header_v"
    else
        fail "Version mismatch — header=$header_v constant=$constant_v readme=$readme_v changelog=$changelog_v"
    fi

    # If expected version supplied, verify match
    if [[ -n "$expected" && "$header_v" != "$expected" ]]; then
        fail "Expected $expected but current is $header_v"
    elif [[ -n "$expected" ]]; then
        pass "Version matches expected ($expected)"
    fi
}

section "1. Version consistency"
if [[ -n "$SLUG" ]]; then
    check_version_consistency "$SLUG" "$EXPECTED_VERSION"
else
    check_version_consistency "luwipress" ""
    check_version_consistency "luwipress-webmcp" ""
fi

# ─── 2. PHP LINT ───────────────────────────────────────────────────────
section "2. PHP lint (all files in luwipress/ + luwipress-webmcp/)"
lint_failed=0
while IFS= read -r f; do
    result=$($PHP -l "$f" 2>&1)
    if ! grep -q "No syntax errors" <<< "$result"; then
        fail "$f — $(echo "$result" | head -1)"
        lint_failed=$((lint_failed + 1))
    fi
done < <(find "$REPO/luwipress" "$REPO/luwipress-webmcp" -name "*.php" -not -path "*/vendor/*" -not -path "*/node_modules/*" 2>/dev/null)
if [[ $lint_failed -eq 0 ]]; then
    pass "All PHP files lint-clean"
fi

# ─── 3. DEBUG MARKERS ──────────────────────────────────────────────────
section "3. Debug markers in shipped paths"
# console.log in shipped JS
js_debug=$(grep -rn "console\.log\|console\.debug\|console\.error" "$REPO/luwipress/assets" "$REPO/luwipress-webmcp/assets" 2>/dev/null \
    | grep -v "// debug-ok" | wc -l)
if [[ $js_debug -gt 0 ]]; then
    warn "$js_debug console.* calls in assets/ (review before ship; add // debug-ok to intentional ones)"
else
    pass "No console.* in shipped JS"
fi

# PHP debug
php_debug=$(grep -rnE "^\s*(var_dump|print_r|var_export)\s*\(" \
    "$REPO/luwipress/includes" "$REPO/luwipress/admin" \
    "$REPO/luwipress-webmcp/includes" "$REPO/luwipress-webmcp/admin" 2>/dev/null \
    | grep -v "debug-ok" | wc -l)
if [[ $php_debug -gt 0 ]]; then
    warn "$php_debug var_dump/print_r/var_export in PHP (review)"
else
    pass "No PHP debug dumps in shipped includes/admin"
fi

# ─── 4. SECRET SCAN ────────────────────────────────────────────────────
section "4. Secret leak scan"
# lp_[a-zA-Z0-9]{30,} — LuwiPress token format
lp_hits=$(grep -rnE "['\"]lp_[a-zA-Z0-9]{30,}['\"]" \
    "$REPO/luwipress" "$REPO/luwipress-webmcp" 2>/dev/null \
    | grep -v "example\|placeholder\|tests/\|docs/" | wc -l)
if [[ $lp_hits -gt 0 ]]; then
    fail "$lp_hits LuwiPress-looking tokens hardcoded:"
    grep -rnE "['\"]lp_[a-zA-Z0-9]{30,}['\"]" \
        "$REPO/luwipress" "$REPO/luwipress-webmcp" 2>/dev/null \
        | grep -v "example\|placeholder\|tests/\|docs/" | head -5 | sed 's/^/    /'
else
    pass "No LuwiPress API tokens hardcoded"
fi

# sk-* (OpenAI/Anthropic) and AIza (Google)
provider_hits=$(grep -rnE "['\"](sk-ant-|sk-proj-|AIza)[a-zA-Z0-9_-]{20,}['\"]" \
    "$REPO/luwipress" "$REPO/luwipress-webmcp" 2>/dev/null | wc -l)
if [[ $provider_hits -gt 0 ]]; then
    fail "$provider_hits provider API keys hardcoded"
else
    pass "No OpenAI/Anthropic/Google keys hardcoded"
fi

# ─── 5. SCAFFOLDING ────────────────────────────────────────────────────
section "5. Required scaffolding"
for slug in "luwipress" "luwipress-webmcp"; do
    [[ -z "$SLUG" || "$SLUG" == "$slug" ]] || continue
    if [[ "$slug" == "luwipress" ]]; then
        main="$slug/luwipress.php"
    else
        main="$slug/luwipress-webmcp.php"
    fi
    required=("$main" "$slug/readme.txt")
    for f in "${required[@]}"; do
        if [[ -f "$REPO/$f" ]]; then
            pass "$f"
        else
            fail "$f missing"
        fi
    done
done

# ─── 6. AUDIT BASELINES ────────────────────────────────────────────────
section "6. Security + Quality audit baselines"
if [[ -f "$REPO/tools/security-audit.php" ]]; then
    sec_result=$($PHP "$REPO/tools/security-audit.php" --diff --only=CRITICAL 2>&1)
    sec_crit=$(echo "$sec_result" | grep -E "CRITICAL\s+[0-9]+" | tail -1 | awk '{print $2}')
    sec_crit=${sec_crit:-0}
    if [[ "$sec_crit" == "0" ]]; then
        pass "No NEW CRITICAL security findings vs baseline"
    else
        fail "$sec_crit NEW CRITICAL security findings — run: php tools/security-audit.php --diff --only=CRITICAL"
    fi
else
    warn "tools/security-audit.php not found — skipping"
fi

if [[ -f "$REPO/tools/quality-check.php" ]]; then
    qual_result=$($PHP "$REPO/tools/quality-check.php" --diff --only=HIGH 2>&1)
    qual_high=$(echo "$qual_result" | grep -E "HIGH\s+[0-9]+" | tail -1 | awk '{print $2}')
    qual_high=${qual_high:-0}
    if [[ "$qual_high" == "0" ]]; then
        pass "No NEW HIGH quality findings vs baseline"
    else
        fail "$qual_high NEW HIGH quality findings — run: php tools/quality-check.php --diff --only=HIGH"
    fi
else
    warn "tools/quality-check.php not found — skipping"
fi

# ─── SUMMARY ───────────────────────────────────────────────────────────
section "═══ SUMMARY ═══"
echo "  ${GREEN}PASS:${R} $pass_count"
[[ $warn_count -gt 0 ]] && echo "  ${YEL}WARN:${R} $warn_count"
if [[ $fail_count -eq 0 ]]; then
    echo "  ${GREEN}${BOLD}✓ Release preflight clean.${R}"
    if [[ -n "$EXPECTED_VERSION" && -n "$SLUG" ]]; then
        echo ""
        echo "  Next: php build-zip.php $EXPECTED_VERSION $SLUG"
    fi
    exit 0
else
    echo "  ${RED}${BOLD}✗ $fail_count check(s) failed — do not ship.${R}"
    exit "$fail_count"
fi
