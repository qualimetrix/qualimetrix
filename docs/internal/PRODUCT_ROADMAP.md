# AIMD Product Roadmap

**Updated:** 2026-03-17
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

## Phase 1: Composite Rules & Quick Wins

Low effort — all data already collected, only need rule logic on top.

### 1.1 Feature Envy Detection

- **Rule:** `code-smell.feature-envy`
- **Logic:** Method uses more symbols from another class than from its own class. Requires method-level coupling data (already available via RFC/CBO collectors)
- **Note:** May need additional method-level coupling metric (external accesses per method). Evaluate feasibility before committing
- **Effort:** Medium (may need new per-method metric)
- **Value:** Medium — classic Fowler smell

### 1.2 Effort-Aware Prioritization

- **Feature:** Prioritized violation output combining ClassRank × severity × remediation_time
- **Output:** "Top N highest-impact issues" section in text/JSON reports
- **Reference:** CodeScene hotspot analysis, SonarQube debt ratio
- **All data already exists:** ClassRank, severity, remediation time
- **Effort:** Low-Medium (reporting layer change)
- **Value:** High — answers "what should I fix first?"

### 1.3 Cyclomatic Density

- **Metric:** `cyclomaticDensity` = CCN / LLOC
- **Rule:** `complexity.cyclomatic-density`
- **Logic:** Normalized complexity — high CCN in a short method is worse than the same CCN spread over many lines
- **Reference:** Gill & Kemerer, NDepend "IL Cyclomatic Complexity / IL LOC"
- **Effort:** Low (derived metric from existing CCN + LLOC)
- **Value:** Low-Medium — useful for prioritization

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

## Phase 3: HTML Report Visualizations

The basic HTML report (treemap, health bars, tables) is done. These additions leverage data we already collect but don't visualize prominently.

### 3.1 Instability/Abstractness Scatter (Martin Diagram)

- **Data:** `instability`, `abstractness`, `distance` (namespace-level, already collected)
- **Visualization:** Scatter plot. X = Instability (0..1), Y = Abstractness (0..1). Diagonal = Main Sequence. Zone of Pain (0,0) = concrete & stable → brittle. Zone of Uselessness (1,1) = abstract & unstable → dead code. Dot size = LOC, color = distance from main sequence
- **Reference:** Robert C. Martin "Clean Architecture", NDepend abstractness/instability graph
- **Effort:** Low (data ready, simple D3 scatter)
- **Value:** High — canonical architecture health diagram, one glance shows at-risk modules

### 3.2 Complexity Distribution (Box Plots)

- **Data:** Per-method `ccn`, `cognitive`, `npath` (already collected at method level)
- **Visualization:** Box plot or histogram per class/namespace showing method complexity distribution. Click outlier → navigate to method
- **Why:** avg/p95 hide distribution shape. Two classes with avg=10 can be radically different (200 simple methods + 3 monsters vs all moderate)
- **Effort:** Medium (need method-level data in HTML tree, D3 box plot)
- **Value:** High — distribution is far more informative than summary stats

### 3.3 Tech Debt Breakdown

- **Data:** `debtMinutes` per violation, `violationCode` grouped by rule group (complexity, coupling, code-smell...)
- **Visualization:** Donut chart or treemap. Segments = rule groups, area = debt minutes. Per-project and per-namespace levels
- **Why:** Shows where to invest refactoring time. Currently debt is a single number without category breakdown
- **Effort:** Low (aggregate violations by group prefix, simple D3 pie/donut)
- **Value:** Medium — directly actionable for refactoring prioritization

### 3.4 Dependency Graph (Interactive)

- **Data:** `ca`, `ce`, `cbo`, `classRank` (class-level), dependency edges (from DependencyVisitor)
- **Visualization:** Force-directed graph (D3 force simulation). Nodes = classes/namespaces, size = LOC or classRank. Edges = directed dependencies. Color = health/instability. Cluster by namespace. Ego-graph mode: show dependencies of a selected class
- **Reference:** NDepend dependency graph, CodeScene hotspot coupling map
- **Challenge:** Performance on large projects (1000+ classes). Need filtering/threshold, lazy rendering, or WebGL
- **Effort:** High (layout, performance, UX for filtering/zoom)
- **Value:** High — makes coupling problems visually obvious, flagship visualization

### 3.5 Health Radar Chart

- **Data:** 5 sub-health scores (complexity, cohesion, coupling, typing, maintainability)
- **Visualization:** Spider/radar chart per class or namespace. Overlay two namespaces for comparison
- **Why:** Health bars are a linear list. Radar shows quality "shape" at a glance and enables comparison between modules
- **Effort:** Low (D3 radar chart, data already available)
- **Value:** Medium — useful for comparative analysis

### 3.6 Type Coverage Heatmap

- **Data:** `typeCoverage.param`, `.return`, `.property` per class
- **Visualization:** Heatmap grid. Rows = classes (grouped by namespace), columns = param/return/property. Color = coverage %
- **Why:** Quick pattern spotting: "property typing is weak everywhere" or "one namespace has no return types"
- **Effort:** Low-Medium
- **Value:** Low-Medium — niche but visually striking

---

## Phase 4: Architecture & Ecosystem

### 4.1 Architecture Rules (deptrac-killer)

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

### 4.2 Trend Analysis & Quality Gates

- **Feature:** Store metric snapshots between runs, detect regressions
- **Approach:** SQLite database (`~/.aimd/history.db` or project-local) storing per-run aggregates
- **Commands:**
  - `aimd trend` — show metric trends over time
  - `aimd check --quality-gate=no-regression` — fail if any metric worsened vs previous run
- **Output:** Sparkline-style trend indicators in text report, JSON timeseries
- **Reference:** SonarQube quality gates (the killer feature), CodeScene trend analysis
- **Effort:** High
- **Value:** Very High — no PHP CLI tool does this. Unique selling point

### 4.3 Custom Rules API

- **Feature:** User-defined rules without forking AIMD
- **Options:**
  - **YAML pattern rules** (like Semgrep) — low-code, pattern-based, limited power
  - **PHP plugin interface** — `implements RuleInterface`, autoloaded from a configured path
  - **Both** — YAML for simple patterns, PHP for complex logic
- **Reference:** ESLint plugins, PHPStan extensions, Semgrep custom rules
- **Effort:** Medium (PHP plugins) to High (YAML DSL)
- **Value:** High — critical for enterprise adoption

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

After Phase 3–4, AIMD replaces: **phpmd + phpmetrics + phpcpd + deptrac** and offers capabilities no PHP tool has (trend analysis, quality gates, interactive visualizations).

**Target value proposition:** "One tool. 40x faster. Deeper metrics. Quality gates. Replaces five tools."

---

## Technical Debt

Items that improve developer experience and code health but are not user-facing:

| Item                        | Priority | Effort | Description                                         |
| --------------------------- | -------- | ------ | --------------------------------------------------- |
| Global function aggregation | Low      | Small  | Aggregate function-level metrics to namespace level |
