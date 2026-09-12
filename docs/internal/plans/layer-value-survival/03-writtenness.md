# Stage 3 — a `~` written above does not erase what was written below

## What this stage is about, after stage 2 moved underneath it

The first draft of this stage aimed at eviction's presence test. Stage 2 deletes
eviction, so that predicate no longer exists. The defect it was aiming at does,
and it turns out to be larger than the eviction path: the merge itself destroys
what a lower layer wrote whenever the layer above writes `~`.

## The defect, in two shapes that share one cause

Both merge sites assign unconditionally — `$result[$key] = $value;` — so a `null`
from the overlay lands in the merged array. `ThresholdParser::firstWrittenKey()`
then asks `isset()`, for which a written `null` and an absent key are the same
thing, and the slot falls to the constructor's compiled default.

| what the layers write                                  | today                 | measured in |
| ------------------------------------------------------ | --------------------- | ----------- |
| lower `{warning: 2, error: 3}`, upper `{threshold: ~}` | **no finding at all** | §4          |
| lower `{warning: 2, error: 100}`, upper `{warning: ~}` | **no finding at all** | §5          |

The first loses the band through eviction (stage 2 removes that path). The second
loses it through the merge's own write, and stage 2 does not touch it — after
unfolding, lower `{threshold: 5}` under upper `{warning: ~}` becomes
`{warning: null, error: 5}`, which reads as `warning = 10` (compiled) and
`error = 5`: an inverted band in which Warning is unreachable, where today the
same configuration yields a coherent `(10, 20)`. Curing stage 2 without this stage
would leave the round's own sentence — a value a layer wrote survives the layers
above it — false in the mirror case, and its first DoD would still pass, because
it only checked the surviving half.

## The semantic question this settles, by the tree's own precedent

Across a layer boundary, `~` can mean two things, and the product does the second
today by accident rather than by decision:

- **(a)** "I wrote nothing here" — the layer below survives;
- **(b)** "I write the default here" — the layer below is overwritten by the
  compiled default.

**(a) is chosen, and not on taste.** One level down, the tree has already decided
this exact question: `ThresholdParser`'s docblock records that a `~` used to
shadow a populated ALIAS behind it and that "both are gone". A `~` does not shadow
a written value behind it. A layer below is the same question in the layer
dimension, and answering it differently would give one symbol two meanings — the
defect class this whole round exists to remove.

## The cure

At both merge sites, an overlay value of `null` does not overwrite a base key that
carries a value. Where nothing stands behind it, the `null` is written exactly as
today, so single-document semantics are untouched: "present but null" still
differs from "silent" wherever that distinction is read. Only cross-layer
shadowing changes — which is the whole of the delta.

This is deliberately not scoped to threshold keys. A per-key carve-out would be
one question with two answers again, and the alias precedent it follows is not
about thresholds either — it is about what `~` means when something is written
behind it.

## What "one predicate" can and cannot mean

The first draft asked for `ThresholdParser::firstWrittenKey()` to be THE shared
predicate. It cannot be: it is `private static`, it looks up by exact key, and the
resolver's side matches normalized spellings — the normalization is what lets a
registry's `max_warning` meet an array's `maxWarning`. Two different things were
being conflated.

What is shared is the value-level question — *is this value written?* — which is
one expression over one value and belongs in one place both sides call. Candidate
lookup by key spelling is not shared and must not be: the parser asks with the
call site's literals, the merge asks with the author's spelling.

## Definition of Done

- Lower `{warning: 2, error: 3}` under upper `{threshold: ~}` reports `error@3`.
- Lower `{warning: 2, error: 100}` under upper `{warning: ~}` reports `warning@2`
  — the mirror case, in the words of §5.
- Lower `{threshold: 5}` under upper `{warning: ~}` reports **both halves at 5**,
  and the band is asserted as a pair, not by one finding: `warning` and `error`
  are both read off the result. No DoD of this round checks one half of a band.
- Single-layer `~` behaviour unchanged: the five existing tests named in
  `measurement/` keep passing untouched.
- The shared written-ness predicate has one definition, and a test asserts parser
  and merge agree for `~`, `false`, `0` and `''` — and for the case where they
  would genuinely diverge, the same key written in two spellings.

## Files

The two merge sites (`RuleOptionsFactory.php`, `FindingConfigurationResolver.php`)
and whichever file carries the shared predicate, plus their tests. One package
with stage 2, separate commits: the reader must be able to see which change moved
which observation.
