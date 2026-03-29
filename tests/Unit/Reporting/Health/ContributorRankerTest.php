<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Unit\Reporting\Health;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Core\Metric\MetricBag;
use Qualimetrix\Core\Symbol\SymbolInfo;
use Qualimetrix\Core\Symbol\SymbolPath;
use Qualimetrix\Reporting\Health\ContributorRanker;
use Qualimetrix\Reporting\Health\MetricHintProvider;

#[CoversClass(ContributorRanker::class)]
final class ContributorRankerTest extends TestCase
{
    use MetricRepositoryTestHelper;

    private ContributorRanker $ranker;

    protected function setUp(): void
    {
        $this->ranker = new ContributorRanker(new MetricHintProvider());
    }

    public function testReturnsEmptyForZeroLimit(): void
    {
        $metrics = $this->createMetricRepository(new MetricBag());

        $result = $this->ranker->rank('health.complexity', $metrics, [], 0);

        self::assertSame([], $result);
    }

    public function testReturnsEmptyForNegativeLimit(): void
    {
        $metrics = $this->createMetricRepository(new MetricBag());

        $result = $this->ranker->rank('health.complexity', $metrics, [], -1);

        self::assertSame([], $result);
    }

    public function testReturnsEmptyForUnknownDimension(): void
    {
        $metrics = $this->createMetricRepository(new MetricBag());

        $result = $this->ranker->rank('health.nonexistent', $metrics, []);

        self::assertSame([], $result);
    }

    public function testReturnsEmptyWhenNoClassSymbols(): void
    {
        $metrics = $this->createMetricRepository(new MetricBag());

        $result = $this->ranker->rank('health.complexity', $metrics, []);

        self::assertSame([], $result);
    }

    public function testSkipsClassesWithoutPrimaryMetric(): void
    {
        $classPath = SymbolPath::forClass('App', 'Empty');
        $classSymbol = new SymbolInfo($classPath, 'src/Empty.php', null);

        $metrics = $this->createMetricRepository(
            projectMetrics: new MetricBag(),
            classes: [$classSymbol],
            classMetrics: [$classPath->toCanonical() => new MetricBag()],
        );

        $result = $this->ranker->rank('health.complexity', $metrics, [$classSymbol]);

        self::assertSame([], $result);
    }

    public function testRanksClassesByPrimaryMetricDescendingForLowerIsBetter(): void
    {
        // Complexity: primary metric is ccn.sum (altKey), direction = lower, so worst = highest
        $classA = SymbolPath::forClass('App', 'ClassA');
        $classB = SymbolPath::forClass('App', 'ClassB');
        $classC = SymbolPath::forClass('App', 'ClassC');

        $symbolA = new SymbolInfo($classA, 'src/ClassA.php', null);
        $symbolB = new SymbolInfo($classB, 'src/ClassB.php', null);
        $symbolC = new SymbolInfo($classC, 'src/ClassC.php', null);

        $metrics = $this->createMetricRepository(
            projectMetrics: new MetricBag(),
            classes: [$symbolA, $symbolB, $symbolC],
            classMetrics: [
                $classA->toCanonical() => (new MetricBag())->with('ccn.sum', 5)->with('cognitive.sum', 3),
                $classB->toCanonical() => (new MetricBag())->with('ccn.sum', 15)->with('cognitive.sum', 10),
                $classC->toCanonical() => (new MetricBag())->with('ccn.sum', 10)->with('cognitive.sum', 7),
            ],
        );

        $result = $this->ranker->rank(
            'health.complexity',
            $metrics,
            [$symbolA, $symbolB, $symbolC],
        );

        self::assertCount(3, $result);
        // Worst first: ClassB(15) > ClassC(10) > ClassA(5)
        self::assertSame('ClassB', $result[0]->className);
        self::assertSame('ClassC', $result[1]->className);
        self::assertSame('ClassA', $result[2]->className);
    }

    public function testRanksClassesByPrimaryMetricAscendingForHigherIsBetter(): void
    {
        // Cohesion: primary metric is tcc (altKey), direction = higher, so worst = lowest
        $classA = SymbolPath::forClass('App', 'ClassA');
        $classB = SymbolPath::forClass('App', 'ClassB');

        $symbolA = new SymbolInfo($classA, 'src/ClassA.php', null);
        $symbolB = new SymbolInfo($classB, 'src/ClassB.php', null);

        $metrics = $this->createMetricRepository(
            projectMetrics: new MetricBag(),
            classes: [$symbolA, $symbolB],
            classMetrics: [
                $classA->toCanonical() => (new MetricBag())->with('tcc', 0.8)->with('lcom', 1),
                $classB->toCanonical() => (new MetricBag())->with('tcc', 0.2)->with('lcom', 4),
            ],
        );

        $result = $this->ranker->rank(
            'health.cohesion',
            $metrics,
            [$symbolA, $symbolB],
        );

        self::assertCount(2, $result);
        // Worst first for higher_is_better: ClassB(0.2) < ClassA(0.8)
        self::assertSame('ClassB', $result[0]->className);
        self::assertSame('ClassA', $result[1]->className);
    }

    public function testRespectsLimit(): void
    {
        $classA = SymbolPath::forClass('App', 'ClassA');
        $classB = SymbolPath::forClass('App', 'ClassB');
        $classC = SymbolPath::forClass('App', 'ClassC');

        $symbolA = new SymbolInfo($classA, 'src/ClassA.php', null);
        $symbolB = new SymbolInfo($classB, 'src/ClassB.php', null);
        $symbolC = new SymbolInfo($classC, 'src/ClassC.php', null);

        $metrics = $this->createMetricRepository(
            projectMetrics: new MetricBag(),
            classes: [$symbolA, $symbolB, $symbolC],
            classMetrics: [
                $classA->toCanonical() => (new MetricBag())->with('ccn.sum', 5),
                $classB->toCanonical() => (new MetricBag())->with('ccn.sum', 15),
                $classC->toCanonical() => (new MetricBag())->with('ccn.sum', 10),
            ],
        );

        $result = $this->ranker->rank(
            'health.complexity',
            $metrics,
            [$symbolA, $symbolB, $symbolC],
            limit: 2,
        );

        self::assertCount(2, $result);
        self::assertSame('ClassB', $result[0]->className);
        self::assertSame('ClassC', $result[1]->className);
    }

    public function testTiedPrimaryMetricSortsByClassName(): void
    {
        $classA = SymbolPath::forClass('App', 'Alpha');
        $classB = SymbolPath::forClass('App', 'Beta');

        $symbolA = new SymbolInfo($classA, 'src/Alpha.php', null);
        $symbolB = new SymbolInfo($classB, 'src/Beta.php', null);

        $metrics = $this->createMetricRepository(
            projectMetrics: new MetricBag(),
            classes: [$symbolA, $symbolB],
            classMetrics: [
                $classA->toCanonical() => (new MetricBag())->with('ccn.sum', 10),
                $classB->toCanonical() => (new MetricBag())->with('ccn.sum', 10),
            ],
        );

        $result = $this->ranker->rank(
            'health.complexity',
            $metrics,
            [$symbolA, $symbolB],
        );

        self::assertCount(2, $result);
        // Tied primary value -> sorted by class name alphabetically
        self::assertSame('Alpha', $result[0]->className);
        self::assertSame('Beta', $result[1]->className);
    }

    public function testContributorIncludesAllDecompositionMetrics(): void
    {
        $classPath = SymbolPath::forClass('App', 'Service');
        $symbol = new SymbolInfo($classPath, 'src/Service.php', null);

        $metrics = $this->createMetricRepository(
            projectMetrics: new MetricBag(),
            classes: [$symbol],
            classMetrics: [
                $classPath->toCanonical() => (new MetricBag())
                    ->with('ccn.sum', 12)
                    ->with('cognitive.sum', 8),
            ],
        );

        $result = $this->ranker->rank('health.complexity', $metrics, [$symbol]);

        self::assertCount(1, $result);
        $contributor = $result[0];
        self::assertSame('Service', $contributor->className);
        // metricValues should include the class-level keys from decomposition
        self::assertArrayHasKey('ccn.sum', $contributor->metricValues);
        self::assertSame(12, $contributor->metricValues['ccn.sum']);
    }
}
