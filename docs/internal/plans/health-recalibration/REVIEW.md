# Plan review — round 1, disposition

Two reviewers, same material, same brief, open question ("what is wrong here").
`comprehensive` (native) returned 15 findings — 3 HIGH, 9 MEDIUM, 3 LOW, of
which 13 confirmed, 1 refuted, 1 unverifiable. `codex` returned 11, all
confirmed — 5 HIGH, 5 MEDIUM, 1 LOW — plus 3 candidates it refuted itself.

## What both found independently

Convergence, not agreement by consultation — the two ran in parallel without
seeing each other:

| defect                                                         | comprehensive | codex     |
| -------------------------------------------------------------- | ------------- | --------- |
| the collector measures under the repository's own `qmx.yaml`   | 01 (HIGH)     | 06        |
| the collector's output cannot feed the offline bench           | —             | 02 (HIGH) |
| P3's DoD depends on artefacts P6 owns — a cycle                | 03 (HIGH)     | 05 (HIGH) |
| C3 tests how absence is spelled, not whether the score applies | 04, 09        | 01 (HIGH) |
| namespace and class levels have no acceptance criterion        | 07            | 03 (HIGH) |
| numbers pinned from the recon draft are wrong                  | 14            | 11        |

The collector's bench problem was also found here, independently of both, before
either returned — see the session record. Three witnesses, one defect.

## Findings accepted and what they change

- **The instrument is contaminated** (c-01, x-06). `collect-benchmark-data.php`
  runs `bin/qmx` through `exec()` with the repository as cwd, so every benchmark
  project is analysed under this repository's `qmx.yaml`. The regression guard
  avoids this deliberately, with an eight-line comment explaining the damage.
  P0 gains the cwd fix; the bench's self-test is restated as a comparison
  against numbers taken by the *same* instrument.
- **The collector does not collect what the bench needs** (x-02). Its output is
  a digest — counts, thirteen selected namespace distributions, outliers — not
  the project metric map. P0 gains its own raw capture.
- **Literal D1 alignment recreates D2** (c-02). The namespace coupling formula
  reads bare `coupling.distance` and `coupling.ce`; neither exists at project
  level, so `?? 0` zeroes two of five terms silently. The earlier 51.0 → 65.6
  probe contains exactly this inflation and is withdrawn as a prediction.
- **P3 cannot be green on its own** (c-03, x-05). `composer check` runs
  `selfcheck:analysis` against `qmx-baseline.json`, whose regeneration P6 owned.
  Ownership moves: the stage that invalidates an artefact regenerates it.
- **The criteria do not reject the target outcome** (c-04, c-08, c-09, x-01).
  C3 was written about the `??` idiom; CodeIgniter reaches 100.0 through
  *present* values that are all below threshold. C2 was corpus fitting, which
  the plan's own constraint forbids. Both are replaced — see the overview.
- **The a-priori ranking never entered acceptance** (x-04). It was produced in
  P2 and then used by nothing. C1 now names it.
- **Breaking is judged on project bands only** (x-09). A class- or
  namespace-level finding crossing a threshold changes a consumer's exit code
  just as well.
- **Corpus freshness is not corpus relevance** (x-10). "A newer release exists"
  is not "users run it"; the bump needs a stated rule, not a reflex.

## Findings rejected

- c-13 (LOW, refuted by its own author): the constraint about
  `HealthFormulaExcluder` was said to be contradicted by code. Read directly:
  the excluder does require the canonical weighted-sum shape and refuses
  explicitly otherwise (`HealthFormulaExcluder.php:167-186`). The constraint
  stands; its wording is sharpened rather than removed.
- c-15 (LOW): P4's cross-language binding. Accepted in part — the drifted
  "ideal" constants are real, the PHP/JS divergence is a stale fixture. The
  prescription is split accordingly rather than dropped.

## What the review did not cover

Neither reviewer ran `composer check` or `benchmark:check`; neither examined the
finding-gate cases, the presets, or CI. The anchors' installation was not
exercised by either. These remain open and are named in the stages that touch
them.
