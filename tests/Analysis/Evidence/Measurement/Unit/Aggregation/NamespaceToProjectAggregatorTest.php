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
use Qualimetrix\Analysis\Evidence\Measurement\Repository\InMemoryMetricRepository;
use Qualimetrix\Core\Path\RelativePath;
use Qualimetrix\Core\Profiler\Contract\ProfilerInterface;
use Qualimetrix\Core\Symbol\CallableKind;
use Qualimetrix\Core\Symbol\DeclarationOrdinal;
use Qualimetrix\Core\Symbol\DeclarationPath;
use Qualimetrix\Core\Symbol\LogicalClassPath;
use Qualimetrix\Core\Symbol\SymbolLevel;
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
            (new MetricBag())->with('maintainability.mi.avg', 80.0)->with('maintainability.mi.count', 10)->with('maintainability.mi.min', 70.0),
            RelativePath::fromString('src/Service/UserService.php'),
            10,
        );
        $this->addMethodsWithMi($repository, 'App\\Service', 'UserService', 'src/Service/UserService.php', 10, 80.0);

        $repository->add(
            SymbolPath::forClass('App\\Service', 'OrderService'),
            (new MetricBag())->with('maintainability.mi.avg', 60.0)->with('maintainability.mi.count', 2)->with('maintainability.mi.min', 50.0),
            RelativePath::fromString('src/Service/OrderService.php'),
            10,
        );
        $this->addMethodsWithMi($repository, 'App\\Service', 'OrderService', 'src/Service/OrderService.php', 2, 60.0);

        // Namespace App\Repository: 1 class, 8 methods
        $repository->add(
            SymbolPath::forClass('App\\Repository', 'UserRepository'),
            (new MetricBag())->with('maintainability.mi.avg', 90.0)->with('maintainability.mi.count', 8)->with('maintainability.mi.min', 85.0),
            RelativePath::fromString('src/Repository/UserRepository.php'),
            10,
        );
        $this->addMethodsWithMi($repository, 'App\\Repository', 'UserRepository', 'src/Repository/UserRepository.php', 8, 90.0);

        $collector = new MaintainabilityIndexCollector();
        $aggregator = new MetricAggregator($collector->getMetricDefinitions(), self::createStub(ProfilerInterface::class));
        $aggregator->aggregate($repository);

        $projectMetrics = $repository->get(SymbolPath::forProject());

        // Project-level weighted average reads class-level data directly:
        // (80*10 + 60*2 + 90*8) / (10+2+8) = (800+120+720) / 20 = 1640/20 = 82.0
        self::assertEqualsWithDelta(82.0, $projectMetrics->get('maintainability.mi.avg'), 0.01);
        // Total method count = 20
        self::assertSame(20, $projectMetrics->get('maintainability.mi.count'));
        // Min is computed from raw callable-level values = min(80..., 60..., 90...) = 60.0
        self::assertEqualsWithDelta(60.0, $projectMetrics->get('maintainability.mi.min'), 0.01);
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
            (new MetricBag())->with('coupling.distance', 0.3),
            null,
            null,
        );
        $repository->add(
            SymbolPath::forNamespace('App\\Repository'),
            (new MetricBag())->with('coupling.distance', 0.1),
            null,
            null,
        );

        // Define a namespace-collected metric with project-level Average aggregation
        $definitions = [
            new MetricDefinition(
                name: 'coupling.distance',
                collectedAt: SymbolLevel::Namespace_,
                aggregations: [
                    SymbolLevel::Project->value => [AggregationStrategy::Average],
                ],
            ),
        ];

        $tree = new NamespaceTree(['App\\Service', 'App\\Repository']);
        $aggregator = new NamespaceToProjectAggregator($tree, self::createStub(ProfilerInterface::class));
        $aggregator->aggregate($repository, $definitions);

        $projectMetrics = $repository->get(SymbolPath::forProject());

        // distance.avg = (0.3 + 0.1) / 2 = 0.2
        self::assertEqualsWithDelta(0.2, $projectMetrics->get('coupling.distance.avg'), 0.001);
    }

    /**
     * The population offered to a namespace-collected aggregate, counted from
     * the declarations rather than from the tree walk the aggregate makes.
     *
     * The two sets must be made to disagree, or the assertion passes under the
     * defect it exists to catch: `App` declares a type and also has a
     * sub-namespace, so it is not a leaf and a denominator read from
     * `getLeaves()` never learns it exists.
     */
    #[Test]
    public function itCountsANamespaceThatDeclaresTypesAndAlsoHasSubNamespaces(): void
    {
        $repository = new InMemoryMetricRepository();
        $repository->add(
            SymbolPath::forClass('App', 'OwnClass'),
            new MetricBag(),
            RelativePath::fromString('src/OwnClass.php'),
            10,
        );
        $repository->add(
            SymbolPath::forClass('App\\Sub', 'Nested'),
            new MetricBag(),
            RelativePath::fromString('src/Sub/Nested.php'),
            10,
        );

        $tree = new NamespaceTree(['App', 'App\\Sub']);
        $project = $this->aggregateWith($repository, $tree);

        self::assertSame(2, $project->get('size.symbol-declaring-namespace-count'));
        // The walk the aggregate makes reaches one of the two, which is the point.
        self::assertSame(['App\\Sub'], $tree->getLeaves());
    }

    /**
     * A namespace holding only functions declares no type, so it is not part of
     * the population a type-shaped aggregate could ever have covered. Counting
     * it would publish a permanent gap that no run can close.
     */
    #[Test]
    public function itDoesNotCountANamespaceThatDeclaresNoType(): void
    {
        $repository = new InMemoryMetricRepository();
        $repository->add(
            SymbolPath::forClass('Util', 'Helper'),
            new MetricBag(),
            RelativePath::fromString('src/Util/Helper.php'),
            10,
        );
        $repository->addCallable(new CallableWithMetrics(
            DeclarationPath::of(
                SymbolPath::forGlobalFunction('Helpers', 'only_a_function'),
                RelativePath::fromString('src/helpers.php'),
                DeclarationOrdinal::fromRank(0),
            ),
            5,
            CallableKind::Function,
            null,
            null,
            null,
            new MetricBag(),
        ));

        $tree = new NamespaceTree(['Util', 'Helpers']);
        $project = $this->aggregateWith($repository, $tree);

        self::assertSame(1, $project->get('size.symbol-declaring-namespace-count'));
        self::assertSame(['Util', 'Helpers'], $tree->getLeaves());
    }

    private function aggregateWith(InMemoryMetricRepository $repository, NamespaceTree $tree): MetricBag
    {
        $definitions = [new MetricDefinition('size.loc', SymbolLevel::File, [
            SymbolLevel::Project->value => [AggregationStrategy::Sum],
        ])];

        (new NamespaceToProjectAggregator($tree, self::createStub(ProfilerInterface::class)))
            ->aggregate($repository, $definitions);

        return $repository->get(SymbolPath::forProject());
    }

    #[Test]
    public function itKeepsFileCollectedProjectTotalsPhysical(): void
    {
        $repository = new InMemoryMetricRepository();
        $file = RelativePath::fromString('src/Multi.php');
        $repository->add(SymbolPath::forFile($file), MetricBag::fromArray(['size.loc' => 20]), $file, 1);
        $repository->add(SymbolPath::forNamespace('One'), MetricBag::fromArray(['size.loc' => 8, 'size.loc.count' => 1]), $file, 2);
        $repository->add(SymbolPath::forNamespace('Two'), MetricBag::fromArray(['size.loc' => 9, 'size.loc.count' => 1]), $file, 10);

        $definitions = [new MetricDefinition('size.loc', SymbolLevel::File, [
            SymbolLevel::Project->value => [AggregationStrategy::Sum, AggregationStrategy::Average],
        ])];

        (new NamespaceToProjectAggregator(
            new NamespaceTree(['One', 'Two']),
            self::createStub(ProfilerInterface::class),
        ))
            ->aggregate($repository, $definitions);

        $project = $repository->get(SymbolPath::forProject());
        self::assertSame(20, $project->get('size.loc.sum'));
        self::assertSame(20, $project->get('size.loc.avg'));
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
                DeclarationPath::of(SymbolPath::forMethod($namespace, $class, "m{$i}"), $relFile, DeclarationOrdinal::fromRank(0)),
                $i * 10,
                CallableKind::Method,
                null,
                null,
                new LogicalClassPath(SymbolPath::forClass($namespace, $class)),
                (new MetricBag())->with('maintainability.mi', $miValue),
            ));
        }
    }
}
