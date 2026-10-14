# 0115. Defined distance and type-weighted project fold

**Date:** 2026-10-11
**Status:** Accepted

## Context

[ADR 0080](0080-a-project-fold-reads-a-partition-not-the-leaves.md) established own namespace scopes so classes declared by a parent namespace are not lost or counted twice. Before this change, the project fold gave one measured namespace the same weight regardless of how many eligible types it declared. A disconnected scope with Ca = Ce = 0 has instability zero by convention; turning that convention into D = 1 describes a worst distance without dependency evidence. Abstractness has the same definition problem when its eligible type denominator is empty.


## Decision

`coupling.abstractness` and `coupling.abstractness-own` are absent when their own eligible-type denominator is empty. `coupling.distance` and `coupling.distance-own` are absent when their required inputs are absent or the corresponding Ca and Ce are both zero. This is not a measured zero. The Distance rule judges only a published own-scope distance and keeps its minimum own-type population gate; its separate edge-free guard is unnecessary after publication is defined this way.

The project publisher computes `coupling.distance-own.avg` directly as the mean of measured own distances weighted by each scope's eligible type count: named classes + traits + interfaces + explicitly implementing enums. Bare enums are excluded. `coupling.distance-own.count` counts measured namespace samples, not the sum of their weights. Unmeasured scopes contribute neither distance zero nor weight; if there is no sample, the project values are absent. Subtree `coupling.distance` has no project fold.

This extends ADR 0080's own-scope partition. A declaration belongs to exactly one own scope; the weighting keeps that partition while expressing the amount of code each measured scope represents. The independently recomputed weighted identity is checked to tolerance 1e-6. The completed selected-candidate check covers all 17 projects: 16 have a measured, discriminating pool and 32 dependent coupling/overall scores are judged. All printed score differences are 0.00000000 at tolerance 1e-6. A separate direct calculation from captured own-scope distances and the four published type-count keys reproduces every measured project average; its largest absolute difference is 5.551115123125783e-17. It calls no product fold. CodeIgniter has no sample and no published average or count. [ADR 0116](0116-measured-health-score-recalibration.md#capture-and-runtime-provenance) records sources, versions, capture hashes and commands.

### Captured weighted identity

The weights below are the sums of `size.class-count`, `size.trait-count`,
`size.interface-count` and `size.implementing-enum-count` in measured own scopes.
The sample column counts namespaces; it is not the type-weight sum. A zero in
CodeIgniter's descriptive sample/weight columns does not publish a metric zero.

| Project                   | Measured own scopes | Type-weight sum | Published weighted distance | Independent absolute difference |
| ------------------------- | ------------------- | --------------- | --------------------------- | ------------------------------- |
| `codeigniter`             | 0                   | 0               | absent                      | not applicable                  |
| `composer`                | 37                  | 308             | 0.311191302                 | 0                               |
| `doctrine-dbal`           | 82                  | 419             | 0.460480273                 | 0                               |
| `doctrine-orm`            | 46                  | 471             | 0.386723418                 | 0                               |
| `flysystem`               | 3                   | 55              | 0.373636364                 | 0                               |
| `guzzle`                  | 5                   | 70              | 0.228603945                 | 0                               |
| `laravel-framework`       | 219                 | 1596            | 0.305861242                 | 0                               |
| `monolog`                 | 9                   | 122             | 0.262458383                 | 0                               |
| `php-parser`              | 20                  | 271             | 0.576311556                 | 0                               |
| `phpunit`                 | 66                  | 1137            | 0.365462686                 | 5.55e-17                        |
| `qmx`                     | 194                 | 1347            | 0.277128960                 | 5.55e-17                        |
| `symfony-console`         | 32                  | 172             | 0.350612215                 | 0                               |
| `symfony-di`              | 15                  | 217             | 0.316215355                 | 0                               |
| `symfony-http-foundation` | 13                  | 116             | 0.353231138                 | 0                               |
| `symfony-http-kernel`     | 19                  | 177             | 0.343780150                 | 5.55e-17                        |
| `symfony-routing`         | 12                  | 71              | 0.370674154                 | 0                               |
| `wordpress`               | 25                  | 595             | 0.331709908                 | 5.55e-17                        |

## Consequences and migration

- A namespace or project with no applicable distance now reports `not-measured` or `not-applicable` through the existing Health coverage model, as appropriate to its population. CodeIgniter's disconnected project distance is absent in the completed capture; its project average and count are absent too.
- Recompute saved `coupling.distance-own.avg`, its `.count`, and dependent health scores. Do not substitute zero for an absent distance in consumers.
- Re-evaluate own-scope Distance findings and accepted limits. The rule's type floor is unchanged; its edge-free guard no longer defines the metric's domain.
- Own/subtree definedness and weighted-fold regressions are included in the passed scoped evidence suite (958 tests, 3,238 assertions). The complete captures and independent identities above record actual publication and score coverage. The semantic decision is accepted on these receipts; full validation, gate, fresh review and CI remain separate pending stage checks.
