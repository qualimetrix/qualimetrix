# AIMD Product Roadmap

**Date:** 2026-03-07
**Based on:** [Competitive analysis](COMPETITOR_COMPARISON.md)

---

## Strategic Positioning

**Current niche:** Deep OOP metrics + actionable thresholds + fast CI integration.

**Goal:** Become the single quality gate tool that replaces phpmd + phpmetrics + phpcpd, while recommending PHPStan/Psalm as complementary tools for type safety.

**Core advantage:** 9x faster in sequential mode, 39x with parallelization (vs phpmd on 10k files). Unique metrics (Cognitive Complexity, TCC/LCC, RFC). Modern PHP 8.4 support while competitors degrade (pdepend crashes, phpmd throws deprecation warnings).

```
What AIMD should own:              What to leave to others:
- OOP metrics (depth)              - Type inference (PHPStan/Psalm)
- Code smells (breadth)            - Taint analysis (Psalm/SonarQube)
- Basic security patterns          - Auto-fixing (Rector)
- Duplication detection            - Style/formatting (PHPCS/PHP-CS-Fixer)
- Type coverage metrics            - Naming conventions (PHPCS)
- CI integration (speed)
```

---

## Categories With Zero Coverage (Gaps)

| Category          | What's Missing                                  | Impact                                       | Competitor Coverage                                 |
| ----------------- | ----------------------------------------------- | -------------------------------------------- | --------------------------------------------------- |
| **Security**      | No injection detection, no credential detection | Critical for enterprise adoption             | SonarQube (full taint), Psalm (taint), PHPMD (none) |
| **Duplication**   | No copy-paste detection                         | Standard expectation for quality tools       | phpcpd, SonarQube, phpmetrics (partial)             |
| **Dead Code**     | No unused member detection, no unreachable code | High value, frequently requested             | Psalm, PHPStan+extensions, Rector (59 rules)        |
| **Type Coverage** | No typed/untyped ratio metrics                  | Increasingly important with PHP 8.x adoption | PHPStan+type-coverage extension, Psalm              |

---

## Tier 1: Accuracy Fixes (Next Release)

Must-fix issues that affect trust in AIMD's output.

### 1.1 ~~MI Uses Physical LOC Instead of LLOC~~ [DONE]

Fixed in commit 1048c9f. AIMD now uses LLOC (logical lines -- statement count) instead of physical LOC for the MI formula.

**Classification:** Real accuracy issue
**Impact:** MI was systematically underestimated by 10-16 points
**Location:** `src/Metrics/Halstead/HalsteadVisitor.php` (fixed)

MaintainabilityIndexCollector now uses LLOC from the LocCollector instead of physical LOC from HalsteadVisitor.

### 1.2 Document CCN Variant

**Classification:** Design choice (not a bug), but needs documentation
**Impact:** Users comparing AIMD CCN with other tools see inflated numbers

AIMD's `SIMPLE_DECISION_NODES` explicitly includes:
- `Coalesce` (`??`) — not in standard CCN2
- `NullsafeMethodCall` (`?->`) — not in standard CCN2
- `Ternary` (`?:`) — in standard CCN2

This is a defensible choice (all are decision points semantically), but:
- It's not documented in `src/Metrics/README.md`
- The metric is exposed as `ccn` which implies standard CCN
- Users get confused when values don't match phpmd/pdepend

**Options to consider:**
1. Document as "CCN2+" and rename metric to clarify
2. Make `??` / `?->` counting configurable (opt-in/opt-out)
3. Report both standard CCN and CCN2+ variants

### 1.3 NPath for `match` Expressions

**Classification:** Methodology difference (AIMD is arguably more correct)
**Impact:** AIMD values are 10-58x lower than pdepend for `match`-heavy methods

AIMD uses **additive** NPath for `match`: `NPath = 1 + Σ NPath(arm_body)`
This is consistent with Nejmeh's formula for `switch`.

pdepend produces extreme values (e.g., 1,417,176 for a method with ~16 match arms) — likely because it doesn't properly handle `match(true) { X instanceof A => ... }` and treats each `instanceof` as a nested conditional.

**Verdict:** AIMD's approach is more reasonable. pdepend's values are impractical (1.4M NPath is not actionable). No fix needed, but should be documented.

### 1.4 Add Raw Metric Export

**Classification:** Missing feature
**Impact:** Can't compare metrics without threshold violations, limits use as metrics platform

Currently metrics are only visible when they exceed thresholds (as violations). There's no way to export raw metric values for analysis or cross-tool comparison.

**Proposal:** Add `--format=metrics-json` or `--dump-metrics` option.

---

## Tier 2: Quick Wins (1-2 Releases)

Low-effort, high-value additions.

### 2.1 Type Coverage Collector + Rule

- **Metrics:** `typeCoverage.param`, `typeCoverage.return`, `typeCoverage.property`
- **Rule:** `design.type-coverage` with configurable minimum thresholds
- **Effort:** Low (count nodes with/without type declarations in AST)
- **Value:** High — tracks PHP modernization progress, unique vs phpmd/phpmetrics

### 2.2 Long Parameter List Rule

- **Rule:** `code-smell.long-parameter-list`
- **Implementation:** Count `$node->params` on ClassMethod/Function_ nodes
- **Default:** warning at 4, error at 6
- **Effort:** Very low (trivial AST check)

### 2.3 Unreachable Code Rule

- **Rule:** `code-smell.unreachable-code`
- **Implementation:** Detect statements after `return`, `throw`, `exit`, `continue`, `break` in statement lists
- **Effort:** Low (check for terminal nodes in statement sequences)

### 2.4 Hardcoded Credentials Rule

- **Rule:** `code-smell.hardcoded-credentials`
- **Implementation:** Regex on variable names (`$password`, `$apiKey`, `$secret`, etc.) + string literal assignments
- **Effort:** Low-Medium

---

## Tier 3: Critical Gap Filling (3-6 Months)

These fill the biggest competitive gaps.

### 3.1 Code Duplication Detection

- **Approach:** Token-stream hashing (Rabin-Karp), similar to phpcpd
- **Metrics:** `duplication.ratio`, `duplication.blocks`, `duplication.lines`
- **Architecture:** Separate pipeline stage (runs on token stream, not AST)
- **Effort:** Medium
- **Value:** Very High — standard expectation, replaces need for phpcpd

### 3.2 Unused Private Members Rule

- **Rule:** `code-smell.unused-private`
- **Scope:** Detect private methods, properties, constants never referenced within the class
- **Approach:** Two-pass AST: collect declarations, collect references, diff
- **Effort:** Medium
- **Value:** High

### 3.3 ClassRank (PageRank) Metric

- **Metric:** `classRank` — applies Google's PageRank algorithm to the dependency graph
- **Implementation:** AIMD already has the dependency graph; PageRank is a simple iterative algorithm
- **Value:** Enables "prioritized violations" — high ClassRank + low quality = high-risk refactoring target
- **Type:** `GlobalContextCollectorInterface` collector
- **Effort:** Low-Medium

### 3.4 Basic Security Pattern Detection

- **Rule group:** `security.*`
- **Rules:**
  - `security.sql-injection` — direct superglobal concatenation in SQL strings
  - `security.xss` — direct echo/print of superglobals
  - `security.command-injection` — superglobals in exec/system/passthru/shell_exec
- **Approach:** AST pattern matching (NOT full taint analysis)
- **Limitation:** Only catches direct flows, not through variables or function calls
- **Effort:** Medium
- **Value:** High for positioning

---

## Tier 4: Differentiation (6-12 Months)

### 4.1 Unused Variables Detection
- Scope-aware analysis within functions (assigned but never read)
- Requires new analysis pass type
- High effort, high value

### 4.2 Identical Sub-expression Detection
- `$a === $a`, duplicate conditions in if/elseif chains
- AST comparison within expressions
- Medium effort, medium value

### 4.3 Technical Debt Estimation
- Assign remediation time metadata to each rule (e.g., "15 min to fix")
- Aggregate and report total "debt" per file/namespace/project
- Low effort (metadata on rules), medium value

### 4.4 HTML Reports
- Interactive visualization (bubble chart: complexity vs coupling, colored by MI)
- A la phpmetrics, but generated from AIMD data
- High effort, medium value (nice-to-have, not essential for CI)

---

## NOT Recommended

| Item                            | Reason                                                                                                                   |
| ------------------------------- | ------------------------------------------------------------------------------------------------------------------------ |
| **Full taint analysis**         | Requires inter-procedural data-flow engine. Psalm and SonarQube have years of investment here. Not practical to compete. |
| **Type checking / null safety** | PHPStan and Psalm own this completely. Would require building a type inference engine.                                   |
| **Auto-fixing**                 | Rector's domain. Fundamentally different concern from analysis.                                                          |
| **Naming convention rules**     | PHPCS/PHP-CS-Fixer handle this well. Low differentiation value.                                                          |
| **Framework-specific presets**  | Adds maintenance burden. AIMD is framework-agnostic by design.                                                           |

---

## Success Metrics

After implementing Tier 2 + Tier 3, AIMD should be able to replace:
- **phpmd** — AIMD already has all phpmd metrics + more; adding code smells closes the gap
- **phpmetrics** — AIMD has deeper metrics; adding ClassRank and HTML reports matches feature parity
- **phpcpd** — duplication detection makes phpcpd redundant

**Target value proposition:** "Run one tool instead of four. 100x faster. Better metrics."
