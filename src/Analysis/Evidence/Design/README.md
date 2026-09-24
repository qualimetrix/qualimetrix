# Design

## Subject and boundary

`Analysis\Evidence\Design` owns evidence and policy for class design:
type declaration coverage, inheritance depth (DIT), number of direct children
(NOC), data-class detection, and god-class detection. Its one public surface is
`Inheritance/Contract/`, promised to the composer adapter in
`Infrastructure\Composer` so that a chain leaving the analysed path can be
followed by reading (ADR 0074). Nothing else here is published.

Collectors consume Measurement's existing collection and repository contracts.
`DitGlobalCollector` and `NocCollector` also consume DependencyModel's public
graph contracts. Rules consume Finding's execution, option, channel, and
finding contracts. No consumer imports a Design collector or rule as a
cross-capability contract.

## Structure

```text
Design/
├── DataClass/
│   ├── DataClassExclusionCheck.php
│   ├── DataClassOptions.php
│   └── DataClassRule.php
├── GodClass/
│   ├── GodClassCriteriaEvaluator.php
│   ├── GodClassCriterionResult.php
│   ├── GodClassOptions.php
│   └── GodClassRule.php
├── Inheritance/
│   ├── Contract/
│   │   ├── ExternalParentSourceInterface.php
│   │   └── ParentLookup.php
│   ├── DitGlobalCollector.php
│   ├── ExternalAncestry.php
│   ├── ExternalChainOutcome.php
│   ├── ExternalDepth.php
│   ├── InheritanceClassInfo.php
│   ├── InheritanceDepthCollector.php
│   ├── InheritanceDepthResolver.php
│   ├── InheritanceDepthVisitor.php
│   ├── InheritanceOptions.php
│   ├── InheritanceRule.php
│   ├── NocCollector.php
│   ├── NocOptions.php
│   ├── NocRule.php
│   └── UnreadChainTally.php
└── TypeCoverage/
    ├── AbstractTypeCoverageRule.php
    ├── ParamTypeCoverageRule.php
    ├── PropertyTypeCoverageRule.php
    ├── ReturnTypeCoverageRule.php
    ├── TypeCoverageCollector.php
    ├── TypeCoverageOptions.php
    ├── TypeCoveragePercentCollector.php
    └── TypeCoverageVisitor.php
```

Four subject folders, one per design property judged: declaration typing,
inheritance shape, data-class shape, god-class shape. Each holds its own
collectors, visitor, options and rules; there are no cross-family imports and
no shared type, which is why the root carries no production code at all. `Noc`
lives in `Inheritance/` because DIT and NOC measure the same inheritance tree
from opposite ends, and NOC contributes one collector with no visitor of its
own. Do not recreate `Metrics/`, `Rules/`, or a generic helper subdirectory
inside any of them, and do not put a type back in the root: a type that would
belong to no family is the signal that a fifth family is being named.

Because they read one tree, DIT and NOC are measured on one population: the
named classes the per-file pass measured. `NocCollector` recognises them by the
`design.dit` that pass left on them, so an interface, a trait or an enum gets
no `design.noc` (not 0), and `interface B extends A` is not a subclass of `A`
(the edge carries `Dependency::$interfaceExtends`). Otherwise every NOC
aggregate would divide by a larger count than its DIT neighbour.

`Inheritance/Contract/` is the one public surface here. It exists because
following a chain out of the analysed path needs a file placed and read, which
is delivery: the port is promised to the composer adapter in
`Infrastructure\Composer`, and this capability imports neither a composer type
nor a parser (ADR 0074).

`ExternalAncestry` decides what counts as a depth and how a chain ended;
`UnreadChainTally` collects those endings for one `calculate()` call so the
collector can say once what the run could not read. The tally is deliberately
not a service: it lives and dies inside the call, which is why nothing resets
it.

## Behaviour and lifecycle

- `TypeCoverageCollector` and `TypeCoverageVisitor` collect parameter, return,
  and property declaration counts and coverage percentages for named
  class-like declarations. `TypeCoveragePercentCollector` derives the combined
  percentage from those raw counts.
- `InheritanceDepthCollector` provides per-file DIT evidence. Its visitor
  resolves local and imported parents; `DitGlobalCollector` recalculates DIT
  through `DependencyGraphInterface` so cross-file inheritance stays correct.
- The external half of a chain is followed by `ExternalAncestry`, which counts
  depth and decides where a chain ends. It reads through
  `Contract\ExternalParentSourceInterface`; placing a class and parsing its
  declaration are delivery and live in `Infrastructure\Composer` (ADR 0074).
  Nothing here loads a class, which is what stopped the tool from executing the
  code it measures.
- A chain ends three ways -- it reaches a root, finds no install to read, or
  breaks partway -- and `ExternalDepth` keeps them apart even though the metric
  publishes one number.
- DIT's `MetricDefinition` belongs to `DitGlobalCollector`, not to the per-file
  collector, because re-aggregation runs over the definitions the global
  collectors declare (ADR 0069). The per-file pass still decides DIT's
  population: `DitGlobalCollector` corrects the depth of symbols that already
  carry a per-file `design.dit` and leaves the rest alone, which is what keeps
  interfaces, traits and enums out of the metric and its aggregates. That makes
  the global pass depend on the file pass's keys, which `requires()` cannot
  express — it orders global collectors against each other. Removing the
  per-file write would empty DIT rather than fail.
- `InheritanceDepthResolver` owns the walk: it indexes the graph's `extends`
  edges by declaration and by name, and answers the depth of one declaration.
  It was split out of `DitGlobalCollector`, which the product's own god-class
  rule flagged once the walk grew a second index — the collector now keeps the
  protocol and the repository pass, and the campaign that replaces external
  ancestry has one class to replace instead of a method inside a collector.
- `DitGlobalCollector` resolves and writes a depth per class **declaration**,
  and only then writes one value per name onto the logical class — the maximum
  over that name's declarations. One name can be declared in two files with two
  different parents, and the name-keyed map it used before let the file read
  last decide for all of them. `InheritanceRule` reads the declaration subject
  it iterates, because the logical projection cannot hold two answers
  (ADR 0073).
- `NocCollector` derives direct-child counts from the same DependencyModel
  graph and retains its collector name, definitions, ordering, and aggregation
  semantics. It counts distinct child **names**: a subclass declared in two
  files is one subclass, and the parent side is a name in any case, since
  `extends` does not say which file declared the parent.
- Both global collectors skip a `Dependency` flagged
  `describesNestedAnonymousClass`: an anonymous class's own `extends` edge is
  recorded with the enclosing named class as source (it has no declaration
  identity of its own to attach to), so counting it would give the enclosing
  class a parent, and the parent a child, neither has (ADR 0071).
- `ParamTypeCoverageRule`, `ReturnTypeCoverageRule` and
  `PropertyTypeCoverageRule` judge one dimension each, one channel each, and
  share `AbstractTypeCoverageRule` for the walk and the emission plus one
  `TypeCoverageOptions` implementation for the shape of their configuration.
  Configuration is keyed by producer rule name, never by Options class, so
  sharing the class does not share the configured instance. They are registered
  by name in `DesignConfigurator`, in the order `param, return, property`,
  because channel order is published in a "did you mean" tie-break and would
  otherwise be decided by alphabetical filenames.
- `DataClassRule`, `GodClassRule`, `InheritanceRule`, and `NocRule` retain their
  IDs, options, and CLI aliases. All rules here read precomputed Measurement
  facts and never traverse an AST.
- `DataClassRule` gates on a **low** WOC: the share of the public interface
  that carries behaviour rather than data access. Its finding channel is
  therefore `WorseDirection::Lower`, and both `@qmx-threshold` axes are upper
  bounds. What counts as data access is decided by method name in
  `Size\MethodCountVisitor` — `get*`/`is*`/`has*`/`set*` — and by public
  property declarations; **a method body is never read**. A public method that
  merely forwards to a collaborator is behaviour for this rule, and an
  `is*`/`has*` predicate that computes its answer is data access: WOC measures
  the shape of the interface, not the weight of the bodies behind it. The
  constructor counts on neither side of the ratio. Classes with no public
  members at all score 100 and are never flagged, and the size floor
  (`minMembers`) counts declared methods plus declared properties so a struct
  of public fields stays in reach. Traits are in the population; only
  interfaces, abstract classes, exceptions and property-less classes are
  excluded.
- Per-file visitors implement Measurement reset semantics. Global collectors
  are stateless across runs; the worker wire payload remains Measurement-owned.

## Tests

Owned test code is under `tests/Analysis/Evidence/Design/`:

```text
Fixtures/
└── DataClass/
    ├── ReadonlyDto.php
    └── SmallClass.php
Integration/
├── DataClass/
│   └── DataClassDetectionTest.php
└── Inheritance/
    ├── AnonymousClassDeclarationEdgeRunTest.php
    ├── DitAggregateRunTest.php
    ├── DuplicateDeclarationDepthRunTest.php
    ├── UnloadableExternalParentRunTest.php
    └── UnreadAncestryDiagnosticRunTest.php
Unit/
├── DataClass/
│   └── DataClassRuleTest.php
├── GodClass/
│   └── GodClassRuleTest.php
├── Inheritance/
│   ├── DitGlobalCollectorTest.php
│   ├── InheritanceDepthCollectorTest.php
│   ├── InheritanceDepthUseAliasTest.php
│   ├── InheritanceRuleTest.php
│   ├── NocCollectorTest.php
│   └── NocRuleTest.php
└── TypeCoverage/
    ├── TypeCoverageCollectorTest.php
    ├── TypeCoverageOptionsTest.php
    ├── TypeCoveragePercentCollectorTest.php
    └── TypeCoverageRuleTest.php
```

Run the owned suite with:

```bash
vendor/bin/phpunit --no-coverage --do-not-cache-result tests/Analysis/Evidence/Design
```

`DataClassDetectionTest` drives the rule from PHP source instead of a
hand-written metric bag: the unit suite can only assert what the rule does with
a WOC number, never what that number means, which is how an inverted WOC
survived it. The tests cover type-coverage
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
