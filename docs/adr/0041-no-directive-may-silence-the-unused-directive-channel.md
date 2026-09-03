# 0041. No Directive May Silence `annotation.unused-directive`

**Date:** 2026-09-03
**Status:** Accepted

## Context

`annotation.unused-directive` ([ADR 0024](0024-channel-identity-and-selector-semantics.md))
reports an inline directive that is well-formed, addresses something real, and
suppressed or overrode nothing this run. `bin/qmx directives`
([ADR 0039](0039-directive-audit-command-and-contract.md)) answers the same
question per directive, with a verdict.

Until this decision the channel could be silenced by a directive: by its exact
name, through the `annotation.*` group selector, with `:file` after either, or
by a directive carrying no rule filter at all, which covered everything.
Two of those spellings could silence the very complaint the directive itself had
provoked. The product then had to hold two devices whose only job was to correct
the crediting that arrangement broke — a splice that added the late channel to
the audit's universe, and a narrowing that withheld a directive's own complaint
from its own accounting.

The obvious repair was to finish the computation, and it was implemented and
measured before this decision was taken: a two-pass `stale()` gives both
consumers one universe, `composer check` and the controls stay green, and it
costs about 8 ms. It was rejected all the same, because it completes a
capability that is broken by construction. The symbol form
(`@qmx-ignore annotation.unused-directive` in a docblock) can never fire: the
finding's subject is the **file** the annotation sits in, while a symbol
suppression matches the exact declaration subject. One of the three forms of the
protected behaviour was unreachable no matter how correctly the remaining two
were computed. Measured on this tree, no directive used the loophole at all.

The behaviour the loophole protected — silencing "this directive does nothing"
with a second directive instead of deleting the first — is also strange on its
face. The legitimate case behind it (a directive kept alive while its rule is
switched off) is served by configuration, where the decision is visible in one
place.

## Decision

**No inline directive silences `annotation.unused-directive`, in any form.**

### 1. One channel, two questions, one object

The ban is asked twice, because two different callers need two different
answers, and it lives in one class
(`Analysis\Policy\Inline\Directive\DirectiveChannelBan`) so the two spellings of
the channel's identity cannot drift apart.

- **"May this target be addressed at all?"** — `problemWith()`. A directive
  whose target reaches the channel is refused where it was written, and the
  refusal is reported as `annotation.unresolved-directive` naming the channel,
  not merely the selector.
- **"Could a directive have silenced this finding?"** — `covers()`, read by
  `SuppressionFilter::applies()`. This question exists for the form the first
  cannot reach: a directive with no rule filter names no channel, so there is
  nothing to refuse, yet it silenced this channel today by covering
  everything.

Question 1 has **two** consumers, and both read the same refusal rather than
deriving one each: `DirectiveAddressability::problemWithSuppression()` and
`DirectiveUsage::unmeasurableReason()`, which answers `already-refused`. Without
the second, a single authored line would draw two complaints — the refusal, plus
a fresh `annotation.unused-directive` about the refused directive — and
`bin/qmx directives` would judge a form that `check` had already refused.

The refusal is asked **after** the `channel:level` grammar, not before. Asked
earlier it would intercept every form carrying an undeclared level and change
its diagnostic text, so `annotation.unused-directive:class` is still answered as
the impossible pair it is.

### 2. The ban is not a property of the channel declaration

The first revision of the plan put it on `ChannelDeclaration`, with a fixture
flag and a guard. That buys an axis in the `Analysis\Finding` contract for a
fact with one carrier and one reader: every consumer of the ban is inside
`Analysis\Policy\Inline`, the channel is produced by a rule of that capability,
and its name is a constant of that capability. There is no cross-owner consumer,
so under [ADR 0022](0022-capability-oriented-modular-monolith.md) the fact stays
with its owner.

**The named cost:** the ban is not visible in the channel catalogue. A reader of
the declarations cannot see that this one channel is unaddressable. Should a
cross-owner consumer ever appear, the fact is promoted then.

### 3. Two questions, not one composite predicate

A single predicate placed on addressability would have to refuse
`@qmx-ignore-file annotation.unresolved-directive` as well. That form is
accepted today and reported `inert`; refusing it would change the behaviour of
the three neighbouring configuration-error channels, move published findings,
and therefore move the equivalence gate.

### 4. The group selector is refused whole, and `expand()` is not narrowed

`problemWith()` reads `ChannelIdentityInterface::expand()` and refuses any
target whose expansion reaches the channel. It does **not** remove the channel
from the expansion. The same object answers the configuration family
(`exclude_namespace_channels` and its siblings), and a narrowed expansion would
silently shrink the `annotation.*` spelling of such a key — a change nothing
would report.

An earlier revision gave a different ground for the same choice: that narrowing
`expand()` would break `exclude_namespace_channels` for this channel's exact
name. Measurement disproved it. That key never worked for this channel and does
not work for most channels of the project: a kebab-cased key inside the `rules`
section is camel-cased by normalization and rejected at startup. The defect is
recorded as a follow-up; it is not a consequence of this decision and predates
it.

### 5. The finding stays ordinary debt

Configuration errors are lifted out of the pipeline and merged back at the very
end, past every stage. **This channel is not.** A finding on it passes every
stage after suppression: the top-level `exclude_paths` drops it, a baseline
ceiling accepts it, a git scope narrows it. Only the mechanism it exists to
audit is withdrawn.

Two exclusions never reached it, and did not before the ban either — measured on
a fixture rather than reasoned about. The top-level `exclude_namespaces` matches
a namespace and this finding's subject is the file, which carries none; the
producing rule's own `exclude_paths` / `exclude_namespaces` close with rule
execution, and this channel is assembled after it.

### 6. The audit universe is not widened to the banned channel

The universe the verdicts are judged against is what rule execution produced,
and `DirectiveUsage::suppressible()` — which drops the configuration-error
channels from it — is **not** extended to drop the banned one as well. Doing so
would add an unreachable branch: `evaluate()` short-circuits to `Unmeasured` on
a non-empty reason, and any directive able to reach a finding on this channel
has already been answered `already-refused` by question 1. The filter stays for
the three configuration-error channels, because there a directive is *not*
refused and the filter is the only thing that makes it inert.

## What this decision revokes

**[ADR 0039](0039-directive-audit-command-and-contract.md), Decision 4, in one
paragraph.** That ADR concluded: "So the universe is the executor's set plus
that late channel — everything the run produced, in whichever step it produced
it." That conclusion was correct while a suppression could address the channel;
under this ban it cannot, so no verdict is judged against a finding of that
channel and including it would name a universe the verdicts were not measured
in. The universe is the executor's set. The rest of Decision 4 — that the
suppression half is judged against what the rules produced rather than what a
report would publish — stands unchanged, as does Decision 5.

`DirectiveAuditReport::$producedFindings` therefore moves on any tree carrying
stale directives. That is the substance of the change, not an accident of it.

**[ADR 0037](0037-suppressed-format-and-produced-findings.md) is not affected.**
Its `RuleExecutionResult::$produced` names what rule execution returns, before
the per-rule ledger and channel selection; the audit's universe is a different
set assembled by a different caller. The plan for this step expected 0037 to
carry a revoked claim; reading it, none was found.

## What was removed, and how its deadness was measured

Two devices existed only to correct the crediting of this one channel and die
with the loophole: the late-channel splice in
`AnalysisPipeline::auditDirectives()` and `DirectiveUsage::withoutOwnComplaint()`.

Three measurements, because one is not enough. Removing them on the tree that
already carries the ban and observing "only the loophole's guards go red" would
be satisfied trivially: those guards assert verdicts the ban itself changes
*before* any removal, so they are red already and the observation cannot tell
"the mechanism is dead" from "the witness was broken before it was asked".

1. **Coverage exists.** On the pre-ban tree, removing each device reddens
   named cases, one each: the splice reddens
   `DirectivesCommandTest::itJudgesASuppressionOfTheChannelProducedAfterRuleExecution`,
   the narrowing reddens
   `::itDoesNotLetADirectiveJustifyItselfWithItsOwnComplaint` (plus a generated
   artifact going stale, because removing the call left an import unused).
   Neither moves a byte of `bin/qmx check src/` — no directive on this tree used
   the loophole. Recorded in
   `docs/internal/plans/rule-vocabulary/X4-directive-ban/measurement-ban-seams.md`, §5.

2. **Neither device ever changed its own answer**, on the tree where the ban
   stands and the devices are still in place. The predicate matters here and the
   first attempt at it was wrong: "put a loud refusal in the body and show it is
   never called" is **unimplementable**, because both bodies are unconditional
   and such a refusal fires on every legitimate run — the first instrumentation
   written this way went red immediately. The correct predicate is conditional,
   and it is three conditions: the refusal is thrown only if the narrowing
   changed the answer of `anyOfTheGroupFired`, only if the splice moved at least
   one verdict, and only if a directive matched a finding on the banned channel.

   **Observation: zero firings**, across the full PHPUnit suite, the forms
   stand, `bin/qmx check src/`, `composer directives:controls`, and all three
   `composer directives:narrow-control` runs.

3. **Removal moves nothing else.** On that same tree, with the test
   expectations already rewritten for post-ban behaviour, deleting both devices
   leaves the suite green and the forms stand equal to the table predicted
   before the ban was written.

## Consequences

### Three breaks for a consumer, of three different kinds

1. **A form that was accepted is now refused.** A directive naming
   `annotation.unused-directive`, or a selector covering it, fails the run with
   a diagnostic on the authored line. Twelve spellings are refused: three tags
   (`@qmx-ignore`, `@qmx-ignore-next-line`, `@qmx-ignore-file`) against four
   targets (the exact name, the exact name with `:file`, `annotation.*`, and
   `annotation.*:file`).

2. **A form that named nothing stops silencing the channel, silently.** The
   form with no rule filter — a bare `@qmx-ignore-file`, or an explicit `*`
   target on it or on `@qmx-ignore-next-line` — names no channel, so there is
   nothing to refuse and it gets no diagnostic, ever. The findings it hides
   today simply appear in the report. A consumer learns of this break only from
   the changelog.

3. **The group form stops addressing its neighbours.**
   `@qmx-ignore-file annotation.*` was a legal way to address the three
   configuration-error channels at once — it was accepted and judged `inert`.
   It is now refused whole, because its expansion reaches the banned channel.
   Addressing any of the three by its exact name is unchanged.

### The accepted cost: the form with no rule filter can die silently

The bare form receives no verdict — it is reported `unmeasured` with reason
`addresses-every-channel`, because there is no channel whose producer could be
consulted, and calling it inert would report a clean file as a defect. Its only
observable effect on this channel was the thing the ban withdraws. Should it
lose its remaining coverage, nothing will say so.

### What the step is proven by, and what it is not

`composer gate` is green here and says nothing about the subject of the step.
The gate has no shape in which a step may declare an intentional change to the
set of findings, so a corpus fixture was deliberately not planted: the green run
witnesses exactly one thing — that nothing unrelated moved. The subject is
proven by the forms stand, the tests, and the audit probes.

The first bill for that missing shape has already arrived: correcting the
"did you mean" hints was dropped from this pass, not because it is unwanted but
because it moves a corpus case's published `message`, and `message` is part of
the equivalence tuple. An author who misspells toward this channel therefore
still gets a hint pointing at a channel they may not name, followed by the
refusal explaining why.
