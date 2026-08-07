# Baseline v10 — the reported-magnitude ceiling

**Status:** revision 10.3 — rounds 1, 2 and 3 folded in; P0 through P3 have
landed, and execution resumes at P4
**Date:** 2026-08-06
**Supersedes:** `baseline-ceiling-v9.md` (revision 9.0, `372d831`),
`ratchet-baseline-v7.md` (revision 8.1, `ac23907`) and `ratchet-baseline-v6.md`.
All three are abandoned; §15 records why. Round 3 checked §15's account of them
against the code and found it accurate, so all three are deleted at 10.3 — §15 is
now the only record, which is what it was written to be.

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

**What changed at 10.3.** Two reviewers examined 10.2 — one external, one
independent — and produced 19 distinct defects: 1 CRITICAL, 10 HIGH, 6 MEDIUM,
3 LOW. None was rejected on verification, and none was in the central mechanism,
which has now survived three rounds. Every defect was inside 10.2's own four
corrections or inside a DoD sentence consuming one. Six changes, the first two
structural:

- **Acceptance is stated cumulatively, not by rank** (§5.1). Both reviewers
  independently found the same CRITICAL: rank alignment has an *end* to choose,
  and each end is wrong in one direction. From the best end, deleting the best
  member of a group makes an untouched survivor breach — a pure repair turning a
  green build red, against goal 5. From the worst end nothing is punished, but a
  survivor may grow into a slot a repair vacated. The cumulative form — *for
  every level of severity, the number of members at least that bad has not
  increased* — has no end to get wrong, is provably equivalent to worst-end
  alignment, and **subsumes the count condition** as its limit case, which is
  what 10.2 asserted about `occurrence` channels without delivering it.
- **The measured set is defined by configuration alone, and one named seam
  delivers it** (§5.5, §10). 10.2 earned "one measured set" inside `check` and
  lost it between commands: the set exists only inside
  `ViolationFilterPipeline::filter()`, reachable only through an orchestrator
  bound to `check`'s option surface, and four of the five baseline commands do
  not exist yet. Defining the set by configuration — CLI-only exclusions
  excluded, by contract — is what lets one seam serve all of them without
  replicating `check`'s flags.
- **Promotion to Error applies only to a measured breach** (§5.6). 10.2's
  unconditional wording swallowed all nine of §5.1's fail-safe paths, so a
  malformed or version-skewed entry became `exit 2` — the opposite of what §6
  says about the same situation, and reachable precisely because change 3 made
  shape mismatch a live class.
- **Magnitudes are normalised at capture and at comparison** (§5.1, §6). Zero
  tolerance was justified by round-trip exactness, but the number compared
  *against* is freshly recomputed, and `coupling.class-rank` is an unrounded
  global PageRank: any unrelated commit perturbs every score, so every such
  entry would breach at Error with no covered code changing. Normalising both
  sides also removes the plan's dependence on the ambient `serialize_precision`.
- **The baseline stage is a transforming stage, not a filter** (§5.2, §10).
  Promotion rewrites a `Violation`, and `ViolationFilterInterface` is
  `shouldInclude(): bool` over a `final readonly` VO with no `with*` helper. The
  stage therefore leaves that interface, and the accepted-level field is added in
  P3 where the promotion site is written, not in P4.
- **`cleanup` selects by complete entry identity, and `--all-listed` is gone**
  (§5.7, §7). The old selector could not name one of two forbidden edges sharing
  a symbol and channel, and `--all-listed` was the "inference wearing a flag"
  that §5.7 rejects two paragraphs above it: nothing tied it to the list a user
  had been shown, so in a CI script it was a standing rule that deletes on
  absence.

The remaining findings were inversions and stale sentences of the same class the
previous rounds produced — the `scope` guard stated in opposite directions in
§5.7 and §7, `@qmx-ignore`'s order in §9 describing the pre-move pipeline, a DoD
naming a "category flag" 10.2 abolished, two DoD sentences that pass against
today's code, and `count`'s monotonicity stated only inside the `higher` clause.
Each is fixed in place.

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

**Decision state.** Settled: the ceiling model (§5.1), its position and nature in
the pipeline (§5.2), the argument that it is not v7 (§5.3), the channel
declaration (§5.4), the measured set and its seam (§5.5), breach severity
(§5.6), staleness (§5.7), the file contract (§6), the commands (§7). Open: the
three items in §14, of which §14.2 and §14.3 were decided in P2 — leaving only
§14.1, the `migrate` report's shape, for P4. **P0 through P3 have landed**
(P0 `3ae829d`, P1 `9b388aa`/`91631dc`, P2 `8f8fb91`, P3 `7a656d2`/`6007b82`);
execution resumes at P4.

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

### 2.5 Stale entries disabled the whole baseline — **changed by P2**

*This section records the state this plan started from. P2 has since landed the
change described below, so the "today" here is the tree before P2, not the tree
now; the rest of §2 still describes the current code.*

`ViolationFilterPipeline` applied `BaselineFilter` only when
`$staleKeys === [] || $options->ignoreStaleBaseline`, and stale keys otherwise
raised `InvalidArgumentException` (exit 3). Under v5's coarse key that was rare;
under v10's finer key it fires on the first repaired finding, which is why §5.7
changes it deliberately.

**As of P2** the filter is applied unconditionally, a stale entry is reported
and does nothing else, and `--baseline-ignore-stale` no longer exists. §5.7's
declarations 1 and 2 were pulled forward for the reason recorded in P2's
description: between a P2 that re-keys staleness and a P4 that removes the
failure path, the first partial repair would have hard-failed `check` on `main`.

### 2.6 The default `fail_on` is `error`

`ExitCodeResolver`: `$failOn ?? Severity::Error`. A channel whose configured
severity is `Warning` does not fail the build on its own — which is what forces
§5.6's decision, and what §5.2 has to qualify.

### 2.7 Two channel families report a magnitude that is not a `MetricName`

Cycle size, god-class matched-criteria count, duplicate-block line count and
computed-metric values are computed at rule time and are not repository metrics.
Under v10 this does not matter: the axis is whatever the violation reports.

### 2.8 v7's landings are gone — P0 has run

Retired in `3ae829d`: `Core/Observation/**` except `WorseDirection`,
`Core/Coverage/**`, `Core/Comparison/**`, `Violation::$observation`, and the
commented-out `analysis-coverage` layer in `qmx.yaml` with its six inbound-edge
markers.

`WorseDirection` was **kept, with both of its operators**: the epsilon-aware
worseness test is §5.1's comparison, and `morePermissive()` is what makes §7's
`update` direction-aware. Revision 10.0 deleted the latter and then needed it —
the defect that produced 10.1's only CRITICAL. `isBetter()` is gone: §5.7 makes
staleness a set-membership question, so nothing in v10 asks whether a value
improved. `ViolationChannel` remains and is still the channel key form of §6.

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
capture, each passed through the normalisation of §6, and is present exactly
when the channel's declared shape is `magnitude` (§5.4). The list is stored in
ascending order for determinism only — the comparison below does not depend on
storage order, which is what removes 10.2's need to justify one order over
another on disk.

**The shape decides, not the value.** The 15 `marker` channels do emit a
`metricValue` — a fixed `1.0` (§2.1) — and it is not a magnitude. Reading it as
one would bound a channel by a constant that never changes. An `occurrence`
channel's reported number is ignored by contract; a `magnitude` channel's is
required.

The group is **accepted** — suppressed — when both hold:

- every current magnitude is finite; and
- **at no level of severity does the current group have more members than the
  stored group had.** For every value `t`, the number of current members at
  least as bad as `t` is no greater than the number of stored members at least
  as bad as `t`, where "at least as bad" means `≥` on a `higher` channel and `≤`
  on a `lower` one.

For an `occurrence` channel there are no magnitudes and the rule has a single
level: the group has no more members than `count`. That is the same sentence
with the severity axis collapsed, not a second mechanism.

Otherwise the whole group is reported (§5.6 decides at what severity).

**Why cumulative, and not by rank.** A rank comparison has an *end* to align
from, and each end is wrong in one direction — the CRITICAL that produced this
revision. Stored `[100, 40]` on a `higher` channel, the 40-line duplicate
deleted and nothing else touched:

| alignment          | stored      | current | at position 0                 | verdict            |
| ------------------ | ----------- | ------- | ----------------------------- | ------------------ |
| from the best end  | `[40, 100]` | `[100]` | `100` vs `40`                 | **breach** — wrong |
| from the worst end | `[100, 40]` | `[100]` | `100` vs `100`                | accepted           |
| cumulative         | —           | —       | `t=100`: 1 ≤ 1; `t=40`: 1 ≤ 2 | accepted           |

Aligning from the best end silently assumes that a shrinking group lost its
*worst* members, so survivors are measured against the vacated best slots; a
pure repair then reports a breach on a symbol nobody touched. Aligning from the
worst end assumes the opposite. The cumulative statement assumes nothing,
because it never pairs members at all — it counts them.

It is **equivalent to worst-end alignment**, and the equivalence is worth
recording so nobody re-derives it: if sorting both worst-first gave some
`c_i` worse than `s_i`, then at `t = c_i` there are at least `i` current members
at least that bad and at most `i−1` stored ones, so the cumulative test fails;
conversely, if `k` current members are at least as bad as `t`, then
`s_1 … s_k` are each at least as bad as `c_1 … c_k` and hence as `t`, so the
stored count is at least `k`.

It also **subsumes the count condition** instead of restating it. Evaluated at
the least-bad current magnitude, the left side is the whole current group, so
`count` is bounded as the limit case — which is why the rule above has one
bullet where 10.2 had two, and why the `occurrence` degeneracy is a consequence
rather than a claim. An implementation therefore needs only the levels the
current group itself supplies.

What this accepts is recorded as a limitation, not hidden: a survivor may grow
into a slot a repair vacated, bounded above by the worst magnitude already
accepted (§13.12). The alternative bounds that case and breaks goal 5 on the
most common user action, which is the wrong trade for a tool whose purpose is to
get debt repaired.

`mode: suppress` on an entry means "accept this identity regardless of magnitude
and count". It is part of this acceptance statement and is never selected
implicitly.

**The governing invariant:** *if an entry cannot be applied, it does not
suppress.* An unknown channel, a missing shape or direction declaration, a
`magnitude` entry whose current group reports a non-finite or absent magnitude,
a shape/entry mismatch in either direction, an unrecognised `mode`, a malformed
entry, a renamed symbol — each resolves toward reporting, **at the finding's own
configured severity**. None of these is a breach, so none of them promotes
(§5.6): an entry the mechanism could not apply says nothing about the debt, and
failing the build on it would punish a user for a stale file rather than for
worsening code.

The invariant is about *applying an entry*, and it is absolute there. It does
not claim that a correctly applied entry always bounds the debt a reader
imagines: §13.5 (a magnitude whose scale changes) and §13.10 (an aggregate that
moves for reasons elsewhere) are cases where the stored number stays applicable
while meaning something slightly different. Those are limitations of what a
magnitude *is*, pinned by §12, not paths through this invariant.

**Every magnitude is normalised, and the tolerance is then zero.** Both sides of
the comparison pass through one normalisation — `round($v, 6)` — at capture and
again at comparison. `WorseDirection::isWorse()` takes an absolute epsilon
defaulting to `0.0`; v10 passes nothing.

Normalisation is what earns the zero, and 10.2's justification for it was
incomplete. "It compares a number against a round-tripped copy of itself" is
true of the *stored* side only; the side compared against is recomputed on every
run. Two consequences follow, and normalising both sides closes both:

- **The round trip stops depending on the ambient ini.** `BaselineWriter` encodes
  without `JSON_PRESERVE_ZERO_FRACTION`, and float representation is governed by
  `serialize_precision`, which is user-settable. At a value like `15` the
  shortest-round-tripping guarantee is gone and a raw double can decode one ULP
  away — a breach at Error with no code change. Six decimal places survive any
  `serialize_precision`, which is P2's observable.
- **Values already rounded by their own rules are unaffected.**
  `maintainability.index` and computed metrics store `round($v, 1)`; one decimal
  place passes through six unchanged, so nothing about those channels moves.

Normalisation does **not** rescue a magnitude that is a globally-normalised
score. `coupling.class-rank` is an iterative PageRank over the whole project:
adding an unrelated class perturbs every score by more than any rounding
absorbs, so a stored rank is not a boundary at all. That is a units problem, not
a precision one, and §5.4 answers it by declaring the channel `occurrence`.

### 5.2 It runs last, and it transforms rather than filters

v9 made the baseline a third link in the per-symbol threshold cascade, consumed
*during* evaluation. v10 rejects that (§15) and consumes the entry *after*
evaluation, in `ViolationFilterPipeline`.

**It is a transforming stage, not a filter, and that is a contract change.**
§5.6 requires a breach to be reported at Error and §8 requires it to carry the
accepted level, so the stage rewrites the violations it passes through.
`ViolationFilterInterface` is `shouldInclude(Violation): bool` and the pipeline
applies every stage through a boolean predicate; `Violation` is `final readonly`
with fourteen constructor parameters and no `with*` helper, and PHP has no
`clone … with`. So the baseline stage leaves `ViolationFilterInterface` for a
transform-shaped contract of its own, and the promotion site reconstructs the
VO. 10.2 called this "a different predicate in an existing seam" — true of the
position, false of the shape, and the difference is a package's worth of work
(§10 assigns it, and the accepted-level field moves to P3 so the site is written
once).

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
  hand outranks one a tool generated. **One documented exception survives it:**
  `exclude_namespaces` does not apply to `architecture.*` channels at all —
  `NamespaceExclusionFilter` returns `true` for them unconditionally, so that a
  noisy-metric exclusion cannot double as a way to switch off layer-policy
  enforcement. Its docblock names the baseline as the sanctioned route for those
  findings, which the new order still honours: architecture findings inside an
  excluded namespace reach the baseline and are captured.
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
- **A hand-written entry cannot make a non-firing symbol fail**, because the rule
  never fired at that level — but it is not inert either. A stored magnitude on
  the strict side of the configured threshold, on a symbol that *does* fire, is a
  breach, so it escalates that finding to Error (§5.6): configured warning 10,
  entry `[5]`, a method at 12 fires at Warning and the entry makes it an Error.
  10.2 called such entries "no effect", which would have led to neither
  validation nor a warning being built for a working undocumented lever. `check`
  reports them alongside inert ones (§6). Stated numerically rather than
  directionally this reads backwards on `lower` channels, which is the same trap
  §7 fell into.

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

**How the inventory's three kinds map onto two shapes**, stated once here because
P1 transcribes it once per channel: the inventory's `magnitude` kind becomes
shape `magnitude`; both `marker` and `absent` become `occurrence`. The one
exception is the `annotation.*` family, which declares no baseline support at all.
Without this sentence the mapping is derivable from §2.1 and §5.1 but never
written down, in the one section that insists the values be filled "never by
analogy".

**The channel count, since three documents disagreed about it.** The 43 emission
points expand to **64 concrete channels**: **51** declared statically, **7**
`annotation.*` variants excluded outright, and **6** built-in `computed.health.*`
resolved at run time alongside any user-defined `computed.*`. The older
`channel-trait-inventory.md` counts 52, which is neither the static set nor the
total — it predates both the `annotation.invalid-threshold.*` per-validator codes
and the decision to treat the computed family as resolved rather than enumerated.
P1's tracked fixture is the number that governs from here.

Filled per channel against the inventory, **never by analogy** — the rule v7 had
to learn twice. Five families need naming because a reader will otherwise
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

- **`coupling.class-rank` is `occurrence`**, though it reports a real number. Its
  magnitude is an iterative PageRank normalised over the whole project, so it
  moves whenever anything anywhere is added, while the rule's own threshold is
  rescaled for the class count to compensate. A stored raw rank is therefore not
  a boundary in any later run's units, and no tolerance fixes a units mismatch
  (§5.1). Bounded by count, the entry says "this class is an accepted coupling
  hotspot", which is the only claim the number supports. Ratcheting the coupling
  it stands for is `coupling.cbo`'s job, and that channel stays `magnitude`.
  `coupling.distance` and `coupling.instability` also stay `magnitude`: they move
  when a real dependency appears, which is debt genuinely worsening (§13.10).
- **`code-smell.unused-private` is `magnitude` / `higher`**, declared although no
  comparison in the code establishes a direction — the rule fires on any nonzero
  count and never consults its own `getSeverity()`. **A threshold is not what
  establishes direction; the meaning of the measured value is.** The reported
  magnitude is a count of unused private members, and more of them is worse debt;
  the missing comparison says only *when* the rule fires, not *which way* the
  channel worsens. Two independent readings of the source agreed on those facts
  and differed only on whether they sufficed, which is why this is written down
  here rather than left to a reader's judgement. Note the quirk §12 pins: every
  member of the group reports the same class-wide total, so count and magnitude
  move together — redundant, not wrong.

One family cannot declare statically: **`computed.*` / `health.*` resolve *both*
facts at run time** — shape is always `magnitude`, and direction comes from the
definition's `inverted` flag — because the vocabulary is open-ended and a
user-defined channel has no static declaration site. 10.2 resolved only
direction, which left every user-defined computed metric unbaselineable under the
"declares neither" default. The two `annotation.*` channels are the mirror case:
they are emitted from `AnalysisPipeline`, not from a rule class, so they have no
declaration site either and are covered by the not-baselineable default rather
than by an explicit entry. §13.5 records what run-time resolution costs.

### 5.5 One measured set

Every operation reads the same set: **the pipeline's violations at the baseline
stage's input** — after `@qmx-ignore` and the exclusions, before git scope
(§5.2). Filtering, `generate`, `update` and staleness all measure against it.

§5.2's move earned that inside `check`. It does not on its own reach the four
commands of §7, and 10.2 assumed it did — the defect both round-3 reviewers
found. The set exists only inside `ViolationFilterPipeline::filter()`, which is
reachable only through `ViolationFilterOrchestrator`, which reads `check`'s
options and is called only from `CheckCommand`. Symfony throws on `getOption()`
for an option a command does not declare, so a baseline command cannot call that
seam, and there is no other. Four of the five commands do not exist yet, and the
one analysis-bearing precedent (`GraphExportCommand`) wires its own discovery and
graph rather than touching the pipeline at all.

Two decisions make one set reachable from all of them:

- **The measured set is defined by configuration alone.** Exclusions and
  suppression that come from `qmx.yaml` and from source annotations are part of
  it; exclusions supplied only as `check` flags — `--exclude-path`,
  `--exclude-namespace` — are **not**, and §7's commands do not accept them.
  Otherwise every baseline command would have to replicate `check`'s option
  surface to agree with it, and a user who passed a flag to one and not the
  other would be measuring two sets again. The cost is stated rather than
  hidden: a CLI-only exclusion can leave an entry that never applies, which
  `check` reports as inert (§6).
- **A flag may narrow the set; no flag may widen it.** The asymmetry is not a
  convention, it is the whole safety argument. Narrowing is harmless because a
  group that lost members cannot breach the entry that bounded it, so the worst
  a `--exclude-*` flag costs is the inert entry named above. Widening is
  authorised by nothing: a finding the set never held has no entry, so it reads
  as a breach and promotes its whole group to Error on code nobody touched, and
  a capture taken under the flag writes an entry no later run can see.

  This is where 10.3 was wrong in one place and the impl review caught it, twice
  and independently, once by running it. `--no-suppression-annotations` (named
  `--no-suppression` before this correction) was listed above as merely "not
  part of the set", and was implemented by dropping the suppression stage from
  the definition — which widens. **Annotation suppression is therefore
  unconditional in the definition of the set, and the flag is honoured
  downstream:** the findings it removed rejoin the report after the baseline
  stage has judged the set and before git scope narrows it, meeting the same
  exclusions and the same scope as everything else on the way. The consequence
  to accept rather than rediscover: under the flag an annotated finding is shown
  at **its own severity**, compared against no entry, because the ceiling never
  measured it. The flag is a diagnostic view of what the annotations hide, not a
  stricter mode of the baseline.

  The rename follows from the same finding — "suppression" was the ambiguity
  that made a report-level flag look like a set-level one, while the annotations
  it reads live in docblocks, `//` comments and `/* */` blocks alike, so
  "annotations" is the honest word and "phpdoc" would name a third of them. No
  alias, per the Backward Compatibility Policy; there is deliberately no paired
  `--no-suppression-baseline`, because a baseline is not configurable and "do
  not suppress by baseline" is spelled by not passing `--baseline`.
- **One named seam delivers it.** Paths plus resolved configuration in, the
  violation list at the baseline stage's input out, with no `InputInterface`
  dependency. `check` obtains its set from the same seam it already runs, and
  every baseline command obtains it by name. P3 owns the extraction (§10).

Two properties the single set has, and both are needed:

- **It is what the user is shown, before `--report` narrowing**, so no entry is
  ever written for a finding `exclude_paths` or `@qmx-ignore` removes. Such an
  entry would be permanently inert, and nothing could retire it. The one
  exception is the `architecture.*` exemption from `exclude_namespaces` (§5.2):
  those findings are in the set and are captured even inside an excluded
  namespace, which is deliberate and is the reason a §12 case for
  `exclude_paths` must not be generalised to `exclude_namespaces`.
- **It is unaffected by `--report` narrowing** (§2.4), so a git-scoped run
  cannot make a file look stale.

`@qmx-threshold` needs no special handling either way: a method whose annotation
raises its threshold does not fire, so nothing is captured for it. The
self-defeat v9 had to legislate against cannot arise, because nothing is
substituted anywhere.

### 5.6 A measured breach is reported at Error

When a group is not accepted **because it was measured against an applicable
entry and exceeded it** — the count grew, or some level of severity holds more
members than it did — every member is reported and its severity is **promoted to
Error**.

**Promotion is scoped to that case and to no other.** Every path through §5.1's
governing invariant — unknown channel, undeclared shape or direction, absent or
non-finite magnitude, shape/entry mismatch either way, unrecognised `mode`,
malformed entry, renamed symbol — reports at the finding's own configured
severity and adds the inert note of §6. 10.2 said "when a group is not accepted"
without that distinction, which made a single hand-edited or version-skewed line
in a baseline file turn a Warning-severity channel into `exit 2`, and directly
contradicted §6's own ruling on the same situation. The worst instance was a
shape declaration corrected in a later release: every existing entry for that
channel mismatches at once, and every user's build goes red on findings that did
not change.

Promotion is required, not cosmetic: the default `fail_on` is `error` (§2.6), so
without it a breach on any Warning-severity channel — `architecture.layer-violation`,
`duplication.code-duplication`, every code-smell channel — would leave the build
green, defeating goal 1. It has **no per-channel opt-out**: one would reintroduce
channels whose growth cannot fail a build, which is the state v10 exists to end.
Scoping it to measured breaches is not such an opt-out — every channel's growth
still promotes; what does not promote is an entry that measured nothing. The consequence for `--baseline` runs is stated in §5.2, and the
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
   never an inference — so `cleanup` selects **per entry, by complete identity**,
   never per category and never in bulk (§7): a flag that removed everything it
   matched would be the same inference wearing a flag. 10.2 wrote that sentence
   and then offered `--all-listed` two paragraphs later, on the reasoning that it
   asserts something about a list the user has just been shown. Nothing tied the
   two together — the list is recomputed inside the same invocation, so
   `baseline:cleanup file.json src/ --all-listed` in a CI step is a standing rule
   that deletes on absence, which is exactly what this decision forbids. The
   concrete loss it enables: `min_lines: 6 → 12`, duplication findings vanish,
   the flag drops their entries, the threshold is reverted, and the debt is now
   un-baselined and invisible. `--all-listed` is therefore removed; `--remove` is
   repeatable, which costs a scripted user one `sed` and keeps the argument
   intact.

**The `scope` guard is a command precondition, not a `check` behaviour.**
`baseline:cleanup` and `baseline:update` refuse to run when **the current run's
scope does not cover the file's recorded `scope`**, overridable with `--force`,
because both write. 10.2 stated this predicate in one direction here and in the
opposite direction in §7; §7 was right, and this is the direction that matches
the hazard — a run *narrower* than the recorded scope makes every identity
outside it look absent, so `cleanup` would list the rest of the file as a
removal candidate, while a wider run is harmless. `check` does not
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
        "magnitudes": [40, 100], "count": 2 }
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
  absent for `occurrence` channels. Each is `round($v, 6)` (§5.1); `-0.0` is
  normalised to `0`; a non-finite value makes the entry invalid.
- `magnitudes` is stored **sorted ascending**, which is a fact about the file
  checkable without knowing a direction that computed metrics resolve only at run
  time (§5.4). Ascending is now purely a determinism convention: the cumulative
  comparison of §5.1 counts members per severity level and does not read the list
  in order, so no consumer re-sorts it and 10.2's argument about which order
  belongs on disk no longer has to be made.
- The **entry selector** — the form `cleanup --remove` takes and `check` prints
  for an inert entry — addresses one entry by its complete identity, edge
  included. `<symbol>#<channel>` cannot: `#` already separates `ruleName` from
  `violationCode` inside a channel, and two forbidden edges from one class on one
  channel share every other component. The selector is a short deterministic
  digest of the full identity, printed next to every entry `cleanup` and `check`
  list, so a user copies it rather than composing it. §14 keeps its exact form
  open; what is settled is that it is complete and unambiguous.
- `mode` is optional and per entry; the only value is `suppress` (§5.1). Any
  other value makes the entry inert.
- An entry that is invalid, addresses an undeclared channel, or mismatches its
  channel's shape in either direction **does not suppress**, and `check` reports
  it as inert, naming symbol, channel and selector. The findings it failed to
  cover are reported at their own configured severity and are **not** promoted
  (§5.6) — the fail-safe direction only prevents harm if "reported" means
  "reported as the rule would have reported it". It is not a load error either:
  refusing to load punishes the whole run for one bad line.
- Entries under one symbol key sort deterministically by channel then edge;
  duplicate identities are invalid.
- The v5 `hash` field is not carried forward.

Everything except `generated` is deterministic for the same analysis. A no-op
command preserves the existing timestamp and bytes.

**Float representation is pinned at the encode site, not inherited from the
environment.** `BaselineWriter` encodes without `JSON_PRESERVE_ZERO_FRACTION`,
and PHP's float output is governed by `serialize_precision`, which a user can
set. **The pin is what makes the text independent of that setting; §5.1's
six-decimal normalisation is not.** 10.3 claimed the two combined were enough,
and they are not: `0.1` has no exact binary form, so at
`serialize_precision=17` PHP writes `0.10000000000000001` and at `15` it writes
`0.1` — one normalised value, two files. `-1` selects the shortest
representation that round-trips to the identical double, which is stable across
every ambient setting and lossless; normalisation does the separate job of
collapsing values that differ below the sixth decimal. Together they earn the
zero tolerance rather than betting on an ini default. Note the consequence for
byte stability: a normalised `40.0` is written as `40` and reloads as `int`,
which is harmless for a numeric comparison and stable from the first write, but
P2 states it rather than leaving it to a flaky test.

## 7. CLI and lifecycle

```text
bin/qmx baseline:generate <baseline> <paths...> [--mode=ratchet|suppress] [--force]
bin/qmx baseline:migrate  <baseline> <paths...> [--force]
bin/qmx baseline:update   <baseline> <paths...> [--force]
bin/qmx baseline:cleanup  <baseline> <paths...> [--remove=<selector>]... [--force]
bin/qmx baseline:explain  <symbol> <paths...> [--baseline=<file>] [--channel=<channel>]
bin/qmx check <paths...> --baseline=<baseline> [--show-resolved]
```

This block is the complete signature: a **baseline-related** flag named in prose
and absent here is a defect in this section. 10.2 wrote the rule without that
qualifier, which made the section fail its own self-test — `--report=git:*`,
`--exclude-path`, `--exclude-namespace` and `--no-suppression-annotations` are
all named in prose as behaviours; the two exclusion flags are deliberately *not*
accepted by these commands (§5.5), and `--no-suppression-annotations` is a
`check` report flag that no baseline command has any reading for, since it
cannot change the set (§5.5). None of the five takes any exclusion or
suppression flag.

`--force` is per command and overrides two different things: on `generate` it
permits overwriting an existing file, on `update` and `cleanup` it overrides the
scope guard. `--mode=ratchet` is `generate`'s default and writes no `mode` key at
all; `--mode=suppress` writes `mode: suppress` on every entry it captures (§6). Of the four baseline options `check` declares today,
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
  stricter, never toward more permissive.** Stated as a rule over the whole
  group, because a per-position rule cannot express the ordinary case:

  - `count` may only **decrease**, on every channel and in both directions.
    Fewer findings is better everywhere; 10.2 stated this inside the `higher`
    clause only, which left `update` free to widen a count on
    `maintainability.index`, every `design.type-coverage.*` and inverted computed
    metrics — the recurrence of the bug 10.1 was written to fix.
  - `magnitudes` may be replaced by the current group's magnitudes **exactly when
    the current group is accepted by §5.1 against the stored one.** Acceptance is
    already "no level of severity holds more members than before", which is
    precisely "not more permissive" at group level, so `update` needs no second
    definition of the same idea.

  A per-position formulation refuses the common case: stored `[40, 100]`, the
  40-line duplicate deleted, candidate `[100]` — at rank 0 the value grew from 40
  to 100, so an element-wise rule reads a widening and declines, leaving the user
  no way to record an improvement short of `generate`, which overwrites every
  other entry including deliberately tightened ones. Under the group rule the
  candidate is accepted and written, because `{100}` is within `{100, 40}`.

  `WorseDirection::morePermissive()` remains the primitive for the single-member
  case (§2.8). `update` never adds an identity, and leaves untouched any identity
  absent from the measured set — a vanished group is `cleanup`'s business, not a
  reason to rewrite an entry to nothing.
- **cleanup** — lists every candidate with its reason (stale, or channel no longer
  declared) and its selector (§6), and removes only the entries named by
  `--remove`, which may be repeated. There is **no bulk form**: with no
  `--remove`, `cleanup` reports and changes nothing (§5.7, which explains why
  `--all-listed` was withdrawn).
- Both writing commands refuse to run when the current run's scope does not cover
  the file's recorded `scope`, behind `--force` (§5.7 — 10.2 stated the two
  halves of this in opposite directions; this is the correct one).
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
- **`@qmx-ignore`** — runs **before** the baseline after §5.2's move; a suppressed
  finding is never seen by the ceiling, and is never captured (§5.5). 10.2 left
  this sentence saying "after", describing the pre-move pipeline and contradicting
  its own next clause — and since §9 is the consolidated section a reader
  consults, that is the sentence an implementer would have built.
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

| File                                                         | Earlier                                                                                                                                         | Later                                                                                        |
| ------------------------------------------------------------ | ----------------------------------------------------------------------------------------------------------------------------------------------- | -------------------------------------------------------------------------------------------- |
| `src/Core/Violation/Violation.php`                           | P0 removed `$observation` (done)                                                                                                                | P3 adds the accepted-level field and re-evaluates the `constructor-overinjection` annotation |
| `src/Infrastructure/Console/ViolationFilterPipeline.php`     | P3 moves the stage, makes it transforming and group-valued                                                                                      | P4 adds the reporting of stale, inert and scope-mismatched entries                           |
| `src/Infrastructure/Console/ViolationFilterOrchestrator.php` | P2 removed the staleness failure path, the `--baseline-ignore-stale` messaging and the flag; P3 extracts the measured-set seam (§5.5) out of it | P4 rewrites `--show-resolved` over the measured set                                          |
| `src/Infrastructure/Console/ViolationFilterResult.php`       | P3 exposes the measured set and reshapes the per-stage counters                                                                                 | P4 replaces the `?BaselineFilter` carrier once resolved-reporting becomes entry-keyed        |

Three corrections to 10.2's assignments, each a fact rather than a preference:

- **The accepted-level field moves from P4 to P3.** §5.2 makes the baseline stage
  transforming, so P3 already reconstructs the `Violation` at the promotion site.
  Adding the field in P4 would send a second package back to the same
  construction site — the seam class this project's reviews keep finding defects
  in. The `constructor-overinjection` annotation is re-evaluated in P3 for the
  same reason: whoever changes the parameter count owns it.
- **`BaselinePresenter.php` is P4's for the opposite reason 10.2 gave.** It does
  not render `--show-resolved`; its only method is
  `generateBaselineIfRequested()`, which implements `--generate-baseline`. §7
  removes that option and `baseline:generate` replaces it, so P4 **deletes the
  class** rather than editing it. `--show-resolved` lives in
  `ViolationFilterOrchestrator`, which is why that file is now in the table.
- **P3, not P4, owns `generate`'s capture set.** P3's DoD needs a working
  `generate` observable, and `generate` today is fed `$result->violations` — the
  raw analysis output — from `CheckCommand`. P3 rewires it to the seam; P4 then
  removes the `--generate-baseline` path entirely. Without this said, P3's DoD
  depended on a file assigned to P4.

`src/Infrastructure/Console/ViolationFilterOptions.php`: P2 removed
`$ignoreStaleBaseline` with the flag; the remaining reshaping of its options is
P3's alone.

### P0 — Retire the v7 landings — **LANDED** (`3ae829d`)
Every DoD item verified: the five observation types, the four coverage types and
the two comparison types are gone; `WorseDirection` remains with `isWorse()` and
`morePermissive()`, each covered by a test naming its v10 consumer;
`isBetter()` is removed; `ViolationChannel` untouched; `Violation::$observation`
removed without touching the `constructor-overinjection` annotation (P3's, per
§10); `qmx.yaml` and `DogfoodingTopologyTest` restored byte-for-byte to their
pre-`171aa72` content; `composer check` green (6161 tests, PHPStan level 8).

### P1 — The channel declaration — **LANDED** (`9b388aa` mechanism, `91631dc` declarations)
Delivered as stated below, with three things worth carrying forward. The
declaration key is the **full** `ruleName#violationCode` form, because
`LayerViolationRule` emits four of its five channels under rule names other than
its own and a bare code could not address them. The shared declaration for the
code-smell and security families lives on their abstract bases via
`static::NAME`, after the duplication detector flagged twelve near-identical
methods. And `code-smell.unused-private` is declared by judgement (§5.4's fifth
family): two independent derivations agreed it reports a measured class-wide
count with no gating comparison, and a threshold is not what establishes
direction.

Original scope, for the record:

Files: `src/Core/Violation/**` (the declaration lives beside `ViolationChannel`,
not in a new `src/Core/Channel/` — two directories "about channels" would fail
ADR 0016's naming and co-change tests, and `ViolationChannel` is already the key
form §6 mandates), `src/Rules/**`, `src/Architecture/Rules/**`,
`src/Infrastructure/Rule/**`, `tests/Fixtures/Channels/**`, matching tests,
`src/Rules/README.md`.
Dependencies: P0 (landed).
DoD: every channel declares shape and, for `magnitude`, direction — or declares
no baseline support; the inventory-kind mapping of §5.4 is applied as stated
there; **51 channels are declared statically**, the **five** families of §5.4 as
stated there — `annotation.*` the only family excluded, all five
`LayerViolationRule` channels present as `occurrence`, `coupling.class-rank` as
`occurrence` while `coupling.cbo`, `.distance` and `.instability` stay
`magnitude`, and `code-smell.unused-private` as `magnitude` / `higher`; the four
declarations decided by judgement rather than derived
(`architecture.circular-dependency`, `coupling.class-rank`,
`code-smell.unused-private`, and the `LayerViolationRule` family) carry their
rationale in code; **every `magnitude` channel's direction is read at its own
gating comparison, and the enumeration is verified by two independent
derivations** — one from the inventory, one from the source alone with the
inventory withheld, because a single agent filling both the declarations and
their oracle can be consistently wrong and pass its own drift guard; **`computed.*` /
`health.*` resolve both shape and direction from the definition at run time**,
proven by a case with a configured user metric and an `inverted` one — 10.2
resolved only direction, which left the whole open family unbaselineable under
the "declares neither" default; the two `annotation.*` channels are covered by
that default without an explicit site, and the fixture records them as
deliberately absent so the guard does not read them as drift; **the enumeration
moves out of `docs/plan/` into a tracked fixture** so the drift guard survives
P6's deletion; the drift guard compares the **static** declared set against that
fixture in both directions, and separately asserts that every channel emitted
anywhere in the integration suite is declared. The fixture is the oracle, not the
suite: a suite-only guard narrows silently to whatever the tests happen to
exercise. The guard is scoped to the static set explicitly, because with any
`computed_metrics:` configured the declared set contains channels no fixture can
list — so the open family is guarded by its own run-time case instead, which is
stated here rather than discovered in P5.

### P2 — File format and entry semantics — **LANDED** (`8f8fb91`)
Files: `src/Baseline/Baseline.php`, `BaselineEntry.php`, `BaselineLoader.php`,
`BaselineWriter.php`, `BaselineGenerator.php`, `ViolationHasher.php` (removal),
matching tests, `src/Baseline/README.md`.
Dependencies: P1.
DoD: the §6 contract round-trips with byte stability under a fixed clock,
including `edge` and multi-element `magnitudes`; **magnitudes are normalised to
six decimal places at capture and round-trip exactly with `serialize_precision`
set to `15`, `17` and `-1`** — the three-value form is the observable, because a
single-value test runs under the developer's own ini and passes while the
property stays unpinned for users; a normalised `40.0` reloading as `int 40` is
asserted rather than left to surface as a flaky byte-stability test; the **entry
selector** of §6 is computed, printed and parsed, with a case where two entries
share a symbol and channel and differ only by `edge` and exactly one is
addressed; invalid and inert entries behave per §6 without failing the load;
writes are atomic under a real CAS guard; staleness and resolved-reporting are
keyed on the §5.1 identity; v5 is rejected outside `migrate`; §14's symbol-key
uniqueness question is decided here and its outcome pinned.

**Two obligations move here from P4, and the reason is that P2 cannot stand
alone without them.** P2 re-keys staleness onto the §5.1 identity while P4 owns
the removal of the failure path, so between the two packages the *first repair
of one channel on a multi-channel symbol* disables the whole baseline and fails
the build — the exact outcome §5.7 exists to prevent, on `main`, for however
long P2 stands alone. Declarations 1 and 2 of §5.7 therefore land in P2: a stale
entry is reported, never fails the run, and never disables the other entries.
`--baseline-ignore-stale` is removed with them rather than in P4, because
without a failure path it is a flag that does nothing, and a no-op flag is the
compatibility shim the project's policy forbids. Declaration 3 (`cleanup` never
removes on its own) and the `scope` guard stay in P4. Two consequences are
pinned here: `--show-resolved` works on a green build — it reads the same
predicate as staleness, so while staleness threw it could only print when there
was nothing to print — and the stale message stops claiming "symbols no longer
exist" and stops advising `baseline:cleanup`, which selects on a vanished
`file:` path and is a guaranteed no-op for a `method:`/`class:`/`ns:`/`project:`
entry.

### P3 — The stage, its new position and nature, the measured-set seam, and capture — **LANDED** (`7a656d2` engine, `6007b82` pipeline and seam)

Delivered as stated below. Three things are worth carrying forward. The
stage-order assertion reads a public, enumerable stage list, and the predicate
filters reach it through an adapter rather than being rewritten to consume
lists. The measured-set seam has two ways in — the stage list `check` continues
past, and a paths-in/findings-out entry point for the commands P4 adds — routed
through one definition, so a command cannot disagree with `check` about what was
measured. And the review round found that the set was still flag-dependent in the
widening direction, which produced §5.5's invariant and the rename of
`--no-suppression`; the two regression cases for it are named in this DoD.

Two obligations pass to P4, both recorded because a session boundary is where
they would be lost. **Inert entries have no route out of the pipeline:**
`Baseline::$inertEntries` is populated by the loader, but the ceiling stage holds
its baseline privately and the pipeline result carries only stale entries, so
§6's "`check` reports it as inert, naming symbol, channel and selector" is not
reachable yet. P4 opens that access in the shape it actually wants — carried out
of the pipeline beside the stale entries, or fetched — rather than adding an
accessor blindly. **Staleness is still a second call:**
`BaselineCeilingStage::staleEntriesOver()` must be handed the very list given to
`apply()`, and that holds by docblock only. The airtight shape returns the
filtered findings and the stale entries together from a `Baseline`-owned outcome
type — `Core` may not carry a `BaselineEntry` — which means changing the one
caller in `ViolationFilterPipeline`.

Original scope, for the record:

Files: `src/Baseline/Filter/**`, `src/Infrastructure/Console/ViolationFilterPipeline.php`,
`src/Infrastructure/Console/ViolationFilterOrchestrator.php`,
`src/Infrastructure/Console/ViolationFilterOptions.php`,
`src/Infrastructure/Console/ViolationFilterResult.php`,
`src/Infrastructure/Console/Command/CheckCommand.php`,
`src/Core/Violation/Violation.php` (the accepted-level field), matching tests.
Dependencies: P2.

This is the package 10.2 called "small". It is not: three of its items are
structural, and each was a round-3 finding.

DoD:

- **The pipeline exposes an ordered, enumerable list of stages, and the assertion
  reads that list** — the baseline stage immediately before git scope (§5.2).
  10.2 required an assertion "on the pipeline's stage order rather than inferred
  from its output" against a single 115-line method that constructs four of its
  five filters inline and has no stage collection to read; the extraction is
  therefore part of this package, including re-evaluating the method's
  `@qmx-threshold complexity.cognitive` annotation once the shape changes.
- **The stage is transforming, not a filter** (§5.2): it leaves
  `ViolationFilterInterface`, whose `shouldInclude(): bool` cannot express
  promotion, and the accepted-level field is added to `Violation` here so the
  reconstruction site is written once.
- **The measured-set seam of §5.5 is extracted** out of
  `ViolationFilterOrchestrator`: paths plus resolved configuration in, the
  violation list at the baseline stage's input out, no `InputInterface`
  dependency. `check` runs through it, and `generate`'s capture is rewired from
  `$result->violations` to it — the raw list it is fed today is what would have
  reproduced 10.1's two-measurement-set defect between commands.
- **Acceptance implements §5.1's cumulative rule over groups**, with shape-driven
  magnitude reading and `mode: suppress`; a test works the shrink cases by hand,
  including **the case that killed rank alignment: the best member of a group is
  repaired, nothing else changes, and the group is accepted.**
- **The recomputed side of the comparison is normalised too.** `round($v, 6)` is
  applied to the stored side by `BaselineEntry`'s constructor (P2), and the zero
  tolerance of §5.1 is unsound unless the number it is compared against goes
  through the same function: a raw recomputed value and its rounded stored copy
  can differ below the sixth decimal and read as a breach. The seam already
  exists — `BaselineEntry::normalizeMagnitude()` is public precisely for this —
  so the obligation is to call it, and to have a test that fails if it is not.
- **The measured-set seam replaces `BaselineFilter::measuredIdentityKeys()`, it
  does not merely wrap it.** That method is a `public static` the pipeline calls
  *before* any filter exists, and the same predicate is evaluated twice per run
  from two separately supplied lists — the pipeline's own `$violations` and the
  orchestrator's `$result->violations`. They agree today only because the
  baseline still runs at stage 1; moving the stage is exactly what stops them
  agreeing. Both call sites must read the seam, and the static must disappear
  rather than acquire a second caller.
- **Promotion is scoped to measured breaches** (§5.6): a test asserts, for each
  ambiguity of §5.1 including non-finite magnitudes, that the finding is reported
  **at its own configured severity** and not at Error; a separate test asserts a
  measured breach does promote and reports every group member.
- **No CLI flag widens the measured set** (§5.5): the definition of the set is
  flag-independent where suppression is concerned, `--no-suppression-annotations`
  is honoured after the baseline stage, and the restored findings still meet the
  exclusions and the git scope. Regression cases: capture then `check --baseline`
  under the flag on unchanged code does not promote and does not fail; capture
  *under* the flag writes no entry for an annotated finding; the measured set is
  identical with and without it; the flag is not thereby a no-op — the annotated
  finding reaches the report, at its own severity.
- **No entry is written for a finding `exclude_paths` or `@qmx-ignore` removes,
  and a group with one ignored member round-trips through `generate` then
  `check`** — the observable a DoD phrased as "reads the stage-1 input" would have
  passed without any change to the code. The `exclude_namespaces` case is written
  for a non-`architecture.*` channel, since that family is exempt (§5.2).
- **A finding removed by `@qmx-ignore` is absent from the set the baseline stage
  measures** — false today by construction, so it witnesses the move. Retain "a
  git-scoped run marks nothing stale" as a regression guard, but not as evidence:
  it already holds, because staleness is computed at stage 1 and git scope is
  stage 5.
- A stale entry neither fails the run nor disables other entries (§5.7).
- The §13 cases named to P3 land here.

### P4 — Commands and reporting — **LANDED** (`9278683`…`135760d`, eight commits)

Delivered as stated below. Four things are worth carrying forward, and the
first two are corrections to *this document*.

**§7's signature block is now incomplete, and §5.5 overstates its own case.**
The five commands also take `--preset`, `--rule-opt`, `--only-rule` and
`--disable-rule`. Extended review found that without them
`check --preset=strict --baseline=b.json` measures a wider set than
`baseline:generate b.json` captured, and promotes findings the capture never
saw — the exact harm §5.5's widening invariant exists to prevent. These are
*configuration* options, not the exclusion and suppression flags §7 refuses,
and §5.5 already says the set is defined by configuration; the sentence
"every baseline command would have to replicate `check`'s option surface"
was true of exclusions and false of these. The asymmetry is narrowed rather
than closed: `check`'s dynamically registered per-rule aliases (`--max-ccn`
and friends) are not mirrored, and `--rule-opt` reaches all of them instead.

**§14.1 is decided.** The only thing a v5 entry and a v10 finding both carry
is the pair `(symbolKey, ruleName)` — v5's key is the same canonical symbol
form, and the rule name is the prefix of a v10 channel key. `migrate` writes
a fresh capture and merges nothing; the report splits the v5 file three ways:
*carried* and *fresh* are counted, *dropped* are listed in full, because each
of those is an acceptance the user loses and a v5 entry has no magnitude and
no channel that a v10 entry could be built from. Rows the v5 file spelled
unreadably are named too — `migrate` is one run, and a row silently skipped
is an acceptance the user never learns was not even read. `migrate`'s
`--force`, which §7 left unassigned, permits overwriting a destination that
is *not* a v5 file, so a mistyped path cannot replace a good v10 baseline
with a fresh capture.

**The measured set had a second, quieter way to disagree with itself.**
`cleanup`, `update` and `explain` originally read the baseline file *before*
resolving configuration. A `computed.*` or `health.*` channel is declared
only once configuration is resolved (§5.4's open family), and the loader
turns an entry on an undeclared channel inert — so those three commands
answered differently from `check` on the same file, and the premise had
already been written into two docblocks as though it were intended. Order is
now configuration first, file second, pinned by a test that fails on the old
order and by an integration test on the real measured-set seam. That seam had
been reachable only through a stub until then, which is why the defect
survived the package's own DoD.

**Scope was five findings serving one mechanism.** A path equal to the
project root has no project-relative form, so a run over the root recorded an
absolute machine path — non-portable across checkouts, and a breach of
CLAUDE.md §10's rule against home paths in tracked files — and then read as a
*narrowing* against `scope: ["src"]`, refusing the widest run there is.
`update` additionally overwrote the recorded scope with the run's, so one
`--force` over a narrow run made the file claim a narrow run had produced it
and the guard never fired again. `RunScope` now owns portability,
normalisation and coverage together; `ScopeCoverage` and both copies of
`portableScope()` are gone.

Two smaller review outcomes worth keeping: `explain` resolved a rule's
configured threshold by property-name convention, which returns a *wrong*
number rather than none where one channel is judged against two boundaries
(`code-smell.long-parameter-list` measures a readonly-VO constructor against
`voWarning`, not `warning`) — it now reports the boundary as unresolvable,
distinctly from zero. And `migrate` wrote without a compare-and-swap token
while the other writing commands carried one.

**One defect found in passing and deliberately not fixed here:**
`--only-rule` silently drops every built-in `computed.*` finding.
`ComputedMetricRule::NAME` is `computed.health`, but each violation's code is
the definition's own name (`health.complexity`), which shares no prefix with
it; `RuleExecutor` lets the rule run on a `ruleName` match and then filters
its violations on the code. It predates this feature, and it now matters more
because the baseline commands accept `--only-rule` too.

Original scope, for the record:

Files: `src/Infrastructure/Console/Command/Baseline*`, `CheckCommandDefinition.php`,
`src/Infrastructure/Console/ViolationFilterPipeline.php` and
`ViolationFilterOrchestrator.php` and `ViolationFilterResult.php` (the later
aspects of §10's table), `src/Infrastructure/Console/BaselinePresenter.php`
(deleted), `src/Reporting/**`, matching tests, the affected READMEs.
Dependencies: P3.
DoD: all five commands behave per §7, each obtaining its set from P3's seam and
declaring no exclusion or suppression flags (§5.5); **`update` is direction-aware,
proven on a `lower` channel where a numerically smaller value is the wider one**,
and its group rule is proven on the two cases a per-position rule gets wrong — a
shrink that must be **accepted** and written, and a `lower`-channel `count` that
must be refused a widening; **`cleanup` with no `--remove` reports and writes
nothing**, `--remove` removes exactly the named entries by selector and leaves
their neighbours, including two entries differing only by `edge`, and there is no
bulk form (10.2's DoD demanded behaviour keyed on a "category flag" the same
revision abolished, which was unverifiable except by reintroducing it); both
writing commands refuse a run whose scope does not cover the recorded one, without
`--force`, with a case for each direction — narrowed refused, widened allowed;
`explain` prints all three sources, and on a channel whose scale can drift it
prints both the stored number and the number currently compared (§13.5);
`--show-resolved` is **computed over the measured set of §5.5 and keyed on the
§5.1 identity** — the two facts that actually change, since "counts entries, not
violations" is already true of today's code and would have been signed off
unchanged; `BaselinePresenter` is deleted with `--generate-baseline`;
the staleness failure path and `--baseline-ignore-stale` are **already gone (P2)**,
so P4 asserts nothing about them; the accepted level reaches the text report and
every machine format with schemas still valid, and promoted severities are correct
in SARIF's result levels and run-level default; §14's `migrate` report shape is
decided here; the §13 cases named to P4 land here.

### P5 — Seam and dogfooding tests
Files: `tests/Integration/BaselineCeiling/**`,
`tests/Functional/Console/Command/BaselineLifecycleTest.php`,
`tests/Fixtures/BaselineV10/**`.
Dependencies: P4.
DoD: §12's matrix passes; **all twelve items of §13 have the case their own line
names, and the packages named there have landed them** — P5 verifies the list is
complete rather than owning the cases; the round-trip property holds on this
repository, including with an `@qmx-ignore` inside a baselined group —
`generate` immediately followed by `check` reports nothing; **a repair measured
end to end on this repository**: delete one of two duplicate blocks in a
baselined file and assert the build stays green, which is the CRITICAL of round 3
pinned against real code rather than a fixture; a handful of findings fixed by
hand are listed by `cleanup` and removed only when named; memory measured against
the 2G ceiling on the largest benchmark project.

### P6 — ADR and documentation
Files: `docs/adr/0017-baseline-ceiling.md`, `docs/adr/README.md`,
`docs/ARCHITECTURE.md`, `website/docs/usage/baseline{,.ru}.md`,
`website/docs/usage/cli-options{,.ru}.md`, `CHANGELOG.md`, and the deletion of
`docs/plan/`.
Dependencies: P5.
DoD: the ADR records the ceiling decision, §5.3's boundary against v7, §5.1's
cumulative rule with the argument for it, and §15; documented options match
`--help`; EN/RU parity; strict MkDocs build clean; `Breaking` entries name the
removed v5 format, `--generate-baseline`, `--baseline-ignore-stale`, the
stale-entry behaviour change, and **the rename of `--no-suppression` to
`--no-suppression-annotations` together with the behaviour change behind it —
the flag no longer widens what a baseline measures, so a run that passes it can
no longer promote an annotated finding to Error (§5.5; landed in P3, and the
`Breaking` entry is owed here because a CHANGELOG written from P3's commits
alone would record the rename without the reason)**; `docs/plan/` is gone and **nothing outside it
references it by section number either** — `WorseDirection`'s two operator
docblocks and its tests currently cite "§7 / §5.1 of the ratchet-baseline plan"
and must be repointed at the ADR, which is the whole point of the ADR outliving
the plan.

## 11. Execution sequence

1. ~~Review this revision~~ — three rounds done; round 3's 19 findings are folded
   in above.
2. ~~P1 → P2 → P3, sequential~~ — done. P3 carried the stage-list extraction, the
   transform-shaped contract and the measured-set seam, each of which 10.2 had
   hidden inside a one-line DoD clause; its own review round added §5.5's
   widening invariant.
3. ~~P4~~ — done, in eight commits, with an extended review round between the
   implementation and the fixes. Its review found eight HIGH defects, five of
   which served the single scope mechanism and were closed by replacing it.
   Then P5, then P6.
4. Full validation: `composer check`, `bin/qmx check src/`, benchmark regression
   suite, website build.

## 12. Test plan

- **The fail-safe invariant, asserted directly** — one case per ambiguity of
  §5.1: unknown channel, undeclared shape, magnitude present where none is
  stored and the reverse, `NaN` and `±INF`, unrecognised `mode`, malformed entry,
  renamed symbol.
- **Acceptance per shape** — magnitude higher-is-worse; lower-is-worse
  (`maintainability.index`, `design.type-coverage.*`); an inverted computed
  metric; a continuous axis (`coupling.distance`); occurrence-only
  (`code-smell.goto`); an occurrence channel that nevertheless reports a real
  number (`coupling.class-rank`), whose value must be ignored; an edge-bearing
  occurrence channel (`architecture.layer-violation`) where one forbidden edge is
  swapped for another and the group must be reported.
- **Multi-member groups, worked by hand against §5.1's cumulative rule** — two
  duplication blocks of different lengths where the *smaller* one grows; a group
  that shrinks; a group that shrinks and gains a worse member at once;
  equal-magnitude member swaps; **the case that killed rank alignment: the group's
  best member is repaired and nothing else changes, which must be accepted** — the
  case an implementer writing "a group that shrinks" from an illustration would
  have satisfied by removing the *worst* member instead; and its mirror, a
  survivor growing past the worst previously accepted magnitude, which must be
  reported.
- **Shape versus value** — a `marker` channel whose fixed `1.0` must not be read
  as a magnitude, and a `magnitude` channel whose entry omits `magnitudes`; the
  first must still suppress by count, the second must not suppress at all **and
  must report at the finding's own severity, not at Error**.
- **Promotion scope (§5.6)** — a measured breach promotes; every fail-safe path of
  §5.1 reports without promoting. The pair matters more than either half: a
  version-skewed shape declaration must not turn a whole channel's worth of
  unchanged findings red.
- **Magnitude normalisation (§5.1, §6)** — a magnitude round-trips exactly at
  `serialize_precision` `15`, `17` and `-1`; a continuous channel accepts across
  two runs where an unrelated file was added, so a globally-drifting value cannot
  breach on its own.
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
  nor counted against an entry; an `exclude_namespaces` finding likewise, written
  on a non-`architecture.*` channel because that family is exempt, plus the
  converse case proving an architecture finding inside an excluded namespace *is*
  captured; a `--report=git:*` run marks nothing stale; **and the same
  `@qmx-ignore`d and excluded entry carried through `generate`, `update`,
  `cleanup`'s candidate listing, staleness and `--show-resolved`** — each of those
  passes its own direction, scope and count tests while consuming the raw
  analysis list, so only a case that names the ignored member distinguishes them.
- **Staleness** — a repaired finding produces a stale entry, the build stays
  green, other entries keep applying, `cleanup` without a flag changes nothing,
  and `cleanup --remove` takes one named entry and leaves its neighbours.
- **The limitations of §13** — one case each, listed there; §13 and this section
  are checked against each other rather than each referring to the other.
- **Lifecycle** — `update` direction-awareness on both directions including a
  refused widening; `update` accepting a shrink and writing it; `update` refused a
  `count` widening on a `lower` channel; `update` ignoring a vanished group; the
  scope guard on both commands in **both** directions (narrowed refused, widened
  allowed); `cleanup` addressing one of two entries that differ only by `edge`;
  `migrate` in one run with its report; serialisation round-trip, byte stability,
  atomic write, failed-rename cleanup, concurrent writers under CAS.
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
   `coupling.cbo` changes meaning with the `scope` option, and a computed metric's
   formula or `inverted` flag can be rewritten. The stored number then bounds a
   differently-meaning quantity, and the direction is toward over-acceptance.
   Detecting it would mean storing the configuration that produced the magnitude —
   §15's rejected alternative. The one channel where the drift was continuous and
   unavoidable rather than configuration-driven, `coupling.class-rank`, is no
   longer a `magnitude` channel at all (§5.4).
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
12. **A survivor may grow into a slot a repair vacated.** With `{100, 40}`
    accepted, repairing the 100 and letting the 40 grow to 95 is accepted, because
    no level of severity holds more members than before. Debt cannot exceed the
    worst magnitude already accepted, and both the total and the maximum fell — so
    this is a redistribution inside an existing ceiling, not new debt. It is the
    price of having no member identity (non-goal 2); the alternative is the rank
    alignment §5.1 rejects, which bounds this case and fails goal 5 on the far
    more common one. Still strictly tighter than v5, which accepted unbounded
    growth.
    *Pinned by P3:* a survivor growing to just under the vacated magnitude is
    accepted; growing past it is reported.

## 14. Open items

1. **The `migrate` report's shape** — what a user is shown for v5 entries that no
   longer fire, and whether `migrate` writes them anywhere. Decide in P4.
2. **Symbol-key uniqueness** (§13.9) — whether the identity gains a
   discriminator, or the collisions are accepted and pinned. The `__PROJECT__`
   collision is a pre-existing defect independent of this feature. Decide in P2,
   together with **aggregation-level keys**: confirm a namespace-level entry is
   unambiguous under the namespace strategy and the aggregation prefixes, both of
   which can rename a symbol with no code change.
3. **The entry selector's exact form** (§6) — a digest of the full identity, and
   how long it has to be to stay collision-free and still be typed by a human.
   Decide in P2, where the identity is built.

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

**Rank alignment of magnitude vectors (revisions 10.1 and 10.2).** The comparison
paired the k-th member of the current group against the k-th of the stored one,
and the two revisions chose opposite ends. Both are wrong, and for the same
reason: a rank pairing has to assume something about *which* members a shrinking
group lost, and neither assumption holds. From the worst end the assumption is
"the best ones vanished", which under-reports; from the best end it is "the worst
ones vanished", which reports a breach when a user deletes the smallest of two
duplicate blocks and touches nothing else — a green build turning red at Error on
a pure repair, and, per the same revision's `update` rule, unrepairable except by
regenerating the whole file. §5.1's cumulative statement replaces both: it counts
members per severity level instead of pairing them, so there is no end to choose,
and it is provably equivalent to the worst-end pairing while also subsuming the
count condition. What it accepts is §13.12, stated as a limitation.

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
