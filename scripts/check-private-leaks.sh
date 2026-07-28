#!/bin/bash
# Guards the public repository against two classes of accidental disclosure:
#
#   1. Absolute developer home paths (they reveal usernames and local layout).
#   2. Private project names, via an optional machine-local denylist.
#
# The denylist lives at scripts/private-terms.local.txt — one term per line,
# blank lines and '#' comments ignored. It is git-ignored by design: writing the
# names into a tracked file would be the very leak this script exists to prevent.
#
# Usage: scripts/check-private-leaks.sh [--staged]
#   (no args)  scan all tracked files
#   --staged   scan only staged files (pre-commit hook)
#
# Portable to bash 3.2 (macOS system bash) — no mapfile, no associative arrays.

set -uo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PROJECT_ROOT="$(dirname "$SCRIPT_DIR")"
cd "$PROJECT_ROOT" || exit 1

SELF="scripts/check-private-leaks.sh"
DENYLIST="scripts/private-terms.local.txt"

# Synthetic paths used in tests, docs and CI — not real developer homes.
ALLOWED='/Users/dev|/Users/project|/home/runner|/home/user|/path/to'

if [ "${1:-}" = "--staged" ]; then
    file_list() { git diff --cached --name-only --diff-filter=ACMR; }
else
    file_list() { git ls-files; }
fi

# Collect existing files, excluding this script (it necessarily holds the patterns).
TARGETS=()
while IFS= read -r f; do
    [ -z "$f" ] && continue
    [ "$f" = "$SELF" ] && continue
    [ -f "$f" ] || continue
    TARGETS+=("$f")
done <<EOF
$(file_list)
EOF

if [ ${#TARGETS[@]} -eq 0 ]; then
    exit 0
fi

status=0

# 1. Absolute home paths.
hits=$(grep -HInE '/(Users|home)/[A-Za-z0-9._-]+' "${TARGETS[@]}" 2>/dev/null \
       | grep -vE "$ALLOWED" || true)
if [ -n "$hits" ]; then
    echo "✖ Absolute developer home paths in tracked files:"
    echo "$hits" | sed 's/^/    /'
    echo "  Use \$HOME, a relative path, or a git-ignored local config instead."
    status=1
fi

# 2. Private terms from the machine-local denylist.
if [ -f "$DENYLIST" ]; then
    while IFS= read -r line || [ -n "$line" ]; do
        term="${line%%#*}"
        # trim surrounding whitespace
        term="${term#"${term%%[![:space:]]*}"}"
        term="${term%"${term##*[![:space:]]}"}"
        [ -z "$term" ] && continue

        found=$(grep -IlF -- "$term" "${TARGETS[@]}" 2>/dev/null || true)
        if [ -n "$found" ]; then
            echo "✖ Private term from $DENYLIST appears in tracked files:"
            echo "$found" | sed 's/^/    /'
            status=1
        fi
    done < "$DENYLIST"
fi

if [ $status -eq 0 ]; then
    echo "✅ No private paths or terms in tracked files"
fi

exit $status
