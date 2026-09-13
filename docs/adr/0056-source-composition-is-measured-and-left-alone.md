# 0056. Source Composition Loses the Middle Layer's Value

**Date:** 2026-09-12
**Status:** Superseded by [ADR 0058](0058-a-layers-value-survives-the-layers-above-it.md)

## Context

[ADR 0055](0055-a-rule-option-declares-the-shape-of-its-value.md) left three
questions undecided, together and on purpose:

- unfolding the `threshold` shorthand **before** the layers merge, rather than
  after;
- the fate of `RuleThresholdKeyGroupRegistry` and its silent suffix heuristic —
  built out, or deleted;
- the second reader, `RuleOptionsRegistry::all()`.

Each is a decision about *which source wrote a value and which one wins*. It
handed them to the round that owns source composition, with a denominator
already measured and frozen, so that round would begin with enumeration rather
than reconnaissance.

That round measured. This ADR records what it found and what it decided.

## What was measured

A fifth axis was added to the stand: two writers, one path, whose value reaches
the object. Its rows come from the promise ledger in three shapes — a path
disputed by two writers, two keys of one threshold group reaching a single
bucket, and an ordered triple with the fate of the slot only one layer wrote.

|                                     |                                                  |
| ----------------------------------- | ------------------------------------------------ |
| path-addressed writer pairs         | 7 of 7, **all** composing as promised            |
| the promised layer order            | holds: preset < `qmx.yaml` < CLI                 |
| `#[CliAlias]` against `--rule-opt`  | decided before any merge; the explicit form wins |
| framework keys at the second reader | correct, observed at their own point             |
| **triples with mode eviction**      | **4 defects**                                    |

## The defect, and the two wrong readings before it

The round read one measurement wrong twice, and both are kept here because the
second was adopted, published, and had to be withdrawn.

**First reading — "the lowest layer's slot must survive".** A preset writes
`warning: 2, error: 3`, `qmx.yaml` writes `threshold`, the command line writes
`warning`; the `error` never fires again, which looks like a written value
dropped in silence.

**The refutation, correct as far as it went.** `ThresholdParser::parse()`
returns `['warning' => v, 'error' => v]`: the shorthand is not a third key
beside the band, it **writes both halves of it**. The middle layer overwrote the
lowest layer's pair, and keeping that pair alive would resurrect what the middle
layer deliberately replaced. The floor rows were withdrawn.

**Second reading — "the loss is lawful" — was also wrong, and one fixture
caused both errors:** it could not tell *the middle layer's value* from *the
constructor default*. A fixture that can — L1 `warning: 2, error: 3`, L2
`threshold: 5`, L3 `warning: 2`, over a callable of cyclomatic complexity 6 —
reports **warning at 2 and no error at all**, though 6 exceeds 5. The surviving
half is neither 3 nor 5: it is the constructor's 20. Reviewers reproduced it
independently with their own fixture and reached the same place.

The mechanism sits in `RuleOptionThresholdModeResolver`, which evicts by
**presence** and runs before the parser sees the document. It removes the lower
layer's pair **and** the middle layer's shorthand, so the half the top layer did
not rewrite falls through to a compiled default no layer wrote.

A value the user wrote, the product accepted, and then replaced with something
nobody asked for — the subject of this programme, found on the axis built for it.

## Decision

**ADR 0055's first question now has its reason, and the answer is yes: the
shorthand must be unfolded before the layers merge.** Eviction by presence is
what loses the middle layer's value; unfolding `threshold` into the band it
means, before merging, leaves nothing to evict and nothing to fall through.

**The cure is not in this round, and the reason is not cost.** A round that
measures a mechanism should not also change it: the grid that would judge the
cure is the grid the cure moves. So the direction is fixed here, four floor rows
must redden until it is fixed. What is known about the price: the unfolding
lives in `ThresholdParser::parse()`, **31 call sites in 30 files**, so the cheap
shape is to unfold inside the resolver rather than move the parser.

**`RuleThresholdKeyGroupRegistry` and its suffix heuristic stay for now.** 33
entries over 22 rules; **26 of 48** rule classes have no entry, and for them "is
this key a mode key" is answered by a spelling match. That hazard is now coupled
to a real defect — the registry is what `evictOverriddenMode` consults — so
whether it survives the unfolding belongs to the round that unfolds, not to a
blind decision here.

**The second reader stays.** Its four consumers read framework keys that the
factory drains *before* an options object exists, which is why the axis observes
them at their own point. Merging them into one document modelled on the factory
would leave three consumers reading nothing.

## What this ADR does not claim

- **The sample is narrow.** Triples are exercised once per form (4) where the
  round's own selection rule gave 120 and the denominator 240; the path pairs
  are measured on **one scalar path**; `#[CliAlias]` in the `L3` position is
  never exercised. Both reviewers said so independently.
- The five object-slot pairs (`@qmx-threshold` as the higher party) are not
  measured at all: a different mechanism needing a different oracle.
- Shipped preset **contents** were never read; the denominator speaks of a
  stage's capability, not of any preset writing a path.
- **Cross-layer eviction still asks whether a key is present, not what it
  holds.** An overlay writing `threshold: ~` evicts the lower layer's band
  without selecting a mode, and the result falls back to defaults. This was
  found by reading code during the cure, not by the axis: the composition probe
  writes magnitudes into layers, never `~`, and the adjacency coordinate writes
  `~` only inside one document. The cell is real and belongs to neither.

**What a wider sample would add:** triples by the stage-03 selection rule (120
rather than 4), path pairs on a list and a map, `#[CliAlias]` in the `L3`
position, and `~` across layers. The defect above was found on the narrow
sample; a wider one can only find more, and the next round starts there.

## Consequences

- No file of the composition path changed: `RuleOptionsFactory`,
  `RuleThresholdKeyGroupRegistry` and `RuleOptionsRegistry` are untouched for
  the whole round, and `RuleOptionThresholdModeResolver` carries one added
  docblock naming the cross-layer gap above. The defect ships **unfixed and
  declared**, with four floor rows that redden until it is not.
- The promise ledger gained a vocabulary for composition it did not have, and
  the round's decisions are recorded in it as `DECIDED` rather than dressed up
  as promises: the middle layer's value must survive in the half the top layer
  did not rewrite, and `--rule-opt` beats a short alias.
- **A measurement can be read wrong twice in the same round, and the way out was
  a fixture that distinguishes the candidates rather than another argument.**
  Both wrong readings were internally consistent and both survived a review;
  what broke the tie was choosing magnitudes such that "the middle layer's
  value" and "the default" produce different findings.
- The next round inherits an enumerated list of what was not measured, and a
  named defect with a direction, instead of a claim that composition is sound.
