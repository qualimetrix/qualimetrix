# Qualimetrix modular architecture refactoring plan

## Outcome and scope

Replace the current hybrid rule (vertical only for “large” features, horizontal role buckets for “thin” checks) with a capability-oriented modular monolith. Keep a small analysis-run kernel and delivery infrastructure, but make each independently evolving check capability own its configuration, preparation, state, and rules. A capability exposes a stable external contract only when a named external consumer exists.

Use `Analysis`, `Evidence`, and `Policy` as navigation taxonomies that make the product model visible in namespaces. They contain no production types, state, shared contracts or qmx layers; they never grant wildcard sibling access. Architectural boundaries remain the leaf modules.

This plan deliberately migrates the proven scattered capabilities first. Thin metric/rule categories are inventoried and migrated only after their actual ownership is classified; no empty `Domain/Configuration/Processing/Rules` skeleton is required.


## Current status and reading guide

P0–P8 are completed and independently reviewed; final P8 authority records 789
declarations / 787 files, 37 owners, zero seams, 64 exact permanent composition
bindings collapsing to 13 owner pairs, 227 qmx allows, 659 artifacts, 102 fixture
directories, 518 PHPUnit classes / 7,036 semantic IDs, and a 269-group /
203-subject baseline. P6 closed at 754 declarations
/ 752 files, 37 owners, zero seams, 51 exact grants collapsing to 8 owner
pairs, and 223 qmx allows; generated discovery contains 509 PHPUnit classes /
7,251 semantic IDs. Final host `composer check` exited 0: 7,251 tests / 23,654
assertions / one skip, 17 Python tests, PHPStan over 1,280 files, 692 artifacts,
107 fixture directories, and workers=0 dogfood over 752 files with active 0 /
stale 0. `native-codex-01` implemented the minimal Finding-owned
`RuleDefinitionInterface` and passed independent address-check; `native-codex-02`
closed the expanded current-document sweep; and `native-codex-03` closed the
anchored lock-file gate. The three-entry, offset-only baseline rekey preserved
the 208-entry cardinality and payloads. At that P6 closure no commit or push was
performed; HEAD was `57fa22fa0d0f074cb11590e358fc01faff3eccf1` on
`codex/modular-architecture-p3`. P7 subsequently removed the former Metrics and
Rules role buckets and completed its implementation, final aggregate
validation, and independent review at 762 declarations / 760 files, 37 owners,
50 exact grants collapsing to 7 owner pairs, 224 qmx allows, and 7,254 generated
PHPUnit cases. P8 completes the migration; no migration phase or package remains pending.

Read the documents by concern rather than loading the historical record as one context:

| Concern                                                                               | Canonical record                                                                                   |
| ------------------------------------------------------------------------------------- | -------------------------------------------------------------------------------------------------- |
| Cross-phase decisions, target topology, P0 design evidence, and superseded hypotheses | [Decisions and target](modular-architecture/decisions-and-target.md)                               |
| P0 governance                                                                         | [P0 governance](modular-architecture/p0-governance.md)                                             |
| P1 Duplication                                                                        | [P1 Duplication](modular-architecture/p1-duplication.md)                                           |
| P2 DependencyModel and GraphProjection                                                | [P2 DependencyModel](modular-architecture/p2-dependency-model.md)                                  |
| P3 Run, Measurement, and Configuration                                                | [P3 Run, Measurement, and Configuration](modular-architecture/p3-run-measurement-configuration.md) |
| Completed P4 Architecture policy and CircularDependency work                          | [P4 Architecture policy](modular-architecture/p4-architecture-policy.md)                           |
| Completed P5 ComputedMetrics and Health remediation and republish                     | [P5 ComputedMetrics and Health](modular-architecture/p5-computed-metrics.md)                       |
| Completed P6 Finding/Policy record and final closure evidence                         | [P6 Finding and Policy](modular-architecture/p6-finding-policy.md)                                 |
| Completed P7 and P8, dependency graph, regression matrix, and non-goals               | [P5–P8 roadmap](modular-architecture/roadmap-p5-p8.md)                                             |

## Work packages

The records above preserve the complete package definitions, evidence,
inventories, closure notes, and definitions of done. The execution summary is
P0 → P1 → P2 → P3 → P4 → P5-F2.1 → P5-F3 → P5-F4 → P5-F4.1 → P5-F4.2 →
P5-F4.3 → P5-F4.4 → P5-G → P5 aggregate/review → P6 → P7 → P8. P5 is
complete after three review findings were fixed, the independent reviewer
returned GO and the post-review aggregate passed. P6 is complete after its
three review findings were closed, the independent address-check returned GO,
and the final host aggregate passed. P7 is complete after its implementation,
final aggregate validation, and independent review returned GO. P8 is complete;
no migration phase or package remains pending.
Detailed package
dependencies and all edge-case/non-goal
statements remain in the [P5–P8 roadmap](modular-architecture/roadmap-p5-p8.md).

## Stable references

The accepted direction remains [ADR 0022](../../adr/0022-capability-oriented-modular-monolith.md). This overview is the stable canonical entry point; phase-specific links above replace the former single large plan body without retaining a duplicate compatibility copy.
