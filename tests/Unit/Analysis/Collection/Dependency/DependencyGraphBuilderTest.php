<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Unit\Analysis\Collection\Dependency;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Collection\Dependency\DependencyGraphBuilder;
use Qualimetrix\Core\Dependency\Dependency;
use Qualimetrix\Core\Dependency\DependencyType;
use Qualimetrix\Core\Path\RelativePath;
use Qualimetrix\Core\Symbol\DeclarationPath;
use Qualimetrix\Core\Symbol\LogicalClassPath;
use Qualimetrix\Core\Symbol\SymbolPath;
use Qualimetrix\Core\Violation\Location;

#[CoversClass(DependencyGraphBuilder::class)]
final class DependencyGraphBuilderTest extends TestCase
{
    #[Test]
    public function itKeepsDegreeZeroDeclarationsAndUndeclaredExternalTargets(): void
    {
        $standalone = self::logical('App\Standalone');
        $graph = (new DependencyGraphBuilder())->build(
            [self::dependency('App\Service', 'Vendor\Contract', DependencyType::Implements)],
            [$standalone, self::logical('App\Service')],
        );

        self::assertSame(
            ['class:App\Service', 'class:App\Standalone', 'class:Vendor\Contract'],
            self::canonicalClasses($graph->getAllClasses()),
        );
        self::assertSame(0, $graph->getClassCe($standalone->symbolPath));
        self::assertSame(1, $graph->getClassCe(SymbolPath::fromClassFqn('App\Service')));
        self::assertSame(1, $graph->getClassCa(SymbolPath::fromClassFqn('Vendor\Contract')));
    }

    #[Test]
    public function itFiltersBuiltinCouplingButRetainsBuiltinInheritance(): void
    {
        $graph = (new DependencyGraphBuilder())->build([
            self::dependency('App\Service', 'Exception', DependencyType::New_),
            self::dependency('App\Failure', 'Exception', DependencyType::Extends),
        ], [self::logical('App\Service'), self::logical('App\Failure')]);

        self::assertCount(1, $graph->getAllDependencies());
        self::assertSame(DependencyType::Extends, $graph->getAllDependencies()[0]->type);
        self::assertSame(0, $graph->getClassCe(SymbolPath::fromClassFqn('App\Service')));
        self::assertSame(1, $graph->getClassCe(SymbolPath::fromClassFqn('App\Failure')));
    }

    #[Test]
    public function itDeduplicatesClassAndNamespaceCouplingEndpoints(): void
    {
        $dependency = self::dependency('App\Service', 'Vendor\Contract', DependencyType::TypeHint);
        $graph = (new DependencyGraphBuilder())->build([$dependency, $dependency], []);

        self::assertSame(1, $graph->getClassCe(SymbolPath::fromClassFqn('App\Service')));
        self::assertSame(1, $graph->getClassCa(SymbolPath::fromClassFqn('Vendor\Contract')));
        self::assertSame(1, $graph->getNamespaceCe(SymbolPath::forNamespace('App')));
        self::assertSame(1, $graph->getNamespaceCa(SymbolPath::forNamespace('Vendor')));
    }

    #[Test]
    public function itTreatsSiblingEdgesAsInternalToTheirParentAndExternalEdgesAsBoundaryCrossings(): void
    {
        $graph = (new DependencyGraphBuilder())->build([
            self::dependency('App\One\Source', 'App\Two\Target', DependencyType::New_),
            self::dependency('App\One\Source', 'Vendor\Outside', DependencyType::StaticCall),
            self::dependency('Vendor\Incoming', 'App\Two\Target', DependencyType::TypeHint),
        ], []);

        self::assertSame(1, $graph->getNamespaceCe(SymbolPath::forNamespace('App')));
        self::assertSame(1, $graph->getNamespaceCa(SymbolPath::forNamespace('App')));
        self::assertSame(1, $graph->getNamespaceCa(SymbolPath::forNamespace('Vendor')));
        self::assertSame(2, $graph->getNamespaceCe(SymbolPath::forNamespace('App\One')));
        self::assertSame(2, $graph->getNamespaceCa(SymbolPath::forNamespace('App\Two')));
    }

    private static function logical(string $class): LogicalClassPath
    {
        return new LogicalClassPath(SymbolPath::fromClassFqn($class));
    }

    private static function dependency(string $source, string $target, DependencyType $type): Dependency
    {
        $file = RelativePath::fromString('src/Fixture.php');

        return new Dependency(
            new DeclarationPath(SymbolPath::fromClassFqn($source), $file, 0),
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
    private static function canonicalClasses(array $classes): array
    {
        $canonical = array_map(static fn(SymbolPath $path): string => $path->toCanonical(), $classes);
        sort($canonical);

        return $canonical;
    }
}
