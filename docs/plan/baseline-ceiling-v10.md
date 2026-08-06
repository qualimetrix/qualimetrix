# Baseline v10 — the reported-magnitude ceiling

**Status:** revision 10.2 — rounds 1 and 2 folded in
**Date:** 2026-08-05
**Supersedes:** `baseline-ceiling-v9.md` (revision 9.0, `372d831`) and
`ratchet-baseline-v7.md` (revision 8.1, `ac23907`). Both are abandoned; §15
records why. They stay in the tree only until this plan passes review — a
reviewer checking §15's claims needs them — and are deleted before P0 starts.

**What changed at 10.1.** Two reviewers examined 10.0 and found 28 defects, none
in the central mechanism. The load-bearing corrections: `update`'s monotonicity
was stated in numbers instead of directions and inverted on every lower-is-worse
channel (§7); the group key dropped the per-edge identity v5 keeps deliberately
(§5.1); a single scalar could not bound a group whose members differ (§5.1);
staleness was measured against a set that `--report=git:*` narrows; and
`cleanup` could delete a legitimate acceptance after nothing worse than a
threshold edit (§5.7, §15).

**What changed at 10.2.** A narrow second round checked whether each of those 28
was closed or merely reworded. Both reviewers agreed roughly half were closed
cleanly and the rest carried a new defect inside the correction — the failure
mode this project has hit in every previous design. Four changes, the first
structural:

- **The baseline stage moves to the end of the pipeline** (§5.2). 10.1 kept it
  at stage 1 and paid with two measurement sets that disagreed *within a single
  group*: `@qmx-ignore` works per line while an identity spans a file or class,
  so capture counted one member fewer than the filter saw, every such group read
  as a breach, and §5.6 promoted all of it to Error. One position means one set,
  and the "shadowed" category, `update`'s undefined behaviour for it, and
  `--show-resolved`'s fourth reading all disappear with it.
- **Magnitude vectors align from the best end** (§5.1). Aligning from the worst
  end accepted growth after a repair: fixing the worst member raised the ceiling
  for every survivor.
- **A channel's declared shape decides whether a magnitude is read** (§5.1), not
  whether the violation carries a number. The 15 `marker` channels report a
  fixed `1.0`, and 10.1's own fail-safe clause would have switched suppression
  off for all of them.
- **The comparison tolerance is zero** (§5.1). 10.1 asserted "one relative
  tolerance inside `WorseDirection`"; the code takes an *absolute* epsilon as a
  call parameter defaulting to `0.0`, and v10 needs no tolerance at all, because
  it compares a number against a round-tripped copy of itself.

## How to execute this plan from a clean session

Read in this order before touching code:

1. `AGENTS.md` — working rules. The *Backward Compatibility Policy* applies:
   breaking the baseline file format is expected; the hard requirement is a
   `Breaking` changelog entry plus migration steps written from the consumer's
   side.
2. This plan in full.
3. [`violation-magnitude-inventory.md`](violation-magnitude-inventory.md) — the
   enumeration of all 43 `new Violation(` emission points with the magnitude
   each one reports. **This plan's every claim about "all channels" is grounded
   in that table.** It is the input to P1, not background reading.
4. [`channel-trait-inventory.md`](channel-trait-inventory.md) — the older
   per-channel trait enumeration (41 rule classes, 52 channels). Still accurate;
   used for channel identity, superseded for threshold questions.
5. `src/Baseline/README.md` and `src/Rules/README.md` for current state.

**Decision state.** Settled: the ceiling model (§5.1), its position in the
pipeline (§5.2), the argument that it is not v7 (§5.3), the channel declaration
(§5.4), the measured set (§5.5), breach severity (§5.6), staleness (§5.7), the
file contract (§6), the commands (§7). Open: the three items in §14.

**This document is temporary.** Delete `docs/plan/` once the feature lands; what
must outlive it goes into the ADR produced by P6, and what P1 needs permanently
moves into a tracked artefact (P1's DoD).

## 1. Executive summary

Baseline v5 is an identity-only suppression snapshot: once a violation is
listed, it stays hidden however much worse it gets.

v10 makes an entry a **ceiling on the magnitude the finding reports about
itself**. Every `Violation` already carries `metricValue` — the measured
quantity it was judged on. An entry records the magnitudes of the findings that
shared one identity at capture time; on later runs that group is accepted only
while no member is worse and the group has not grown.

The rule is not reconfigured, and nothing is substituted into its options. The
rule evaluates against `qmx.yaml` exactly as it does today, and the baseline
decides only whether an already-fired finding is reported. That is where the
baseline filter already sits (`ViolationFilterPipeline`), so the change is
a different predicate in an existing seam, not a new subsystem.

## 2. Preconditions and current-state facts

Verified against the code; each citation is a verification point, not an
instruction.

### 2.1 Every violation already carries the magnitude it was judged on

`Violation` has `public int|float|null $metricValue` and
`public int|float|null $threshold`. Of the 43 `new Violation(` emission points
in `src/`, **31 report a real measured magnitude, 5 report a fixed `1.0`
occurrence marker, and 7 report nothing** — the two `annotation.*` diagnostics
built in `AnalysisPipeline` and all five channels of `LayerViolationRule`,
including its own primary `architecture.layer-violation`. The full table, with
the comparison operator and `file:line` behind each direction, is
`violation-magnitude-inventory.md`.

This is the fact the whole design rests on, and it is the reason v10 needs no
knowledge of any rule's options.

### 2.2 v5 already computes a per-finding identity, and it is finer than the symbol

`ViolationHasher` hashes `ruleName | namespace | type | member | violationCode`
and, **for dependency-bearing findings, appends the target and the dependency
type** — its own comment says this is so that "per-use-site edges with the same
source but different targets/types produce distinct hashes". Line, message and
severity are deliberately excluded.

v10 keeps that identity and stops hashing it (§5.1): a hash cannot be read by
`explain`, cannot be diffed, and carries no magnitude.

### 2.3 The baseline is already a single predicate over a violation

`BaselineFilter::shouldInclude(Violation $violation): bool` is stage 1 of
`ViolationFilterPipeline`; the order is baseline → `@qmx-ignore` suppression →
`exclude_paths` → `exclude_namespaces` → git scope.
`BaselineGenerator::generate(array $violations): Baseline` is the single
function from violations to file. v10 keeps both seams and changes three things
about them: the predicate's body, the content of an entry, and the stage's
position in the pipeline (§5.2). The predicate also becomes group-valued, which
`ViolationFilterPipeline` can satisfy by grouping before applying it — the
current per-violation signature is P3's to change.

### 2.4 `--report=git:*` narrows presentation only

`GitScopeFilter` is a `ViolationFilterInterface` at stage 5, the option is
documented as "Scope of violations to **report**", and `GitScope` appears
nowhere under `src/Analysis/`. The set of findings a run produces is therefore
unaffected by report narrowing — which is what makes §5.5's choice of set for
staleness safe.

### 2.5 Stale entries today disable the whole baseline

`ViolationFilterPipeline` applies `BaselineFilter` only when
`$staleKeys === [] || $options->ignoreStaleBaseline`, and stale keys otherwise
raise `InvalidArgumentException` (exit 3). Under v5's coarse key that is rare;
under v10's finer key it would fire on the first repaired finding, so §5.7
changes it deliberately.

### 2.6 The default `fail_on` is `error`

`ExitCodeResolver`: `$failOn ?? Severity::Error`. A channel whose configured
severity is `Warning` does not fail the build on its own — which is what forces
§5.6's decision, and what §5.2 has to qualify.

### 2.7 Two channel families report a magnitude that is not a `MetricName`

Cycle size, god-class matched-criteria count, duplicate-block line count and
computed-metric values are computed at rule time and are not repository metrics.
Under v10 this does not matter: the axis is whatever the violation reports.

### 2.8 v7 landed code that v10 mostly makes dead

`Core/Observation/**` except `WorseDirection`, `Core/Coverage/**`,
`Core/Comparison/**`, `Violation::$observation`, and a commented-out
`analysis-coverage` layer in `qmx.yaml`.

`WorseDirection` is **kept, and both of its operators are kept**: the
epsilon-aware worseness test is §5.1's comparison, and `morePermissive()` is
what makes §7's `update` direction-aware. Revision 10.0 deleted the latter and
then needed it — the defect that produced this revision's only CRITICAL.
`isBetter()`, if it has no consumer after P3 and P4, is removed by name.

## 3. Goals

1. An accepted finding may not get worse; growth is reported and fails the
   build.
2. No history, no diff, and no regeneration are required to detect that.
3. Every failure mode is fail-safe: an ambiguity means the entry does not
   suppress, and the finding is reported.
4. The mechanism is uniform across all 43 emission points — no per-rule
   knowledge, no options substitution, no slot inventory.
5. Improving the code never makes the build worse.
6. Files stay deterministic, portable, and atomically written; v5 migrates in
   one run.

## 4. Non-goals

1. Trend or history ("was 25, now 40").
2. Distinguishing *which* member of a group changed.
3. Per-axis ceilings on compound rules beyond the axis the rule reports (§13.3).
4. Changing `@qmx-threshold` or `qmx.yaml` semantics. The baseline is not a link
   in the threshold cascade under v10 — see §5.2.
5. Detecting that a finding stopped firing because policy moved rather than
   because code improved — see §15's rejected alternative.
6. Automatic acceptance of new debt: widening is an explicit regeneration.

## 5. Architectural decisions

### 5.1 An entry is a ceiling over a group of findings sharing one identity

**Identity** is v5's (§2.2), minus the hash: the symbol, the channel, and — when
the finding carries one — the dependency edge (target and type). Keeping the
edge is not an embellishment: without it, replacing one forbidden dependency
with another leaves the count unchanged and is accepted silently, which would be
a regression against v5 rather than a simplification of it.

**A group** is the set of violations in a run sharing one identity.

```text
entry(identity) = { magnitudes: number[] | null, count: int }
```

`count` is the group's size. `magnitudes` holds the group's magnitudes at
capture, sorted **best-first**, and is present exactly when the channel's
declared shape is `magnitude` (§5.4).

**The shape decides, not the value.** The 15 `marker` channels do emit a
`metricValue` — a fixed `1.0` (§2.1) — and it is not a magnitude. Reading it as
one would bound a channel by a constant that never changes. An `occurrence`
channel's reported number is ignored by contract; a `magnitude` channel's is
required.

The group is **accepted** — filtered out — when all hold:

- the current group is no larger than `count`; and
- for a `magnitude` channel, with both vectors sorted best-first, the current
  member at each position is no worse than the stored member at that position,
  in the channel's declared direction; and
- every current magnitude is finite.

Otherwise the whole group is reported.

**Best-first alignment is load-bearing.** Aligning from the worst end accepts
growth after a repair: with `[100, 40]` stored and the 100-line duplicate fixed,
the surviving 40 would be compared against 100 and could grow to it unnoticed —
one repair silently raising the ceiling for everything left. Best-first compares
the k-th best against the k-th best, so any member worsening is caught, whatever
else the group did. The comparison is by rank rather than by member identity,
which the design cannot recover (non-goal 2); rank comparison is what makes it
sound anyway, because a multiset that is element-wise no worse when sorted
cannot contain a worsened member.

`mode: suppress` on an entry means "accept this identity regardless of magnitude
and count". It is part of this acceptance statement and is never selected
implicitly.

**The governing invariant:** *if an entry cannot be applied, it does not
suppress.* An unknown channel, a missing shape or direction declaration, a
`magnitude` entry whose current group reports a non-finite or absent magnitude,
a shape/entry mismatch in either direction, an unrecognised `mode`, a malformed
entry, a renamed symbol — each resolves toward reporting.

The invariant is about *applying an entry*, and it is absolute there. It does
not claim that a correctly applied entry always bounds the debt a reader
imagines: §13.5 (a magnitude whose scale changes) and §13.10 (an aggregate that
moves for reasons elsewhere) are cases where the stored number stays applicable
while meaning something slightly different. Those are limitations of what a
magnitude *is*, pinned by §12, not paths through this invariant.

The comparison is against the number the violation itself reports, so rounding
is self-consistent by construction: `maintainability.index` and computed metrics
store `round($v, 1)`, and that same rounded value is what an entry records and
is compared against. **The tolerance is zero.** `WorseDirection::isWorse()`
takes an absolute epsilon as a parameter defaulting to `0.0`; v10 passes
nothing, because it compares a number against a round-tripped copy of itself and
a tolerance would only widen the ceiling. Byte-exact float round-tripping is
P2's DoD, and it is what makes zero the right value.

### 5.2 It is a filter, and it runs last

v9 made the baseline a third link in the per-symbol threshold cascade, consumed
*during* evaluation. v10 rejects that (§15) and consumes the entry *after*
evaluation, in `ViolationFilterPipeline`.

**The stage moves to the end of the pipeline, immediately before git scope.**
The order becomes `@qmx-ignore` → `exclude_paths` → `exclude_namespaces` →
**baseline** → git scope, where the v5 baseline runs first.

The move is forced, and 10.1's attempt to avoid it is what made it necessary.
Suppression is per line while an identity spans a file or a class, so a group
with one `@qmx-ignore`d member is seen as *n* violations by a stage-1 filter and
captured as *n−1* by anything downstream. The entry then reads as breached on
the very next run, and §5.6 promotes the whole group to Error — `generate`
followed by `check` fails on any project that combines a baseline with inline
ignores. Running the baseline after suppression and exclusion means one stage
sees one set, and the arithmetic cannot disagree with itself.

What this costs and what it does not:

- **`@qmx-ignore` and the exclusions now win over the baseline.** A finding that
  is both accepted and ignored is dropped by the ignore. That is a behaviour
  change against v5, and the right way round: a suppression the user wrote by
  hand outranks one a tool generated.
- **Git scope stays last**, so report narrowing still cannot change what is
  accepted, captured, or stale (§2.4).
- **The baseline never causes a rule to fire.** It cannot make a symbol violate
  a policy that accepts it. It can change the *severity* of a finding that
  already fires (§5.6), so a run with `--baseline` can be red where the same run
  without it was yellow. That is intended, and is the precise limit of this
  claim; 10.0's "cannot turn a green build red" was wrong.
- **The effective boundary is printable, but it is not always one number.** For
  a single-member group it is one number; for a multi-member group it is the
  stored vector, and `baseline:explain` prints the vector with its provenance.
  Claiming a scalar was an artefact of 10.0, before groups existed.
- **A hand-written entry cannot tighten**, in the channel's direction: a stored
  magnitude on the strict side of the configured threshold has no effect,
  because the rule never fired at that level. Stated numerically this reads
  backwards on `lower` channels, which is the same trap §7 fell into.

### 5.3 Why this is not v7's measurement comparison

It resembles it — a stored number compared against a current one — and a
reviewer who stops there will reject it. The difference is which question the
comparison answers.

v7 compared in order to decide **whether a finding that no longer fires was
repaired**. That question has no safe answer: silence has many causes, the design
had to enumerate them, every ambiguity resolved toward "repaired", and each such
resolution deleted a real entry. Nine review rounds could not close it.

v10 never asks that question. The comparison applies only to a **live, currently
firing** group and answers only "is this within what was accepted". Absence
triggers no comparison, no deletion and no build failure (§5.7). The stored
numbers are a *boundary*, not a record to be re-derived; nothing has to reproduce
how they were obtained.

**The test for any future change to this plan:** does it give the subsystem an
opinion about a finding that did not fire? If yes, it re-creates v7. §15 records
the one such proposal already considered and rejected.

### 5.4 The channel declaration

Per channel, exactly one fact plus one shape:

- **shape** — `magnitude` or `occurrence`, taken from the inventory.
- **direction** — `higher` or `lower`, for `magnitude` channels only.

Nothing else: no axis name, no slot path, no options binding, no epsilon (§5.1),
no manifest. A channel that declares neither is not baselineable, and its entries
do not suppress (§5.1).

Filled per channel against the inventory, **never by analogy** — the rule v7 had
to learn twice. Three families need naming because a reader will otherwise
generalise wrongly:

- **`annotation.unsupported-threshold` and `annotation.invalid-threshold.*`
  declare no baseline support.** They report configuration mistakes, not code
  debt. This is the only family excluded outright.
- **The five `LayerViolationRule` channels are `occurrence`**, including the
  primary `architecture.layer-violation`. They report no magnitude (§2.1), and
  `architecture.layer-violation` carries a dependency edge, so its identity is
  per-edge (§5.1).
- **`architecture.circular-dependency` is `magnitude` / `higher`** — a decision,
  not a derivation. Its severity is not monotone in cycle size: a direct
  two-class cycle is an Error while a twelve-class cycle is a Warning, and cycles
  above `maxCycleSize` are dropped entirely. The declaration says a cycle that
  gains a member is worse debt, which is a judgement about debt, not about the
  rule's severity ladder. The rule's own cutoff is untouched.

One family cannot declare statically: **`computed.*` / `health.*` take direction
from the definition's `inverted` flag**, resolved at run time, because the
vocabulary is open-ended. §13.5 records what that costs.

### 5.5 One measured set

Every operation reads the same set: **the pipeline's violations at the baseline
stage's input** — after `@qmx-ignore` and the exclusions, before git scope
(§5.2). Filtering, `generate`, `update` and staleness all measure against it.

That is the whole of this section, and it is short because §5.2's move earned
it. 10.1 defined two sets and a table saying which operation used which; both
reviewers found the same defect in the arrangement, and a third category
("shadowed") existed only to describe entries the split created. One stage
position removes the split, the table and the category together.

Two properties the single set has, and both are needed:

- **It is what the user is shown**, so no entry is ever written for a finding
  `exclude_paths` or `@qmx-ignore` removes. Such an entry would be permanently
  inert, and nothing could retire it.
- **It is unaffected by `--report` narrowing** (§2.4), so a git-scoped run
  cannot make a file look stale.

`@qmx-threshold` needs no special handling either way: a method whose annotation
raises its threshold does not fire, so nothing is captured for it. The
self-defeat v9 had to legislate against cannot arise, because nothing is
substituted anywhere.

### 5.6 A breach is reported at Error

When a group is not accepted, every member is reported and its severity is
**promoted to Error**.

Promotion is required, not cosmetic: the default `fail_on` is `error` (§2.6), so
without it a breach on any Warning-severity channel — `architecture.layer-violation`,
`duplication.code-duplication`, every code-smell channel — would leave the build
green, defeating goal 1. It is unconditional: a per-channel opt-out would
reintroduce channels whose growth cannot fail a build, which is the state v10
exists to end. The consequence for `--baseline` runs is stated in §5.2, and the
propagation to machine formats — where SARIF levels and the run's default level
derive from `Violation::severity` — is P4's.

Every member is reported because the design cannot tell which member is new
(non-goal 2). This is loud on occurrence channels: a file accepted at three
`goto` statements that acquires a fourth reports four Errors, not one. Accepted,
and pinned by a test so it is not silently "fixed" into reporting one.

### 5.7 Staleness reports; it never deletes and never fails

An entry is **stale** when its identity is absent from the measured set (§5.5):
the rule ran against the configured policy and produced nothing for it, or what
it produced the user had already silenced.

Three decisions, and the third is the one a reviewer should press hardest:

1. **Staleness never fails the build**, reversing today's behaviour (§2.5).
   Under v10's finer identity a repaired finding produces a stale entry
   immediately, so failing on staleness would make the tool punish improvement —
   against goal 5. `--baseline-ignore-stale` is removed as meaningless.
2. **A stale entry never disables the rest of the baseline.** Today one stale key
   skips the filter entirely (§2.5); under the finer identity that would turn the
   first repair into a fully red build.
3. **`cleanup` never removes an entry on its own.** Absence is not proof: a
   loosened threshold, a changed `min_lines`, a rewritten computed formula or an
   edited layer topology all silence a finding without any code improving, and no
   cheap corroboration distinguishes them (§15). Removal is a user's assertion,
   never an inference — so `cleanup` selects **per entry**, not per category
   (§7): a category flag that removed everything it matched would be the same
   inference wearing a flag.

**The `scope` guard is a command precondition, not a `check` behaviour.**
`baseline:cleanup` and `baseline:update` refuse to run when the file's recorded
`scope` does not cover the current run, because both write. `check` does not
fail on a scope mismatch — it reports one, since a narrower run legitimately
sees fewer identities and failing would punish the ordinary case of checking one
directory. P4 owns both halves.

`--show-resolved` reads the same predicate as staleness and reports the same
set, in a different unit: it counts **entries whose group did not appear**, not
violations. It is a presentation of §5.7, not a fourth operation. §13.2 records
that a group shrinking without vanishing is not "resolved" and stays invisible.

### 5.8 Writes are atomic

Temporary file plus atomic rename. Concurrency uses a real compare-and-swap: the
file is locked, or its content hash is verified inside the same critical section
as the rename. A re-read before writing is a TOCTOU window, not a guard. The hash
is an implementation detail of that guard and is **not** a file field — nothing
in the file is described by it.

## 6. File contract

```json
{
  "version": 10,
  "generated": "2026-08-05T12:00:00+03:00",
  "scope": ["src"],
  "entries": {
    "method:App\\OrderService::calculate": [
      { "channel": "complexity.cyclomatic#complexity.cyclomatic.method",
        "magnitudes": [25], "count": 1 }
    ],
    "file:src/Legacy/dup.php": [
      { "channel": "duplication.code-duplication#duplication.code-duplication",
        "magnitudes": [100, 40], "count": 2 }
    ],
    "file:src/Legacy/bootstrap.php": [
      { "channel": "code-smell.goto#code-smell.goto", "count": 3 }
    ],
    "class:App\\Web\\Controller": [
      { "channel": "architecture.layer-violation#architecture.layer-violation",
        "edge": { "target": "class:App\\Db\\Connection", "type": "new" },
        "count": 1 }
    ]
  }
}
```

| Field       | Contract                                                  |
| ----------- | --------------------------------------------------------- |
| `version`   | Exactly `10`; v5 is rejected outside `migrate`            |
| `generated` | ISO 8601, from an injected clock                          |
| `scope`     | The analysed path set that produced this file, normalised |
| `entries`   | Canonical symbol keys → deterministic entry lists         |

Entry invariants:

- `channel` is the `ViolationChannel` key form (`ruleName#violationCode`).
- `edge` is present exactly when the finding carries a dependency target, and
  completes the identity of §5.1.
- `count` is a positive integer and is always present.
- `magnitudes` is a list of finite numbers with exactly `count` elements, or
  absent for `occurrence` channels. `-0.0` is normalised; a non-finite value
  makes the entry invalid.
- `magnitudes` is stored **sorted ascending**, and the loader sorts it
  best-first per the channel's direction before comparing (§5.1). Storage order
  is therefore a fact about the file, checkable without knowing a direction that
  computed metrics only resolve at run time (§5.4); "best-first on disk" would
  make validity undecidable for exactly those channels.
- `mode` is optional and per entry; the only value is `suppress` (§5.1). Any
  other value makes the entry inert.
- An entry that is invalid, addresses an undeclared channel, or mismatches its
  channel's shape in either direction **does not suppress**, and `check` reports
  it as inert, naming symbol and channel. It is not a load error: refusing to
  load punishes the whole run for one bad line, and the fail-safe direction
  already prevents harm.
- Entries under one symbol key sort deterministically by channel then edge;
  duplicate identities are invalid.
- The v5 `hash` field is not carried forward.

Everything except `generated` is deterministic for the same analysis. A no-op
command preserves the existing timestamp and bytes.

## 7. CLI and lifecycle

```text
bin/qmx baseline:generate <baseline> <paths...> [--mode=ratchet|suppress] [--force]
bin/qmx baseline:migrate  <baseline> <paths...> [--force]
bin/qmx baseline:update   <baseline> <paths...> [--force]
bin/qmx baseline:cleanup  <baseline> <paths...> [--remove=<symbol>#<channel>]... [--all-listed] [--force]
bin/qmx baseline:explain  <symbol> <paths...> [--baseline=<file>] [--channel=<channel>]
bin/qmx check <paths...> --baseline=<baseline> [--show-resolved]
```

This block is the complete signature: a flag named in prose and absent here is a
defect in this section. Of the four baseline options `check` declares today,
`--baseline` and `--show-resolved` are retained, `--generate-baseline` and
`--baseline-ignore-stale` are removed with no alias (§5.7, and `Breaking`
entries in P6).

- **generate** — captures from the measured set (§5.5); refuses to overwrite
  without `--force`.
- **migrate** — one run. Captures for everything currently firing and reports
  what the v5 file listed that no longer fires. A v5 entry is a rule name plus an
  opaque hash with no magnitude, so nothing is carried across structurally; the
  report is the only continuity, and §14 owns its shape.
- **update** — **direction-aware monotonic: a boundary may move only toward
  stricter, never toward more permissive.** For a `higher` channel that means
  `magnitudes` may only decrease and `count` may only decrease; for a `lower`
  channel (`maintainability.index`, every `design.type-coverage.*`, inverted
  computed metrics) it means `magnitudes` may only *increase*. Stating it as
  "lowers to current values" — 10.0's wording — silently widened every
  lower-is-worse channel. `WorseDirection::morePermissive()` is the primitive
  (§2.8). `update` never adds an identity, and leaves untouched any identity
  absent from the measured set — a vanished group is `cleanup`'s business, not a
  reason to rewrite an entry to nothing.
- **cleanup** — lists every candidate with its reason (stale, or channel no
  longer declared) and removes only the entries named by `--remove`, which may
  be repeated. `--all-listed` removes exactly the listed set in one step and is
  the only bulk form: it is an explicit user assertion about a list the user has
  just been shown, not a standing rule. With neither flag, `cleanup` reports and
  changes nothing (§5.7).
- Both writing commands refuse to run when the current `scope` does not cover
  the recorded one, behind `--force`.
- **explain** — prints the effective boundary for a symbol and where it comes
  from: "`ccn` ≤ 25 from baseline; `qmx.yaml` says 10; annotation raises it to
  40". It takes `<paths...>` because the annotation source is extracted during
  Collection and cannot be read from configuration alone.

There is no `baseline:accept`: accepting more debt is a regeneration.

## 8. Reporting and exit behaviour

A breach is an ordinary violation at Error (§5.6) and follows ordinary
`fail_on`; no new exit-code policy and no new configuration key.

The text report names the accepted level on such a finding — "accepted at 25,
now 31" — so a breach is distinguishable from a fresh violation without running
`explain`. Carrying that through machine formats requires a field on `Violation`
and formatter changes; that is real work, owned by P4, not the "existing property
bag" v9 assumed (`Violation` is a flat VO with no open map).

`cleanup` and `migrate` report per entry what they did and why; `check` reports
stale, inert and scope-mismatched entries without failing on them (§5.7, §6).

## 9. Interaction with other features

- **Thresholds** — tightening `qmx.yaml` does not move an accepted identity: the
  finding still fires and the entry still accepts it. Loosening past a stored
  magnitude makes the finding stop firing, and the entry becomes stale —
  reported, never auto-removed (§5.7).
- **`@qmx-ignore`** — runs after the baseline; a suppressed finding is never seen
  by the ceiling, and is never captured (§5.5).
- **Git scopes** — presentation-only (§2.4): they cannot change what is accepted,
  what is captured, or what is stale.
- **Computed metrics** — covered without special casing, because the mechanism
  never touches options. Direction comes from the definition (§5.4, §13.5).
- **Rules bypassing the options cascade** — `ComputedMetricRule`,
  `LongParameterListRule`'s VO branch, `CboRule` and `TypeCoverageRule`'s inline
  comparisons: all irrelevant, all covered. Under v9 each was a separate defect.
- **AST cache** — unaffected.

## 10. Work packages

**Ownership rule.** Each *changed aspect* of a file has exactly one owning
package. A file may appear in two packages when the changes are disjoint and
each DoD names its own:

| File                                                     | Earlier                                                 | Later                                                                                        |
| -------------------------------------------------------- | ------------------------------------------------------- | -------------------------------------------------------------------------------------------- |
| `src/Core/Violation/Violation.php`                       | P0 removes `$observation`                               | P4 adds the accepted-level field and re-evaluates the `constructor-overinjection` annotation |
| `src/Infrastructure/Console/ViolationFilterPipeline.php` | P3 moves the stage and makes the predicate group-valued | P4 adds the reporting of stale, inert and scope-mismatched entries                           |

Files the two contract changes reach that no package listed before 10.2, now
assigned: `src/Infrastructure/Console/ViolationFilterOptions.php` and
`ViolationFilterResult.php` (P3 — the stale/ignore-stale options and the
per-stage counters change shape), and
`src/Infrastructure/Console/BaselinePresenter.php` (P4 — it is what renders
`--show-resolved` today).

### P0 — Retire the v7 landings
Files: `src/Core/Observation/**`, `src/Core/Coverage/**`,
`src/Core/Comparison/**`, `src/Core/Violation/Violation.php` (the `$observation`
parameter only), `qmx.yaml`, matching tests.
Dependencies: none.
DoD: `DebtObservation`, `AxisObservation`, `ObservationKind`, `ContractReference`,
`OccurrenceKey`, the four coverage types and the two comparison types are gone;
**`WorseDirection` remains with `isWorse()` and `morePermissive()`, each covered
by a test naming its v10 consumer** — §5.1's acceptance and §7's `update`
respectively; **`isBetter()` is removed**, decided here rather than deferred:
§5.7 makes staleness a set-membership question, so nothing in v10 asks whether a
value improved. A DoD that made P0 depend on what P3 and P4 turn out to need was
undecidable at the time P0 runs; `ViolationChannel` remains; `Violation::$observation` is removed; the
commented `analysis-coverage` layer and its six inbound-edge markers are reverted
from `qmx.yaml`; `composer check` green.

### P1 — The channel declaration
Files: `src/Core/Channel/**`, `src/Rules/**`, `src/Architecture/Rules/**`,
`src/Infrastructure/Rule/**`, `tests/Fixtures/Channels/**`, matching tests,
`src/Rules/README.md`.
Dependencies: P0.
DoD: every channel declares shape and, for `magnitude`, direction — or declares
no baseline support; the three families of §5.4 are declared as stated there,
with `annotation.*` the only family excluded and all five `LayerViolationRule`
channels present as `occurrence`; the two decided directions carry their
rationale in code; **the enumeration moves out of `docs/plan/` into a tracked
fixture** so the drift guard survives P6's deletion; the drift guard compares the
declared set against that fixture in both directions, and separately asserts that
every channel emitted anywhere in the integration suite is declared. The fixture
is the oracle, not the suite: a suite-only guard narrows silently to whatever the
tests happen to exercise, and `computed.*` channels exist only per configuration,
so no run enumerates them.

### P2 — File format and entry semantics
Files: `src/Baseline/Baseline.php`, `BaselineEntry.php`, `BaselineLoader.php`,
`BaselineWriter.php`, `BaselineGenerator.php`, `ViolationHasher.php` (removal),
matching tests, `src/Baseline/README.md`.
Dependencies: P1.
DoD: the §6 contract round-trips with byte stability under a fixed clock,
including `edge` and multi-element `magnitudes`; float magnitudes survive the
round trip unchanged; invalid and inert entries behave per §6 without failing the
load; writes are atomic under a real CAS guard; staleness and resolved-reporting
are keyed on the §5.1 identity; v5 is rejected outside `migrate`.

### P3 — The filter, its new position, and capture
Files: `src/Baseline/Filter/**`, `src/Infrastructure/Console/ViolationFilterPipeline.php`,
`src/Infrastructure/Console/ViolationFilterOrchestrator.php`,
`src/Infrastructure/Console/Command/CheckCommand.php`, matching tests.
Dependencies: P2.
DoD: **the baseline stage runs immediately before git scope** (§5.2), asserted
on the pipeline's stage order rather than inferred from its output; acceptance
implements §5.1 over groups, including best-first element-wise comparison,
shape-driven magnitude reading and `mode: suppress`; **no entry is written for a
finding `exclude_paths` or `@qmx-ignore` removes, and a group with one ignored
member round-trips through `generate` then `check`** — the observable a DoD
phrased as "reads the stage-1 input" would have passed without any change to the
code; a git-scoped run marks nothing stale; a stale entry neither fails the run
nor disables other entries (§5.7); a test asserts the fail-safe invariant
directly for each ambiguity of §5.1, non-finite magnitudes included; breach
promotes to Error and reports every group member.

### P4 — Commands and reporting
Files: `src/Infrastructure/Console/Command/Baseline*`, `CheckCommandDefinition.php`,
`src/Reporting/**`, `src/Core/Violation/Violation.php` (the accepted-level field),
matching tests, the affected READMEs.
Dependencies: P3.
DoD: all five commands behave per §7; **`update` is direction-aware, proven on a
`lower` channel where a numerically smaller value is the wider one**; `cleanup`
changes nothing without a category flag and never removes unselected entries;
both commands refuse a narrowed scope without `--force`; `explain` prints all
three sources; the accepted level reaches the text report and every machine
format with schemas still valid, and promoted severities are correct in SARIF's
result levels and run-level default; `--baseline-ignore-stale` and
`--generate-baseline` are gone; `Violation`'s overinjection annotation is
re-evaluated once, here.

### P5 — Seam and dogfooding tests
Files: `tests/Integration/BaselineCeiling/**`,
`tests/Functional/Console/Command/BaselineLifecycleTest.php`,
`tests/Fixtures/BaselineV10/**`.
Dependencies: P4.
DoD: §12's matrix passes; **every item of §13 has the case its own line names,
and the packages named there have landed them** — P5 verifies the list is
complete rather than owning the cases; the round-trip property holds on this
repository, including with an `@qmx-ignore` inside a baselined group —
`generate` immediately followed by `check` reports nothing; a handful of findings
fixed by hand are listed by `cleanup` and removed only when named; memory measured against the 2G ceiling
on the largest benchmark project.

### P6 — ADR and documentation
Files: `docs/adr/0017-baseline-ceiling.md`, `docs/adr/README.md`,
`docs/ARCHITECTURE.md`, `website/docs/usage/baseline{,.ru}.md`,
`website/docs/usage/cli-options{,.ru}.md`, `CHANGELOG.md`, and the deletion of
`docs/plan/`.
Dependencies: P5.
DoD: the ADR records the ceiling decision, §5.3's boundary against v7, and §15;
documented options match `--help`; EN/RU parity; strict MkDocs build clean;
`Breaking` entries name the removed v5 format, `--generate-baseline`,
`--baseline-ignore-stale` and the stale-entry behaviour change; `docs/plan/` is
gone and nothing outside it references it.

## 11. Execution sequence

1. Review this revision — against the inventory rather than against the code,
   since the enumeration is done.
2. P0 → P1 → P2 → P3, sequential and each small.
3. P4, then P5, then P6.
4. Full validation: `composer check`, `bin/qmx check src/`, benchmark regression
   suite, website build.

## 12. Test plan

- **The fail-safe invariant, asserted directly** — one case per ambiguity of
  §5.1: unknown channel, undeclared shape, magnitude present where none is
  stored and the reverse, `NaN` and `±INF`, unrecognised `mode`, malformed entry,
  renamed symbol.
- **Acceptance per shape** — magnitude higher-is-worse; lower-is-worse
  (`maintainability.index`, `design.type-coverage.*`); an inverted computed
  metric; a continuous axis (`coupling.class-rank`); occurrence-only
  (`code-smell.goto`); an edge-bearing occurrence channel
  (`architecture.layer-violation`) where one forbidden edge is swapped for
  another and the group must be reported.
- **Multi-member groups** — two duplication blocks of different lengths where the
  *smaller* one grows; a group that shrinks; a group that shrinks and gains a
  worse member at once; **the best-first alignment case**: the worst member is
  repaired and a survivor then grows toward the vacated value, which must be
  reported (worst-first alignment accepts it, §5.1).
- **Shape versus value** — a `marker` channel whose fixed `1.0` must not be read
  as a magnitude, and a `magnitude` channel whose entry omits `magnitudes`; the
  first must still suppress by count, the second must not suppress at all.
- **The stage position** — a group with one `@qmx-ignore`d member round-trips
  through `generate` then `check`; a pipeline-order assertion pins the baseline
  stage immediately before git scope (§5.2).
- **The round trip** — `generate` then `check` over the same paths reports
  nothing, for every shape above.
- **Growth fires** — magnitude worse by one step; count larger by one; both; and
  `code-smell.unused-private`'s quirk where every member reports the same
  class-wide total.
- **`mode: suppress`** — accepts a worsened group; an unrecognised mode does not.
- **The measured set (§5.5)** — an `exclude_paths` finding is neither captured
  nor counted against an entry; a `--report=git:*` run marks nothing stale.
- **Staleness** — a repaired finding produces a stale entry, the build stays
  green, other entries keep applying, `cleanup` without a flag changes nothing,
  and `cleanup --remove` takes one named entry and leaves its neighbours.
- **The limitations of §13** — one case each, listed there; §13 and this section
  are checked against each other rather than each referring to the other.
- **Lifecycle** — `update` direction-awareness on both directions including a
  refused widening; `update` ignoring a vanished group; scope guards on both
  commands; `migrate` in one run with its report; serialisation round-trip, byte
  stability, atomic write, failed-rename cleanup, concurrent writers under CAS.
- **Reporting** — a breach names the accepted level and promotes to Error;
  machine formats stay schema-valid; SARIF levels reflect promotion;
  `--show-resolved` counts entries, not violations; `explain` for all three
  sources.
- **Full validation** — `composer check`; `bin/qmx check src/`; strict MkDocs
  build; private-leak guard; benchmark regression suite.

## 13. Residual limitations

Each carries its own pinning case below, named here rather than delegated to
§12 — 10.1 said "pinned by a case in §12" while §12 covered four of eleven, and
P5's DoD pointed back at §13, so the requirement circled without landing
anywhere. P5 verifies the list is complete; the cases themselves belong to the
package that owns the behaviour, named per item.

1. **Which member of a group changed is not tracked.** One removed plus one added
   at the same magnitude reads as unchanged. PHPStan's `count` and Psalm's
   `occurrences` share the blind spot.
   *Pinned by P3:* a group whose members swap at equal magnitude is accepted.
2. **A shrinking group is not "resolved".** `--show-resolved` counts entries
   whose group vanished; a group going from five members to two is invisible.
   *Pinned by P4:* a shrunk-but-present group is not counted as resolved.
3. **Compound rules are bounded on the axis they report.** `design.god-class`
   reports the matched-criteria tally, so a criterion worsening without changing
   the tally is invisible. `design.data-class` reports WOC only, which is *not* a
   gap: rising WMC makes the rule stop matching, so WOC is the axis in which this
   channel's debt worsens.
   *Pinned by P3:* a god-class criterion worsens while the tally holds, and is
   accepted.
4. **A breach reports the whole group** (§5.6).
   *Pinned by P3:* a four-member group over a count of three reports four Errors.
5. **A magnitude's scale can change without the channel changing** —
   `coupling.class-rank` is normalised by project size, `coupling.cbo` changes
   meaning with the `scope` option, a computed metric's formula or `inverted`
   flag can be rewritten. The stored number then bounds a differently-meaning
   quantity, and the direction is toward over-acceptance. Detecting it would mean
   storing the configuration that produced the magnitude — §15's rejected
   alternative.
   *Pinned by P4:* `explain` on a scaled channel prints the stored number and the
   number currently compared, so the divergence is at least visible where a user
   would look for it.
6. **`complexity.npath.*` saturates at 10⁹**, so an entry captured at saturation
   can never breach.
   *Pinned by P3:* an entry stored at the saturation value accepts any value.
7. **Renames drop the entry**: the finding fires as new and the old entry goes
   stale. Noisy rather than silent.
   *Pinned by P3:* a renamed symbol reports its finding and strands its entry.
8. **Duplication entries re-key when the alphabetically-first copy moves**, so
   *reducing* duplication can produce a stale entry plus a fresh violation.
   *Pinned by P5:* the re-keying is reproduced end to end on a fixture.
9. **Symbol keys are not unique per declaration.** Two same-FQN declarations
   merge, a trait method is keyed once for all consumers, and `SymbolPath`'s
   `__PROJECT__` sentinel is a legal PHP namespace name. See §14.
   *Pinned by P2:* two same-FQN declarations share one entry.
10. **Aggregate magnitudes move without the symbol changing** — another file's
    dependency raises this class's CBO past its stored magnitude. Correct, and
    worth documenting because the cause is elsewhere.
    *Pinned by P5:* a CBO entry breaches after an edit to a different file.
11. **Three project-keyed architecture channels form multi-member groups** —
    `architecture.unreachable-layer`, `.potential-shadow` and `.empty-template`
    all key on the project sentinel, so their entries are bounded by count alone
    and carry no positional information. `architecture.coverage` emits at most
    one per run and is unaffected.
    *Pinned by P3:* two project-keyed diagnostics of one channel form one group.

## 14. Open items

1. **The `migrate` report's shape** — what a user is shown for v5 entries that no
   longer fire, and whether `migrate` writes them anywhere. Decide in P4.
2. **Symbol-key uniqueness** (§13.9) — whether the identity gains a
   discriminator, or the collisions are accepted and pinned. The `__PROJECT__`
   collision is a pre-existing defect independent of this feature. Decide in P2.
3. **Aggregation-level keys** — confirm a namespace-level entry is unambiguous
   under the namespace strategy and the aggregation prefixes, both of which can
   rename a symbol with no code change.

## 15. Rejected alternatives

**Corroborating staleness with the captured configuration.** Round 1 showed that
`cleanup` deleting on absence alone loses a legitimate acceptance after nothing
worse than a threshold edit. The obvious fix is to store the boundary in force at
capture — `Violation::threshold` already carries it — and refuse to remove when
the current boundary is more permissive. Rejected: **a threshold does not
determine whether a rule fires.** `min_lines`/`min_tokens` gate duplication
during Collection, `minCriteria` decides god-class, `directAsError` and
`maxCycleSize` decide cycles, the layer topology decides every
`architecture.layer-violation`, and a computed metric's formula decides its own
channel entirely. Storing one number would give false confidence; storing enough
to be right means storing the rule's whole configuration, which is v7's
provenance fingerprint under a new name. §5.7 instead makes removal a user's
assertion, which needs no corroboration at all.

**Threshold substitution (v9, revision 9.0).** The entry was a ceiling written
into the rule's own options. Rejected after one review round: two reviewers
independently found that `withOverride(?warning, ?error)` is positional and
per-Options-class, so a per-channel ceiling has nowhere to be written —
`TypeCoverageOptions` holds three channels' slots and one write hits all of them;
`GodClassOptions::withOverride()` writes `minCriteria` and not the four criterion
thresholds. The mechanism also inverted on `architecture.circular-dependency`,
whose only slot is a report cutoff: writing the accepted cycle size there means a
growing cycle stops being reported. It could not reach rules that bypass the
options cascade, needed a second mechanism for the 22 channels with no
thresholds, and produced a ceiling equal to the captured value on inclusive
comparisons — where the same value fires again. Every one of those is a
consequence of acting on the rule's input, which differs per rule; v10 acts on
its output, which does not.

**Measurement comparison (v7/v8, revisions 7.0–8.1).** Rejected after nine review
rounds; §5.3 states the boundary v10 must not cross. The mechanism required
deciding whether a finding that no longer fires was repaired; every ambiguity
resolved toward "repaired" and deleted real entries, and rounds 8 and 9 each
found a CRITICAL plus seven HIGH inside the previous round's corrections.

**Absolute ratchet as a second policy (v6).** Rejected: two independent sets of
boundaries drifting with no instrument to reconcile them. §5.2 answers why a
filter is not a revival of it.

**A declarative per-axis predicate algebra.** Rejected: `design.god-class` is
"≥ 3 of 4 criteria" with a veto, which is neither AND nor OR; per-axis severity
does not compose into one finding severity. v10 does not need it — the rule
already reduces its axes to one reported magnitude.

**Diff-based gating instead of a baseline.** Complementary, tracked separately in
`PRODUCT_ROADMAP.md`. It cannot replace ceilings for aggregate magnitudes, which
move when another file changes (§13.10).
