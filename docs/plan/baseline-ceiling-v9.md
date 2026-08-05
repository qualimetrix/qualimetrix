# Baseline v9 — the ceiling plan

**Status:** revision 9.0 — unreviewed
**Date:** 2026-08-05
**Supersedes:** `ratchet-baseline-v7.md` (revision 8.1, `ac23907`), abandoned —
see §15.

## How to execute this plan from a clean session

Read in this order before touching code:

1. `AGENTS.md` — working rules. The *Backward Compatibility Policy* matters
   here: breaking the baseline file format is expected, the hard requirement is
   a `Breaking` changelog entry and migration steps written from the consumer's
   side.
2. This plan in full. It is deliberately short.
3. [`baseline-ceiling-v9-digest.md`](baseline-ceiling-v9-digest.md) — the
   decisions this plan implements, with the reasoning that produced them.
4. [`channel-trait-inventory.md`](channel-trait-inventory.md) — the enumeration
   of 41 rule classes and 52 channels. Kept from v7; the appendix of this plan
   extends it.
5. `src/Baseline/README.md` and `src/Rules/README.md` for current state.

**Decision state.** Settled: the ceiling model (§5.1), the cascade and its
precedence (§5.2), the capture point (§5.3), the vocabulary (§5.4), the channel
declaration (§5.5), tier semantics (§5.6), the staleness oracle (§5.7), the file
contract (§6), the commands (§7). Open: the two items in §14.

**This document is temporary.** Delete `docs/plan/` once the feature lands. What
must outlive it goes into the ADR produced by P5 — in particular §15, which is
the record of why measurement-comparison was abandoned after nine review rounds.

## 1. Executive summary

Baseline v5 is an identity-only suppression snapshot: once a violation is
listed, it stays hidden however much worse it gets. v9 replaces the entry with a
**ceiling**: for this symbol, on this channel, the effective limit is X. The
rule then evaluates as it always has, against X instead of the configured
threshold. Legacy debt is suppressed at the level it stands at; growth past that
level fires.

There is no comparison of a past measurement against a present one. That single
property is what makes this plan short — v7 spent 3 000 lines and nine review
rounds making one such comparison safe, and §15 records why that could not be
finished.

## 2. Preconditions and current-state facts

Verified against the code; the citation is the verification point, not an
instruction.

### 2.1 A per-symbol threshold cascade already exists

`AbstractRule::getEffectiveOptions()` resolves a rule's options for a symbol,
applying `@qmx-threshold` overrides carried on `AnalysisContext`
(`getThresholdOverride(ruleName, file, line)`). Overrides are applied through
`RuleOptionsInterface::withOverride(?warning, ?error)`, which replaces each
threshold when the argument is non-null. v9 adds a link to this cascade; it does
not build a parallel one.

### 2.2 Threshold slots are named in at least six ways

Extracted mechanically from every `*Options.php`:

`warning`/`error` (18 rules) · `maxWarning`/`maxError` (5) ·
`maxDistanceWarning`/`maxDistanceError` · `paramWarning`/`returnWarning`/
`propertyWarning` and their `*Error` partners · `voWarning`/`voError` ·
`wocThreshold`/`wmcThreshold`/`tccThreshold`/`lcomThreshold`/`classLocThreshold`.

No naming rule recovers which pair a channel uses. The binding is declared
(§5.5), never inferred.

### 2.3 Some thresholds live in nested per-level options

`ComplexityOptions` itself carries no thresholds: `MethodComplexityOptions` has
`warning`/`error` while `ClassComplexityOptions` has `maxWarning`/`maxError`.
The same shape holds for cognitive and npath complexity, for CBO
(`ClassCboOptions`/`NamespaceCboOptions`) and for instability. A slot is
therefore addressed by a **path**, not by a property name.

### 2.4 Class-level channels compare aggregated metrics

`MetricRepositoryInterface` documents aggregated keys as `{metric}.{strategy}`
(`ccn.sum`, `loc.avg`). A class-level channel's axis is the aggregated key it
actually compares, not the base metric.

### 2.5 `MetricName` already names the quantities

69 constants, values such as `ccn`, `cbo`, `woc`, `wmc`, `classRank`,
`typeCoverage.param`. v9 reuses them as axis names (§5.4).

### 2.6 Exclusions are post-evaluation filters

`RuleExecutor` runs `analyze()` in full and then drops violations from excluded
namespaces and paths; `ViolationFilterPipeline` applies global exclusions after
all rules have run. Only the discovery-level `exclude` key prevents evaluation.
Baseline generation today consumes the raw pre-filter list — §5.3 changes that.

### 2.7 v7 landed code that v9 makes dead

`Core/Observation/**` except `WorseDirection`, `Core/Coverage/**`,
`Core/Comparison/**`, `Violation::$observation`, and a commented-out
`analysis-coverage` layer in `qmx.yaml`. P0 retires them.

## 3. Goals

1. A baselined finding may not get worse; growth fires and fails the build.
2. No history, no diff, and no regeneration are required to detect that.
3. The effective limit for any symbol is one number, computable and printable.
4. Every failure mode is fail-safe: an ambiguity means the ceiling does not
   apply and the finding fires.
5. Files stay deterministic, portable, and atomically written.
6. v5 migrates in one run.

## 4. Non-goals

1. Trend or history ("was 25, now 40").
2. Detecting that suppressed debt improved, except through §5.7's oracle.
3. Distinguishing *which* occurrences, where only a count is stored.
4. A declarative predicate algebra over axes — see the digest §5.
5. Changing `@qmx-threshold` semantics.
6. Automatic acceptance of new debt: widening is an edit to `qmx.yaml`, to an
   annotation, or an explicit regeneration.

## 5. Architectural decisions

### 5.1 An entry is a ceiling

```text
entry(symbol, channel) = { limits: map<axis, number>, occurrences: int|null }
```

It is consumed **during rule evaluation**, by substitution into the rule's
effective options. It is never compared against anything.

**The governing invariant, from which the whole design's safety follows:** *if a
ceiling cannot be applied, the configured threshold governs and the finding
fires.* An unknown axis name, a renamed symbol, a channel whose declaration is
missing, a malformed entry — all resolve toward reporting, never toward
silence. There is no path by which the baseline subsystem can cause debt to go
unreported, which is the property v7 could not establish (§15).

### 5.2 The cascade

Strictness grows from the general to the specific:

```text
qmx.yaml  →  baseline  →  @qmx-threshold on the symbol
```

Each link **replaces** the previous for that (symbol, channel, axis); a link
specifying only part inherits the rest. Because each link replaces rather than
merges, whether an override widens or tightens is not a separate question.

- Tightening `qmx.yaml` does not move a baselined symbol. That is the point.
- An annotation may widen past a ceiling. It is a visible line of code that
  passes review and must carry a reason per `AGENTS.md`.
- The baseline never tightens below `qmx.yaml`: per-symbol tightening is what
  annotations are for. A generated file cannot contain such an entry (§5.3), and
  a hand-written one is accepted as written — it is the user's own policy.

### 5.3 The capture point

`generate` captures **only findings that actually fired, under the full
cascade, after evaluation-exclusion and before presentation-suppression.**

Both halves are load-bearing:

- *Under the full cascade* — a method with `@qmx-threshold 40` and a current
  value of 35 fires nothing, so it gets no entry. Capturing 35 would write a
  ceiling the annotation overrides, and growth to 38 would then be permitted
  silently. This is the one way the cascade could defeat itself.
- *After evaluation-exclusion* — today generation consumes the raw pre-filter
  list (§2.6), capturing findings `check` would never report.

### 5.4 Vocabulary

| Entity      | Named by                                             | Examples                                                 |
| ----------- | ---------------------------------------------------- | -------------------------------------------------------- |
| **channel** | `(ruleName, violationCode)`, type `ViolationChannel` | `complexity.cyclomatic` / `complexity.cyclomatic.method` |
| **axis**    | its `MetricName` value, aggregation suffix included  | `ccn`, `ccn.max`, `woc`, `typeCoverage.param`            |
| **slot**    | a path into the rule's options                       | `warning`, `class.maxWarning`, `wocThreshold`            |

"Axis" is a role — *the quantity a channel bounds* — not a naming system. Two
channels bound quantities that are not repository metrics
(`duplication.code-duplication`, block length; `architecture.coverage`,
unmatched-end count); each declares its own axis name and says so, rather than
inventing a metric that does not exist.

### 5.5 The channel declaration

Per channel, per axis: **axis name, worse-direction, and the slot pair a ceiling
writes into.** A triple. Nothing else — no version, no manifest, no kinds, no
traits.

Two facts make the slot half non-optional: §2.2 (six naming schemes) and §2.3
(nested paths). `design.type-coverage.param` binds axis `typeCoverage.param` to
`paramWarning`/`paramError` by convention alone.

**The declaration is filled per channel against the inventory, never by
analogy.** The appendix carries the mechanically extracted slot inventory; the
axis column is completed by P1 with the metric each channel actually compares.
This plan asserts nothing about a channel it has not enumerated — the rule that
v7's §0.7 had to learn twice.

A channel may declare **no ceiling support**. Its entries are then rejected at
load with a message naming the channel, rather than silently ignored.

### 5.6 Tiers

Applying the cascade yields exactly one `(warning, error)` pair per channel per
symbol — which is what `withOverride()` already produces.

**A ceiling writes both slots of the pair to the captured value.** A value past
the ceiling then fires at the Error tier through the ordinary tier logic, so
"exceeding an accepted ceiling fails the build" needs no separate rule and no
new configuration key. Gradation *inside* accepted debt is lost, which is what a
ceiling means.

For a channel whose slots are not a severity ladder — `design.data-class`'s
`wocThreshold`/`wmcThreshold`, `design.god-class`'s four criterion thresholds —
the ceiling writes the captured value into each named slot. The criteria then
stop matching, and growth makes them match again. The rule's predicate is
untouched.

### 5.7 The staleness oracle

An entry whose debt was repaired keeps sitting in the file. Accepted — with one
cheap mitigation that needs no new contract:

**Evaluate the rule twice for the entry's symbol: with the ceiling and without
it.** If the finding does not fire without the ceiling, the entry is no longer
needed. The metric is already computed and the rule already exists, so the cost
is one extra predicate evaluation per entry.

The oracle is the rule itself, so it is correct for compound channels and for
graph channels by construction — the case v7 could not handle with an entire
subsystem. Being wrong costs a re-added entry on the next `generate`, never lost
debt, which is why `cleanup` is housekeeping rather than a
correctness-critical operation.

### 5.8 Writes are atomic

Temporary file plus atomic rename, hash algorithm pinned in the file.
Concurrency uses a real compare-and-swap: the file is locked, or its content
hash is verified inside the same critical section as the rename. A re-read
before writing is a TOCTOU window, not a guard.

## 6. File contract

```json
{
  "version": 9,
  "generated": "2026-08-05T12:00:00+03:00",
  "hash_algorithm": "xxh3",
  "scope": ["src"],
  "entries": {
    "method:App\\OrderService::calculate": [
      { "channel": "complexity.cyclomatic#complexity.cyclomatic.method",
        "limits": { "ccn": 25 } }
    ],
    "class:App\\Legacy\\Report": [
      { "channel": "design.data-class#design.data-class",
        "limits": { "woc": 85, "wmc": 12 } }
    ],
    "file:src/Legacy/bootstrap.php": [
      { "channel": "code-smell.goto#code-smell.goto", "occurrences": 3 }
    ]
  }
}
```

| Field            | Contract                                                  |
| ---------------- | --------------------------------------------------------- |
| `version`        | Exactly `9`; v5 is rejected outside `migrate`             |
| `generated`      | ISO 8601, from an injected clock                          |
| `hash_algorithm` | Explicit and pinned, never feature-detected               |
| `scope`          | The analysed path set that produced this file, normalised |
| `entries`        | Canonical symbol keys → deterministic entry lists         |

Entry invariants:

- `channel` is the `ViolationChannel` key form (`ruleName#violationCode`) and
  must resolve to a declared channel; an unresolvable one is a load error.
- An entry carries `limits`, `occurrences`, or both, and at least one of them.
- Every `limits` key must be a declared axis of that channel. An undeclared axis
  is a load error — not silently dropped, because a dropped ceiling is a silent
  policy change.
- Numeric values are finite; `-0.0` is normalised.
- `occurrences` is a positive integer.
- Entries under one symbol key sort deterministically by channel.
- Duplicate (symbol, channel) pairs are invalid.
- The v5 `hash` field is not carried forward.

Everything except `generated` is deterministic for the same analysis. A no-op
command preserves the existing timestamp and bytes.

**`mode: suppress`** is retained as a top-level field for full suppression: a
matching (symbol, channel) is filtered regardless of value or count. It is never
selected implicitly at runtime.

## 7. CLI and lifecycle

```text
bin/qmx baseline:generate <baseline> <paths...> [--mode=ratchet|suppress] [--force]
bin/qmx baseline:migrate  <baseline> <paths...> [--force]
bin/qmx baseline:update   <baseline> <paths...>
bin/qmx baseline:cleanup  <baseline> <paths...> [--force]
bin/qmx baseline:explain  <symbol> [--channel=<channel>]
bin/qmx check <paths...> --baseline=<baseline>
```

This block is the complete signature: a flag named in prose and absent here is a
defect in this section.

- **generate** — captures per §5.3; refuses to overwrite without `--force`.
- **migrate** — one run. Captures ceilings for everything currently firing, and
  reports what the old v5 file listed that no longer fires. A v5 entry is a rule
  name plus an opaque hash with no symbol and no value, so nothing is carried
  across structurally; the report is the migration's only continuity, and §14
  owns its shape.
- **update** — monotonic: lowers ceilings and counts to current values, never
  raises one, never adds a symbol, never removes an entry.
- **cleanup** — removes entries the §5.7 oracle finds unnecessary, plus entries
  whose channel is no longer declared by any rule in the build. Refuses to run
  when the current `scope` does not cover the recorded one, behind `--force`.
- **explain** — prints the effective limit for a symbol and the provenance of
  each part: "`ccn` ≤ 25, from baseline; `qmx.yaml` says 10; no annotation".
  This is what makes a three-source cascade trustworthy.
- `--generate-baseline` on `check` is removed, with no alias.

There is no `baseline:accept`: accepting more debt is an edit to `qmx.yaml`, an
annotation, or a regeneration.

## 8. Reporting and exit behaviour

A finding that exceeds a ceiling is an ordinary violation of its rule at the
Error tier (§5.6) and follows ordinary `fail_on`. **No new exit-code policy and
no new configuration key**: v7 needed both because a "regression" was a status
outside the violation model; here it is a violation.

The text report names the ceiling on such a finding — "limit 25 from baseline,
current 31" — so the user can tell an accepted-debt breach from a fresh
violation without running `explain`. Machine formats carry the ceiling and its
source in their existing per-result property bag; no schema is extended and no
enum member is invented.

`cleanup` and `migrate` report per entry what they did and why.

## 9. Interaction with other features

- **Thresholds** — tightening `qmx.yaml` does not move baselined symbols;
  everything else tightens. Visible in the `qmx.yaml` diff.
- **`@qmx-ignore`** — unchanged, and it is the last link's neighbour: it
  suppresses rather than bounds.
- **Git scopes** — `--report=git:*` filters presentation only; ceilings are
  consumed during evaluation, so a narrowed report cannot change what fires.
- **Computed metrics** — a user-defined metric is a channel like any other; its
  axis is the definition's own name, and its slots are the definition's
  thresholds.
- **AST cache** — unaffected: no metric is cached, only parsed nodes.

## 10. Work packages

Every tracked path has exactly one owning package. A package owns every test its
production changes break.

### P0 — Retire the v7 landings
Files: `src/Core/Observation/**`, `src/Core/Coverage/**`,
`src/Core/Comparison/**`, `src/Core/Violation/Violation.php`, `qmx.yaml`, and
the matching tests.
Dependencies: none. First, because everything else reads a smaller Core.
DoD: `DebtObservation`, `AxisObservation`, `ObservationKind`,
`ContractReference`, `OccurrenceKey`, the four coverage types and the two
comparison types are gone; `WorseDirection` and `ViolationChannel` remain;
`Violation::$observation` is removed and the class's
`@qmx-threshold code-smell.constructor-overinjection` annotation is re-evaluated
rather than left behind; the commented `analysis-coverage` layer and its six
inbound-edge markers are reverted from `qmx.yaml`; `composer check` green.

### P1 — The channel declaration
Files: `src/Core/Channel/**` (declaration types), `src/Rules/**`,
`src/Architecture/Rules/**`, `docs/plan/channel-trait-inventory.md`, matching
tests, `src/Rules/README.md`.
Dependencies: P0.
DoD: every channel in the inventory declares its axes — name, worse-direction,
slot path — **or declares no ceiling support**, and registration rejects a
channel declaring neither; the appendix table is completed in the inventory,
with the axis column filled from the metric each rule actually compares
(aggregation suffix included, §2.4); a registry-driven test enumerates every
channel through `RuleRegistry` with no hand-maintained list; the four
`architecture.*` diagnostic names that no class declares as its own are present.

### P2 — The cascade
Files: `src/Rules/AbstractRule.php`, `src/Core/Rule/**`, matching tests.
Dependencies: P1.
DoD: a ceiling is applied by substitution into effective options, in the order
of §5.2, with partial links inheriting; a test shows an annotation overriding a
ceiling and a ceiling overriding `qmx.yaml` for the same symbol; a test shows
that an unknown axis, an unresolvable channel and a missing declaration each
leave the configured threshold in force and the finding firing (§5.1); nested
slot paths work for a class-level and a method-level channel of the same rule
(§2.3).

### P3 — File, commands, reporting
Files: `src/Baseline/**`, `src/Infrastructure/Console/Command/**`,
`src/Reporting/**`, `src/Configuration/**` where the loader is touched, matching
tests, the affected READMEs.
Dependencies: P2.
DoD: the file contract of §6 round-trips with byte stability and a fixed clock;
malformed files fail closed; writes are atomic under a real CAS guard; `generate`
captures per §5.3, proven by an `exclude_paths` finding being absent and by an
annotated symbol below its annotation getting no entry; `update` is monotonic
and an attempt to raise a ceiling is refused; `cleanup` implements the §5.7
oracle; `explain` prints all three sources; v5 is rejected outside `migrate`.

### P4 — Seam and dogfooding tests
Files: `tests/Integration/BaselineCeiling/**`,
`tests/Functional/Console/Command/BaselineLifecycleTest.php`,
`tests/Fixtures/BaselineV9/**`.
Dependencies: P3.
DoD: §13's matrix passes; lifecycle commands succeed against this repository's
own `qmx.yaml`; a baseline generated here, with a handful of findings then fixed
by hand, is cleaned to exactly those; memory measured against the 2G ceiling on
the largest benchmark project.

### P5 — ADR and documentation
Files: `docs/adr/0017-baseline-ceiling.md`, `docs/adr/README.md`,
`docs/ARCHITECTURE.md`, `website/docs/usage/baseline{,.ru}.md`,
`website/docs/usage/cli-options{,.ru}.md`, `CHANGELOG.md`.
Dependencies: P4.
DoD: the ADR records the ceiling decision **and §15** — why
measurement-comparison was abandoned, argued from the two review rounds;
documented options match `--help`; EN/RU parity; strict MkDocs build clean;
`Breaking` entries name the removed v5 format and `--generate-baseline`.

## 11. Execution sequence

1. Review this plan. One round; it is short, and its predecessor's history is
   the argument for reviewing before writing code, not for reviewing nine times.
2. P0, then P1, then P2 — sequential, each small.
3. P3.
4. P4, then P5.
5. Full validation: `composer check`, `bin/qmx check src/`, benchmark
   regression suite, website build.

## 12. Test plan

- **Cascade** — each link alone; each pair; all three; partial overrides
  inheriting; a class-level and a method-level channel of one rule; a
  symbol-conditioned channel (`LongParameterListRule`'s VO branch, which
  bypasses `getEffectiveOptions()` and must be reproduced, not idealised).
- **The fail-safe invariant, asserted directly** — unknown axis, unresolvable
  channel, undeclared channel, symbol renamed, entry malformed: each leaves the
  configured threshold in force and the finding firing. This is the invariant
  the whole design rests on and it is the one a refusal-only suite would miss.
- **Ceilings per shape** — scalar higher-is-worse; scalar lower-is-worse
  (`maintainability.index`, `design.type-coverage.*`); an inverted computed
  metric; an occurrence cap; a compound channel where writing current values
  stops the criteria matching and growth makes them match again; a graph channel.
- **Capture point** — an `exclude_paths` finding absent from a fresh baseline;
  an annotated symbol below its annotation getting no entry.
- **Staleness oracle** — an entry whose finding no longer fires without its
  ceiling is proposed for removal; one that still fires is kept; a compound and
  a graph channel both behave.
- **Lifecycle** — `update` monotonicity, including a refused attempt to raise;
  `cleanup` refusing a narrowed scope without `--force`; `migrate` in one run
  with its report; serialisation round-trip, byte stability, atomic write,
  failed-rename cleanup, concurrent writers under the CAS guard.
- **Reporting** — a ceiling breach names the limit and its source; machine
  formats stay schema-valid; `explain` output for all three sources.
- **Full validation** — `composer check`; `bin/qmx check src/`; strict MkDocs
  build; private-leak guard; benchmark regression suite.

## 13. Residual limitations

Each must be pinned by a test so it cannot be silently "fixed" into different
behaviour.

1. **Which occurrences is not tracked** where only a count is stored: one
   removed plus one added reads as unchanged. PHPStan's `count` and Psalm's
   `occurrences` have the same blind spot.
2. **Improvement is invisible** except through `cleanup`'s oracle. There is no
   "was 25, now 12" anywhere.
3. **Renames drop their ceiling**: the entry no longer applies, the finding
   fires as new, and the user regenerates or edits. Fail-safe and noisy rather
   than silent — the opposite of v7, where a rename could delete the entry.
4. **Stale entries accumulate** between `cleanup` runs.
5. **A hand-written entry may tighten below `qmx.yaml`.** Accepted: it is the
   user's own policy and `explain` shows it.
6. **Aggregate metrics move without the symbol changing** — another file's
   dependency raises this class's CBO past its ceiling and the finding fires
   here. Correct, and worth documenting because the cause is elsewhere.

## 14. Open items

1. **The `migrate` report's shape.** What a user is shown for v5 entries that no
   longer fire, and whether `migrate` writes them anywhere. Decide in P3.
2. **Aggregation-level symbol keys.** Confirm that a namespace-level ceiling is
   unambiguous under the namespace strategy and the aggregation prefixes, both
   of which can rename a symbol without a code change.

## 15. Rejected alternatives

**Measurement comparison (v7/v8, revisions 7.0–8.1).** The baseline stored a
past measurement and each run compared the present one against it. Rejected
after nine review rounds, on evidence rather than taste:

- The design required deciding, for a finding that no longer fires, whether the
  debt was repaired. Revisions 7.0–7.9 answered by inference, and four review
  rounds found nine distinct mechanisms that could explain the silence without
  the code improving; the list had no reason to be complete, and the error
  direction deleted real debt.
- Revision 8.0 inverted it — re-read the measurement, decide from the value —
  which is sound but pulled in a reader per channel, a self-check against the
  rules, a provenance fingerprint, a coverage contract, and a status
  precedence. Review rounds 8 and 9 each found one CRITICAL and seven HIGH,
  **inside the previous round's corrections**, and the corrections were not
  converging.
- The structural cause: every ambiguity in a comparison resolves toward "the
  debt is gone", and each such resolution deletes data. A ceiling has no
  comparison, and every ambiguity resolves toward reporting (§5.1).

What survives from that work: the channel inventory, `ViolationChannel`,
`WorseDirection`, and the vocabulary of §5.4.

**Absolute ratchet as a second policy (v6).** Rejected: the file and `qmx.yaml`
became two independent sets of boundaries that drift with no instrument to
reconcile them. The ceiling model is not a revival — the three sources compose
into one effective boundary per (symbol, channel, axis), and `explain` prints it.

**A declarative per-axis predicate algebra.** Rejected: `design.god-class` is
"≥ 3 of 4 criteria" with a veto, which is neither AND nor OR; per-axis severity
does not compose into one finding severity; thirty-odd single-axis channels
would pay for structure they do not use; and it breaks a live public surface
across 33 Options classes. Computed metrics with Expression Language formulas
are the project's declarative composition mechanism and already serve the case.

**Diff-based gating instead of a baseline.** Not rejected — complementary, and
tracked separately in `PRODUCT_ROADMAP.md`. It cannot replace ceilings for
aggregate metrics, which move when another file changes (§13.6).

**Deriving values from violation messages, and adding one `value` field to v5.**
Rejected as in v7: presentation is unstable, and one field cannot represent
multi-axis or occurrence ceilings.

## Appendix — slot inventory, mechanically extracted

Threshold-bearing properties per Options class, from
`find src/Rules src/Architecture -name '*Options.php'`. **The axis column is
deliberately absent**: P1 fills it per channel from the metric each rule
compares. This table is evidence about slots only.

| Options class                                                                                                                                                                                                             | Slots                                                                                                                       |
| ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- | --------------------------------------------------------------------------------------------------------------------------- |
| `MethodComplexityOptions`, `MethodCognitiveComplexityOptions`, `MethodNpathComplexityOptions`                                                                                                                             | `warning`, `error`                                                                                                          |
| `ClassComplexityOptions`, `ClassCognitiveComplexityOptions`, `ClassNpathComplexityOptions`                                                                                                                                | `maxWarning`, `maxError`                                                                                                    |
| `ClassCboOptions`, `NamespaceCboOptions`                                                                                                                                                                                  | `warning`, `error`                                                                                                          |
| `ClassInstabilityOptions`, `NamespaceInstabilityOptions`                                                                                                                                                                  | `maxWarning`, `maxError`                                                                                                    |
| `DistanceOptions`                                                                                                                                                                                                         | `maxDistanceWarning`, `maxDistanceError`                                                                                    |
| `ClassRankOptions`                                                                                                                                                                                                        | `warning`, `error` (scaled at run time by project class count)                                                              |
| `TypeCoverageOptions`                                                                                                                                                                                                     | `paramWarning`/`paramError`, `returnWarning`/`returnError`, `propertyWarning`/`propertyError` — three channels in one class |
| `DataClassOptions`                                                                                                                                                                                                        | `wocThreshold`, `wmcThreshold`                                                                                              |
| `GodClassOptions`                                                                                                                                                                                                         | `wmcThreshold`, `lcomThreshold`, `tccThreshold`, `classLocThreshold`                                                        |
| `LongParameterListOptions`                                                                                                                                                                                                | `warning`/`error`, plus `voWarning`/`voError` selected by the symbol                                                        |
| `ConstructorOverinjectionOptions`, `UnreachableCodeOptions`, `ClassCountOptions`, `MethodCountOptions`, `PropertyCountOptions`, `InheritanceOptions`, `LcomOptions`, `NocOptions`, `WmcOptions`, `MaintainabilityOptions` | `warning`, `error`                                                                                                          |
| `CodeDuplicationOptions`                                                                                                                                                                                                  | `warning`, `error` (severity tiers only — emission is gated during Collection by `min_lines`/`min_tokens`)                  |
| `CircularDependencyOptions`                                                                                                                                                                                               | `maxCycleSize` (a band, not a ladder), `directAsError`                                                                      |
| `LayerViolationOptions`                                                                                                                                                                                                   | none — a flat configured severity and a coverage `mode`                                                                     |
| code-smell and security Options without tiers                                                                                                                                                                             | none — the ceiling is an occurrence cap                                                                                     |
