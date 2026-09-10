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

## Amended (X16 F1): the precondition was necessary and not sufficient

Execution review of the round that landed the row found the precondition wrong
in three ways at once, each in the expensive direction — a channel accusing an
author of a correct configuration, which fails a `--fail-on=warning` pipeline.
All three are now closed, and the amendment is recorded here rather than left
to be rediscovered from the code.

**"No denominator" is not "covered".** `ProjectScopeCoverage` read the
production autoload roots from `autoload.psr-4` alone and treated an empty
result as a whole-project run. A project declaring its production code through
`classmap`, `psr-0` or `files` therefore licensed every channel of the row on
any run at all. The measurement now has three answers, carried together on
`ProjectScopeMeasurement`: covered, narrowed, and unreadable — and unreadable
reads as "cannot judge", so the gate closes and no scope warning is printed,
because there is no uncovered target to name. *Which manifests are unreadable
is settled by the F4 amendment below, which supersedes the answer this
paragraph first gave.*

**The precondition binds every channel of the row, and one shipped without
it.** `architecture.unmatched-exclude` was added a round later and published
unconditionally. It now asks the same predicate, and
`ScopeConditionedChannelGuardTest` is what keeps the next one honest: it reads
the row's population from the channel registry — **every declared channel whose
name contains `unmatched` belongs to this row**, which is the naming convention
this ADR now fixes — and runs the same fixture under a manifest whose
production declarations the run covers, where all six must speak, and under
one declaring production code the run never looked at, where all six must be
silent.

**A run wide enough to judge the project is not wide enough to judge every
value written for it.** `qmx check src/` covers the production autoload roots
of an ordinary repository, so the precondition passes — while an entry naming
`tests/`, written for `qmx check .`, binds nothing there through no fault of
its author. Three channels reported it at once. A second question is therefore
asked of each value separately: where does the value's own subject live, and
did this run analyse that place? A path value is placed by its literal anchor's
deepest existing ancestor, a namespace value through the PSR-4 map with
`autoload-dev` included, and an `--exclude` / `exclude:` pattern by re-probing
the whole project tree — a pattern that removes a directory somewhere the run
did not look is not stale. A value that names no single place (one beginning
with a glob) is never judged: the cost of the safe direction is a stale entry
left unreported until a run whose paths reach it.

Two channels of the row keep the project-wide question alone, and this is a
named residual rather than a decision. `coupling.unmatched-framework-namespace`
names code outside the project by construction, so it has no subject to place.
`architecture.unmatched-exclude` has one for `patterns:` but not for
`suffix:`/`implements:`/`extends:`, and a rule cannot see the run's paths at
all — `AnalysisContext` carries the verdict, not the scope. Both therefore
still report a clause whose classes live under `autoload-dev` when the run
covers only the production roots.

**A template's `exclude:` clause is judged once, across every layer it expanded
to.** Per instance, a clause carving classes out of one module was reported
against every module with nothing to carve, and its recommendation — drop the
clause — would have broken the module where it works. The counts are summed
over the instances one declaration produced.

## Amended (X16 F4): only genuine illegibility closes the gate

F1's answer to "no denominator" was right about the direction and wrong about
which manifests have none. Treating `classmap`, `psr-0` and `files` as unread
production declarations closed the gate on any manifest carrying one. Measured
on `benchmarks/vendor`: **51 of 125 packages** declare production code that
way, most often a `files` section of polyfills or helpers standing beside an
ordinary `psr-4` one. On such a project all six channels of the row are silent
on every run — the cure for silent acceptance itself silently inert on roughly
half of real projects, which is worse than the defect it treats, because the
defect is visible in the report and the inertness is not.

`classmap`, `psr-0` and `files` are therefore ordinary path targets in the
coverage comparison. `files` names files, `classmap` names files and
directories, and the question asked of a target — does an analysed path
contain it — answers the same way for a file as for a directory. The
denominator is the union of every production section, so a mixed manifest is
measured whole rather than by its PSR-4 half: the false accusation F1 removed
stays removed, now because the run is measured against the `files` entry too
and comes up short, rather than because nothing could be measured. After the
change every one of the 125 packages yields a readable denominator.

"Cannot judge" survives, narrowed to the honest case: the manifest declares no
production autoload this product can read **at all** — it is absent, it does
not parse, it has no `autoload` section, or every production section in it is
empty or malformed. This reverses F1's second half as well: a *missing*
`composer.json` used to read as covered on the ground that its absence is
warned about separately. That warning is a stderr line, and this ADR already
rejected stderr as a signal: it survives neither `-q` nor the machine formats,
so on a project without a manifest the row is simply silent in CI. The price
is paid anyway, because a project that never said which of its directories
hold production code cannot distinguish a slice from a whole, and guessing
"whole" is the guess that accuses an author of a correct configuration. Unlike
the `classmap` reversal, this one has no price measured on a corpus:
`benchmarks/vendor` cannot price it, since every package there carries a
`composer.json` by construction.

Two boundaries of the new comparison, stated rather than left to be
rediscovered. A declared target that does not resolve on disk is skipped,
which opens the gate rather than closing it; that covers a stale entry and a
`classmap` glob alike, since Composer accepts `*` in a `classmap` entry and
this product does not expand it (no manifest of the 125 uses one). And
`ComposerDiscoveryStage` still derives *default* analysis paths from
`autoload.psr-4` only, so on a mixed manifest whose non-PSR-4 targets lie
outside its PSR-4 roots, `qmx check` with no paths now reports an incomplete
scope naming that target and silences the row — correctly, since that code
genuinely was not analysed, but it is a behaviour the default invocation did
not have before. Measured on the same corpus: **1 package of 125**
(`marc-mabe/php-enum`, a `files` stub outside `src/`) is in that shape.

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

A row-two finding is an ordinary finding. It enters the ratchet and a baseline
entry accepts it, which means **"my `suppress_paths` entry is stale" can become
accepted debt**. That is the cost of choosing the shape that survives `-q` and
the machine formats, and it is not an incidental discovery: the project already
does exactly this with `architecture.unreachable-layer`, whose acceptance has
the same shape and the same consequence.

Two corrections to how that price was first written, both measured on fixtures
after the fact, both narrowing it:

- **Regenerating does not accept the three `suppression.*` channels.**
  `baseline:generate` measures on a seam those findings are not on, and a test
  pins that they are never written there. A hand-written entry does accept one.
  So acceptance of a stale suppression is a decision someone writes, not a
  side-effect of a command someone runs, and the earlier phrasing "the moment
  someone regenerates one" was wrong about half of this ADR's own channels. The
  route that remains for a shared configuration is narrower still and better:
  `disabled_rules` names one channel, in the file the shared configuration
  already lives in.
- **The accepted unit is the value, not the count.** Every row-two finding now
  carries an `OccurrenceKey` built from the value it is about — the pattern, the
  prefix, the suppression value, the `exclude:` declaration. Without it these
  findings shared one identity per channel on the project subject, so an entry
  bounded how many of them there were: accepting two stale patterns accepted any
  two, including one introduced by the next edit. A signal about a miss whose
  acceptance does not name the value that missed is itself a silent acceptance,
  which is the defect this ADR's row two exists to remove.

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
