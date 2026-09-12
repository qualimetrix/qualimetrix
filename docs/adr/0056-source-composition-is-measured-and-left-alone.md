# 56. Source Composition Is Measured, and Left Alone

**Date:** 2026-09-12
**Status:** Accepted

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

A fifth axis was added to the promise-effect stand: two writers, one path,
whose value reaches the object. Its rows come from the promise ledger in three
shapes — a path disputed by two writers, two keys of one threshold group
reaching a single bucket, and an ordered triple with the fate of the slot only
the lowest layer wrote.

|                                     |                                                  |
| ----------------------------------- | ------------------------------------------------ |
| path-addressed writer pairs         | 7 of 7, **all** composing as promised            |
| the promised layer order            | holds: preset < `qmx.yaml` < CLI                 |
| `#[CliAlias]` against `--rule-opt`  | decided before any merge; the explicit form wins |
| triples with mode eviction          | 4 forms; the slot is lost **lawfully**           |
| framework keys at the second reader | correct, observed at their own point             |
| **defects on the axis**             | **0**                                            |

The triples deserve their own sentence, because the round first read them
wrong. `error: 3` written by a preset does vanish for good once a middle layer
writes `threshold` and a higher one rewrites `warning`, and that looks exactly
like a value accepted and then dropped in silence. It is not.
`ThresholdParser::parse()` returns `['warning' => v, 'error' => v]`: the
shorthand is not a third key standing beside the band, it **writes both halves
of it**. The middle layer overwrote the pair; the top layer overwrote one half;
the other half staying at the middle layer's value is correct. Keeping the
lowest layer's value alive would resurrect what the middle one deliberately
replaced. The first reading was recorded as a decision, refuted from the code
by external review, and reversed — which is why it is written out here rather
than quietly replaced.

## Decision

**The refactoring is not done, and that is a decision rather than an omission.**

**The shorthand is not unfolded before the merge.** Its purpose was to make
mode eviction unnecessary. Eviction produces no defect: it exists so that a
merged document never carries both `threshold` and a band, which the reader is
obliged to refuse. The cost is measured and large — the unfolding lives in
`ThresholdParser::parse()`, **31 call sites in 30 files**, each inside its own
`fromArray()`.

**`RuleThresholdKeyGroupRegistry` and its suffix heuristic stay, with the price
named.** 33 entries over 22 rules; **26 of 48** rule classes have no entry at
all, and for them "is this key a mode key" is answered by a spelling match. That
is a real hazard. But the round measured *effect*, and the effect is correct on
every pair and triple it exercised. Deleting a working mechanism for the shape
of it is the "prescribed cure as hypothesis" that cost the previous round four
packages out of six.

**The second reader stays.** Its four consumers read framework keys that the
factory drains *before* an options object exists — which is why the axis
observes them at their own point. Merging them into one document modelled on
the factory would leave three consumers reading nothing.

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

**Condition for reopening, so the decision does not become permanent:** a wider
sample — triples by the stage-03 selection rule, path pairs on a list and a
map, `#[CliAlias]` in the `L3` position, and `~` across layers — and a defect on
it. Then the three questions open again, with a reason.

## Consequences

- No file of the composition path changed: `RuleOptionsFactory`,
  `RuleThresholdKeyGroupRegistry` and `RuleOptionsRegistry` are untouched for
  the whole round, and `RuleOptionThresholdModeResolver` carries one added
  docblock naming the cross-layer gap above.
- The promise ledger gained a vocabulary for composition it did not have, and
  two decisions of the round are recorded in it as `DECIDED` rather than dressed
  up as promises: a slot rewritten by the shorthand is lost lawfully, and
  `--rule-opt` beats a short alias.
- The next round inherits an enumerated list of what was not measured, instead
  of a claim that composition is sound.
