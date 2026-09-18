<?php

declare(strict_types=1);

namespace Qualimetrix\Governance\ModularOwnership;

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

/**
 * The ComputedMetrics leaf's own import direction, which owner-level policy
 * cannot state.
 *
 * This file used to carry a second thing as well: a hand-written list of every
 * cross-owner import of a ComputedMetrics contract, with its own pinned counts.
 * That list is the manifest's `consumers` field, and `composer
 * architecture:check` already enforces it in both directions — an observed
 * cross-owner import with no matching consumer entry fails as "unapproved
 * exact internal import" or "contract import ... has N matching consumer
 * entries", and a consumer entry no import uses fails as "unused contract
 * consumer entry" ({@see \Qualimetrix\Governance\ModularOwnership\ModularArchitectureManifest},
 * `validateAuthorizations()` in
 * `scripts/generate-modular-architecture-production-inventory.php`). Keeping a
 * copy here meant a second place to edit for every contract consumer the
 * project gains.
 *
 * What stays is what the manifest does not express: `ComputedMetrics` and
 * `ComputedMetrics.Health` are owners, and the checker skips a pair that shares
 * one — so nothing outside this file says that a root Contract may not import
 * root Internal.
 */
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

    #[Test]
    public function itAcceptsTheMaterializedInternalDag(): void
    {
        $declarations = $this->productionDeclarations();
        self::assertCount(52, $declarations);

        foreach ($declarations as $source => $path) {
            $sourceZone = $this->zone($source);
            foreach ($this->imports($path) as $target) {
                if (!isset($declarations[$target])) {
                    continue;
                }

                self::assertTrue($this->allows($sourceZone, $this->zone($target)), "$source cannot import $target");
            }
        }

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
        $this->expectException(LogicException::class);
        $this->zone('Qualimetrix\\Analysis\\Evidence\\ComputedMetrics\\Future\\Unknown');
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
        $root = realpath(__DIR__ . '/../../');
        self::assertIsString($root);

        return $root;
    }
}
