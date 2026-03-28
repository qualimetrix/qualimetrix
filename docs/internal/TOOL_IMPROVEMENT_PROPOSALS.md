# Tool Improvement Proposals

Collected from self-analysis of `src/` with `bin/qmx check --preset=strict` (2026-03-28).
Each proposal follows the format: "If the tool could X, I would be able to Y."

---

## 1. VO Constructor Exemption for `long-parameter-list` and `constructor-overinjection`

**If the tool could distinguish VO constructors from service constructors**, agents would avoid
~30+ false-positive violations across Core, Reporting, and Analysis domains.

**Rationale:** Readonly classes with promoted properties are structurally different from service
constructors. A VO exemption (e.g., when all constructor parameters are `readonly` promoted
properties with no body logic) or a higher threshold for `readonly class` would dramatically
reduce noise in domain-model-heavy packages.

**Domains affected:** Core (15 violations), Reporting (11), Analysis (8), Infrastructure (6)

---

## 2. Interface-Mandated LCOM Suppression

**If the tool could detect "interface-mandated LCOM"** (methods required by an implemented
interface but accessing no shared state), agents would filter out ~70 false-positive LCOM
violations across Rules, Metrics, Reporting, and Configuration.

**Rationale:** Methods like `getName()`, `getDescription()`, `priority()` that return constants
and are declared in an interface always disconnect from the main logic in LCOM4 graph analysis.
A heuristic: methods that access no instance state and are declared in an interface should
contribute to at most 1 LCOM component.

**Concrete examples:**
- 14 Rule classes with LCOM 4 (RuleInterface mandates `getName`, `getDescription`, etc.)
- 35 Collector classes (MetricCollectorInterface mandates `getName`, `provides`, etc.)
- 19 Formatter classes (FormatterInterface mandates `getName`, `getDefaultGroupBy`)
- 5 ConfigurationStage classes (ConfigurationStageInterface mandates `priority`, `name`)

**Alternative:** A rule-level config like `design.lcom.exclude-implementing: [MetricCollectorInterface, ...]`

---

## 3. Framework-Type vs Application-Type CBO Distinction

**If the tool could distinguish framework-type CBO from application-type CBO**, agents would
separate structural coupling noise from genuine architectural coupling.

**Rationale:** Importing 50 `PhpParser\Node\*` classes counts the same as depending on 50
application services. A `--framework-packages` option (or heuristic for vendor types) that
separately reports "framework CBO" vs "application CBO" would make the coupling score
actionable for AST-heavy codebases.

**Domains affected:** Metrics (HalsteadVisitor CBO=128, IdenticalSubExpressionVisitor CBO=49)

---

## 4. Plain-Text Health Score Output

**If `--format=health` had a plain-text mode** (auto-detecting TTY or via `--format=health-text`),
agents would be able to quickly inspect health scores in the terminal.

**Rationale:** Currently `--format=health` outputs a full HTML document. CLI analysis workflows
require falling back to `--format=json` and parsing JSON to extract health dimension scores.
Every single domain agent (7/7) reported this as a friction point.

---

## 5. Per-File Violation Grouping in JSON Output

**If the JSON output included a per-file violation grouping** (in addition to the flat list),
agents would identify the "top N problematic files" without post-processing.

**Rationale:** The current JSON has a flat `violations[]` array. Building a "worst files" view
requires grouping violations by file path. A `violationsByFile` summary with counts and a
`worstFiles` ranking (analogous to existing `worstClasses`) would accelerate triage.

**Domains affected:** All 7 domains reported this need.

---

## 6. Violation Clustering by Root Cause

**If the tool could group violations by root cause** (e.g., "59 duplication violations caused
by shared Options boilerplate"), agents would prioritize structural refactorings by impact
rather than raw violation count.

**Rationale:** Currently each violation is independent. Patterns like "high CBO causes high
instability causes distance violation" are reported as three separate violations, but they
share one root cause. A clustering algorithm that groups causally related violations would
dramatically improve triage efficiency.

**Examples:**
- Rules: 59 duplication violations from Options boilerplate -> 1 refactoring
- Reporting: 49 long-parameter-list violations from rendering context -> 1 RenderingContext VO
- Infrastructure: RuntimeConfigurator's 13 violations -> 1 responsibility split

---

## 7. Refactoring Impact Score

**If the tool could compute a "refactoring impact score"** (violation reduction expected from
a specific change), agents would quantify the value of each refactoring before making it.

**Rationale:** Extracting `AggregationHelper::collectNamespaceMetricValues` into 3 methods
would likely eliminate ~5 violations (cognitive, NPath, CCN, MI, WMC). Knowing this before
making the change helps prioritize. Could be approximated by analyzing which violations
co-locate in the same method/class.

---

## 8. Duplication Content Hints

**If the duplication detection reported the actual repeated code pattern** (or at least a
one-line summary), agents would assess whether it is genuine duplication or coincidental
structural similarity.

**Rationale:** Current message says "33 lines, 2 occurrences" with line ranges but no content
hint. A short fingerprint like "namespace/project formula pair" or "threshold parsing
boilerplate" would provide immediate context.

**Extension:** Distinguish declarative data constants (array definitions) from algorithmic
code in duplication detection. MetricHintProvider's 22 false-positive duplication violations
from const arrays obscure 2 genuine duplications.

---

## 9. Architectural Role Annotations

**If the tool could annotate violations with an "architectural expectation" flag** (marking
DI configurators, pipeline orchestrators, and facade classes as expected-high-coupling),
agents would immediately separate genuine design issues from structural false positives.

**Rationale:** The Infrastructure layer is *architecturally expected* to have high outward
coupling. DI Configurators (CBO=35-50) and the Analysis pipeline (instability ~1.0) are
structural, not quality defects. Similarly, Core foundational types (SymbolPath ClassRank
0.084) are shared primitives that *should* be widely depended upon.

---

## 10. Full JSON Violation Output (No Truncation)

**If the JSON output included the full violation list** without truncation (or had a
`--limit=0` option), agents would perform complete automated analysis without relying
on the text format.

**Rationale:** The default truncation to 50 violations in JSON meant agents needed to
cross-reference with text output for the full picture. A `--violations-limit=0` flag
or `--all` option would solve this.

---

## 11. Worst Contributors per Health Dimension

**If the health report showed which specific classes dragged down a namespace score**,
agents would prioritize without manually reading files.

**Rationale:** The health output says `Qualimetrix\Core\ComputedMetric` has cohesion 46.7,
but doesn't say that `ComputedMetricDefinition` (30.0) is the primary contributor. Adding
a "worst contributors" list per namespace per dimension would save an investigation round.

---

## 12. NPath Severity Categories

**If the tool reported NPath as a category** (e.g., `500-1000`, `1000-10000`, `>10000`,
`>1M`) rather than just "exceeds threshold", agents would prioritize more effectively.

**Rationale:** NPath 36120 and NPath >1M are qualitatively different problems requiring
different refactoring strategies. The violation message sometimes shows "> 1M" but this
categorization is inconsistent.

---

## 13. Violation Density Metric

**If the tool could show "violation density"** (violations per LOC or per method) alongside
absolute counts, agents would more accurately compare classes of different sizes.

**Rationale:** A 200-line class with 13 violations is structurally worse than a 400-line class
with 13 violations, but the current report treats them equally.

---

## 14. Instability Scope Awareness

**If the tool could report instability metrics scoped to the full project dependency graph**
even when analyzing a subset of files, agents would avoid false-positive instability errors.

**Rationale:** Analyzing only `src/Configuration/` means afferent couplings from `Analysis/`
and `Infrastructure/` are invisible, making instability = 1.00 for all namespaces. Consider
always computing the coupling graph project-wide regardless of file scope, or annotating
instability violations when the analyzed scope is a subset.
