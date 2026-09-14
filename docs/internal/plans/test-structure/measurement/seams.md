# Seams: what no single slice saw on its own

A synthesis of ten wave-1 reports. Everything below was re-verified against the code of the
working tree `<repository root>`
by reading and `grep`; no tests were run.

---

## Rules the `defect-ledger.tsv` was assembled by

These decisions change the ledger's composition, so they are named explicitly — the orchestrator
can override them.

1. **One row — one defect, not one file.** A file with two different defects gives two rows
   (`RuntimeConfiguratorTest` — `category-wrong` and `dupe`; `SymbolInfoTest` — `name-lies` and
   `tautology`).
2. **An intra-file duplicate** is recorded with `counterpart` equal to the file itself. A
   cross-file one — the row is placed on the **redundant** side (the one proposed for removal/
   merging), `counterpart` points to the owner of the fact.
3. **Severity is assigned by class, not by circumstance** (to avoid 213 individual judgment
   calls): `never-runs`, `name-lies` → **high**; `dupe`, `tautology`, `category-wrong` →
   **medium**; `misplaced` → **low**; `stale-doc` → low, and **medium in one case** — when the
   docblock asserts the OPPOSITE of the body (`AnalysisPipelineIntegrationTest`: "all tests must
   fail", while the bodies check already-fixed behavior); `other` → low by default, medium where
   the report showed the test cannot catch its own defect (a conditional assert, a weak oracle, a
   hand-maintained registry, a process-state leak). This rule and the invariant "`counterpart` is
   non-empty ⇔ class `dupe`" are checked by `assert`s in the generator (`build/gen.py`), not by
   eye.
4. **A plain "this is control/control-invariant" classification is NOT a ledger row.** No such
   class exists in the brief's list, and this material has its own artifact,
   `controls-from-category.tsv`. A control test enters the ledger only when it also has a
   placement defect: **its SUT lives outside `src/`** (`scripts/promise-effect/`,
   `scripts/directive-audit*/`, `scripts/finding-gate/`, `scripts/benchmark-*.php`,
   `benchmarks/composer.lock`, `docs/adr/`, `website/docs/`), while the test itself sits in a
   tree that mirrors `src/`. There are 16 such rows, all `misplaced`/low.
5. **Hypotheses from the reports did not enter the ledger.** Report 05 explicitly flagged as
   unverified the overlaps `SarifRuleCollectorTest`↔`SarifRuleDescriptorCoverageTest` and
   `OutputFormatResolverTest`↔`OutputFormatRefusesUnexecutableValuesTest`; report 04 flagged
   `ResultPresenterTest`↔`DrillDownBindingTest`. A ledger row is an assertion that will be acted
   on; an unproven one has no place there.
6. **Product-code defects found in passing did not enter the ledger** — the ledger is about
   tests. There is exactly one such case:
   `src/Analysis/Policy/Architecture/Layer/Expansion/LayerExpansionResult.php` declares both
   `public readonly array $expandedLayers` and a same-named method `expandedLayers()` returning
   the same thing (probably a dead accessor); noticed by report 03 from inconsistent access
   patterns in `LayerExpansionStageTest`.
7. Line numbers are exact only. The approximate ones from the reports ("~330", "~250", "~99-115",
   "around 37") were re-verified with `grep -n`: `CheckCommandInputValidationTest` — not 330, but
   **458**; `CircularDependencyRuleTest` — not 99, but **100**; `DrillDownBindingTest` 250 and
   `LayerViolationIntegrationTest` 37 were confirmed. Where a number was not verified, the column
   is empty.
8. **A check against the partial 01a/01b/01c/01d chunks was carried out** (the brief asked to use
   them only for control). All their findings are already present in the merged `01-finding.md`
   and in the ledger, except for two the merged report lost or stated more vaguely — both added
   with `source_report` 01b/01c: a stale `@see` in `ChannelCoverageTest.php:88` pointing at a
   nonexistent FQCN, `Qualimetrix\Tests\Integration\Infrastructure\Rule\ChannelDeclarationFixtureDriftTest`
   (`grep -rn 'Tests\Integration\Infrastructure' tests/` — zero matches; the real class lives in
   `Qualimetrix\Tests\Analysis\Finding\Integration`), and the misleading name
   `AbstractRuleSubjectControlTest`. One more 01c claim — "`readExcludedFixtureKeys()` from
   `ChannelCoverageTest` duplicates the `ChannelEmissionStaticGuardTest` helper" — **was not
   confirmed**: a method with that name exists only in `ChannelEmissionStaticGuardTest` (line
   1034), `ChannelCoverageTest` has none; not entered in the ledger.

---

## Seam 1. `tests/Analysis/Finding/Support/FindingFactory.php` doesn't live in its own subject

**What's wrong.** The file sits in the Finding subject, but no Finding test uses it.

**Confirmed by.** `grep -rl FindingFactory tests/ src/ scripts/` gives exactly nine places besides
the file itself:

- eight `use Qualimetrix\Tests\Analysis\Finding\Support\FindingFactory;` — and all eight in
  `tests/Analysis/Policy/Baseline/Unit/`: `BaselineIdentityTest`, `BaselineGeneratorTest`,
  `BaselineCleanerTest`, `BaselineUpdaterTest`,
  `BaselineCeilingStage{Acceptance,FailSafe,JudgeAll,Promotion}Test`;
- the ninth — `scripts/generate-modular-architecture-test-inventory.php:181`, where the path
  `tests/Analysis/Finding/Support/FindingFactory.php` is **hardcoded** inside the
  `P6_A_FINDING_TEST_PATHS` constant ("Exact Finding test closure; future siblings require an
  ownership decision").

Consumers in `tests/Analysis/Finding/` — zero.

**What to do.** Move it to `tests/Analysis/Policy/Baseline/Support/FindingFactory.php` (next to
the already-existing `Baseline/Fixtures/CeilingStageFixtures.php`), change the namespace, fix the
eight `use` statements. **The move is not complete without editing
`scripts/generate-modular-architecture-test-inventory.php`** — the path is written literally
there, and the modular-architecture manifest considers this file part of Finding's closure.
Order: move → edit the script → `composer architecture:check` (which also verifies the freshness
of the generated artifacts).

Ledger: `misplaced`, low (one row, slice 01).

---

## Seam 2. Two parallel homes for console functional tests — and the mechanism holding them there

**What's wrong.** `tests/Functional/Console/` and `tests/Infrastructure/Console/Functional/` are
two directories with the same purpose. There is no content overlap (Hook commands only in the
first, everything else only in the second), but this is a special case of a broader phenomenon no
single slice sees: **two layouts** coexist in the tree — the old one ("test level at the root,
subject a subfolder": `tests/Unit/*`, `tests/Integration/*`, `tests/Functional/*`) and the new one
per ADR 0022/0016 ("subject is the folder, level is a subfolder inside the subject"). The old one
is populated by `tests/Unit/{Reporting,Infrastructure,Core,PromiseEffect,RuleVocabulary}`,
`tests/Integration/{Architecture,Infrastructure,DependencyInjection}`,
`tests/Functional/{Console,Reporting}`.

**Confirmed by.** `phpunit.xml.dist`: the `Unit` and `Integration` suites **enumerate directories
by name** — 33 `<directory>` entries in `Unit` and 16 in `Integration`, including both
`tests/Unit` (the old root) and `tests/Analysis/.../Unit`, `tests/Reporting/.../Unit` (the new
one) at once. The `Functional` and `Infrastructure` suites are shorter: `tests/Functional` +
`tests/Analysis/Policy/Baseline/Functional`, and `tests/Infrastructure` in full.

**An important negative observation (verified, not assumed).** Right now nothing is lost: a
script comparing `phpunit.xml.dist` against `glob('tests/**/*Test.php')` gives **679 test files
and 0 unreachable**; independently, the `suites` column in `tests-inventory.csv` says the same —
none of the 679 is empty.

**The risk is latent, and it is exactly the class the project's memory calls "ownerless files
break packages".** There is no guard for this in the tree — the check "every `*Test.php` is
reachable from at least one `<directory>`" is performed by nothing.

**And the risk is not abstractly future: holes gape around ALREADY existing capabilities.**
Diffing the 53 enumerated `<directory>` entries against the Cartesian product of
`{existing capability} × {Unit, Integration, Functional}` gives **39 uncovered level
directories**, and not one of them currently exists on disk — which is exactly why 679/0 checks
out. Among them: `Evidence/{CircularDependency,Cohesion,DependencyModel,Duplication,
Maintainability,Prioritization,Security,Size}/Integration`,
`Reporting/{FindingProjection,Formatter,GraphProjection}/Integration` and **`Functional/` for
every capability except `Policy/Baseline`** (including `Finding/Functional`, `Run/Functional`,
`Configuration/Functional`, `Policy/{Architecture,Inline}/Functional`).

**Here is the main consequence no slice saw: curing the ledger itself steps on this seam.** The
standard fix for a `category-wrong` row is "move the file to the directory of its real level".
For part of the 32 such rows, the target directory is exactly on the uncovered list, and the move
**will create a new directory that falls into no suite — the test will silently stop running
under a green `composer check`**. Specifically: `CycleIdentityStabilityTest` →
`Evidence/CircularDependency/Integration/`; `LcomCollectorTest`/`TccLccCollectorTest` →
`Evidence/Cohesion/Integration/`; `DuplicationDetectorTest` →
`Evidence/Duplication/Integration/`; `DuplicationMemoryLimitProcessTest`,
`RuleOptionKeyDoorSymmetryTest`, `TranslatedRefusalVocabularyTest`,
`ConfigurationValidatorSilencingPathsTest` → any `*/Functional/`. So the reachability guard is a
**precondition** for the `category-wrong` package, not a nice-to-have.

**What to do, in decreasing order of value.**
1. **First**, add a reachability guard: a test (or a `composer check` step) that compares the set
   `tests/**/*Test.php` against the `<directory>` expansion from `phpunit.xml.dist`; a
   discrepancy is red. Closes the class, not the case. A cheaper and more radical alternative:
   replace the 53 enumerations with a single `<directory>tests</directory>` for a suite filtered
   by `#[Group]`/suffix — but that is a change of suite model and a separate owner decision.
2. Move `tests/Functional/Console/*` (three Hook tests + `LayerAssignmentCommandTest`) under
   `tests/Infrastructure/Console/Functional/` — all their SUTs live in
   `src/Infrastructure/Console/Command/`. This also resolves seam 3a. The target directory is
   **covered** (the `Infrastructure` suite takes all of `tests/Infrastructure`), so this move is
   safe even without the guard.
3. Finish migrating the remaining old roots in separate packages; until it's finished, the ledger
   carries 45 `misplaced` rows, a significant share of which is exactly this tail.

---

## Seam 3. Two duplicated file names in the tree

There are exactly two matching basenames across the whole tree (verified against
`tests-inventory.csv`), and **there are no FQCN collisions**: the namespace differs for all four
files, PHPUnit loads and runs all four. The "one file silently doesn't run" scenario is ruled out.

### 3a. `HookStatusCommandTest.php` — this is **one subject split apart**

| file                                                                  | namespace                                               | what it checks                                                                                                                        |
| --------------------------------------------------------------------- | ------------------------------------------------------- | ------------------------------------------------------------------------------------------------------------------------------------- |
| `tests/Functional/Console/Command/HookStatusCommandTest.php`          | `Qualimetrix\Tests\Functional\Console\Command`          | 7 behavior tests for `execute()`: hook not installed, symlink, copy, a foreign hook, non-executable, backup, outside a git repository |
| `tests/Unit/Infrastructure/Console/Command/HookStatusCommandTest.php` | `Qualimetrix\Tests\Unit\Infrastructure\Console\Command` | 3 smoke tests for `configure()`: name and description, "no options of its own", "no arguments"                                        |

Both carry `#[CoversClass(HookStatusCommand::class)]` and both construct
`new HookStatusCommand(new GitRepositoryLocator())`. There is no duplicated assertion — this is
one SUT, cut by level and spread across two competing roots (seam 2). The second one's docblock
honestly calls itself a smoke test.

**What to do.** Merge into one file under
`tests/Infrastructure/Console/Functional/Command/HookStatusCommandTest.php` (or, if the smoke
part should stay separate, keep two files, but in **one** root and with different names —
`HookStatusCommandDefinitionTest` next to `HookStatusCommandTest`). This also fixes the
unrestored `chdir()` in the functional half (ledger, slice 04, `other`/medium).

### 3b. `UnmatchedExcludeIntegrationTest.php` — these are **two different subjects**

| file                                                                                 | SUT                                                | channel                                              |
| ------------------------------------------------------------------------------------ | -------------------------------------------------- | ---------------------------------------------------- |
| `tests/Analysis/Policy/Architecture/Integration/UnmatchedExcludeIntegrationTest.php` | `LayerViolationRule` + `LayerDeclarationValidator` | `architecture.unmatched-exclude` (docblock, line 17) |
| `tests/Analysis/Run/Integration/ExcludeBinding/UnmatchedExcludeIntegrationTest.php`  | `UnmatchedExcludeRule` + `UnmatchedExcludeAudit`   | `discovery.unmatched-exclude` (docblock, line 17)    |

Different `#[CoversClass]`, different channels, different assertions. Not a duplicate. But their
text structure is a mirror image (`itStaysSilentOnARunNarrowedBelowTheAutoloadRoots`,
`itLeavesTheRunGreenUnderFailOnNone`, `itFailsTheRunUnderFailOnWarning` — identical names in both
files), and the matching basename makes any reference like "UnmatchedExcludeIntegrationTest
says…" ambiguous.

**What to do.** Rename by the channel each one guards: `ArchitectureUnmatchedExcludeIntegrationTest`
and `DiscoveryUnmatchedExcludeIntegrationTest`.
Ledger: `other`, low.

---

## Seam 4. Duplicates whose twins landed in DIFFERENT slices

Within-slice duplicates the agents saw; cross-slice ones nobody saw — the slice boundary ran
between the files.

**How this was enumerated, not eyeballed.** A script walked all 679 `*Test.php` files, assigned
each to one of ten slices by path prefix (0 unassigned), pulled out every
`#[CoversClass(X::class)]` and grouped by `X`. Classes covered from **two or more** slices — 38.
Of these, 20 are an artifact of one file, `Policy/Inline/Unit/ThresholdOverrideIntegrationTest.php`,
which reflects over 17 `*Options` classes from other capabilities: that is its subject, not a
duplicate. The rest were read pairwise. The oracle is incomplete: it doesn't see files without
`#[CoversClass]` (e.g. `CoversNothing` tests) — which is why the docs family below was found by a
separate `grep -rl "website/docs" tests/`.

### 4a. `RuleOptionsCompilerPass` — slices 04 and 10 (confirmed duplicate)

`tests/Infrastructure/Integration/SharedRuleOptionsContainerTest.php` (a single test, line 55) and
`tests/Infrastructure/Unit/RuleOptionsCompilerPassTest.php::itKeepsTheOptionsServiceIdentitySeparateForEveryProducer`
(line 144). Both carry `#[CoversClass(RuleOptionsCompilerPass::class)]`, both assert "every
producer sharing an Options class gets its own separate options service", and **the list of ten
producer rules (7 × `CodeSmellOptions` + 3 × `SecurityPatternOptions`) is copied verbatim into
both files**, order included. The difference is level: one reads the definition graph after
`process()`, the other takes `RuleOptionsRegistry` from the assembled container. A legitimate
layering, but neither file references the other, and the producer list will have to be edited in
two places. Ledger: `dupe`/medium.

### 4b. `RulesCommand` — slices 04 and 10 (confirmed duplicate)

`tests/Infrastructure/Integration/RulesCommandWiringTest.php` (3 tests, a real container) versus
`tests/Infrastructure/Unit/RulesCommandTest.php` (12 tests, `CommandTester`):
`itRefusesAGroupNoProducerHas` (line 212) ↔ `itFailsOnAGroupNoProducerHas` (line 83);
`itFiltersByGroupAgainstTheRealRuleSet` (120) ↔ `itFiltersRulesByGroup` (140);
`itListsEveryRegisteredRule` (35) ↔ `itListsRulesUnderGroupHeaders` (120).
All three tests in the "wiring" file have a twin in the "unit" file, differing only in the source
of the rule set. Ledger: `dupe`/medium. These same two files also carry `misplaced` (neither
lives in `tests/Infrastructure/Console/`, even though the SUT is the Console domain).

### 4c. "Every channel has a docs page" — slices 01 and 05 (confirmed duplicate)

`tests/Analysis/Finding/Integration/ChannelPresentationCoverageTest.php` and
`tests/Reporting/Formatter/Sarif/Integration/SarifRuleDescriptorCoverageTest.php` are built the
same way: both split into the same two methods (the entire static channel universe + configured
computed-metric channels), both resolve `website/docs` (`dirname(__DIR__, 4)` and
`dirname(__DIR__, 5)` respectively) and both fail if a channel has no page. The second one's
docblock openly admits the kinship ("Matches ChannelPresentationCoverageTest"). The first checks
this via `ChannelPresentationInterface::presentationFor()`, the second via the SARIF descriptor's
`helpUri`. The fact that the page exists is asserted twice; only the helpUri format is unique to
the second. Ledger: `dupe`/medium on the Sarif side.

Nearby sit three more docs-checkers across three other slices — `RuleDocsPageCoverageTest` and
`RuleRemediationMinutesCoverageTest` (both 01, already flagged as a helper duplicate between
themselves), `DebugCodeDocumentationConsistencyTest` (06), `ChannelPublicationConsistencyTest` and
`DocumentationConsistencyTest` (03). Different facts, but the same mechanics — "walk the registry
and `is_file`/`preg_match` against `website/docs`" — implemented six times by six private
helpers. This is a candidate for one shared docs-guard, but I cannot assert "duplicate fact"
beyond the 4c pair without a line-by-line reading of all six — not entered in the ledger.

### 4d. Checked and turned out NOT to be a duplicate (a negative result, so it isn't re-checked)

- **`Application`, slices 04 and 10.** `Unit/Infrastructure/Console/ApplicationTest` (14 tests,
  `CommandTester`) and `Infrastructure/Console/Functional/ApplicationRefusalTest` (9 tests, a
  real subprocess) have three thematically paired tests (an unknown command; a working-dir
  that's a file; an unreadable working-dir). The functional half checks what the unit test
  physically cannot: no PHP warning on stderr, cancellation of exit code 255, a trace under `-v`.
  A legitimate layering, no ledger row placed.
- **`FileProcessingResult`, slices 09 and 10.** `Run/Unit/Collection/FileProcessingResultTest`
  (the VO's contract: success/failure/partial states) versus
  `Unit/Infrastructure/Parallel/FileProcessingResultWireFormatTest` (a round trip through
  `serialize` and igbinary). No overlap of assertions.
- **`VisitorMethodContext`, slices 06 and 07.** `Complexity/Unit/CyclomaticComplexityVisitorTest`
  (3 tests about visitor scoping) versus `Measurement/Unit/VisitorMethodContextTest` (7 tests
  about the context contract itself). They touch on "state resets between files", but from
  different sides; I would not call it a strict duplicate.
- **`RuleCompilerPass`, `FormatterContextFactory`, `BaselineGenerateCommand`,
  `ChannelDeclaration`, `ConfigurationPipeline`, `RuleOptionsFactory`, `RuleExecution`,
  `CompositeCollector`** — covered from two slices, but each file from its own angle; no shared
  assertions are visible from reading the method names.

---

## Ledger row count

`defect-ledger.tsv` — **213 rows** (plus the header row).

Breakdown by class: `other` 60, `dupe` 47, `misplaced` 45, `category-wrong` 32, `tautology` 13,
`name-lies` 8, `stale-doc` 7, `never-runs` 1.
By severity: high 9, medium 106, low 98.

All 213 values of the `file` column and all non-empty values of the `counterpart` column were
checked for existence with the loop
`while read -r f; do [ -f "$f" ] || echo MISSING "$f"; done` — not a single `MISSING`.
Every row has exactly 7 fields; the class, severity, and the `counterpart`⇔`dupe` link are held
by `assert`s in the generator.

**The ledger's single most expensive finding** is the sole `never-runs` row:
`tests/Analysis/Evidence/Design/Unit/TypeCoverage/TypeCoverageRuleTest.php:143`,
the method `itAliasesItsOwnTwoBoundariesOnly` is declared without `#[Test]` and `#[DataProvider]`,
even though all eleven neighboring methods in the file have them. PHPUnit never calls it; the
CLI-alias contract of three type-coverage rules is currently checked by nothing. Personally
re-verified by fully reading the file
(`grep` over signatures does not see this — the attributes sit on separate lines).
