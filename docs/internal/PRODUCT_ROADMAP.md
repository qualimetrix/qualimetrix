# Qualimetrix Product Roadmap

**Updated:** 2026-09-13
**Based on:** [Competitive analysis](COMPETITOR_COMPARISON.md), cross-ecosystem research (SonarQube, ESLint, Semgrep,
NDepend, CodeScene, RuboCop, Ruff, ArchUnit), triple expert evaluation (Gemini + Codex, 2026-03-25)

**Scope:** this document tracks *what is not built yet*. Shipped functionality is deliberately not duplicated here —
see [CHANGELOG.md](../../CHANGELOG.md) for the release timeline, [README.md](../../README.md) and
[the website](https://qualimetrix.dev/) for the user-facing feature list, and the "Key Features" section of
[CLAUDE.md](../../CLAUDE.md) for the agent-facing inventory. When an item below ships, delete it from here.

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

#### 1. Trend Analysis & Quality Gates

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

#### 2. Complexity Distribution (Box Plots)

- **Why it matters:** Summary statistics (avg, p95) hide distribution shape. Two classes with avg CCN=10 look identical,
  but one might have 200 trivial methods + 3 monsters while the other is uniformly moderate. Box plots per
  class/namespace reveal the outliers that summary stats mask — and outliers are exactly what developers need to find
- **What changes:** New visualization in HTML report. Box plot or histogram per class/namespace showing per-method
  CCN/cognitive/NPath distribution. Click outlier → navigate to method detail
- **Effort:** Medium (need method-level data in HTML tree, D3 box plot component)
- **Value:** High for experienced teams, medium for general audience
- **Marketing angle:** Visually impressive, appeals to data-oriented developers

#### 3. Custom Rules API

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

#### 4. Unused Variables Detection

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

#### 5. Tech Debt Breakdown

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

#### 6. Feature Envy Detection

- **Rule:** `code-smell.feature-envy`
- **Logic:** Method uses more symbols from another class than from its own. Classic Fowler smell
- **Concern:** High false-positive risk in PHP — ORM hydrators, service classes with injected dependencies, repository
  patterns all trigger this legitimately. Without careful tuning, this erodes trust
- **Note:** May need additional method-level coupling metric (external accesses per method). Evaluate feasibility before
  committing
- **Effort:** Medium-High (analysis + FP tuning)
- **Value:** Medium — recognized smell, but risky in PHP

#### 7. CRAP Index

- **Metric:** `crap` = CCN² × (1 − coverage)². Without coverage data: CRAP = CCN²
- **Input:** Optional Clover XML coverage file (`--coverage=clover.xml`)
- **Rule:** `complexity.crap`
- **Concern:** Without coverage data, CRAP = CCN² — which adds no information beyond CCN itself. Value is conditional on
  the user having coverage reports in their pipeline. This creates an external dependency that most Qualimetrix users may not
  have
- **Reference:** Alberto Savoia, crap4j; phpunit `--log-crap4j`
- **Effort:** Medium
- **Value:** Low without coverage, Medium-High with coverage — conditional feature

#### 8. Interactive Dependency Graph

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

#### 9. Health Radar Chart

- **Data:** 5 sub-health scores (complexity, cohesion, coupling, typing, maintainability)
- **Visualization:** Spider/radar chart per class or namespace. Overlay two namespaces for comparison
- **Concern:** Radar charts are familiar but analytically weak — they distort comparisons (area depends on axis order),
  and the same information is already shown as health bars. Provides visual variety without analytical depth
- **Effort:** Low
- **Value:** Low — visual garnish

#### 10. Cyclomatic Density

- **Metric:** `cyclomaticDensity` = CCN / LLOC
- **Rule:** `complexity.cyclomatic-density`
- **Logic:** Normalized complexity — high CCN in a short method is worse than the same CCN spread over many lines
- **Concern:** Useful as an internal signal for prioritization (and already achievable via computed metrics), but as a
  standalone rule it adds little value that users can't get from CCN + LOC separately
- **Reference:** Gill & Kemerer, NDepend
- **Effort:** Low
- **Value:** Low — better as internal signal than user-facing rule

#### 11. Type Coverage Heatmap

- **Data:** `typeCoverage.param`, `.return`, `.property` per class
- **Visualization:** Heatmap grid. Rows = classes (grouped by namespace), columns = param/return/property. Color =
  coverage %
- **Concern:** Niche audience. Type coverage metrics are useful, but a dedicated heatmap visualization appeals mainly to
  teams actively working on type migration — a narrow use case
- **Effort:** Low-Medium
- **Value:** Low-Medium — niche

---

## Deferred Obligations and Unscheduled Candidates

These entries preserve user value and unresolved decisions that would otherwise
be easy to lose. They are backlog records, not approved implementation plans.
Replanning starts only when the stated condition is met and the current code is
rechecked. A count from a frozen inventory locates deferred scope; it is not a
count of confirmed product defects.

### PHPDoc-Derived Dependency Edges

**Value:** Include declared type relationships in dependency evidence.
**Owner:** `Analysis.Evidence.DependencyModel`; Configuration owns consumer
policy. **Current state / decision:** Native syntax is extracted; no PHPDoc
parser or edges exist. A reviewed design records choices, but there is no ADR
or scheduled implementation, so this remains a candidate. **Replan when:**
Dependency-graph completeness is prioritized; reconfirm supported forms,
false-positive boundaries, and default behavior. **ADR:** None.

### New-Findings-Only Regression Gate

**Value:** Let CI distinguish findings introduced by a change from findings
merely located in touched files, without requiring trend history. **Owner:**
`Analysis.Policy.Baseline`, `Infrastructure.Git`, and Console. **Current state /
decision:** Git reporting narrows file scope but does not classify new findings.
Variant C is accepted as a design direction, not scheduled or specified by an
ADR; it remains distinct from history-backed trends. **Replan when:** A CI use
case needs a no-history new-findings gate; decide comparison source and
incomparable cases first. **ADR:** None.

### Baseline Memory-Bounded Revalidation

**Value:** Keep baseline comparison viable as accepted state grows without
changing verdicts. **Owner:** `Analysis.Policy.Baseline`. **Current state /
decision:** `Baseline::staleEntries()` materializes a complete stale-entry
array. Revalidate only; no streaming or no-materialization implementation is
approved, and the old goal alone does not prove a current performance defect.
**Replan when:** Current workloads make baseline size or peak memory a
constraint; confirm callers and result lifetime before choosing a contract.
**ADR:** ADRs 0017 and 0026 define identity context, not materialization.

### Configuration Content-Form Coverage

**Value:** Make acceptance and refusal reproducible for forms users can
actually supply, without confusing parser normalization with product behavior.
**Owner:** `Analysis.Configuration` and consuming capability owners.
**Current state / decision:** Axis F remains explicitly deferred; some forms
are refused or collapse before reaching the product, and controls do not fully
guard the axis. No content-form contract is accepted. **Replan when:** Owners
decide which parsed forms must differ observably; rebuild coverage through YAML
and CLI and prove its controls fail closed. **ADR:** ADR 0058 covers related
precedence, not this axis.

### Promise-versus-Effect Deferred Cases

**Value:** Ensure layered configuration honors each source's promise rather
than silently dropping an option. **Owner:** `Analysis.Configuration` and
owning rule-option classes. **Current state / decision:** Accepted key-shape and
selected layer-precedence behavior are implemented. The frozen ledger retains
115 deferred cases (including cross-source axis-B pairs and scope-wide cases),
not 115 confirmed defects; source composition (axis C), inline input, and
cross-layer conflicts remain outside the accepted contract. ADR 0055 accepts
only bounded behavior. **Replan when:** A supported use case depends on
a deferred interaction and owners first decide precedence/refusal semantics;
refresh evidence from current code. **ADR:** ADR 0055; ADRs 0058 and 0061 are related.

### Conditional Duplicate-FQN Semantics

**Value:** Make metrics predictable when valid mutually exclusive declarations
share one fully qualified name. **Owner:** `Analysis.Evidence.Measurement` owns
the stored projections; DependencyModel and metric owners consume them.
**Current state / decision:** Exact declarations and their findings remain
independently addressable. Class aggregation and graph metrics deliberately
deduplicate the logical name and project one logical score back to each
declaration. Whether conditional variants represent one semantic symbol or
independent alternatives is not decided; disagreement with a tool that retains
duplicates is not by itself a defect. **Replan when:** A representative PHP use
case makes the distinction material; freeze fixtures and decide the product
contract before changing aggregation or graph semantics. **ADR:** ADR 0021
leaves this semantic question open; ADR 0026 governs declaration keys only.

### Rule and Metric Residuals

**Value:** Keep findings actionable and metric descriptions accurate. **Owner:**
`Analysis.Evidence.CodeSmell`, `Analysis.Evidence.Size`, project-configuration
owners, and website documentation. **Current state / decision:** Both
parameter-count rules still inspect constructors and their intended overlap is
unsettled. The public size-rule page omits the accessor exclusion documented by
the owning capability. Broad CBO and ClassRank suppressions for Finding
contracts remain in project configuration without a current justification
established here and need explicit owner confirmation. These need owner
decisions, not blanket behavior changes; only the wording correction is clearly
required. Older audit records mix closed and open findings, so other claims are
not carried forward without revalidation.
**Replan when:** Preparing the documentation correction, reviewing rule overlap,
or next calibrating project configuration. **ADR:** ADRs 0035 and 0060 govern
names; ADR 0047 governs suppression versus exclusion, not these decisions.

---

## Identified Gaps (not yet in backlog, need design)

Items surfaced during expert evaluation that don't fit existing phases but deserve tracking:

| Gap                      | Description                                                                                                                                                      | Potential Value | Notes                                                                                       |
| ------------------------ | ---------------------------------------------------------------------------------------------------------------------------------------------------------------- | --------------- | ------------------------------------------------------------------------------------------- |
| **Explainability depth** | Per-violation "what to do" recommendations beyond current `humanMessage` — e.g., "extract method X to reduce CCN", "introduce interface to break coupling cycle" | Medium          | Partially exists; evaluate coverage and quality of current recommendations before investing |

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

Goal: Qualimetrix replaces **phpmd + phpmetrics + phpcpd + deptrac** and offers capabilities no PHP tool has.

**Where we stand:** the deptrac leg of that claim is settled — architecture layer rules shipped in v0.18 and the project
removed `deptrac/deptrac` from its own dev-dependencies on 2026-05-17 ([ADR 0014](../adr/0014-deptrac-retirement.md);
current design in [ADR 0059](../adr/0059-declared-layer-policy-and-architecture-governance.md), with declaration order detailed in
[ADR 0006](../adr/0006-architecture-rules-declaration-order.md)). The remaining differentiator on this list is quality gates
(Tier 1 #1); everything else is depth, not positioning.

**Target value proposition:** "One tool. 40x faster. Deeper metrics. Quality gates. Replaces five tools."
