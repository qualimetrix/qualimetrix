# 0043. A Late-Assembled Finding Obeys Channel Selection, Not the Exclusion Ledger

**Date:** 2026-09-04
**Status:** Accepted

## Context

`annotation.unused-directive` is the only channel assembled after rule
execution: `AnalysisPipeline::reportedFindings()` joins `RuleExecution`'s
published findings with the directive-usage audit, which can only be answered
once every rule has produced its findings.

Being assembled after `execute()` meant being assembled past everything
`RuleExecution::published()` applies. Two checks live there, and both were out
of reach. Measured on a fixture with controls:

- `--disable-rule=annotation.unused-directive` was **inert and silent** — the
  channel was published anyway, with no message at all;
- an `--only-rule` naming a *sibling* channel of `annotation.directive`
  published this one too, although it never named it;
- four other spellings (the producer name, the group, the group with a level,
  the union of all four channels) worked, so "the selector addresses the
  channel" is not a usable predicate for refusal — it would refuse what works.

The per-rule exclusion ledger (`exclude_paths`, `exclude_namespaces`,
`exclude_namespace_channels`) was equally out of reach, and silently so.

## Decision

**Channel selection applies; the exclusion ledger does not.**

`RuleExecutionInterface::publishable()` offers `published()`'s
channel-selection half to the one caller that assembles findings late, and
`reportedFindings()` asks it where the channel joins the report. The predicate
is the same object and the same call, so the union-quantified half of the
grammar — a producer stopped because its disable selectors together cover every
level of every channel it emits — is not re-derived per selector.

The exclusion ledger is deliberately left out, and the reason is measured, not
stylistic: a ledger lives for one `execute()` call and its account is frozen
into the result by the time this channel exists. Applying it removed the finding
from the report while `--show-suppressed`, the mechanism counters and the
attributions went on naming only the early sibling. A finding that disappears
with nothing able to say who removed it is worse than an option that does not
apply.

Alternatives considered and rejected:

- **Refuse a selector that addresses the late channel.** Rejected because the
  predicate is wrong: four spellings already worked, and the refusal would have
  denied them.
- **Move the channel's assembly inside rule execution so it passes both checks
  for free.** That is a different decision with a different cost — the audit
  would have to be answered before every rule has finished, which is the one
  thing it cannot do. Selection is a predicate over a finding; the ledger is an
  account kept during a run. Only the first can be asked afterwards.

## Consequences

- `--disable-rule` and `--only-rule` naming this channel now do what they say,
  and so does every producer-level and group-level spelling — through one
  predicate rather than a second copy of the grammar.
- Per-channel `exclude_*` under `annotation.directive` remain inapplicable to
  this channel, and remain silent about it. The follow-up that owns this names
  the two ways out: assemble the channel inside rule execution so it passes the
  ledger with its accounting, or give late channels an exclusion stage with an
  account of their own. Both move the publication order.
- `ConfiguredLevelActivity` carries the level-activity snapshot out of
  `RuleExecution`, whose WMC the new method took to its ceiling.
- The equivalence gate does not witness any of this: the corpus has selector
  cases, but not in the case that carries the late channel. The subject is
  proved by regression tests; a green gate here says only that nothing else
  moved.
