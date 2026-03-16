# AIMD Product Roadmap

**Updated:** 2026-03-16
**Based on:** [Competitive analysis](COMPETITOR_COMPARISON.md), cross-ecosystem research (SonarQube, ESLint, Semgrep, NDepend, CodeScene, RuboCop, Ruff, ArchUnit)

---

## Strategic Positioning

**Current niche:** Deep OOP metrics + actionable thresholds + fast CI integration.

**Goal:** Become the single quality gate tool that replaces phpmd + phpmetrics + phpcpd + deptrac, while recommending PHPStan/Psalm as complementary tools for type safety.

**Core advantage:** 9–39x faster than competitors (sequential/parallel). Unique metrics (Cognitive Complexity, TCC/LCC, RFC, ClassRank). Modern PHP 8.4 support while competitors degrade (pdepend crashes, phpmd deprecation warnings). Only PHP tool with parallel processing.

```
What AIMD should own:              What to leave to others:
─────────────────────────────────  ──────────────────────────────────────
- OOP metrics (depth)              - Type inference (PHPStan/Psalm)
- Code smells (breadth)            - Taint analysis (Psalm/SonarQube)
- Basic security patterns          - Auto-fixing (Rector)
- Duplication detection            - Style/formatting (PHPCS/PHP-CS-Fixer)
- Type coverage metrics            - Naming conventions (PHPCS)
- Architecture rules (deptrac)
- Trend analysis / quality gates
- CI integration (speed)
```

---

## Completed Work

<details>
<summary><strong>Tier 1–4: All Done</strong> (click to expand)</summary>

### Tier 1: Accuracy Fixes
- Raw metric export (`--format=metrics-json`)
- MI formula fix (LLOC instead of physical LOC)

### Tier 2: Quick Wins
- Type Coverage collector + rule (`design.type-coverage`)
- Long Parameter List rule (`code-smell.long-parameter-list`)
- Unreachable Code rule (`code-smell.unreachable-code`)
- Hardcoded Credentials rule (`security.hardcoded-credentials`)

### Tier 3: Critical Gap Filling
- Code Duplication detection (`duplication.code-duplication`) — token-stream Rabin-Karp
- Unused Private Members (`code-smell.unused-private`) — methods, properties, constants
- ClassRank / PageRank metric — `GlobalContextCollectorInterface`
- Security patterns — `security.sql-injection`, `security.xss`, `security.command-injection`, `security.sensitive-parameter`
- Identical Sub-expression (`code-smell.identical-subexpression`)
- Technical Debt estimation — remediation time per rule, debt summary in reports

### Tier 4: CI Integration & Usability
- `--fail-on` option — control exit code by severity (`--fail-on=error` ignores warnings)
- `--format=github` — GitHub Actions inline PR annotations without SARIF upload

</details>

### Remaining Gap: Dead Code

Unused private members and unreachable code are done. **Unused variables** is the remaining gap — see Phase 2 below.

---

## Phase 1: Composite Rules & Quick Wins

Low effort — all data already collected, only need rule logic on top.

### 1.1 God Class Detection ✅

- **Rule:** `code-smell.god-class`
- **Logic:** Composite threshold on WMC + LCOM4 + TCC + class LOC. A class is a God Class when it has high WMC, low cohesion (high LCOM or low TCC), and is large
- **Thresholds:** WMC ≥ 47, LCOM4 ≥ 3, TCC < 0.33, classLoc ≥ 300 (any 3 of 4 = Warning, all evaluable = Error)
- **Reference:** Lanza & Marinescu "Object-Oriented Metrics in Practice", SonarQube S1820
- **Effort:** Low
- **Value:** High — the most requested OOP smell, phpmd has it, SonarQube has it

### 1.2 Data Class Detection ✅

- **Rule:** `code-smell.data-class`
- **Logic:** High WOC (≥ 80% = mostly public accessors), low WMC (≤ 10), excludes readonly/promoted-only/intentional DTOs
- **Reference:** Fowler's "Refactoring", Lanza & Marinescu
- **Effort:** Low
- **Value:** Medium — encourages moving behavior closer to data

### 1.3 Feature Envy Detection

- **Rule:** `code-smell.feature-envy`
- **Logic:** Method uses more symbols from another class than from its own class. Requires method-level coupling data (already available via RFC/CBO collectors)
- **Note:** May need additional method-level coupling metric (external accesses per method). Evaluate feasibility before committing
- **Effort:** Medium (may need new per-method metric)
- **Value:** Medium — classic Fowler smell

### 1.4 Constructor Over-injection ✅

- **Rule:** `code-smell.constructor-overinjection`
- **Logic:** `__construct` parameter count ≥ threshold (warning: 8, error: 12)
- **Effort:** Low
- **Value:** Medium — direct signal of SRP violation in DI-heavy codebases

### 1.5 Cyclomatic Density

- **Metric:** `cyclomaticDensity` = CCN / LLOC
- **Rule:** `complexity.cyclomatic-density`
- **Logic:** Normalized complexity — high CCN in a short method is worse than the same CCN spread over many lines
- **Reference:** Gill & Kemerer, NDepend "IL Cyclomatic Complexity / IL LOC"
- **Effort:** Low (derived metric from existing CCN + LLOC)
- **Value:** Low-Medium — useful for prioritization

### 1.6 Effort-Aware Prioritization

- **Feature:** Prioritized violation output combining ClassRank × severity × remediation_time
- **Output:** "Top N highest-impact issues" section in text/JSON reports
- **Reference:** CodeScene hotspot analysis, SonarQube debt ratio
- **All data already exists:** ClassRank, severity, remediation time
- **Effort:** Low-Medium (reporting layer change)
- **Value:** High — answers "what should I fix first?"

---

## Phase 2: Dead Code & Complexity Insights

### 2.1 Unused Variables Detection

- **Rule:** `code-smell.unused-variable`
- **Scope:** Variables assigned but never read within a function/method scope
- **Challenges:** Compact assignments (`list()`, `[...]`), `extract()`, variable variables (`$$x`), closures with `use`
- **Approach:** Scope-aware single-pass AST visitor tracking writes/reads
- **Reference:** ESLint no-unused-vars, Pylint W0612, PHPStan (via extension)
- **Effort:** High
- **Value:** High — universally expected

### 2.2 Cognitive Complexity Breakdown

- **Feature:** Per-element contribution to total Cognitive Complexity in violation messages
- **Output:** `"Cognitive complexity is 35 (if+3 at line 12, nested for+4 at line 15, ...)"` — top N contributors
- **Reference:** SonarQube's inline annotation display
- **Effort:** Medium (refactor CognitiveComplexityVisitor to track per-increment source)
- **Value:** High — unique in PHP ecosystem, dramatically improves actionability

### 2.3 CRAP Index (Change Risk Anti-Patterns)

- **Metric:** `crap` = CCN² × (1 − coverage)². Without coverage data: CRAP = CCN²
- **Input:** Optional Clover XML coverage file (`--coverage=clover.xml`)
- **Rule:** `complexity.crap`
- **Reference:** Alberto Savoia, crap4j; phpunit `--log-crap4j`
- **Note:** Value is limited without coverage data. Consider whether the optional dependency is worth the complexity
- **Effort:** Medium
- **Value:** Medium (high if coverage data available, low otherwise)

---

## Phase 3: Architecture & Ecosystem

### 3.1 Architecture Rules (deptrac-killer)

- **Feature:** Declarative dependency constraints in `aimd.yml`
- **Syntax (draft):**
  ```yaml
  architecture:
    layers:
      Controller: App\Controller\**
      Service: App\Service\**
      Repository: App\Repository\**
    rules:
      - Controller must not depend on Repository
      - Repository must not depend on Controller
  ```
- **Implementation:** AIMD already has the full dependency graph. Need: layer DSL parser, constraint evaluator, new rule `architecture.layer-violation`
- **Reference:** deptrac (PHP), ArchUnit (Java), NetArchTest (.NET), Dependency Cruiser (JS)
- **Effort:** Medium-High
- **Value:** Very High — replaces deptrac, reduces tool count. "One tool instead of five"

### 3.2 Custom Rules API

- **Feature:** User-defined rules without forking AIMD
- **Options:**
  - **YAML pattern rules** (like Semgrep) — low-code, pattern-based, limited power
  - **PHP plugin interface** — `implements RuleInterface`, autoloaded from a configured path
  - **Both** — YAML for simple patterns, PHP for complex logic
- **Reference:** ESLint plugins, PHPStan extensions, Semgrep custom rules
- **Effort:** Medium (PHP plugins) to High (YAML DSL)
- **Value:** High — critical for enterprise adoption

### 3.3 Trend Analysis & Quality Gates

- **Feature:** Store metric snapshots between runs, detect regressions
- **Approach:** SQLite database (`~/.aimd/history.db` or project-local) storing per-run aggregates
- **Commands:**
  - `aimd trend` — show metric trends over time
  - `aimd check --quality-gate=no-regression` — fail if any metric worsened vs previous run
- **Output:** Sparkline-style trend indicators in text report, JSON timeseries
- **Reference:** SonarQube quality gates (the killer feature), CodeScene trend analysis
- **Effort:** High
- **Value:** Very High — no PHP CLI tool does this. Unique selling point

### 3.4 Interactive HTML Reports

- **Feature:** Self-contained HTML file with interactive visualizations
- **Visualizations:**
  - Treemap (file size × complexity, colored by MI)
  - Bubble chart (coupling × cohesion × size)
  - Dependency graph (interactive, zoomable)
  - Hotspot table (sortable, filterable)
- **Reference:** phpmetrics HTML reports, NDepend dashboards, CodeScene visualizations
- **Effort:** High (standalone — needs JS charting library embedded)
- **Value:** Medium — impressive for presentations/reviews, not essential for CI

---

## Not Recommended

| Item                            | Reason                                                                                                                                                 |
| ------------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------ |
| **Full taint analysis**         | Requires inter-procedural data-flow engine. Psalm and SonarQube have years of investment here. Not practical to compete.                               |
| **Type checking / null safety** | PHPStan and Psalm own this completely. Would require building a type inference engine.                                                                 |
| **Auto-fixing**                 | Rector's domain. Fundamentally different concern from analysis.                                                                                        |
| **Naming convention rules**     | PHPCS/PHP-CS-Fixer handle this well. Low differentiation value.                                                                                        |
| **Framework-specific rules**    | Adds maintenance burden. AIMD is framework-agnostic by design. Configuration presets (strict/relaxed) are acceptable, but not framework-coupled rules. |

---

## Success Metrics

After Phase 1–2, AIMD replaces: **phpmd + phpmetrics + phpcpd** (already largely achieved).

After Phase 3, AIMD replaces: **phpmd + phpmetrics + phpcpd + deptrac** and offers capabilities no PHP tool has (trend analysis, quality gates).

**Target value proposition:** "One tool. 40x faster. Deeper metrics. Quality gates. Replaces five tools."

---

## Technical Debt

Items that improve developer experience and code health but are not user-facing:

| Item                        | Priority | Effort | Description                                         |
| --------------------------- | -------- | ------ | --------------------------------------------------- |
| Global function aggregation | Low      | Small  | Aggregate function-level metrics to namespace level |
