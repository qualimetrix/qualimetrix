# 39. The directive audit is a separate contract, and its verdicts are four

Date: 2026-09-01

## Status

Accepted

## Context

`bin/qmx directives` answers, for every inline `@qmx-ignore` and
`@qmx-threshold` in the analysed tree, whether it still does anything. The
suppression half has always been computable: a suppression that matched no
finding silenced nothing, and `annotation.unused-directive` already reports it.

The threshold half is not computable that way, because nothing a rule publishes
says which boundary it decided with. Its only observable is the run it changes,
so each authored directive is removed on its own and the rules are executed
again over the run's own measurements. That is the whole cost of the feature:
one rule execution per directive.

Three decisions in the resulting design are not recoverable from the code.

## Decision 1 — a second contract on the pipeline, not a field, a port, or a
second operation on `AnalysisPipelineInterface`

The audit needs the entire prepared stage of a run: the measurements, the graph,
the namespace tree, the prepared capabilities and the executor that produced the
baseline. Exactly one type assembles that — `AnalysisPipeline` — so the audit
enters through it.

It enters through a **separate** contract,
`Analysis\Run\Contract\Pipeline\DirectiveAuditInterface`, implemented by the same
class. The alternatives were each rejected for a reason:

- **A ninth field on `AnalysisResult`** would make every caller of `analyze()`
  pay for an audit nobody asked for; the audit costs N executions.
- **An observer port in `Run`** is the generic lifecycle port ADR 0022 refuses.
- **A second operation on `AnalysisPipelineInterface`** would hand the audit to
  four consumers that analyse and do not audit. `DependencyGraphAnalyzerInterface`
  already sets the precedent for splitting instead.

The composition root binds one instance under both contracts.

## Decision 2 — four verdicts, and one of them is not observable everywhere

`Effective`, `Overrun`, `Inert`, `Unmeasured`.

`Unmeasured` exists so that "nobody asked" is never reported as "it does
nothing". Reporting a directive as inert tells an author to delete it, and a
directive addressing a rule that did not run has not failed to do anything. Its
four reasons are read off the code that already refuses to account for them,
not invented: a disabled producer, a directive already refused by
`annotation.unresolved-directive`, a directive with no rule filter, and a
directive masked by a neighbour (Decision 3).

`Overrun` — the directive applied, and nothing moved except the boundary the
finding prints — is the interesting one, in two ways.

First, **it is not observable for every rule.** Nine of the twenty-seven rules
that build findings put no boundary in them, and four of those nine support
overrides. For them a raised boundary the measured value had already passed and
a directive that does nothing produce the identical fingerprint, so the verdict
comes out `Inert`. The report says so — `boundaryObservable` is false exactly
when the addressed rule reported on a covered subject without publishing a
boundary — rather than passing silence off as a check. Extending findings with a
boundary for the audit's benefit is a separate decision with its own subject.

Second, **it makes no claim about direction.** The rule layer has no notion of
which way is stricter: `coupling.instability` is worse when higher,
`cohesion.tcc` when lower. A directive that tightens a boundary and one whose
promise the value had already overrun are the same observable. So the verdict
states exactly what it supports — applied, and nothing moved except the printed
boundary — and the text projection prints that sentence while the machine
projection keeps `overrun` as a stable key. Splitting the verdict in two is
possible only after the rule layer acquires a notion of direction.

## Decision 3 — a coalition of directives is not inertness

Leave-one-out is blind to mutual masking, and that is a property of the method
rather than an oversight. A class-level directive materialises on the class and
on every method in it; a method-level one materialises on the callable; and the
context picks one by specificity. If two of them give the same verdict on a
subject, removing either alone changes nothing, and both would be reported inert
although removing both changes the outcome.

So a directive that comes out `Inert` is asked again, **differentially**. Every
directive of the same rule on the same subject is removed, and two
counterfactual executions are compared: without the maskers, and without the
maskers and the directive together. A difference makes it
`Unmeasured / Masked`, naming the neighbour; agreement makes it genuinely inert.

Two details are load-bearing and were each wrong in an earlier revision:

- **A set, not a pair.** One subject can carry three directives — a class
  docblock, a property docblock and a hook docblock — and then no pair moves the
  outcome while the triple does.
- **A difference, not a comparison against the baseline.** Comparing against the
  baseline run charges a live neighbour's effect to the dead annotation beside
  it.

## Decision 4 — the universe is what the run produced, including what it
produces late

The suppression half is judged against the findings the rules produced, not
against what a report would have published: `exclude_paths`,
`exclude_namespaces` and `exclude_namespace_channels` suppress publication, and
a suppression covering a finding the ledger would have dropped anyway did not
silence nothing.

One channel makes "produced" less obvious than it sounds.
`annotation.unused-directive` is not emitted inside rule execution — the owning
rule returns nothing and merely arms a gate, and the findings are assembled
afterwards. Judging against the executor's own set therefore reported *every*
suppression aimed at that channel as inert, while removing one demonstrably
adds findings to a `check` of the same tree: the command would have told an
author to delete an annotation and turned their build red for obeying. So the
universe is the executor's set plus that late channel — everything the run
produced, in whichever step it produced it.

The threshold half keeps the executor's own set, and correctly: a
`@qmx-threshold` cannot move a channel that declares no boundary, and the late
one declares none.

## Decision 5 — a verdict fails the build only where the answer was observable

Exit `2` means "this directive is proven dead". Where the addressed rule
publishes no boundary with its finding, `Inert` and `Overrun` are the same
observable, and the report says so. Failing the build on that would report an
unasked question as proven debt — the exact error `Unmeasured` exists to
prevent — so an inert verdict with `boundaryObservable = false` is printed with
its note and moves nothing.

A run that discovered no PHP file at all is refused with the configuration
error code rather than reported clean. Its verdict list is empty for the same
reason a run over an empty directory is: nothing was measured, and "nothing was
measured" is not "nothing is wrong".

## Decision 6 — a suppression is judged against what a suppression can reach

A channel declared by a configuration validator is exempt from annotation
suppression by the kind of thing it is: the projection never offers such a
finding to an annotation, in any run and under any configuration. Counting one
as something a suppression matched reported a directive as live that provably
cannot work — measured on a fixture, `@qmx-ignore-file
annotation.unresolved-directive` came out effective while `check` printed the
very error it claimed to silence.

This is not the publication ledger of Decision 4 returning. That ledger is a
configuration choice about a report, and excluding it would have made a
directive's verdict depend on someone's `exclude_namespaces`. This is a
property of the producing type, identical in every run.

**The two halves do not share one universe in one case, and it is recorded
rather than hidden.** `annotation.unused-directive` is the staleness
accounting's own output, so the accounting cannot be given a list containing
it, while a caller asking for verdicts can be. A suppression aimed at that
channel is therefore called live by `qmx directives` and stale by `check`. The
command is the correct one of the two — it did silence the neighbour's finding
— and closing the gap means making the channel's accounting two-pass, which
changes a published channel and belongs to the package that owns it.

## Consequences

- `qmx check` is unchanged. The audit is its own command and its own contract,
  and its cost is paid only by callers that ask for it.
- The verdict types of `Analysis\Policy\Inline` became contracts when — and not
  before — the command that renders them began naming them in code.
- Preparing the run's directives no longer depends on the directive rule being
  enabled. Switching that rule off silences its channels through gates that were
  always the real ones (the rule opens its own channel as it runs; a validator
  executes inside its producer's slot), and clearing the store as well silenced a
  third thing nobody asked to silence: the audit's answer about suppressions,
  which then read as "this tree carries no annotations".
- A verdict is relative to the analysed scope, and the report prints that scope.
  There is no "was the whole project analysed" flag, because a resolved
  `RunConfiguration` no longer knows what it was resolved from.
