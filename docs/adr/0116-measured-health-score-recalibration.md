# 0116. Measured health-score recalibration

**Date:** 2026-10-11
**Status:** Proposed — awaiting integration and actual calibration receipts

## Context

The cohesion graph and method population changed; project distance now measures only applicable own scopes and weights eligible types. Project coupling still reads bidirectional CBO, mixing incoming dependency magnets with outgoing work. Formula and benchmark values cannot be carried forward as if the input population had stayed fixed. Earlier pilot measurements used a different captured corpus and preceded the present applicability and evaluation contract; their numbers are historical, not candidate verdicts.

**Awaiting actual calibration receipts:** exact integrated candidate commit, the BEFORE and AFTER captures for all 17 projects, tool versions, capture dates and repository identities. The 17-project BEFORE capture exists; AFTER and final candidate evaluation have not completed. No current-candidate verdict follows from the historical corpus.

## Decision

The built-in class cohesion formula uses `cohesion.tcc-method-count` for the purity fraction. The namespace cohesion LCOM term uses **[awaiting selected calibrated span]**. Project coupling uses only outgoing signals in the selected outgoing-only form: `coupling.ce-packages.avg`, square-root-scaled `coupling.ce.avg`, `coupling.ce.max`, and applicable `coupling.distance-own.avg`. Coefficients are **[awaiting selected distance and breadth weights]**, derived from the captured candidate. Update the decomposition catalog with the same actual inputs and labels, and remove dead or incoming-only terms. Preserve nullable evaluation and the policy that missing inputs are not invented as zeros.

The existing `scripts/health-calibration.php` evaluates through `ComputedMetricSubjectEvaluation` and uses the `declaring:types` project-distance scheme. Before searching candidates, its self-test must pass on the candidate capture. Record the exact invocation and result here. The final candidate and all verdicts must come from one capture, with before/after values traceable to each of the 17 named projects.

| Criterion | Population and meaning                                                                                                                   | Candidate result and evidence           |
| --------- | ---------------------------------------------------------------------------------------------------------------------------------------- | --------------------------------------- |
| C1        | Full applicable parent/child population; one-sided parent score <= maximum contained child score, using symbol containment               | **[awaiting actual candidate result]**  |
| C2        | **[awaiting exact retained criterion contract]**                                                                                         | **[awaiting actual candidate result]**  |
| C3        | **[awaiting exact retained criterion contract]**                                                                                         | **[awaiting actual candidate result]**  |
| C4        | Independent eligible-type-weighted distance identity, tolerance 1e-6; do not reuse the product fold as the oracle                        | **[awaiting actual candidate result]**  |
| C5        | **[awaiting exact retained criterion contract]**                                                                                         | **[awaiting actual candidate result]**  |
| C6        | Descriptive before/after across 18 dimension-by-level cells on the 17-project corpus: n, median, Q1 and Q3; no numeric pass/fail verdict | **[awaiting actual descriptive table]** |

### Declared search, before observing AFTER

The independent objective is the preserved a priori overall ranking: CodeIgniter
and WordPress are each lower than Flysystem, PHPUnit, Symfony HttpFoundation,
HttpKernel, Console, Routing, Monolog, Doctrine ORM, DBAL, PHP-Parser, Guzzle and
Symfony DependencyInjection. The 24 pair terms have weight 1 and margin 0;
the objective is their weighted sum. Ties use ascending declared grid order.
The objective source is `docs/internal/plans/health-recalibration/measurement/02-apriori-ranking.md`,
SHA-256 `dff205c1c4774b0e461264f9e7fef3fa72975a9d964386abf92a896d564ecbf5`.
Keep its exact bytes in the verification evidence before retiring that plan.

The declared grid has 27 candidates: namespace LCOM span in `[5, 4, 2]`,
project distance weight in `[6, 9, 12]`, and project breadth weight in `[4, 6, 8]`.
Neither the grid nor the objective may be selected from AFTER outcomes.
For namespace cohesion, the LCOM contribution is
`(1 - clamp((LCOM.avg - 1) / span, 0, 1)) * 100`, combined with the available
square-root-scaled TCC contribution by participating weights.
Project outgoing-only Form B is:

```text
clamp(100 * 18 / (18
  + distance-own.avg * distance_weight
  + max(ce-packages.avg * 3.0 + sqrt(ce.avg) * 0.5 - 4, 0) * breadth_weight
  + sqrt(max(ce.max - 30, 0)) * 0.8), 0, 100)
```

This displayed shape omits expression-level nullable guards for readability;
publication must include the exact accepted expression and its applicability.
An absent distance is recorded as not measured, not a measured distance zero.

**Awaiting actual calibration receipts:** all 27 objective values, deterministic
selection and rejection reasons, exact accepted expressions and coefficients,
self-test command/result, C1–C5 contracts and verdicts, and C6's 18 descriptive
cells (n, median, Q1, Q3) before/after. C6 has no numeric pass threshold.

## Consequences and migration

- Recalculate class, namespace and project health expectations from the accepted capture. Class cohesion changes with the tracked TCC population; namespace/project cohesion changes with the selected LCOM span. Project coupling changes with outgoing-only inputs and applicable weighted distance.
- Recheck consumer thresholds, presets, CI cases, baselines and benchmark ranges by key and reporting level. Record intent for each revised limit. A score change is not evidence that a new limit should be relaxed.
- CodeIgniter's project distance is absent if its only own scope is disconnected; its decomposition and coverage must show that absence. WordPress and Laravel remain named anchor reviews, judged by their measured code quality and not solely by a target corridor.
- **Awaiting actual calibration receipts:** 17-project before/after table, current-candidate self-analysis, consumer-limit decisions, final benchmark diff, native checks, CI and review. Retire the former plan only after this ADR preserves those actual decisions and evidence.
