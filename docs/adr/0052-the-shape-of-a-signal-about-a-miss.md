# 52. The Shape of a Signal About a Miss

**Date:** 2026-09-10
**Status:** Accepted

## Context

A user input can name something that does not exist. A `--namespace` that
selects no namespace, an `--exclude` that removes no directory, a
`suppress_paths` entry whose file was renamed, a `--log-level` outside the four
levels: in every one of these the product used to continue and produce a report
that looked exactly like a report produced from a value that had bound.

The round that closed this class had to answer one question repeatedly, once per
door: **what does the product do about a miss?** The product owns three shapes —
a refusal (exit 3), a finding, and a line on stderr — and precedent exists for
all three, so precedent alone chooses nothing.

Two discriminators were settled before any door was treated.

**What the signal must survive.** A finding survives `-q`, the machine formats
and `--fail-on`; it is therefore visible to `composer selfcheck`. A line on
stderr survives none of that under `-q`, which is the lesson position #51 of the
previous round already paid for.

**Whether a finding needs a declaration subject.** It does not, and this was
measured rather than argued: `DeclaredLayerReachability` attaches
`architecture.unreachable-layer` to
`MetricSubject::aggregate(SymbolPath::forProject())`
(`src/Analysis/Policy/Architecture/LayerViolation/DeclaredLayerReachability.php`,
the `Finding` built around line 91). A project-level subject is available to a
CLI door that has no subject of its own, so the choice of shape does **not**
split by where the value came from — a CLI flag and a YAML key with the same
consequence get the same shape.

## Decision

**The axis is what the user sees *after* the miss, not what the door
configures.**

| the miss makes the output                                                                                                                              | the user                                                                | shape                                                        |
| ------------------------------------------------------------------------------------------------------------------------------------------------------ | ----------------------------------------------------------------------- | ------------------------------------------------------------ |
| **less than intended**, down to empty — `--namespace`, `--class`, `graph:export --namespace`                                                           | sees nothing and cannot tell "this does not exist" from "this is clean" | **refusal, exit 3**                                          |
| **more than intended** — `--exclude`, `exclude:`, `suppress_*`, `layers[].exclude`, `frameworkNamespaces`                                              | sees extra material while believing it was filtered out                 | **finding**, `warning`, subject to the coverage precondition |
| **exactly what a hit makes** — `graph:export --exclude-namespace`                                                                                      | sees the whole picture and loses nothing                                | **nothing**                                                  |
| **no output difference at all** — the value set is closed before analysis: `--log-level`, `--cache-dir`, `--format-opt`, `--rule-opt` without `=VALUE` | would get a silent fallback to a default                                | **refusal, exit 3**                                          |

Row one refuses because there is nothing to tell the two readings apart, and
because a report must not carry a finding about its own filter — under `--class`
that finding would not survive the filter it is about. Row two reports because
the mistake belongs to a configuration author and lives as long as the
configuration does. Row four refuses because the accepted set is known before
anything is analysed, and the neighbouring `--output` already refuses.

**Precondition on row two: a zero binding is a property of the pair
⟨configuration, run scope⟩, not of the configuration.** A finding of this row is
produced only when the analysed paths cover the production autoload roots from
`composer.json`. The precondition is not caution — it was forced by measurement
on this repository after the first channel of the row already existed: `check`
over one evidence subdirectory reported three framework prefixes as unmatched
under the project's own faultless configuration, where a full run reports none.
The slice simply did not contain the framework code. A channel judging the
configuration alone charges the author for a path the caller chose. One
measurement answers for every channel of the row —
`Analysis\Run\Configuration\ProjectScopeCoverage` — and the console's
incomplete-coverage warning renders that same answer, so the warning and the
findings cannot come to differ about whether a run was a slice.

## Rejected alternatives

**The axis "configuration versus view of the report", which the round started
from.** Withdrawn by measurement, not by argument: it puts `check --exclude` and
`graph:export --exclude-namespace` in the same class — both exclude, both belong
to "view" — while the first is a defect worth treating and the second is
legitimate silence. What separates them is the consequence of the miss, not what
the door shapes. Any later document reproducing the old axis is a defect, not a
citation.

**A line on stderr for the row-two doors.** It does not survive `-q`, the
machine formats or `--fail-on`, so nothing the project uses to check itself
would ever see it.

**Refusal for the row-two doors.** A stale exclusion would then fail every run of
a configuration that is otherwise fine, including runs that never cared about the
excluded subtree. The row-two mistake is debt, and debt is reported, not fatal.

**Classifying row-two channels as configuration errors** (the shape
`architecture.unreachable-layer`'s siblings use). A configuration-error channel
bypasses `--fail-on` and exits 2 unconditionally, which is heavier than the
refusal this ADR already rejected for the same doors.

**Splitting the fork by the source of the value** — CLI versus YAML. Removed by
the subject measurement above, and independently by a survey of the YAML halves:
`disabledRules`, `onlyRules`, `rules.<name>`, `format`, `failOn`, `excludeHealth`,
`paths`, `parallel.workers` and `memoryLimit` all already refuse.

## Price, named as a price

A row-two finding is an ordinary finding. It enters the ratchet and it enters a
baseline, which means **"my `suppress_paths` entry is stale" becomes acceptable
debt** the moment someone regenerates one. That is the cost of choosing the
shape that survives `-q` and the machine formats, and it is not an incidental
discovery: the project already does exactly this with
`architecture.unreachable-layer`, whose acceptance has the same shape and the
same consequence.

Two smaller prices are named with it. The signal does not reach every machine
format: `health` and `metrics` print their own projections and carry no
project-level finding, so a row-two miss is invisible in two of the twelve
formats. And the coverage precondition means a narrow run reports nothing of
this class — an honest "there is nothing here to judge", but also a run in which
a stale value stays unseen.

The claim boundary of the oracle that found these doors, and the reason it is a
denominator rather than a proof of completeness, are
[ADR 0053](0053-door-enumeration-as-an-oracle.md). Counts of door classes are
owned by `docs/internal/plans/silent-acceptance/01-oracle.md` §1 and are
deliberately not copied here.

## Consequences

- A new input door is designed by asking which row of the table its miss falls
  into, and the answer determines the shape without further debate.
- Row two obliges its author to route `coversProjectScope` to wherever the
  finding is produced. The field carries no default: every place that narrows a
  run builds a fresh configuration, and a default of "covers" would let one of
  them inherit a wider run's answer in silence.
- Row three is a real row. A door whose miss costs the reader nothing is left
  alone, and leaving it alone is a decision with a reason, not an omission.
