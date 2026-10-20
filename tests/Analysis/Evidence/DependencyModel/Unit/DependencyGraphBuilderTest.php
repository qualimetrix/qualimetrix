<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Analysis\Evidence\DependencyModel\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Evidence\DependencyModel\CanonicalGraphInput;
use Qualimetrix\Analysis\Evidence\DependencyModel\Contract\ClassLikeDeclaration;
use Qualimetrix\Analysis\Evidence\DependencyModel\Contract\Dependency;
use Qualimetrix\Analysis\Evidence\DependencyModel\Contract\DependencyGraphInterface;
use Qualimetrix\Analysis\Evidence\DependencyModel\Contract\DependencyType;
use Qualimetrix\Analysis\Evidence\DependencyModel\Contract\ExternalClassSpellingInterface;
use Qualimetrix\Analysis\Evidence\DependencyModel\DependencyGraphBuilder;
use Qualimetrix\Analysis\Evidence\DependencyModel\DependencyIdentityCanonicalizer;
use Qualimetrix\Analysis\Evidence\DependencyModel\NamespaceCouplingBuilder;
use Qualimetrix\Analysis\Evidence\DependencyModel\UnplacedExternalClassSpelling;
use Qualimetrix\Analysis\Finding\Contract\Location;
use Qualimetrix\Core\Path\RelativePath;
use Qualimetrix\Core\Symbol\ClassType;
use Qualimetrix\Core\Symbol\DeclarationOrdinal;
use Qualimetrix\Core\Symbol\DeclarationPath;
use Qualimetrix\Core\Symbol\LogicalClassPath;
use Qualimetrix\Core\Symbol\SymbolPath;

#[CoversClass(DependencyGraphBuilder::class)]
#[CoversClass(CanonicalGraphInput::class)]
#[CoversClass(DependencyIdentityCanonicalizer::class)]
#[CoversClass(NamespaceCouplingBuilder::class)]
final class DependencyGraphBuilderTest extends TestCase
{
    #[Test]
    public function itPreservesOnlyNamedClassSelfExtendsInTheDeclarationView(): void
    {
        foreach (['App\\A', 'app\\a'] as $target) {
            $edge = self::dependency('App\\A', $target, DependencyType::Extends);
            $graph = self::builder()->build([$edge, self::dependency('App\\A', $target, DependencyType::StaticCall)], [self::declaration('App\\A')])->graph;
            self::assertCount(1, $graph->getDeclarationDependencies());
            self::assertSame($edge->source, $graph->getDeclarationDependencies()[0]->source);
            self::assertSame($edge->location, $graph->getDeclarationDependencies()[0]->location);
            self::assertSame([], $graph->getAllDependencies());
            self::assertSame([], $graph->getClassDependencies(self::logical('App\\A')->symbolPath));
            self::assertSame(0, $graph->getClassCe(self::logical('App\\A')->symbolPath));
            self::assertSame(0, $graph->getClassCa(self::logical('App\\A')->symbolPath));
        }
    }

    #[Test]
    public function itDoesNotWidenSelfDeclarationFactsToInterfacesOrNestedAnonymousClasses(): void
    {
        $interface = self::dependency('App\\I', 'app\\i', DependencyType::Extends);
        $classEdge = self::dependency('App\\A', 'app\\a', DependencyType::Extends);
        $nested = Dependency::ofClassLike($classEdge->source, new LogicalClassPath($classEdge->targetLogical()), DependencyType::Extends, $classEdge->location, true, false);
        $graph = self::builder()->build([$interface, $nested], [self::declaration('App\\I', ClassType::Interface_), self::declaration('App\\A')])->graph;
        self::assertSame([], $graph->getDeclarationDependencies());
        self::assertSame([], $graph->getAllDependencies());
    }

    #[Test]
    public function itKeepsTypedFactsForDegreeZeroDeclarations(): void
    {
        $declaration = self::declaration('App\\Standalone', ClassType::Trait_, false, true);
        $graph = self::builder()->build([], [$declaration])->graph;

        self::assertSame([$declaration], $graph->getClassLikeDeclarations());
        self::assertSame(['class:App\\Standalone'], self::canonicalPaths($graph->getAllClasses()));
    }

    #[Test]
    public function itKeepsDegreeZeroDeclarationsAndUndeclaredExternalTargets(): void
    {
        $standalone = self::logical('App\Feature\Standalone');
        $service = self::logical('App\Service\Worker');
        $outgoing = self::dependency('App\Service\Worker', 'Vendor\Contracts\Api', DependencyType::Implements);
        $incoming = self::dependency('Vendor\Producer', 'App\Service\Worker', DependencyType::TypeHint);
        $graph = self::builder()->build(
            [$outgoing, $incoming],
            [self::declaration('App\Feature\Standalone'), self::declaration('App\Service\Worker')],
        )->graph;

        self::assertSame(
            [
                'class:App\Feature\Standalone',
                'class:App\Service\Worker',
                'class:Vendor\Contracts\Api',
                'class:Vendor\Producer',
            ],
            self::canonicalPaths($graph->getAllClasses()),
        );
        self::assertSame(
            [
                'ns:App\Feature',
                'ns:App\Service',
                'ns:Vendor\Contracts',
                'ns:Vendor',
                'ns:App',
            ],
            self::canonicalPaths($graph->getAllNamespaces()),
        );
        self::assertSame(
            [
                ['class:App\Service\Worker', 'class:Vendor\Contracts\Api', DependencyType::Implements, 'src/Fixture.php:1'],
                ['class:Vendor\Producer', 'class:App\Service\Worker', DependencyType::TypeHint, 'src/Fixture.php:1'],
            ],
            self::dependencyFields($graph->getAllDependencies()),
        );
        self::assertSame([$outgoing], $graph->getClassDependencies($service->symbolPath));
        self::assertSame([$incoming], $graph->getClassDependents($service->symbolPath));
        self::assertSame([], $graph->getClassDependencies($standalone->symbolPath));
        self::assertSame([], $graph->getClassDependents($standalone->symbolPath));
        self::assertSame(0, $graph->getClassCe($standalone->symbolPath));
        self::assertSame(0, $graph->getClassCa($standalone->symbolPath));
        self::assertSame(1, $graph->getClassCe($service->symbolPath));
        self::assertSame(1, $graph->getClassCa($service->symbolPath));
        self::assertSame(1, $graph->getNamespaceCe(SymbolPath::forNamespace('App')));
        self::assertSame(1, $graph->getNamespaceCa(SymbolPath::forNamespace('App')));
    }

    #[Test]
    public function itCanonicalizesPhpClassIdentityWithoutChangingExactDeclarations(): void
    {
        $lowerDeclaration = self::declaration('app\\service', ClassType::Trait_, true, true);
        $upperDeclaration = self::declaration('App\\Service');
        $lowerEdge = self::dependency('app\\service', 'vendor\\foo', DependencyType::TypeHint);
        $upperEdge = self::dependency('App\\Service', 'Vendor\\FOO', DependencyType::New_);
        $caseOnlySelfEdge = self::dependency('app\\service', 'APP\\SERVICE', DependencyType::StaticCall);

        $build = self::builder()->build(
            [$lowerEdge, $upperEdge, $caseOnlySelfEdge],
            [$lowerDeclaration, $upperDeclaration],
        );
        $graph = $build->graph;

        self::assertSame(
            [
                ['class:App\\Service', 'class:Vendor\\FOO', DependencyType::TypeHint, 'src/Fixture.php:1'],
                ['class:App\\Service', 'class:Vendor\\FOO', DependencyType::New_, 'src/Fixture.php:1'],
            ],
            self::dependencyFields($graph->getAllDependencies()),
        );
        self::assertSame('app\\service', $graph->getAllDependencies()[0]->source->logical->toString());

        $facts = $graph->getClassLikeDeclarations();
        self::assertCount(2, $facts);
        self::assertSame(['app\\service', 'App\\Service'], array_map(
            static fn(ClassLikeDeclaration $fact): string => $fact->declaration->logical->toString(),
            $facts,
        ));
        self::assertSame(['App\\Service', 'App\\Service'], array_map(
            static fn(ClassLikeDeclaration $fact): string => $fact->logical->symbolPath->toString(),
            $facts,
        ));
        self::assertSame(ClassType::Trait_, $facts[0]->type);
        self::assertTrue($facts[0]->declaresToString);
        self::assertTrue($facts[0]->aliasesTraitMethodAsToString);
        self::assertSame(['class', 'external'], array_column($build->mixedSpellings, 'kind'));
        self::assertSame(['App\\Service', 'app\\service'], $build->mixedSpellings[0]->spellings);
        self::assertSame(['Vendor\\FOO', 'vendor\\foo'], $build->mixedSpellings[1]->spellings);
    }

    #[Test]
    public function itUsesTheInstalledDeclarationSpellingForAnExternalIdentity(): void
    {
        $spelling = new class implements ExternalClassSpellingInterface {
            public function declaredSpelling(string $className): ?string
            {
                return strcasecmp($className, 'Vendor\\Foo') === 0 ? 'vendor\\Foo' : null;
            }
        };

        $build = self::builder($spelling)->build(
            [
                self::dependency('App\\First', 'Vendor\\FOO', DependencyType::TypeHint),
                self::dependency('App\\Second', 'vendor\\foo', DependencyType::New_),
            ],
            [self::declaration('App\\First'), self::declaration('App\\Second')],
        );

        self::assertSame(
            ['class:vendor\\Foo', 'class:vendor\\Foo'],
            array_map(
                static fn(Dependency $dependency): string => $dependency->targetLogical()->toCanonical(),
                $build->graph->getAllDependencies(),
            ),
        );
        self::assertSame('external', $build->mixedSpellings[0]->kind);
        self::assertSame('vendor\\Foo', $build->mixedSpellings[0]->canonical);
    }

    #[Test]
    public function itDropsSelfReferencesAfterCanonicalization(): void
    {
        $build = self::builder()->build(
            [
                self::dependency('App\\SelfRef', 'app\\selfref', DependencyType::TypeHint),
                self::dependency('App\\SelfRef', 'App\\SelfRef', DependencyType::StaticCall),
            ],
            [self::declaration('App\\SelfRef')],
        );

        self::assertSame([], $build->graph->getAllDependencies());
        self::assertSame(0, $build->graph->getClassCe(SymbolPath::fromClassFqn('App\\SelfRef')));
        self::assertSame(0, $build->graph->getClassCa(SymbolPath::fromClassFqn('App\\SelfRef')));
    }

    /**
     * An `extends` edge to a PHP class stays in the edge list, where DIT and
     * NOC read inheritance from, but counts toward no coupling: the same class
     * written as `implements` or as a type hint already counts nothing, and
     * how a class names PHP's type is not a measure of its coupling.
     */
    #[Test]
    public function itFiltersBuiltinCouplingButRetainsBuiltinInheritanceForInheritanceReadersOnly(): void
    {
        $filtered = self::dependency('App\Service', 'Exception', DependencyType::New_);
        $inheritance = self::dependency('App\Failure', 'Exception', DependencyType::Extends);
        $graph = self::builder()->build([
            $filtered,
            $inheritance,
        ], [self::declaration('App\Service'), self::declaration('App\Failure')])->graph;

        self::assertSame([$inheritance], $graph->getAllDependencies());
        self::assertSame(
            [['class:App\Failure', 'class:Exception', DependencyType::Extends, 'src/Fixture.php:1']],
            self::dependencyFields($graph->getAllDependencies()),
        );
        self::assertSame(
            ['class:App\Service', 'class:App\Failure', 'class:Exception'],
            self::canonicalPaths($graph->getAllClasses()),
        );
        self::assertSame([], $graph->getClassDependencies(SymbolPath::fromClassFqn('App\Failure')));
        self::assertSame([], $graph->getClassDependents(SymbolPath::fromClassFqn('Exception')));
        self::assertSame(0, $graph->getClassCe(SymbolPath::fromClassFqn('App\Service')));
        self::assertSame(0, $graph->getClassCe(SymbolPath::fromClassFqn('App\Failure')));
        self::assertSame(0, $graph->getClassCa(SymbolPath::fromClassFqn('Exception')));
        self::assertSame(0, $graph->getNamespaceCe(SymbolPath::forNamespace('App')));
        self::assertSame(0, $graph->getNamespaceOwnCe(SymbolPath::forNamespace('App')));
    }

    /**
     * The neighbour the exclusion must not reach: `extends` on a project or
     * vendor class is coupling like any other edge.
     */
    #[Test]
    public function itCountsAnExtendsEdgeToANonPhpClassAsCoupling(): void
    {
        $project = self::dependency('App\Domain\Child', 'App\Model\Base', DependencyType::Extends);
        $vendor = self::dependency('App\Domain\Child', 'Vendor\Base', DependencyType::Extends);
        $graph = self::builder()->build([$project, $vendor], [self::declaration('App\Domain\Child')])->graph;

        self::assertSame([$project, $vendor], $graph->getClassDependencies(SymbolPath::fromClassFqn('App\Domain\Child')));
        self::assertSame(2, $graph->getClassCe(SymbolPath::fromClassFqn('App\Domain\Child')));
        self::assertSame(1, $graph->getClassCa(SymbolPath::fromClassFqn('Vendor\Base')));
        self::assertSame(2, $graph->getNamespaceCe(SymbolPath::forNamespace('App\Domain')));
        self::assertSame(1, $graph->getNamespaceCe(SymbolPath::forNamespace('App')));
    }

    #[Test]
    public function itKeepsADeclarationEdgeToAPhpTypeForDeclarationReadersOnly(): void
    {
        $implementsPhp = self::dependency('App\Domain\Snapshot', 'JsonSerializable', DependencyType::Implements);
        $attributePhp = self::dependency('App\Domain\Tagged', 'AllowDynamicProperties', DependencyType::Attribute);
        $extendsPhp = self::dependency('App\Domain\Failure', 'Exception', DependencyType::Extends);
        $implementsUser = self::dependency('App\Domain\Snapshot', 'App\Contract\Marker', DependencyType::Implements);
        $typeHint = self::dependency('App\Domain\Snapshot', 'App\Infra\Db', DependencyType::TypeHint);
        $newPhp = self::dependency('App\Domain\Tagged', 'ArrayObject', DependencyType::New_);
        $universe = [
            self::declaration('App\Domain\Snapshot'),
            self::declaration('App\Domain\Tagged'),
            self::declaration('App\Domain\Failure'),
            self::declaration('App\Infra\Db'),
        ];

        $graph = self::builder()->build(
            [$implementsPhp, $typeHint, $attributePhp, $newPhp, $extendsPhp, $implementsUser],
            $universe,
        )->graph;

        self::assertSame([$implementsPhp, $attributePhp, $extendsPhp, $implementsUser], $graph->getDeclarationDependencies());
        self::assertSame([$typeHint, $extendsPhp, $implementsUser], $graph->getAllDependencies());
    }

    /**
     * What coupling reads must be exactly what it read before the declaration
     * view existed: the same graph built without the two PHP-typed
     * declaration edges answers every coupling query the same way.
     */
    #[Test]
    public function itLeavesEveryCouplingViewAsItWasWithoutThePhpTypedDeclarationEdges(): void
    {
        $implementsPhp = self::dependency('App\Domain\Snapshot', 'JsonSerializable', DependencyType::Implements);
        $attributePhp = self::dependency('App\Domain\Tagged', 'AllowDynamicProperties', DependencyType::Attribute);
        $rest = [
            self::dependency('App\Domain\Snapshot', 'App\Infra\Db', DependencyType::TypeHint),
            self::dependency('App\Domain\Tagged', 'App\Infra\Db', DependencyType::New_),
            self::dependency('App\Domain\Failure', 'Exception', DependencyType::Extends),
            self::dependency('App\Infra\Db', 'App\Domain\Tagged', DependencyType::StaticCall),
        ];
        $universe = [
            self::declaration('App\Domain\Snapshot'),
            self::declaration('App\Domain\Tagged'),
            self::declaration('App\Domain\Failure'),
            self::declaration('App\Infra\Db'),
        ];

        $with = self::builder()->build([$implementsPhp, ...$rest, $attributePhp], $universe)->graph;
        $without = self::builder()->build($rest, $universe)->graph;

        self::assertSame(self::couplingViews($without), self::couplingViews($with));
        self::assertSame(1, $with->getClassCe(SymbolPath::fromClassFqn('App\Domain\Snapshot')));
        self::assertSame(1, $with->getClassCe(SymbolPath::fromClassFqn('App\Domain\Tagged')));
        self::assertSame(0, $with->getClassCa(SymbolPath::fromClassFqn('JsonSerializable')));
        self::assertSame(1, $with->getClassCa(SymbolPath::fromClassFqn('App\Domain\Tagged')));
    }

    #[Test]
    public function itDeduplicatesClassAndNamespaceCouplingEndpoints(): void
    {
        $dependency = self::dependency('App\Service', 'Vendor\Contract', DependencyType::TypeHint);
        $graph = self::builder()->build([$dependency, $dependency], [])->graph;

        self::assertSame([$dependency, $dependency], $graph->getAllDependencies());
        self::assertSame([$dependency, $dependency], $graph->getClassDependencies(SymbolPath::fromClassFqn('App\Service')));
        self::assertSame([$dependency, $dependency], $graph->getClassDependents(SymbolPath::fromClassFqn('Vendor\Contract')));
        self::assertSame(
            ['class:App\Service', 'class:Vendor\Contract'],
            self::canonicalPaths($graph->getAllClasses()),
        );
        self::assertSame(
            ['ns:App', 'ns:Vendor'],
            self::canonicalPaths($graph->getAllNamespaces()),
        );
        self::assertSame(1, $graph->getClassCe(SymbolPath::fromClassFqn('App\Service')));
        self::assertSame(1, $graph->getClassCa(SymbolPath::fromClassFqn('Vendor\Contract')));
        self::assertSame(1, $graph->getNamespaceCe(SymbolPath::forNamespace('App')));
        self::assertSame(1, $graph->getNamespaceCa(SymbolPath::forNamespace('Vendor')));
    }

    #[Test]
    public function itTreatsSiblingEdgesAsInternalToTheirParentAndExternalEdgesAsBoundaryCrossings(): void
    {
        $sibling = self::dependency('App\One\Source', 'App\Two\Target', DependencyType::New_);
        $outgoing = self::dependency('App\One\Source', 'Vendor\Outside', DependencyType::StaticCall);
        $incoming = self::dependency('Vendor\Incoming', 'App\Two\Target', DependencyType::TypeHint);
        $graph = self::builder()->build([$sibling, $outgoing, $incoming], [])->graph;

        self::assertSame([$sibling, $outgoing], $graph->getClassDependencies(SymbolPath::fromClassFqn('App\One\Source')));
        self::assertSame([$sibling, $incoming], $graph->getClassDependents(SymbolPath::fromClassFqn('App\Two\Target')));
        self::assertSame(
            [
                'class:App\One\Source',
                'class:App\Two\Target',
                'class:Vendor\Outside',
                'class:Vendor\Incoming',
            ],
            self::canonicalPaths($graph->getAllClasses()),
        );
        self::assertSame(
            ['ns:App\One', 'ns:App\Two', 'ns:Vendor', 'ns:App'],
            self::canonicalPaths($graph->getAllNamespaces()),
        );
        self::assertSame(2, $graph->getClassCe(SymbolPath::fromClassFqn('App\One\Source')));
        self::assertSame(2, $graph->getClassCa(SymbolPath::fromClassFqn('App\Two\Target')));
        self::assertSame(1, $graph->getNamespaceCe(SymbolPath::forNamespace('App')));
        self::assertSame(1, $graph->getNamespaceCa(SymbolPath::forNamespace('App')));
        self::assertSame(1, $graph->getNamespaceCa(SymbolPath::forNamespace('Vendor')));
        self::assertSame(2, $graph->getNamespaceCe(SymbolPath::forNamespace('App\One')));
        self::assertSame(2, $graph->getNamespaceCa(SymbolPath::forNamespace('App\Two')));
    }

    /**
     * Every coupling-facing answer of the graph, over every class and
     * namespace it knows plus the PHP types it must not know.
     *
     * @return array<string, mixed>
     */
    private static function couplingViews(DependencyGraphInterface $graph): array
    {
        $views = [
            'dependencies' => self::dependencyFields($graph->getAllDependencies()),
            'classes' => self::canonicalPaths($graph->getAllClasses()),
            'namespaces' => self::canonicalPaths($graph->getAllNamespaces()),
        ];

        $classes = [...$graph->getAllClasses(), SymbolPath::fromClassFqn('JsonSerializable'), SymbolPath::fromClassFqn('AllowDynamicProperties')];
        foreach ($classes as $class) {
            $views[$class->toCanonical()] = [
                self::dependencyFields($graph->getClassDependencies($class)),
                self::dependencyFields($graph->getClassDependents($class)),
                $graph->getClassCe($class),
                $graph->getClassCa($class),
            ];
        }

        foreach ($graph->getAllNamespaces() as $namespace) {
            $views[$namespace->toCanonical()] = [
                $graph->getNamespaceCe($namespace),
                $graph->getNamespaceCa($namespace),
                $graph->getNamespaceOwnCe($namespace),
                $graph->getNamespaceOwnCa($namespace),
            ];
        }

        return $views;
    }

    private static function logical(string $class): LogicalClassPath
    {
        return new LogicalClassPath(SymbolPath::fromClassFqn($class));
    }

    private static function builder(?ExternalClassSpellingInterface $spelling = null): DependencyGraphBuilder
    {
        return new DependencyGraphBuilder($spelling ?? new UnplacedExternalClassSpelling());
    }

    private static function declaration(
        string $class,
        ClassType $type = ClassType::Class_,
        bool $declaresToString = false,
        bool $aliasesTraitMethodAsToString = false,
    ): ClassLikeDeclaration {
        $file = RelativePath::fromString('src/Fixture.php');

        return ClassLikeDeclaration::of(
            DeclarationPath::of(SymbolPath::fromClassFqn($class), $file, DeclarationOrdinal::fromRank(0)),
            $type,
            $declaresToString,
            $aliasesTraitMethodAsToString,
        );
    }

    private static function dependency(string $source, string $target, DependencyType $type): Dependency
    {
        $file = RelativePath::fromString('src/Fixture.php');

        return Dependency::ofKind(
            DeclarationPath::of(SymbolPath::fromClassFqn($source), $file, DeclarationOrdinal::fromRank(0)),
            self::logical($target),
            $type,
            new Location($file, 1),
        );
    }

    /**
     * @param array<SymbolPath> $classes
     *
     * @return list<string>
     */
    private static function canonicalPaths(array $classes): array
    {
        return array_values(array_map(static fn(SymbolPath $path): string => $path->toCanonical(), $classes));
    }

    /**
     * @param array<Dependency> $dependencies
     *
     * @return list<array{string, string, DependencyType, string}>
     */
    private static function dependencyFields(array $dependencies): array
    {
        return array_values(array_map(
            static fn(Dependency $dependency): array => [
                $dependency->sourceLogical()->toCanonical(),
                $dependency->targetLogical()->toCanonical(),
                $dependency->type,
                $dependency->location->toString(),
            ],
            $dependencies,
        ));
    }
}
