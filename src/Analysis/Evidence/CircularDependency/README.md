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
payloads, serialization, or cache entries.

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
