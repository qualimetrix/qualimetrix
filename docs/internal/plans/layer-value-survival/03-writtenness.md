# Stage 3 — eviction asks what was written, not what is present

## The defect

`evictUsingDeclaredGroups()` decides by key PRESENCE: `containsAnyNormalized()`
walks `array_keys()`. `ThresholdParser` decided the opposite question years apart
and settled it the other way — a key written `~` left its value to the default, so
it selects no mode and mixes with nothing. The two disagree, and the disagreement
is only visible across a layer boundary, which is why neither axis found it: the
composition probe writes magnitudes into layers and never `~`, and the adjacency
coordinate writes `~` only inside one document.

Measured effect (`measurement/observations.md` §4): a preset configuring
`warning: 2, error: 3` under an overlay writing nothing but `threshold: ~` produces
**no finding at all**. ADR 0056 records this as "the result then defaults"; the
measurement shows both halves are lost, not one, and the rule falls to compiled
10/20 where the subject scores 6.

## The cure

The presence test becomes the writtenness test — the same predicate
`ThresholdParser::firstWrittenKey()` uses, so the two cannot drift into meaning
different things again. A key written `~` then evicts nothing and unfolds nothing:
it is a key whose value the author left to the layer below, which is what it means
everywhere else in the document.

This is a separate fix from stage 2 and does not follow from it: unfolding a
shorthand that was never written is a no-op, so stage 2 alone leaves the `~`
overlay still evicting. They are two defects in one method, and each needs its own
regression test.

## Definition of Done

- Preset `{warning: 2, error: 3}` under overlay `{threshold: ~}` reports
  `error@3` — the lower layer's band survives an overlay that selected no mode.
  Regression test in those words.
- The mirror case: overlay `{warning: ~}` over a lower `{threshold: 5}` leaves
  `error@5` standing.
- The single-layer `~` behaviour is unchanged: the five existing tests named in
  `scratchpad/e2-expansion/refusal.md` keep passing untouched.
- One predicate is used by both the parser and the resolver, and a test asserts
  they answer the same way for `~`, for `false`, for `0` and for `''` — the four
  values where "present" and "written" could plausibly come apart.

## Files

Same file as stage 2 (`RuleOptionThresholdModeResolver.php`) and its test. One
package with stage 2, two commits: the reader of this history must be able to see
which change moved which observation.
