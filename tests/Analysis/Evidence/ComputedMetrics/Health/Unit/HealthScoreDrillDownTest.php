<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Analysis\Evidence\ComputedMetrics\Health\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Evidence\ComputedMetrics\Contract\Definition\ComputedMetricDefinitionCatalogInterface;
use Qualimetrix\Analysis\Evidence\ComputedMetrics\Health\Contract\DrillDown\HealthScoreDrillDown;
use Qualimetrix\Analysis\Evidence\ComputedMetrics\Health\Metadata\HealthMetricCatalog;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\MetricBag;
use Qualimetrix\Core\Path\RelativePath;
use Qualimetrix\Core\Symbol\SymbolInfo;
use Qualimetrix\Core\Symbol\SymbolPath;

#[CoversClass(HealthScoreDrillDown::class)]
final class HealthScoreDrillDownTest extends TestCase
{
    use MetricRepositoryTestHelper;

    private HealthScoreDrillDown $drillDown;

    protected function setUp(): void
    {
        $this->drillDown = new HealthScoreDrillDown(new HealthMetricCatalog(), self::createStub(ComputedMetricDefinitionCatalogInterface::class));
    }

    // --- buildSubtreeHealthScores ---

    #[Test]
    public function itSubtreeHealthScoresReturnsEmptyForNoMatchingNamespaces(): void
    {
        $metrics = $this->createMetricRepository(
            projectMetrics: new MetricBag(),
            namespaces: [
                new SymbolInfo(SymbolPath::forNamespace('App\\Other'), RelativePath::fromString('src/Other.php'), 1),
            ],
            namespaceMetrics: [
                'ns:App\\Other' => MetricBag::fromArray([
                    'health.complexity' => 80.0,
                    'health.overall' => 75.0,
                    'classCount.sum' => 5,
                ]),
            ],
        );

        $result = $this->drillDown->buildSubtreeHealthScores($metrics, 'App\\Service');

        self::assertSame([], $result);
    }

    #[Test]
    public function itSubtreeHealthScoresMatchesExactNamespace(): void
    {
        $classPath = SymbolPath::forClass('App\Service', 'Worker');
        $metrics = $this->createMetricRepository(
            projectMetrics: new MetricBag(),
            namespaces: [
                new SymbolInfo(SymbolPath::forNamespace('App\\Service'), RelativePath::fromString('src/Service.php'), 1),
            ],
            namespaceMetrics: [
                'ns:App\\Service' => MetricBag::fromArray([
                    'health.complexity' => 80.0,
                    'health.overall' => 75.0,
                    'classCount.sum' => 3,
                ]),
            ],
            classes: [new SymbolInfo($classPath, RelativePath::fromString('src/Service/Worker.php'), null)],
            classMetrics: [
                $classPath->toCanonical() => MetricBag::fromArray([
                    'ccn.sum' => 9,
                    'cognitive.sum' => 6,
                ]),
            ],
        );

        $result = $this->drillDown->buildSubtreeHealthScores($metrics, 'App\\Service');

        self::assertArrayHasKey('complexity', $result);
        self::assertArrayHasKey('overall', $result);
        self::assertEqualsWithDelta(80.0, $result['complexity']->score, 0.01);
        self::assertEqualsWithDelta(75.0, $result['overall']->score, 0.01);
        self::assertCount(1, $result['complexity']->worstContributors);
        self::assertSame(
            ['ccn.sum' => 9, 'cognitive.sum' => 6],
            $result['complexity']->worstContributors[0]->metricValues,
        );
    }

    #[Test]
    public function itSubtreeHealthScoresIgnoreATrailingBackslashInTheSelector(): void
    {
        $classPath = SymbolPath::forClass('App\Service', 'Worker');
        $metrics = $this->createMetricRepository(
            projectMetrics: new MetricBag(),
            namespaces: [
                new SymbolInfo(SymbolPath::forNamespace('App\\Service'), RelativePath::fromString('src/Service.php'), 1),
            ],
            namespaceMetrics: [
                'ns:App\\Service' => MetricBag::fromArray([
                    'health.complexity' => 80.0,
                    'classCount.sum' => 3,
                ]),
            ],
            classes: [new SymbolInfo($classPath, RelativePath::fromString('src/Service/Worker.php'), null)],
            classMetrics: [
                $classPath->toCanonical() => MetricBag::fromArray(['ccn.sum' => 9]),
            ],
        );

        $result = $this->drillDown->buildSubtreeHealthScores($metrics, 'App\\Service\\');

        self::assertArrayHasKey('complexity', $result);
        self::assertCount(1, $result['complexity']->worstContributors);
    }

    #[Test]
    public function itSubtreeHealthScoresMatchesChildNamespaces(): void
    {
        $metrics = $this->createMetricRepository(
            projectMetrics: new MetricBag(),
            namespaces: [
                new SymbolInfo(SymbolPath::forNamespace('App\\Service\\Payment'), RelativePath::fromString('src/Service/Payment.php'), 1),
            ],
            namespaceMetrics: [
                'ns:App\\Service\\Payment' => MetricBag::fromArray([
                    'health.complexity' => 90.0,
                    'classCount.sum' => 2,
                ]),
            ],
        );

        $result = $this->drillDown->buildSubtreeHealthScores($metrics, 'App\\Service');

        self::assertArrayHasKey('complexity', $result);
        self::assertEqualsWithDelta(90.0, $result['complexity']->score, 0.01);
    }

    #[Test]
    public function itSubtreeHealthScoresDoesNotMatchSimilarPrefix(): void
    {
        // App\ServiceManager should NOT match prefix App\Service
        $metrics = $this->createMetricRepository(
            projectMetrics: new MetricBag(),
            namespaces: [
                new SymbolInfo(SymbolPath::forNamespace('App\\ServiceManager'), RelativePath::fromString('src/ServiceManager.php'), 1),
            ],
            namespaceMetrics: [
                'ns:App\\ServiceManager' => MetricBag::fromArray([
                    'health.complexity' => 90.0,
                    'classCount.sum' => 2,
                ]),
            ],
        );

        $result = $this->drillDown->buildSubtreeHealthScores($metrics, 'App\\Service');

        self::assertSame([], $result);
    }

    #[Test]
    public function itSubtreeHealthScoresWeightedAverageAcrossNamespaces(): void
    {
        $metrics = $this->createMetricRepository(
            projectMetrics: new MetricBag(),
            namespaces: [
                new SymbolInfo(SymbolPath::forNamespace('App\\Service'), RelativePath::fromString('src/Service.php'), 1),
                new SymbolInfo(SymbolPath::forNamespace('App\\Service\\Sub'), RelativePath::fromString('src/Service/Sub.php'), 1),
            ],
            namespaceMetrics: [
                'ns:App\\Service' => MetricBag::fromArray([
                    'health.complexity' => 100.0,
                    'classCount.sum' => 1,
                ]),
                'ns:App\\Service\\Sub' => MetricBag::fromArray([
                    'health.complexity' => 50.0,
                    'classCount.sum' => 3,
                ]),
            ],
        );

        $result = $this->drillDown->buildSubtreeHealthScores($metrics, 'App\\Service');

        // Weighted avg: (100*1 + 50*3) / (1+3) = 250/4 = 62.5
        self::assertArrayHasKey('complexity', $result);
        self::assertEqualsWithDelta(62.5, $result['complexity']->score, 0.01);
    }

    #[Test]
    public function itSubtreeHealthScoresUsesMinimumClassCountOfOne(): void
    {
        $metrics = $this->createMetricRepository(
            projectMetrics: new MetricBag(),
            namespaces: [
                new SymbolInfo(SymbolPath::forNamespace('App\\Service'), RelativePath::fromString('src/Service.php'), 1),
            ],
            namespaceMetrics: [
                'ns:App\\Service' => MetricBag::fromArray([
                    'health.complexity' => 80.0,
                    'classCount.sum' => 0, // zero class count -> treated as 1
                ]),
            ],
        );

        $result = $this->drillDown->buildSubtreeHealthScores($metrics, 'App\\Service');

        self::assertArrayHasKey('complexity', $result);
        self::assertEqualsWithDelta(80.0, $result['complexity']->score, 0.01);
    }

    // --- buildWorstClasses ---

    // --- buildClassHealthScores ---

    #[Test]
    public function itBuildClassHealthScoresReturnsEmptyWhenClassNotFound(): void
    {
        $metrics = $this->createMetricRepository(
            projectMetrics: new MetricBag(),
            classes: [],
        );

        $result = $this->drillDown->buildClassHealthScores($metrics, 'App\\Service\\NonExistent');

        self::assertSame([], $result);
    }

    #[Test]
    public function itBuildClassHealthScoresReturnsDimensionScores(): void
    {
        $classPath = SymbolPath::forClass('App\\Service', 'UserService');

        $metrics = $this->createMetricRepository(
            projectMetrics: new MetricBag(),
            classes: [
                new SymbolInfo($classPath, RelativePath::fromString('src/Service/UserService.php'), 1),
            ],
            classMetrics: [
                'class:App\\Service\\UserService' => MetricBag::fromArray([
                    'health.complexity' => 85.0,
                    'health.cohesion' => 70.0,
                    'health.coupling' => 90.0,
                    'health.typing' => 95.0,
                    'health.maintainability' => 80.0,
                    'health.overall' => 82.0,
                ]),
            ],
        );

        $result = $this->drillDown->buildClassHealthScores($metrics, 'App\\Service\\UserService');

        self::assertArrayHasKey('complexity', $result);
        self::assertArrayHasKey('cohesion', $result);
        self::assertArrayHasKey('coupling', $result);
        self::assertArrayHasKey('typing', $result);
        self::assertArrayHasKey('maintainability', $result);
        self::assertArrayHasKey('overall', $result);

        self::assertEqualsWithDelta(85.0, $result['complexity']->score, 0.01);
        self::assertEqualsWithDelta(82.0, $result['overall']->score, 0.01);
    }

    #[Test]
    public function itBuildClassHealthScoresSkipsMissingDimensions(): void
    {
        $classPath = SymbolPath::forClass('App\\Service', 'Partial');

        $metrics = $this->createMetricRepository(
            projectMetrics: new MetricBag(),
            classes: [
                new SymbolInfo($classPath, RelativePath::fromString('src/Service/Partial.php'), 1),
            ],
            classMetrics: [
                'class:App\\Service\\Partial' => MetricBag::fromArray([
                    'health.complexity' => 80.0,
                    // Other dimensions missing
                ]),
            ],
        );

        $result = $this->drillDown->buildClassHealthScores($metrics, 'App\\Service\\Partial');

        self::assertArrayHasKey('complexity', $result);
        self::assertCount(1, $result);
    }

    #[Test]
    public function itBuildClassHealthScoresMatchesGlobalClass(): void
    {
        $classPath = SymbolPath::forClass('', 'GlobalClass');

        $metrics = $this->createMetricRepository(
            projectMetrics: new MetricBag(),
            classes: [
                new SymbolInfo($classPath, RelativePath::fromString('src/GlobalClass.php'), 1),
            ],
            classMetrics: [
                'class:GlobalClass' => MetricBag::fromArray([
                    'health.overall' => 50.0,
                ]),
            ],
        );

        $result = $this->drillDown->buildClassHealthScores($metrics, 'GlobalClass');

        self::assertArrayHasKey('overall', $result);
        self::assertEqualsWithDelta(50.0, $result['overall']->score, 0.01);
    }
}
