# 0025. Channel Selectors in Configuration Keys

**Date:** 2026-08-19
**Status:** Accepted
**Related:** [ADR 0024](0024-channel-identity-and-selector-semantics.md) (the selector grammar this extends), [ADR 0016](0016-subject-cohesion.md) (why the shared type is owned by Finding rather than by Core)

## Context

ADR 0024 settled what a user-written string means when it names a rule or a
channel: equality, `X.*` for strict descendants, and — where a whole channel is
meant — the explicit `ruleName#violationCode` pair with both halves exact. The
documentation states that grammar once and lists the surfaces it governs,
`exclude_namespace_channels` among them.

One of those surfaces never implemented the pair. `RuleNamespaceExclusionProvider`
parsed its map keys with `NameSelector` and matched the result against the
violation code alone. `NameSelector` does not reject `#`, so a key such as
`complexity.cyclomatic#complexity.cyclomatic.callable` parsed successfully — as a
*name that happens to contain a `#`* — and was then compared against codes that
never contain one. It matched nothing, and said nothing.

At the CLI the outcome was not silence but a false statement:
`RuleInputValidator` refused the key with *"addresses no channel. Write an exact
channel name, or `X.*`"*, advice that contradicts the documented grammar. Silence
remained for every consumer reaching the provider without passing that validator.

Two further facts shaped the fix.

**The pair form was implemented twice already**, in `SuppressionTarget` (halves
validated) and in `RuleSelector` (raw `explode`). A third copy would have been
the obvious way to close the gap, and the second-obvious way to reopen it later.

**The provider could not have matched the pair even with a parser.** Its query
took `(ruleName, violationCode)`, where `ruleName` is the rule the option is
*configured under* — a producer. The pair's first half is the channel's own
`ruleName`, and nothing guaranteed the two were the same: `architecture.layer-violation`
emits five channels, four of them under rule names no class declares as its own.

**The addressability check was also incomplete in a way the pair made visible.**
`exclude_namespace_channels` is applied to the findings of the rule it sits
under, but the validator only asked whether the key addressed *some* channel in
the universe. A key naming another rule's channel was accepted and excluded
nothing — the same "configured, does nothing" outcome ADR 0024's loud-refusal
rule exists to remove.

## Decision

**1. `exclude_namespace_channels` keys read the full ADR 0024 grammar, pair
included.** The option is the only surface whose *key is a channel*; refusing it
the full spelling of a channel would have made it the odd one out in a grammar
whose entire purpose is being one grammar.

**2. The grammar for "addresses a whole channel" is one type,
`Analysis\Finding\Contract\Rule\ChannelSelector`.** It is `NameSelector` plus the
pair. `SuppressionTarget` and `RuleSelector`'s pair branch delegate to it; the
count of implementations goes from three (one of them absent) to one.
Recognising the form is part of the grammar too, so `RuleSelector` asks
`ChannelSelector::looksLikePair()` rather than testing for the separator itself,
and the separator is declared once — on `ViolationChannel`, whose `toKey()` is
the canonical spelling of a pair.

`RuleSelector`'s *one-part* branch is deliberately not folded in: it matches a
producer name, a channel's rule name, or a channel's code, because selection is
the one surface asked to mean all three. That is a different question, and
merging it would have widened `ChannelSelector` to the union of two grammars.

**3. The exclusion query takes the whole `ViolationChannel`.** Reading only the
code would make `a#x` and `b#x` the same key, and would silently answer the
pair by its second half — a match that happens to be right only while the two
rule names agree. This mirrors `RuleSelector::isChannelEnabled`, which already
takes the channel for the same reason.

The channel is what the query compares against, not the rule the option sits
under; a regression that compared the owner instead would still pass every
case that spells all three roles alike, so one test spells them differently on
purpose.

**4. A key must address a channel the owning rule actually produces.** Both
forms, not just the pair. The refusal names which half is wrong: a code carried
by no channel, a code carried under a different rule name (answered with the
spelling the author should have written), or a real channel this rule does not
emit.

**This check is production, not applicability, and the difference is real.**
`RuleExecution` offers the option only findings whose subject is a namespace, so
a key naming an occurrence channel (`code-smell.*`), a class-only channel
(`design.lcom`), or one of the project-scoped layer-policy diagnostics passes
this check and still excludes nothing. Closing that too would need a declared
"can this channel appear as a namespace aggregate" property, which
`ChannelDeclaration` does not carry: its shape separates occurrence from
magnitude but says nothing about level, and the level of a statically declared
channel is not recorded anywhere. A check built on shape alone would refuse the
occurrence half and quietly keep admitting the class-only half — a rule that
looks complete and is not. It is left out deliberately, and named here so the
next reader does not mistake the gap for an oversight.

### Rejected: remove the claim from the documentation and refuse `#` here

Cheaper by one class, and it would have satisfied the "no silence" requirement.
It was rejected because the cost lands on the grammar rather than on the code:
the docs would need a table of which surfaces accept the pair and which do not,
and that table is precisely the thing ADR 0024 abolished. A surface-specific
exception is also not stable — the next surface to grow a channel-shaped key
would have to pick a side with no principle to pick it by.

## Consequences

**Breaking.** An `exclude_namespace_channels` key that addresses a channel its
owning rule does not produce now ends the run with exit code 3. Such a key
previously parsed, validated, and excluded nothing, so no run's findings change
except by the error — but a configuration that was green can now fail. The
message lists the owning rule's channels, so the fix is mechanical.

**The pair is redundant here, and deliberately allowed anyway.** The key already
sits under a rule name, so `computed.health#health.cohesion` says under
`computed.health` exactly what `health.cohesion` says.

It is worth being exact about *how* redundant, because the obvious counterexample
does not hold. The one family whose channels carry rule names of their own is the
layer-policy diagnostics — and every one of them reports against the project, so
none reaches this option at all. Across the current channel space there is no key
the pair can express and the one-part form cannot. Consistency of grammar is
therefore the whole of the reason: a surface whose key *is* a channel refusing the
canonical spelling of a channel would be the odd one out, and a future channel
whose halves disagree would otherwise need the grammar changed rather than used.

**`SuppressionTarget::exactChannel()` returns a `ViolationChannel`** instead of
an `array{ruleName, violationCode}`. Internal to `Analysis\Policy\Inline`.

**The loud refusal is an adapter invariant, not a domain one.** Deciding that a
key addresses nothing needs the resolved channel universe, which the provider
does not see; it therefore keeps answering "no match" in silence, and every CLI
entry point routes through `RuleInputValidator` before analysis starts. A
consumer that reached the provider directly would still get silence — there is
no such consumer today, and giving the domain the universe purely to forbid that
would invert the dependency the universe was split to avoid.
