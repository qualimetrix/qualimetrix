# Ratchet Baseline v6 Plan

**Status:** Proposed
**Date:** 2026-08-04
**Target release:** TBD
**Review status:** Approved after three file-level review rounds by an independent reviewer

## 1. Executive Summary

Qualimetrix baseline v5 is an identity-based suppression snapshot. Once a
violation is present in the baseline, the same violation remains hidden even
when its measured value or occurrence count becomes worse. This is useful for
initial legacy-project adoption, but it does not enforce the more valuable CI
invariant: accepted debt must not grow.

Baseline v6 will make **ratcheting** the default for newly generated baseline
files. A baselined finding remains hidden while its observed debt is unchanged
or improved, and is reported when it regresses. Full suppression remains
available only through an explicit `mode: suppress` baseline.

The implementation must not infer ratchet data from formatted violation
messages or from `Violation::metricValue` alone. Rules will return baseline
observations independently from threshold-gated violations, and Analysis will
return an explicit run-completeness contract. Baseline may resolve or mutate an
entry only when that contract proves that the relevant rule and source scope
were fully evaluated.

## 2. Preconditions and Current-State Facts

### 2.1 Baseline v5 stores identity, not debt magnitude

The current entry contains only:

- rule name;
- stable violation hash.

The hash deliberately excludes line, message, severity, configured threshold,
and measured value. Consequently, a violation whose metric grows from 25 to
100 still matches the same baseline entry.

### 2.2 Current deduplication loses multiplicity

Generation deduplicates by canonical symbol plus violation hash. Repeated
findings with the same identity may collapse into one entry. This affects
repeated smells/security findings, repeated dependency use sites, and any rule
whose violation identity is coarser than the individual occurrence.

### 2.3 `Violation::metricValue` is not a universal ratchet contract

Some rules expose a useful scalar, but others expose only one part of a
multi-axis decision, a count of matched criteria, a display-rounded value, or a
sentinel value for a binary finding. Examples include Data Class, God Class,
computed metrics, circular dependencies, and duplication clusters.

### 2.4 Violations are threshold-gated

Rules currently return violations only after applying configured thresholds.
Attaching ratchet semantics only to `Violation` would couple debt comparison to
policy changes and would provide no observation when a symbol is evaluated but
does not currently violate the rule.

### 2.5 Absence is not proof of resolution

A finding may be absent because:

- the symbol was fixed or removed;
- the rule was disabled;
- a path or namespace was excluded;
- only changed files were analyzed;
- parsing failed;
- a collection worker failed;
- an aggregate/project-level rule did not have complete input;
- execution was interrupted.

Baseline must distinguish these cases before reporting `resolved` or changing
the baseline file.

## 3. Goals

1. Make ratcheting the default for newly generated baselines.
2. Detect worsening of existing scalar, vector, occurrence, presence, and graph
   findings.
3. Preserve an explicit full-suppression mode for intentional use.
4. Keep comparison independent from human-readable violation messages.
5. Keep raw observations independent from threshold-gated violations.
6. Make incomplete analysis conservative: incomplete scope never resolves or
   mutates debt.
7. Preserve deterministic, portable, atomically written baseline files.
8. Provide an analysis-aware and conservative migration path from v5.
9. Make baseline lifecycle operations explicit and reviewable.
10. Expose current, captured, and delta values in human and machine output.

## 4. Non-Goals

1. Historical trend storage or a time-series database.
2. Automatic acceptance of new debt.
3. Automatic source-code fixes or refactoring suggestions beyond existing rule
   recommendations.
4. Inferring numeric values by parsing violation messages.
5. Treating severity changes alone as code regressions.
6. Supporting v5 directly during normal `check` execution.
7. Providing a runtime flag that silently overrides the mode stored in a
   committed baseline file.
8. Comparing two arbitrary Git revisions; `--new-since` remains a separate
   two-snapshot feature.

## 5. Architectural Decisions

### 5.1 Baseline v6 is required

- Newly generated baselines use version 6.
- `mode` is required and stored in the file.
- Generation defaults to `ratchet`.
- Full suppression requires explicit `mode: suppress`.
- A v5 file is rejected during normal `check` execution with an actionable
  migration command.
- v5 is never silently reinterpreted as ratchet.

### 5.2 Rules return violations and observations separately

`RuleInterface` changes to return a result object rather than a bare violation
list.

Contract:

```text
RuleInterface::analyze(AnalysisContext $context): RuleAnalysisResult

RuleAnalysisResult
  violations: list<Violation>
  baselineObservations: list<BaselineObservation>
  coverage: RuleCoverage
```

Rules remain stateless. An observation represents raw evaluated state; a
violation represents current policy evaluation. Scalar/vector rules emit an
observation for every relevant evaluated symbol, including symbols below the
current warning threshold.

### 5.3 Core owns portable observation contracts

New primitives live under `Core/Baseline` so Rules can produce observations
without depending on the Baseline implementation.

Planned contracts:

```text
BaselineObservation
  identity: BaselineObservationIdentity
  contract: BaselineContractReference
  kind: BaselineObservationKind
  axes: map<string, BaselineAxisObservation>
  debtCondition: BaselineDebtCondition
  occurrenceKey: string|null

BaselineObservationIdentity
  ruleName: string
  violationCode: string
  symbolPath: SymbolPath

BaselineContractReference
  id: string
  version: positive integer

BaselineAxisObservation
  name: string
  rawValue: int|float
  worseDirection: Higher|Lower
  epsilon: non-negative float

BaselineDebtCondition
  ScalarPredicate(version, boundary, direction)
  VectorPredicate(version, normalized named-axis boolean predicate)
  OccurrenceCount(positive integer)
  PresenceIdentity(stable discriminator)
  GraphIdentity(stable graph discriminator)

BaselineObservationKind
  Scalar | Vector | Occurrence | Presence | Graph
```

These are domain contracts, not serializer-specific data structures.

The kind-specific debt condition captures what was true when the entry was
generated. For scalar rules it is the effective warning boundary and
direction. For vector rules it is a normalized, versioned boolean predicate
over named axes, including any required-count semantics. These predicates are
data, not arbitrary executable expressions. Occurrence, presence, and graph
kinds carry their positive count or stable identity condition instead and do
not carry a scalar/vector predicate.

### 5.4 Observation identity is stable and deterministic

The logical key is derived from:

- rule name;
- violation code;
- canonical `SymbolPath`;
- contract id and version;
- optional stable occurrence discriminator.

Rules that can provide a stable occurrence discriminator must do so. When one
is unavailable, v6 stores an explicit bucket count and documents the remaining
one-out/one-in blind spot.

Duplicate logical identities are either combined by the declared occurrence
contract or rejected as an invariant violation. Collection order must never
change serialized output.

### 5.5 Analysis owns completeness and coverage

Baseline consumes completeness information but does not derive or guess it.

Planned contracts:

```text
AnalysisCompleteness
  Complete | Partial | Failed

RuleCoverage
  ruleName: string
  scopes: map<CoverageScopeIdentity, CoverageScopeResult>

CoverageScopeIdentity
  kind: Project | File | Symbol | Aggregate | Graph
  canonicalKey: string

CoverageScopeResult
  status: Evaluated | Excluded | Failed | NotScheduled
  reasonCode: string|null

AnalysisCoverage
  completeness: AnalysisCompleteness
  byRule: map<string, RuleCoverage>
  discoveryInventory
  parseFailures
  workerFailures
```

Coverage must be sufficient to answer: “Was this exact rule and relevant
file/symbol/graph scope successfully evaluated?”

Every observation contract declares the scope identity required to prove its
absence. Analysis aggregates `RuleCoverage` by rule and scope, intersects it
with discovery and failure inventories, and rejects conflicting scope results.
An entry is covered only when its rule-specific required scope is `Evaluated`;
global completeness flags alone are never sufficient.

The current pipeline executes rules sequentially after metric aggregation;
baseline observations therefore do not require parallel worker merging.
Collection workers contribute file/symbol/parse completeness, while
`RuleExecutor` contributes evaluated-rule coverage and deterministic
observation aggregation.

### 5.6 Comparison uses the current rule contract

The baseline file contains readable contract metadata, but runtime comparison
uses the current rule observation contract as authoritative. Stored axis names,
directions, epsilon, debt-condition kind/schema/version, and contract version
must match the current observation. Captured predicate parameters such as the old
warning boundary intentionally remain stored values and need not equal current
policy parameters.

Mismatch produces `incompatible`; it never falls back to suppression.

### 5.7 Threshold policy and ratchet debt are separate

- Configured thresholds decide whether current state produces a violation and
  its severity.
- Raw observations decide whether a continuing baselined finding became worse.
- A threshold-only change does not itself create a raw regression.
- A worsened raw observation is `regressed` even when relaxed current policy
  no longer produces a `Violation`; threshold relaxation cannot bypass the
  ratchet.
- A finding is `resolved` only when the raw observation proves that the
  stored debt predicate is now false under complete coverage, or when the
  required occurrence/presence identity is absent; current policy alone is not
  evidence of resolution.
- Tightening policy may create a new violation; if it has no baseline identity,
  it is reported as new.

### 5.8 Vector comparison is strict Pareto ratcheting

A vector finding is not worse only when no captured axis became worse beyond
its epsilon.

- Any worsened axis produces `regressed`.
- Improvements on other axes do not compensate for a worsened axis.
- Missing, extra, renamed, or empty axes produce `incompatible`.
- NaN and infinity are invalid observations and invalid serialized values.

### 5.9 Baseline writes remain atomic

All generate, migrate, update, and cleanup operations use temporary-file plus
atomic rename semantics.

No command writes when:

- analysis completeness is not `Complete`;
- any required rule/scope is unobserved;
- contract validation fails;
- migration contains unresolved ambiguity;
- serialization validation fails.

### 5.10 The breaking rule contract switches atomically

The return-type change of `RuleInterface::analyze()` and all built-in rule
implementations form one atomic work package. There is no intermediate commit
in which the interface returns `RuleAnalysisResult` while implementations
still return arrays, and no compatibility bridge becomes part of the product
architecture.

The package may be reviewed internally by rule category, but it is integrated
and validated as one unit. Analysis and Baseline packages start only after this
unit passes the full PHP type and rule-contract test suite.

## 6. Baseline v6 File Contract

Illustrative contract shape:

```json
{
  "version": 6,
  "mode": "ratchet",
  "generated": "2026-08-04T12:00:00+03:00",
  "violations": {
    "method:App\\OrderService::calculate": [
      {
        "rule": "complexity.cyclomatic",
        "code": "complexity.cyclomatic.method",
        "hash": "9ac318e5f6d12c41",
        "contract": "complexity.cyclomatic.method@1",
        "kind": "scalar",
        "allowance": {
          "debt_condition": {
            "kind": "scalar_predicate",
            "version": 1,
            "axis": "ccn",
            "operator": ">",
            "boundary": 20
          },
          "axes": {
            "ccn": {
              "value": 25,
              "worse": "higher",
              "epsilon": 0
            }
          },
          "occurrences": 1
        }
      }
    ]
  }
}
```

### 6.1 Required top-level fields

| Field        | Contract                                                  |
| ------------ | --------------------------------------------------------- |
| `version`    | Exactly `6`                                               |
| `mode`       | `ratchet` or `suppress`                                   |
| `generated`  | ISO 8601 timestamp                                        |
| `violations` | Canonical symbol keys mapped to deterministic entry lists |

Summary counts may be serialized for readability but are derived values and
must be validated against the entry collection.

Canonical ordering and all content other than `generated` are deterministic
for the same analysis result and contracts. Time comes from an injected clock;
tests use a fixed clock. A no-op lifecycle command preserves the existing
timestamp and bytes instead of rewriting the file.

### 6.2 Entry invariants

- `rule`, `code`, `hash`, `contract`, `kind`, and `allowance` are required.
- Ratchet allowances require exactly one debt-condition variant compatible
  with their observation kind and contract.
- Contract ids end with an explicit positive version.
- Axis names are unique and deterministically sorted.
- Scalar entries have exactly one axis.
- Vector entries have at least two axes.
- Scalar/vector entries require their matching normalized, versioned predicate.
- Occurrence entries require a positive count condition; presence/graph entries
  require their stable identity condition and may have no numeric axes.
- `occurrences` is a positive integer; zero-count entries are not serialized.
- Numeric values are finite.
- Epsilon is finite and non-negative.
- Duplicate entry identities are invalid.
- Arbitrary expression strings and unknown predicate operators are invalid.

### 6.3 `mode: suppress`

Suppress mode preserves intentional full suppression:

- matching identity is filtered regardless of value or count growth;
- contract metadata is retained for readability and future migration;
- unmatched identities remain visible;
- the mode is never selected implicitly at runtime.

## 7. Comparison Semantics

### 7.1 Statuses

| Status         | Meaning                                                        |
| -------------- | -------------------------------------------------------------- |
| `new`          | Current violation has no baseline entry                        |
| `matched`      | Current debt is equivalent to captured allowance               |
| `improved`     | Current debt is better but the captured debt condition remains |
| `regressed`    | At least one axis/count became worse                           |
| `resolved`     | Captured debt condition is absent in proven covered scope      |
| `unobserved`   | Coverage cannot prove that the finding was evaluated           |
| `incompatible` | Current and stored contracts cannot be compared                |

Status is computed from raw observations before current-policy presentation.
For a baselined identity, `regressed` takes precedence over absence of a
current `Violation`. Such a result is emitted as a baseline regression
diagnostic and fails the check independently of `fail_on`; human-oriented
formatters present it at error severity, while machine formats retain a stable
`baseline-regression` reason code so it is not confused with rule severity.

### 7.2 Scalar

- `Higher`: current greater than captured plus epsilon is worse.
- `Lower`: current less than captured minus epsilon is worse.
- Equivalent values are `matched`.
- Better values are `improved` while the captured debt condition remains;
  the stored predicate becoming false is `resolved` under complete coverage.

### 7.3 Vector

- Compare axes by name.
- Any worse axis means `regressed`.
- At least one better axis with none worse means `improved`.
- Missing/extra axes or shape change means `incompatible`.
- With no worsened axis, the stored vector predicate becoming false means
  `resolved`; otherwise the result is `improved` or `matched`.

### 7.4 Occurrence/count

- Current count greater than captured count means `regressed`.
- Equal count means `matched`.
- Lower positive count means `improved`.
- Zero current findings means `resolved` only under proven coverage.
- Stable occurrence keys take precedence over count buckets.

### 7.5 Presence and graph

- Stable identity present means `matched`.
- A new stable identity is `new`.
- Missing identity is `resolved` only under complete relevant coverage.
- Graph contract identity includes the required source, target, dependency
  type, SCC/cycle membership, or duplication-cluster discriminator defined by
  the rule.

### 7.6 Missing symbols and partial scope

- Symbol absent from a complete project discovery may be `resolved`.
- Symbol absent from a changed-file, excluded, failed, or interrupted scope is
  `unobserved`.
- Disabled rules never resolve their entries.
- Aggregate and graph entries require complete aggregate/graph coverage.

## 8. CLI and Lifecycle

### 8.1 Commands

```text
bin/qmx baseline:generate <baseline> <paths...> [--mode=ratchet|suppress] [--force]
bin/qmx baseline:migrate <baseline> <paths...> [--mode=ratchet|suppress]
bin/qmx baseline:rebase-contracts <baseline> <paths...> --contract=<id>... --force
bin/qmx baseline:update <baseline> <paths...>
bin/qmx baseline:cleanup <baseline> <paths...>
bin/qmx check <paths...> --baseline=<baseline>
```

The names follow the existing `noun:verb` management-command convention.

### 8.2 Generate

- Defaults to ratchet mode.
- Refuses to overwrite an existing file unless `--force` is explicit.
- Requires complete analysis.
- Captures all current violations and their independent observations.
- Produces canonically ordered output; only the injected generation timestamp
  may differ between two otherwise equivalent fresh generations.

### 8.3 Migrate

- Accepts v5 input only.
- Writes ratchet mode by default; preserving suppression requires explicit
  `--mode=suppress`.
- Performs a complete current analysis.
- Matches current findings against v5 hashes.
- Reconstructs v6 observations only for debt represented by v5.
- Never adds unmatched current violations.
- When one v5 hash matches multiple indistinguishable current occurrences, it
  captures allowance `1`; the additional occurrences remain visible as an
  immediate regression in ratchet mode. Suppress mode intentionally continues
  to hide matching multiplicity growth. Migration never guesses the lost
  historical count in either mode.
- Drops an unmatched v5 entry only when coverage proves it resolved.
- Fails without writing when an unmatched entry remains ambiguous.
- Reports matched, resolved, unobserved, incompatible, and rejected counts.

Offline migration is impossible because v5 does not contain values, axes,
contract versions, or multiplicity.

### 8.4 Update

- Requires complete analysis.
- Only tightens existing allowances and reduces occurrence counts.
- Never adds a new baseline identity.
- Does not silently remove resolved entries.
- Refuses any change that would increase accepted debt.
- Preserves the stored debt condition, contract id/version, and captured policy
  boundary; it updates only comparable allowance values/counts. Changing the
  debt condition or contract requires `baseline:rebase-contracts`.

### 8.5 Cleanup

- Requires complete analysis and coverage for every removed entry.
- Removes only confirmed resolved entries.
- Does not infer class/method/namespace existence from violation presence.
- Uses discovered symbol inventory and rule coverage.

### 8.6 Removed interface

The existing `--generate-baseline` check option is removed to avoid two
interfaces for the same lifecycle operation. This is an intentional breaking
change; baseline generation becomes `baseline:generate`.

### 8.7 No baseline acceptance command in MVP

There is no `baseline:accept` command in the initial version. A baseline is for
legacy adoption and controlled debt reduction, not routine acceptance of new
violations. Intentional local exceptions use threshold overrides or explicit
suppression mechanisms.

### 8.8 Rebase incompatible v6 contracts

`baseline:rebase-contracts` is the only lifecycle path for a known rule
algorithm/contract change. It:

- accepts v6 input only;
- requires one or more explicit old contract ids;
- requires complete rule-specific coverage;
- replaces allowances only for existing identities using the selected old
  contracts;
- never adds new identities or removes resolved entries;
- prints old and replacement contract/value data before writing;
- requires the explicit `--force` confirmation flag;
- writes atomically or leaves the original bytes unchanged.

This operation explicitly accepts recalibration risk and is distinct from the
monotonic `baseline:update` command.

## 9. Reporting and Exit Behavior

### 9.1 Human output

A regression includes:

- rule and symbol;
- captured value/count;
- current value/count;
- delta and direction;
- current rule severity and recommendation when a current policy violation
  exists;
- explicit baseline-regression severity/reason otherwise;
- contract id when verbose.

### 9.2 Machine output

JSON and SARIF expose native stable fields for:

- baseline status;
- captured values;
- current values;
- deltas;
- contract id/version;
- coverage/incompatibility reason.

Formats whose published schema has no extension field, including Checkstyle
and GitLab Code Quality, preserve schema validity and encode the stable status,
reason code, captured/current values, and delta in their existing description
or message field. GitHub output follows the capabilities of its selected
annotation format. The implementation must not invent invalid attributes.

### 9.3 Exit behavior

- New current-policy violations participate in the normal `fail_on` policy.
- Every raw baseline regression fails independently of `fail_on`, including
  when relaxed current policy emits no violation.
- `matched` and `improved` findings are filtered from normal output.
- `resolved` is informational.
- `unobserved` and `incompatible` produce explicit diagnostics and prevent
  baseline mutation.
- Loader/schema errors preserve the existing configuration/error exit class.

## 10. Interaction With Other Features

### 10.1 Threshold overrides and rule configuration

Configuration and inline thresholds run as normal. Observations are collected
independently. A policy-only change may change current violation presentation,
but cannot manufacture a raw regression or change `resolved` status, which is
evaluated against the stored debt condition.

### 10.2 Git and changed-file reporting

- Baseline comparison runs against the available analysis scope before
  presentation filtering.
- A full analysis may be presented as changed files only.
- An actual changed-files-only analysis is partial and cannot resolve, update,
  clean, generate, or migrate a project baseline.
- Project/namespace/graph observations require full project scope.

### 10.3 Exclusions and disabled rules

Excluded symbols and disabled rules are recorded in coverage and yield
`unobserved`, not `resolved`.

### 10.4 Contract and algorithm changes

Any algorithm change that affects observation meaning, axes, direction,
epsilon, identity, or occurrence semantics increments the rule contract
version. Existing entries become `incompatible` until explicitly recalibrated
with `baseline:rebase-contracts`.

### 10.5 Computed metrics

Dynamic computed metrics require a deterministic contract id derived from the
normalized formula, level, axis semantics, and observation version. Display
rounding is never used for comparison.

## 11. Edge Cases

1. NaN, positive infinity, and negative infinity are rejected.
2. `-0.0` is normalized deterministically.
3. Floating-point comparison uses contract epsilon, not formatted decimals.
4. Empty vectors and duplicate axis names are invalid.
5. Missing or extra vector dimensions are incompatible.
6. Zero occurrence count is not serialized.
7. Equal counts with one removed and one new occurrence remain a documented
   limitation when no stable occurrence key exists.
8. Renamed/moved symbols become new plus resolved unless a future explicit
   rename map is introduced.
9. Deleted symbols resolve only after complete discovery.
10. Parse failures, worker failures, interruption, disabled rules, and
    exclusions produce unobserved coverage.
11. Circular-dependency identity must not depend on arbitrary cycle starting
    vertex or traversal order.
12. Duplication identity must be invariant to nondeterministic copy ordering.
13. Dependency identity includes source, target, dependency type, and stable
    use-site discriminator where available.
14. Multiple violations with the same hash preserve multiplicity.
15. Same contract id with a changed shape is incompatible.
16. v5 migration never treats an unmatched current violation as accepted debt.
17. A failed migration leaves the original file byte-for-byte unchanged.
18. A generated file is portable across project roots.
19. Concurrent writers cannot leave a partial baseline file.
20. Partial analysis cannot update summary counts or timestamps.

## 12. Work Packages

Every tracked path has one owning package. Parallel packages use separate
worktrees. A package may add new test files only inside its listed test
directories; it may not edit a test owned by another package. No package
stashes, restores, or cleans the shared main worktree.

### P0 — Contract freeze

**Files:**

- `docs/plan/ratchet-baseline-v6.md`

**Dependencies:** none.

**Decisions frozen:** status semantics, observation identity, coverage,
comparison kinds, lifecycle commands, v5 migration policy.

**DoD:** plan review approved; no unresolved contract question blocks P1; the
package does not pre-create implementation or ADR files owned by later
packages.

### P1 — Atomic rule-result and observation migration

**Files:**

- `src/Core/Rule/RuleInterface.php`
- `src/Core/Rule/HierarchicalRuleInterface.php`
- planned `src/Core/Rule/RuleAnalysisResult.php`
- planned `src/Core/Baseline/*`
- `src/Rules/AbstractRule.php`
- `src/Rules/{CodeSmell,Complexity,ComputedMetric,Coupling,Design,Duplication,Maintainability,Security,Size,Structure}/**`
- `src/Architecture/Rules/**`
- `src/Analysis/RuleExecution/**`
- `tests/Unit/Core/Rule/**`
- planned `tests/Unit/Core/Baseline/**`
- `tests/Unit/Rules/**`
- `tests/Architecture/**`
- `tests/Integration/Rules/**`
- `tests/Unit/Analysis/RuleExecution/**`
- `tests/Integration/Pipeline/AnalysisPipelineIntegrationTest.php`
- `tests/Unit/Infrastructure/Console/Command/RulesCommandTest.php`
- `tests/Unit/Infrastructure/DependencyInjection/CompilerPass/RuleRegistryCompilerPassTest.php`
- `src/Core/README.md`
- `src/Rules/README.md`
- `src/Architecture/README.md`

**Dependencies:** P0.

**Contracts:** observation/axis/kind/contract-reference/result/coverage
primitives; `RuleInterface::analyze()` return type; all built-in scalar,
vector, occurrence, presence, and graph observations; deterministic rule-result
aggregation.

**DoD:** the interface, every built-in implementation, direct test fixture, and
`RuleExecutor` switch in one integrated diff; no array-returning rule remains;
Core stays dependency-free; threshold changes do not change raw axis values or
observation identities; rule/Core/RuleExecution tests and PHPStan pass. No
partial P1 commit is allowed.

### P2 — Analysis aggregation and completeness

**Files:**

- `src/Analysis/Collection/**`
- `src/Analysis/Pipeline/**`
- planned `src/Analysis/Coverage/*`
- `tests/Unit/Analysis/Collection/**`
- `tests/Unit/Analysis/Pipeline/**`
- planned `tests/Unit/Analysis/Coverage/**`
- planned `tests/Integration/Pipeline/BaselineCoverageIntegrationTest.php`
- `src/Analysis/README.md`

**Dependencies:** completed and validated P1.

**Responsibilities:** deterministic observation aggregation, evaluated-rule
coverage, collection completeness, failure propagation, aggregate/graph scope
per-rule/per-scope results.

**DoD:** partial/failed runs are distinguishable from complete runs; duplicate
identity behavior is deterministic; interrupted/failed collection cannot
produce complete coverage.

### P3 — Baseline v6 domain and lifecycle

**Files:**

- `src/Baseline/**`
- `tests/Unit/Baseline/**`
- `tests/Integration/Baseline/**`
- `src/Baseline/README.md`

**Dependencies:** completed P1 contracts. May run in parallel with P2 using
Core contract fixtures and must not infer Analysis coverage.

**Responsibilities:** v6 schema, loader validation, canonical writer,
ratchet/suppress comparator, status model, v5 migration, monotonic update,
cleanup, and explicit v6 contract rebase services.

**DoD:** scalar/vector/count/presence matrices pass; v5 multiplicity allowance
is never guessed above one; malformed files fail closed; all writes are atomic;
failed/no-op operations preserve original bytes; v5 is rejected outside
migration; rebase changes only selected existing incompatible identities.

### P4 — CLI, DI, and reporting integration

**Files:**

- `src/Infrastructure/Console/Command/*Baseline*`
- `src/Infrastructure/Console/Command/CheckCommand.php`
- `src/Infrastructure/Console/CheckCommandDefinition.php`
- `src/Infrastructure/Console/BaselinePresenter.php`
- `src/Infrastructure/Console/ViolationFilter*`
- `src/Infrastructure/DependencyInjection/**`
- `src/Reporting/**`
- planned `tests/Functional/Infrastructure/Console/Baseline/**`
- planned `tests/Integration/Reporting/BaselineStatus/**`
- planned `tests/Unit/Infrastructure/Console/Baseline/**`
- `src/Infrastructure/README.md`
- `src/Reporting/README.md`

**Dependencies:** P2 and P3 integrated and validated.

**Responsibilities:** lifecycle commands, completeness guards, comparison
order, exit codes, human/machine diagnostics, and removal of
`--generate-baseline`.

**DoD:** CLI lifecycle matrix passes; partial/failed runs cannot mutate files;
all structured formats expose their representable stable baseline fields or a
documented standard-compatible fallback; raw regressions fail even without a
current policy violation; the old flag fails with an actionable message.

### P5 — Cross-component integration tests

**Files:**

- planned `tests/Integration/BaselineRatchet/**`
- planned `tests/Functional/Infrastructure/Console/Command/BaselineLifecycleTest.php`
- planned `tests/Fixtures/BaselineV6/**`

**Dependencies:** P4.

**Responsibilities:** verify seams without reopening production-file ownership:
rule result to per-scope coverage, coverage to comparator, comparator to
reporter/exit status, and every lifecycle command to atomic persistence.

**DoD:** a full project run covers scalar/vector/count/presence/graph paths;
relaxed thresholds cannot hide raw regression; disabled/excluded/failed scopes
stay unobserved; v5 ambiguous multiplicity remains visible; contract rebase is
explicit and scoped.

### P6 — ADR and user documentation

**Files:**

- final `docs/adr/001x-ratchet-baseline-v6.md`
- `docs/adr/README.md`
- `docs/ARCHITECTURE.md` if pipeline contracts change materially
- `website/docs/usage/baseline.md`
- `website/docs/usage/baseline.ru.md`
- `website/docs/usage/cli-options.md`
- `website/docs/usage/cli-options.ru.md`
- `website/docs/ci-cd/github-actions.md`
- `website/docs/ci-cd/github-actions.ru.md`
- `website/docs/ci-cd/other-ci.md`
- `website/docs/ci-cd/other-ci.ru.md`
- `CHANGELOG.md`

**Dependencies:** P5 behavior frozen.

**Responsibilities:** record why, document migration and lifecycle, update EN
and RU together, document breaking changes and count-fallback limitations.

**DoD:** documentation matches executable CLI/schema; EN/RU parity; MkDocs
strict build passes without warnings.

## 13. Execution Sequence

1. Approve P0 contract plan.
2. Implement P1 as one atomic package; category-level internal review is
   allowed, but no partial interface migration is integrated.
3. Run P1 rule/Core/RuleExecution tests and PHPStan, then perform standard
   contract review.
4. Fix the reviewed P1 revision as the common base for P2 and P3 worktrees.
5. Execute P2 and P3 in parallel with disjoint production and test files.
6. Verify each diff and package DoD, then integrate P2 and P3 one at a time.
7. Execute P4 after both producer and comparator contracts are integrated.
8. Execute P5 seam tests without modifying earlier package files.
9. Run full validation and self-analysis.
10. Perform extended review with three independent reviewers because the change
   introduces a new domain contract and changes more than three cross-domain
   interfaces.
11. Verify every finding, fix all confirmed severities, and rerun validation.
12. Complete P6 documentation/ADR against frozen behavior.
13. Run final full validation, website build, and a second seam-focused review
    if the first review found contract or coverage issues.

## 14. Test Plan

### 14.1 Core contracts

- scalar/vector/occurrence/presence/graph construction invariants;
- finite numeric values and epsilon;
- identity equality and canonicalization;
- duplicate/missing axis rejection;
- contract-version validation;
- kind-specific debt-condition and scalar/vector predicate validation;
- `RuleAnalysisResult` immutability.

### 14.2 Rule observations

- observations exist below current warning thresholds;
- changing thresholds changes violations and current predicate parameters, but
  not raw axes or observation identities;
- inverted direction rules;
- multi-axis completeness;
- raw versus display-rounded values;
- stable dynamic computed-metric contract ids;
- stable cycle/duplication identity;
- occurrence multiplicity.

### 14.3 Coverage

- complete run;
- changed-file/partial run;
- disabled rule;
- excluded path/namespace;
- parse failure;
- collection worker failure;
- interrupted execution;
- incomplete graph/aggregate scope;
- deleted versus unobserved symbols.

### 14.4 Comparison

- scalar higher/lower and epsilon boundaries;
- scalar/vector stored-predicate resolution independently of current policy;
- vector matched/improved/regressed/incompatible cases;
- occurrence count growth/reduction/zero;
- stable occurrence-key replacement;
- presence and graph identity;
- mode ratchet versus suppress;
- contract version/shape mismatch;
- current new violation not present in baseline.

### 14.5 Serialization

- v6 round-trip;
- deterministic ordering and byte stability;
- fixed-clock generation and no-op timestamp preservation;
- path portability;
- derived-count validation;
- malformed mode/kind/contract/axis/count values;
- NaN/infinity rejection;
- atomic write and failed-rename cleanup;
- concurrent writer behavior.

### 14.6 Migration

- matched v5 finding reconstructs current observation;
- resolved v5 entry under complete coverage;
- unmatched current finding is never accepted;
- ambiguous/unobserved v5 entry aborts migration;
- collapsed v5 multiplicity captures allowance one and reports every additional
  current occurrence as regression;
- failure preserves original v5 bytes;
- migration report counts are deterministic.

### 14.7 CLI and reporting

- generate default ratchet and explicit suppress;
- overwrite refusal and `--force`;
- update monotonicity;
- cleanup coverage guard;
- explicit selected-contract v6 rebase and mandatory `--force` guard;
- removed old generation flag;
- current/captured/delta human output;
- JSON/SARIF native fields and schema-compatible fallback encoding for
  Checkstyle/GitLab/GitHub;
- exit codes under `fail_on`;
- `resolved`, `unobserved`, and `incompatible` diagnostics;
- git presentation scope versus actual partial analysis.

### 14.8 Full validation

- `composer check`;
- `bin/qmx check src/` self-analysis;
- strict MkDocs build;
- private-leak guard;
- benchmark regression suite when computed/health observations are touched.

## 15. Definition of Done

The feature is complete only when all of the following hold:

1. New generation writes deterministic v6 ratchet files by default.
2. Explicit suppress mode preserves intentional full suppression.
3. v5 is rejected during check and migrates only through full reanalysis.
4. Every enabled built-in rule emits an explicit supported observation or an
   intentionally documented presence/count contract.
5. Threshold changes do not alter raw axes or observation identities.
6. Stored, kind-specific debt conditions make resolution deterministic without
   consulting current thresholds.
7. Scalar, vector, occurrence, presence, and graph regressions are detected.
8. Multiplicity is not silently collapsed or guessed during v5 migration.
9. Partial, failed, excluded, disabled, and interrupted scopes never resolve or
   mutate entries.
10. Contract mismatch is visible and cannot fall back to suppression.
11. Generate/migrate/update/cleanup/rebase are atomic and coverage-guarded.
12. Update is monotonic and never accepts new debt.
13. All human and machine formats expose stable ratchet status details within
    their published schemas.
14. Component documentation and EN/RU website documentation match behavior.
15. ADR records the architectural rationale and rejected alternatives.
16. Full project validation, self-analysis, benchmarks where applicable, and
    strict website build pass.
17. Extended three-reviewer review has no unresolved confirmed findings.

## 16. Known Residual Limitations

1. Count fallback cannot distinguish one removed occurrence from one new
   occurrence at equal count; rules should add stable occurrence keys over
   time.
2. Symbol rename/move tracking is out of scope and appears as resolved plus new.
3. Ratchet baseline is not historical trend analysis.
4. Policy changes may change current violation presentation, but do not change
   raw comparison or resolution against the stored debt condition.
5. Extension rules must adopt the new `RuleAnalysisResult` and observation
   contracts; this is an intentional breaking change.

## 17. Rejected Alternatives

### Add one `value` field to v5

Rejected because it cannot represent inverted, vector, occurrence, graph, or
dynamic computed-metric contracts and would silently miscompare several
built-in rules.

### Derive values from violation messages

Rejected because message text is presentation, unstable, localized in the
future, and intentionally excluded from baseline identity.

### Store only severity or configured threshold

Rejected because this tracks policy changes rather than code debt.

### Silently treat v5 as ratchet

Rejected because v5 has no captured values, axes, direction, epsilon,
multiplicity, or coverage evidence.

### Keep suppress as the default

Rejected because it preserves the exact blind spot this feature is intended to
close: existing debt can worsen indefinitely without a CI signal.

### Let Baseline infer completeness

Rejected because absence of a violation cannot distinguish resolution from
disabled rules, exclusions, parse/worker failure, or partial analysis.

### Add `baseline:accept` in MVP

Rejected because routine acceptance of new debt conflicts with ratcheting and
creates an easy path to hide regressions.
