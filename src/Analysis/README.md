# Analysis

`Analysis` is a navigation taxonomy. It owns no shared production type, state,
or dependency allow-list target. Its leaf capabilities define the actual
boundaries.

## Current leaves

| Leaf                                                                   | Subject                                                                   | Read first                                              |
| ---------------------------------------------------------------------- | ------------------------------------------------------------------------- | ------------------------------------------------------- |
| [`Configuration`](Configuration/README.md)                             | configuration document resolution and transitional runtime configuration  | schema, stages, and feature document contributions      |
| [`Evidence/CodeSmell`](Evidence/CodeSmell/README.md)                   | code-smell collection and rules                                           | finding groups, visitors, options, and exact DI root    |
| [`Evidence/Cohesion`](Evidence/Cohesion/README.md)                     | class cohesion evidence and rules                                         | TCC/LCC, LCOM, and unused-private semantics             |
| [`Evidence/Complexity`](Evidence/Complexity/README.md)                 | cyclomatic, cognitive, NPath, and WMC evidence and rules                  | callable/class aggregation and thresholds               |
| [`Evidence/Coupling`](Evidence/Coupling/README.md)                     | coupling evidence, rules, and run configuration                           | framework-namespace parsing and one-run state           |
| [`Evidence/DependencyModel`](Evidence/DependencyModel/README.md)       | dependency evidence, graph construction, and extraction                   | public graph/traversal contracts                        |
| [`Evidence/Design`](Evidence/Design/README.md)                         | inheritance and design evidence and rules                                 | DIT, NOC, data-class, god-class, and coverage semantics |
| [`Evidence/Duplication`](Evidence/Duplication/README.md)               | file-set duplication evidence and one-run result                          | Run-port implementation and result lifecycle            |
| [`Evidence/CircularDependency`](Evidence/CircularDependency/README.md) | SCC evidence, cycle values, rule, and one-run preparation                 | preparation contract and reset semantics                |
| [`Evidence/Measurement`](Evidence/Measurement/README.md)               | metric facts, repository, namespace attribution, and aggregation          | metric/repository contracts and worker reconstruction   |
| [`Evidence/ComputedMetrics`](Evidence/ComputedMetrics/README.md)       | formula-defined metrics, instance-owned definitions, and Health semantics | lifecycle, evaluation contract, and Health contracts    |
| [`Evidence/Maintainability`](Evidence/Maintainability/README.md)       | Halstead and maintainability evidence and rules                           | formula semantics and exact DI root                     |
| [`Evidence/Prioritization`](Evidence/Prioritization/README.md)         | finding impact ranking and technical-debt evidence                        | ranking and debt contracts                              |
| [`Evidence/Security`](Evidence/Security/README.md)                     | security evidence and rules                                               | pattern detectors, findings, options, and visitors      |
| [`Evidence/Size`](Evidence/Size/README.md)                             | size evidence and rules                                                   | LOC and member-count collection and thresholds          |
| [`Finding`](Finding/README.md)                                         | rule language, execution, finding values, and filtering primitives        | public contracts and runtime-state ownership            |
| [`Policy/Architecture`](Policy/Architecture/README.md)                 | declared-layer policy, preparation, and debug projection                  | contracts and internal-zone topology                    |
| [`Policy/Baseline`](Policy/Baseline/README.md)                         | accepted-finding ceiling lifecycle                                        | file contract and fail-safe application                 |
| [`Policy/Inline`](Policy/Inline/README.md)                             | source annotation extraction and suppression controls                     | extraction and suppression contracts                    |
| [`Run`](Run/README.md)                                                 | discovery, collection, phase ordering, coverage, and run results          | Pipeline and FileSet inspection contracts               |

P1-P7 have published their accepted capability boundaries. P7 distributed the
former Metrics and Rules role buckets among the eight evidence leaves listed
above, and its final aggregate validation and independent review returned GO.
Architecture, Baseline, and Inline are current Policy leaves; P8 remains
pending as the next phase.

## Current execution flow

```text
Configuration resolution
  -> Run discovery
  -> Run collection (the only parallel phase)
  -> DependencyModel graph build
  -> Architecture policy preparation
  -> Measurement aggregation and ComputedMetrics evaluation
  -> CircularDependency preparation
  -> rule execution
  -> reporting adapters
```

Run sequencing never creates a generic capability registry or transports
capability result payloads. Its only P3 participant port is file-set inspection:
a capability receives the eligible file set and keeps its result privately.
Dependency traversal is separately a DependencyModel promise, not a Run port.

## Reading and test conventions

Read the leaf README before changing a type beneath it. Tests follow the same
subject-first layout under `tests/Analysis/`; a moved production capability moves
its owned tests, fixtures, and support with it. External tests remain with their
semantic owner even when P3 rewrites their imports.

## Definition of Done

- No type is added directly to `Analysis`, `Analysis\\Evidence`, or
  `Analysis\\Policy`.
- Cross-leaf imports use a declared contract; a taxonomy namespace is never an
  allow-list target.
- A phase change documents its input, output, state owner, and direct tests in
  the owning leaf README.
