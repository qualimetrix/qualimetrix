<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Analysis\Evidence\ComputedMetrics\Health\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Evidence\ComputedMetrics\Contract\Definition\ComputedMetricDefinitionCatalogInterface;
use Qualimetrix\Analysis\Evidence\ComputedMetrics\Health\Contract\Summary\HealthSummaryBuilder;
use Qualimetrix\Analysis\Evidence\ComputedMetrics\Health\Metadata\HealthMetricCatalog;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\MetricBag;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\NamespaceTree;
use Qualimetrix\Core\Path\RelativePath;
use Qualimetrix\Core\Symbol\SymbolInfo;
use Qualimetrix\Core\Symbol\SymbolPath;

#[CoversClass(HealthSummaryBuilder::class)]
final class HealthSummaryBuilderTest extends TestCase
{
    use MetricRepositoryTestHelper;

    #[Test]
    public function itEnrichesWithHealthScores(): void
    {
        $classPath = SymbolPath::forClass('App', 'Service');
        $metrics = $this->createMetricRepository(
            projectMetrics: MetricBag::fromArray([
                'health.complexity' => 65.0,
                'health.cohesion' => 45.0,
                'health.coupling' => 80.0,
                'health.typing' => 90.0,
                'health.maintainability' => 58.0,
                'health.overall' => 72.0,
                'complexity.ccn.avg' => 8.2,
                'complexity.cognitive.avg' => 6.1,
                'cohesion.tcc.avg' => 0.15,
                'cohesion.lcom.avg' => 4.0,
                // Written from the symbol list by every run; each health
                // coverage divides by one of them.
                'size.symbol-class-count' => 1,
                'size.symbol-method-count' => 2,
                'size.symbol-declaring-namespace-count' => 1,
            ]),
            classes: [new SymbolInfo($classPath, RelativePath::fromString('src/Service.php'), null)],
            classMetrics: [
                $classPath->toCanonical() => MetricBag::fromArray([
                    'complexity.ccn.sum' => 12,
                    'complexity.cognitive.sum' => 8,
                ]),
            ],
        );
        $builder = new HealthSummaryBuilder(
            new HealthMetricCatalog(),
            self::createStub(ComputedMetricDefinitionCatalogInterface::class),
        );

        $result = $builder->build($metrics, new NamespaceTree([]), []);

        self::assertCount(6, $result->healthScores);
        self::assertArrayHasKey('complexity', $result->healthScores);
        self::assertArrayHasKey('cohesion', $result->healthScores);
        self::assertArrayHasKey('overall', $result->healthScores);
        $complexity = $result->healthScores['complexity'];
        self::assertSame('complexity', $complexity->name);
        self::assertSame(65.0, $complexity->score);
        self::assertSame('Fair', $complexity->label);
        self::assertCount(2, $complexity->decomposition);
        self::assertSame('complexity.ccn.avg', $complexity->decomposition[0]->metricKey);
        self::assertSame('complexity.cognitive.avg', $complexity->decomposition[1]->metricKey);
        self::assertCount(1, $complexity->worstContributors);
        self::assertSame(
            ['complexity.ccn.sum' => 12, 'complexity.cognitive.sum' => 8],
            $complexity->worstContributors[0]->metricValues,
        );
        $cohesion = $result->healthScores['cohesion'];
        self::assertSame(45.0, $cohesion->score);
        self::assertSame('Poor', $cohesion->label);
        self::assertCount(2, $cohesion->decomposition);
        self::assertSame('cohesion.tcc.avg', $cohesion->decomposition[0]->metricKey);
        self::assertSame(0.15, $cohesion->decomposition[0]->value);
        self::assertSame('cohesion.lcom.avg', $cohesion->decomposition[1]->metricKey);
        self::assertSame('Fair', $result->healthScores['maintainability']->label);
    }

    /**
     * A container namespace holding no declarations of its own is not a worst
     * offender: the score it publishes is its subtree's, and ranking it beside
     * its own children double-counts them. The guard asked
     * `size.class-count.sum`, which is the subtree count and therefore positive
     * for exactly the containers it meant to exclude.
     */
    #[Test]
    public function itRanksANamespaceOnlyWhenItDeclaresClassesOfItsOwn(): void
    {
        $container = SymbolPath::forNamespace('Cont');
        $child = SymbolPath::forNamespace('Cont\\A');
        $metrics = $this->createMetricRepository(
            projectMetrics: MetricBag::fromArray(['health.overall' => 72.0]),
            namespaces: [
                new SymbolInfo($container, RelativePath::fromString('src/Cont'), null),
                new SymbolInfo($child, RelativePath::fromString('src/Cont/A'), null),
            ],
            namespaceMetrics: [
                // No own declarations: the subtree sum is all it has.
                'ns:Cont' => MetricBag::fromArray(['health.overall' => 40.0, 'size.class-count.sum' => 2]),
                'ns:Cont\\A' => MetricBag::fromArray(['health.overall' => 40.0, 'size.class-count' => 2, 'size.class-count.sum' => 2]),
            ],
        );

        $builder = new HealthSummaryBuilder(
            new HealthMetricCatalog(),
            self::createStub(ComputedMetricDefinitionCatalogInterface::class),
        );

        $ranked = array_map(
            static fn(object $offender): string => $offender->symbolPath->toCanonical(),
            $builder->build($metrics, new NamespaceTree(['Cont', 'Cont\\A']), [])->worstNamespaces,
        );

        self::assertSame(['ns:Cont\\A'], $ranked);
    }
}
