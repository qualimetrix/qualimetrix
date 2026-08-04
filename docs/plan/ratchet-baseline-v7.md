# Ratchet Baseline v7 Plan

**Status:** Proposed — revision 7.1 (supersedes `ratchet-baseline-v6.md`)
**Date:** 2026-08-04
**Target release:** TBD
**Review status:** Round 1 complete (three independent reviewers). All CRITICAL
and HIGH findings folded in below; round 2 required before P0 freeze.

## How To Execute This Plan From A Clean Session

This document is self-contained. Everything decided during planning is recorded
here or in a linked artefact; nothing load-bearing lives only in a chat
transcript.

**Read in this order before touching code:**

1. `AGENTS.md` — working rules. Two sections matter especially here: *Backward
   Compatibility Policy* (breaking changes are cheap and expected; the hard
   requirement is history — `Breaking` entries in `CHANGELOG.md`, an ADR for
   non-obvious rationale, migration steps written from the consumer's side) and
   *Decision framework for new features*.
2. This plan, in full. §5 carries the architectural decisions, §11 the work
   packages and file ownership, §13 the test plan.
3. [ADR 0016](../adr/0016-subject-cohesion.md) — the directory-layout rule that
   settles §16 and governs where new types go.
4. `src/Baseline/README.md` and `docs/ARCHITECTURE.md` for current state.

**Prerequisites outside this plan.** Two pre-existing defects were found during
planning and are tracked separately. Neither blocks P1a, P1b, P2, P3, or P4:

- Unstable cycle identity in `CircularDependencyRule` (§2.7). **Graph-kind
  ratcheting must not ship before this lands** — verify its state before P5.
- Expanding the Architecture slice into enforced internal sub-layers (ADR 0016).
  Unrelated to this feature; it only touches `qmx.yaml`, so coordinate if a
  package of this plan is editing that file at the same time (P1a owns it).

**Decision state.** Settled: the allowance rule (§5.1), observations riding on
violations (§5.2), contract placement in Core (§5.3), the three suppression
categories (§5.6), the file schema (§6), the status model (§7.1), the lifecycle
commands (§8), exit behaviour (§9.3), layered layout (§16). Open: nothing that
blocks P1a. The one judgement call deliberately left to implementation is the
naming of the migration disposition-plan schema fields, which P3 specifies.

**Review state.** Round 1 is complete — three independent reviewers examined
revision 7.0 against the code; every CRITICAL and HIGH finding is folded into
7.1 and summarised in §0.2 with the correction it forced. **Round 2 has not run.
P0 is not frozen and implementation must not start until it does.** Round 2
should concentrate on what round 1 did not reach: the v5 migration path in
depth, `mode: suppress`, and any defect introduced by the 7.1 corrections
themselves.

**This document is temporary.** Once the feature has landed, delete
`docs/plan/` — both this revision and the superseded `ratchet-baseline-v6.md`.
The rationale that must outlive it belongs in the ADR produced by P6; anything
still only in this file when P6 is written has not been recorded properly yet.

**A note on this plan's own history.** Revision 7.0 fixed v6's architectural
problems and introduced three new blockers of its own, all caught by round 1.
That is the expected failure mode here: the design is a chain of consequences
from one premise (§5.1), and it is easy to take one step further than the
premise licenses. When changing anything in §5, re-derive the consequences
rather than patching locally.

## 0. Revision History And Governing Decision

### 0.1 The governing change (7.0)

v6 made the ratchet absolute: a captured value was the ceiling forever, even
after the team deliberately raised the threshold. There was no way to express
"we have decided 50 is acceptable" — `update` only tightens,
`rebase-contracts` covers only rule-contract changes, `accept` was rejected by
design, and the only remaining path was `generate --force`, which silently
re-accepts *all* debt.

v7 defines the allowance as the **more permissive of the captured value and the
rule's violation-onset boundary**, per axis, in the axis's worse-direction
(`max` for higher-is-worse, `min` for lower-is-worse). Raising a threshold in
`qmx.yaml` is the legitimate, reviewable way to accept more debt; the ratchet
guards everything above the newly declared line. There is no second acceptance
surface.

Because `allowance >= onset boundary`, a ratchet regression is always also a
current violation. Observations therefore only need to exist where a violation
exists, which removed v6's O(symbols × rules) retention, its stored predicate
DSL, its `RuleInterface` return-type break, and its per-rule coverage maps.

### 0.2 What round 1 changed (7.1)

Three reviewers examined 7.0 against the code. Their findings, and the
corrections they forced, are folded in below. The load-bearing ones:

| Finding                                                                                                                                                                                                                                     | Correction                                                                                                                  |
| ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- | --------------------------------------------------------------------------------------------------------------------------- |
| `currentEffectiveBoundary` was ambiguous; rules pick their threshold by the severity tier the current value landed in, so the allowance widened as the value grew — the ratchet blinded itself. Independently found by all three reviewers. | §5.1: the boundary is the **violation-onset** boundary, never the tier-matched one.                                         |
| Removing v6's independent failure path was a step wider than the premise: `fail_on` defaults to `error`, so a regression inside the warning tier printed and exited 0.                                                                      | §9.3: `regressed` fails the build regardless of the finding's severity, with an explicit opt-out.                           |
| `qmx.yaml` grants Baseline and Reporting `[core]` only, so a comparator in `src/Baseline` cannot consume a coverage DTO living in `src/Analysis`. `composer check` would fail at integration.                                               | §5.5, §11: the coverage **contract** and the status enum live in Core, owned by P1a; Analysis owns only the implementation. |
| `exclude_paths` / `exclude_namespaces` / per-rule exclusions are post-`analyze()` filters, not evaluation-exclusions. Combined with a global refuse-to-write, every lifecycle command would refuse on this project's own config.            | §5.6: taxonomy corrected against the code; §5.10: the write guard is per-entry, not global.                                 |
| `occurrence_key` was declared part of identity but had no field in the file schema — it could not survive a load/save cycle.                                                                                                                | §6: field added, with a deterministic sort rule.                                                                            |
| `resolved` conflated "the code was fixed" with "the policy moved". Cleanup would delete captured values that still represent real debt.                                                                                                     | §6, §7.1: the captured onset boundary is stored per axis (one number, not a predicate), and `resolved` carries a reason.    |
| An axis can be genuinely absent — `TccLccCollector` skips classes with fewer than two public methods, and `GodClassRuleTest::itAdjustsEvaluableCountWhenTccIsMissing` pins that behaviour. 7.0 required every axis to be numeric.           | §5.3, §6.2: an entry may carry a null axis; a *contract* shape change remains `incompatible`.                               |
| No way to obtain a rule's current contract when it emits no violation, so a forgotten version bump read as `resolved` instead of `incompatible`.                                                                                            | §5.7: an independent contract registry, populated from rules at boot.                                                       |
| P1 owned 44 rule files plus 74 collectors and blocked everything else; several files it needed belonged elsewhere.                                                                                                                          | §11: split into P1a/P1b/P1c with corrected ownership.                                                                       |

Corrections to 7.0's own claims, for the record: `DataClassRule` is **not** a
dynamic compound rule (its criteria are fixed), so §5.9 applies to `GodClassRule`
alone; and there is no changed-files-only *analysis* mode — `--report=git:*` is
a presentation filter, so 7.0's partial-scope branch described a mode that does
not exist.

## 1. Executive Summary

Baseline v5 is an identity-only suppression snapshot: once a violation is
listed, it stays hidden however much worse it gets. v7 makes **ratcheting** the
default for newly generated baselines. A baselined finding stays hidden while
its measured debt is within the allowance, and is reported when it exceeds it.
Full suppression remains available through an explicit `mode: suppress` file.

## 2. Preconditions and Current-State Facts

Each fact below was verified against the code during review; the citation is
the verification point, not an implementation instruction.

### 2.1 v5 stores identity, not magnitude

`BaselineEntry` holds `rule` and `hash`. The hash excludes line, message,
severity, threshold, and value, so a metric growing from 25 to 100 still
matches its entry.

### 2.2 Current deduplication loses multiplicity

Generation deduplicates by canonical symbol plus hash, collapsing repeated
findings that share an identity — repeated smells and security findings,
repeated dependency use-sites, and any rule whose identity is coarser than the
occurrence.

### 2.3 `Violation::metricValue` is not a universal debt contract

`GodClassRule` sets it to the *count of matched criteria*;
`CircularDependencyRule` sets it to the cycle size. Others expose a
display-rounded value, a binary sentinel, or one axis of a multi-axis decision.
A structured observation is required.

### 2.4 Rule thresholds are tier-dependent

Rules select `$threshold` by the severity tier the measurement fell into
(`ComplexityRule`, `ConstructorOverinjectionRule`, and 30 Options classes share
this shape). `Violation::threshold` is therefore **not** a stable boundary and
must not be used as one — see §5.1.

### 2.5 Exclusions are post-evaluation filters

`RuleExecutor` runs `analyze()` in full and then drops violations from excluded
namespaces and paths; `ViolationFilterPipeline` applies global exclusions after
all rules have run, in the order baseline → inline suppression → path →
namespace → git. Only the discovery-level `exclude` key prevents measurement.
Additionally, baseline generation today consumes the raw pre-filter violation
list, so exclusions do not currently affect what is captured.

### 2.6 There is no changed-files-only analysis

`--report=git:*` is a filter step in `ViolationFilterPipeline`; discovery and
the pipeline have no changed-file mode. Analysis is always full.

### 2.7 Two pre-existing defects bound this work

- `CircularDependencyRule` derives a cycle's `symbolPath` from `$classes[0]`,
  taken from the Tarjan SCC stack with no canonical ordering, so cycle identity
  depends on traversal order. Fixed **outside this plan**; graph-kind ratcheting
  must not ship before it lands.
- `ViolationHasher` feature-detects `xxh3` with a `sha256` fallback. On PHP
  `^8.4` the fallback is dead code, so the practical risk is low, but a portable
  format must pin its algorithm — §6.1.

## 3. Goals

1. Ratcheting is the default for newly generated baselines.
2. Worsening is detected for scalar, vector, occurrence, presence, and graph
   findings.
3. An explicit full-suppression mode is preserved.
4. Comparison never depends on human-readable messages.
5. Unevaluated scope is conservative: it never resolves or mutates an entry.
6. Files stay deterministic, portable, and atomically written.
7. v5 migrates through an explicit, reviewable, two-phase process.
8. Output stays legible to a human and to an agent at 500+ entries.
9. A 1M-line codebase analyses within a `memory_limit` of 2G.

## 4. Non-Goals

1. Historical trend storage.
2. Automatic acceptance of new debt.
3. Inferring values by parsing violation messages.
4. Treating severity changes alone as code regressions.
5. Supporting v5 during normal `check` execution.
6. A runtime flag overriding the mode stored in a committed file.
7. Comparing two arbitrary Git revisions.
8. Per-axis ratcheting *inside* a compound rule — §14.1.
9. Introducing a changed-files-only analysis mode (§2.6).

## 5. Architectural Decisions

### 5.1 The allowance rule

For a baselined identity and each axis:

```text
allowance(axis) = more-permissive-of(captured(axis), onsetBoundary(axis))
                  in the axis's worse-direction
```

`onsetBoundary` is the **violation-onset boundary**: the most permissive
configured boundary at which the rule emits a violation at all — in a
warning/error rule, the warning tier. It is *never* the boundary of the tier the
current measurement happened to land in. Deriving it from `Violation::threshold`
is explicitly forbidden (§2.4): with `warning=10, error=20` and a captured 15, a
tier-derived boundary would make a growth to 20 read as `matched`, and the
ratchet would loosen precisely as the code got worse.

The onset boundary reflects configuration, presets, per-level options, and
inline `@qmx-threshold` overrides applicable to that symbol; it does not depend
on the measured value.

Rules with no numeric boundary — fixed-severity rules such as layer violations,
and binary detectors such as hardcoded credentials — have no onset boundary.
Their kinds are Presence or Graph and the allowance degenerates to identity
presence, which is the v6 behaviour.

Because `allowance` is never stricter than the onset boundary, **a ratchet
regression is always also a current violation.** This invariant is what makes
observations-on-violations sufficient; §13 requires it to be tested directly,
including the warning→error transition.

### 5.2 Observations accompany violations

A rule attaches a structured observation to each `Violation` it emits. There is
no separate observation channel, no second traversal, and no change to
`RuleInterface::analyze()`'s return type. Rules remain stateless.

`Violation` gains one observation-carrying member. Because `Violation` already
carries a constructor-overinjection threshold override, the observation is a
single bundled value object, not a set of new scalar parameters.

### 5.3 Core owns the observation and coverage contracts

Contracts live under `Core/Observation` — named for what they describe, measured
debt, rather than for the feature that consumes them. This keeps Core free of
feature naming and answers "why does a rule know about baselines": it does not.

```text
DebtObservation
  contract: ContractReference
  kind: ObservationKind
  axes: map<string, AxisObservation>
  occurrenceKey: string|null

ContractReference
  id: string
  version: positive integer

AxisObservation
  name: string
  rawValue: int|float|null     // null = metric genuinely unavailable for this symbol
  onsetBoundary: int|float|null
  worseDirection: Higher|Lower
  epsilon: non-negative float

ObservationKind
  Scalar | Vector | Occurrence | Presence | Graph
```

A null `rawValue` is a legal, first-class state, not a shape change: a class
with fewer than two public methods has no TCC at all
(`TccLccCollector`), and `GodClassRuleTest::itAdjustsEvaluableCountWhenTccIsMissing`
already pins that behaviour. §5.8 distinguishes a null axis value from a
contract shape change.

The **coverage contract** — its interface, its scope-result value objects, and
the comparison status enum — also live in Core, because `qmx.yaml` grants both
`baseline` and `reporting` a dependency on `[core]` alone. Placing the coverage
DTO in `Analysis` and consuming it from `Baseline` is an upward edge that
`composer check` rejects.

### 5.4 Observation identity

The logical key is: rule name, violation code, canonical `SymbolPath`, contract
**id** (without version), and an optional stable occurrence discriminator.

The contract version is deliberately **excluded** from the key. v6 put it in the
key while also expecting a bump to yield `incompatible`; those are mutually
exclusive — a versioned key simply fails to match and reads as `resolved` plus
`new`. Here the version is compared after the identity matches.

Rules that can provide a stable occurrence discriminator must do so; where none
exists, a bucket count is stored and the blind spot is documented (§14.2).
Duplicate logical identities are combined per the declared occurrence contract
or rejected as an invariant violation. Collection order never affects output.

### 5.5 Coverage is central, with sparse rule deviations

`Analysis` implements the Core coverage contract from data it already owns:
discovery inventory, parse failures, worker failures, enabled rules, and the
exclusion configuration. A scope is `Evaluated` when it was discovered, parsed,
not excluded from *evaluation* (§5.6), and its rule was enabled and completed.

Rules report only what the centre cannot see, as a sparse deviation list — never
a per-symbol map. Confirmed cases: `GodClassRule` skips classes by its own
applicability check; `ComplexityRule` can disable an individual level;
aggregate and graph rules must state whether their input was complete.

The coverage key is `ruleName` **plus** `violationCode`: one rule class can emit
several codes (`LayerViolationRule` emits its main code and a coverage
diagnostic), and indexing by rule name alone loses that granularity.

### 5.6 Three suppression categories, matched to the code

7.0's two-way split contradicted the implementation (§2.5). The corrected
taxonomy:

| Category                       | Mechanisms                                                                                                               | Comparison             | Report                  | May mutate entry |
| ------------------------------ | ------------------------------------------------------------------------------------------------------------------------ | ---------------------- | ----------------------- | ---------------- |
| **Not evaluated**              | discovery `exclude`, `@generated` stripping, `disabled_rules`, `only_rules`, parse failure, worker failure, interruption | impossible             | `unobserved` diagnostic | no               |
| **Area silenced by config**    | `exclude_paths`, `exclude_namespaces`, per-rule exclusions                                                               | possible — data exists | suppressed              | no               |
| **Finding silenced by author** | `@qmx-ignore`, `@qmx-ignore-file`                                                                                        | possible               | suppressed              | no               |

The second category is the correction: those symbols *were* measured, so
claiming `unobserved` would be false. They are nonetheless never mutated —
silently resolving entries in a silenced area means un-silencing later
resurfaces everything as `new`.

Implementation note, and an explicit scope item rather than a description of
today's behaviour: the comparison must run **after** evaluation-exclusion and
**before** presentation-suppression. `ViolationFilterPipeline` currently applies
the baseline first, so P2/P4 must reorder it. P2's Definition of Done owns the
coverage gate; P4 owns the pipeline order.

### 5.7 Comparison uses the current rule contract

An independent contract registry is populated from the rule set at boot,
carrying each rule's current contract id, version, kind, axis names, directions,
and epsilon. It is populated from the rules themselves and does **not** depend
on a violation being emitted; without it, a forgotten version bump on a
now-passing symbol would read as `resolved` instead of `incompatible`.

The file's contract manifest (§6.1) is compared against this registry. A
mismatch produces `incompatible` and never falls back to suppression.

### 5.8 Vector comparison is strict Pareto ratcheting

A vector finding is not worse only when no axis exceeded its allowance beyond
epsilon. Any worsened axis is `regressed`; improvements elsewhere never
compensate.

- A **contract shape change** — the registry's axis set differs from the
  manifest's — is `incompatible`.
- A **null axis value** is not a shape change. An axis whose metric is
  unavailable is skipped in comparison; a transition between null and numeric is
  recorded and reported, never treated as improvement or regression.
- NaN and infinity are invalid as observations and as serialised values.

### 5.9 Compound rules ratchet on their firing identity

`GodClassRule` fires on `matchedCount >= minCriteria` and its evaluable set is
value-dependent: the LCOM criterion is vetoed when TCC ≥ 0.5. Two distinct
consequences, which 7.0 ran together:

1. **Axis set drift.** If observation axes tracked criterion evaluability, a
   legitimate cohesion improvement would change the axis set and report
   `incompatible`. Therefore compound-rule axes are the **raw underlying
   metrics**, fixed by the contract, independent of which criteria were
   evaluable. Unavailable metrics are null axes (§5.3).
2. **The predicate stops firing.** A class that stops satisfying the compound
   predicate emits no observation and resolves, even if one axis worsened.
   Detecting that would require observations for non-violating classes, which
   reinstates the memory cost the design removed.

v7 accepts (2) deliberately: while the rule fires, axis worsening is caught;
once it stops firing, the finding is resolved by the configured policy, which is
consistent with §5.1. Coverage by single-metric rules is **partial**:
`complexity.wmc` and `design.lcom` exist; TCC and class LOC have no dedicated
rules, and `DataClassRule`'s WOC has none either — note its WMC semantics are
inverted relative to `complexity.wmc`. Recorded in §14.1.

`DataClassRule` is **not** affected: its criteria are fixed, so only
`GodClassRule` needs the raw-axis rule above.

### 5.10 Writes are atomic and guarded per entry

Generate, migrate, update, cleanup, and rebase use temporary-file plus atomic
rename, with the hash algorithm pinned in the file.

The write guard is **per entry, not global**. v6 refused any write when any
required scope was unobserved; on this project's own configuration — two dozen
`exclude_paths` and `exclude_namespaces` blocks — that would refuse every
lifecycle command permanently, so the feature could not serve its own
dogfooding. Instead: an entry whose scope is not proven is carried forward
unchanged, and the command reports how many entries it left untouched and why. A
command aborts entirely only on contract-validation failure, serialisation
failure, or an unresolved migration.

Concurrency uses a real compare-and-swap: the file is locked, or its content
hash is verified inside the same critical section as the rename. A re-read
before writing is a TOCTOU window, not a guard, and does not satisfy this
requirement.

## 6. Baseline v7 File Contract

```json
{
  "version": 7,
  "mode": "ratchet",
  "generated": "2026-08-04T12:00:00+03:00",
  "hash_algorithm": "xxh3",
  "contracts": {
    "complexity.cyclomatic.method": {
      "version": 1,
      "kind": "scalar",
      "axes": { "ccn": { "worse": "higher", "epsilon": 0 } }
    }
  },
  "violations": {
    "method:App\\OrderService::calculate": [
      {
        "rule": "complexity.cyclomatic",
        "code": "complexity.cyclomatic.method",
        "contract": "complexity.cyclomatic.method",
        "occurrence_key": null,
        "axes": { "ccn": { "value": 25, "onset": 10 } },
        "occurrences": 1
      }
    ]
  }
}
```

### 6.1 Top-level fields

| Field            | Contract                                                                                                       |
| ---------------- | -------------------------------------------------------------------------------------------------------------- |
| `version`        | Exactly `7`                                                                                                    |
| `mode`           | `ratchet` or `suppress`                                                                                        |
| `generated`      | ISO 8601, from an injected clock                                                                               |
| `hash_algorithm` | Explicit and pinned; names the algorithm used for occurrence keys and content hashing. Never feature-detected. |
| `contracts`      | Manifest: contract id → version, kind, axis directions and epsilon                                             |
| `violations`     | Canonical symbol keys → deterministic entry lists                                                              |

The manifest detects a forgotten version bump: if the registry's axes,
direction, or epsilon differ from the manifest at the same declared version, the
result is `incompatible`, not a silent miscomparison. Cost is O(contracts).

Everything except `generated` is deterministic for the same analysis and
contracts. A no-op command preserves the existing timestamp and bytes.

### 6.2 Entry invariants

- `rule`, `code`, `contract`, `axes`, and `occurrences` are required;
  `occurrence_key` is optional and null when the rule offers no stable
  discriminator.
- Each axis carries its captured `value` (numeric or null) and the `onset`
  boundary in force when it was captured. The stored onset exists to
  distinguish "the code was fixed" from "the policy moved" (§7.1) — it is a
  single number per axis and is **not** used to decide comparison, which always
  uses the current onset boundary.
- The referenced contract must exist in the manifest.
- Scalar entries have exactly one axis; vector entries at least two; presence
  and graph entries may have none.
- Axis names are unique, deterministically sorted, and match the manifest.
- Entries under one symbol key sort deterministically by
  (rule, code, occurrence_key, contract).
- Numeric values are finite; `-0.0` is normalised.
- `occurrences` is a positive integer; zero-count entries are not serialised.
- Duplicate entry identities are invalid.
- The v5 `hash` field is not carried forward; identity is structural (§5.4), and
  a second identity system in one file is a defect surface.

### 6.3 `mode: suppress`

Matching identity is filtered regardless of value or count growth; contract
metadata is retained for readability and migration; unmatched identities stay
visible; the mode is never selected implicitly at runtime.

## 7. Comparison Semantics

### 7.1 Statuses and reasons

| Status         | Meaning                                               |
| -------------- | ----------------------------------------------------- |
| `new`          | Current violation has no baseline entry               |
| `matched`      | Within allowance                                      |
| `improved`     | Within allowance and better than captured             |
| `regressed`    | At least one axis or the count exceeded its allowance |
| `resolved`     | No current violation, under proven coverage           |
| `unobserved`   | Coverage cannot prove the finding was evaluated       |
| `orphaned`     | The entry's rule is unknown or disabled               |
| `incompatible` | Contracts cannot be compared                          |

Two attributes qualify a status rather than multiplying the list:

- `withinWidenedPolicy` — set on `matched` when the current value is worse than
  captured but still inside an allowance that the current policy widened. This
  is the "accepted growth" case: it is not a regression, and calling it plain
  `matched` would hide that debt grew by permission.
- `resolutionReason` — `fixed` when the captured value would no longer violate
  under its **captured** onset boundary, `policy` when it still would and only
  the boundary moved. `cleanup` removes `fixed` entries only; `policy` entries
  are reported and retained, so that re-tightening the threshold restores the
  original captured debt rather than re-admitting it as `new` at today's worse
  values.

`regressed` findings are reported as violations of their rule's severity and
carry a stable `baseline-regression` reason code in machine output.

### 7.2 Scalar and vector

- Higher-is-worse: `current > allowance + epsilon` is worse.
- Lower-is-worse: `current < allowance - epsilon` is worse.
- Vector: any worse axis is `regressed`; at least one better and none worse is
  `improved`; a registry/manifest shape change is `incompatible`; a null axis is
  skipped (§5.8).

### 7.3 Occurrence, presence, graph

- Occurrence: count above allowance is `regressed`, equal is `matched`, lower
  positive is `improved`, zero is `resolved` under proven coverage. Stable
  occurrence keys take precedence over count buckets.
- Presence and graph: identity present is `matched`, a new identity is `new`, a
  missing identity is `resolved` only under complete relevant coverage. Graph
  identity must be canonical and traversal-order independent (§2.7).

### 7.4 Missing symbols and scope

Absent from complete discovery → may be `resolved`. Absent because the scope was
not evaluated (§5.6, first category) → `unobserved`. Absent because its rule is
gone or disabled → `orphaned`. Aggregate and graph entries require complete
aggregate or graph coverage.

Because analysis is always full (§2.6), `--report=git:*` narrows *presentation*
only. A regression outside the reported scope still counts toward the exit code
and appears in the summary line; its detail lines are collapsed. Making the exit
code depend on a display flag would be a footgun.

## 8. CLI and Lifecycle

```text
bin/qmx baseline:generate <baseline> <paths...> [--mode=ratchet|suppress] [--force]
bin/qmx baseline:migrate-plan  <baseline> <paths...> --out=<plan>
bin/qmx baseline:migrate-apply <baseline> <plan> [--mode=ratchet|suppress]
bin/qmx baseline:rebase-contracts <baseline> <paths...> --contract=<id>... --force
bin/qmx baseline:update  <baseline> <paths...>
bin/qmx baseline:cleanup <baseline> <paths...>
bin/qmx check <paths...> --baseline=<baseline>
```

Command names follow `noun:verb` per `docs/internal/CLI_CONVENTIONS.md`; the
two migration phases are separate verbs rather than one command with `--plan`
and `--apply` modes. `rebase-contracts` must be renamed to a conforming verb
during P0 if the conventions document disallows the object suffix.

All five lifecycle commands need to run an analysis. They share one
analysis-run service; the orchestration must not be copied out of
`CheckCommand`.

- **generate** — ratchet by default; refuses to overwrite without `--force`;
  captures the violation list **after evaluation-exclusion and before
  presentation-suppression** (§5.6). This is a deliberate change: today
  generation consumes the raw pre-filter list (§2.5), capturing findings that
  `check` would never report.
- **migrate-plan** — v5 in, disposition plan out: every v5 entry classified as
  matched, resolved, ambiguous, or unobserved, with a canonical fingerprint of
  the baseline and of the analysis inputs. The plan is a documented,
  user-editable artefact; its schema is part of the public surface and is
  specified in P3, not left to the implementation.
- **migrate-apply** — applies a reviewed plan, refusing if either fingerprint
  changed. Multiplicity is never invented: a v5 hash matching several
  indistinguishable occurrences captures `1`, and the rest surface as
  regressions. Unmatched current violations are never added.
- **update** — monotonic: tightens allowances, reduces counts, never adds an
  identity, never increases accepted debt, never changes contract or kind. It
  additionally performs **debt-neutral identity re-pointing**: when a
  `resolved` and a `new` finding share a contract and identical captured axes,
  the entry follows the moved symbol. This accepts no new debt and is the only
  remedy for a mass rename (§14.3).
- **cleanup** — **modifies the existing `BaselineCleanupCommand`**, it is not a
  new command. Today it takes only a baseline path, runs no analysis, and
  removes entries whose *file no longer exists on disk*. v7 subsumes that
  heuristic (a missing file implies a missing symbol implies `resolved` under
  complete coverage) and replaces it: the command gains a required
  `<paths...>` argument, runs a full analysis, and removes entries confirmed
  `resolved` with reason `fixed`, plus `orphaned` entries behind an explicit
  flag. It never removes `unobserved`, silenced-area, or `policy`-resolved
  entries. The added argument is a breaking CLI change and needs a `Breaking`
  changelog entry.
- **rebase-contracts** — the only path for a known contract change; explicit
  contract ids plus `--force`; prints old and new data before writing.
- `--generate-baseline` on `check` is removed, with no alias.

There is no `baseline:accept`. Accepting more debt is a threshold change in
`qmx.yaml`.

## 9. Reporting and Exit Behaviour

### 9.1 Output shape

Output is read by humans and by agents, so the signal must survive 500 entries.

1. A **summary line first**: `regressed N / new N / matched N / improved N /
   resolved N / unobserved N / orphaned N / incompatible N`.
2. **Expanded by name**: `regressed` and `new` only.
3. **Collapsed to one line each**: every other status, with `--explain=<status>`
   to expand one on demand.

A regression line carries rule and symbol, captured and current value or count,
delta and direction, the applicable allowance, whether the allowance was widened
by policy, the rule's recommendation, and the contract id when verbose.

### 9.2 Machine formats

- **JSON** — native keys for status, attributes, captured, current, delta,
  contract, coverage reason, plus per-status counts.
- **SARIF** — `baselineState` admits only `new`/`unchanged`/`updated`/`absent`,
  so v7 detail goes in `result.properties`; `baselineState` is populated only
  where a status maps honestly. No invented enum members.
- **Checkstyle, GitLab Code Quality** — no extension point; status, reason code,
  captured/current values, and delta go in the existing message field, keeping
  the schema valid.
- **GitHub** — per the selected annotation format's capabilities.

### 9.3 Exit behaviour

`fail_on` defaults to `error`, and Info and Warning findings exit 0. A
regression that stays inside the warning tier would therefore print and pass —
removing v6's independent failure path was a step wider than §5.1's premise,
which proves a violation *exists*, not that it *fails*.

Therefore: **`regressed` fails the build regardless of the finding's severity**,
controlled by an explicit configuration key so the behaviour can be turned off
deliberately. This is an exit-code policy, not a second debt-acceptance surface;
the §15 objection to `baseline:accept` does not apply.

`new` findings follow ordinary `fail_on`. `matched` and `improved` are filtered.
`resolved` and `orphaned` are informational. `unobserved` and `incompatible`
produce diagnostics and block mutation of the entries concerned. Loader and
schema errors keep the existing configuration error class.

## 10. Interaction With Other Features

- **Thresholds** — changing the onset boundary changes the allowance by design.
  Tightening may surface findings as `new`; relaxing widens allowances, marks
  affected entries `withinWidenedPolicy` or resolves them with reason `policy`,
  and is visible in the `qmx.yaml` diff.
- **Git scopes** — presentation only (§7.4).
- **Contract versioning** — bumped when an observation's *meaning* changes
  (axes, direction, epsilon, identity, occurrence semantics), not on every
  algorithm edit. Bumps land only in major releases and are listed in
  `CHANGELOG.md` by contract id, because each turns consumer entries
  `incompatible` until rebased.
- **Computed metrics** — need a deterministic contract id derived from the
  normalised formula, level, axis semantics, and observation version. Display
  rounding is never used for comparison.
- **Metric cache** — P1 changes collector output shape (occurrence
  discriminators). The cache key must invalidate accordingly; a warm cache from
  a previous version must not serve entries without occurrence keys.

## 11. Work Packages

Every tracked path has exactly one owning package. Parallel packages use
separate worktrees. A package may add test files only inside its listed test
directories. No package stashes, restores, or cleans a shared worktree.

### P0 — Contract freeze
Files: this document. Dependencies: none.
DoD: round 2 review passes with no unresolved CRITICAL or HIGH finding; the
layered-versus-vertical-slice decision (§16) is recorded; CLI names checked
against `docs/internal/CLI_CONVENTIONS.md`.

### P1a — Core contracts and topology
Files: `src/Core/Observation/**`, `src/Core/Violation/Violation.php`,
`src/Rules/AbstractRule.php`, `qmx.yaml`,
`tests/Unit/Core/Observation/**`, the dogfooding topology test.
Dependencies: P0.
Small and deliberately first: it unblocks P2 and P3 simultaneously. `qmx.yaml`
and its pinning test are owned here and by no one else.
DoD: observation, axis, kind, contract-reference, coverage-contract, and status
types exist in Core; Core remains dependency-free; the topology admits
`Analysis\Coverage` and keeps `baseline: [core]` satisfiable; PHPStan passes.

### P1b — Rule observations
Files: `src/Rules/**`, `src/Architecture/Rules/**`, `tests/Unit/Rules/**`,
`tests/Architecture/Unit/Rules/**`, `src/Rules/README.md`,
`src/Architecture/README.md`.
Dependencies: P1a. Splittable by rule category across agents with disjoint
directories.
DoD: a registry-driven test asserts every rule in `RuleRegistry` emits an
observation of a declared kind — no hand-maintained list; the onset boundary is
never derived from the tier-matched threshold; `GodClassRule` emits fixed raw
axes with nulls for unavailable metrics; threshold changes alter boundaries but
never raw values or identities.

### P1c — Occurrence identity in collectors
Files: `src/Metrics/**`, `src/Analysis/Duplication/**`,
`src/Core/Duplication/**`, `tests/Unit/Metrics/**`,
`tests/Unit/Analysis/Duplication/**`, `src/Metrics/README.md`.
Dependencies: P1a. Parallel with P1b.
`src/Analysis/Duplication/**` is included because the normalised token hash is
produced there, not in `Core/Duplication` — 7.0's boundary could not reach it.
DoD: stable occurrence keys for code-smell, security, and duplication findings,
reproducible across runs and across file-order changes; the metric cache key
invalidates on the new shape.

### P2 — Coverage
Files: `src/Analysis/Coverage/**`, `src/Analysis/Collection/**`,
`src/Analysis/Pipeline/**`, `src/Analysis/RuleExecution/**`, matching tests,
`src/Analysis/README.md`.
Dependencies: P1a.
DoD: partial, failed, and interrupted runs are distinguishable from complete
ones; the deviation list is empty on a clean full run; the coverage key includes
the violation code; the evaluation gate implements §5.6's first category before
rules execute.

### P3 — Baseline v7 domain and lifecycle
Files: `src/Baseline/**`, its tests, `src/Baseline/README.md`.
Dependencies: P1a only — genuinely parallel with P2 now that the coverage
contract is in Core.
DoD: comparison matrices pass for every kind, status, and attribute; the
migration plan schema is specified and versioned; malformed files fail closed;
writes are atomic with a real CAS guard; no-op operations preserve bytes; v5 is
rejected outside migration.

### P4 — CLI, DI, reporting
Files: `src/Infrastructure/Console/**` (including `ViolationFilterPipeline`),
`src/Infrastructure/DependencyInjection/**`, `src/Reporting/**`, matching tests,
`src/Infrastructure/README.md`, `src/Reporting/README.md`.
Dependencies: P2 and P3.
DoD: one shared analysis-run service backs all lifecycle commands; the filter
pipeline runs comparison after evaluation-exclusion and before
presentation-suppression; the summary line precedes details and only
`regressed`/`new` expand; SARIF uses `properties`; Checkstyle and GitLab stay
schema-valid; `regressed` fails the build with `fail_on: error` and a
warning-severity finding; `--generate-baseline` fails with an actionable
message.

### P5 — Seam tests
Files: `tests/Integration/BaselineRatchet/**`,
`tests/Functional/.../BaselineLifecycleTest.php`, `tests/Fixtures/BaselineV7/**`.
Dependencies: P4.
DoD: every kind exercised end to end; the warning→error transition is a
regression, not a `matched`; relaxing a threshold widens the allowance without
hiding growth beyond the new boundary; `@qmx-ignore` suppresses a regression
while `exclude_paths` neither resolves nor mutates; lifecycle commands succeed
on this project's own `qmx.yaml`; §14 limitations are pinned by tests so they
cannot be silently "fixed"; memory measured against the 2G ceiling on the
largest benchmark project.

### P6 — ADR and documentation
Files: `docs/adr/001x-ratchet-baseline-v7.md`, `docs/adr/README.md`,
`docs/ARCHITECTURE.md`, `website/docs/usage/baseline{,.ru}.md`,
`website/docs/usage/cli-options{,.ru}.md`, `website/docs/ci-cd/*{,.ru}.md`,
`CHANGELOG.md`.
Dependencies: P5 behaviour frozen.
DoD: a test compares documented options against `--help` output; EN/RU parity;
strict MkDocs build clean; `Breaking` entries name every removed surface per the
Backward Compatibility Policy in `AGENTS.md`; the stale `composer deptrac`
reference in `ARCHITECTURE.md` (removed by ADR 0014) is corrected.

## 12. Execution Sequence

1. Round 2 review, then approve P0.
2. P1a; standard review; freeze as the common base.
3. P1b and P1c in parallel worktrees; verify each diff against its DoD.
4. P2 and P3 in parallel; integrate one at a time.
5. P4, then P5 without touching earlier packages' files.
6. Full validation and self-analysis, including lifecycle commands against this
   repository's own `qmx.yaml`.
7. Extended review with three independent reviewers.
8. Verify every finding, fix confirmed ones, re-validate.
9. P6, final validation, website build, seam-focused second round if round 1
   found contract or coverage issues.

## 13. Test Plan

- **Core contracts** — per-kind construction invariants; finite values; null
  axis values; epsilon; identity canonicalisation; version compared after
  identity match, never as part of it.
- **The allowance rule** — captured tighter than onset; onset tighter than
  captured; both directions; **the warning→error transition, asserted to be
  `regressed` and not `matched`**; inline `@qmx-threshold` changing the onset for
  one symbol only; rules with no numeric boundary; the invariant that a
  regression always implies a current violation.
- **Rule observations** — a registry-driven test over every rule; raw versus
  display-rounded values; inverted directions; `GodClassRule` axis stability
  when TCC is missing and when the LCOM veto engages; stable computed-metric
  contract ids; stable cycle and duplication identity; occurrence multiplicity.
- **Coverage** — complete, disabled, `only_rules`, discovery `exclude`,
  `exclude_paths`, parse failure, worker failure, interruption, incomplete
  aggregate and graph scope; the three categories of §5.6 producing their
  documented statuses; deleted versus unobserved versus orphaned.
- **Comparison** — every status and attribute for every kind, including
  `withinWidenedPolicy` and both `resolutionReason` values; manifest mismatch at
  an equal declared version; ratchet versus suppress.
- **Serialisation** — round trip including `occurrence_key` and stored onsets;
  byte stability; fixed-clock generation; no-op preservation; path portability;
  malformed values; NaN and infinity rejection; atomic write and failed-rename
  cleanup; concurrent writers under the CAS guard.
- **Lifecycle** — plan/apply fingerprint guards; ambiguous entries surfacing in
  the plan rather than aborting; per-entry guard leaving unproven entries
  untouched while writing the rest; `update` debt-neutral re-pointing; `cleanup`
  refusing `policy`-resolved entries.
- **Reporting and exit** — summary-line-first ordering; expansion rules;
  `--explain`; SARIF properties; schema-valid Checkstyle and GitLab; a
  warning-severity regression failing the build under `fail_on: error`, and
  passing when the ratchet failure key is disabled.
- **Residual limitations** — §14.1, §14.2, and §14.3 each pinned by a test that
  asserts the documented behaviour.
- **Full validation** — `composer check`; `bin/qmx check src/`; strict MkDocs
  build; private-leak guard; benchmark regression suite; memory and wall-time
  measurement against the 2G ceiling. `RuleExecution` is 1–3% of runtime today,
  so the expected wall-time impact is small — but it is measured, not assumed.

## 14. Residual Limitations

1. **Compound rules** — per-axis worsening is invisible once `GodClassRule`
   stops firing (§5.9). Coverage by single-metric rules is partial: WMC and LCOM
   have their own rules; TCC, class LOC, and WOC do not.
2. **Count fallback** — without a stable occurrence key, one removed plus one new
   occurrence at equal count is indistinguishable.
3. **Renames** — mitigated but not solved by `update`'s debt-neutral
   re-pointing (§8), which requires identical captured axes; a rename combined
   with a change still appears as resolved plus new.
4. **Relaxing a threshold widens every allowance under it** in one move. This is
   the accepted cost of making policy the source of truth; it is visible in the
   `qmx.yaml` diff, marked `withinWidenedPolicy`, and `cleanup` will not delete
   the affected captured values.
5. Ratchet is not historical trend analysis.

## 15. Rejected Alternatives

**Absolute ratchet independent of policy (v6).** Rejected: it makes the baseline
and the threshold configuration two independent policies that drift apart with
no instrument to reconcile them, forcing every deliberate relaxation through
`generate --force`. Recorded dissent: one reviewer preferred keeping the
absolute ratchet plus a narrow `baseline:accept-regression` command. Not
adopted — it adds a second acceptance surface alongside `qmx.yaml`, and
duplicated acceptance paths are how policy drifts in the first place.

**Observations for every evaluated symbol.** Rejected: O(symbols × rules)
retention for data that, under §5.1, cannot change any outcome. The project
already made comparable retention opt-in for memory reasons in `RuleExecutor`.

**Stored debt predicates with a versioned operator DSL.** Rejected: an
expression language embedded in a data file, when the configuration already
supplies the boundary. Note the stored per-axis onset (§6.2) is *not* a revival
of this: it is one number used to explain a resolution, never to decide one.

**Deriving the boundary from `Violation::threshold`.** Rejected: it is
tier-dependent (§2.4), so the allowance would widen as the measurement worsened.

**Adding one `value` field to v5.** Rejected: cannot represent inverted, vector,
occurrence, graph, or dynamic computed-metric contracts.

**Deriving values from violation messages.** Rejected: presentation, unstable,
and intentionally excluded from identity.

**Silently treating v5 as ratchet.** Rejected: v5 has no values, axes,
directions, multiplicity, or coverage evidence.

**Keeping suppress as the default.** Rejected: it preserves the exact blind spot
this feature closes.

**`baseline:accept` in MVP.** Rejected: routine acceptance of new debt conflicts
with ratcheting; the threshold configuration is the acceptance mechanism.

## 16. Layout Decision (Settled)

**Layered, as planned.** Decided before P0 freeze; the reasoning belongs in this
plan's ADR and follows [ADR 0016](../adr/0016-subject-cohesion.md).

By ADR 0010's two-criteria checklist the feature scores one criterion met
(five lifecycle adapters, one of them genuinely multi-stage — stronger than the
Architecture pilot's single debug command), one half-met (the comparator
consumes Analysis-time coverage), and ADR 0012's "analogous complexity" escape
hatch closed: baseline has **zero** `ConfigSchema` entries today, so the
"configuration loader complex enough to live separately" half is not met at all.
Counting criteria therefore decides nothing, which is precisely why ADR 0016
states the underlying rule.

Under that rule the decision is unambiguous, for two independent reasons that
agree:

1. **Subject placement.** The debt-observation contract is "measured debt of a
   symbol" — a cross-cutting primitive of the same kind as `SymbolPath` and
   `Violation`, constructed by every rule and consumed by both Baseline and
   Reporting. It belongs in `Core` by subject, not merely by constraint.
   Coverage is "what this run actually evaluated" — orchestration, so
   `Analysis`. The file schema and comparator are "accepted debt and its file" —
   `Baseline`.
2. **A slice would be a relabelling.** Once the contract sits in Core, a
   `src/Baseline/{Domain,Processing,Configuration}` slice would contain exactly
   today's `src/Baseline/` content, and adapters stay in `Infrastructure/`
   either way (ADR 0016: "many adapters" is not an argument for a slice). Not
   one dependency edge would change.

Internal sub-directories inside `src/Baseline/` may still be introduced as it
grows — ADR 0016 makes that a refactoring decision, subject to the same tests,
not an architectural one requiring an ADR.

Observation recorded during this analysis, out of scope here: `src/Baseline/
Suppression/` (inline `@qmx-ignore` and `@qmx-threshold` extraction) is a
different subject that happens to share the directory. §5.6 separates the two
concepts; separating them physically is a later, independent piece of work.
