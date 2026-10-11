# 0115. Defined distance and type-weighted project fold

**Date:** 2026-10-11
**Status:** Proposed — awaiting integration and actual calibration receipts

## Context

[ADR 0080](0080-a-project-fold-reads-a-partition-not-the-leaves.md) established own namespace scopes so classes declared by a parent namespace are not lost or counted twice. The current fold still gives one measured namespace the same weight regardless of how many eligible types it declares. A disconnected scope with Ca = Ce = 0 has instability zero by convention; turning that convention into D = 1 describes a worst distance without dependency evidence. Abstractness has the same definition problem when its eligible type denominator is empty.


## Decision

`coupling.abstractness` and `coupling.abstractness-own` are absent when their own eligible-type denominator is empty. `coupling.distance` and `coupling.distance-own` are absent when their required inputs are absent or the corresponding Ca and Ce are both zero. This is not a measured zero. The Distance rule judges only a published own-scope distance and keeps its minimum own-type population gate; its separate edge-free guard is unnecessary after publication is defined this way.

The project publisher computes `coupling.distance-own.avg` directly as the mean of measured own distances weighted by each scope's eligible type count: named classes + traits + interfaces + explicitly implementing enums. Bare enums are excluded. `coupling.distance-own.count` counts measured namespace samples, not the sum of their weights. Unmeasured scopes contribute neither distance zero nor weight; if there is no sample, the project values are absent. Subtree `coupling.distance` has no project fold.

This extends ADR 0080's own-scope partition. A declaration belongs to exactly one own scope; the weighting keeps that partition while expressing the amount of code each measured scope represents. The independently recomputed weighted identity is checked to tolerance 1e-6. **Awaiting actual calibration receipts:** the independent C4 witness over the integrated candidate, including the measured namespace population and weights.

## Consequences and migration

- A namespace or project with no applicable distance now reports `not-measured` or `not-applicable` through the existing Health coverage model, as appropriate to its population. CodeIgniter's disconnected project distance must be absent in the candidate capture, not zero.
- Recompute saved `coupling.distance-own.avg`, its `.count`, and dependent health scores. Do not substitute zero for an absent distance in consumers.
- Re-evaluate own-scope Distance findings and accepted limits. The rule's type floor is unchanged; its edge-free guard no longer defines the metric's domain.
- **Awaiting actual integration receipts:** own/subtree definedness tests, captured weighted distance, coverage and self-analysis. Acceptance requires actual results.
