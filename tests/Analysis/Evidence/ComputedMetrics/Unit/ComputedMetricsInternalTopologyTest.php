<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Analysis\Evidence\ComputedMetrics\Unit;

use LogicException;
use PhpParser\Node;
use PhpParser\Node\Name;
use PhpParser\Node\Stmt\Namespace_;
use PhpParser\Node\Stmt\Use_;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NameResolver;
use PhpParser\NodeVisitorAbstract;
use PhpParser\ParserFactory;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

final class ComputedMetricsInternalTopologyTest extends TestCase
{
    private const string ROOT_PREFIX = 'Qualimetrix\\Analysis\\Evidence\\ComputedMetrics\\';
    private const string HEALTH_PREFIX = self::ROOT_PREFIX . 'Health\\';

    /** @var array<string, list<string>> */
    private const array ZONE_DAG = [
        'RootContract' => [],
        'RootInternal' => ['RootContract'],
        'HealthContract' => ['RootContract'],
        'HealthContractImplementation' => ['RootContract', 'HealthContract', 'HealthInternal'],
        'HealthInternal' => ['RootContract', 'HealthContract', 'HealthContractImplementation'],
        'Reporting' => ['RootContract', 'HealthContract', 'HealthContractImplementation'],
    ];

    /** @var list<array{string, string}> */
    private const array EXPECTED_RELATIONS = [
        ['Qualimetrix\\Analysis\\Evidence\\ComputedMetrics\\Contract\\Configuration\\ComputedMetricConfiguratorInterface', 'Qualimetrix\\Infrastructure\\Console\\AnalysisRuntimeConfigurator'],
        ['Qualimetrix\\Analysis\\Evidence\\ComputedMetrics\\Contract\\Configuration\\ComputedMetricConfiguratorInterface', 'Qualimetrix\\Infrastructure\\DependencyInjection\\Configurator\\OutputConfigurator'],
        ['Qualimetrix\\Analysis\\Evidence\\ComputedMetrics\\Contract\\Definition\\ResolvedComputedMetricDefinitions', 'Qualimetrix\\Infrastructure\\Console\\AnalysisRuntimeConfigurator'],
        ['Qualimetrix\\Analysis\\Evidence\\ComputedMetrics\\Contract\\Definition\\ResolvedComputedMetricDefinitions', 'Qualimetrix\\Infrastructure\\Console\\RuleInputValidator'],
        ['Qualimetrix\\Analysis\\Evidence\\ComputedMetrics\\Contract\\Definition\\ResolvedComputedMetricDefinitions', 'Qualimetrix\\Infrastructure\\Rule\\RuleChannelRegistry'],
        ['Qualimetrix\\Analysis\\Evidence\\ComputedMetrics\\Contract\\Definition\\ResolvedComputedMetricDefinitions', 'Qualimetrix\\Infrastructure\\Rule\\Contract\\RuleChannelSnapshotFactoryInterface'],
        ['Qualimetrix\\Analysis\\Evidence\\ComputedMetrics\\Contract\\Evaluation\\ComputedMetricEvaluator', 'Qualimetrix\\Analysis\\Run\\Pipeline\\AnalysisPipeline'],
        ['Qualimetrix\\Analysis\\Evidence\\ComputedMetrics\\Contract\\Definition\\ComputedMetricDefinitionCatalogInterface', 'Qualimetrix\\Infrastructure\\Rule\\ChannelDeclarationRegistry'],
        ['Qualimetrix\\Analysis\\Evidence\\ComputedMetrics\\Contract\\Definition\\ComputedMetricDefinitionCatalogInterface', 'Qualimetrix\\Reporting\\Formatter\\Html\\HtmlTreeBuilder'],
        ['Qualimetrix\\Analysis\\Evidence\\ComputedMetrics\\Contract\\Definition\\ComputedMetricDefinitionCatalogInterface', 'Qualimetrix\\Analysis\\Evidence\\ComputedMetrics\\Health\\Contract\\Summary\\HealthSummaryBuilder'],
        ['Qualimetrix\\Analysis\\Evidence\\ComputedMetrics\\Contract\\Definition\\ComputedMetricDefinitionCatalogInterface', 'Qualimetrix\\Analysis\\Evidence\\ComputedMetrics\\Health\\Contract\\DrillDown\\HealthScoreDrillDown'],
        ['Qualimetrix\\Analysis\\Evidence\\ComputedMetrics\\Contract\\Definition\\ComputedMetricDefinitionCatalogInterface', 'Qualimetrix\\Analysis\\Evidence\\ComputedMetrics\\Health\\Contract\\DrillDown\\WorstClassDrillDown'],
        ['Qualimetrix\\Analysis\\Evidence\\ComputedMetrics\\Contract\\Finding\\ComputedMetricChannelFamily', 'Qualimetrix\\Infrastructure\\DependencyInjection\\CompilerPass\\ChannelDeclarationCompilerPass'],
        ['Qualimetrix\\Analysis\\Evidence\\ComputedMetrics\\Contract\\Configuration\\HealthFormulaExclusionInterface', 'Qualimetrix\\Analysis\\Evidence\\ComputedMetrics\\Health\\Configuration\\HealthFormulaExcluder'],
        ['Qualimetrix\\Analysis\\Evidence\\ComputedMetrics\\Contract\\Definition\\ComputedMetricDefinition', 'Qualimetrix\\Analysis\\Evidence\\ComputedMetrics\\Health\\Configuration\\HealthFormulaExcluder'],
        ['Qualimetrix\\Analysis\\Evidence\\ComputedMetrics\\Contract\\Definition\\HealthDimension', 'Qualimetrix\\Reporting\\Formatter\\Html\\HtmlMetricAggregator'],
        ['Qualimetrix\\Analysis\\Evidence\\ComputedMetrics\\Contract\\Definition\\HealthDimension', 'Qualimetrix\\Reporting\\Formatter\\Json\\JsonHealthSection'],
        ['Qualimetrix\\Analysis\\Evidence\\ComputedMetrics\\Contract\\Definition\\HealthDimension', 'Qualimetrix\\Reporting\\Formatter\\Summary\\HealthBarRenderer'],
        ['Qualimetrix\\Analysis\\Evidence\\ComputedMetrics\\Contract\\Definition\\HealthDimension', 'Qualimetrix\\Analysis\\Evidence\\ComputedMetrics\\Health\\Configuration\\HealthFormulaExcluder'],
        ['Qualimetrix\\Analysis\\Evidence\\ComputedMetrics\\Contract\\Definition\\HealthDimension', 'Qualimetrix\\Analysis\\Evidence\\ComputedMetrics\\Health\\Contract\\Summary\\HealthSummaryBuilder'],
        ['Qualimetrix\\Analysis\\Evidence\\ComputedMetrics\\Contract\\Definition\\HealthDimension', 'Qualimetrix\\Analysis\\Evidence\\ComputedMetrics\\Health\\Contract\\DrillDown\\HealthScoreDrillDown'],
        ['Qualimetrix\\Analysis\\Evidence\\ComputedMetrics\\Contract\\Definition\\HealthDimension', 'Qualimetrix\\Analysis\\Evidence\\ComputedMetrics\\Health\\Contract\\DrillDown\\WorstClassDrillDown'],
        ['Qualimetrix\\Analysis\\Evidence\\ComputedMetrics\\Health\\Contract\\Score\\DecompositionItem', 'Qualimetrix\\Reporting\\Formatter\\Health\\HealthTextFormatter'],
        ['Qualimetrix\\Analysis\\Evidence\\ComputedMetrics\\Health\\Contract\\Score\\DecompositionItem', 'Qualimetrix\\Reporting\\Formatter\\Json\\JsonHealthSection'],
        ['Qualimetrix\\Analysis\\Evidence\\ComputedMetrics\\Health\\Contract\\Score\\DecompositionItem', 'Qualimetrix\\Reporting\\Formatter\\Summary\\HealthBarRenderer'],
        ['Qualimetrix\\Analysis\\Evidence\\ComputedMetrics\\Health\\Contract\\Score\\HealthContributor', 'Qualimetrix\\Reporting\\Formatter\\Json\\JsonHealthSection'],
        ['Qualimetrix\\Analysis\\Evidence\\ComputedMetrics\\Health\\Contract\\Score\\HealthScore', 'Qualimetrix\\Reporting\\Formatter\\Health\\HealthTextFormatter'],
        ['Qualimetrix\\Analysis\\Evidence\\ComputedMetrics\\Health\\Contract\\Score\\HealthScore', 'Qualimetrix\\Reporting\\Formatter\\Summary\\HealthBarRenderer'],
        ['Qualimetrix\\Analysis\\Evidence\\ComputedMetrics\\Health\\Contract\\DrillDown\\HealthScoreDrillDown', 'Qualimetrix\\Reporting\\Health\\HealthScoreResolver'],
        ['Qualimetrix\\Analysis\\Evidence\\ComputedMetrics\\Health\\Contract\\DrillDown\\WorstClassDrillDown', 'Qualimetrix\\Reporting\\Formatter\\Json\\JsonOffenderSection'],
        ['Qualimetrix\\Analysis\\Evidence\\ComputedMetrics\\Health\\Contract\\DrillDown\\WorstClassDrillDown', 'Qualimetrix\\Reporting\\Formatter\\Summary\\OffenderListRenderer'],
        ['Qualimetrix\\Analysis\\Evidence\\ComputedMetrics\\Health\\Contract\\Offender\\WorstOffender', 'Qualimetrix\\Reporting\\Filter\\ViolationFilter'],
        ['Qualimetrix\\Analysis\\Evidence\\ComputedMetrics\\Health\\Contract\\Offender\\WorstOffender', 'Qualimetrix\\Reporting\\Formatter\\Json\\JsonOffenderSection'],
        ['Qualimetrix\\Analysis\\Evidence\\ComputedMetrics\\Health\\Contract\\Offender\\WorstOffender', 'Qualimetrix\\Reporting\\Formatter\\Summary\\OffenderListRenderer'],
        ['Qualimetrix\\Analysis\\Evidence\\ComputedMetrics\\Health\\Contract\\Summary\\HealthSummaryBuilder', 'Qualimetrix\\Reporting\\Health\\SummaryEnricher'],
        ['Qualimetrix\\Analysis\\Evidence\\ComputedMetrics\\Health\\Contract\\Summary\\HealthSummary', 'Qualimetrix\\Reporting\\Health\\SummaryEnricher'],
        ['Qualimetrix\\Analysis\\Evidence\\ComputedMetrics\\Health\\Contract\\Metadata\\HealthMetricMetadataProviderInterface', 'Qualimetrix\\Reporting\\Health\\HealthHintProjector'],
        ['Qualimetrix\\Analysis\\Evidence\\ComputedMetrics\\Health\\Contract\\Metadata\\HealthMetricMetadataCollection', 'Qualimetrix\\Reporting\\Health\\HealthHintProjector'],
    ];

    /** @var list<array{string, string}> */
    private const array COMPOSED_CARRIER_RELATIONS = [
        ['Qualimetrix\\Analysis\\Evidence\\ComputedMetrics\\Health\\Contract\\Offender\\WorstOffender', 'Qualimetrix\\Reporting\\Report'],
        ['Qualimetrix\\Analysis\\Evidence\\ComputedMetrics\\Health\\Contract\\Score\\HealthContributor', 'Qualimetrix\\Reporting\\Formatter\\Health\\HealthTextFormatter'],
        ['Qualimetrix\\Analysis\\Evidence\\ComputedMetrics\\Health\\Contract\\Score\\HealthScore', 'Qualimetrix\\Reporting\\Formatter\\Json\\JsonHealthSection'],
        ['Qualimetrix\\Analysis\\Evidence\\ComputedMetrics\\Health\\Contract\\Score\\HealthScore', 'Qualimetrix\\Reporting\\Health\\HealthScoreResolver'],
        ['Qualimetrix\\Analysis\\Evidence\\ComputedMetrics\\Health\\Contract\\Score\\HealthScore', 'Qualimetrix\\Reporting\\Report'],
    ];

    #[Test]
    public function itAcceptsTheMaterializedInternalDag(): void
    {
        $declarations = $this->productionDeclarations();
        self::assertCount(38, $declarations);

        foreach ($declarations as $source => $path) {
            $sourceZone = $this->zone($source);
            foreach ($this->imports($path) as $target) {
                if (!isset($declarations[$target])) {
                    continue;
                }

                self::assertTrue($this->allows($sourceZone, $this->zone($target)), "$source cannot import $target");
            }
        }

        $relations = [];
        foreach ($this->projectDeclarations() as $source => $path) {
            foreach ($this->imports($path) as $target) {
                if (isset($declarations[$target])
                    && str_contains($target, '\\Contract\\')
                    && $this->relationOwner($source) !== $this->relationOwner($target)) {
                    $relations[] = [$target, $source];
                }
            }
        }

        sort($relations);
        $expected = [...self::EXPECTED_RELATIONS, ...self::COMPOSED_CARRIER_RELATIONS];
        sort($expected);
        self::assertSame($expected, $relations, 'Every raw cross-owner Contract import must be explicitly classified.');
        self::assertCount(43, $relations);
        self::assertCount(38, self::EXPECTED_RELATIONS);
        self::assertCount(22, array_filter(self::EXPECTED_RELATIONS, static fn(array $relation): bool => !str_contains($relation[0], '\\Health\\Contract\\')));
        self::assertCount(16, array_filter(self::EXPECTED_RELATIONS, static fn(array $relation): bool => str_contains($relation[0], '\\Health\\Contract\\')));

        $source = implode("\n", array_map(static fn(string $path): string => (string) file_get_contents($path), $declarations));
        $obsoleteNames = [
            'ComputedMetricDefinition' . 'Holder',
            'TransitionalMetric' . 'Enricher',
            'TransitionalEnrichment' . 'Result',
            'Qualimetrix\\Configuration\\ComputedMetric',
            'Qualimetrix\\Rules\\ComputedMetric',
        ];
        foreach ($obsoleteNames as $obsolete) {
            self::assertStringNotContainsString($obsolete, $source);
        }

        $projectDeclarations = $this->projectDeclarations();
        self::assertNotContains(
            'Qualimetrix\\Analysis\\Evidence\\ComputedMetrics\\ComputedMetricRule',
            $this->imports($projectDeclarations['Qualimetrix\\Infrastructure\\DependencyInjection\\CompilerPass\\ChannelDeclarationCompilerPass']),
        );
        self::assertNotContains(
            'Qualimetrix\\Analysis\\Evidence\\ComputedMetrics\\ComputedMetricFormulaValidator',
            $this->imports($projectDeclarations['Qualimetrix\\Infrastructure\\DependencyInjection\\Configurator\\OutputConfigurator']),
        );
    }

    #[Test]
    public function itRejectsAReverseEdge(): void
    {
        self::assertFalse($this->allows('RootContract', 'RootInternal'));
        self::assertFalse($this->allows('HealthContract', 'HealthInternal'));
        self::assertFalse($this->allows('HealthInternal', 'RootInternal'));
        self::assertFalse($this->allows('RootInternal', 'HealthContract'));
    }

    #[Test]
    public function itRejectsUnknownAndCrossOwnerInternalEdges(): void
    {
        self::assertArrayNotHasKey('*', self::ZONE_DAG);
        foreach (self::ZONE_DAG as $allowed) {
            self::assertNotContains('*', $allowed);
        }
        self::assertNotContains([
            self::ROOT_PREFIX . 'Contract\\Definition\\HealthDimension',
            self::HEALTH_PREFIX . 'Metadata\\HealthMetricCatalog',
        ], self::EXPECTED_RELATIONS, 'A DAG-valid but undeclared relation must not be silently accepted.');

        $this->expectException(LogicException::class);
        $this->zone('Qualimetrix\\Analysis\\Evidence\\ComputedMetrics\\Future\\Unknown');
    }

    private function relationOwner(string $fqcn): string
    {
        if (str_starts_with($fqcn, self::HEALTH_PREFIX)) {
            return 'Health';
        }
        if (str_starts_with($fqcn, self::ROOT_PREFIX)) {
            return 'Root';
        }

        return 'External';
    }

    /** @return array<string, string> */
    private function productionDeclarations(): array
    {
        $root = $this->repositoryRoot();
        $paths = $this->phpFiles($root . '/src/Analysis/Evidence/ComputedMetrics');
        $paths = array_merge($paths, $this->phpFiles($root . '/src/Reporting/Health'));
        $declarations = [];
        foreach ($paths as $path) {
            $fqcn = $this->declaration($path);
            if ($fqcn !== null) {
                $declarations[$fqcn] = $path;
            }
        }

        ksort($declarations);

        return $declarations;
    }

    /** @return array<string, string> */
    private function projectDeclarations(): array
    {
        $declarations = [];
        foreach ($this->phpFiles($this->repositoryRoot() . '/src') as $path) {
            $fqcn = $this->declaration($path);
            if ($fqcn !== null) {
                $declarations[$fqcn] = $path;
            }
        }

        return $declarations;
    }

    /** @return list<string> */
    private function phpFiles(string $directory): array
    {
        $paths = [];
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory));
        foreach ($iterator as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                $paths[] = $file->getPathname();
            }
        }

        return $paths;
    }

    private function declaration(string $path): ?string
    {
        $nodes = $this->parse($path);
        foreach ($nodes as $node) {
            if (!$node instanceof Namespace_) {
                continue;
            }
            foreach ($node->stmts as $statement) {
                if ($statement instanceof Node\Stmt\ClassLike && $statement->name !== null) {
                    return $node->name?->toString() . '\\' . $statement->name->toString();
                }
            }
        }

        return null;
    }

    /** @return list<string> */
    private function imports(string $path): array
    {
        $nodes = $this->parse($path);
        $traverser = new NodeTraverser();
        $traverser->addVisitor(new NameResolver());
        $collector = new class extends NodeVisitorAbstract {
            /** @var array<string, true> */
            public array $imports = [];

            public function enterNode(Node $node): null
            {
                if ($node instanceof Use_) {
                    foreach ($node->uses as $use) {
                        $this->imports[$use->name->toString()] = true;
                    }
                } elseif ($node instanceof Name) {
                    $resolved = $node->getAttribute('resolvedName');
                    if ($resolved instanceof Name) {
                        $this->imports[$resolved->toString()] = true;
                    }
                }

                return null;
            }
        };
        $traverser->addVisitor($collector);
        $traverser->traverse($nodes);

        return array_keys($collector->imports);
    }

    /** @return list<Node\Stmt> */
    private function parse(string $path): array
    {
        $nodes = (new ParserFactory())->createForNewestSupportedVersion()->parse((string) file_get_contents($path));
        if ($nodes === null) {
            throw new LogicException('Unable to parse ' . $path);
        }

        return array_values($nodes);
    }

    private function zone(string $fqcn): string
    {
        if (str_starts_with($fqcn, 'Qualimetrix\\Reporting\\')) {
            return 'Reporting';
        }
        if (str_starts_with($fqcn, self::HEALTH_PREFIX . 'Contract\\')) {
            if (\in_array($fqcn, [
                self::HEALTH_PREFIX . 'Contract\\DrillDown\\HealthScoreDrillDown',
                self::HEALTH_PREFIX . 'Contract\\DrillDown\\WorstClassDrillDown',
                self::HEALTH_PREFIX . 'Contract\\Offender\\WorstOffender',
                self::HEALTH_PREFIX . 'Contract\\Summary\\HealthSummary',
                self::HEALTH_PREFIX . 'Contract\\Summary\\HealthSummaryBuilder',
            ], true)) {
                return 'HealthContractImplementation';
            }

            return 'HealthContract';
        }
        if (str_starts_with($fqcn, self::HEALTH_PREFIX)) {
            $relative = substr($fqcn, \strlen(self::HEALTH_PREFIX));
            $subject = strstr($relative, '\\', true);
            if (!\in_array($subject, ['Configuration', 'Metadata', 'Offender', 'Score'], true)
                || substr_count($relative, '\\') !== 1) {
                throw new LogicException('Unknown ComputedMetrics Health zone: ' . $fqcn);
            }

            return 'HealthInternal';
        }
        if (str_starts_with($fqcn, self::ROOT_PREFIX . 'Contract\\')) {
            if ($fqcn === self::ROOT_PREFIX . 'Contract\\Evaluation\\ComputedMetricEvaluator') {
                return 'RootInternal';
            }

            return 'RootContract';
        }
        if (str_starts_with($fqcn, self::ROOT_PREFIX . 'Configuration\\')
            || str_starts_with($fqcn, self::ROOT_PREFIX . 'Finding\\')) {
            $relative = substr($fqcn, \strlen(self::ROOT_PREFIX));
            if (substr_count($relative, '\\') !== 1) {
                throw new LogicException('Unknown ComputedMetrics root zone: ' . $fqcn);
            }

            return 'RootInternal';
        }
        if (str_starts_with($fqcn, self::ROOT_PREFIX)) {
            if (str_contains(substr($fqcn, \strlen(self::ROOT_PREFIX)), '\\')) {
                throw new LogicException('Unknown ComputedMetrics root zone: ' . $fqcn);
            }

            return 'RootInternal';
        }

        throw new LogicException('Unknown ComputedMetrics topology declaration: ' . $fqcn);
    }

    private function allows(string $source, string $target): bool
    {
        return $source === $target || \in_array($target, self::ZONE_DAG[$source] ?? [], true);
    }

    private function repositoryRoot(): string
    {
        $root = realpath(__DIR__ . '/../../../../../');
        self::assertIsString($root);

        return $root;
    }
}
