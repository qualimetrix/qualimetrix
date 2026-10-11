# 0116. Measured health-score recalibration

**Date:** 2026-10-11
**Status:** Proposed — awaiting integration and actual calibration receipts

## Context

The cohesion graph and method population changed; project distance now measures only applicable own scopes and weights eligible types. Before this change, project coupling read bidirectional CBO, mixing incoming dependency magnets with outgoing work; the captured candidate already uses outgoing-only Form B. Formula and benchmark values cannot be carried forward as if the input population had stayed fixed. Earlier pilot measurements used a different captured corpus and preceded the present applicability and evaluation contract; their numbers are historical, not candidate verdicts.

The 17-project BEFORE and AFTER captures exist. AFTER completed on source
`d4aa7c619dbee903d11891b2f760dc5492afd57a`, with complete coverage for every
project. The original 27-candidate search completed with exit 1 for every
candidate under the former binding C1. The corrected instrument must pass its
self-test again before repeating the unchanged search. No final tuple has been
selected.

**Awaiting final calibration receipts:** the integrated candidate commit, capture
identities, tool versions and dates, repeated search, and final candidate
verdicts. Historical pilot values do not substitute for this evidence.

## Decision

The built-in class cohesion formula uses `cohesion.tcc-method-count` for the purity fraction. The namespace cohesion LCOM term uses **[awaiting selected calibrated span]**. Project coupling uses only outgoing signals in the selected outgoing-only form: `coupling.ce-packages.avg`, square-root-scaled `coupling.ce.avg`, `coupling.ce.max`, and applicable `coupling.distance-own.avg`. Coefficients are **[awaiting selected distance and breadth weights]**, derived from the captured candidate. Update the decomposition catalog with the same actual inputs and labels, and remove dead or incoming-only terms. Preserve nullable evaluation and the policy that missing inputs are not invented as zeros.

The existing `scripts/health-calibration.php` evaluates through
`ComputedMetricSubjectEvaluation` and uses the `declaring:types` project-distance
scheme. Its default-formula self-test passed on all 17 AFTER captures before the
original search, with exit 0: every published aggregate reproduced within 1e-6,
and every score and score absence within 0.100. The recorded invocation in
`instrument-selftest.receipt.json` has this repository-relative equivalent,
where `AFTER_CAPTURE_DIR` names the completed AFTER capture:

```sh
php -d memory_limit=1024M scripts/health-calibration.php \
  --capture-dir="$AFTER_CAPTURE_DIR" \
  --projects=symfony-console,symfony-di,symfony-http-foundation,symfony-http-kernel,symfony-routing,phpunit,php-parser,doctrine-orm,doctrine-dbal,flysystem,composer,monolog,guzzle,laravel-framework,codeigniter,wordpress,qmx \
  --levels=project,namespace,class --aggregation=current --self-test
```

Recorded stdout SHA-256:
`37f1912876fe99f0f7aa9f8cf33df36e1fe09d15f867a5a0e04270ae1a1ed8cf`.
This receipt precedes the instrument repair and is not its repeated self-test.
The final candidate and all verdicts must come from the same AFTER capture,
with before/after values traceable to each of the 17 named projects.

| Criterion | Population and meaning                                                                                                                                                                                                                  | Candidate result and evidence                                                                                            |
| --------- | --------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- | ------------------------------------------------------------------------------------------------------------------------ |
| C1        | Full applicable population, all six dimensions and both containment edges (namespace/class and project/namespace); report parents above every child and below every child as diagnostic distributions                                   | Universal upper-bound verdict withdrawn on the native counterexample below; **[awaiting final candidate distributions]** |
| C2        | Binding frozen neutral/adverse source profiles at every dimension and level; adverse inputs must lower the score below the neutral score and below 100; refuse unprofiled sources and corpus perfect scores matching an adverse profile | **[awaiting actual candidate result]**                                                                                   |
| C3        | Binding stated symbol/line coverage with complete captures; independently detected carrier/count disagreement is machine `unjudged`, without an applicability cutoff or score damping                                                   | **[awaiting actual candidate result]**                                                                                   |
| C4        | Binding independent eligible-type-weighted distance identity, tolerance 1e-6; do not reuse the product fold as the oracle                                                                                                               | **[awaiting actual candidate result]**                                                                                   |
| C5        | Binding 24 frozen project-overall ranking pairs, allowing equality; retain the declared objective and tie-break                                                                                                                         | **[awaiting actual candidate result]**                                                                                   |
| C6        | Descriptive before/after across 18 dimension-by-level cells on the 17-project corpus: n, median, Q1 and Q3; no numeric pass/fail verdict                                                                                                | **[awaiting actual descriptive table]**                                                                                  |

### Why C1 is diagnostic

Correct pooling does not imply `parent <= max(children)`. A native two-class
fixture uses the same effective formula at class and namespace levels:

```text
h(tcc, lcom) = 50 * sqrt(tcc) + 50 * (1 - clamp((lcom - 1) / 5, 0, 1))
```

| Subject                           | TCC | LCOM | Health cohesion   |
| --------------------------------- | --- | ---- | ----------------- |
| Class A                           | 1   | 6    | 50                |
| Class B                           | 0   | 1    | 50                |
| Namespace, exact means of A and B | 0.5 | 3.5  | 60.35533905932738 |

Both classes have zero pure methods. Both aggregate counts are two: the raw
coordinates pool exactly the same complete class population. The native repeat
on source `d4aa7c619dbee903d11891b2f760dc5492afd57a` exited 0 with complete
coverage (one discovered and one analyzed file), recorded in
`s11h-c1-counterexample-native.json`; its stdout SHA-256 is
`461ffc66845c5148f581274f83fc354b9eb9c47f85a2cb7ad4ee4035cbf2e141`.
The classes have complementary TCC/LCOM inputs and square root is strictly
concave. Their correctly averaged inputs therefore score above both children.
No absent input, rounding, formula difference or population difference explains
this result.

C1 retains the full above/below distributions over every applicable containment
edge and all six dimensions. Neither direction alone classifies a lost input;
there is no cap or corpus, level or dimension exception. A model that folds
atomic losses before aggregation could impose a different bound, but it changes
the scoring model rather than calibrating the three declared parameters.

Actual input loss remains a defect. An isolated capture probe drops measured
TCC and its count from a single class's namespace aggregate, raising cohesion
from 50 to 100. The original C3 printed carrier/count disagreement only inside
its LOC text and still passed machine adjudication. C3 must instead classify
that independently observed disagreement as `unjudged`. C2's formula probes
and C4's distance-only identity do not prove preservation of every input.

C2, C3, C4 and C5 remain binding. Coverage accounting alone does not prove every
aggregate scalar correct: owning regressions must preserve measured key
presence/absence, exact eligible counts, sums, means and percentiles, retaining
measured zero and excluding valid absence. The product/default self-test and
source-to-score applicability and aggregation regressions remain required.
C1's native example distinguishes legitimate pooling from actual lost inputs;
it does not excuse the latter. Grid, objective, anchors and tie-break stay
unchanged.

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
repeated self-test command/result, C1 distributions, C2–C5 verdicts, and C6's
18 descriptive cells (n, median, Q1, Q3) before/after. C6 has no numeric pass threshold.

## Consequences and migration

- Recalculate class, namespace and project health expectations from the accepted capture. Class cohesion changes with the tracked TCC population; namespace/project cohesion changes with the selected LCOM span. Project coupling changes with outgoing-only inputs and applicable weighted distance.
- Recheck consumer thresholds, presets, CI cases, baselines and benchmark ranges by key and reporting level. Record intent for each revised limit. A score change is not evidence that a new limit should be relaxed.
- CodeIgniter's project distance is absent if its only own scope is disconnected; its decomposition and coverage must show that absence. WordPress and Laravel remain named anchor reviews, judged by their measured code quality and not solely by a target corridor.
- **Awaiting actual calibration receipts:** 17-project before/after table, current-candidate self-analysis, consumer-limit decisions, final benchmark diff, native checks, CI and review. Retire the former plan only after this ADR preserves those actual decisions and evidence.
