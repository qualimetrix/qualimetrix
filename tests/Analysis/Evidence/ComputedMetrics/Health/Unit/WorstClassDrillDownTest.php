<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Analysis\Evidence\ComputedMetrics\Health\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Evidence\ComputedMetrics\Contract\Definition\ComputedMetricDefinitionCatalogInterface;
use Qualimetrix\Analysis\Evidence\ComputedMetrics\Health\Contract\DrillDown\WorstClassDrillDown;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\MetricBag;
use Qualimetrix\Analysis\Finding\Contract\Location;
use Qualimetrix\Analysis\Finding\Contract\Severity;
use Qualimetrix\Analysis\Finding\Contract\Violation;
use Qualimetrix\Core\Path\RelativePath;
use Qualimetrix\Core\Symbol\DeclarationOrdinal;
use Qualimetrix\Core\Symbol\DeclarationPath;
use Qualimetrix\Core\Symbol\MetricSubject;
use Qualimetrix\Core\Symbol\SymbolInfo;
use Qualimetrix\Core\Symbol\SymbolPath;

#[CoversClass(WorstClassDrillDown::class)]
final class WorstClassDrillDownTest extends TestCase
{
    use MetricRepositoryTestHelper;

    private WorstClassDrillDown $drillDown;

    protected function setUp(): void
    {
        $this->drillDown = new WorstClassDrillDown(self::createStub(ComputedMetricDefinitionCatalogInterface::class));
    }

    // --- buildSubtreeHealthScores ---

    // --- buildWorstClasses ---

    #[Test]
    public function itBuildWorstClassesReturnsEmptyWhenNoClassesMatch(): void
    {
        $metrics = $this->createMetricRepository(
            projectMetrics: new MetricBag(),
            classes: [
                new SymbolInfo(SymbolPath::forClass('App\\Other', 'Foo'), RelativePath::fromString('src/Other/Foo.php'), 1),
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

    #[Test]
    public function itBuildWorstClassesSortedByHealthAscending(): void
    {
        $classA = SymbolPath::forClass('App\\Service', 'Alpha');
        $classB = SymbolPath::forClass('App\\Service', 'Beta');

        $metrics = $this->createMetricRepository(
            projectMetrics: new MetricBag(),
            classes: [
                new SymbolInfo($classA, RelativePath::fromString('src/Service/Alpha.php'), 1),
                new SymbolInfo($classB, RelativePath::fromString('src/Service/Beta.php'), 1),
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

    #[Test]
    public function itBuildWorstClassesCountsViolationsPerClass(): void
    {
        $classPath = SymbolPath::forClass('App\\Service', 'Foo');
        $methodPath = SymbolPath::forMethod('App\\Service', 'Foo', 'bar');

        $metrics = $this->createMetricRepository(
            projectMetrics: new MetricBag(),
            classes: [
                new SymbolInfo($classPath, RelativePath::fromString('src/Service/Foo.php'), 1),
            ],
            classMetrics: [
                'class:App\\Service\\Foo' => MetricBag::fromArray([
                    'health.overall' => 60.0,
                    'classLoc' => 100,
                ]),
            ],
        );

        // Two violations: one class-level, one callable-level (both count toward the class)
        $violations = [
            new Violation(
                location: new Location(RelativePath::fromString('src/Service/Foo.php'), 10),
                subject: MetricSubject::declaration(DeclarationPath::of($classPath, RelativePath::fromString('src/Service/Foo.php'), DeclarationOrdinal::fromRank(0))),
                symbolPath: $classPath,
                ruleName: 'test.rule',
                violationCode: 'T001',
                message: 'test violation 1',
                severity: Severity::Warning,
            ),
            new Violation(
                location: new Location(RelativePath::fromString('src/Service/Foo.php'), 20),
                subject: MetricSubject::declaration(DeclarationPath::of($methodPath, RelativePath::fromString('src/Service/Foo.php'), DeclarationOrdinal::fromRank(0))),
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
        self::assertSame(2.0, $result[0]->violationDensity);
    }

    #[Test]
    public function itBuildWorstClassesSkipsNamespaceLevelViolations(): void
    {
        $classPath = SymbolPath::forClass('App\\Service', 'Foo');
        $nsPath = SymbolPath::forNamespace('App\\Service');

        $metrics = $this->createMetricRepository(
            projectMetrics: new MetricBag(),
            classes: [
                new SymbolInfo($classPath, RelativePath::fromString('src/Service/Foo.php'), 1),
            ],
            classMetrics: [
                'class:App\\Service\\Foo' => MetricBag::fromArray([
                    'health.overall' => 60.0,
                ]),
            ],
        );

        $violations = [
            new Violation(
                location: new Location(RelativePath::fromString('src/Service/Foo.php'), 10),
                subject: MetricSubject::aggregate($nsPath),
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

    #[Test]
    public function itBuildWorstClassesSkipsClassesWithoutHealthOverall(): void
    {
        $classPath = SymbolPath::forClass('App\\Service', 'NoHealth');

        $metrics = $this->createMetricRepository(
            projectMetrics: new MetricBag(),
            classes: [
                new SymbolInfo($classPath, RelativePath::fromString('src/Service/NoHealth.php'), 1),
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

    #[Test]
    public function itBuildWorstClassesIncludesNotableMetricsWhenRequested(): void
    {
        $classPath = SymbolPath::forClass('App\\Service', 'Rich');

        $metrics = $this->createMetricRepository(
            projectMetrics: new MetricBag(),
            classes: [
                new SymbolInfo($classPath, RelativePath::fromString('src/Service/Rich.php'), 1),
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

    #[Test]
    public function itBuildWorstClassesOmitsNotableMetricsByDefault(): void
    {
        $classPath = SymbolPath::forClass('App\\Service', 'Simple');

        $metrics = $this->createMetricRepository(
            projectMetrics: new MetricBag(),
            classes: [
                new SymbolInfo($classPath, RelativePath::fromString('src/Service/Simple.php'), 1),
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

}
