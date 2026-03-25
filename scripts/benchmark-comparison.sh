#!/bin/bash
# Benchmark comparison: Qualimetrix vs PHPMD vs PHPMetrics vs PDepend
# Usage: ./scripts/benchmark-comparison.sh [small|medium|large|all]

set -euo pipefail

# Paths
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PROJECT_ROOT="$(dirname "$SCRIPT_DIR")"
RESULTS_DIR="$PROJECT_ROOT/benchmark-results"
COMPOSER_BIN="$HOME/.composer/vendor/bin"

# Codebase paths
SMALL_CODEBASE="$PROJECT_ROOT/src"
MEDIUM_CODEBASE="/Users/fractalizer/.composer/vendor/laravel/framework/src"
LARGE_CODEBASE="/Users/fractalizer/PhpstormProjects/Eda/all/backend_core"

# Colors
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
NC='\033[0m'

# Create results directory
mkdir -p "$RESULTS_DIR"
TIMESTAMP=$(date +%Y%m%d_%H%M%S)

log() { echo -e "${BLUE}[INFO]${NC} $1"; }
warn() { echo -e "${YELLOW}[WARN]${NC} $1"; }
success() { echo -e "${GREEN}[OK]${NC} $1"; }
error() { echo -e "${RED}[ERROR]${NC} $1"; }

# Clean caches
clean_caches() {
    log "Cleaning caches..."

    # Qualimetrix cache
    rm -rf "$PROJECT_ROOT/.qmx-cache" 2>/dev/null || true
    rm -f "$PROJECT_ROOT/.phpmd.result-cache.php" 2>/dev/null || true

    # System caches
    sync

    success "Caches cleaned"
}

# Count PHP files
count_files() {
    local path="$1"
    find "$path" -name "*.php" -type f 2>/dev/null | wc -l | tr -d ' '
}

# Run benchmark for a single tool
run_benchmark() {
    local tool="$1"
    local path="$2"
    local output_file="$3"
    local run_num="$4"

    local start_time end_time duration
    local mem_before mem_after mem_used

    start_time=$(python3 -c "import time; print(time.time())")

    case "$tool" in
        "qmx-seq")
            "$PROJECT_ROOT/bin/qmx" analyze "$path" --workers=0 --format=json > /dev/null 2>&1
            ;;
        "qmx-par")
            "$PROJECT_ROOT/bin/qmx" analyze "$path" --workers=4 --format=json > /dev/null 2>&1
            ;;
        "phpmd")
            "$COMPOSER_BIN/phpmd" "$path" text cleancode,codesize,design --ignore-violations-on-exit > /dev/null 2>&1
            ;;
        "phpmetrics")
            "$COMPOSER_BIN/phpmetrics" --report-json=/tmp/phpmetrics-out.json "$path" > /dev/null 2>&1
            ;;
        "pdepend")
            "$COMPOSER_BIN/pdepend" --summary-xml=/tmp/pdepend-out.xml "$path" > /dev/null 2>&1
            ;;
    esac

    end_time=$(python3 -c "import time; print(time.time())")
    duration=$(python3 -c "print(round($end_time - $start_time, 2))")

    echo "$duration"
}

# Run benchmark suite for a codebase
benchmark_codebase() {
    local name="$1"
    local path="$2"
    local runs="${3:-3}"

    if [[ ! -d "$path" ]]; then
        warn "Codebase not found: $path"
        return 1
    fi

    local file_count=$(count_files "$path")
    log "Benchmarking: $name ($file_count PHP files)"
    log "Path: $path"
    log "Runs: $runs"
    echo ""

    local result_file="$RESULTS_DIR/benchmark_${name}_${TIMESTAMP}.csv"
    echo "tool,run,duration_sec" > "$result_file"

    for tool in "qmx-seq" "qmx-par" "phpmd" "phpmetrics" "pdepend"; do
        log "Testing: $tool"

        for ((i=1; i<=runs; i++)); do
            clean_caches

            local duration
            duration=$(run_benchmark "$tool" "$path" "$result_file" "$i" 2>/dev/null || echo "ERROR")

            if [[ "$duration" == "ERROR" ]]; then
                warn "  Run $i: FAILED"
                echo "$tool,$i,ERROR" >> "$result_file"
            else
                success "  Run $i: ${duration}s"
                echo "$tool,$i,$duration" >> "$result_file"
            fi
        done
        echo ""
    done

    # Generate summary
    log "Generating summary..."
    python3 << EOF
import csv
from collections import defaultdict
import statistics

data = defaultdict(list)
with open("$result_file") as f:
    reader = csv.DictReader(f)
    for row in reader:
        if row['duration_sec'] != 'ERROR':
            data[row['tool']].append(float(row['duration_sec']))

print("\n" + "="*60)
print(f"SUMMARY: $name ($file_count files)")
print("="*60)
print(f"{'Tool':<15} {'Mean (s)':<12} {'Min (s)':<12} {'Max (s)':<12}")
print("-"*60)

for tool in sorted(data.keys()):
    times = data[tool]
    if times:
        mean = statistics.mean(times)
        min_t = min(times)
        max_t = max(times)
        print(f"{tool:<15} {mean:<12.2f} {min_t:<12.2f} {max_t:<12.2f}")
    else:
        print(f"{tool:<15} {'FAILED':<12} {'-':<12} {'-':<12}")

print("="*60 + "\n")
EOF

    success "Results saved to: $result_file"
}

# Main
main() {
    local target="${1:-small}"

    echo ""
    echo "========================================"
    echo " PHP Analysis Tools Benchmark"
    echo " Qualimetrix vs PHPMD vs PHPMetrics vs PDepend"
    echo "========================================"
    echo ""

    # Check tools
    for tool in "$PROJECT_ROOT/bin/qmx" "$COMPOSER_BIN/phpmd" "$COMPOSER_BIN/phpmetrics" "$COMPOSER_BIN/pdepend"; do
        if [[ ! -x "$tool" ]]; then
            error "Tool not found: $tool"
            exit 1
        fi
    done
    success "All tools available"
    echo ""

    # Check Laravel if needed
    if [[ "$target" == "medium" || "$target" == "all" ]]; then
        if [[ ! -d "$MEDIUM_CODEBASE" ]]; then
            log "Installing Laravel framework for medium benchmark..."
            composer global require laravel/framework --no-interaction 2>/dev/null || true
        fi
    fi

    case "$target" in
        "small")
            benchmark_codebase "small" "$SMALL_CODEBASE" 3
            ;;
        "medium")
            benchmark_codebase "medium" "$MEDIUM_CODEBASE" 3
            ;;
        "large")
            benchmark_codebase "large" "$LARGE_CODEBASE" 2
            ;;
        "all")
            benchmark_codebase "small" "$SMALL_CODEBASE" 3
            benchmark_codebase "medium" "$MEDIUM_CODEBASE" 3
            benchmark_codebase "large" "$LARGE_CODEBASE" 2
            ;;
        *)
            error "Unknown target: $target"
            echo "Usage: $0 [small|medium|large|all]"
            exit 1
            ;;
    esac

    success "Benchmark complete!"
    log "Results directory: $RESULTS_DIR"
}

main "$@"
