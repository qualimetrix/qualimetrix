# Stage 1 — the contract

What a top-level band means beside a nested level block, in every orientation,
with the cost of each answer. Nothing here is derived by analogy from ADR 0058:
that ADR settled whether one layer's value survives a higher layer, key by key.
Depth against layer is a second question and is decided here, explicitly.

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
- **Layer** — preset, config file, CLI. Layers are merged at two seams; a band
  written in one layer is a statement of that layer alone.

## The contract

**C1. A top-level key covers the levels its rule declares it covers, and no
others.** Reach is declared per rule and per key, checked against the levels the
options class actually builds, and is not inferred from key names.

**C2. Within one layer, the deeper key wins for what it names; the top-level key
fills only what that layer left unwritten.** The fill unit is the band, not the
individual key: a level whose band carries any written key in this layer has
chosen its own band, and the top-level band does not reach into it. The half it
did not name takes that level's own default, never the top-level value.

**C3. Across layers nothing composes.** Every layer is made unambiguous before
it is merged — its top-level keys are pushed down into the levels they reach and
removed — so the merge is a plain key-for-key overlay in which the higher layer
wins for exactly the keys it names. Specificity never overrides layer priority,
in either orientation.

**C4. Refusal is unchanged: two spellings of one band, in one array, in one
layer.** No new refusal is introduced, and no existing one is removed. A
top-level band beside a level block is not that shape — the two address
different depths — so it composes under C2.

`fromArray()` receives a document in which no top-level band survives. Its early
returns therefore have nothing left to return on and are removed, not repaired.

## The five questions

### Q1 — specificity against layer priority, both orientations

**Answer: layer priority always wins; specificity acts only inside a layer.**
This follows from C3 and is a decision, not a consequence of ADR 0058.

| lower layer                              | higher layer                 | result under this contract                                                                             | today                                              |
| ---------------------------------------- | ---------------------------- | ------------------------------------------------------------------------------------------------------ | -------------------------------------------------- |
| preset: `{threshold: 5}`                 | config: `{class: {…}}`       | preset unfolds to the levels it reaches; config's `class` keys overlay them; preset survives elsewhere | config's block discarded, preset's band wins       |
| preset: `{class: {enabled: true, 2, 3}}` | CLI: `threshold=50`          | CLI unfolds to the levels it reaches and overlays the preset's block there; preset survives elsewhere  | CLI's band wins whole (same outcome, other reason) |
| config: `{threshold: 50}`                | CLI: `{class: {warning: 1}}` | CLI's `class.warning` overlays; config's pushed-down `class.error` survives beneath it                 | CLI half-band; config's other half lost            |

The second row is the orientation the withdrawn plan never named, and the one
its rule inverted: putting the cure in `fromArray()` would have left the
preset's `2/3` standing against a CLI that explicitly asked for `50`. Measured
on this tree, that document gives the CLI the whole rule today
(`measurement/seams.md`, Q6).

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

### Q3 — granularity, once

**Answer: the band.** A level whose band carries any written key in this layer
takes nothing from the top-level band; the half it did not name takes that
level's own default.

Two measurements decide it against key granularity. The levels of one rule carry
**different key names and different scales** — `MethodComplexityOptions` uses
`warning`/`error` at 10/20 while `ClassComplexityOptions` uses `max_warning`/
`max_error` at 30/50 — so filling one half of a level's band from a top-level
value of the other scale can produce `error < warning`, and the product does not
reject an inverted band anywhere: it collapses the band into a solid `error`
(measured, `seams.md` Q3 — 27 findings, none of them `warning`). Key
granularity would also place a `threshold` key beside a written `warning` inside
one level array, which is the one shape the product does refuse.

### Q4 — `class: {}`, `class: ~`, `class: {enabled: ~}`

**Answer: all three name nothing, and need no classification rule of their
own.** Under C2 the fill unit is the band and the question "is this level
named?" never arises: a level whose band is unwritten takes the top-level band
if the rule's reach covers it, whether the key is absent, `~`, empty, or present
with only a `~` inside. `~` keeps the single meaning ADR 0058 gave it — silence,
not a reset — and an empty map does not become an enabler of anything.

This is the reason the contract does not say "a block re-enables the level".
That rule would have had to answer why `complexity.npath: {threshold: 50,
class: {}}` behaves differently from `class:` absent, when
`ClassNpathComplexityOptions` defaults to `enabled: false` and its two siblings
default to `enabled: true`. Under C2 there is nothing to answer: enablement
comes from keys, never from presence.

Measured today: `class: ~` and `class: {enabled: ~}` change nothing and refuse
nothing, and `null` reaches `fromArray()` unchanged (`seams.md`, Q5 d1/d2).

### Q5 — `enabled: false` beside a block

**Answer: the same contract, with `enabled` as its own group.** Top-level
`enabled` covers every level of its rule and fills each level that did not write
its own `enabled`. It is not a gate and gets no exception.

- `{enabled: false}` alone still switches the rule off: it fills every level.
- `{enabled: false, class: {enabled: true, warning: 1, error: 1}}` leaves the
  class level on. Today it reports zero findings where the block alone reports
  three (`review-native.md`, claude-04).
- The ledger already promises this: all five `enabled × class.enabled` rows read
  `compose`, "both apply at their own depth" (ADR 0052 row 3).

The withdrawn plan called this M1 a ledger correction rather than a product
change, on the ground that the outcome is identical either way. It is not: the
outcome differs on the one-line document above, and the block is not inert at
the recognition seam either — an unknown key inside it exits 3 while the rule is
off. "Read enough to refuse by, not enough to obey" is not legitimate inertness.

## Reach, per rule

Reach is what the cure must declare. The table below is the claim; the machine
enumeration behind it, with today's outcome measured per cell, is
`measurement/population.tsv` — read it rather than this summary when checking
completeness.

| rule                   | levels               | top-level band                          | reach of that band | other top-level keys and their reach                                |
| ---------------------- | -------------------- | --------------------------------------- | ------------------ | ------------------------------------------------------------------- |
| `complexity.ccn`       | `callable`, `class`  | `threshold` / `warning`+`error`         | `callable`         | `enabled` → both levels                                             |
| `complexity.cognitive` | `callable`, `class`  | `threshold` / `warning`+`error`         | `callable`         | `enabled` → both levels                                             |
| `complexity.npath`     | `callable`, `class`  | `threshold` / `warning`+`error`         | `callable`         | `enabled` → both levels                                             |
| `coupling.cbo`         | `class`, `namespace` | `threshold` / `warning`+`error`         | both               | `enabled` → both; `scope` → `class` (already composes by key today) |
| `coupling.instability` | `class`, `namespace` | `threshold` / `max_warning`+`max_error` | both               | `enabled` → both levels                                             |

## The two breaking changes, named

**B1. A bare `complexity.*` shorthand no longer switches the class level off.**
Today `complexity.ccn: {threshold: 3}` forces `class` to `enabled: false`; under
C1 the band reaches `callable` only and says nothing about `class`, which keeps
its own default. For `ccn` and `cognitive` that default is on (30/50), so
documents that write only the shorthand gain class-level findings. For `npath`
the class default is off, so nothing changes. Measured: the control reports one
class finding, the bare shorthand none (`seams.md`, Q5 b).

This is the price of C1 being a rule rather than a list of special cases. The
alternative — unfolding the shorthand into `class: {enabled: false}` as well —
was rejected because it leaves the subject of this round uncured: a `class:`
block written beside the shorthand names the band, not `enabled`, so the level
would stay off and the written block would still change nothing.

**B2. A top-level band beside a level block now composes instead of replacing
it.** This is the round's subject and the reason it exists. Every affected
document moves from "one of the two written keys has no effect" to "both apply
at their own depth".

## Cells the enumeration found that no earlier round named

The population was enumerated declaratively from the options classes
(reflection over `HierarchicalRuleOptionsInterface`, `levelOptionsClasses()`,
`acceptedOptionKeys()`, constructor defaults) and every cell was then run
through the product binary: 560 declared cells plus 60 refusal probes, all 620
measured. Five outcomes in it were not named by any prior measurement of this
subject. Two are cured by the contract; three are not, and are recorded here so
that nobody reads this round as having cured them.

**Cured by the contract:**

- **A level's explicit `enabled: false` is silently flipped ON by a top-level
  shorthand.** `complexity.ccn: {threshold: 1, callable: {enabled: false}}`
  reports 13 findings. Same shape on both levels of `coupling.cbo` and
  `coupling.instability`, and for `cbo` through the graduated pair too. Under
  C2 `enabled` is its own group: the level wrote it, so the top-level key does
  not fill it, and the level stays off. This is the mirror image of the round's
  subject and strengthens it — the discard runs in both directions.
- **A level's `enabled: true` does not switch the level back on beside a
  shorthand.** `{threshold: 1, class: {enabled: true, max_warning: 1,
  max_error: 2}}` reports 0 where the same block without the shorthand reports
  11. Cured by C1: the complexity band does not reach `class` at all.

**Not cured, and not this round's subject:**

- **`complexity.npath` class level: a stricter threshold yields fewer
  findings.** `{class: {enabled: true}}` reports 1, `{class: {max_warning: 1,
  max_error: 2}}` reports 0, both together report 11. The cause is the single
  `?? false` in `ClassNpathComplexityOptions::fromArray()` against a class
  default of `enabled: false`, where its two siblings default to `true`. The
  same document reports 11 / 1 / 0 on `ccn` / `cognitive` / `npath`. This is a
  written block that changes nothing, so it belongs to the same family as this
  round's subject — but its mechanism is a level's own default enablement, not
  what a top-level key covers, and it is reachable with no top-level key
  present. Folding it in would make the contract answer a second question.
- **A refusal names keys in a spelling a document cannot carry.** The unknown-key
  refusal inside a level reports kebab-case names that cannot be written as
  written. Adjacent to K1 but not produced by it.
- **The exit code reflects severity, not whether the configuration took
  effect.** 13 findings exit 0 with half a band and 2 with the full band. Worth
  naming because acceptance cases that read exit codes instead of findings would
  be judging the wrong thing.

## What this answer does NOT change

- The same-depth mode mix still refuses (`{threshold: 30, warning: 10}`), with
  the message and exit code it has today.
- `~` still means silence, at every depth and across layers.
- Nested `threshold` inside a level (`callable: {threshold: 5}`) keeps its
  current meaning; the gate's `layered-threshold` case writes exactly that shape
  and must not move.
- Inline `@qmx-threshold` and its `withOverride()` path are untouched.
- The inverted band (`error < warning`) is accepted today and stays accepted.
  The contract does not introduce it and does not cure it; C2 keeps the cure
  from producing one that the author did not write.
