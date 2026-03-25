#!/bin/bash
# ============================================================================
# Qualimetrix pre-commit hook
# ============================================================================
# Analyzes staged PHP files before commit.
# Blocks commit when violations are found.
#
# Installation:
#   ln -s ../../scripts/pre-commit-hook.sh .git/hooks/pre-commit
#
# Or copy:
#   cp scripts/pre-commit-hook.sh .git/hooks/pre-commit
#   chmod +x .git/hooks/pre-commit
# ============================================================================

set -e

# Get list of staged PHP files (exclude deleted)
STAGED_FILES=$(git diff --cached --name-only --diff-filter=ACM -- '*.php')

# If no staged PHP files, exit successfully
if [ -z "$STAGED_FILES" ]; then
    exit 0
fi

echo "🔍 Running Qualimetrix on staged files..."
echo ""

# Convert file list to command arguments
FILES_ARGS=$(echo "$STAGED_FILES" | tr '\n' ' ')

# Determine path to qmx (vendor/bin or bin/)
if [ -f "vendor/bin/qmx" ]; then
    QMX_BIN="vendor/bin/qmx"
elif [ -f "bin/qmx" ]; then
    QMX_BIN="bin/qmx"
else
    echo "❌ Error: qmx binary not found!"
    echo "Run 'composer install' first."
    exit 1
fi

# Run analysis
# Use baseline if available
BASELINE_ARG=""
if [ -f "baseline.json" ]; then
    BASELINE_ARG="--baseline=baseline.json"
fi

# Run qmx
if $QMX_BIN check $FILES_ARGS $BASELINE_ARG; then
    echo ""
    echo "✅ Qualimetrix passed."
    exit 0
else
    EXIT_CODE=$?
    echo ""
    echo "❌ Qualimetrix found issues."
    echo ""
    echo "Options:"
    echo "  - Fix the issues and try again"
    echo "  - Update baseline: $QMX_BIN check src/ --generate-baseline=baseline.json"
    echo "  - Skip this check: git commit --no-verify"
    exit $EXIT_CODE
fi
