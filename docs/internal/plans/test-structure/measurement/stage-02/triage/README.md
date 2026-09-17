# Triage of what the audit never examined

`census-completeness.md` established that the plan's 81-path census covers 81 of
688 test files, and that two real controls sat in the untouched 607. This
directory is the pass over the part of that remainder a calibrated instrument
can name.

## The instrument, and what its recall does and does not prove

The signature witness of `census-completeness.md` was reduced to the smallest
subset of signals that still accuses **every** control already known to be one:
a filesystem walk, a tracked artifact, a pinned `private const` list, or a
control-shaped class name. Dropping the other three signals — the `src/`
literal, the project-root helper, the generated-artifact mention — costs nothing
in recall and cuts the list from 327 files to 100.

Recall is **42/42** over the calibration set: the 40 files the audit ruled
`repo-control` plus the two found by hand. That is what makes the 100 a work
list rather than a sample. It is *not* a completeness proof: recall above the
calibration set is unmeasured, and a control unlike all 42 would be missed by
the instrument exactly as it was missed by the audit. No tracked guard is
written for "is this file a control" — a guard that models that question instead
of measuring it is the failure this campaign exists to remove.

Three agents classified the 100 by the same one-question criterion, each seeing
only its own third.

## Result: 19 files carry control substance

| batch | files | repo-control | mixed | product-test | tooling-test |
| ----- | ----- | ------------ | ----- | ------------ | ------------ |
| 1     | 34    | 8            | 2     | 23           | 1            |
| 2     | 34    | 3            | 4     | 27           | —            |
| 3     | 32    | 1            | 1     | 30           | —            |
| total | 100   | **12**       | **7** | 80           | 1            |

The population the stage must relocate therefore grows from 57 files to **76**:
52 whole controls and 24 mixed.

Two independent confirmations that the criterion is being applied reproducibly
rather than to taste: batch 1 re-found `ChannelUniverseCoverageTest` and
`ChannelLevelDeclarationDriftTest` without being told they were already known,
and batch 3's low yield survived a spot-check — it drew the console-command
functional tests, where `AnalysisCoverageTest` and
`TranslatedRefusalVocabularyTest` are representative-input tests by their own
docblocks.

Batch 3 is also the one that argued *against* two plausible candidates, and its
reasons hold: `ClasslessProducerOptionOwnerTest` hard-codes seven producer names
and says in its docblock that reading them from the product would make the test
tautological, and `SharedRuleOptionsContainerTest` builds its own ten-class
container rather than the production one. Neither is obliged to change when the
population grows, so neither is a census.

## What this does to the packages

`Channel` gains four — `ChannelJudgedMetricDriftTest`,
`ChannelLevelDeclarationDriftTest`, `ChannelUniverseCoverageTest`,
`ScopeConditionedChannelGuardTest` — and moves as sixteen files, not twelve. The
plan's co-change proof for that group was computed over the smaller set; it is
not invalidated, but it was never the whole population.

The other fifteen have no group assigned here. Group assignment is a
subject-cohesion judgement about what a directory is *about*, and it is made in
the package that owns the destination, with the skill loaded — not guessed in a
measurement artifact. Two of them (`RuleOptionWordSetDeclarationAgreementTest`,
`UnknownRuleOptionKeyRefusalTest`) belong to `RuleOptionKeys`, a group that has
already moved; adding a file to a directory that exists is the small follow-up
the population decision was taken to afford.

## Open, and deliberately not closed here

- **The 507 files below the instrument's threshold are unexamined.** The 100
  are the files it accuses; the rest were not read by anyone in this pass.
- One `DISPUTED` verdict stands: `DependencyGraphAnalyzerTest` reads the source
  of a single named class to check it depends on a contract rather than an
  internal. One class is not a population, so it was left a product test.
- `ChannelSuggestionTieTest` was marked disputed for the opposite reason: a
  hand-fixed set of channel names, but membership checked against the live
  population from the container.
