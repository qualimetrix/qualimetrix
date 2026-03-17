<?php

declare(strict_types=1);

namespace AiMessDetector\Tests\Unit\Metrics\Coupling;

use AiMessDetector\Analysis\Collection\Dependency\DependencyGraphBuilder;
use AiMessDetector\Analysis\Repository\InMemoryMetricRepository;
use AiMessDetector\Core\Dependency\Dependency;
use AiMessDetector\Core\Dependency\DependencyType;
use AiMessDetector\Core\Metric\AggregationStrategy;
use AiMessDetector\Core\Metric\MetricBag;
use AiMessDetector\Core\Metric\SymbolLevel;
use AiMessDetector\Core\Symbol\SymbolPath;
use AiMessDetector\Core\Violation\Location;
use AiMessDetector\Metrics\Coupling\ClassRankCollector;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(ClassRankCollector::class)]
final class ClassRankCollectorTest extends TestCase
{
    private ClassRankCollector $collector;
    private DependencyGraphBuilder $graphBuilder;

    protected function setUp(): void
    {
        $this->collector = new ClassRankCollector();
        $this->graphBuilder = new DependencyGraphBuilder();
    }

    #[Test]
    public function getName_returnsClassRank(): void
    {
        self::assertSame('classRank', $this->collector->getName());
    }

    #[Test]
    public function requires_returnsCaAndCe(): void
    {
        self::assertSame(['ca', 'ce'], $this->collector->requires());
    }

    #[Test]
    public function provides_returnsClassRank(): void
    {
        self::assertSame(['classRank'], $this->collector->provides());
    }

    #[Test]
    public function getMetricDefinitions_returnsOneDefinition(): void
    {
        $definitions = $this->collector->getMetricDefinitions();

        self::assertCount(1, $definitions);

        $def = $definitions[0];
        self::assertSame('classRank', $def->name);
        self::assertSame(SymbolLevel::Class_, $def->collectedAt);
        self::assertSame(
            [AggregationStrategy::Max, AggregationStrategy::Average, AggregationStrategy::Percentile95],
            $def->getStrategiesForLevel(SymbolLevel::Namespace_),
        );
        self::assertSame(
            [AggregationStrategy::Max, AggregationStrategy::Average, AggregationStrategy::Percentile95],
            $def->getStrategiesForLevel(SymbolLevel::Project),
        );
    }

    #[Test]
    public function calculate_emptyGraph_writesNoMetrics(): void
    {
        $graph = $this->graphBuilder->build([]);
        $repository = new InMemoryMetricRepository();

        $this->collector->calculate($graph, $repository);

        // No classes in repository — nothing to check
        $this->expectNotToPerformAssertions();
    }

    #[Test]
    public function calculate_singleClass_rankIsOne(): void
    {
        // Single class with a dependency on an external (vendor) class
        $deps = [
            $this->dep('App\\Foo', 'Vendor\\Bar'),
        ];

        $graph = $this->graphBuilder->build($deps);
        $repository = new InMemoryMetricRepository();
        $this->registerClass($repository, 'App\\Foo');

        $this->collector->calculate($graph, $repository);

        $fooPath = SymbolPath::forClass('App', 'Foo');
        $metrics = $repository->get($fooPath);

        self::assertEqualsWithDelta(1.0, $metrics->get('classRank'), 0.001);
    }

    #[Test]
    public function calculate_simpleGraph_dependedClassHasHigherRank(): void
    {
        // A->B, C->B: B has the most incoming links, so B should have the highest rank
        $deps = [
            $this->dep('App\\A', 'App\\B'),
            $this->dep('App\\C', 'App\\B'),
        ];

        $graph = $this->graphBuilder->build($deps);
        $repository = new InMemoryMetricRepository();
        $this->registerClass($repository, 'App\\A');
        $this->registerClass($repository, 'App\\B');
        $this->registerClass($repository, 'App\\C');

        $this->collector->calculate($graph, $repository);

        $rankA = $repository->get(SymbolPath::forClass('App', 'A'))->get('classRank');
        $rankB = $repository->get(SymbolPath::forClass('App', 'B'))->get('classRank');
        $rankC = $repository->get(SymbolPath::forClass('App', 'C'))->get('classRank');

        self::assertNotNull($rankA);
        self::assertNotNull($rankB);
        self::assertNotNull($rankC);

        // B should have the highest rank (most depended on)
        self::assertGreaterThan($rankA, $rankB);
        self::assertGreaterThan($rankC, $rankB);
    }

    #[Test]
    public function calculate_linearChain_ranksIncrease(): void
    {
        // A->B->C: C gets votes from B, B gets votes from A
        // C should have highest rank, then B, then A
        $deps = [
            $this->dep('App\\A', 'App\\B'),
            $this->dep('App\\B', 'App\\C'),
        ];

        $graph = $this->graphBuilder->build($deps);
        $repository = new InMemoryMetricRepository();
        $this->registerClass($repository, 'App\\A');
        $this->registerClass($repository, 'App\\B');
        $this->registerClass($repository, 'App\\C');

        $this->collector->calculate($graph, $repository);

        $rankA = (float) $repository->get(SymbolPath::forClass('App', 'A'))->get('classRank');
        $rankB = (float) $repository->get(SymbolPath::forClass('App', 'B'))->get('classRank');
        $rankC = (float) $repository->get(SymbolPath::forClass('App', 'C'))->get('classRank');

        self::assertGreaterThan($rankA, $rankB, 'B should rank higher than A');
        self::assertGreaterThan($rankB, $rankC, 'C should rank higher than B');
    }

    #[Test]
    public function calculate_vendorClassesNotInRepo_areSkipped(): void
    {
        // A depends on Vendor\Bar, but Vendor\Bar is not in the repository
        $deps = [
            $this->dep('App\\A', 'Vendor\\Bar'),
        ];

        $graph = $this->graphBuilder->build($deps);
        $repository = new InMemoryMetricRepository();
        $this->registerClass($repository, 'App\\A');

        $this->collector->calculate($graph, $repository);

        // Vendor\Bar must NOT be added to repository
        $vendorPath = SymbolPath::forClass('Vendor', 'Bar');
        self::assertFalse(
            $repository->has($vendorPath),
            'Vendor classes must not be added to the repository',
        );

        // A should still get a rank (single project class)
        $rankA = $repository->get(SymbolPath::forClass('App', 'A'))->get('classRank');
        self::assertEqualsWithDelta(1.0, $rankA, 0.001);
    }

    #[Test]
    public function calculate_selfDependencies_areExcluded(): void
    {
        // A->A (self-dependency) and A->B
        $deps = [
            $this->dep('App\\A', 'App\\A'),
            $this->dep('App\\A', 'App\\B'),
        ];

        $graph = $this->graphBuilder->build($deps);
        $repository = new InMemoryMetricRepository();
        $this->registerClass($repository, 'App\\A');
        $this->registerClass($repository, 'App\\B');

        $this->collector->calculate($graph, $repository);

        // Self-dependency should not boost A's own rank
        $rankA = (float) $repository->get(SymbolPath::forClass('App', 'A'))->get('classRank');
        $rankB = (float) $repository->get(SymbolPath::forClass('App', 'B'))->get('classRank');

        // B should have higher rank than A (A votes for B, not for itself)
        self::assertGreaterThan($rankA, $rankB);
    }

    #[Test]
    public function calculate_isolatedClasses_haveUniformRank(): void
    {
        // Two completely isolated classes (no dependencies between them)
        // Each depends only on vendor classes
        $deps = [
            $this->dep('App\\A', 'Vendor\\X'),
            $this->dep('App\\B', 'Vendor\\Y'),
        ];

        $graph = $this->graphBuilder->build($deps);
        $repository = new InMemoryMetricRepository();
        $this->registerClass($repository, 'App\\A');
        $this->registerClass($repository, 'App\\B');

        $this->collector->calculate($graph, $repository);

        $rankA = (float) $repository->get(SymbolPath::forClass('App', 'A'))->get('classRank');
        $rankB = (float) $repository->get(SymbolPath::forClass('App', 'B'))->get('classRank');

        // Both should have equal rank (isolated nodes get (1-d)/N from teleportation + dangling)
        self::assertEqualsWithDelta($rankA, $rankB, 0.001);

        // For isolated classes: rank = 1/N (dangling nodes distribute evenly)
        self::assertEqualsWithDelta(0.5, $rankA, 0.001);
    }

    #[Test]
    public function calculate_ranksConverge_sumToOne(): void
    {
        // Create a non-trivial graph
        $deps = [
            $this->dep('App\\A', 'App\\B'),
            $this->dep('App\\A', 'App\\C'),
            $this->dep('App\\B', 'App\\C'),
            $this->dep('App\\C', 'App\\A'),
        ];

        $graph = $this->graphBuilder->build($deps);
        $repository = new InMemoryMetricRepository();
        $this->registerClass($repository, 'App\\A');
        $this->registerClass($repository, 'App\\B');
        $this->registerClass($repository, 'App\\C');

        $this->collector->calculate($graph, $repository);

        $rankA = (float) $repository->get(SymbolPath::forClass('App', 'A'))->get('classRank');
        $rankB = (float) $repository->get(SymbolPath::forClass('App', 'B'))->get('classRank');
        $rankC = (float) $repository->get(SymbolPath::forClass('App', 'C'))->get('classRank');

        // PageRank scores should sum to approximately 1.0
        self::assertEqualsWithDelta(1.0, $rankA + $rankB + $rankC, 0.01);
    }

    #[Test]
    public function calculate_doesNotCreateSymbolsForExternalClasses(): void
    {
        $deps = [
            $this->dep('App\\A', 'Vendor\\External'),
            $this->dep('App\\B', 'App\\A'),
        ];

        $graph = $this->graphBuilder->build($deps);
        $repository = new InMemoryMetricRepository();
        $this->registerClass($repository, 'App\\A');
        $this->registerClass($repository, 'App\\B');

        $this->collector->calculate($graph, $repository);

        $vendorPath = SymbolPath::forClass('Vendor', 'External');
        self::assertFalse(
            $repository->has($vendorPath),
            'Global collectors must not create symbols for external classes',
        );
    }

    #[Test]
    public function calculate_starTopology_centerHasHighestRank(): void
    {
        // Star topology: A, B, C, D all depend on Center
        $deps = [
            $this->dep('App\\A', 'App\\Center'),
            $this->dep('App\\B', 'App\\Center'),
            $this->dep('App\\C', 'App\\Center'),
            $this->dep('App\\D', 'App\\Center'),
        ];

        $graph = $this->graphBuilder->build($deps);
        $repository = new InMemoryMetricRepository();
        $this->registerClass($repository, 'App\\A');
        $this->registerClass($repository, 'App\\B');
        $this->registerClass($repository, 'App\\C');
        $this->registerClass($repository, 'App\\D');
        $this->registerClass($repository, 'App\\Center');

        $this->collector->calculate($graph, $repository);

        $rankCenter = (float) $repository->get(SymbolPath::forClass('App', 'Center'))->get('classRank');
        $rankA = (float) $repository->get(SymbolPath::forClass('App', 'A'))->get('classRank');

        // Center should have the highest rank by far
        self::assertGreaterThan($rankA, $rankCenter);
    }

    private function dep(string $source, string $target): Dependency
    {
        return new Dependency(
            SymbolPath::fromClassFqn($source),
            SymbolPath::fromClassFqn($target),
            DependencyType::New_,
            new Location('/test.php', 1),
        );
    }

    private function registerClass(InMemoryMetricRepository $repository, string $fqn): void
    {
        $repository->add(SymbolPath::fromClassFqn($fqn), new MetricBag(), '/test.php', 1);
    }
}
