# 0046. A Channel Declares the Metric It Judges

**Date:** 2026-09-05
**Status:** Accepted

## Context

"Which catalog metric does this channel's reported magnitude come from?" was
spelled three ways in the tree and checked by none of them.

1. `RuleInterface::requires()` listed metric keys per rule — 57 distinct strings
   across 36 rule classes with no production consumer at all (the two
   `->requires()` call sites in `src/` belong to the collector hierarchy, a
   same-named method on a different interface). 17 of the 57 came from a
   different vocabulary entirely: `codeSmell.boolean_argument`,
   `security.sql_injection` — live collector fact keys in an older spelling, not
   metric names. One list mixed two namespaces and nothing said which was meant.
2. Spelling coincidence: 18 of the 52 channel codes equal a metric key
   character for character. That coincidence is what made a literal guard
   subtract the intersection from its ownership map, leaving production sites
   unjudged.
3. Spelling divergence: `complexity.cyclomatic` judges `complexity.ccn`,
   `maintainability.index` judges `maintainability.mi`, `design.inheritance`
   judges `design.dit`, `code-smell.constructor-overinjection` judges
   `code-smell.parameter-count`, `code-smell.unused-private` judges
   `code-smell.unused-private.total`. The relation was written nowhere.

The two universes are not in bijection, so "one thing, one name" was never
available as an answer: 30 of the 52 static channels declare no judged metric,
and most `MetricName` constants are judged by no channel. `coupling.cbo` reads
`coupling.cbo` or `coupling.cbo-app` depending on its `scope` option; two
channels judge `code-smell.parameter-count` under different thresholds.

## Decision

**A channel declares the catalog metric its magnitude may be read from, and the
declaration is data rather than a resemblance between two strings.**

`RuleInterface::requires()` is deleted, not given a consumer. In its place
`ChannelDeclaration` gains a third factory:

```php
ChannelDeclaration::judging(WorseDirection $direction, JudgedMetrics $metrics, SymbolLevel $level, SymbolLevel ...$moreLevels): self
JudgedMetrics::of(string $metricKey, string ...$moreKeys): self
```

**`ChannelShape` does not change.** `judging()` and `magnitude()` both produce a
`magnitude` channel; no new shape case exists, and ADR 0031 stands unamended on
its own subject. The two factories differ in one thing: whether the producer's
number comes out of the metric catalog at all.

**A separate `JudgedMetrics` type rather than `list<string>`.** The
declaration's levels are already variadic and a signature cannot carry a second
variadic; and a plain list would make the empty list *expressible*, which is
exactly what `ChannelDeclaration`'s own docblock refuses for its levels. The
same refusal is spelled the same way — mandatory first key plus variadic rest —
so non-emptiness is a property of the signature rather than a check in a body
that a later edit could remove in silence.

**What the type does not buy.** `JudgedMetrics::of()` takes `string`s. It is not
a typed reference to a metric: the type gives non-emptiness, registry assembly
gives existence, and a typo inside the *value* of a `MetricName` constant is
caught by neither. What is typed is the **call site** — the author writes
`MetricName::COMPLEXITY_CCN` instead of `'complexity.ccn'`, which is what a
guard reading PHP tokens can see. Two rounds of plan review caught an earlier
wording that claimed more; the wording in the class and here is the corrected
one.

**Keys are declared in their exact published spelling, aggregate strategy
included.** `size.class-count` is declared as `size.class-count.sum`, because
that is the key `ClassCountRule` reads. A channel whose body chooses between
keys names all of them, in the order its own code considers them:
`coupling.cbo` names `coupling.cbo` and `coupling.cbo-app`; the three complexity
channels each name a base key and that key's `.max` aggregate, because they read
the base at callable level and the aggregate at class level. Order is preserved
rather than canonicalised: these are alternatives, not a set with a relation
between its members.

**Two build-time checks, both in `ChannelDeclarationCompilerPass`,** not in
`ChannelDeclaration`: a declared key must exist in the catalog (directly, or as
an aggregate spelling of a real key), and only a `magnitude` producer may
declare one at all. `MetricName` belongs to `Analysis.Evidence.Measurement` and
`ChannelDeclaration` to `Analysis.Finding`; asserting inside the declaration
would buy a build-time check at the price of a permanent capability edge. The
compiler pass already composes both sides.

**The fourth field is a deliberate exception to a closed list.**
`ChannelDeclaration`'s docblock enumerates what it carries and ends with
"Nothing else belongs here"; ADR 0031 explains why shape left. `judges` is added
against both texts, and both are amended in the same step rather than left to
argue with the code.

It belongs on the channel and not on the producer because the fact is bound to
the pair (channel, the levels that channel reports at), and a producer is
neither half of that pair. Two witnesses in the tree, and each says something
the other does not:

- `AbstractTypeCoverageRule::channelDeclarations()` is **one** declaration site
  whose `judges` reads `static::coverageMetric()`. Three channels come out of
  it — `design.type-coverage.param`, `.property`, `.return` — judging three
  different metrics. This one shows only that the value varies below the class
  that writes the declaration; it would survive a producer-held field, since
  each of the three producers owns exactly one channel. It is here because the
  next witness is the one that would not.
- `CboRule` declares **one** channel naming two candidate metrics,
  `coupling.cbo` and `coupling.cbo-app`, and its `scope` option picks between
  them at run time. `ComplexityRule` does the same across levels rather than
  options: one channel over `callable` and `class`, judging `complexity.ccn` on
  the first and its `.max` aggregate on the second. Which key the published
  number is, is a fact about the channel at a level; the class is the same class
  either way, so a producer-held `judges` could not name it without inventing a
  second producer.

What the tree does **not** contain is a producer declaring two channels with
different `judges` — the shape that decided `direction`'s placement in ADR 0031.
The argument here is deliberately not that one; it is the level pairing above.
The absence is also not a reason to move `judges` onto the producer: it would
put a per-channel fact on a class that today happens to own one channel each
time, and the first producer to declare a second would have to move it back.

**`coupling.class-rank` stays `occurrence` and declares nothing**, although a
catalog metric is exactly what it publishes. That is ADR 0017 point 5: a
project-normalised rank can change meaning while the channel does not, and a
baseline entry bound to its magnitude would over-accept. The trade is recorded
in the rule's own docblock so the question is not reopened as a "bug" in the
declaration.

## Consequences

- 22 of the 52 static channels declare a judged metric. The remaining 30 are 26
  occurrence channels plus four magnitudes that publish a number of their own
  making (`architecture.circular-dependency`, `architecture.unassigned-class`,
  `duplication.code-duplication`, `design.god-class`).
- **The set the check does not cover is six positions, named in the compiler
  pass's own docblock** so it cannot grow in silence: those four magnitudes,
  `coupling.class-rank` (the only one where the check stays silent over a live
  catalog value), and the whole computed-metric family, whose channels are
  resolved at run time from configuration and which no build-time pass can see.
- What the build cannot decide is whether a declared key is the key the rule
  body actually reads. That is a property of an observed run: strict equality is
  wrong for at least three measured reasons — `MaintainabilityRule` publishes
  `round($mi, 1)`, `ClassCountRule` publishes an aggregate, and `CboRule`
  chooses its key by configuration — so the run-time comparison matches any one
  declared candidate within the declared rounding.
- The tracked fixture `tests/Analysis/Finding/Fixtures/Channels/declared.txt`
  gains an optional `judges:<keys>` token, and its drift guard compares it
  against the registry in both directions, as it already did for direction and
  levels.
- Rules keep facts `requires()` used to carry incidentally — metrics read for
  gating but never published (`LcomRule`, `WmcRule`,
  `AbstractCodeSmellRule`). `judges` does not carry them by definition: it names
  the source of `metricValue`, not everything a rule reads.
- `AbstractTypeCoverageRule::coverageMetric()` becomes static, so one method
  answers both the declaration and the reading rather than a subclass being able
  to answer them differently.
