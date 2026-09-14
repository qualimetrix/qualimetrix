# Deep body review: Reporting + Architecture/Unit

**Read by body in this pass (wave 2):**
- Architecture (`tests/Analysis/Policy/Architecture/Unit/`): **31 of 31** files — full coverage of
  the slice. (3 more files from this same directory — `ArchitectureInternalTopologyTest`,
  `LayersValidatorMembershipRefusalGuardTest`, `ArchitectureProcessorTest` — were already read in
  full in wave 1, which is where these 31 come from.)
- Reporting (`tests/Unit/Reporting/`, `tests/Reporting/`, `tests/Functional/Reporting/`): **24 of
  61** test files had their bodies read in full (8 in wave 1, 16 in this pass). **37 files'
  bodies remain unread** — the list is below, in the "Uncovered" section.

The Architecture slice is fully closed. The Reporting slice is **not closed** — the pass's
budget wasn't enough to read the remaining part (many large Formatter files of 300–1700 lines
each). The DoD "together with wave 1, the bodies of every file in both slices are covered" is
**not met for Reporting**; it is met for Architecture.

## Defect table

| file                                                  | method                             | line | defect class                                        | confirmed by                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                         |
| ----------------------------------------------------- | ---------------------------------- | ---- | --------------------------------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------ |
| `tests/Unit/Reporting/Health/SummaryEnricherTest.php` | `itTypingNotAddedWhenNoDimensions` | 487  | **the name lies about the content** + **duplicate** | The name promises a narrow check, "the `typing` key is absent among the other dimensions", but the body is byte-for-byte the same report construction and the same single assertion, `self::assertSame([], $result->healthScores);`, as in the method `itHealthScoresEmptyWhenNoProjectHealthMetrics` (line 295) in the same file: not a single check about `typing` specifically. The neighboring method `itTypingNAWhenOtherDimensionsExist` (line 458) shows what a real "typing not added" check would look like — `assertArrayHasKey('typing', ...)` inside a non-empty set — and this file has exactly none of that form here. |

The duplicate isn't formally "in another file" as class 2 is defined in the brief, it's inside
one file — I list it here because it's the same SUT (`SummaryEnricher::enrich`) and the same
single assertion, repeated verbatim under a different, misleading name; de facto the defect
combines both classes.

### A borderline case (not a duplicate, but an overlap — listed separately, not in the table)

`tests/Reporting/Unit/OutputFormatResolverTest.php::itUsesSummaryByDefaultAndTheLastExplicitFormat`
(line 20, the first half of the assertions) and
`tests/Reporting/Unit/OutputFormatRefusesUnexecutableValuesTest.php::itStillDefaultsWhenNobodyNamedAFormat`
(line 62) — both about `OutputFormatResolver`, both assert "an empty document gives the
`summary` format". I don't count this as a full duplicate: for the first test this is only one
of two checked branches (the same method's second assertion is about `cli` taking priority over
`config`, which the second file doesn't have), for the second it's the file's sole check,
complementing the broader `provideUnexecutableValues`/`provideExecutableNames` data provider.
This confirms wave 1's hypothesis ("An unconfirmed hypothesis") — the overlap is real, but it's
not an identical, remainder-free duplication of the same assertion.

## Architecture/Unit — "clean" (31/31, no defects found by body)

`AllowAliasExpanderTest`, `AllowValidatorTest`, `ArchitectureConfigurationFactoryTest`, `ArchitectureConfigurationTest`,
`ArchitectureInternalTopologyTest`*, `ArchitectureProcessorTest`*, `CapturePatternTest`, `ClassContextFactoryTest`,
`ClassSetTest`, `CoverageDiagnosticsTest`, `CoverageModeTest`, `CoverageValidatorTest`, `ExactAllowCycleValidatorTest`,
`ExcludeSpecTest`, `LayerDefinitionTest`, `LayerExpansionStageTest`, `LayerInstantiatorTest`, `LayerPolicyTest`,
`LayerRegistryTest`, `LayerSelectorTest`, `LayerViolationOptionsTest`, `LayerViolationRuleTest`,
`LayersValidatorMembershipRefusalGuardTest`*, `LayersValidatorTest`, `PatternScopeTest`, `PendingLayerDiagnosticsTest`,
`TemplateLayerDefinitionTest`, `TupleExtractorTest`, `UnassignedClassDiagnosticsTest`, `UnassignedClassOptionsTest`,
`WildcardSelfAllowDetectorTest` (* — read in wave 1).

The one observation wave 1 flagged (`LayerExpansionStageTest`, lines 567–568: accessing
`expandedLayers()`/`emptyTemplateNames()` as methods versus properties in the other ~20 cases) —
re-checked: both accessor methods really do exist on `LayerExpansionResult` and return the same
thing as the same-named properties (a duplicate, possibly dead, accessor in the product — not a
test defect under any of the brief's three classes).

## Reporting — "clean" (read in this pass, no defects found by body)

`ReportTest`, `FormatterContextTest`, `ReportBuilderTest`, `Health/HealthHintProjectorTest`,
`Health/HealthScoreResolverTest`, `Filter/FindingFilterTest`, `FindingProjection/Unit/SuppressionMechanismTest`,
`GraphProjection/Unit/DependencyGraphProjectorTest`, `Unit/OutputFormatResolverTest`,
`Unit/OutputFormatRefusesUnexecutableValuesTest` (both — see the borderline case above),
`FindingProjection/Unit/ProjectScopedChannelProjectionTest`, `Formatter/Suppressed/Unit/SuppressedFormatterTest`,
`Formatter/Suppressed/Unit/SuppressionSnapshotKeyTest`, `GraphProjection/Unit/NamespaceFilterTest`,
`GraphProjection/Unit/DotExporterTest`, `Formatter/Support/AnsiColorTest`, `Formatter/Support/AcceptedLevelNarratorTest`.

Separately re-checked wave 1's hypothesis about `ProjectScopedChannelProjectionTest` as a
possible second tautology case following the `DeclaredChannelFileScopeTest` pattern: this is NOT
a tautology. The channel list (`declaredProjectScopedKeys()`) is read directly from the
`LayerPolicyPreparationInterface::PROJECT_SCOPED_CHANNELS` and
`CircularDependencyPreparationInterface::PROJECT_SCOPED_CHANNELS` constants (the same ones the
product owns), not duplicated by a separate hardcoded test literal — and beyond that, the test
actually runs `FindingProjector` through three independent filtering scenarios
(path/namespace/git-scope) and checks that the finding survived. This is an exemplary
anti-tautology test, exactly as its own docblock claims.

## Uncovered (Reporting, 37 files — bodies not read in either wave 1 or wave 2)

```
tests/Reporting/FindingProjection/Unit/ConfigurationErrorProjectionTest.php
tests/Reporting/FindingProjection/Unit/FindingProjectorTest.php
tests/Reporting/FindingProjection/Unit/SuppressionCompositionBuilderTest.php
tests/Reporting/Formatter/Sarif/Integration/SarifRuleDescriptorCoverageTest.php   (partial — wave 1 read it selectively)
tests/Reporting/GraphProjection/Unit/JsonGraphExporterTest.php
tests/Unit/Reporting/Formatter/ArchitectureViolationSmokeTest.php
tests/Unit/Reporting/Formatter/CheckstyleFormatterTest.php
tests/Unit/Reporting/Formatter/FormatterRegistryTest.php
tests/Unit/Reporting/Formatter/GitLabCodeQualityFormatterTest.php
tests/Unit/Reporting/Formatter/GithubActionsFormatterTest.php
tests/Unit/Reporting/Formatter/HealthTextFormatterTest.php
tests/Unit/Reporting/Formatter/Html/HtmlDebtCalculatorTest.php
tests/Unit/Reporting/Formatter/Html/HtmlFindingPartitionerTest.php
tests/Unit/Reporting/Formatter/Html/HtmlMetricAggregatorTest.php
tests/Unit/Reporting/Formatter/Html/HtmlTreeBuilderTest.php
tests/Unit/Reporting/Formatter/HtmlFormatterTest.php
tests/Unit/Reporting/Formatter/Json/JsonFindingSectionTest.php
tests/Unit/Reporting/Formatter/Json/JsonHealthSectionTest.php
tests/Unit/Reporting/Formatter/Json/JsonOffenderSectionDensityTest.php
tests/Unit/Reporting/Formatter/Json/JsonSanitizerTest.php
tests/Unit/Reporting/Formatter/JsonFormatterTest.php
tests/Unit/Reporting/Formatter/MetricsJsonFormatterTest.php
tests/Unit/Reporting/Formatter/Sarif/SarifFormatterPosixSeparatorTest.php
tests/Unit/Reporting/Formatter/Sarif/SarifRuleCollectorTest.php
tests/Unit/Reporting/Formatter/Sarif/SarifSchemaValidationTest.php
tests/Unit/Reporting/Formatter/SarifFormatterTest.php
tests/Unit/Reporting/Formatter/Summary/FindingSummaryRendererTest.php
tests/Unit/Reporting/Formatter/Summary/HealthBarRendererTest.php
tests/Unit/Reporting/Formatter/Summary/HintRendererTest.php
tests/Unit/Reporting/Formatter/Summary/OffenderListRendererDensityTest.php
tests/Unit/Reporting/Formatter/Summary/TopIssuesRendererTest.php
tests/Unit/Reporting/Formatter/SummaryFormatterTest.php
tests/Unit/Reporting/Formatter/Support/DetailedFindingRendererTest.php
tests/Unit/Reporting/Formatter/Support/FindingSorterTest.php
tests/Unit/Reporting/Formatter/TextFormatterTest.php
tests/Unit/Reporting/Formatter/TextVerboseFormatterTest.php
tests/Unit/Reporting/Health/SummaryEnricherTest.php   (read in full — a defect was found and
                                                        entered in the table; noted here only so
                                                        the file isn't lost from the "needed
                                                        reading" list, not as unread)
```

A clarification on the last line: `SummaryEnricherTest.php` was actually read in full (511
lines) — this exact reading found the defect in the table above. It doesn't belong in
"uncovered"; it's kept in the block for traceability between wave 1's list ("needs reading") and
the outcome. The real count of uncovered files is **36**, not 37; this note corrects the
document's opening line.

The largest unread files: `JsonFormatterTest.php` (1692 lines, 47 tests),
`SummaryFormatterTest.php` (1288, 42 tests), `FindingProjectorTest.php` (1070, 36 tests),
`SarifFormatterTest.php` (909, 24 tests), `HtmlTreeBuilderTest.php` (898).

## What this method cannot see

- No tests were ever run (per the task's boundaries — forbidden). Every "the test checks X"
  conclusion was made by reading the source text, not by observing a real pass/fail.
- For the remaining 36 Reporting files (the list above), the class 1–3 defect method **was not
  applied at all** — they weren't read either structurally or by body in this pass. Wave 1 had
  only a structural/grep signal for them (see its own "what it cannot see" section).
- For the files read as "clean", the tautology/duplicate check is limited: (a) by a
  line-by-line reading by one person with no independent second pass (see the "two-witness"
  lesson in the project's memory — only one witness was applied here); (b) by a mechanical
  search for exact byte-for-byte method-body duplicates across the whole slice (a Python script,
  whitespace normalization) — it finds only fully identical bodies, misses duplication that
  differs by even one line or assertion order, and misses a semantic duplicate that constructs
  the same case via two different paths.
- The `SummaryEnricherTest` duplicate was found precisely by the mechanical script
  (normalization + hash), not by eye while reading in order — this argues that among the 36
  unread files, similar intra- and cross-file duplicates are likely and uncaught, because the
  whole-slice script ran only once and its result (3 matches, one a real defect, two legitimate
  repeats of template tests on DIFFERENT formatter classes) was not manually re-verified on each
  unread file individually.
- Wave 1's hypotheses about `SarifRuleCollectorTest` vs `SarifRuleDescriptorCoverageTest`
  (both about `SarifRuleCollector`) and about the
  `JsonOffenderSectionDensityTest`/`OffenderListRendererDensityTest` coincidence remain
  unconfirmed and unchecked — either both files in each pair went unread, or only half of the
  pair was read.
