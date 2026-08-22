#!/bin/bash
# Builds the website with `mkdocs --strict`, turning documentation warnings —
# broken internal links, pages missing from the nav, unreadable includes — into
# a failing exit code.
#
# Without this gate a broken link only surfaces when someone happens to click
# it: `composer check` never touched the website, and the deploy workflow built
# the site non-strictly on version tags only.
#
# The build goes to a temporary directory: this is a check, not a publish step,
# and the site must never appear in the working tree.
#
# The mkdocs interpreter is resolved from, in order:
#
#   $QMX_MKDOCS            explicit path, wins over everything
#   website/.venv/bin/mkdocs   local development virtualenv
#   mkdocs                 whatever is on PATH (CI installs it globally)
#
# Each candidate is probed with `--version` before use, because a virtualenv
# whose interpreter path went stale still exists as a file and would otherwise
# fail with a confusing "bad interpreter". When no candidate works the script
# fails with setup instructions — it never skips silently, since a green run
# that checked nothing is the failure mode this gate exists to remove.
#
# Portable to bash 3.2 (macOS system bash).

set -uo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PROJECT_ROOT="$(dirname "$SCRIPT_DIR")"
cd "$PROJECT_ROOT" || exit 1

CONFIG="website/mkdocs.yml"

MKDOCS=""
TRIED=""
for candidate in "${QMX_MKDOCS:-}" "website/.venv/bin/mkdocs" "mkdocs"; do
    [ -n "$candidate" ] || continue
    TRIED="$TRIED  $candidate"$'\n'
    if command -v "$candidate" > /dev/null 2>&1 && "$candidate" --version > /dev/null 2>&1; then
        MKDOCS="$candidate"
        break
    fi
done

if [ -z "$MKDOCS" ]; then
    echo "❌ No working mkdocs found. Tried:" >&2
    printf '%s' "$TRIED" >&2
    echo "" >&2
    echo "Install it into a local virtualenv:" >&2
    echo "  python3 -m venv website/.venv" >&2
    echo "  website/.venv/bin/pip install -r website/requirements.txt" >&2
    echo "" >&2
    echo "Or point QMX_MKDOCS at an existing installation." >&2
    exit 1
fi

SITE_DIR="$(mktemp -d "${TMPDIR:-/tmp}/qmx-docs.XXXXXX")"
trap 'rm -rf "$SITE_DIR"' EXIT

echo "📚 Building documentation with --strict ($MKDOCS)..."
if ! "$MKDOCS" build --strict --config-file "$CONFIG" --site-dir "$SITE_DIR"; then
    echo "" >&2
    echo "❌ Documentation build failed under --strict" >&2
    exit 1
fi

echo "✅ Documentation OK"
