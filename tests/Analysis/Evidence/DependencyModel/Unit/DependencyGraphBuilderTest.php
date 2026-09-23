<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Analysis\Evidence\DependencyModel\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Evidence\DependencyModel\Contract\Dependency;
use Qualimetrix\Analysis\Evidence\DependencyModel\Contract\DependencyGraphInterface;
use Qualimetrix\Analysis\Evidence\DependencyModel\Contract\DependencyType;
use Qualimetrix\Analysis\Evidence\DependencyModel\DependencyGraphBuilder;
use Qualimetrix\Analysis\Finding\Contract\Location;
use Qualimetrix\Core\Path\RelativePath;
use Qualimetrix\Core\Symbol\DeclarationOrdinal;
use Qualimetrix\Core\Symbol\DeclarationPath;
use Qualimetrix\Core\Symbol\LogicalClassPath;
use Qualimetrix\Core\Symbol\SymbolPath;

#[CoversClass(DependencyGraphBuilder::class)]
final class DependencyGraphBuilderTest extends TestCase
{
    #[Test]
    public function itKeepsDegreeZeroDeclarationsAndUndeclaredExternalTargets(): void
    {
        $standalone = self::logical('App\Feature\Standalone');
        $service = self::logical('App\Service\Worker');
        $outgoing = self::dependency('App\Service\Worker', 'Vendor\Contracts\Api', DependencyType::Implements);
        $incoming = self::dependency('Vendor\Producer', 'App\Service\Worker', DependencyType::TypeHint);
        $graph = (new DependencyGraphBuilder())->build(
            [$outgoing, $incoming],
            [$standalone, $service],
        );

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
    public function itFiltersBuiltinCouplingButRetainsBuiltinInheritance(): void
    {
        $filtered = self::dependency('App\Service', 'Exception', DependencyType::New_);
        $inheritance = self::dependency('App\Failure', 'Exception', DependencyType::Extends);
        $graph = (new DependencyGraphBuilder())->build([
            $filtered,
            $inheritance,
        ], [self::logical('App\Service'), self::logical('App\Failure')]);

        self::assertSame([$inheritance], $graph->getAllDependencies());
        self::assertSame(
            [['class:App\Failure', 'class:Exception', DependencyType::Extends, 'src/Fixture.php:1']],
            self::dependencyFields($graph->getAllDependencies()),
        );
        self::assertSame(
            ['class:App\Service', 'class:App\Failure', 'class:Exception'],
            self::canonicalPaths($graph->getAllClasses()),
        );
        self::assertSame([$inheritance], $graph->getClassDependencies(SymbolPath::fromClassFqn('App\Failure')));
        self::assertSame([$inheritance], $graph->getClassDependents(SymbolPath::fromClassFqn('Exception')));
        self::assertSame(0, $graph->getClassCe(SymbolPath::fromClassFqn('App\Service')));
        self::assertSame(1, $graph->getClassCe(SymbolPath::fromClassFqn('App\Failure')));
        self::assertSame(1, $graph->getClassCa(SymbolPath::fromClassFqn('Exception')));
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
            self::logical('App\Domain\Snapshot'),
            self::logical('App\Domain\Tagged'),
            self::logical('App\Domain\Failure'),
            self::logical('App\Infra\Db'),
        ];

        $graph = (new DependencyGraphBuilder())->build(
            [$implementsPhp, $typeHint, $attributePhp, $newPhp, $extendsPhp, $implementsUser],
            $universe,
        );

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
            self::logical('App\Domain\Snapshot'),
            self::logical('App\Domain\Tagged'),
            self::logical('App\Domain\Failure'),
            self::logical('App\Infra\Db'),
        ];

        $with = (new DependencyGraphBuilder())->build([$implementsPhp, ...$rest, $attributePhp], $universe);
        $without = (new DependencyGraphBuilder())->build($rest, $universe);

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
        $graph = (new DependencyGraphBuilder())->build([$dependency, $dependency], []);

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
        $graph = (new DependencyGraphBuilder())->build([$sibling, $outgoing, $incoming], []);

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

    private static function dependency(string $source, string $target, DependencyType $type): Dependency
    {
        $file = RelativePath::fromString('src/Fixture.php');

        return new Dependency(
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
