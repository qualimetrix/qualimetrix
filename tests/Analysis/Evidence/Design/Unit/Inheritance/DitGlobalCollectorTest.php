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
use Qualimetrix\Analysis\Evidence\Design\Inheritance\InheritanceDepthResolver;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\AggregationStrategy;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\MetricBag;
use Qualimetrix\Analysis\Evidence\Measurement\Repository\InMemoryMetricRepository;
use Qualimetrix\Analysis\Finding\Contract\Location;
use Qualimetrix\Core\Path\RelativePath;
use Qualimetrix\Core\Symbol\DeclarationOrdinal;
use Qualimetrix\Core\Symbol\DeclarationPath;
use Qualimetrix\Core\Symbol\LogicalClassPath;
use Qualimetrix\Core\Symbol\MetricSubject;
use Qualimetrix\Core\Symbol\SymbolLevel;
use Qualimetrix\Core\Symbol\SymbolPath;
use Qualimetrix\Tests\Analysis\Evidence\CircularDependency\Support\AdjacencyGraphBuilder;
use Qualimetrix\Tests\Analysis\Evidence\Design\Support\UnloadableClassProbe;
use RuntimeException;

#[CoversClass(DitGlobalCollector::class)]
#[CoversClass(InheritanceDepthResolver::class)]
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

    /**
     * Deterministic from the FQN so a call site that omits `$file` always
     * agrees with another that also omits it: the identity an edge names and
     * the identity a seed writes cannot drift apart by construction.
     */
    private function declarationFor(string $fqn, ?RelativePath $file = null, int $ordinal = 0): DeclarationPath
    {
        $file ??= RelativePath::fromString(strtr($fqn, '\\', '/') . '.php');

        return DeclarationPath::of(SymbolPath::fromClassFqn($fqn), $file, DeclarationOrdinal::fromRank($ordinal));
    }

    private function createExtends(
        string $childFqn,
        string $parentFqn,
        bool $describesNestedAnonymousClass = false,
        ?RelativePath $file = null,
        int $ordinal = 0,
    ): Dependency {
        $declaration = $this->declarationFor($childFqn, $file, $ordinal);

        return new Dependency(
            source: $declaration,
            target: new LogicalClassPath(SymbolPath::fromClassFqn($parentFqn)),
            type: DependencyType::Extends,
            location: new Location($declaration->file, 1),
            describesNestedAnonymousClass: $describesNestedAnonymousClass,
        );
    }

    /**
     * Seeds one class declaration through the same {@see declarationFor()}
     * helper `createExtends()` uses for the edge source. The collector
     * matches an edge to a repository entry by that identity alone, so
     * seeding through any other path risks a file/ordinal mismatch that
     * would silently zero every DIT in the test instead of failing loudly.
     */
    private function seedDeclaration(
        InMemoryMetricRepository $repository,
        string $fqn,
        MetricBag $bag,
        ?RelativePath $file = null,
        int $ordinal = 0,
        int $line = 1,
    ): MetricSubject {
        $declaration = $this->declarationFor($fqn, $file, $ordinal);
        $subject = MetricSubject::declaration($declaration);
        $repository->addSubject($subject, $bag, $declaration->file, $line);

        return $subject;
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
        $this->seedDeclaration($repository, 'App\\Root', (new MetricBag())->with('design.dit', self::UNWRITTEN));

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
            $this->seedDeclaration($repository, 'App\\Local', (new MetricBag())->with('design.dit', self::UNWRITTEN));

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
        $this->seedDeclaration($repository, 'App\\MyException', (new MetricBag())->with('design.dit', self::UNWRITTEN));

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
        $this->seedDeclaration($repository, 'App\\GrandParent', (new MetricBag())->with('design.dit', self::UNWRITTEN));

        $parentPath = SymbolPath::forClass('App', 'Parent');
        $this->seedDeclaration($repository, 'App\\Parent', (new MetricBag())->with('design.dit', 1));

        $childPath = SymbolPath::forClass('App', 'Child');
        // The per-file collector would have set dit=1 (can't see grandparent)
        $this->seedDeclaration($repository, 'App\\Child', (new MetricBag())->with('design.dit', 1));

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
        $this->seedDeclaration($repository, 'App\\A', (new MetricBag())->with('design.dit', self::UNWRITTEN));

        $bPath = SymbolPath::forClass('App', 'B');
        $this->seedDeclaration($repository, 'App\\B', (new MetricBag())->with('design.dit', 1));

        $cPath = SymbolPath::forClass('App', 'C');
        $this->seedDeclaration($repository, 'App\\C', (new MetricBag())->with('design.dit', 1));

        $dPath = SymbolPath::forClass('App', 'D');
        $this->seedDeclaration($repository, 'App\\D', (new MetricBag())->with('design.dit', 1));

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
        $this->seedDeclaration($repository, 'App\\B', (new MetricBag())->with('design.dit', self::UNWRITTEN));

        $cPath = SymbolPath::forClass('App', 'C');
        $this->seedDeclaration($repository, 'App\\C', (new MetricBag())->with('design.dit', self::UNWRITTEN));

        $dPath = SymbolPath::forClass('App', 'D');
        $this->seedDeclaration($repository, 'App\\D', (new MetricBag())->with('design.dit', self::UNWRITTEN));

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
        $this->seedDeclaration($repository, 'App\\Parent', (new MetricBag())->with('complexity.wmc', 10)->with('design.dit', self::UNWRITTEN));

        $childPath = SymbolPath::forClass('App', 'Child');
        $this->seedDeclaration($repository, 'App\\Child', (new MetricBag())->with('complexity.wmc', 5)->with('design.dit', 1));

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
        $this->seedDeclaration($repository, 'Vendor\\Core\\Component', (new MetricBag())->with('design.dit', self::UNWRITTEN));

        $abstractPath = SymbolPath::forClass('Vendor\\Base', 'AbstractHandler');
        $this->seedDeclaration($repository, 'Vendor\\Base\\AbstractHandler', (new MetricBag())->with('design.dit', self::UNWRITTEN));

        $handlerPath = SymbolPath::forClass('App\\Service', 'Handler');
        $this->seedDeclaration($repository, 'App\\Service\\Handler', (new MetricBag())->with('design.dit', self::UNWRITTEN));

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
        $this->seedDeclaration($repository, 'An\\L0', (new MetricBag())->with('design.dit', self::UNWRITTEN));

        $l1Path = SymbolPath::forClass('An', 'L1');
        $this->seedDeclaration($repository, 'An\\L1', (new MetricBag())->with('design.dit', self::UNWRITTEN));

        $hostPath = SymbolPath::forClass('An', 'Host');
        $this->seedDeclaration($repository, 'An\\Host', (new MetricBag())->with('design.dit', self::UNWRITTEN));

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
        $this->seedDeclaration($repository, 'App\\Impl', (new MetricBag())->with('design.dit', self::UNWRITTEN));

        $unmeasured = SymbolPath::forClass('App', 'Contract');
        $this->seedDeclaration($repository, 'App\\Contract', new MetricBag());

        $this->collector->calculate($graph, $repository);

        self::assertSame(1, $repository->get($measured)->get('design.dit'));
        self::assertNull($repository->get($unmeasured)->get('design.dit'));
    }

    /**
     * A builtin reached through a real external class, rather than named
     * directly as the parent. The per-file collector used to cover this by
     * reflecting on the chain itself; that pass no longer resolves anything
     * outside its own file, so the case belongs to the collector that does.
     */
    #[Test]
    public function itCountsABuiltinInsideAnExternalChain(): void
    {
        $repository = new InMemoryMetricRepository();
        $graph = $this->graph([
            $this->createExtends('App\\MyException', DitChainCustomException::class),
        ]);

        $path = SymbolPath::forClass('App', 'MyException');
        $this->seedDeclaration($repository, 'App\\MyException', (new MetricBag())->with('design.dit', self::UNWRITTEN));

        $this->collector->calculate($graph, $repository);

        // 1 for extending it, plus 1 for its own RuntimeException parent, which
        // is builtin and ends the walk.
        self::assertSame(2, $repository->get($path)->get('design.dit'));
    }

    /**
     * A parent that is in the project and has no parent of its own has no entry
     * in a map keyed by child, so it used to be treated as somebody else's class
     * and looked up through an autoloader. The depth was right either way --
     * 1 + 0 -- which is why nothing caught it; the probe's counter is what can.
     */
    #[Test]
    public function itDoesNotLookOutsideForAParentTheProjectDeclares(): void
    {
        $probe = UnloadableClassProbe::start();

        try {
            $repository = new InMemoryMetricRepository();
            $graph = $this->graph([
                $this->createExtends('App\\Child', $probe->childFqcn()),
            ]);

            $childPath = SymbolPath::forClass('App', 'Child');
            $this->seedDeclaration($repository, 'App\\Child', (new MetricBag())->with('design.dit', self::UNWRITTEN));

            // The parent is measured by this run, so it belongs to the project
            // even though nothing records a parent for it.
            $rootPath = SymbolPath::fromClassFqn($probe->childFqcn());
            $this->seedDeclaration($repository, $probe->childFqcn(), (new MetricBag())->with('design.dit', self::UNWRITTEN));

            $this->collector->calculate($graph, $repository);

            self::assertSame(0, $probe->queryCount(), 'An in-project parent was looked up through an autoloader');
            self::assertSame(1, $repository->get($childPath)->get('design.dit'));
            self::assertSame(0, $repository->get($rootPath)->get('design.dit'));
        } finally {
            $probe->stop();
        }
    }

    /**
     * One name declared in two files, each extending a parent of its own
     * depth. The child-side map is keyed by the exact `DeclarationPath`, so
     * the two declarations must not collapse onto a single answer.
     */
    #[Test]
    public function itGivesEachDeclarationOfADuplicatedNameItsOwnDepth(): void
    {
        $repository = new InMemoryMetricRepository();
        $fileA = RelativePath::fromString('a.php');
        $fileB = RelativePath::fromString('b.php');

        $graph = $this->graph([
            $this->createExtends('App\\Dup', 'App\\Root', file: $fileA),
            $this->createExtends('App\\Dup', 'App\\Mid', file: $fileB),
            $this->createExtends('App\\Mid', 'App\\Root2'),
        ]);

        $declarationA = $this->seedDeclaration($repository, 'App\\Dup', (new MetricBag())->with('design.dit', self::UNWRITTEN), $fileA);
        $declarationB = $this->seedDeclaration($repository, 'App\\Dup', (new MetricBag())->with('design.dit', self::UNWRITTEN), $fileB);

        $this->collector->calculate($graph, $repository);

        self::assertSame(1, $repository->getSubject($declarationA)->get('design.dit'));
        self::assertSame(2, $repository->getSubject($declarationB)->get('design.dit'));
    }

    /**
     * The logical projection is the one view left for readers that only know
     * a name -- aggregates, `format:metrics`, a user formula -- so it carries
     * the max over that name's declarations, not whichever was written last.
     */
    #[Test]
    public function itProjectsTheDuplicatedNameToTheMaximumDepthAcrossDeclarations(): void
    {
        $repository = new InMemoryMetricRepository();
        $fileA = RelativePath::fromString('a.php');
        $fileB = RelativePath::fromString('b.php');

        $graph = $this->graph([
            $this->createExtends('App\\Dup', 'App\\Root', file: $fileA),
            $this->createExtends('App\\Dup', 'App\\Mid', file: $fileB),
            $this->createExtends('App\\Mid', 'App\\Root2'),
        ]);

        $this->seedDeclaration($repository, 'App\\Dup', (new MetricBag())->with('design.dit', self::UNWRITTEN), $fileA);
        $this->seedDeclaration($repository, 'App\\Dup', (new MetricBag())->with('design.dit', self::UNWRITTEN), $fileB);

        $this->collector->calculate($graph, $repository);

        self::assertSame(2, $repository->get(SymbolPath::fromClassFqn('App\\Dup'))->get('design.dit'));
    }

    /**
     * `extends Dup` names a name, not a declaration, so a child of that name
     * cannot pick a side: it takes 1 + the deeper of the two declarations.
     */
    #[Test]
    public function itScoresAChildOfADuplicatedParentNameAtMaxPlusOne(): void
    {
        $repository = new InMemoryMetricRepository();
        $fileA = RelativePath::fromString('a.php');
        $fileB = RelativePath::fromString('b.php');

        $graph = $this->graph([
            $this->createExtends('App\\Dup', 'App\\Root', file: $fileA),
            $this->createExtends('App\\Dup', 'App\\Mid', file: $fileB),
            $this->createExtends('App\\Mid', 'App\\Root2'),
            $this->createExtends('App\\GrandChild', 'App\\Dup'),
        ]);

        $grandChild = $this->seedDeclaration($repository, 'App\\GrandChild', (new MetricBag())->with('design.dit', self::UNWRITTEN));

        $this->collector->calculate($graph, $repository);

        self::assertSame(3, $repository->getSubject($grandChild)->get('design.dit'));
    }

    /**
     * The parent map and the per-name index both come from the graph's edges,
     * not from repository iteration -- so seeding the same four declarations
     * in reverse of the inheritance chain must publish the same four depths.
     */
    #[Test]
    public function itGivesTheSameDitRegardlessOfDeclarationInsertionOrder(): void
    {
        $repository = new InMemoryMetricRepository();
        $graph = $this->graph([
            $this->createExtends('App\\Order\\D', 'App\\Order\\C'),
            $this->createExtends('App\\Order\\C', 'App\\Order\\B'),
            $this->createExtends('App\\Order\\B', 'App\\Order\\A'),
        ]);

        $aPath = SymbolPath::forClass('App\\Order', 'A');
        $bPath = SymbolPath::forClass('App\\Order', 'B');
        $cPath = SymbolPath::forClass('App\\Order', 'C');
        $dPath = SymbolPath::forClass('App\\Order', 'D');

        // Reverse of the chain on purpose: D first, A last.
        $this->seedDeclaration($repository, 'App\\Order\\D', (new MetricBag())->with('design.dit', self::UNWRITTEN));
        $this->seedDeclaration($repository, 'App\\Order\\C', (new MetricBag())->with('design.dit', self::UNWRITTEN));
        $this->seedDeclaration($repository, 'App\\Order\\B', (new MetricBag())->with('design.dit', self::UNWRITTEN));
        $this->seedDeclaration($repository, 'App\\Order\\A', (new MetricBag())->with('design.dit', self::UNWRITTEN));

        $this->collector->calculate($graph, $repository);

        self::assertSame(0, $repository->get($aPath)->get('design.dit'));
        self::assertSame(1, $repository->get($bPath)->get('design.dit'));
        self::assertSame(2, $repository->get($cPath)->get('design.dit'));
        self::assertSame(3, $repository->get($dPath)->get('design.dit'));
    }

    /**
     * A cycle through a duplicated name cannot escape through `max`: every
     * branch the recursion reaches is itself cycle-marked, so the walk falls
     * back to the "no depth could be established" default of 1. Which of the
     * two declarations is marked first -- and therefore which one still gets
     * a real answer from the other -- is a named, accepted limitation of
     * ordinary cycles (see the plan's "cycles remain order-dependent"), so
     * this test pins the repository order instead of leaving it to chance.
     */
    #[Test]
    public function itTerminatesACycleThroughADuplicatedNameAndYieldsTheDefaultDepth(): void
    {
        $repository = new InMemoryMetricRepository();
        $fileX = RelativePath::fromString('x.php');
        $fileY = RelativePath::fromString('y.php');

        $graph = $this->graph([
            $this->createExtends('App\\Cyclic', 'App\\Cyclic', file: $fileX),
            $this->createExtends('App\\Cyclic', 'App\\Cyclic', file: $fileY),
        ]);

        $declarationX = $this->seedDeclaration($repository, 'App\\Cyclic', (new MetricBag())->with('design.dit', self::UNWRITTEN), $fileX);
        $declarationY = $this->seedDeclaration($repository, 'App\\Cyclic', (new MetricBag())->with('design.dit', self::UNWRITTEN), $fileY);

        $this->collector->calculate($graph, $repository);

        self::assertSame(2, $repository->getSubject($declarationX)->get('design.dit'));
        self::assertSame(1, $repository->getSubject($declarationY)->get('design.dit'));
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

/**
 * A non-standard class whose own parent is a builtin: the shape that makes the
 * walk continue one step before it stops.
 */
class DitChainCustomException extends RuntimeException {}
