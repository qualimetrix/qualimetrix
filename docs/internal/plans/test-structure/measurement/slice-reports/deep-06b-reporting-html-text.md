# Deep read — Reporting Html/Text/Summary slice (wave 2, second half)

19 files had their bodies read in full (all 18 from the list + the bonus
ArchitectureViolationSmokeTest.php). No file was skipped, none was too much to handle.

Files:
1. tests/Unit/Reporting/Formatter/HtmlFormatterTest.php (184 lines)
2. tests/Unit/Reporting/Formatter/Html/HtmlTreeBuilderTest.php (898)
3. tests/Unit/Reporting/Formatter/Html/HtmlDebtCalculatorTest.php (175)
4. tests/Unit/Reporting/Formatter/Html/HtmlFindingPartitionerTest.php (437)
5. tests/Unit/Reporting/Formatter/Html/HtmlMetricAggregatorTest.php (216)
6. tests/Unit/Reporting/Formatter/SummaryFormatterTest.php (1288)
7. tests/Unit/Reporting/Health/SummaryEnricherTest.php (511)
8. tests/Unit/Reporting/Formatter/TextFormatterTest.php (618)
9. tests/Unit/Reporting/Formatter/TextVerboseFormatterTest.php (297)
10. tests/Unit/Reporting/Formatter/HealthTextFormatterTest.php (406)
11. tests/Unit/Reporting/Formatter/Summary/HealthBarRendererTest.php (542)
12. tests/Unit/Reporting/Formatter/Summary/HintRendererTest.php (351)
13. tests/Unit/Reporting/Formatter/Support/DetailedFindingRendererTest.php (408)
14. tests/Unit/Reporting/Formatter/Summary/FindingSummaryRendererTest.php (420)
15. tests/Unit/Reporting/Formatter/Summary/TopIssuesRendererTest.php (455)
16. tests/Unit/Reporting/Formatter/Summary/OffenderListRendererDensityTest.php (307)
17. tests/Reporting/FindingProjection/Unit/SuppressionCompositionBuilderTest.php (488)
18. tests/Reporting/FindingProjection/Unit/ConfigurationErrorProjectionTest.php (341)
19. tests/Unit/Reporting/Formatter/ArchitectureViolationSmokeTest.php (551, bonus — was listed as unread)

## Table

| File                    | Method                             | Line    | Defect class | Confirmed by                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                     |
| ----------------------- | ---------------------------------- | ------- | ------------ | ---------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| SummaryEnricherTest.php | `itTypingNotAddedWhenNoDimensions` | 487–509 | duplicate    | Byte-for-byte identical to the body of `itHealthScoresEmptyWhenNoProjectHealthMetrics` (295–317): the same `MetricBag::fromArray(['complexity.ccn.avg' => 5.0, 'size.loc' => 1000])`, the same `Report` assembly, the same single assertion `self::assertSame([], $result->healthScores)`. Verified via `diff` after renaming the method — 0 discrepancies. Both names describe the same fact ("no health.* metrics → healthScores is empty"), just phrased differently — a duplicate, not merely a similar test |

## A borderline case (not a defect, noted separately)

`SummaryEnricherTest.php`: `itReturnsUnchangedReportWhenNoMetrics` (53–71, metrics not passed at
all — default is used) and `itNullMetricsReturnsUnchangedReport` (354–370, metrics passed
explicitly as `null`) — the bodies differ (the second checks fewer fields: only
`$result === $report` and `healthScores === []`, while the first additionally checks
`worstNamespaces`, `worstClasses`, `techDebtMinutes`). Not a byte-for-byte duplicate, so I don't
include it in the defect table, but in fact the second test is a strict subset of the first and
adds no coverage beyond checking that an explicit `null` behaves like the default (which is also
not useless, but sits implicitly in the borderline zone between "duplicates" and "extends").

## Clean

All the other 18 files (and 17 of 18 methods in SummaryEnricherTest.php) — no tautologies, no
duplicates, no name/body mismatch:

- **HtmlFormatterTest, HtmlDebtCalculatorTest, HtmlMetricAggregatorTest,
  HtmlFindingPartitionerTest, HtmlTreeBuilderTest** — every test builds a fresh figure
  (findings/metrics/tree), the assertion checks either arithmetic (LOC sums, a weighted health
  average, debt minutes 30/60/75 via `RemediationTimeRegistry`), or a tree/partitioning
  structure that the test didn't plug in as a constant itself but obtained by running the SUT.
  NaN/Inf zeroing, JSON_HEX_TAG escaping, falling back to a namespace node when there's no class
  node — every case is separate and distinguishable.
- **SummaryFormatterTest.php (44 tests)** — the largest file in the slice; every test combines
  its own set of findings/healthScores/worstNamespaces/worstClasses and checks the
  corresponding output fragment (`assertStringContainsString`/`NotContainsString`), including
  numerically derived values (debt "1h 30min", "2.5 min/kLOC", weighted health 45%). The color
  thresholds (`itColorsScoreBoundaryAtWarningThreshold` / `...GreenAboveWarningThreshold` /
  `...RedAtErrorThreshold`) deliberately check boundary values (50.0 / 50.1 / 30.0) — not
  duplicates, different points on the same boundary.
- **TextFormatterTest, TextVerboseFormatterTest, HealthTextFormatterTest** — pinning a string
  format (`itOmitsTheAcceptedLevelFragmentWhenAbsent` carries an explicit comment "Regression
  pin: byte-for-byte") — this is a declared format contract, not a tautology; see the brief's
  rule about pinning wording.
- **HealthBarRendererTest.php** — includes a check of the bar-width arithmetic
  (`itCalculatesBarWidthCorrectly`, 30 characters, 15 `#`/15 `.` at score=50) and a C2-delta
  fixture with a real `MetricRepositoryInterface` mock, computing childScore via
  `2*overallScore - flatScore` — not a planted expected number, an honest calculation.
- **HintRendererTest, DetailedFindingRendererTest, FindingSummaryRendererTest,
  TopIssuesRendererTest, OffenderListRendererDensityTest** — a common pattern: different
  Offender/Finding fixtures → different text fragments, including sorting
  (`itReordersOffendersWhenRankingByDensity`,
  `itSortsNullDensityOffendersLastWhenRankingByDensity`) via a `strpos` position comparison,
  which genuinely checks order, not merely substring presence.
- **SuppressionCompositionBuilderTest.php** — explicitly designed as "one test = one mechanism"
  (see the class docblock), plus two guard tests against specific bugs (a ledger-producer/
  `ruleName` mismatch, overlapping global exclude patterns) — an exemplary structure, not a
  tautology.
- **ConfigurationErrorProjectionTest.php** — "one test per way to exit the pipeline"
  (Suppression, PathExclusion, NamespaceExclusion, Baseline, GitScope) + a separate test for
  measuredFindings — a deliberately similar structure (this is a classifier, not a tautology:
  every test targets its own pipeline stage).
- **ArchitectureViolationSmokeTest.php** — one test per formatter
  (Text/TextVerbose/Json/MetricsJson/Html/Checkstyle/Sarif/GitLab/Health/Summary/GithubActions),
  each checking a format-specific output structure (XML validity, counts via
  `count($run['results'])`, a regex on `::error|warning|notice`) — not duplicates, honest
  per-format smoke coverage.

## What this method cannot see

- **Only the method bodies**, not the SUT. I did not read the classes `HtmlTreeBuilder`,
  `SummaryFormatter`, `SuppressionCompositionBuilder`, etc. themselves — arithmetic (30 min for
  `complexity.ccn`, a weighted average of 87.5, a bar width of 30) is checked against the tests'
  own comments and internal consistency, not against an independent recomputation from the SUT's
  original formula. If a comment and the SUT's code diverge, this kind of defect won't be
  caught.
- **Duplicates between wave 1 and wave 2 files were not checked** — the comparison ran only
  within wave 2 (18+1 files), not against the remaining ~660 files in the slice. The same
  `itFormatsWithNullMetrics` / `itBuildsWithNullMetrics` pattern could repeat in the first
  agent's JSON/Sarif group — not checked (the brief's boundary: "don't touch its files").
- **Duplicate detection was a line-by-line diff of two clearly similar candidates**, not a
  systematic, programmatic normalization of all ~600 method bodies in this slice (clustering by
  a hash of the normalized body). One duplicate was found because the brief pointed at it
  directly and I checked the file in full; less obvious pairs (structurally similar but with one
  differing field) could have gone unnoticed — as the borderline case
  `itReturnsUnchangedReportWhenNoMetrics`/`itNullMetricsReturnsUnchangedReport` above shows,
  near-duplicates exist beyond the one explicitly found.
- **Wording pin vs format contract** — the boundary was assessed manually (code comments like
  "Regression pin"), not by an automatic criterion; in disputed cases (e.g.
  `assertStringContainsString` on specific text like "sub-namespaces raise the score" in
  HealthBarRendererTest) the decision "this is a format contract" was made by context, not
  formally proven.
