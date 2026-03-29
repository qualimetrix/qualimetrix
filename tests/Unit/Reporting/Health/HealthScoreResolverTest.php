<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Unit\Reporting\Health;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Core\Metric\MetricBag;
use Qualimetrix\Core\Symbol\SymbolInfo;
use Qualimetrix\Core\Symbol\SymbolPath;
use Qualimetrix\Reporting\FormatterContext;
use Qualimetrix\Reporting\Health\HealthScore;
use Qualimetrix\Reporting\Health\HealthScoreResolver;
use Qualimetrix\Reporting\Health\MetricHintProvider;
use Qualimetrix\Reporting\Health\NamespaceDrillDown;
use Qualimetrix\Reporting\Report;

#[CoversClass(HealthScoreResolver::class)]
final class HealthScoreResolverTest extends TestCase
{
    use MetricRepositoryTestHelper;

    private HealthScoreResolver $resolver;

    protected function setUp(): void
    {
        $this->resolver = new HealthScoreResolver(
            new NamespaceDrillDown(new MetricHintProvider()),
        );
    }

    public function testReturnsProjectHealthScoresWhenNoFilter(): void
    {
        $projectScores = [
            'complexity' => new HealthScore('complexity', 80.0, 'Good', 50.0, 25.0),
            'overall' => new HealthScore('overall', 75.0, 'Good', 50.0, 25.0),
        ];

        $report = new Report(
            violations: [],
            filesAnalyzed: 5,
            filesSkipped: 0,
            duration: 1.0,
            errorCount: 0,
            warningCount: 0,
            healthScores: $projectScores,
        );

        $context = new FormatterContext();

        $result = $this->resolver->resolve($report, $context);

        self::assertSame($projectScores, $result);
    }

    public function testReturnsProjectHealthScoresWhenNoMetrics(): void
    {
        $projectScores = [
            'complexity' => new HealthScore('complexity', 80.0, 'Good', 50.0, 25.0),
        ];

        $report = new Report(
            violations: [],
            filesAnalyzed: 5,
            filesSkipped: 0,
            duration: 1.0,
            errorCount: 0,
            warningCount: 0,
            metrics: null,
            healthScores: $projectScores,
        );

        $context = new FormatterContext(namespace: 'App');

        $result = $this->resolver->resolve($report, $context);

        // Without metrics, project-level scores are returned even with namespace filter
        self::assertSame($projectScores, $result);
    }

    public function testNamespaceFilterReturnsSubtreeHealthScores(): void
    {
        $nsPath = SymbolPath::forNamespace('App\\Service');
        $nsSymbol = new SymbolInfo($nsPath, '', null);

        $metrics = $this->createMetricRepository(
            projectMetrics: new MetricBag(),
            namespaces: [$nsSymbol],
            namespaceMetrics: [
                $nsPath->toCanonical() => (new MetricBag())
                    ->with('health.complexity', 70.0)
                    ->with('health.overall', 65.0)
                    ->with('classCount.sum', 3),
            ],
        );

        $report = new Report(
            violations: [],
            filesAnalyzed: 5,
            filesSkipped: 0,
            duration: 1.0,
            errorCount: 0,
            warningCount: 0,
            metrics: $metrics,
            healthScores: [],
        );

        $context = new FormatterContext(namespace: 'App\\Service');

        $result = $this->resolver->resolve($report, $context);

        // Should return namespace-specific health scores
        self::assertNotEmpty($result);
        self::assertArrayHasKey('complexity', $result);
    }

    public function testNamespaceFilterReturnsEmptyWhenNoMatchingNamespaces(): void
    {
        $metrics = $this->createMetricRepository(new MetricBag());

        $report = new Report(
            violations: [],
            filesAnalyzed: 5,
            filesSkipped: 0,
            duration: 1.0,
            errorCount: 0,
            warningCount: 0,
            metrics: $metrics,
            healthScores: [],
        );

        $context = new FormatterContext(namespace: 'NonExistent');

        $result = $this->resolver->resolve($report, $context);

        self::assertSame([], $result);
    }

    public function testClassFilterReturnsClassHealthScores(): void
    {
        $classPath = SymbolPath::forClass('App', 'UserService');
        $classSymbol = new SymbolInfo($classPath, 'src/UserService.php', null);

        $metrics = $this->createMetricRepository(
            projectMetrics: new MetricBag(),
            classes: [$classSymbol],
            classMetrics: [
                $classPath->toCanonical() => (new MetricBag())
                    ->with('health.complexity', 85.0)
                    ->with('health.cohesion', 70.0)
                    ->with('health.overall', 78.0),
            ],
        );

        $report = new Report(
            violations: [],
            filesAnalyzed: 5,
            filesSkipped: 0,
            duration: 1.0,
            errorCount: 0,
            warningCount: 0,
            metrics: $metrics,
            healthScores: [],
        );

        $context = new FormatterContext(class: 'App\\UserService');

        $result = $this->resolver->resolve($report, $context);

        self::assertNotEmpty($result);
        self::assertArrayHasKey('complexity', $result);
    }

    public function testClassFilterFallsBackToProjectWhenClassNotFound(): void
    {
        $projectScores = [
            'complexity' => new HealthScore('complexity', 80.0, 'Good', 50.0, 25.0),
        ];

        $metrics = $this->createMetricRepository(new MetricBag());

        $report = new Report(
            violations: [],
            filesAnalyzed: 5,
            filesSkipped: 0,
            duration: 1.0,
            errorCount: 0,
            warningCount: 0,
            metrics: $metrics,
            healthScores: $projectScores,
        );

        $context = new FormatterContext(class: 'App\\NonExistent');

        $result = $this->resolver->resolve($report, $context);

        self::assertSame($projectScores, $result);
    }
}
