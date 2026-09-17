# Stage 03 — the base the prediction is measured against

Measured on `main` at `c49fc0b4`, in this session. Nothing here is inherited
from the stage-02 report.

## Two numbers, not one

The stage-02 commit message states "9198 expanded cases" next to per-suite
execution counts, which reads as one measurement. It is two, and they differ:

| Measurement                                            | Command                                | Value |
| ------------------------------------------------------ | -------------------------------------- | ----- |
| Inventory: cases PHPUnit *discovers* over the worktree | `composer architecture:check`          | 9198  |
| Aggregate: cases the shards *execute*                  | `python3 scripts/phpunit-aggregate.py` | 9196  |

The difference is exactly the two cases the aggregate excludes by group, and
they are named rather than inferred — `phpunit --list-tests --group=live-freshness`
returns these two and `--group=benchmark` returns none:

- `Qualimetrix\Governance\ModularOwnership\ModularArchitectureGovernanceIntegrationTest::itChecksEveryGeneratedProjectionWithoutWriting`
- `Qualimetrix\Governance\GeneratedArtifactFreshness\SuppressionSnapshotFreshnessTest::itMatchesAFreshSelfAnalysisOfSrc`

Both are Governance, so Governance discovers 750 and executes 748. A stage that
states one number and checks the other has not checked anything.

## Executed, per suite

| Suite          | Executed | Assertions |
| -------------- | -------- | ---------- |
| Unit           | 7148     | 17460      |
| Integration    | 437      | 41102      |
| Functional     | 203      | 698        |
| Infrastructure | 660      | 2551       |
| Governance     | 748      | 15486      |
| **Total**      | **9196** |            |

Unit reports `OK, but some tests were skipped!` with `Skipped: 1` — the skip is
pre-existing and is counted in the 7148.

## Discovered, by the inventory

`Checked 921 artifacts, 120 fixture directories, 725 PHPUnit classes, and 9198
expanded cases.` Plus, from the same command: 955 declarations, 37
semantic-owner layers, 0 seams, 73 exact internal grants, 13 coarse edges.

## The invariant this stage is checked by

Relocation creates no cases. Both totals must be unchanged at the end: **9198
discovered and 9196 executed**. Per-suite counts move; the totals do not. A
per-suite prediction that sums to something else is a prediction error, not a
new fact about the tree.

## What a green suite run does not prove — measured here, not assumed

`CLAUDE.md` claims that a `<directory>` "naming a path that no longer exists —
PHPUnit warns, exits 0, and hands back an **empty suite**", and marks it as
failing **silently**. That is false for the PHPUnit this tree pins (12.5.25).
Probed four ways against an isolated copy of `phpunit.xml.dist`, with one
`<directory>` pointed at an absent path:

| Probe                                             | Result                                                     |
| ------------------------------------------------- | ---------------------------------------------------------- |
| one absent directory among many, `--list-tests`   | `Test directory "…" not found`, **exit 2**, nothing listed |
| one absent directory among many, run              | same, **exit 2**                                           |
| the suite's only directory absent, `--list-tests` | same, **exit 2**                                           |
| the suite's only directory absent, run            | same, **exit 2**                                           |

`scripts/phpunit-aggregate.py` then refuses (`cannot list tests for aggregate:
PHPUnit exited 2`), `composer architecture:check` fails, and G2
(`TestFilesAreExecutedTest`) reports 6 errors. Four independent refusals.

**So the DoD item "every declared `<directory>` exists" needs no new control** —
PHPUnit itself is the loudest one. The stage still has to remove a stale
`<directory>`, but because it would be a *lie about the suite map*, not because
it would be silent.

A directory that exists and holds no test files is a different case and **is**
silent: declaring an empty `tests/Vanished/Unit` left Unit at exactly 7148,
exit 0. That is the shape this stage can actually produce, and it is caught by
`assertSuiteClassifierAgreesWithPhpunit()` only in the sense that the
`<directory>`/`testSuitePrefixTable()` pair must stay symmetric — neither side
checks that the pair still describes a directory holding tests.

**But that silence does not survive a clone, which makes removing a stale
`<directory>` mandatory rather than tidy.** Git tracks no empty directory. A
directory this stage empties still exists on the author's disk — so the local
run is the silent, green, exit-0 case — and does not exist in a fresh checkout,
where the identical tree hits the *absent* case and PHPUnit exits 2. Local green
and CI red would be the same commit. Raised by the plan review; the two halves
of it are each measured above.

**A separate silent-green mechanism, found by accident and not this stage's:**
an unreadable `bootstrap` gives `Cannot open bootstrap script "…"` and
**exit 0**, running nothing. Reached by pointing `--configuration` at a copy of
`phpunit.xml.dist` outside the project root, so that its relative `bootstrap`
no longer resolved. Recorded because it is the shape `CLAUDE.md` attributes to
the missing-directory case, and it is real — just at a different address.

## `directives:controls:coverage` is already red, so its exit code is not an oracle

Measured on the pristine tree:

```
120 probes, 0 not as declared, 2 cases guarded by nothing.
  guarded by nothing: Qualimetrix.Tests.Infrastructure.Console.Functional.DirectivesCommandTest::itAnswersExitOneForAnUnrecognisedExceptionFromAnUnreadablePath
  guarded by nothing: Qualimetrix.Tests.Infrastructure.Console.Functional.DirectivesCommandTest::itRefusesANonExistentPath
EXIT=1
```

This control is **not** part of `composer check` (`check:self` is
`gate:self-test`, `selfcheck:analysis`, `directives:audit`), which is why a red
one has survived on `main`.

It matters here because `scripts/directive-audit-controls/Probes.php` pins the
three moving RuleVocabulary tests by name — 119 dot-separated `FQN::method`
literals out of the file's 363 (16 `DirectiveAuditGateTest`, 38
`DirectiveAuditReportReadingTest`, 65 `ThresholdPopulationAgreementTest`). Those
literals **are** individually machine-checked. Demonstrated by planting one
bogus method name and reverting:

```
stale declaration: fixture-grows-an-unnamed-form names
"…ThresholdPopulationAgreementTest::itNamesEveryFormTheFixtureDeclaresXYZ",
which no case in this run carries
120 probes, 1 not as declared, 3 cases guarded by nothing.
EXIT=1
```

**Exit 1 before and exit 1 after.** The oracle is therefore the text, not the
code: `0 not as declared`, no `stale declaration:` line, and exactly the two
pre-existing `guarded by nothing` rows. A package that reports "the control
exits 1, same as before" has reported nothing.
