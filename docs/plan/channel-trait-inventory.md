# Channel × Trait Inventory

Pure fact inventory of every `(ruleName, violationCode)` channel emitted by
`src/Rules/**/*Rule.php` and `src/Architecture/Rules/*Rule.php`. A channel is
identified by walking every `new Violation(...)` call site and recording the
literal `ruleName` / `violationCode` values actually passed at that call site
(not just the class's own `NAME` constant). Facts only — no design proposals.

## Dimension vocabulary

1. **Threshold source** — `none` \| `configured tiers` \| `symbol-dependent` \| `run-dependent`
2. **Comparison** — `inclusive` (`>=`/`<=`) \| `strict` (`>`/`<`) \| `not applicable`
3. **Worse direction** — `higher` \| `lower` \| `not applicable`
4. **Trigger predicate** — `single threshold` \| `conjunction` \| `criteria count` \| `unconditional`
5. **Band** — `unbounded` \| `has cutoff`
6. **Magnitude** — `none` \| `scalar` \| `vector` \| `count`
7. **Identity** — `symbol` \| `occurrence` \| `graph`

A cell contains `?` only when the code genuinely does not give one unambiguous
answer; the reason is in [Anomalies](#anomalies).

**Applied convention** (kept uniform across the whole table, documented here
once instead of per-row): several rules gate symbol *eligibility* before the
threshold check runs — e.g. `minClassCount`, `minAfferent`, `excludeReadonly`,
`excludeDataClasses`. These gates decide whether a symbol is evaluated at all,
not a second scored criterion feeding the violation decision. They are
classified as `single threshold`, not `conjunction`. `conjunction` is reserved
for cases where two or more *measured* conditions jointly gate the same
violation (e.g. `DataClassRule`: WOC high AND WMC low, both metrics of the
symbol being judged).

## Table

### CodeSmell (14 channels / 11 rule classes read + `AbstractCodeSmellRule` base)

| Channel                                | Emitting class                 | 1.Threshold source                                                                | 2.Comparison     | 3.Direction    | 4.Trigger        | 5.Band    | 6.Magnitude | 7.Identity |
| -------------------------------------- | ------------------------------ | --------------------------------------------------------------------------------- | ---------------- | -------------- | ---------------- | --------- | ----------- | ---------- |
| `code-smell.boolean-argument`          | `BooleanArgumentRule`          | none                                                                              | not applicable   | not applicable | unconditional    | unbounded | none        | occurrence |
| `code-smell.count-in-loop`             | `CountInLoopRule`              | none                                                                              | not applicable   | not applicable | unconditional    | unbounded | none        | occurrence |
| `code-smell.debug-code`                | `DebugCodeRule`                | none                                                                              | not applicable   | not applicable | unconditional    | unbounded | none        | occurrence |
| `code-smell.empty-catch`               | `EmptyCatchRule`               | none                                                                              | not applicable   | not applicable | unconditional    | unbounded | none        | occurrence |
| `code-smell.error-suppression`         | `ErrorSuppressionRule`         | none                                                                              | not applicable   | not applicable | unconditional    | unbounded | none        | occurrence |
| `code-smell.eval`                      | `EvalRule`                     | none                                                                              | not applicable   | not applicable | unconditional    | unbounded | none        | occurrence |
| `code-smell.exit`                      | `ExitRule`                     | none                                                                              | not applicable   | not applicable | unconditional    | unbounded | none        | occurrence |
| `code-smell.goto`                      | `GotoRule`                     | none                                                                              | not applicable   | not applicable | unconditional    | unbounded | none        | occurrence |
| `code-smell.superglobals`              | `SuperglobalsRule`             | none                                                                              | not applicable   | not applicable | unconditional    | unbounded | none        | occurrence |
| `code-smell.identical-subexpression`   | `IdenticalSubExpressionRule`   | none                                                                              | not applicable   | not applicable | unconditional    | unbounded | none        | occurrence |
| `code-smell.unused-private`            | `UnusedPrivateRule`            | none                                                                              | not applicable   | not applicable | unconditional    | unbounded | count       | occurrence |
| `code-smell.constructor-overinjection` | `ConstructorOverinjectionRule` | configured tiers                                                                  | inclusive (`>=`) | higher         | single threshold | unbounded | count       | symbol     |
| `code-smell.long-parameter-list`       | `LongParameterListRule`        | symbol-dependent (`voWarning`/`voError` when `CODE_SMELL_IS_VO_CONSTRUCTOR == 1`) | inclusive (`>=`) | higher         | single threshold | unbounded | count       | symbol     |
| `code-smell.unreachable-code`          | `UnreachableCodeRule`          | configured tiers                                                                  | inclusive (`>=`) | higher         | single threshold | unbounded | count       | symbol     |

`AbstractCodeSmellRule` (not a channel): shared `analyze()` loop over
`codeSmell.{SMELL_TYPE}` metric entries; always emits fixed `SEVERITY` class
constant and `metricValue: 1.0`. Nine of the eleven "none" rows above inherit
it unmodified; `IdenticalSubExpressionRule` and `UnusedPrivateRule` extend
`AbstractRule` directly instead but reach the same shape by hand.

### Complexity (6 channels / 3 rule classes) + ComputedMetric (1 mechanism row / 1 rule class)

| Channel                                                                                                                                                                                                                                                                                | Emitting class                                           | 1.Threshold source                                                          | 2.Comparison     | 3.Direction                                                                                                                                                                                     | 4.Trigger        | 5.Band    | 6.Magnitude | 7.Identity |
| -------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- | -------------------------------------------------------- | --------------------------------------------------------------------------- | ---------------- | ----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- | ---------------- | --------- | ----------- | ---------- |
| `complexity.cognitive` / `complexity.cognitive.method`                                                                                                                                                                                                                                 | `CognitiveComplexityRule::analyzeMethodLevel`            | configured tiers                                                            | inclusive (`>=`) | higher                                                                                                                                                                                          | single threshold | unbounded | scalar      | symbol     |
| `complexity.cognitive` / `complexity.cognitive.class`                                                                                                                                                                                                                                  | `CognitiveComplexityRule::analyzeClassLevel`             | configured tiers                                                            | inclusive (`>=`) | higher                                                                                                                                                                                          | single threshold | unbounded | scalar      | symbol     |
| `complexity.cyclomatic` / `complexity.cyclomatic.method`                                                                                                                                                                                                                               | `ComplexityRule::analyzeMethodLevel`                     | configured tiers                                                            | inclusive (`>=`) | higher                                                                                                                                                                                          | single threshold | unbounded | scalar      | symbol     |
| `complexity.cyclomatic` / `complexity.cyclomatic.class`                                                                                                                                                                                                                                | `ComplexityRule::analyzeClassLevel`                      | configured tiers                                                            | inclusive (`>=`) | higher                                                                                                                                                                                          | single threshold | unbounded | scalar      | symbol     |
| `complexity.npath` / `complexity.npath.method`                                                                                                                                                                                                                                         | `NpathComplexityRule::analyzeMethodLevel`                | configured tiers                                                            | inclusive (`>=`) | higher                                                                                                                                                                                          | single threshold | unbounded | scalar      | symbol     |
| `complexity.npath` / `complexity.npath.class`                                                                                                                                                                                                                                          | `NpathComplexityRule::analyzeClassLevel`                 | configured tiers                                                            | inclusive (`>=`) | higher                                                                                                                                                                                          | single threshold | unbounded | scalar      | symbol     |
| `computed.health` / `{definition->name}` — **one channel per metric definition** (built-in `health.*` from `ComputedMetricDefaults`, plus user `computed.*` from YAML `computed_metrics`), `ruleName` always the fixed constant `computed.health`, `violationCode = $definition->name` | `ComputedMetricRule::checkLevel` → `determineSeverity()` | configured tiers (fixed `warningThreshold`/`errorThreshold` per definition) | strict (`<`/`>`) | ? — per-definition `inverted` flag: all 6 built-in `health.*` dims have `inverted=true` → lower is worse; user `computed.*` default `inverted=false` → higher is worse unless YAML overrides it | single threshold | unbounded | scalar      | symbol     |

### Coupling (6 channels / 4 rule classes)

| Channel                                                   | Emitting class                           | 1.Threshold source                                                                           | 2.Comparison     | 3.Direction | 4.Trigger                                                              | 5.Band    | 6.Magnitude                       | 7.Identity |
| --------------------------------------------------------- | ---------------------------------------- | -------------------------------------------------------------------------------------------- | ---------------- | ----------- | ---------------------------------------------------------------------- | --------- | --------------------------------- | ---------- |
| `coupling.cbo` / `coupling.cbo.class`                     | `CboRule::analyzeClassLevel`             | configured tiers                                                                             | inclusive (`>=`) | higher      | single threshold                                                       | unbounded | count (`│Ca∪Ce│` set cardinality) | symbol     |
| `coupling.cbo` / `coupling.cbo.namespace`                 | `CboRule::analyzeNamespaceLevel`         | configured tiers                                                                             | inclusive (`>=`) | higher      | single threshold (gated by `minClassCount`)                            | unbounded | count                             | symbol     |
| `coupling.class-rank` / `coupling.class-rank`             | `ClassRankRule::analyze`                 | run-dependent (`computeScaleFactor(classCount) = sqrt(classCount/100)` scales warning/error) | inclusive (`>=`) | higher      | single threshold                                                       | unbounded | scalar (PageRank score)           | symbol     |
| `coupling.distance` / `coupling.distance`                 | `DistanceRule::analyze`                  | configured tiers                                                                             | inclusive (`>=`) | higher      | single threshold (gated by project-namespace filter + `minClassCount`) | unbounded | scalar (`D=│A+I-1│`, ratio 0–1)   | symbol     |
| `coupling.instability` / `coupling.instability.class`     | `InstabilityRule::analyzeClassLevel`     | configured tiers                                                                             | inclusive (`>=`) | higher      | single threshold (gated by `minAfferent`)                              | unbounded | scalar (`I=Ce/(Ca+Ce)`)           | symbol     |
| `coupling.instability` / `coupling.instability.namespace` | `InstabilityRule::analyzeNamespaceLevel` | configured tiers                                                                             | inclusive (`>=`) | higher      | single threshold (gated by `minClassCount` + `minAfferent`)            | unbounded | scalar                            | symbol     |

### Design (5 channels / 3 rule classes), Duplication (1 / 1), Maintainability (1 / 1)

| Channel                         | Emitting class                         | 1.Threshold source                                                                   | 2.Comparison                                                                    | 3.Direction                    | 4.Trigger                                                                                                                                                  | 5.Band                                      | 6.Magnitude                                      | 7.Identity                                                                          |
| ------------------------------- | -------------------------------------- | ------------------------------------------------------------------------------------ | ------------------------------------------------------------------------------- | ------------------------------ | ---------------------------------------------------------------------------------------------------------------------------------------------------------- | ------------------------------------------- | ------------------------------------------------ | ----------------------------------------------------------------------------------- |
| `design.data-class`             | `DataClassRule::evaluateClass`         | configured tiers                                                                     | inclusive (`WOC>=wocThreshold`, `WMC<=wmcThreshold`)                            | higher (reported value is WOC) | conjunction (WOC>=threshold AND WMC<=threshold)                                                                                                            | has cutoff (on the WMC axis, see Anomalies) | scalar (WOC reported as `metricValue`)           | symbol                                                                              |
| `design.god-class`              | `GodClassRule::evaluateClass`          | configured tiers                                                                     | inclusive (per-criterion; `matchedCount>=minCriteria(3)` overall)               | higher                         | criteria count (4 criteria: WMC≥47, LCOM4≥3, TCC<0.33, LOC≥300; violation iff `matchedCount>=minCriteria` of evaluable criteria)                           | unbounded                                   | count (`matchedCount` reported as `metricValue`) | symbol                                                                              |
| `design.type-coverage.param`    | `TypeCoverageRule`                     | configured tiers                                                                     | strict (`<`)                                                                    | lower                          | single threshold                                                                                                                                           | unbounded                                   | scalar (% typed)                                 | symbol                                                                              |
| `design.type-coverage.return`   | `TypeCoverageRule`                     | configured tiers                                                                     | strict (`<`)                                                                    | lower                          | single threshold                                                                                                                                           | unbounded                                   | scalar                                           | symbol                                                                              |
| `design.type-coverage.property` | `TypeCoverageRule`                     | configured tiers                                                                     | strict (`<`)                                                                    | lower                          | single threshold                                                                                                                                           | unbounded                                   | scalar                                           | symbol                                                                              |
| `duplication.code-duplication`  | `CodeDuplicationRule::createViolation` | configured tiers (`warning`/`error` line-count tiers select severity, see Anomalies) | inclusive (`>=`, in `CodeDuplicationOptions::getSeverity`)                      | higher                         | unconditional (every `DuplicateBlock` always produces a `Violation`; the threshold only picks severity tier, never gates emission — `severity ?? Warning`) | unbounded                                   | count (`metricValue = $block->lines`)            | occurrence (one file/symbol can host several independent `DuplicateBlock` findings) |
| `maintainability.index`         | `MaintainabilityRule`                  | configured tiers                                                                     | strict (`<`, "threshold is the first acceptable value for the better category") | lower                          | single threshold                                                                                                                                           | unbounded                                   | scalar                                           | symbol                                                                              |

### Security (5 channels / 5 rule classes + `AbstractSecurityPatternRule` base)

| Channel                          | Emitting class                                                      | 1.Threshold source | 2.Comparison                                                                   | 3.Direction    | 4.Trigger     | 5.Band    | 6.Magnitude | 7.Identity |
| -------------------------------- | ------------------------------------------------------------------- | ------------------ | ------------------------------------------------------------------------------ | -------------- | ------------- | --------- | ----------- | ---------- |
| `security.command-injection`     | `AbstractSecurityPatternRule::analyze` (via `CommandInjectionRule`) | none               | not applicable                                                                 | not applicable | unconditional | unbounded | none        | occurrence |
| `security.sql-injection`         | `AbstractSecurityPatternRule::analyze` (via `SqlInjectionRule`)     | none               | not applicable                                                                 | not applicable | unconditional | unbounded | none        | occurrence |
| `security.xss`                   | `AbstractSecurityPatternRule::analyze` (via `XssRule`)              | none               | not applicable                                                                 | not applicable | unconditional | unbounded | none        | occurrence |
| `security.hardcoded-credentials` | `HardcodedCredentialsRule::analyze`                                 | none               | not applicable (see Anomalies — literal operator is `>` but unreachable-false) | not applicable | unconditional | unbounded | none        | occurrence |
| `security.sensitive-parameter`   | `SensitiveParameterRule::analyze`                                   | none               | not applicable (same caveat)                                                   | not applicable | unconditional | unbounded | none        | occurrence |

`AbstractSecurityPatternRule` (not a channel): `getSeverity(): Severity` takes
no parameters — fixed constant per subclass (`Error` for command-injection /
sql-injection / xss), `metricValue: 1.0` always.

### Size (3 channels / 3 rule classes), Structure (4 channels / 4 rule classes)

| Channel                                  | Emitting class      | 1.Threshold source | 2.Comparison     | 3.Direction | 4.Trigger                                                           | 5.Band    | 6.Magnitude                       | 7.Identity              |
| ---------------------------------------- | ------------------- | ------------------ | ---------------- | ----------- | ------------------------------------------------------------------- | --------- | --------------------------------- | ----------------------- |
| `size.class-count`                       | `ClassCountRule`    | configured tiers   | inclusive (`>=`) | higher      | single threshold                                                    | unbounded | count                             | symbol (leaf namespace) |
| `size.method-count`                      | `MethodCountRule`   | configured tiers   | inclusive (`>=`) | higher      | single threshold                                                    | unbounded | count                             | symbol (class)          |
| `size.property-count`                    | `PropertyCountRule` | configured tiers   | inclusive (`>=`) | higher      | single threshold (gated by `excludeReadonly`/`excludePromotedOnly`) | unbounded | count                             | symbol (class)          |
| `design.inheritance` (DIT only — no NOC) | `InheritanceRule`   | configured tiers   | inclusive (`>=`) | higher      | single threshold                                                    | unbounded | scalar (ancestor depth)           | symbol (class)          |
| `design.lcom`                            | `LcomRule`          | configured tiers   | inclusive (`>=`) | higher      | single threshold (gated by `excludeReadonly`, `minMethods`)         | unbounded | count (connected-component tally) | symbol (class)          |
| `design.noc`                             | `NocRule`           | configured tiers   | inclusive (`>=`) | higher      | single threshold                                                    | unbounded | count                             | symbol (class)          |
| `complexity.wmc`                         | `WmcRule`           | configured tiers   | inclusive (`>=`) | higher      | single threshold (gated by `excludeDataClasses`)                    | unbounded | scalar                            | symbol (class)          |

Note: all seven of these channels call `AbstractRule::getEffectiveOptions()`
to support a per-location `@qmx-threshold` annotation override. This is the
same generic, universally-available override mechanism used by nearly every
threshold-bearing rule in the codebase and is not itself evidence of
`symbol-dependent` classification (see Anomalies) — `symbol-dependent` is
reserved for automatic, metric-driven threshold selection, of which
`LongParameterListRule` is the only confirmed instance in the whole rule set.

### Architecture (6 channels / 2 rule classes)

| Channel                                                                | Emitting class/method                                                          | 1.Threshold source                                      | 2.Comparison                                                                                        | 3.Direction                          | 4.Trigger                                                               | 5.Band                                                                       | 6.Magnitude                         | 7.Identity                                                                                                                        |
| ---------------------------------------------------------------------- | ------------------------------------------------------------------------------ | ------------------------------------------------------- | --------------------------------------------------------------------------------------------------- | ------------------------------------ | ----------------------------------------------------------------------- | ---------------------------------------------------------------------------- | ----------------------------------- | --------------------------------------------------------------------------------------------------------------------------------- |
| `architecture.circular-dependency`                                     | `CircularDependencyRule::analyze` via `CircularDependencyOptions::getSeverity` | configured tiers                                        | ? — mixed within one method (strict `>` for the cutoff, inclusive `<=` for the error-tier boundary) | higher (with caveat — see Anomalies) | single threshold                                                        | has cutoff (`cycleSize > maxCycleSize` → `null`, only when `maxCycleSize>0`) | scalar (`metricValue = cycle size`) | graph (SCC of ≥2 classes; `symbolPath` points at one representative class only)                                                   |
| `architecture.layer-violation` (`self::NAME`)                          | `LayerViolationRule::buildViolation`                                           | none (one flat configured `severity`, not tiered)       | not applicable                                                                                      | not applicable                       | conjunction (`fromMatch!==null AND toMatch!==null AND !isAllowed(...)`) | unbounded                                                                    | none                                | occurrence (real edge location, `dependency->source`)                                                                             |
| `architecture.coverage` (`COVERAGE_DIAGNOSTIC_NAME`)                   | `LayerViolationRule::buildCoverageDiagnostic`                                  | configured tiers (`mode` → `match` over `Warn`/`Error`) | not applicable                                                                                      | not applicable                       | conjunction (`mode !== Ignore AND unmatchedEnds > 0`)                   | unbounded                                                                    | none                                | ? — one aggregated project-level `Violation` per run (`SymbolPath::forProject()`), doesn't map cleanly to symbol/occurrence/graph |
| `architecture.unreachable-layer` (`UNREACHABLE_LAYER_DIAGNOSTIC_NAME`) | `LayerViolationRule::buildUnreachableLayerDiagnostics`                         | none (fixed cutoff of 0, not configurable)              | not applicable                                                                                      | not applicable                       | single threshold (`hitCount === 0`)                                     | unbounded                                                                    | none                                | occurrence (one per declared layer name)                                                                                          |
| `architecture.potential-shadow` (`POTENTIAL_SHADOW_DIAGNOSTIC_NAME`)   | `LayerViolationRule::buildPotentialShadowDiagnostics`                          | none                                                    | not applicable                                                                                      | not applicable                       | criteria count (`matchCount = count(matched layers) > 1`)               | unbounded                                                                    | none                                | graph (relationship between two layers, evidenced by a class set)                                                                 |
| `architecture.empty-template` (`EMPTY_TEMPLATE_DIAGNOSTIC_NAME`)       | `LayerViolationRule::buildEmptyTemplateDiagnostics`                            | none                                                    | not applicable                                                                                      | not applicable                       | unconditional                                                           | unbounded                                                                    | none                                | occurrence (one per empty template name)                                                                                          |

`src/Architecture/Rules/` contains exactly these two rule files (plus their
Options classes) — no other rule classes present.

## Anomalies

**ruleName ≠ emitting class's own `NAME` constant** — expected and
significant in one place: `LayerViolationRule::NAME = 'architecture.layer-violation'`,
but four of its five `new Violation(...)` call sites use separate constants
instead: `COVERAGE_DIAGNOSTIC_NAME = 'architecture.coverage'`,
`UNREACHABLE_LAYER_DIAGNOSTIC_NAME = 'architecture.unreachable-layer'`,
`POTENTIAL_SHADOW_DIAGNOSTIC_NAME = 'architecture.potential-shadow'`,
`EMPTY_TEMPLATE_DIAGNOSTIC_NAME = 'architecture.empty-template'` (all defined
at `src/Architecture/Rules/LayerViolationRule.php:91-99`). No other rule class
in the entire inventory shows this pattern — every other channel's `ruleName`
resolves to `$this->getName()` → `self::NAME`.

**`?` cells and why:**
- `architecture.circular-dependency`, Comparison: `CircularDependencyOptions::getSeverity()`
  uses strict `>` for the report-suppressing cutoff (`cycleSize > maxCycleSize`)
  and inclusive `<=` for the error-tier boundary (`cycleSize <= 2`) in the same
  method — one operator cannot represent both.
- `computed.health`, Worse direction: the mechanism serves both built-in
  `health.*` definitions (`inverted=true` uniformly, i.e. lower is worse) and
  user `computed.*` definitions (`inverted=false` by default, i.e. higher is
  worse, unless YAML sets `inverted: true`). Direction is a per-definition
  config value, not a fixed property of the channel/mechanism as a whole.
- `architecture.coverage`, Identity: exactly one `Violation` is built per
  analysis run, anchored to `SymbolPath::forProject()` (a sentinel, not a real
  symbol), summarizing a potentially large number of unmatched edges/classes
  (sample list capped at `COVERAGE_SAMPLE_LIMIT=10`, display-only, does not
  suppress emission). It is not a per-symbol fact, not a single edge
  occurrence, and not a multi-node graph relationship.

**Places where two dimension values looked equally applicable:**
- Eligibility pre-filters (`minClassCount`, `minAfferent`, `excludeReadonly`,
  `excludeDataClasses`, `excludePromotedOnly`) precede the threshold check in
  `coupling.cbo.namespace`, `coupling.instability.class/namespace`,
  `coupling.distance`, `design.lcom`, `complexity.wmc`, `size.property-count`.
  Classified uniformly as `single threshold` per the convention stated above
  the table; `conjunction` is a defensible alternative reading.
- `design.data-class`, Band: the conjunction has two axes (WOC high, WMC low)
  but only WOC is the reported `metricValue`. The cutoff (finding disappears
  once WMC grows past its threshold) lives on the *unreported* axis. Classified
  as `has cutoff` because the trigger predicate as a whole has one, but the
  reported magnitude's own axis (WOC) is itself unbounded.
- `duplication.code-duplication`, Trigger predicate vs Comparison/Direction:
  this is the one channel in the inventory where an `unconditional` trigger
  co-occurs with a real, live `configured tiers` / `inclusive` / `higher`
  severity computation. Every other `unconditional` channel in the table has
  `none`/`not applicable` for threshold source, comparison, and direction
  because their Options' `getSeverity()` is either dead code (CodeSmell) or
  functionally unreachable-false (Security, see below) — `CodeDuplicationRule`
  is the exception: its `warning`/`error` line-count tiers genuinely change
  the emitted `severity` field, just never whether emission happens at all.
- `security.hardcoded-credentials` / `security.sensitive-parameter`,
  Comparison: `HardcodedCredentialsOptions::getSeverity()` and
  `SensitiveParameterOptions::getSeverity()` literally contain
  `$value > 0 ? Severity::X : null` (a strict `>` operator, live code, reached
  via `getEffectiveSeverity()`), but the only call site already guards
  `if ($entries === []) { continue; }` before invoking it with
  `count($entries)` — so the `> 0` branch is always true in practice, and
  there is no warning/error tiering (a single fixed severity per rule).
  Classified as `not applicable` since the comparison never actually filters
  anything, but the literal operator in the Options class does say `strict`.
- `architecture.potential-shadow`, Trigger predicate: classified as
  `criteria count` by analogy to `GodClassRule` (`matchCount = count(matched
  layers) > 1`, i.e. how many independent layer-membership criteria a class
  satisfies), though structurally it is a single comparison against a fixed
  threshold of 1 rather than N independently-configurable criteria — a
  borderline call against `single threshold`.

## Counts

- **Rule files scanned:** 44 (`src/Rules/**/*Rule.php` + `src/Architecture/Rules/*Rule.php`).
- **Abstract base classes (not channels):** 3 — `AbstractRule`,
  `AbstractCodeSmellRule`, `AbstractSecurityPatternRule`.
- **Concrete rule classes:** 41.
- **Channels catalogued (table rows):** 52, one of which
  (`computed.health` / `ComputedMetricRule`) represents a dynamic
  per-metric-definition mechanism rather than a single fixed channel — at
  minimum 6 built-in `health.*` channels plus an open-ended number of
  user-defined `computed.*` channels from YAML config, none enumerable
  statically from the class alone.

**Per-dimension distribution (52 rows):**

| Dimension            | Breakdown                                                                 |
| -------------------- | ------------------------------------------------------------------------- |
| 1. Threshold source  | configured tiers 30 · none 20 · symbol-dependent 1 · run-dependent 1      |
| 2. Comparison        | inclusive 25 · not applicable 21 · strict 5 · `?` 1                       |
| 3. Worse direction   | higher 26 · not applicable 21 · lower 4 · `?` 1                           |
| 4. Trigger predicate | single threshold 29 · unconditional 18 · conjunction 3 · criteria count 2 |
| 5. Band              | unbounded 50 · has cutoff 2                                               |
| 6. Magnitude         | scalar 19 · none 20 · count 13 · vector 0                                 |
| 7. Identity          | symbol 29 · occurrence 20 · graph 2 · `?` 1                               |

Notable single instances confirming the task's canonical examples:
`symbol-dependent` threshold source occurs exactly once
(`code-smell.long-parameter-list`); `run-dependent` occurs exactly once
(`coupling.class-rank`); `has cutoff` band occurs exactly twice
(`architecture.circular-dependency`, `design.data-class`); `vector`
magnitude occurs zero times anywhere in the rule set (no channel emits a
`Violation` whose measured quantity is a vector — `metricValue` is
declared `int|float|null` throughout `Core\Violation\Violation`).

## Observations

- `LayerViolationRule`'s class docblock (`src/Architecture/Rules/LayerViolationRule.php:38-39`)
  says the rule emits "three" diagnostic channels while listing four bullets;
  the `@qmx-threshold`/`@qmx-ignore` docblock notes (lines 76-78, 84, 86) say
  "four" channels, also omitting `empty-template`; `LayerViolationOptions.php:15`
  likewise says "four diagnostic channels" while its own bullet list (lines
  16-23) has five items. The code emits five distinct `ruleName`s. None of
  the counts in comments match the actual code.
- `HardcodedCredentialsOptions::getSeverity()` and
  `SensitiveParameterOptions::getSeverity()` contain a `$value > 0 ? Severity : null`
  conditional that is unreachable-false at its only call site, given the
  upstream `if ($entries === []) { continue; }` guard.
- Several CodeSmell `Options` classes (e.g. `BooleanArgumentOptions`,
  `ErrorSuppressionOptions`, the shared `CodeSmellOptions`) define a working
  `getSeverity()` method that their owning rule never calls — severity is a
  hardcoded class constant instead, making `getSeverity()` dead code from the
  channel's perspective.
- `ComputedMetricRule::NAME = 'computed.health'` is used as `ruleName` for
  every emitted violation, including ones whose `violationCode` is a
  user-defined `computed.*` metric unrelated to the built-in health scores —
  the rule name does not reflect that the class also serves arbitrary
  user-defined computed metrics.
