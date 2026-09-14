# Audit of group C — `tests/Analysis/Finding/Integration/` (15 files)

All 15 files were read in full. Below is a categorization based on actually reading the code,
not on the file name.

## Table

| Path                                             | SUT                                                                                                                                                                                                                      | Category                                                                                                                                                    | Correct directory                                                                                                   | Defects in brief                                                                                                                                                                                                                                              |
| ------------------------------------------------ | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------ | ----------------------------------------------------------------------------------------------------------------------------------------------------------- | ------------------------------------------------------------------------------------------------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| AnalysisContextScopeArgumentGuardTest.php        | `AnalysisContext::__construct` 5th argument (`coversProjectScope`)                                                                                                                                                       | control-invariant (an AST scan of ALL of src/ for `new AnalysisContext(...)` constructs)                                                                    | `tests/Infrastructure/` or a separate `tests/Architecture/` — not Finding-subject behavior, a code-layout invariant | No assertion anywhere that `true` is the correct value; it checks only that "the argument is named", not "the value is measured"                                                                                                                              |
| ChannelCoverageTest.php                          | ~12 concrete `*Rule::analyze()` + `ChannelDeclarationRegistryInterface` via a real container                                                                                                                             | integration (real rule classes, a stub repository, a real DI container)                                                                                     | `tests/Analysis/Finding/Integration/` — correct                                                                     | `#[CoversNothing]` on a class with an explicit SUT in the docblock — formally correct per PHPUnit, but misleading; `readExcludedFixtureKeys()` duplicates the helper from ChannelEmissionStaticGuardTest one-for-one (deliberately, see that file's docblock) |
| ChannelDeclarationFixtureDriftTest.php           | `ChannelDeclarationRegistryInterface::staticDeclarations()` vs the text fixture `declared.txt`/`excluded.txt`                                                                                                            | control (fixture drift: the registry is built by a real container, but the subject of comparison is synchrony with a hand-written text file)                | the same directory is acceptable, but in spirit this is control, not a behavior test                                | None                                                                                                                                                                                                                                                          |
| ChannelEmissionStaticGuardTest.php (1059 lines)  | The source code of ~every Rule class in src/ (an AST resolver for the `ruleName`/`code` arguments of `new Finding()`)                                                                                                    | control-invariant (parses all of src/ with php-parser, including its own resolver self-test)                                                                | `tests/Infrastructure/` / static layout analysis, not Finding behavior                                              | One of the largest files in the slice — effectively a second mini code-analyzer inside the tests; `skipList()` is empty, meaning the resolver's whole complexity is currently never exercised — dead weight until the first real skip case                    |
| ChannelJudgedMetricDriftTest.php                 | A real product run over the external corpus `finding-gate/cases/` (`CorpusCaseRun`), comparing `finding.metricValue` against the metric catalog                                                                          | functional (runs real analysis via `CorpusCaseRun` — effectively the CLI/pipeline)                                                                          | the current location is acceptable (a borderline functional/integration test of product behavior)                   | None — an exemplary test: it measures real behavior, not declarations                                                                                                                                                                                         |
| ChannelLevelAssemblyTopologyTest.php             | An AST scan of src/ (`.class`/`.namespace` literals etc.) + a structural check of `staticDeclarations()` keys                                                                                                            | control-invariant + one resolver self-test (`itRecognisesARetiredLevelBearingChannelName` tests its own private parser `levelSegmentOf()`, not the product) | `tests/Infrastructure/`                                                                                             | `itRecognisesARetiredLevelBearingChannelName` — a test of a test helper, not of the product (tautological with respect to the product, though deliberately documented as an "anti-empty-set" guarantee)                                                       |
| ChannelLevelDeclarationDriftTest.php             | A real corpus run (`CorpusCaseRun`) + `ChannelDeclarationRegistryInterface`, comparing observed vs declared vs `observed-levels.tsv`                                                                                     | functional/integration (runs real analysis)                                                                                                                 | the current location is correct                                                                                     | `levelOf()` is its own subject-string parser, deliberately independent from the product (documented and justified); `itRecognisesEveryFindingSubjectFormTheCorpusReaches` is a self-test of the oracle's coverage, not of the product                         |
| ChannelLevelRefusalTopologyTest.php              | A text/AST scan of src/ via regex/token_get_all with hand-maintained allow-lists (`LEVEL_READERS`, `LEVEL_WORDING_AUTHORS`)                                                                                              | control-invariant (source topology, pinned lists)                                                                                                           | `tests/Infrastructure/`                                                                                             | A fragile substring-based detector (`str_contains('->levelsOf(')` etc.) — a new call form silently slips past it; two self-tests (`itCatches...`) test the detectors themselves on synthetic strings, not the product                                         |
| ChannelOrderFixtureDriftTest.php                 | `ChannelUniverseInterface::channels()` order vs `order.txt`                                                                                                                                                              | control (fixture drift)                                                                                                                                     | acceptable                                                                                                          | None                                                                                                                                                                                                                                                          |
| ChannelPresentationCoverageTest.php              | `ChannelPresentationInterface::presentationFor()` + checking that `website/docs/**` files exist                                                                                                                          | control (directly uses `is_file()` on the docs tree — this checks repository state, not behavior)                                                           | a `website/`-related check under `tests/Reporting/` or a separate docs-guard, not Finding Integration               | `DECLARED_CHANNEL_COUNT = 58` is hardcoded and duplicated (see "Duplicates")                                                                                                                                                                                  |
| ChannelShapeNotDeclaredByChannelTopologyTest.php | An AST scan of src/ (calls to `ChannelDeclaration::magnitude()/occurrence()` + co-occurring `ChannelShape::` in the same method)                                                                                         | control-invariant                                                                                                                                           | `tests/Infrastructure/`                                                                                             | An explicitly documented blind spot (a reference through a private helper isn't seen) — honest, but limits the value                                                                                                                                          |
| ChannelSuggestionTieTest.php                     | `levenshtein()` over hardcoded channel strings + `DirectiveNameHints::SUGGESTION_DISTANCE`                                                                                                                               | unit (a constant + pure math, the real `ChannelUniverseInterface` is used only to check order and code existence)                                           | `tests/Analysis/Policy/Inline/` (closer to `DirectiveNameHints`, not the Finding subject)                           | Never calls `DirectiveNameHints` itself — recomputes Levenshtein by hand and relies on the implementation matching the test's algorithm; if the product ever changes the suggestion algorithm (away from Levenshtein), the test won't notice                  |
| ChannelUniverseCoverageTest.php (518 lines)      | `ChannelIdentityInterface`/`ChannelUniverseInterface`, two independent "witnesses" (the registry vs directly reading the rule classes)                                                                                   | integration (a real container, real rule classes) — the best test in the slice after ChannelCoverageTest                                                    | the current location is correct                                                                                     | `DECLARED_CHANNEL_COUNT = 58` is duplicated with ChannelPresentationCoverageTest with no programmatic check between the files (see "Duplicates")                                                                                                              |
| ConfigurationErrorClassificationTopologyTest.php | `ChannelDeclaration::asConfigurationError()` — an AST scan of src/ (control) **plus** `ChannelDeclarationCompilerPass`/`RuleExecution` with fixture classes (`StampRule`, `StampValidator`, etc.) via `ContainerBuilder` | mixed: control (the first 2 tests — an AST/grep scan) + integration (the next 5 — a real compiler pass and RuleExecution with fixture classes)              | the control part → `tests/Infrastructure/`; the integration part stays here                                         | One file mixes two different test genres under one heading — makes it harder to read; otherwise the tests are valid and useful                                                                                                                                |
| ConfigurationValidatorSilencingPathsTest.php     | A real `CheckCommand` via `CommandTester` over a temp directory with real PHP files and `qmx.yaml`                                                                                                                       | functional (a full CLI run)                                                                                                                                 | the current location is correct (or `tests/Infrastructure/Console/`)                                                | None — an exemplary test of silencing-path behavior                                                                                                                                                                                                           |

## Control candidates

Of the 15 files, **9 are control** (they scan src/ with static analysis, or check pinned text
fixtures without running the product over user code):

- `AnalysisContextScopeArgumentGuardTest.php` — an AST scan of src/ for a constructor argument.
- `ChannelDeclarationFixtureDriftTest.php` — checking the registry against the hand-written text
  file `declared.txt`/`excluded.txt`.
- `ChannelEmissionStaticGuardTest.php` — an AST scan of all of src/, a second mini code-analyzer
  inside the tests.
- `ChannelLevelAssemblyTopologyTest.php` — an AST scan of src/ for level literals + a parser
  self-test.
- `ChannelLevelRefusalTopologyTest.php` — a regex/token scan of src/ with pinned allow-lists.
- `ChannelOrderFixtureDriftTest.php` — checking channel order against `order.txt`.
- `ChannelPresentationCoverageTest.php` — checking that `website/docs/**` files exist via
  `is_file()`.
- `ChannelShapeNotDeclaredByChannelTopologyTest.php` — an AST scan of src/ for the co-occurrence
  of two constructs.
- `ConfigurationErrorClassificationTopologyTest.php` (the first 2 of 7 tests) — a grep/AST scan
  of src/ for the single call site of the wither method.

None of them fit "control-invariant taken via reflection from ALL src/ classes" in the narrow
sense (they deliberately search for specific constructs/calls rather than checking an invariant
on every class across the board) — these are more like hand-written fitness-function/architecture
guards layered over the source, purposely written in response to past regressions (judging by the
docblocks — each names a specific incident). Formally they fall under the client's criterion
"checks the repository's/declarations' laid-out state" and evidently should be moved out of
`tests/`.

## Duplicates and contradictions

1. **`DECLARED_CHANNEL_COUNT = 58`** — hardcoded independently in
   `ChannelUniverseCoverageTest.php:65` and `ChannelPresentationCoverageTest.php:31`. The docblock
   of `ChannelPresentationCoverageTest` explicitly admits: "Matches
   ChannelUniverseCoverageTest::DECLARED_CHANNEL_COUNT: both read the same real container's
   static declarations, so a divergence between the two counts would itself be a regression" — but
   nothing programmatically checks this. If the count is fixed in one file and forgotten in the
   other, both tests will stay green individually while diverging from each other. A candidate for
   moving the constant into a shared support class (`CorpusCaseRun` or a separate
   `ChannelFixtures`).

2. **`readExcludedFixtureKeys()`** — an identical private method in `ChannelCoverageTest.php`
   and `ChannelEmissionStaticGuardTest.php`. Deliberately duplicated (the second file's docblock
   explains: "sharing the helper would couple two otherwise-independent guards through a third
   file") — not a defect but a conscious trade-off; mentioned for completeness.

3. **The "Drift/Assembly/Refusal" triad by levels** — `ChannelLevelAssemblyTopologyTest`,
   `ChannelLevelDeclarationDriftTest`, `ChannelLevelRefusalTopologyTest` really do check three
   different facets (declaration syntax / emission agreement / refusal topology), explicitly and
   convincingly justified by each file's docblock, with cross-references to each other. No
   contradictions found, no substantive duplication — noted only because the brief asked to
   explicitly check such triads.

4. **`ChannelCoverageTest` vs `ChannelEmissionStaticGuardTest`** — both check "an emitted channel
   resolves to a declaration", one dynamically (a real rule call), the other statically (AST
   reflection of the source). `ChannelEmissionStaticGuardTest`'s docblock explicitly explains the
   difference and it is not a duplicate in fact (different failure modes: the dynamic one misses
   unreachable branches, the static one catches them).

## Assumptions

- Did not run `composer check`/PHPUnit — the "control vs integration vs functional"
  categorization was done purely by reading the code (use of a real DI container, real src/
  files, a real `CommandTester`, or comparison against text fixtures).
- The notion of "control" was applied by the letter of the client's brief ("checks the
  repository's/declarations'/docs-pages' laid-out state... or scans src/ via reflection/as text
  for a layout invariant") — including AST scans of src/ via `nikic/php-parser`, even when they
  don't literally relate to documentation/qmx.yaml/composer.json, but examine the source code as
  text for an architectural invariant rather than behavior on user input.
- No CLAUDE.md §9 violations (`#[Test]` + `itXxx`) were found in this slice — all 15 files use
  the correct test-method naming form.
- Did not check git history/blame for who introduced each guard and when — the defect assessment
  rests only on the current state of the code.
