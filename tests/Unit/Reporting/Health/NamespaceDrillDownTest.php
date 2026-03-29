<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Unit\Reporting\Health;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Core\Metric\MetricBag;
use Qualimetrix\Core\Symbol\SymbolInfo;
use Qualimetrix\Core\Symbol\SymbolPath;
use Qualimetrix\Core\Violation\Location;
use Qualimetrix\Core\Violation\Severity;
use Qualimetrix\Core\Violation\Violation;
use Qualimetrix\Reporting\Health\MetricHintProvider;
use Qualimetrix\Reporting\Health\NamespaceDrillDown;

#[CoversClass(NamespaceDrillDown::class)]
final class NamespaceDrillDownTest extends TestCase
{
    use MetricRepositoryTestHelper;

    private NamespaceDrillDown $drillDown;

    protected function setUp(): void
    {
        $this->drillDown = new NamespaceDrillDown(new MetricHintProvider());
    }

    // --- buildSubtreeHealthScores ---

    public function testSubtreeHealthScoresReturnsEmptyForNoMatchingNamespaces(): void
    {
        $metrics = $this->createMetricRepository(
            projectMetrics: new MetricBag(),
            namespaces: [
                new SymbolInfo(SymbolPath::forNamespace('App\\Other'), 'src/Other.php', 1),
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

    public function testSubtreeHealthScoresMatchesExactNamespace(): void
    {
        $metrics = $this->createMetricRepository(
            projectMetrics: new MetricBag(),
            namespaces: [
                new SymbolInfo(SymbolPath::forNamespace('App\\Service'), 'src/Service.php', 1),
            ],
            namespaceMetrics: [
                'ns:App\\Service' => MetricBag::fromArray([
                    'health.complexity' => 80.0,
                    'health.overall' => 75.0,
                    'classCount.sum' => 3,
                ]),
            ],
        );

        $result = $this->drillDown->buildSubtreeHealthScores($metrics, 'App\\Service');

        self::assertArrayHasKey('complexity', $result);
        self::assertArrayHasKey('overall', $result);
        self::assertEqualsWithDelta(80.0, $result['complexity']->score, 0.01);
        self::assertEqualsWithDelta(75.0, $result['overall']->score, 0.01);
    }

    public function testSubtreeHealthScoresMatchesChildNamespaces(): void
    {
        $metrics = $this->createMetricRepository(
            projectMetrics: new MetricBag(),
            namespaces: [
                new SymbolInfo(SymbolPath::forNamespace('App\\Service\\Payment'), 'src/Service/Payment.php', 1),
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

    public function testSubtreeHealthScoresDoesNotMatchSimilarPrefix(): void
    {
        // App\ServiceManager should NOT match prefix App\Service
        $metrics = $this->createMetricRepository(
            projectMetrics: new MetricBag(),
            namespaces: [
                new SymbolInfo(SymbolPath::forNamespace('App\\ServiceManager'), 'src/ServiceManager.php', 1),
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

    public function testSubtreeHealthScoresWeightedAverageAcrossNamespaces(): void
    {
        $metrics = $this->createMetricRepository(
            projectMetrics: new MetricBag(),
            namespaces: [
                new SymbolInfo(SymbolPath::forNamespace('App\\Service'), 'src/Service.php', 1),
                new SymbolInfo(SymbolPath::forNamespace('App\\Service\\Sub'), 'src/Service/Sub.php', 1),
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

    public function testSubtreeHealthScoresUsesMinimumClassCountOfOne(): void
    {
        $metrics = $this->createMetricRepository(
            projectMetrics: new MetricBag(),
            namespaces: [
                new SymbolInfo(SymbolPath::forNamespace('App\\Service'), 'src/Service.php', 1),
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

    public function testBuildWorstClassesReturnsEmptyWhenNoClassesMatch(): void
    {
        $metrics = $this->createMetricRepository(
            projectMetrics: new MetricBag(),
            classes: [
                new SymbolInfo(SymbolPath::forClass('App\\Other', 'Foo'), 'src/Other/Foo.php', 1),
            ],
            classMetrics: [
                'class:App\\Other\\Foo' => MetricBag::fromArray([
                    'health.overall' => 80.0,
                ]),
            ],
        );

        $result = $this->drillDown->buildWorstClasses($metrics, 'App\\Service', []);

        self::assertSame([], $result);
    }

    public function testBuildWorstClassesSortedByHealthAscending(): void
    {
        $classA = SymbolPath::forClass('App\\Service', 'Alpha');
        $classB = SymbolPath::forClass('App\\Service', 'Beta');

        $metrics = $this->createMetricRepository(
            projectMetrics: new MetricBag(),
            classes: [
                new SymbolInfo($classA, 'src/Service/Alpha.php', 1),
                new SymbolInfo($classB, 'src/Service/Beta.php', 1),
            ],
            classMetrics: [
                'class:App\\Service\\Alpha' => MetricBag::fromArray([
                    'health.overall' => 80.0,
                    'health.complexity' => 90.0,
                ]),
                'class:App\\Service\\Beta' => MetricBag::fromArray([
                    'health.overall' => 40.0,
                    'health.complexity' => 30.0,
                ]),
            ],
        );

        $result = $this->drillDown->buildWorstClasses($metrics, 'App\\Service', []);

        self::assertCount(2, $result);
        // Worst (lowest score) first
        self::assertSame('Beta', $result[0]->symbolPath->type);
        self::assertSame('Alpha', $result[1]->symbolPath->type);
    }

    public function testBuildWorstClassesCountsViolationsPerClass(): void
    {
        $classPath = SymbolPath::forClass('App\\Service', 'Foo');
        $methodPath = SymbolPath::forMethod('App\\Service', 'Foo', 'bar');

        $metrics = $this->createMetricRepository(
            projectMetrics: new MetricBag(),
            classes: [
                new SymbolInfo($classPath, 'src/Service/Foo.php', 1),
            ],
            classMetrics: [
                'class:App\\Service\\Foo' => MetricBag::fromArray([
                    'health.overall' => 60.0,
                    'classLoc' => 100,
                ]),
            ],
        );

        // Two violations: one class-level, one method-level (both count toward the class)
        $violations = [
            new Violation(
                location: new Location('src/Service/Foo.php', 10),
                symbolPath: $classPath,
                ruleName: 'test.rule',
                violationCode: 'T001',
                message: 'test violation 1',
                severity: Severity::Warning,
            ),
            new Violation(
                location: new Location('src/Service/Foo.php', 20),
                symbolPath: $methodPath,
                ruleName: 'test.rule',
                violationCode: 'T002',
                message: 'test violation 2',
                severity: Severity::Warning,
            ),
        ];

        $result = $this->drillDown->buildWorstClasses($metrics, 'App\\Service', $violations);

        self::assertCount(1, $result);
        self::assertSame(2, $result[0]->violationCount);
    }

    public function testBuildWorstClassesSkipsNamespaceLevelViolations(): void
    {
        $classPath = SymbolPath::forClass('App\\Service', 'Foo');
        $nsPath = SymbolPath::forNamespace('App\\Service');

        $metrics = $this->createMetricRepository(
            projectMetrics: new MetricBag(),
            classes: [
                new SymbolInfo($classPath, 'src/Service/Foo.php', 1),
            ],
            classMetrics: [
                'class:App\\Service\\Foo' => MetricBag::fromArray([
                    'health.overall' => 60.0,
                ]),
            ],
        );

        $violations = [
            new Violation(
                location: new Location('src/Service/Foo.php', 10),
                symbolPath: $nsPath,
                ruleName: 'test.rule',
                violationCode: 'T001',
                message: 'namespace violation',
                severity: Severity::Warning,
            ),
        ];

        $result = $this->drillDown->buildWorstClasses($metrics, 'App\\Service', $violations);

        self::assertCount(1, $result);
        self::assertSame(0, $result[0]->violationCount);
    }

    public function testBuildWorstClassesSkipsClassesWithoutHealthOverall(): void
    {
        $classPath = SymbolPath::forClass('App\\Service', 'NoHealth');

        $metrics = $this->createMetricRepository(
            projectMetrics: new MetricBag(),
            classes: [
                new SymbolInfo($classPath, 'src/Service/NoHealth.php', 1),
            ],
            classMetrics: [
                'class:App\\Service\\NoHealth' => MetricBag::fromArray([
                    'health.complexity' => 80.0,
                    // no health.overall
                ]),
            ],
        );

        $result = $this->drillDown->buildWorstClasses($metrics, 'App\\Service', []);

        self::assertSame([], $result);
    }

    public function testBuildWorstClassesIncludesNotableMetricsWhenRequested(): void
    {
        $classPath = SymbolPath::forClass('App\\Service', 'Rich');

        $metrics = $this->createMetricRepository(
            projectMetrics: new MetricBag(),
            classes: [
                new SymbolInfo($classPath, 'src/Service/Rich.php', 1),
            ],
            classMetrics: [
                'class:App\\Service\\Rich' => MetricBag::fromArray([
                    'health.overall' => 70.0,
                    'methodCount' => 15,
                    'cbo' => 8,
                    'loc' => 300,
                ]),
            ],
        );

        $result = $this->drillDown->buildWorstClasses($metrics, 'App\\Service', [], includeNotableMetrics: true);

        self::assertCount(1, $result);
        self::assertArrayHasKey('methodCount', $result[0]->metrics);
        self::assertSame(15, $result[0]->metrics['methodCount']);
        self::assertArrayHasKey('cbo', $result[0]->metrics);
        self::assertArrayHasKey('loc', $result[0]->metrics);
    }

    public function testBuildWorstClassesOmitsNotableMetricsByDefault(): void
    {
        $classPath = SymbolPath::forClass('App\\Service', 'Simple');

        $metrics = $this->createMetricRepository(
            projectMetrics: new MetricBag(),
            classes: [
                new SymbolInfo($classPath, 'src/Service/Simple.php', 1),
            ],
            classMetrics: [
                'class:App\\Service\\Simple' => MetricBag::fromArray([
                    'health.overall' => 70.0,
                    'methodCount' => 5,
                ]),
            ],
        );

        $result = $this->drillDown->buildWorstClasses($metrics, 'App\\Service', []);

        self::assertCount(1, $result);
        self::assertSame([], $result[0]->metrics);
    }

    // --- buildClassHealthScores ---

    public function testBuildClassHealthScoresReturnsEmptyWhenClassNotFound(): void
    {
        $metrics = $this->createMetricRepository(
            projectMetrics: new MetricBag(),
            classes: [],
        );

        $result = $this->drillDown->buildClassHealthScores($metrics, 'App\\Service\\NonExistent');

        self::assertSame([], $result);
    }

    public function testBuildClassHealthScoresReturnsDimensionScores(): void
    {
        $classPath = SymbolPath::forClass('App\\Service', 'UserService');

        $metrics = $this->createMetricRepository(
            projectMetrics: new MetricBag(),
            classes: [
                new SymbolInfo($classPath, 'src/Service/UserService.php', 1),
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

    public function testBuildClassHealthScoresSkipsMissingDimensions(): void
    {
        $classPath = SymbolPath::forClass('App\\Service', 'Partial');

        $metrics = $this->createMetricRepository(
            projectMetrics: new MetricBag(),
            classes: [
                new SymbolInfo($classPath, 'src/Service/Partial.php', 1),
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

    public function testBuildClassHealthScoresMatchesGlobalClass(): void
    {
        $classPath = SymbolPath::forClass('', 'GlobalClass');

        $metrics = $this->createMetricRepository(
            projectMetrics: new MetricBag(),
            classes: [
                new SymbolInfo($classPath, 'src/GlobalClass.php', 1),
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
