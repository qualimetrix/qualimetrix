<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Analysis\Policy\Architecture\Unit\Domain\Layer;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Evidence\DependencyModel\Contract\Dependency;
use Qualimetrix\Analysis\Evidence\DependencyModel\Contract\DependencyGraphInterface;
use Qualimetrix\Analysis\Evidence\DependencyModel\Contract\DependencyType;
use Qualimetrix\Analysis\Finding\Contract\Location;
use Qualimetrix\Analysis\Policy\Architecture\Layer\ClassContext;
use Qualimetrix\Analysis\Policy\Architecture\Layer\ClassContextFactory;
use Qualimetrix\Core\Path\RelativePath;
use Qualimetrix\Core\Symbol\DeclarationOrdinal;
use Qualimetrix\Core\Symbol\DeclarationPath;
use Qualimetrix\Core\Symbol\LogicalClassPath;
use Qualimetrix\Core\Symbol\SymbolPath;

#[CoversClass(ClassContextFactory::class)]
#[CoversClass(ClassContext::class)]
final class ClassContextFactoryTest extends TestCase
{
    private const bool INTERFACE_EXTENDS = true;

    #[Test]
    public function itBuildsAMinimalContextWithoutABoundGraph(): void
    {
        $factory = new ClassContextFactory();

        $context = $factory->build(SymbolPath::forClass('App\\Service', 'UserService'));

        self::assertSame('App\\Service\\UserService', $context->fqn);
        self::assertSame('UserService', $context->shortName);
        self::assertSame([], $context->attributeFqns);
        self::assertSame([], $context->interfaces);
        self::assertSame([], $context->parentClasses);
        self::assertFalse(
            $context->graphBacked,
            'Three empty lists with no graph behind them are the absence of an answer, not one.',
        );
    }

    #[Test]
    public function itBuildsAMinimalContextForANamespacePathEvenWithABoundGraph(): void
    {
        $factory = new ClassContextFactory();
        $factory->bindGraph(self::graphWith([]));

        $context = $factory->build(SymbolPath::forNamespace('App\\Service'));

        self::assertSame('App\\Service', $context->fqn);
        self::assertSame('Service', $context->shortName);
        self::assertSame([], $context->attributeFqns);
        self::assertSame([], $context->interfaces);
        self::assertSame([], $context->parentClasses);
        self::assertTrue(
            $context->graphBacked,
            'A namespace has no attributes, interfaces or parents — with a graph bound that is an answer, '
            . 'and a graph-backed criterion asked about it must get a non-match rather than a refusal.',
        );
    }

    #[Test]
    public function itKeepsAClassAndTheNamespaceOfItsOwnNameApart(): void
    {
        // Both resolve to the same FQN. Whichever was asked for first used to
        // answer for the other, and observation now warms the cache before any
        // runtime lookup, so it would always be the class that lost.
        $command = SymbolPath::forClass('App\\Console', 'Command');
        $base = SymbolPath::forClass('App\\Console', 'Base');

        $factory = new ClassContextFactory();
        $factory->bindGraph(self::graphWith([[$command, $base, DependencyType::Extends]]));

        $namespaceContext = $factory->build(SymbolPath::forNamespace('App\\Console\\Command'));
        self::assertSame([], $namespaceContext->parentClasses);

        $classContext = $factory->build($command);
        self::assertSame(['App\\Console\\Base'], $classContext->parentClasses);
    }

    #[Test]
    public function itCollectsDirectAttributesInterfacesAndParentFromTheGraph(): void
    {
        $userService = SymbolPath::forClass('App\\Service', 'UserService');
        $abstractService = SymbolPath::forClass('App\\Service', 'AbstractService');
        $entityAttr = SymbolPath::forClass('App\\Attr', 'Entity');
        $serviceInterface = SymbolPath::forClass('App\\Contracts', 'Service');

        $factory = new ClassContextFactory();
        $factory->bindGraph(self::graphWith([
            [$userService, $abstractService, DependencyType::Extends],
            [$userService, $serviceInterface, DependencyType::Implements],
            [$userService, $entityAttr, DependencyType::Attribute],
        ]));

        $context = $factory->build($userService);

        self::assertSame(['App\\Attr\\Entity'], $context->attributeFqns);
        self::assertSame(['App\\Contracts\\Service'], $context->interfaces);
        self::assertSame(['App\\Service\\AbstractService'], $context->parentClasses);
    }

    #[Test]
    public function itWalksTheTransitiveParentChain(): void
    {
        $derived = SymbolPath::forClass('App\\Domain', 'User');
        $base = SymbolPath::forClass('App\\Domain', 'AbstractUser');
        $root = SymbolPath::forClass('App\\Domain', 'AggregateRoot');

        $factory = new ClassContextFactory();
        $factory->bindGraph(self::graphWith([
            [$derived, $base, DependencyType::Extends],
            [$base, $root, DependencyType::Extends],
        ]));

        $context = $factory->build($derived);

        self::assertSame(
            ['App\\Domain\\AbstractUser', 'App\\Domain\\AggregateRoot'],
            $context->parentClasses,
        );
    }

    #[Test]
    public function itIncludesInterfacesInheritedFromAParentClass(): void
    {
        $derived = SymbolPath::forClass('App\\Domain', 'User');
        $base = SymbolPath::forClass('App\\Domain', 'AbstractUser');
        $iface = SymbolPath::forClass('App\\Contracts', 'AggregateRoot');

        $factory = new ClassContextFactory();
        $factory->bindGraph(self::graphWith([
            [$derived, $base, DependencyType::Extends],
            [$base, $iface, DependencyType::Implements],
        ]));

        $context = $factory->build($derived);

        self::assertSame(['App\\Contracts\\AggregateRoot'], $context->interfaces);
    }

    #[Test]
    public function itWalksTransitiveInterfaceExtension(): void
    {
        // Test scenario from plan: class implements Sub; Sub extends Base.
        $klass = SymbolPath::forClass('App\\Repo', 'UserRepository');
        $sub = SymbolPath::forClass('App\\Repo', 'UserRepositoryInterface');
        $base = SymbolPath::forClass('Doctrine\\Persistence', 'ObjectRepository');

        $factory = new ClassContextFactory();
        $factory->bindGraph(self::graphWith([
            [$klass, $sub, DependencyType::Implements],
            [$sub, $base, DependencyType::Extends],
        ]));

        $context = $factory->build($klass);

        self::assertContains('App\\Repo\\UserRepositoryInterface', $context->interfaces);
        self::assertContains('Doctrine\\Persistence\\ObjectRepository', $context->interfaces);
    }

    #[Test]
    public function itCountsTheInterfacesAnInterfaceExtendsAmongItsInterfaces(): void
    {
        // `getInterfaceNames()` semantics, which PHP's own interfaces already
        // get from the builtin table: `\OuterIterator` answers "yes" to
        // `implements: ['\Iterator']`, so an analysed interface extending
        // `\IteratorAggregate` must too, and not only for what is above it.
        $bag = SymbolPath::forClass('App\\Library', 'Bag');
        $child = SymbolPath::forClass('App\\Library', 'SortedBag');

        $factory = new ClassContextFactory();
        $factory->bindGraph(self::graphWith([
            [$bag, SymbolPath::fromClassFqn('IteratorAggregate'), DependencyType::Extends, self::INTERFACE_EXTENDS],
            [$child, $bag, DependencyType::Extends, self::INTERFACE_EXTENDS],
        ]), [$bag, $child]);

        self::assertSame(['IteratorAggregate', 'Traversable'], $factory->build($bag)->interfaces);
        self::assertSame(['App\\Library\\Bag', 'IteratorAggregate', 'Traversable'], $factory->build($child)->interfaces);
    }

    #[Test]
    public function itCountsEveryInterfaceUpAChainTheProjectWrites(): void
    {
        $leaf = SymbolPath::forClass('App\\Library', 'Leaf');
        $mid = SymbolPath::forClass('App\\Library', 'Mid');
        $root = SymbolPath::forClass('App\\Library', 'Root');

        $factory = new ClassContextFactory();
        $factory->bindGraph(self::graphWith([
            [$leaf, $mid, DependencyType::Extends, self::INTERFACE_EXTENDS],
            [$mid, $root, DependencyType::Extends, self::INTERFACE_EXTENDS],
        ]), [$leaf, $mid, $root]);

        $context = $factory->build($leaf);

        self::assertSame(['App\\Library\\Mid', 'App\\Library\\Root'], $context->interfaces);
        self::assertSame(['App\\Library\\Mid', 'App\\Library\\Root'], $context->parentClasses);
        self::assertTrue($context->interfacesKnown());
        // An interface extending nothing has nothing to add.
        self::assertSame([], $factory->build($root)->interfaces);
    }

    #[Test]
    public function itKeepsTheParentsOfAClassOutOfItsInterfaces(): void
    {
        // The neighbour the interface seeding must not swallow: a parent class
        // is not an interface its subclass implements, so `implements:
        // [SomeClass]` stays off the subclasses — a PHP parent class included.
        $sub = SymbolPath::forClass('App\\Domain', 'Sub');
        $base = SymbolPath::forClass('App\\Domain', 'Base');
        $root = SymbolPath::forClass('App\\Domain', 'Root');
        $failure = SymbolPath::forClass('App\\Domain', 'Failure');

        $factory = new ClassContextFactory();
        $factory->bindGraph(self::graphWith([
            [$sub, $base, DependencyType::Extends],
            [$base, $root, DependencyType::Extends],
            [$failure, SymbolPath::fromClassFqn('RuntimeException'), DependencyType::Extends],
        ]), [$sub, $base, $root, $failure]);

        self::assertSame([], $factory->build($sub)->interfaces);
        self::assertSame(['App\\Domain\\Base', 'App\\Domain\\Root'], $factory->build($sub)->parentClasses);
        self::assertNotContains('RuntimeException', $factory->build($failure)->interfaces);
        self::assertNotContains('Exception', $factory->build($failure)->interfaces);
        self::assertContains('Throwable', $factory->build($failure)->interfaces);
    }

    #[Test]
    public function itCountsAnUnreadInterfaceAnInterfaceExtendsAndSaysWhereItStopped(): void
    {
        // The edge was recorded from the analysed side, so the direct vendor
        // parent is known; what it extends in turn is not.
        $port = SymbolPath::forClass('App\\Library', 'Port');

        $factory = new ClassContextFactory();
        $factory->bindGraph(self::graphWith([
            [$port, SymbolPath::forClass('Vendor\\Contract', 'Thing'), DependencyType::Extends, self::INTERFACE_EXTENDS],
        ]), [$port]);

        $context = $factory->build($port);

        self::assertSame(['Vendor\\Contract\\Thing'], $context->interfaces);
        self::assertSame(
            ['parentChain' => ['Vendor\\Contract\\Thing'], 'interfaces' => ['Vendor\\Contract\\Thing']],
            $context->ancestryCuts,
        );
        self::assertFalse($context->interfacesKnown());
    }

    #[Test]
    public function itDeduplicatesRepeatedAttributeAndRelationEdges(): void
    {
        // Same target referenced through multiple edges (e.g. two #[Attr]
        // occurrences at different lines) collapses into a single entry.
        $klass = SymbolPath::forClass('App\\Domain', 'User');
        $attr = SymbolPath::forClass('App\\Attr', 'Audit');

        $factory = new ClassContextFactory();
        $factory->bindGraph(self::graphWith([
            [$klass, $attr, DependencyType::Attribute],
            [$klass, $attr, DependencyType::Attribute],
        ]));

        self::assertSame(['App\\Attr\\Audit'], $factory->build($klass)->attributeFqns);
    }

    #[Test]
    public function itResetsCachedContextsWhenTheGraphIsRebound(): void
    {
        $klass = SymbolPath::forClass('App\\Domain', 'User');
        $parentA = SymbolPath::forClass('App\\Domain', 'BaseA');
        $parentB = SymbolPath::forClass('App\\Domain', 'BaseB');

        $factory = new ClassContextFactory();

        $factory->bindGraph(self::graphWith([[$klass, $parentA, DependencyType::Extends]]));
        self::assertSame(['App\\Domain\\BaseA'], $factory->build($klass)->parentClasses);

        $factory->bindGraph(self::graphWith([[$klass, $parentB, DependencyType::Extends]]));
        self::assertSame(['App\\Domain\\BaseB'], $factory->build($klass)->parentClasses);
    }

    #[Test]
    public function itSwitchesBackToAMinimalContextWhenTheGraphIsUnbound(): void
    {
        $klass = SymbolPath::forClass('App\\Domain', 'User');
        $parent = SymbolPath::forClass('App\\Domain', 'Base');

        $factory = new ClassContextFactory();
        $factory->bindGraph(self::graphWith([[$klass, $parent, DependencyType::Extends]]));
        self::assertNotSame([], $factory->build($klass)->parentClasses);

        $factory->bindGraph(null);
        self::assertSame([], $factory->build($klass)->parentClasses);
    }

    #[Test]
    public function itMemoizesTheBuiltContextAcrossRepeatedCalls(): void
    {
        $klass = SymbolPath::forClass('App\\Domain', 'User');
        $parent = SymbolPath::forClass('App\\Domain', 'Base');

        $factory = new ClassContextFactory();
        $factory->bindGraph(self::graphWith([[$klass, $parent, DependencyType::Extends]]));

        $first = $factory->build($klass);
        $second = $factory->build($klass);

        self::assertSame($first, $second, 'Repeated build() calls for the same FQN must hand back the same instance.');
    }

    #[Test]
    public function itIgnoresDependencyTypesOtherThanExtendsImplementsAndAttribute(): void
    {
        // Only Extends/Implements/Attribute should feed ClassContext. Other
        // dependency kinds (TypeHint, New_, etc.) must be ignored.
        $klass = SymbolPath::forClass('App', 'A');
        $other = SymbolPath::forClass('App', 'B');

        $factory = new ClassContextFactory();
        $factory->bindGraph(self::graphWith([
            [$klass, $other, DependencyType::TypeHint],
            [$klass, $other, DependencyType::New_],
            [$klass, $other, DependencyType::StaticCall],
        ]));

        $context = $factory->build($klass);
        self::assertSame([], $context->attributeFqns);
        self::assertSame([], $context->interfaces);
        self::assertSame([], $context->parentClasses);
    }

    /**
     * The optional fourth element of an edge marks an `Extends` edge an
     * interface declares.
     *
     * @param list<array{0: SymbolPath, 1: SymbolPath, 2: DependencyType, 3?: bool}> $edges
     */
    private static function graphWith(array $edges): DependencyGraphInterface
    {
        $deps = [];
        foreach ($edges as $edge) {
            [$source, $target, $type] = $edge;
            $deps[] = new Dependency(
                DeclarationPath::of($source, RelativePath::fromString('test.php'), DeclarationOrdinal::fromRank(0)),
                new LogicalClassPath($target),
                $type,
                Location::none(),
                interfaceExtends: $edge[3] ?? false,
            );
        }

        return new readonly class ($deps) implements DependencyGraphInterface {
            /**
             * @param list<Dependency> $deps
             */
            public function __construct(private array $deps) {}

            public function getClassDependencies(SymbolPath $class): array
            {
                return [];
            }

            public function getClassDependents(SymbolPath $class): array
            {
                return [];
            }

            public function getClassCe(SymbolPath $class): int
            {
                return 0;
            }

            public function getClassCa(SymbolPath $class): int
            {
                return 0;
            }

            public function getNamespaceCe(SymbolPath $namespace): int
            {
                return 0;
            }

            public function getNamespaceCa(SymbolPath $namespace): int
            {
                return 0;
            }

            public function getNamespaceOwnCe(SymbolPath $namespace): int
            {
                return 0;
            }

            public function getNamespaceOwnCa(SymbolPath $namespace): int
            {
                return 0;
            }

            public function getAllClasses(): array
            {
                return [];
            }

            public function getAllNamespaces(): array
            {
                return [];
            }

            public function getAllDependencies(): array
            {
                return $this->deps;
            }

            public function getDeclarationDependencies(): array
            {
                return $this->deps;
            }
        };
    }
}
