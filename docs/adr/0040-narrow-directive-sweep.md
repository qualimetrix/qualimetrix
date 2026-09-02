# 40. A threshold directive is judged by re-executing the rule it addresses

**Date:** 2026-09-02
**Status:** Accepted

## Context

[ADR 0039](0039-directive-audit-command-and-contract.md) established how
`bin/qmx directives` answers what a `@qmx-threshold` did: nothing a rule
publishes says which boundary it decided with, so the directive is removed and
the rules are executed again over the run's own measurements. The cost of the
feature is that sweep — one whole rule execution per authored directive.

Measured on this repository before the change: 57.6 s for 43 verdicts, of which
31 are thresholds spread over eight rules. A profile of one `check` puts a full
rule execution at 2279 ms, and a single rule at between 37 ms and 261 ms. The
sweep was therefore paying, for every directive, forty-eight rule executions
whose only possible result was to be compared against themselves.

A directive addresses exactly one rule by exact name — `ThresholdOverride::matches()`
is equality, with no group form and no wildcard ([ADR 0024](0024-channel-identity-and-selector-semantics.md)
§2) — so the outcome it can move is that rule's.

## Decision 1 — the counterfactual executes one producer, named by name

`RuleExecutionInterface::execute()` takes an optional producer name that
**narrows** the run's already-resolved selection. The two are asked in series
rather than merged: a producer the configuration disabled stays disabled when
the narrowing names it, so a directive on a disabled rule remains `Unmeasured`
rather than becoming measurable by being audited. The host of a classless
producer still runs when the narrowing names a producer it hosts, which is the
existing `--only-rule` semantics of the computed-metric family.

A name and not a `RuleSelection`. One name is the whole subject, and
`RuleSelection` is Finding's internal type with no foreign consumers: taking one
here would have published it as contract surface in exchange for an
optimisation.

## Decision 2 — three controls, and each answers a different question

Narrowing changes which run a verdict is measured against, which is the one
class of mistake this pass has repeatedly made. Three controls, none of which
subsumes another:

1. **Both sides are measured the same way.** The baseline a counterfactual is
   compared against is the same producer executed the same narrowed way — not
   the full baseline projected onto the rule's name. A projection would compare
   a run against a filtered other run and read the difference in how the two
   were produced as the work of a directive.
2. **The rebuilt context still reproduces the run.** The existing full
   before/after control stays full and stays in the price. It is the only thing
   tying any of this to the run `check` actually performed; a narrowed baseline
   is tied to nothing on its own.
3. **A rule in isolation produced what it produced in company.** The narrowed
   baseline of a rule is checked against the full baseline restricted to the
   names the narrowed run produced, plus the addressed rule itself. It costs
   nothing — the full result is already in hand — and it fails the whole sweep
   rather than any one directive, because that is what it is a statement about.

What none of the three can see: removing a directive of rule X moving a finding
of rule Y. A narrowed sweep never executes Y, so neither side of that comparison
contains it. Decision 3 addresses it, and the first draft of this ADR claimed it
*measured* it — which is the same mistake, one layer up. What the control
compares is **verdicts**, not findings: a moved finding of Y is visible only
where it changes X's verdict from one category to another. Where X is
`Effective` on its own account either way — the state of every directive on this
tree — the movement is real and the comparison is silent.

What holds the claim up is therefore structural, and it is worth stating because
it is cheaper and stronger than the sweep: a rule cannot read another rule's
directive. `AnalysisContext::getThresholdOverride()` is the sole reader of the
override map in the rule layer, it is called from exactly two places, both pass
the calling rule's own name, and `ThresholdOverride::matches()` is equality. A
guard test holds that shape, so a third call site with a foreign name reddens
immediately rather than waiting for someone to run the full sweep.

## Decision 3 — the full sweep stays in the product, as the other side of a control

`--sweep=full` re-executes every enabled rule and produces the same verdicts at
many times the cost. It is not a fallback, a debug mode, or a legacy path: it is
how the claim the narrowing rests on gets measured on a real tree.
`composer directives:narrow-control` runs the two scopes as two processes over
`src/` and compares verdict for verdict.

The alternative was a script assembling the audit through the container. It was
rejected on the pass's own recurring lesson: a control must measure both sides
by the path CI will take, and a script that reassembles the command's wiring can
part company with it without either side noticing. A one-off comparison against
a fingerprint taken before the optimisation was rejected too — it does not
survive the next directive added to the tree.

The price of the decision is one CLI option that exists mostly for us. It is
documented as what it is: an expensive re-measurement of a cheap answer.

## Consequences

- The audit on this tree falls from 57.6 s to 16.0 s, which is what allows it
  into `composer check` — group `check:self`, after `selfcheck` — without
  spending the owner's stated budget of roughly one minute.
- The remaining cost is dominated by the prepared run itself, which repeats
  Collection in a separate process. Narrowing does not touch it.
- The equivalence control is green, and its discriminating power is bounded by
  the population it runs on. On `src/` every verdict is `Effective`, so a defect
  that yields `Effective` everywhere passes it — measured, not supposed: a
  mutation narrowing to the wrong rule passed while the narrowing was written in
  two expressions. Review once ran the control over a tree seeded with an
  `Overrun`, a dead directive and a masking pair — 45 verdicts, both scopes
  agreeing, the only run in which the coalition and `Overrun` branches have
  executed under the control at all. **That tree is not in the repository and
  the control cannot be pointed at another one**, so the run is a session
  record rather than evidence anyone can repeat; `FOLLOWUPS.md` carries what
  would close it. Verdicts this tree does not produce are held by tests.
- Per-directive cost is now the addressed rule's own cost, so a directive on an
  expensive rule costs more than one on a cheap rule. The population figure that
  would reopen the question is no longer directive count alone.
