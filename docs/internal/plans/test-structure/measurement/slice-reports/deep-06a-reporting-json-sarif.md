18 of 18 files were read in full, including the bodies of every method (not just the
structure/method list).

## Files and their paths (for reference)

1. tests/Unit/Reporting/Formatter/JsonFormatterTest.php (1692 lines)
2. tests/Unit/Reporting/Formatter/Json/JsonFindingSectionTest.php (502 lines)
3. tests/Unit/Reporting/Formatter/Json/JsonHealthSectionTest.php (225 lines)
4. tests/Unit/Reporting/Formatter/Json/JsonOffenderSectionDensityTest.php (198 lines)
5. tests/Unit/Reporting/Formatter/Json/JsonSanitizerTest.php (127 lines)
6. tests/Reporting/GraphProjection/Unit/JsonGraphExporterTest.php (389 lines)
7. tests/Unit/Reporting/Formatter/MetricsJsonFormatterTest.php (363 lines)
8. tests/Unit/Reporting/Formatter/SarifFormatterTest.php (909 lines)
9. tests/Unit/Reporting/Formatter/Sarif/SarifFormatterPosixSeparatorTest.php (139 lines)
10. tests/Unit/Reporting/Formatter/Sarif/SarifRuleCollectorTest.php (382 lines)
11. tests/Reporting/Formatter/Sarif/Integration/SarifRuleDescriptorCoverageTest.php (242 lines)
12. tests/Unit/Reporting/Formatter/Sarif/SarifSchemaValidationTest.php (262 lines)
13. tests/Unit/Reporting/Formatter/CheckstyleFormatterTest.php (426 lines)
14. tests/Unit/Reporting/Formatter/GitLabCodeQualityFormatterTest.php (612 lines)
15. tests/Unit/Reporting/Formatter/GithubActionsFormatterTest.php (398 lines)
16. tests/Unit/Reporting/Formatter/FormatterRegistryTest.php (207 lines)
17. tests/Reporting/FindingProjection/Unit/FindingProjectorTest.php (1070 lines)
18. tests/Unit/Reporting/Formatter/Support/FindingSorterTest.php (243 lines)

## Table: file | method | line | defect class | confirmed by

No formal defects of the "tautology / duplicate / name lies about the content" classes were
found in this slice. The table is empty; below is a "clean" section with the borderline cases
that were examined and rejected as not fitting the definition.

## Clean

All 18 files carry substantive assertions checking the real behavior of the SUT (the
Json/Sarif/Checkstyle/GitLab/Github formatters, JsonSanitizer, JsonGraphExporter,
FormatterRegistry, FindingSorter, FindingProjector) against independently constructed
expectations (constants, explicitly given values, a real DI container), not against lists copied
from the SUT itself.

Borderline cases examined and rejected:

1. **FormatterRegistryTest::itDeclaresKeysOfFormattersHiddenFromListings**
   (FormatterRegistryTest.php:142) — the test uses the magic name `'text-verbose'`, matching the
   private constant `FormatterRegistry::HIDDEN_FORMATTERS`. This is coupling to an
   implementation detail, but NOT a tautology by the brief's definition: the test doesn't
   reproduce the SUT's list alongside it, it relies on a single hardcoded literal with the same
   value. The behavior itself (hiding from the listing) is genuinely checked.

2. **GitLabCodeQualityFormatterTest::itKeepsLegacyFingerprintsAndSeparatesTargetOnlyEdges**
   (GitLabCodeQualityFormatterTest.php:252) and
   **SarifFormatterTest::itKeepsLegacyFingerprintsAndSeparatesTargetOnlyEdges**
   (SarifFormatterTest.php:116) — an identical method name and nearly identical fixture
   structure (4 edge findings, magic numbers `:15:`/`:14:` in the expected strings). NOT a
   duplicate by the brief's definition: different SUTs (GitLabCodeQualityFormatter versus
   SarifFormatter), different result formats (GitLab hashes with md5, Sarif compares the raw
   `primaryLocationLineHash` string with no hashing) — this is parallel coverage of one identity
   contract through two different formatters, not a repeat of the same assertion.

3. **JsonFormatterTest::itIncludesShownCountInFindingsMeta** (JsonFormatterTest.php:1186, 55
   findings) and **itShowsShownEqualsTotalInFindingsMeta** (JsonFormatterTest.php:1215, 10
   findings) — overlapping coverage (both check `shown === total` under default options), but
   the bodies aren't identical: a different number of findings, the first also checks
   `limit === null`. Not a duplicate in the strict sense (no byte-for-byte identical bodies
   under different names).

4. **JsonFormatterTest::itIncludesAllFindingsByDefault** (detailLimit unset → null) and
   **itShowsAllFindingsWhenDetailEnabled** (detailLimit: 0) — verified: the default of
   `FormatterContext::$detailLimit` is `null`, not `0` (see
   src/Reporting/FormatterContext.php:42). These are two different configurations (detail mode
   off entirely / explicitly on with "no limit"), not a duplicate setup with a coincidentally
   matching result — not a defect.

5. **SarifRuleDescriptorCoverageTest::UNIVERSE_CHANNEL_COUNT = 58** and the **DOCS_BASE_URI**
   literal (SarifRuleDescriptorCoverageTest.php:45,54) — hardcoded constants duplicating the
   SUT's/environment's actual values. Both are explicitly and thoroughly documented in the
   docblocks as a deliberate decision (the channel count — with an explanation of where it comes
   from; DOCS_BASE_URI — "duplicated on purpose so the test asserts the collector's actual
   output against an independently stated expectation"). Not a tautology: the comparison is
   against `SarifRuleCollector`'s real output, not against itself.

6. **MetricsJsonFormatterTest::itGivesEveryDeclarationKindAPublicationPosition**
   (MetricsJsonFormatterTest.php:356) — reads the `MetricsJsonFormatter::DECLARATION_KINDS`
   constant via Reflection and compares it against `SymbolType::cases()`. This is not a
   tautology: the source of truth (the `SymbolType` enum) is independent of the SUT's constant,
   the test genuinely checks the invariant "every enum case has a publication position".

7. **SarifFormatterPosixSeparatorTest** (both checks) — the input `RelativePath` values are
   already given in POSIX form (`'src/Sub/Dir/Foo.php'`), so the test cannot directly demonstrate
   converting backslashes → slashes on input. But this is not a tautology: it checks that the
   formatter does NOT introduce backslashes in the output
   (`assertStringNotContainsString('\\', $uri)`), which is a real (though weaker than the name
   might suggest) regression pin, backed by a docblock referencing ADR 0015.

## What this method cannot see

- **No test was run and no DI container was built.** Some tests (SarifRuleCollectorTest,
  SarifFormatterTest, SarifRuleDescriptorCoverageTest) build a real container and check against
  real channel descriptions — the correctness of the descriptions themselves (not the test's
  structure) was not verified.
- **Reading without running catches no semantic defects at the level of "the assertion is
  technically correct, but the SUT itself has a bug"** — only the test's form was verified (what
  it checks and how), not whether the expected behavior matches the product's intent beyond what
  the docblocks document.
- **Magic numbers in fixture strings (`:15:`, `:14:` in the fingerprint tests) were not traced
  to the SUT's formula** — the code producing these offsets (probably path/string lengths) was
  not opened, so it can't be ruled out that the numbers themselves were obtained by copying the
  SUT's actual output rather than by independent calculation. This is a blind spot by
  construction: the test body is the sole source; verifying the number's independence would have
  required reading the formatter's code and computing by hand.
- **Duplicates between this slice and the remaining 661 files of the slice were not checked** —
  the task was limited to the 18 files of the first half; another agent reads the second half,
  overlaps were not cross-checked.
- **Whitespace/formatting beyond the method body was not checked** (e.g. whether
  `#[CoversClass]` really names the correct class) — read by eye while reading the use section,
  not separately verified via Serena/grep.
