#!/usr/bin/env php
<?php

declare(strict_types=1);

use Qualimetrix\ModularArchitecture\ProcessOutput;

require_once __DIR__ . '/modular-architecture/ProcessOutput.php';

/**
 * Generates test-topology evidence for modular-architecture governance.
 *
 * The inventory is intentionally based on the committable worktree (tracked
 * plus non-ignored untracked files) and PHPUnit's own discovery output. It
 * fails closed when a test disappears, a discovered class cannot be mapped to
 * a file, or two artifacts converge on one target without an explicit
 * migration disposition.
 */

const OUTPUT_DIRECTORY = 'docs/internal/generated/modular-architecture';
const TEST_LEVELS = ['Unit', 'Integration', 'Functional'];
// 65 paths. Stage 05 moved four of them from Unit/ to Integration/ --
// BaselineChannelRenamerTest, BaselineRoundTripVOTest, BaselineWriterTest and
// ConfigurationErrorChannelRejectionTest -- because their bodies do real work,
// not because the set changed: no file entered or left the tree, and the count
// is the same on both sides of the move. Re-hash only against a diff of the
// path list; a digest refreshed to make the generator run again asserts nothing.
const P6_C_BASELINE_PATHS_SHA256 = 'c8620ffa4e9199f0acd82954a306942839a9e5c3ada3e51daa878b2d347867b8';

$arguments = $_SERVER['argv'] ?? [];
$check = in_array('--check', $arguments, true);
$outputDirectoryArguments = array_values(array_filter(
    array_slice($arguments, 1),
    static fn(string $argument): bool => str_starts_with($argument, '--output-directory='),
));
$classificationProbeArguments = array_values(array_filter(
    array_slice($arguments, 1),
    static fn(string $argument): bool => str_starts_with($argument, '--classification-probe='),
));
$discoveryProbeArguments = array_values(array_filter(
    array_slice($arguments, 1),
    static fn(string $argument): bool => str_starts_with($argument, '--discovery-probe='),
));
if (count($outputDirectoryArguments) > 1) {
    fail('Only one --output-directory path may be provided.');
}
if (count($classificationProbeArguments) > 1) {
    fail('Only one classification probe path may be provided.');
}
if (count($discoveryProbeArguments) > 1) {
    fail('Only one discovery probe path may be provided.');
}
$unknownArguments = array_values(array_filter(
    array_slice($arguments, 1),
    static fn(string $argument): bool => $argument !== '--check'
        && !str_starts_with($argument, '--output-directory=')
        && !str_starts_with($argument, '--classification-probe=')
        && !str_starts_with($argument, '--discovery-probe='),
));
if ($unknownArguments !== []) {
    fail('Unknown argument: ' . implode(', ', $unknownArguments));
}

/** @var array<string, string> */
const ORPHAN_CANDIDATE_PREFIXES = [
    'tests/Fixture/DataClassEntity.php' => 'No live test references this fixture class.',
    'tests/Fixtures/Aggregation/' => 'No live test reads or analyses this fixture directory.',
    'tests/Fixtures/CircularDeps/' => 'Tests use equivalent FQNs but do not read these files.',
    'tests/Fixtures/CouplingProject/' => 'No live test reads or analyses this fixture directory.',
    'tests/Fixtures/Inheritance/' => 'No live test reads or analyses this fixture directory.',
];

/** @var list<string> JSON fixtures are covered by a repository-wide ignore rule until staged. */
const P4_IGNORED_FIXTURE_PATHS = [
    'tests/Analysis/Policy/Architecture/Fixtures/Sample/expected-violations.json',
    'tests/Analysis/Policy/Architecture/Fixtures/Sample/phase1-compat-violations-warn.json',
    'tests/Analysis/Policy/Architecture/Fixtures/Sample/phase1-compat-violations.json',
];

/**
 * @var list<string> The Measurement fixtures governed as one closed set. Their six
 *                   test-class siblings left with the stage-04 path parse, which
 *                   precedes this list in all three classifiers.
 */
const P7_MEASUREMENT_PATHS = [
    'tests/Analysis/Evidence/Measurement/Fixtures/AnonymousClassContext.php',
    'tests/Analysis/Evidence/Measurement/Fixtures/pdepend-collision.xml',
    'tests/Analysis/Evidence/Measurement/Fixtures/pdepend-fqn.xml',
    'tests/Analysis/Evidence/Measurement/Fixtures/phpmetrics-fqn.json',
    'tests/Analysis/Evidence/Measurement/Fixtures/qmx-current.json',
    'tests/Analysis/Evidence/Measurement/Fixtures/qmx-incomplete-coverage.json',
    'tests/Analysis/Evidence/Measurement/Fixtures/qmx-malformed-coverage.json',
    'tests/Analysis/Evidence/Measurement/Fixtures/qmx-missing-coverage.json',
    'tests/Analysis/Evidence/Measurement/Fixtures/qmx-polluted.txt',
    'tests/Analysis/Evidence/Measurement/Fixtures/qmx-stale-keys.json',
];

/**
 * The single source for every tooling test root: which directory (or, for
 * html-report/, single file) and which subject owner, in the order
 * registration happened.
 * Every one of these files, once retained in place, is written straight into
 * its final home, so `classifyOwner()`, `dispositionFor()`/`targetPath()`
 * (via `isRegisteredToolingRoot()`) and the `git ls-files` scan-scope pathspec
 * all read this map instead of repeating its keys — a key named here once
 * reaches all three. Two checks cross-check the map against the tree itself,
 * independently of this file, one per direction:
 * `assertToolingTestRootRegistrationIsComplete()` answers "a registered key
 * that is no longer there", for a key of any shape; and
 * `assertEveryTestDirectoryIsScanned()` answers "a test directory the tree
 * carries that this generator never scans", for a directory anywhere in the
 * tree rather than only under `scripts/` and `tools/`. That second one is
 * deliberately not phrased as "that nothing registered": a registration which
 * does not put the directory in {@see inventoryScanScope()} leaves it exactly
 * as invisible as no registration at all, and an earlier draft that accepted
 * one is why the distinction is spelled out here. See each function's own
 * docblock for the source it judges against.
 *
 * @var array<string, string>
 */
const TOOLING_TEST_ROOT_OWNERS = [
    'governance/' => 'Architecture.Governance',
    'tools/phpstan/tests/' => 'Tooling/PhpStan',
    'scripts/promise-effect/tests/' => 'Tooling/PromiseEffect',
    'scripts/directive-audit/tests/' => 'Tooling/DirectiveAudit',
    'scripts/directive-audit-controls/tests/' => 'Tooling/DirectiveAuditControls',
    'scripts/tautology-controls/tests/' => 'Tooling/TautologyControls',
    'scripts/finding-gate/tests/' => 'Tooling/FindingGate',
    'scripts/suppression-snapshot/tests/' => 'Tooling/SuppressionSnapshot',
    'scripts/rename-enumeration/tests/' => 'Tooling/RenameEnumeration',
    'scripts/health-calibration/tests/' => 'Tooling/HealthCalibration',
    'scripts/benchmark/tests/' => 'Tooling/Benchmark',
    'scripts/modular-architecture/tests/' => 'Tooling/ModularArchitecture',
    'scripts/cross-tool-comparison/tests/' => 'Tooling/CrossToolComparison',
    'scripts/phpunit-aggregate/tests/' => 'Tooling/PhpunitAggregate',
    // A root-level, non-PSR-4 npm project outside both scripts/ and tools/, so
    // it is outside actualToolingTestRootsOnDisk()'s glob the same way
    // governance/ is — assertToolingTestRootRegistrationIsComplete() checks
    // it by direct existence instead, and assertEveryTestDirectoryIsScanned()
    // is what would have refused for html-report/tests/ had this entry never
    // been written. Two file keys beside the directory key
    // because the retained slice is not one directory: the viewer's own
    // package.json and vite.config.js sit beside tests/, not under it, and
    // nothing else under html-report/ (report.html, dist/, src/*.js,
    // dev.html, package-lock.json, README.md) is a test artifact.
    'html-report/tests/' => 'HtmlReport',
    'html-report/package.json' => 'HtmlReport',
    'html-report/vite.config.js' => 'HtmlReport',
];

/**
 * The basenames that make a directory test-shaped, for the sweep in
 * {@see assertEveryTestDirectoryIsScanned()}.
 *
 * `tests` is the only one the tree uses today; the other four are the spellings
 * the ecosystems that would land the next root-level project reach for first —
 * a vitest or jest project writes `__tests__` or `test` as readily as `tests`.
 * Widening the *source* this way costs nothing measurable (all five together
 * name exactly the sixteen directories `tests` alone names) and it is the
 * source, not the exclusion list, that may be generous: a directory this set
 * names and nothing claims is refused, so a spelling missing here is a root the
 * sweep cannot see, while a spelling too many is at worst one more literal in
 * {@see NON_SCANNED_TEST_DIRECTORIES} the day some directory innocently uses it.
 *
 * @var list<string>
 */
const TEST_DIRECTORY_BASENAMES = ['tests', 'test', 'Tests', '__tests__', 'spec'];

/**
 * Path segments that disqualify a directory from the sweep's source outright.
 *
 * The two halves are not in the same position, and calling both "defensive"
 * was wrong:
 *
 * - `vendor` is **load-bearing**. `.gitignore`'s `/vendor/` is anchored to the
 *   repository root — which is exactly why `/benchmarks/vendor/` needed a line
 *   of its own. Any other nested `vendor/` is not ignored, would be listed by
 *   `--others`, and is held out only by this constant. The tree carries
 *   `composer.json` files outside the repository root, so the directory that
 *   would trip this is one `composer install` away. Do not remove it as dead
 *   weight; it is not dead.
 * - `node_modules` is unanchored in `.gitignore` and does hold everywhere, so
 *   this half is the defensive one. It is written anyway because the rule has
 *   to read as itself: a control whose correctness rests on a second file's
 *   contents changes meaning when that file does, in silence and at a
 *   distance, and `node_modules/` is one `!` away from being un-ignored by
 *   someone solving an unrelated problem — at which point an npm dependency's
 *   own `test/` directory would be refused as an unregistered root of this
 *   repository.
 *
 * @var list<string>
 */
const NON_PROJECT_PATH_SEGMENTS = ['vendor', 'node_modules'];

/**
 * Test-shaped directories this generator deliberately does not scan — the set
 * subtracted from {@see assertEveryTestDirectoryIsScanned()}'s source, spelled
 * out as literals so that it grows with the filter it excuses rather than
 * hiding inside a pattern.
 *
 * A pattern would have done the job in fewer characters and is the reason this
 * is a list: a glob for "anything under a `fixtures` directory" excuses the
 * entry below, and it goes on excusing every future directory that happens to
 * sit under one — including a real test root someone files there by mistake. A
 * literal excuses one path and says so by name.
 *
 * Every direction is checked, so an entry cannot outlive its reason: an entry
 * naming a path the sweep no longer finds is refused as stale, an entry naming
 * a path this generator does scan is refused as redundant, and an entry whose
 * reason is blank is refused outright — an excuse that does not explain itself
 * is the thing this list exists to prevent.
 *
 * @var array<string, string> path (trailing slash) => why it is not this repository's to scan
 */
const NON_SCANNED_TEST_DIRECTORIES = [
    'input-doors/fixtures/main/tests/' => 'the test directory of the fixture project the input-door stand'
        . ' analyses, not a test directory of this repository: its one file is input to a measurement, and'
        . ' running it as a test of this tree is exactly what it must not do.',
];

/** @var list<string> Exact Run test classes; future siblings require an ownership decision. */
const P3_TEST_PATHS = [
    'tests/Analysis/Configuration/Unit/ConfigSchemaCoverageTest.php',
    'tests/Analysis/Configuration/Integration/ConfigurationPipelineIntegrationTest.php',
    'tests/Analysis/Policy/Architecture/Integration/ArchitectureConfigurationWarningIntegrationTest.php',
    'tests/Analysis/Configuration/Integration/FullPipelineIntegrationTest.php',
    'tests/Analysis/Configuration/Integration/PresetIntegrationTest.php',
    'tests/Analysis/Finding/Integration/RuleOptionKeyNormalizationTest.php',
    'tests/Analysis/Configuration/Unit/AnalysisConfigurationTest.php',
    'tests/Analysis/Configuration/Unit/ConfigSchemaTest.php',
    'tests/Analysis/Configuration/Unit/ConfigurationHolderTest.php',
    'tests/Analysis/Configuration/Unit/Discovery/ComposerReaderTest.php',
    'tests/Analysis/Configuration/Unit/Loader/YamlConfigLoaderTest.php',
    'tests/Analysis/Configuration/Unit/Pipeline/ConfigDataNormalizerTest.php',
    'tests/Analysis/Configuration/Unit/Pipeline/ConfigurationMergerTest.php',
    'tests/Analysis/Configuration/Unit/Pipeline/ConfigurationPipelineTest.php',
    'tests/Analysis/Configuration/Unit/Pipeline/RuleNameValidatorTest.php',
    'tests/Analysis/Configuration/Unit/Pipeline/Stage/CliStageTest.php',
    'tests/Analysis/Configuration/Unit/Pipeline/Stage/ComposerDiscoveryStageTest.php',
    'tests/Analysis/Configuration/Unit/Pipeline/Stage/ConfigFileStageTest.php',
    'tests/Analysis/Configuration/Unit/Pipeline/Stage/DefaultsStageTest.php',
    'tests/Analysis/Configuration/Unit/Pipeline/Stage/PresetStageTest.php',
    'tests/Analysis/Configuration/Unit/Preset/PresetResolverTest.php',
    'tests/Analysis/Finding/Unit/RuleNamespaceExclusionProviderTest.php',
    'tests/Analysis/Finding/Unit/RuleOptionsFactoryTest.php',
    'tests/Analysis/Finding/Unit/RuleOptionsParserTest.php',
    'tests/Analysis/Finding/Unit/RulePathExclusionProviderTest.php',
    'governance/ThresholdKeys/RuleThresholdKeyGroupRegistryDriftTest.php',
    'tests/Analysis/Evidence/DependencyModel/Unit/Extraction/DependencyResolverTest.php',
    'tests/Analysis/Evidence/DependencyModel/Unit/Extraction/DependencyVisitorTest.php',
    'tests/Analysis/Evidence/DependencyModel/Unit/Extraction/Handler/TypeDependencyHelperTest.php',
    'tests/Analysis/Evidence/Measurement/Integration/Aggregation/GoldenFileAggregationTest.php',
    'tests/Analysis/Evidence/Measurement/Integration/Aggregation/MetricInvariantTest.php',
    'tests/Analysis/Evidence/Measurement/Unit/Aggregation/AggregationHelperTest.php',
    'tests/Analysis/Evidence/Measurement/Unit/Aggregation/ClassToNamespaceAggregatorTest.php',
    'tests/Analysis/Evidence/Measurement/Unit/Aggregation/GlobalCollectorSorterTest.php',
    'tests/Analysis/Evidence/Measurement/Unit/Aggregation/GlobalFunctionAggregationTest.php',
    'tests/Analysis/Evidence/Measurement/Unit/Aggregation/MeasurementAggregationServiceTest.php',
    'tests/Analysis/Evidence/Measurement/Unit/Aggregation/MetricAggregatorTest.php',
    'tests/Analysis/Evidence/Measurement/Unit/Aggregation/NamespaceMetricContributionsTest.php',
    'tests/Analysis/Evidence/Measurement/Unit/Aggregation/NamespaceToProjectAggregatorTest.php',
    'tests/Analysis/Evidence/Measurement/Unit/Aggregation/TreeAwareNamespaceAggregatorTest.php',
    'tests/Analysis/Evidence/Measurement/Unit/FileMeasurement/CompositeCollectorTest.php',
    'tests/Analysis/Evidence/Measurement/Unit/FileMeasurement/DerivedCollectorRunnerTest.php',
    'tests/Analysis/Evidence/Measurement/Unit/FileMeasurement/DerivedCollectorSortTest.php',
    'tests/Analysis/Evidence/Measurement/Unit/FileMeasurement/DerivedMetricExtractorTest.php',
    'tests/Analysis/Evidence/Measurement/Unit/Namespace_/ProjectNamespaceResolverTest.php',
    'tests/Analysis/Evidence/Measurement/Unit/Repository/InMemoryMetricRepositoryTest.php',
    'tests/Analysis/Evidence/Measurement/Unit/Repository/MetricSubjectIndexTest.php',
    'tests/Analysis/Evidence/Measurement/Unit/Repository/NamespaceMetricIndexTest.php',
    'tests/Analysis/Evidence/Measurement/Unit/Repository/RepositoryMergeTest.php',
    'tests/Analysis/Run/Integration/Pipeline/AnalysisPipelineIntegrationTest.php',
    'tests/Analysis/Run/Integration/Pipeline/MultiNamespaceAnalysisTest.php',
    'tests/Analysis/Run/Unit/Collection/CollectionOrchestratorTest.php',
    'tests/Analysis/Run/Unit/Collection/FileProcessingResultTest.php',
    'tests/Analysis/Run/Unit/Collection/FileProcessorTest.php',
    'tests/Analysis/Run/Unit/Contract/Collection/CollectionPhaseOutputTest.php',
    'tests/Analysis/Run/Unit/Discovery/FinderFileDiscoveryAbsolutePathTest.php',
    'tests/Analysis/Run/Unit/Discovery/FinderFileDiscoveryTest.php',
    'tests/Analysis/Run/Unit/Discovery/AnalysisFileDiscoveryTest.php',
    'tests/Analysis/Run/Unit/Discovery/GeneratedFileFilterTest.php',
    'tests/Analysis/Run/Unit/FileSetInspection/FileSetInspectionCompositeTest.php',
    'tests/Analysis/Run/Unit/Pipeline/AnalysisCoverageTest.php',
    'tests/Analysis/Run/Unit/Pipeline/AnalysisPipelineTest.php',
    'tests/Analysis/Run/Unit/Pipeline/AnalysisResultTest.php',
    'tests/Analysis/Run/Unit/Pipeline/DependencyGraphAnalyzerTest.php',
    'tests/Analysis/Run/Unit/RuleProducerPreparationTest.php',
    'tests/Analysis/Finding/Unit/RuleExclusionStatsTest.php',
    'tests/Analysis/Finding/Unit/RuleExecutionTest.php',
    'tests/Infrastructure/Console/Unit/CheckScopeResolverTest.php',
    'tests/Infrastructure/Console/Unit/RuntimeLoggerConfiguratorTest.php',
];

/** @var list<string> Exact Finding test closure; future siblings require an ownership decision. */
const P6_A_FINDING_TEST_PATHS = [
    'governance/Channel/Fixtures/declared.txt',
    'governance/Channel/Fixtures/excluded.txt',
    'tests/Analysis/Finding/Integration/ChannelCoverageTest.php',
    'governance/Channel/ChannelDeclarationFixtureDriftTest.php',
    'governance/Channel/ChannelEmissionStaticGuardTest.php',
    'tests/Analysis/Finding/Integration/RuleOptionKeyNormalizationTest.php',
    'tests/Analysis/Finding/Support/StubChannelDeclarationRegistry.php',
    'tests/Analysis/Policy/Baseline/Support/FindingFactory.php',
    'tests/Analysis/Finding/Unit/AbstractRuleThresholdSeamTest.php',
    'tests/Analysis/Finding/Unit/AcceptedLevelTest.php',
    'tests/Analysis/Finding/Unit/AnalysisContextTest.php',
    'tests/Infrastructure/DependencyInjection/Unit/CompilerPass/ChannelDeclarationCompilerPassTest.php',
    'tests/Analysis/Finding/Unit/ChannelDeclarationReaderTest.php',
    'tests/Analysis/Finding/Unit/ChannelDeclarationTest.php',
    'tests/Analysis/Finding/Unit/LocationNullFileTest.php',
    'tests/Analysis/Finding/Unit/LocationTest.php',
    'tests/Analysis/Finding/Unit/NamespaceExclusionFilterTest.php',
    'tests/Analysis/Finding/Unit/OccurrenceKeyTest.php',
    'tests/Analysis/Finding/Unit/PathExclusionFilterTest.php',
    'tests/Analysis/Finding/Unit/PredicateFilterStageTest.php',
    'tests/Analysis/Finding/Unit/RuleExecutionTest.php',
    'tests/Analysis/Finding/Unit/RuleNameReaderTest.php',
    'tests/Analysis/Finding/Unit/RuleNamespaceExclusionProviderTest.php',
    'tests/Analysis/Finding/Unit/RuleOptionsFactoryTest.php',
    'tests/Analysis/Finding/Unit/RuleOptionsParserTest.php',
    'tests/Analysis/Finding/Unit/RulePathExclusionProviderTest.php',
    'tests/Analysis/Finding/Unit/RuleSelectorTest.php',
    'governance/ThresholdKeys/RuleThresholdKeyGroupRegistryDriftTest.php',
    'tests/Analysis/Finding/Unit/SeverityTest.php',
    'tests/Analysis/Finding/Unit/ThresholdParserTest.php',
    'governance/ThresholdKeys/ThresholdValidatorAssignmentTest.php',
    'tests/Analysis/Finding/Unit/FindingChannelTest.php',
    'tests/Analysis/Finding/Unit/FindingFilterStageTest.php',
    'tests/Analysis/Finding/Unit/FindingTest.php',
];

/** @var list<string> Exact Inline additions to the Finding test closure. */
const P6_B_FINDING_TEST_PATHS = [
    'tests/Analysis/Finding/Unit/AnalysisContextThresholdTest.php',
    'tests/Analysis/Finding/Unit/ThresholdOverrideTest.php',
];

/** @var list<string> Exact Inline test closure; future siblings require an ownership decision. */
const P6_B_INLINE_TEST_PATHS = [
    'tests/Analysis/Policy/Inline/Fixtures/IgnoreSample/Controller/PolicedController.php',
    'tests/Analysis/Policy/Inline/Fixtures/IgnoreSample/Controller/SilencedController.php',
    'tests/Analysis/Policy/Inline/Fixtures/IgnoreSample/Domain/Customer.php',
    'tests/Analysis/Policy/Inline/Fixtures/IgnoreSample/Repository/CustomerRepository.php',
    'tests/Analysis/Policy/Inline/Fixtures/IgnoreSample/Service/CustomerService.php',
    'tests/Analysis/Policy/Inline/Integration/InlineSuppressionLayerViolationIntegrationTest.php',
    'tests/Analysis/Policy/Inline/Integration/ThresholdAnnotationParserPathTest.php',
    'tests/Analysis/Policy/Inline/Integration/ThresholdOverrideIntegrationTest.php',
    'tests/Analysis/Policy/Inline/Integration/ThresholdValidatorWiringTest.php',
    'tests/Analysis/Policy/Inline/Unit/Extraction/DeclarationControlBindingsTest.php',
    'tests/Analysis/Policy/Inline/Unit/IndependentAxisValidatorTest.php',
    'tests/Analysis/Policy/Inline/Unit/InvertedOverrideValidatorTest.php',
    'tests/Analysis/Policy/Inline/Unit/Extraction/SourceControlExtractorTest.php',
    'tests/Analysis/Policy/Inline/Unit/StandardOverrideValidatorTest.php',
    'tests/Analysis/Policy/Inline/Unit/SuppressionExtractorTest.php',
    'tests/Analysis/Policy/Inline/Unit/SuppressionFilterTest.php',
    'tests/Analysis/Policy/Inline/Unit/SuppressionTest.php',
    'tests/Analysis/Policy/Inline/Unit/ThresholdOverrideExtractorTest.php',
    'tests/Analysis/Policy/Inline/Unit/WarningOnlyValidatorTest.php',
];

/**
 * @var list<string> The Prioritization support class the subject owns. Its five
 *                   test-class siblings left with the stage-04 path parse, which
 *                   precedes this list in all three classifiers.
 */
const P6_D_PRIORITIZATION_TEST_PATHS = [
    'tests/Analysis/Evidence/Prioritization/Support/StubRemediationMinutes.php',
];

/** @var list<string> Exact live additions relative to the accepted 509/7,245 authority. */
const P6_LIVE_ADDED_TEST_IDS = [
    'Qualimetrix\\Tests\\Analysis\\Finding\\Unit\\RuleExecutionTest::itPublishesRuleMetadataWithExactAliasMappingWithoutConcreteRuleInstances',
    'Qualimetrix\\Tests\\Analysis\\Finding\\Unit\\RuleNamespaceExclusionProviderTest::itConfiguresAndQueriesNamespaceExclusionsWithoutProviderAccess',
    'Qualimetrix\\Tests\\Analysis\\Finding\\Unit\\RuleNamespaceExclusionProviderTest::itConfiguresAndQueriesNamespaceChannelExclusionsWithoutProviderAccess',
    'Qualimetrix\\Tests\\Analysis\\Policy\\Inline\\Unit\\Extraction\\SourceControlExtractorTest::itExtractsSourceControlsWithoutRunDeclarationBindings',
    'Qualimetrix\\Tests\\Infrastructure\\Git\\Integration\\ReportingGitScopeQueryProjectSubdirTest::itProjectsGitScopeThroughTheReportingPortWithoutAReverseImport',
    'Qualimetrix\\Tests\\Analysis\\Run\\Integration\\Pipeline\\AnalysisPipelineIntegrationTest::itPreservesInlineControlsAcrossARealParallelWorkerRoundTrip',
];

/** @var array<string, string> Exact zero-net method-ID replacements. */
const P6_RENAMED_TEST_IDS = [
    'Qualimetrix\\Tests\\Unit\\Infrastructure\\DependencyInjection\\CompilerPass\\RuleCompilerPassTest::itCollectsTaggedRulesIntoRuleExecutor' => 'Qualimetrix\\Tests\\Infrastructure\\DependencyInjection\\Unit\\CompilerPass\\RuleCompilerPassTest::itCollectsTaggedRulesIntoRuleExecution',
    'Qualimetrix\\Tests\\Integration\\DependencyInjection\\ContainerFactoryTest::itInjectsRulesIntoRuleExecutor' => 'Qualimetrix\\Tests\\Infrastructure\\DependencyInjection\\Integration\\ContainerFactoryTest::itInjectsRulesIntoRuleExecution',
    'Qualimetrix\\Tests\\Integration\\Infrastructure\\Console\\RuleExclusionStatsWiringTest::itSharesTheSameRuleExecutorInstanceBetweenThePipelineAndTheOrchestrator' => 'Qualimetrix\\Tests\\Infrastructure\\Integration\\RuleExclusionStatsWiringTest::itSharesTheSameRuleExecutionInstanceBetweenThePipelineAndTheOrchestrator',
    'Qualimetrix\\Tests\\Infrastructure\\Console\\Functional\\Command\\CheckCommandBaselineTest::itDoesNotPromoteAnAnnotatedFindingTheBaselineNeverMeasured' => 'Qualimetrix\\Tests\\Infrastructure\\Console\\Functional\\Command\\CheckCommandBaselineTest::itCombinesConfiguredAndCliExclusionsWithoutLosingBaselineAnnotationOrGit',
];

/** @var array<string, array{string, string, string}> Exact A03 orphan-probe dispositions. */
const P8_ORPHAN_DISPOSITIONS = [
    'tests/Fixture/DataClassEntity.php' => ['DataClassEntity', 'delete', 'No consumer; isolated static and process probes passed.'],
    'tests/Fixtures/Aggregation/App/Repository/OrderRepository.php' => ['Aggregation', 'delete', 'No consumer; isolated static and process probes passed.'],
    'tests/Fixtures/Aggregation/App/Repository/UserRepository.php' => ['Aggregation', 'delete', 'No consumer; isolated static and process probes passed.'],
    'tests/Fixtures/Aggregation/App/Service/OrderService.php' => ['Aggregation', 'delete', 'No consumer; isolated static and process probes passed.'],
    'tests/Fixtures/Aggregation/App/Service/PaymentService.php' => ['Aggregation', 'delete', 'No consumer; isolated static and process probes passed.'],
    'tests/Fixtures/Aggregation/App/Service/UserService.php' => ['Aggregation', 'delete', 'No consumer; isolated static and process probes passed.'],
    'tests/Fixtures/Aggregation/README.md' => ['Aggregation', 'delete', 'No consumer; isolated static and process probes passed.'],
    'tests/Fixtures/CircularDeps/HelperA.php' => ['CircularDeps', 'delete', 'No path consumer; equivalent FQNs are independently covered.'],
    'tests/Fixtures/CircularDeps/HelperB.php' => ['CircularDeps', 'delete', 'No path consumer; equivalent FQNs are independently covered.'],
    'tests/Fixtures/CircularDeps/IndependentService.php' => ['CircularDeps', 'delete', 'No path consumer; equivalent FQNs are independently covered.'],
    'tests/Fixtures/CircularDeps/README.md' => ['CircularDeps', 'delete', 'No path consumer; equivalent FQNs are independently covered.'],
    'tests/Fixtures/CircularDeps/ServiceA.php' => ['CircularDeps', 'delete', 'No path consumer; equivalent FQNs are independently covered.'],
    'tests/Fixtures/CircularDeps/ServiceB.php' => ['CircularDeps', 'delete', 'No path consumer; equivalent FQNs are independently covered.'],
    'tests/Fixtures/CircularDeps/ServiceC.php' => ['CircularDeps', 'delete', 'No path consumer; equivalent FQNs are independently covered.'],
    'tests/Fixtures/Inheritance/BaseEntity.php' => ['Inheritance', 'delete', 'No consumer; isolated static and process probes passed.'],
    'tests/Fixtures/Inheritance/ChildEntity.php' => ['Inheritance', 'delete', 'No consumer; isolated static and process probes passed.'],
    'tests/Fixtures/Inheritance/ExtremelyDeepEntity.php' => ['Inheritance', 'delete', 'No consumer; isolated static and process probes passed.'],
    'tests/Fixtures/Inheritance/GrandChildEntity.php' => ['Inheritance', 'delete', 'No consumer; isolated static and process probes passed.'],
    'tests/Fixtures/Inheritance/GreatGrandChildEntity.php' => ['Inheritance', 'delete', 'No consumer; isolated static and process probes passed.'],
    'tests/Fixtures/Inheritance/README.md' => ['Inheritance', 'delete', 'No consumer; isolated static and process probes passed.'],
    'tests/Fixtures/Inheritance/VeryDeepEntity.php' => ['Inheritance', 'delete', 'No consumer; isolated static and process probes passed.'],
    'tests/Fixtures/CouplingProject/Core/AbstractEntity.php' => ['CouplingProject', 'move', 'AnalysisPipelineIntegrationTest reads the owner-local fixture path.'],
    'tests/Fixtures/CouplingProject/Core/EntityInterface.php' => ['CouplingProject', 'move', 'AnalysisPipelineIntegrationTest reads the owner-local fixture path.'],
    'tests/Fixtures/CouplingProject/Domain/Order.php' => ['CouplingProject', 'move', 'AnalysisPipelineIntegrationTest reads the owner-local fixture path.'],
    'tests/Fixtures/CouplingProject/Domain/User.php' => ['CouplingProject', 'move', 'AnalysisPipelineIntegrationTest reads the owner-local fixture path.'],
    'tests/Fixtures/CouplingProject/Isolated/StandaloneClass.php' => ['CouplingProject', 'move', 'AnalysisPipelineIntegrationTest reads the owner-local fixture path.'],
    'tests/Fixtures/CouplingProject/Service/OrderService.php' => ['CouplingProject', 'move', 'AnalysisPipelineIntegrationTest reads the owner-local fixture path.'],
    'tests/Fixtures/CouplingProject/Service/UserService.php' => ['CouplingProject', 'move', 'AnalysisPipelineIntegrationTest reads the owner-local fixture path.'],
];

/**
 * @var array<string, string> Artifacts a package listed and a later step then
 *                            removed. Kept because the alternative to naming
 *                            them is a closure that silently shrinks, and
 *                            checked in the other direction: a path here that
 *                            exists again is a defect too.
 */
const RETIRED_PATH_ASSERTIONS = [
    'tests/Analysis/Finding/Unit/ChannelDeclarationRegistryTest.php' => 'P6-A closure; the test was removed after the package.',
    'tests/Analysis/Finding/Unit/RuleExclusionCaptureHolderTest.php' => 'P6-A closure; the test was removed after the package.',
    'tests/Analysis/Finding/Unit/RuleLevelTest.php' => 'P6-A closure; the test was removed after the package.',
    'tests/Analysis/Finding/Unit/RuleMatcherTest.php' => 'P6-A closure; the test was removed after the package.',
    'tests/Analysis/Evidence/Measurement/Unit/Contract/CollectorRuntimeConfigurationTest.php' => 'P3 closure; the test was removed after the package.',
    'tests/Analysis/Run/Unit/Collection/Declaration/DeclarationBindingsTest.php' => 'P3 closure; the test was removed after the package.',
    'tests/Analysis/Run/Unit/Pipeline/MetricEnricherTest.php' => 'P3 closure; the test was removed after the package.',
    'tests/Infrastructure/Logging/LoggerFactoryTest.php' => 'The P8 LoggerFactory coverage consolidation described for this path has happened.',
    'tests/Unit/Infrastructure/Logging/LoggerFactoryTest.php' => 'The P8 LoggerFactory coverage consolidation described for this path has happened.',
];

/**
 * The owner vocabulary is the manifest's, for fixtures as much as for test
 * classes — and these two names are not in it.
 *
 * One vocabulary is the point. A fixture used to be published under
 * `Reporting/Sarif`, which the manifest does not declare, and so the inventory
 * prescribed moving it into `tests/Reporting/Sarif/` — a root the invariant
 * forbids a test class to sit in, reached through the one kind the invariant
 * does not judge. Nothing said so, because the fixture branch of
 * `classifyOwner()` answered from a vocabulary of its own.
 *
 * The count is part of the entry, so the allowance is closed in both
 * directions: a third owner name, or the disappearance of the one that
 * remains, each refuses here and is settled by editing this constant, which
 * is the admission rather than the side effect.
 *
 * HtmlReport is not here. It used to be, prescribing an unresolved move into
 * `tests/HtmlReport/Tests/` — a target `assertTestOwnersAreManifestOwners()`
 * would happily count rows against, but which the accepted ADR for the
 * viewer's relocation rejects outright: `tests/` carries `export-ignore`, so
 * landing shipped assets there breaks `--format=html` for every consumer.
 * Registering html-report/tests/, html-report/package.json and
 * html-report/vite.config.js in TOOLING_TEST_ROOT_OWNERS instead retains them
 * at their current path, and a retained tooling root's rows never reach
 * `assertTestOwnersAreManifestOwners()` in the first place — neither their
 * current nor their target path starts with `tests/`, which is the same
 * reason that function's own docblock gives for exempting every other
 * tooling root. Keeping a `NON_MANIFEST_TEST_OWNERS` entry alongside that
 * registration would not coexist with it: `$allowed['HtmlReport']` would stay
 * 0 forever (nothing increments a key the loop never reaches), so the row
 * count in the entry would refuse no matter what number was written there.
 *
 * @var array<string, array{rows: int, reason: string}>
 */
const NON_MANIFEST_TEST_OWNERS = [
    'TestSupport/Logging' => [
        'rows' => 1,
        'reason' => 'the shared PSR-3 recording helper, published as its own support owner in'
            . ' test-system-support-owners.tsv. It is retained where it is, so it prescribes no move into a root'
            . ' that does not exist.',
    ],
];

$projectRoot = realpath(__DIR__ . '/..');
if ($projectRoot === false) {
    fail('Cannot resolve the project root.');
}
assertPathLiteralsResolve($projectRoot);
assertSuiteClassifierAgreesWithPhpunit($projectRoot);
assertToolingTestRootRegistrationIsComplete($projectRoot);
assertEveryTestDirectoryIsScanned($projectRoot);
$p6CBaselinePaths = p6CBaselinePaths($projectRoot);
if (hash('sha256', implode("\n", $p6CBaselinePaths) . "\n") !== P6_C_BASELINE_PATHS_SHA256) {
    fail('P6-C Baseline test artifact set differs from the reviewed finite path digest.');
}

if ($classificationProbeArguments !== []) {
    $path = substr($classificationProbeArguments[0], strlen('--classification-probe='));
    $owner = classifyOwner($path);
    $currentSuite = currentSuite($path);
    // The kind is derived, not assumed. Hardcoding 'phpunit-test-class' here
    // answered for a path the probe was not given: a support file came back
    // with a target under `{owner}/none/`, a directory the main pass would
    // never produce for it. There is no PHPUnit discovery in a probe, so the
    // kind comes from the path, and classifyKind() answers the rest.
    //
    // The proxy is the basename and deliberately not isTestClassPath(), which is
    // `tests/`-scoped because it answers the owner parse — a different question.
    // A test class outside that root (`governance/Other/ProbeTest.php`,
    // `tools/phpstan/tests/Unit/FooTest.php`) would fall to classifyKind(), whose
    // support branch excludes `*Test.php` by name, and be refused as an
    // unclassified kind. Those are the probes the unregistered-group claim in
    // AGENTS.md is checked with.
    $kind = str_ends_with($path, 'Test.php') ? 'phpunit-test-class' : classifyKind($path, []);
    $targetSuite = $kind === 'phpunit-test-class'
        ? ($currentSuite === 'Infrastructure'
            ? (str_contains($path, '/Integration/') ? 'Integration' : 'Unit')
            : $currentSuite)
        : 'none';
    fwrite(STDOUT, implode("\t", [
        $owner,
        $currentSuite,
        targetPath($path, $kind, $owner, $targetSuite),
    ]) . "\n");
    exit(0);
}

// inventoryScanScope() holds the pathspec, because assertEveryTestDirectoryIsScanned()
// judges a directory by whether this scan reaches it and must read the scan's own
// scope rather than a second copy that can drift from it.
$worktreePaths = commandLines(
    ['git', 'ls-files', '--cached', '--others', '--exclude-standard', '--', ...inventoryScanScope()],
    $projectRoot,
);
$worktreePaths = array_values(array_unique([...$worktreePaths, ...P4_IGNORED_FIXTURE_PATHS]));
$worktreePaths = array_values(array_filter(
    $worktreePaths,
    static fn(string $path): bool => is_file($projectRoot . '/' . $path),
));
sort($worktreePaths, SORT_STRING);

$worktreeTestPaths = array_values(array_filter(
    $worktreePaths,
    static fn(string $path): bool => str_ends_with($path, 'Test.php'),
));
$phpunitDiscovery = runCommand(
    ['vendor/bin/phpunit', '--list-tests', '--no-coverage', ...$worktreeTestPaths],
    $projectRoot,
);
$parsedPhpunitDiscovery = parsePhpunitDiscovery($phpunitDiscovery);
$canonicalPhpunitDiscovery = canonicalPhpunitDiscovery($parsedPhpunitDiscovery);
$discoveredCaseCounts = discoveredCaseCounts($parsedPhpunitDiscovery['exact_ids']);
validateReviewedTestAuthority($parsedPhpunitDiscovery['exact_ids'], $discoveredCaseCounts);
if ($discoveryProbeArguments !== []) {
    $probePath = substr($discoveryProbeArguments[0], strlen('--discovery-probe='));
    $probe = file_get_contents($probePath);
    if ($probe === false) {
        fail('Cannot read PHPUnit discovery probe: ' . $probePath);
    }
    $parsedProbe = parsePhpunitDiscovery($probe);
    if ($parsedProbe['exact_ids'] !== $parsedPhpunitDiscovery['exact_ids']) {
        fail('PHPUnit discovery probe does not match the live exact test IDs.');
    }
    fwrite(STDOUT, canonicalPhpunitDiscovery($parsedProbe));
    exit(0);
}
// Verify that the repository's configured suite topology remains readable.
// The generated suite evidence below is derived from committable worktree
// files, excluding ignored local files and dependencies.
runCommand(['vendor/bin/phpunit', '--list-suites', '--no-coverage'], $projectRoot);

$rows = [];
foreach ($worktreePaths as $path) {
    $absolutePath = $projectRoot . '/' . $path;
    $classes = str_ends_with($path, '.php') ? declaredTypes($absolutePath) : [];
    $discoveredClasses = [];
    $discoveredCases = 0;

    foreach ($classes as $class) {
        if (!isset($discoveredCaseCounts[$class])) {
            continue;
        }

        $discoveredClasses[] = $class;
        $discoveredCases += $discoveredCaseCounts[$class];
    }

    $owner = classifyOwner($path);
    $kind = classifyKind($path, $discoveredClasses);
    $currentSuite = currentSuite($path);
    $targetSuite = $kind === 'phpunit-test-class'
        ? ($currentSuite === 'Infrastructure'
            ? (str_contains($path, '/Integration/') ? 'Integration' : 'Unit')
            : $currentSuite)
        : 'none';
    $target = targetPath($path, $kind, $owner, $targetSuite);
    $disposition = dispositionFor($path, $kind, $target);

    $rows[] = [
        'current_path' => $path,
        'kind' => $kind,
        'classes' => implode(',', $classes),
        'discovered_classes' => implode(',', $discoveredClasses),
        'discovered_test_cases' => (string) $discoveredCases,
        'current_suite' => $currentSuite,
        'target_suite' => $targetSuite,
        'subject_owner' => $owner,
        'target_path' => $target,
        'disposition' => $disposition,
    ];
}

validateInventory($rows, $discoveredCaseCounts);
assertTestOwnersAreManifestOwners($rows);
$fixtureDirectoryRows = fixtureDirectoryRows($rows);

$outputDirectory = $outputDirectoryArguments === []
    ? $projectRoot . '/' . OUTPUT_DIRECTORY
    : substr($outputDirectoryArguments[0], strlen('--output-directory='));
if (!is_dir($outputDirectory) && !$check && !mkdir($outputDirectory, 0777, true) && !is_dir($outputDirectory)) {
    fail('Cannot create output directory: ' . OUTPUT_DIRECTORY);
}

emitGenerated($outputDirectory . '/test-ownership.tsv', tsvContents($rows), $check);
emitGenerated($outputDirectory . '/test-fixture-directories.tsv', tsvContents($fixtureDirectoryRows), $check);
emitGenerated($outputDirectory . '/test-phpunit-discovery.txt', $canonicalPhpunitDiscovery, $check);
emitGenerated($outputDirectory . '/test-phpunit-suites.txt', worktreeSuiteOutput($rows, $phpunitDiscovery), $check);
emitGenerated($outputDirectory . '/test-orphan-dispositions.tsv', orphanDispositionContents(), $check);
emitGenerated($outputDirectory . '/test-system-support-owners.tsv', systemSupportContents($projectRoot), $check);
emitGenerated($outputDirectory . '/test-topology.tsv', topologyContents($rows, $fixtureDirectoryRows, $discoveredCaseCounts), $check);

$summary = inventorySummary($rows, $fixtureDirectoryRows, $discoveredCaseCounts);
fwrite(
    STDOUT,
    sprintf(
        "%s %d artifacts, %d fixture directories, %d PHPUnit classes, and %d expanded cases.\n",
        $check ? 'Checked' : 'Generated',
        count($rows),
        count($fixtureDirectoryRows),
        $summary['discovered_unique_classes'],
        $summary['discovered_test_cases'],
    ),
);

/**
 * @param list<string> $command
 *
 * @return list<string>
 */
function commandLines(array $command, string $workingDirectory): array
{
    return array_values(array_filter(
        explode("\n", trim(runCommand($command, $workingDirectory))),
        static fn(string $line): bool => $line !== '',
    ));
}

/**
 * @param list<string> $command
 */
function runCommand(array $command, string $workingDirectory): string
{
    $descriptorSpec = [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ];
    $process = proc_open($command, $descriptorSpec, $pipes, $workingDirectory);
    if (!is_resource($process)) {
        fail('Cannot start command: ' . implode(' ', $command));
    }

    fclose($pipes[0]);
    [$stdout, $stderr] = ProcessOutput::drain($pipes[1], $pipes[2], fail(...));
    $exitCode = proc_close($process);
    if ($exitCode !== 0) {
        fail(sprintf(
            "Command failed with exit %d: %s\n%s",
            $exitCode,
            implode(' ', $command),
            trim($stderr),
        ));
    }

    return $stdout;
}

/**
 * @param list<string> $exactIds
 *
 * @return array<string, int>
 */
function discoveredCaseCounts(array $exactIds): array
{
    $counts = [];
    foreach ($exactIds as $exactId) {
        $class = explode('::', $exactId, 2)[0];
        $counts[$class] = ($counts[$class] ?? 0) + 1;
    }
    ksort($counts, SORT_STRING);

    return $counts;
}

/**
 * @return list<string>
 */
function declaredTypes(string $path): array
{
    $contents = file_get_contents($path);
    if ($contents === false) {
        fail('Cannot read PHP file: ' . $path);
    }

    $tokens = token_get_all($contents);
    $namespace = '';
    $types = [];
    $tokenCount = count($tokens);

    for ($index = 0; $index < $tokenCount; ++$index) {
        $token = $tokens[$index];
        if (!is_array($token)) {
            continue;
        }

        if ($token[0] === T_NAMESPACE) {
            $namespace = '';
            for (++$index; $index < $tokenCount; ++$index) {
                $part = $tokens[$index];
                if ($part === ';' || $part === '{') {
                    break;
                }
                if (is_array($part) && in_array($part[0], [T_STRING, T_NAME_QUALIFIED, T_NS_SEPARATOR], true)) {
                    $namespace .= $part[1];
                }
            }

            continue;
        }

        if (!in_array($token[0], [T_CLASS, T_INTERFACE, T_TRAIT, T_ENUM], true)) {
            continue;
        }

        $next = nextSignificantToken($tokens, $index + 1);
        if (!is_array($next) || $next[0] !== T_STRING) {
            continue;
        }

        $types[] = ($namespace !== '' ? $namespace . '\\' : '') . $next[1];
    }

    sort($types, SORT_STRING);

    return array_values(array_unique($types));
}

/**
 * @param list<array{int, string, int}|string> $tokens
 *
 * @return array{int, string, int}|string|null
 */
function nextSignificantToken(array $tokens, int $start): array|string|null
{
    for ($index = $start, $count = count($tokens); $index < $count; ++$index) {
        $token = $tokens[$index];
        if (is_array($token) && in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
            continue;
        }

        return $token;
    }

    return null;
}

/** @return list<string> */
function p6CBaselinePaths(string $projectRoot): array
{
    $root = $projectRoot . '/tests/Analysis/Policy/Baseline';
    $paths = [];
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root));
    /** @var SplFileInfo $file */
    foreach ($iterator as $file) {
        if ($file->isFile()) {
            $paths[] = substr($file->getPathname(), strlen($projectRoot) + 1);
        }
    }
    sort($paths, SORT_STRING);

    return $paths;
}

/**
 * The manifest owners, spelled the way the tree spells them. Core.Neutral is
 * the one owner whose name is not a namespace: its tests live at tests/Core.
 *
 * @return list<string>
 */
function manifestOwnerPaths(): array
{
    /** @var list<string>|null $cached */
    static $cached = null;
    if ($cached !== null) {
        return $cached;
    }

    $manifestPath = __DIR__ . '/../docs/internal/modular-architecture-manifest.json';
    $contents = file_get_contents($manifestPath);
    if ($contents === false) {
        fail('Cannot read the modular-architecture manifest: ' . $manifestPath);
    }
    $decoded = json_decode($contents, true);
    if (!is_array($decoded) || !isset($decoded['owners']) || !is_array($decoded['owners'])) {
        fail('The modular-architecture manifest declares no owner list: ' . $manifestPath);
    }

    $owners = [];
    foreach ($decoded['owners'] as $owner) {
        if (!is_string($owner)) {
            fail('The modular-architecture manifest declares a non-string owner.');
        }
        $owners[] = $owner === 'Core.Neutral' ? 'Core' : str_replace('.', '/', $owner);
    }
    sort($owners, SORT_STRING);
    $cached = $owners;

    return $cached;
}

/** The population the owner parse answers for; path-based, because it is read before the kind is known. */
function isTestClassPath(string $path): bool
{
    return str_starts_with($path, 'tests/') && str_ends_with($path, 'Test.php');
}

/**
 * Where every directory segment naming a test level sits, by index.
 *
 * The basename is dropped first: only a directory can be the level segment, and
 * a file called `UnitTest.php` is not one.
 *
 * @return list<int>
 */
function testLevelSegments(string $path): array
{
    $segments = explode('/', substr($path, strlen('tests/')));
    array_pop($segments);

    return array_keys(array_filter(
        $segments,
        static fn(string $segment): bool => in_array($segment, TEST_LEVELS, true),
    ));
}

/**
 * The owner a test path declares: the segments before its one level segment.
 *
 * Null when the path does not name **exactly one** level, which is the stated
 * rule and not merely this function's convenience. Taking the first match
 * instead published a conforming row for a two-level path that
 * `TestSubjectPaths::judge()` refuses outright — one rule answered in two
 * places, with the loud half in the control and the silent half here.
 */
function parseOwnerFromTestPath(string $path): ?string
{
    $levels = testLevelSegments($path);
    if (count($levels) !== 1) {
        return null;
    }

    return implode('/', array_slice(explode('/', substr($path, strlen('tests/'))), 0, $levels[0]));
}

/**
 * Why a parsed owner is not a manifest owner. The two cases read differently to
 * whoever has to fix the path: a segment that leaves the manifest names the
 * wrong subject, while a taxonomy above the owners names no subject at all.
 */
function ownerRefusalReason(string $owner): string
{
    $prefix = '';
    foreach (explode('/', $owner) as $segment) {
        $prefix = $prefix === '' ? $segment : $prefix . '/' . $segment;
        $candidates = array_filter(
            manifestOwnerPaths(),
            static fn(string $candidate): bool => $candidate === $prefix || str_starts_with($candidate, $prefix . '/'),
        );
        if ($candidates === []) {
            return sprintf('the segment "%s" leaves the manifest', $segment);
        }
    }

    return sprintf('"%s" is a taxonomy above its owners, not an owner', $owner);
}

function failUnownedTestClass(string $path): never
{
    $levels = testLevelSegments($path);
    if (count($levels) > 1) {
        fail(sprintf(
            '%s names %d of %s, and a test file names exactly one. A test class lives at'
            . ' tests/{manifest owner}/{Unit|Integration|Functional}/...',
            $path,
            count($levels),
            implode(', ', TEST_LEVELS),
        ));
    }

    $owner = parseOwnerFromTestPath($path);
    if ($owner === null) {
        fail(sprintf(
            '%s names no test level, so it declares no owner. A test class lives at'
            . ' tests/{manifest owner}/{Unit|Integration|Functional}/...',
            $path,
        ));
    }
    if ($owner === '') {
        fail(sprintf(
            '%s has no segment before its level segment, so it declares no owner. A test class lives at'
            . ' tests/{manifest owner}/{Unit|Integration|Functional}/...',
            $path,
        ));
    }

    fail(sprintf(
        '%s parses to owner "%s", which is not one of the %d manifest owners: %s. Move the file under its'
        . ' manifest owner.',
        $path,
        $owner,
        count(manifestOwnerPaths()),
        ownerRefusalReason($owner),
    ));
}

function classifyOwner(string $path): string
{
    // The repository-controls root is not a test tree: every file under it
    // asserts something about this repository, so the governance that owns the
    // repository owns all of it, whichever subject a group guards; every other
    // tooling test root is a subject the tool itself owns. Both come from the
    // single TOOLING_TEST_ROOT_OWNERS map — see its docblock.
    foreach (TOOLING_TEST_ROOT_OWNERS as $prefix => $owner) {
        if (toolingRootKeyMatches($path, $prefix)) {
            return $owner;
        }
    }
    // A test class declares its owner with its path. That rule is the branch
    // below; the ladder that follows it answers for fixtures, support classes
    // and the non-PHP artifacts, which the rule says nothing about.
    if (isTestClassPath($path)) {
        $declaredOwner = parseOwnerFromTestPath($path);
        if ($declaredOwner === null || !in_array($declaredOwner, manifestOwnerPaths(), true)) {
            failUnownedTestClass($path);
        }

        return $declaredOwner;
    }
    if (str_starts_with($path, 'tests/TestSupport/Logging/')) {
        return 'TestSupport/Logging';
    }
    // Empty today for the same reason the retaining disjuncts removed from
    // targetPath() were — every file under these eight roots is a test class,
    // which the arm above answers for. It stays where they went because it is
    // not redundant with anything: for the first fixture or support file filed
    // under one of them it is the only branch that names an owner, and the
    // owner it names is right. Deleting it would turn that file into an
    // unclassified refusal for a question the manifest already answers.
    if (preg_match('#^tests/Analysis/Evidence/(CodeSmell|Cohesion|Complexity|Coupling|Design|Maintainability|Security|Size)/#', $path, $matches) === 1) {
        return 'Analysis/Evidence/' . $matches[1];
    }
    if (in_array($path, P7_MEASUREMENT_PATHS, true)) {
        return 'Analysis/Evidence/Measurement';
    }
    global $p6CBaselinePaths;

    if (str_starts_with($path, 'tests/Analysis/Policy/Baseline/')) {
        if (!in_array($path, $p6CBaselinePaths, true)) {
            fail('Unclassified P6-C Baseline test artifact: ' . $path);
        }

        return 'Analysis/Policy/Baseline';
    }
    if (str_starts_with($path, 'tests/Analysis/Finding/')) {
        return 'Analysis/Finding';
    }
    if (str_starts_with($path, 'tests/Analysis/Policy/Inline/')) {
        return 'Analysis/Policy/Inline';
    }
    if (in_array($path, P6_D_PRIORITIZATION_TEST_PATHS, true)) {
        return 'Analysis/Evidence/Prioritization';
    }
    if (str_starts_with($path, 'tests/Analysis/Evidence/ComputedMetrics/Health/')) {
        return 'Analysis/Evidence/ComputedMetrics/Health';
    }
    if (str_starts_with($path, 'tests/Analysis/Evidence/ComputedMetrics/')) {
        return 'Analysis/Evidence/ComputedMetrics';
    }
    if (str_starts_with($path, 'tests/Analysis/Policy/Architecture/')) {
        return 'Analysis/Policy/Architecture';
    }
    if (str_starts_with($path, 'tests/Analysis/Evidence/CircularDependency/')) {
        return 'Analysis/Evidence/CircularDependency';
    }
    if (str_starts_with($path, 'scripts/tests/')) {
        return 'Analysis/Evidence/Measurement';
    }
    // html-report/tests/, package.json and vite.config.js are answered by the
    // TOOLING_TEST_ROOT_OWNERS loop above; no branch needed here.
    if (str_starts_with($path, 'tests/Architecture/')) {
        if (str_contains($path, 'CircularDependency')) {
            return 'Analysis/Evidence/CircularDependency';
        }
        if (str_contains($path, 'InlineSuppression') || str_contains($path, '/Fixtures/IgnoreSample/')) {
            return 'Analysis/Policy/Inline';
        }

        return 'Analysis/Policy/Architecture';
    }
    if ($path === 'tests/Support/Console/StubBaselineRun.php' || $path === 'tests/Support/Console/TempDirectory.php' || str_starts_with($path, 'tests/Support/Time/')) {
        return 'Analysis/Policy/Baseline';
    }
    if (str_starts_with($path, 'tests/Support/Dependency/')) {
        return 'Analysis/Evidence/CircularDependency';
    }
    if (str_starts_with($path, 'tests/Support/Logger/')) {
        return 'TestSupport/Logging';
    }
    if (str_starts_with($path, 'tests/Support/Pipeline/')) {
        return 'Analysis/Run';
    }
    if (str_starts_with($path, 'tests/Support/Violation/')) {
        return 'Analysis/Finding';
    }
    if (str_starts_with($path, 'tests/Analysis/Configuration/')) {
        return 'Analysis/Configuration';
    }
    if (str_starts_with($path, 'tests/Fixture/')) {
        if (preg_match('/(DataClass|ReadonlyDto|SmallClass)/', $path) === 1) {
            return 'Analysis/Evidence/Design';
        }

        return 'Analysis/Configuration';
    }
    if (str_starts_with($path, 'tests/Fixtures/BaselineV10/')) {
        return 'Analysis/Policy/Baseline';
    }
    if (str_starts_with($path, 'tests/Fixtures/Channels/')) {
        return 'Analysis/Finding';
    }
    if (str_starts_with($path, 'tests/Fixtures/CircularDeps/')) {
        return 'Analysis/Evidence/CircularDependency';
    }
    if (str_starts_with($path, 'tests/Fixtures/CouplingProject/')) {
        return 'Analysis/Evidence/Coupling';
    }
    if (str_starts_with($path, 'tests/Fixtures/GoldenMetrics/') || str_starts_with($path, 'tests/Fixtures/Aggregation/')) {
        return 'Analysis/Run';
    }
    if (str_starts_with($path, 'tests/Fixtures/Inheritance/')) {
        return 'Analysis/Evidence/Design';
    }
    if (str_starts_with($path, 'tests/Fixtures/Schema/')) {
        return 'Reporting';
    }
    if ($path === 'tests/Fixtures/AnonymousClassContext.php') {
        return 'Analysis/Evidence/Measurement';
    }
    if (str_starts_with($path, 'tests/Analysis/Evidence/Duplication/')) {
        return 'Analysis/Evidence/Duplication';
    }
    if (str_starts_with($path, 'tests/Analysis/Evidence/DependencyModel/')) {
        return 'Analysis/Evidence/DependencyModel';
    }
    if (preg_match('#^tests/Core/(Path|Symbol|Profiler)/#', $path, $matches) === 1) {
        return 'Core/' . $matches[1];
    }
    if (str_starts_with($path, 'tests/Core/')) {
        return 'Core/Neutral';
    }
    if (str_starts_with($path, 'tests/Analysis/Evidence/Measurement/')) {
        return 'Analysis/Evidence/Measurement';
    }
    if (str_starts_with($path, 'tests/Analysis/Run/')) {
        return 'Analysis/Run';
    }

    if (str_contains($path, 'ThresholdAnnotationParser') || str_contains($path, 'ThresholdValidatorWiring')) {
        return 'Analysis/Policy/Inline';
    }
    if (str_contains($path, 'ComputedMetric') || str_contains($path, 'ComputedMetrics') || str_contains($path, 'HealthFormula')) {
        return 'Analysis/Evidence/ComputedMetrics';
    }
    if (str_contains($path, 'AnalysisContextThreshold') || str_contains($path, 'ThresholdOverride')) {
        return 'Analysis/Policy/Inline';
    }
    if (str_contains($path, 'ChannelDeclaration')) {
        return 'Analysis/Finding';
    }
    if (str_contains($path, 'Wmc')) {
        $subject = match (true) {
            preg_match('/(Lcom|TccLcc)/', $path) === 1 => 'Cohesion',
            preg_match('/(Inheritance|Dit|Noc)/', $path) === 1 => 'Design',
            preg_match('/MethodCount/', $path) === 1 => 'Size',
            preg_match('/(UnusedPrivate|TraitUsageResolver)/', $path) === 1 => 'CodeSmell',
            preg_match('/Rfc/', $path) === 1 => 'Coupling',
            preg_match('/Wmc/', $path) === 1 => 'Complexity',
            default => fail('Unclassified Structure test: ' . $path),
        };

        return 'Analysis/Evidence/' . $subject;
    }
    if (str_contains($path, 'ThresholdValidatorAssignment')) {
        return 'Analysis/Finding';
    }
    if (str_contains($path, 'CoverageProjection') || str_contains($path, 'JsonShapePreservation')) {
        return 'Reporting/FindingProjection';
    }
    // No broad `tests/Infrastructure/` fallback sits above this: one did, and it
    // answered first with the bare string `Infrastructure`, which is a taxonomy
    // above its owners and not one of the manifest owners at all — the parse
    // refuses that exact string by name for a test class. It published a wrong
    // owner and, with it, a move to `tests/Infrastructure/Support/`, a root that
    // is not an owner either. An Infrastructure subject missing from this list
    // now reaches the unclassified refusal at the end of the ladder, which is an
    // ownership decision asked for rather than answered wrongly.
    if (preg_match('#^tests/Infrastructure/(Ast|Cache|Console|DependencyInjection|Logging|Parallel|Profiler|Rule|Serializer)/#', $path, $matches) === 1) {
        return 'Infrastructure/' . $matches[1];
    }
    if (str_starts_with($path, 'tests/Reporting/')) {
        return 'Reporting';
    }
    if (str_contains($path, 'LayerAssignment')) {
        return 'Infrastructure/Console';
    }
    if (str_ends_with($path, '.gitkeep')) {
        return 'legacy-placeholder';
    }

    return fail('Unclassified test artifact: ' . $path);
}

/**
 * @param list<string> $discoveredClasses
 */
function classifyKind(string $path, array $discoveredClasses): string
{
    if ($discoveredClasses !== []) {
        return 'phpunit-test-class';
    }
    if (str_contains($path, '/Fixtures/') || str_contains($path, '/Fixture/') || str_contains($path, '/data/') || str_contains($path, '/fixtures/') || preg_match('/Fixture(s)?\.php$/', $path) === 1) {
        return 'fixture';
    }
    if (str_contains($path, '/Support/') || (str_ends_with($path, '.php') && !str_ends_with($path, 'Test.php'))) {
        return 'support';
    }
    if (str_ends_with($path, 'package.json') || str_ends_with($path, 'vite.config.js')) {
        return 'non-php-test-config';
    }
    if (preg_match('/\.(py|js)$/', $path) === 1) {
        return 'non-php-test-process';
    }
    if (str_ends_with($path, '.gitkeep')) {
        return 'placeholder';
    }

    return fail('Unclassified test artifact kind: ' . $path);
}

/**
 * Single source of truth for currentSuite(), with no branch beside it.
 * Order matters: a more specific prefix must precede a shorter one it nests
 * under (e.g. the Baseline/Functional entry before the bare Functional
 * entry). assertSuiteClassifierAgreesWithPhpunit() walks this
 * same table to check the reverse direction, so a literal added here without
 * a matching phpunit.xml.dist <directory> fails the same way a <directory>
 * without a matching literal already did.
 *
 * **Sole source is what makes that reconciliation bidirectional**, and it was
 * not one. Two regexes above the walk classified eleven `Analysis/Evidence/*`
 * directories that no row named — per-capability prefixes written as a pattern
 * because they were regular, not because they were unknowable. The backward
 * half walks this table, so those eleven were checked in one direction only:
 * deleting such a `<directory>` from phpunit.xml.dist left the classifier
 * answering `Unit` for a path PHPUnit no longer runs, and nothing here said so.
 * They are rows now. A family regular enough to write as a pattern is regular
 * enough to enumerate, and enumeration is what the reverse direction can read.
 *
 * @return list<array{prefix: string, suite: string}>
 */
function testSuitePrefixTable(): array
{
    return [
        ['prefix' => 'tests/Analysis/Policy/Architecture/Unit/', 'suite' => 'Unit'],
        ['prefix' => 'tests/Analysis/Policy/Baseline/Unit/', 'suite' => 'Unit'],
        ['prefix' => 'tests/Analysis/Policy/Inline/Unit/', 'suite' => 'Unit'],
        ['prefix' => 'tests/Analysis/Evidence/CircularDependency/Unit/', 'suite' => 'Unit'],
        ['prefix' => 'tests/Analysis/Evidence/CodeSmell/Unit/', 'suite' => 'Unit'],
        ['prefix' => 'tests/Analysis/Evidence/Cohesion/Unit/', 'suite' => 'Unit'],
        ['prefix' => 'tests/Analysis/Evidence/Complexity/Unit/', 'suite' => 'Unit'],
        ['prefix' => 'tests/Analysis/Evidence/Coupling/Unit/', 'suite' => 'Unit'],
        ['prefix' => 'tests/Analysis/Evidence/Design/Unit/', 'suite' => 'Unit'],
        ['prefix' => 'tests/Analysis/Evidence/Duplication/Unit/', 'suite' => 'Unit'],
        ['prefix' => 'tests/Analysis/Evidence/Duplication/Integration/', 'suite' => 'Integration'],
        ['prefix' => 'tests/Analysis/Evidence/Duplication/Functional/', 'suite' => 'Functional'],
        ['prefix' => 'tests/Analysis/Evidence/Maintainability/Unit/', 'suite' => 'Unit'],
        ['prefix' => 'tests/Analysis/Evidence/Security/Unit/', 'suite' => 'Unit'],
        ['prefix' => 'tests/Analysis/Evidence/Size/Unit/', 'suite' => 'Unit'],
        ['prefix' => 'tests/Analysis/Evidence/DependencyModel/Unit/', 'suite' => 'Unit'],
        ['prefix' => 'tests/Analysis/Evidence/Measurement/Unit/', 'suite' => 'Unit'],
        ['prefix' => 'tests/Analysis/Evidence/ComputedMetrics/Health/Unit/', 'suite' => 'Unit'],
        ['prefix' => 'tests/Analysis/Evidence/ComputedMetrics/Unit/', 'suite' => 'Unit'],
        ['prefix' => 'tests/Analysis/Evidence/Prioritization/Unit/', 'suite' => 'Unit'],
        ['prefix' => 'tests/Analysis/Configuration/Unit/', 'suite' => 'Unit'],
        ['prefix' => 'tests/Analysis/Finding/Unit/', 'suite' => 'Unit'],
        ['prefix' => 'tests/Analysis/Run/Unit/', 'suite' => 'Unit'],
        ['prefix' => 'tests/Reporting/GraphProjection/Unit/', 'suite' => 'Unit'],
        ['prefix' => 'tests/Reporting/Unit/', 'suite' => 'Unit'],
        ['prefix' => 'tests/Core/Path/Unit/', 'suite' => 'Unit'],
        ['prefix' => 'tests/Core/Symbol/Unit/', 'suite' => 'Unit'],
        ['prefix' => 'tests/Core/Unit/', 'suite' => 'Unit'],
        ['prefix' => 'tests/Analysis/Policy/Architecture/Integration/', 'suite' => 'Integration'],
        ['prefix' => 'tests/Analysis/Policy/Baseline/Integration/', 'suite' => 'Integration'],
        ['prefix' => 'tests/Analysis/Policy/Inline/Integration/', 'suite' => 'Integration'],
        ['prefix' => 'tests/Analysis/Configuration/Integration/', 'suite' => 'Integration'],
        ['prefix' => 'tests/Analysis/Finding/Integration/', 'suite' => 'Integration'],
        ['prefix' => 'tests/Analysis/Evidence/Measurement/Integration/', 'suite' => 'Integration'],
        ['prefix' => 'tests/Analysis/Evidence/ComputedMetrics/Integration/', 'suite' => 'Integration'],
        ['prefix' => 'tests/Analysis/Run/Integration/', 'suite' => 'Integration'],
        ['prefix' => 'tests/Analysis/Evidence/CodeSmell/Integration/', 'suite' => 'Integration'],
        ['prefix' => 'tests/Analysis/Evidence/Complexity/Integration/', 'suite' => 'Integration'],
        ['prefix' => 'tests/Analysis/Evidence/Coupling/Integration/', 'suite' => 'Integration'],
        ['prefix' => 'tests/Analysis/Evidence/Design/Integration/', 'suite' => 'Integration'],
        ['prefix' => 'tests/Reporting/Integration/', 'suite' => 'Integration'],
        ['prefix' => 'tests/Analysis/Policy/Baseline/Functional/', 'suite' => 'Functional'],
        ['prefix' => 'tests/Infrastructure/', 'suite' => 'Infrastructure'],
        ['prefix' => 'governance/TestSuiteHygiene/', 'suite' => 'Governance'],
        ['prefix' => 'governance/Occurrence/', 'suite' => 'Governance'],
        ['prefix' => 'governance/RuleOptionKeys/', 'suite' => 'Governance'],
        ['prefix' => 'governance/Channel/', 'suite' => 'Governance'],
        ['prefix' => 'governance/ThresholdKeys/', 'suite' => 'Governance'],
        ['prefix' => 'governance/RuleDeclaration/', 'suite' => 'Governance'],
        ['prefix' => 'governance/RatchetArtifact/', 'suite' => 'Governance'],
        ['prefix' => 'governance/PlanningRecords/', 'suite' => 'Governance'],
        ['prefix' => 'governance/DocumentationCensus/', 'suite' => 'Governance'],
        ['prefix' => 'governance/DistributedPackage/', 'suite' => 'Governance'],
        ['prefix' => 'governance/ModularOwnership/', 'suite' => 'Governance'],
        ['prefix' => 'governance/ConsoleComposition/', 'suite' => 'Governance'],
        ['prefix' => 'governance/FrameworkClassification/', 'suite' => 'Governance'],
        ['prefix' => 'governance/PackageVersion/', 'suite' => 'Governance'],
        ['prefix' => 'governance/ProjectScopeCoverage/', 'suite' => 'Governance'],
        ['prefix' => 'governance/SelectorSyntax/', 'suite' => 'Governance'],
        ['prefix' => 'governance/SuppressionOptionKeys/', 'suite' => 'Governance'],
        ['prefix' => 'governance/RepositoryEntrypoints/', 'suite' => 'Governance'],
        ['prefix' => 'governance/ConfigurationVocabulary/', 'suite' => 'Governance'],
        ['prefix' => 'governance/MeasurementVocabulary/', 'suite' => 'Governance'],
        ['prefix' => 'governance/MeasurementIdentity/', 'suite' => 'Governance'],
        ['prefix' => 'governance/GeneratedArtifactFreshness/', 'suite' => 'Governance'],
        ['prefix' => 'governance/FormatOptionKeys/', 'suite' => 'Governance'],
        ['prefix' => 'governance/DirectiveVocabulary/', 'suite' => 'Governance'],
        ['prefix' => 'governance/ControlRigLedger/', 'suite' => 'Governance'],
        ['prefix' => 'governance/HealthVocabulary/', 'suite' => 'Governance'],
        ['prefix' => 'governance/FindingVocabulary/', 'suite' => 'Governance'],
        ['prefix' => 'governance/LayerPolicyVocabulary/', 'suite' => 'Governance'],
        ['prefix' => 'governance/SymbolVocabulary/', 'suite' => 'Governance'],
        ['prefix' => 'tools/phpstan/tests/', 'suite' => 'Tooling'],
        ['prefix' => 'scripts/promise-effect/tests/', 'suite' => 'Tooling'],
        ['prefix' => 'scripts/directive-audit/tests/', 'suite' => 'Tooling'],
        ['prefix' => 'scripts/directive-audit-controls/tests/', 'suite' => 'Tooling'],
        ['prefix' => 'scripts/tautology-controls/tests/', 'suite' => 'Tooling'],
        ['prefix' => 'scripts/finding-gate/tests/', 'suite' => 'Tooling'],
        ['prefix' => 'scripts/suppression-snapshot/tests/', 'suite' => 'Tooling'],
        ['prefix' => 'scripts/rename-enumeration/tests/', 'suite' => 'Tooling'],
        ['prefix' => 'scripts/health-calibration/tests/', 'suite' => 'Tooling'],
        ['prefix' => 'scripts/benchmark/tests/', 'suite' => 'Tooling'],
        ['prefix' => 'scripts/modular-architecture/tests/', 'suite' => 'Tooling'],
    ];
}

function currentSuite(string $path): string
{
    foreach (testSuitePrefixTable() as $entry) {
        if (str_starts_with($path, $entry['prefix'])) {
            return $entry['suite'];
        }
    }

    return 'none';
}

/**
 * A test published as belonging to no suite while PHPUnit runs it is a lie this
 * artifact cannot detect on its own: `currentSuite()` classifies an arbitrary
 * input path, so nothing ties its literals to the suite map they mirror. Six
 * directories had drifted apart from it before this check existed.
 *
 * The map disagreement is symmetric and both directions are checked: a
 * phpunit.xml.dist <directory> currentSuite() cannot place under the same
 * name (forward — the original six-directory drift), and a
 * testSuitePrefixTable() literal with no matching <directory> declared for
 * that suite (backward — a stale literal PHPUnit never runs, the same silent
 * outcome through the opposite door).
 *
 * The backward half reads the table, so the symmetry holds exactly while the
 * table is everything currentSuite() knows. It is; see that function's own
 * docblock for the eleven directories that were once outside it and were
 * therefore checked forward only.
 */
function assertSuiteClassifierAgreesWithPhpunit(string $projectRoot): void
{
    $configuration = $projectRoot . '/phpunit.xml.dist';
    $document = @simplexml_load_file($configuration);
    if ($document === false) {
        fail('Cannot read the PHPUnit suite map: ' . $configuration);
    }

    $mismatches = [];
    $declaredDirectories = [];
    foreach ($document->testsuites->testsuite as $suite) {
        $name = (string) $suite['name'];
        foreach ($suite->directory as $directory) {
            $declared = rtrim(trim((string) $directory), '/');
            $declaredDirectories[$name][] = $declared;
            $classified = currentSuite($declared . '/probe/ProbeTest.php');
            if ($classified !== $name) {
                $mismatches[] = sprintf('%s is suite %s in %s, %s in currentSuite()', $declared, $name, basename($configuration), $classified);
            }
        }
    }

    foreach (testSuitePrefixTable() as $entry) {
        $literal = rtrim($entry['prefix'], '/');
        if (!in_array($literal, $declaredDirectories[$entry['suite']] ?? [], true)) {
            $mismatches[] = sprintf(
                '%s is suite %s in currentSuite() but is not declared under that <testsuite> in %s',
                $literal,
                $entry['suite'],
                basename($configuration),
            );
        }
    }

    if ($mismatches !== []) {
        fail("Suite classifier disagrees with the PHPUnit suite map:\n  " . implode("\n  ", $mismatches));
    }
}

/**
 * @return list<string> every 'scripts/<tool>/tests/' and 'tools/<tool>/tests/'
 *                      directory that actually exists on disk, independent of
 *                      TOOLING_TEST_ROOT_OWNERS — the source this function
 *                      checks that map against.
 */
function actualToolingTestRootsOnDisk(string $projectRoot): array
{
    $roots = [];
    foreach (['scripts', 'tools'] as $parent) {
        $directories = glob($projectRoot . '/' . $parent . '/*/tests', GLOB_ONLYDIR);
        foreach ($directories === false ? [] : $directories as $directory) {
            $roots[] = $parent . '/' . basename(dirname($directory)) . '/tests/';
        }
    }
    sort($roots, SORT_STRING);

    return $roots;
}

/**
 * Every registered key still names something, and every glob-shaped directory
 * on disk is still registered.
 *
 * The four sites that used to spell out the tooling-root set now all read
 * TOOLING_TEST_ROOT_OWNERS, so they cannot drift from each other — but the map
 * itself can still drift from the tree, and the two directions of that drift
 * are answered by different oracles because only one population can be found by
 * a glob:
 *
 * - Any registered key — whichever shape — naming a path that is no longer
 *   there. This is answered directly, by `is_dir()`/`is_file()` on the key
 *   itself, which needs no glob and therefore has no shape requirement: a
 *   typo, a rename or a deletion under `governance/` or `html-report/` is
 *   caught exactly as one under `scripts/` or `tools/` is. Earlier this
 *   direction was answered only by comparing against the glob's listing too,
 *   which is why `governance/` and `html-report/*` had to be exempted from it
 *   outright — the exemption was a gap in the oracle, not a property of
 *   those roots.
 * - A new `scripts/<tool>/tests/` or `tools/<tool>/tests/` directory landing
 *   without a registration — found by comparing the glob's own listing
 *   against the registered keys shaped like it. A key outside that shape
 *   (`governance/`, `html-report/*`) could never be produced by this glob no
 *   matter how faithfully it is registered, so it does not participate here.
 *
 * That second direction used to be the whole of what was asked about roots
 * landing unregistered, and a glob over two directories is not a population.
 * It is no longer the whole: {@see assertEveryTestDirectoryIsScanned()} asks it
 * of the tree rather than of two parent directories, and what remains here is
 * the narrower question of whether the map and the glob agree where both can
 * see. The two overlap on `scripts/` and `tools/` deliberately — this one names
 * the missing *map entry* for a shape whose owner is known, the other names an
 * unclaimed *directory* wherever it is.
 */
function assertToolingTestRootRegistrationIsComplete(string $projectRoot): void
{
    $onDisk = actualToolingTestRootsOnDisk($projectRoot);
    $globShapedRegistered = array_values(array_filter(
        array_keys(TOOLING_TEST_ROOT_OWNERS),
        static fn(string $prefix): bool => preg_match('#^(?:scripts|tools)/[^/]+/tests/$#', $prefix) === 1,
    ));
    sort($globShapedRegistered, SORT_STRING);

    $problems = [];
    foreach (array_diff($onDisk, $globShapedRegistered) as $root) {
        $problems[] = $root . ' exists on disk but is not registered in TOOLING_TEST_ROOT_OWNERS';
    }
    foreach (array_diff($globShapedRegistered, $onDisk) as $root) {
        $problems[] = $root . ' is registered in TOOLING_TEST_ROOT_OWNERS but no longer exists on disk';
    }

    foreach (array_keys(TOOLING_TEST_ROOT_OWNERS) as $prefix) {
        if (in_array($prefix, $globShapedRegistered, true)) {
            continue; // already checked against the glob's own listing above
        }
        $exists = str_ends_with($prefix, '/')
            ? is_dir($projectRoot . '/' . $prefix)
            : is_file($projectRoot . '/' . $prefix);
        if (!$exists) {
            $problems[] = $prefix . ' is registered in TOOLING_TEST_ROOT_OWNERS but no longer exists on disk';
        }
    }

    if ($problems !== []) {
        fail("Tooling test root registration disagrees with the tree:\n  " . implode("\n  ", $problems));
    }
}

/**
 * The pathspec the inventory's own scan is given — the single definition of
 * which paths this generator can see at all.
 *
 * `'scripts/tests'` is dead scope left over from before the roots below
 * existed; its directory is gone. It stays because this is a description of
 * what the scan is handed, not a wish about it, and a path that would be
 * scanned if it reappeared is a path this file governs.
 *
 * @return list<string> git pathspecs, no trailing slash
 */
function inventoryScanScope(): array
{
    return [
        'tests',
        'scripts/tests',
        ...array_map(static fn(string $prefix): string => rtrim($prefix, '/'), array_keys(TOOLING_TEST_ROOT_OWNERS)),
    ];
}

/**
 * Every test-shaped directory the tree carries — the source
 * {@see assertEveryTestDirectoryIsScanned()} judges, derived from git rather
 * than from any registration so that it cannot agree with one by construction.
 * Returned sorted; "shallowest" below describes which match is taken per path,
 * not the order of the result.
 *
 * **Git is asked, not the filesystem.** What decides whether anyone but this
 * worktree sees a directory is whether git carries a file under it; `glob()`
 * answers about this machine, which is how the direction this function serves
 * came to be missing in the first place. `--others` is included so that a root
 * is judged the moment it is created rather than one commit later.
 *
 * **What `--exclude-standard` delegates to, exactly.** Not `.gitignore` alone:
 * it is `.gitignore` plus `$GIT_DIR/info/exclude` plus `core.excludesFile`, and
 * the last two are per-clone and per-machine — untracked, invisible to review,
 * and not the same on any two checkouts. That matters here and was measured: a
 * developer checkout that followed this repository's own setup instructions
 * carries `website/.venv/`, whose site-packages hold a real `tests` directory,
 * and `benchmarks/vendor/`. Both are held out by tracked `.gitignore` lines, so
 * the sweep names neither — but the guarantee is tracked only for what
 * `.gitignore` covers. Anything a machine excludes locally is excluded here
 * too, silently and differently per machine.
 *
 * **Shallowest match per path.** A `tests` directory nested inside another is
 * the same root, and a claim over the outer one covers it by prefix; emitting
 * both would refuse the inner one separately the day the outer one's claim
 * names a deeper path.
 *
 * @return list<string> each with a trailing slash
 */
function trackedTestDirectories(string $projectRoot): array
{
    $paths = commandLines(
        ['git', 'ls-files', '--cached', '--others', '--exclude-standard'],
        $projectRoot,
    );

    $directories = [];
    foreach ($paths as $path) {
        $segments = explode('/', $path);
        array_pop($segments); // the file name; only its parents can be directories
        if (array_intersect($segments, NON_PROJECT_PATH_SEGMENTS) !== []) {
            continue;
        }

        $prefix = '';
        foreach ($segments as $segment) {
            $prefix .= $segment . '/';
            if (in_array($segment, TEST_DIRECTORY_BASENAMES, true)) {
                $directories[$prefix] = true;

                break; // shallowest match — see the docblock
            }
        }
    }

    $directories = array_keys($directories);
    sort($directories, SORT_STRING);

    return $directories;
}

/**
 * Every test-shaped directory in the tree is one this generator actually scans.
 *
 * This is the direction {@see assertToolingTestRootRegistrationIsComplete()}
 * cannot answer. That function compares the map against a `glob()` of
 * `scripts/*\/tests` and `tools/*\/tests`, so a root outside those two shapes
 * is a root it cannot name, let alone miss: `governance/` had that gap from the
 * day it was registered, and `html-report/` joined it. The consequence was not
 * a wrong answer but an unasked question — a root-level project landing without
 * a registration is absent from the inventory entirely, under a green
 * `composer architecture:check`, leaving nothing behind for a reader to notice.
 *
 * **"Claimed" means scanned, and nothing weaker.** The claim is
 * {@see inventoryScanScope()} — the same pathspec the row pass is handed, read
 * rather than restated. An earlier draft accepted a second door, a
 * `<directory>` under a `<testsuite>` in `phpunit.xml.dist`, on the reasoning
 * that the root `tests/` tree is registered as its leaf directories rather than
 * as itself. That door granted a claim that does not entail what the claim is
 * for: a new PHP test root declared in `phpunit.xml.dist` and classified by
 * `testSuitePrefixTable()` would satisfy it, run under PHPUnit, and still be
 * absent from every generated artifact, because neither registration touches
 * the scan scope. The harm this function exists to refuse would have been
 * reachable through a directory it called claimed. Scan scope covers `tests/`
 * by its own literal, so nothing is lost by refusing the weaker door.
 *
 * **The population is closed by being stated, not by being narrow.** The judged
 * set is exactly {@see trackedTestDirectories()} minus
 * {@see NON_SCANNED_TEST_DIRECTORIES}, and the subtracted set is literals. A
 * witness — "some root is registered", "the ones we remembered are still there"
 * — would move the blind spot rather than remove it, because what it never
 * enumerates it can never miss. Subtraction by literal has the opposite
 * property: the excuse list is as visible as the thing it excuses, and it grows
 * only by someone writing a path and a reason.
 *
 * **What this does not reach.** Two shapes, and neither is covered by anything
 * said above.
 *
 * - A test root whose directory is not test-shaped. `governance/` is the tree's
 *   one example. Declared as a `<testsuite>` `<directory>`, it is caught by
 *   {@see assertSuiteClassifierAgreesWithPhpunit()}, which refuses a declared
 *   directory `currentSuite()` does not classify. Undeclared and outside
 *   `autoload-dev`, nothing sees it — and nothing runs it either, so it is
 *   silence about a directory PHPUnit never reaches. That silence is
 *   pre-existing and unchanged here.
 * - Tests with no enclosing test-shaped directory at all: `viewer.test.js`
 *   beside the source it covers, or a layout spelling the directory `e2e` or
 *   `cypress`. The source derives a candidate from a path segment, so a project
 *   that wraps its tests in no such segment produces none.
 *
 * **Which refusal a reader actually sees.** `fail()` exits, and
 * {@see assertToolingTestRootRegistrationIsComplete()} runs one line earlier, so
 * for the `scripts/<tool>/tests/` and `tools/<tool>/tests/` shapes it is always
 * that function's one-line message that prints and never this one. The two
 * overlap there on purpose, but only the earlier one speaks.
 */
function assertEveryTestDirectoryIsScanned(string $projectRoot): void
{
    $candidates = trackedTestDirectories($projectRoot);
    if ($candidates === []) {
        fail(
            'Found no test-shaped directory anywhere in the tree, so every judgement below would be vacuous. '
            . 'Either the sweep stopped reaching git or ' . implode('/', TEST_DIRECTORY_BASENAMES)
            . ' no longer names how this repository spells a test directory.',
        );
    }

    $scannedThrough = [];
    foreach ($candidates as $candidate) {
        $scannedThrough[$candidate] = scanScopeEntryFor($candidate);
    }

    $problems = [];
    foreach ($candidates as $candidate) {
        if (isset(NON_SCANNED_TEST_DIRECTORIES[$candidate]) || $scannedThrough[$candidate] !== null) {
            continue;
        }
        $problems[] = $candidate
            . ' holds files git reports and nothing scans it: register it in TOOLING_TEST_ROOT_OWNERS (and in every'
            . ' other address AGENTS.md lists for a new test root), or name it in NON_SCANNED_TEST_DIRECTORIES with'
            . ' the reason it is not this repository\'s to scan';
    }

    foreach (NON_SCANNED_TEST_DIRECTORIES as $excused => $reason) {
        if (trim($reason) === '') {
            $problems[] = $excused
                . ' is excused in NON_SCANNED_TEST_DIRECTORIES with an empty reason, which excuses it from this'
                . ' check and from explaining itself at the same time';
        }
        if (!in_array($excused, $candidates, true)) {
            $problems[] = $excused
                . ' is excused in NON_SCANNED_TEST_DIRECTORIES but git carries no file under it, so the'
                . ' exclusion excuses nothing and is holding a seat for a path that is gone';

            continue;
        }
        if ($scannedThrough[$excused] !== null) {
            $problems[] = $excused
                . ' is excused in NON_SCANNED_TEST_DIRECTORIES as "' . $reason . '" and is at the same time'
                . ' scanned through ' . $scannedThrough[$excused]
                . ' — the exclusion has outlived its reason and one of the two is wrong';
        }
    }

    if ($problems !== []) {
        fail(
            "Test directories in the tree that nothing scans:\n  "
            . implode("\n  ", $problems),
        );
    }
}

/**
 * The scan-scope entry under which `$candidate` is scanned, named the way a
 * refusal has to name it, or null when nothing scans it.
 *
 * Each entry is compared with a trailing slash appended. Without it
 * `html-report/tests` would also claim a future `html-report/testsuite/`, and
 * the map's two file keys — `html-report/package.json`,
 * `html-report/vite.config.js` — would need excluding by hand instead of
 * failing to match anything, which is what they should do: they register a
 * file, not a directory of tests.
 */
function scanScopeEntryFor(string $candidate): ?string
{
    foreach (inventoryScanScope() as $entry) {
        if (str_starts_with($candidate, $entry . '/')) {
            return 'the inventory scan scope entry ' . $entry;
        }
    }

    return null;
}

/**
 * Whether `$key` (a `TOOLING_TEST_ROOT_OWNERS` key) names `$path`. A key
 * ending in `/` is a directory: everything under it matches, by prefix. A
 * key not ending in `/` (`html-report/package.json`,
 * `html-report/vite.config.js`) is a single file: only that exact path
 * matches. A prefix match on a file key would also accept
 * `html-report/package.json.bak` or, more dangerously, would silently start
 * matching a sibling if one were ever added whose name happens to extend the
 * key's — `str_starts_with` cannot tell "this is the file" from "this is a
 * file that starts with the same characters", and a single-file key means
 * exactly the former.
 */
function toolingRootKeyMatches(string $path, string $key): bool
{
    return str_ends_with($key, '/') ? str_starts_with($path, $key) : $path === $key;
}

/**
 * Whether `$path` falls under one of the registered tooling test roots — the
 * single TOOLING_TEST_ROOT_OWNERS map that `classifyOwner()` and the
 * scan-scope pathspec also read.
 */
function isRegisteredToolingRoot(string $path): bool
{
    foreach (array_keys(TOOLING_TEST_ROOT_OWNERS) as $prefix) {
        if (toolingRootKeyMatches($path, $prefix)) {
            return true;
        }
    }

    return false;
}

/**
 * The disposition is read off the target, not off a prefix list. "Move" and
 * "retain" answer exactly the question `targetPath()` has already answered —
 * whether this artifact is where it belongs — so deciding them twice let the
 * two disagree: a prefix list that had not grown a retain case for a directory
 * printed "Move atomically" beside a target equal to the row's own path.
 *
 * The two arms above the derivation are the rows whose disposition is not that
 * question. An orphan candidate carries the reason it is a candidate, and a
 * placeholder's target is the sentinel DELETE, which is not a path it could be
 * retained at.
 */
function dispositionFor(string $path, string $kind, string $target): string
{
    $orphanReason = orphanCandidateReason($path);
    if ($orphanReason !== null) {
        return 'Retain in place until consumer proof; delete only if the orphan candidate is confirmed. ' . $orphanReason;
    }
    if ($kind === 'placeholder') {
        return 'Remove the empty legacy placeholder after its target topology exists.';
    }

    return $target === $path
        ? 'Retain at the materialized subject-owned path.'
        : 'Move atomically with the named subject owner.';
}

function orphanCandidateReason(string $path): ?string
{
    foreach (ORPHAN_CANDIDATE_PREFIXES as $prefix => $reason) {
        if ($path === $prefix || str_starts_with($path, $prefix)) {
            return $reason;
        }
    }

    return null;
}

function targetPath(string $path, string $kind, string $owner, string $targetSuite): string
{
    if (isRegisteredToolingRoot($path)) {
        return $path;
    }
    if (isTestClassPath($path)) {
        return $path;
    }
    // Two disjuncts stood here and no longer do: the eight
    // `tests/Analysis/Evidence/{CodeSmell…Size}/` roots, and
    // `tests/Infrastructure/Logging/Unit/`. Both prescribed retention for a
    // population that is entirely test classes — 119 files and 5, every one of
    // them `*Test.php` — so the arm above answered for all of them first and
    // neither disjunct could reach anything. The general rule below is also the
    // right answer for the non-test-class file that would arrive there next: a
    // support class under one of those roots belongs at `{owner}/Support/`, and
    // a retaining prefix would have held it where it fell.
    if (in_array($path, P7_MEASUREMENT_PATHS, true)
        || str_starts_with($path, 'tests/Analysis/Evidence/ComputedMetrics/')
        || in_array($path, P6_D_PRIORITIZATION_TEST_PATHS, true)
    ) {
        return $path;
    }
    if ($kind === 'placeholder') {
        return 'DELETE';
    }

    $targetRoot = 'tests/' . $owner;
    if ($kind === 'phpunit-test-class') {
        return $targetRoot . '/' . $targetSuite . '/' . basename($path);
    }
    if ($kind === 'fixture') {
        return $targetRoot . '/Fixtures/' . fixtureTail($path);
    }
    if ($kind === 'support') {
        return $targetRoot . '/Support/' . basename($path);
    }

    return $targetRoot . '/Tests/' . basename($path);
}

/**
 * @param list<string> $exactIds
 * @param array<string, int> $discoveredCaseCounts
 */
function validateReviewedTestAuthority(array $exactIds, array $discoveredCaseCounts): void
{
    if ($exactIds === [] || $discoveredCaseCounts === []) {
        fail('PHPUnit discovery must contain mapped classes and exact IDs.');
    }
}

function fixtureTail(string $path): string
{
    foreach ([
        'tests/Analysis/Policy/Architecture/Fixtures/',
        'tests/Analysis/Policy/Baseline/Fixtures/',
        'tests/Analysis/Policy/Inline/Fixtures/',
        'tests/Architecture/Fixtures/',
        'tests/Fixtures/',
        'tests/Fixture/',
        'scripts/tests/fixtures/',
        '/data/',
    ] as $marker) {
        $position = strpos($path, $marker);
        if ($position !== false) {
            return substr($path, $position + strlen($marker));
        }
    }

    return basename($path);
}

/**
 * Every row that touches `tests/` names a manifest owner, whichever kind it is.
 *
 * Tooling roots are outside this rule by position rather than by exemption:
 * neither their current nor their target path lies under `tests/`, so the
 * vocabulary they use is their own tree's and not this one's.
 *
 * @param list<array<string, string>> $rows
 */
function assertTestOwnersAreManifestOwners(array $rows): void
{
    $owners = manifestOwnerPaths();
    $unknown = [];
    $allowed = array_fill_keys(array_keys(NON_MANIFEST_TEST_OWNERS), 0);

    foreach ($rows as $row) {
        if (!str_starts_with($row['current_path'], 'tests/') && !str_starts_with($row['target_path'], 'tests/')) {
            continue;
        }
        if (in_array($row['subject_owner'], $owners, true)) {
            continue;
        }
        if (isset($allowed[$row['subject_owner']])) {
            ++$allowed[$row['subject_owner']];

            continue;
        }

        $unknown[$row['subject_owner']][] = $row['current_path'];
    }

    $mismatches = [];
    foreach ($unknown as $owner => $paths) {
        $mismatches[] = sprintf(
            '%s is not one of the %d manifest owners, and %d row(s) under tests/ publish it: %s',
            $owner,
            count($owners),
            count($paths),
            implode(', ', $paths),
        );
    }

    foreach (NON_MANIFEST_TEST_OWNERS as $owner => $entry) {
        if ($allowed[$owner] !== $entry['rows']) {
            $mismatches[] = sprintf(
                '%s is allowed here for %d row(s) and the tree now has %d (%s)',
                $owner,
                $entry['rows'],
                $allowed[$owner],
                $entry['reason'],
            );
        }
    }

    if ($mismatches !== []) {
        fail(
            "A test artifact's owner is a manifest owner, and these are not:\n  "
            . implode("\n  ", $mismatches)
            . "\nEither file the artifact under the owner that owns it, or name the exception in"
            . ' NON_MANIFEST_TEST_OWNERS with the reason and the row count.',
        );
    }
}

/**
 * @param list<array<string, string>> $rows
 * @param array<string, int> $discoveredCaseCounts
 */
function validateInventory(array $rows, array $discoveredCaseCounts): void
{
    $mappedDiscoveredClasses = [];
    $targetPaths = [];

    foreach ($rows as $row) {
        foreach (array_filter(explode(',', $row['discovered_classes']), static fn(string $class): bool => $class !== '') as $class) {
            $mappedDiscoveredClasses[$class][] = $row['current_path'];
        }
        if ($row['target_path'] !== 'DELETE') {
            $targetPaths[$row['target_path']][] = $row['current_path'];
        }
        if (str_ends_with($row['current_path'], 'Test.php') && $row['discovered_classes'] === '') {
            fail('PHPUnit did not discover worktree test file: ' . $row['current_path']);
        }

        // A PHPUnit test class currentSuite() cannot place in any configured
        // phpunit.xml.dist <testsuite> is a test `composer test` silently
        // never runs — the shape that let SuppressedFormatterTest.php (5
        // methods) and tests/Reporting/Unit/OutputFormatResolverTest.php (1
        // method, dormant since the modular-architecture migration) sit
        // green in `composer check` without ever executing. currentSuite()
        // already has a closed literal per directory (see the function's
        // docblock and assertSuiteClassifierAgreesWithPhpunit()), so 'none'
        // on a phpunit-test-class always means a missing literal, never a
        // legitimate resident — the only other current_suite: 'none' rows
        // are non-PHPUnit artifacts (kind !== 'phpunit-test-class': JS test
        // files, package.json, fixtures, placeholders).
        if ($row['kind'] === 'phpunit-test-class' && $row['current_suite'] === 'none') {
            fail(sprintf(
                'PHPUnit test class classified as suite "none" (no phpunit.xml.dist <testsuite> directory covers'
                . ' it, so `composer test` silently never runs it): %s. Add its directory to a <testsuite> in'
                . ' phpunit.xml.dist and to the matching branch of currentSuite().',
                $row['current_path'],
            ));
        }
    }

    $unmatched = array_diff_key($discoveredCaseCounts, $mappedDiscoveredClasses);
    if ($unmatched !== []) {
        fail('Discovered PHPUnit classes without worktree files: ' . implode(', ', array_keys($unmatched)));
    }

    foreach ($mappedDiscoveredClasses as $class => $paths) {
        if (count(array_unique($paths)) > 1) {
            fail(sprintf('Duplicate discovered PHPUnit class %s in: %s', $class, implode(', ', $paths)));
        }
    }

    foreach ($targetPaths as $target => $paths) {
        $uniquePaths = array_values(array_unique($paths));
        if (count($uniquePaths) < 2) {
            continue;
        }

        fail(sprintf('Target collision at %s: %s', $target, implode(', ', $uniquePaths)));
    }

}

/**
 * @param list<array<string, string>> $rows
 *
 * @return list<array<string, string>>
 */
function fixtureDirectoryRows(array $rows): array
{
    $directories = [];
    foreach ($rows as $row) {
        if ($row['kind'] !== 'fixture') {
            continue;
        }

        $directory = dirname($row['current_path']);
        while ($directory !== '.' && (str_contains($directory, '/Fixtures') || str_contains($directory, '/Fixture') || str_contains($directory, '/data'))) {
            $directories[$directory]['owners'][$row['subject_owner']] = true;
            $directories[$directory]['orphan'] = ($directories[$directory]['orphan'] ?? true)
                && orphanCandidateReason($row['current_path']) !== null;
            // A directory whose every member file is already retained in
            // place (a tooling test root, e.g.) is not a pending move — it is
            // already at its final home, and saying "move" about it asserts a
            // relocation nobody planned. Retained is dispositionFor()'s answer
            // when a file's target equals its path, so this aggregate says
            // "move" only where some member really does still owe one.
            $directories[$directory]['retained'] = ($directories[$directory]['retained'] ?? true)
                && $row['disposition'] === 'Retain at the materialized subject-owned path.';
            $directory = dirname($directory);
        }
    }
    ksort($directories, SORT_STRING);

    $result = [];
    foreach ($directories as $directory => $data) {
        $owners = array_keys($data['owners']);
        sort($owners, SORT_STRING);
        $result[] = [
            'current_directory' => $directory,
            'subject_owners' => implode(',', $owners),
            'disposition' => $data['orphan']
                ? 'Retain until consumer proof; delete only if confirmed orphan.'
                : ($data['retained']
                    ? 'Retain at the materialized subject-owned path.'
                    : (count($owners) > 1 ? 'Split by file owner.' : 'Move atomically with the owning subject.')),
        ];
    }

    return $result;
}

/**
 * @param list<array<string, string>> $rows
 */
function tsvContents(array $rows): string
{
    if ($rows === []) {
        fail('Refusing to render an empty TSV.');
    }

    $handle = fopen('php://temp', 'w+b');
    if ($handle === false) {
        fail('Cannot create temporary TSV stream.');
    }

    fputcsv($handle, array_keys($rows[0]), "\t", '"', '');
    foreach ($rows as $row) {
        fputcsv($handle, $row, "\t", '"', '');
    }
    rewind($handle);
    $contents = stream_get_contents($handle);
    fclose($handle);
    if ($contents === false) {
        fail('Cannot read temporary TSV stream.');
    }

    return $contents;
}

function emitGenerated(string $path, string $contents, bool $check): void
{
    if ($check) {
        $current = is_file($path) ? file_get_contents($path) : false;
        if ($current !== $contents) {
            fail('Generated artifact is stale: ' . $path);
        }
        return;
    }

    writeFileAtomically($path, $contents);
}

function orphanDispositionContents(): string
{
    $rows = [];
    foreach (P8_ORPHAN_DISPOSITIONS as $path => [$group, $decision, $rationale]) {
        $rows[] = [$group, $path, $decision, 'A03 isolated probe', '0', $rationale];
    }

    return tsvContentsFromRows(
        ['group', 'source_path', 'decision', 'probe', 'exit_status', 'rationale'],
        $rows,
    );
}

function systemSupportContents(string $root): string
{
    $rows = [
        ['TestSupport/Logging', 'tests/TestSupport/Logging/Support/RecordingLogger.php', 'Shared PSR-3 recording helper for named Finding and Coupling tests.', 'support'],
    ];
    foreach ($rows as $row) {
        if (!is_file($root . '/' . $row[1])) {
            fail('missing System/TestSupport artifact: ' . $row[1]);
        }
    }
    foreach (['tests/TestSupport'] as $taxonomy) {
        $children = glob($root . '/' . $taxonomy . '/*');
        if ($children === false) {
            $children = [];
        }
        foreach ($children as $entry) {
            if (is_file($entry)) {
                fail('taxonomy root must not contain a direct file: ' . $entry);
            }
        }
    }

    return tsvContentsFromRows(['owner', 'path', 'justification', 'suite'], $rows);
}

/** @param list<array<string, string>> $rows
 * @param list<array<string, string>> $fixtureDirectories
 * @param array<string, int> $discoveredCaseCounts
 */
function topologyContents(array $rows, array $fixtureDirectories, array $discoveredCaseCounts): string
{
    return tsvContentsFromRows(
        ['metric', 'count'],
        [
            ['mapped_artifacts', (string) count($rows)],
            ['fixture_directories', (string) count($fixtureDirectories)],
            ['phpunit_classes', (string) count($discoveredCaseCounts)],
            ['phpunit_ids', (string) array_sum($discoveredCaseCounts)],
            ['orphan_dispositions', (string) count(P8_ORPHAN_DISPOSITIONS)],
        ],
    );
}

/** @param list<string> $header
 * @param list<list<string>> $rows
 */
function tsvContentsFromRows(array $header, array $rows): string
{
    $handle = fopen('php://temp', 'w+b');
    if ($handle === false) {
        fail('Cannot create TSV buffer.');
    }
    fputcsv($handle, $header, "\t", '"', '');
    foreach ($rows as $row) {
        fputcsv($handle, $row, "\t", '"', '');
    }
    rewind($handle);
    $contents = stream_get_contents($handle);
    fclose($handle);
    if ($contents === false) {
        fail('Cannot read TSV buffer.');
    }

    return $contents;
}

function writeFileAtomically(string $path, string $contents): void
{
    $temporaryPath = $path . '.tmp.' . getmypid();
    if (file_put_contents($temporaryPath, $contents) === false) {
        fail('Cannot write temporary file: ' . $temporaryPath);
    }
    if (!rename($temporaryPath, $path)) {
        fail('Cannot replace file: ' . $path);
    }
}

/** @return array{version_line: string, exact_ids: list<string>} */
function parsePhpunitDiscovery(string $output): array
{
    $normalized = str_replace("\r\n", "\n", $output);
    if (!str_ends_with($normalized, "\n")) {
        fail('PHPUnit discovery output must end with a terminal LF.');
    }
    $lines = explode("\n", substr($normalized, 0, -1));
    $versionLine = array_shift($lines);
    if ($versionLine === null || !str_starts_with($versionLine, 'PHPUnit ')) {
        fail('Cannot read the PHPUnit version line from discovery output.');
    }
    if (array_shift($lines) !== '' || array_shift($lines) !== 'Available tests:') {
        fail('Cannot read the PHPUnit discovery heading.');
    }

    if ($lines === []) {
        fail('PHPUnit discovery output contains no test IDs.');
    }
    $exactIds = [];
    foreach ($lines as $line) {
        if (preg_match('/^ - ([A-Za-z_][A-Za-z0-9_\\\\]*)::([A-Za-z_][A-Za-z0-9_]*)(?:#[0-9]+|".*")?$/', $line, $matches) !== 1) {
            fail('Unexpected PHPUnit discovery output line: ' . $line);
        }
        $exactId = substr($line, 3);
        if (isset($exactIds[$exactId])) {
            fail('Duplicate PHPUnit exact test ID: ' . $exactId);
        }
        $exactIds[$exactId] = true;
    }
    $exactIds = array_keys($exactIds);
    sort($exactIds, \SORT_STRING);

    return ['version_line' => $versionLine, 'exact_ids' => $exactIds];
}

/** @param array{version_line: string, exact_ids: list<string>} $discovery */
function canonicalPhpunitDiscovery(array $discovery): string
{
    $testLines = array_map(static fn(string $exactId): string => ' - ' . $exactId, $discovery['exact_ids']);

    return implode("\n", [$discovery['version_line'], '', 'Available tests:', ...$testLines]) . "\n";
}

/**
 * @param list<array<string, string>> $rows
 */
function worktreeSuiteOutput(array $rows, string $phpunitDiscovery): string
{
    $versionLine = strtok($phpunitDiscovery, "\n");
    if ($versionLine === false || !str_starts_with($versionLine, 'PHPUnit ')) {
        fail('Cannot read the PHPUnit version line from discovery output.');
    }

    $suites = [];
    foreach ($rows as $row) {
        if ($row['kind'] !== 'phpunit-test-class') {
            continue;
        }

        $suite = $row['current_suite'];
        $suites[$suite]['files'] = ($suites[$suite]['files'] ?? 0) + 1;
        $suites[$suite]['cases'] = ($suites[$suite]['cases'] ?? 0) + (int) $row['discovered_test_cases'];
    }
    ksort($suites, SORT_STRING);

    $lines = [
        $versionLine,
        '',
        'Worktree test suites (derived from PHPUnit discovery):',
    ];
    foreach ($suites as $suite => $counts) {
        $lines[] = sprintf(' - %s (%d files, %d tests)', $suite, $counts['files'], $counts['cases']);
    }

    return implode("\n", $lines) . "\n";
}

/**
 * @param list<array<string, string>> $rows
 * @param list<array<string, string>> $fixtureDirectories
 * @param array<string, int> $discoveredCaseCounts
 *
 * @return array<string, mixed>
 */
function inventorySummary(array $rows, array $fixtureDirectories, array $discoveredCaseCounts): array
{
    $kindCounts = array_count_values(array_column($rows, 'kind'));
    $suiteFileCounts = array_count_values(array_column(
        array_filter($rows, static fn(array $row): bool => $row['kind'] === 'phpunit-test-class'),
        'current_suite',
    ));
    ksort($kindCounts, SORT_STRING);
    ksort($suiteFileCounts, SORT_STRING);

    $targetPaths = [];
    foreach ($rows as $row) {
        if ($row['target_path'] !== 'DELETE') {
            $targetPaths[$row['target_path']][] = $row['current_path'];
        }
    }
    $targetCollisions = array_filter(
        $targetPaths,
        static fn(array $paths): bool => count(array_unique($paths)) > 1,
    );
    $orphanCandidates = array_filter(
        $rows,
        static fn(array $row): bool => orphanCandidateReason($row['current_path']) !== null,
    );

    return [
        'worktree_artifacts' => count($rows),
        'fixture_directories' => count($fixtureDirectories),
        'discovered_unique_classes' => count($discoveredCaseCounts),
        'discovered_test_cases' => array_sum($discoveredCaseCounts),
        'undiscovered_test_files' => 0,
        'duplicate_discovered_classes' => 0,
        'explicit_target_collisions' => count($targetCollisions),
        'explicit_orphan_candidates' => count($orphanCandidates),
        'kind_counts' => $kindCounts,
        'suite_file_counts' => $suiteFileCounts,
    ];
}

/**
 * Every path literal this file asserts something about, resolved against the
 * worktree.
 *
 * The closures below are matched against discovered paths, so a literal that
 * matches nothing does not fail — it just stops meaning anything. That is how
 * one rename left seven paths in two closures pointing at files no longer on
 * disk while every generation stayed green, and the mechanism does not care
 * which rename it was: a name is either checked or it rots.
 *
 * Both directions are checked, because both rot: a closure entry must exist,
 * and a retired entry must not. The prefix and pattern literals inside
 * `classifyOwner()`, `currentSuite()` and `targetPath()` are deliberately
 * outside this check — they classify an arbitrary input path, including
 * pre-migration ones handed in through `--classification-probe=`, and are
 * claims about inputs rather than about the tree.
 *
 * What they are claims about has narrowed, deliberately and once: the literals
 * naming `tests/Unit/`, `tests/Integration/` and `tests/Functional/` were
 * deleted when the stage-04 campaign emptied those three roots. A probe for a
 * path under one of them no longer resolves to the owner it had before the
 * campaign — it is refused as an unclassified artifact, or, being a test class,
 * refused by the parse. That is the intended end state: the classifier answers
 * for the tree the repository has, and one epoch back is the last epoch it
 * still answers for.
 */
function assertPathLiteralsResolve(string $projectRoot): void
{
    $closures = [
        'P4_IGNORED_FIXTURE_PATHS' => P4_IGNORED_FIXTURE_PATHS,
        'P7_MEASUREMENT_PATHS' => P7_MEASUREMENT_PATHS,
        'P3_TEST_PATHS' => P3_TEST_PATHS,
        'P6_A_FINDING_TEST_PATHS' => P6_A_FINDING_TEST_PATHS,
        'P6_B_FINDING_TEST_PATHS' => P6_B_FINDING_TEST_PATHS,
        'P6_B_INLINE_TEST_PATHS' => P6_B_INLINE_TEST_PATHS,
        'P6_D_PRIORITIZATION_TEST_PATHS' => P6_D_PRIORITIZATION_TEST_PATHS,
    ];

    foreach ($closures as $constant => $paths) {
        foreach ($paths as $path) {
            if (!is_file($projectRoot . '/' . $path)) {
                fail(sprintf(
                    '%s names %s, which the worktree does not have. Follow the rename, or move the entry to'
                    . ' RETIRED_PATH_ASSERTIONS with the reason it is gone.',
                    $constant,
                    $path,
                ));
            }
        }
    }

    $retired = RETIRED_PATH_ASSERTIONS;

    foreach (array_keys(ORPHAN_CANDIDATE_PREFIXES) as $prefix) {
        $retired[$prefix] = 'ORPHAN_CANDIDATE_PREFIXES; the P8 orphan disposition for it has been carried out.';
    }

    foreach (array_keys(P8_ORPHAN_DISPOSITIONS) as $path) {
        $retired[$path] = 'P8_ORPHAN_DISPOSITIONS; the recorded disposition has been carried out.';
    }

    foreach ($retired as $path => $reason) {
        if (file_exists($projectRoot . '/' . rtrim($path, '/'))) {
            fail(sprintf(
                '%s is recorded as retired (%s) but exists again. Either the record is wrong, or the artifact'
                . ' came back and needs a live disposition.',
                $path,
                $reason,
            ));
        }
    }
}

function fail(string $message): never
{
    fwrite(STDERR, $message . "\n");
    exit(1);
}
