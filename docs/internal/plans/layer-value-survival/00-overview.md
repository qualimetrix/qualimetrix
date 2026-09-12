# A value a layer wrote survives the layers above it — overview

**Round:** X20. **Base:** `main` at `1210b037` (PR #64, X19), verified merged.
**Branch:** `x20-promise-effect-remainder`.

## What this round is about

X19 built the axis that measures source composition and found exactly one
mechanism with it, then deliberately left it uncured: a round that measures a
mechanism must not also move the grid that would judge the cure
([ADR 0056](../../../adr/0056-source-composition-is-measured-and-left-alone.md)).
This round cures it, and closes the two neighbouring defects that reading the
code turned up beside it.

The subject is one sentence: **a value a layer wrote must still be there after a
higher layer rewrote something else.** Three things break it today, all in the
same method, all measured (see `measurement/observations.md`):

| #   | what breaks                                                                  | measured effect                                                   |
| --- | ---------------------------------------------------------------------------- | ----------------------------------------------------------------- |
| 1   | eviction drops a shorthand the higher layer only half-rewrote                | `error` falls to a compiled default nobody wrote                  |
| 2   | eviction asks whether a key is PRESENT, not whether it was WRITTEN           | an overlay's `threshold: ~` silences the lower layer's whole band |
| 3   | a rule with no registry entry has its grouping GUESSED by a suffix heuristic | after the cure the guess would WRITE keys, not just remove them   |

A fourth, unrelated in mechanism but named by the same programme: three option
keys own a closed set of words and are declared `text()`, so the declaration
promises every string while the reader accepts three.

## The decisions this round takes, and why

**The shorthand is unfolded before the layers merge, inside the resolver.**
ADR 0055 left this question open and ADR 0056 fixed the direction; the price was
measured at 31 `ThresholdParser::parse()` call sites in 30 files, so moving the
parser is not the cheap shape. The resolver already receives `$ruleName` and
`$path` and already consults the group declaration, so unfolding happens where
eviction happens today. See `02-unfolding.md`.

**`RuleThresholdKeyGroupRegistry` survives the unfolding; its suffix heuristic
does not.** This is ADR 0055's second open question, which ADR 0056 explicitly
handed to the round that unfolds. A heuristic that REMOVES a key on a wrong
guess loses configuration; the same heuristic asked to WRITE keys fabricates it.
The registry is kept and made provably complete by a guard rather than trusted.
See `02-unfolding.md` §3.

**The declaration of a closed word set learns to say that it folds case.** The
three readers fold case deliberately, with tests, and already refuse an unknown
word naming the accepted set — so a case-sensitive `oneOf()` would be NARROWER
than its reader and refuse a legal value. `RuleOptionWordSet`'s own class
docblock already prescribes the cure ("where a reader does fold case, the set it
declares has to say so"), while its code says the opposite. See `04-words.md`.

**The floor learns to judge a cure that lands after the snapshot.** Mandatory,
and on the critical path of every other stage: `Floor::cureMisses()` applies the
`cure` column to BOTH halves, so the first cure a round lands after its own
frozen half turns that half red. Git ancestry cannot decide it — the commits
already in the column are not ancestors of `main` (squash-merge), so the check
would redden twenty-one standing rows and fail on a fresh clone. See
`01-floor.md`.

## What this round deliberately does not do, and at what price

Each is banked with its enumeration already taken, so the round that picks it up
begins with the table rather than with reconnaissance — the pattern ADR 0055
used to hand this round its own subject.

| deferred                          | price, as a number                                                                                                                                                                                                                                               | why not here                                                                                                                                                                                               |
| --------------------------------- | ---------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- | ---------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| axis F (container × CONTENT form) | population must be rebuilt: of the 8 canonical forms in a map KEY, 5 are refused by the YAML parser before the product sees them and 2 more collapse into one — 3 outcomes, not 8. Ten stand guards must be satisfied, two of which stay SILENT rather than red. | it is a measurement design task of its own, and its probe would move the grid this round's cure is judged on                                                                                               |
| axis B (82 cells)                 | 4 mechanisms, 81 of 82 cells one copy-pasted pattern in 5 sibling classes; 0 blocked on anything                                                                                                                                                                 | it is a SEMANTICS decision about what a top-level shorthand means beside a nested block, the same family as floor row 96 — one subject, taken whole, not bolted onto the round that changes the merge path |
| the ten STAND rows + a retake     | 4 rows (not 10) are the envelope defect, over 2 bases (`patterns`, `suffix`), not 6                                                                                                                                                                              | every input edit costs the comparability the cure's proof rests on, and the cure needs no retake once the floor can judge it                                                                               |
| bool blindness on axis A          | 155 rows / 226 cells today (the brief's 1202 is a retired snapshot on a different grid)                                                                                                                                                                          | an input change, so a retake, so the same reason                                                                                                                                                           |

**A premise this round refutes and does not carry forward:** axis B was said to
be blocked on the rule layer having no single notion of "channel boundary", and
`channel-identity-substrate.md` was cited. That document is about finding
identity — `(ruleName, violationCode)` across suppression, threshold, selector
and exclusion consumers — and never mentions axis B, pairs, or coexistence. X17's
actual recorded reason is that no owner held a mandate over pair semantics: a
governance gap, not a technical one. This round holds that mandate and still
defers, for the reason in the table.

## Stages, in order

Order is forced, not stylistic: the floor must be able to judge a post-snapshot
cure before any cure lands, or the intermediate commit is red.

1. `01-floor.md` — the floor judges a cure landed after its own snapshot.
2. `02-unfolding.md` — the shorthand unfolds before the merge; the heuristic dies.
3. `03-writtenness.md` — eviction asks what was written, not what is present.
4. `04-words.md` — three keys declare their closed set, and the set says it folds case.
5. `05-acceptance.md` — ledger, floor rows, blocking axes, grid, docs, changelog.

Stages 2 and 3 are one package (one method, two commits). Stage 4 is independent
of 2-3 by file set but shares the generated grid, so it runs after them rather
than beside them.

## Measurement this plan stands on

Tables are on disk and are not reproduced here. `measurement/observations.md`
holds what the orchestrator measured personally, with the command for each.
The enumerations behind the deferrals are in the scratchpad tables named there.
