<?php

declare(strict_types=1);

namespace AiMessDetector\Tests\Unit\Analysis\Aggregator;

use AiMessDetector\Analysis\Aggregator\MetricAggregator;
use AiMessDetector\Analysis\Aggregator\NamespaceToProjectAggregator;
use AiMessDetector\Analysis\Repository\InMemoryMetricRepository;
use AiMessDetector\Core\Metric\MetricBag;
use AiMessDetector\Core\Symbol\SymbolPath;
use AiMessDetector\Metrics\Maintainability\MaintainabilityIndexCollector;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

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
}
