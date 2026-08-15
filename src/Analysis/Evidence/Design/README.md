# Design

## Subject and boundary

`Analysis\Evidence\Design` owns evidence and policy for class design:
type declaration coverage, inheritance depth (DIT), number of direct children
(NOC), data-class detection, and god-class detection. It is a private leaf
capability: it publishes no `Contract/` namespace.

Collectors consume Measurement's existing collection and repository contracts.
`DitGlobalCollector` and `NocCollector` also consume DependencyModel's public
graph contracts. Rules consume Finding's execution, option, channel, and
violation contracts. No consumer imports a Design collector or rule as a
cross-capability contract.

## Structure

```text
Design/
├── TypeCoverageCollector.php
├── TypeCoveragePercentCollector.php
├── TypeCoverageVisitor.php
├── DitGlobalCollector.php
├── InheritanceClassInfo.php
├── InheritanceDepthCollector.php
├── InheritanceDepthVisitor.php
├── NocCollector.php
├── DataClassExclusionCheck.php
├── DataClassOptions.php
├── DataClassRule.php
├── GodClassCriteriaEvaluator.php
├── GodClassCriterionResult.php
├── GodClassOptions.php
├── GodClassRule.php
├── TypeCoverageOptions.php
├── TypeCoverageRule.php
├── InheritanceOptions.php
├── InheritanceRule.php
├── NocOptions.php
└── NocRule.php
```

The flat layout is intentional: all files participate in one class-design
evidence lifecycle. Do not recreate `Metrics/`, `Rules/`, or a generic helper
subdirectory inside this leaf.

## Behaviour and lifecycle

- `TypeCoverageCollector` and `TypeCoverageVisitor` collect parameter, return,
  and property declaration counts and coverage percentages for named
  class-like declarations. `TypeCoveragePercentCollector` derives the combined
  percentage from those raw counts.
- `InheritanceDepthCollector` provides per-file DIT evidence. Its visitor
  resolves local and imported parents; `DitGlobalCollector` recalculates DIT
  through `DependencyGraphInterface` so cross-file inheritance and external
  PHP parent fallback remain correct.
- `NocCollector` derives direct-child counts from the same DependencyModel
  graph. Both global collectors retain their existing collector names,
  definitions, ordering, and aggregation semantics.
- `DataClassRule`, `GodClassRule`, `TypeCoverageRule`, `InheritanceRule`, and
  `NocRule` retain their IDs, options, CLI aliases, thresholds, finding
  channels, and output semantics. They read precomputed Measurement facts and
  never traverse an AST.
- Per-file visitors implement Measurement reset semantics. Global collectors
  are stateless across runs; the worker wire payload remains Measurement-owned.

## Tests

Owned test code is under `tests/Analysis/Evidence/Design/`:

```text
Fixtures/
├── ReadonlyDto.php
└── SmallClass.php
Unit/
├── DataClassRuleTest.php
├── DitGlobalCollectorTest.php
├── GodClassRuleTest.php
├── InheritanceDepthCollectorTest.php
├── InheritanceDepthUseAliasTest.php
├── InheritanceRuleTest.php
├── NocCollectorTest.php
├── NocRuleTest.php
├── TypeCoverageCollectorTest.php
├── TypeCoverageOptionsTest.php
├── TypeCoveragePercentCollectorTest.php
├── TypeCoverageRuleTest.php
└── TypeCoverageScaleTest.php
```

Run the owned suite with:

```bash
vendor/bin/phpunit --no-coverage --do-not-cache-result tests/Analysis/Evidence/Design
```

The tests cover all 235 discovered PHPUnit IDs, including type-coverage
projection and scale, data/god-class criteria, local/imported/external
inheritance fallback, DIT/NOC global graph behavior, thresholds, and finding
identity. Shared container, worker, and cross-capability integration tests stay
with their owning integration subjects.

## Change recipe

For a Design metric or rule change, update this leaf's implementation and
owned tests together; keep cross-owner interactions on the existing
Measurement, DependencyModel, and Finding contracts. Update the two Design
website pages when user-visible metric or rule behaviour changes. Adding a
new external consumer requires an explicit, narrow contract decision rather
than exposing a concrete collector or rule.


## Locality

This README is part of the subject boundary: keep its production code, tests, fixtures, support, and documentation with the named owner. External consumers use declared contracts only; mutable runtime state has one owner, reset point, and typed readers. Composition-only access to a private declaration requires a reviewed exact binding, not a generic qmx permission.
