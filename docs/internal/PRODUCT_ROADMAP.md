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

| Category              | What's Missing                                                                                                                                                         | Impact                                       | Competitor Coverage                                 |
| --------------------- | ---------------------------------------------------------------------------------------------------------------------------------------------------------------------- | -------------------------------------------- | --------------------------------------------------- |
| ~~**Security**~~      | ~~No injection detection, no credential detection~~ ✅ Basic patterns implemented (SQL injection, XSS, command injection, sensitive parameter, hardcoded credentials)  | Critical for enterprise adoption             | SonarQube (full taint), Psalm (taint), PHPMD (none) |
| ~~**Duplication**~~   | ~~No copy-paste detection~~ ✅ Token-stream duplication detection implemented (`duplication.code-duplication` rule)                                                    | Standard expectation for quality tools       | phpcpd, SonarQube, phpmetrics (partial)             |
| **Dead Code**         | ~~No unused member detection~~, ~~no unreachable code~~ ✅ Unused private members (`code-smell.unused-private`) and unreachable code implemented. Unused variables TBD | High value, frequently requested             | Psalm, PHPStan+extensions, Rector (59 rules)        |
| ~~**Type Coverage**~~ | ~~No typed/untyped ratio metrics~~ ✅ Implemented (`design.type-coverage` rule)                                                                                        | Increasingly important with PHP 8.x adoption | PHPStan+type-coverage extension, Psalm              |

---

## Tier 1: Accuracy Fixes ✅ DONE

### 1.1 Add Raw Metric Export ✅ DONE
Implemented as `--format=metrics-json`.

---

## Tier 2: Quick Wins ✅ DONE

### 2.1 Type Coverage Collector + Rule ✅ DONE
### 2.2 Long Parameter List Rule ✅ DONE
### 2.3 Unreachable Code Rule ✅ DONE
### 2.4 Hardcoded Credentials Rule ✅ DONE

---

## Tier 3: Critical Gap Filling (3-6 Months)

These fill the biggest competitive gaps.

### 3.1 Code Duplication Detection ✅ DONE

- **Approach:** Token-stream hashing (Rabin-Karp), similar to phpcpd
- **Rule:** `duplication.code-duplication` with configurable `min_lines` (5) and `min_tokens` (70)
- **Architecture:** Separate pipeline phase (Phase 3.8), results passed via `AnalysisContext::$duplicateBlocks`
- **SARIF:** `relatedLocations` support for clickable cross-references in IDE
- **Effort:** Medium
- **Value:** Very High — standard expectation, replaces need for phpcpd

### 3.2 Unused Private Members Rule ✅ DONE

- **Rule:** `code-smell.unused-private`
- **Scope:** Detect private methods, properties, constants never referenced within the class
- **Approach:** Two-pass AST: collect declarations, collect references, diff
- **Features:** Magic method awareness, constructor promotion, anonymous class isolation, enum/interface/trait exclusion
- **Effort:** Medium
- **Value:** High

### 3.3 ClassRank (PageRank) Metric ✅ DONE

- **Metric:** `classRank` — applies Google's PageRank algorithm to the dependency graph
- **Implementation:** AIMD already has the dependency graph; PageRank is a simple iterative algorithm
- **Value:** Enables "prioritized violations" — high ClassRank + low quality = high-risk refactoring target
- **Type:** `GlobalContextCollectorInterface` collector
- **Effort:** Low-Medium

### 3.4 Basic Security Pattern Detection ✅ DONE

- **Rule group:** `security.*`
- **Rules:**
  - `security.sql-injection` — direct superglobal concatenation in SQL strings
  - `security.xss` — direct echo/print of superglobals
  - `security.command-injection` — superglobals in exec/system/passthru/shell_exec
  - `security.sensitive-parameter` — parameters with sensitive names (`$password`, `$secret`, `$token`, `$apiKey`, etc.) missing `#[\SensitiveParameter]` attribute (PHP 8.2+)
- **Approach:** AST pattern matching (NOT full taint analysis)
- **Limitation:** Only catches direct flows, not through variables or function calls (except `sensitive-parameter` which is purely signature-based)
- **Effort:** Medium
- **Value:** High for positioning

---

## Tier 4: Differentiation (6-12 Months)

### 4.1 Unused Variables Detection
- Scope-aware analysis within functions (assigned but never read)
- Requires new analysis pass type
- High effort, high value

### 4.2 Identical Sub-expression Detection ✅ DONE

- **Rule:** `code-smell.identical-subexpression`
- **Detection types:**
  - Identical operands in binary operations (`$a === $a`, `$a - $a`, `$a && $a`)
  - Duplicate conditions in if/elseif chains
  - Identical ternary branches (`$cond ? $x : $x`)
  - Duplicate match arm conditions
- **Approach:** Recursive structural AST comparison with side-effect exclusion
- **Effort:** Medium
- **Value:** Medium — catches copy-paste errors and logic bugs

### 4.3 Technical Debt Estimation ✅ DONE
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
