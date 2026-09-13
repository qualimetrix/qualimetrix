# Stage 1 — the contract

What a top-level key means beside a nested level block, in every orientation,
with the cost of each answer. Nothing here is derived by analogy from ADR 0058:
that ADR settled whether one layer's value survives a higher layer, key by key.
Depth against layer is a second question and is decided here, explicitly.

Revision 3, after two rounds of review. Round 1's four HIGH are folded in — the
orientation table separates the two families, the top-level `enabled` is named as
a third breaking change, and the fill unit is stated honestly. Round 2 added two
more: `scope` is pushed down at the seam rather than left composing layer-blind
inside `fromArray()`, and C4 says where the top-level mix refusal has to move,
because the cure deletes the branch that carries it today. Sections are rewritten
rather than appended to; a plan patched paragraph by paragraph argues with
itself.

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

*What the top-level key may fill.* Three groups are pushed down, and each has its
own fill unit. This table is the norm; the prose elsewhere in this file explains
it and does not extend it.

| group                    | fill unit | "already written" means                            | fallback when the level wrote half of it    |
| ------------------------ | --------- | -------------------------------------------------- | ------------------------------------------- |
| the band                 | the band  | the level carries any written key of its own band  | that level's own default for the other half |
| `enabled`                | the key   | the level carries its own `enabled`                | not applicable — one key                    |
| `scope` (`coupling.cbo`) | the key   | the level carries its own `scope`, written not `~` | not applicable — one key                    |

`scope: ~` at a level means the level did not write one, and the top-level value
fills it. That is not a new rule but the one ADR 0058 already gave `~`, and it
matches what the product does today: `ClassCboOptions::parseScope()` reads `~` as
absent and `CboOptions::fromArray()` tests `isset()`, which `~` fails.

A level that wrote half a band has chosen the graduated mode for that band; the
top-level band does not reach into it, and the half it did not name takes that
level's own default, never the top-level value. Symmetrically, a top-level band
that is itself only half written pushes down only the half that was written.

*What the push-down may never touch — everything else.* **The push-down only
ADDS keys to a level. It never removes, replaces or rebuilds any key the level
wrote.** Level keys outside the three groups above — `min_afferent`,
`min_class_count`, and anything a later rule adds — are the level's own and
survive untouched, without needing to be enumerated here.

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

**C4. Refusal is two spellings of one band, in one array, in one layer — and that
set moves in exactly one direction, once.** No existing refusal is removed — the
part revision 1 left unguarded — and one document starts refusing that does not
today, named as B4 rather than discovered later. A top-level band beside a level block is
not the refused shape: the two address different depths, so they compose under
C2.

**Where that refusal lives has to move, and revision 3 did not notice.** Today
`coupling.cbo: {threshold: 30, warning: 10}` exits 3, and the mechanism is
indirect: the seam's condition 3 deliberately declines to unfold a layer that
wrote both spellings, so both survive into `fromArray()`, whose flat branch calls
`ThresholdParser::parse()` at the top level and refuses there. That flat branch is
the one this round deletes — and the only top-level `parse()` call of both
coupling rules is inside it. Measured on two builds of the product: pristine
exits 3 with `Cannot mix "threshold" with "warning"/"error"`; with the flat branch
removed the same document exits 2 with four findings and both keys silently gone.

So the refusal moves to the seam, which already detects the mix and today only
defers it to a reader that will no longer exist. It must name the top-level keys
the author wrote and keep the message and exit code it has now. This is what makes
the next sentence true rather than declarative:

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

`scope` shows that the unit belongs to the group rather than to the contract: it
is one key, so it fills by key. `CboOptions::fromArray()` composes it that way
today — but layer-blind, which is why the cure moves that composition to the seam
rather than leaving it. Doing both would apply it twice; doing neither would leave
one key under exactly the regime this round removes.

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
| `complexity.ccn`       | `callable`, `class`  | `threshold` only                        | `callable`        | both levels     | band, `enabled`          |
| `complexity.cognitive` | `callable`, `class`  | `threshold` only                        | `callable`        | both levels     | band, `enabled`          |
| `complexity.npath`     | `callable`, `class`  | `threshold` only                        | `callable`        | both levels     | band, `enabled`          |
| `coupling.cbo`         | `class`, `namespace` | `threshold` / `warning`+`error`         | both              | both levels     | band, `enabled`, `scope` |
| `coupling.instability` | `class`, `namespace` | `threshold` / `max_warning`+`max_error` | both              | both levels     | band, `enabled`          |

`coupling.cbo` also accepts a top-level `scope` reaching `class`, and it **is**
pushed down like the other two groups. Revision 2 left it inside
`CboOptions::fromArray()`, where it composes by key today — and that is precisely
the defect this round exists to remove, one key over: `fromArray()` cannot see
layers, so a lower layer's `class: {scope: …}` beats a higher layer's top-level
`scope`, which is C3 violated in the orientation nobody would notice. Leaving one
key under the old regime because its old regime looks reasonable is how the
original defect survived five rounds. P4 therefore removes that composition when
it removes the early returns; P3 declares `scope`'s reach and pushes it.

The complexity family accepts no graduated pair at its own top level — the
registry declares `LONE_THRESHOLD_SHAPE` there, with an empty warning list, and
`unfold()`'s condition 4 skips the group for exactly that reason. Revision 3's
table said `threshold / warning+error` for all five rules, which is a spelling the
product refuses at recognition for three of them. The push-down therefore has to
enter through a gate that today exists to make the group a no-op, and P3 names
that as the line it changes.

Why pushing the band into a level is enough, and enabling it is not also needed:
of the ten level options classes only `ClassNpathComplexityOptions` defaults to
`enabled: false`; the complexity band reaches `callable`, where all three rules
default to `true`, and both coupling rules' levels default to `true`. Verified
by reading all ten constructors.

## The four breaking changes, named

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

**B4. A same-layer spelling mix now refuses even when the rule is switched off in
that layer.** Today the refusal lives inside a branch that the `enabled: false`
early return jumps over, so the contradiction is judged or not judged depending on
an unrelated key. Measured on the shipped binary: `coupling.cbo: {enabled: false,
threshold: 30, warning: 10}` exits 2, the same document without `enabled` exits 3.
Once the early returns are gone and the refusal lives at the seam, both refuse.

This is the smallest of the four and the only one that makes the product stricter.
It is taken rather than avoided because the alternative — teaching the seam to skip
the check when the rule is off — would keep a document's validity depending on a
key unrelated to its contradiction, which is the shape this round removes
everywhere else.

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
  where the block alone reports 2; the same for `min_class_count`, and today the
  same for `scope` — which stops being an example of this category once it is
  pushed down, and is listed here only as one of the measurements that found the
  hole.
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
  the message and exit code it has today — but at the seam rather than inside
  `fromArray()`, because the cure deletes the branch that carries it. See C4.
- The malformed-level refusal still refuses (`class: 5` — "takes a map of
  options, got int"), guarded by K5.
- `~` still means silence, at every depth and across layers.
- Nested `threshold` inside a level (`callable: {threshold: 5}`) keeps its
  current meaning; the gate's `layered-threshold` case writes exactly that shape
  and must not move.
- `scope` keeps its meaning — the top-level value reaches `class` only when the
  block did not name it. What changes is where that is decided: at the seam, per
  layer, so a higher layer's `scope` is no longer beaten by a lower layer's block.
- Inline `@qmx-threshold` and its `withOverride()` path are untouched: it is
  applied to an already-built level object, never through `fromArray()`.
- The inverted band (`error < warning`) is accepted today and stays accepted.
  The contract does not introduce it and does not cure it; C2 keeps the cure from
  producing one the author did not write.
