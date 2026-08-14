#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Generates the P0 test-topology evidence for the modular-architecture migration.
 *
 * The inventory is intentionally based on the committable worktree (tracked
 * plus non-ignored untracked files) and PHPUnit's own discovery output. It
 * fails closed when a test disappears, a discovered class cannot be mapped to
 * a file, or two artifacts converge on one target without an explicit
 * migration disposition.
 */

const OUTPUT_DIRECTORY = 'docs/internal/generated/modular-architecture';
const P6_C_BASELINE_PATHS_SHA256 = '432fa29e394eb7fc070bce14e7f276c7237a6453fa91d4d134443766ad30e1f9';
const EXPECTED_PHPUNIT_CLASSES = 509;
const EXPECTED_PHPUNIT_IDS = 7254;

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

/** @var array<string, string> */
const EXPLICIT_PATH_DISPOSITIONS = [
    'tests/Infrastructure/Logging/LoggerFactoryTest.php' => 'P8: consolidate overlapping LoggerFactory coverage before moving to Infrastructure/Unit.',
    'tests/Unit/Infrastructure/Logging/LoggerFactoryTest.php' => 'P8: consolidate overlapping LoggerFactory coverage before moving to Infrastructure/Unit.',
];

/** @var list<string> JSON fixtures are covered by a repository-wide ignore rule until staged. */
const P4_IGNORED_FIXTURE_PATHS = [
    'tests/Analysis/Policy/Architecture/Fixtures/Sample/expected-violations.json',
    'tests/Analysis/Policy/Architecture/Fixtures/Sample/phase1-compat-violations-warn.json',
    'tests/Analysis/Policy/Architecture/Fixtures/Sample/phase1-compat-violations.json',
];

/** @var list<string> Exact Measurement artifacts materialized by P7. */
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
    'tests/Analysis/Evidence/Measurement/Tests/test_cross_tool_comparison.py',
    'tests/Analysis/Evidence/Measurement/Unit/AnonymousClassContextRegressionTest.php',
    'tests/Analysis/Evidence/Measurement/Unit/CallableWithMetricsTest.php',
    'tests/Analysis/Evidence/Measurement/Unit/DataBagTest.php',
    'tests/Analysis/Evidence/Measurement/Unit/MetricBagTest.php',
    'tests/Analysis/Evidence/Measurement/Unit/MetricDefinitionTest.php',
    'tests/Analysis/Evidence/Measurement/Unit/VisitorMethodContextTest.php',
];

/** @var list<string> Exact P3 test classes; future siblings require an ownership decision. */
const P3_TEST_PATHS = [
    'tests/Analysis/Configuration/Integration/ConfigSchemaCoverageTest.php',
    'tests/Analysis/Configuration/Integration/ConfigurationPipelineIntegrationTest.php',
    'tests/Analysis/Configuration/Integration/ArchitectureConfigurationWarningIntegrationTest.php',
    'tests/Analysis/Configuration/Integration/FullPipelineIntegrationTest.php',
    'tests/Analysis/Configuration/Integration/Loader/YamlNormalizationCharacterizationTest.php',
    'tests/Analysis/Configuration/Integration/PresetIntegrationTest.php',
    'tests/Analysis/Configuration/Integration/RuleOptionKeyNormalizationTest.php',
    'tests/Analysis/Configuration/Integration/YamlKeyReachabilityTest.php',
    'tests/Analysis/Configuration/Unit/AnalysisConfigurationCacheDirResolutionTest.php',
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
    'tests/Analysis/Configuration/Unit/RuleNamespaceExclusionProviderTest.php',
    'tests/Analysis/Configuration/Unit/RuleOptionsFactoryTest.php',
    'tests/Analysis/Configuration/Unit/RuleOptionsParserTest.php',
    'tests/Analysis/Configuration/Unit/RulePathExclusionProviderTest.php',
    'tests/Analysis/Configuration/Unit/RuleThresholdKeyGroupRegistryDriftTest.php',
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
    'tests/Analysis/Evidence/Measurement/Unit/Contract/CollectorRuntimeConfigurationTest.php',
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
    'tests/Analysis/Run/Unit/Collection/Declaration/DeclarationBindingsTest.php',
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
    'tests/Analysis/Run/Unit/Pipeline/MetricEnricherTest.php',
    'tests/Analysis/Run/Unit/RuleProducerPreparationTest.php',
    'tests/Unit/Analysis/RuleExecution/RuleExclusionStatsTest.php',
    'tests/Unit/Analysis/RuleExecution/RuleExecutorTest.php',
    'tests/Unit/Infrastructure/Console/CheckScopeResolverTest.php',
    'tests/Unit/Infrastructure/Console/RuntimeLoggerConfiguratorTest.php',
];

/** @var list<string> Exact P6-A Finding test closure; future siblings require an ownership decision. */
const P6_A_FINDING_TEST_PATHS = [
    'tests/Analysis/Finding/Fixtures/Channels/declared.txt',
    'tests/Analysis/Finding/Fixtures/Channels/excluded.txt',
    'tests/Analysis/Finding/Integration/ChannelCoverageTest.php',
    'tests/Analysis/Finding/Integration/ChannelDeclarationFixtureDriftTest.php',
    'tests/Analysis/Finding/Integration/ChannelEmissionStaticGuardTest.php',
    'tests/Analysis/Finding/Integration/RuleOptionKeyNormalizationTest.php',
    'tests/Analysis/Finding/Support/StubChannelDeclarationRegistry.php',
    'tests/Analysis/Finding/Support/ViolationFactory.php',
    'tests/Analysis/Finding/Unit/AbstractRuleSubjectControlTest.php',
    'tests/Analysis/Finding/Unit/AcceptedLevelTest.php',
    'tests/Analysis/Finding/Unit/AnalysisContextTest.php',
    'tests/Analysis/Finding/Unit/ChannelDeclarationCompilerPassTest.php',
    'tests/Analysis/Finding/Unit/ChannelDeclarationReaderTest.php',
    'tests/Analysis/Finding/Unit/ChannelDeclarationRegistryTest.php',
    'tests/Analysis/Finding/Unit/ChannelDeclarationTest.php',
    'tests/Analysis/Finding/Unit/LocationNullFileTest.php',
    'tests/Analysis/Finding/Unit/LocationTest.php',
    'tests/Analysis/Finding/Unit/NamespaceExclusionFilterTest.php',
    'tests/Analysis/Finding/Unit/OccurrenceKeyTest.php',
    'tests/Analysis/Finding/Unit/PathExclusionFilterTest.php',
    'tests/Analysis/Finding/Unit/PredicateFilterStageTest.php',
    'tests/Analysis/Finding/Unit/RuleExclusionCaptureHolderTest.php',
    'tests/Analysis/Finding/Unit/RuleExecutorTest.php',
    'tests/Analysis/Finding/Unit/RuleLevelTest.php',
    'tests/Analysis/Finding/Unit/RuleMatcherTest.php',
    'tests/Analysis/Finding/Unit/RuleNameReaderTest.php',
    'tests/Analysis/Finding/Unit/RuleNamespaceExclusionProviderTest.php',
    'tests/Analysis/Finding/Unit/RuleOptionsFactoryTest.php',
    'tests/Analysis/Finding/Unit/RuleOptionsParserTest.php',
    'tests/Analysis/Finding/Unit/RulePathExclusionProviderTest.php',
    'tests/Analysis/Finding/Unit/RuleSelectorTest.php',
    'tests/Analysis/Finding/Unit/RuleThresholdKeyGroupRegistryDriftTest.php',
    'tests/Analysis/Finding/Unit/SeverityTest.php',
    'tests/Analysis/Finding/Unit/ThresholdParserTest.php',
    'tests/Analysis/Finding/Unit/ThresholdValidatorAssignmentTest.php',
    'tests/Analysis/Finding/Unit/ViolationChannelTest.php',
    'tests/Analysis/Finding/Unit/ViolationFilterStageTest.php',
    'tests/Analysis/Finding/Unit/ViolationTest.php',
];

/** @var list<string> Exact P6-B additions to the Finding test closure. */
const P6_B_FINDING_TEST_PATHS = [
    'tests/Analysis/Finding/Unit/AnalysisContextThresholdTest.php',
    'tests/Analysis/Finding/Unit/ThresholdOverrideTest.php',
];

/** @var list<string> Exact P6-B Inline test closure; future siblings require an ownership decision. */
const P6_B_INLINE_TEST_PATHS = [
    'tests/Analysis/Policy/Inline/Fixtures/IgnoreSample/Controller/PolicedController.php',
    'tests/Analysis/Policy/Inline/Fixtures/IgnoreSample/Controller/SilencedController.php',
    'tests/Analysis/Policy/Inline/Fixtures/IgnoreSample/Domain/Customer.php',
    'tests/Analysis/Policy/Inline/Fixtures/IgnoreSample/Repository/CustomerRepository.php',
    'tests/Analysis/Policy/Inline/Fixtures/IgnoreSample/Service/CustomerService.php',
    'tests/Analysis/Policy/Inline/Integration/InlineSuppressionLayerViolationIntegrationTest.php',
    'tests/Analysis/Policy/Inline/Integration/ThresholdAnnotationParserPathTest.php',
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
    'tests/Analysis/Policy/Inline/Unit/ThresholdOverrideIntegrationTest.php',
    'tests/Analysis/Policy/Inline/Unit/WarningOnlyValidatorTest.php',
];

/** @var list<string> Exact P6-D subject-owned test moves. */
const P6_D_REPORTING_TEST_PATHS = [
    'tests/Reporting/FindingProjection/Unit/FindingProjectorTest.php',
];

/** @var list<string> Exact P6-D Prioritization subject-unit moves. */
const P6_D_PRIORITIZATION_TEST_PATHS = [
    'tests/Analysis/Evidence/Prioritization/Unit/Debt/DebtCalculatorTest.php',
    'tests/Analysis/Evidence/Prioritization/Unit/Debt/DebtSummaryTest.php',
    'tests/Analysis/Evidence/Prioritization/Unit/Debt/RemediationTimeRegistryTest.php',
    'tests/Analysis/Evidence/Prioritization/Unit/Impact/ClassRankResolverTest.php',
    'tests/Analysis/Evidence/Prioritization/Unit/Impact/ImpactCalculatorTest.php',
];

/** @var list<string> Exact P6-D Infrastructure Git adapter authorities. */
const P6_D_GIT_TEST_PATHS = [
    'tests/Integration/Infrastructure/Git/ReportingGitScopeQueryProjectSubdirTest.php',
    'tests/Unit/Infrastructure/Git/ReportingGitScopeQueryTest.php',
];

/** @var list<string> Exact live additions relative to the accepted 509/7,245 authority. */
const P6_LIVE_ADDED_TEST_IDS = [
    'Qualimetrix\\Tests\\Analysis\\Finding\\Unit\\RuleExecutorTest::itPublishesRuleMetadataWithExactAliasMappingWithoutConcreteRuleInstances',
    'Qualimetrix\\Tests\\Analysis\\Finding\\Unit\\RuleNamespaceExclusionProviderTest::itConfiguresAndQueriesNamespaceExclusionsWithoutProviderAccess',
    'Qualimetrix\\Tests\\Analysis\\Finding\\Unit\\RuleNamespaceExclusionProviderTest::itConfiguresAndQueriesNamespaceChannelExclusionsWithoutProviderAccess',
    'Qualimetrix\\Tests\\Analysis\\Policy\\Inline\\Unit\\Extraction\\SourceControlExtractorTest::itExtractsSourceControlsWithoutRunDeclarationBindings',
    'Qualimetrix\\Tests\\Integration\\Infrastructure\\Git\\ReportingGitScopeQueryProjectSubdirTest::itProjectsGitScopeThroughTheReportingPortWithoutAReverseImport',
    'Qualimetrix\\Tests\\Analysis\\Run\\Integration\\Pipeline\\AnalysisPipelineIntegrationTest::itPreservesInlineControlsAcrossARealParallelWorkerRoundTrip',
];

/** @var array<string, string> Exact zero-net method-ID replacements. */
const P6_RENAMED_TEST_IDS = [
    'Qualimetrix\\Tests\\Unit\\Infrastructure\\DependencyInjection\\CompilerPass\\RuleCompilerPassTest::itCollectsTaggedRulesIntoRuleExecutor' => 'Qualimetrix\\Tests\\Infrastructure\\Unit\\RuleCompilerPassTest::itCollectsTaggedRulesIntoRuleExecution',
    'Qualimetrix\\Tests\\Integration\\DependencyInjection\\ContainerFactoryTest::itInjectsRulesIntoRuleExecutor' => 'Qualimetrix\\Tests\\Integration\\DependencyInjection\\ContainerFactoryTest::itInjectsRulesIntoRuleExecution',
    'Qualimetrix\\Tests\\Integration\\Infrastructure\\Console\\RuleExclusionStatsWiringTest::itSharesTheSameRuleExecutorInstanceBetweenThePipelineAndTheOrchestrator' => 'Qualimetrix\\Tests\\Infrastructure\\Integration\\RuleExclusionStatsWiringTest::itSharesTheSameRuleExecutionInstanceBetweenThePipelineAndTheOrchestrator',
    'Qualimetrix\\Tests\\Infrastructure\\Console\\Functional\\Command\\CheckCommandBaselineTest::itDoesNotPromoteAnAnnotatedFindingTheBaselineNeverMeasured' => 'Qualimetrix\\Tests\\Infrastructure\\Console\\Functional\\Command\\CheckCommandBaselineTest::itCombinesConfiguredAndCliExclusionsWithoutLosingBaselineAnnotationOrGit',
];

$projectRoot = realpath(__DIR__ . '/..');
if ($projectRoot === false) {
    fail('Cannot resolve the project root.');
}
$p6CBaselinePaths = p6CBaselinePaths($projectRoot);
if (hash('sha256', implode("\n", $p6CBaselinePaths) . "\n") !== P6_C_BASELINE_PATHS_SHA256) {
    fail('P6-C Baseline test artifact set differs from the reviewed finite path digest.');
}

if ($classificationProbeArguments !== []) {
    $path = substr($classificationProbeArguments[0], strlen('--classification-probe='));
    [$owner, $closurePackage] = classifyOwner($path);
    $currentSuite = currentSuite($path);
    $targetSuite = $currentSuite === 'Infrastructure'
        ? (str_contains($path, '/Integration/') ? 'Integration' : 'Unit')
        : $currentSuite;
    fwrite(STDOUT, implode("\t", [
        $owner,
        $closurePackage,
        $currentSuite,
        targetPath($path, 'phpunit-test-class', $owner, $targetSuite),
    ]) . "\n");
    exit(0);
}

$worktreePaths = commandLines(
    ['git', 'ls-files', '--cached', '--others', '--exclude-standard', '--', 'tests', 'scripts/tests', 'src/Reporting/Template/tests', 'src/Reporting/Template/package.json', 'src/Reporting/Template/vite.config.js'],
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

    [$owner, $closurePackage] = classifyOwner($path);
    $kind = classifyKind($path, $discoveredClasses);
    $currentSuite = currentSuite($path);
    $targetSuite = $kind === 'phpunit-test-class'
        ? ($currentSuite === 'Infrastructure'
            ? (str_contains($path, '/Integration/') ? 'Integration' : 'Unit')
            : $currentSuite)
        : 'none';
    $disposition = dispositionFor($path, $kind);

    if (isset(EXPLICIT_PATH_DISPOSITIONS[$path]) || orphanCandidateReason($path) !== null || $kind === 'placeholder') {
        $closurePackage = 'P8';
    }

    $rows[] = [
        'current_path' => $path,
        'kind' => $kind,
        'classes' => implode(',', $classes),
        'discovered_classes' => implode(',', $discoveredClasses),
        'discovered_test_cases' => (string) $discoveredCases,
        'current_suite' => $currentSuite,
        'target_suite' => $targetSuite,
        'subject_owner' => $owner,
        'target_path' => targetPath($path, $kind, $owner, $targetSuite),
        'closure_package' => $closurePackage,
        'disposition' => $disposition,
    ];
}

validateInventory($rows, $discoveredCaseCounts);
validateP4Topology($rows);
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
    [$stdout, $stderr] = drainProcessPipes($pipes[1], $pipes[2]);
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

/** @param resource $stdoutPipe
 * @param resource $stderrPipe
 *
 * @return array{string, string}
 */
function drainProcessPipes($stdoutPipe, $stderrPipe): array
{
    stream_set_blocking($stdoutPipe, false);
    stream_set_blocking($stderrPipe, false);
    $streams = [(int) $stdoutPipe => ['stream' => $stdoutPipe, 'index' => 0], (int) $stderrPipe => ['stream' => $stderrPipe, 'index' => 1]];
    $output = ['', ''];
    while ($streams !== []) {
        $read = array_column($streams, 'stream');
        $write = null;
        $except = null;
        if (stream_select($read, $write, $except, null) === false) {
            fail('Cannot read command output streams.');
        }
        foreach ($read as $stream) {
            $key = (int) $stream;
            $chunk = stream_get_contents($stream);
            if ($chunk === false) {
                fail('Cannot read command output stream.');
            }
            $output[$streams[$key]['index']] .= $chunk;
            if (feof($stream)) {
                fclose($stream);
                unset($streams[$key]);
            }
        }
    }

    return [$output[0], $output[1]];
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
 * @return array{string, string}
 */
function classifyOwner(string $path): array
{
    if (preg_match('#^tests/Analysis/Evidence/(CodeSmell|Cohesion|Complexity|Coupling|Design|Maintainability|Security|Size)/#', $path, $matches) === 1) {
        return ['Analysis/Evidence/' . $matches[1], 'P7'];
    }
    if (in_array($path, P7_MEASUREMENT_PATHS, true)) {
        return ['Analysis/Evidence/Measurement', 'P7'];
    }
    global $p6CBaselinePaths;

    if (str_starts_with($path, 'tests/Analysis/Policy/Baseline/')) {
        if (!in_array($path, $p6CBaselinePaths, true)) {
            fail('Unclassified P6-C Baseline test artifact: ' . $path);
        }

        return ['Analysis/Policy/Baseline', 'P6-C'];
    }
    if (in_array($path, P6_B_FINDING_TEST_PATHS, true)) {
        return ['Analysis/Finding', 'P6-B'];
    }
    if (str_starts_with($path, 'tests/Analysis/Finding/')) {
        if (!in_array($path, P6_A_FINDING_TEST_PATHS, true)) {
            fail('Unclassified P6-A Finding test artifact: ' . $path);
        }

        return ['Analysis/Finding', 'P6-A'];
    }
    if (str_starts_with($path, 'tests/Analysis/Policy/Inline/')) {
        if (!in_array($path, P6_B_INLINE_TEST_PATHS, true)) {
            fail('Unclassified P6-B Inline test artifact: ' . $path);
        }

        return ['Analysis/Policy/Inline', 'P6-B'];
    }
    if (in_array($path, P6_D_REPORTING_TEST_PATHS, true)) {
        return ['Reporting/FindingProjection', 'P6-D'];
    }
    if (in_array($path, P6_D_PRIORITIZATION_TEST_PATHS, true)) {
        return ['Analysis/Evidence/Prioritization', 'P6-D'];
    }
    if (in_array($path, P6_D_GIT_TEST_PATHS, true)) {
        return ['Infrastructure/Git', 'P6-D'];
    }
    if (str_starts_with($path, 'tests/Analysis/Evidence/ComputedMetrics/Health/')) {
        return ['Analysis/Evidence/ComputedMetrics/Health', 'P5'];
    }
    if (str_starts_with($path, 'tests/Analysis/Evidence/ComputedMetrics/')) {
        return ['Analysis/Evidence/ComputedMetrics', 'P5'];
    }
    if (str_starts_with($path, 'tests/Analysis/Policy/Architecture/')) {
        return ['Analysis/Policy/Architecture', 'P4'];
    }
    if (str_starts_with($path, 'tests/Analysis/Evidence/CircularDependency/')) {
        return ['Analysis/Evidence/CircularDependency', 'P4'];
    }
    if (str_ends_with($path, 'Test.php')
        && (
            str_starts_with($path, 'tests/Analysis/Configuration/')
            || str_starts_with($path, 'tests/Analysis/Evidence/Measurement/')
            || str_starts_with($path, 'tests/Analysis/Evidence/DependencyModel/Unit/Extraction/')
            || str_starts_with($path, 'tests/Analysis/Run/')
        )
        && !in_array($path, P3_TEST_PATHS, true)
    ) {
        fail('Unclassified test artifact: ' . $path);
    }
    if (str_starts_with($path, 'scripts/tests/')) {
        return ['Analysis/Evidence/Measurement', 'P7'];
    }
    if (str_starts_with($path, 'src/Reporting/Template/tests/') || in_array($path, ['src/Reporting/Template/package.json', 'src/Reporting/Template/vite.config.js'], true)) {
        return ['Reporting/HtmlTemplate', 'permanent'];
    }
    if (str_starts_with($path, 'tests/Architecture/')) {
        if (str_contains($path, 'CircularDependency')) {
            return ['Analysis/Evidence/CircularDependency', 'P4'];
        }
        if (str_contains($path, 'InlineSuppression') || str_contains($path, '/Fixtures/IgnoreSample/')) {
            return ['Analysis/Policy/Inline', 'P6'];
        }

        return ['Analysis/Policy/Architecture', 'P4'];
    }
    if ($path === 'tests/Support/Console/StubBaselineRun.php' || $path === 'tests/Support/Console/TempDirectory.php' || str_starts_with($path, 'tests/Support/Time/')) {
        return ['Analysis/Policy/Baseline', 'P6'];
    }
    if (str_starts_with($path, 'tests/Support/Dependency/')) {
        return ['Analysis/Evidence/CircularDependency', 'P4'];
    }
    if (str_starts_with($path, 'tests/Support/Logger/')) {
        return ['TestSupport/Logging', 'P8'];
    }
    if (str_starts_with($path, 'tests/Support/Pipeline/')) {
        return ['Analysis/Run', 'P3'];
    }
    if (str_starts_with($path, 'tests/Support/Violation/')) {
        return ['Analysis/Finding', 'P6'];
    }
    if (str_starts_with($path, 'tests/Analysis/Configuration/')) {
        return ['Analysis/Configuration', 'P3'];
    }
    if (str_starts_with($path, 'tests/Fixture/')) {
        if (preg_match('/(DataClass|ReadonlyDto|SmallClass)/', $path) === 1) {
            return ['Analysis/Evidence/Design', 'P7'];
        }

        return ['Analysis/Configuration', 'P3'];
    }
    if (str_starts_with($path, 'tests/Fixtures/BaselineV10/')) {
        return ['Analysis/Policy/Baseline', 'P6'];
    }
    if (str_starts_with($path, 'tests/Fixtures/Channels/')) {
        return ['Analysis/Finding', 'P6'];
    }
    if (str_starts_with($path, 'tests/Fixtures/CircularDeps/')) {
        return ['Analysis/Evidence/CircularDependency', 'P4'];
    }
    if (str_starts_with($path, 'tests/Fixtures/CouplingProject/')) {
        return ['Analysis/Evidence/Coupling', 'P7'];
    }
    if (str_starts_with($path, 'tests/Fixtures/GoldenMetrics/') || str_starts_with($path, 'tests/Fixtures/Aggregation/')) {
        return ['Analysis/Run', 'P3'];
    }
    if (str_starts_with($path, 'tests/Fixtures/Inheritance/')) {
        return ['Analysis/Evidence/Design', 'P7'];
    }
    if (str_starts_with($path, 'tests/Fixtures/Schema/')) {
        return ['Reporting/Sarif', 'permanent'];
    }
    if (str_starts_with($path, 'tests/Fixtures/Ast/')) {
        return ['Infrastructure/Ast', 'permanent'];
    }
    if ($path === 'tests/Fixtures/AnonymousClassContext.php') {
        return ['Analysis/Evidence/Measurement', 'P7'];
    }
    if (
        str_starts_with($path, 'tests/Analysis/Evidence/Duplication/Unit/')
        || str_starts_with($path, 'tests/Unit/Analysis/Duplication/')
        || str_starts_with($path, 'tests/Unit/Core/Duplication/')
        || str_starts_with($path, 'tests/Unit/Rules/Duplication/')
    ) {
        return ['Analysis/Evidence/Duplication', 'P1'];
    }
    $p2DependencyModelTests = [
        'tests/Unit/Core/Dependency/DependencyTest.php',
        'tests/Unit/Core/Dependency/EmptyDependencyGraphTest.php',
        'tests/Unit/Analysis/Collection/Dependency/DependencyGraphTest.php',
        'tests/Unit/Analysis/Collection/Dependency/DependencyGraphBuilderTest.php',
        'tests/Analysis/Evidence/DependencyModel/Unit/DependencyTest.php',
        'tests/Analysis/Evidence/DependencyModel/Unit/EmptyDependencyGraphTest.php',
        'tests/Analysis/Evidence/DependencyModel/Unit/DependencyGraphTest.php',
        'tests/Analysis/Evidence/DependencyModel/Unit/DependencyGraphBuilderTest.php',
    ];
    if (in_array($path, $p2DependencyModelTests, true)) {
        return ['Analysis/Evidence/DependencyModel', 'P2'];
    }
    if (str_starts_with($path, 'tests/Analysis/Evidence/DependencyModel/')) {
        return ['Analysis/Evidence/DependencyModel', 'P3'];
    }
    if (str_starts_with($path, 'tests/Analysis/Evidence/Measurement/')) {
        return ['Analysis/Evidence/Measurement', 'P3'];
    }
    if (str_starts_with($path, 'tests/Analysis/Run/')) {
        return ['Analysis/Run', 'P3'];
    }

    $p2GraphProjectionTests = [
        'tests/Unit/Analysis/Collection/Dependency/Export/DotExporterTest.php',
        'tests/Unit/Analysis/Collection/Dependency/Export/JsonGraphExporterTest.php',
        'tests/Reporting/GraphProjection/Unit/DotExporterTest.php',
        'tests/Reporting/GraphProjection/Unit/JsonGraphExporterTest.php',
        'tests/Reporting/GraphProjection/Unit/DependencyGraphProjectorTest.php',
    ];
    if (in_array($path, $p2GraphProjectionTests, true)) {
        return ['Reporting/GraphProjection', 'P2'];
    }

    if (in_array($path, [
        'tests/Functional/Console/Command/GraphExportCommandTest.php',
        'tests/Infrastructure/Console/Functional/GraphExportCommandTest.php',
    ], true)) {
        return ['Infrastructure/Console', 'P2'];
    }

    if ($path === 'tests/Unit/Analysis/Collection/SourceControl/SourceControlsTest.php') {
        return ['Analysis/Policy/Inline', 'P6'];
    }

    if (preg_match('#^tests/Unit/Analysis/Collection/Dependency/(CircularDependencyDetector|Cycle)#', $path) === 1) {
        return ['Analysis/Evidence/CircularDependency', 'P4'];
    }
    if (in_array($path, [
        'tests/Unit/Analysis/Collection/Dependency/DependencyResolverTest.php',
        'tests/Unit/Analysis/Collection/Dependency/DependencyVisitorTest.php',
        'tests/Unit/Analysis/Collection/Dependency/TypeDependencyHelperTest.php',
    ], true)) {
        return ['Analysis/Run', 'P3'];
    }
    if (str_starts_with($path, 'tests/Unit/Analysis/Repository/') || str_starts_with($path, 'tests/Unit/Core/Metric/')) {
        return ['Analysis/Evidence/Measurement', 'P7'];
    }
    if (str_starts_with($path, 'tests/Unit/Analysis/') || str_starts_with($path, 'tests/Integration/Analysis/') || str_starts_with($path, 'tests/Integration/Pipeline/')) {
        return ['Analysis/Run', 'P3'];
    }
    if (str_starts_with($path, 'tests/Unit/Baseline/Suppression/') || str_contains($path, 'ThresholdAnnotationParser') || str_contains($path, 'ThresholdValidatorWiring')) {
        return ['Analysis/Policy/Inline', 'P6'];
    }
    if (str_starts_with($path, 'tests/Unit/Baseline/') || str_starts_with($path, 'tests/Integration/Baseline') || str_starts_with($path, 'tests/Functional/Console/Command/Baseline')) {
        return ['Analysis/Policy/Baseline', 'P6'];
    }
    if (str_starts_with($path, 'tests/Unit/Core/ComputedMetric/') || str_contains($path, 'ComputedMetric') || str_contains($path, 'ComputedMetrics') || str_contains($path, 'HealthFormula')) {
        return ['Analysis/Evidence/ComputedMetrics', 'P5'];
    }
    if (str_starts_with($path, 'tests/Unit/Core/Coupling/')) {
        return ['Analysis/Evidence/Coupling', 'P7'];
    }
    if (str_starts_with($path, 'tests/Unit/Core/Suppression/') || str_starts_with($path, 'tests/Unit/Core/Rule/Override/') || str_contains($path, 'AnalysisContextThreshold') || str_contains($path, 'ThresholdOverride')) {
        return ['Analysis/Policy/Inline', 'P6'];
    }
    if (str_starts_with($path, 'tests/Unit/Core/Rule/') || str_starts_with($path, 'tests/Unit/Core/Violation/') || str_starts_with($path, 'tests/Integration/Violation/') || str_contains($path, 'ChannelDeclaration')) {
        return ['Analysis/Finding', 'P6'];
    }
    if (str_starts_with($path, 'tests/Unit/Configuration/') || str_starts_with($path, 'tests/Integration/Configuration/')) {
        return ['Analysis/Configuration', 'P3'];
    }
    if (str_starts_with($path, 'tests/Unit/Metrics/ComputedMetric/') || str_starts_with($path, 'tests/Unit/Rules/ComputedMetric/')) {
        return ['Analysis/Evidence/ComputedMetrics', 'P5'];
    }
    if (preg_match('#^tests/Unit/(Metrics|Rules)/(CodeSmell|Complexity|Coupling|Design|Halstead|Maintainability|Security|Size)/#', $path, $matches) === 1) {
        $subject = match ($matches[2]) {
            'Halstead' => 'Maintainability',
            default => $matches[2],
        };

        return ['Analysis/Evidence/' . $subject, 'P7'];
    }
    if (str_starts_with($path, 'tests/Unit/Metrics/Structure/') || str_starts_with($path, 'tests/Unit/Rules/Structure/') || str_contains($path, 'Wmc')) {
        $subject = match (true) {
            preg_match('/(Lcom|TccLcc)/', $path) === 1 => 'Cohesion',
            preg_match('/(Inheritance|Dit|Noc)/', $path) === 1 => 'Design',
            preg_match('/MethodCount/', $path) === 1 => 'Size',
            preg_match('/(UnusedPrivate|TraitUsageResolver)/', $path) === 1 => 'CodeSmell',
            preg_match('/Rfc/', $path) === 1 => 'Coupling',
            preg_match('/Wmc/', $path) === 1 => 'Complexity',
            default => fail('Unclassified Structure test: ' . $path),
        };

        return ['Analysis/Evidence/' . $subject, 'P7'];
    }
    if (str_starts_with($path, 'tests/Integration/Metrics/')) {
        return ['Analysis/Run', 'P3'];
    }
    if (str_starts_with($path, 'tests/Unit/Metrics/')) {
        return ['Analysis/Evidence/Measurement', 'P7'];
    }
    if (str_starts_with($path, 'tests/Unit/Rules/CodeSmell/') || str_starts_with($path, 'tests/Integration/Rules/')) {
        return ['Analysis/Evidence/CodeSmell', 'P7'];
    }
    if (str_starts_with($path, 'tests/Unit/Rules/Support/') || str_starts_with($path, 'tests/Unit/Rules/AbstractRule') || str_contains($path, 'ThresholdValidatorAssignment')) {
        return ['Analysis/Finding', 'P6'];
    }
    if (str_starts_with($path, 'tests/Unit/Reporting/Health/')) {
        return ['Reporting', 'permanent'];
    }
    if (str_starts_with($path, 'tests/Unit/Reporting/Impact/')) {
        return ['Reporting/Impact', 'permanent'];
    }
    if (str_starts_with($path, 'tests/Unit/Reporting/Filter/') || str_contains($path, 'CoverageProjection') || str_contains($path, 'JsonShapePreservation')) {
        return ['Reporting/FindingProjection', 'P6'];
    }
    if (str_starts_with($path, 'tests/Unit/Reporting/') || str_starts_with($path, 'tests/Functional/Reporting/')) {
        return ['Reporting', 'permanent'];
    }
    if (str_starts_with($path, 'tests/Unit/Infrastructure/') || str_starts_with($path, 'tests/Infrastructure/') || str_starts_with($path, 'tests/Integration/Infrastructure/') || str_starts_with($path, 'tests/Integration/DependencyInjection/') || str_starts_with($path, 'tests/Integration/Profiler/')) {
        if (str_contains($path, 'ViolationFilter')) {
            return ['Infrastructure', 'P6'];
        }
        if (str_contains($path, 'Rule') || str_contains($path, 'CompilerPass')) {
            return ['Infrastructure', 'P7'];
        }

        return ['Infrastructure', 'permanent'];
    }
    if (str_contains($path, 'LayerAssignment')) {
        return ['Infrastructure/Console', 'P4'];
    }
    if (str_starts_with($path, 'tests/Functional/Console/Command/Hook')) {
        return ['Infrastructure/GitHook', 'permanent'];
    }
    if (str_starts_with($path, 'tests/Functional/Console/')) {
        return ['Infrastructure/Console', 'P3'];
    }
    if (str_starts_with($path, 'tests/Integration/Architecture/')) {
        return ['Analysis/Policy/Architecture', 'P0'];
    }
    if (str_starts_with($path, 'tests/Integration/Documentation/')) {
        return ['System/DocumentationConsistency', 'P8'];
    }
    if (str_starts_with($path, 'tests/Integration/Scripts/')) {
        return ['Analysis/Evidence/ComputedMetrics', 'P5'];
    }
    if (str_starts_with($path, 'tests/Unit/PhpStan/')) {
        return ['TestSupport/ArchitectureStaticAnalysis', 'P8'];
    }
    if (str_starts_with($path, 'tests/Unit/Core/')) {
        return ['Core', 'permanent'];
    }
    if (str_ends_with($path, '.gitkeep')) {
        return ['legacy-placeholder', 'P8'];
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

function currentSuite(string $path): string
{
    return match (true) {
        preg_match('#^tests/Analysis/Evidence/(CodeSmell|Cohesion|Complexity|Coupling|Design|Maintainability|Security|Size)/Unit/#', $path) === 1 => 'Unit',
        preg_match('#^tests/Analysis/Evidence/(CodeSmell|Complexity)/Integration/#', $path) === 1 => 'Integration',
        str_starts_with($path, 'tests/Architecture/Unit/'),
        str_starts_with($path, 'tests/Analysis/Policy/Architecture/Unit/'),
        str_starts_with($path, 'tests/Analysis/Policy/Baseline/Unit/'),
        str_starts_with($path, 'tests/Analysis/Policy/Inline/Unit/'),
        str_starts_with($path, 'tests/Analysis/Evidence/CircularDependency/Unit/'),
        str_starts_with($path, 'tests/Analysis/Evidence/Duplication/Unit/'),
        str_starts_with($path, 'tests/Analysis/Evidence/DependencyModel/Unit/'),
        str_starts_with($path, 'tests/Analysis/Evidence/Measurement/Unit/'),
        str_starts_with($path, 'tests/Analysis/Evidence/ComputedMetrics/Unit/'),
        str_starts_with($path, 'tests/Analysis/Evidence/ComputedMetrics/Health/Unit/'),
        str_starts_with($path, 'tests/Analysis/Evidence/Prioritization/Unit/'),
        str_starts_with($path, 'tests/Analysis/Configuration/Unit/'),
        str_starts_with($path, 'tests/Analysis/Finding/Unit/'),
        str_starts_with($path, 'tests/Analysis/Run/Unit/'),
        str_starts_with($path, 'tests/Reporting/GraphProjection/Unit/'),
        str_starts_with($path, 'tests/Reporting/FindingProjection/Unit/'),
        str_starts_with($path, 'tests/Unit/') => 'Unit',
        str_starts_with($path, 'tests/Architecture/Integration/'),
        str_starts_with($path, 'tests/Analysis/Policy/Architecture/Integration/'),
        str_starts_with($path, 'tests/Analysis/Policy/Baseline/Integration/'),
        str_starts_with($path, 'tests/Analysis/Policy/Inline/Integration/'),
        str_starts_with($path, 'tests/Analysis/Configuration/Integration/'),
        str_starts_with($path, 'tests/Analysis/Finding/Integration/'),
        str_starts_with($path, 'tests/Analysis/Evidence/Measurement/Integration/'),
        str_starts_with($path, 'tests/Analysis/Evidence/ComputedMetrics/Integration/'),
        str_starts_with($path, 'tests/Analysis/Run/Integration/'),
        str_starts_with($path, 'tests/Integration/') => 'Integration',
        str_starts_with($path, 'tests/Functional/'), str_starts_with($path, 'tests/Analysis/Policy/Baseline/Functional/'), str_starts_with($path, 'tests/Infrastructure/Console/Functional/') => 'Functional',
        str_starts_with($path, 'tests/Infrastructure/') => 'Infrastructure',
        default => 'none',
    };
}

function dispositionFor(string $path, string $kind): string
{
    if (preg_match('#^tests/Analysis/Evidence/(CodeSmell|Cohesion|Complexity|Coupling|Design|Maintainability|Security|Size)/#', $path) === 1
        || in_array($path, P7_MEASUREMENT_PATHS, true)
        || preg_match('#^tests/Infrastructure/(Unit|Integration)/#', $path) === 1
        || str_starts_with($path, 'tests/Analysis/Finding/')
        || str_starts_with($path, 'tests/Analysis/Policy/Inline/')
        || str_starts_with($path, 'tests/Analysis/Policy/Baseline/')
        || in_array($path, P6_D_REPORTING_TEST_PATHS, true)
        || in_array($path, P6_D_PRIORITIZATION_TEST_PATHS, true)
        || in_array($path, P6_D_GIT_TEST_PATHS, true)
        || str_starts_with($path, 'tests/Analysis/Evidence/ComputedMetrics/')
        || str_starts_with($path, 'tests/Unit/Reporting/Health/')
    ) {
        return 'Retain at the materialized subject-owned path.';
    }
    if (isset(EXPLICIT_PATH_DISPOSITIONS[$path])) {
        return EXPLICIT_PATH_DISPOSITIONS[$path];
    }

    $orphanReason = orphanCandidateReason($path);
    if ($orphanReason !== null) {
        return 'P8: retain in place until consumer proof; delete only if the orphan candidate is confirmed. ' . $orphanReason;
    }
    if ($kind === 'placeholder') {
        return 'P8: remove the empty legacy placeholder after its target topology exists.';
    }

    return 'Move atomically with the named owner and closure package.';
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
    if ($path === 'tests/Unit/Analysis/Collection/SourceControl/SourceControlsTest.php') {
        return 'tests/Analysis/Policy/Inline/Unit/Extraction/SourceControlExtractorTest.php';
    }
    if (preg_match('#^tests/Analysis/Evidence/(CodeSmell|Cohesion|Complexity|Coupling|Design|Maintainability|Security|Size)/#', $path) === 1
        || in_array($path, P7_MEASUREMENT_PATHS, true)
        || preg_match('#^tests/Infrastructure/(Unit|Integration)/#', $path) === 1
        || str_starts_with($path, 'tests/Analysis/Evidence/ComputedMetrics/')
        || str_starts_with($path, 'tests/Unit/Reporting/Health/')
        || in_array($path, P6_D_REPORTING_TEST_PATHS, true)
        || in_array($path, P6_D_PRIORITIZATION_TEST_PATHS, true)
        || in_array($path, P6_D_GIT_TEST_PATHS, true)
        || in_array($path, [
            'tests/Analysis/Policy/Inline/Unit/Extraction/DeclarationControlBindingsTest.php',
            'tests/Analysis/Policy/Inline/Unit/Extraction/SourceControlExtractorTest.php',
        ], true)
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
    if (count($discoveredCaseCounts) !== EXPECTED_PHPUNIT_CLASSES || count($exactIds) !== EXPECTED_PHPUNIT_IDS) {
        fail(sprintf(
            'Expected reviewed PHPUnit authority %d classes / %d exact IDs, found %d / %d.',
            EXPECTED_PHPUNIT_CLASSES,
            EXPECTED_PHPUNIT_IDS,
            count($discoveredCaseCounts),
            count($exactIds),
        ));
    }

    foreach (P6_LIVE_ADDED_TEST_IDS as $id) {
        if (!in_array($id, $exactIds, true)) {
            fail('Missing reviewed P6 test addition: ' . $id);
        }
    }
    foreach (P6_RENAMED_TEST_IDS as $oldId => $newId) {
        if (in_array($oldId, $exactIds, true) || !in_array($newId, $exactIds, true)) {
            fail(sprintf('Invalid reviewed P6 test ID replacement: %s -> %s.', $oldId, $newId));
        }
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
        foreach ($uniquePaths as $path) {
            if (!isset(EXPLICIT_PATH_DISPOSITIONS[$path])) {
                fail(sprintf('Target collision without disposition at %s: %s', $target, implode(', ', $uniquePaths)));
            }
        }
    }

    $expectedOrphanCandidates = 28;
    $orphanCandidates = array_filter($rows, static fn(array $row): bool => orphanCandidateReason($row['current_path']) !== null);
    if (count($orphanCandidates) !== $expectedOrphanCandidates) {
        fail(sprintf('Expected %d explicit orphan candidates, found %d.', $expectedOrphanCandidates, count($orphanCandidates)));
    }
}

/** @param list<array<string, string>> $rows */
function validateP4Topology(array $rows): void
{
    $p4Rows = array_values(array_filter(
        $rows,
        static fn(array $row): bool => $row['closure_package'] === 'P4',
    ));
    $classes = array_values(array_filter(
        $p4Rows,
        static fn(array $row): bool => $row['kind'] === 'phpunit-test-class',
    ));
    $fixtures = array_values(array_filter(
        $p4Rows,
        static fn(array $row): bool => $row['kind'] === 'fixture',
    ));
    $supports = array_values(array_filter(
        $p4Rows,
        static fn(array $row): bool => $row['kind'] === 'support',
    ));
    $testIds = array_sum(array_map(
        static fn(array $row): int => (int) $row['discovered_test_cases'],
        $classes,
    ));
    if (count($classes) !== 43 || $testIds !== 795 || count($fixtures) !== 52 || count($supports) !== 4) {
        fail(sprintf(
            'P4 test topology must contain exactly 43 PHPUnit classes / 778 retained IDs plus six Architecture contribution-merge IDs, two Architecture warning IDs, three Architecture topology IDs, a net one ArchitecturePolicy lifecycle ID, two CircularDependency reset IDs, and three governance IDs, 52 fixtures and four supports; found %d / %d, %d and %d.',
            count($classes),
            $testIds,
            count($fixtures),
            count($supports),
        ));
    }

    foreach ($p4Rows as $row) {
        if (str_contains($row['current_path'], 'InlineSuppressionLayerViolationIntegrationTest')
            || str_contains($row['current_path'], '/Fixtures/IgnoreSample/')
        ) {
            fail('P4 test topology must not enroll P6-owned InlineSuppression or IgnoreSample artifacts: ' . $row['current_path']);
        }
    }
    $architectureClasses = array_values(array_filter(
        $classes,
        static fn(array $row): bool => $row['subject_owner'] === 'Analysis/Policy/Architecture',
    ));
    $circularClasses = array_values(array_filter(
        $classes,
        static fn(array $row): bool => $row['subject_owner'] === 'Analysis/Evidence/CircularDependency',
    ));
    $consoleClasses = array_values(array_filter(
        $classes,
        static fn(array $row): bool => $row['subject_owner'] === 'Infrastructure/Console',
    ));
    foreach ($architectureClasses as $row) {
        if (!str_starts_with($row['target_path'], 'tests/Analysis/Policy/Architecture/')) {
            fail('P4 Architecture test has an unexpected target path: ' . $row['target_path']);
        }
    }
    foreach ($circularClasses as $row) {
        if (!str_starts_with($row['target_path'], 'tests/Analysis/Evidence/CircularDependency/')) {
            fail('P4 CircularDependency test has an unexpected target path: ' . $row['target_path']);
        }
    }
    if (count($consoleClasses) !== 1
        || $consoleClasses[0]['target_path'] !== 'tests/Infrastructure/Console/Functional/LayerAssignmentCommandTest.php'
    ) {
        fail('P4 must retain exactly LayerAssignmentCommandTest under the Infrastructure Console adapter target');
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
            $directories[$directory]['packages'][$row['closure_package']] = true;
            $directories[$directory]['orphan'] = ($directories[$directory]['orphan'] ?? true)
                && orphanCandidateReason($row['current_path']) !== null;
            $directory = dirname($directory);
        }
    }
    ksort($directories, SORT_STRING);

    $result = [];
    foreach ($directories as $directory => $data) {
        $owners = array_keys($data['owners']);
        $packages = array_keys($data['packages']);
        sort($owners, SORT_STRING);
        sort($packages, SORT_STRING);
        $result[] = [
            'current_directory' => $directory,
            'subject_owners' => implode(',', $owners),
            'closure_packages' => implode(',', $packages),
            'disposition' => $data['orphan']
                ? 'P8: retain until consumer proof; delete only if confirmed orphan.'
                : (count($owners) > 1 ? 'Split by file owner and closure package.' : 'Move atomically with the owning subject.'),
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
    $packageCounts = array_count_values(array_column($rows, 'closure_package'));
    ksort($kindCounts, SORT_STRING);
    ksort($suiteFileCounts, SORT_STRING);
    ksort($packageCounts, SORT_STRING);

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
        'closure_package_counts' => $packageCounts,
    ];
}

function fail(string $message): never
{
    fwrite(STDERR, $message . "\n");
    exit(1);
}
