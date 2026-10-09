<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Analysis\Evidence\Design\Unit\Inheritance;

use LogicException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Evidence\DependencyModel\Contract\ClassLikeDeclaration;
use Qualimetrix\Analysis\Evidence\DependencyModel\Contract\Dependency;
use Qualimetrix\Analysis\Evidence\DependencyModel\Contract\DependencyGraphInterface;
use Qualimetrix\Analysis\Evidence\DependencyModel\Contract\DependencyType;
use Qualimetrix\Analysis\Evidence\Design\Inheritance\DitGlobalCollector;
use Qualimetrix\Analysis\Evidence\Design\Inheritance\ExternalAncestry;
use Qualimetrix\Analysis\Evidence\Design\Inheritance\InheritanceDepthResolver;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\AggregationStrategy;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\MetricBag;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\MetricDefinition;
use Qualimetrix\Analysis\Evidence\Measurement\Repository\InMemoryMetricRepository;
use Qualimetrix\Analysis\Finding\Contract\Location;
use Qualimetrix\Core\Path\RelativePath;
use Qualimetrix\Core\Symbol\ClassType;
use Qualimetrix\Core\Symbol\DeclarationOrdinal;
use Qualimetrix\Core\Symbol\DeclarationPath;
use Qualimetrix\Core\Symbol\LogicalClassPath;
use Qualimetrix\Core\Symbol\MetricSubject;
use Qualimetrix\Core\Symbol\SymbolLevel;
use Qualimetrix\Core\Symbol\SymbolPath;
use Qualimetrix\Tests\Analysis\Evidence\CircularDependency\Support\AdjacencyGraphBuilder;
use Qualimetrix\Tests\Analysis\Evidence\Design\Support\FixedParentSource;
use Qualimetrix\Tests\Analysis\Evidence\Design\Support\UnloadableClassProbe;
use RuntimeException;

#[CoversClass(DitGlobalCollector::class)]
#[CoversClass(InheritanceDepthResolver::class)]
final class DitGlobalCollectorTest extends TestCase
{
    private DitGlobalCollector $collector;

    /** @var array<string, ClassLikeDeclaration> */
    private array $facts = [];

    protected function setUp(): void
    {
        // No install to read: every external parent stays unresolved, which is
        // what these cases assume unless they say otherwise.
        $this->collector = new DitGlobalCollector(
            new ExternalAncestry(FixedParentSource::unconfigured()),
        );
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

        return Dependency::ofClassLike(
            source: $declaration,
            target: new LogicalClassPath(SymbolPath::fromClassFqn($parentFqn)),
            type: DependencyType::Extends,
            location: new Location($declaration->file, 1),
            describesNestedAnonymousClass: $describesNestedAnonymousClass,
            interfaceExtends: false,
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
        ClassType $type = ClassType::Class_,
    ): MetricSubject {
        $declaration = $this->declarationFor($fqn, $file, $ordinal);
        $subject = MetricSubject::declaration($declaration);
        $this->facts[$declaration->toCanonical()] = ClassLikeDeclaration::of($declaration, $type, false, false);
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
        self::assertSame(['design.dit', 'design.dit-unresolved', 'design.is-exception'], $this->collector->provides());
    }

    #[Test]
    public function itScoresDitZeroForAClassWithNoParent(): void
    {
        $repository = new InMemoryMetricRepository([
            ...$this->collector->getMetricDefinitions(),
            new MetricDefinition('complexity.wmc', SymbolLevel::Class_),
        ]);
        $graph = $this->graph([]);

        $path = SymbolPath::forClass('App', 'Root');
        $this->seedDeclaration($repository, 'App\\Root', (new MetricBag())->with('complexity.wmc', 0));

        $this->calculate($graph, $repository);

        self::assertSame(0, $this->classMetrics($repository, $path)->get('design.dit'));
    }

    /**
     * The last load is gone: the global pass reads ancestors' files instead.
     *
     * The probe's reading inverts with the mechanism. It was built to show that
     * a load was attempted and broke on the parent's absence -- the shape a
     * standalone install hits, which used to end the whole run because nothing
     * catches around this collector. Now the claim is that no autoloader is
     * consulted at all, so `failedOnTheMissingParent()` is false precisely
     * because nothing was tried, and only `queryCount()` can carry it: depth 1
     * is what an unresolved parent scores either way.
     */
    #[Test]
    public function itAsksNoAutoloaderAboutAnExternalParent(): void
    {
        $probe = UnloadableClassProbe::start();

        try {
            $repository = new InMemoryMetricRepository([
                ...$this->collector->getMetricDefinitions(),
                new MetricDefinition('complexity.wmc', SymbolLevel::Class_),
            ]);
            $graph = $this->graph([
                $this->createExtends('App\\Local', $probe->childFqcn()),
            ]);

            $path = SymbolPath::forClass('App', 'Local');
            $this->seedDeclaration($repository, 'App\\Local', (new MetricBag())->with('complexity.wmc', 0));

            $this->calculate($graph, $repository);

            self::assertSame(0, $probe->queryCount(), 'The collector consulted an autoloader');
            self::assertFalse($probe->failedOnTheMissingParent(), 'A load was attempted, so foreign code ran');
            self::assertSame(1, $this->classMetrics($repository, $path)->get('design.dit'));
        } finally {
            $probe->stop();
        }
    }

    #[Test]
    public function itIncludesTheBuiltinParentsOwnDepth(): void
    {
        $repository = new InMemoryMetricRepository([
            ...$this->collector->getMetricDefinitions(),
            new MetricDefinition('complexity.wmc', SymbolLevel::Class_),
        ]);
        $graph = $this->graph([
            $this->createExtends('App\\MyException', 'RuntimeException'),
        ]);

        $path = SymbolPath::forClass('App', 'MyException');
        $this->seedDeclaration($repository, 'App\\MyException', (new MetricBag())->with('complexity.wmc', 0));

        $this->calculate($graph, $repository);

        self::assertSame(2, $this->classMetrics($repository, $path)->get('design.dit'));
    }

    #[Test]
    public function itComputesDitTwoAcrossFilesForATwoLevelInheritanceChain(): void
    {
        // A extends B extends C (C is root, each in different "file")
        $repository = new InMemoryMetricRepository([
            ...$this->collector->getMetricDefinitions(),
            new MetricDefinition('complexity.wmc', SymbolLevel::Class_),
        ]);
        $graph = $this->graph([
            $this->createExtends('App\\Child', 'App\\Parent'),
            $this->createExtends('App\\Parent', 'App\\GrandParent'),
        ]);

        $grandparentPath = SymbolPath::forClass('App', 'GrandParent');
        $this->seedDeclaration($repository, 'App\\GrandParent', (new MetricBag())->with('complexity.wmc', 0));

        $parentPath = SymbolPath::forClass('App', 'Parent');
        $this->seedDeclaration($repository, 'App\\Parent', (new MetricBag())->with('design.dit', 1));

        $childPath = SymbolPath::forClass('App', 'Child');
        $this->seedDeclaration($repository, 'App\\Child', (new MetricBag())->with('design.dit', 1));

        $this->calculate($graph, $repository);

        self::assertSame(0, $this->classMetrics($repository, $grandparentPath)->get('design.dit'));
        self::assertSame(1, $this->classMetrics($repository, $parentPath)->get('design.dit'));
        self::assertSame(2, $this->classMetrics($repository, $childPath)->get('design.dit'));
    }

    #[Test]
    public function itComputesDitThreeAcrossFilesForAThreeLevelInheritanceChain(): void
    {
        $repository = new InMemoryMetricRepository([
            ...$this->collector->getMetricDefinitions(),
            new MetricDefinition('complexity.wmc', SymbolLevel::Class_),
        ]);
        $graph = $this->graph([
            $this->createExtends('App\\D', 'App\\C'),
            $this->createExtends('App\\C', 'App\\B'),
            $this->createExtends('App\\B', 'App\\A'),
        ]);

        $aPath = SymbolPath::forClass('App', 'A');
        $this->seedDeclaration($repository, 'App\\A', (new MetricBag())->with('complexity.wmc', 0));

        $bPath = SymbolPath::forClass('App', 'B');
        $this->seedDeclaration($repository, 'App\\B', (new MetricBag())->with('design.dit', 1));

        $cPath = SymbolPath::forClass('App', 'C');
        $this->seedDeclaration($repository, 'App\\C', (new MetricBag())->with('design.dit', 1));

        $dPath = SymbolPath::forClass('App', 'D');
        $this->seedDeclaration($repository, 'App\\D', (new MetricBag())->with('design.dit', 1));

        $this->calculate($graph, $repository);

        self::assertSame(0, $this->classMetrics($repository, $aPath)->get('design.dit'));
        self::assertSame(1, $this->classMetrics($repository, $bPath)->get('design.dit'));
        self::assertSame(2, $this->classMetrics($repository, $cPath)->get('design.dit'));
        self::assertSame(3, $this->classMetrics($repository, $dPath)->get('design.dit'));
    }

    #[Test]
    public function itComputesDitForAChainRootedInAStandardPhpClass(): void
    {
        // D extends C extends B extends Exception (standard)
        $repository = new InMemoryMetricRepository([
            ...$this->collector->getMetricDefinitions(),
            new MetricDefinition('complexity.wmc', SymbolLevel::Class_),
        ]);
        $graph = $this->graph([
            $this->createExtends('App\\D', 'App\\C'),
            $this->createExtends('App\\C', 'App\\B'),
            $this->createExtends('App\\B', 'Exception'),
        ]);

        $bPath = SymbolPath::forClass('App', 'B');
        $this->seedDeclaration($repository, 'App\\B', (new MetricBag())->with('complexity.wmc', 0));

        $cPath = SymbolPath::forClass('App', 'C');
        $this->seedDeclaration($repository, 'App\\C', (new MetricBag())->with('complexity.wmc', 0));

        $dPath = SymbolPath::forClass('App', 'D');
        $this->seedDeclaration($repository, 'App\\D', (new MetricBag())->with('complexity.wmc', 0));

        $this->calculate($graph, $repository);

        self::assertSame(1, $this->classMetrics($repository, $bPath)->get('design.dit'));
        self::assertSame(2, $this->classMetrics($repository, $cPath)->get('design.dit'));
        self::assertSame(3, $this->classMetrics($repository, $dPath)->get('design.dit'));
    }

    #[Test]
    public function itPreservesOtherMetricsWhileUpdatingDit(): void
    {
        $repository = new InMemoryMetricRepository([
            ...$this->collector->getMetricDefinitions(),
            new MetricDefinition('complexity.wmc', SymbolLevel::Class_),
        ]);
        $graph = $this->graph([
            $this->createExtends('App\\Child', 'App\\Parent'),
        ]);

        $parentPath = SymbolPath::forClass('App', 'Parent');
        $this->seedDeclaration($repository, 'App\\Parent', (new MetricBag())->with('complexity.wmc', 10));

        $childPath = SymbolPath::forClass('App', 'Child');
        $this->seedDeclaration($repository, 'App\\Child', (new MetricBag())->with('complexity.wmc', 5)->with('design.dit', 1));

        $this->calculate($graph, $repository);

        // WMC should be preserved, DIT updated
        self::assertSame(10, $this->classMetrics($repository, $parentPath)->get('complexity.wmc'));
        self::assertSame(0, $this->classMetrics($repository, $parentPath)->get('design.dit'));
        self::assertSame(5, $this->classMetrics($repository, $childPath)->get('complexity.wmc'));
        self::assertSame(1, $this->classMetrics($repository, $childPath)->get('design.dit'));
    }

    #[Test]
    public function itComputesDitAcrossANamespaceCrossingInheritanceChain(): void
    {
        $repository = new InMemoryMetricRepository([
            ...$this->collector->getMetricDefinitions(),
            new MetricDefinition('complexity.wmc', SymbolLevel::Class_),
        ]);
        $graph = $this->graph([
            $this->createExtends('App\\Service\\Handler', 'Vendor\\Base\\AbstractHandler'),
            $this->createExtends('Vendor\\Base\\AbstractHandler', 'Vendor\\Core\\Component'),
        ]);

        $componentPath = SymbolPath::forClass('Vendor\\Core', 'Component');
        $this->seedDeclaration($repository, 'Vendor\\Core\\Component', (new MetricBag())->with('complexity.wmc', 0));

        $abstractPath = SymbolPath::forClass('Vendor\\Base', 'AbstractHandler');
        $this->seedDeclaration($repository, 'Vendor\\Base\\AbstractHandler', (new MetricBag())->with('complexity.wmc', 0));

        $handlerPath = SymbolPath::forClass('App\\Service', 'Handler');
        $this->seedDeclaration($repository, 'App\\Service\\Handler', (new MetricBag())->with('complexity.wmc', 0));

        $this->calculate($graph, $repository);

        self::assertSame(0, $this->classMetrics($repository, $componentPath)->get('design.dit'));
        self::assertSame(1, $this->classMetrics($repository, $abstractPath)->get('design.dit'));
        self::assertSame(2, $this->classMetrics($repository, $handlerPath)->get('design.dit'));
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
        $repository = new InMemoryMetricRepository([
            ...$this->collector->getMetricDefinitions(),
            new MetricDefinition('complexity.wmc', SymbolLevel::Class_),
        ]);
        $graph = $this->graph([
            $this->createExtends('An\\L1', 'An\\L0'),
            $this->createExtends('An\\Host', 'An\\L1', describesNestedAnonymousClass: true),
        ]);

        $l0Path = SymbolPath::forClass('An', 'L0');
        $this->seedDeclaration($repository, 'An\\L0', (new MetricBag())->with('complexity.wmc', 0));

        $l1Path = SymbolPath::forClass('An', 'L1');
        $this->seedDeclaration($repository, 'An\\L1', (new MetricBag())->with('complexity.wmc', 0));

        $hostPath = SymbolPath::forClass('An', 'Host');
        $this->seedDeclaration($repository, 'An\\Host', (new MetricBag())->with('complexity.wmc', 0));

        $this->calculate($graph, $repository);

        self::assertSame(0, $this->classMetrics($repository, $l0Path)->get('design.dit'));
        self::assertSame(1, $this->classMetrics($repository, $l1Path)->get('design.dit'));
        self::assertSame(0, $this->classMetrics($repository, $hostPath)->get('design.dit'), 'A flagged edge must not be read as the enclosing class\'s own ancestry');
    }

    #[Test]
    public function itOwnsTheDitMetricDefinition(): void
    {
        $definitions = $this->collector->getMetricDefinitions();

        self::assertCount(3, $definitions);

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
    public function itKeepsInterfacesOutOfTheDitPopulation(): void
    {
        $repository = new InMemoryMetricRepository([
            ...$this->collector->getMetricDefinitions(),
            new MetricDefinition('complexity.wmc', SymbolLevel::Class_),
        ]);
        $graph = $this->graph([
            $this->createExtends('App\\Impl', 'App\\Contract'),
        ]);

        $measured = SymbolPath::forClass('App', 'Impl');
        $this->seedDeclaration($repository, 'App\\Impl', (new MetricBag())->with('complexity.wmc', 0));

        $unmeasured = SymbolPath::forClass('App', 'Contract');
        $this->seedDeclaration($repository, 'App\\Contract', new MetricBag(), type: ClassType::Interface_);

        $this->calculate($graph, $repository);

        self::assertSame(1, $this->classMetrics($repository, $measured)->get('design.dit'));
        self::assertNull($this->classMetrics($repository, $unmeasured)->get('design.dit'));
    }

    /**
     * A builtin reached through an external class, one step in. The chain is
     * followed by reading, so the case states the parents rather than relying
     * on a class this process happens to have loaded.
     */
    #[Test]
    public function itCountsABuiltinInsideAnExternalChain(): void
    {
        $collector = new DitGlobalCollector(
            new ExternalAncestry(new FixedParentSource(['Vendor\\Upstream' => 'RuntimeException'])),
        );

        $repository = new InMemoryMetricRepository([
            ...$this->collector->getMetricDefinitions(),
            new MetricDefinition('complexity.wmc', SymbolLevel::Class_),
        ]);
        $graph = $this->graph([$this->createExtends('App\\MyException', 'Vendor\\Upstream')]);

        $path = SymbolPath::forClass('App', 'MyException');
        $this->seedDeclaration($repository, 'App\\MyException', (new MetricBag())->with('complexity.wmc', 0));

        $this->calculate($graph, $repository, $collector);

        self::assertSame(3, $this->classMetrics($repository, $path)->get('design.dit'));
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
            $repository = new InMemoryMetricRepository([
                ...$this->collector->getMetricDefinitions(),
                new MetricDefinition('complexity.wmc', SymbolLevel::Class_),
            ]);
            $graph = $this->graph([
                $this->createExtends('App\\Child', $probe->childFqcn()),
            ]);

            $childPath = SymbolPath::forClass('App', 'Child');
            $this->seedDeclaration($repository, 'App\\Child', (new MetricBag())->with('complexity.wmc', 0));

            // The parent is measured by this run, so it belongs to the project
            // even though nothing records a parent for it.
            $rootPath = SymbolPath::fromClassFqn($probe->childFqcn());
            $this->seedDeclaration($repository, $probe->childFqcn(), (new MetricBag())->with('complexity.wmc', 0));

            $this->calculate($graph, $repository);

            self::assertSame(0, $probe->queryCount(), 'An in-project parent was looked up through an autoloader');
            self::assertSame(1, $this->classMetrics($repository, $childPath)->get('design.dit'));
            self::assertSame(0, $this->classMetrics($repository, $rootPath)->get('design.dit'));
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
        $repository = new InMemoryMetricRepository([
            ...$this->collector->getMetricDefinitions(),
            new MetricDefinition('complexity.wmc', SymbolLevel::Class_),
        ]);
        $fileA = RelativePath::fromString('a.php');
        $fileB = RelativePath::fromString('b.php');

        $graph = $this->graph([
            $this->createExtends('App\\Dup', 'App\\Root', file: $fileA),
            $this->createExtends('App\\Dup', 'App\\Mid', file: $fileB),
            $this->createExtends('App\\Mid', 'App\\Root2'),
        ]);

        $declarationA = $this->seedDeclaration($repository, 'App\\Dup', (new MetricBag())->with('complexity.wmc', 0), $fileA);
        $declarationB = $this->seedDeclaration($repository, 'App\\Dup', (new MetricBag())->with('complexity.wmc', 0), $fileB);
        // The walk reasons about measured declarations, and in a run every
        // class on the path is one.
        $this->seedDeclaration($repository, 'App\\Mid', (new MetricBag())->with('complexity.wmc', 0));

        $this->calculate($graph, $repository);

        self::assertSame(1, $repository->getSubject($declarationA)->get('design.dit'));
        self::assertSame(2, $repository->getSubject($declarationB)->get('design.dit'));
    }

    /** The two declarations retain their separately measured depths. */
    #[Test]
    public function itKeepsDuplicatedNameDepthsOnTheirOwnDeclarations(): void
    {
        $repository = new InMemoryMetricRepository([
            ...$this->collector->getMetricDefinitions(),
            new MetricDefinition('complexity.wmc', SymbolLevel::Class_),
        ]);
        $fileA = RelativePath::fromString('a.php');
        $fileB = RelativePath::fromString('b.php');

        $graph = $this->graph([
            $this->createExtends('App\\Dup', 'App\\Root', file: $fileA),
            $this->createExtends('App\\Dup', 'App\\Mid', file: $fileB),
            $this->createExtends('App\\Mid', 'App\\Root2'),
        ]);

        $declarationA = $this->seedDeclaration($repository, 'App\\Dup', (new MetricBag())->with('complexity.wmc', 0), $fileA);
        $declarationB = $this->seedDeclaration($repository, 'App\\Dup', (new MetricBag())->with('complexity.wmc', 0), $fileB);
        // The walk reasons about measured declarations, and in a run every
        // class on the path is one.
        $this->seedDeclaration($repository, 'App\\Mid', (new MetricBag())->with('complexity.wmc', 0));

        $this->calculate($graph, $repository);

        self::assertSame(1, $repository->getSubject($declarationA)->get('design.dit'));
        self::assertSame(2, $repository->getSubject($declarationB)->get('design.dit'));
    }

    /**
     * `extends Dup` names a name, not a declaration, so a child of that name
     * cannot pick a side: it takes 1 + the deeper of the two declarations.
     */
    #[Test]
    public function itScoresAChildOfADuplicatedParentNameAtMaxPlusOne(): void
    {
        $repository = new InMemoryMetricRepository([
            ...$this->collector->getMetricDefinitions(),
            new MetricDefinition('complexity.wmc', SymbolLevel::Class_),
        ]);
        $fileA = RelativePath::fromString('a.php');
        $fileB = RelativePath::fromString('b.php');

        $graph = $this->graph([
            $this->createExtends('App\\Dup', 'App\\Root', file: $fileA),
            $this->createExtends('App\\Dup', 'App\\Mid', file: $fileB),
            $this->createExtends('App\\Mid', 'App\\Root2'),
            $this->createExtends('App\\GrandChild', 'App\\Dup'),
        ]);

        // Every declaration on the path is seeded: the walk reasons about the
        // declarations a run measured, and in a run they all are.
        $declarationA = $this->seedDeclaration($repository, 'App\\Dup', (new MetricBag())->with('complexity.wmc', 0), $fileA);
        $declarationB = $this->seedDeclaration($repository, 'App\\Dup', (new MetricBag())->with('complexity.wmc', 0), $fileB);
        $this->seedDeclaration($repository, 'App\\Mid', (new MetricBag())->with('complexity.wmc', 0));
        $grandChild = $this->seedDeclaration($repository, 'App\\GrandChild', (new MetricBag())->with('complexity.wmc', 0));

        $this->calculate($graph, $repository);

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
        // Both orders in one test, compared against each other rather than
        // against a constant: with a single order the assertion holds whether
        // the depth was resolved or merely written last.
        $chain = ['App\\Order\\A', 'App\\Order\\B', 'App\\Order\\C', 'App\\Order\\D'];

        $forwards = $this->depthsAfterSeeding($chain);
        $backwards = $this->depthsAfterSeeding(array_reverse($chain));

        self::assertSame($forwards, $backwards);
        self::assertSame(
            ['App\\Order\\A' => 0, 'App\\Order\\B' => 1, 'App\\Order\\C' => 2, 'App\\Order\\D' => 3],
            $forwards,
        );
    }

    /**
     * Resolve the chain A <- B <- C <- D with the declarations seeded in the
     * given order, and report the depth published for each name.
     *
     * @param list<string> $seedOrder
     *
     * @return array<string, int|float|null>
     */
    private function depthsAfterSeeding(array $seedOrder): array
    {
        $repository = new InMemoryMetricRepository([
            ...$this->collector->getMetricDefinitions(),
            new MetricDefinition('complexity.wmc', SymbolLevel::Class_),
        ]);
        $graph = $this->graph([
            $this->createExtends('App\\Order\\D', 'App\\Order\\C'),
            $this->createExtends('App\\Order\\C', 'App\\Order\\B'),
            $this->createExtends('App\\Order\\B', 'App\\Order\\A'),
        ]);

        foreach ($seedOrder as $fqn) {
            $this->seedDeclaration($repository, $fqn, (new MetricBag())->with('complexity.wmc', 0));
        }

        $this->calculate($graph, $repository);

        $depths = [];
        foreach ($seedOrder as $fqn) {
            $depths[$fqn] = $this->classMetrics($repository, SymbolPath::fromClassFqn($fqn))->get('design.dit');
        }
        ksort($depths);

        return $depths;
    }

    #[Test]
    public function itOmitsBothDitValuesForACycleThroughADuplicatedName(): void
    {
        $repository = new InMemoryMetricRepository([
            ...$this->collector->getMetricDefinitions(),
            new MetricDefinition('complexity.wmc', SymbolLevel::Class_),
        ]);
        $fileX = RelativePath::fromString('x.php');
        $fileY = RelativePath::fromString('y.php');

        $graph = $this->graph([
            $this->createExtends('App\\Cyclic', 'App\\Cyclic', file: $fileX),
            $this->createExtends('App\\Cyclic', 'App\\Cyclic', file: $fileY),
        ]);

        $declarationX = $this->seedDeclaration($repository, 'App\\Cyclic', (new MetricBag())->with('complexity.wmc', 0), $fileX);
        $declarationY = $this->seedDeclaration($repository, 'App\\Cyclic', (new MetricBag())->with('complexity.wmc', 0), $fileY);

        $this->calculate($graph, $repository);

        self::assertNull($repository->getSubject($declarationX)->get('design.dit'));
        self::assertSame(1, $repository->getSubject($declarationX)->get('design.dit-unresolved'));
        self::assertNull($repository->getSubject($declarationY)->get('design.dit'));
        self::assertSame(1, $repository->getSubject($declarationY)->get('design.dit-unresolved'));
    }

    #[Test]
    public function itPublishesCompleteNonThrowableExternalRoots(): void
    {
        $collector = new DitGlobalCollector(
            new ExternalAncestry(new FixedParentSource(['Vendor\\Base' => null])),
        );

        $repository = new InMemoryMetricRepository([
            ...$this->collector->getMetricDefinitions(),
            new MetricDefinition('complexity.wmc', SymbolLevel::Class_),
        ]);
        $this->seedDeclaration($repository, 'App\\Child', (new MetricBag())->with('complexity.wmc', 0));

        $this->calculate($this->graph([$this->createExtends('App\\Child', 'Vendor\\Base')]), $repository, $collector);

        self::assertSame(0, $this->classMetrics($repository, SymbolPath::fromClassFqn('App\\Child'))->get('design.dit-unresolved'));
        self::assertSame(0, $this->classMetrics($repository, SymbolPath::fromClassFqn('App\\Child'))->get('design.is-exception'));
    }

    /**
     * A tree whose parents are all absent, builtin or in-project never asks the
     * external source anything, so there is nothing to report even without an
     * install. The silence has to be observable, because it is what makes the
     * no-install case below a statement about chains rather than about config.
     */
    #[Test]
    public function itPublishesCompleteInProjectRootsAndTheirChildren(): void
    {
        $repository = new InMemoryMetricRepository([
            ...$this->collector->getMetricDefinitions(),
            new MetricDefinition('complexity.wmc', SymbolLevel::Class_),
        ]);
        $this->seedDeclaration($repository, 'App\\Root', (new MetricBag())->with('complexity.wmc', 0));
        $this->seedDeclaration($repository, 'App\\Child', (new MetricBag())->with('complexity.wmc', 0));

        $this->calculate(
            $this->graph([$this->createExtends('App\\Child', 'App\\Root')]),
            $repository,
        );

        self::assertSame(0, $this->classMetrics($repository, SymbolPath::fromClassFqn('App\\Root'))->get('design.dit-unresolved'));
        self::assertSame(0, $this->classMetrics($repository, SymbolPath::fromClassFqn('App\\Child'))->get('design.dit-unresolved'));
    }

    #[Test]
    public function itPublishesUnknownExceptionForAnUnreadExternalTail(): void
    {
        $collector = new DitGlobalCollector(
            new ExternalAncestry(new FixedParentSource([])),
        );

        $repository = new InMemoryMetricRepository([
            ...$this->collector->getMetricDefinitions(),
            new MetricDefinition('complexity.wmc', SymbolLevel::Class_),
        ]);
        $this->seedDeclaration($repository, 'App\\Child', (new MetricBag())->with('complexity.wmc', 0));

        $this->calculate($this->graph([$this->createExtends('App\\Child', 'Vendor\\Gone')]), $repository, $collector);

        self::assertIncompletePublication($repository);
    }

    /**
     * The unit is the child declaration, not the ancestor: two classes whose
     * depth stops early are two classes, and counting distinct ancestors would
     * report one.
     */
    #[Test]
    public function itPublishesIncompleteEvidenceForEachChildOfAnUnreadParent(): void
    {
        $collector = new DitGlobalCollector(
            new ExternalAncestry(new FixedParentSource([])),
        );

        $repository = new InMemoryMetricRepository([
            ...$this->collector->getMetricDefinitions(),
            new MetricDefinition('complexity.wmc', SymbolLevel::Class_),
        ]);
        $this->seedDeclaration($repository, 'App\\First', (new MetricBag())->with('complexity.wmc', 0));
        $this->seedDeclaration($repository, 'App\\Second', (new MetricBag())->with('complexity.wmc', 0));

        $this->calculate($this->graph([
            $this->createExtends('App\\First', 'Vendor\\Gone'),
            $this->createExtends('App\\Second', 'Vendor\\Gone'),
        ]), $repository, $collector);

        self::assertIncompletePublication($repository);
    }

    #[Test]
    public function itPublishesAnIncompleteFloorWithoutAnInstall(): void
    {
        $repository = new InMemoryMetricRepository([
            ...$this->collector->getMetricDefinitions(),
            new MetricDefinition('complexity.wmc', SymbolLevel::Class_),
        ]);
        $this->seedDeclaration($repository, 'App\\Child', (new MetricBag())->with('complexity.wmc', 0));

        $this->calculate($this->graph([$this->createExtends('App\\Child', 'Vendor\\Gone')]), $repository);

        self::assertIncompletePublication($repository);
    }

    #[Test]
    public function itOmitsDitForACyclicExternalTail(): void
    {
        $collector = new DitGlobalCollector(
            new ExternalAncestry(new FixedParentSource([
                'Vendor\\A' => 'Vendor\\B',
                'Vendor\\B' => 'Vendor\\A',
            ])),
        );

        $repository = new InMemoryMetricRepository([
            ...$this->collector->getMetricDefinitions(),
            new MetricDefinition('complexity.wmc', SymbolLevel::Class_),
        ]);
        $this->seedDeclaration($repository, 'App\\Child', (new MetricBag())->with('complexity.wmc', 0));

        $this->calculate($this->graph([$this->createExtends('App\\Child', 'Vendor\\A')]), $repository, $collector);

        self::assertIncompletePublication($repository);
        self::assertNull($this->classMetrics($repository, SymbolPath::fromClassFqn('App\\Child'))->get('design.dit'));
    }

    /**
     * The other outcome a looser wording would lie about: the walk gives up
     * after `ExternalAncestry::VISIT_CAP` steps and books the class it had
     * reached -- a class that reads perfectly well, and whose package is
     * installed. "Reading stopped at" would be false of it; "the walk stopped
     * at" is what is true.
     */
    #[Test]
    public function itPublishesTheKnownFloorAtTheExternalVisitCap(): void
    {
        $links = [];

        // Longer than the cap, so the walk runs out of steps rather than
        // reaching a root or failing to place anything.
        for ($step = 0; $step < 80; ++$step) {
            $links['Vendor\\C' . $step] = 'Vendor\\C' . ($step + 1);
        }

        $collector = new DitGlobalCollector(
            new ExternalAncestry(new FixedParentSource($links)),
        );

        $repository = new InMemoryMetricRepository([
            ...$this->collector->getMetricDefinitions(),
            new MetricDefinition('complexity.wmc', SymbolLevel::Class_),
        ]);
        $this->seedDeclaration($repository, 'App\\Child', (new MetricBag())->with('complexity.wmc', 0));

        $this->calculate($this->graph([$this->createExtends('App\\Child', 'Vendor\\C0')]), $repository, $collector);

        self::assertIncompletePublication($repository);
        self::assertSame(65, $this->classMetrics($repository, SymbolPath::fromClassFqn('App\\Child'))->get('design.dit'));
    }

    #[Test]
    public function itDeclaresTheIncompleteAndExceptionSumsAtBothAggregateLevels(): void
    {
        $definitions = $this->collector->getMetricDefinitions();
        foreach ([$definitions[1], $definitions[2]] as $definition) {
            foreach ([SymbolLevel::Namespace_, SymbolLevel::Project] as $level) {
                self::assertSame([AggregationStrategy::Sum], $definition->getStrategiesForLevel($level));
            }
        }
    }

    #[Test]
    public function itPublishesTheFullPositiveRosterWithoutALocalDitSeed(): void
    {
        $repository = new InMemoryMetricRepository($this->collector->getMetricDefinitions());
        $facts = [];
        foreach ([ClassType::Class_, ClassType::Interface_, ClassType::Trait_, ClassType::Enum_] as $i => $type) {
            $declaration = $this->declarationFor('App\\T' . $i);
            $facts[] = ClassLikeDeclaration::of($declaration, $type, false, false);
            $repository->addSubject(MetricSubject::declaration($declaration), new MetricBag(), $declaration->file, 1);
        }
        $graph = AdjacencyGraphBuilder::builder()->build([], $facts)->graph;
        $this->collector->calculate($graph, $repository);
        foreach ($facts as $i => $fact) {
            $bag = $repository->getSubject(MetricSubject::declaration($fact->declaration));
            self::assertSame($i === 0 ? 0 : null, $bag->get('design.dit'));
            self::assertSame($i === 0 ? 0 : null, $bag->get('design.dit-unresolved'));
            self::assertSame(0, $bag->get('design.is-exception'));
        }
    }

    #[Test]
    public function itPublishesLoopOmissionAndUnknownClassifierWithoutFallbackScalars(): void
    {
        $repository = new InMemoryMetricRepository($this->collector->getMetricDefinitions());
        $loop = $this->seedDeclaration($repository, 'App\\Loop', new MetricBag());
        $floor = $this->seedDeclaration($repository, 'App\\Floor', new MetricBag());
        $this->calculate($this->graph([
            $this->createExtends('App\\Loop', 'app\\loop'),
            $this->createExtends('App\\Floor', 'Vendor\\Unread'),
        ]), $repository);
        self::assertNull($repository->getSubject($loop)->get('design.dit'));
        self::assertSame(1, $repository->getSubject($floor)->get('design.dit'));
        foreach ([$loop, $floor] as $subject) {
            self::assertSame(1, $repository->getSubject($subject)->get('design.dit-unresolved'));
            self::assertFalse($repository->getSubject($subject)->has('design.is-exception'));
        }
    }

    #[Test]
    public function itRefusesAGraphClassWhoseExactRepositoryRecordIsMissing(): void
    {
        $fact = ClassLikeDeclaration::of($this->declarationFor('App\\Missing'), ClassType::Class_, false, false);
        $graph = AdjacencyGraphBuilder::builder()->build([], [$fact])->graph;
        self::expectException(LogicException::class);
        self::expectExceptionMessage('requires an exact repository subject');
        $this->collector->calculate($graph, new InMemoryMetricRepository($this->collector->getMetricDefinitions()));
    }

    private static function assertIncompletePublication(InMemoryMetricRepository $repository): void
    {
        $population = iterator_to_array($repository->allClassDeclarations());
        self::assertNotEmpty($population);
        foreach ($population as $info) {
            self::assertNotNull($info->subject);
            $metrics = $repository->getSubject($info->subject);
            self::assertSame(1, $metrics->get('design.dit-unresolved'));
            self::assertFalse($metrics->has('design.is-exception'));
        }
    }

    private function calculate(DependencyGraphInterface $graph, InMemoryMetricRepository $repository, ?DitGlobalCollector $collector = null): void
    {
        $dependencies = $graph->getDeclarationDependencies();
        ($collector ?? $this->collector)->calculate(AdjacencyGraphBuilder::builder()->build($dependencies, array_values($this->facts))->graph, $repository);
    }

    /** @param list<Dependency> $dependencies */
    private function graph(array $dependencies): DependencyGraphInterface
    {
        $universe = array_map(
            static fn(Dependency $dependency): ClassLikeDeclaration => ClassLikeDeclaration::of(
                $dependency->source,
                ClassType::Class_,
                false,
                false,
            ),
            $dependencies,
        );

        return AdjacencyGraphBuilder::builder()->build($dependencies, $universe)->graph;
    }
    private function classMetrics(InMemoryMetricRepository $repository, SymbolPath $logical): MetricBag
    {
        $subjects = [];
        foreach ($repository->allClassDeclarations() as $info) {
            if ($info->symbolPath->toCanonical() === $logical->toCanonical()) {
                $subjects[] = $info->subject;
            }
        }
        self::assertCount(1, $subjects, 'Class fixture must identify exactly one declaration');
        self::assertNotNull($subjects[0]);

        return $repository->getSubject($subjects[0]);
    }
}

/**
 * A non-standard class whose own parent is a builtin: the shape that makes the
 * walk continue one step before it stops.
 */
class DitChainCustomException extends RuntimeException {}
