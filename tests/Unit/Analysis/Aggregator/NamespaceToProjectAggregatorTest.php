<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Unit\Analysis\Aggregator;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Aggregator\MetricAggregator;
use Qualimetrix\Analysis\Aggregator\NamespaceToProjectAggregator;
use Qualimetrix\Analysis\Repository\InMemoryMetricRepository;
use Qualimetrix\Core\Metric\AggregationStrategy;
use Qualimetrix\Core\Metric\MetricBag;
use Qualimetrix\Core\Metric\MetricDefinition;
use Qualimetrix\Core\Metric\SymbolLevel;
use Qualimetrix\Core\Namespace_\NamespaceTree;
use Qualimetrix\Core\Symbol\SymbolPath;
use Qualimetrix\Metrics\Maintainability\MaintainabilityIndexCollector;

#[CoversClass(NamespaceToProjectAggregator::class)]
final class NamespaceToProjectAggregatorTest extends TestCase
{
    #[Test]
    public function itComputesWeightedAverageAcrossNamespacesAtProjectLevel(): void
    {
        $repository = new InMemoryMetricRepository();

        // Namespace App\Service: 2 classes, total 12 methods
        $repository->add(
            SymbolPath::forClass('App\\Service', 'UserService'),
            (new MetricBag())->with('mi.avg', 80.0)->with('mi.count', 10)->with('mi.min', 70.0),
            'src/Service/UserService.php',
            10,
        );
        $repository->add(
            SymbolPath::forClass('App\\Service', 'OrderService'),
            (new MetricBag())->with('mi.avg', 60.0)->with('mi.count', 2)->with('mi.min', 50.0),
            'src/Service/OrderService.php',
            10,
        );

        // Namespace App\Repository: 1 class, 8 methods
        $repository->add(
            SymbolPath::forClass('App\\Repository', 'UserRepository'),
            (new MetricBag())->with('mi.avg', 90.0)->with('mi.count', 8)->with('mi.min', 85.0),
            'src/Repository/UserRepository.php',
            10,
        );

        $collector = new MaintainabilityIndexCollector();
        $aggregator = new MetricAggregator($collector->getMetricDefinitions());
        $aggregator->aggregate($repository);

        $projectMetrics = $repository->get(SymbolPath::forProject());

        // Project-level weighted average reads class-level data directly:
        // (80*10 + 60*2 + 90*8) / (10+2+8) = (800+120+720) / 20 = 1640/20 = 82.0
        self::assertEqualsWithDelta(82.0, $projectMetrics->get('mi.avg'), 0.01);
        // Total method count = 20
        self::assertSame(20, $projectMetrics->get('mi.count'));
        // Min is computed from collected values (.avg fallback) = min([80, 60, 90]) = 60.0
        self::assertEqualsWithDelta(60.0, $projectMetrics->get('mi.min'), 0.01);
    }

    #[Test]
    public function itAggregatesNamespaceCollectedMetricsToProjectLevel(): void
    {
        $repository = new InMemoryMetricRepository();

        // Register classes so namespaces exist in the repository
        $repository->add(
            SymbolPath::forClass('App\\Service', 'Svc'),
            new MetricBag(),
            'src/Service/Svc.php',
            10,
        );
        $repository->add(
            SymbolPath::forClass('App\\Repository', 'Repo'),
            new MetricBag(),
            'src/Repository/Repo.php',
            10,
        );

        // Store namespace-collected metric (like distance) directly on namespace paths
        $repository->add(
            SymbolPath::forNamespace('App\\Service'),
            (new MetricBag())->with('distance', 0.3),
            '',
            null,
        );
        $repository->add(
            SymbolPath::forNamespace('App\\Repository'),
            (new MetricBag())->with('distance', 0.1),
            '',
            null,
        );

        // Define a namespace-collected metric with project-level Average aggregation
        $definitions = [
            new MetricDefinition(
                name: 'distance',
                collectedAt: SymbolLevel::Namespace_,
                aggregations: [
                    SymbolLevel::Project->value => [AggregationStrategy::Average],
                ],
            ),
        ];

        $tree = new NamespaceTree(['App\\Service', 'App\\Repository']);
        $aggregator = new NamespaceToProjectAggregator($tree);
        $aggregator->aggregate($repository, $definitions);

        $projectMetrics = $repository->get(SymbolPath::forProject());

        // distance.avg = (0.3 + 0.1) / 2 = 0.2
        self::assertEqualsWithDelta(0.2, $projectMetrics->get('distance.avg'), 0.001);
    }
}
