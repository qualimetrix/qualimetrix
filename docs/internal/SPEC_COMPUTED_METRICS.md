# Spec: Computed Metrics (Health Scores)

**Status:** Draft v4 (reviewed by Claude + Gemini + Codex, refined with author)
**Created:** 2026-03-10
**Updated:** 2026-03-13
**Phase:** 3.x (extends Phase 3 roadmap)
**Depends on:** Existing metric pipeline, aggregation system

---

## Problem

AIMD collects 30+ raw metrics and aggregates them to namespace/project level. But there is no way to answer the
question "how healthy is this namespace overall?" without manually interpreting multiple numbers.

Additionally, when baseline suppresses all violations (common in established CI pipelines), the violation-based view
becomes empty — yet raw metrics still reveal the true state of the code.

No PHP competitor offers user-definable composite health metrics. SonarQube has hardcoded ratings (A-E), NDepend has
CQLinq but it's a separate query language. **Configurable formula-based computed metrics are a unique differentiator.**

---

## Feature Overview

**Computed Metrics** are user-definable derived metrics that:

1. Take existing aggregated metrics as input (e.g., `ccn__avg`, `tcc__avg`, `mi__avg`)
2. Apply a formula (Symfony Expression Language) to produce a scalar value
3. Are computed per class, namespace, and project — with **separate formulas per level**
4. Support warning/error thresholds — generating violations like any other rule
5. Ship with sensible defaults that work out of the box

### Inter-Metric References

All computed metrics (`health.*` and `computed.*`) live in a **single pool**. Any computed metric can reference any
other computed metric. Circular dependencies are detected via topological sort and reported as a critical error at
config load time.

Evaluation order is determined by the topological sort. For the default set, the dependency graph is simple:
`health.overall` depends on the other 5 `health.*` metrics; all others depend only on raw metrics.

---

## Architecture

### Pipeline Position

```
Collection → Derived → Aggregation → Global Context (+re-agg) → ★ Computed Metrics ★ → Analysis → Reporting
```

Computed metrics run **after aggregation and global context** (they need namespace-level data including distance, CBO,
abstractness) and **before analysis** (so rules can generate violations from them).

### Key Components

```
src/
├── Core/
│   └── ComputedMetric/
│       ├── ComputedMetricDefinition.php          # name, formulas, levels, thresholds
│       ├── ComputedMetricDefaults.php            # built-in health.* definitions
│       └── ComputedMetricDefinitionHolder.php    # shared runtime value holder
├── Metrics/
│   └── ComputedMetric/
│       └── ComputedMetricEvaluator.php          # resolves variables, evaluates formulas
├── Rules/
│   └── ComputedMetric/
│       ├── ComputedMetricRule.php                # single rule, iterates all definitions
│       └── ComputedMetricRuleOptions.php         # reads definitions from holder
├── Configuration/
│   └── Pipeline/Stage/
│       └── ComputedMetricsStage.php              # merge defaults + YAML, validate, store in holder
```

### Single ComputedMetricRule (Not N Instances)

**Architectural constraint:** the DI container compiles before `aimd.yaml` is loaded at runtime. User-defined
`computed.*` metrics are not known at container build time. Creating N rule instances via CompilerPass is impossible for
user-defined metrics.

**Solution:** One `ComputedMetricRule` with a static `NAME` constant, standard DI registration via autoconfiguration.
All definitions (built-in + user-defined) arrive through `ComputedMetricRuleOptions` at runtime, following the existing
`RuleOptionsFactory` pattern.

```php
class ComputedMetricRule implements RuleInterface
{
    public const NAME = 'computed.health';

    // Receives ComputedMetricRuleOptions with merged definitions at runtime
    // analyze() iterates definitions, reads pre-computed values, checks thresholds
    // Each violation gets its own violationCode = definition name (e.g., 'health.complexity')
}
```

**NAME convention:** `computed.health` follows the standard `group.rule-name` convention.
`--disable-rule=computed` prefix-matches `computed.health`, disabling the rule entirely.
```

**Why this works:**

- **Standard DI registration** — one class, one service, one NAME constant. `RuleRegistryCompilerPass`,
  `RuleOptionsCompilerPass`, and `RuleCompilerPass` work unmodified. No new CompilerPasses needed.
- **Runtime config** — `ComputedMetricRuleOptions` is created by `RuleOptionsFactory` at runtime, which merges
  `ComputedMetricDefaults` (built-in) with user overrides/additions from `aimd.yaml`. This follows the exact same
  pattern as every other rule in the system.
- **`--disable-rule` granularity** — works via `isViolationCodeEnabled()`, the same mechanism used by hierarchical
  rules:
    - `--disable-rule=computed.health` → standard match on rule NAME → disables the whole rule
    - `--disable-rule=health` → prefix match on violation code → disables all `health.*` violations
    - `--disable-rule=health.complexity` → exact match on violation code → disables one metric
    - `--disable-rule=computed` → prefix match → disables all `computed.*` violations
- **Suppression** — `@aimd-ignore health.complexity` matches the violation code. `@aimd-ignore health` matches all
  health violations via prefix.
- **Baseline** — violation code (`health.complexity`) + symbol path → standard baseline behavior.
- **Profiler** — `RuleExecutor` creates one span `rule.computed.health`. Inside `analyze()`, the rule creates sub-spans
  per definition (`rule.computed.health.health.complexity`, etc.) for diagnostics. (Note: rule-level spans use the rule
  NAME with hyphens; pipeline-level spans use `computed` with dots — these are separate span trees.)

**Minimal infrastructure changes.** No modifications to RuleRegistry, RuleExecutor, RuleOptionsCompilerPass, or any
existing CompilerPass. New additions: `ComputedMetricDefinitionHolder` (simple value holder service) and
`ComputedMetricsStage` (configuration pipeline stage).

### How ComputedMetricRuleOptions Works

`ComputedMetricRuleOptions` implements `RuleOptionsInterface` and holds:

- `definitions: array<string, ComputedMetricDefinition>` — merged list of all computed metrics
- `enabled: bool` — whether the rule is active
- `getSeverity()` — returns default severity (each definition has its own thresholds, applied internally)

**Runtime creation flow:**

1. `RuleOptionsFactory::create('computed.health', ComputedMetricRuleOptions::class)` is called
2. Factory calls `ComputedMetricRuleOptions::fromArray($config)` with the rule's config section
3. `fromArray()` reads pre-merged definitions from `ComputedMetricDefinitionHolder` (injected) — it does NOT perform the
   merge itself
4. Returns options wrapping the already-validated definitions

**Merge happens earlier**, in the configuration pipeline (see "Definitions flow" in ComputedMetricEvaluator section).
`ComputedMetricRuleOptions` is a thin wrapper — it reads from the holder, not from raw config. This ensures a single
source of truth.

**Config pipeline mapping:** A `ComputedMetricsStage` (or extension of `ConfigFileStage`) reads the `computed_metrics`
top-level YAML section, performs the merge+validation, and stores results in `ComputedMetricDefinitionHolder`. The
`computed_metrics` key is NOT mapped to `ruleOptions['computed.health']` — the rule options bypass
`RuleOptionsFactory`'s standard config lookup and read from the holder directly.

### Dependency Flow

```
ComputedMetricRule (Rules) → ComputedMetricDefinitionHolder (Core)
ComputedMetricRuleOptions (Rules) → ComputedMetricDefinitionHolder (Core)
ComputedMetricEvaluator (Metrics) → ComputedMetricDefinition (Core)
ComputedMetricsStage (Configuration) → ComputedMetricDefaults (Core), ComputedMetricDefinitionHolder (Core)
AnalysisPipeline (Analysis) → ComputedMetricEvaluator (Metrics), ComputedMetricDefinitionHolder (Core)
```

All dependencies flow downward. `Core` defines the data structures and built-in defaults. `Metrics` does the
calculation (pipeline phase). `Rules` generates violations (analysis phase — reads pre-computed values from repository).

**Deptrac note:** `Analysis → Metrics` dependency is already allowed (`Analysis.Pipeline` ruleset includes `Metrics`).
Verified.

### ComputedMetricDefinition (Core)

```
ComputedMetricDefinition:
  - name: string                    # e.g., "health.complexity" or "computed.risk_score"
  - formulas: array<SymbolType, string>  # per-level formulas (see Per-Level Formulas section)
  - description: string             # human-readable description
  - levels: SymbolType[]           # where to compute (Class_, Namespace_, Project)
  - inverted: bool                  # true = higher is better (like MI, TCC)
  - thresholds:
      warning: float|null           # optional, for violation generation
      error: float|null             # optional, for violation generation
```

### ComputedMetricEvaluator (Metrics)

Responsibilities:

1. For each symbol (class/namespace/project), resolve formula variables from `MetricRepositoryInterface`
2. Select the appropriate formula for the symbol's level
3. Evaluate the formula using `symfony/expression-language`
4. Store result back into the metric repository as a regular metric
5. Handle errors gracefully (see Error Handling section)

**How the calculator receives definitions at runtime:**
`ComputedMetricEvaluator` is a stateless service with signature
`compute(MetricRepositoryInterface $repo, array $definitions)`. It does not store or merge definitions — it receives
them as a method argument.

**Definitions flow (concrete mechanism):**

A `ComputedMetricDefinitionHolder` service acts as a runtime value holder:

```php
// Registered in DI as a shared singleton
class ComputedMetricDefinitionHolder
{
    private array $definitions = [];  // list<ComputedMetricDefinition>

    public function setDefinitions(array $definitions): void { ... }
    public function getDefinitions(): array { ... }
}
```

The flow:

1. `ConfigFileStage` loads `computed_metrics` section from `aimd.yaml`
2. `ConfigFileStage` (or a new `ComputedMetricsStage`) performs the merge:
    - Loads `ComputedMetricDefaults::getDefaults()` as base
    - Merges user overrides for `health.*` (per-field, formulas per-level)
    - Adds user-defined `computed.*` definitions
    - Filters `enabled: false`
    - Validates: syntax, circular deps, name collisions, prefix rules, per-level formula coverage, non-existent
      references
3. `RuntimeConfigurator` stores merged definitions in `ComputedMetricDefinitionHolder`
4. Both consumers read from the same holder:
    - `ComputedMetricRuleOptions::fromArray()` calls `$holder->getDefinitions()` — no re-merge, just wraps
    - `AnalysisPipeline::computeMetrics()` calls `$holder->getDefinitions()` and passes to Calculator

`ComputedMetricDefinitionHolder` is injected into `AnalysisPipeline` and into `ComputedMetricRuleOptions` via
constructor (standard DI). `RuntimeConfigurator` receives it via constructor and calls `setDefinitions()` after config
resolution.

`ComputedMetricDefinition` is a readonly value object — both consumers receive identical, immutable data.

**Single-pass evaluation with topological sort:**

1. Build dependency graph from all definitions by scanning formula variables
2. Topological sort (circular deps should already be caught at config time, but guard here too)
3. Evaluate in topological order, storing results in repository after each metric

**Symbol iteration per level:**

- **Project:** single symbol (`SymbolPath::forProject()`)
- **Namespace:** iterate `$repository->getNamespaces()`
- **Class:** iterate `$repository->all(SymbolType::Class_)`

**Variable naming:** metric names with `.` replaced by `__` (double underscore) for Expression Language compatibility.
Example: `ccn.avg` → `ccn__avg`, `tcc.avg` → `tcc__avg`, `health.complexity` → `health__complexity`.

**Class-native vs aggregated metrics:** metrics collected at class level (e.g., `tcc`, `lcom`, `cbo`, `dit`) are stored
without a suffix at class level. The `.avg` suffix only appears after aggregation to namespace/project level. This is
why per-level formulas are essential — class-level formulas use `tcc`, `cbo`, `dit` (no suffix), while namespace-level
formulas use `tcc__avg`, `cbo__avg`, `dit__avg` (aggregated). Method-collected metrics like `ccn` already have `.avg` at
class level (aggregated from methods → class).

**Why double underscore:** single `_` would create ambiguous reverse mapping if a future metric name contains `_` (e.g.,
`foo_bar.avg` → `foo_bar_avg` vs `foo.bar.avg` → `foo_bar_avg`). Double `__` eliminates this: `foo_bar.avg` →
`foo_bar__avg`, `foo.bar.avg` → `foo__bar__avg`. No existing metrics use `_`, but this future-proofs the mapping.

**Available math functions** exposed in Expression Language context:

- `min(a, b)`, `max(a, b)`, `abs(x)`, `sqrt(x)`, `log(x)` (natural), `log10(x)`
- `clamp(value, min, max)` — convenience function

Null coalescing (`??`) is supported natively by Expression Language (Symfony 7.1+): `(distance ?? 0)`.

**Important:** `ComputedMetricEvaluator` must NOT implement `MetricCollectorInterface`. It is a standalone service
called by `AnalysisPipeline`, not a collector.

**DI registration:** The file name `ComputedMetricEvaluator.php` does not match `*Calculator.php` or other exclude
patterns in `CollectorConfigurator`, so it will be auto-registered by `registerClasses()` under the
`AiMessDetector\Metrics\` namespace. However, since it does not implement any tagged interface
(`MetricCollectorInterface`, `DerivedCollectorInterface`, `GlobalContextCollectorInterface`), it will be registered as a
plain autowired service — which is exactly what `AnalysisPipeline` needs for constructor injection. No manual
registration required.

### ComputedMetricRule (Rules)

`ComputedMetricRule` is a standard rule with `NAME = 'computed.health'`. It receives all definitions through
`ComputedMetricRuleOptions` at runtime.

**`analyze()` logic:**

1. Iterate all definitions from options
2. For each definition, iterate symbols at each configured level
3. Read the pre-computed metric value from `MetricRepositoryInterface`
4. If value is absent (metric was not computed due to missing data) → skip, no violation
5. Compare value against thresholds (inverted logic for health scores)
6. Generate `Violation` with `violationCode = $definition->name`
7. Create profiler sub-spans per definition for diagnostics

Note: `ComputedMetricRule` does NOT depend on `ComputedMetricEvaluator`. The calculator runs in the pipeline phase (
before analysis), storing results in `MetricRepositoryInterface`. The rule simply reads the pre-computed values from the
repository — same as any other rule reading raw metrics.

**`--disable-rule` behavior** (via existing `isViolationCodeEnabled()`):

- `--disable-rule=computed.health` → standard match on NAME → rule skipped entirely
- `--disable-rule=health` → prefix match on violation code → health violations filtered
- `--disable-rule=computed` → prefix match → computed.* violations filtered
- `--disable-rule=health.complexity` → exact match on violation code → one metric filtered

**`--only-rule` limitation:** `--only-rule=health.complexity` does NOT work for individual computed metrics.
`RuleExecutor.isRuleEnabled()` checks the rule NAME (`computed.health`), which has no prefix relationship with
violation codes (`health.*`, `computed.*`). The rule is disabled entirely before `isViolationCodeEnabled()` gets a
chance to filter.

Supported: `--only-rule=computed.health` (enables the whole rule). For granular control, use `--disable-rule` to
exclude unwanted metrics instead. This is a known trade-off of the single-rule architecture — documented, not a bug.

### Integration with Analysis Pipeline

`AnalysisPipeline` gets a new step between global context and analysis:

```
// Simplified pseudocode (see AnalysisPipeline for full details)
$metricBags = $this->collect($files);          // parallel
$this->deriveMetrics($metricBags);             // sequential
$graph = $this->buildDependencyGraph();        // sequential
$this->aggregate($repository);                 // sequential
$this->runGlobalCollectors($graph, $repo);     // sequential (+re-aggregate)
$this->computeMetrics($repository);            // ★ NEW
$cycles = $this->detectCircularDeps($graph);   // sequential
$violations = $this->analyze($repository);     // sequential
```

The `computeMetrics` step invokes `ComputedMetricEvaluator` for each defined computed metric in topological order,
across all relevant symbol levels.

**Profiler:** Add span `computed` for the entire phase, and per-metric sub-spans (`computed.health.complexity`, etc.)
for diagnostics.

---

## Per-Level Formulas

Each computed metric defines **separate formulas for each level** it supports. This eliminates the need for `??`
fallbacks on level-inappropriate metrics and ensures each level's formula is semantically correct.

### Why Per-Level Formulas

The same metric name (e.g., `health.typing`) means different things at different levels:

- **Class level**: no `abstractness`, no `distance` → formula should not reference them (even with `?? 0`)
- **Namespace level**: `abstractness` and `distance` are available → formula can use them directly
- **Project level**: same as namespace (aggregated values)

With a single formula, class-level `health.typing` would cap at 80/100 because the abstractness component (20% weight)
always evaluates to 0. Per-level formulas let each level use its full 0–100 range with appropriate weights.

### Definition Structure

```
ComputedMetricDefinition:
  formulas:
    class: "..."         # formula for SymbolType::Class_
    namespace: "..."     # formula for SymbolType::Namespace_
    project: "..."       # formula for SymbolType::Project (often same as namespace)
```

**Formula inheritance:** `project` inherits from `namespace` if not specified. `class` does NOT inherit — if `class` is
listed in `levels`, it MUST have an explicit formula (either via `formula` shorthand or `formulas.class`). This prevents
accidentally using namespace-level formulas with namespace-native metrics at class level.

**Caution on project inheritance:** namespace formulas may reference namespace-native metrics from global collectors
(e.g., `distance`, `abstractness`) that exist at namespace level as raw names but are re-aggregated to project level
under `.avg` suffixes (e.g., `distance__avg`, `abstractness__avg`). If a namespace formula uses such metrics, provide an
explicit project formula. Formulas that reference only standard aggregated metrics (`.avg`, `.sum`) are safe to inherit.

### YAML Config Syntax

```yaml
computed_metrics:
  # Single formula (applied to all levels — valid only if levels don't include class,
  # or if the formula is safe for all levels)
  computed.simple:
    formula: "ccn__avg * 10"
    levels: [ namespace, project ]

  # Per-level formulas
  computed.detailed:
    formulas:
      class: "ccn__avg * 10"
      namespace: "ccn__avg * 10 + distance * 20"
    levels: [ class, namespace, project ]   # project inherits namespace formula
```

The `formula` key (singular) is shorthand for "same formula at all levels". The `formulas` key (plural) provides
per-level overrides. If both are specified, `formulas` takes precedence for levels it defines; `formula` is used as
fallback for other levels.

### Validation

At config load time:

- Each level in `levels` must have a resolvable formula (explicit, or inherited for project←namespace only)
- If `class` is in `levels`, a class-level formula must exist (explicit via `formulas.class` or via `formula` shorthand)
- All formulas are syntax-validated via `ExpressionLanguage::parse()`
- Circular dependency detection considers all formulas across all levels
- References to non-existent computed metrics (variables matching `health__*` or `computed__*` that don't correspond to
  any definition) → **critical config error** (not a silent runtime warning)

---

## Naming Conventions

### Metric Name Prefixes

| Prefix       | Purpose                                                   | Example                                        |
|--------------|-----------------------------------------------------------|------------------------------------------------|
| `health.*`   | **Reserved** for built-in default health scores           | `health.complexity`, `health.overall`          |
| `computed.*` | **Required** prefix for all user-defined computed metrics | `computed.risk_score`, `computed.team.quality` |

### Metric Name Grammar

```
metric_name = prefix "." identifier ("." identifier)*
prefix      = "health" | "computed"
identifier  = [a-zA-Z] [a-zA-Z0-9_]*
```

Metric names must NOT contain `__` (double underscore) — it is reserved as the `.` replacement in Expression Language
variables. This is validated at config load time.

### Validation at Config Load Time

- User-defined metrics MUST start with `computed.`
- No computed metric name may collide with existing raw metric names (e.g., `ccn`, `mi`, `loc`)
- The `health.*` prefix is reserved — users cannot define `health.custom` (only override existing `health.*` defaults)
- No collision between user-defined `computed.*` names and default `health.*` names (YAML overrides of `health.*` are
  legitimate; a `computed.*` name matching a `health.*` name is not)
- Metric names must not contain `__`

### Inter-Metric Reference Validation

At config load time, validate that:

- Circular dependencies among all computed metrics (health + computed) are detected (topological sort fails → error)
- References to non-existent computed metrics are detected → critical error (variable name starts with `health__` or
  `computed__` but no matching definition exists)
- The dependency graph is built by scanning formula variables for `health__*` and `computed__*` prefixes, mapping `__`
  back to `.` to resolve definition names

### Violation Codes

Each violation from `ComputedMetricRule` uses the metric name as its `violationCode` (e.g., `health.complexity`,
`computed.risk_score`). This enables granular `--disable-rule`, `@aimd-ignore`, and baseline matching via existing
`RuleMatcher` prefix logic.

---

## Error Handling

### Fail-Fast Philosophy

Errors in computed metric formulas are reported immediately and loudly. Silent assumptions about missing data lead to
misleading health scores — it is better to fail and let the user fix the formula.

### Syntax Validation (Config Load Time)

Expression Language can parse/compile a formula without evaluating it (`ExpressionLanguage::parse()`). **Invalid syntax
is a critical error** that aborts execution immediately — the user must fix the formula before analysis can proceed.
This is validated at config load time, before any files are processed.

Error messages from Expression Language are wrapped with context: metric name, level, and the original formula text.

### Missing Variables

The `??` operator is an **explicit opt-in** for handling absent metrics — the same semantics as PHP null coalescing:

- **With `??`**: `(distance ?? 0)` — if `distance` is absent, use `0`. Normal operation.
- **Without `??`**: `distance * 50` — if `distance` is absent, this is an **error**. The metric is not computed and a
  warning is reported to the user.

The user controls this explicitly. With per-level formulas, the need for `??` is significantly reduced — each level's
formula references only metrics that exist at that level. `??` remains useful for metrics that may be absent for
specific symbols (e.g., cohesion metrics for classes with 0-1 methods).

### Evaluation Errors

| Error Type                                    | When             | Behavior                                         |
|-----------------------------------------------|------------------|--------------------------------------------------|
| **Syntax error**                              | Config load time | **Critical error**, abort execution              |
| **Reference to non-existent computed metric** | Config load time | **Critical error**, abort execution              |
| **Missing variable (no `??`)**                | Evaluation time  | **Warning**: metric not computed for this symbol |
| **Runtime error** (division by zero, etc.)    | Evaluation time  | **Warning**: metric not computed for this symbol |
| **NaN / Infinity result**                     | Evaluation time  | **Warning**: metric not computed for this symbol |

All evaluation-time errors are reported as warnings to stderr with context (metric name, symbol, level). The computed
metric is simply not stored in the repository for that symbol. Rules will not generate violations for metrics that were
not computed.

### Configuration Merge Semantics

When a user overrides a default `health.*` metric in YAML:

- Only specified fields are overridden; unspecified fields inherit from the default
- Example: specifying only `warning: 30` for `health.complexity` keeps the default formulas, levels, etc.
- `enabled: false` disables the metric without requiring other fields
- `levels` override is a **full replacement**, not merge (if specified, it replaces the default levels entirely)
- `formulas` override merges per-level: specifying `formulas: { class: "..." }` only overrides the class-level formula,
  namespace/project inherit from the default

For new `computed.*` metrics, unspecified fields use these defaults:

- `levels: [namespace, project]` (class level is opt-in)
- `inverted: false` (higher = worse)
- `warning: null`, `error: null` (no violations by default)
- `description: ""`

**Interaction with `health.overall`:** disabling a sub-score (e.g., `health.typing: { enabled: false }`) means
`health.overall` formulas referencing it (without `??`) will encounter a missing variable. The overall score will not be
computed for that level, and a warning will be reported. To avoid this, customize the `health.overall` formula to remove
or `??`-fallback the disabled sub-score.

---

## Configuration

### YAML Config (`aimd.yaml`)

```yaml
computed_metrics:
  # Override a default health metric (only specified fields change)
  health.complexity:
    formula: "100 - clamp((ccn__avg * 3 + cognitive__avg * 2) / 5, 0, 100)"
    warning: 50
    error: 25

  # Define a custom metric with single formula (same for all levels)
  computed.risk_score:
    formula: "ccn__avg * (1 - (tcc__avg ?? 0)) * log(max(loc__sum, 1))"
    description: "Custom risk score combining complexity, cohesion and size"
    levels: [ namespace ]
    inverted: false     # lower is better
    warning: 30
    error: 60

  # Define a custom metric with per-level formulas
  computed.quality:
    formulas:
      class: "clamp(mi__avg, 0, 100) * 0.6 + (tcc ?? 0) * 100 * 0.4"
      namespace: "clamp(mi__avg, 0, 100) * 0.5 + (tcc__avg ?? 0) * 100 * 0.3 + (1 - (distance ?? 0)) * 100 * 0.2"
    levels: [ class, namespace, project ]   # project inherits namespace formula
    inverted: true
    warning: 40
    error: 20

  # Disable a default metric
  health.typing:
    enabled: false
```

### CLI Integration

```bash
# Computed metrics appear in metrics-json output
bin/aimd check src/ --format=metrics-json   # computed metrics included

# Violations from computed metrics appear in all violation formats (text, json, sarif, etc.)
bin/aimd check src/ --format=json           # violations only

# Disable all computed metric violations (the rule itself)
bin/aimd check src/ --disable-rule=computed.health

# Disable all health score violations (prefix match on violation code)
bin/aimd check src/ --disable-rule=health

# Disable all user-defined computed metric violations
bin/aimd check src/ --disable-rule=computed

# Disable a specific score
bin/aimd check src/ --disable-rule=health.complexity

# Use computed metrics in fail-on
bin/aimd check src/ --fail-on=error  # includes computed metric violations
```

---

## Default Computed Metrics

### Prerequisite: TypeCoveragePercentCollector

Before computed metrics, add a `DerivedCollectorInterface` that computes `typeCoverage.pct` (0–100) from existing sum
counters **at class level**:

```
typeCoverage.pct = (paramTyped + returnTyped + propertyTyped) / max(paramTotal + returnTotal + propertyTotal, 1) * 100
```

Collected at class level only. Does NOT self-aggregate to namespace/project — percentages don't aggregate correctly via
Average (Average(50%, 100%) ≠ weighted average). At namespace/project level, the correct percentage is computed inline
in formulas from aggregated sum counters (see `health.typing`).

**Aggregation strategy:** `typeCoverage.pct` defines no aggregation strategies. It is a class-level-only metric.

**Implementation note:** `DerivedCollectorInterface::calculate(MetricBag $sourceBag)` receives the MetricBag for a single
file containing all collected metrics. The collector must iterate class-level metrics keyed as
`typeCoverage.paramTotal:{FQN}`, `typeCoverage.paramTyped:{FQN}`, etc. in the bag, and produce
`typeCoverage.pct:{FQN}` for each class — same pattern as `MaintainabilityIndexCollector` iterating method-level
metrics.

---

### health.complexity (Complexity Score)

```
formulas:
  class: "clamp(100 * 32 / (32 + max(ccn__avg - 1, 0) * 0.2 + cognitive__avg * 2.2), 0, 100)"
  namespace: "clamp(100 * 32 / (32 + max(ccn__avg - 1, 0) * 0.2 + cognitive__avg * 2.2), 0, 100)"
levels: [class, namespace, project]    # project inherits namespace formula
inverted: true         # higher = healthier
warning: 50
error: 25
```

Rationale: Harmonic decay formula — CCN avg of 1 (no branching) = 100. Smooth falloff for high values
(never reaches 0 abruptly). Calibrated against 9 open-source projects: P10≈27, P25≈49, P50≈75.

At class level, `ccn__avg` and `cognitive__avg` are the class-level aggregated values (average across methods). Classes
without methods (empty interfaces, enums) will not have these metrics — health.complexity is simply not computed for
them (see Error Handling). This is documented behavior, not a bug.

### health.cohesion (Cohesion Score)

```
formulas:
  class: "clamp((tcc ?? 0) * 50 + (1 - clamp(((lcom ?? 0) - 1) / 5, 0, 1)) * 50, 0, 100)"
  namespace: "clamp((tcc__avg ?? 0) * 50 + (1 - clamp(((lcom__avg ?? 0) - 1) / 5, 0, 1)) * 50, 0, 100)"
levels: [class, namespace, project]    # project inherits namespace formula
inverted: true
warning: 50
error: 25
```

Rationale: TCC is 0-1 (50% weight). LCOM normalized inversely (50% weight): LCOM=1 (single connected component, ideal) →
0 penalty → 100 for cohesion part. LCOM=6 → full penalty → 0. Uses `1 - clamp((lcom-1)/5, 0, 1)` so that LCOM=1 gives
perfect score.

Uses `??` because not all classes have cohesion metrics (e.g., classes with 0-1 methods).

### health.coupling (Coupling Score)

```
formulas:
  class: "clamp(100 - max((cbo ?? 0) - 5, 0) * 5, 0, 100)"
  namespace: "clamp(100 - (distance ?? 0) * 75 - max((cbo__avg ?? 0) - 8, 0) * 5, 0, 100)"
  project: "clamp(100 - (distance__avg ?? 0) * 75 - max((cbo__avg ?? 0) - 8, 0) * 5, 0, 100)"
levels: [class, namespace, project]
inverted: true
warning: 50
error: 25
```

At class level: `cbo` (class-native, no suffix) is the sole driver. `distance` is a namespace-native metric and is not
referenced. `cbo` is always available for classes (computed by global collector for all classes), so `?? 0` is a safety
fallback, not expected to trigger.

At namespace level: `distance` is a namespace-native metric (raw, no suffix). `cbo__avg` is the average class CBO.

At project level: `distance` is re-aggregated from namespaces as `distance.avg` → formula uses `distance__avg`.
`cbo__avg` remains the same key at project level (standard aggregation chain).

### health.typing (Typing Score)

```
formulas:
  class: "clamp(typeCoverage__pct ?? 0, 0, 100)"
  namespace: "clamp(
      (typeCoverage__paramTyped__sum + typeCoverage__returnTyped__sum + typeCoverage__propertyTyped__sum)
      / max(typeCoverage__paramTotal__sum + typeCoverage__returnTotal__sum + typeCoverage__propertyTotal__sum, 1) * 100
  , 0, 100)"
levels: [class, namespace, project]
inverted: true
warning: 80
error: 50
```

**Class level (0–100 range):** Raw type coverage percentage via `typeCoverage.pct` — passthrough with clamp.

**Namespace/project level (0–100 range):** Type coverage is recomputed from aggregated sum counters (not averaged
percentages — this is mathematically correct). Project inherits namespace formula.

### health.maintainability (Maintainability Score)

```
formulas:
  class: "clamp(mi__avg, 0, 100)"
  namespace: "clamp(mi__avg, 0, 100)"
levels: [class, namespace, project]    # project inherits namespace formula
inverted: true
warning: 65
error: 50
```

Rationale: MI is already 0-100, just pass through with thresholds. No `??` — if MI is not available, the metric is
simply not computed (classes without methods).

### health.overall (Overall Health Score)

```
formulas:
  class: "clamp(
      (health__complexity ?? 75) * 0.30
    + (health__cohesion ?? 75) * 0.25
    + (health__coupling ?? 75) * 0.25
    + (health__typing ?? 75) * 0.20
  , 0, 100)"
  namespace: "clamp(
      (health__complexity ?? 75) * 0.25
    + (health__cohesion ?? 75) * 0.20
    + (health__coupling ?? 75) * 0.20
    + (health__typing ?? 75) * 0.15
    + (health__maintainability ?? 75) * 0.20
  , 0, 100)"
levels: [class, namespace, project]    # project inherits namespace formula
inverted: true
warning: 50
error: 30
```

**Class level:** Uses `?? 75` for `health__complexity` and `health__cohesion` — classes without methods (interfaces,
enums) and classes with 0-1 methods get neutral scores instead of being excluded. `health__coupling` and
`health__typing` are always computed at class level. All sub-scores now use `?? 75` fallback — `cbo`, `dit`, and `typeCoverage.pct` are class-native metrics
always available. `health__maintainability` is not included (it depends on method-level MI; including it with `??` would
add noise). Weights: 0.30 + 0.25 + 0.25 + 0.20 = 1.0.

**Namespace/project level:** All 5 sub-scores are available. Weights: complexity 25%, cohesion 20%, coupling 20%, design
15%, maintainability 20% = 1.0.

Uses inter-metric references to other `health.*` metrics. This is clean, readable, and automatically syncs with user
overrides of sub-scores.

---

## Implementation Considerations

### Inter-Metric Dependencies

All computed metrics (`health.*` and `computed.*`) are in a **single dependency pool** with unified topological sort.

**Implementation:** Single-pass evaluation in `ComputedMetricEvaluator`:

1. Build dependency graph: for each metric, extract referenced computed metric names from all formulas (scan for
   variable names matching `health__*` or `computed__*` prefixes, then map back to metric names via `__` → `.`)
2. Topological sort (circular deps should be caught at config time, but guard here too)
3. Evaluate metrics in topological order, storing results in repository

### Expression Language Security

`symfony/expression-language` is safe by default — no function calls beyond registered ones, no variable writes, no
system access. We register only math functions. Formulas live in `aimd.yaml` in the repository — developer-controlled,
not user input.

### Performance

Expression Language compiles expressions to PHP closures on first use. Cost is O(definitions × symbols_per_level). For
typical projects (6 health + N user metrics, hundreds of classes, dozens of namespaces), this is sub-second. Expression
parsing is done once per definition+level and cached.

### Metric Storage

Computed metrics are stored in `MetricRepositoryInterface` like any other metric, using the computed metric name as the
metric key. They appear in `--format=metrics-json` output alongside raw metrics.

SymbolPath: same as the namespace/class/project being evaluated.

### Aggregation

Computed metrics are computed **independently at each level** (class, namespace, project) using the level-specific
formula. They do NOT aggregate from class→namespace→project. Each level's formula runs with that level's input
variables.

Rationale: aggregating average-of-scores gives mathematically different results than score-of-averages. Independent
computation is more predictable and gives the user control. Per-level formulas make this explicit.

### Variable Resolution

Variable names are unified across levels: `ccn__avg` resolves to `ccn.avg` in the metric repository (`.` → `__` mapping
reversed). This works the same regardless of whether the symbol is a class, namespace, or project. The aggregation
system already stores `ccn.avg` at every level.

For metrics without `.avg` variants at certain levels, the variable simply won't exist → resolved to `null` → formula
uses `??` fallback or fails (see Error Handling).

### Baseline and Suppression Behavior

Computed metric violations are treated like any other violation:

- **Baseline:** violations can be included in baseline files and suppressed. Since computed metrics change as code
  evolves, baseline entries for them will naturally expire when the code improves.
- **Suppression:** `@aimd-ignore health.complexity` suppresses the violation for that symbol. Prefix matching:
  `@aimd-ignore health` suppresses all health violations.
- No special handling needed — standard baseline and suppression behavior applies.

### Project-Level Violations

Project-level violations use `Location::none()` — there is no natural file/line for project-wide metrics. Inline
`@aimd-ignore` is not applicable for project-level violations. Suppression for project-level metrics is done via:

- `--disable-rule=health.complexity` (CLI)
- Baseline (violation stored with `symbolPath = project:`)

### Violation Message Format

Violations from computed metrics use the following message format:

```
<SymbolPath>: <metric_name> = <value> (<severity> threshold: <operator> <threshold>)
```

Example: `App\Payment: health.complexity = 34 (warning: below 50)`

For inverted metrics (higher = better): `below <threshold>`. For normal metrics: `above <threshold>`.

### New Dependency

```
composer require symfony/expression-language
```

Lightweight, no transitive dependencies. Already in the Symfony ecosystem.

---

## Definition of Done

### Prerequisite

- [ ] `TypeCoveragePercentCollector` — compute `typeCoverage.pct` at class level (DerivedCollectorInterface)
- [ ] No aggregation to namespace/project (class-level only metric)
- [ ] Unit tests

### Core

- [ ] `ComputedMetricDefinition` value object with all fields (name, formulas, levels, inverted, thresholds)
- [ ] Per-level formula support: `formulas: array<SymbolType, string>`
- [ ] Formula inheritance: `project` ← `namespace` only. `class` requires explicit formula.
- [ ] `ComputedMetricDefaults` returns the 6 default health definitions with per-level formulas
- [ ] Metric name grammar validation: no `__` in names, must match `[a-zA-Z][a-zA-Z0-9_]*` per segment
- [ ] Unit tests for definition creation and validation

### Metrics

- [ ] `ComputedMetricEvaluator` evaluates formulas using Expression Language
- [ ] Single-pass evaluation with topological sort across all computed metrics (health + computed)
- [ ] Per-level formula selection: picks the correct formula for the current symbol's level
- [ ] Symbol iteration: Project (single), Namespace (`getNamespaces()`), Class (`all(SymbolType::Class_)`)
- [ ] Variable resolution from `MetricRepositoryInterface` (`.` → `__` mapping, reversible)
- [ ] Math functions registered: `min`, `max`, `abs`, `sqrt`, `log`, `log10`, `clamp`
- [ ] Error handling: missing variable without `??` → warning + skip, NaN/Infinity → warning + skip
- [ ] `??` support: missing variable with `??` → uses fallback (normal operation)
- [ ] `ComputedMetricEvaluator` must NOT implement `MetricCollectorInterface`
- [ ] Profiler spans: `computed` phase span + per-metric sub-spans (`computed.health.complexity`, etc.)
- [ ] Unit tests for each default formula with sample metric values (per level)
- [ ] Unit tests for error cases: missing metrics, invalid formulas, NaN results

### Rules

- [ ] `ComputedMetricRule` implements `RuleInterface` with `NAME = 'computed.health'`
- [ ] Standard DI registration via autoconfiguration — no new CompilerPasses
- [ ] `ComputedMetricRuleOptions` holds merged definitions + thresholds, implements `RuleOptionsInterface`
- [ ] `ComputedMetricRuleOptions::fromArray()` reads pre-merged definitions from `ComputedMetricDefinitionHolder`
- [ ] `analyze()` iterates definitions, reads pre-computed values, checks thresholds, generates violations
- [ ] Violation code = definition name (e.g., `health.complexity`, `computed.risk_score`)
- [ ] Threshold semantics: inverted metrics (below threshold = violation) vs normal (above = violation)
- [ ] Violation message format: `<symbol>: <metric> = <value> (<severity> threshold: <op> <threshold>)`
- [ ] Per-definition profiler sub-spans inside `analyze()` (`rule.computed.health.health.complexity`, etc.)
- [ ] Unit tests for violation generation with various threshold/inverted combinations
- [ ] Unit tests for `--disable-rule` via `isViolationCodeEnabled()` (prefix and exact match)
- [ ] Unit tests for suppression compatibility (`@aimd-ignore health.complexity`, `@aimd-ignore health`)
- [ ] Unit tests for baseline compatibility (computed metric violations in baseline)

### Configuration

- [ ] `computed_metrics` section parsed from YAML
- [ ] Support for `formula` (singular, all levels) and `formulas` (plural, per-level)
- [ ] Merge semantics: override only specified fields, inherit rest from default (levels = full replace, formulas =
  per-level merge)
- [ ] Defaults for user-defined: `levels: [namespace, project]`, `inverted: false`, thresholds: null
- [ ] `enabled: false` support
- [ ] Validation: formula syntax (critical error, aborts execution)
- [ ] Validation: each level in `levels` has a resolvable formula (class requires explicit)
- [ ] Validation: `computed.*` prefix required for user-defined
- [ ] Validation: no collision with raw metric names
- [ ] Validation: no collision between `computed.*` names and `health.*` names
- [ ] Validation: no `__` in metric names
- [ ] Validation: circular dependency detection (topological sort)
- [ ] Validation: references to non-existent computed metrics → critical error
- [ ] Error messages wrapped with context (metric name, level, formula text)
- [ ] CLI: computed metric violations appear in all violation formats
- [ ] CLI: computed metrics appear in `--format=metrics-json`
- [ ] Documentation in `src/Configuration/README.md`

### Infrastructure

- [ ] `composer require symfony/expression-language` — add to `composer.json`
- [ ] `ComputedMetricDefinitionHolder` — shared value holder service for merged definitions
- [ ] `ComputedMetricsStage` — configuration pipeline stage: merge defaults + YAML, validate, store in holder
- [ ] `RuntimeConfigurator` receives holder, triggers merge via config pipeline
- [ ] Holder injected into both `AnalysisPipeline` and `ComputedMetricRuleOptions`

### Pipeline Integration

- [ ] `AnalysisPipeline` calls `ComputedMetricEvaluator` after global context (+re-agg), before analysis
- [ ] `ComputedMetricEvaluator::compute($repo, $definitions)` — stateless, receives definitions as argument
- [ ] Edge case: empty definitions list → no-op (no metrics computed, no violations)
- [ ] Integration test: Pipeline and Rule receive identical definitions from holder
- [ ] Integration tests: end-to-end with sample PHP files (including multiple classes per file)

### Documentation

- [ ] Update `src/Core/README.md`, `src/Metrics/README.md`, `src/Rules/README.md`
- [ ] Update `CLAUDE.md` key features section
- [ ] Website docs: new page for computed metrics
- [ ] Website docs: document per-level formulas and which metrics are available at each level
- [ ] Website docs: document that classes without methods get neutral complexity/cohesion in overall score
- [ ] Website docs: document project-level violation behavior (no inline suppression)
- [ ] Update `CHANGELOG.md`
- [ ] Update `PRODUCT_ROADMAP.md`

---

## Resolved Questions

1. **Type coverage percentage** → `TypeCoveragePercentCollector` (DerivedCollectorInterface) at class level only. No
   aggregation to namespace/project — percentages don't aggregate correctly via Average. At namespace/project level, the
   correct percentage is computed inline in health.typing formula from aggregated sum counters.

2. **Class-level computed metrics** → Yes, support class level with **per-level formulas**. Each level has its own
   formula referencing only metrics available at that level.

3. **Inter-metric references** → Single pool of all computed metrics (health + computed). Unified topological sort.
   Circular dependencies detected as critical error. No two-tier system.

4. **Naming convention** → `health.*` reserved for built-in defaults. `computed.*` required for user-defined. Metric
   names must not contain `__`. Prefix matching via `isViolationCodeEnabled()` handles `--disable-rule=health` and
   `--disable-rule=computed`.

5. **Missing metrics** → Fail-fast approach. Missing variable without `??` is an **error** (warning reported, metric not
   computed). `??` is an explicit opt-in for fallbacks. Per-level formulas significantly reduce the need for `??`.

6. **NaN / Infinity** → Metric not stored, warning reported with context.

7. **Syntax validation** → Critical error at config load time. Expression Language can parse without evaluating. Invalid
   formula aborts execution immediately.

8. **Config merge** → YAML overrides only specified fields; unspecified fields inherit from default definition. `levels`
   is full replacement when specified. `formulas` is per-level merge. User-defined defaults:
   `levels: [namespace, project]`, `inverted: false`, thresholds: null.

9. **Aggregation** → Computed independently at each level using per-level formulas. No class→namespace→project
   aggregation.

10. **Variable naming** → `.` replaced by `__` (double underscore). `ccn__avg` resolves to `ccn.avg`. Reverse mapping is
    unambiguous: `__` always means `.`, single `_` is preserved as-is.

11. **health.typing at class level** → Per-level formula with renormalized weights (type coverage 60%, DIT 40%). Full
    0–100 range at all levels.

12. **health.overall for classes without methods** → Class-level formula uses `(health__complexity ?? 75)` and
    `(health__cohesion ?? 75)` for neutral fallbacks. Excludes `health__maintainability`. Weights redistributed among 4
    sub-scores (0.30 + 0.25 + 0.25 + 0.20 = 1.0).

13. **health.overall and catastrophic sub-scores** → Weighted average without penalty. Sub-score violations provide
    sufficient alerting for catastrophic values. Overall is an overview metric, not an alarm.

14. **LCOM formula** → `1 - clamp((lcom-1)/4, 0, 1)`. LCOM=1 (ideal) → 0 penalty → 100 score. LCOM=5+ → full penalty →
    0. Cap at LCOM=5 is intentional.

15. **Partial analysis (git:staged)** → Not a concern. AIMD always analyzes the full codebase for metrics; partial
    analysis only filters violation output.

16. **Baseline and suppression** → Standard behavior. `@aimd-ignore health` prefix matching works. Project-level
    violations use `Location::none()`, no inline suppression.

17. **Violation message format** → `<symbol>: <metric> = <value> (<severity> threshold: <op> <threshold>)`.

18. **Rule architecture** → Single `ComputedMetricRule` with `NAME = 'computed.health'`, standard DI registration. NOT
    N instances via CompilerPass — `aimd.yaml` loads at runtime, after container compilation. Definitions arrive through
    `ComputedMetricRuleOptions` via `RuleOptionsFactory` pattern. Granular `--disable-rule` via
    `isViolationCodeEnabled()` (existing hierarchical rule mechanism).

19. **`scale` field** → Removed. Formulas use `clamp()` explicitly. Built-in health metrics are bounded 0–100 by design.
    User-defined metrics can produce any finite scalar.

20. **Per-level formula inheritance** → `project` inherits from `namespace`. `class` does NOT inherit — must be
    explicit. Prevents accidental use of namespace-native metrics at class level. **Caveat:** namespace formulas using
    namespace-native metrics from global collectors (`distance`, `abstractness`) must provide explicit project formulas,
    because these metrics are re-aggregated to project level under `.avg` suffixes.

21. **Project-level violations** → Use `Location::none()`. Inline `@aimd-ignore` not applicable. Suppression via CLI (
    `--disable-rule`) or baseline.

22. **Reference to non-existent computed metric** → Critical config error at load time. Detected by scanning formula
    variables for `health__*`/`computed__*` prefixes and checking against known definitions.

23. **ComputedMetricDefaults placement** → Stays in `Core`. It is pure data (definitions as value objects), not policy
    or config logic. No dependencies.

24. **Definition sharing between Calculator and Rule** → `ComputedMetricDefinitionHolder` (shared singleton service).
    `ComputedMetricsStage` merges definitions once and stores in holder. Both `AnalysisPipeline` and
    `ComputedMetricRuleOptions` read from the same holder. Calculator is stateless: `compute($repo, $definitions)`.
    Single source of truth, readonly value objects guarantee consistency.

25. **Weights in overall score** → Kept as proposed (arbitrary). Will validate on real projects before release. Users
    can override the formula.

26. **Class-native vs aggregated variable names** → At class level, class-collected metrics (`tcc`, `lcom`, `cbo`,
    `dit`) use their base name (no suffix). Method-collected metrics (`ccn`, `cognitive`) use `.avg` suffix (aggregated
    from methods). At namespace/project level, all metrics use `.avg` suffix (aggregated from classes). Per-level
    formulas must use the correct variable name for each level. Double underscore mapping applies only to dots:
    `tcc.avg` → `tcc__avg`, but `tcc` → `tcc` (no dots, no mapping).

27. **`--only-rule` limitation** → `--only-rule=health.complexity` does NOT work for individual computed metrics (rule
    NAME `computed.health` has no prefix relation to violation codes `health.*`/`computed.*`). Only
    `--only-rule=computed.health` works. For granular control, use `--disable-rule` instead. Documented trade-off of
    single-rule architecture.

28. **`cbo__avg` at namespace level** → Deliberately uses `cbo.avg` (average class CBO in namespace), not
    namespace-level `cbo` (namespace coupling count). The health score measures average per-class coupling quality, not
    the namespace's own coupling.

---

## Open Questions

None. All questions resolved.

---

## Changelog

### v4 (2026-03-13) — Review round

- **Fixed:** `health.coupling` and `health.typing` now have explicit project-level formulas. Namespace formulas use
  namespace-native metrics (`distance`, `abstractness`) that are re-aggregated to project level as `.avg` — inheriting
  the namespace formula would fail at project level.
- **Fixed:** Added DI registration note for `ComputedMetricEvaluator` (renamed from `ComputedMetricCalculator`). The
  original name matched the `*Calculator.php` exclude pattern in `CollectorConfigurator`, preventing DI registration
  entirely. Renamed to `ComputedMetricEvaluator` which is auto-registered as a plain autowired service.
- **Fixed:** Replaced `SymbolLevel` (non-existent type) with `SymbolType` (existing enum in Core).
- **Added:** Note on `NAME = 'computed.health'` being an intentional exception to `group.rule-name` convention.
- **Added:** Warning about disabling sub-scores breaking `health.overall` (missing variable without `??`).
- **Added:** `symfony/expression-language` to DoD Infrastructure section.
- **Added:** Formula inheritance caution for namespace-native metrics at project level.
- **Added:** Implementation note for `TypeCoveragePercentCollector` on iterating FQN-keyed metrics in MetricBag.
- **Clarified:** Duplicate name validation → collision between `computed.*` and `health.*` names.
- **Clarified:** Profiler span naming to `computed` / `computed.health.complexity` (consistent with pipeline style).
