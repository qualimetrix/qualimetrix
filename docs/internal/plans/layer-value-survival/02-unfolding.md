# Stage 2 — the shorthand unfolds before the layers merge

## The mechanism today

`RuleOptionThresholdModeResolver::evictOverriddenMode()` is called by both merge
sites before a deep merge. It removes, from the LOWER layer, the keys of the mode
the HIGHER layer switched away from. Applied to a shorthand that means both
halves of a band, it removes the whole band; the higher layer then rewrites one
half, and the half it did not rewrite reaches no layer's value at all — it
reaches the constructor's compiled default.

Measured on two layers and on three; see `measurement/observations.md` §3.

## The cure

Unfold, do not evict. Before the two layers are merged, each layer that carries a
`threshold` shorthand for a declared group has that shorthand rewritten into the
group's graduated pair — the same pair `ThresholdParser::parse()` would have
produced from it. After that, an ordinary deep merge does the right thing by
itself: a higher layer's `warning` overwrites a `warning`, and the `error` the
higher layer did not write stays at the value the lower layer meant by its
shorthand. There is then nothing to evict and nothing to fall through.

```
unfoldShorthand(array $layer, list<Group> $groups): array
    // for each group: if the threshold key is WRITTEN and no graduated key
    // of that group is WRITTEN in this same layer, replace it with the pair
    // ... implementation details
```

### Four conditions, each with a measured reason

1. **Written, not present.** `~` selects no mode (`ThresholdParser` has said so
   since X19), so a layer whose only `threshold` is `~` is not unfolded. This is
   also what stage 3 needs; the two stages share the predicate.
2. **Not when the same layer already carries a graduated key of the group.** That
   is the within-one-layer mix, and it must keep reaching `ThresholdParser` and
   being refused. Unfolding it would silently accept what is refused today.
   Pinned by `RuleOptionsFactoryTest::itStillThrowsWhenThresholdAndWarningComeFromTheSameLayer`
   and its prefixed-key sibling, which must keep passing untouched.
3. **Only for a group with a graduated pair to unfold into.** The three
   `LONE_THRESHOLD` entries (`complexity.ccn`, `.cognitive`, `.npath` at the
   rule's own top level) have empty `warning`/`error` lists on purpose: that
   shorthand is CROSS-PATH — it configures the `callable` level and switches the
   `class` level off — so it has no pair at its own level and must be left alone.
   29 of 32 rule×path pairs unfold; these 3 do not.
4. **The key written is the folded spelling.** Every door folds separators away
   before either merge site runs, so the array the resolver sees is camelCase.
   Writing the registry's `max_warning` beside a later layer's `maxWarning` would
   leave BOTH keys alive — no literal collision in the merge — and
   `ThresholdParser` takes the first candidate, shadowing the user's explicit
   override with a defect worse than the one being cured. The unfolding writes
   `ConfigKeySpelling::normalize($canonicalKey)`. Significant for 8 of the 29.

## §3 — the heuristic dies, the registry is made provable

ADR 0055's second open question, handed to this round by ADR 0056.

`evictUsingHeuristic()` guesses a key's group from a `threshold`/`warning`/`error`
suffix when the registry has no entry. Its two known failure modes are recorded
in its own docblock. Removing a key on a wrong guess loses configuration; the
same guess asked to WRITE a pair fabricates it. **It is deleted**, together with
its three helpers, and a rule with no declared group is simply not unfolded and
not evicted — the conservative direction, and the one that cannot invent a value.

That is only safe if "has no declared group" never happens for a rule that needs
one, so completeness stops being a claim and becomes a test: every
`ThresholdParser::parse()` call site in `src/` is derived mechanically — by the
PHP tokenizer, which the round used to settle 31-in-30 — and each must be matched
by a registry entry whose key names equal that call site's literal arguments. A
call site with no entry, and an entry naming a key no call site passes, both fail.
Two witnesses agreed on today's list and found 0 discrepancies across all 32
pairs, so the guard starts green and is a regression net, not a migration.

**The alternative considered and rejected for this round:** moving the group
declaration onto each Options class's own `acceptedOptionKeys()` / `RuleOptionKeySet`
and deleting the registry outright. It is reachable — `RuleNameReader::read()`
plus the static `getOptionsClass()` give a rule-name → options-class map with no
rule instance and no cycle, `RuleOptionsFactory::create()` already holds the class,
and `FindingConfigurationResolver` is a DI service that could take the lookup. It
is the better long-term shape and it is written down here so the next round does
not re-derive it. It is not taken now because it moves ~22 options classes and a
static resolver's shape in the same change as the behavioural cure, and the
behavioural cure is what four floor rows are waiting on.

## Blast radius, stated as behaviour

Unfolding changes the reverse direction too, and that is intended: a preset
`threshold: 25` under a `qmx.yaml` `warning: 10` today yields `(10, default)` and
will yield `(10, 25)`. Every combination where one layer wrote a shorthand and a
higher layer rewrote part of a band now keeps the shorthand's other half. Nothing
changes for a single layer, for two layers that agree on mode, or for the three
`LONE_THRESHOLD` paths. The full per-rule table is
`scratchpad/e2-expansion/blast-radius.md`, carried into the round's ADR.

This is user-visible: a `Changed` entry, the website page on layered
configuration, and a `composer gate -- --reference=1210b037` run whose finding
differences are expected and must be read, not assumed.

## Definition of Done

- The two-layer and three-layer fixtures of `measurement/observations.md` §3
  produce `warning@2` AND `error@5`; both are regression tests, in the words of
  the observation, not paraphrased.
- `evictUsingHeuristic()` and its helpers no longer exist anywhere in `src/`.
- The completeness guard exists, derives call sites by tokenizer, and fails when
  an entry is deleted and when a call site's key literal is changed — both proved
  by planting each breakage once.
- Within-one-layer mixing still refuses, with the existing tests untouched.
- `composer check` green; `composer gate -- --reference=1210b037` run and its
  output read into the round's report.
- The 4 axis-C floor rows are no longer defects on the live grid, and are marked
  `pending:` in `floor.tsv` in the same commit, so no intermediate commit is red.

## Files

`src/Analysis/Finding/RuleConfiguration/RuleOptionThresholdModeResolver.php`,
`src/Analysis/Finding/RuleConfiguration/RuleThresholdKeyGroupRegistry.php`,
their tests, the new completeness-guard test, `promise-effect/floor.tsv` (the 4
rows), and the regenerated grid artifact. Shares no file with stage 4.
