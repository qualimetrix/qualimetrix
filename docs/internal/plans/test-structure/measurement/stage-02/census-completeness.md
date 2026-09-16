# Stage 02 — the 81-path census is provably incomplete

The stage's DoD is "every `repo-control` path is out of `tests/`". That sentence
quantifies over a population, and the population is `controls-verdict.tsv`'s 81
paths — the union of two wave-1 witnesses, re-ruled. `controls-verdict-notes.md`
named this exact risk under "What this method cannot see":

> **Input completeness.** The input itself is the union of two wave-1 witnesses.
> A control both of them missed (say, a test with no file calls and a "unit"
> category) never entered these 81 paths and is not caught by this method at all.

It happened. `tests/` holds 688 test files; 81 were examined and 607 never were.

## Three found, two of them controls

Found while establishing facts for an unrelated fork, not by searching for them:

| file (all `tests/Analysis/Finding/Integration/`) | methods | reading                                                                                                                                                                                                                                                                                                                                           |
| ------------------------------------------------ | ------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| `ChannelUniverseCoverageTest`                    | 11      | **repo-control.** "Every declared channel names its producer", proved by two independent enumerations of the channel universe — the assembled container and the rule registry plus the tracked fixture — compared against each other. Its own docblock says a hand-written inventory of channel-identity mechanisms "has been wrong three times". |
| `ChannelLevelDeclarationDriftTest`               | 4       | **repo-control.** Declared levels against levels observed over the `finding-gate/cases/` corpus, with a test-owned fixture as the oracle. The same shape as `ChannelDeclarationFixtureDriftTest` and `ChannelOrderFixtureDriftTest`, both of which the TSV rules `repo-control`.                                                                  |
| `ChannelCoverageTest`                            | 12      | **product-test**, on the discriminating pair: twelve `itDeclaresTheXChannel` methods, each running one real rule against a hand-built context. It reads `excluded.txt`, but per channel, not as a census.                                                                                                                                         |

Both controls belong to `Channel` — the group the plan uses to *prove* its
co-change axis. That proof was computed over a population missing them.

## Why no cheap sweep closes the gap

Two mechanical witnesses were built over the 607 unexamined files and, before
being believed, were calibrated against the 40 files already ruled
`repo-control`. Both failed calibration.

**Witness A — signatures** (filesystem walk, project-root helper, tracked
artifact, generated artifact, pinned `private const` list, `src/` literal, plus
a name hint). Distribution of signal count over the 40 known controls:

| signals | 1   | 2   | 3   | 4   | 5   | 6   | 7   |
| ------- | --- | --- | --- | --- | --- | --- | --- |
| files   | 6   | 1   | 11  | 12  | 3   | 5   | 2   |

Six known controls score a single signal — `MetricNameVocabularyTest`,
`ChannelDeclarationFixtureDriftTest`, `ChannelOrderFixtureDriftTest`,
`LevelActivityCoversEveryDeclaredLevelTest`, `ErrorStreamContainerIdentityTest`,
`CoverageIsRequestedExplicitlyTest` — which is exactly what
`ChannelUniverseCoverageTest` scores. A threshold selective enough to be
actionable drops 15% of the known positives; a threshold that keeps them
accuses 327 of the 607.

**Witness B — a universal quantifier in the method name** (`itRequiresEvery…`,
`itFindsNo…`, `itKeepsEach…`). It fires on 25 of 40 known `repo-control` files
and on 4 of 15 known `product-test` files. It flags 106 of the 607.

Neither is an oracle. Their union is a work list, not a bound: recall is
unmeasured above the calibration set, so "the union is N" says nothing about how
many controls lie outside it.

Raw output is kept at `uncensused-suspects.tsv` and `quantifier-suspects.tsv`
in the scratch measurement, not tracked: a suspect list no measurement can close
is a claim about completeness that nothing checks.

## What this does to the stage

The moves themselves are unaffected — every path in the census is still classed
correctly, and moving it is still right. What is affected is the **DoD**, which
cannot be read as "`tests/` now holds no control". It can only be read as
"`tests/` now holds no control *the audit found*", and that is a different and
much weaker sentence.

The choice this forces is not the implementer's, because it changes the size of
the stage. It is stated for the owner in the same message as the other forks:
relocate the known population and open the re-derivation as its own work, or
re-derive the population first and move once.

Whichever is chosen, `ChannelUniverseCoverageTest` and
`ChannelLevelDeclarationDriftTest` move with `Channel` in this stage: they were
found, and leaving a control behind that is known to be one is the defect the
stage exists to remove.
