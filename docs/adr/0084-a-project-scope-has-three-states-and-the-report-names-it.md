# 0084. A Project Scope Has Three States, and the Report Names It

**Date:** 2026-09-24
**Status:** Accepted

Amends the 2026-09-24 amendment to
[ADR 0079](0079-a-criterion-the-run-cannot-answer-is-undecidable.md): its
paragraph "Two verdicts need the whole project" first recorded the opposite
decision — a manifest-less project never judged — and now defers here.

## Context

A family of channels says that a configured value matches nothing in the
project: `architecture.unreachable-layer`, `architecture.empty-template`,
`architecture.unmatched-exclude`, `coupling.unmatched-framework-namespace`,
`discovery.unmatched-exclude` and the three `suppression.unmatched-*`
channels. A run over a slice of the project cannot say that about code it did
not analyse, so each reads one predicate, `coversProjectScope`, answered by
`ProjectScopeCoverage` from the run's paths and the production targets
`composer.json` declares under `autoload`.

The predicate answered two things with one boolean. `false` meant both "the
paths leave out declared code" and "the manifest declares no readable
production autoload, so there is nothing to measure against". The second case
silenced every channel above on every project without a readable manifest, for
good: measured on the 26 review fixtures, 9 have no `composer.json`, and on
three of them a mistyped layer — an error on a whole-project run — became a
clean exit 0 once `architecture.unreachable-layer` joined the family.

Neither case left a trace a machine could read. The console's warning about
uncovered autoload targets is a stderr line, so under `-q` or `--format=json`
a narrowed run that did not judge those channels read exactly like a run
where they had nothing to report.

## Decision

**1. Three states, not two.** `ProjectScopeMeasurement::state()` answers
`Covered` (the paths contain every declared target), `Narrowed` (some declared
target lies outside them) or `Unknown` (the manifest is missing, does not
parse, or declares no readable production autoload). The boolean the channels
read is `state !== Narrowed`: without a manifest the project is what the user
named, so the paths cover it and the channels judge them, as they did before
the gate grew its second meaning. Only a run narrowed below a declared project
is withheld.

**2. The report names the state in every format with a place for it.**
`Reporting\ReportProjectScope` carries the state, the uncovered targets, the
channels not judged and the configured values not judged: on a narrowed run
every whole-project channel and an empty value list, since it judges none, on a `covered` or `unknown` run
the suppression values the run skipped and the channels those values belong
to (see Consequences). `json`, `metrics` and `suppressed` publish it under a
top-level `projectScope` key of one shape in every state; `sarif`, `github`,
`html` and the human formats add an entry whenever it says something — every
state but a `covered` run that skipped no value. `gitlab` and `checkstyle` omit it: their consumers
count every entry as a finding, and narrowing a run is the caller's choice, so
— unlike a drill-down selection, which those formats refuse — the run is not
refused either.

**3. The list of withheld channels is declared, and held to its readers.** The
readers are silent when they are silent and report nothing about it, so
nothing derives the list. `ProjectScopeCoverage::WHOLE_PROJECT_CHANNELS` spells
it, and `ProjectScopeReadersTest` sweeps `src/` for every file naming the
predicate: each is either a reader whose channels are listed or a carrier, and
a file that is neither fails the test by name.

## Consequences

- A manifest-less project is judged by all eight channels again, the
  suppression channels on their path values only (next item). A run over
  part of such a project is judged as the whole of it: `qmx check src/Web`
  reports every layer whose classes lie elsewhere, and the report reads
  `unknown` to say why. The remedy is to run such a project over all of its
  code, or to give it a `composer.json` with `autoload`.
- On such a project the suppression channels judge path values only. A path
  value has an on-disk anchor, so `suppress_paths: [{subtree: tests/Legacy}]`
  is spared on `qmx check src` and a miss under `src/` is reported. A namespace
  value has no location without a declared autoload: `suppress_namespaces:
  [{subtree: Tests}]` on `qmx check src` may name code in a directory the run
  never read, and reporting it as matching nothing was a guess that failed a
  `--fail-on=warning` pipeline over correct configuration. So no namespace
  value — global `suppress_namespaces`, per-rule `suppress_namespaces` or
  `suppress_namespace_channels` — is judged on an `unknown` run. The price is
  the whole-tree run of such a project, where every namespace was in reach and
  a miss would have been a fact; it is silent too, because the rule is one
  answer per state rather than a second predicate about the paths. The remedy
  is the one above: a `composer.json` with `autoload`.
- A skipped value is named, and the channels are derived from the values. The
  suppression audit that judges each value lists every one it skipped — any
  namespace value on an `unknown` run, a path value the run's paths do not
  reach on a `covered` one — and the report publishes them as
  `unjudgedValues`, `{option, pattern}` each, with `unjudgedChannels` the
  distinct channels of those values. A fixed list of channels per state would
  name a channel none of whose values was skipped, and would say nothing on a
  `covered` run that spared a value: `suppress_paths: [tests/Legacy]` on
  `qmx check src/`, with `tests/` declared only for development, read exactly
  like a value judged and bound. One enumeration yields both the findings and
  the skipped values, so the two cannot disagree about which values exist.
- The narrowed list names every channel of the family, enabled on the run or
  not; it says which channels this kind of run cannot judge, not which ones
  would have fired.
- A new reader of the predicate is a registration in two places — the
  constant and the test's reader map — and the test refuses a missing one.

## Rejected alternatives

- **An enum on `RunConfiguration` and `AnalysisContext`.** It would carry
  the state to every rule, but no rule needs more than whether it may speak,
  and it changes a constructor that over thirty test and production sites
  across other capabilities build by name.
- **A channel-declaration flag** marking a channel as whole-project, from
  which the list would be derived. It is the better derivation, and it needs
  every producer of the family to declare it, which is five owners for one
  list; the reader sweep holds the declared list to the code at a fraction of
  that.
- **Keeping `Unknown` silent and only publishing it.** A published silence is
  still a silence: the three fixtures would still exit 0 over a typo, now with
  a key saying why.
