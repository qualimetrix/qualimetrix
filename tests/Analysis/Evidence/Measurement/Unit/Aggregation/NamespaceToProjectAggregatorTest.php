<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Analysis\Evidence\Measurement\Unit\Aggregation;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Evidence\Maintainability\MaintainabilityIndexCollector;
use Qualimetrix\Analysis\Evidence\Measurement\Aggregation\MetricAggregator;
use Qualimetrix\Analysis\Evidence\Measurement\Aggregation\NamespaceToProjectAggregator;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\AggregationStrategy;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\CallableWithMetrics;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\MetricBag;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\MetricDefinition;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\NamespaceTree;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\SymbolLevel;
use Qualimetrix\Analysis\Evidence\Measurement\Repository\InMemoryMetricRepository;
use Qualimetrix\Core\Path\RelativePath;
use Qualimetrix\Core\Symbol\CallableKind;
use Qualimetrix\Core\Symbol\DeclarationPath;
use Qualimetrix\Core\Symbol\LogicalClassPath;
use Qualimetrix\Core\Symbol\SymbolPath;

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
            RelativePath::fromString('src/Service/UserService.php'),
            10,
        );
        $this->addMethodsWithMi($repository, 'App\\Service', 'UserService', 'src/Service/UserService.php', 10, 80.0);

        $repository->add(
            SymbolPath::forClass('App\\Service', 'OrderService'),
            (new MetricBag())->with('mi.avg', 60.0)->with('mi.count', 2)->with('mi.min', 50.0),
            RelativePath::fromString('src/Service/OrderService.php'),
            10,
        );
        $this->addMethodsWithMi($repository, 'App\\Service', 'OrderService', 'src/Service/OrderService.php', 2, 60.0);

        // Namespace App\Repository: 1 class, 8 methods
        $repository->add(
            SymbolPath::forClass('App\\Repository', 'UserRepository'),
            (new MetricBag())->with('mi.avg', 90.0)->with('mi.count', 8)->with('mi.min', 85.0),
            RelativePath::fromString('src/Repository/UserRepository.php'),
            10,
        );
        $this->addMethodsWithMi($repository, 'App\\Repository', 'UserRepository', 'src/Repository/UserRepository.php', 8, 90.0);

        $collector = new MaintainabilityIndexCollector();
        $aggregator = new MetricAggregator($collector->getMetricDefinitions());
        $aggregator->aggregate($repository);

        $projectMetrics = $repository->get(SymbolPath::forProject());

        // Project-level weighted average reads class-level data directly:
        // (80*10 + 60*2 + 90*8) / (10+2+8) = (800+120+720) / 20 = 1640/20 = 82.0
        self::assertEqualsWithDelta(82.0, $projectMetrics->get('mi.avg'), 0.01);
        // Total method count = 20
        self::assertSame(20, $projectMetrics->get('mi.count'));
        // Min is computed from raw callable-level values = min(80..., 60..., 90...) = 60.0
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
            RelativePath::fromString('src/Service/Svc.php'),
            10,
        );
        $repository->add(
            SymbolPath::forClass('App\\Repository', 'Repo'),
            new MetricBag(),
            RelativePath::fromString('src/Repository/Repo.php'),
            10,
        );

        // Store namespace-collected metric (like distance) directly on namespace paths
        $repository->add(
            SymbolPath::forNamespace('App\\Service'),
            (new MetricBag())->with('distance', 0.3),
            null,
            null,
        );
        $repository->add(
            SymbolPath::forNamespace('App\\Repository'),
            (new MetricBag())->with('distance', 0.1),
            null,
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

    #[Test]
    public function itKeepsFileCollectedProjectTotalsPhysical(): void
    {
        $repository = new InMemoryMetricRepository();
        $file = RelativePath::fromString('src/Multi.php');
        $repository->add(SymbolPath::forFile($file), MetricBag::fromArray(['loc' => 20]), $file, 1);
        $repository->add(SymbolPath::forNamespace('One'), MetricBag::fromArray(['loc' => 8, 'loc.count' => 1]), $file, 2);
        $repository->add(SymbolPath::forNamespace('Two'), MetricBag::fromArray(['loc' => 9, 'loc.count' => 1]), $file, 10);

        $definitions = [new MetricDefinition('loc', SymbolLevel::File, [
            SymbolLevel::Project->value => [AggregationStrategy::Sum, AggregationStrategy::Average],
        ])];

        (new NamespaceToProjectAggregator(new NamespaceTree(['One', 'Two'])))
            ->aggregate($repository, $definitions);

        $project = $repository->get(SymbolPath::forProject());
        self::assertSame(20, $project->get('loc.sum'));
        self::assertSame(20, $project->get('loc.avg'));
    }

    private function addMethodsWithMi(
        InMemoryMetricRepository $repository,
        string $namespace,
        string $class,
        string $file,
        int $count,
        float $miValue,
    ): void {
        $relFile = RelativePath::fromString($file);
        for ($i = 1; $i <= $count; $i++) {
            $repository->addCallable(new CallableWithMetrics(
                new DeclarationPath(SymbolPath::forMethod($namespace, $class, "m{$i}"), $relFile, $i * 10),
                CallableKind::Method,
                null,
                null,
                new LogicalClassPath(SymbolPath::forClass($namespace, $class)),
                (new MetricBag())->with('mi', $miValue),
            ));
        }
    }
}
