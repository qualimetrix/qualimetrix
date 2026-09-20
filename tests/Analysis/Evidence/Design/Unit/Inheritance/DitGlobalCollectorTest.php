<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Analysis\Evidence\Design\Unit\Inheritance;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Evidence\DependencyModel\Contract\Dependency;
use Qualimetrix\Analysis\Evidence\DependencyModel\Contract\DependencyGraphInterface;
use Qualimetrix\Analysis\Evidence\DependencyModel\Contract\DependencyType;
use Qualimetrix\Analysis\Evidence\Design\Inheritance\DitGlobalCollector;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\AggregationStrategy;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\MetricBag;
use Qualimetrix\Analysis\Evidence\Measurement\Repository\InMemoryMetricRepository;
use Qualimetrix\Analysis\Finding\Contract\Location;
use Qualimetrix\Core\Path\RelativePath;
use Qualimetrix\Core\Symbol\DeclarationOrdinal;
use Qualimetrix\Core\Symbol\DeclarationPath;
use Qualimetrix\Core\Symbol\LogicalClassPath;
use Qualimetrix\Core\Symbol\SymbolLevel;
use Qualimetrix\Core\Symbol\SymbolPath;
use Qualimetrix\Tests\Analysis\Evidence\CircularDependency\Support\AdjacencyGraphBuilder;
use Qualimetrix\Tests\Analysis\Evidence\Design\Support\UnloadableClassProbe;

#[CoversClass(DitGlobalCollector::class)]
final class DitGlobalCollectorTest extends TestCase
{
    /**
     * Seeded in place of the per-file depth so that an assertion of 0 proves
     * the global pass wrote it, rather than reading back its own fixture.
     * The guard only tests presence, so the magnitude is free.
     */
    private const int UNWRITTEN = 99;

    private DitGlobalCollector $collector;

    protected function setUp(): void
    {
        $this->collector = new DitGlobalCollector();
    }

    private function createExtends(string $childFqn, string $parentFqn, bool $describesNestedAnonymousClass = false): Dependency
    {
        return new Dependency(
            source: DeclarationPath::of(SymbolPath::fromClassFqn($childFqn), RelativePath::fromString('test.php'), DeclarationOrdinal::fromRank(0)),
            target: new LogicalClassPath(SymbolPath::fromClassFqn($parentFqn)),
            type: DependencyType::Extends,
            location: new Location(RelativePath::fromString('test.php'), 1),
            describesNestedAnonymousClass: $describesNestedAnonymousClass,
        );
    }

    #[Test]
    public function itIsNamedDitGlobal(): void
    {
        self::assertSame('dit-global', $this->collector->getName());
    }

    #[Test]
    public function itRequiresNoUpstreamMetrics(): void
    {
        self::assertSame([], $this->collector->requires());
    }

    #[Test]
    public function itProvidesTheDitMetric(): void
    {
        self::assertSame(['design.dit'], $this->collector->provides());
    }

    #[Test]
    public function itScoresDitZeroForAClassWithNoParent(): void
    {
        $repository = new InMemoryMetricRepository();
        $graph = $this->graph([]);

        $path = SymbolPath::forClass('App', 'Root');
        $repository->add($path, (new MetricBag())->with('design.dit', self::UNWRITTEN), RelativePath::fromString('root.php'), 1);

        $this->collector->calculate($graph, $repository);

        self::assertSame(0, $repository->get($path)->get('design.dit'));
    }

    /**
     * Nothing catches around this collector -- aggregation calls it directly --
     * so an Error escaping the external lookup ends the entire run with an
     * internal error rather than costing one class its DIT.
     */
    #[Test]
    public function itTreatsAnUnloadableExternalParentAsUnresolvedInsteadOfEndingTheRun(): void
    {
        $probe = UnloadableClassProbe::start();

        try {
            $repository = new InMemoryMetricRepository();
            $graph = $this->graph([
                $this->createExtends('App\\Local', $probe->childFqcn()),
            ]);

            $path = SymbolPath::forClass('App', 'Local');
            $repository->add($path, (new MetricBag())->with('design.dit', self::UNWRITTEN), RelativePath::fromString('local.php'), 1);

            $this->collector->calculate($graph, $repository);

            // Same reason as the per-file case: depth 1 says nothing about how
            // the resolution ended, so assert the failure and its cause.
            self::assertTrue(
                $probe->failedOnTheMissingParent(),
                'Loading the parent did not fail on its own missing parent',
            );
            self::assertSame(1, $repository->get($path)->get('design.dit'));
        } finally {
            $probe->stop();
        }
    }

    #[Test]
    public function itScoresDitOneForAClassExtendingAStandardPhpClass(): void
    {
        $repository = new InMemoryMetricRepository();
        $graph = $this->graph([
            $this->createExtends('App\\MyException', 'RuntimeException'),
        ]);

        $path = SymbolPath::forClass('App', 'MyException');
        $repository->add($path, (new MetricBag())->with('design.dit', self::UNWRITTEN), RelativePath::fromString('ex.php'), 1);

        $this->collector->calculate($graph, $repository);

        self::assertSame(1, $repository->get($path)->get('design.dit'));
    }

    #[Test]
    public function itComputesDitTwoAcrossFilesForATwoLevelInheritanceChain(): void
    {
        // A extends B extends C (C is root, each in different "file")
        $repository = new InMemoryMetricRepository();
        $graph = $this->graph([
            $this->createExtends('App\\Child', 'App\\Parent'),
            $this->createExtends('App\\Parent', 'App\\GrandParent'),
        ]);

        $grandparentPath = SymbolPath::forClass('App', 'GrandParent');
        $repository->add($grandparentPath, (new MetricBag())->with('design.dit', self::UNWRITTEN), RelativePath::fromString('gp.php'), 1);

        $parentPath = SymbolPath::forClass('App', 'Parent');
        $repository->add($parentPath, (new MetricBag())->with('design.dit', 1), RelativePath::fromString('p.php'), 1);

        $childPath = SymbolPath::forClass('App', 'Child');
        // The per-file collector would have set dit=1 (can't see grandparent)
        $repository->add($childPath, (new MetricBag())->with('design.dit', 1), RelativePath::fromString('c.php'), 1);

        $this->collector->calculate($graph, $repository);

        self::assertSame(0, $repository->get($grandparentPath)->get('design.dit'));
        self::assertSame(1, $repository->get($parentPath)->get('design.dit'));
        self::assertSame(2, $repository->get($childPath)->get('design.dit'));
    }

    #[Test]
    public function itComputesDitThreeAcrossFilesForAThreeLevelInheritanceChain(): void
    {
        $repository = new InMemoryMetricRepository();
        $graph = $this->graph([
            $this->createExtends('App\\D', 'App\\C'),
            $this->createExtends('App\\C', 'App\\B'),
            $this->createExtends('App\\B', 'App\\A'),
        ]);

        $aPath = SymbolPath::forClass('App', 'A');
        $repository->add($aPath, (new MetricBag())->with('design.dit', self::UNWRITTEN), RelativePath::fromString('a.php'), 1);

        $bPath = SymbolPath::forClass('App', 'B');
        $repository->add($bPath, (new MetricBag())->with('design.dit', 1), RelativePath::fromString('b.php'), 1);

        $cPath = SymbolPath::forClass('App', 'C');
        $repository->add($cPath, (new MetricBag())->with('design.dit', 1), RelativePath::fromString('c.php'), 1);

        $dPath = SymbolPath::forClass('App', 'D');
        $repository->add($dPath, (new MetricBag())->with('design.dit', 1), RelativePath::fromString('d.php'), 1);

        $this->collector->calculate($graph, $repository);

        self::assertSame(0, $repository->get($aPath)->get('design.dit'));
        self::assertSame(1, $repository->get($bPath)->get('design.dit'));
        self::assertSame(2, $repository->get($cPath)->get('design.dit'));
        self::assertSame(3, $repository->get($dPath)->get('design.dit'));
    }

    #[Test]
    public function itComputesDitForAChainRootedInAStandardPhpClass(): void
    {
        // D extends C extends B extends Exception (standard)
        $repository = new InMemoryMetricRepository();
        $graph = $this->graph([
            $this->createExtends('App\\D', 'App\\C'),
            $this->createExtends('App\\C', 'App\\B'),
            $this->createExtends('App\\B', 'Exception'),
        ]);

        $bPath = SymbolPath::forClass('App', 'B');
        $repository->add($bPath, (new MetricBag())->with('design.dit', self::UNWRITTEN), RelativePath::fromString('b.php'), 1);

        $cPath = SymbolPath::forClass('App', 'C');
        $repository->add($cPath, (new MetricBag())->with('design.dit', self::UNWRITTEN), RelativePath::fromString('c.php'), 1);

        $dPath = SymbolPath::forClass('App', 'D');
        $repository->add($dPath, (new MetricBag())->with('design.dit', self::UNWRITTEN), RelativePath::fromString('d.php'), 1);

        $this->collector->calculate($graph, $repository);

        self::assertSame(1, $repository->get($bPath)->get('design.dit'));
        self::assertSame(2, $repository->get($cPath)->get('design.dit'));
        self::assertSame(3, $repository->get($dPath)->get('design.dit'));
    }

    #[Test]
    public function itPreservesOtherMetricsWhileUpdatingDit(): void
    {
        $repository = new InMemoryMetricRepository();
        $graph = $this->graph([
            $this->createExtends('App\\Child', 'App\\Parent'),
        ]);

        $parentPath = SymbolPath::forClass('App', 'Parent');
        $repository->add($parentPath, (new MetricBag())->with('complexity.wmc', 10)->with('design.dit', self::UNWRITTEN), RelativePath::fromString('p.php'), 1);

        $childPath = SymbolPath::forClass('App', 'Child');
        $repository->add($childPath, (new MetricBag())->with('complexity.wmc', 5)->with('design.dit', 1), RelativePath::fromString('c.php'), 1);

        $this->collector->calculate($graph, $repository);

        // WMC should be preserved, DIT updated
        self::assertSame(10, $repository->get($parentPath)->get('complexity.wmc'));
        self::assertSame(0, $repository->get($parentPath)->get('design.dit'));
        self::assertSame(5, $repository->get($childPath)->get('complexity.wmc'));
        self::assertSame(1, $repository->get($childPath)->get('design.dit'));
    }

    #[Test]
    public function itComputesDitAcrossANamespaceCrossingInheritanceChain(): void
    {
        $repository = new InMemoryMetricRepository();
        $graph = $this->graph([
            $this->createExtends('App\\Service\\Handler', 'Vendor\\Base\\AbstractHandler'),
            $this->createExtends('Vendor\\Base\\AbstractHandler', 'Vendor\\Core\\Component'),
        ]);

        $componentPath = SymbolPath::forClass('Vendor\\Core', 'Component');
        $repository->add($componentPath, (new MetricBag())->with('design.dit', self::UNWRITTEN), RelativePath::fromString('comp.php'), 1);

        $abstractPath = SymbolPath::forClass('Vendor\\Base', 'AbstractHandler');
        $repository->add($abstractPath, (new MetricBag())->with('design.dit', self::UNWRITTEN), RelativePath::fromString('abs.php'), 1);

        $handlerPath = SymbolPath::forClass('App\\Service', 'Handler');
        $repository->add($handlerPath, (new MetricBag())->with('design.dit', self::UNWRITTEN), RelativePath::fromString('handler.php'), 1);

        $this->collector->calculate($graph, $repository);

        self::assertSame(0, $repository->get($componentPath)->get('design.dit'));
        self::assertSame(1, $repository->get($abstractPath)->get('design.dit'));
        self::assertSame(2, $repository->get($handlerPath)->get('design.dit'));
    }

    /**
     * An anonymous class's `extends` header has no declaration identity of
     * its own, so the edge is recorded with the enclosing class as source and
     * flagged `describesNestedAnonymousClass`. Reading it as the enclosing
     * class's own ancestry (the pre-cure defect) would score Host at
     * dit(L1) + 1 = 2 instead of 0.
     */
    #[Test]
    public function itIgnoresAnExtendsEdgeFlaggedAsANestedAnonymousClassDeclaration(): void
    {
        $repository = new InMemoryMetricRepository();
        $graph = $this->graph([
            $this->createExtends('An\\L1', 'An\\L0'),
            $this->createExtends('An\\Host', 'An\\L1', describesNestedAnonymousClass: true),
        ]);

        $l0Path = SymbolPath::forClass('An', 'L0');
        $repository->add($l0Path, (new MetricBag())->with('design.dit', self::UNWRITTEN), RelativePath::fromString('l0.php'), 1);

        $l1Path = SymbolPath::forClass('An', 'L1');
        $repository->add($l1Path, (new MetricBag())->with('design.dit', self::UNWRITTEN), RelativePath::fromString('l1.php'), 1);

        $hostPath = SymbolPath::forClass('An', 'Host');
        $repository->add($hostPath, (new MetricBag())->with('design.dit', self::UNWRITTEN), RelativePath::fromString('host.php'), 1);

        $this->collector->calculate($graph, $repository);

        self::assertSame(0, $repository->get($l0Path)->get('design.dit'));
        self::assertSame(1, $repository->get($l1Path)->get('design.dit'));
        self::assertSame(0, $repository->get($hostPath)->get('design.dit'), 'A flagged edge must not be read as the enclosing class\'s own ancestry');
    }

    #[Test]
    public function itOwnsTheDitMetricDefinition(): void
    {
        $definitions = $this->collector->getMetricDefinitions();

        self::assertCount(1, $definitions);

        $def = $definitions[0];
        self::assertSame('design.dit', $def->name);
        self::assertSame(SymbolLevel::Class_, $def->collectedAt);

        foreach ([SymbolLevel::Namespace_, SymbolLevel::Project] as $level) {
            $strategies = $def->getStrategiesForLevel($level);
            self::assertContains(AggregationStrategy::Average, $strategies);
            self::assertContains(AggregationStrategy::Max, $strategies);
            self::assertContains(AggregationStrategy::Percentile95, $strategies);
        }
    }

    #[Test]
    public function itLeavesSymbolsTheFilePassNeverMeasuredAlone(): void
    {
        // The file pass measures named class declarations only, so its keys are
        // DIT's population. An interface reaches the repository as a class-level
        // symbol without one, and must not be given a depth here -- doing so
        // moves the denominator of every aggregate.
        $repository = new InMemoryMetricRepository();
        $graph = $this->graph([
            $this->createExtends('App\\Impl', 'App\\Contract'),
        ]);

        $measured = SymbolPath::forClass('App', 'Impl');
        $repository->add($measured, (new MetricBag())->with('design.dit', self::UNWRITTEN), RelativePath::fromString('impl.php'), 1);

        $unmeasured = SymbolPath::forClass('App', 'Contract');
        $repository->add($unmeasured, new MetricBag(), RelativePath::fromString('contract.php'), 1);

        $this->collector->calculate($graph, $repository);

        self::assertSame(1, $repository->get($measured)->get('design.dit'));
        self::assertNull($repository->get($unmeasured)->get('design.dit'));
    }

    /** @param list<Dependency> $dependencies */
    private function graph(array $dependencies): DependencyGraphInterface
    {
        $universe = array_map(
            static fn(Dependency $dependency): LogicalClassPath => new LogicalClassPath($dependency->sourceLogical()),
            $dependencies,
        );

        return AdjacencyGraphBuilder::builder()->build($dependencies, $universe);
    }
}
