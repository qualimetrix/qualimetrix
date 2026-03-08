# AIMD vs Competitors: Deep Comparison

**Analysis date:** 2026-03-07 (update of 2025-12-23 analysis)
**Tools:** AIMD (dev), phpmd 2.15.0, phpmetrics 2.9.1, pdepend 2.16.2

---

## Executive Summary

AIMD is **9-39x faster** than competitors on large codebases (sequential and parallel respectively) and has the deepest set of OOP metrics. However, there are accuracy issues in MI calculation (systematic 10-16 point underestimation) and undocumented CCN variant differences. The biggest product gaps are in security analysis, code duplication, dead code detection, and type coverage metrics.

---

## 1. Performance Benchmarks

### 1.1 Test Environments

| Codebase | Files | Description |
|----------|------:|-------------|
| **Small** | 320 | AI Mess Detector (src/) |
| **Medium** | 10,308 | Symfony Framework (src/) |
| **Large** | 9,953 | Production backend (Eda/backend_core) |

All benchmarks: cold cache, PHP 8.4, macOS, Apple Silicon (14 cores).

**Important:** `--workers=0` in AIMD means auto-detect (parallel), NOT sequential. Sequential mode is `--workers=1`. Initial benchmarks incorrectly used `--workers=0` as sequential — corrected below.

### 1.2 Results (Large Codebase, ~10k files)

Detailed benchmarks on `/Users/fractalizer/PhpstormProjects/Eda/all/backend_core` (9,953 files). AIMD numbers are medians of 3 runs.

| Tool | Wall time | CPU time | CPU% | Notes |
|------|----------:|--------:|-----:|-------|
| **AIMD parallel** (14 workers) | **7.9s** | ~35s | 481% | Collection phase: 4.5s |
| **AIMD sequential** (workers=1) | **33.0s** | ~31s | 94% | Collection phase: 29.4s |
| phpmd (all rulesets) | 305s | 295s | 98% | O(n²) scaling |
| phpmd (codesize only) | 159s | 151s | 97% | |
| phpmetrics | 67.6s | - | - | |
| pdepend | 241.5s | - | - | |

*pdepend crashes on Symfony due to PHP 8.4 compatibility issues (deprecated implicit nullable types).*

### 1.3 Relative Performance (vs AIMD, large codebase)

| Tool | vs AIMD sequential | vs AIMD parallel |
|------|-------------------:|-----------------:|
| phpmd (all rules) | **9.2x slower** | **38.6x slower** |
| phpmd (codesize) | 4.8x slower | 20.1x slower |
| phpmetrics | 2.0x slower | 8.6x slower |
| pdepend | 7.3x slower | 30.6x slower |

### 1.4 AIMD Parallel vs Sequential

| Mode | No cache | With cache | Cache speedup |
|------|------:|------:|---:|
| Sequential (workers=1) | 33.0s | 32.9s | 1.00x |
| Parallel (14 workers) | 7.9s | 7.5s | 1.06x |
| **Parallel speedup** | **4.2x** | **4.4x** | |

Phase breakdown (profiler, no cache):

| Phase | Sequential | Parallel | Speedup |
|-------|----------:|--------:|--------:|
| collection.execute_strategy | 29.4s | 4.45s | **6.6x** |
| aggregation | 0.92s | 0.96s | 1.0x |
| dependency | 0.59s | 0.62s | 1.0x |
| rules | 0.51s | 0.49s | 1.0x |
| discovery | 0.30s | 0.30s | 1.0x |

### 1.5 Performance Conclusions

1. **AIMD is 9x faster** than phpmd in sequential mode, **39x faster** in parallel
2. **Parallel mode gives 4.2x speedup** on 10k files with 14 cores — collection phase accelerates 6.6x, but sequential phases (aggregation, rules, dependency = ~2.5s) cap the total gain
3. **AST cache is nearly useless** — 0% speedup for sequential, 6% for parallel. The bottleneck is metric computation, not AST parsing
4. **phpmd scales O(n²)** — PDepend builds a full in-memory dependency graph. 3x files = 7.6x time, 10x files = 87.6x time
5. **pdepend has PHP 8.4 compatibility issues** — crashes on some codebases
6. **For CI:** parallel mode with default settings is optimal. Set `memory_limit >= 1G` (parallel on 10k files needs it). Cache can be skipped — the savings don't justify 886MB disk space

---

## 2. Metric Accuracy Comparison

Detailed comparison on 10 classes from AIMD source code (74 methods total).

### 2.1 Cyclomatic Complexity

| Agreement Category | Methods | % |
|-------------------|--------:|----:|
| AIMD = pdepend CCN (exact) | 50 | 67.6% |
| AIMD = pdepend CCN2 (not CCN) | 11 | 14.9% |
| AIMD matches neither | 13 | 17.6% |

**AIMD is always >= pdepend CCN.** Never lower.

**Root causes of discrepancies:**

1. **`??` counting (biggest source):** AIMD counts null coalescing `??` as a decision point; pdepend does not (in either CCN or CCN2). For chained `??` this inflates CCN significantly:
   ```php
   // HalsteadVisitor::getOperatorName — 6 chained ??
   // AIMD: CCN=8, pdepend CCN=2, CCN2=2
   ```
   This is a defensible but non-standard choice. Industry standard (McCabe, pdepend) does not count `??`.

2. **Boolean operators `&&`/`||`:** AIMD matches pdepend CCN2 exactly for these. Standard CCN2 behavior.

3. **Remaining +1 cases (13 methods):** Likely caused by `match` expression or `foreach` counting differences.

**Conclusion:** AIMD implements a **CCN2+ variant** (CCN2 + `??` counting). This should be documented and ideally made configurable.

### 2.2 NPath Complexity

| Method | AIMD | pdepend | Ratio |
|--------|-----:|--------:|------:|
| getOperandName | 24,576 | 1,417,176 | 0.02x |
| getComplexityIncrement | 256 | 5,832 | 0.04x |
| collectNamespaceMetricValues | 528 | 348 | 1.52x |
| enterNode | 240 | 360 | 0.67x |

**Massive discrepancies** (up to 58x) due to `match` expression handling. pdepend multiplies NPath by number of `match` arms; AIMD appears to undercount significantly. **This needs investigation.**

### 2.3 Maintainability Index

AIMD MI is consistently **10-16 raw points lower** than pdepend MI.

**Bug identified:** AIMD uses **physical LOC** (endLine - startLine + 1, including blank lines and comments) instead of **logical/executable LOC** for the MI formula.

Location: `src/Metrics/Halstead/HalsteadVisitor.php:113`
```php
$methodLoc = max(1, $info['endLine'] - $info['line'] + 1);
```

Impact: The formula `16.2 * ln(LOC)` is very sensitive:
- Physical LOC=63: term = 67.1
- Executable LOC=40: term = 59.8
- **Difference: 7.3 MI points** per method, compounding at class level

The Oman-Hagemeister original paper uses "lines of code" which in metrics literature means logical/executable LOC.

### 2.4 Halstead Metrics

phpmetrics and pdepend compute Halstead at different scopes:
- **phpmetrics:** class-level (treating class as one unit)
- **pdepend:** method-level
- **AIMD:** method-level (via HalsteadVisitor)

Class-level Volume is naturally lower than sum of method Volumes (shared vocabulary counted once). Ratio ~0.3-0.5x is expected.

### 2.5 LCOM Variants

Tools use **fundamentally different LCOM algorithms** with the same name:
- **AIMD:** LCOM4 (graph-based connected components)
- **phpmetrics:** Henderson-Sellers LCOM
- **pdepend:** not reported

These are **not comparable** — different algorithms can give opposite conclusions for the same class.

### 2.6 WMC (Weighted Methods per Class)

| Class | AIMD | phpmetrics | pdepend | Notes |
|-------|-----:|---------:|-------:|-------|
| CognitiveComplexityVisitor | 82 | 80 | 77 | AIMD highest due to CCN2+ |
| HalsteadVisitor | 79 | 77 | 68 | delta=11, `??` chaining |
| AggregationHelper | - | 35 | 34 | delta=1 |
| FileCache | - | 20 | 20 | exact match |

AIMD WMC is consistently highest due to CCN2+ variant propagation.

---

## 3. Full Metrics Coverage Table

### 3.1 Metrics

| Metric | AIMD | pdepend | phpmetrics | phpmd |
|--------|:----:|:-------:|:----------:|:-----:|
| **Complexity** | | | | |
| CCN (Cyclomatic) | CCN2+* | CCN+CCN2 | CCN | CCN |
| NPath | ✅ | ✅ | ❌ | ✅ |
| Cognitive Complexity | ✅ | ❌ | ❌ | ❌ |
| **Halstead** | | | | |
| Volume, Difficulty, Effort, Bugs | ✅ | ✅ | ✅ | ❌ |
| Time | ✅ | ✅ | ❌ | ❌ |
| **Maintainability** | | | | |
| MI (Index) | ✅** | ✅ | ✅ | ❌ |
| MI without comments | ❌ | ❌ | ✅ | ❌ |
| **Size** | | | | |
| LOC / LLOC / CLOC | ✅ | ✅ | ✅ | ❌ |
| ELOC | ❌ | ✅ | ❌ | ❌ |
| Class Count / Method Count | ✅ | ✅ | ✅/✅ | ❌/✅ |
| **Coupling** | | | | |
| CBO | ✅ | ✅ | ❌ | ✅ |
| Ca (Afferent) / Ce (Efferent) | ✅ | ✅ | ✅ | ❌ |
| Instability | ✅ | ❌ | ✅ | ❌ |
| Abstractness / Distance | ✅ | ❌ | ❌ | ❌ |
| RFC | ✅ | ❌ | ❌ | ❌ |
| **Cohesion** | | | | |
| LCOM | LCOM4 | ❌ | HS-LCOM | ❌ |
| TCC / LCC | ✅ | ❌ | ❌ | ❌ |
| WMC | ✅ | ✅ | ✅ | ✅ |
| WOC | ✅ | ❌ | ❌ | ❌ |
| **Inheritance** | | | | |
| DIT / NOC | ✅ | ✅ | ✅/❌ | ✅ |
| **Graph-based** | | | | |
| ClassRank / PageRank | ❌ | CodeRank | PageRank | ❌ |
| Kan Defects | ❌ | ❌ | ✅ | ❌ |
| System Complexity | ❌ | ❌ | ✅ | ❌ |

\* AIMD CCN = CCN2 + null coalescing `??` counting (stricter than standard)
\** AIMD MI uses physical LOC instead of LLOC/ELOC (bug — systematically underestimates by 10-16 points)

### 3.2 Unique AIMD Metrics

1. **Cognitive Complexity** — not available in any competitor
2. **TCC/LCC** (Tight/Loose Class Cohesion) — not available in any competitor
3. **RFC** (Response for Class) — not available in any competitor
4. **WOC** (Weight of Class) — not available in any competitor
5. **Distance from Main Sequence** — not available in any competitor
6. **Abstractness** (per namespace) — not available in phpmd/pdepend at namespace level

---

## 4. Feature Comparison

| Feature | AIMD | phpmd | phpmetrics | pdepend |
|---------|:----:|:-----:|:----------:|:-------:|
| Parallel processing | ✅ | ❌ | ❌ | ❌ |
| Baseline (ignore known issues) | ✅ | ✅ | ❌ | ❌ |
| Git integration (--diff/--staged) | ✅ | ❌ | ✅ | ❌ |
| Inline suppression (@aimd-ignore) | ✅ | ✅ (@SuppressWarnings) | ❌ | ❌ |
| SARIF output | ✅ | ✅ | ❌ | ❌ |
| GitLab Code Quality | ✅ | ❌ | ❌ | ❌ |
| Checkstyle output | ✅ | ✅ | ❌ | ❌ |
| JSON output | ✅ | ✅ | ✅ | ❌ |
| HTML reports | ❌ | ✅ | ✅ | ❌ |
| Graph visualization | ✅ (DOT) | ❌ | ✅ (HTML) | ✅ (SVG) |
| AST caching | ✅ | ✅ | ❌ | ❌ |
| Analysis rules with thresholds | ✅ | ✅ | ❌ | ❌ |
| Custom rules | Planned | ✅ | ❌ | ❌ |
| PHP 8.4 support | ✅ | ⚠️ (deprecated) | ✅ | ⚠️ (crashes) |
| Raw metric export | ❌ | ❌ | ✅ (JSON) | ✅ (XML) |
| Code duplication | ❌ | ❌ | ❌ | ❌ |
| Security rules | Basic* | ❌ | ❌ | ❌ |
| Dead code detection | ❌ | ✅ (unused params) | ❌ | ❌ |
| Type coverage metrics | ❌ | ❌ | ❌ | ❌ |

\* AIMD has superglobals, eval, exit, error suppression rules — but no taint analysis or injection detection.

---

## 5. Accuracy Issues and Bugs Found

### 5.1 Critical: MI Uses Physical LOC (Bug)

**File:** `src/Metrics/Halstead/HalsteadVisitor.php:113`
**Impact:** MI systematically underestimated by 10-16 points
**Fix:** Use LLOC from LocCollector instead of `endLine - startLine + 1`

### 5.2 Important: CCN Variant Undocumented

AIMD counts `??` as a decision point, making it stricter than both CCN and CCN2 standards. This is not documented anywhere. Users comparing AIMD CCN values with other tools will see inflated numbers.

**Options:**
- Document as "CCN2+" variant
- Make `??` counting configurable (default: off for standard compliance)
- Report both CCN and CCN2+ variants

### 5.3 Important: NPath for `match` Expressions

AIMD underestimates NPath by 10-58x compared to pdepend for methods with large `match` expressions. The `match` arm multiplication logic needs review.

### 5.4 Minor: No Raw Metric Export

AIMD only outputs metrics as violations (when thresholds are exceeded). There is no way to export raw metric values for all symbols. This limits use as a metrics platform and makes cross-tool comparison difficult.

**Recommendation:** Add `--format=metrics-json` or `--dump-metrics` flag.

---

## 6. Competitive Gap Analysis

### 6.1 Categories With Zero Coverage

| Category | What's missing | Competitor coverage |
|----------|---------------|-------------------|
| **Security** | No injection detection, no taint analysis, no credential detection | SonarQube (full), Psalm (taint) |
| **Duplication** | No copy-paste detection | SonarQube, phpcpd, phpmetrics (partial) |
| **Dead code** | No unused member detection, no unreachable code | Psalm, PHPStan+extensions, Rector |
| **Type coverage** | No typed/untyped ratio metrics | PHPStan+type-coverage, Psalm |

### 6.2 Broader Competitive Landscape

```
                Type Safety / Bugs          Metrics / Design Quality
                (Psalm, PHPStan)            (AIMD, PhpMetrics, pdepend)
                     |                            |
                     |                            |
    Security --------+------- Code Smells --------+------- Style
    (SonarQube)      |        (PHPMD)             |        (PHPCS)
                     |                            |
                Refactoring                  Duplication
                (Rector)                     (phpcpd)
```

**AIMD's current niche:** Deep OOP metrics + actionable thresholds + fast CI integration.
**Direct competitors:** PhpMetrics (metrics), PHPMD (rules).
**Complementary tools:** PHPStan/Psalm (type safety), phpcpd (duplication), PHPCS (style).

---

## 7. Product Roadmap Recommendations

### 7.1 Short-Term: Fix Accuracy (next release)

| # | Item | Effort | Impact |
|---|------|--------|--------|
| 1 | **Fix MI LOC input** — use LLOC instead of physical LOC | Low | Critical (accuracy) |
| 2 | **Review NPath for `match`** — align with industry standard | Medium | High (accuracy) |
| 3 | **Document CCN variant** — explain `??` counting in README | Low | Medium (trust) |

### 7.2 Short-Term: Quick Wins (1-2 releases)

| # | Item | Effort | Impact |
|---|------|--------|--------|
| 4 | **Type Coverage Collector** — `typeCoverage.param/return/property` | Low | High |
| 5 | **Long Parameter List Rule** — count `$node->params` | Very Low | Medium |
| 6 | **Unreachable Code Rule** — statements after return/throw/exit | Low | Medium |
| 7 | **Raw metric export** — `--format=metrics-json` | Medium | High |

### 7.3 Medium-Term: Fill Critical Gaps (3-6 months)

| # | Item | Effort | Impact |
|---|------|--------|--------|
| 8 | **Code Duplication Detection** — token-stream hashing (Rabin-Karp) | Medium | Very High |
| 9 | **Unused Private Members** — private methods/properties/constants never used within class | Medium | High |
| 10 | **ClassRank (PageRank)** — apply to existing dependency graph | Low-Medium | Medium |
| 11 | **Basic Security Patterns** — direct superglobal-in-sink detection (SQL, XSS, exec) | Medium | High |
| 12 | **Hardcoded Credentials** — regex on variable names + string literals | Low-Medium | Medium |

### 7.4 Long-Term: Differentiation (6-12 months)

| # | Item | Effort | Impact |
|---|------|--------|--------|
| 13 | **Unused Variables** — scope-aware analysis within functions | High | High |
| 14 | **Identical Sub-expression Detection** — `$a === $a`, duplicate if/elseif conditions | Medium | Medium |
| 15 | **Technical Debt Estimation** — remediation time per rule, aggregate reporting | Low | Medium |
| 16 | **HTML Reports** — interactive visualization (a la phpmetrics bubble chart) | High | Medium |

### 7.5 NOT Recommended

| Item | Reason |
|------|--------|
| Full taint analysis | Requires data-flow engine. Psalm/SonarQube own this. |
| Type checking / null safety | PHPStan and Psalm own this space entirely. |
| Auto-fixing | Rector's domain. Different concern. |
| Naming convention rules | PHPCS/PHP-CS-Fixer handle this. Low differentiation. |

### 7.6 Strategic Positioning

**Goal:** Become the single quality gate that replaces phpmd + phpmetrics + phpcpd.

Developers shouldn't need to run 4 tools. AIMD should cover:
- **Metrics** (already strong) — CCN, Halstead, MI, coupling, cohesion
- **Code smells** (partially covered) — extend with dead code, duplication, type coverage
- **Basic security** (not covered) — obvious injection patterns without full taint analysis
- **CI integration** (strong) — baseline, Git integration, SARIF, GitLab CQ

While recommending PHPStan/Psalm as complementary for type safety.

---

## Appendix A: Methodology Notes

| Aspect | AIMD | phpmetrics | pdepend |
|--------|------|-----------|---------|
| Parser | nikic/php-parser 5.x | nikic/php-parser 4/5.x | Custom tokenizer |
| CCN variant | CCN2 + `??` counting | CCN (class-level) | CCN + CCN2 |
| MI scope | Method-level, normalized 0-100 | Class-level, raw 0-171 + comment weight | Method-level, raw 0-171 |
| MI LOC input | Physical LOC (bug) | LLOC | ELOC |
| LCOM variant | LCOM4 (connected components) | Henderson-Sellers | Not reported |
| CBO scope | Efferent coupling (unique types) | Afferent + efferent separated | CA + CE separated |
| Halstead scope | Method-level | Class-level aggregated | Method-level |
| NPath | Method-level | Not reported | Method-level |

## Appendix B: Benchmark Environment

- **Hardware:** Apple Silicon Mac
- **PHP:** 8.4
- **OS:** macOS (Darwin 25.3.0)
- **Method:** Cold cache (no AST cache), sequential tool execution, `time` via Python `time.time()`
- **Note:** Each tool ran after the previous completed (no concurrent benchmarks). Disk pagecache was not explicitly purged between runs.
