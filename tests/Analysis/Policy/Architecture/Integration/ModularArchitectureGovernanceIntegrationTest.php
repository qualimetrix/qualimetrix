<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Analysis\Policy\Architecture\Integration;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Core\Util\NamespaceMatcher;
use ReflectionClass;
use ReflectionMethod;
use ReflectionParameter;
use Symfony\Component\Yaml\Yaml;

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
        self::assertCount(7254, $testIds);
        self::assertSame($sortedTestIds, $testIds, 'Generated PHPUnit discovery IDs must be canonical across environments.');

        $path = $this->root() . '/docs/internal/generated/modular-architecture/test-phpunit-discovery.txt';
        $lines = file($path, \FILE_IGNORE_NEW_LINES);
        self::assertIsArray($lines);
        self::assertCount(7257, $lines);

        $suites = file(
            $this->root() . '/docs/internal/generated/modular-architecture/test-phpunit-suites.txt',
            \FILE_IGNORE_NEW_LINES | \FILE_SKIP_EMPTY_LINES,
        );
        self::assertIsArray($suites);
        $suiteRows = array_values(array_filter(
            $suites,
            static fn(string $line): bool => str_starts_with($line, ' - '),
        ));
        self::assertSame([
            ' - Functional (22 files, 227 tests)',
            ' - Infrastructure (21 files, 100 tests)',
            ' - Integration (48 files, 346 tests)',
            ' - Unit (418 files, 6581 tests)',
        ], $suiteRows);

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
        $rows = array_map(static fn(string $line): array => explode("\t", $line), $lines);
        $summary = array_column(\array_slice($rows, 1), 1, 0);
        $manifest = $this->manifest();
        self::assertArrayNotHasKey('p5_target', $manifest);
        $declarations = $manifest['declarations'];
        $owners = array_values(array_unique(array_column($declarations, 'owner')));
        $consumerEntries = array_sum(array_map(
            static fn(array $declaration): int => \count($declaration['consumers']),
            $declarations,
        ));
        $temporaryConsumerEntries = array_sum(array_map(
            static fn(array $declaration): int => \count(array_filter(
                $declaration['consumers'],
                static fn(array $consumer): bool => $consumer['closes_in'] !== null,
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

        self::assertCount(0, $manifest['enforcement_seams']);
        self::assertCount(50, $manifest['temporary_internal_grants']);
        self::assertCount(7, $coarseGrantEdges);
        self::assertSame(37, $qmxLayerCount);
        self::assertSame(224, $qmxAllowEdgeCount);
        self::assertSame('762', $summary['declarations']);
        self::assertSame('760', $summary['files']);
        self::assertSame('837', $summary['contract_consumer_entries']);
        self::assertSame((string) \count(array_unique(array_column($declarations, 'path'))), $summary['files']);
        self::assertSame((string) \count($owners), $summary['semantic_owners']);
        self::assertSame((string) $consumerEntries, $summary['contract_consumer_entries']);
        self::assertSame((string) $temporaryConsumerEntries, $summary['temporary_contract_consumer_entries']);
        self::assertSame((string) \count($manifest['enforcement_seams']), $summary['singleton_seams']);
        self::assertSame((string) \count($manifest['temporary_internal_grants']), $summary['exact_internal_grants']);
        self::assertSame((string) \count($coarseGrantEdges), $summary['coarse_internal_grant_edges']);

        $ruleDefinition = $declarations['Qualimetrix\\Analysis\\Finding\\Contract\\Rule\\RuleDefinitionInterface'];
        self::assertSame(['Analysis.Finding', 'contract', 'P6'], array_values(array_intersect_key(
            $ruleDefinition,
            array_flip(['owner', 'visibility', 'closure_package']),
        )));
        self::assertSame([
            'Qualimetrix\\Analysis\\Policy\\Inline\\Contract\\RuleValidatorMapFactory',
            'Qualimetrix\\Infrastructure\\Console\\Command\\BaselineConfiguredThresholds',
            'Qualimetrix\\Infrastructure\\DependencyInjection\\CompilerPass\\ParallelCollectorClassesCompilerPass',
            'Qualimetrix\\Infrastructure\\DependencyInjection\\CompilerPass\\RuleRegistryCompilerPass',
            'Qualimetrix\\Infrastructure\\DependencyInjection\\CompilerPass\\ThresholdValidatorMapCompilerPass',
            'Qualimetrix\\Infrastructure\\Parallel\\FileProcessingTask',
            'Qualimetrix\\Infrastructure\\Parallel\\FileProcessingTaskFactory',
            'Qualimetrix\\Infrastructure\\Parallel\\WorkerBootstrap',
            'Qualimetrix\\Infrastructure\\Rule\\KnownRuleNamesAdapter',
            'Qualimetrix\\Infrastructure\\Rule\\RuleRegistry',
            'Qualimetrix\\Infrastructure\\Rule\\RuleRegistryInterface',
        ], array_column($ruleDefinition['consumers'], 'source_fqcn'));

        $scratchDirectory = sys_get_temp_dir() . '/qmx-rule-definition-' . bin2hex(random_bytes(8));
        self::assertTrue(mkdir($scratchDirectory));
        $positiveSource = $scratchDirectory . '/RuleOptionsParserFactory.php';
        $negativeSource = $scratchDirectory . '/KnownRuleNamesAdapter.php';
        $overridesPath = $scratchDirectory . '/overrides.json';
        $outputDirectory = $scratchDirectory . '/output';
        $qmxOutput = $scratchDirectory . '/qmx.yaml';
        file_put_contents($positiveSource, <<<'PHP'
<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Finding\RuleConfiguration;

use Qualimetrix\Analysis\Finding\Contract\Rule\RuleDefinitionInterface as Definition;

final class RuleOptionsParserFactory
{
    /** @var class-string<Definition> */
    private string $direct;

    /**
     * @param list<class-string<RuleOptionsParserFactory>> $definitions
     */
    public function __construct(private array $definitions) {}

    /**
     * @return array{definition: class-string<\Qualimetrix\Analysis\Finding\Contract\Rule\RuleDefinitionInterface>}|class-string<Definition>
     */
    private function definitions(): array|string
    {
        return [];
    }

    /** This prose mentions class-string<RuleDefinitionInterface>. */
    private string $prose = '';

    /** @var 'class-string<RuleDefinitionInterface>' */
    private string $quoted = '';

    /** @var class-string */
    private string $bare = '';

    /**
     * @template T of object
     * @var class-string<T>
     */
    private string $template = '';

    private const string ORDINARY_LITERAL = 'class-string<\Qualimetrix\Analysis\Finding\Contract\Rule\RuleDefinitionInterface>';
}
PHP);
        file_put_contents($overridesPath, json_encode([
            'src/Analysis/Finding/RuleConfiguration/RuleOptionsParserFactory.php' => $positiveSource,
        ], \JSON_THROW_ON_ERROR));

        try {
            [$exitCode, $output] = $this->runCommand([
                \PHP_BINARY,
                $this->root() . '/scripts/generate-modular-architecture-production-inventory.php',
                '--output-directory=' . $outputDirectory,
                '--qmx-output=' . $qmxOutput,
                '--source-overrides=' . $overridesPath,
            ]);
            self::assertSame(0, $exitCode, $output);
            $targets = file($outputDirectory . '/production-class-string-targets.tsv', \FILE_IGNORE_NEW_LINES | \FILE_SKIP_EMPTY_LINES);
            self::assertIsArray($targets);
            self::assertSame([
                "Qualimetrix\\Analysis\\Finding\\RuleConfiguration\\RuleOptionsParserFactory\tconstructor\tdefinitions\tQualimetrix\\Analysis\\Finding\\RuleConfiguration\\RuleOptionsParserFactory",
                "Qualimetrix\\Analysis\\Finding\\RuleConfiguration\\RuleOptionsParserFactory\tmethod\tdefinitions\tQualimetrix\\Analysis\\Finding\\Contract\\Rule\\RuleDefinitionInterface",
                "Qualimetrix\\Analysis\\Finding\\RuleConfiguration\\RuleOptionsParserFactory\tmethod\tdefinitions\tQualimetrix\\Analysis\\Finding\\Contract\\Rule\\RuleDefinitionInterface",
                "Qualimetrix\\Analysis\\Finding\\RuleConfiguration\\RuleOptionsParserFactory\tproperty\tdirect\tQualimetrix\\Analysis\\Finding\\Contract\\Rule\\RuleDefinitionInterface",
            ], array_values(array_filter(
                \array_slice($targets, 1),
                static fn(string $row): bool => str_starts_with($row, "Qualimetrix\\Analysis\\Finding\\RuleConfiguration\\RuleOptionsParserFactory\t"),
            )));

            file_put_contents($negativeSource, <<<'PHP'
<?php

declare(strict_types=1);

namespace Qualimetrix\Infrastructure\Rule;

use Qualimetrix\Analysis\Configuration\Contract\KnownRuleNamesProviderInterface;
use Qualimetrix\Analysis\Finding\Rule\RuleInterface as InternalRule;

final readonly class KnownRuleNamesAdapter implements KnownRuleNamesProviderInterface
{
    /**
     * @param list<class-string<InternalRule>> $ruleClasses
     */
    public function __construct(private array $ruleClasses) {}

    public function getKnownRuleNames(): array
    {
        return [];
    }
}
PHP);
            file_put_contents($overridesPath, json_encode([
                'src/Infrastructure/Rule/KnownRuleNamesAdapter.php' => $negativeSource,
            ], \JSON_THROW_ON_ERROR));
            [$exitCode, $output] = $this->runCommand([
                \PHP_BINARY,
                $this->root() . '/scripts/generate-modular-architecture-production-inventory.php',
                '--check',
                '--source-overrides=' . $overridesPath,
            ]);
            self::assertNotSame(0, $exitCode);
            self::assertStringContainsString('cross-owner class-string target Qualimetrix\\Infrastructure\\Rule\\KnownRuleNamesAdapter -> Qualimetrix\\Analysis\\Finding\\Rule\\RuleInterface is internal', $output);

            file_put_contents($negativeSource, <<<'PHP'
<?php

declare(strict_types=1);

namespace Qualimetrix\Infrastructure\Rule;

use Qualimetrix\Analysis\Configuration\Contract\KnownRuleNamesProviderInterface;

final readonly class KnownRuleNamesAdapter implements KnownRuleNamesProviderInterface
{
    /**
     * @param list<class-string<\Qualimetrix\Analysis\Finding\Rule\RuleInterface>> $ruleClasses
     */
    public function __construct(private array $ruleClasses) {}

    public function getKnownRuleNames(): array
    {
        return [];
    }
}
PHP);
            [$exitCode, $output] = $this->runCommand([
                \PHP_BINARY,
                $this->root() . '/scripts/generate-modular-architecture-production-inventory.php',
                '--check',
                '--source-overrides=' . $overridesPath,
            ]);
            self::assertNotSame(0, $exitCode);
            self::assertStringContainsString('cross-owner class-string target Qualimetrix\\Infrastructure\\Rule\\KnownRuleNamesAdapter -> Qualimetrix\\Analysis\\Finding\\Rule\\RuleInterface is internal', $output);
        } finally {
            $outputPaths = glob($outputDirectory . '/*');
            if ($outputPaths !== false) {
                foreach ($outputPaths as $path) {
                    unlink($path);
                }
            }
            foreach ([$positiveSource, $negativeSource, $overridesPath, $qmxOutput] as $path) {
                if (is_file($path)) {
                    unlink($path);
                }
            }
            if (is_dir($outputDirectory)) {
                rmdir($outputDirectory);
            }
            rmdir($scratchDirectory);
        }

        foreach ([
            'Qualimetrix\\Reporting\\Formatter\\Support\\DebtBreakdownRenderer' => 'src/Reporting/Formatter/Support/DebtBreakdownRenderer.php',
            'Qualimetrix\\Reporting\\Formatter\\Support\\ViolationDetailRenderer' => 'src/Reporting/Formatter/Support/ViolationDetailRenderer.php',
        ] as $fqcn => $path) {
            self::assertSame($path, $declarations[$fqcn]['path']);
            self::assertSame('Reporting', $declarations[$fqcn]['owner']);
            self::assertSame('internal', $declarations[$fqcn]['visibility']);
            self::assertSame('P6', $declarations[$fqcn]['closure_package']);
            self::assertSame([], $declarations[$fqcn]['consumers']);
        }

        $qmx = Yaml::parseFile($this->root() . '/qmx.yaml');
        self::assertIsArray($qmx);
        $exactSelectorSets = [
            [$qmx['rules']['coupling.distance']['exclude_namespaces'], [
                '[Q]ualimetrix\\Analysis',
                '[Q]ualimetrix\\Analysis\\Evidence\\Prioritization',
                '[Q]ualimetrix\\Analysis\\Finding',
                '[Q]ualimetrix\\Analysis\\Finding\\Contract',
                '[Q]ualimetrix\\Analysis\\Finding\\Contract\\Rule',
                '[Q]ualimetrix\\Analysis\\Finding\\Contract\\Rule\\Override',
            ]],
            [$qmx['rules']['computed.health']['exclude_namespace_channels']['health.cohesion'], [
                '[Q]ualimetrix\\Analysis\\Evidence\\Prioritization',
                '[Q]ualimetrix\\Analysis\\Finding\\Contract\\Rule',
                '[Q]ualimetrix\\Analysis\\Policy\\Inline\\Contract',
                '[Q]ualimetrix\\Reporting\\FindingProjection',
                '[Q]ualimetrix\\Infrastructure\\Git',
            ]],
            [$qmx['rules']['coupling.cbo']['exclude_namespace_channels']['coupling.cbo.namespace'], [
                '[Q]ualimetrix\\Analysis\\Policy\\Inline\\Contract',
            ]],
            [$qmx['rules']['coupling.instability']['exclude_namespace_channels']['coupling.instability.namespace'], [
                '[Q]ualimetrix\\Infrastructure\\Parallel',
            ]],
        ];
        foreach ($exactSelectorSets as [$configured, $reviewed]) {
            self::assertIsArray($configured);
            foreach ($reviewed as $selector) {
                $target = $selector[1] . substr($selector, 3);
                self::assertContains($selector, $configured);
                self::assertNotContains($target, $configured);
                self::assertTrue(NamespaceMatcher::matchesSingle($selector, $target));
                self::assertFalse(NamespaceMatcher::matchesSingle($selector, $target . '\\Child'));
            }
        }

        $p5Declarations = array_filter(
            $declarations,
            static fn(array $declaration): bool => \in_array($declaration['owner'], [
                'Analysis.Evidence.ComputedMetrics',
                'Analysis.Evidence.ComputedMetrics.Health',
            ], true),
        );
        self::assertCount(34, $p5Declarations);
        self::assertSame([
            'Analysis.Evidence.ComputedMetrics' => 16,
            'Analysis.Evidence.ComputedMetrics.Health' => 18,
        ], array_count_values(array_column($p5Declarations, 'owner')));

        $externalRelations = [];
        foreach ($p5Declarations as $fqcn => $declaration) {
            self::assertStringNotContainsString('*', $fqcn);
            self::assertNotContains($declaration['owner'], ['Analysis', 'Analysis.Evidence']);
            foreach ($declaration['consumers'] as $consumer) {
                self::assertIsString($consumer['source_fqcn'], "P5 contract {$fqcn} has no owner-wide consumer.");
                self::assertNull($consumer['closes_in'], "P5 contract {$fqcn} retains a temporary consumer.");
                if ($consumer['owner'] !== $declaration['owner']) {
                    $externalRelations[] = [$fqcn, $consumer['source_fqcn']];
                }
            }
        }
        self::assertCount(34, $externalRelations);
        self::assertSame('internal', $declarations['Qualimetrix\\Analysis\\Evidence\\ComputedMetrics\\ComputedMetricDefaults']['visibility']);
        self::assertSame('Analysis.Evidence.ComputedMetrics', $declarations['Qualimetrix\\Analysis\\Evidence\\ComputedMetrics\\Contract\\Definition\\HealthDimension']['owner']);
        self::assertContains([
            'owner' => 'Analysis.Run',
            'source_fqcn' => 'Qualimetrix\\Analysis\\Run\\Pipeline\\AnalysisPipeline',
            'closes_in' => null,
        ], $declarations['Qualimetrix\\Analysis\\Evidence\\ComputedMetrics\\Contract\\Evaluation\\ComputedMetricEvaluator']['consumers']);
        foreach ([
            'Qualimetrix\\Core\\ComputedMetric\\ComputedMetricDefinitionHolder',
            'Qualimetrix\\Analysis\\Run\\Enrichment\\TransitionalMetricEnricher',
            'Qualimetrix\\Analysis\\Run\\Enrichment\\TransitionalEnrichmentResult',
            'Qualimetrix\\Reporting\\Health\\MetricHintProvider',
            'Qualimetrix\\Reporting\\Health\\HealthReasonBuilder',
        ] as $obsoleteFqcn) {
            self::assertArrayNotHasKey($obsoleteFqcn, $declarations);
        }

        $invalid = $manifest;
        $invalid['declarations']['Qualimetrix\\Analysis\\Evidence\\ComputedMetrics\\Contract\\Evaluation\\ComputedMetricEvaluator']['consumers'][0]['source_fqcn'] = null;
        [$exitCode, $output] = $this->runWithManifest($invalid);
        self::assertNotSame(0, $exitCode);
        self::assertStringContainsString('retains an owner-wide or temporary consumer', $output);

        $invalid = $manifest;
        $invalid['declarations']['Qualimetrix\\Analysis\\Evidence\\ComputedMetrics\\Contract\\Evaluation\\ComputedMetricEvaluator']['consumers'][0]['closes_in'] = 'P5-C';
        [$exitCode, $output] = $this->runWithManifest($invalid);
        self::assertNotSame(0, $exitCode);
        self::assertStringContainsString('retains an owner-wide or temporary consumer', $output);

        $invalid = $manifest;
        $invalid['declarations']['Qualimetrix\\Core\\ComputedMetric\\ComputedMetricDefinitionHolder'] = $declarations['Qualimetrix\\Analysis\\Evidence\\ComputedMetrics\\ComputedMetricDefaults'];
        [$exitCode, $output] = $this->runWithManifest($invalid);
        self::assertNotSame(0, $exitCode);
        self::assertStringContainsString('Materialized P5-F2 capability must contain exactly 34 owned declarations', $output);
    }
    #[Test]
    public function itEncodesThePostP1DuplicationBoundary(): void
    {
        $manifest = $this->manifest();
        $declarations = $manifest['declarations'];
        self::assertCount(762, $declarations);
        self::assertCount(760, array_unique(array_column($declarations, 'path')));
        self::assertCount(37, $manifest['owners']);
        self::assertSame(248, \count(array_filter(
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
        self::assertCount(762, $declarations);

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
        $enforcementSeams = $manifest['enforcement_seams'] ?? null;
        if (!\is_array($enforcementSeams)) {
            self::fail('Manifest enforcement_seams must be an array.');
        }
        self::assertCount(0, $enforcementSeams);
        self::assertArrayNotHasKey(
            'Qualimetrix\\Core\\Metric\\GlobalContextCollectorInterface',
            $enforcementSeams,
        );
        self::assertArrayNotHasKey(
            'Qualimetrix\\Core\\Violation\\Location',
            $enforcementSeams,
        );
    }

    #[Test]
    public function itEncodesThePostP3RunConfigurationMeasurementAndDependencyModelBoundaries(): void
    {
        $manifest = $this->manifest();
        $declarations = $manifest['declarations'];

        $transitionalProviderConsumers = $declarations[
            'Qualimetrix\\Analysis\\Configuration\\Contract\\TransitionalRuntimeConfigurationProviderInterface'
        ]['consumers'];
        self::assertNotContains(
            'Analysis.Finding',
            array_column($transitionalProviderConsumers, 'owner'),
            'Finding no longer consumes the transitional runtime configuration provider.',
        );
        self::assertSame(
            ['Analysis.Evidence.Coupling'],
            array_column(
                $declarations['Qualimetrix\\Analysis\\Finding\\Contract\\Rule\\AdditionalOptionKeysInterface']['consumers'],
                'owner',
            ),
            'Only the observed Coupling implementation consumes AdditionalOptionKeysInterface.',
        );
        self::assertSame(
            ['Infrastructure.Console', 'Infrastructure.Rule'],
            array_column(
                $declarations['Qualimetrix\\Analysis\\Finding\\Contract\\Rule\\CliAliasReader']['consumers'],
                'owner',
            ),
            'CliAliasReader retains only its two observed infrastructure consumers.',
        );
        self::assertSame(
            ['Analysis.Run', 'Analysis.Policy.Inline'],
            array_column(
                $declarations['Qualimetrix\\Analysis\\Finding\\Contract\\Rule\\RuleMatcher']['consumers'],
                'owner',
            ),
            'RuleMatcher retains only its observed Inline and Run consumers.',
        );
        self::assertSame(
            [
                'Infrastructure.Console',
                'Infrastructure.DependencyInjection',
                'Infrastructure.Rule',
                'Analysis.Policy.Inline',
            ],
            array_column(
                $declarations['Qualimetrix\\Analysis\\Finding\\Contract\\Rule\\RuleNameReader']['consumers'],
                'owner',
            ),
            'RuleNameReader retains only its four observed external consumers.',
        );
        self::assertSame(
            [
                'Analysis.Evidence.CircularDependency',
                'Analysis.Evidence.CodeSmell',
                'Analysis.Evidence.Cohesion',
                'Analysis.Evidence.Complexity',
                'Analysis.Evidence.ComputedMetrics',
                'Analysis.Evidence.Coupling',
                'Analysis.Evidence.Design',
                'Analysis.Evidence.Duplication',
                'Analysis.Evidence.Maintainability',
                'Analysis.Evidence.Security',
                'Analysis.Evidence.Size',
                'Analysis.Policy.Architecture',
                'Infrastructure.Console',
            ],
            array_column(
                $declarations['Qualimetrix\\Analysis\\Finding\\Contract\\Rule\\RuleOptionKey']['consumers'],
                'owner',
            ),
            'RuleOptionKey retains exactly its 13 observed consumers.',
        );
        self::assertSame(
            [
                'Analysis.Evidence.CircularDependency',
                'Analysis.Evidence.CodeSmell',
                'Analysis.Evidence.Cohesion',
                'Analysis.Evidence.Complexity',
                'Analysis.Evidence.ComputedMetrics',
                'Analysis.Evidence.Coupling',
                'Analysis.Evidence.Design',
                'Analysis.Evidence.Duplication',
                'Analysis.Evidence.Maintainability',
                'Analysis.Evidence.Security',
                'Analysis.Evidence.Size',
                'Analysis.Policy.Architecture',
                'Infrastructure.Console',
                'Infrastructure.DependencyInjection',
            ],
            array_column(
                $declarations['Qualimetrix\\Analysis\\Finding\\Contract\\Rule\\RuleOptionsInterface']['consumers'],
                'owner',
            ),
            'RuleOptionsInterface retains exactly its 14 observed consumers.',
        );
        self::assertSame(
            [
                'Analysis.Evidence.CodeSmell',
                'Analysis.Evidence.Cohesion',
                'Analysis.Evidence.Complexity',
                'Analysis.Evidence.Coupling',
                'Analysis.Evidence.Design',
                'Analysis.Evidence.Duplication',
                'Analysis.Evidence.Maintainability',
                'Analysis.Evidence.Size',
            ],
            array_column(
                $declarations['Qualimetrix\\Analysis\\Finding\\Contract\\Rule\\ShorthandOptionKeysInterface']['consumers'],
                'owner',
            ),
            'ShorthandOptionKeysInterface retains exactly its eight observed consumers.',
        );
        self::assertSame('Core.Neutral', $declarations['Qualimetrix\\Core\\Util\\NamespaceMatcher']['owner']);
        self::assertSame('P8', $declarations['Qualimetrix\\Core\\Util\\NamespaceMatcher']['closure_package']);
        self::assertSame(
            ['Analysis.Finding', 'Analysis.Policy.Architecture', 'Reporting'],
            array_column($declarations['Qualimetrix\\Core\\Util\\NamespaceMatcher']['consumers'], 'owner'),
            'NamespaceMatcher retains only its three observed consumers.',
        );
        self::assertSame('Core.Neutral', $declarations['Qualimetrix\\Core\\Util\\PathMatcher']['owner']);
        self::assertSame('P8', $declarations['Qualimetrix\\Core\\Util\\PathMatcher']['closure_package']);
        self::assertSame(
            ['Analysis.Finding', 'Reporting'],
            array_column($declarations['Qualimetrix\\Core\\Util\\PathMatcher']['consumers'], 'owner'),
            'PathMatcher retains only its two observed consumers.',
        );
        self::assertSame([], array_values(array_filter(
            $manifest['temporary_internal_grants'],
            static fn(array $grant): bool => $grant['source_fqcn'] === 'Qualimetrix\\Infrastructure\\DependencyInjection\\CompilerPass\\RuleCompilerPass'
                && $grant['target_fqcn'] === 'Qualimetrix\\Infrastructure\\Console\\Command\\RulesCommand',
        )), 'The closed RuleCompilerPass-to-RulesCommand grant must not return.');
        self::assertSame([], array_values(array_filter(
            $manifest['temporary_internal_grants'],
            static fn(array $grant): bool => $grant['source_fqcn'] === 'Qualimetrix\\Infrastructure\\DependencyInjection\\Configurator\\AnalysisConfigurator'
                && $grant['target_fqcn'] === 'Qualimetrix\\Analysis\\Finding\\RuleExecution',
        )), 'AnalysisConfigurator must consume the public RuleExecutionInterface, not internal RuleExecution.');
        self::assertContains([
            'owner' => 'Infrastructure.DependencyInjection',
            'source_fqcn' => null,
            'closes_in' => null,
        ], $declarations['Qualimetrix\\Analysis\\Finding\\Contract\\RuleExecutionInterface']['consumers']);
        self::assertSame('P8', $declarations['Qualimetrix\\Infrastructure\\Logging\\DelegatingLogger']['closure_package']);
        self::assertSame([], array_values(array_filter(
            $manifest['temporary_internal_grants'],
            static fn(array $grant): bool => $grant['source_fqcn'] === 'Qualimetrix\\Infrastructure\\DependencyInjection\\Configurator\\ConfigurationConfigurator'
                && $grant['target_fqcn'] === 'Qualimetrix\\Infrastructure\\Logging\\DelegatingLogger',
        )), 'ConfigurationConfigurator no longer consumes DelegatingLogger.');
        self::assertArrayNotHasKey(
            'Qualimetrix\\Analysis\\Finding\\RuleExecution',
            $manifest['enforcement_seams'],
            'RuleExecution no longer needs a singleton seam to keep the owner DAG acyclic.',
        );
        self::assertArrayNotHasKey('Qualimetrix\\Core\\Rule\\RuleInterface', $declarations);
        self::assertArrayHasKey('Qualimetrix\\Analysis\\Finding\\Rule\\RuleInterface', $declarations);
        self::assertArrayHasKey('Qualimetrix\\Analysis\\Finding\\Contract\\Rule\\RuleDefinitionInterface', $declarations);
        $ruleDefinition = new ReflectionClass(\Qualimetrix\Analysis\Finding\Contract\Rule\RuleDefinitionInterface::class);
        self::assertSame(['getOptionsClass'], array_map(
            static fn(ReflectionMethod $method): string => $method->getName(),
            $ruleDefinition->getMethods(),
        ));
        self::assertTrue((new ReflectionClass(\Qualimetrix\Analysis\Finding\Rule\RuleInterface::class))
            ->isSubclassOf(\Qualimetrix\Analysis\Finding\Contract\Rule\RuleDefinitionInterface::class));
        $classStringTargets = file(
            $this->root() . '/docs/internal/generated/modular-architecture/production-class-string-targets.tsv',
            \FILE_IGNORE_NEW_LINES | \FILE_SKIP_EMPTY_LINES,
        );
        self::assertIsArray($classStringTargets);
        self::assertSame([], array_values(array_filter(
            \array_slice($classStringTargets, 1),
            static fn(string $row): bool => str_ends_with($row, "\tQualimetrix\\Analysis\\Finding\\Rule\\RuleInterface"),
        )));
        $extensionInventory = file(
            $this->root() . '/docs/internal/generated/modular-architecture/production-extension-families.tsv',
            \FILE_IGNORE_NEW_LINES | \FILE_SKIP_EMPTY_LINES,
        );
        self::assertIsArray($extensionInventory);
        self::assertCount(41, array_filter(
            \array_slice($extensionInventory, 1),
            static fn(string $row): bool => str_starts_with($row, "rule\t"),
        ));
        $productionGenerator = file_get_contents(
            $this->root() . '/scripts/generate-modular-architecture-production-inventory.php',
        );
        self::assertIsString($productionGenerator);
        self::assertStringNotContainsString('src/Analysis/RuleExecution/RuleExecutor.php', $productionGenerator);
        self::assertStringContainsString(
            "'participant' => 'Analysis\\\\Finding\\\\RuleExecution + 41 RuleInterface implementations'",
            $productionGenerator,
        );
        self::assertStringContainsString("'source' => 'src/Analysis/Finding/RuleExecution.php'", $productionGenerator);
        $p6Documentation = [
            'docs/internal/plans/modular-architecture/p6-finding-policy.md' => 'P6-F',
            'docs/internal/plans/modular-architecture/p6/p6-production-ledger.md' => 'P6-0',
            'docs/internal/plans/modular-architecture/p6/p6-relations-ledger.md' => 'P6-0',
            'docs/internal/plans/modular-architecture/p6/p6-test-ledger.md' => 'P6-0',
        ];
        foreach ($p6Documentation as $path => $closure) {
            self::assertFileExists($this->root() . '/' . $path);
            [$exitCode, $output] = $this->runCommand([
                \PHP_BINARY,
                $this->root() . '/scripts/generate-modular-architecture-production-inventory.php',
                '--documentation-probe=' . $path,
            ]);
            self::assertSame(0, $exitCode, $output);
            self::assertSame(
                "Architecture.Governance\t{$closure}\tMove or update atomically with the named migration package.\n",
                $output,
                $path,
            );
        }
        $p6Plan = file_get_contents($this->root() . '/docs/internal/plans/modular-architecture/p6-finding-policy.md');
        self::assertIsString($p6Plan);
        foreach (\array_slice(array_keys($p6Documentation), 1) as $ledgerPath) {
            self::assertStringContainsString('](' . str_replace(
                'docs/internal/plans/modular-architecture/',
                '',
                $ledgerPath,
            ) . ')', $p6Plan);
        }
        foreach ([
            'src/Analysis/Finding/README.md' => [
                'Analysis.Finding',
                'P6-A',
            ],
            'src/Analysis/Policy/Inline/README.md' => [
                'Analysis.Policy.Inline',
                'P6-B',
            ],
            'src/Analysis/Policy/Baseline/README.md' => [
                'Analysis.Policy.Baseline',
                'P6-C',
            ],
            'src/Analysis/Configuration/README.md' => [
                'Analysis.Configuration',
                'P3',
            ],
        ] as $path => [$owner, $closure]) {
            self::assertFileExists($this->root() . '/' . $path);
            [$exitCode, $output] = $this->runCommand([
                \PHP_BINARY,
                $this->root() . '/scripts/generate-modular-architecture-production-inventory.php',
                '--documentation-probe=' . $path,
            ]);
            self::assertSame(0, $exitCode, $output);
            self::assertSame(
                "{$owner}\t{$closure}\tMove or update atomically with the named migration package.\n",
                $output,
                $path,
            );
        }
        self::assertArrayNotHasKey('Qualimetrix\\Analysis\\Policy\\Inline\\Contract\\Control\\ControlScope', $declarations);
        self::assertArrayNotHasKey('Qualimetrix\\Analysis\\Policy\\Inline\\Contract\\Threshold\\ThresholdOverride', $declarations);
        self::assertSame('Analysis.Finding', $declarations['Qualimetrix\\Analysis\\Finding\\Contract\\Control\\ControlScope']['owner']);
        self::assertSame('Analysis.Finding', $declarations['Qualimetrix\\Analysis\\Finding\\Contract\\Threshold\\ThresholdOverride']['owner']);
        self::assertSame([], $manifest['enforcement_seams']);
        self::assertCount(50, $manifest['temporary_internal_grants']);
        if (!\is_array($declarations)) {
            self::fail('Manifest declarations must be an array.');
        }
        $p6CBaseline = array_filter(
            $declarations,
            static fn(array $declaration): bool => $declaration['owner'] === 'Analysis.Policy.Baseline'
                && $declaration['closure_package'] === 'P6-C',
        );
        self::assertCount(45, $p6CBaseline);
        foreach (array_keys($p6CBaseline) as $fqcn) {
            self::assertStringStartsWith('Qualimetrix\\Analysis\\Policy\\Baseline\\', $fqcn);
            self::assertStringStartsWith('src/Analysis/Policy/Baseline/', $declarations[$fqcn]['path']);
        }
        self::assertArrayNotHasKey('Qualimetrix\\Baseline\\Baseline', $declarations);
        foreach ([
            'tests/Analysis/Finding/Unit/ThresholdOverrideTest.php' => "Analysis/Finding\tP6-B\tUnit",
            'tests/Analysis/Policy/Inline/Unit/Extraction/SourceControlExtractorTest.php' => "Analysis/Policy/Inline\tP6-B\tUnit",
            'tests/Analysis/Policy/Baseline/Unit/BaselineTest.php' => "Analysis/Policy/Baseline\tP6-C\tUnit",
            'tests/Reporting/FindingProjection/Unit/FindingProjectorTest.php' => "Reporting/FindingProjection\tP6-D\tUnit",
            'tests/Analysis/Evidence/Prioritization/Unit/Debt/DebtCalculatorTest.php' => "Analysis/Evidence/Prioritization\tP6-D\tUnit",
            'tests/Unit/Infrastructure/Git/ReportingGitScopeQueryTest.php' => "Infrastructure/Git\tP6-D\tUnit",
            'tests/Integration/Infrastructure/Git/ReportingGitScopeQueryProjectSubdirTest.php' => "Infrastructure/Git\tP6-D\tIntegration",
        ] as $path => $prefix) {
            [$exitCode, $output] = $this->runCommand([
                \PHP_BINARY,
                $this->root() . '/scripts/generate-modular-architecture-test-inventory.php',
                '--classification-probe=' . $path,
            ]);
            self::assertSame(0, $exitCode, $output);
            self::assertStringStartsWith($prefix, $output);
        }
        $invalidSurfaceManifest = $manifest;
        $invalidSurfaceManifest['declarations']['Qualimetrix\\Analysis\\Policy\\Inline\\Contract\\Suppression\\Suppression']['consumers'][0]['carrier_fqcn'] =
            'Qualimetrix\\Analysis\\Policy\\Inline\\Contract\\SourceControlExtractorInterface';
        [$exitCode, $output] = $this->runWithManifest($invalidSurfaceManifest);
        self::assertNotSame(0, $exitCode);
        self::assertStringContainsString('carrier lacks exact declared target containment', $output);
        $scratchTestDirectory = sys_get_temp_dir() . '/qmx-p6a-governance-' . bin2hex(random_bytes(8));
        [$exitCode, $output] = $this->runCommand([
            \PHP_BINARY,
            $this->root() . '/scripts/generate-modular-architecture-test-inventory.php',
            '--output-directory=' . $scratchTestDirectory,
        ]);
        self::assertSame(0, $exitCode, $output);
        self::assertStringContainsString('509 PHPUnit classes, and 7254 expanded cases', $output);
        [$exitCode, $output] = $this->runCommand([
            \PHP_BINARY,
            $this->root() . '/scripts/generate-modular-architecture-test-inventory.php',
            '--classification-probe=tests/Analysis/Finding/Unit/FutureSiblingTest.php',
        ]);
        self::assertNotSame(0, $exitCode);
        self::assertStringContainsString('Unclassified P6-A Finding test artifact', $output);
        self::assertNotContains([
            'source_fqcn' => 'Qualimetrix\\Infrastructure\\DependencyInjection\\CompilerPass\\RuleCompilerPass',
            'target_fqcn' => 'Qualimetrix\\Analysis\\Finding\\RuleExecution',
            'owner' => 'Analysis.Finding',
            'rationale' => 'Temporary composition-root access to a current internal declaration during modular migration.',
            'closes_in' => 'P6-E',
        ], $manifest['temporary_internal_grants']);

        self::assertArrayNotHasKey('Qualimetrix\\Analysis\\Policy\\Inline\\Contract\\Control\\ControlScope', $declarations);
        self::assertArrayNotHasKey('Qualimetrix\\Analysis\\Policy\\Inline\\Contract\\Threshold\\ThresholdOverride', $declarations);
        self::assertSame(
            ['Analysis.Finding', 'contract', 'P6-B'],
            array_values(array_intersect_key(
                $declarations['Qualimetrix\\Analysis\\Finding\\Contract\\Control\\ControlScope'],
                array_flip(['owner', 'visibility', 'closure_package']),
            )),
        );
        self::assertSame(
            ['Analysis.Finding', 'contract', 'P6-B'],
            array_values(array_intersect_key(
                $declarations['Qualimetrix\\Analysis\\Finding\\Contract\\Threshold\\ThresholdOverride'],
                array_flip(['owner', 'visibility', 'closure_package']),
            )),
        );
        self::assertSame([], $manifest['enforcement_seams']);
        self::assertCount(50, $manifest['temporary_internal_grants']);
        self::assertSame([], array_values(array_filter(
            $manifest['temporary_internal_grants'],
            static fn(array $grant): bool => \in_array($grant['closes_in'], ['P6', 'P6-E'], true),
        )), 'All remaining original P6 grants are closed without replacement.');
        $ruleConfigurationConsumers = $declarations[
            'Qualimetrix\\Analysis\\Finding\\Contract\\RuleConfigurationInterface'
        ]['consumers'] ?? null;
        if (!\is_array($ruleConfigurationConsumers)) {
            self::fail('RuleConfigurationInterface consumers must be an array.');
        }
        self::assertContains([
            'owner' => 'Infrastructure.DependencyInjection',
            'source_fqcn' => 'Qualimetrix\\Infrastructure\\DependencyInjection\\Configurator\\AnalysisConfigurator',
            'closes_in' => null,
        ], $ruleConfigurationConsumers);
        self::assertSame([
            'Qualimetrix\\Infrastructure\\Console\\AnalysisRuntimeConfigurator',
            'Qualimetrix\\Infrastructure\\DependencyInjection\\Configurator\\AnalysisConfigurator',
            'Qualimetrix\\Infrastructure\\DependencyInjection\\Configurator\\FindingConfigurator',
            'Qualimetrix\\Infrastructure\\DependencyInjection\\Configurator\\OutputConfigurator',
            'Qualimetrix\\Analysis\\Run\\RuleProducerPreparation',
        ], array_column($ruleConfigurationConsumers, 'source_fqcn'));
        self::assertNotContains(
            'Qualimetrix\\Analysis\\Run\\Pipeline\\AnalysisPipeline',
            array_column($ruleConfigurationConsumers, 'source_fqcn'),
        );
        self::assertCount(762, $declarations);
        self::assertCount(760, array_unique(array_column($declarations, 'path')));
        self::assertCount(37, $manifest['owners']);
        $baseline = json_decode(
            (string) file_get_contents($this->root() . '/qmx-baseline.json'),
            true,
            512,
            \JSON_THROW_ON_ERROR,
        );
        self::assertIsArray($baseline);
        self::assertCount(274, array_merge(...array_values($baseline['entries'])));
        $suppressionFilterPredecessor = 'declaration:callable:Qualimetrix\\Analysis\\Policy\\Inline\\Suppression\\SuppressionFilter::shouldInclude@src/Analysis/Policy/Inline/Suppression/SuppressionFilter.php:2433';
        $suppressionFilterSuccessor = 'declaration:callable:Qualimetrix\\Analysis\\Policy\\Inline\\Suppression\\SuppressionFilter::shouldInclude@src/Analysis/Policy/Inline/Suppression/SuppressionFilter.php:2552';
        self::assertArrayNotHasKey($suppressionFilterPredecessor, $baseline['entries']);
        self::assertSame([[
            'channel' => 'complexity.cyclomatic#complexity.cyclomatic.callable',
            'magnitudes' => [11],
            'count' => 1,
        ]], $baseline['entries'][$suppressionFilterSuccessor]);
        self::assertArrayNotHasKey('occurrence', $baseline['entries'][$suppressionFilterSuccessor][0]);
        foreach ([
            'declaration:callable:Qualimetrix\\Infrastructure\\Console\\ViolationFilterPipeline::filter@src/Infrastructure/Console/ViolationFilterPipeline.php:5119',
            'declaration:callable:Qualimetrix\\Reporting\\Formatter\\Support\\DetailedViolationRenderer::renderGrouped@src/Reporting/Formatter/Support/DetailedViolationRenderer.php:3215',
            'declaration:callable:Qualimetrix\\Reporting\\Formatter\\Support\\DetailedViolationRenderer::renderViolation@src/Reporting/Formatter/Support/DetailedViolationRenderer.php:4963',
            'declaration:class:Qualimetrix\\Analysis\\RuleExecution\\RuleExecutor@src/Analysis/RuleExecution/RuleExecutor.php:800',
            'declaration:class:Qualimetrix\\Reporting\\FormatterContext@src/Reporting/FormatterContext.php:244',
            'declaration:class:Qualimetrix\\Reporting\\Formatter\\Support\\DetailedViolationRenderer@src/Reporting/Formatter/Support/DetailedViolationRenderer.php:612',
            'ns:Qualimetrix\\Core\\Rule',
            'ns:Qualimetrix\\Infrastructure\\Git',
            'ns:Qualimetrix\\Infrastructure\\Parallel',
        ] as $resolvedIdentity) {
            self::assertArrayNotHasKey($resolvedIdentity, $baseline['entries']);
        }
        $factorySource = file_get_contents(
            $this->root() . '/src/Analysis/Finding/RuleConfiguration/RuleOptionsFactory.php',
        );
        self::assertIsString($factorySource);
        self::assertStringNotContainsString('normalizeNamespaceExclusions', $factorySource);
        self::assertSame(1, substr_count($factorySource, '->configureNamespaceExclusions($ruleName, $namespaces)'));
        self::assertSame(1, substr_count($factorySource, '->configureNamespaceChannelExclusions($ruleName, $channels)'));
        foreach (['configureNamespaceExclusions', 'configureNamespaceChannelExclusions'] as $method) {
            $concreteParameter = new ReflectionParameter([
                \Qualimetrix\Analysis\Finding\RuleConfiguration\RuleOptionsRegistry::class,
                $method,
            ], 1);
            $interfaceParameter = new ReflectionParameter([
                \Qualimetrix\Analysis\Finding\Contract\RuleConfigurationInterface::class,
                $method,
            ], 1);
            self::assertSame('mixed', (string) $concreteParameter->getType());
            self::assertSame('array', (string) $interfaceParameter->getType());
        }
        $formatterContextSource = file_get_contents($this->root() . '/src/Reporting/FormatterContext.php');
        self::assertIsString($formatterContextSource);
        self::assertStringContainsString('coupling.cbo warning=30 error=30', $formatterContextSource);
        $qmxSource = file_get_contents($this->root() . '/qmx.yaml');
        self::assertIsString($qmxSource);
        self::assertStringContainsString('Ca=3 / Ce=31', $qmxSource);
        self::assertArrayNotHasKey('Qualimetrix\\Infrastructure\\Console\\ViolationFilterPipeline', $declarations);
        self::assertArrayNotHasKey('Qualimetrix\\Infrastructure\\Git\\GitScopeFilter', $declarations);
        self::assertSame(
            ['Reporting', 'contract', 'P6-D'],
            array_values(array_intersect_key(
                $declarations['Qualimetrix\\Reporting\\FindingProjection\\FindingProjector'],
                array_flip(['owner', 'visibility', 'closure_package']),
            )),
        );
        self::assertSame(
            ['Analysis.Evidence.Prioritization', 'P6-D'],
            array_values(array_intersect_key(
                $declarations['Qualimetrix\\Analysis\\Evidence\\Prioritization\\Debt\\DebtCalculator'],
                array_flip(['owner', 'closure_package']),
            )),
        );
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

        $extractorFqcn = 'Qualimetrix\\Analysis\\Policy\\Inline\\Extraction\\SourceControlExtractor';
        self::assertSame([
            'src/Analysis/Policy/Inline/Extraction/SourceControlExtractor.php',
            'Analysis.Policy.Inline',
            'internal',
            'P6-E',
        ], [
            $declarations[$extractorFqcn]['path'],
            $declarations[$extractorFqcn]['owner'],
            $declarations[$extractorFqcn]['visibility'],
            $declarations[$extractorFqcn]['closure_package'],
        ]);
        $sourceControlsSource = file_get_contents(
            $this->root() . '/src/Analysis/Policy/Inline/Contract/SourceControls.php',
        );
        $extractorSource = file_get_contents(
            $this->root() . '/src/Analysis/Policy/Inline/Extraction/SourceControlExtractor.php',
        );
        $analysisConfiguratorSource = file_get_contents(
            $this->root() . '/src/Infrastructure/DependencyInjection/Configurator/AnalysisConfigurator.php',
        );
        self::assertIsString($sourceControlsSource);
        self::assertIsString($extractorSource);
        self::assertIsString($analysisConfiguratorSource);
        self::assertStringNotContainsString('implements SourceControlExtractorInterface', $sourceControlsSource);
        self::assertStringContainsString('implements SourceControlExtractorInterface', $extractorSource);
        self::assertStringContainsString(
            '@qmx-ignore health.cohesion -- One public extraction operation uses both extraction collaborators; TCC is undefined, promoted constructor properties create an LCOM artifact, and private static traversal helpers push the general health methodCount to its >= 6 eligibility cutoff.',
            $extractorSource,
        );
        self::assertStringContainsString(
            "private const string SOURCE_CONTROL_EXTRACTOR_CLASS = 'Qualimetrix\\\\Analysis\\\\Policy\\\\Inline\\\\Extraction\\\\SourceControlExtractor';",
            $analysisConfiguratorSource,
        );
        self::assertStringContainsString('new Reference($privateExtractorId)', $analysisConfiguratorSource);
        self::assertStringNotContainsString('setAlias(SourceControlExtractorInterface::class', $analysisConfiguratorSource);
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
            "Analysis/Policy/Inline\tP6\tUnit\ttests/Analysis/Policy/Inline/Unit/Extraction/SourceControlExtractorTest.php\n",
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
            'docs/internal/plans/modular-architecture/decisions-and-target.md' => 'Architecture.Governance',
            'docs/internal/plans/modular-architecture/p0-governance.md' => 'Architecture.Governance',
            'docs/internal/plans/modular-architecture/p1-duplication.md' => 'Architecture.Governance',
            'docs/internal/plans/modular-architecture/p2-dependency-model.md' => 'Architecture.Governance',
            'docs/internal/plans/modular-architecture/p3-run-measurement-configuration.md' => 'Architecture.Governance',
            'docs/internal/plans/modular-architecture/p4-architecture-policy.md' => 'Architecture.Governance',
            'docs/internal/plans/modular-architecture/p5-computed-metrics.md' => 'Architecture.Governance',
            'docs/internal/plans/modular-architecture/roadmap-p5-p8.md' => 'Architecture.Governance',
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
        $target = 'Qualimetrix\\Infrastructure\\Rule\\ChannelDeclarationRegistry';
        $contractSibling = 'Qualimetrix\\Infrastructure\\Rule\\RuleRegistryInterface';
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
        $manifest = $this->replaceOwner($this->manifest(), 'Analysis.Evidence.Size', 'Seam.Synthetic');
        $fqcn = 'Qualimetrix\\Analysis\\Evidence\\Size\\ClassCountCollector';
        self::assertSame('Seam.Synthetic', $manifest['declarations'][$fqcn]['owner']);
        $manifest['enforcement_seams'][$fqcn] = [
            'semantic_owner' => 'Seam.Synthetic',
            'layer' => 'seam-synthetic',
            'closes_in' => 'P8',
        ];

        [$exitCode, $output] = $this->runWithManifest($manifest);

        self::assertNotSame(0, $exitCode);
        self::assertStringContainsString('generated qmx layer name collision on seam-synthetic', $output);
    }

    #[Test]
    public function itRejectsNonCanonicalSegmentedSeamLayerNames(): void
    {
        $manifest = $this->manifest();
        self::assertSame([], $manifest['enforcement_seams']);
        $fqcn = 'Qualimetrix\\Analysis\\Evidence\\Size\\ClassCountCollector';
        $manifest['enforcement_seams'][$fqcn] = [
            'semantic_owner' => $manifest['declarations'][$fqcn]['owner'],
            'layer' => 'seam-a--b',
            'closes_in' => 'P8',
        ];

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
        $fixture = $this->root() . '/tests/Analysis/Policy/Architecture/Fixtures/ModularTopologySample/TransientUndiscoveredTest.php';
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
        foreach ([
            "docs/adr/0001-computed-metrics.md\tAnalysis.Evidence.ComputedMetrics\tP5\t",
            "docs/internal/plans/modular-architecture/p5-computed-metrics.md\tArchitecture.Governance\tP2\t",
            "src/Analysis/Evidence/ComputedMetrics/README.md\tAnalysis.Evidence.ComputedMetrics\tP5\t",
            "website/docs/reference/health-scores.md\tAnalysis.Evidence.ComputedMetrics\tP5\t",
            "website/docs/reference/health-scores.ru.md\tAnalysis.Evidence.ComputedMetrics\tP5\t",
        ] as $authoritativeP5Row) {
            self::assertStringContainsString($authoritativeP5Row, $contents);
        }
    }

    #[Test]
    public function itKeepsTheModularArchitecturePlanSplitLinkedAndFinite(): void
    {
        $directory = $this->root() . '/docs/internal/plans/modular-architecture';
        $paths = glob($directory . '/*.md');
        self::assertIsArray($paths);
        $relativePaths = array_map(
            static fn(string $path): string => basename($path),
            $paths,
        );
        sort($relativePaths, \SORT_STRING);
        self::assertSame([
            'decisions-and-target.md',
            'p0-governance.md',
            'p1-duplication.md',
            'p2-dependency-model.md',
            'p3-run-measurement-configuration.md',
            'p4-architecture-policy.md',
            'p5-computed-metrics.md',
            'p6-finding-policy.md',
            'roadmap-p5-p8.md',
        ], $relativePaths);

        $overview = file_get_contents($this->root() . '/docs/internal/plans/modular-architecture.md');
        self::assertIsString($overview);
        foreach ($relativePaths as $path) {
            self::assertStringContainsString('modular-architecture/' . $path, $overview);
            $contents = file_get_contents($directory . '/' . $path);
            self::assertIsString($contents);
            self::assertStringContainsString('../modular-architecture.md', $contents, $path);
        }

        $p4 = file_get_contents($directory . '/p4-architecture-policy.md');
        self::assertIsString($p4);
        self::assertStringContainsString('### P4 — Isolate Architecture policy and its circular-dependency preparation', $p4);
        $p6 = file_get_contents($directory . '/p6-finding-policy.md');
        self::assertIsString($p6);
        foreach ([
            'p6/p6-production-ledger.md' => '9bb72814f69adc9cc18b9d813e37e99697d38b55baaecf6113f90531f8cb71a8',
            'p6/p6-test-ledger.md' => 'b4cfec682fc760b8eff54468b80602206f7b5c465bd16fa439cf625ced507a19',
            'p6/p6-relations-ledger.md' => 'df68971537a08b4d571fe577801b374d9eb1c7751d3fb37e6f533a8df4aaa080',
        ] as $relativePath => $expectedHash) {
            self::assertSame($expectedHash, hash_file('sha256', $directory . '/' . $relativePath));
            self::assertStringContainsString($expectedHash, $p6);
        }
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

    #[Test]
    public function itDoesNotExtendAPermanentExactP4ConsumerToAnotherDeclarationOfItsOwner(): void
    {
        $cases = [
            [
                'Qualimetrix\\Analysis\\Policy\\Architecture\\Contract\\ArchitecturePolicyConfiguratorInterface',
                'Qualimetrix\\Infrastructure\\Console\\RuntimeConfigurator',
                'Qualimetrix\\Infrastructure\\Console\\AnalysisRuntimeConfigurator',
            ],
            [
                'Qualimetrix\\Analysis\\Configuration\\Contract\\ConfigurationDocument',
                'Qualimetrix\\Analysis\\Policy\\Architecture\\ArchitecturePolicy',
                'Qualimetrix\\Analysis\\Policy\\Architecture\\LayerViolation\\LayerViolationRule',
            ],
        ];
        foreach ($cases as [$target, $actualSource, $unlistedSource]) {
            $manifest = $this->manifest();
            foreach ($manifest['declarations'][$target]['consumers'] as &$consumer) {
                if ($consumer['source_fqcn'] !== $actualSource) {
                    continue;
                }
                $consumer['source_fqcn'] = $unlistedSource;
            }
            unset($consumer);

            [$exitCode, $output] = $this->runWithManifest($manifest);

            self::assertNotSame(0, $exitCode);
            self::assertStringContainsString(
                'contract import ' . $actualSource . ' -> ' . $target . ' has 0 matching consumer entries',
                $output,
            );
        }
    }

    #[Test]
    public function itEncodesTheMaterializedP4TopologyAsCurrentAuthority(): void
    {
        $manifest = $this->manifest();
        self::assertArrayNotHasKey('p4_target', $manifest);
        self::assertCount(762, $manifest['declarations']);
        self::assertCount(760, array_unique(array_column($manifest['declarations'], 'path')));

        $architecture = array_filter(
            $manifest['declarations'],
            static fn(array $declaration): bool => $declaration['owner'] === 'Analysis.Policy.Architecture',
        );
        $circular = array_filter(
            $manifest['declarations'],
            static fn(array $declaration): bool => $declaration['owner'] === 'Analysis.Evidence.CircularDependency',
        );
        self::assertCount(57, $architecture);
        self::assertCount(7, $circular);

        $architectureZones = [];
        foreach ($architecture as $fqcn => $declaration) {
            self::assertStringStartsWith('Qualimetrix\\Analysis\\Policy\\Architecture\\', $fqcn);
            self::assertStringStartsWith('src/Analysis/Policy/Architecture/', $declaration['path']);
            self::assertSame('P4', $declaration['closure_package']);
            self::assertFileExists($this->root() . '/' . $declaration['path']);
            $zone = match (true) {
                str_contains($declaration['path'], '/Contract/') => 'Contract',
                str_contains($declaration['path'], '/Configuration/Allow/') => 'Configuration/Allow',
                str_contains($declaration['path'], '/Layer/Expansion/') => 'Layer/Expansion',
                str_contains($declaration['path'], '/LayerViolation/') => 'LayerViolation',
                str_ends_with($declaration['path'], '/ArchitecturePolicy.php') => 'ArchitecturePolicy',
                str_contains($declaration['path'], '/Configuration/') => 'Configuration',
                str_contains($declaration['path'], '/Layer/') => 'Layer',
                default => 'unknown',
            };
            $architectureZones[$zone] = ($architectureZones[$zone] ?? 0) + 1;
        }
        ksort($architectureZones, \SORT_STRING);
        $expectedArchitectureZones = [
            'ArchitecturePolicy' => 1,
            'Configuration' => 12,
            'Configuration/Allow' => 10,
            'Contract' => 8,
            'Layer' => 18,
            'Layer/Expansion' => 4,
            'LayerViolation' => 4,
        ];
        ksort($expectedArchitectureZones, \SORT_STRING);
        self::assertCount(\count($expectedArchitectureZones), $architectureZones);
        foreach ($expectedArchitectureZones as $zone => $expectedCount) {
            self::assertArrayHasKey($zone, $architectureZones);
            self::assertSame($expectedCount, $architectureZones[$zone]);
        }
        self::assertArrayNotHasKey('unknown', $architectureZones);
        foreach ([
            'Qualimetrix\\Architecture\\Processing\\ArchitectureLifecycleHook',
            'Qualimetrix\\Architecture\\Processing\\ArchitectureProcessor',
            'Qualimetrix\\Architecture\\Processing\\ArchitectureProcessorInterface',
            'Qualimetrix\\Core\\Dependency\\CycleInterface',
        ] as $removedDeclaration) {
            self::assertArrayNotHasKey($removedDeclaration, $manifest['declarations']);
        }

        foreach ($circular as $fqcn => $declaration) {
            self::assertStringStartsWith('Qualimetrix\\Analysis\\Evidence\\CircularDependency\\', $fqcn);
            self::assertStringStartsWith('src/Analysis/Evidence/CircularDependency/', $declaration['path']);
            self::assertSame('P4', $declaration['closure_package']);
            self::assertFileExists($this->root() . '/' . $declaration['path']);
        }
        $circularContract = $circular['Qualimetrix\\Analysis\\Evidence\\CircularDependency\\Contract\\CircularDependencyPreparationInterface'];
        self::assertSame('contract', $circularContract['visibility']);
        self::assertSame(
            [
                'Qualimetrix\\Analysis\\Run\\RuleProducerPreparation',
                'Qualimetrix\\Infrastructure\\DependencyInjection\\Configurator\\AnalysisConfigurator',
                'Qualimetrix\\Infrastructure\\DependencyInjection\\Configurator\\CircularDependencyConfigurator',
            ],
            array_column($circularContract['consumers'], 'source_fqcn'),
        );

        $expectedP4Consumers = [
            'ArchitectureConfigurationException' => [
                'Qualimetrix\\Infrastructure\\Console\\Command\\BaselineCommand',
                'Qualimetrix\\Infrastructure\\Console\\Command\\CheckCommand',
                'Qualimetrix\\Infrastructure\\Console\\Command\\Debug\\LayerAssignmentCommand',
            ],
            'ArchitectureConfigurationWarning' => [
                'Qualimetrix\\Infrastructure\\Console\\RuntimeConfigurator',
            ],
            'ArchitecturePolicyConfiguratorInterface' => [
                'Qualimetrix\\Infrastructure\\Console\\RuntimeConfigurator',
                'Qualimetrix\\Infrastructure\\DependencyInjection\\Configurator\\ArchitectureConfigurator',
                'Qualimetrix\\Infrastructure\\DependencyInjection\\Configurator\\OutputConfigurator',
            ],
            'ArchitecturePreparationException' => [
                'Qualimetrix\\Infrastructure\\Console\\Command\\BaselineCommand',
                'Qualimetrix\\Infrastructure\\Console\\Command\\CheckCommand',
                'Qualimetrix\\Infrastructure\\Console\\Command\\Debug\\LayerAssignmentCommand',
            ],
            'LayerAssignment' => [
                'Qualimetrix\\Infrastructure\\Console\\LayerAssignmentResolver',
            ],
            'LayerAssignmentInspectorInterface' => [
                'Qualimetrix\\Infrastructure\\Console\\LayerAssignmentResolver',
                'Qualimetrix\\Infrastructure\\DependencyInjection\\Configurator\\ArchitectureConfigurator',
            ],
            'LayerAssignmentMatch' => [
                'Qualimetrix\\Infrastructure\\Console\\Command\\Debug\\LayerAssignmentCommand',
            ],
            'LayerPolicyPreparationInterface' => [
                'Qualimetrix\\Analysis\\Run\\RuleProducerPreparation',
                'Qualimetrix\\Infrastructure\\DependencyInjection\\Configurator\\AnalysisConfigurator',
                'Qualimetrix\\Infrastructure\\DependencyInjection\\Configurator\\ArchitectureConfigurator',
            ],
        ];
        foreach ($expectedP4Consumers as $contract => $expectedSources) {
            $declaration = $architecture['Qualimetrix\\Analysis\\Policy\\Architecture\\Contract\\' . $contract];
            self::assertSame($expectedSources, array_column($declaration['consumers'], 'source_fqcn'), $contract);
        }
        $configurationDocument = $manifest['declarations']['Qualimetrix\\Analysis\\Configuration\\Contract\\ConfigurationDocument'];
        self::assertSame([
            'Qualimetrix\\Analysis\\Policy\\Architecture\\ArchitecturePolicy',
            'Qualimetrix\\Analysis\\Policy\\Architecture\\Contract\\ArchitecturePolicyConfiguratorInterface',
            'Qualimetrix\\Analysis\\Evidence\\ComputedMetrics\\ComputedMetricAnalysis',
            'Qualimetrix\\Analysis\\Evidence\\ComputedMetrics\\Configuration\\ComputedMetricContributionReader',
            'Qualimetrix\\Analysis\\Evidence\\ComputedMetrics\\Contract\\Configuration\\ComputedMetricConfiguratorInterface',
            'Qualimetrix\\Analysis\\Evidence\\Coupling\\CouplingAnalysis',
            'Qualimetrix\\Analysis\\Evidence\\Coupling\\Contract\\Configuration\\CouplingConfiguratorInterface',
        ], array_column($configurationDocument['consumers'], 'source_fqcn'));

        $p4EraRelations = [];
        foreach ($manifest['declarations'] as $target => $declaration) {
            if ($declaration['closure_package'] !== 'P4' || $declaration['visibility'] !== 'contract') {
                continue;
            }
            foreach ($declaration['consumers'] as $index => $consumer) {
                if (str_starts_with((string) $consumer['source_fqcn'], 'Qualimetrix\\Analysis\\Evidence\\ComputedMetrics\\')) {
                    continue;
                }
                $p4EraRelations[] = [$target, $index, $consumer['relation'] ?? 'import'];
                self::assertIsString($consumer['source_fqcn']);
                self::assertNull($consumer['closes_in']);
            }
        }
        self::assertCount(24, $p4EraRelations);
        self::assertCount(22, array_filter(
            $p4EraRelations,
            static fn(array $relation): bool => $relation[2] === 'import',
        ));
        self::assertCount(2, array_filter(
            $p4EraRelations,
            static fn(array $relation): bool => $relation[2] === 'contract_surface',
        ));

        foreach ([
            'Qualimetrix\\Analysis\\Configuration\\Contract\\Exception\\ConfigLoadException',
            'Qualimetrix\\Analysis\\Configuration\\Contract\\Pipeline\\DeferredWarning',
            'Qualimetrix\\Architecture\\Processing\\ArchitectureLifecycleHook',
        ] as $removedSeam) {
            self::assertArrayNotHasKey($removedSeam, $manifest['enforcement_seams']);
        }
        self::assertSame([], array_values(array_filter(
            $manifest['temporary_internal_grants'],
            static fn(array $grant): bool => $grant['closes_in'] === 'P4',
        )));
        self::assertSame([], array_values(array_filter(
            $manifest['temporary_internal_grants'],
            static fn(array $grant): bool => $grant['source_fqcn'] === 'Qualimetrix\\Infrastructure\\DependencyInjection\\Configurator\\ArchitectureConfigurator'
                && $grant['target_fqcn'] === 'Qualimetrix\\Analysis\\Policy\\Architecture\\ArchitecturePolicy',
        )));

        $topology = new ReflectionClass(\Qualimetrix\Tests\Analysis\Policy\Architecture\Unit\ArchitectureInternalTopologyTest::class);
        self::assertSame([
            'Contract' => [],
            'Configuration/Allow' => ['Contract'],
            'Layer' => ['Contract', 'Configuration/Allow'],
            'Configuration' => ['Contract', 'Configuration/Allow', 'Layer'],
            'Layer/Expansion' => ['Contract', 'Configuration', 'Configuration/Allow', 'Layer'],
            'ArchitecturePolicy' => ['Contract', 'Configuration', 'Layer', 'Layer/Expansion'],
            'LayerViolation' => ['Contract', 'ArchitecturePolicy', 'Configuration', 'Layer'],
        ], $topology->getConstant('ALLOWED'));
        self::assertTrue($topology->hasMethod('itRejectsInjectedReverseAndUnknownCrossZoneEdgesWithoutWildcardWidening'));

        $inventory = file($this->root() . '/docs/internal/generated/modular-architecture/test-ownership.tsv', \FILE_IGNORE_NEW_LINES | \FILE_SKIP_EMPTY_LINES);
        self::assertIsArray($inventory);
        $p4Classes = 0;
        $p4Ids = 0;
        $p4Fixtures = 0;
        $p4Supports = 0;
        foreach (\array_slice($inventory, 1) as $line) {
            $fields = str_getcsv($line, "\t", '"', '');
            if (($fields[9] ?? null) !== 'P4') {
                continue;
            }
            match ($fields[1]) {
                'phpunit-test-class' => [$p4Classes++, $p4Ids += (int) $fields[4]],
                'fixture' => $p4Fixtures++,
                'support' => $p4Supports++,
                default => null,
            };
        }
        $expectedP4Ids = 778
            + 6 // ArchitectureConfigurationFactory tests moved into P4 ownership
            + 2 // ArchitectureConfigurationWarningIntegrationTest moved into P4 ownership
            + 3 // ArchitectureInternalTopology regressions
            + 1 // ArchitectureProcessor net reset regression
            + 2 // CircularDependencyAnalysis regressions
            + 3; // P4-A governance assertions
        self::assertSame([43, $expectedP4Ids, 52, 4], [$p4Classes, $p4Ids, $p4Fixtures, $p4Supports]);
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
        $directory = sys_get_temp_dir() . '/qmx-governance-' . bin2hex(random_bytes(8));
        self::assertTrue(mkdir($directory));
        $qmxOutput = $directory . '/qmx.yaml';

        try {
            [$exitCode, $output] = $this->runCommand([
                \PHP_BINARY,
                $this->root() . '/scripts/generate-modular-architecture-production-inventory.php',
                '--output-directory=' . $directory,
                '--qmx-output=' . $qmxOutput,
            ]);
            self::assertSame(0, $exitCode, $output);

            return $this->runCommand([
                \PHP_BINARY,
                $this->root() . '/scripts/generate-modular-architecture-production-inventory.php',
                '--check',
                '--output-directory=' . $directory,
                '--qmx-source=' . $path,
                '--qmx-output=' . $qmxOutput,
            ]);
        } finally {
            unlink($path);
            $generatedPaths = glob($directory . '/*');
            foreach ($generatedPaths === false ? [] : $generatedPaths as $generatedPath) {
                unlink($generatedPath);
            }
            rmdir($directory);
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
        $manifest = $this->manifest();
        if ($manifest['enforcement_seams'] === []) {
            $manifest['enforcement_seams'] = (object) [];
        }
        file_put_contents(
            $manifestPath,
            json_encode(
                $manifest,
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
        if ($manifest['enforcement_seams'] === []) {
            $manifest['enforcement_seams'] = (object) [];
        }
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
        $root = realpath(__DIR__ . '/../../../../..');
        self::assertIsString($root);

        return $root;
    }
}
