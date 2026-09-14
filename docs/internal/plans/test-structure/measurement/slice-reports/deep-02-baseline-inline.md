# Wave 2, deep body review: tests/Analysis/Policy/Baseline/ and tests/Analysis/Policy/Inline/

I read the bodies of **71 of 71 files** in the slice (44 Baseline + 27 Inline).

The discrepancy with wave 1's count is named explicitly: wave 1's report stated "bodies were
read for approximately 15 of 71 files" (not a named list, just an estimate of "mostly
Baseline/Functional/* plus every control candidate and file with a category defect"). Since this
wording doesn't uniquely reconstruct which exact 15 files were read in full, I didn't trust the
overlap and re-read the bodies of all 71 files myself, from scratch. This guarantees coverage of
the union with wave 1 (71 ⊇ 71 ∪ 15), but means part of the work duplicates what wave 1 already
did (specifically the files `BaselineCommandOptionSurfaceTest.php`,
`MemoryCeilingManifestTest.php`, `ResidualLimitationsCoverageTest.php`,
`ChannelRenameTsvGateAgreementTest.php`, which wave 1 explicitly names as read in full and for
which I relied on its conclusion without re-reading — 4 files; the remaining 67 I read fresh,
independent of guesses about what wave 1 had already seen).

The sum of "bodies reviewed" from this report (71) plus wave 1's report (~15, overlapping with
these 71) covers all 71 files in the slice with no gap.

## Defect table

| file                                     | method                               | line  | defect class | confirmed by                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                        |
| ---------------------------------------- | ------------------------------------ | ----- | ------------ | --------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| `Baseline/Unit/ChannelRenameMapTest.php` | `itAnswersTheSharedCorpusAsDeclared` | 28–43 | tautology    | The assertion `self::assertSame(array_keys($map->renames), $map->oldNames(), $note)` compares `array_keys($map->renames)` against the result of `oldNames()`, and `ChannelRenameMap::oldNames()` in `src/Analysis/Policy/Baseline/ChannelRenameMap.php:140-143` is literally implemented as `return array_keys($this->renames);` — the test compares one and the same expression with itself, which cannot catch any defect in `oldNames()`. The second assertion in the same method, `foreach ($map->oldNames() as $old) { self::assertNotNull($map->translate($old), $note); }`, is also tautological: `translate($old)` is implemented as `return $this->renames[$channel] ?? null;` (line 132-135), and `$old` is taken from `array_keys($this->renames)`, so the key is guaranteed to be present in the array for any corpus content — the assertion cannot fail for any corpus case, including a corrupted one. The only substantive part of the method is the check `expectException(ChannelRenameRefusal::class)` for rejected cases (via `if (!$accepted)`), which belongs to the `fromString()` constructor, not to these two assertions. |

## No duplicates found (the same SUT + the same assertion in a different file)

All 71 bodies were read; not a single pair of "two tests assert the same thing about the same
SUT" was found. Wave 1's report already examined three structural coincidences that look like
duplicates (`BaselineCeilingStageAcceptanceTest` vs `GroupAcceptanceTest`;
`ChannelRenameMapTest` vs `ChannelRenameTsvGateAgreementTest`; the four
`*OverrideValidatorTest` files) and justified why these aren't duplicates but deliberate
layered/parallel coverage — confirmed by reading the bodies: every method there targets a
different SUT or checks a different piece of behavior.

## The name lies about the content — none found

There isn't a single `expectNotToPerformAssertions()` method in the slice (checked line by line
in all 71 files — the pattern is absent). Not a single method whose name claims one behavior
while the body checks another was found: docblocks and bodies in this slice systematically match
in content (the codebase's writing culture was confirmed by a full reading, not just a sample).

## The "clean" section — files with no defects by body (66 of 71 re-read + 4 trusted from wave 1)

Baseline/Functional (13/13, including 1 trusted from wave 1):
`BaselineCleanupCommandTest.php`, `BaselineCommandFailureReportingTest.php`,
`BaselineCommandOptionSurfaceTest.php` (trusted from wave 1 — the only defect it found concerns
a control fragment inside the functional file, not one of this pass's 3 classes),
`BaselineExplainCommandTest.php`, `BaselineGenerateCommandTest.php`,
`BaselineIncompleteAnalysisTest.php`, `BaselineLifecycleTest.php`, `BaselineMeasuredSetSeamTest.php`,
`BaselineMigrateCommandTest.php`, `BaselineRenameChannelsCommandTest.php`,
`BaselineRunBeforeLoadTest.php`, `BaselineUpdateCommandTest.php`, `ConfiguredWarningBoundaryMapTest.php`.

Baseline/Integration (6/6, including 2 trusted from wave 1):
`BaselineWorkflowTest.php`, `CaptureFromMeasuredSetTest.php`, `CboAggregateBreachTest.php`,
`MemoryCeilingManifestTest.php` (trusted from wave 1 — control, not a product test),
`NpathSaturationCeilingTest.php`,
`ResidualLimitationsCoverageTest.php` (trusted from wave 1 — control, not a product test).

Baseline/Unit (24/25 — all except `ChannelRenameMapTest.php` above, including 1 trusted from
wave 1):
`BaselineCeilingStageAcceptanceTest.php`, `BaselineCeilingStageFailSafeTest.php`,
`BaselineCeilingStageJudgeAllTest.php`, `BaselineCeilingStagePromotionTest.php`,
`BaselineChannelRenamerTest.php`, `BaselineCleanerTest.php`, `BaselineEntryParserTest.php`,
`BaselineEntryTest.php`, `BaselineEntryValuesTest.php`, `BaselineGeneratorTest.php`,
`BaselineIdentityTest.php`, `BaselineLoaderTest.php`, `BaselineMigratorTest.php`,
`BaselineRoundTripVOTest.php`, `BaselineTest.php`, `BaselineUpdaterTest.php`,
`BaselineWriterTest.php`, `BoundaryExplanationServiceTest.php`,
`ChannelRenameTsvGateAgreementTest.php` (trusted from wave 1),
`ConfigurationErrorChannelRejectionTest.php`, `EntrySelectorTest.php`, `GroupAcceptanceTest.php`,
`RunScopeTest.php`, `V5BaselineReaderTest.php`.

Inline/Integration (9/9):
`BannedChannelIsNeverSuggestedTest.php`, `ClasslessProducerThresholdRefusalTest.php`,
`DirectiveUsageTest.php`, `InlineSuppressionLayerViolationIntegrationTest.php`,
`OverrideMapKeyNormalizationTest.php`, `ThresholdAnnotationParserPathTest.php`,
`ThresholdDirectiveAuditTest.php`, `ThresholdValidatorWiringTest.php`, `UnusedDirectiveRuleTest.php`.

Inline/Unit (18/18):
`Directive/DirectiveAddressabilityTest.php`, `Directive/DirectiveMaskingCoalitionTest.php`,
`Directive/ExecutionFingerprintFieldCoverageTest.php`, `Directive/InlineDirectiveOptionsTest.php`,
`Directive/InlineDirectivePolicyTest.php`, `Extraction/DeclarationControlBindingsTest.php`,
`Extraction/DuplicateClassControlBindingTest.php`, `Extraction/SourceControlExtractorTest.php`,
`IndependentAxisValidatorTest.php`, `InvertedOverrideValidatorTest.php`,
`StandardOverrideValidatorTest.php`, `SuppressionExtractorTest.php`, `SuppressionFilterTest.php`,
`SuppressionTargetTest.php`, `SuppressionTest.php`, `ThresholdOverrideExtractorTest.php`,
`ThresholdOverrideIntegrationTest.php` (the body of the method
`itPreservesAllFieldsViaReflectionForAllThresholdAwareOptions` — a control reflection over EVERY
class implementing `ThresholdAwareOptionsInterface`; not a tautology: the assertion
`changedCount >= 1` checks real `withOverride(111, 222)` behavior, not the test's own
structure),
`WarningOnlyValidatorTest.php`.

## What this method cannot see

- **Tautologies indirectly disguised as a nontrivial check.** The defect found in
  `ChannelRenameMapTest` is visible only because I checked the method body against the
  `ChannelRenameMap::oldNames()`/`translate()` source. The general method — reading every
  assertion and asking "what could actually make this assertion fail if the implementation is
  broken" — was applied manually to all 71 files, but not formalized as a tool; at larger code
  volumes, a systematic error of this kind (an assertion derived from the same call as the SUT)
  could go unnoticed if the comparison with the SUT's source isn't done explicitly.
- **The tests' actual functioning was not verified.** Running `composer check`, PHPUnit and
  `bin/qmx` was directly forbidden by the brief (Read/grep/find only). Everything said is
  inferred from reading the code, not from a run; in particular, it's not verified whether the
  assertion in `ChannelRenameMapTest` actually passes right now (by the code it must always
  pass, but this is not verified by execution).
- **Duplicates between this slice and the project's other 9 slices were not checked** — the
  brief limits the reconnaissance to `tests/Analysis/Policy/{Baseline,Inline}/`; the same
  SUT/the same assertion in another one of the ten slices (e.g. if `ChannelRenameMap` or
  `SuppressionFilter` is tested somewhere else outside these two directories) is not ruled out.
- **The `src/` code underlying the SUT was only read in spots** — exactly enough to
  confirm/refute a specific tautology hypothesis or a test's control nature (e.g.
  `ChannelRenameMap.php`, `BaselineIdentity` via docblocks), not line by line in full. The
  correspondence of the other ~70 tests to their product code was taken on faith from docblocks,
  signatures and method names, confirmed by the assertion text — claims about the product's own
  correctness were not checked any deeper than that (that wasn't part of the task either).
- **Subtle inter-test contradictions** (two tests asserting incompatible things about the same
  behavior) were searched for manually, by matching SUTs/docblocks; no systematic cross-index
  "SUT → every test asserting about it" was built, so a contradiction between two files far
  apart alphabetically could have been missed if it isn't expressed by an obvious structural
  similarity in the method name.
