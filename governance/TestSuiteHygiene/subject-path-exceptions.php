<?php

declare(strict_types=1);

/*
 * The test paths that do not name the subject they cover, measured by
 * `php governance/TestSuiteHygiene/derive-subject-path-exceptions.php`.
 *
 * Do not add a row by hand: a row nobody measured is a claim about the tree that
 * nothing checks, and TestPathsNameTheirSubjectTest refuses a row that no longer
 * describes an exception exactly as loudly as it refuses one that is missing.
 * The way out is to empty a list, one refiled or re-covered test at a time.
 *
 * Each `ceiling` is how many rows its list may carry. Deriving only ever lowers
 * one, so a fresh exception cannot be absorbed by re-running the command; raising
 * one is a hand-edited number, which is what makes it a decision rather than a
 * side effect.
 */

return [
    'declares_no_coverage' => [
        'ceiling' => 77,
        'rows' => [
            'tests/Analysis/Configuration/Integration/PresetIntegrationTest.php' => 'declares no coverage attribute',
            'tests/Analysis/Evidence/Cohesion/Unit/LcomCollectionConfigurationTest.php' => 'declares no coverage attribute',
            'tests/Analysis/Evidence/Complexity/Integration/WmcIntegrationTest.php' => 'declares no coverage attribute',
            'tests/Analysis/Evidence/ComputedMetrics/Integration/HealthCoverageAgreesWithCountsTest.php' => 'declares no coverage attribute',
            'tests/Analysis/Evidence/ComputedMetrics/Unit/ComputedMetricContributionReaderTest.php' => 'declares no coverage attribute',
            'tests/Analysis/Evidence/Coupling/Unit/CouplingConfigurationRefusalTest.php' => 'declares no coverage attribute',
            'tests/Analysis/Evidence/Duplication/Functional/DuplicationMemoryLimitProcessTest.php' => 'declares no coverage attribute',
            'tests/Analysis/Evidence/Measurement/Integration/Aggregation/GlobalNamespaceProjectAggregateTest.php' => 'declares no coverage attribute',
            'tests/Analysis/Evidence/Measurement/Integration/Aggregation/GoldenFileAggregationTest.php' => 'declares no coverage attribute',
            'tests/Analysis/Evidence/Measurement/Integration/Aggregation/MetricInvariantTest.php' => 'declares no coverage attribute',
            'tests/Analysis/Evidence/Measurement/Integration/Identity/ClassProducerOrdinalTest.php' => 'declares no coverage attribute',
            'tests/Analysis/Evidence/Measurement/Integration/Identity/DeclarationIdentityTest.php' => 'declares no coverage attribute',
            'tests/Analysis/Evidence/Measurement/Unit/AnonymousClassContextRegressionTest.php' => 'declares no coverage attribute',
            'tests/Analysis/Finding/Integration/ChannelCoverageTest.php' => 'declares #[CoversNothing]',
            'tests/Analysis/Finding/Integration/HierarchicalLevelActivityTest.php' => 'declares no coverage attribute',
            'tests/Analysis/Finding/Unit/FindingConfigurationResolverTest.php' => 'declares no coverage attribute',
            'tests/Analysis/Finding/Unit/RuleConfiguration/LevelActivityReadsBothDisablingSpellingsTest.php' => 'declares no coverage attribute',
            'tests/Analysis/Finding/Unit/RuleConfigurationIsolationTest.php' => 'declares no coverage attribute',
            'tests/Analysis/Finding/Unit/ThresholdParserTest.php' => 'declares no coverage attribute',
            'tests/Analysis/Policy/Architecture/Integration/CaptureBindingIntegrationTest.php' => 'declares no coverage attribute',
            'tests/Analysis/Policy/Architecture/Integration/FailClosedModularTopologyIntegrationTest.php' => 'declares no coverage attribute',
            'tests/Analysis/Policy/Architecture/Integration/LayerCriteriaIntegrationTest.php' => 'declares no coverage attribute',
            'tests/Analysis/Policy/Architecture/Integration/LayerExcludeIntegrationTest.php' => 'declares no coverage attribute',
            'tests/Analysis/Policy/Architecture/Integration/LayerTemplateExpansionIntegrationTest.php' => 'declares no coverage attribute',
            'tests/Analysis/Policy/Architecture/Integration/LayerViolationIntegrationTest.php' => 'declares no coverage attribute',
            'tests/Analysis/Policy/Architecture/Integration/Phase1ConfigCompatibilityTest.php' => 'declares no coverage attribute',
            'tests/Analysis/Policy/Architecture/Integration/RelationsFilterIntegrationTest.php' => 'declares no coverage attribute',
            'tests/Analysis/Policy/Architecture/Unit/LayersValidatorEmptyMembershipRefusalTest.php' => 'declares no coverage attribute',
            'tests/Analysis/Policy/Baseline/Functional/BaselineIncompleteAnalysisTest.php' => 'declares no coverage attribute',
            'tests/Analysis/Policy/Baseline/Functional/BaselineLifecycleTest.php' => 'declares no coverage attribute',
            'tests/Analysis/Policy/Baseline/Functional/BaselineMigrateCommandTest.php' => 'declares no coverage attribute',
            'tests/Analysis/Policy/Baseline/Integration/BaselineWorkflowTest.php' => 'declares no coverage attribute',
            'tests/Analysis/Policy/Baseline/Integration/CaptureFromMeasuredSetTest.php' => 'declares no coverage attribute',
            'tests/Analysis/Policy/Baseline/Integration/CboAggregateBreachTest.php' => 'declares no coverage attribute',
            'tests/Analysis/Policy/Baseline/Integration/NpathSaturationCeilingTest.php' => 'declares no coverage attribute',
            'tests/Analysis/Policy/Inline/Integration/InlineSuppressionLayerViolationIntegrationTest.php' => 'declares no coverage attribute',
            'tests/Analysis/Policy/Inline/Integration/NamespaceExclusionOccurrenceTest.php' => 'declares no coverage attribute',
            'tests/Analysis/Policy/Inline/Integration/PropertyHookControlPrecedenceTest.php' => 'declares no coverage attribute',
            'tests/Analysis/Run/Integration/Pipeline/AnalysisPipelineIntegrationTest.php' => 'declares no coverage attribute',
            'tests/Analysis/Run/Integration/Pipeline/MultiNamespaceAnalysisTest.php' => 'declares no coverage attribute',
            'tests/Analysis/Run/Unit/Configuration/EmptyAnalysisPathRefusalTest.php' => 'declares no coverage attribute',
            'tests/Analysis/Run/Unit/Configuration/RunConfigurationResolverTest.php' => 'declares no coverage attribute',
            'tests/Core/Unit/VersionTest.php' => 'declares no coverage attribute',
            'tests/Infrastructure/Cache/Unit/CacheConfigurationResolverTest.php' => 'declares no coverage attribute',
            'tests/Infrastructure/Cache/Unit/CacheConfigurationStoreTest.php' => 'declares no coverage attribute',
            'tests/Infrastructure/Cache/Unit/CacheDirectoryShapeRefusalTest.php' => 'declares no coverage attribute',
            'tests/Infrastructure/Console/Functional/Command/ChannelExclusionKeySpellingTest.php' => 'declares no coverage attribute',
            'tests/Infrastructure/Console/Functional/Command/CheckCommandConfigErrorExitCodeTest.php' => 'declares no coverage attribute',
            'tests/Infrastructure/Console/Functional/Command/CheckCommandConfigurationErrorGateTest.php' => 'declares no coverage attribute',
            'tests/Infrastructure/Console/Functional/Command/CheckCommandInputValidationTest.php' => 'declares no coverage attribute',
            'tests/Infrastructure/Console/Functional/Command/CheckCommandProjectScopedGateTest.php' => 'declares no coverage attribute',
            'tests/Infrastructure/Console/Functional/ErrorStreamPseudoTerminalTest.php' => 'declares no coverage attribute',
            'tests/Infrastructure/Console/Functional/TranslatedRefusalVocabularyTest.php' => 'declares #[CoversNothing]',
            'tests/Infrastructure/Console/Integration/ConfigurationRefusalRoutingTest.php' => 'declares #[CoversNothing]',
            'tests/Infrastructure/Console/Integration/RuntimeConfigurationIsolationTest.php' => 'declares no coverage attribute',
            'tests/Infrastructure/Console/Unit/DirectiveAuditSummaryProjectionTest.php' => 'declares no coverage attribute',
            'tests/Infrastructure/Console/Unit/EmptyCliValueReachesItsOwnerTest.php' => 'declares no coverage attribute',
            'tests/Infrastructure/Console/Unit/Progress/ConsoleProgressBarTest.php' => 'declares no coverage attribute',
            'tests/Infrastructure/Logging/Unit/DelegatingLoggerTest.php' => 'declares no coverage attribute',
            'tests/Infrastructure/Logging/Unit/LoggerHolderTest.php' => 'declares no coverage attribute',
            'tests/Infrastructure/Parallel/Unit/ParallelConfigurationResolverTest.php' => 'declares no coverage attribute',
            'tests/Infrastructure/Parallel/Unit/ParallelConfigurationStoreTest.php' => 'declares no coverage attribute',
            'tests/Infrastructure/Profiler/Unit/Export/ChromeTracingExporterTest.php' => 'declares no coverage attribute',
            'tests/Infrastructure/Profiler/Unit/Export/JsonExporterTest.php' => 'declares no coverage attribute',
            'tests/Infrastructure/Profiler/Unit/ProfileSessionTest.php' => 'declares no coverage attribute',
            'tests/Infrastructure/Profiler/Unit/ProfilerTest.php' => 'declares no coverage attribute',
            'tests/Infrastructure/Profiler/Unit/SpanTest.php' => 'declares no coverage attribute',
            'tests/Reporting/GraphProjection/Unit/DependencyGraphProjectorTest.php' => 'declares no coverage attribute',
            'tests/Reporting/GraphProjection/Unit/DotExporterTest.php' => 'declares no coverage attribute',
            'tests/Reporting/GraphProjection/Unit/JsonGraphExporterTest.php' => 'declares no coverage attribute',
            'tests/Reporting/Integration/CoverageProjectionFormatterTest.php' => 'declares no coverage attribute',
            'tests/Reporting/Unit/FindingExclusionShapeRefusalTest.php' => 'declares no coverage attribute',
            'tests/Reporting/Unit/FindingProjection/Configuration/ConfiguredFindingExclusionsResolverTest.php' => 'declares no coverage attribute',
            'tests/Reporting/Unit/Formatter/ArchitectureViolationSmokeTest.php' => 'declares #[CoversNothing]',
            'tests/Reporting/Unit/Formatter/JsonShapePreservationTest.php' => 'declares #[CoversNothing]',
            'tests/Reporting/Unit/OutputFormatRefusesUnexecutableValuesTest.php' => 'declares no coverage attribute',
            'tests/Reporting/Unit/OutputFormatResolverTest.php' => 'declares no coverage attribute',
        ],
    ],
    'covers_another_owner' => [
        'ceiling' => 17,
        'rows' => [
            'tests/Analysis/Evidence/ComputedMetrics/Unit/HealthFormulaExcluderTest.php' => 'is filed under Analysis.Evidence.ComputedMetrics and covers only Analysis.Evidence.ComputedMetrics.Health',
            'tests/Analysis/Evidence/ComputedMetrics/Unit/WeightedHealthFormulaTest.php' => 'is filed under Analysis.Evidence.ComputedMetrics and covers only Analysis.Evidence.ComputedMetrics.Health',
            'tests/Analysis/Policy/Baseline/Functional/BaselineCleanupCommandTest.php' => 'is filed under Analysis.Policy.Baseline and covers only Infrastructure.Console',
            'tests/Analysis/Policy/Baseline/Functional/BaselineCommandFailureReportingTest.php' => 'is filed under Analysis.Policy.Baseline and covers only Infrastructure.Console',
            'tests/Analysis/Policy/Baseline/Functional/BaselineCommandOptionSurfaceTest.php' => 'is filed under Analysis.Policy.Baseline and covers only Infrastructure.Console',
            'tests/Analysis/Policy/Baseline/Functional/BaselineExplainCommandTest.php' => 'is filed under Analysis.Policy.Baseline and covers only Infrastructure.Console',
            'tests/Analysis/Policy/Baseline/Functional/BaselineGenerateCommandTest.php' => 'is filed under Analysis.Policy.Baseline and covers only Infrastructure.Console',
            'tests/Analysis/Policy/Baseline/Functional/BaselineMeasuredSetSeamTest.php' => 'is filed under Analysis.Policy.Baseline and covers only Infrastructure.Console',
            'tests/Analysis/Policy/Baseline/Functional/BaselineRenameChannelsCommandTest.php' => 'is filed under Analysis.Policy.Baseline and covers only Infrastructure.Console',
            'tests/Analysis/Policy/Baseline/Functional/BaselineRunBeforeLoadTest.php' => 'is filed under Analysis.Policy.Baseline and covers only Infrastructure.Console',
            'tests/Analysis/Policy/Baseline/Functional/BaselineUpdateCommandTest.php' => 'is filed under Analysis.Policy.Baseline and covers only Infrastructure.Console',
            'tests/Analysis/Policy/Inline/Integration/ThresholdOverrideIntegrationTest.php' => 'is filed under Analysis.Policy.Inline and covers only Analysis.Evidence.CodeSmell + Analysis.Evidence.Cohesion + Analysis.Evidence.Complexity + Analysis.Evidence.Coupling + Analysis.Evidence.Design + Analysis.Evidence.Duplication + Analysis.Evidence.Maintainability + Analysis.Evidence.Size',
            'tests/Analysis/Policy/Inline/Unit/IndependentAxisValidatorTest.php' => 'is filed under Analysis.Policy.Inline and covers only Analysis.Finding',
            'tests/Analysis/Policy/Inline/Unit/InvertedOverrideValidatorTest.php' => 'is filed under Analysis.Policy.Inline and covers only Analysis.Finding',
            'tests/Analysis/Policy/Inline/Unit/StandardOverrideValidatorTest.php' => 'is filed under Analysis.Policy.Inline and covers only Analysis.Finding',
            'tests/Analysis/Policy/Inline/Unit/WarningOnlyValidatorTest.php' => 'is filed under Analysis.Policy.Inline and covers only Analysis.Finding',
            'tests/Infrastructure/Console/Functional/RuleOptionKeyDoorSymmetryTest.php' => 'is filed under Infrastructure.Console and covers only Analysis.Finding',
        ],
    ],
    'remainder_is_not_a_prefix' => [
        'ceiling' => 4,
        'rows' => [
            'tests/Analysis/Run/Unit/Collection/FileProcessingResultTest.php' => 'Collection against Contract/Collection',
            'tests/Analysis/Run/Unit/Configuration/RunConfigurationScopeTest.php' => 'Configuration against Contract/Configuration',
            'tests/Analysis/Run/Unit/Pipeline/AnalysisCoverageTest.php' => 'Pipeline against Contract/Pipeline',
            'tests/Analysis/Run/Unit/Pipeline/AnalysisResultTest.php' => 'Pipeline against Contract/Pipeline',
        ],
    ],
];
