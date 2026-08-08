# Scanner Validation Round 1

**Status:** complete
**Date:** 2026-08-08
**Base:** `origin/main` at `6bfcb561`

## Objective

Find and fix scanner defects that ordinary unit tests may miss by comparing
independent execution modes, using manually calculable fixtures and local
invariants, running the existing fail-closed cross-tool comparison, and
checking whether Qualimetrix's own architectural signals agree with a manual
repository inspection.

The round is bounded. It does not attempt to prove every rule or every metric.
It prioritizes seams where one implementation is expected to produce the same
analysis model through different paths, plus high-risk metric families whose
contracts have explicit PHP-specific extensions.

## Evidence Rules

1. A competitor is evidence, not automatically an oracle. Equality verdicts
   are allowed only for pairs classified `comparable` by
   `scripts/cross-tool-comparison.py`.
2. Intentional extensions are not defects. Every investigated metric records
   its academic baseline, Qualimetrix extension, and appropriate oracle type.
3. A suspected correctness defect is confirmed only by a clean repeat and an
   independent oracle: an invariant, a hand-calculated fixture, or an external
   metric whose contract equivalence was proved separately. Another execution
   path can confirm a mode/cache divergence, but cannot decide which result is
   correct; E1/E2 divergences require a third oracle before confirmation.
4. Raw outputs, exit codes, and stderr go to a session scratch directory.
   Durable conclusions and every confirmed/rejected finding go to
   `docs/internal/SCANNER_VALIDATION_ROUND_1_FINDINGS.md`.
5. A fix requires a regression test that fails under a targeted mutation or
   on the pre-fix behavior and passes after the correction.

## Bounded Inventory

### Execution paths

| ID  | Path                      | Variants selected for this round                                                      | Required invariant                                                                                   |
| --- | ------------------------- | ------------------------------------------------------------------------------------- | ---------------------------------------------------------------------------------------------------- |
| E1  | Collection strategy       | sequential (`workers=0`) vs parallel (`workers=2`)                                    | Same normalized metrics, findings, identities, and coverage                                          |
| E2  | AST cache                 | disabled, cold session cache, warm session cache                                      | Same normalized analysis model                                                                       |
| E3  | Configuration composition | defaults, `strict`, `legacy`, `ci`, one targeted `only-rule` + `rule-opt`             | Metrics/coverage stable; only intended policy projection changes                                     |
| E4  | Report scope              | full, `git:HEAD~1`, `git:HEAD~1 --report-strict`                                      | Scoped findings are subsets; analysis coverage remains full                                          |
| E5  | Baseline projection       | no baseline vs committed v10 ceiling with resolved entries                            | Same measured set; only acceptance/severity projection changes                                       |
| E6  | Output projection         | JSON, metrics JSON, health, graph JSON/DOT                                            | Shared coverage and identities agree where surfaces overlap                                          |
| E7  | Analysis failure          | valid project vs invalid-syntax fixture                                               | Invalid input yields incomplete coverage and exit 4 even with `fail-on=none`                         |
| E8  | Architecture inspection   | `check`, `graph:export`, `debug:layer-assignment`                                     | Layer assignment and dependency edges explain architecture findings                                  |
| E9  | Cache invalidation        | same-size content replacement with preserved second-resolution `mtime`                | Cached and fresh AST-derived results agree                                                           |
| E10 | PHP language evolution    | official PHP 8.0-8.5 change inventory, frozen oracle matrix, clean paired-fixture run | Every atomic construct is SUPPORTED/UNSUPPORTED/N-A and expected/observed concrete keys agree        |
| E11 | Boundary/anomaly inputs   | empty symbols/files, unknown or mixed types, dynamic targets, zero denominators       | No crash/symbol loss/non-finite output; neutral, unknown, absent, and zero follow explicit contracts |

The hook write commands, baseline mutation commands, HTML/CI formatter schema
exhaustion, performance benchmarking, and every possible CLI option are outside
this round unless a selected experiment exposes a related defect.

### Concrete metric scope

This table enumerates the concrete base keys in scope. Dynamically generated
aggregation suffixes (`sum`, `avg`, `min`, `max`, `count`, `p95`, `p5`) are checked by the
existing golden/invariant suites rather than treated as additional base
algorithms.

| Concrete keys                                                                                                                                                                                                                                                      | Symbol level / producer                                             | Contract source                                       | Oracle and fixture                                                                                  |
| ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------ | ------------------------------------------------------------------- | ----------------------------------------------------- | --------------------------------------------------------------------------------------------------- |
| `ccn`, `cognitive`, `npath`                                                                                                                                                                                                                                        | Method; complexity collectors                                       | Complexity component README and visitor docblocks     | Exact common-subset fixture; modern-PHP divergence fixture; NPath monotonicity/saturation           |
| `halstead.volume`, `halstead.difficulty`, `halstead.effort`, `halstead.bugs`, `halstead.time`, `mi`, `methodStatementCount`                                                                                                                                        | Method; Halstead/MI/size collectors                                 | Maintainability README, ADR 0020, collector docblocks | Exact formula fixtures and formatting metamorphism; PDepend contextual only                         |
| `loc`, `lloc`, `cloc`                                                                                                                                                                                                                                              | File contributions; LOC collector                                   | Size README and LOC visitor                           | Hand-counted file fixtures and existing aggregation invariants                                      |
| `classLoc`                                                                                                                                                                                                                                                         | Class; LOC collector                                                | Size README and LOC visitor                           | Hand fixture, then exact PDepend/phpmetrics post-check for proven class forms                       |
| `classCount`, `abstractClassCount`, `interfaceCount`, `traitCount`, `enumCount`, `functionCount`                                                                                                                                                                   | File contributions; class-count collector                           | Size README                                           | Hand fixtures including anonymous classes and multiple declarations                                 |
| `methodCount`, `propertyCount`, `methodCountTotal`, `methodCountPublic`, `methodCountProtected`, `methodCountPrivate`, `getterCount`, `setterCount`, `propertyCountPublic`, `propertyCountProtected`, `propertyCountPrivate`, `promotedPropertyCount`, `woc`       | Class; method/property collectors                                   | Structure README and collector constants              | Exact visibility/promoted/accessor fixtures                                                         |
| `dit`, `noc`                                                                                                                                                                                                                                                       | Class; global inheritance collectors                                | Structure README and collector docblocks              | Exact inheritance graph; NOC external exact post-check after contract qualification; DIT contextual |
| `ca`, `ce`, `cbo`, `instability`, `abstractness`, `distance`, `classRank`, `ce_packages`, `cbo_app`, `ce_framework`                                                                                                                                                | Class and namespace/project derivatives; coupling/global collectors | Coupling README and website contract                  | Manual edge audit, graph invariants, rename/order metamorphism; external values contextual          |
| `lcom`, `tcc`, `lcc`, `pureMethodCount_cohesion`, `rfc`, `rfc_own`, `rfc_external`, `wmc`                                                                                                                                                                          | Class; structure/global collectors                                  | Structure README and documented PHP extensions        | Small exact class graphs and call-set fixtures; external values contextual                          |
| `typeCoverage.paramTotal`, `typeCoverage.paramTyped`, `typeCoverage.param`, `typeCoverage.returnTotal`, `typeCoverage.returnTyped`, `typeCoverage.return`, `typeCoverage.propertyTotal`, `typeCoverage.propertyTyped`, `typeCoverage.property`, `typeCoverage.pct` | Class; type-coverage collector                                      | Design documentation and collector docblock           | Exact untyped/partial/fully-typed fixtures                                                          |
| `isReadonly`, `isPromotedPropertiesOnly`, `isDataClass`, `isAbstract`, `isInterface`, `isException`                                                                                                                                                                | Class; structural classifiers                                       | Structure README and collector tests                  | Exact class-kind fixtures                                                                           |
| `unusedPrivate.total`, `unusedPrivate.method`, `unusedPrivate.property`, `unusedPrivate.constant`, `parameterCount`, `isVoConstructor`, `unreachableCode`, `unreachableCode.firstLine`                                                                             | Class/method; code-smell collectors                                 | Code-smell README and collector tests                 | Smoke plus exact existing fixtures; no external equality                                            |
| `security.hardcodedCredentials`, `security.sensitiveParameter`                                                                                                                                                                                                     | Method/file findings; security collectors                           | Security documentation                                | Smoke only in this round; no external equality                                                      |
| `health.complexity`, `health.cohesion`, `health.coupling`, `health.typing`, `health.maintainability`, `health.overall`                                                                                                                                             | Class/namespace/project; expression evaluator                       | Computed-metric README and project formulas           | Exact formula tests and benchmark-range regression only                                             |

Code-smell/security detectors and duplication receive smoke/self-analysis
coverage in this round, but not cross-tool equality claims.
User-defined computed metric keys are outside the bounded metric inventory.

### Artifact overlap matrix

| Artifact       | Available fields in scope         | Identity form                  | Coverage                  | Allowed comparison                                                            |
| -------------- | --------------------------------- | ------------------------------ | ------------------------- | ----------------------------------------------------------------------------- |
| `json`         | findings, summary, coverage       | violation identity/symbol path | Explicit top-level object | JSON vs metrics JSON: coverage counts only                                    |
| `metrics` JSON | symbol metrics, summary, coverage | typed symbol name/FQN          | Explicit top-level object | Mode/cache equality; class FQN intersection with graph JSON                   |
| `health` text  | health summary/hotspots           | human-readable labels          | Human summary only        | No machine identity or graph oracle                                           |
| graph JSON     | nodes and typed edges             | class FQN                      | Not serialized            | Exact normalized nodes/edges vs DOT; class-FQN intersection with metrics JSON |
| graph DOT      | nodes and typed edges             | quoted class FQN               | Not serialized            | Exact normalized nodes/edges vs graph JSON                                    |

No plan assertion treats graph exports as coverage oracles, or health text as an
identity oracle. Other formatter surfaces are tested only for parseability,
exit 4, and their documented incomplete-analysis marker in E7.

## Research Sequence

### Phase 0 — Reproducible environment and baseline

- Install locked root dependencies with `composer install --no-scripts`; this
  must not run the shared-repository `setup-hooks` mutation.
- Treat live competitor provisioning as a blocking prerequisite. Record the
  global Composer manifest/lock hash, exact PDepend/phpmetrics binary paths and
  versions, and the benchmark corpus `installed.json` hash/package versions.
  Use a scratch launcher with two explicit inputs: the recorded competitor bin
  directory and recorded corpus vendor directory. The launcher imports
  `scripts/cross-tool-comparison.py`, overrides only `COMPOSER_BIN`,
  `BENCHMARK_VENDOR`, and `PROJECTS`, and writes its JSON report under scratch;
  it never changes tracked source or creates `benchmarks/vendor` in this
  worktree.
  If any input is absent or a single-project smoke run fails, mark live
  cross-tool comparison Blocked rather than silently skipping it. The tracked
  `benchmarks/composer.lock` is not installable while its manifest is absent.
- Prove the runtime reads this worktree, not the parent checkout.
- Run `composer check` and record the pre-change result.
- Record PHP/extensions, Qualimetrix version, competitor versions, base commit,
  and the exact command matrix.

**DoD:** runtime commands work from this worktree; root lock hash plus competitor
manifest/lock/corpus hashes, binary paths, versions, and smoke result are in the
findings log. The launcher smoke command, exit code, and parsed single-project
artifact are recorded; no project cache is deleted. `benchmark-comparison.sh` is never
run as a whole because it deletes project caches and writes tracked-tree output.

### Phase 1 — Mode equivalence and failure semantics

- Capture E1-E9 outputs in a fresh scratch directory. Every cache path is a
  unique session scratch path; `--clear-cache` is not used.
- Normalize only documented non-semantic fields such as duration and ordering.
- Compare structured content fail-closed: malformed JSON, incomplete coverage,
  duplicate identities, or missing symbols are findings, not skipped rows.
- Repeat every unexpected result sequentially with cache disabled.
- For E9, replace a file with different same-size content while restoring the
  original `mtime`, then compare the cached result against a no-cache run.

**DoD:** every selected execution path has command, exit code, coverage, result,
and verdict recorded; each divergence is either rejected with evidence or
promoted to a numbered finding.

### Phase 2 — Metric differential and metamorphic probes

- Run `composer test:cross-tool` first to validate the comparison harness.
- Run the live cross-tool comparison only after Phase 0 provisioning succeeds.
- The harness's `agreement` verdict is not strict equality. Run a separate
  fail-closed post-check over `noc` vs `pdepend.nocc`, `classLoc` vs
  `pdepend.loc`, and `classLoc` vs `phpmetrics.loc`. First qualify equivalence
  with hand fixtures for class/enum/interface/trait forms, multiple declarations
  per file, attributes, and multiline declarations. Exact equality applies
  only to the qualified forms; every other shape is explicitly reclassified or
  excluded with rationale before the corpus verdict.
- Inspect the largest contextual
  divergences for contract mistakes, identity loss, or missing metrics.
- Add temporary, manually calculable probes for high-risk NPath, dependency
  graph, Halstead/MI, and cohesion/RFC contracts. Test safe transformations:
  formatting-only edits, symbol renames, file-order changes, and equivalent
  namespace layouts where the metric contract says the value is invariant.

**DoD:** each family in the bounded matrix has source/documentation evidence,
oracle type, probes executed, and verdict. External disagreements are never
called bugs solely because the numbers differ.

### Phase 3 — Independent architecture assessment

- Inspect repository subjects, declared layers, and dependency directions
  without using the scanner's verdict as the starting point.
- Compare that manual model with graph export, layer assignment, coupling,
  cohesion, size, complexity, and health hotspots.
- Classify mismatches as scanner defect, configuration blind spot, deliberate
  contract boundary, or reasonable metric signal requiring human judgment.

**DoD:** at least the top ten scanner hotspots and all declared layer findings
receive an independent assessment; conclusions distinguish facts from metric
interpretation.

### Phase 3a — PHP 8.0-8.5 construct coverage

- Derive the atomic syntax/semantic-change inventory from official PHP 8.0-8.5
  migration guides and RFC pages independently of current parser support.
  Unsupported syntax remains a visible `UNSUPPORTED` row; it cannot disappear
  from scope by construction.
- Before reading runtime metric output, freeze for every atomic construct its
  fixture/control pair, expected source-symbol ownership, concrete metric keys
  and rule slugs, exact delta or neutral invariant, applicable aggregation
  levels, and contract/source rationale. Hash the frozen matrix.
- Explicitly enumerate callable and nested-scope boundaries: closures, static
  and arrow closures, capture/return-by-reference forms, nested/IIFE closures,
  function/method/static first-class callables, anonymous classes, generators,
  property-hook bodies, and Fiber callbacks (`N-A` as syntax where applicable).
  Freeze both ownership and non-leakage expectations between inner and outer
  scopes.
- Only after the freeze, run a clean no-cache dynamic repeat that fills command,
  exit, actual deltas, repeat evidence, and verdict. Exploratory pre-freeze
  output is non-authoritative.
- Inspect complexity, Halstead, statements/size, RFC/call graph,
  dependencies/coupling, declaration counts, WMC/cohesion, duplication, and
  each enumerated syntax-sensitive smell/security rule. A parser failure, an
  intentionally neutral construct, and a wrong metric delta are separate
  verdicts.

**DoD:** the official bounded inventory is explicitly enumerated in the durable
matrix; every row has parser classification, frozen expected concrete keys and
ownership/roll-ups, observed clean-repeat evidence, and PASS/GAP/N-A verdict.

Frozen E10 oracle: `/private/tmp/qmx-scanner-r1-e10-static.oObQRG/E10_STATIC_REPORT.md`,
SHA-256 `3938e644a439b337ee1530f85385221cbe8e4f28528e0c152539e5699a335e47`.

**E10 stop boundary:** this extension is diagnosis-only. Confirmed PHP 8.x
defects are recorded in the findings log with reproduction and fix direction,
but no production, test, or user-facing documentation fix is implemented in
this round. Remediation is a separate user-approved round.

### Phase 3b — Boundary and unknown-value anomalies

- Freeze expected behavior for empty/comment-only files; empty named and
  anonymous class-like declarations; empty functions, methods, constructors,
  and closures; and classes at the zero/one property/method boundaries.
- Probe missing and explicit broad/special types (`mixed`, `object`, `callable`,
  `iterable`, `never`, `void`, literal/null, union/intersection/DNF), unresolved
  names, dynamic calls/properties, variable variables, and unknown receiver
  types.
- Exercise zero-denominator or missing-input states in Halstead/MI,
  TCC/LCC/LCOM4, instability/abstractness/distance, DIT/NOC, type coverage,
  aggregation, and health. Distinguish valid neutral/unknown/absent values from
  false zeroes, non-finite output, symbol loss, incomplete analysis, and crash.

**DoD:** every atomic E11 row freezes concrete expected keys/symbol ownership
before execution, records clean no-cache evidence and repeat for surprises, and
is classified PASS/BUG/CONTRACT-GAP/N-A. E11 is diagnosis-only; no fix is made.

Frozen E11 oracle: `/private/tmp/qmx-scanner-r1-e11-boundary.3P5HgN/EXPECTED_FROZEN.md`,
SHA-256 `cfcbc2ed20366eb912b54ead1056a14e974bd39a0ab1a090b18cdd5cf643edfe`.

### Phase 4 — Confirmed fixes

For each confirmed finding, first confirm the data/control-flow root cause,
then create a separate implementation package before
editing. The package must name exact production/test/documentation files and
must not overlap another active package. The implementation agent receives the
finding evidence, root-cause hypothesis, relevant component README/Core
contracts, and machine-verifiable DoD.

Fix order:

1. incorrect analysis results or incomplete coverage hidden as success;
2. serial/parallel/cache nondeterminism;
3. wrong metric formula or aggregation;
4. wrong CLI/report/baseline behavior;
5. stale executable tooling or documentation that misdirects validation.

Every fix starts with a direct regression test against the public seam and
demonstrates the pre-fix failure before production code is edited. A targeted
mutation is allowed only when restoring the original behavior is technically
impossible; the findings log must explain why and show that the mutation is
equivalent to the confirmed root cause. Metric-semantic changes also
update the component README, code docblock, EN/RU website documentation,
CHANGELOG `Breaking` entry, and ADR/baseline impact when applicable.

**DoD per finding:** reproduction red before fix (or targeted mutation proves
the test), focused suite green after fix, diff matches the package, and the
original experiment is green.

### Phase 5 — Integrated validation and review

- Run focused suites after each package, then `composer check`, self-analysis,
  cross-tool harness, and benchmark regression when metric formulas changed.
- Load the project review procedure and run the required independent review.
- Verify every review issue against code and evidence; resolve all confirmed
  issues, including low severity, then rerun affected validation.
- Finalize the findings log with fixed, rejected, deferred, and out-of-scope
  items. Do not commit or push without an explicit request.

**DoD:** full required validation is green or any unrelated/environmental
failure is isolated with evidence; review has no unresolved confirmed issues;
the findings log is sufficient to resume after context compaction.

## Work Packages

| Package                      | Ownership and files                                                                      | Dependencies      | Parallelism                  | Machine DoD                                                                        |
| ---------------------------- | ---------------------------------------------------------------------------------------- | ----------------- | ---------------------------- | ---------------------------------------------------------------------------------- |
| P0 provisioning/baseline     | Read-only source plus unique `scratch/p0/`; no tracked edits                             | None              | Sequential and blocking      | Worktree runtime proof, baseline result, hashes/versions, launcher smoke artifact  |
| R1 mode matrix               | Read-only source plus unique `scratch/r1/` artifacts for E1-E9                           | P0                | Parallel with R2/R3          | Normalized comparisons and exit codes recorded                                     |
| R2 metric/oracle matrix      | Read-only metric sources/tests/docs plus unique `scratch/r2/` cross-tool/probe artifacts | P0                | Parallel with R1/R3          | All bounded concrete keys classified and probed                                    |
| R3 manual architecture audit | Read-only `src/`, `qmx.yaml` plus unique `scratch/r3/` graph/audit artifacts             | P0                | Parallel with R1/R2          | Top ten hotspots and all layer findings assessed                                   |
| R4a PHP 8.x static oracle    | Official sources plus read-only source/tests/docs; unique `scratch/e10-static/`          | P0                | Independent reconnaissance   | Atomic inventory and expected matrix frozen and hashed before runtime comparison   |
| R4b PHP 8.x dynamic probes   | Read-only runtime plus unique `scratch/e10-dynamic/` fixtures and raw outputs            | R4a frozen matrix | Sequential after R4a         | Clean repeat fills only actual deltas/evidence/verdict against frozen expectations |
| R5 boundary/anomaly matrix   | Read-only contracts/tests/runtime plus unique `scratch/e11-boundary/` artifacts          | P0                | Independent diagnosis        | Frozen boundary oracles and clean finite/symbol/roll-up evidence complete          |
| F-N confirmed bug            | Exact files are enumerated from finding N before delegation                              | Finding confirmed | Only with strict non-overlap | Regression red/mutated-red, fix green, experiment green                            |
| V integrated validation      | No production edits; validation logs and final findings status                           | All fixes         | Sequential                   | Required suites and review complete                                                |

Only the orchestrator updates the central plan/findings documents. Research
agents own only their named, non-overlapping scratch directories and report
compact evidence; implementation agents edit only their assigned
file sets and never perform repository-wide Git operations.

## Stop Conditions

The discovery corpus is bounded to Qualimetrix itself, the existing golden and
architecture fixtures, one generated parity corpus, and at most four available
open-source projects already named by the live harness. This round implements
at most three non-overlapping fix packages. Additional confirmed defects receive
a complete disposition and become input to a later fix round. The sole
exception is a defect that makes later evidence non-authoritative (for example,
stale cache or incomplete analysis reported as complete); it blocks discovery
until fixed and does not consume the three-package limit.
