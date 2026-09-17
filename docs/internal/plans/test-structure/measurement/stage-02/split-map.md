# Split map for the 17 `mixed`-verdict files

First-witness pass over `controls-verdict.tsv` (rows where `class == mixed`,
exactly 17). For each file: which `#[Test]` methods the `scope` column moves
out, which stay, what the two halves share, what paths are read from disk,
expanded PHPUnit case counts (from `--list-tests`, method names split on the
first `::` after the FQCN, data-set names ignored for that split), and a
feasibility verdict.

Case counts were taken with:

```
vendor/bin/phpunit --configuration=phpunit.xml.dist --testsuite=<Unit|Integration|Functional|Infrastructure> \
  --list-tests --no-coverage --exclude-group=benchmark
```

No PHPUnit test was executed; only `--list-tests`. All duplication/no-duplication
claims are read from source, not run.

---

## 1. `tests/Analysis/Configuration/Unit/ConfigSchemaTest.php`

Suite: Unit. `#[CoversClass(ConfigSchema::class)]` on the class.

- **Departing (control, 7 methods, 7 cases):** `itKeepsSectionAndListKeysDisjoint`,
  `itIncludesEveryTypedKeyInAllowedRootKeys`, `itAcceptsAConfigCoveringEverySchemaEntry`,
  `itReturnsTheCorrectSubKeysPerSection`, `itGivesEveryEntryAMatchingConstant`,
  `itGivesEveryConstantAnEntryOrMarksItInternal`, `itLeavesNoConstantUnreferencedByAConsumer`.
- **Staying (product-test, 3 methods, 3 cases):** `itListsAllExpectedRootKeys`,
  `itIncludesDottedRootsAmongSectionKeys`, `itReturnsOnlyListTypeKeys`.

| Dependency                                                       | Departing                                         | Staying | Needed by both?                             |
| ---------------------------------------------------------------- | ------------------------------------------------- | ------- | ------------------------------------------- |
| `buildFullConfigYaml()` / `dummyScalarValue()` (private)         | yes (`itAcceptsAConfigCoveringEverySchemaEntry`)  | no      | no                                          |
| `ReflectionClass`/`ReflectionNamedType` imports                  | yes (3 methods)                                   | no      | no                                          |
| `YamlConfigLoader` import                                        | yes                                               | no      | no                                          |
| `RecursiveDirectoryIterator`/`RecursiveIteratorIterator` imports | yes (`itLeavesNoConstantUnreferencedByAConsumer`) | no      | no                                          |
| `ConfigSchema` (product)                                         | yes                                               | yes     | yes, but it's the SUT, not test scaffolding |

Paths read from disk: `dirname(__DIR__, 4) . '/src'` (whole `src/` tree, read as
text) by `itLeavesNoConstantUnreferencedByAConsumer` only — departing half only.

No `#[DataProvider]`, no shared constants beyond `ConfigSchema::` itself (product
code). No `setUp`.

**Verdict: clean.** No private helper or constant is needed by both halves.

---

## 2. `tests/Analysis/Evidence/ComputedMetrics/Integration/BenchmarkConsumersCoverageTest.php`

Suite: Integration. No `#[CoversClass]`.

- **Departing (control, 1 method, 1 case):** `itKeepsTrackedBenchmarkConsumersOnTheCheckCommand`.
- **Staying (tooling-test, 3 methods, 5 cases):** `itDoesNotUpdateBaselinesFromANonAuthoritativeArtifact`
  (3 cases via `#[TestWith]` x3), `itDoesNotWriteCollectedDataWhenAnyProjectIsIncomplete` (1),
  `itDoesNotPartiallyRatchetWhenAConfiguredProjectIsSkipped` (1).

| Dependency                                                                                                                    | Departing | Staying | Needed by both? |
| ----------------------------------------------------------------------------------------------------------------------------- | --------- | ------- | --------------- |
| `$fixtureRoots` property + `tearDown()`                                                                                       | no        | yes     | no              |
| `createFixtureRoot()`, `copyScript()`, `writeFakeQmx()`, `metricsArtifact()`, `createCollectorProjectDirectories()` (private) | no        | yes     | no              |

Paths read: departing method runs `git grep` over `scripts/benchmark-comparison.sh`
and `scripts/compare-metrics.py` (tracked scripts, read as text) — departing only.
Staying methods copy real `scripts/benchmark-regression.php` /
`scripts/collect-benchmark-data.php` into a temp fixture root via
`dirname(__DIR__, 5) . '/scripts/' . $script` — staying only.

**Verdict: clean.**

---

## 3. `tests/Analysis/Evidence/Measurement/Integration/Identity/ClassProducerOrdinalTest.php`

Suite: Integration. No `#[CoversClass]`.

- **Departing (control, 2 methods, 2 cases):** `itCoversEveryClassMetricProducer`,
  `itFindsTheHelperCallSiteOfEveryCoveredProducer`.
- **Staying (product-test, 1 method, 16 cases):** `itNumbersTheSecondDeclarationOfOneClassIdentity`
  (via `#[DataProvider('provideClassProducers')]`, 8 producers × 2 source forms).

| Dependency                                                              | Departing                                | Staying                               | Needed by both? |
| ----------------------------------------------------------------------- | ---------------------------------------- | ------------------------------------- | --------------- |
| `private const array PRODUCERS` (pinned producer→file map, 8 entries)   | yes (both departing methods)             | yes (feeds `provideClassProducers()`) | **yes**         |
| `deliverIndex()` (private)                                              | no                                       | yes                                   | no              |
| `productionClasses()` (private)                                         | yes (`itCoversEveryClassMetricProducer`) | no                                    | no              |
| `duplicateClass()` / `bracedDuplicateClass()` (private, fixture source) | no                                       | yes (data provider)                   | no              |

Paths read: `dirname(__DIR__, 6) . '/src'` walked by both departing methods
(inline `dirname()` call, not a shared helper — but the walk itself is
duplicated logic between the two departing methods, which is fine since they
move together).

**Verdict: needs a duplicate (or a shared Support) of `PRODUCERS`.** The pinned
8-entry producer map is read by the completeness check (departing,
`itCoversEveryClassMetricProducer`/`itFindsTheHelperCallSiteOfEveryCoveredProducer`)
and by the data provider that drives the surviving product test
(`itNumbersTheSecondDeclarationOfOneClassIdentity`). Splitting the file means
either duplicating this constant or extracting it to a shared class both new
files import.

---

## 4. `tests/Analysis/Evidence/Measurement/Integration/Identity/RatchetKeyGrammarTest.php`

Suite: Integration. No `#[CoversClass]`.

- **Departing (control, 1 method, 1 case):** `itFindsNoPositionInAnyDeclarationKeyOfTheRepositoryRatchet`.
- **Staying (product-test, 2 methods, 7 cases):** `itSplitsADeclarationKeyIntoItsFileAndItsOrdinal`
  (6 cases via `#[DataProvider('provideDeclarationKeys')]`), `itRejectsAKeyThatStillCarriesAPosition` (1).

| Dependency                           | Departing | Staying | Needed by both? |
| ------------------------------------ | --------- | ------- | --------------- |
| `parse()` (private static)           | yes       | yes     | **yes**         |
| `declarationKeys()` (private static) | yes       | no      | no              |

Paths read: `dirname(__DIR__, 6) . '/qmx-baseline.json'` (tracked baseline
artifact) by `declarationKeys()`, departing only.

**Verdict: needs a duplicate (or a shared Support) of `parse()`.** It is the
only production logic in the file (splits a declaration key on the last `@`
and an optional `#ordinal` suffix) and both halves call it directly.

---

## 5. `tests/Analysis/Finding/Integration/ConfigurationErrorClassificationTopologyTest.php`

Suite: Integration. `#[CoversClass(ChannelDeclaration::class)]`.

- **Departing (control, 3 methods, 3 cases):** `itAllowsExactlyOneProductionSiteToTurnADeclarationIntoAConfigurationError`,
  `itRefusesAnyOtherProductionFileThatEvenNamesTheWither`,
  `itRefusesAProductionSiteThatHandsTheFlagToTheConstructorInstead`.
- **Staying (product-test, 4 methods, 4 cases):** `itStampsTheAssemblyWithExactlyWhatAValidatorDeclares`,
  `itFailsTheBuildWhenAValidatorNamesAProducerThatIsNotARule`,
  `itFailsTheBuildWhenAChannelIsDeclaredByBothProducerKinds`,
  `itEndsTheRunWhenAValidatorEmitsOnAChannelItDoesNotDeclare`.

| Dependency                                                                                                                                       | Departing                      | Staying     | Needed by both? |
| ------------------------------------------------------------------------------------------------------------------------------------------------ | ------------------------------ | ----------- | --------------- |
| `WITHER` const                                                                                                                                   | yes (2 of 3 departing methods) | no          | no              |
| `productionFiles()`, `parse()`, `sourceRoot()`, `relative()` (private)                                                                           | yes (2 of 3 departing methods) | no          | no              |
| `containerWith()` (private static)                                                                                                               | no                             | yes (all 4) | no              |
| Fixture classes at file bottom (`StampRule`, `StampOptions`, `StampValidator`, `OrphanedValidator`, `PoachingValidator`, `TrespassingValidator`) | no                             | yes         | no              |

`itRefusesAProductionSiteThatHandsTheFlagToTheConstructorInstead` (departing)
uses none of the AST-walking helpers — it reflects `ChannelDeclaration`'s
constructor directly and calls `ChannelDeclaration::occurrence()`/`magnitude()`.
It is self-contained.

Paths read: `sourceRoot()` = `dirname(__DIR__, 4) . '/src'`, walked by
`productionFiles()`, used by 2 of the 3 departing methods only.

**Verdict: clean.** The fixture classes and `containerWith()` belong entirely to
the staying half; the AST-scanning helpers and `WITHER` belong entirely to the
departing half. No overlap found.

---

## 6. `tests/Analysis/Finding/Unit/Exclusion/ConfiguredSuppressionTest.php`

Suite: Unit. `#[CoversClass(ConfiguredSuppression::class)]`.

- **Departing (control, 1 method, 1 case):** `itIsTheOnlyPlaceInSourceThatReadsASuppressionOptionKey`.
- **Staying (product-test, 2 methods, 2 cases):** `itReadsEitherSpellingOfEachOption`,
  `itReadsNothingRatherThanThrowingOnAMalformedValue`.

| Dependency                             | Departing | Staying | Needed by both? |
| -------------------------------------- | --------- | ------- | --------------- |
| `READER` const, `RAW_READ` regex const | yes       | no      | no              |

Paths read: `dirname(__DIR__, 5) . '/src'`, walked via `RecursiveDirectoryIterator`,
departing only.

**Verdict: clean.**

---

## 7. `tests/Analysis/Policy/Architecture/Integration/ModularArchitectureGovernanceIntegrationTest.php`

Suite: Integration. No `#[CoversClass]`. One method (`itChecksEveryGeneratedProjectionWithoutWriting`)
carries `#[Group('live-freshness')]`, which is excluded from
`scripts/phpunit-aggregate.py` (so it doesn't run under `composer check` →
`check:code` → `test:aggregate`, only under a bare `composer test`).

- **Departing (control, 4 methods, 4 cases):** `itChecksEveryGeneratedProjectionWithoutWriting`,
  `itRoutesFreshnessOraclesExactlyOnceThroughAggregateCheck`,
  `itPublishesOnlyPermanentExactCompositionBindingsForDiInternals`,
  `itPublishesTheReviewedTopologyEvidenceAndRejectsProductionToTestImports`.
- **Staying (tooling-test, 3 methods, 3 cases):** `itRejectsCompositionBindingsWhenOnlyIdentityEvidenceRemains`,
  `itFailsWhenAPhpunitTestClassHasNoConfiguredSuite`, `itFailsWhenACurrentSuiteLiteralHasNoDeclaredDirectory`.

| Dependency                                                                                                      | Departing                                              | Staying                                                             | Needed by both? |
| --------------------------------------------------------------------------------------------------------------- | ------------------------------------------------------ | ------------------------------------------------------------------- | --------------- |
| `root()` (private)                                                                                              | yes (all 4)                                            | yes (all 3)                                                         | **yes**         |
| `runProcess()` (private)                                                                                        | yes (`itChecksEveryGeneratedProjectionWithoutWriting`) | yes (all 3, directly or via `withIsolatedProject`)                  | **yes**         |
| `manifest()`, `composer()`, `scriptSteps()`, `tsv()` (private)                                                  | yes                                                    | no                                                                  | no              |
| `sourcePath()`, `relativePath()` (private)                                                                      | no                                                     | yes (`itRejectsCompositionBindingsWhenOnlyIdentityEvidenceRemains`) | no              |
| `withIsolatedProject()`, `createIsolatedProject()`, `copyDirectory()`, `removeDirectory()` (private, ~40 lines) | no                                                     | yes (2 of 3 staying methods)                                        | no              |

Paths read: `docs/internal/modular-architecture-manifest.json`, `composer.json`,
`docs/internal/generated/modular-architecture/*.tsv` — departing only.
`src/Infrastructure/DependencyInjection/{Configurator,CompilerPass}/*.php` (via
`sourcePath()` glob) — staying only. `withIsolatedProject()` copies `tests/`,
`governance/`, `src/`, and the generated-modular-architecture directory into a
scratch project root — staying only (2 methods).

**Verdict: needs a duplicate (or a shared Support) of `root()` and `runProcess()`.**
Both are small (root: 5 lines; runProcess: ~10 lines) but are called from both
halves. Everything else (manifest/composer/tsv readers vs. the isolated-project
machinery) splits cleanly.

Notable gap versus the docs the file carries: the notes file (see divergence
section) only discusses `itChecksEveryGeneratedProjectionWithoutWriting` as a
deletable duplicate of `composer architecture:check`; it does not describe that
three more methods in this file are independently classified as control by the
current TSV.

---

## 8. `tests/Analysis/Policy/Baseline/Functional/BaselineCommandOptionSurfaceTest.php`

Suite: Functional. Four `#[CoversClass]` (the four baseline commands).

- **Departing (control, 1 method, 1 case):** `itKeepsRepositoryEntrypointsOnTheBaselineLifecycleSurface`.
- **Staying (product-test, 4 methods, 13 cases):** `itDeclaresNoExclusionOrSuppressionOption` (4,
  via `#[DataProvider('provideBaselineCommands')]`), `itAcceptsTheConfigurationThatDefinesTheSet` (4),
  `itAcceptsEveryOptionThatDecidesWhatIsMeasured` (4), `itNoLongerLetsCheckWriteABaseline` (1).

| Dependency                                                   | Departing | Staying | Needed by both? |
| ------------------------------------------------------------ | --------- | ------- | --------------- |
| `command()` (private static, builds via `ContainerFactory`)  | no        | yes     | no              |
| `FORBIDDEN_OPTIONS`, `REQUIRED_CONFIGURATION_OPTIONS` consts | no        | yes     | no              |
| `provideBaselineCommands()` (data provider)                  | no        | yes     | no              |

Paths read: `action.yml`, `docker-compose.yml`, `scripts/pre-commit-hook.sh`
(via `dirname(__DIR__, 5) . '/' . $path`) — departing only.

**Verdict: clean.**

---

## 9. `tests/Analysis/Policy/Baseline/Unit/ChannelRenameMapTest.php`

Suite: Unit. `#[CoversClass(ChannelRenameMap::class)]`.

- **Departing (control, 1 method, 1 case):** `itReadsTheRepositorysOwnDeclaredChannelMap`.
- **Staying (product-test, 3 methods, 19 cases):** `itAnswersTheSharedCorpusAsDeclared` (17,
  via `#[DataProvider('provideCorpus')]` fed by `Qualimetrix\Tests\Analysis\Policy\Baseline\Fixtures\ChannelRenameTsvCorpus::cases()`),
  `itReadsTheRowsItAccepted` (1), `itRefusesAMapFileItCannotRead` (1).

No shared private helper: the departing method calls
`ChannelRenameMap::fromFile(dirname(__DIR__, 5) . '/finding-gate/maps/channels.tsv')`
directly. The staying data provider imports the `ChannelRenameTsvCorpus`
test-tree fixture, used only there.

Paths read: `finding-gate/maps/channels.tsv` (tracked corpus map), departing only.

**Verdict: clean.**

---

## 10. `tests/Infrastructure/Unit/RuleRegistryTest.php`

Suite: Infrastructure. No `#[CoversClass]`.

- **Departing (control, 1 method, 1 case):** `itResolvesEveryCliAliasToARealRuleOption`.
- **Staying (product-test, 5 methods, 5 cases):** `itReturnsRegisteredRuleClassNames`,
  `itCollectsCliAliasesFromAllRulesUsingReflection`, `itThrowsWhenTwoRulesShareACliAlias`,
  `itReturnsEmptyResultsForAnEmptyRegistry`, `itReadsTheNameConstantWithoutInstantiatingRules`.

| Dependency                   | Departing | Staying | Needed by both? |
| ---------------------------- | --------- | ------- | --------------- |
| `optionResolves()` (private) | yes       | no      | no              |

Departing method builds the registry from the real container
(`(new ContainerFactory())->create()->get(RuleRegistryInterface::class)`);
staying methods construct `new RuleRegistry([ComplexityRule::class, ...])`
directly with two named rule classes as fixtures. No shared helper.

**Verdict: clean.**

---

## 11. `tests/Reporting/Formatter/Sarif/Integration/SarifRuleDescriptorCoverageTest.php`

Suite: Integration. `#[CoversClass(SarifRuleCollector::class)]`.

- **Departing (control, 2 methods, 2 cases):** `itGivesEveryChannelOfTheRealUniverseItsProducersOwnDescriptorAndAWorkingHelpUri`,
  `itAlsoGivesEveryConfiguredComputedMetricChannelItsProducersOwnDescriptorAndAWorkingHelpUri`.
- **Staying (product-test, 1 method, 1 case):** `itKeepsTheHumanisedFallbackAndTheRepositoryUrlForAnUnknownCode`.

| Dependency                                                     | Departing                            | Staying                               | Needed by both? |
| -------------------------------------------------------------- | ------------------------------------ | ------------------------------------- | --------------- |
| `checkChannel()` (private)                                     | yes (both)                           | no                                    | no              |
| `UNIVERSE_CHANNEL_COUNT`, `DOCS_BASE_URI` consts, `docsRoot()` | yes                                  | no                                    | no              |
| `finding()` (private static, builds a `Finding` fixture)       | yes (called inside `checkChannel()`) | **yes (called directly at line 216)** | **yes**         |

Paths read: `website/docs/` (via `docsRoot()`), departing only.

**Verdict: needs a duplicate of `finding()`.** This is not mentioned in the
notes file's description of the split (see divergence section) — it is a small
(~10-line) fixture-builder, but it is called both from inside `checkChannel()`
(departing) and directly from the one staying test.

---

## 12. `tests/Unit/Core/Util/GlobSyntaxTest.php`

Suite: Unit. `#[CoversClass(GlobSyntax::class)]`.

- **Departing (control, 1 method, 1 case):** `itIsTheOnlyPlaceInSourceThatEnumeratesTheGlobCharacters`.
- **Staying (product-test, 2 methods, 7 cases):** `itRecognisesAGlobByItsCharacters` (6, via
  `#[DataProvider('providePatterns')]`), `itAgreesWithTheMatcherThatAppliesTheValue` (1).

| Dependency                                    | Departing | Staying | Needed by both? |
| --------------------------------------------- | --------- | ------- | --------------- |
| `SOURCE_OF_TRUTH`, `RESTATED_ALPHABET` consts | yes       | no      | no              |

Paths read: `dirname(__DIR__, 4) . '/src'`, departing only.

**Verdict: clean.**

---

## 13. `tests/Unit/Core/Util/NamespaceMatcherTest.php`

Suite: Unit. `#[CoversClass(NamespaceMatcher::class)]`, `#[CoversClass(PatternMatch::class)]`.

- **Departing (control, 1 method, 1 case):** `itLeavesPatternNormalizationToThePrimitiveOnEverySurface`.
- **Staying (product-test, 29 methods, 47 cases):** everything else (four `#[DataProvider]`
  groups: `matchingPrefixesProvider`, `nonMatchingPrefixesProvider`, `globPatternProvider`,
  `nonGlobPatternProvider`, plus 21 plain methods).

| Dependency                                               | Departing | Staying | Needed by both? |
| -------------------------------------------------------- | --------- | ------- | --------------- |
| `codeWithoutComments()` (private static)                 | yes       | no      | no              |
| `SELECTOR_SURFACES` const (7-entry pinned call-site map) | yes       | no      | no              |

Paths read: `dirname(__DIR__, 4) . '/src'`, departing only.

**Verdict: clean.**

---

## 14. `tests/Unit/Core/VersionTest.php`

Suite: Unit. No `#[CoversClass]`.

- **Departing (control, 1 method, 1 case):** `itDoesNotResolveTheVersionThroughTheRootPackage`.
- **Staying (product-test, 2 methods, 2 cases):** `itReportsTheVersionOfTheQualimetrixPackage`,
  `itReturnsANonEmptyVersionString`.

No private helpers at all in this file; the departing method reflects
`Version::class` and reads its source file inline.

**Verdict: clean.**

---

## 15. `tests/Unit/PromiseEffect/LedgerVocabularyTest.php`

Suite: Unit. No `#[CoversClass]`. Loads `scripts/promise-effect/Ledger.php` via
`require_once` in `setUpBeforeClass()` (no PSR-4 entry for `scripts/`).

- **Departing (control, 1 method, 1 case):** `itLoadsTheRepositorysOwnLedger`.
- **Staying (tooling-test, 4 methods, 4 cases):** `itRefusesACompositionPromiseNoClassifierBranchAwards`,
  `itRefusesACoexistenceTheClassifierWouldSilentlyReadAsCompose`,
  `itRefusesAnEmptyCoexistenceOnARowThatReachesTheClassifier`,
  `itAcceptsTheOneWinsFormWhichCarriesItsWinnerInTheValue`.

| Dependency                                                                                                   | Departing                                               | Staying                 | Needed by both?          |
| ------------------------------------------------------------------------------------------------------------ | ------------------------------------------------------- | ----------------------- | ------------------------ |
| `setUpBeforeClass()` (`require_once .../Ledger.php`)                                                         | yes                                                     | yes                     | **yes, but boilerplate** |
| `$scratchRoots` property + `tearDown()`                                                                      | no                                                      | yes                     | no                       |
| `trackedLedger()`, `rootWith()`, `replaceFirst()`, `promoteFirstDeferredPairRow()`, `removeTree()` (private) | no                                                      | yes                     | no                       |
| `LEDGER` const                                                                                               | no (departing loads via `dirname(__DIR__, 3)` directly) | yes (`trackedLedger()`) | no                       |

**Verdict: clean, modulo duplicating the one-line `setUpBeforeClass()`
`require_once`** — trivial, not a real coupling hazard.

---

## 16. `tests/Unit/RuleVocabulary/DirectiveAuditReportReadingTest.php`

Suite: Unit. No `#[CoversClass]`. Loads eight `scripts/directive-audit/*.php`
parts via `require_once` in `setUpBeforeClass()`.

- **Departing (control, 3 methods, 3 cases):** `itNamesEveryVerdictTheProductCanPublishAndNoOther`,
  `itAsksForEveryVerdictTheProductCanPublishAndNoOther`, `itAsksForEveryRefusalTheProductCanPublishAndNoOther`.
- **Staying (tooling-test, 20 methods, 26 cases):** everything else, including
  `itRefusesAVerdictWhoseFieldsAreNotTheShapeTheAuditPublishes` (7 cases via
  `#[DataProvider('provideIllShapedVerdicts')]`).

| Dependency                                                     | Departing | Staying | Needed by both?          |
| -------------------------------------------------------------- | --------- | ------- | ------------------------ |
| `setUpBeforeClass()` (8 `require_once` calls)                  | yes       | yes     | **yes, but boilerplate** |
| `verdict()`, `reportJson()`, `heterogeneousReport()` (private) | no        | yes     | no                       |

The three departing methods compare `DirectiveEffect::cases()` /
`DirectiveUnmeasurableReason::cases()` directly against `MeasuredEffects::TABLE`
/ `HeterogeneityFloor::REQUIRED_EFFECTS` / `REQUIRED_REASONS`; they call no
private test helper.

**Verdict: clean, modulo duplicating `setUpBeforeClass()`.**

---

## 17. `tests/Unit/RuleVocabulary/ThresholdPopulationAgreementTest.php`

Suite: Unit. No `#[CoversClass]`. Loads two `scripts/directive-audit/*.php`
parts via `require_once` in `setUpBeforeClass()`.

- **Departing (control, 2 methods, 2 cases):** `itKeepsEverySeededFixtureFileOutOfSrc`,
  `itKeepsTheSeededDirectivesOutOfTheEnumerationOverSrc`.
- **Staying (tooling-test, 6 methods, 25 cases):** `itReadsAnAuthoredFormTheWayTheProductDoes`
  (21, via `#[DataProvider('provideAuthoredForms')]`), `itNamesEveryFormTheFixtureDeclares`,
  `itMeasuresTheSamePopulationOverTheWholeFixture`, `itScansATreeAndSkipsWhatIsNotPhp`,
  `itRefusesToScanATreeItCannotRead` — five distinct methods for 25 cases (1+1+21+1+1).

| Dependency                                                                                                                                                                                                                                                                                                    | Departing                                                    | Staying | Needed by both?                              |
| ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- | ------------------------------------------------------------ | ------- | -------------------------------------------- |
| `setUpBeforeClass()` (`require_once` for `EnumeratedSite`, `ThresholdDirectiveScan`)                                                                                                                                                                                                                          | yes                                                          | yes     | **yes, but boilerplate**                     |
| `SEEDED_FIXTURE` const                                                                                                                                                                                                                                                                                        | yes (both departing methods)                                 | no      | no (only the two methods that move together) |
| `phpFileHashes()` (private)                                                                                                                                                                                                                                                                                   | yes (`itKeepsEverySeededFixtureFileOutOfSrc`)                | no      | no                                           |
| `contentIdentity()` (private static)                                                                                                                                                                                                                                                                          | yes (`itKeepsTheSeededDirectivesOutOfTheEnumerationOverSrc`) | no      | no                                           |
| `FIXTURE` const, `productSites()`, `scanSites()`, `scanRawSites()`, `readingsOf()`, `productReadingOf()`, `thresholds()`, `addressedBy()`, `complaintIn()`, `documentedNodes()`, `rangeOf()`, `within()`, `lineAuthoring()`, `methods()`, `temporaryTree()`, `source()`, `$trees`/`tearDown()`/`removeTree()` | no                                                           | yes     | no                                           |

**Verdict: clean, modulo duplicating `setUpBeforeClass()`.**

---

## Summary table

| #   | File                                                                                        | Departing cases | Staying cases | Verdict                                                                 |
| --- | ------------------------------------------------------------------------------------------- | --------------: | ------------: | ----------------------------------------------------------------------- |
| 1   | `Analysis/Configuration/Unit/ConfigSchemaTest.php`                                          | 7               | 3             | clean                                                                   |
| 2   | `Analysis/Evidence/ComputedMetrics/Integration/BenchmarkConsumersCoverageTest.php`          | 1               | 5             | clean                                                                   |
| 3   | `Analysis/Evidence/Measurement/Integration/Identity/ClassProducerOrdinalTest.php`           | 2               | 16            | needs duplicate (`PRODUCERS` const)                                     |
| 4   | `Analysis/Evidence/Measurement/Integration/Identity/RatchetKeyGrammarTest.php`              | 1               | 7             | needs duplicate (`parse()`)                                             |
| 5   | `Analysis/Finding/Integration/ConfigurationErrorClassificationTopologyTest.php`             | 3               | 4             | clean                                                                   |
| 6   | `Analysis/Finding/Unit/Exclusion/ConfiguredSuppressionTest.php`                             | 1               | 2             | clean                                                                   |
| 7   | `Analysis/Policy/Architecture/Integration/ModularArchitectureGovernanceIntegrationTest.php` | 4               | 3             | needs duplicate (`root()`, `runProcess()`)                              |
| 8   | `Analysis/Policy/Baseline/Functional/BaselineCommandOptionSurfaceTest.php`                  | 1               | 13            | clean                                                                   |
| 9   | `Analysis/Policy/Baseline/Unit/ChannelRenameMapTest.php`                                    | 1               | 19            | clean                                                                   |
| 10  | `Infrastructure/Unit/RuleRegistryTest.php`                                                  | 1               | 5             | clean                                                                   |
| 11  | `Reporting/Formatter/Sarif/Integration/SarifRuleDescriptorCoverageTest.php`                 | 2               | 1             | needs duplicate (`finding()`)                                           |
| 12  | `Unit/Core/Util/GlobSyntaxTest.php`                                                         | 1               | 7             | clean                                                                   |
| 13  | `Unit/Core/Util/NamespaceMatcherTest.php`                                                   | 1               | 47            | clean                                                                   |
| 14  | `Unit/Core/VersionTest.php`                                                                 | 1               | 2             | clean                                                                   |
| 15  | `Unit/PromiseEffect/LedgerVocabularyTest.php`                                               | 1               | 4             | clean (boilerplate `setUpBeforeClass` dup)                              |
| 16  | `Unit/RuleVocabulary/DirectiveAuditReportReadingTest.php`                                   | 3               | 26            | clean (boilerplate `setUpBeforeClass` dup)                              |
| 17  | `Unit/RuleVocabulary/ThresholdPopulationAgreementTest.php`                                  | 2               | 25            | clean (boilerplate `setUpBeforeClass` dup)                              |
|     | **Totals**                                                                                  | **33**          | **189**       | 10 clean, 3 clean-with-boilerplate-dup, 4 need a real duplicate/Support |

No file in this set is unsplittable. "Needs duplicate" always means a small
(single constant or single short private method), not a structural blocker.

---

## Where notes diverge from code

`controls-verdict-notes.md` documents an **earlier wave with 14 mixed files**;
the current `controls-verdict.tsv` has 17. Method names (not the notes' line
numbers, which the notes file itself calls stale) were checked against the
current TSV `scope` column and the source:

1. **`ConfigSchemaTest` — major undercount.** Notes item 1 names only
   `itLeavesNoConstantUnreferencedByAConsumer` as the method to extract. The
   current TSV `scope` column lists **seven** departing methods. The classifier
   became substantially more thorough between the notes' wave and the current
   TSV for this file; the notes' split instruction is stale by 6 methods.

2. **`ConfigurationErrorClassificationTopologyTest` — undercount by one.** Notes
   item 5 says "the first two methods (L67-99, L117-154) move out". The current
   TSV scope has **three**: it also includes
   `itRefusesAProductionSiteThatHandsTheFlagToTheConstructorInstead`, which the
   notes text never mentions in the split instructions (only implicitly, in the
   file's own docblock about "three halves").

3. **`RuleRegistryTest` — contradicted, not just stale.** Notes' "Divergences
   from wave 1" section explicitly reclassifies this file as full **test**
   ("there is no filesystem access at all, only the container and reflection").
   Reading the code shows `itResolvesEveryCliAliasToARealRuleOption` builds its
   registry from `(new ContainerFactory())->create()->get(RuleRegistryInterface::class)`
   — the real, fully-wired registry, which is exactly what makes it a
   repo-control per the classifier's own letter (quantifies over "every rule in
   the real registry"). The current TSV's `mixed` verdict is the more accurate
   one; the notes' "test" reclassification does not survive rereading this
   method.

4. **`LedgerVocabularyTest` and `DirectiveAuditReportReadingTest` — notes'
   blanket claim doesn't hold at method granularity.** Notes' "third class"
   section lists both files among "ten control verdicts" resting purely on
   "the test's subject is code living in `scripts/`, pulled in via
   `require_once`" — implying the whole file is control. The current TSV
   correctly narrows this to specific methods (1 of 5 for `LedgerVocabularyTest`,
   3 of 23 for `DirectiveAuditReportReadingTest`); the remaining methods build
   their own fixture input (a mutated ledger TSV, a hand-built JSON report) and
   test the tool's *behavior*, which is tooling-test, not control, by the same
   classifier the notes themselves apply elsewhere. Confirmed by reading both
   files in full.

5. **`ModularArchitectureGovernanceIntegrationTest` — notes underdescribe the
   split.** Notes discuss this file only in the "what duplicates mechanisms the
   repository already has" section, as one deletable method
   (`itChecksEveryGeneratedProjectionWithoutWriting`, a literal duplicate of
   `composer architecture:check`) plus "the file's other six methods do not form
   a duplicate". Notes never describe this file as needing a control/test split
   at all. The current TSV verdict is `mixed` with **four** departing control
   methods (not one), and the split needs `root()`/`runProcess()` duplicated —
   information entirely absent from the notes.

6. **`SarifRuleDescriptorCoverageTest` — a shared helper notes don't mention.**
   Notes item 11 says the two coverage methods move out "along with the private
   helper that does `is_file()` and searches ... in `website/docs`" (i.e.
   `checkChannel()`), implying a clean split. Rereading the code: `checkChannel()`
   calls `self::finding()`, and the **staying** method
   (`itKeepsTheHumanisedFallbackAndTheRepositoryUrlForAnUnknownCode`) also calls
   `self::finding()` directly (line 216). This file needs a small duplicate the
   notes did not flag.

7. **Files in the notes' 14-item "how to split" list that are absent from the
   current 17-file mixed set:** `RuleDocsPageCoverageTest`,
   `RuleRemediationMinutesCoverageTest`, `ChannelLevelAssemblyTopologyTest`
   (notes items 6, 7, 4). These three are not in the current `mixed` set at all
   — either reclassified (to `control` or `test`) or removed/renamed between
   waves. **Not verified against current code** — they are outside this task's
   17-file scope, and this is reported as an input-coverage gap, not a
   contradiction of a claim I checked.

Everything else in the notes' 14-item list that overlaps the current 17
(`ClassProducerOrdinalTest`, `RatchetKeyGrammarTest`, `ConfiguredSuppressionTest`,
`BaselineCommandOptionSurfaceTest`, `ChannelRenameMapTest`, `GlobSyntaxTest`,
`NamespaceMatcherTest`, `VersionTest`) matches the current TSV `scope` column by
method name exactly, and the code confirms the notes' clean-split / needs-a-copy
calls for those files.

## Assumptions and choices made

- Case counts are the literal `--list-tests` line count per method, split on
  the **first** `::` after the fully-qualified class name (per-method, not
  per-dataset, since dataset names can themselves contain `::`).
- "Departing" = every method named in the TSV `scope` column for that row;
  "staying" = every other `#[Test]` method in the file. No sub-method split was
  attempted even where a method's own reason text describes a mixed rationale
  (e.g. `ConfigSchemaTest::itReturnsTheCorrectSubKeysPerSection`) — the TSV
  verdict is per-method, and the whole method was treated as departing since it
  is named in `scope`.
- "`clean (boilerplate setUpBeforeClass dup)`" is reported as `clean` in the
  summary table's category, not `needs duplicate`, because the shared payload is
  a single `require_once` line with no logic — duplicating it is mechanical and
  has no correctness cost, unlike the four files with a genuinely shared
  constant or method.
- `ModularArchitectureGovernanceIntegrationTest`'s
  `itChecksEveryGeneratedProjectionWithoutWriting` also carries the separate
  "delete as a literal duplicate of `composer architecture:check`" question
  raised in the notes file. That question is orthogonal to the control/test
  split and is not resolved here — this file's split-map row only answers
  "where does this method's *code* go if the file is split", not "should this
  method be deleted first".
