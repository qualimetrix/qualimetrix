# Stage 2 — the shorthand unfolds in every layer, and eviction is deleted

## The mechanism today

`RuleOptionThresholdModeResolver::evictOverriddenMode()` is called by both merge
sites before a deep merge. It removes, from the LOWER layer only, the keys of the
mode the HIGHER layer switched away from. Applied to a shorthand that means both
halves of a band, it removes the whole band; the higher layer then rewrites one
half, and the half it did not rewrite reaches no layer's value at all — it reaches
the constructor's compiled default. Measured on two layers and on three;
`measurement/observations.md` §3.

## The cure, with every degree of freedom fixed

The first draft of this stage left three things unsaid, and two of the readings it
allowed silently fail. All three are decided here.

**Which layer unfolds: BOTH, before they meet.** Unfolding only the base leaves
the reverse case broken — base `{warning: 2, error: 3}` under overlay
`{threshold: 5}` would merge into an array carrying all three keys and the parser
would refuse it as a mix, where today it works. So unfolding is a transformation
of ONE layer, applied by each merge site to base and to overlay before merging:

```
RuleOptionThresholdShorthand::unfold(array $layer, string $ruleName, string $path): array
    // for each declared group of (ruleName, path): if the threshold key is
    // WRITTEN, carries a value of the form both graduated keys take, and no
    // graduated key of that group is written in this same layer — replace it
    // with the pair
    // ... implementation details
```

The resolver stops being a resolver of conflicts between two layers and becomes a
rewriter of one layer, which is why the class is renamed. Its old signature took
`$base` and `$overlay` and returned only `$base`; that asymmetry is what made
"unfold each layer" unexpressible.

**Whether eviction survives: it is deleted.** Once both layers are unfolded,
neither carries a shorthand when they meet, so eviction has nothing to remove —
proved by enumeration over all 32 rule×path pairs, not asserted: for the 29
unfoldable pairs both branches operate on keys that are no longer there; for the
three `LONE_THRESHOLD` pairs both key lists are empty; for a rule with no entry
the registry returns nothing. Keeping a function that provably cannot act, and
testing it, would be worse than deleting it.

**In which order: the question disappears** with eviction. Unfold each layer, then
merge. There is no second operation to sequence against.

## What each condition is for, and what it costs

1. **Written, not present.** A `threshold` written `~` selects no mode
   (`ThresholdParser` has said so since X19), so a layer whose only `threshold` is
   `~` is not unfolded and leaves the layer below alone.
2. **A value of the form the pair takes.** Unfolding rewrites one key into two, so
   it must not change WHICH key a refusal names. `threshold: abc` unfolded would
   reach the recognition seam as `warning: abc` and refuse the author for a key
   they never wrote — the exact defect class ("a refusal names the author's
   spelling") this programme closed elsewhere. So a `threshold` whose value is not
   the scalar form both graduated keys declare is left alone, reaches the seam
   under its own name, and is refused there as `threshold`. The completeness guard
   below asserts that every unfoldable group's three keys declare the same scalar
   form, so this condition can never silently skip a legal value.
3. **Not when the same layer already carries a graduated key of the group.** That
   is the within-one-layer mix and it must keep reaching `ThresholdParser` to be
   refused. Pinned by `RuleOptionsFactoryTest::itStillThrowsWhenThresholdAndWarningComeFromTheSameLayer`
   and its prefixed-key sibling, which stay untouched.
4. **Only for a group with a graduated pair to unfold into.** The three
   `LONE_THRESHOLD` entries (`complexity.ccn`, `.cognitive`, `.npath` at path `''`)
   declare empty `warning`/`error` lists on purpose: that shorthand is CROSS-PATH —
   it configures the `callable` level and switches `class` off — and bare
   `warning`/`error` are not accepted at that path at all. Unfolding there would
   synthesise a key the recognition seam then refuses, blaming the user for it.
   29 of 32 pairs unfold; these 3 do not.
5. **The key written is the folded spelling.** Every door folds separators away
   before either merge site runs, so the array is camelCase. Writing the registry's
   `max_warning` beside a later layer's `maxWarning` would leave BOTH keys alive —
   no literal collision in the merge — and `ThresholdParser` takes the first
   candidate, shadowing the user's explicit override. Unfolding writes
   `ConfigKeySpelling::normalize($canonicalKey)`. Significant for 8 of the 29.

## §3 — the heuristic dies, and the registry becomes a checked claim

ADR 0055's second open question, handed to this round by ADR 0056.

`evictUsingHeuristic()` guesses a key's group from a suffix when the registry has
no entry; its two known failure modes are in its own docblock. A wrong guess that
REMOVES a key loses configuration; the same guess asked to WRITE a pair fabricates
it. **It is deleted** with its three helpers.

**What that costs, named rather than called conservative.** A rule with no
registry entry is no longer unfolded, so a cross-layer mode change for it —
preset `{warning: 2, error: 3}` under `qmx.yaml {threshold: 5}` — reaches the
parser whole and is REFUSED as a mix, where the heuristic makes it work today.
That is a hard refusal, not inaction, and it is the right outcome only because the
guard below makes "a rule with no entry" impossible to introduce unnoticed.

**The guard, and what makes it a second witness.** The claim is that every
`ThresholdParser::parse()` call site is covered by a registry entry. Both sides are
derived mechanically, from different sources:

- call sites and their literal key arguments, by the PHP tokenizer over `src/` —
  the same method that settled 31-in-30 against a grep that counted docblocks;
- the join key, `(ruleName, path) → options class`, from the rule class list:
  `RuleNameReader::read($ruleClass)` gives the name and the static
  `RuleDefinitionInterface::getOptionsClass()` gives the class, both without
  constructing a rule; `HierarchicalRuleOptionsInterface::levelOptionsClasses()`
  gives each nested path's class. Nothing in this chain is the registry, so the
  registry is compared against something, not against itself.

Three things the guard asserts, stated as the predicate rather than as "equality":

- every call site's key arguments, NORMALIZED by `ConfigKeySpelling`, appear in the
  entry for its `(ruleName, path)`, and vice versa — constants are resolved to
  their values before comparison;
- **the three `LONE_THRESHOLD` pairs are a DECLARED exception, listed by name in
  the guard with their reason** (their call sites name `warning`/`error`, but
  `acceptedOptionKeys()` at that path admits only `enabled` and `threshold`, so
  those arguments are unreachable from configuration and the entry deliberately
  omits them). A declared exception fails when it stops being true; a silent
  mismatch would not;
- every key an entry can WRITE is accepted by the options class at that path, and
  its declared shape is the same scalar form as the `threshold` key's. This is the
  condition that makes unfolding safe against the recognition seam, which runs
  over the MERGED array (`RuleOptionsFactory::create()` validates `$userConfig`
  after both merges), so a synthesised key is judged exactly as a written one.

The guard starts green: two witnesses agreed on today's list with 0 discrepancies
across all 32 pairs, the three exceptions declared.

**The alternative considered and rejected for this round:** moving the group
declaration onto each options class's own `acceptedOptionKeys()` / `RuleOptionKeySet`
and deleting the registry outright. Reachable — the join chain above is exactly
what it would need, and `FindingConfigurationResolver` is a DI service that could
take the lookup. Better long-term shape, written down in
`measurement/deferred.md` so the next round does not re-derive it. Not taken now
because it moves ~22 options classes in the same change as the behavioural cure
four floor rows are waiting on.

## Blast radius, stated as behaviour

Unfolding changes the reverse direction too, and that is intended: preset
`threshold: 25` under `qmx.yaml warning: 10` yields `(10, default)` today and
`(10, 25)` after. For a rule whose constructor error is 20 that moves the error
boundary from 20 to 25, so findings scoring 20-24 change severity from error to
warning — and a run with `--fail-on=error` can go from red to green with no
configuration change. That is a user-visible verdict change, and it is why stage 5
files this under `Breaking` rather than `Changed`.

Two further behaviour changes belong to the same table: a lower layer that already
mixed `threshold` with a graduated key was masked by eviction and is now refused;
a rule with no registry entry now refuses a cross-layer mode change. The full
per-rule table is `measurement/threshold-groups.tsv`, carried into the round's ADR.

## Definition of Done

- The two-layer and three-layer fixtures of `measurement/observations.md` §3
  produce `warning@2` AND `error@5`, in the words of the observation.
- The reverse fixture (`threshold` below, one graduated key above) produces BOTH
  halves of the band, not just the one the top layer wrote.
- `evictOverriddenMode`, `evictUsingDeclaredGroups` and `evictUsingHeuristic` no
  longer exist anywhere in `src/`; no test tests eviction.
- `threshold: abc` is refused naming `threshold`, not `warning`.
- The completeness guard exists with the predicate above, derives both sides
  independently, and fails when: an entry is deleted; a call site's key literal is
  changed; a declared `LONE_THRESHOLD` exception stops being true; an entry names
  a key the options class does not accept. Each proved by planting it once.
- Within-one-layer mixing still refuses, existing tests untouched.
- `composer check` green; `composer gate -- --reference=1210b037` run, its
  differences read and attributed, never assumed.
- The 4 axis-C floor rows stop being defects on the live grid and are marked
  `pending:` in `floor.tsv` in the same commit.

## Files

`src/Analysis/Finding/RuleConfiguration/RuleOptionThresholdModeResolver.php`
(renamed to its new subject), `RuleThresholdKeyGroupRegistry.php`, **both merge
sites** — `src/Analysis/Finding/RuleConfiguration/RuleOptionsFactory.php` and
`src/Analysis/Finding/Configuration/FindingConfigurationResolver.php` — their
tests, the new guard test, `promise-effect/floor.tsv` (the 4 rows), and the
regenerated grid artifact. Shares no `src/` file with stage 4.
