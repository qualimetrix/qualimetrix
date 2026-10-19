# 0116. Measured health-score recalibration

**Date:** 2026-10-11
**Status:** Accepted

## Context

Effective cohesion methods and their tracked populations changed; project
distance now measures applicable own scopes and weights eligible types.
Project coupling previously read bidirectional CBO, mixing incoming contract
use with outgoing dependencies. Saved scores cannot be carried forward as if
those inputs and populations were unchanged. Historical pilot measurements
used different captures and are not candidate verdicts.

The completed measurement uses 17 projects. The frozen search fits the 16
external projects; Qualimetrix is evaluated after selection and supplies no
optimization feedback. BEFORE is the published capture from source
`436e7e31720faff84c247bce15ff44f6fe1267ec`; the final AFTER capture was
refreshed from `746f642a5ca51d7c9a4769a2476029a71081797e` after correcting
effective-name lifecycle aliases. The corrected calibration instrument remains
the source from `63e3c582ade8d29862b0213b6fca660be1550fb9`. Every native capture
has complete file coverage. The post-correction full repository check and
semantic gate completed successfully; refreshed documentation review, exact
candidate validation and CI remain separate pending stage checks.

## Decision

The selected tuple is **LCOM span 5, project distance weight 6, project breadth
weight 4**. The class purity fraction uses `cohesion.tcc-method-count`. Project
Form B reads outgoing package breadth, square-root-scaled outgoing volume,
outgoing outliers and applicable own distance. Its decomposition uses those
same inputs; incoming CBO is not a term.

Span five was already present in the BEFORE and pre-integration sources. The
earlier [scale alignment](https://github.com/qualimetrix/qualimetrix/commit/fb8081f1e3664e9b21f8743658ab980410150afe)
changed the historical main namespace/project span from two to five on
2026-10-10; this calibration **retains five**. The selected tuple already
matches the integrated defaults, so selection requires no additional coefficient
change. The new purity denominator and outgoing project inputs remain distinct
consumer-visible changes.

### Exact expressions and applicability

Namespace cohesion, inherited by project cohesion over project aggregate inputs:

```text
clamp(weighted_mean((m["cohesion.tcc.avg"] ?? null) === null ? null : (m["cohesion.tcc.avg"] + (1 - m["cohesion.tcc.avg"]) * ((m["cohesion.pure-method-count.avg"] ?? 0) / max(m["cohesion.tcc-method-count.avg"] ?? 1, 1)) * 0.4) ** 0.5 * 100, 0.5, (m["cohesion.lcom.avg"] ?? null) === null ? null : (1 - clamp((max(m["cohesion.lcom.avg"] - (m["cohesion.pure-method-count.avg"] ?? 0) * 0.7, 1) - 1) / 5, 0, 1)) * 100, 0.5), 0, 100)
```

Its applicability requires a finite measured `cohesion.tcc.avg` or
`cohesion.lcom.avg`; missing contributions remain nullable participating terms.
The project evaluates its own aggregate inputs, not namespace health scores.

Project coupling:

```text
clamp(100 * 18 / (18 + (m["coupling.distance-own.avg"] ?? 0) * 6 + max((m["coupling.ce-packages.avg"] ?? 0) * 3.0 + (m["coupling.ce.avg"] ?? 0) ** 0.5 * 0.5 - 4, 0) * 4 + max((m["coupling.ce.max"] ?? 0) - 30, 0) ** 0.5 * 0.8), 0, 100)
```

Project applicability requires at least one finite measured input among
`coupling.distance-own.avg`, `coupling.ce-packages.avg`, `coupling.ce.avg` and
`coupling.ce.max`. The expression's missing-term fallback does not publish a
distance zero or fabricate measured coverage. Class and namespace coupling
semantics remain unchanged.

### Limits confirmed by final review

The aggregate-cohesion correction retains the class purity adjustment when
evaluating namespace and project cohesion: pure-method and tracked TCC-method
means now accompany the raw TCC/LCOM means. A single-class namespace retains
that class's score (78.879058 rather than the previous 60.432817 for TCC 0.167,
LCOM 2 and two pure methods among four tracked public methods). The earlier
explanation of C1 only justified scores above all children through nonlinear
pooling. It did not justify losing purity below the minimum child. The
historical tables below describe the earlier capture, not the corrected
aggregate formula.

Health cohesion intentionally accepts every nonempty measured LCOM graph; it
does not inherit `cohesion.lcom`'s configurable `min_methods` default of three.
One vertex implies LCOM 1 and an LCOM contribution of 100 by construction;
the whole score is 100 when TCC is absent. A virtual stateless LCOM vertex
can still accompany several public TCC methods and a lower combined score. Two disconnected
vertices can score 40 and warn even when the LCOM rule abstains. Review of the
17-project capture found one-vertex scored classes in Qualimetrix 209/877
(23.8%), PHP-Parser 164/242 (67.8%), PHPUnit 359/826 (43.5%) and Laravel 153/856
(17.9%). Of class health-cohesion warnings, 11/22 in Qualimetrix and 31/85 in
PHPUnit came from graphs with fewer than three vertices. The score describes
this graph shape, not evidence that a sufficiently large method population
has demonstrated a responsibility split. Keeping that distinction explicit
avoids silently coupling the score to a configurable rule population.

Project Form B's breadth term was zero on all 17 calibration projects. The
maximum combined input was 2.996722 (Guzzle), below its knee four. Breadth
weights 4, 6 and 8 therefore produced identical corpus values; weight four is
a deterministic grid-order tie-break, not a fitted effect. On this corpus the
penalty consists only of own distance and the worst outgoing class: that
outlier supplies about 83% of Qualimetrix's penalty and 80% of Laravel's.
Composer coupling 35.71→72.97 and Monolog 60.11→91.96 in the historical table
must be read with this limitation; CodeIgniter reaches 100 with neither
applicable distance nor a nonzero breadth/outlier penalty. The retained form
can respond above the knee, but the calibration establishes no discrimination
of ordinary project breadth below it. Changing that promise requires a new
model and a search whose breadth parameter actually affects the corpus.

### Reproducing the final-review census

The final-review census was repeated on production source
`59191fbec0d64e67c2220161f8899604f127791b`, PHP 8.5.11, with the same 17
source selections in `docs/internal/benchmark-baselines.json`. All 17 runs
published complete coverage with zero failed files. Use the native metrics
capture command in [Capture and runtime provenance](#capture-and-runtime-provenance),
adding `--no-cache --no-progress`; disable `duplication.clone` for PHP-Parser
as in that original capture. Read `symbols` from each resulting JSON:

```python
classes = [s["metrics"] for s in data["symbols"] if s["type"] == "class"]
scored = [m for m in classes if "health.cohesion" in m]
singletons = [m for m in scored if m.get("cohesion.lcom-method-count") == 1]
perfect = sum(m["health.cohesion"] == 100 for m in singletons)
warnings = [m for m in scored if m["health.cohesion"] < 50]
small = sum(m.get("cohesion.lcom-method-count", 3) < 3 for m in warnings)
p = next(s["metrics"] for s in data["symbols"] if s["type"] == "project")
breadth_input = p.get("coupling.ce-packages.avg", 0) * 3 + p.get("coupling.ce.avg", 0) ** 0.5 * 0.5
breadth_penalty = max(breadth_input - 4, 0) * 4
```

`Small warnings` uses the default health warning boundary 50 and graph size
below three, independently of the LCOM rule. `Perfect singletons` tests the
whole health score, not just its LCOM contribution. In particular, PHP-Parser
has virtual one-vertex LCOM graphs whose two public TCC methods still produce
TCC zero and combined health 81.622777. The broad statement that every
one-vertex class scores 100 is therefore false.

| Project                 | Cohesion scored | One LCOM vertex | Perfect singletons | Small warnings / all warnings | Breadth input |
| ----------------------- | --------------- | --------------- | ------------------ | ----------------------------- | ------------- |
| flysystem               | 29              | 11              | 11                 | 0/1                           | 0.694131      |
| symfony-console         | 131             | 30              | 30                 | 1/9                           | 1.240111      |
| symfony-di              | 137             | 33              | 33                 | 3/9                           | 1.328880      |
| symfony-http-foundation | 75              | 19              | 19                 | 1/2                           | 1.278649      |
| symfony-http-kernel     | 109             | 42              | 42                 | 1/2                           | 1.383793      |
| symfony-routing         | 41              | 10              | 10                 | 1/1                           | 1.024636      |
| phpunit                 | 826             | 359             | 359                | 31/85                         | 1.093363      |
| php-parser              | 242             | 164             | 74                 | 0/13                          | 1.111828      |
| doctrine-orm            | 273             | 86              | 86                 | 1/6                           | 1.247605      |
| doctrine-dbal           | 244             | 71              | 71                 | 2/29                          | 1.006917      |
| composer                | 228             | 22              | 22                 | 1/43                          | 2.434484      |
| monolog                 | 105             | 22              | 22                 | 0/3                           | 1.622741      |
| guzzle                  | 28              | 2               | 2                  | 0/1                           | 2.996722      |
| laravel-framework       | 856             | 153             | 153                | 8/101                         | 1.827424      |
| qmx                     | 877             | 209             | 209                | 11/22                         | 1.688108      |
| codeigniter             | 135             | 15              | 15                 | 0/30                          | 0.469117      |
| wordpress               | 388             | 39              | 39                 | 5/20                          | 0.978759      |

Every breadth penalty is zero. These measurements support the explicit model
limits above; they do not turn the tied breadth weights into fitted evidence.
The pure-method aggregation correction changes namespace/project cohesion and
overall scores, so this source census is not a claim about revised benchmark
acceptance or the historical frozen search's candidate verdicts.

### Instrument verification

The existing `scripts/health-calibration.php` evaluates through
`ComputedMetricSubjectEvaluation` and independently pools `declaring:types`
project distance. Its final source SHA-256 is
`42b395a469b963dabaa9e72e63ee826584493d0a6b3ff3c5c928fb8080cc0b4b`.
The repeated default self-test passed on all 17 complete AFTER captures before
the frozen search, with exit 0: published aggregates reproduced within 1e-6,
and scores plus score absence within 0.100. Its stdout SHA-256 is
`3ae39273d9861b0768e64b6c97590ccdc2d34b0e67313afb9ff55fd40d7ec875`.

The invocation has this repository-relative form; `AFTER_CAPTURE_DIR` is the
completed normalized capture and `SELECTED_CANDIDATE` contains the tuple above:

```sh
php -d memory_limit=1024M scripts/health-calibration.php \
  --capture-dir="$AFTER_CAPTURE_DIR" \
  --projects=symfony-console,symfony-di,symfony-http-foundation,symfony-http-kernel,symfony-routing,phpunit,php-parser,doctrine-orm,doctrine-dbal,flysystem,composer,monolog,guzzle,laravel-framework,codeigniter,wordpress,qmx \
  --levels=project,namespace,class --aggregation=current --self-test

php -d memory_limit=1024M scripts/health-calibration.php \
  --capture-dir="$AFTER_CAPTURE_DIR" \
  --projects=symfony-console,symfony-di,symfony-http-foundation,symfony-http-kernel,symfony-routing,phpunit,php-parser,doctrine-orm,doctrine-dbal,flysystem,composer,monolog,guzzle,laravel-framework,codeigniter,wordpress,qmx \
  --levels=project,namespace,class --aggregation=current \
  --candidates="$SELECTED_CANDIDATE" --criteria
```

The selected all-17 criteria check exited 0; stdout SHA-256:
`82f2e2c9f7b99f4fc592b4b8ec985c4b96bcdcdbffa6d2b4e0323b9fc2f6bcf0`. Instrument plus adjacent benchmark
tests passed (61 tests, 338 assertions); calibration plus owning computed-metric
tests passed (556 tests, 2,489 assertions). Five deliberate reversions of the
C1/coverage checks each produced red exit 1 and restored green exit 0. The
post-commit absent-score early-return reversion also produced red 1 / green 0
on the evaluation source above. These are scoped correctness receipts, not
a claim that the full repository checks have completed.

| Criterion | Meaning                                                                                                           | Selected result                                                                                            |
| --------- | ----------------------------------------------------------------------------------------------------------------- | ---------------------------------------------------------------------------------------------------------- |
| C1        | Full applicable population, six dimensions and both containment edges; both directions remain diagnostic          | Full all-17 counts below; no monotonic passing cutoff                                                      |
| C2        | Binding frozen neutral/adverse source profiles; unknown inputs and unearned perfect scores refuse                 | All 57 profiles passed; no corpus perfect score matched a frozen adverse profile                           |
| C3        | Binding complete captures and stated symbol/line coverage, independent of score presence; no applicability cutoff | 0 machine-unjudged rows; measured/valid absence states below                                               |
| C4        | Binding independent eligible-type-weighted project identity, tolerance 1e-6                                       | 16 discriminating pools, 32 dependent scores; printed deltas 0.00000000; direct raw identities in ADR 0115 |
| C5        | Binding 24 frozen project-overall pairs, allowing equality                                                        | All 24 hold; objective loss 0; deterministic selected index 0                                              |
| C6        | Descriptive BEFORE/AFTER over 18 dimension-by-level cells                                                         | Complete table below; no numeric pass/fail bands                                                           |

### Fresh native self-analysis

After the separate finite ceiling/floor decisions, stale-entry cleanup and
lifecycle-alias correction,
`php bin/qmx check src/ --baseline=qmx-baseline.json --fail-on=warning
--memory-limit=512M --workers=0 --format=json --no-progress --no-cache`
exited 0 on source `746f642a5ca51d7c9a4769a2476029a71081797e` with all 1,434
discovered files analyzed, no failed or excluded files and zero findings at
every severity. Its JSON
output timestamp is `2026-10-11T05:59:47+00:00`; output SHA-256 is
`2d0cbcdcae1b7ab56d015d119ac0bf2c59e7ff9fbc32e1901dbde6bafd35bcb4`.
The lifecycle correction changes source and the Qualimetrix capture; the
calibration instrument and selected coefficients remain unchanged. This is the
native baseline verdict; refreshed documentation review, exact candidate
validation and CI are separate checks.

### Full C1 distributions

Counts are from all 17 projects, with every applicable parent/child comparison
retained. Above means above every measured child; below means below every
measured child. A printed sample limit does not limit these counts.

| Containment edge     | Dimension                | Parents above every child | Parents below every child |
| -------------------- | ------------------------ | ------------------------- | ------------------------- |
| `project->namespace` | `health.complexity`      | 0                         | 0                         |
| `project->namespace` | `health.cohesion`        | 0                         | 0                         |
| `project->namespace` | `health.coupling`        | 0                         | 1                         |
| `project->namespace` | `health.typing`          | 0                         | 0                         |
| `project->namespace` | `health.maintainability` | 0                         | 0                         |
| `project->namespace` | `health.overall`         | 0                         | 1                         |
| `namespace->class`   | `health.complexity`      | 7                         | 197                       |
| `namespace->class`   | `health.cohesion`        | 0                         | 66                        |
| `namespace->class`   | `health.coupling`        | 0                         | 702                       |
| `namespace->class`   | `health.typing`          | 10                        | 6                         |
| `namespace->class`   | `health.maintainability` | 13                        | 155                       |
| `namespace->class`   | `health.overall`         | 43                        | 469                       |

C3 reports 160,752 measured rows, 996 not-measured rows, 22,762 absent rows
and 442 not-applicable rows, with zero unjudged rows. These are stated input
coverage rows, not disjoint symbol counts or an applicability quality score.

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
coverage (one discovered and one analyzed file); its stdout SHA-256 is
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
from 50 to 100. Ordinary pre-merge verification found that the original C3
printed carrier/count disagreement only inside its LOC text while machine
adjudication still passed. A further nullable-output check found that an absent
score could bypass aggregate coverage judgement. The instrument now judges
carrier presence, aggregate presence and contributor counts independently of
score presence: disagreement is machine `unjudged`, while coherent absence
and measured zero retain their distinct states. The owning regressions include
missing values, missing counts, missing both, explicit nullable outputs and
valid absence. The repeated default self-test and unchanged-grid search described above
passed after these instrument corrections. Their coverage verdict remains
separate from the diagnostic parent/child distributions.
C2's formula probes and C4's distance-only identity do not prove preservation
of every input.

C2, C3, C4 and C5 remain binding. Coverage accounting alone does not prove every
aggregate scalar correct: owning regressions must preserve measured key
presence/absence, exact eligible counts, sums, means and percentiles, retaining
measured zero and excluding valid absence. The product/default self-test and
source-to-score applicability and aggregation regressions remain required.
C1's native example distinguishes legitimate pooling from actual lost inputs;
it does not excuse the latter. Grid, objective, anchors and tie-break stay
unchanged.

### Frozen search and selection

The independent source ranking places CodeIgniter and WordPress below each of
Flysystem, PHPUnit, Symfony HttpFoundation, HttpKernel, Console, Routing, Monolog,
Doctrine ORM, DBAL, PHP-Parser, Guzzle and Symfony DependencyInjection. Its 24
pair terms have weight 1 and margin 0; the objective is their weighted sum and
ties use ascending declared grid order. The [frozen source ranking](https://github.com/qualimetrix/qualimetrix/blob/420476782939d4113ff949b6cefe72b10d9efc50/docs/internal/plans/health-recalibration/measurement/02-apriori-ranking.md) has SHA-256
`dff205c1c4774b0e461264f9e7fef3fa72975a9d964386abf92a896d564ecbf5`.
Its retired plan path was replaced by a byte-identical retained evidence copy;
only that anchor path changed in the search specification. Parameters, formulas,
objective terms, aggregation and tie-break remain unchanged.
Composer, Laravel and Qualimetrix are diagnostics, not extra formal pairs.

The unchanged grid is LCOM span `[5, 4, 2]`, distance weight `[6, 9, 12]`
and breadth weight `[4, 6, 8]`. All 27 candidates completed; 24 were eligible
with loss 0. The first of those tied candidates, index 0 `(5, 6, 4)`, was
selected. Indices 6–8 fail only C5: CodeIgniter overall 60.634540833713 exceeds
Symfony DependencyInjection 60.176751597908, yielding loss
0.45778923580520825. No grid, objective, anchor or tie-break was changed from
these outcomes. The later all-17 check, including Qualimetrix, exited 0 without
feeding its result back into selection.

| Index | LCOM span | Distance weight | Breadth weight | Eligible | Objective loss      |
| ----- | --------- | --------------- | -------------- | -------- | ------------------- |
| 0     | 5         | 6               | 4              | yes      | 0                   |
| 1     | 5         | 6               | 6              | yes      | 0                   |
| 2     | 5         | 6               | 8              | yes      | 0                   |
| 3     | 5         | 9               | 4              | yes      | 0                   |
| 4     | 5         | 9               | 6              | yes      | 0                   |
| 5     | 5         | 9               | 8              | yes      | 0                   |
| 6     | 5         | 12              | 4              | no: C5   | 0.45778923580520825 |
| 7     | 5         | 12              | 6              | no: C5   | 0.45778923580520825 |
| 8     | 5         | 12              | 8              | no: C5   | 0.45778923580520825 |
| 9     | 4         | 6               | 4              | yes      | 0                   |
| 10    | 4         | 6               | 6              | yes      | 0                   |
| 11    | 4         | 6               | 8              | yes      | 0                   |
| 12    | 4         | 9               | 4              | yes      | 0                   |
| 13    | 4         | 9               | 6              | yes      | 0                   |
| 14    | 4         | 9               | 8              | yes      | 0                   |
| 15    | 4         | 12              | 4              | yes      | 0                   |
| 16    | 4         | 12              | 6              | yes      | 0                   |
| 17    | 4         | 12              | 8              | yes      | 0                   |
| 18    | 2         | 6               | 4              | yes      | 0                   |
| 19    | 2         | 6               | 6              | yes      | 0                   |
| 20    | 2         | 6               | 8              | yes      | 0                   |
| 21    | 2         | 9               | 4              | yes      | 0                   |
| 22    | 2         | 9               | 6              | yes      | 0                   |
| 23    | 2         | 9               | 8              | yes      | 0                   |
| 24    | 2         | 12              | 4              | yes      | 0                   |
| 25    | 2         | 12              | 6              | yes      | 0                   |
| 26    | 2         | 12              | 8              | yes      | 0                   |

### C6: descriptive distribution

BEFORE uses preserved published `health.*` values, not the new model recomputed
on old inputs. AFTER uses `evaluateAll()` with the selected candidate at all
three levels. Every non-null numeric score participates, including zero;
absence is excluded. Percentiles use the existing sorted-sample rank
`p * (n - 1)` with linear interpolation, without rounding before calculation.
The display rounds to two decimals. Different n values describe changed
applicability/populations and are not a C6 failure threshold.

| Level     | Dimension                | BEFORE n | Median | Q1     | Q3     | AFTER n | Median | Q1     | Q3     |
| --------- | ------------------------ | -------- | ------ | ------ | ------ | ------- | ------ | ------ | ------ |
| project   | `health.complexity`      | 17       | 70.51  | 54.42  | 85.79  | 17      | 70.51  | 54.42  | 85.79  |
| project   | `health.cohesion`        | 17       | 73.49  | 70.81  | 79.75  | 17      | 73.08  | 67.25  | 82.16  |
| project   | `health.coupling`        | 17       | 62.69  | 55.13  | 76.07  | 17      | 76.68  | 66.75  | 89.00  |
| project   | `health.typing`          | 17       | 98.13  | 90.62  | 99.70  | 17      | 98.13  | 90.62  | 99.70  |
| project   | `health.maintainability` | 17       | 69.90  | 55.24  | 84.37  | 17      | 69.90  | 55.24  | 84.37  |
| project   | `health.overall`         | 17       | 74.74  | 65.02  | 78.12  | 17      | 77.29  | 64.11  | 81.04  |
| namespace | `health.complexity`      | 838      | 96.73  | 83.21  | 100.00 | 838     | 96.73  | 83.12  | 100.00 |
| namespace | `health.cohesion`        | 773      | 82.35  | 69.75  | 100.00 | 672     | 84.98  | 70.84  | 100.00 |
| namespace | `health.coupling`        | 855      | 90.00  | 81.42  | 96.00  | 855     | 90.13  | 81.82  | 96.60  |
| namespace | `health.typing`          | 832      | 100.00 | 54.24  | 100.00 | 832     | 100.00 | 54.24  | 100.00 |
| namespace | `health.maintainability` | 838      | 85.76  | 71.44  | 100.00 | 838     | 85.58  | 71.23  | 100.00 |
| namespace | `health.overall`         | 856      | 85.38  | 78.32  | 91.56  | 856     | 86.18  | 78.84  | 92.31  |
| class     | `health.complexity`      | 6762     | 100.00 | 95.60  | 100.00 | 6777    | 100.00 | 95.50  | 100.00 |
| class     | `health.cohesion`        | 6324     | 100.00 | 80.00  | 100.00 | 4699    | 100.00 | 77.68  | 100.00 |
| class     | `health.coupling`        | 7401     | 100.00 | 100.00 | 100.00 | 7416    | 100.00 | 100.00 | 100.00 |
| class     | `health.typing`          | 6781     | 100.00 | 37.50  | 100.00 | 6796    | 100.00 | 37.84  | 100.00 |
| class     | `health.maintainability` | 6762     | 96.30  | 77.41  | 100.00 | 6777    | 96.30  | 77.23  | 100.00 |
| class     | `health.overall`         | 7401     | 95.37  | 85.00  | 100.00 | 7416    | 95.41  | 84.92  | 100.00 |

### Project scores, BEFORE → AFTER

Each cell is the published BEFORE score followed by the selected AFTER score,
rounded only for display. The Qualimetrix row also includes its source changes
between the two captured commits.

| Project                   | Complexity      | Cohesion      | Coupling       | Typing          | Maintainability | Overall       |
| ------------------------- | --------------- | ------------- | -------------- | --------------- | --------------- | ------------- |
| `codeigniter`             | 55.50 → 55.50   | 61.87 → 67.25 | 76.07 → 100.00 | 0.84 → 0.84     | 52.26 → 52.26   | 54.77 → 60.63 |
| `composer`                | 40.18 → 40.18   | 72.72 → 73.08 | 35.71 → 72.97  | 77.51 → 77.51   | 54.51 → 54.51   | 52.39 → 59.92 |
| `doctrine-dbal`           | 91.91 → 91.91   | 72.42 → 68.69 | 62.69 → 76.48  | 99.70 → 99.70   | 84.37 → 84.37   | 81.44 → 83.45 |
| `doctrine-orm`            | 68.47 → 68.47   | 86.68 → 83.99 | 58.82 → 66.75  | 99.61 → 99.61   | 67.58 → 67.58   | 73.12 → 74.17 |
| `flysystem`               | 100.00 → 100.00 | 70.81 → 69.27 | 89.63 → 88.92  | 93.62 → 93.62   | 100.00 → 100.00 | 91.45 → 91.00 |
| `guzzle`                  | 53.20 → 53.20   | 81.20 → 61.88 | 63.65 → 78.43  | 92.52 → 92.52   | 54.16 → 54.16   | 65.02 → 64.11 |
| `laravel-framework`       | 85.79 → 85.79   | 70.81 → 66.41 | 55.13 → 66.26  | 21.03 → 21.03   | 86.42 → 86.42   | 70.31 → 71.66 |
| `monolog`                 | 82.05 → 82.05   | 75.88 → 85.91 | 60.11 → 91.96  | 96.28 → 96.28   | 67.73 → 67.73   | 74.99 → 83.36 |
| `php-parser`              | 94.88 → 94.88   | 63.03 → 64.06 | 44.49 → 57.96  | 90.62 → 90.62   | 95.54 → 95.54   | 78.14 → 81.04 |
| `phpunit`                 | 86.72 → 86.72   | 66.21 → 65.70 | 57.69 → 65.29  | 99.94 → 99.94   | 86.67 → 86.67   | 78.12 → 79.54 |
| `qmx`                     | 85.28 → 85.09   | 84.08 → 86.53 | 46.44 → 65.65  | 99.97 → 99.97   | 75.63 → 75.06   | 76.81 → 80.97 |
| `symfony-console`         | 70.51 → 70.51   | 79.75 → 82.16 | 66.27 → 76.60  | 98.59 → 98.59   | 72.60 → 72.60   | 74.74 → 77.29 |
| `symfony-di`              | 31.22 → 31.22   | 78.96 → 77.96 | 51.72 → 76.68  | 99.84 → 99.84   | 55.24 → 55.24   | 56.53 → 61.32 |
| `symfony-http-foundation` | 80.55 → 80.55   | 79.60 → 79.26 | 79.71 → 89.47  | 98.13 → 98.13   | 75.64 → 75.64   | 80.97 → 82.85 |
| `symfony-http-kernel`     | 63.75 → 63.75   | 84.37 → 85.03 | 84.78 → 89.72  | 99.40 → 99.40   | 69.90 → 69.90   | 76.87 → 78.00 |
| `symfony-routing`         | 54.42 → 54.42   | 71.84 → 76.84 | 80.92 → 89.00  | 100.00 → 100.00 | 64.27 → 64.27   | 69.73 → 72.35 |
| `wordpress`               | 14.51 → 14.51   | 73.49 → 68.93 | 69.11 → 83.37  | 9.89 → 9.89     | 33.68 → 33.68   | 40.60 → 42.54 |

CodeIgniter's distance is absent, while its measured outgoing inputs yield
project coupling 100. Its overall 60.63 remains below all 12 formally higher
projects. Composer overall 59.92 is below CodeIgniter; that diagnostic reversal
is reported, not promoted into an undeclared objective. WordPress overall 42.54
and Laravel overall 71.66 retain their independently measured components;
there is no additional target corridor imposed on them.

### Capture and runtime provenance

The native tool reports version **1.0.0** and PHP **8.5.9**. Native JSON output
timestamps span 2026-10-10T20:48:43+00:00–20:56:03+00:00 for BEFORE and
2026-10-11T05:50:34+00:00–05:57:06+00:00 for AFTER. These are output timestamps,
not separately established invocation-start times. All 17 native commands
returned 2 with complete parsed coverage and existing findings; those exits
are not calibration or infrastructure failures.

The captures used `php -d memory_limit=2G bin/qmx check "$PROJECT_SOURCE"
--format=metrics --workers=0 --config="$EMPTY_CONFIG"`, with the empty YAML
configuration `{}` and no baseline. PHP-Parser additionally disabled
`duplication.clone` on both sides. External source selections and installed
package revisions are fixed; Qualimetrix uses `src/` at the two captured commits.
Normalized BEFORE coverage metadata was restored from the original complete
native raw outputs, without a new analysis run or any change to raw/symbol values.
All 16 external AFTER captures are semantically identical to their prior
completed captures. The Qualimetrix capture changed on 11 symbols because its
source changed; the added helper method raises the project cognitive sample
count from 7,584 to 7,585. The refreshed sum is 11,959, average
1.5766644693473961, p95 7 and maximum 53. This is source drift, not a
cognitive-algorithm change.

Corpus identity SHA-256:

- `composer.lock`: `b30ba89727ee7cb6bfc3a4e5b54dc6c525836e7be4966ed5d9b4d9dfeba8582c`;
- `benchmarks/composer.lock`: `7e57f5c73c8e0e116ece6fd31cce29f1898670da19a52b79d7f30c235be356c0`;
- installed package inventory: `ceba7487523f91325616ad6a84a636fe7d91408c3c037531823b591044b6224f`;
- selected candidate definition: `6d562f0e4310266695922da73b28338a8616d2d8a672cbe5c302f6e1c0adce69`;
- frozen search specification: `b30f3ac0f1c41523b7db14b0c32df599036e6e3bae5e10c8e6285526e1bfc8e7`;
- full search result: `3575930dcdc1126c6064a91f5a23ee1d5e61ff2d40f0e5d30d4ad4a3ebbf3983`;
- descriptive report: `cdbea6cdcf10162217f684946fb86e2ee731eb30b6692cd91b8e1ed2affc12cd`.

| Project                   | Installed version       | Source revision                            | Complete files BEFORE → AFTER |
| ------------------------- | ----------------------- | ------------------------------------------ | ----------------------------- |
| `codeigniter`             | 3.1.13                  | `bcb17eb8ba53a85de154439d0ab8ff1bed047bc9` | 176 → 176                     |
| `composer`                | 2.10.3                  | `f0de0bf90226853b841672f086d8b58b02332504` | 309 → 309                     |
| `doctrine-dbal`           | 4.4.4                   | `fb9e0ffe15e1590e24dc61c0c0a23f9a33ee42ce` | 434 → 434                     |
| `doctrine-orm`            | 3.7.1                   | `037807a39697890dcede8b766c5efe8a1b4855fe` | 471 → 471                     |
| `flysystem`               | 3.36.0                  | `f7fb152932f30072d573510cbd4dd657d6475b25` | 55 → 55                       |
| `guzzle`                  | 8.2.0                   | `93939470950a9b11e2e84204166ef5e048c55fe4` | 70 → 70                       |
| `laravel-framework`       | v13.31.0                | `7c75fbf93f91fa077d3df1c820cc14f4e59a9774` | 1696 → 1696                   |
| `monolog`                 | 3.12.0                  | `72c534fc0ab181ef52d92a68382318631e301608` | 125 → 125                     |
| `php-parser`              | v5.9.0                  | `9e33da9553fe7786f0962b35f4e4ecf01be89def` | 271 → 271                     |
| `phpunit`                 | 13.3.3                  | `0d8711067516c1f6ba1a66f762e16c2fb1a8ccd1` | 1144 → 1144                   |
| `qmx`                     | 1.0.0 (source snapshot) | `BEFORE/AFTER commits above`               | 1419 → 1434                   |
| `symfony-console`         | v8.1.6                  | `eb7d9957d66739649e931ce7a9d05dab69f8abac` | 174 → 174                     |
| `symfony-di`              | v8.1.5                  | `e79d512848b75f92374e473f8e9ead4202c965c5` | 215 → 215                     |
| `symfony-http-foundation` | v8.1.6                  | `093b78326f649c3a9db922b9f17123b6aeb3b8fb` | 116 → 116                     |
| `symfony-http-kernel`     | v8.1.6                  | `2f73beb7c6f1a97d2c17bbf4dbd59da8cc18b355` | 178 → 178                     |
| `symfony-routing`         | v8.1.6                  | `3c188091b6b4fa2e4bc83a135caede12deb8576c` | 72 → 72                       |
| `wordpress`               | 6.9.0                   | `4626d4e896c36ab77a69ce58627bc76243b5dd07` | 844 → 844                     |

The normalized per-project capture hashes bind the full populations used by
the search, coverage checks and descriptive tables:

| Project                   | BEFORE capture SHA-256                                             | AFTER capture SHA-256                                              |
| ------------------------- | ------------------------------------------------------------------ | ------------------------------------------------------------------ |
| `codeigniter`             | `996ba1882487ae52521df534d26bf6d170a74d83754fafb07d4d209c29efa64b` | `5c5503101f59e213d952e11cda8f57488f922582f9e3390c2ed5df0a93df1f32` |
| `composer`                | `62ac5574b2a39bc3313f7e217a162b839ee859c7c2fce3ed59752ebc204da084` | `6c07248b746f08b77a458721141b72f8f46afcdf5e3714b194185a961e44350e` |
| `doctrine-dbal`           | `b9d2e035ed8370785155d6ed60874a1eac8ad230ded09bad016fd7b02a989986` | `72372f848a08f9ccdd6fc8c687bf4e1bdd349a109da34c68e26b90611398d9cd` |
| `doctrine-orm`            | `cc5d7d04ea8938d0755f6e9e0effaee64b27e9d67bb7214cd663164322ed62f9` | `b9aa3c949ef4b5f45f70c630ec2f7182764ddc0d361351fe3b85ae8e57c4e6ff` |
| `flysystem`               | `3f1acd2508a3fc49e7cf970c36b7934747fcd510276aee446db6ea257dc1aaf0` | `2f501363325ff0e0fc3ed3c2cffb7807589fc7363573a31a77bc764d8e87096c` |
| `guzzle`                  | `f1be6a3c7f376fa6df1492cdd2cbe1c6c2077c94cf1ced949c48b01085ee5161` | `2df3fe30e90e1423685f51bc2bb8e2f1320e7e1e4d63ca7aefbe4d8d3ebce239` |
| `laravel-framework`       | `407e57da0e908d757de0ca0199a58730d9a16a1fde2612c2679e800d58da83db` | `2e3e90ec8c10f4119f51bac83d4cdac8036e2dff13726413e61f1245f60d0378` |
| `monolog`                 | `e2cf917084888dd47be978e22a44ad825e61071fb364c4f11c7b6afbbf0e3faa` | `1448f5b54ee1f52764f7b22e03e177cdf6b11c36128fba8cc9262b7b5a788a14` |
| `php-parser`              | `a028ea8787cce22da4bfddce4b34d16632b0cf5f49d15fa9f815d6fabfb5d28f` | `f48888b186a3afee259c163253f4392c0e53433aa6e63a65400be037a1f63d92` |
| `phpunit`                 | `66bd24ae8b752c5de5dbbdad5678dc1222987c189c1ec6886ef89691e9169f0a` | `e3549a753a6ec40acaf2c362247d835fe9957f9130e5cbdd245a167d5812544e` |
| `qmx`                     | `4d5e9dd13d614e376d8f7791f6757bc36cd32e769743e409a85e7b3f766ef5ab` | `c562304674380e58f67ca59e028120adb56a674f728d2b3310094acd7d1a4b3b` |
| `symfony-console`         | `d68dbe8e65eebc95445fd010f67b96ea8e523460a3ba8de359c649983e141ee6` | `92b25fa1ce090e010690d75ddb4582a7306ef5fa3dab84a77f6d1f2678972923` |
| `symfony-di`              | `79185af6fde17a3a7ca3df708fde76803f0e2c0f9ce62c3d3b2a07f043310dd3` | `638534a7975eb038bff578492f42ff631e101fbe926ac3c8c64535b86d188d5c` |
| `symfony-http-foundation` | `f0b9a768dc393b4402e1545f2280d5878ba076c925b75b0723ca1fd52680ca0c` | `c57200174edd58de37c761ad244e3bdc49fb79e54f7d5e062434f7d4184e5d5e` |
| `symfony-http-kernel`     | `a82ab40f4cd8756d0acd10a3ea87b413cb71f78b79d1e5be7c4e7c98dfb3c21d` | `eb082f51ca0631a49e12ee00c14641dc57de7a21c8652429c0cab8d27ad67a5a` |
| `symfony-routing`         | `0600579ab4f54fb1ff341ada4be00976dd28cc9e1f6116e207ad21983e3b1f3a` | `9b86e81ebab3d07792bb82a1ac36170f30b4de4f33f7f26da8f78e345bd049ce` |
| `wordpress`               | `6cdc543d8867cb67c7b4fe574e756cffbb201e453b7dd931567646cd1210b15e` | `dbedd9286a9e97c8928730550703aebb71374482d3d6414b93df6685d471288c` |

## Consequences and migration

- Recompute class, namespace and project expectations. Class cohesion uses the tracked TCC population; aggregate cohesion retains span five but uses corrected measured method/trait populations. Project coupling uses outgoing-only evidence and applicable type-weighted distance.
- Recheck consumer thresholds, presets, CI cases, baselines and benchmark ranges by metric key and level. Preserve the reason for each revised limit. A score change alone does not justify relaxing it, and C6 supplies no numeric pass bands.
- Preserve measured key presence, eligible counts, sums, means and percentiles in owning regressions; numeric zero and valid absence are distinct. Coverage accounting alone does not prove every aggregate scalar correct.
- Residual self-analysis ceilings/floors and stale-entry cleanup have separate measured baseline decisions; coefficient selection does not itself justify accepting residual findings. The fresh native ratchet below records their resulting verdict.
- This calibration decision is accepted on its scoped tests, repeated self-test, frozen search, all-17 check, descriptive C6 evidence and fresh native ratchet. The post-correction `composer check` and semantic gate passed; refreshed documentation review, exact candidate validation and CI remain separate pending stage checks. Accepting this ADR does not claim those checks are complete.
