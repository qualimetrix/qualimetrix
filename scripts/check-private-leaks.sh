#!/bin/bash
# Guards this public repository against accidental disclosure of private data.
#
# Three independent checks:
#
#   1. Absolute developer home paths — they reveal usernames and local layout.
#   2. Private project names, from a denylist supplied out-of-band (see below).
#   3. Structural shapes that the known incident took, caught without any
#      denylist: proprietary/private entries carrying a hardcoded absolute path,
#      and markdown tables enumerating codebases by file count.
#
# The denylist never lives in the repository — committing the names would be the
# very leak this script prevents. It is read from, in order of precedence:
#
#   $QMX_PRIVATE_TERMS            newline-separated, one term per line
#   scripts/private-terms.local.txt   git-ignored local file
#
# In CI, set QMX_PRIVATE_TERMS from a repository secret. Without either source,
# checks 1 and 3 still run; only the name check is skipped.
#
# IMPORTANT: this script never prints matched content — only `file:line`. A
# matching line can itself contain a private name or path, so echoing it would
# leak the very thing being guarded into the build log.
#
# Usage:
#   check-private-leaks.sh                  scan all tracked files
#   check-private-leaks.sh --staged         scan staged files (pre-commit hook)
#   check-private-leaks.sh --message FILE   scan a commit message (commit-msg hook)
#   check-private-leaks.sh --commits RANGE  scan commit messages in a git range (CI)
#
# Portable to bash 3.2 (macOS system bash): no mapfile, no associative arrays.

set -uo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PROJECT_ROOT="$(dirname "$SCRIPT_DIR")"
cd "$PROJECT_ROOT" || exit 1

SELF="scripts/check-private-leaks.sh"
DENYLIST="scripts/private-terms.local.txt"

# Synthetic paths used in tests, docs and CI — not real developer homes.
ALLOWED_PATHS='/Users/dev|/Users/project|/home/runner|/home/user|/path/to|/Users/<you>|/absolute/path'

MODE="files"
ARG=""
case "${1:-}" in
    --staged)   MODE="staged" ;;
    --message)  MODE="message"; ARG="${2:-}" ;;
    --commits)  MODE="commits"; ARG="${2:-}" ;;
    "")         ;;
    *)          echo "Unknown option: $1" >&2; exit 2 ;;
esac

status=0

# ---------------------------------------------------------------------------
# Denylist terms, newline-separated on stdout. Empty when no source exists.
# ---------------------------------------------------------------------------
read_terms() {
    if [ -n "${QMX_PRIVATE_TERMS:-}" ]; then
        printf '%s\n' "$QMX_PRIVATE_TERMS"
    elif [ -f "$DENYLIST" ]; then
        cat "$DENYLIST"
    fi
}

# Strips comments and whitespace; drops blank lines.
clean_terms() {
    read_terms | while IFS= read -r line || [ -n "$line" ]; do
        term="${line%%#*}"
        term="${term#"${term%%[![:space:]]*}"}"
        term="${term%"${term##*[![:space:]]}"}"
        [ -n "$term" ] && printf '%s\n' "$term"
    done
}

TERMS="$(clean_terms)"
if [ -z "$TERMS" ]; then
    echo "ℹ️  No private-terms source found — name check skipped (path and shape checks still run)."
fi

# ---------------------------------------------------------------------------
# Commit message modes
# ---------------------------------------------------------------------------
scan_text_for_terms() {
    # $1 = text, $2 = label for the report
    local text="$1" label="$2" found=0
    [ -z "$TERMS" ] && return 0

    while IFS= read -r term; do
        [ -z "$term" ] && continue
        if printf '%s' "$text" | grep -qiF -- "$term"; then
            found=1
        fi
    done <<EOF
$TERMS
EOF

    if [ "$found" -eq 1 ]; then
        echo "✖ $label contains a private term from the denylist."
        echo "  (the term is deliberately not echoed — check the message yourself)"
        return 1
    fi
    return 0
}

if [ "$MODE" = "message" ]; then
    [ -f "$ARG" ] || { echo "Commit message file not found: $ARG" >&2; exit 2; }
    scan_text_for_terms "$(cat "$ARG")" "Commit message" || status=1
    [ $status -eq 0 ] && echo "✅ Commit message clean"
    exit $status
fi

if [ "$MODE" = "commits" ]; then
    if [ -z "$ARG" ] || ! git rev-parse "$ARG" >/dev/null 2>&1; then
        echo "ℹ️  Commit range '$ARG' unavailable (shallow clone or first push) — skipped."
        exit 0
    fi
    while IFS= read -r sha; do
        [ -z "$sha" ] && continue
        msg=$(git log -1 --format='%B' "$sha")
        scan_text_for_terms "$msg" "Commit message of $sha" || status=1
    done <<EOF
$(git rev-list "$ARG" 2>/dev/null)
EOF
    [ $status -eq 0 ] && echo "✅ Commit messages clean"
    exit $status
fi

# ---------------------------------------------------------------------------
# File modes
# ---------------------------------------------------------------------------
if [ "$MODE" = "staged" ]; then
    file_list() { git diff --cached --name-only --diff-filter=ACMR; }
else
    file_list() { git ls-files; }
fi

TARGETS=()
while IFS= read -r f; do
    [ -z "$f" ] && continue
    [ "$f" = "$SELF" ] && continue      # necessarily contains the patterns
    [ -f "$f" ] || continue
    TARGETS+=("$f")
done <<EOF
$(file_list)
EOF

[ ${#TARGETS[@]} -eq 0 ] && exit 0

# Prints only file:line, never the matching content.
locations_only() {
    cut -d: -f1,2
}

# 1. Absolute home paths.
hits=$(grep -HInE '/(Users|home)/[A-Za-z0-9._<>-]+' "${TARGETS[@]}" 2>/dev/null \
       | grep -vE "$ALLOWED_PATHS" | locations_only || true)
if [ -n "$hits" ]; then
    echo "✖ Absolute developer home paths (file:line):"
    echo "$hits" | sed 's/^/    /'
    echo "  Use \$HOME, a relative path, or a git-ignored local config instead."
    status=1
fi

# 2. Private terms from the denylist.
if [ -n "$TERMS" ]; then
    while IFS= read -r term; do
        [ -z "$term" ] && continue
        # Case-insensitive, matching the commit-message check: a name written
        # in a different case is the same disclosure.
        found=$(grep -HIniF -- "$term" "${TARGETS[@]}" 2>/dev/null | locations_only || true)
        if [ -n "$found" ]; then
            echo "✖ A private term from the denylist appears at (file:line):"
            echo "$found" | sed 's/^/    /'
            echo "  (the term is deliberately not echoed)"
            status=1
        fi
    done <<EOF
$TERMS
EOF
fi

# 3. Structural shapes — no denylist needed.
#    3a. proprietary/private inventory entries with a hardcoded absolute path.
shape=$(grep -HInE "'type'[[:space:]]*=>[[:space:]]*'(proprietary|private)'" "${TARGETS[@]}" 2>/dev/null \
        | grep -E '"/|=> *"/|/(Users|home)/' | locations_only || true)
if [ -n "$shape" ]; then
    echo "✖ Proprietary/private inventory entry with a hardcoded absolute path (file:line):"
    echo "$shape" | sed 's/^/    /'
    echo "  Move private targets into a git-ignored local config."
    status=1
fi

#    3b. markdown tables enumerating codebases by approximate file count.
table=$(grep -HInE '^\|[^|]+\|[[:space:]]*~[0-9]+[[:space:]]+files' "${TARGETS[@]}" 2>/dev/null \
        | locations_only || true)
if [ -n "$table" ]; then
    echo "✖ Markdown table enumerating codebases by file count (file:line):"
    echo "$table" | sed 's/^/    /'
    echo "  This is the shape the previous disclosure took — describe sizes without naming projects."
    status=1
fi

if [ $status -eq 0 ]; then
    echo "✅ No private paths, terms or inventory shapes in tracked files"
fi

exit $status
