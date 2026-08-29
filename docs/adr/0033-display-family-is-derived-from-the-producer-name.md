# 0033. The Display Family Is Derived from the Producer Name

**Date:** 2026-08-26
**Status:** Accepted
**Supersedes:** [ADR 0024](0024-channel-identity-and-selector-semantics.md) on
one point of Decision 4 — "the category is *not* deleted". Every other decision
of 0024, including the one this reverses half of (the category loses all
behaviour), remains in force.

## Context

ADR 0024 §4 found `RuleCategory` doing two jobs — grouping producers for
display, and deciding whether `exclude_paths` / `exclude_namespaces` may
silence a finding — and removed the second. It then declined to remove the
enum itself, and recorded why:

> The category is *not* deleted. `getCategory()` is declared by 33 rules, sits
> in `RuleInterface` and `RuleMetadata`, and is referenced by 46 test files;
> removal would touch roughly 85 files and would still have required the
> separate declared property.

Both halves of that argument have expired.

**The declared property is no longer a future cost — it landed in ADR 0024
itself.** Whether a channel's findings are file-scoped is declared per channel
(`ChannelFileScope`); ADR 0031 moved shape onto the producer; ADR 0025 keyed
exclusions by channel selector. Nothing behavioural is waiting on the category
any more, so "removal would still have required the separate declared
property" no longer describes a choice between two costs. The property exists;
only the second spelling of the display label is left.

**The file count is a migration cost, and it was paid.** The actual removal
touched 100 files that mention the removed names — more than the estimate — but all of it mechanical:
delete a `getCategory()` method, delete an assertion about its return value.
Nothing needed a design decision per file.

What settles the question is not the cost but a measurement. Between ADR 0024
and now, the correlation the category was allowed to keep ("harmless, a
correlation nothing reads") became complete. At ADR 0024's time the declared
category equalled the first dot-separated segment of the producer's name for
41 of 42 producers, `computed.health → Maintainability` being the one anomaly.
After the intervening steps — the level suffix leaving the channel name, the
name/code pair collapsing to one name, the type-coverage split (ADR 0030), and
the computed-metric producer split (ADR 0032), which retired `computed.health`
and introduced the `health` and `computed` cases — the count is 51 of 51. A
declaration that never disagrees with a value already present in the name
carries no fact; it carries a second place to write the same thing, and
nothing checked that the two agreed.

## Decision

`RuleCategory` is deleted. `RuleInterface::getCategory()` and the `$category`
property of `RuleMetadata` and `ProducerDeclaration` go with it. The family a
producer is listed under is the first dot-separated segment of its name,
derived in exactly one place, `RuleFamily::of()`, and handed to consumers ready
as `RuleMetadata::$family`.

**A family is a label, not an address — in one exact sense: it decides nothing
about findings.** No inline directive resolves against it, no rule selector
matches it, no channel exclusion consults it, and the set of findings a run
reports does not depend on it. Group addressing is written `complexity.*` and
is parsed by `NameSelector` from the whole name, never from a first segment.

That is what keeps ADR 0024's defect closed, and it is a different property
from the one 0024 secured by keeping a declared enum. The defect was never
"a label is derived from a name"; it was *behavioural membership* derived from
a name — a group matcher whose derived membership decided what a directive
applied to, so that a future rule named `architecture.anything` would inherit
an immunity it never asked for. Deriving the display label re-creates none of
that: the only consumer is `qmx rules`, and the only thing at stake in a
misspelled first segment is which heading a producer prints under.

One consumer reads the family, and only to display: `qmx rules --group=<family>`
narrows the listing, comparing against the very value the group heading is
printed from. One reading, so the filter and the headings cannot name different
sets.

### What replaces the review barrier

Deleting the enum removes something real: a new family used to require a new
`case`, a visible edit in review. Nothing in the product validates a producer
name's spelling, so a typo in a first segment (`complexty.cyclomatic`) would
silently open a fourteenth heading and drop out of `--group=complexity`.

The barrier is restored as a test, not as a production declaration:
`RulesCommandWiringTest` holds the thirteen families as a literal list and
fails when the registered producers name a family the list does not, or fail to
name one it does. It is deliberately literal — an expectation built by calling
`RuleFamily::of()` moves with the derivation it checks, and did: under a
mutation making the family the whole name, the derived-expectation version of
that test stayed green while grouping 51 producers into 51 groups of one.

A production check is narrower on purpose. `ChannelDeclarationCompilerPass`
refuses, at container build, a producer whose name yields no family at all
(`''`, `.orphan`) — the case that would print a producer under an empty
heading. It does not enforce a name grammar: `computed.branch_load` is a legal
producer name today, and a strict pattern would refuse it. The producer-name
grammar is a separate question, deliberately left open here.

## Consequences

- `RuleInterface` shrinks by one method and `RuleMetadata` by one constructor
  parameter. Consumer code that named the removed types changes mechanically:
  drop a `getCategory()` implementation, read the `string` `$family` where a
  `RuleCategory` was read. `CHANGELOG.md` carries the migration note.
- No published output changes. The same producers list under the same
  headings, `--group` takes the same values, and no channel name, rule name,
  metric key, configuration key, CLI flag, output field, exit code or baseline
  entry is affected. That is verifiable rather than asserted: the step is
  covered by the finding-equivalence gate, which compares the eleven formats
  and `qmx rules` against the preceding commit.
- The name space's spelling now decides a heading. That is a real coupling,
  and it is bounded: a heading, and the `--group` value that selects the same
  heading. Should a producer ever need a display group its name does not
  imply, the answer is a declared exception at the listing, not the return of
  a parallel vocabulary for all 51.
- `qmx rules --group` stays fail-open for an unknown or wrongly-cased group:
  an empty listing and exit 0. Unchanged by this ADR and pinned by a test so
  that a later step changes it on purpose.
