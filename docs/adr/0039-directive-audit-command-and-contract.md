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
