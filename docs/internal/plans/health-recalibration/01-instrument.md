# P0 — the instruments

No product code. This stage makes the measuring equipment trustworthy; review
found three separate reasons it is not.

## What is wrong today

1. **The collector measures under the wrong configuration.**
   `collect-benchmark-data.php` runs `bin/qmx` through `exec()`, which inherits
   the repository root as its working directory. `bin/qmx` discovers `qmx.yaml`
   from the process cwd, so every benchmark project is analysed under this
   repository's memory limit, architecture layers and coupling framework
   namespaces. `benchmark-regression.php:49-61` avoids exactly this, with a
   comment naming the damage it once caused. The two instruments therefore do
   not measure the same thing.
2. **The collector's output cannot feed a formula bench.** It writes a digest —
   `counts`, thirteen selected namespace distributions, `outliers` — and not the
   project metric map. None of `complexity.ccn.p95`, `coupling.cbo.max` or
   `maintainability.mi.p5` survives, and those are formula inputs.
3. **`--update-baselines` refuses to write in the case it exists for.**
   `benchmark-regression.php:224` requires a wholly empty `$failures`, which
   mixes infrastructure refusals with expectation mismatches. A recalibration
   produces mismatches by definition.
4. The collector dies of OOM assembling its output at the default 128M
   (`measurement/00-collect-oom.txt`); the analysis itself completes.
5. The guard reads project-level symbols only, and `health.typing` is in no
   `expectations` block, no printed column, and cannot enter one:
   `--update-baselines` rewrites present keys.
6. `foreach ($config['expectations'] ...)` at line 159 has no null guard, so a
   new corpus entry carrying only a `path` is a fatal error. P1 needs that path
   to exist before it can seed the anchors.

## Changes

### Equal conditions for both instruments

The collector runs its analysis from a neutral working directory, as the guard
does. Until this holds, no comparison between the two is meaningful, and the
bench's self-test below is the comparison.

### Raw capture for the bench

A capture step that keeps, per project, the full metric map of the project
symbol and of every namespace and class symbol — the bench's input. Kept
separate from the digest the collector already writes: the digest answers
"what does this corpus look like", the capture answers "what would this formula
have produced", and merging them makes each worse.

Size is the reason the digest exists; the capture is written per project rather
than as one document, and is not committed.

### Guard: refusal and disagreement are different

Split `$failures` into infrastructure failures and expectation failures. Writing
requires no infrastructure failure and a complete corpus; disagreement does not
block it. Exit codes keep their meaning: 0 within range, 1 regression, 2
infrastructure error. Partial corpora still never ratchet.

Two cases review named that the split alone does not cover: a metric expected
but not measured is neither an infrastructure failure nor a disagreement about
its value, and must be classified explicitly; and a project entry with no
`expectations` must be accepted and seeded, not fatal.

### Guard: every dimension, every level

`health.typing` joins the expectation set and the table. Namespace and class
distributions — median and interquartile range per dimension — are recorded
alongside the project row, because premise 3 of the overview is a namespace-level
defect and C5 is judged there.

The namespace half of this is nearly free: the collector already computes those
distributions and nothing reads them.

### The offline bench

`scripts/health-calibration.php`, evaluating candidate formulas through the
product's own `ComputedMetricExpression` over the captured raw metrics.

Three properties, each closing a way this instrument could lie:

- It evaluates through the product's expression class, never a re-implementation.
- It recomputes `health.*` from non-health inputs in dependency order. Reading
  published `health.complexity` as an input to `health.overall` would make a
  candidate complexity formula invisible in the overall result, and the self-test
  below would pass tautologically.
- It reports, per subject and dimension, which referenced keys were **absent**
  and what `.count` each aggregate carried. C2 and C3 are unprovable without it.
- It prints monotonicity in its general form, not only the single-child case:
  for every parent/child level pair and every dimension, whether the parent
  score falls outside `[min(children), max(children)]`. C1 is stated on the
  single-child case because that case needs no opinion about which aggregation
  is right; the instrument has to cover the rest, or C1 "holds across the
  corpus" has nothing behind it.
- It carries `size.loc` per symbol alongside the metrics, so coverage can be
  expressed as a share of code rather than a share of symbols. WordPress's
  structural aggregate covers 20 of 32 namespaces but a far smaller share of
  its lines; which denominator C3 uses is P3's decision, and the bench must be
  able to compute either.

## Definition of Done

- The collector and the guard, run on the same project, produce the same
  `health.*` project values. Today they need not; this is the equality the rest
  of the plan assumes.
- The bench reproduces the guard's published project scores to within 0.1 for
  every corpus project, from captured data. Same instrument on both sides — this
  is the self-test review's c-01 showed was previously comparing two different
  measurements.
- The bench reproduces the namespace-level scores for at least one project with
  more than fifty namespaces, so C5 has a working instrument and not only a
  project-level one.
- `composer benchmark:update` writes when the corpus measured completely and
  expectations disagree; refuses when a project path is missing. Both proven by
  running them.
- A baseline entry carrying only `path` is seeded rather than fatal.
- `php scripts/collect-benchmark-data.php <out>` exits 0 over the whole corpus.
- `composer check` green.

## Files

`scripts/collect-benchmark-data.php`, `scripts/benchmark-regression.php`,
`scripts/health-calibration.php` (new), `composer.json`, `benchmarks/README.md`,
and tests for the guard's classification and the bench's verdict arithmetic.

## Test plan

A fixture corpus where one project fails infrastructure, one disagrees, one
expects a metric that was not measured, and one has no expectations at all —
four different outcomes from one run of `--update-baselines`. For the bench, a
synthetic subject whose aggregate `.count` is 1 of 500, asserting that C3 reports
"not applicable" rather than a score.
