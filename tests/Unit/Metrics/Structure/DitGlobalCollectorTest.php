<?php

declare(strict_types=1);

namespace AiMessDetector\Tests\Unit\Metrics\Structure;

use AiMessDetector\Analysis\Collection\Dependency\DependencyGraphBuilder;
use AiMessDetector\Analysis\Repository\InMemoryMetricRepository;
use AiMessDetector\Core\Dependency\Dependency;
use AiMessDetector\Core\Dependency\DependencyType;
use AiMessDetector\Core\Metric\MetricBag;
use AiMessDetector\Core\Symbol\SymbolPath;
use AiMessDetector\Core\Violation\Location;
use AiMessDetector\Metrics\Structure\DitGlobalCollector;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(DitGlobalCollector::class)]
final class DitGlobalCollectorTest extends TestCase
{
    private DitGlobalCollector $collector;

    protected function setUp(): void
    {
        $this->collector = new DitGlobalCollector();
    }

    private function createExtends(string $childFqn, string $parentFqn): Dependency
    {
        return new Dependency(
            source: SymbolPath::fromClassFqn($childFqn),
            target: SymbolPath::fromClassFqn($parentFqn),
            type: DependencyType::Extends,
            location: new Location('/test.php', 1),
        );
    }

    #[Test]
    public function getName_returnsDitGlobal(): void
    {
        self::assertSame('dit-global', $this->collector->getName());
    }

    #[Test]
    public function requires_returnsEmpty(): void
    {
        self::assertSame([], $this->collector->requires());
    }

    #[Test]
    public function provides_returnsDit(): void
    {
        self::assertSame(['dit'], $this->collector->provides());
    }

    #[Test]
    public function classWithNoParent_ditZero(): void
    {
        $repository = new InMemoryMetricRepository();
        $graph = (new DependencyGraphBuilder())->build([]);

        $path = SymbolPath::forClass('App', 'Root');
        $repository->add($path, new MetricBag(), '/root.php', 1);

        $this->collector->calculate($graph, $repository);

        self::assertSame(0, $repository->get($path)->get('dit'));
    }

    #[Test]
    public function classExtendsStandardPhpClass_ditOne(): void
    {
        $repository = new InMemoryMetricRepository();
        $graph = (new DependencyGraphBuilder())->build([
            $this->createExtends('App\\MyException', 'RuntimeException'),
        ]);

        $path = SymbolPath::forClass('App', 'MyException');
        $repository->add($path, new MetricBag(), '/ex.php', 1);

        $this->collector->calculate($graph, $repository);

        self::assertSame(1, $repository->get($path)->get('dit'));
    }

    #[Test]
    public function twoLevelInheritance_crossFile_ditTwo(): void
    {
        // A extends B extends C (C is root, each in different "file")
        $repository = new InMemoryMetricRepository();
        $graph = (new DependencyGraphBuilder())->build([
            $this->createExtends('App\\Child', 'App\\Parent'),
            $this->createExtends('App\\Parent', 'App\\GrandParent'),
        ]);

        $grandparentPath = SymbolPath::forClass('App', 'GrandParent');
        $repository->add($grandparentPath, (new MetricBag())->with('dit', 0), '/gp.php', 1);

        $parentPath = SymbolPath::forClass('App', 'Parent');
        $repository->add($parentPath, (new MetricBag())->with('dit', 1), '/p.php', 1);

        $childPath = SymbolPath::forClass('App', 'Child');
        // The per-file collector would have set dit=1 (can't see grandparent)
        $repository->add($childPath, (new MetricBag())->with('dit', 1), '/c.php', 1);

        $this->collector->calculate($graph, $repository);

        self::assertSame(0, $repository->get($grandparentPath)->get('dit'));
        self::assertSame(1, $repository->get($parentPath)->get('dit'));
        self::assertSame(2, $repository->get($childPath)->get('dit'));
    }

    #[Test]
    public function threeLevelInheritance_crossFile_ditThree(): void
    {
        $repository = new InMemoryMetricRepository();
        $graph = (new DependencyGraphBuilder())->build([
            $this->createExtends('App\\D', 'App\\C'),
            $this->createExtends('App\\C', 'App\\B'),
            $this->createExtends('App\\B', 'App\\A'),
        ]);

        $aPath = SymbolPath::forClass('App', 'A');
        $repository->add($aPath, (new MetricBag())->with('dit', 0), '/a.php', 1);

        $bPath = SymbolPath::forClass('App', 'B');
        $repository->add($bPath, (new MetricBag())->with('dit', 1), '/b.php', 1);

        $cPath = SymbolPath::forClass('App', 'C');
        $repository->add($cPath, (new MetricBag())->with('dit', 1), '/c.php', 1);

        $dPath = SymbolPath::forClass('App', 'D');
        $repository->add($dPath, (new MetricBag())->with('dit', 1), '/d.php', 1);

        $this->collector->calculate($graph, $repository);

        self::assertSame(0, $repository->get($aPath)->get('dit'));
        self::assertSame(1, $repository->get($bPath)->get('dit'));
        self::assertSame(2, $repository->get($cPath)->get('dit'));
        self::assertSame(3, $repository->get($dPath)->get('dit'));
    }

    #[Test]
    public function inheritanceChainWithStandardClassAtRoot(): void
    {
        // D extends C extends B extends Exception (standard)
        $repository = new InMemoryMetricRepository();
        $graph = (new DependencyGraphBuilder())->build([
            $this->createExtends('App\\D', 'App\\C'),
            $this->createExtends('App\\C', 'App\\B'),
            $this->createExtends('App\\B', 'Exception'),
        ]);

        $bPath = SymbolPath::forClass('App', 'B');
        $repository->add($bPath, new MetricBag(), '/b.php', 1);

        $cPath = SymbolPath::forClass('App', 'C');
        $repository->add($cPath, new MetricBag(), '/c.php', 1);

        $dPath = SymbolPath::forClass('App', 'D');
        $repository->add($dPath, new MetricBag(), '/d.php', 1);

        $this->collector->calculate($graph, $repository);

        self::assertSame(1, $repository->get($bPath)->get('dit'));
        self::assertSame(2, $repository->get($cPath)->get('dit'));
        self::assertSame(3, $repository->get($dPath)->get('dit'));
    }

    #[Test]
    public function preservesExistingMetrics(): void
    {
        $repository = new InMemoryMetricRepository();
        $graph = (new DependencyGraphBuilder())->build([
            $this->createExtends('App\\Child', 'App\\Parent'),
        ]);

        $parentPath = SymbolPath::forClass('App', 'Parent');
        $repository->add($parentPath, (new MetricBag())->with('wmc', 10)->with('dit', 0), '/p.php', 1);

        $childPath = SymbolPath::forClass('App', 'Child');
        $repository->add($childPath, (new MetricBag())->with('wmc', 5)->with('dit', 1), '/c.php', 1);

        $this->collector->calculate($graph, $repository);

        // WMC should be preserved, DIT updated
        self::assertSame(10, $repository->get($parentPath)->get('wmc'));
        self::assertSame(0, $repository->get($parentPath)->get('dit'));
        self::assertSame(5, $repository->get($childPath)->get('wmc'));
        self::assertSame(1, $repository->get($childPath)->get('dit'));
    }

    #[Test]
    public function crossNamespaceInheritance(): void
    {
        $repository = new InMemoryMetricRepository();
        $graph = (new DependencyGraphBuilder())->build([
            $this->createExtends('App\\Service\\Handler', 'Vendor\\Base\\AbstractHandler'),
            $this->createExtends('Vendor\\Base\\AbstractHandler', 'Vendor\\Core\\Component'),
        ]);

        $componentPath = SymbolPath::forClass('Vendor\\Core', 'Component');
        $repository->add($componentPath, new MetricBag(), '/comp.php', 1);

        $abstractPath = SymbolPath::forClass('Vendor\\Base', 'AbstractHandler');
        $repository->add($abstractPath, new MetricBag(), '/abs.php', 1);

        $handlerPath = SymbolPath::forClass('App\\Service', 'Handler');
        $repository->add($handlerPath, new MetricBag(), '/handler.php', 1);

        $this->collector->calculate($graph, $repository);

        self::assertSame(0, $repository->get($componentPath)->get('dit'));
        self::assertSame(1, $repository->get($abstractPath)->get('dit'));
        self::assertSame(2, $repository->get($handlerPath)->get('dit'));
    }
}
