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
}
