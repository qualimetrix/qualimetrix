# Qualimetrix Product Roadmap

**Updated:** 2026-04-02
**Based on:** [Competitive analysis](COMPETITOR_COMPARISON.md), cross-ecosystem research (SonarQube, ESLint, Semgrep,
NDepend, CodeScene, RuboCop, Ruff, ArchUnit), triple expert evaluation (Gemini + Codex, 2026-03-25)

---

## Strategic Positioning

**Current niche:** Deep OOP metrics + actionable thresholds + fast CI integration.

**Goal:** Become the single quality gate tool that replaces phpmd + phpmetrics + phpcpd + deptrac, while recommending
PHPStan/Psalm as complementary tools for type safety.

**Core advantage:** 9–39x faster than competitors (sequential/parallel). Unique metrics (Cognitive Complexity, TCC/LCC,
RFC, ClassRank). Modern PHP 8.4 support while competitors degrade (pdepend crashes, phpmd deprecation warnings). Only
PHP tool with parallel processing.

```
What Qualimetrix should own:              What to leave to others:
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

## Priority Tiers

Items ordered by combined usefulness × marketing impact × effort efficiency.

### Tier 1 — Strategic (high value, higher effort)

#### 1. Architecture Rules (deptrac replacement)

- **Why it matters:** This is the single most important feature for the "replaces five tools" narrative. Deptrac is the
  only tool in the replacement set that Qualimetrix doesn't yet cover. With architecture rules, Qualimetrix owns the full stack:
  metrics + smells + duplication + architecture constraints. The dependency graph already exists — Qualimetrix just needs a DSL
  to express constraints on it
- **What changes:** New `architecture:` section in `qmx.yml` with layer definitions (glob patterns → layer names) and
  rules (`Controller must not depend on Repository`). New rule `architecture.layer-violation`. Layer DSL parser +
  constraint evaluator
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
- **Implementation:** Qualimetrix already has the full dependency graph from `DependencyVisitor`. Need: layer DSL parser,
  constraint evaluator, violation reporting with "found dependency X→Y via class Z"
- **Reference:** deptrac (PHP), ArchUnit (Java), NetArchTest (.NET), Dependency Cruiser (JS)
- **Effort:** Medium-High (DSL design is the hardest part; execution on existing graph is straightforward)
- **Value:** Very High — completes the "one tool replaces five" promise
- **Marketing angle:** Headline feature. "Drop deptrac from your CI — Qualimetrix does it natively, 40x faster"

#### 2. Trend Analysis & Quality Gates

- **Why it matters:** This is SonarQube's killer feature — and no PHP CLI tool has it. Today Qualimetrix answers "how healthy
  is your code now?" but can't answer "is it getting better or worse?" Quality gates that fail CI when metrics regress
  are the single most effective way to prevent tech debt accumulation. This moves Qualimetrix from "analysis tool" to "quality
  platform"
- **What changes:** SQLite database storing per-run metric snapshots (project-local or `~/.qmx/history.db`). New
  `qmx trend` command showing metric trends over time. `qmx check --quality-gate=no-regression` failing CI if any key
  metric worsened. Sparkline-style trend indicators in text report
- **Challenges:** Schema design for efficient queries, determining what "worsened" means (absolute vs relative),
  handling baseline resets, storage lifecycle
- **Reference:** SonarQube quality gates, CodeScene trend analysis
- **Effort:** High (not just SQLite — it's history model + regression semantics + UX + CI integration)
- **Value:** Very High — unique selling point, no PHP CLI tool does this
- **Marketing angle:** Enters SonarQube/CodeScene territory. "Quality gates without SonarQube"

---

### Tier 2 — Depth & Breadth (valuable, can wait)

#### 3. Complexity Distribution (Box Plots)

- **Why it matters:** Summary statistics (avg, p95) hide distribution shape. Two classes with avg CCN=10 look identical,
  but one might have 200 trivial methods + 3 monsters while the other is uniformly moderate. Box plots per
  class/namespace reveal the outliers that summary stats mask — and outliers are exactly what developers need to find
- **What changes:** New visualization in HTML report. Box plot or histogram per class/namespace showing per-method
  CCN/cognitive/NPath distribution. Click outlier → navigate to method detail
- **Effort:** Medium (need method-level data in HTML tree, D3 box plot component)
- **Value:** High for experienced teams, medium for general audience
- **Marketing angle:** Visually impressive, appeals to data-oriented developers

#### 4. Custom Rules API

- **Why it matters:** Enterprise teams have domain-specific quality rules ("no direct DB queries outside Repository", "
  all DTOs must be readonly"). Without a plugin API, they either fork Qualimetrix or use a separate tool. A PHP plugin
  interface (`implements RuleInterface`, autoloaded from configured path) makes Qualimetrix extensible without forking —
  critical for enterprise adoption and community growth
- **What changes:** PHP plugin interface (autoloaded from configured path), optionally YAML pattern rules for simple
  cases. Plugin discovery, API stability guarantees, documentation
- **Options:**
    - **PHP plugin interface** — `implements RuleInterface`, full power, familiar to PHPStan extension authors
    - **YAML pattern rules** (like Semgrep) — low-code, pattern-based, limited but accessible
    - **Both** — YAML for quick patterns, PHP for complex logic
- **Reference:** ESLint plugins, PHPStan extensions, Semgrep custom rules
- **Effort:** Medium (PHP plugins) to High (YAML DSL)
- **Value:** High — critical for enterprise adoption, attracts community contributions
- **Marketing angle:** "Platform maturity" signal. Less wow, more trust

#### 5. Unused Variables Detection

- **Why it matters:** Universally expected code quality check. Every linter in every language has it. Its absence is
  noticed. However, doing it well in PHP is hard due to `extract()`, variable variables (`$$x`), `compact()`, `list()`
  destructuring, closures with `use`, and dynamic features. False positives erode trust faster than missing features
- **Rule:** `code-smell.unused-variable`
- **Scope:** Variables assigned but never read within a function/method scope
- **Challenges:** Compact assignments (`list()`, `[...]`), `extract()`, variable variables (`$$x`), closures with `use`,
  `@` suppression. PHPStan already covers this via extension at high levels — overlap risk
- **Approach:** Scope-aware single-pass AST visitor tracking writes/reads per scope
- **Reference:** ESLint no-unused-vars, Pylint W0612, PHPStan (via extension)
- **Effort:** Very High (the analysis itself is medium, but achieving acceptable false-positive rate in PHP is the real
  cost)
- **Value:** High for adoption (expected feature), but overlap with PHPStan reduces unique value
- **Marketing angle:** Checkbox feature — expected, not differentiating

#### 6. Tech Debt Breakdown

- **Why it matters:** Qualimetrix reports total tech debt as a single number ("4.2 hours"). But a tech lead planning a sprint
  needs to know: "2.5 hours is complexity, 1 hour is coupling, 0.7 hours is code smells". Category breakdown makes debt
  actionable for sprint planning — you can assign "fix complexity debt" to one developer and "fix coupling debt" to
  another
- **What changes:** Donut chart or treemap in HTML report. Segments = rule groups (complexity, coupling, code-smell,
  etc.), area = debt minutes. Per-project and per-namespace drill-down
- **Data ready:** `debtMinutes` per violation, `violationCode` grouped by rule group prefix
- **Effort:** Low
- **Value:** Medium — directly actionable for refactoring prioritization
- **Marketing angle:** Good dashboard visual, but not a reason to adopt

---

### Tier 3 — Nice to Have (low priority or high risk)

#### 7. Feature Envy Detection

- **Rule:** `code-smell.feature-envy`
- **Logic:** Method uses more symbols from another class than from its own. Classic Fowler smell
- **Concern:** High false-positive risk in PHP — ORM hydrators, service classes with injected dependencies, repository
  patterns all trigger this legitimately. Without careful tuning, this erodes trust
- **Note:** May need additional method-level coupling metric (external accesses per method). Evaluate feasibility before
  committing
- **Effort:** Medium-High (analysis + FP tuning)
- **Value:** Medium — recognized smell, but risky in PHP

#### 8. CRAP Index

- **Metric:** `crap` = CCN² × (1 − coverage)². Without coverage data: CRAP = CCN²
- **Input:** Optional Clover XML coverage file (`--coverage=clover.xml`)
- **Rule:** `complexity.crap`
- **Concern:** Without coverage data, CRAP = CCN² — which adds no information beyond CCN itself. Value is conditional on
  the user having coverage reports in their pipeline. This creates an external dependency that most Qualimetrix users may not
  have
- **Reference:** Alberto Savoia, crap4j; phpunit `--log-crap4j`
- **Effort:** Medium
- **Value:** Low without coverage, Medium-High with coverage — conditional feature

#### 9. Interactive Dependency Graph

- **Visualization:** Force-directed graph (D3 force simulation). Nodes = classes/namespaces, edges = dependencies.
  Color = health, size = ClassRank
- **Concern:** Maximum wow on demo, but high risk of becoming useless on real projects. At 500+ nodes, force-directed
  graphs become unreadable without sophisticated filtering, clustering, and level-of-detail rendering. The UX work to
  make this genuinely useful (not just pretty) is where the real effort lies. Qualimetrix already exports DOT graphs —
  interactive browser version adds visual wow but limited analytical depth
- **Reference:** NDepend dependency graph, CodeScene hotspot coupling map
- **Effort:** Very High (layout + performance + filtering UX)
- **Value:** High for demos, Medium for daily use
- **Marketing angle:** Best possible screenshot, but risk of overpromise

#### 10. Health Radar Chart

- **Data:** 5 sub-health scores (complexity, cohesion, coupling, typing, maintainability)
- **Visualization:** Spider/radar chart per class or namespace. Overlay two namespaces for comparison
- **Concern:** Radar charts are familiar but analytically weak — they distort comparisons (area depends on axis order),
  and the same information is already shown as health bars. Provides visual variety without analytical depth
- **Effort:** Low
- **Value:** Low — visual garnish

#### 11. Cyclomatic Density

- **Metric:** `cyclomaticDensity` = CCN / LLOC
- **Rule:** `complexity.cyclomatic-density`
- **Logic:** Normalized complexity — high CCN in a short method is worse than the same CCN spread over many lines
- **Concern:** Useful as an internal signal for prioritization (and already achievable via computed metrics), but as a
  standalone rule it adds little value that users can't get from CCN + LOC separately
- **Reference:** Gill & Kemerer, NDepend
- **Effort:** Low
- **Value:** Low — better as internal signal than user-facing rule

#### 12. Type Coverage Heatmap

- **Data:** `typeCoverage.param`, `.return`, `.property` per class
- **Visualization:** Heatmap grid. Rows = classes (grouped by namespace), columns = param/return/property. Color =
  coverage %
- **Concern:** Niche audience. Type coverage metrics are useful, but a dedicated heatmap visualization appeals mainly to
  teams actively working on type migration — a narrow use case
- **Effort:** Low-Medium
- **Value:** Low-Medium — niche

---

## Identified Gaps (not yet in backlog, need design)

Items surfaced during expert evaluation that don't fit existing phases but deserve tracking:

| Gap                       | Description                                                                                                                                                      | Potential Value | Notes                                                                                                                                                                  |
| ------------------------- | ---------------------------------------------------------------------------------------------------------------------------------------------------------------- | --------------- | ---------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| **Diff-based regression** | "Did this PR make things worse?" without SQLite history — compare violations in changed files against baseline or previous commit                                | High            | Lighter alternative to full Trend Analysis (2). Partially covered by `--analyze=git:staged` + `--report=git:main..HEAD`, but lacks explicit "new violations only" mode |
| **Explainability depth**  | Per-violation "what to do" recommendations beyond current `humanMessage` — e.g., "extract method X to reduce CCN", "introduce interface to break coupling cycle" | Medium          | Partially exists; evaluate coverage and quality of current recommendations before investing                                                                            |

---

## Not Recommended

| Item                            | Reason                                                                                                                                                                                                                                     |
| ------------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------ |
| **Full taint analysis**         | Requires inter-procedural data-flow engine. Psalm and SonarQube have years of investment here. Not practical to compete.                                                                                                                   |
| **Type checking / null safety** | PHPStan and Psalm own this completely. Would require building a type inference engine.                                                                                                                                                     |
| **Auto-fixing**                 | Rector's domain. Qualimetrix metrics (high CCN, low TCC) can't be auto-fixed — simplifying a complex method requires human judgment. Fundamentally different concern from analysis.                                                        |
| **Naming convention rules**     | PHPCS/PHP-CS-Fixer handle this well. Low differentiation value.                                                                                                                                                                            |
| **Framework-specific rules**    | Adds maintenance burden. Qualimetrix is framework-agnostic by design. Configuration presets (strict/relaxed) are acceptable, but not framework-coupled rules.                                                                              |
| **IDE plugins**                 | PhpStorm/VSCode plugins are separate products with their own lifecycle, API, review processes. Qualimetrix already integrates via SARIF, GitHub Actions, GitLab Code Quality — the right integration surface for a CLI tool at this stage. |

---

## Success Metrics

After Tiers 1–2, Qualimetrix replaces: **phpmd + phpmetrics + phpcpd + deptrac** and offers capabilities no PHP tool has (
architecture rules, quality gates).

**Already delivered:** Effort-aware prioritization, cognitive complexity breakdown, analysis presets, Martin diagram.

**Target value proposition:** "One tool. 40x faster. Deeper metrics. Quality gates. Replaces five tools."

---

## Technical Debt

Items that improve developer experience and code health but are not user-facing:

| Item                        | Priority | Effort | Description                                         |
| --------------------------- | -------- | ------ | --------------------------------------------------- |
| Global function aggregation | Low      | Small  | Aggregate function-level metrics to namespace level |
