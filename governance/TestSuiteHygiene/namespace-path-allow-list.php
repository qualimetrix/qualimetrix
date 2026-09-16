<?php

declare(strict_types=1);

/*
 * The namespace-versus-path violations this tree is known to carry, measured by
 * `php governance/TestSuiteHygiene/derive-namespace-path-allow-list.php`.
 *
 * Do not add a row by hand: a row nobody measured is a claim about the tree that
 * nothing checks, and TestNamespacesFollowTheirPathTest refuses a row that no
 * longer describes a violation exactly as loudly as it refuses one that is missing.
 * The way out is to empty the list, one renamed namespace at a time.
 *
 * `ceiling` is how many rows this list may carry. Deriving only ever lowers it, so
 * a fresh violation cannot be absorbed by re-running the command; raising it is a
 * hand-edited number, which is what makes it a decision rather than a side effect.
 */

return [
    'ceiling' => 60,
    'rows' => [
        'tests/Analysis/Evidence/Measurement/Unit/AnonymousClassContextRegressionTest.php' => 'Qualimetrix\\Tests\\Unit\\Metrics',
        'tests/Analysis/Evidence/Measurement/Unit/CallableWithMetricsTest.php' => 'Qualimetrix\\Tests\\Unit\\Core\\Metric',
        'tests/Analysis/Evidence/Measurement/Unit/DataBagTest.php' => 'Qualimetrix\\Tests\\Unit\\Core\\Metric',
        'tests/Analysis/Evidence/Measurement/Unit/MetricBagTest.php' => 'Qualimetrix\\Tests\\Unit\\Core\\Metric',
        'tests/Analysis/Evidence/Measurement/Unit/MetricDefinitionTest.php' => 'Qualimetrix\\Tests\\Unit\\Core\\Metric',
        'tests/Analysis/Evidence/Measurement/Unit/VisitorMethodContextTest.php' => 'Qualimetrix\\Tests\\Unit\\Metrics',
        'tests/Analysis/Finding/Unit/RuleExclusionStatsTest.php' => 'Qualimetrix\\Tests\\Unit\\Analysis\\RuleExecution',
        'tests/Analysis/Policy/Architecture/Unit/AllowAliasExpanderTest.php' => 'Qualimetrix\\Tests\\Analysis\\Policy\\Architecture\\Unit\\Configuration\\Allow',
        'tests/Analysis/Policy/Architecture/Unit/AllowValidatorTest.php' => 'Qualimetrix\\Tests\\Analysis\\Policy\\Architecture\\Unit\\Configuration\\Validation',
        'tests/Analysis/Policy/Architecture/Unit/ArchitectureConfigurationFactoryTest.php' => 'Qualimetrix\\Tests\\Analysis\\Policy\\Architecture\\Unit\\Configuration',
        'tests/Analysis/Policy/Architecture/Unit/ArchitectureConfigurationTest.php' => 'Qualimetrix\\Tests\\Analysis\\Policy\\Architecture\\Unit\\Domain',
        'tests/Analysis/Policy/Architecture/Unit/ArchitectureProcessorTest.php' => 'Qualimetrix\\Tests\\Analysis\\Policy\\Architecture\\Unit\\Processing',
        'tests/Analysis/Policy/Architecture/Unit/CapturePatternTest.php' => 'Qualimetrix\\Tests\\Analysis\\Policy\\Architecture\\Unit\\Domain\\Layer',
        'tests/Analysis/Policy/Architecture/Unit/ClassContextFactoryTest.php' => 'Qualimetrix\\Tests\\Analysis\\Policy\\Architecture\\Unit\\Domain\\Layer',
        'tests/Analysis/Policy/Architecture/Unit/ClassSetTest.php' => 'Qualimetrix\\Tests\\Analysis\\Policy\\Architecture\\Unit\\Domain\\Layer',
        'tests/Analysis/Policy/Architecture/Unit/CoverageDiagnosticsTest.php' => 'Qualimetrix\\Tests\\Analysis\\Policy\\Architecture\\Unit\\Rules',
        'tests/Analysis/Policy/Architecture/Unit/CoverageModeTest.php' => 'Qualimetrix\\Tests\\Analysis\\Policy\\Architecture\\Unit\\Domain',
        'tests/Analysis/Policy/Architecture/Unit/CoverageValidatorTest.php' => 'Qualimetrix\\Tests\\Analysis\\Policy\\Architecture\\Unit\\Configuration\\Validation',
        'tests/Analysis/Policy/Architecture/Unit/ExactAllowCycleValidatorTest.php' => 'Qualimetrix\\Tests\\Analysis\\Policy\\Architecture\\Unit\\Configuration\\Validation',
        'tests/Analysis/Policy/Architecture/Unit/ExcludeSpecTest.php' => 'Qualimetrix\\Tests\\Analysis\\Policy\\Architecture\\Unit\\Domain\\Layer',
        'tests/Analysis/Policy/Architecture/Unit/LayerDefinitionTest.php' => 'Qualimetrix\\Tests\\Analysis\\Policy\\Architecture\\Unit\\Domain\\Layer',
        'tests/Analysis/Policy/Architecture/Unit/LayerExpansionStageTest.php' => 'Qualimetrix\\Tests\\Analysis\\Policy\\Architecture\\Unit\\Processing',
        'tests/Analysis/Policy/Architecture/Unit/LayerInstantiatorTest.php' => 'Qualimetrix\\Tests\\Analysis\\Policy\\Architecture\\Unit\\Processing',
        'tests/Analysis/Policy/Architecture/Unit/LayerPolicyTest.php' => 'Qualimetrix\\Tests\\Analysis\\Policy\\Architecture\\Unit\\Domain\\Layer',
        'tests/Analysis/Policy/Architecture/Unit/LayerRegistryTest.php' => 'Qualimetrix\\Tests\\Analysis\\Policy\\Architecture\\Unit\\Domain\\Layer',
        'tests/Analysis/Policy/Architecture/Unit/LayerSelectorTest.php' => 'Qualimetrix\\Tests\\Analysis\\Policy\\Architecture\\Unit\\Domain\\Allow',
        'tests/Analysis/Policy/Architecture/Unit/LayerViolationOptionsTest.php' => 'Qualimetrix\\Tests\\Analysis\\Policy\\Architecture\\Unit\\Rules',
        'tests/Analysis/Policy/Architecture/Unit/LayerViolationRuleTest.php' => 'Qualimetrix\\Tests\\Analysis\\Policy\\Architecture\\Unit\\Rules',
        'tests/Analysis/Policy/Architecture/Unit/LayersValidatorMembershipRefusalGuardTest.php' => 'Qualimetrix\\Tests\\Analysis\\Policy\\Architecture\\Unit\\Configuration\\Validation',
        'tests/Analysis/Policy/Architecture/Unit/LayersValidatorTest.php' => 'Qualimetrix\\Tests\\Analysis\\Policy\\Architecture\\Unit\\Configuration\\Validation',
        'tests/Analysis/Policy/Architecture/Unit/TemplateLayerDefinitionTest.php' => 'Qualimetrix\\Tests\\Analysis\\Policy\\Architecture\\Unit\\Domain\\Layer',
        'tests/Analysis/Policy/Architecture/Unit/TupleExtractorTest.php' => 'Qualimetrix\\Tests\\Analysis\\Policy\\Architecture\\Unit\\Processing',
        'tests/Analysis/Policy/Architecture/Unit/WildcardSelfAllowDetectorTest.php' => 'Qualimetrix\\Tests\\Analysis\\Policy\\Architecture\\Unit\\Configuration\\Validation',
        'tests/Core/Path/Unit/AbsolutePathSerializationTest.php' => 'Qualimetrix\\Tests\\Unit\\Core\\Path',
        'tests/Core/Path/Unit/AbsolutePathTest.php' => 'Qualimetrix\\Tests\\Unit\\Core\\Path',
        'tests/Core/Path/Unit/PathFactoryTest.php' => 'Qualimetrix\\Tests\\Unit\\Core\\Path',
        'tests/Core/Path/Unit/PathFastPathCostTest.php' => 'Qualimetrix\\Tests\\Unit\\Core\\Path',
        'tests/Core/Path/Unit/PathLexicalConstructionTest.php' => 'Qualimetrix\\Tests\\Unit\\Core\\Path',
        'tests/Core/Path/Unit/RelativePathSerializationTest.php' => 'Qualimetrix\\Tests\\Unit\\Core\\Path',
        'tests/Core/Path/Unit/RelativePathTest.php' => 'Qualimetrix\\Tests\\Unit\\Core\\Path',
        'tests/Core/Symbol/Unit/CallableKindTest.php' => 'Qualimetrix\\Tests\\Unit\\Core\\Symbol',
        'tests/Core/Symbol/Unit/DeclarationPathTest.php' => 'Qualimetrix\\Tests\\Unit\\Core\\Symbol',
        'tests/Core/Symbol/Unit/FileDeclarationIndexTest.php' => 'Qualimetrix\\Tests\\Unit\\Core\\Symbol',
        'tests/Core/Symbol/Unit/LogicalClassPathTest.php' => 'Qualimetrix\\Tests\\Unit\\Core\\Symbol',
        'tests/Core/Symbol/Unit/MetricSubjectCodecTest.php' => 'Qualimetrix\\Tests\\Unit\\Core\\Symbol',
        'tests/Core/Symbol/Unit/MetricSubjectTest.php' => 'Qualimetrix\\Tests\\Unit\\Core\\Symbol',
        'tests/Core/Symbol/Unit/PhpBuiltinClassRegistryTest.php' => 'Qualimetrix\\Tests\\Unit\\Core\\Symbol',
        'tests/Core/Symbol/Unit/SymbolInfoTest.php' => 'Qualimetrix\\Tests\\Unit\\Core\\Symbol',
        'tests/Core/Symbol/Unit/SymbolPathCanonicalKeyStabilityTest.php' => 'Qualimetrix\\Tests\\Unit\\Core\\Symbol',
        'tests/Core/Symbol/Unit/SymbolPathTest.php' => 'Qualimetrix\\Tests\\Unit\\Core\\Symbol',
        'tests/Infrastructure/Console/Unit/Progress/ConsoleProgressBarTest.php' => 'Qualimetrix\\Tests\\Unit\\Infrastructure\\Console\\Progress',
        'tests/Infrastructure/Parallel/Unit/Strategy/WorkerCountDetectorTest.php' => 'Qualimetrix\\Tests\\Unit\\Infrastructure\\Parallel\\Strategy',
        'tests/Infrastructure/Profiler/Unit/Export/ChromeTracingExporterTest.php' => 'Qualimetrix\\Tests\\Unit\\Infrastructure\\Profiler\\Export',
        'tests/Infrastructure/Profiler/Unit/Export/JsonExporterTest.php' => 'Qualimetrix\\Tests\\Unit\\Infrastructure\\Profiler\\Export',
        'tests/Infrastructure/Profiler/Unit/ProfilerTest.php' => 'Qualimetrix\\Tests\\Unit\\Infrastructure\\Profiler',
        'tests/Infrastructure/Profiler/Unit/SpanTest.php' => 'Qualimetrix\\Tests\\Unit\\Infrastructure\\Profiler',
        'tests/TestSupport/ArchitectureStaticAnalysis/Unit/BannedStringPathPromotedPropertyRuleTest.php' => 'Qualimetrix\\Tests\\Unit\\PhpStan\\Rules',
        'tests/TestSupport/ArchitectureStaticAnalysis/Unit/BannedStringPathPropertyRuleTest.php' => 'Qualimetrix\\Tests\\Unit\\PhpStan\\Rules',
    ],
];
