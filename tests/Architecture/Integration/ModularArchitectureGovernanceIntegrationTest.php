<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Architecture\Integration;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ModularArchitectureGovernanceIntegrationTest extends TestCase
{
    #[Test]
    public function itChecksEveryGeneratedProjectionWithoutWriting(): void
    {
        $generatedPaths = glob($this->root() . '/docs/internal/generated/modular-architecture/*');
        self::assertIsArray($generatedPaths);
        $paths = array_merge(
            [$this->root() . '/qmx.yaml'],
            $generatedPaths,
        );
        $before = $this->hashes($paths);

        [$exitCode, $output] = $this->runCommand([
            \PHP_BINARY,
            $this->root() . '/scripts/generate-modular-architecture.php',
            '--check',
        ]);

        self::assertSame(0, $exitCode, $output);
        self::assertSame($before, $this->hashes($paths), '--check must not modify qmx or generated evidence.');

        $discovery = file(
            $this->root() . '/docs/internal/generated/modular-architecture/test-phpunit-discovery.txt',
            \FILE_IGNORE_NEW_LINES | \FILE_SKIP_EMPTY_LINES,
        );
        self::assertIsArray($discovery);
        $testIds = array_values(array_filter(
            $discovery,
            static fn(string $line): bool => str_starts_with($line, ' - '),
        ));
        $sortedTestIds = $testIds;
        sort($sortedTestIds, \SORT_STRING);
        self::assertCount(7224, $testIds);
        self::assertSame($sortedTestIds, $testIds, 'Generated PHPUnit discovery IDs must be canonical across environments.');

        $path = $this->root() . '/docs/internal/generated/modular-architecture/test-phpunit-discovery.txt';
        $lines = file($path, \FILE_IGNORE_NEW_LINES);
        self::assertIsArray($lines);
        self::assertCount(7227, $lines);

        $malformed = $lines;
        $malformed[3] = ' - not-an-exact-test-id';
        [$exitCode, $output] = $this->runWithDiscoveryProbe(implode("\n", $malformed) . "\n");
        self::assertNotSame(0, $exitCode);
        self::assertStringContainsString('Unexpected PHPUnit discovery output line', $output);

        $interiorBlank = $lines;
        $interiorBlank[4] = '';
        [$exitCode, $output] = $this->runWithDiscoveryProbe(implode("\n", $interiorBlank) . "\n");
        self::assertNotSame(0, $exitCode);
        self::assertStringContainsString('Unexpected PHPUnit discovery output line', $output);

        [$exitCode, $output] = $this->runWithDiscoveryProbe(implode("\n", $lines) . "\n\n");
        self::assertNotSame(0, $exitCode);
        self::assertStringContainsString('Unexpected PHPUnit discovery output line', $output);

        $duplicate = $lines;
        $duplicate[] = $duplicate[3];
        [$exitCode, $output] = $this->runWithDiscoveryProbe(implode("\n", $duplicate) . "\n");
        self::assertNotSame(0, $exitCode);
        self::assertStringContainsString('Duplicate PHPUnit exact test ID', $output);

        $substituted = $lines;
        $substituted[3] = ' - Qualimetrix\\Tests\\Synthetic\\ReplacementTest::itLooksLikeARealTest';
        [$exitCode, $output] = $this->runWithDiscoveryProbe(implode("\n", $substituted) . "\n");
        self::assertNotSame(0, $exitCode);
        self::assertStringContainsString('does not match the live exact test IDs', $output);
    }

    #[Test]
    public function itPinsTheReviewedSnapshotAsComputedEvidence(): void
    {
        $lines = file(
            $this->root() . '/docs/internal/generated/modular-architecture/manifest-enforcement-summary.tsv',
            \FILE_IGNORE_NEW_LINES | \FILE_SKIP_EMPTY_LINES,
        );
        self::assertIsArray($lines);
        $rows = array_map(
            static fn(string $line): array => explode("\t", $line),
            $lines,
        );
        $summary = array_column(\array_slice($rows, 1), 1, 0);
        $manifest = $this->manifest();
        $declarations = $manifest['declarations'];
        self::assertIsArray($declarations);
        $owners = array_values(array_unique(array_column($declarations, 'owner')));
        $consumerEntries = array_sum(array_map(
            static fn(array $declaration): int => \count($declaration['consumers']),
            $declarations,
        ));
        $temporaryConsumerEntries = array_sum(array_map(
            static fn(array $declaration): int => \count(array_filter(
                $declaration['consumers'],
                static fn(array $consumer): bool => $consumer['source_fqcn'] !== null,
            )),
            $declarations,
        ));
        $coarseGrantEdges = [];
        foreach ($manifest['temporary_internal_grants'] as $grant) {
            $sourceOwner = $declarations[$grant['source_fqcn']]['owner'];
            $targetOwner = $declarations[$grant['target_fqcn']]['owner'];
            $coarseGrantEdges[$sourceOwner . "\0" . $targetOwner] = true;
        }
        [$qmxLayerCount, $qmxAllowEdgeCount] = $this->generatedQmxCounts();

        self::assertCount(11, $manifest['enforcement_seams']);
        self::assertCount(67, $manifest['temporary_internal_grants']);
        self::assertCount(12, $coarseGrantEdges);
        self::assertSame(48, $qmxLayerCount);
        self::assertSame(266, $qmxAllowEdgeCount);

        self::assertSame('717', $summary['declarations']);
        self::assertSame('715', $summary['files']);
        self::assertSame('775', $summary['contract_consumer_entries']);
        self::assertSame((string) \count(array_unique(array_column($declarations, 'path'))), $summary['files']);
        self::assertSame((string) \count($owners), $summary['semantic_owners']);
        self::assertSame((string) \count($owners), $summary['semantic_owner_layers']);
        self::assertSame((string) $consumerEntries, $summary['contract_consumer_entries']);
        self::assertSame((string) $temporaryConsumerEntries, $summary['temporary_contract_consumer_entries']);
        self::assertMatchesRegularExpression('/^[1-9][0-9]*$/', $summary['exact_dependency_edges']);
        self::assertMatchesRegularExpression('/^[1-9][0-9]*$/', $summary['cross_owner_imports']);
        self::assertSame((string) \count($manifest['enforcement_seams']), $summary['singleton_seams']);
        self::assertSame((string) \count($manifest['temporary_internal_grants']), $summary['exact_internal_grants']);
        self::assertSame((string) \count($coarseGrantEdges), $summary['coarse_internal_grant_edges']);
        self::assertSame((string) $qmxLayerCount, $summary['internal_enforcement_layers']);
        self::assertSame((string) $qmxAllowEdgeCount, $summary['declared_allow_edges']);
    }

    #[Test]
    public function itEncodesThePostP1DuplicationBoundary(): void
    {
        $manifest = $this->manifest();
        $declarations = $manifest['declarations'];
        self::assertCount(717, $declarations);
        self::assertCount(715, array_unique(array_column($declarations, 'path')));
        self::assertCount(37, $manifest['owners']);
        self::assertSame(235, \count(array_filter(
            $declarations,
            static fn(array $declaration): bool => $declaration['visibility'] === 'contract',
        )));

        $prefix = 'Qualimetrix\\Analysis\\Evidence\\Duplication\\';
        $duplication = array_filter(
            $declarations,
            static fn(string $fqcn): bool => str_starts_with($fqcn, $prefix),
            \ARRAY_FILTER_USE_KEY,
        );
        self::assertCount(17, $duplication);
        self::assertArrayHasKey($prefix . 'DuplicationResultProvider', $duplication);
        self::assertArrayNotHasKey('Qualimetrix\\Analysis\\Duplication\\DuplicationDetectorInterface', $declarations);
        self::assertArrayNotHasKey('Qualimetrix\\Core\\Duplication\\DuplicateBlock', $declarations);
        self::assertArrayNotHasKey('Qualimetrix\\Rules\\Duplication\\CodeDuplicationRule', $declarations);

        self::assertArrayNotHasKey($prefix . 'Contract\\DuplicationInspectionInterface', $duplication);

        foreach ($duplication as $fqcn => $declaration) {
            self::assertSame('internal', $declaration['visibility'], $fqcn);
            self::assertSame([], $declaration['consumers'], $fqcn);
        }
        self::assertSame([], array_values(array_filter(
            $manifest['temporary_internal_grants'],
            static fn(array $grant): bool => $grant['owner'] === 'Analysis.Evidence.Duplication',
        )));
    }

    #[Test]
    public function itClassifiesLegacyAndTargetDuplicationTestsWithoutACatchAll(): void
    {
        $cases = [
            'tests/Unit/Analysis/Duplication/DuplicationDetectorTest.php',
            'tests/Unit/Core/Duplication/DuplicateBlockIdentityTest.php',
            'tests/Unit/Rules/Duplication/CodeDuplicationRuleTest.php',
            'tests/Analysis/Evidence/Duplication/Unit/DuplicationDetectorTest.php',
        ];

        foreach ($cases as $path) {
            [$exitCode, $output] = $this->runCommand([
                \PHP_BINARY,
                $this->root() . '/scripts/generate-modular-architecture-test-inventory.php',
                '--classification-probe=' . $path,
            ]);

            self::assertSame(0, $exitCode, $output);
            self::assertSame(
                "Analysis/Evidence/Duplication\tP1\tUnit\ttests/Analysis/Evidence/Duplication/Unit/" . basename($path) . "\n",
                $output,
                $path,
            );
        }

        [$exitCode, $output] = $this->runCommand([
            \PHP_BINARY,
            $this->root() . '/scripts/generate-modular-architecture-test-inventory.php',
            '--classification-probe=tests/Analysis/Evidence/Duplication/Integration/UnexpectedTest.php',
        ]);
        self::assertNotSame(0, $exitCode);
        self::assertStringContainsString(
            'Unclassified test artifact: tests/Analysis/Evidence/Duplication/Integration/UnexpectedTest.php',
            $output,
        );
    }

    #[Test]
    public function itClassifiesTheP1DuplicationModuleReadmeExactly(): void
    {
        [$exitCode, $output] = $this->runCommand([
            \PHP_BINARY,
            $this->root() . '/scripts/generate-modular-architecture-production-inventory.php',
            '--documentation-probe=src/Analysis/Evidence/Duplication/README.md',
        ]);

        self::assertSame(0, $exitCode, $output);
        self::assertSame(
            "Analysis.Evidence.Duplication\tP1\tMove or update atomically with the named migration package.\n",
            $output,
        );

        [$exitCode, $output] = $this->runCommand([
            \PHP_BINARY,
            $this->root() . '/scripts/generate-modular-architecture-production-inventory.php',
            '--documentation-probe=src/Analysis/Evidence/Duplication/Unexpected/README.md',
        ]);
        self::assertNotSame(0, $exitCode);
        self::assertStringContainsString(
            'unclassified committable documentation path: src/Analysis/Evidence/Duplication/Unexpected/README.md',
            $output,
        );
    }

    #[Test]
    public function itEncodesThePostP2DependencyAndProjectionBoundaries(): void
    {
        $manifest = $this->manifest();
        $declarations = $manifest['declarations'];
        self::assertCount(717, $declarations);

        $dependencyPrefix = 'Qualimetrix\\Analysis\\Evidence\\DependencyModel\\';
        $dependencyDeclarations = array_filter(
            $declarations,
            static fn(string $fqcn): bool => str_starts_with($fqcn, $dependencyPrefix),
            \ARRAY_FILTER_USE_KEY,
        );
        self::assertCount(22, $dependencyDeclarations);
        self::assertSame(6, \count(array_filter(
            $dependencyDeclarations,
            static fn(array $declaration): bool => $declaration['visibility'] === 'contract',
        )));
        self::assertSame(16, \count(array_filter(
            $dependencyDeclarations,
            static fn(array $declaration): bool => $declaration['visibility'] === 'internal',
        )));
        self::assertSame(['Analysis.Finding'], array_column(
            $dependencyDeclarations[$dependencyPrefix . 'Contract\\DependencyLocationInterface']['consumers'],
            'owner',
        ));
        self::assertSame('internal', $dependencyDeclarations[$dependencyPrefix . 'Extraction\\DependencyVisitor']['visibility']);

        $projectionPrefix = 'Qualimetrix\\Reporting\\GraphProjection\\';
        $projectionDeclarations = array_filter(
            $declarations,
            static fn(string $fqcn): bool => str_starts_with($fqcn, $projectionPrefix),
            \ARRAY_FILTER_USE_KEY,
        );
        self::assertSame([
            $projectionPrefix . 'Contract\\DependencyGraphProjectionInterface',
            $projectionPrefix . 'Contract\\GraphProjectionRequest',
            $projectionPrefix . 'DependencyGraphProjector',
            $projectionPrefix . 'DotExporter',
            $projectionPrefix . 'DotExporterOptions',
            $projectionPrefix . 'JsonGraphExporter',
        ], array_keys($projectionDeclarations));
        self::assertSame(2, \count(array_filter(
            $projectionDeclarations,
            static fn(array $declaration): bool => $declaration['visibility'] === 'contract',
        )));
        self::assertSame(4, \count(array_filter(
            $projectionDeclarations,
            static fn(array $declaration): bool => $declaration['visibility'] === 'internal',
        )));

        $oldDeclarations = [
            'Qualimetrix\\Core\\Dependency\\Dependency',
            'Qualimetrix\\Core\\Dependency\\DependencyType',
            'Qualimetrix\\Core\\Dependency\\DependencyGraphInterface',
            'Qualimetrix\\Core\\Dependency\\EmptyDependencyGraph',
            'Qualimetrix\\Analysis\\Collection\\Dependency\\DependencyGraph',
            'Qualimetrix\\Analysis\\Collection\\Dependency\\DependencyGraphBuilder',
            'Qualimetrix\\Analysis\\Collection\\Dependency\\Export\\DotExporter',
            'Qualimetrix\\Analysis\\Collection\\Dependency\\Export\\DotExporterOptions',
            'Qualimetrix\\Analysis\\Collection\\Dependency\\Export\\JsonGraphExporter',
            'Qualimetrix\\Analysis\\Collection\\Dependency\\Export\\GraphExporterInterface',
        ];
        foreach ($oldDeclarations as $oldDeclaration) {
            self::assertArrayNotHasKey($oldDeclaration, $declarations);
        }

        foreach ($projectionDeclarations as $fqcn => $declaration) {
            $expectedConsumers = str_contains($fqcn, '\\Contract\\') ? ['Infrastructure.Console'] : [];
            self::assertSame($expectedConsumers, array_column($declaration['consumers'], 'owner'), $fqcn);
        }

        self::assertSame([], array_values(array_filter(
            $manifest['temporary_internal_grants'],
            static fn(array $grant): bool => $grant['closes_in'] === 'P2',
        )));
        self::assertCount(11, $manifest['enforcement_seams']);
        self::assertArrayNotHasKey(
            'Qualimetrix\\Core\\Metric\\GlobalContextCollectorInterface',
            $manifest['enforcement_seams'],
        );
        self::assertArrayNotHasKey(
            'Qualimetrix\\Core\\Violation\\Location',
            $manifest['enforcement_seams'],
        );
    }

    #[Test]
    public function itEncodesThePostP3RunConfigurationMeasurementAndDependencyModelBoundaries(): void
    {
        $manifest = $this->manifest();
        $declarations = $manifest['declarations'];

        self::assertCount(717, $declarations);
        self::assertCount(715, array_unique(array_column($declarations, 'path')));
        self::assertCount(37, $manifest['owners']);
        self::assertSame(
            'internal',
            $declarations['Qualimetrix\\Infrastructure\\Parallel\\FileProcessingTaskFactory']['visibility'],
        );
        self::assertSame(
            ['Infrastructure.Console', 'internal', 'P3'],
            array_values(array_intersect_key(
                $declarations['Qualimetrix\\Infrastructure\\Console\\LayerAssignmentResolver'],
                array_flip(['owner', 'visibility', 'closure_package']),
            )),
        );
        $parallelFactoryGrants = array_values(array_filter(
            $manifest['temporary_internal_grants'],
            static fn(array $grant): bool => $grant['target_fqcn'] === 'Qualimetrix\\Infrastructure\\Parallel\\FileProcessingTaskFactory',
        ));
        self::assertSame([
            'Qualimetrix\\Infrastructure\\DependencyInjection\\CompilerPass\\ParallelCollectorClassesCompilerPass',
            'Qualimetrix\\Infrastructure\\DependencyInjection\\Configurator\\CollectorConfigurator',
        ], array_column($parallelFactoryGrants, 'source_fqcn'));
        $payload = $declarations['Qualimetrix\\Analysis\\Run\\Contract\\Collection\\SuccessfulFileProcessing'];
        self::assertSame('contract', $payload['visibility']);
        self::assertSame([[
            'owner' => 'Infrastructure.Parallel',
            'relation' => 'contract_composition',
            'source_fqcn' => null,
            'closes_in' => null,
            'carrier_fqcn' => 'Qualimetrix\\Analysis\\Run\\Contract\\Collection\\FileProcessingResult',
            'boundary_fqcn' => 'Qualimetrix\\Infrastructure\\Parallel\\FileProcessingTask',
        ]], $payload['consumers']);
        self::assertContains('Analysis.Evidence.Measurement', array_column(
            $declarations['Qualimetrix\\Core\\Profiler\\ProfilerInterface']['consumers'],
            'owner',
        ));
        $carrierSource = file_get_contents($this->root() . '/src/Analysis/Run/Contract/Collection/FileProcessingResult.php');
        $boundarySource = file_get_contents($this->root() . '/src/Infrastructure/Parallel/FileProcessingTask.php');
        self::assertIsString($carrierSource);
        self::assertIsString($boundarySource);
        self::assertMatchesRegularExpression('/\?SuccessfulFileProcessing\s+\$success/', $carrierSource);
        self::assertStringContainsString('implements Task', $boundarySource);
        self::assertMatchesRegularExpression('/function run\([^)]*\): FileProcessingResult/s', $boundarySource);
        self::assertStringNotContainsString('SuccessfulFileProcessing', $boundarySource);
        $carrier = 'Qualimetrix\\Analysis\\Run\\Contract\\Collection\\FileProcessingResult';
        $boundary = 'Qualimetrix\\Infrastructure\\Parallel\\FileProcessingTask';
        $target = 'Qualimetrix\\Analysis\\Run\\Contract\\Collection\\SuccessfulFileProcessing';
        [$exitCode, $output] = $this->runWithCompositionProbe([]);
        self::assertSame(0, $exitCode, $output);
        [$exitCode, $output] = $this->runWithParameterOnlyCompositionSource();
        self::assertNotSame(0, $exitCode, 'ordinary native parameters must not prove stored containment');
        self::assertStringContainsString('carrier lacks exact native stored target containment', $output);
        $negativeProbes = [
            'PHPDoc or string-only containment' => ['rows' => [$carrier => ['contract_property_containments' => ['SuccessfulFileProcessing']]]],
            'internal carrier' => ['rows' => [$carrier => ['proposed_status' => 'internal']]],
            'wrong-owner carrier' => ['rows' => [$carrier => ['proposed_owner' => 'Analysis.Evidence.Measurement']]],
            'non-Task boundary' => ['rows' => [$boundary => ['implements' => []]]],
            'wrong native return' => ['rows' => [$boundary => ['native_method_returns' => ['run' => [$target]]]]],
            'missing native return' => ['rows' => [$boundary => ['native_method_returns' => ['run' => []]]]],
            'direct external target import' => ['add_pairs' => [[$boundary, $target]]],
        ];
        foreach ($negativeProbes as $label => $probe) {
            [$exitCode, $output] = $this->runWithCompositionProbe($probe);
            self::assertNotSame(0, $exitCode, $label);
            self::assertStringContainsString('contract_composition', $output, $label);
        }

        $invalidManifest = $manifest;
        $invalidManifest['declarations'][$target]['consumers'][0]['relation'] = 'unknown_relation';
        [$exitCode] = $this->runWithManifest($invalidManifest);
        self::assertNotSame(0, $exitCode, 'unknown relation');

        $invalidManifest = $manifest;
        $invalidManifest['declarations'][$target]['consumers'][0]['carrier_fqcn'] = null;
        [$exitCode] = $this->runWithManifest($invalidManifest);
        self::assertNotSame(0, $exitCode, 'malformed composition');

        $invalidManifest = $manifest;
        $invalidManifest['declarations'][$carrier]['consumers'] = [];
        [$exitCode, $output] = $this->runWithManifest($invalidManifest);
        self::assertNotSame(0, $exitCode, 'unused carrier consumer');
        self::assertStringContainsString('must publish at least one used consumer', $output);

        $invalidManifest = $manifest;
        $invalidManifest['declarations'][$target]['consumers'][] = [
            'owner' => 'Infrastructure.Parallel',
            'source_fqcn' => null,
            'closes_in' => null,
        ];
        [$exitCode] = $this->runWithManifest($invalidManifest);
        self::assertNotSame(0, $exitCode, 'duplicate direct and composed relation');
        self::assertSame('internal', $declarations['Qualimetrix\\Analysis\\Configuration\\Pipeline\\ConfigurationStageInterface']['visibility']);
        self::assertSame('contract', $declarations['Qualimetrix\\Analysis\\Run\\Contract\\Collection\\CollectionPhaseOutput']['visibility']);
        self::assertSame(['Infrastructure.Console'], array_column(
            $declarations['Qualimetrix\\Analysis\\Run\\Contract\\Collection\\CollectionPhaseOutput']['consumers'],
            'owner',
        ));
        self::assertSame(['Analysis.Run'], array_column(
            $declarations['Qualimetrix\\Analysis\\Evidence\\Measurement\\Contract\\CollectionOutput']['consumers'],
            'owner',
        ));
        self::assertSame([
            'Analysis.Evidence.Measurement',
            'Analysis.Run',
            'Infrastructure.DependencyInjection',
            'Infrastructure.Parallel',
        ], array_column(
            $declarations['Qualimetrix\\Analysis\\Evidence\\DependencyModel\\Contract\\DependencyTraversalParticipantInterface']['consumers'],
            'owner',
        ));
        self::assertArrayNotHasKey('Qualimetrix\\Core\\Namespace_\\NamespaceDetectorInterface', $declarations);
        self::assertArrayNotHasKey('Qualimetrix\\Analysis\\Collection\\FileProcessor', $declarations);
    }

    #[Test]
    public function itClassifiesEveryP3OwnedTestAndReadmeWithoutACatchAll(): void
    {
        $cases = [
            'tests/Analysis/Configuration/Unit/AnalysisConfigurationTest.php' => 'Analysis/Configuration',
            'tests/Analysis/Evidence/DependencyModel/Unit/Extraction/DependencyVisitorTest.php' => 'Analysis/Evidence/DependencyModel',
            'tests/Analysis/Evidence/Measurement/Unit/Aggregation/MeasurementAggregationServiceTest.php' => 'Analysis/Evidence/Measurement',
            'tests/Analysis/Run/Unit/Pipeline/AnalysisResultTest.php' => 'Analysis/Run',
        ];

        foreach ($cases as $path => $owner) {
            [$exitCode, $output] = $this->runCommand([
                \PHP_BINARY,
                $this->root() . '/scripts/generate-modular-architecture-test-inventory.php',
                '--classification-probe=' . $path,
            ]);
            self::assertSame(0, $exitCode, $output);
            self::assertStringStartsWith($owner . "\tP3\t", $output, $path);
        }

        $readmes = [
            'src/Analysis/Configuration/README.md' => 'Analysis.Configuration',
            'src/Analysis/Evidence/Measurement/README.md' => 'Analysis.Evidence.Measurement',
            'src/Analysis/Run/README.md' => 'Analysis.Run',
        ];
        foreach ($readmes as $path => $owner) {
            [$exitCode, $output] = $this->runCommand([
                \PHP_BINARY,
                $this->root() . '/scripts/generate-modular-architecture-production-inventory.php',
                '--documentation-probe=' . $path,
            ]);
            self::assertSame(0, $exitCode, $output);
            self::assertSame(
                "{$owner}\tP3\tMove or update atomically with the named migration package.\n",
                $output,
                $path,
            );
        }

        foreach (array_keys($readmes) as $path) {
            $unexpected = str_replace('/README.md', '/Unexpected/README.md', $path);
            [$exitCode, $output] = $this->runCommand([
                \PHP_BINARY,
                $this->root() . '/scripts/generate-modular-architecture-production-inventory.php',
                '--documentation-probe=' . $unexpected,
            ]);
            self::assertNotSame(0, $exitCode);
            self::assertStringContainsString(
                'unclassified committable documentation path: ' . $unexpected,
                $output,
            );
        }

        [$exitCode, $output] = $this->runCommand([
            \PHP_BINARY,
            $this->root() . '/scripts/generate-modular-architecture-test-inventory.php',
            '--classification-probe=tests/Unit/Analysis/Collection/SourceControl/SourceControlsTest.php',
        ]);
        self::assertSame(0, $exitCode, $output);
        self::assertSame(
            "Analysis/Policy/Inline\tP6\tUnit\ttests/Analysis/Policy/Inline/Unit/SourceControlsTest.php\n",
            $output,
        );

        [$exitCode, $output] = $this->runCommand([
            \PHP_BINARY,
            $this->root() . '/scripts/generate-modular-architecture-test-inventory.php',
            '--classification-probe=tests/Analysis/Run/UnexpectedTest.php',
        ]);
        self::assertNotSame(0, $exitCode);
        self::assertStringContainsString('Unclassified test artifact', $output);
    }

    #[Test]
    public function itRejectsExternalImportsOfDependencyModelExtractionInternals(): void
    {
        $manifest = $this->manifest();
        $target = 'Qualimetrix\\Analysis\\Evidence\\DependencyModel\\Extraction\\DependencyVisitor';
        self::assertSame('internal', $manifest['declarations'][$target]['visibility']);
        $manifest['declarations'][$target]['consumers'] = [[
            'owner' => 'Analysis.Run',
            'source_fqcn' => null,
            'closes_in' => null,
        ]];

        [$exitCode, $output] = $this->runWithManifest($manifest);

        self::assertNotSame(0, $exitCode);
        self::assertStringContainsString('internal declaration ' . $target . ' cannot publish consumers', $output);
    }

    #[Test]
    public function itClassifiesTheSevenP2OwnedTestsWithoutACatchAll(): void
    {
        $p2Cases = [
            'tests/Unit/Core/Dependency/DependencyTest.php' => 'tests/Analysis/Evidence/DependencyModel/Unit/DependencyTest.php',
            'tests/Unit/Core/Dependency/EmptyDependencyGraphTest.php' => 'tests/Analysis/Evidence/DependencyModel/Unit/EmptyDependencyGraphTest.php',
            'tests/Unit/Analysis/Collection/Dependency/DependencyGraphTest.php' => 'tests/Analysis/Evidence/DependencyModel/Unit/DependencyGraphTest.php',
            'tests/Unit/Analysis/Collection/Dependency/DependencyGraphBuilderTest.php' => 'tests/Analysis/Evidence/DependencyModel/Unit/DependencyGraphBuilderTest.php',
            'tests/Unit/Analysis/Collection/Dependency/Export/DotExporterTest.php' => 'tests/Reporting/GraphProjection/Unit/DotExporterTest.php',
            'tests/Unit/Analysis/Collection/Dependency/Export/JsonGraphExporterTest.php' => 'tests/Reporting/GraphProjection/Unit/JsonGraphExporterTest.php',
            'tests/Functional/Console/Command/GraphExportCommandTest.php' => 'tests/Infrastructure/Console/Functional/GraphExportCommandTest.php',
        ];

        foreach ($p2Cases as $legacyPath => $targetPath) {
            foreach ([$legacyPath, $targetPath] as $path) {
                [$exitCode, $output] = $this->runCommand([
                    \PHP_BINARY,
                    $this->root() . '/scripts/generate-modular-architecture-test-inventory.php',
                    '--classification-probe=' . $path,
                ]);

                self::assertSame(0, $exitCode, $output);
                $owner = str_contains($targetPath, '/DependencyModel/')
                    ? 'Analysis/Evidence/DependencyModel'
                    : (str_contains($targetPath, '/GraphProjection/') ? 'Reporting/GraphProjection' : 'Infrastructure/Console');
                $suite = str_contains($targetPath, '/Functional/') ? 'Functional' : 'Unit';
                self::assertSame("{$owner}\tP2\t{$suite}\t{$targetPath}\n", $output, $path);
            }
        }

        foreach (['DependencyResolverTest.php', 'DependencyVisitorTest.php', 'TypeDependencyHelperTest.php'] as $test) {
            $path = 'tests/Unit/Analysis/Collection/Dependency/' . $test;
            [$exitCode, $output] = $this->runCommand([
                \PHP_BINARY,
                $this->root() . '/scripts/generate-modular-architecture-test-inventory.php',
                '--classification-probe=' . $path,
            ]);
            self::assertSame(0, $exitCode, $output);
            self::assertSame("Analysis/Run\tP3\tUnit\ttests/Analysis/Run/Unit/{$test}\n", $output, $path);
        }

        foreach (['CircularDependencyDetectorTest.php', 'CycleIdentityStabilityTest.php', 'CycleMemberLabelsTest.php', 'CycleTest.php'] as $test) {
            $path = 'tests/Unit/Analysis/Collection/Dependency/' . $test;
            [$exitCode, $output] = $this->runCommand([
                \PHP_BINARY,
                $this->root() . '/scripts/generate-modular-architecture-test-inventory.php',
                '--classification-probe=' . $path,
            ]);
            self::assertSame(0, $exitCode, $output);
            self::assertSame(
                "Analysis/Evidence/CircularDependency\tP4\tUnit\ttests/Analysis/Evidence/CircularDependency/Unit/{$test}\n",
                $output,
                $path,
            );
        }

        [$exitCode, $output] = $this->runCommand([
            \PHP_BINARY,
            $this->root() . '/scripts/generate-modular-architecture-test-inventory.php',
            '--classification-probe=tests/Analysis/Evidence/DependencyModel/Unit/Extraction/UnexpectedTest.php',
        ]);
        self::assertNotSame(0, $exitCode);
        self::assertStringContainsString(
            'Unclassified test artifact: tests/Analysis/Evidence/DependencyModel/Unit/Extraction/UnexpectedTest.php',
            $output,
        );
    }

    #[Test]
    public function itClassifiesTheTwoP2ModuleReadmesExactly(): void
    {
        $cases = [
            'src/Analysis/Evidence/DependencyModel/README.md' => 'Analysis.Evidence.DependencyModel',
            'src/Reporting/GraphProjection/README.md' => 'Reporting.GraphProjection',
            'AGENTS.md' => 'Architecture.Governance',
            'CHANGELOG.md' => 'Architecture.Governance',
            'docs/ARCHITECTURE.md' => 'Architecture.Governance',
            'docs/adr/0021-declaration-scoped-callable-identity-and-dependency-projections.md' => 'Analysis.Evidence.DependencyModel',
            'docs/adr/0022-capability-oriented-modular-monolith.md' => 'Architecture.Governance',
            'docs/internal/plans/modular-architecture.md' => 'Architecture.Governance',
            'src/Analysis/README.md' => 'Analysis.Run',
            'src/Core/README.md' => 'Architecture.Governance',
            'src/Infrastructure/README.md' => 'Architecture.Governance',
            'src/Infrastructure/Console/README.md' => 'Architecture.Governance',
            'src/Reporting/README.md' => 'Architecture.Governance',
            'website/docs/rules/architecture.md' => 'Architecture.Governance',
            'website/docs/rules/architecture.ru.md' => 'Architecture.Governance',
        ];

        foreach ($cases as $path => $owner) {
            [$exitCode, $output] = $this->runCommand([
                \PHP_BINARY,
                $this->root() . '/scripts/generate-modular-architecture-production-inventory.php',
                '--documentation-probe=' . $path,
            ]);

            self::assertSame(0, $exitCode, $output);
            self::assertSame(
                "{$owner}\tP2\tMove or update atomically with the named migration package.\n",
                $output,
                $path,
            );
        }

        foreach ([
            'src/Analysis/Evidence/DependencyModel/Unexpected/README.md',
            'src/Reporting/GraphProjection/Unexpected/README.md',
        ] as $path) {
            [$exitCode, $output] = $this->runCommand([
                \PHP_BINARY,
                $this->root() . '/scripts/generate-modular-architecture-production-inventory.php',
                '--documentation-probe=' . $path,
            ]);
            self::assertNotSame(0, $exitCode);
            self::assertStringContainsString('unclassified committable documentation path: ' . $path, $output);
        }
    }

    #[Test]
    public function itRejectsAnUnlistedExactInternalPairBehindACoarseOwnerEdge(): void
    {
        $manifest = $this->manifest();
        $source = 'Qualimetrix\\Infrastructure\\DependencyInjection\\CompilerPass\\ChannelDeclarationCompilerPass';
        $target = 'Qualimetrix\\Core\\Rule\\RuleInterface';
        $contractSibling = 'Qualimetrix\\Core\\Rule\\ChannelDeclarationReader';
        self::assertSame($manifest['declarations'][$target]['owner'], $manifest['declarations'][$contractSibling]['owner']);
        self::assertSame('internal', $manifest['declarations'][$target]['visibility']);
        self::assertSame('contract', $manifest['declarations'][$contractSibling]['visibility']);
        self::assertContains(
            'Infrastructure.DependencyInjection',
            array_column($manifest['declarations'][$contractSibling]['consumers'], 'owner'),
        );
        $matchingGrant = array_search(
            $source . "\0" . $target,
            array_map(
                static fn(array $grant): string => $grant['source_fqcn'] . "\0" . $grant['target_fqcn'],
                $manifest['temporary_internal_grants'],
            ),
            true,
        );
        self::assertIsInt($matchingGrant);
        $removed = $manifest['temporary_internal_grants'][$matchingGrant];
        array_splice($manifest['temporary_internal_grants'], $matchingGrant, 1);
        self::assertIsArray($removed);

        [$exitCode, $output] = $this->runWithManifest($manifest);

        self::assertNotSame(0, $exitCode);
        self::assertStringContainsString(
            'unapproved exact internal import ' . $removed['source_fqcn'] . ' -> ' . $removed['target_fqcn'],
            $output,
        );
    }

    #[Test]
    public function itRejectsGeneratedLayerNameCollisions(): void
    {
        $manifest = $this->replaceOwner($this->manifest(), 'Analysis.Evidence.Size', 'Seam.Architecture.Lifecycle.Hook');

        [$exitCode, $output] = $this->runWithManifest($manifest);

        self::assertNotSame(0, $exitCode);
        self::assertStringContainsString('generated qmx layer name collision on seam-architecture-lifecycle-hook', $output);
    }

    #[Test]
    public function itRejectsNonCanonicalSegmentedSeamLayerNames(): void
    {
        $manifest = $this->manifest();
        $fqcn = array_key_first($manifest['enforcement_seams']);
        self::assertIsString($fqcn);
        $manifest['enforcement_seams'][$fqcn]['layer'] = 'seam-a--b';

        [$exitCode, $output] = $this->runWithManifest($manifest);

        self::assertNotSame(0, $exitCode);
        self::assertStringContainsString('manifest schema validation failed', $output);
        self::assertStringContainsString('enforcement_seams', $output);
    }

    #[Test]
    public function itRejectsOrphanGeneratedArtifacts(): void
    {
        $path = $this->root() . '/docs/internal/generated/modular-architecture/orphan-review-artifact.tsv';
        file_put_contents($path, "stale\n");

        try {
            [$exitCode, $output] = $this->runCommand([
                \PHP_BINARY,
                $this->root() . '/scripts/generate-modular-architecture.php',
                '--check',
            ]);
            self::assertNotSame(0, $exitCode);
            self::assertStringContainsString('orphan=[orphan-review-artifact.tsv]', $output);

            [$writeExitCode, $writeOutput] = $this->runCommand([
                \PHP_BINARY,
                $this->root() . '/scripts/generate-modular-architecture.php',
            ]);
            self::assertSame(0, $writeExitCode, $writeOutput);
            self::assertFileDoesNotExist($path);
        } finally {
            if (is_file($path)) {
                unlink($path);
            }
        }
    }

    #[Test]
    public function itPublishesNothingWhenTestInventoryRenderingFails(): void
    {
        $generatedPaths = glob($this->root() . '/docs/internal/generated/modular-architecture/*');
        self::assertIsArray($generatedPaths);
        $paths = array_merge([$this->root() . '/qmx.yaml'], $generatedPaths);
        $before = $this->hashes($paths);
        $fixture = $this->root() . '/tests/Architecture/Fixtures/ModularTopologySample/TransientUndiscoveredTest.php';
        file_put_contents(
            $fixture,
            "<?php\n\ndeclare(strict_types=1);\n\nnamespace Qualimetrix\\Tests\\Architecture\\Fixtures\\ModularTopologySample;\n\nfinal class TransientUndiscoveredTest {}\n",
        );

        try {
            [$exitCode, $output] = $this->runCommand([
                \PHP_BINARY,
                $this->root() . '/scripts/generate-modular-architecture.php',
            ]);
        } finally {
            unlink($fixture);
        }

        self::assertNotSame(0, $exitCode);
        self::assertStringContainsString('generate-modular-architecture-test-inventory.php', $output);
        self::assertStringContainsString('TransientUndiscoveredTest.php', $output);
        self::assertSame($before, $this->hashes($paths));
    }

    #[Test]
    public function itRollsBackTheWholePublicationAfterTheFirstReplacement(): void
    {
        $orphan = $this->root() . '/docs/internal/generated/modular-architecture/rollback-orphan.tsv';
        file_put_contents($orphan, "preserve me\n");
        $generatedPaths = glob($this->root() . '/docs/internal/generated/modular-architecture/*');
        self::assertIsArray($generatedPaths);
        $paths = array_merge([$this->root() . '/qmx.yaml'], $generatedPaths);
        $before = $this->hashes($paths);

        try {
            [$exitCode, $output] = $this->runCommand([
                \PHP_BINARY,
                $this->root() . '/scripts/generate-modular-architecture.php',
                '--fail-after-publish=1',
            ]);

            self::assertNotSame(0, $exitCode);
            self::assertStringContainsString('Publication transaction rolled back', $output);
            self::assertSame($before, $this->hashes($paths));
            self::assertSame("preserve me\n", file_get_contents($orphan));
        } finally {
            if (is_file($orphan)) {
                unlink($orphan);
            }
        }
    }

    #[Test]
    public function itInventoriesOnlyCommittableDocumentation(): void
    {
        $contents = file_get_contents($this->root() . '/docs/internal/generated/modular-architecture/documentation-ownership.tsv');
        self::assertIsString($contents);

        self::assertStringContainsString("AGENTS.md\t", $contents);
        self::assertStringContainsString("CLAUDE.md\t", $contents);
        self::assertStringNotContainsString('.local.md', $contents);
        self::assertStringNotContainsString('/node_modules/', $contents);
        self::assertStringNotContainsString('/vendor/', $contents);
        self::assertStringContainsString(
            "docs/adr/0016-subject-cohesion.md\tArchitecture.Governance\tshared\t",
            $contents,
        );
        self::assertStringContainsString(
            "docs/internal/plans/modular-architecture.md\tArchitecture.Governance\tP2\t",
            $contents,
        );
    }

    #[Test]
    public function itRejectsAnUnknownCommittableDocumentationPath(): void
    {
        [$exitCode, $output] = $this->runCommand([
            \PHP_BINARY,
            $this->root() . '/scripts/generate-modular-architecture-production-inventory.php',
            '--check',
            '--documentation-probe=website/docs/rules/duplication-guide.md',
        ]);

        self::assertNotSame(0, $exitCode);
        self::assertStringContainsString(
            'unclassified committable documentation path: website/docs/rules/duplication-guide.md',
            $output,
        );
    }

    #[Test]
    public function itRejectsMissingDuplicateAndMisorderedQmxMarkers(): void
    {
        $qmx = file_get_contents($this->root() . '/qmx.yaml');
        self::assertIsString($qmx);
        $begin = '# BEGIN GENERATED MODULAR ARCHITECTURE - DO NOT EDIT';
        $end = '# END GENERATED MODULAR ARCHITECTURE';
        $misordered = str_replace([$begin, $end], ['__QMX_END__', '__QMX_BEGIN__'], $qmx);
        $misordered = str_replace(['__QMX_BEGIN__', '__QMX_END__'], [$begin, $end], $misordered);
        $cases = [
            'missing' => str_replace([$begin, $end], '', $qmx),
            'duplicate' => $qmx . "\n{$begin}\n{$end}\n",
            'misordered' => $misordered,
        ];

        foreach ($cases as $label => $contents) {
            [$exitCode, $output] = $this->runWithQmx($contents);
            self::assertNotSame(0, $exitCode, $label);
            self::assertStringContainsString('qmx.yaml', $output, $label);
            self::assertStringContainsString('marker', $output, $label);
        }
    }

    #[Test]
    public function itDoesNotExtendATemporaryContractConsumerToAnotherDeclarationOfItsOwner(): void
    {
        $manifest = $this->manifest();
        $target = 'Qualimetrix\\Analysis\\Run\\Contract\\Collection\\CollectionOrchestratorInterface';
        foreach ($manifest['declarations'][$target]['consumers'] as &$consumer) {
            if ($consumer['owner'] !== 'Infrastructure.DependencyInjection') {
                continue;
            }
            $consumer['source_fqcn'] = 'Qualimetrix\\Infrastructure\\DependencyInjection\\Configurator\\AnalysisConfigurator';
            $consumer['closes_in'] = 'P3';
        }
        unset($consumer);

        [$exitCode, $output] = $this->runWithManifest($manifest);

        self::assertNotSame(0, $exitCode);
        self::assertStringContainsString(
            'contract import Qualimetrix\\Infrastructure\\DependencyInjection\\Configurator\\ArchitectureConfigurator -> ' . $target . ' has 0 matching consumer entries',
            $output,
        );
    }

    /** @return array<string, mixed> */
    private function manifest(): array
    {
        $contents = file_get_contents($this->root() . '/docs/internal/modular-architecture-manifest.json');
        self::assertIsString($contents);
        $manifest = json_decode($contents, true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($manifest);

        return $manifest;
    }

    /** @param array<string, mixed> $manifest
     *
     * @return array<string, mixed>
     */
    private function replaceOwner(array $manifest, string $from, string $to): array
    {
        array_walk_recursive($manifest, static function (mixed &$value) use ($from, $to): void {
            if ($value === $from) {
                $value = $to;
            }
        });

        return $manifest;
    }

    /** @return array{int, string} */
    private function runWithQmx(string $contents): array
    {
        $path = tempnam(sys_get_temp_dir(), 'qmx-config-');
        self::assertIsString($path);
        file_put_contents($path, $contents);

        try {
            return $this->runCommand([
                \PHP_BINARY,
                $this->root() . '/scripts/generate-modular-architecture-production-inventory.php',
                '--check',
                '--qmx-source=' . $path,
                '--qmx-output=' . $path,
            ]);
        } finally {
            unlink($path);
        }
    }

    /** @return array{int, string} */
    private function runWithDiscoveryProbe(string $contents): array
    {
        $path = tempnam(sys_get_temp_dir(), 'qmx-discovery-');
        self::assertIsString($path);
        file_put_contents($path, $contents);

        try {
            return $this->runCommand([
                \PHP_BINARY,
                $this->root() . '/scripts/generate-modular-architecture-test-inventory.php',
                '--discovery-probe=' . $path,
            ]);
        } finally {
            unlink($path);
        }
    }

    /**
     * @param array<string, mixed> $probe
     *
     * @return array{int, string}
     */
    private function runWithCompositionProbe(array $probe): array
    {
        $path = tempnam(sys_get_temp_dir(), 'qmx-composition-');
        self::assertIsString($path);
        file_put_contents($path, json_encode($probe, \JSON_THROW_ON_ERROR));

        try {
            return $this->runCommand([
                \PHP_BINARY,
                $this->root() . '/scripts/generate-modular-architecture-production-inventory.php',
                '--check',
                '--composition-probe=' . $path,
            ]);
        } finally {
            unlink($path);
        }
    }

    /** @return array{int, string} */
    private function runWithParameterOnlyCompositionSource(): array
    {
        $directory = sys_get_temp_dir() . '/qmx-composition-source-' . bin2hex(random_bytes(8));
        self::assertTrue(mkdir($directory));
        $sourcePath = $directory . '/FileProcessingResult.php';
        $probePath = $directory . '/probe.json';
        $manifestPath = $directory . '/manifest.json';
        $source = <<<'PHP'
<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Run\Contract\Collection;

final readonly class FileProcessingResult
{
    public function inspect(SuccessfulFileProcessing $payload): void {}
}
PHP;
        file_put_contents($sourcePath, $source);
        file_put_contents($probePath, json_encode([
            'source_overrides' => [
                'src/Analysis/Run/Contract/Collection/FileProcessingResult.php' => $sourcePath,
            ],
        ], \JSON_THROW_ON_ERROR));
        file_put_contents(
            $manifestPath,
            json_encode(
                $this->manifest(),
                \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE | \JSON_THROW_ON_ERROR,
            ) . "\n",
        );

        try {
            return $this->runCommand([
                \PHP_BINARY,
                $this->root() . '/scripts/generate-modular-architecture-production-inventory.php',
                '--check',
                '--manifest=' . $manifestPath,
                '--composition-probe=' . $probePath,
            ]);
        } finally {
            unlink($sourcePath);
            unlink($probePath);
            unlink($manifestPath);
            rmdir($directory);
        }
    }

    /**
     * @param array<string, mixed> $manifest
     *
     * @return array{int, string}
     */
    private function runWithManifest(array $manifest): array
    {
        $path = tempnam(sys_get_temp_dir(), 'qmx-manifest-');
        self::assertIsString($path);
        $json = json_encode($manifest, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE | \JSON_THROW_ON_ERROR);
        file_put_contents($path, $json . "\n");

        try {
            return $this->runCommand([
                \PHP_BINARY,
                $this->root() . '/scripts/generate-modular-architecture-production-inventory.php',
                '--check',
                '--manifest=' . $path,
            ]);
        } finally {
            unlink($path);
        }
    }

    /**
     * @param list<string> $command
     *
     * @return array{int, string}
     */
    private function runCommand(array $command): array
    {
        $pipes = [];
        $stderr = tmpfile();
        self::assertIsResource($stderr);
        $process = proc_open($command, [1 => ['pipe', 'w'], 2 => $stderr], $pipes, $this->root());
        self::assertIsResource($process);
        $output = stream_get_contents($pipes[1]);
        self::assertIsString($output);
        fclose($pipes[1]);
        $exitCode = proc_close($process);
        rewind($stderr);
        $stderrOutput = stream_get_contents($stderr);
        self::assertIsString($stderrOutput);
        fclose($stderr);

        return [$exitCode, $output . $stderrOutput];
    }

    /**
     * @param list<string> $paths
     *
     * @return array<string, string>
     */
    private function hashes(array $paths): array
    {
        $hashes = [];
        foreach ($paths as $path) {
            $hash = hash_file('sha256', $path);
            self::assertIsString($hash);
            $hashes[$path] = $hash;
        }
        ksort($hashes, \SORT_STRING);

        return $hashes;
    }

    /** @return array{int, int} */
    private function generatedQmxCounts(): array
    {
        $contents = file_get_contents($this->root() . '/qmx.yaml');
        self::assertIsString($contents);
        $begin = strpos($contents, '# BEGIN GENERATED MODULAR ARCHITECTURE - DO NOT EDIT');
        $end = strpos($contents, '# END GENERATED MODULAR ARCHITECTURE');
        self::assertIsInt($begin);
        self::assertIsInt($end);
        $generated = substr($contents, $begin, $end - $begin);
        $allowPosition = strpos($generated, "  allow:\n");
        self::assertIsInt($allowPosition);
        $layers = substr($generated, 0, $allowPosition);
        $allow = substr($generated, $allowPosition);
        $layerCount = preg_match_all('/^    - name: /m', $layers);
        $externalLayerCount = preg_match_all('/^    - name: external$/m', $layers);
        $allowEdgeCount = preg_match_all('/^      - /m', $allow);
        self::assertIsInt($layerCount);
        self::assertSame(1, $externalLayerCount);
        self::assertIsInt($allowEdgeCount);

        return [$layerCount - $externalLayerCount, $allowEdgeCount];
    }

    private function root(): string
    {
        $root = realpath(__DIR__ . '/../../..');
        self::assertIsString($root);

        return $root;
    }
}
