<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Analysis\Evidence\DependencyModel\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Evidence\DependencyModel\Contract\Dependency;
use Qualimetrix\Analysis\Evidence\DependencyModel\Contract\DependencyGraphInterface;
use Qualimetrix\Analysis\Evidence\DependencyModel\Contract\DependencyType;
use Qualimetrix\Analysis\Evidence\DependencyModel\DependencyGraph;
use Qualimetrix\Analysis\Evidence\DependencyModel\DependencyGraphBuilder;
use Qualimetrix\Analysis\Finding\Contract\Location;
use Qualimetrix\Core\Path\RelativePath;
use Qualimetrix\Core\Symbol\DeclarationOrdinal;
use Qualimetrix\Core\Symbol\DeclarationPath;
use Qualimetrix\Core\Symbol\LogicalClassPath;
use Qualimetrix\Core\Symbol\SymbolPath;

#[CoversClass(DependencyGraph::class)]
#[CoversClass(DependencyGraphBuilder::class)]
final class DependencyGraphTest extends TestCase
{
    private DependencyGraphBuilder $builder;

    protected function setUp(): void
    {
        $this->builder = new DependencyGraphBuilder();
    }

    #[Test]
    public function itReturnsOutgoingDependenciesForAClass(): void
    {
        $deps = [
            $this->dep('App\\Foo', 'Vendor\\Bar'),
            $this->dep('App\\Foo', 'Vendor\\Baz'),
            $this->dep('App\\Other', 'Vendor\\Bar'),
        ];

        $graph = $this->build($deps);

        $fooDeps = $graph->getClassDependencies(SymbolPath::fromClassFqn('App\\Foo'));
        self::assertCount(2, $fooDeps);

        $targets = array_map(fn($d) => $d->targetLogical()->toString(), $fooDeps);
        self::assertContains('Vendor\\Bar', $targets);
        self::assertContains('Vendor\\Baz', $targets);
    }

    #[Test]
    public function itReturnsIncomingDependenciesForAClass(): void
    {
        $deps = [
            $this->dep('App\\Foo', 'Vendor\\Bar'),
            $this->dep('App\\Baz', 'Vendor\\Bar'),
            $this->dep('App\\Foo', 'Vendor\\Other'),
        ];

        $graph = $this->build($deps);

        $barDependents = $graph->getClassDependents(SymbolPath::fromClassFqn('Vendor\\Bar'));
        self::assertCount(2, $barDependents);

        $sources = array_map(fn($d) => $d->sourceLogical()->toString(), $barDependents);
        self::assertContains('App\\Foo', $sources);
        self::assertContains('App\\Baz', $sources);
    }

    #[Test]
    public function itCountsUniqueTargetsAsClassCe(): void
    {
        $deps = [
            $this->dep('App\\Foo', 'Vendor\\Bar'),
            $this->dep('App\\Foo', 'Vendor\\Bar'), // duplicate
            $this->dep('App\\Foo', 'Vendor\\Baz'),
        ];

        $graph = $this->build($deps);

        self::assertSame(2, $graph->getClassCe(SymbolPath::fromClassFqn('App\\Foo')));
    }

    #[Test]
    public function itCountsUniqueSourcesAsClassCa(): void
    {
        $deps = [
            $this->dep('App\\Foo', 'Vendor\\Bar'),
            $this->dep('App\\Foo', 'Vendor\\Bar'), // duplicate
            $this->dep('App\\Baz', 'Vendor\\Bar'),
        ];

        $graph = $this->build($deps);

        self::assertSame(2, $graph->getClassCa(SymbolPath::fromClassFqn('Vendor\\Bar')));
    }

    #[Test]
    public function itCountsCrossNamespaceDependenciesAsNamespaceCe(): void
    {
        $deps = [
            // App -> Vendor (cross-namespace)
            $this->dep('App\\Foo', 'Vendor\\Bar'),
            $this->dep('App\\Foo', 'Vendor\\Baz'),
            $this->dep('App\\Baz', 'Vendor\\Bar'), // same target, different source
            // App -> App (internal, should not count)
            $this->dep('App\\Foo', 'App\\Internal'),
        ];

        $graph = $this->build($deps);

        // App namespace has Ce = 2 (Vendor\Bar, Vendor\Baz)
        self::assertSame(2, $graph->getNamespaceCe(SymbolPath::fromNamespaceFqn('App')));
    }

    #[Test]
    public function itCountsCrossNamespaceDependentsAsNamespaceCa(): void
    {
        $deps = [
            // App -> Vendor (Vendor gets Ca)
            $this->dep('App\\Foo', 'Vendor\\Bar'),
            $this->dep('App\\Baz', 'Vendor\\Bar'),
            // Other -> Vendor
            $this->dep('Other\\Service', 'Vendor\\Bar'),
            // Vendor -> Vendor (internal, should not count)
            $this->dep('Vendor\\Internal', 'Vendor\\Bar'),
        ];

        $graph = $this->build($deps);

        // Vendor namespace has Ca = 3 (App\Foo, App\Baz, Other\Service)
        self::assertSame(3, $graph->getNamespaceCa(SymbolPath::fromNamespaceFqn('Vendor')));
    }

    #[Test]
    public function itReturnsAllUniqueClassesInTheGraph(): void
    {
        $deps = [
            $this->dep('App\\Foo', 'Vendor\\Bar'),
            $this->dep('App\\Foo', 'Vendor\\Baz'),
        ];

        $graph = $this->build($deps);
        $classes = array_map(fn(SymbolPath $p) => $p->toString(), $graph->getAllClasses());

        self::assertCount(3, $classes);
        self::assertContains('App\\Foo', $classes);
        self::assertContains('Vendor\\Bar', $classes);
        self::assertContains('Vendor\\Baz', $classes);
    }

    #[Test]
    public function itReturnsAllUniqueNamespacesIncludingParents(): void
    {
        $deps = [
            $this->dep('App\\Service\\Foo', 'Vendor\\Package\\Bar'),
            $this->dep('App\\Domain\\Baz', 'Vendor\\Other\\Qux'),
        ];

        $graph = $this->build($deps);
        $namespaces = array_map(fn(SymbolPath $p) => $p->namespace ?? '', $graph->getAllNamespaces());

        // 4 leaf namespaces + 2 parent namespaces (App, Vendor)
        self::assertCount(6, $namespaces);
        self::assertContains('App\\Service', $namespaces);
        self::assertContains('App\\Domain', $namespaces);
        self::assertContains('Vendor\\Package', $namespaces);
        self::assertContains('Vendor\\Other', $namespaces);
        self::assertContains('App', $namespaces);
        self::assertContains('Vendor', $namespaces);
    }

    #[Test]
    public function itReturnsEveryDependencyInTheGraph(): void
    {
        $deps = [
            $this->dep('App\\Foo', 'Vendor\\Bar'),
            $this->dep('App\\Baz', 'Vendor\\Qux'),
        ];

        $graph = $this->build($deps);

        self::assertCount(2, $graph->getAllDependencies());
    }

    #[Test]
    public function itReturnsEmptyResultsForAnEmptyGraph(): void
    {
        $graph = $this->build([]);

        self::assertSame([], $graph->getAllClasses());
        self::assertSame([], $graph->getAllNamespaces());
        self::assertSame([], $graph->getAllDependencies());
        self::assertSame(0, $graph->getClassCe(SymbolPath::fromClassFqn('NonExistent')));
        self::assertSame(0, $graph->getClassCa(SymbolPath::fromClassFqn('NonExistent')));
        self::assertSame(0, $graph->getNamespaceCe(SymbolPath::fromNamespaceFqn('NonExistent')));
        self::assertSame(0, $graph->getNamespaceCa(SymbolPath::fromNamespaceFqn('NonExistent')));
    }

    #[Test]
    public function itSeedsDegreeZeroClassesFromTheExplicitUniverse(): void
    {
        $standalone = new LogicalClassPath(SymbolPath::fromClassFqn('App\\Standalone'));

        $graph = $this->builder->build([], [$standalone]);

        self::assertSame([$standalone->symbolPath], $graph->getAllClasses());
        self::assertSame(0, $graph->getClassCe($standalone->symbolPath));
        self::assertSame(0, $graph->getClassCa($standalone->symbolPath));
    }

    #[Test]
    public function itTreatsAGlobalNamespaceClassAsAValidMember(): void
    {
        $deps = [
            $this->dep('GlobalClass', 'App\\Foo'),
        ];

        $graph = $this->build($deps);

        // Global namespace class should still be in classes list
        $classStrings = array_map(fn(SymbolPath $p) => $p->toString(), $graph->getAllClasses());
        self::assertContains('GlobalClass', $classStrings);

        // Global namespace should be included as a valid namespace
        $nsStrings = array_map(fn(SymbolPath $p) => $p->namespace, $graph->getAllNamespaces());
        self::assertContains('', $nsStrings);
    }

    #[Test]
    public function itExcludesNonStructuralBuiltinDependencies(): void
    {
        $deps = [
            $this->dep('App\\Foo', 'App\\Bar'),
            $this->dep('App\\Foo', 'Exception', DependencyType::Catch_),
            $this->dep('App\\Foo', 'DateTime', DependencyType::TypeHint),
            $this->dep('App\\Foo', 'Iterator', DependencyType::Instanceof_),
        ];

        $graph = $this->build($deps);

        // Only App\Bar should remain (non-structural built-in deps filtered)
        $allDeps = $graph->getAllDependencies();
        self::assertCount(1, $allDeps);
        self::assertSame('App\\Bar', $allDeps[0]->targetLogical()->toString());

        // Built-in classes should not appear in class list
        $classNames = array_map(fn(SymbolPath $p) => $p->toString(), $graph->getAllClasses());
        self::assertContains('App\\Foo', $classNames);
        self::assertContains('App\\Bar', $classNames);
        self::assertNotContains('Exception', $classNames);
        self::assertNotContains('DateTime', $classNames);
        self::assertNotContains('Iterator', $classNames);
    }

    #[Test]
    public function itPreservesAnExtendsDependencyToABuiltinClass(): void
    {
        $deps = [
            $this->dep('App\\MyException', 'RuntimeException', DependencyType::Extends),
            $this->dep('App\\MyException', 'Throwable', DependencyType::Instanceof_),
        ];

        $graph = $this->build($deps);

        // extends preserved, instanceof filtered
        self::assertCount(1, $graph->getAllDependencies());
        self::assertSame(1, $graph->getClassCe(SymbolPath::fromClassFqn('App\\MyException')));

        $classNames = array_map(fn(SymbolPath $p) => $p->toString(), $graph->getAllClasses());
        self::assertContains('RuntimeException', $classNames);
        self::assertNotContains('Throwable', $classNames);
    }

    #[Test]
    public function itExcludesNamespacedPhpBuiltinsButKeepsExtends(): void
    {
        $deps = [
            $this->dep('App\\Foo', 'App\\Bar'),
            $this->dep('App\\Foo', 'Random\\Randomizer', DependencyType::TypeHint),
            $this->dep('App\\Foo', 'Dom\\Document', DependencyType::New_),
            $this->dep('App\\Foo', 'Pdo\\Mysql', DependencyType::Extends),  // extends preserved
        ];

        $graph = $this->build($deps);

        // App\Bar and Pdo\Mysql (extends) remain, Random\Randomizer and Dom\Document filtered
        self::assertCount(2, $graph->getAllDependencies());
        self::assertSame(2, $graph->getClassCe(SymbolPath::fromClassFqn('App\\Foo')));
    }

    #[Test]
    public function itKeepsAUserClassEvenInTheGlobalNamespace(): void
    {
        // A class name that isn't a PHP built-in, even though it's in global namespace
        $deps = [
            $this->dep('App\\Foo', 'MyCustomGlobalClass'),
        ];

        $graph = $this->build($deps);

        self::assertCount(1, $graph->getAllDependencies());
        self::assertSame(1, $graph->getClassCe(SymbolPath::fromClassFqn('App\\Foo')));
    }

    #[Test]
    public function itKeepsANamespacedClassWhoseNameMatchesABuiltin(): void
    {
        // App\Exception is a user class, not PHP's built-in \Exception
        $deps = [
            $this->dep('App\\Foo', 'App\\Exception'),
            $this->dep('App\\Foo', 'Vendor\\DateTime'),
        ];

        $graph = $this->build($deps);

        self::assertCount(2, $graph->getAllDependencies());
        self::assertSame(2, $graph->getClassCe(SymbolPath::fromClassFqn('App\\Foo')));
    }

    #[Test]
    public function itExcludesBuiltinsFromTheCeCount(): void
    {
        $deps = [
            $this->dep('App\\Foo', 'App\\Bar'),
            $this->dep('App\\Foo', 'Exception', DependencyType::Catch_),
            $this->dep('App\\Foo', 'RuntimeException', DependencyType::TypeHint),
        ];

        $graph = $this->build($deps);

        // Only App\Bar counts toward Ce
        self::assertSame(1, $graph->getClassCe(SymbolPath::fromClassFqn('App\\Foo')));
    }

    #[Test]
    public function itExcludesBuiltinsFromTheNamespaceCeCount(): void
    {
        $deps = [
            $this->dep('App\\Foo', 'Vendor\\Bar'),
            $this->dep('App\\Foo', 'Exception', DependencyType::Catch_),
            $this->dep('App\\Foo', 'Throwable', DependencyType::TypeHint),
        ];

        $graph = $this->build($deps);

        // Only Vendor\Bar counts toward App namespace Ce
        self::assertSame(1, $graph->getNamespaceCe(SymbolPath::fromNamespaceFqn('App')));
    }

    #[Test]
    public function itKeepsImplementsDependenciesForUserDefinedInterfaces(): void
    {
        $deps = [
            $this->dep('App\\Foo', 'App\\BarInterface', DependencyType::Implements),
            $this->dep('App\\Foo', 'Vendor\\ContractInterface', DependencyType::Implements),
        ];

        $graph = $this->build($deps);

        self::assertCount(2, $graph->getAllDependencies());
        self::assertSame(2, $graph->getClassCe(SymbolPath::fromClassFqn('App\\Foo')));
    }

    #[Test]
    public function itExcludesBuiltinInterfacesFromDependencies(): void
    {
        $deps = [
            $this->dep('App\\Foo', 'App\\Bar'),
            $this->dep('App\\Foo', 'Countable', DependencyType::Implements),
            $this->dep('App\\Foo', 'Serializable', DependencyType::Implements),
            $this->dep('App\\Foo', 'Stringable', DependencyType::TypeHint),
        ];

        $graph = $this->build($deps);

        self::assertCount(1, $graph->getAllDependencies());
        self::assertSame(1, $graph->getClassCe(SymbolPath::fromClassFqn('App\\Foo')));
    }

    // ---------------------------------------------------------------
    // Parent namespace Ce/Ca tests
    // ---------------------------------------------------------------

    #[Test]
    public function itTreatsDependenciesBetweenSiblingChildrenAsInternalToTheParent(): void
    {
        $deps = [
            $this->dep('A\\X\\Foo', 'A\\Y\\Bar'),
        ];

        $graph = $this->build($deps);

        // Both A\X and A\Y are children of A — dependency is internal to A
        self::assertSame(0, $graph->getNamespaceCe(SymbolPath::fromNamespaceFqn('A')));
    }

    #[Test]
    public function itCountsAChildNamespaceDependencyOutsideTheParentAsCe(): void
    {
        $deps = [
            $this->dep('A\\X\\Foo', 'B\\Bar'),
        ];

        $graph = $this->build($deps);

        // A\X\Foo depends on B\Bar — crosses A boundary
        self::assertSame(1, $graph->getNamespaceCe(SymbolPath::fromNamespaceFqn('A')));
    }

    #[Test]
    public function itCountsAChildNamespaceDependentOutsideTheParentAsCa(): void
    {
        $deps = [
            $this->dep('B\\Bar', 'A\\X\\Foo'),
        ];

        $graph = $this->build($deps);

        // B\Bar depends on A\X\Foo — A gets Ca=1
        self::assertSame(1, $graph->getNamespaceCa(SymbolPath::fromNamespaceFqn('A')));
    }

    #[Test]
    public function itCountsOnlyTheExternalDepsAmongAMixOfInternalAndExternal(): void
    {
        $deps = [
            // Internal to A — should NOT count
            $this->dep('A\\X\\Foo', 'A\\Y\\Bar'),
            $this->dep('A\\Y\\Bar', 'A\\X\\Baz'),
            // External — should count for Ce(A)
            $this->dep('A\\X\\Foo', 'B\\Qux'),
            $this->dep('A\\Y\\Bar', 'C\\Quux'),
            // External — should count for Ca(A)
            $this->dep('D\\Service', 'A\\X\\Foo'),
        ];

        $graph = $this->build($deps);

        // Ce(A) = 2 unique external targets: B\Qux, C\Quux
        self::assertSame(2, $graph->getNamespaceCe(SymbolPath::fromNamespaceFqn('A')));
        // Ca(A) = 1 unique external source: D\Service
        self::assertSame(1, $graph->getNamespaceCa(SymbolPath::fromNamespaceFqn('A')));
    }

    #[Test]
    public function itPropagatesCeUpEveryAncestorNamespace(): void
    {
        $deps = [
            $this->dep('A\\B\\C\\Foo', 'D\\E\\Bar'),
        ];

        $graph = $this->build($deps);

        // The dependency crosses boundaries for both parent A and parent A\B
        self::assertSame(1, $graph->getNamespaceCe(SymbolPath::fromNamespaceFqn('A')));
        self::assertSame(1, $graph->getNamespaceCe(SymbolPath::fromNamespaceFqn('A\\B')));
        // Leaf namespace A\B\C also gets Ce=1
        self::assertSame(1, $graph->getNamespaceCe(SymbolPath::fromNamespaceFqn('A\\B\\C')));
    }

    #[Test]
    public function itKeepsLeafNamespaceCeAndCaUnaffectedByParentNamespaces(): void
    {
        $deps = [
            // Cross-namespace leaf dep: A\X -> B\Y
            $this->dep('A\\X\\Foo', 'B\\Y\\Bar'),
            // Internal to A parent (but cross leaf namespaces)
            $this->dep('A\\X\\Baz', 'A\\Z\\Qux'),
        ];

        $graph = $this->build($deps);

        // Leaf namespace Ce/Ca should reflect their own leaf-level semantics
        // A\X has 2 outgoing external deps (B\Y\Bar and A\Z\Qux are both outside A\X)
        self::assertSame(2, $graph->getNamespaceCe(SymbolPath::fromNamespaceFqn('A\\X')));
        // B\Y has Ca=1 (from A\X\Foo)
        self::assertSame(1, $graph->getNamespaceCa(SymbolPath::fromNamespaceFqn('B\\Y')));
        // A\Z has Ca=1 (from A\X\Baz)
        self::assertSame(1, $graph->getNamespaceCa(SymbolPath::fromNamespaceFqn('A\\Z')));
    }

    #[Test]
    public function itReportsZeroCouplingWhenAllDepsAreInternalToTheParent(): void
    {
        $deps = [
            $this->dep('A\\X\\Foo', 'A\\Y\\Bar'),
            $this->dep('A\\Y\\Bar', 'A\\Z\\Baz'),
            $this->dep('A\\Z\\Baz', 'A\\X\\Foo'),
        ];

        $graph = $this->build($deps);

        // All deps are internal to A — no boundary crossing
        self::assertSame(0, $graph->getNamespaceCe(SymbolPath::fromNamespaceFqn('A')));
        self::assertSame(0, $graph->getNamespaceCa(SymbolPath::fromNamespaceFqn('A')));
    }

    // ---------------------------------------------------------------
    // Own-scope Ce/Ca tests
    // ---------------------------------------------------------------

    /**
     * A namespace that both declares classes and contains sub-namespaces holds
     * two coupling scopes at once, and the graph has to answer for both: the
     * subtree rollup, where a sub-namespace is inside, and its own scope, where
     * everything but its own declarations is outside.
     */
    #[Test]
    public function itSeparatesTheOwnScopeOfANamespaceFromItsSubtreeRollup(): void
    {
        $deps = [
            $this->dep('A\\B\\X', 'Ext\\Y'),
            $this->dep('A\\Z', 'Ext\\W'),
            $this->dep('Ext\\P', 'A\\Z'),
            $this->dep('Ext\\Q', 'A\\B\\X'),
        ];

        $graph = $this->build($deps);
        $a = SymbolPath::fromNamespaceFqn('A');

        self::assertSame(2, $graph->getNamespaceCe($a), 'rollup Ce counts both subtree crossings');
        self::assertSame(2, $graph->getNamespaceCa($a), 'rollup Ca counts both subtree crossings');
        self::assertSame(1, $graph->getNamespaceOwnCe($a), 'own Ce counts only what A itself declares');
        self::assertSame(1, $graph->getNamespaceOwnCa($a), 'own Ca counts only what reaches A itself');
    }

    /**
     * The own scope has to stay the own scope of the namespace that owns it: a
     * sub-namespace is a whole namespace to itself, so its two scopes coincide,
     * and a rollup recomputed for its parent must not reach it.
     */
    #[Test]
    public function itLeavesTheOwnScopeOfANamespaceWithoutChildrenEqualToItsRollup(): void
    {
        $deps = [
            $this->dep('A\\B\\X', 'Ext\\Y'),
            $this->dep('A\\Z', 'Ext\\W'),
            $this->dep('Ext\\P', 'A\\Z'),
            $this->dep('Ext\\Q', 'A\\B\\X'),
        ];

        $graph = $this->build($deps);
        $b = SymbolPath::fromNamespaceFqn('A\\B');

        self::assertSame(1, $graph->getNamespaceOwnCe($b));
        self::assertSame($graph->getNamespaceCe($b), $graph->getNamespaceOwnCe($b));
        self::assertSame($graph->getNamespaceCa($b), $graph->getNamespaceOwnCa($b));
    }

    /**
     * A dependency between two children of one parent is internal to the parent
     * in the rollup, and a crossing in both children's own scopes. That is the
     * case where reading the rollup as an own-scope value would lose the edge.
     */
    #[Test]
    public function itCountsASiblingCrossingInTheOwnScopeOfBothChildren(): void
    {
        $deps = [
            $this->dep('A\\X\\Foo', 'A\\Y\\Bar'),
        ];

        $graph = $this->build($deps);

        self::assertSame(0, $graph->getNamespaceOwnCe(SymbolPath::fromNamespaceFqn('A')));
        self::assertSame(1, $graph->getNamespaceOwnCe(SymbolPath::fromNamespaceFqn('A\\X')));
        self::assertSame(1, $graph->getNamespaceOwnCa(SymbolPath::fromNamespaceFqn('A\\Y')));
    }

    #[Test]
    public function itReportsZeroOwnCouplingForAnUnknownNamespace(): void
    {
        $graph = $this->build([$this->dep('A\\X', 'Ext\\Y')]);

        self::assertSame(0, $graph->getNamespaceOwnCe(SymbolPath::fromNamespaceFqn('NonExistent')));
        self::assertSame(0, $graph->getNamespaceOwnCa(SymbolPath::fromNamespaceFqn('NonExistent')));
    }

    private function dep(string $source, string $target, DependencyType $type = DependencyType::New_): Dependency
    {
        $sourcePath = SymbolPath::fromClassFqn($source);

        return new Dependency(
            DeclarationPath::of($sourcePath, RelativePath::fromString('test.php'), DeclarationOrdinal::fromRank(0)),
            new LogicalClassPath(SymbolPath::fromClassFqn($target)),
            $type,
            new Location(RelativePath::fromString('test.php'), 1),
        );
    }

    /** @param list<Dependency> $dependencies */
    private function build(array $dependencies): DependencyGraphInterface
    {
        /** @var array<string, LogicalClassPath> $universe */
        $universe = [];
        foreach ($dependencies as $dependency) {
            $source = new LogicalClassPath($dependency->sourceLogical());
            $universe[$source->toCanonical()] = $source;
        }

        return $this->builder->build($dependencies, array_values($universe));
    }
}
