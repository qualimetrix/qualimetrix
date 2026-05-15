<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Architecture\Unit\Domain\Layer;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Architecture\Domain\Layer\ClassContext;
use Qualimetrix\Architecture\Domain\Layer\ClassContextFactory;
use Qualimetrix\Core\Dependency\Dependency;
use Qualimetrix\Core\Dependency\DependencyGraphInterface;
use Qualimetrix\Core\Dependency\DependencyType;
use Qualimetrix\Core\Symbol\SymbolPath;
use Qualimetrix\Core\Violation\Location;

#[CoversClass(ClassContextFactory::class)]
#[CoversClass(ClassContext::class)]
final class ClassContextFactoryTest extends TestCase
{
    #[Test]
    public function build_withoutGraph_returnsMinimalContext(): void
    {
        $factory = new ClassContextFactory();

        $context = $factory->build(SymbolPath::forClass('App\\Service', 'UserService'));

        self::assertSame('App\\Service\\UserService', $context->fqn);
        self::assertSame('UserService', $context->shortName);
        self::assertSame([], $context->attributeFqns);
        self::assertSame([], $context->interfaces);
        self::assertSame([], $context->parentClasses);
    }

    #[Test]
    public function build_pureNamespacePath_returnsMinimalContextEvenWhenGraphIsBound(): void
    {
        $factory = new ClassContextFactory();
        $factory->bindGraph(self::graphWith([]));

        $context = $factory->build(SymbolPath::forNamespace('App\\Service'));

        self::assertSame('App\\Service', $context->fqn);
        self::assertSame('Service', $context->shortName);
        self::assertSame([], $context->attributeFqns);
        self::assertSame([], $context->interfaces);
        self::assertSame([], $context->parentClasses);
    }

    #[Test]
    public function build_collectsDirectAttributesInterfacesAndParent(): void
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
    public function build_walksTransitiveParentChain(): void
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
    public function build_includesInterfacesInheritedFromParentClass(): void
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
    public function build_walksTransitiveInterfaceExtension(): void
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
    public function build_deduplicatesAttributeAndRelationLists(): void
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
    public function bindGraph_resetsInternalCachesAndContexts(): void
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
    public function bindGraph_withNull_switchesBackToMinimalContext(): void
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
    public function build_resultsAreMemoizedAcrossCalls(): void
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
    public function build_ignoresUnrelatedDependencyTypes(): void
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
     * @param list<array{0: SymbolPath, 1: SymbolPath, 2: DependencyType}> $edges
     */
    private static function graphWith(array $edges): DependencyGraphInterface
    {
        $deps = [];
        foreach ($edges as [$source, $target, $type]) {
            $deps[] = new Dependency($source, $target, $type, Location::none());
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
        };
    }
}
