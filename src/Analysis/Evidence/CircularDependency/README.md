# Circular-dependency evidence

`Analysis\\Evidence\\CircularDependency` owns the SCC detector, immutable cycle
values, `architecture.circular-dependency`, and its per-run prepared result.
It is independent from declared-layer policy, which lives in
[`Analysis\\Policy\\Architecture`](../../Policy/Architecture/README.md).

## Boundary and lifecycle

`CircularDependencyPreparationInterface` is the sole public preparation
contract. `Analysis\\Run` invokes it after graph construction with the rule's
enabled state. `CircularDependencyAnalysis` clears the previous result before
every invocation; disabled preparation performs no SCC work and exposes no
previous cycle result.

`CircularDependencyRule` reads the same leaf-owned analysis service. Cycle
results never enter `AnalysisContext`, transitional enrichment, parallel-worker
payloads, serialization, or cache entries. `prepare()` is the analysis's only
source of cycles; tests hand it exact cycles through a detector double, never
through a setter on the product class.

`CircularDependencyDetector` walks the graph with Tarjan's algorithm over an
explicit stack of frames, not the call stack: the walk is as deep as the
longest dependency chain, and a recursive walk hit Xdebug's nesting limit on a
chain of a few hundred classes. The displayed cycle path is a breadth-first
search that keeps each node's predecessor rather than a copy of its path, so a
long cycle is found in linear rather than quadratic time. Neither change moves
the output: members, representative, path and order are what they were.

## Layout

```text
CircularDependency/
├── Contract/CircularDependencyPreparationInterface.php
├── CircularDependencyAnalysis.php
├── CircularDependencyDetector.php
├── Cycle.php
├── CycleMemberLabels.php
├── CircularDependencyOptions.php
└── CircularDependencyRule.php
```

Only the preparation contract is available outside this leaf. Console and DI
adapters compose it through that contract; no sibling imports detector or
prepared-state internals.

## Definition of Done

- Preserve canonical cycle identity, severity, recommendation, and rule-option
  behavior.
- Test enabled/disabled replacement across sequential runs.
- Keep SCC preparation in the main process after collection and outside worker
  payloads.


## Locality

This README is part of the subject boundary: keep its production code, tests, fixtures, support, and documentation with the named owner. External consumers use declared contracts only; mutable runtime state has one owner, reset point, and typed readers. Composition-only access to a private declaration requires a reviewed exact binding, not a generic qmx permission.
