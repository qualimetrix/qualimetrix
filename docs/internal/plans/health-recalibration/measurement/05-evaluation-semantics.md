# Health-metric computation mechanics — reverse-engineering report

Working tree: `.claude/worktrees/health-metrics-recalibration-54a7a3`
All paths below are relative to this worktree's root.

## 1. What actually gets computed at the project level when a formula is defined only for class/namespace

`ComputedMetricDefinition::getFormulaForLevel()` — `src/Analysis/Evidence/ComputedMetrics/Contract/Definition/ComputedMetricDefinition.php:55-79`:
a direct lookup by level key (`class`/`namespace`/`project`); if the level is `project` and there's no explicit `formulas['project']` entry, it **inherits** the `formulas['namespace']` entry (lines 73-76). If there's no namespace formula either, it returns `null`.

This is confirmed by the actual defaults in `ComputedMetricDefaults.php`:
- `health.cohesion` (`src/Analysis/Evidence/ComputedMetrics/ComputedMetricDefaults.php:38-52`) has no `project` key → at project level it explicitly uses the namespace formula (line 45).
- `health.typing` (same file, `:82-98`) — the same thing, project inherits the namespace formula (line 91).
- `health.overall` (`:117-131`) — also no `project` key, project inherits the namespace formula (line 124).
- `health.complexity`, `health.coupling`, `health.maintainability` **duplicate** the same formula under an explicit `project` key instead of inheriting (comments "explicit to avoid inherited formula drift", lines 29, 70-74, 108) — meaning the authors deliberately avoided the inheritance mechanism where they genuinely wanted the exact same formula, but documented it explicitly.

`ComputedMetricEvaluator::evaluate()` (`src/Analysis/Evidence/ComputedMetrics/Contract/Evaluation/ComputedMetricEvaluator.php:49-59`) calls `getFormulaForLevel($level)` for each level in `$definition->levels`; if it's `null` — it **skips the dimension at that level** (`continue`, line 55-56), the metric at that level simply isn't published (`repo->addScalar` isn't called).

Important: `ComputedMetricFormulaValidator::validateFormulaCoverage()` (`src/Analysis/Evidence/ComputedMetrics/ComputedMetricFormulaValidator.php:99-115`) **requires** that for every level in `$definition->levels` a formula resolves (via `getFormulaForLevel`, including namespace→project inheritance) — if not, the configuration is rejected with a `ConfigurationRefusal`. So the "skip the dimension" path never fires in production for the default metrics — either an explicit formula or namespace inheritance must cover all three levels in `levels`. Not verified by running it (only by reading the validator's code), but the logic is linear and doesn't depend on external data.

## 2. Behavior when an input metric's key is absent: `m["x"] ?? 0` vs. bare `m["x"]`

`MetricLookup::offsetGet()` (`src/Analysis/Evidence/ComputedMetrics/Contract/Evaluation/MetricLookup.php:31-40`) **never throws an exception and never issues a PHP warning** — for a missing or non-numeric key it returns `null` (lines 37-39: `$this->values[$offset] ?? null`, then `is_int/is_float` else `null`).

A bare `m["x"]` (without `??`) for a missing key yields **`null`**, which PHP arithmetic/functions (`max`, `+`, etc.) further interpret as `0` without an exception. Confirmed by running exactly this pattern from `health.complexity`/project: `max(m["size.symbol-method-count"], 1)` (`ComputedMetricDefaults.php:28,30`).

Run (the docblock confirms this is intentional — `MetricLookup.php:11-15`: *"Absence answers rather than warns, so `m['x'] ?? 0` is the idiom... a missing key never becomes a PHP warning"*):
```
php scratchpad/test_missing_key.php
```
Script: `/private/tmp/.../scratchpad/test_missing_key.php` — three calls through the real `ComputedMetricExpression::evaluate()` with `MetricLookup(['a'=>5])`:
- `max(m["missing-key"], 1)` → `int(1)` (no exception, `null` is treated as ≤1)
- `m["missing-key"] + 1` → `int(1)` (no exception; `null + 1 == 1`)
- `(m["missing-key"] ?? 0) + 1` → `int(1)` (the same result via the explicit path)

I.e. bare access does NOT raise an exception — semantically it behaves like the absence of a check, a zero in arithmetic; but this is not the same thing as `?? 0` from the VALIDATION standpoint (see below).

**Validation that rejects a formula referencing a key that doesn't exist at that level**: yes, but not in `ComputedMetricFormulaValidator` (config time) — rather in `ComputedMetricEvaluator::validateFormulaVariables()` (`.../Contract/Evaluation/ComputedMetricEvaluator.php:129-158`), called from `evaluateAtLevel()` (line 75) — **at runtime**, on real data:
- it collects the union of all known metric keys across all symbols at that level (`collectKnownMetricKeys`, lines 167-177);
- if there's no data at all, validation is skipped (lines 141-143, `allKnownKeys === []`);
- it only takes the "required" variables — those not guarded by `??` (`extractRequiredFormulaVariables` → `ComputedMetricExpression::requiredKeysOf()`, lines 182-193 in the evaluator, implemented in `ComputedMetricExpression.php:182-193`: a key is required if at least one mention of it is NOT on the left of `??`);
- references to other computed metrics (`health.*`/`computed.*`) are not checked here — that's `ComputedMetricFormulaValidator`'s job (skipped, lines 192-194);
- if it finds a "foreign" key, it throws a `RuntimeException` (lines 149-157).

`ComputedMetricFormulaValidator::validateMetricKeyExistence()` (`ComputedMetricFormulaValidator.php:223-253`), at the config stage, checks **any** mentioned key (not just "required" ones) against the global `MetricName` constant catalog (`existsInCatalog`, lines 269-293) — but this checks "the metric exists somewhere at all in the product", not "exists at this level". The level-specific check is only the runtime path above.

There's also a **grammar guard**, separate from missing values: `ComputedMetricExpression::everyAccessIsALiteralIndex()` (`ComputedMetricExpression.php:97-116`) requires that every access to `m` be `m["literal-string"]`, not a method call or a computed index — this is checked in `ComputedMetricFormulaValidator::validateFormulaSyntax()` (`ComputedMetricFormulaValidator.php:83-89`) at the configuration stage.

## 3. Order of evaluation between metrics (health.overall → health.complexity, etc.)

`ComputedMetricDependencyGraphCalculator::sort()` (`src/Analysis/Evidence/ComputedMetrics/ComputedMetricDependencyGraphCalculator.php:33-45`) builds a dependency graph from the formulas (`collectDependenciesOf` → `extractComputedMetricDeps` → `ComputedMetricExpression::computedReferencesOf()`, which extracts `health.*`/`computed.*` keys from a formula, `ComputedMetricExpression.php:200-206`) and performs a Kahn topological sort (`buildReverseDepsAndInDegree` + `traverseQueue`, lines 109-153). If the graph is cyclic, `sort()` returns `null`.

`ComputedMetricEvaluator::topologicalSort()` (`Contract/Evaluation/ComputedMetricEvaluator.php:224-236`) calls this calculator; on `null` (a cycle) it **logs a warning and returns the original (unsorted) order**, relying on the fact that config-time validation (`ComputedMetricFormulaValidator::validateCircularDependencies()`, `ComputedMetricFormulaValidator.php:122-178`, a DFS with an explicit `ConfigurationRefusal`) should already have rejected such a configuration earlier — i.e. a cycle should theoretically be unreachable at `evaluate()` time when the config resolver is used.

`ComputedMetricEvaluator::evaluate()` (`ComputedMetricEvaluator.php:35-65`) walks `$sorted` (the topologically ordered definitions) and immediately writes the result via `$repo->addScalar()` (line 114) for each — meaning that by the time a dependent metric (`health.overall`) is evaluated, all of its dependencies (`health.complexity`, etc.) at the SAME level have already been computed and sit in the `MetricRepositoryInterface`, accessible via `buildVariableMap()` → `MetricLookup` (lines 260-263).

**If a sub-value hasn't been computed at that level** (e.g. the dependency isn't reported at that level at all, or its computation failed with an error/NaN and was skipped — see `catch (Throwable $e)` lines 83-92 and the NaN/Infinity guard lines 105-112, both leading to a `continue` without writing to the repository): then `m["health.xxx"]` in the dependent formula returns `null` via `MetricLookup`, and this is exactly where the `?? 75` (or `?? 0.5`, `?? 50` — a specific number per dimension) fallback kicks in, hardcoded in the formula itself, e.g. `(m["health.complexity"] ?? 75) * 0.35` (`ComputedMetricDefaults.php:123-124`). No separate "guarantee mechanism" backs this up — it's purely the formula-level `??` idiom, the same mechanism as for base metrics in section 2.

## 4. Functions registered in expressions

All are registered in the `ComputedMetricExpression::__construct()` constructor (`Contract/Evaluation/ComputedMetricExpression.php:46-67`):
- `min`, `max`, `abs`, `sqrt`, `log`, `log10` — via `ExpressionFunction::fromPhp(...)` (lines 50-55), i.e. direct wrappers over the same-named PHP functions.
- `clamp(value, min, max)` — a custom function (lines 57-66): `max(min, min(max, value))`, i.e. a range clamp.

Besides the explicitly registered functions, Symfony's `ExpressionLanguage` supports operators out of the box: arithmetic (`+ - * /`), exponentiation `**`, the ternary operator `?:`/`? :`, the null-coalescing operator `??`, comparisons (`< > <= >= == !=`), logic (`&& || !`) — these aren't "registered" separately, they're part of the expression grammar (used, e.g., in the `health.cohesion`/`health.typing` formulas with a ternary, `ComputedMetricDefaults.php:44,91`).

## 5. Semantics of `warningThreshold`/`errorThreshold` under `inverted: true`

`ComputedMetricFindingBuilder::severity()` (`src/Analysis/Evidence/ComputedMetrics/Finding/ComputedMetricFindingBuilder.php:47-66`): when `inverted === true`, severity is assigned when the value is **less than** the threshold (`$value < $definition->errorThreshold` / `$value < $definition->warningThreshold`, lines 50-53) — i.e. a finding (Error/Warning) means the health score is below the threshold ("below"). When `inverted === false` — the opposite, a finding when the value is above the threshold ("above").

The finding message line (`build()`, line 31): `$operator = $definition->inverted ? 'below' : 'above';` — confirms the same thing in text.

All default `health.*` metrics are declared with `inverted: true` (`ComputedMetricDefaults.php:34,49,78,95,113,128`) — meaning that for health "higher is better", and a finding is raised when the score **drops below** the warning/error threshold.

## 6. Overriding the formula/thresholds via `qmx.yaml`

`ComputedMetricOverrideReader` (`src/Analysis/Evidence/ComputedMetrics/ComputedMetricOverrideReader.php`) reads this, assembled by `ComputedMetricsConfigResolver::resolve()` (`ComputedMetricsConfigResolver.php:49-81`).

Keys of one `computed_metrics.<name>:` entry (method `formulas()`, `:107-149`; `levels()`, `:165-182`; `description()`, `:189-204`; `inverted()`, `:214-229`; `thresholds()`, `:346-376`):
- `formula` (singular) — a string that replaces the formula **on all levels at once** (lines 111-123), including any level-specific formulas the default metric already had.
- `formulas` (plural, a `level → formula` map) — overrides the formula for a **single level**; takes precedence over `formula` (applied after it, lines 125-148) — meaning YES, you can set a formula for an individual level.
- `levels` — a list of levels (`class`/`namespace`/`project`) at which the metric is reported; replaces the list wholesale, doesn't merge (lines 165-182).
- `description` — a string (:189-204).
- `inverted` — a boolean; if not specified, it's taken from the base definition during `merge()` (`:61`, `?? $base->inverted`) or defaults to `false` in `create()` for a new user-defined name (`:93`).
- `threshold` (singular) — sets warning=error=one number, mutually exclusive with `warning`/`error` (otherwise a `ConfigurationRefusal`, `:352-357`); `threshold: null` resets to the defaults (:363-365).
- `warning` / `error` — set individually, each can be `null` (reset to the default, `thresholdOrNull`, :383-386) or a number.
- `enabled: false` — handled OUTSIDE `ComputedMetricOverrideReader`, in `ComputedMetricsConfigResolver::applyEntry()` (:117-122, `applyDisable`, :177-194): for `health.*` (other than `health.overall`) it's not removed immediately, but accumulated in `$disabledHealth` and run through `HealthFormulaExcluder` (which reformulates `health.overall` for the remaining weights — see section 7); for other metrics (and for `health.overall`) — `unset($definitions[$name])`.

**Merge semantics for a level not mentioned in an override**: `merge()` takes the formula for a specific level from `$base->formulas` as the default and layers `formula`/`formulas` on top (`formulas()`, `:107-149`, starts with `$formulas = $defaults`); meaning a level not mentioned in the overrides **keeps the default definition's formula** (or, if the override uses the singular `formula`, it overrides ALL levels, including ones not explicitly mentioned). The same applies to `description`/`inverted`/`thresholds` — if a key isn't specified in the overrides, the value comes from `$base`. Levels (`levels:`), if not specified, are also fully inherited from `$base->levels` (`:167-169`) — there's no partial merge of the level list, only full replacement or full inheritance.

For a NEW (user-defined) metric absent from the defaults, `create()` (:78-97) supplies the defaults: `levels = [Namespace_, Project]` (no `Class_`!, :84), `inverted = false`, `description = ''`, `formulas = []` (must be closed via `formula`/`formulas` in the config itself, otherwise `validateFormulaCoverage` in the validator will refuse).

## 7. `HealthFormulaExcluder` — what gets excluded and by which config

`HealthFormulaExcluder::applyExcludeHealth()` (`src/Analysis/Evidence/ComputedMetrics/Health/Configuration/HealthFormulaExcluder.php:36-51`), called from `ComputedMetricsConfigResolver::resolve()` step 3 (`ComputedMetricsConfigResolver.php:70-75`) with the list `$combinedExclusions` — the union of:
- the explicit `exclude_health` (CLI/config option, normalized to the `health.<dim>` shape in `ComputedMetricsConfigResolver::resolve()`, :57-60), and
- dimensions automatically collected as disabled via `enabled: false` in the `computed_metrics.health.<dim>:` entry itself (see section 6, `applyDisable`).

What gets excluded: the dimension itself (`health.complexity`, `health.cohesion`, etc.) is removed wholesale from the list of definitions (`filterDefinitions`, `:112-129`, `isset($excludedSet[$definition->name])` → `continue`). Plus **the `health.overall` formula is rebuilt** at each level (`rebuildOverallFormula`, `:155-213`): it parses the canonical form `(m["health.dim"] ?? fallback) * weight` via `WeightedHealthFormula::termsOf()` (:161), drops the terms of the excluded dimensions, and **renormalizes the weights of the remaining ones** proportionally (`buildWeightedFormula`, `:225-246`, dividing each weight by the sum of the remaining weights). If the `health.overall` formula doesn't match the canonical form (e.g. the user overrode it with something non-standard), the resolver **refuses** with an explicit `ConfigurationRefusal` (:170-188), rather than silently losing data. If, after exclusion, `health.overall` has no level left with any weights, the `health.overall` definition is removed entirely (`allEmpty`, :200-201; `replaceOverall`, :137-147).

`HealthFormulaExclusionInterface` (`src/Analysis/Evidence/ComputedMetrics/Contract/Configuration/HealthFormulaExclusionInterface.php`) — the contract's single method, `applyExcludeHealth(list $definitions, list $excludedDimensions): list` — the boundary between `ComputedMetricsConfigResolver` (which assembles the exclusion list) and the concrete implementation that rebuilds the formulas.

## Verified by running

- `php scratchpad/test_missing_key.php` — confirmed section 2 (a missing key → `null`, no exception, arithmetic behaves as if it were `0`).
- Everything else was derived from reading the validators/resolvers/evaluator code; `bin/qmx check` was not run — the behavior in sections 1 and 3 follows linearly from the cited code (`getFormulaForLevel`, `ComputedMetricEvaluator::evaluate/evaluateAtLevel`, `ComputedMetricDependencyGraphCalculator::sort`) and is corroborated by the repository's existing unit tests (`tests/Analysis/Evidence/ComputedMetrics/Unit/ComputedMetricEvaluatorTest.php`, `ComputedMetricDefinitionTest.php`, `ComputedMetricsConfigResolverTest.php`, `HealthFormulaExcluderTest.php`, `WeightedHealthFormulaTest.php`), which were not run as part of this recon — not verified by running them.
