# Stage 04 — P4: the buckets are retired and the allowance closed

Base `ae65dc9d`. Three files changed: the generator, `test-ownership.tsv`,
`test-fixture-directories.tsv`. No test file moves, nothing in
`phpunit.xml.dist`, nothing in the governance integration test.

## The measurement everything else rests on

"Which branch is dead" is answered by a **labelled replica** of the generator's
three path ladders — `classifyOwner()`, `dispositionFor()`, `targetPath()` — with
one entry per branch *and per disjunct of a branch*, evaluated in ladder order
over the generator's own population, recording which entry each path reaches
first.

The replica is only evidence because it is self-validating: for all **921** rows
its owner, closure package, disposition-arm and target-arm answers were compared
against the generated `test-ownership.tsv` cell by cell. **0 mismatches, 0 paths
unmatched.** A replica that agreed with the artifact by construction would prove
nothing; this one is written from the source text and checked against the output.

Population identity was checked first: `git ls-files` over the generator's own
pathspec and the TSV's `current_path` column are the same 921-member set.

It was also run over the population at `e15c7f42`, and that run is **reported as
inconclusive rather than cited as evidence.** It applies today's ladder to
yesterday's tree, and today's ladder begins with the parse, so every branch a
`*Test.php` path could reach reads 0 hits at both revisions whatever the moves
did. A zero that cannot be non-zero is a statement, not a measurement. Nothing
below rests on it: the epoch question is answered instead by counting population
members under a root at the two revisions (`grep -c` on the two `current_path`
columns), which is what the Rule B table and the not-pruned section do.

Both runs and the probe script are session scratch and deliberately not tracked —
the derivation's *result* is below; the script is a measuring instrument, not a
control, and this stage already has one tracked judge too many written twice.

## 1. The allowance closes

**Asserted empty before deletion**, statically, on the declaration itself:

```
php -r '$s=file_get_contents("scripts/generate-modular-architecture-test-inventory.php");
        preg_match("/const LEGACY_UNMOVED = (.*?);/s",$s,$m); exit($m[1]==="[]"?0:1);'
```
→ body `[]`, exit **0**. P3 left the constant empty and its guard already deleted.

Deleted: the constant with its docblock, and its three `array_key_exists()` call
sites in `classifyOwner()`, `dispositionFor()` and `targetPath()`. The
`classifyOwner()` comment that counted "the two branches below" — the rule and
its transitional exception — was rewritten, because there is now one branch; a
comment that counts is a comment that rots.

`failUnownedTestClass()`'s refusal no longer ends "…or record the move in
`LEGACY_UNMOVED`". A refusal that offers a reader a constant that does not exist
is worse than one that offers nothing.

**Checkpoint.** Regenerate → `git diff docs/internal/generated/modular-architecture/`
**empty**. An empty allowance cannot change an answer, and the artifacts say so.

## 2. The pruning, and the derivation that licenses it

Two rules, both stated before any deletion. Nothing was pruned that only the
population census called dead: a census alone cannot distinguish "this stage
emptied it" from "an earlier epoch did", and pruning the second kind is
discarding someone else's history.

**Rule A — structural.** `isTestClassPath()` precedes the whole ladder in all
three functions. A branch whose predicate can *only* match `tests/**/*Test.php`
is therefore unreachable by construction, not merely by census. Members, each
verified to contain no non-`Test.php` literal:

| Pruned                            | Literals | Note                                                          |
| --------------------------------- | -------: | ------------------------------------------------------------- |
| `P6_D_GIT_TEST_PATHS`             | 2        | constant + 3 consumers + `assertPathLiteralsResolve()` row    |
| `P6_D_REPORTING_TEST_PATHS`       | 1        | constant + 3 consumers + `assertPathLiteralsResolve()` row    |
| `P6_D_PRIORITIZATION_TEST_PATHS`  | 5 of 6   | `*Test.php` half only; the Support entry is **live** (1 hit)  |
| `P7_MEASUREMENT_PATHS`            | 6 of 16  | `*Test.php` half only; the 10 fixtures are **live** (10 hits) |
| `$p2DependencyModelTests`         | 8        | local list + its branch                                       |
| `$p2GraphProjectionTests`         | 5        | local list + its branch                                       |
| the `GraphExportCommandTest` pair | 2        | inline list + its branch                                      |
| `SourceControlsTest` equality     | 1        | branch                                                        |
| `DependencyResolver` trio         | 3        | inline list + its branch                                      |

The two trimmed constants keep a live consumer, so their docblocks were rewritten
rather than left asserting a "closed set" that is now a fixture/support set.

*Removed literals were **not** moved to `RETIRED_PATH_ASSERTIONS`.* That constant
asserts a path is **absent**; all eleven trimmed files exist. Adding them there
would redden the generator immediately — the opposite of a record.

A second argument for trimming the two surviving constants, beyond deadness:
they claimed those six and five test classes for closure packages `P7` and `P6-D`,
while the artifact has said `permanent` for them since P0 made a conforming test
class permanent. The file now agrees with its own output.

**Rule B — measured, and measured as this stage's doing.** A literal naming one
of the three retired bucket roots. The discriminator is the population at the two
revisions:

| Root                                     | population @ `e15c7f42` | population @ `ae65dc9d` |
| ---------------------------------------- | ----------------------: | ----------------------: |
| `tests/Unit/`                            | 76                      | **0**                   |
| `tests/Integration/`                     | 6                       | **0**                   |
| `tests/Functional/`                      | 6                       | **0**                   |
| — of which `tests/Unit/Reporting/`       | 41                      | **0**                   |
| — of which `tests/Functional/Reporting/` | 2                       | **0**                   |

88 members moved out during P1–P3, the directories are gone, and P2/P3 removed
their `<directory>` declarations. Every such disjunct was deleted; a branch left
with no disjunct was deleted whole; a **mixed** condition lost only its bucket
disjuncts. Eight mixed conditions were treated this way, keeping
`ThresholdAnnotationParser`, `ThresholdValidatorWiring`, `ComputedMetric(s)`,
`HealthFormula`, `AnalysisContextThreshold`, `ThresholdOverride`,
`ChannelDeclaration`, `Wmc`, `ThresholdValidatorAssignment`, `CoverageProjection`,
`JsonShapePreservation` and the live `tests/Infrastructure/` prefix — substring
claims about inputs are not bucket-root addresses and no measurement here says
anything about them.

**Counts.** `git grep -E 'tests/(Unit|Integration|Functional)/'` over the
generator: **43 lines before, 1 after.** The 42 deleted lines are exactly the
deletions in the diff (`git diff -U0 | grep '^-' | grep -cE …` → **42**). The
one that stays is the `RETIRED_PATH_ASSERTIONS` key
`tests/Unit/Infrastructure/Logging/LoggerFactoryTest.php`, whose contract is that
the path must **not** exist — deleting it would delete a live assertion.

**Why removing a dead branch cannot change an answer, stated rather than hoped:**
for every path in the population the first matching branch is, by the census,
never one of the pruned ones; deleting non-first matches leaves every first match
where it was. The checkpoint is the proof, not the argument.

**Checkpoint.** Regenerate → `git diff docs/internal/generated/modular-architecture/`
**empty** again. `php -l` clean, PHPStan level 8 on the file **exit 0** (no
orphaned helper, no now-unused `$matches`), `php-cs-fixer --dry-run` **exit 0**.

### What the pruning narrows, said in the docblock rather than left to be found

`assertPathLiteralsResolve()`'s docblock declared the classifier literals to be
claims about **inputs**, including pre-migration paths handed to
`--classification-probe=`, which is why they sit outside that check. That is
still true and still the reason; what changed is the reach, and the docblock now
says so: the classifier answers for the tree the repository has, and one epoch
back is the last epoch it still answers for.

Measured, against a copy of the pre-P4 generator, on both shapes of probe:

| `--classification-probe=`                              | before P4                                                     | after P4                              |
| ------------------------------------------------------ | ------------------------------------------------------------- | ------------------------------------- |
| `tests/Unit/Reporting/Formatter/JsonFormatterTest.php` | refusal, "no segment before its level segment"                | **identical refusal**                 |
| `tests/Unit/Reporting/Fixtures/Sample.php`             | `Reporting  permanent  none  tests/Reporting/none/Sample.php` | `Unclassified test artifact` (exit 1) |

So the narrowing reaches **only non-`Test.php` inputs** under a retired root: a
test class there was already refused by the parse, which precedes everything
pruned. And the answer it replaces was junk — a target with a literal `none`
segment for a suite the path does not name. A refusal is strictly better than
that.

The docblock also dropped `dispositionFor()` from its list of functions carrying
classifier literals, because after item 4 it carries none.

## 3. `P6_LIVE_ADDED_TEST_IDS` and `P6_RENAMED_TEST_IDS` — stated, not deleted

Both are **read by nothing**: `git grep` finds each name only at its own
declaration. Nothing validates their literals, which is why they rot silently.
Deleting a record of the closed migration epoch is the owner's decision, so both
stay exactly as they are.

What each records, and which half has rotted — every FQCN and every method name
resolved against the tree:

- **`P6_LIVE_ADDED_TEST_IDS`** (6 rows) — test IDs that appeared after the
  accepted 509/7,245 authority. **All 6 resolve**: class and method both exist.
- **`P6_RENAMED_TEST_IDS`** (4 rows, old ID => new ID) — zero-net method-ID
  replacements. The **key** half is a pre-rename name, so three keys naming a
  class that no longer exists is the constant working, not rotting. The **value**
  half is supposed to be a current name, and **row 3's value half is stale in
  both its segments**:
  `Qualimetrix\Tests\Infrastructure\Integration\RuleExclusionStatsWiringTest::itSharesTheSameRuleExecutionInstanceBetweenThePipelineAndTheOrchestrator`.
  The class is today
  `Qualimetrix\Tests\Infrastructure\Console\Integration\RuleExclusionStatsWiringTest`,
  and the file has no method of that name under any spelling (its three are
  `itSurfacesPerRuleExclusionStatsFromARealRunThroughTheContainer`,
  `itKeysThePerRuleBreakdownByTheProducerOfTheFindingNotTheRuleThatRan`,
  `itOmitsPerRuleExclusionDetailsWithoutShowSuppressed`). This is sharper than
  the plan's statement, which named only the namespace. Rows 1, 2 and 4 resolve.

Not created here: the file has stood at `tests/Infrastructure/Console/Integration/`
since before this stage — `3213b905` (P1) touched it with a **1-line docblock
repair**, not a rename, and the map check in DoD 1 independently proves no
unmapped rename happened across the stage.

## 4. The disposition is derived from the target

`dispositionFor()` decided from a prefix list; `targetPath()` decided from a
different one. Two decisions, one question — "is this artifact where it belongs" —
so they were free to disagree, and did. The cure:

```
dispositionFor(path, kind, target):
    orphan candidate  -> its P8 reason      // not that question
    placeholder       -> its P8 sentence    // target is DELETE, not a path
    target === path   -> Retain
    otherwise         -> Move atomically
```

and the loop computes `targetPath()` first and passes it in. The whole retain
prefix list is gone: it is subsumed, and measurably so — **0** rows say "Retain"
against a target different from their path, in either direction, before or after.

Two arms deliberately stay **above** the derivation. An orphan candidate whose
target happens to equal its path would otherwise silently lose the reason it is a
candidate; a placeholder's target is the sentinel `DELETE`, which is not a path it
could be retained at, so pure derivation would call it a move. Both arms have 0
hits today, so neither changes an answer — they are there so that the next row of
that shape does not.

### The census

`test-ownership.tsv` before vs after, joined on `current_path`:

| Quantity                                                       | Value                                                  |
| -------------------------------------------------------------- | -----------------------------------------------------: |
| rows                                                           | 921                                                    |
| rows whose `disposition` changed                               | **63**                                                 |
| of those, rows whose `target_path` equals `current_path`       | **63**                                                 |
| rows that changed disposition for any other reason             | **0**                                                  |
| cells changed in any column other than `disposition`, anywhere | **0**                                                  |
| directions of change observed                                  | 1 — `Move atomically…` → `Retain at the materialized…` |

The two totals are the same number, which is the claim. Every flip is a row whose
recorded target was already its own path.

The 63 are 4 Configuration fixtures, 1 CircularDependency support class, 52
Architecture fixtures, 4 Architecture support classes, `tests/Reporting/Support/StubChannelPresentation.php`
and `tests/TestSupport/Logging/Support/RecordingLogger.php`.

**Against the brief: one moved support class flips, not two.**
`tests/Analysis/Finding/Support/FromArrayKeyReader.php` (P3's) already read
"Retain", because it falls under the `tests/Analysis/Finding/` prefix the old
list happened to carry; only its `closure_package` was off, which is what P3's
D5 actually recorded. `tests/Reporting/Support/StubChannelPresentation.php`
(P2's) is the one that flips — P2's `tests/Reporting/` arm had no retain case.
The brief's "two moved support classes … still answers Move atomically" is half
right, and the half that is wrong is a row that was already correct.

**Second artifact, explained rather than noticed.**
`test-fixture-directories.tsv`: **120 rows, 68 changed, all in `disposition`,
all `Move atomically with the owning subject.` → `Retain at the materialized
subject-owned path.`, no row added or dropped.** `fixtureDirectoryRows()`
computes a directory's `retained` flag by conjunction over its member files'
dispositions, so it is the same 63 flips aggregated upward. Its comment, which
pointed at "dispositionFor()'s own retain arm", now says what that arm is.

## 5. Artifacts refreshed; the two hardcoded counts verified, not re-derived

`governance/ModularOwnership/ModularArchitectureGovernanceIntegrationTest.php`
is **untouched** — `git diff` on it is empty — and its two literals still read
`assertCount(28, …)` and `assertCount(1, …)`. Measured independently:
`test-orphan-dispositions.tsv` has **28** data rows and
`test-system-support-owners.tsv` has **1**, and `git diff` on both artifacts is
empty. Neither moved, so neither number was touched.

## Decisions, with the alternative rejected

| Decision                                                                         | Rejected                                                                                                                         | Verified by                                                           |
| -------------------------------------------------------------------------------- | -------------------------------------------------------------------------------------------------------------------------------- | --------------------------------------------------------------------- |
| Prune by two named rules (structural + this-stage-measured), not by census alone | Prune every zero-hit branch — it would sweep in ~30 branches dead since earlier epochs, which is discarding history, not closure | the `e15c7f42` column of the Rule B table; the survivors list below   |
| Delete `$p2*` local lists whole rather than trimming their bucket entries        | Trim only — leaves a branch of live addresses that the parse shadows forever, dead code PHPStan cannot see                       | every literal in the three lists is `tests/**/*Test.php`              |
| Trim, not delete, `P7_MEASUREMENT_PATHS` and `P6_D_PRIORITIZATION_TEST_PATHS`    | Delete whole — their fixture/support entries are live (10 and 1 first-hits)                                                      | replica hit counts; artifacts byte-identical after the trim           |
| Removed literals do not go to `RETIRED_PATH_ASSERTIONS`                          | "Record them as retired" — that constant asserts absence and the files exist; it would fail on the next run                      | `assertPathLiteralsResolve()`'s second loop                           |
| Delete the retain prefix list in `dispositionFor()` entirely                     | Keep it ahead of the derivation — measured 0 rows where the two disagree, so it is pure duplication of the target decision       | census: 0 cells changed outside `disposition`                         |
| Keep the orphan and placeholder arms ahead of the derivation                     | Pure `target === path` — a placeholder would read "Move" toward `DELETE`, an orphan would lose its reason                        | both arms have 0 hits, so the artifacts are unaffected either way     |
| Leave `targetPath()`'s retain list alone                                         | Collapse it too — it is what makes `target === path` true, so collapsing it would be circular                                    | —                                                                     |
| Three checkpoints (allowance / pruning / derivation) instead of one edit         | One edit and one regeneration — then "the 63 flips" could not be separated from a pruning mistake                                | two byte-identical regenerations before the only one that moves cells |

## What was measured dead and deliberately **not** pruned

All have 0 first-hits at HEAD. Reported for whoever owns them, not acted on.
They divide by *why* they are out of scope, and only the first group carries an
epoch claim — the one kind of claim the population counts can actually support.

**Group 1 — an earlier epoch emptied the root, measured the same way Rule B is.**
Population members under each root, `e15c7f42` → `ae65dc9d`:

```
tests/Architecture/                        0 -> 0    tests/Fixtures/CouplingProject/   0 -> 0
tests/Support/                             0 -> 0    tests/Fixtures/GoldenMetrics/     0 -> 0
tests/Fixture/                             0 -> 0    tests/Fixtures/Aggregation/       0 -> 0
tests/Fixtures/BaselineV10/                0 -> 0    tests/Fixtures/Inheritance/       0 -> 0
tests/Fixtures/Channels/                   0 -> 0    scripts/tests/                    0 -> 0
tests/Fixtures/CircularDeps/               0 -> 0    *.gitkeep                         0 -> 0
tests/Fixtures/AnonymousClassContext.php   0 -> 0
```

Empty *before* the stage began, so this stage did not empty them and Rule B does
not reach them.

**Group 2 — outside both rules, no epoch claim made.** The directory exists but
holds only test classes, so the parse shadows the branch permanently:
`tests/Analysis/Evidence/Duplication/Unit/`,
`tests/Analysis/Evidence/DependencyModel/`, `tests/Core/`,
`#^tests/Core/(Path|Symbol|Profiler)/#`, `tests/Analysis/Evidence/ComputedMetrics/`,
the 8-subject `tests/Analysis/Evidence/…` regex, every surviving `str_contains()`
substring disjunct, and the `disp:`/`tgt:` arms for the 8-subject regex and
`tests/Infrastructure/Logging/Unit/`, plus the orphan and placeholder arms.
Rule A does not reach them (a prefix can match a non-test file) and Rule B does
not (they name no bucket root). Whether they should go is a question this
package's measurement does not answer.
- **`#^tests/Infrastructure/(Ast|Cache|Console|DependencyInjection|Logging|Parallel|Profiler|Rule|Serializer)/#`
  is dead by shadowing, and that is a finding, not just deadness.**
  **[Superseded by the follow-up below: the shadowing branch is deleted, so this
  regex is live with 4 first-hits and no longer belongs in this list.]** The broad
  `tests/Infrastructure/` branch precedes it and answers first, so the four
  `tests/Infrastructure/Console/Support/*.php` files are owned by the coarse
  `Infrastructure` where the finer branch would say `Infrastructure/Console`.
  **And `Infrastructure` is not one of the 37 manifest owners** — measured:
  `--classification-probe=tests/Infrastructure/Unit/ProbeTest.php` is refused with
  `"Infrastructure" is a taxonomy above its owners, not an owner`, while
  `tests/Infrastructure/Console/Unit/ProbeTest.php` resolves to
  `Infrastructure/Console`. So those four rows publish a `subject_owner` the
  generator's own parse would refuse for a test class at the same path.
  Pre-existing (the broad branch preceded the finer one before this stage too)
  and outside P4's named scope, so untouched — but it is a wrong value, not
  merely a coarse one.
  **[Superseded by the follow-up below, which deletes the shadowing branch; these
  four rows now read owner `Infrastructure/Console`, target equal to their own
  path, and disposition Retain.]**

## Assumptions

- **A1.** The replica is a faithful model of the ladders. Not assumed —
  validated cell by cell against the artifact on all 921 rows, 0 mismatches. A
  transcription slip would have shown as a mismatch, not as a wrong hit count.
- **A2.** The generator's population is the 921 rows of `test-ownership.tsv`.
  Checked: the `git ls-files` pathspec result and the TSV's `current_path` column
  are the same set.
- **A3.** The `e15c7f42` column of the Rule B table is the population at that
  revision, read from that revision's own artifact, not re-derived.
- **A4.** `dispositionFor()` has no consumer outside this file.
  `git grep dispositionFor` returns the generator only.

## Deviations from the brief

**V1 — the brief's item 4 predicts two support-row flips; there is one.**
Reported in §4 rather than forced into two. The other row was already correct.

**V2 — Definition of Done item 1 was replaced by the coordinator mid-package.**
The brief's three `move-oracle.py --package=P{1,2,3} --base=ae65dc9d` runs cannot
pass at this base: the judge reads each row's pre-move class with
`git show <base>:<current>`, and at `ae65dc9d` no pre-move path exists any more.
The replacement, the whole-stage tree-against-map check, is executed below.

**V3 — the pruning is larger than "43 literals" implies.** 42 bucket lines plus
nine Rule A branches and constants, 211 source lines removed in all. Each is
named in §2 and each is covered by one of the two rules; none is a branch the
census alone called dead.

## Definition of Done

| #   | Item                                                                                                                         | Command                                                                                                                            | Exit                                    |
| --- | ---------------------------------------------------------------------------------------------------------------------------- | ---------------------------------------------------------------------------------------------------------------------------------- | --------------------------------------: |
| 1   | **The tree against the map, both directions** (replacing the three per-package judge runs, per the coordinator's correction) | `git diff --name-status -M 46c3deca HEAD \| awk '$1~/^R/{print $2"\t"$3}' \| sort` vs the map's `current`/`target` columns, `diff` | **0**                                   |
| 1b  | directory half: every `<directory>` in `phpunit.xml.dist` has ≥1 file in `git ls-files`                                      | python sweep, 78 entries                                                                                                           | **0**                                   |
| 2   | `LEGACY_UNMOVED` gone by name                                                                                                | `git grep -n LEGACY_UNMOVED`                                                                                                       | plans/reports only                      |
| 3   | the disposition census, two totals equal                                                                                     | TSV join, above                                                                                                                    | 63 = 63                                 |
| 4   | `vendor/bin/phpunit --testsuite=Governance --no-coverage --filter=ModularArchitectureGovernanceIntegrationTest`              |                                                                                                                                    | **0**                                   |
| 5   | six per-suite counts unchanged                                                                                               | from the aggregate                                                                                                                 | **6705 / 383 / 152 / 1029 / 179 / 748** |
| 6   | `composer check`                                                                                                             | full aggregate, once                                                                                                               | **0**                                   |
| 7   | `git status --porcelain`                                                                                                     |                                                                                                                                    | 3 intended files                        |

**Item 1.** `renames.tsv` **114** lines, `map.tsv` **114** lines, `diff` empty and
exit **0**: every map row was executed and git records no rename the map does not
name. Two things this does and does not say. It compares `46c3deca..HEAD`, the
**whole stage**, not this package — **P4's own contribution to it is zero**,
because P4 moves no test file, and that is exactly why the check can be run here
at all. And git detects renames by content similarity, so a heavily edited moved
file could in principle appear as delete-plus-add; the diff is empty, so the
question does not arise, but a non-empty diff would have to be checked for that
before concluding a row was skipped.

**Item 1b.** 78 `<directory>` entries, 78 distinct, **0** with no tracked file
beneath them. This is the half the rename diff is silent about: a fresh clone of
this commit hands PHPUnit no path that is not there.

**Item 2.** Remaining occurrences: `04-packages.md` (9), the stage-04 review
notes (9), the P0–P3 reports (22), and **`measurement/stage-04/move-oracle.py`
(4)** — the judge, which the brief forbids this package to touch. Its allowance
arm is now permanently satisfied for every package; that it still mentions the
constant is a plan artifact, not a live reference.

**Item 4.** `OK (4 tests, 785 assertions)`, exit **0**. Run by hand because the
test is `#[Group('live-freshness')]` and `scripts/phpunit-aggregate.py` excludes
that group, so `composer check` is silent about exactly what this package edits.
`composer architecture:check` separately exit **0**:
`955 declarations, 37 semantic-owner layers, 0 seams, 73 exact internal grants -> 13 coarse edges`
and `921 artifacts, 120 fixture directories, 725 PHPUnit classes, 9198 expanded cases`.

**Item 6.** `composer check` exit **0**, run twice: once after the last source
edit, and again after this report landed, so the sentence "run after the last
edit" is literally true rather than nearly true. The second run is the one cited;
both were green and both printed the same six suite counts.

## Findings for whoever comes next

- **The pre-existing `Infrastructure` vs `Infrastructure/Console` owner
  disagreement** for the four `tests/Infrastructure/Console/Support/*` files
  (see above). Two branches answer differently and the coarser one wins by order.
- **63 rows now read "Retain" beside a `closure_package` that names a package
  (`P4`, `P8`, `P3`, `P6`, `P7`).**
  **[Superseded by the follow-up below: 67, once the four Infrastructure/Console
  support rows join them.]** Closure package means "which package still
  owes this artifact a move", so a retained row owing one is the same
  contradiction P3's D5 recorded for a single file, now visible on 63. P0 derived
  that column for test classes only; for fixtures and support it is still
  whatever the ladder returns. Not fixed here: the brief's item 4 is about the
  disposition column, and changing a second column would have made the census
  unreadable as a check.
- **`P6_D_PRIORITIZATION_TEST_PATHS` now holds one Support file and no test
  paths, so its name lies about its contents.** Renaming touches four sites and
  is defensible either way; left alone because renaming a constant is not in this
  package's scope, and named here so the next reader does not rediscover it.
- **Ladder deadness is invisible to PHPStan in this file.** Nothing
  flagged any of the branches pruned here; the only thing PHPStan caught in this
  whole area was P3's empty-array guard. A ladder branch that can never fire is
  well-typed. The replica is the only instrument that saw them, and it is not
  tracked — whether that should change is the owner's call, and it is the third
  time this stage has raised it.

---

# Follow-up — the shadowed Infrastructure branch (post-`2edb8d33`)

Requested after P4 landed, from P4's own finding. Branch
`x30-stage-04-subject-layout`, base `2edb8d33`. Scope kept to
`scripts/generate-modular-architecture-test-inventory.php` and the regenerated
artifacts. Not committed.

## The defect, restated as measured

Exactly **4** rows carried `subject_owner: Infrastructure`:

```
tests/Infrastructure/Console/Support/PseudoTerminalRun.php
tests/Infrastructure/Console/Support/RestoresShellVerbosityEnvironment.php
tests/Infrastructure/Console/Support/SplitStreamConsoleOutput.php
tests/Infrastructure/Console/Support/TerminalScreen.php
```

`Infrastructure` is not one of the 37 manifest owners — the generator refuses
that exact string by name:
`--classification-probe=tests/Infrastructure/Unit/ProbeTest.php` →
`"Infrastructure" is a taxonomy above its owners, not an owner`. Each row also
recorded `target_path: tests/Infrastructure/Support/<basename>`, a relocation to
a root that is not an owner either, and a disposition of `Move atomically…` to
carry it out. Wrong owner, wrong target, and a move the stage's invariant
forbids.

## The cure, and the shape chosen

The broad `tests/Infrastructure/` branch sat **above** the nine-subject regex and
answered first. The branch is **deleted**, not reordered below it.

- After the regex answers first, the broad branch is reachable only by a
  `tests/Infrastructure/{Subject}/…` path whose subject is not among the nine, or
  by a file directly under `tests/Infrastructure/`. Measured: **0** population
  members. So it is dead, and what it publishes when it does fire is the
  non-owner string plus a target under a non-owner root — a wrong answer waiting
  for the next Infrastructure subject that grows a support file.
- Deleting it sends such a path to the ladder's existing
  `fail('Unclassified test artifact: …')`. That is an ownership decision asked
  for instead of answered wrongly, and it is the same fail-closed shape the file
  already uses for an unregistered Structure test and an unregistered tooling
  root.
- Its three inner arms (`ViolationFilter` → P6, `Rule`/`CompilerPass` → P7,
  otherwise permanent) went with it; measured 0 hits each, since all four rows
  reaching the broad branch were under `Console/` and none carries those
  substrings.
- **Rejected: reorder and keep the broad branch as a fallback.** It preserves a
  branch whose only possible output is a non-manifest owner. Keeping a fallback
  that can only be wrong is what produced these four rows.
- **Rejected: replace both with a parse of `tests/Infrastructure/{Subject}`
  against `manifestOwnerPaths()`.** Cleaner in the abstract and it would pick up
  `Infrastructure/Git`, which the nine-subject list omits; but it is a second
  design decision inside a fix whose whole point is that one ordering change
  moved four rows, and nothing on disk needs it today. Named here rather than
  taken silently.

The deletion carries a comment saying what was there and why it is not — the
next reader would otherwise read the missing fallback as an oversight.

## Census — expected answer stated first, then measured

**Expected:** exactly the four rows above; `subject_owner` →
`Infrastructure/Console`; `target_path` → each row's own path; `disposition` →
`Retain`; no `tests/**/*Test.php` row touched, because the parse answers for
those ahead of the whole ladder.

**Measured**, `test-ownership.tsv` before vs after, joined on `current_path`:

| Quantity                                    | Value |
| ------------------------------------------- | ----: |
| rows                                        | 921   |
| rows with **any** change                    | **4** |
| rows changed that are `tests/**/*Test.php`  | **0** |
| `subject_owner` changed                     | 4     |
| `target_path` changed                       | 4     |
| `disposition` changed                       | 4     |
| `closure_package` changed                   | **4** |
| any other column changed, anywhere          | **0** |
| `test-fixture-directories.tsv` rows changed | **0** |

The four are the four named rows, and each moves identically:

```
subject_owner    Infrastructure  ->  Infrastructure/Console
target_path      tests/Infrastructure/Support/X.php  ->  tests/Infrastructure/Console/Support/X.php
closure_package  permanent  ->  P8
disposition      Move atomically with the named owner and closure package.
                 ->  Retain at the materialized subject-owned path.
```

and `target_path == current_path` is now true for all four, which is what makes
the disposition Retain under the rule P4 installed. No fifth row.

`test-fixture-directories.tsv` does not move because these are `kind: support`,
and that artifact aggregates `kind: fixture` rows only.

### The fourth column, reported rather than absorbed

`closure_package` was not in the request, and it moves for the same four rows:
`permanent` → `P8`, because the nine-subject branch carries `P8` where the
deleted broad branch carried `permanent`. Left as `P8`, deliberately:

- `P8` there is the **established** value for every other subject-owned
  support/fixture root — `tests/Analysis/Finding/`, `tests/Reporting/`,
  `tests/TestSupport/Logging/`, `tests/Core/*` all return `P8` and all now read
  `Retain`. Making Infrastructure/Console `permanent` would make four rows right
  and leave 63 wrong, with nothing to explain the difference between siblings.
- It is the same contradiction P4 already reported — "Retain" beside a
  `closure_package` that claims a package still owes a move — now on 67 rows
  instead of 63. P0 derived that column for test classes only; for fixtures and
  support it is still whatever the ladder returns. Fixing it is the separate
  owner-level decision already on the record, not something to smuggle into an
  ordering fix.

Both readings are defensible; this one keeps the defect uniform and countable
rather than patched in one place.

## The reusable question: is any other branch shadowed the same way?

A sweep extracts every address predicate of `classifyOwner()`,
`dispositionFor()` and `targetPath()` in ladder order — `str_starts_with`
prefixes, `$path ===` equalities, anchored regex stems, and every literal of each
`in_array` constant — and reports any later predicate whose literal begins with
an earlier **prefix** on a different line.

**The first version of this sweep found nothing, including on the file that
carries the defect.** It excluded pairs whose literals are *equal*, and the
Infrastructure pair is exactly that: the regex `#^tests/Infrastructure/(Ast|…)/#`
has stem `tests/Infrastructure/`, identical to the prefix above it. A sweep that
cannot report the one case it was written for is a green that means nothing, so
it is recorded here rather than quietly repaired.

The corrected sweep was run on both revisions:

| File                    | Shadowed pairs                                                                      | Exit |
| ----------------------- | ----------------------------------------------------------------------------------: | ---: |
| `2edb8d33` (pre-fix)    | **1** — `tests/Infrastructure/` regex stem below the `tests/Infrastructure/` prefix | 1    |
| working tree (post-fix) | **0**                                                                               | 0    |

So the answer is measured, not asserted: **that pair was the only one of its
shape in the three ladders**, and it is gone.

A second, empirical sweep asks the same question of the tree rather than of the
text: for each of the **154** population members that actually reach the ladder
(not a tooling root, not `tests/**/*Test.php`), which branches match at all, and
does the winner differ from a later matcher?

- **8** paths match more than one predicate.
- **6** of those are an artifact of flattening: the two `str_contains` tests they
  also match (`CircularDependency`, `/Fixtures/IgnoreSample/`) are **nested
  inside** the `tests/Architecture/` branch, which those paths do not enter. Not
  alternatives at all.
- The remaining **2** —
  `tests/Analysis/Evidence/ComputedMetrics/Health/Unit/MetricRepositoryTestHelper.php`
  and `tests/Analysis/Finding/Support/StubChannelDeclarationRegistry.php` — are
  won by the **finer** branch, with the coarser prefix and the substring branches
  below it. That is the ordering working.

No remaining case where a broad predicate answers ahead of a finer one that would
answer differently.

## Definition of Done for the follow-up

| Item                                                                | Result                                                  |
| ------------------------------------------------------------------- | ------------------------------------------------------: |
| owner of `tests/Infrastructure/Console/Support/TerminalScreen.php`  | `Infrastructure/Console`                                |
| its target                                                          | its own path                                            |
| its disposition                                                     | `Retain at the materialized subject-owned path.`        |
| census: rows changed / of which `*Test.php`                         | 4 / **0**                                               |
| columns moved                                                       | 4 (three requested + `closure_package`, reported above) |
| `composer architecture:check`                                       | **0**                                                   |
| `composer check:code`                                               | **0**                                                   |
| `vendor/bin/phpunit … ModularArchitectureGovernanceIntegrationTest` | **0** — `OK (4 tests, 785 assertions)`                  |
| six per-suite counts                                                | **6705 / 383 / 152 / 1029 / 179 / 748**                 |
| files touched                                                       | the generator + `test-ownership.tsv` + this report      |

`architecture:check` prints the unchanged
`955 declarations, 37 semantic-owner layers, 0 seams, 73 exact internal grants -> 13 coarse edges`
and `921 artifacts, 120 fixture directories, 725 PHPUnit classes, 9198 expanded cases`.
