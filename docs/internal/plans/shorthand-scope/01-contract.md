# Stage 1 — the contract

What a top-level key means beside a nested level block, in every orientation,
with the cost of each answer. Nothing here is derived by analogy from ADR 0058:
that ADR settled whether one layer's value survives a higher layer, key by key.
Depth against layer is a second question and is decided here, explicitly.

Revision 2, after review. Four HIGH findings against revision 1 are folded in
rather than appended: the orientation table now separates the two families, the
top-level `enabled` is named as a third breaking change, `scope` is placed, and
the fill unit is stated as three units rather than one.

## Vocabulary

- **Band** — a threshold pair and the `threshold` shorthand that stands for it.
  One band is one slot; `threshold` and `warning`/`error` are two spellings of
  it. Which keys form a band for a given rule and path is declared in
  `RuleThresholdKeyGroupRegistry`, never guessed.
- **Level** — `callable:`/`class:` for the complexity family, `class:`/
  `namespace:` for the coupling family. Each level carries its own band, its own
  `enabled`, and its own defaults.
- **Reach** — the set of levels a top-level key covers. This is the concept the
  registry has no way to declare today, and the whole cure hangs on it.
- **Layer** — preset, config file, CLI. Layers are merged at two seams; a key
  written in one layer is a statement of that layer alone.

## The contract

**C1. A top-level key covers the levels its rule declares it covers, and no
others.** Reach is declared per rule and per key, checked against the levels the
options class actually builds, and is not inferred from key names.

**C2. Within one layer, the deeper key wins for what it names; the top-level key
fills only what that layer left unwritten.** Two halves, and the second is the
one revision 2 left implicit:

*What the top-level key may fill.* Two groups are pushed down, and each has its
own fill unit:

| group     | fill unit | "already written" means                           | fallback when the level wrote half of it    |
| --------- | --------- | ------------------------------------------------- | ------------------------------------------- |
| the band  | the band  | the level carries any written key of its own band | that level's own default for the other half |
| `enabled` | the key   | the level carries its own `enabled`               | not applicable — one key                    |

A level that wrote half a band has chosen the graduated mode for that band; the
top-level band does not reach into it, and the half it did not name takes that
level's own default, never the top-level value. Symmetrically, a top-level band
that is itself only half written pushes down only the half that was written.

*What the push-down may never touch — everything else.* **The push-down only
ADDS keys to a level. It never removes, replaces or rebuilds any key the level
wrote.** Level keys outside the two groups above — `min_afferent`,
`min_class_count`, `scope`, and anything a later rule adds — are the level's own
and survive untouched, without needing to be enumerated here.

This half is not decoration. Measured: `coupling.instability: {class:
{min_afferent: 0}}` reports 2 findings, and the same block with `threshold: 1.01`
beside it reports none — the level's `min_afferent` is discarded along with the
band it never wrote, because `CboOptions`/`InstabilityOptions` rebuild the level
config from exactly three keys and every other level key is structurally
unreachable inside that branch. Revision 2's C2 answered this document with
neither half of its rule.

**C3. Across layers there is no composition rule at all.** Every layer is made
unambiguous before it is merged — its top-level keys are pushed down into the
levels they reach and removed — so the merge stays what it is today: a plain
key-for-key overlay in which the higher layer wins for exactly the keys it
names. Specificity never overrides layer priority, in either orientation. (The
values of the two layers are of course assembled into one document; what does
not happen is any rule reading depth against layer, which is what revision 1
called "nothing composes".)

**C4. Refusal is unchanged: two spellings of one band, in one array, in one
layer.** No new refusal is introduced, and — this is the part revision 1 left
unguarded — no existing one is removed. A top-level band beside a level block is
not the refused shape: the two address different depths, so they compose under
C2.

`fromArray()` receives a document in which no top-level band survives. Its early
returns therefore have nothing left to return on and are removed, not repaired.

## The five questions

### Q1 — specificity against layer priority, both orientations

**Answer: layer priority always wins; specificity acts only inside a layer.**
This follows from C3 and is a decision, not a consequence of ADR 0058.

The two families differ, so the table separates them. "Today" is measured, not
inferred (`measurement/seams.md` Q6, `measurement/population.tsv`).

| #   | rule family | lower layer              | higher layer                | today                                                     | under this contract                                                       |
| --- | ----------- | ------------------------ | --------------------------- | --------------------------------------------------------- | ------------------------------------------------------------------------- |
| 1   | complexity  | preset `{threshold: 5}`  | config `{class: {…}}`       | config's block discarded; class level OFF                 | preset fills `callable` only; config's `class` block applies              |
| 2   | complexity  | preset `{class: {…}}`    | CLI `threshold=50`          | CLI's shorthand switches the class level OFF — 0 findings | CLI fills `callable`; **the preset's class block stays alive**            |
| 3   | coupling    | preset `{threshold: 5}`  | config `{class: {…}}`       | config's block discarded; 5 applied to both levels        | preset fills both levels; config's `class` keys overlay `class` only      |
| 4   | coupling    | preset `{class: {…}}`    | CLI `threshold=50`          | CLI's band wins on both levels                            | CLI fills `namespace` and overlays `class` — same outcome, other reason   |
| 5   | either      | config `{threshold: 50}` | CLI `{class: {warning: 1}}` | CLI's half band; config's other half lost                 | CLI's `class.warning` overlays; config's pushed-down `class.error` stands |

Row 2 is the orientation revision 1 got wrong, and it is not a table error but a
missing breaking change: see B1's cross-layer face below. Measured —
`complexity.npath` preset block alone reports 2 class findings, the same preset
with `--rule-opt='complexity.npath:threshold=50'` reports 0, and under this
contract it must report 2 again.

### Q2 — where the cure lives

**Answer: at the per-layer unfold, before either merge — with the top-level band
removed once it has been pushed down.** Q1's answer forces it: provenance exists
only there. Two measurements narrow it further (`measurement/seams.md`):

- the factory seam runs unconditionally, so a single-layer document reaches it;
  the resolver seam does not run for a single contribution, so the cure cannot
  live only there;
- for `coupling.cbo`/`coupling.instability` the top-level `threshold` is already
  unfolded into the top-level graduated pair, and that pair is what opens the
  flat branch that discards the block. Leaving it in place would drown the block
  more reliably than today.

### Q3 — granularity

**Answer: the band fills by band, `enabled` fills by key, and every other level
key is not filled at all.** Revision 1 answered "the band, once" while in fact
using more than one unit — the same defect the withdrawn plan was withdrawn for.
Stating the third case as "not filled" rather than as a third unit is what closes
it against keys nobody has written yet.

Two measurements decide the band against key granularity. The levels of one rule
carry **different key names and different scales** — `MethodComplexityOptions`
uses `warning`/`error` at 10/20 while `ClassComplexityOptions` uses
`max_warning`/`max_error` at 30/50 — so filling one half of a level's band from
a top-level value of the other scale can produce `error < warning`, and the
product rejects an inverted band nowhere: it collapses the band into a solid
`error` (measured, `seams.md` Q3 — 27 findings, none of them `warning`). Key
granularity would also place a `threshold` key beside a written `warning` inside
one level array, which is the one shape the product does refuse.

`scope` is the counter-case that proves the unit belongs to the group and not to
the contract: `CboOptions::fromArray()` already composes it by key today — the
top-level value reaches `class` only when the block did not name it. That
behaviour is kept exactly as it is, and P3 must not push `scope` down at the
seam: doing both would apply it twice.

### Q4 — `class: {}`, `class: ~`, `class: {enabled: ~}`

**Answer: all three name nothing, and need no classification rule of their
own.** Under C2 the question "is this level named?" never arises: a level whose
band is unwritten takes the top-level band if the rule's reach covers it,
whether the key is absent, `~`, empty, or present with only a `~` inside. `~`
keeps the single meaning ADR 0058 gave it — silence, not a reset — and an empty
map does not become an enabler of anything.

This is why the contract does not say "a block re-enables the level". That rule
would have had to answer why `complexity.npath: {threshold: 50, class: {}}`
behaves differently from `class:` absent, when `ClassNpathComplexityOptions`
defaults to `enabled: false` and its two siblings default to `true`. Under C2
there is nothing to answer: enablement comes from keys, never from presence.

Measured today: `class: ~` and `class: {enabled: ~}` change nothing and refuse
nothing, and `null` reaches `fromArray()` unchanged (`seams.md`, Q5 d1/d2).

A value that is neither a map nor `~` is a different matter and is guarded in
`02-cure.md` K5: `class: 5` refuses today, and the cure must not silence it.

### Q5 — `enabled: false` beside a block

**Answer: the same contract, with `enabled` as its own group.** Top-level
`enabled` covers every level of its rule and fills each level that did not write
its own `enabled`. It is not a gate and gets no exception.

- `{enabled: false}` alone still switches the rule off: it fills every level.
- `{enabled: false, class: {enabled: true, warning: 1, error: 1}}` leaves the
  class level on. Today it reports zero findings where the block alone reports
  three (`review-native.md`, claude-04 of the previous round).
- The ledger already promises this: all five `enabled × class.enabled` rows read
  `compose`, "both apply at their own depth" (ADR 0052 row 3).

The withdrawn plan called this a ledger correction rather than a product change,
on the ground that the outcome is identical either way. It is not: the outcome
differs on the one-line document above, and the block is not inert at the
recognition seam either — an unknown key inside it exits 3 while the rule is off.
"Read enough to refuse by, not enough to obey" is not legitimate inertness.

This answer carries a breaking change of its own, B3 below, because one of the
ten levels defaults the other way.

## Reach, per rule

Reach is what the cure must declare. The table below is the claim; the machine
enumeration behind it, with today's outcome measured per cell, is
`measurement/population.tsv` and `measurement/population-gap.tsv` — read those
rather than this summary when checking completeness.

| rule                   | levels               | top-level band                          | reach of the band | `enabled` reach | pushed down at the seam? |
| ---------------------- | -------------------- | --------------------------------------- | ----------------- | --------------- | ------------------------ |
| `complexity.ccn`       | `callable`, `class`  | `threshold` / `warning`+`error`         | `callable`        | both levels     | band, `enabled`          |
| `complexity.cognitive` | `callable`, `class`  | `threshold` / `warning`+`error`         | `callable`        | both levels     | band, `enabled`          |
| `complexity.npath`     | `callable`, `class`  | `threshold` / `warning`+`error`         | `callable`        | both levels     | band, `enabled`          |
| `coupling.cbo`         | `class`, `namespace` | `threshold` / `warning`+`error`         | both              | both levels     | band, `enabled`          |
| `coupling.instability` | `class`, `namespace` | `threshold` / `max_warning`+`max_error` | both              | both levels     | band, `enabled`          |

`coupling.cbo` also accepts a top-level `scope` reaching `class`. It is **not**
pushed down at the seam: it is composed by key inside `CboOptions::fromArray()`
today and stays there. P4 must preserve that composition when it removes the
early returns around it.

Why pushing the band into a level is enough, and enabling it is not also needed:
of the ten level options classes only `ClassNpathComplexityOptions` defaults to
`enabled: false`; the complexity band reaches `callable`, where all three rules
default to `true`, and both coupling rules' levels default to `true`. Verified
by reading all ten constructors.

## The three breaking changes, named

**B1. A bare `complexity.*` shorthand no longer switches the class level off.**
Today `complexity.ccn: {threshold: 3}` forces `class` to `enabled: false`; under
C1 the band reaches `callable` only and says nothing about `class`.

*Single-layer face:* the class level keeps its own default. For `ccn` and
`cognitive` that default is on (30/50), so documents that write only the
shorthand gain class-level findings; for `npath` the class default is off, so
nothing changes. Measured: the control reports one class finding, the bare
shorthand none (`seams.md`, Q5 b).

*Cross-layer face, which revision 1 missed:* across layers the class level takes
**the lower layer's value, not its default**. Both shipped presets write
`class:` blocks for `complexity.ccn` and `complexity.cognitive` (20/35 and
60/100), so `--preset=strict` together with a top-level shorthand — a normal CI
shape — starts reporting class-level findings at the preset's thresholds. This
is the larger half of B1's cost and belongs in the CHANGELOG entry and on
`rules/complexity.md`, not only here.

The alternative — unfolding the shorthand into `class: {enabled: false}` as
well — was rejected because it leaves this round's subject uncured: a `class:`
block written beside the shorthand names the band, not `enabled`, so the level
would stay off and the written block would still change nothing.

**B2. A top-level band beside a level block now composes instead of replacing
it.** This is the round's subject and the reason it exists. Every affected
document moves from "one of the two written keys has no effect" to "both apply
at their own depth".

**B3. A top-level `enabled` now reaches `complexity.npath`'s class level, which
defaults to off.** `complexity.npath: {enabled: true}` changes nothing today and
switches the class level on at 500/1000 under Q5; `{enabled: true, class:
{max_warning: 2, max_error: 3}}` moves from no findings to the block's. This is
the one level of ten whose default runs the other way, and generalising over the
five rules is what hid it in revision 1. Measured: E1 `{enabled: true}` → 0
class findings today, E2 `{class: {enabled: true}}` → 1, and under Q5 E1 must
equal E2.

## Cells the enumeration found that no earlier round named

The population was enumerated declaratively from the options classes (reflection
over `HierarchicalRuleOptionsInterface`, `levelOptionsClasses()`,
`acceptedOptionKeys()`, constructor defaults) and every cell was then run through
the product binary: 560 declared cells plus 60 refusal probes, extended by
`population-gap.tsv` with the two forms review found missing. Five outcomes in
it were not named by any prior measurement of this subject.

**Cured by the contract:**

- **A level's explicit `enabled: false` is silently flipped ON by a top-level
  shorthand.** `complexity.ccn: {threshold: 1, callable: {enabled: false}}`
  reports 13 findings. Same shape on both levels of `coupling.cbo` and
  `coupling.instability`. Cured by C2's second half rather than its first: the
  document carries no top-level `enabled` at all, so nothing is entitled to fill
  that key, and pushing a *band* down may not touch a level's `enabled`. The
  level stays off because no group of the contract has the right to move it —
  not because the level "wrote its own". The distinction matters for the
  implementation: the answer must not depend on the level having written the key.
  The neighbouring cell settles the other direction and is not in the population
  either — `{threshold: 1, enabled: true, callable: {enabled: false}}` must still
  report none, because there the level did write it.
- **A level's `enabled: true` does not switch the level back on beside a
  shorthand.** `{threshold: 1, class: {enabled: true, max_warning: 1,
  max_error: 2}}` reports 0 where the same block without the shorthand reports
  11. Cured by C1: the complexity band does not reach `class` at all.

**Found by the population extension review asked for, and folded into C2:**

- **A block writing only a non-band key is discarded by a top-level band.**
  `coupling.instability: {threshold: 1.01, class: {min_afferent: 0}}` reports 0
  where the block alone reports 2; the same for `scope` and `min_class_count`.
  This is the document revision 2's C2 answered with neither half of its rule,
  and it is why C2 now says what the push-down may never touch. The form exists
  only in the coupling family: the complexity levels declare no non-band key
  other than `enabled`, so it is five combinations, not ten.
- **A top-level half band overrides a level's written `enabled: false`.** Same
  family as the mirror image above, cured by the same clause; worth naming
  separately because its neighbour — a top-level `enabled: false` — is honoured,
  so the product respects the rule's own switch and discards the level's.
- **A top-level half band puts a half-written band into both levels.**
  `coupling.cbo: {warning: 30}` reports an *error* on a class with CBO 25, at the
  level default of 20, while both neighbouring spellings of the same intent
  (`threshold: 30`, and `warning: 30, error: 31`) report nothing. The unwritten
  half comes from the `ThresholdParser::parse()` call-site defaults. Under C2 the
  pushed band carries only the half actually written and the level supplies the
  other, which changes this outcome; it is named here so the change is not read
  as an accident.

**Half cured, and said so rather than claimed either way:**

- **`complexity.npath` class level: a stricter threshold yields fewer
  findings.** `{class: {enabled: true}}` reports 1, `{class: {max_warning: 1,
  max_error: 2}}` reports 0, both together report 11; the cause is
  `ClassNpathComplexityOptions` defaulting to `enabled: false` where its siblings
  default to `true`. The half of this reachable **with** a top-level `enabled`
  is cured as a consequence of Q5 — that is B3. The half reachable with **no**
  top-level key present is not cured: it is a question about a level's own
  default enablement, not about what a top-level key covers, and answering it
  here would make the contract answer two questions. It is this programme's next
  subject, with its measurement already taken.

**Not cured, and not this round's subject:**

- A refusal names keys in a spelling a document cannot carry: the unknown-key
  refusal inside a level reports kebab-case names that cannot be written as
  written.
- The exit code reflects severity, not whether the configuration took effect: 13
  findings exit 0 with half a band and 2 with the full band. Named because
  acceptance cases reading exit codes instead of findings would judge the wrong
  thing.

## What this answer does NOT change

- The same-depth mode mix still refuses (`{threshold: 30, warning: 10}`), with
  the message and exit code it has today.
- The malformed-level refusal still refuses (`class: 5` — "takes a map of
  options, got int"), guarded by K5.
- `~` still means silence, at every depth and across layers.
- Nested `threshold` inside a level (`callable: {threshold: 5}`) keeps its
  current meaning; the gate's `layered-threshold` case writes exactly that shape
  and must not move.
- `scope` keeps composing by key inside `CboOptions::fromArray()`.
- Inline `@qmx-threshold` and its `withOverride()` path are untouched: it is
  applied to an already-built level object, never through `fromArray()`.
- The inverted band (`error < warning`) is accepted today and stays accepted.
  The contract does not introduce it and does not cure it; C2 keeps the cure from
  producing one the author did not write.
