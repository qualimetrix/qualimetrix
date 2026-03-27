<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Unit\Analysis\Collection\Dependency;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Collection\Dependency\DependencyGraph;
use Qualimetrix\Analysis\Collection\Dependency\DependencyGraphBuilder;
use Qualimetrix\Core\Dependency\Dependency;
use Qualimetrix\Core\Dependency\DependencyType;
use Qualimetrix\Core\Symbol\SymbolPath;
use Qualimetrix\Core\Violation\Location;

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
    public function getClassDependencies_returnsOutgoingDependencies(): void
    {
        $deps = [
            $this->dep('App\\Foo', 'Vendor\\Bar'),
            $this->dep('App\\Foo', 'Vendor\\Baz'),
            $this->dep('App\\Other', 'Vendor\\Bar'),
        ];

        $graph = $this->builder->build($deps);

        $fooDeps = $graph->getClassDependencies(SymbolPath::fromClassFqn('App\\Foo'));
        self::assertCount(2, $fooDeps);

        $targets = array_map(fn($d) => $d->target->toString(), $fooDeps);
        self::assertContains('Vendor\\Bar', $targets);
        self::assertContains('Vendor\\Baz', $targets);
    }

    #[Test]
    public function getClassDependents_returnsIncomingDependencies(): void
    {
        $deps = [
            $this->dep('App\\Foo', 'Vendor\\Bar'),
            $this->dep('App\\Baz', 'Vendor\\Bar'),
            $this->dep('App\\Foo', 'Vendor\\Other'),
        ];

        $graph = $this->builder->build($deps);

        $barDependents = $graph->getClassDependents(SymbolPath::fromClassFqn('Vendor\\Bar'));
        self::assertCount(2, $barDependents);

        $sources = array_map(fn($d) => $d->source->toString(), $barDependents);
        self::assertContains('App\\Foo', $sources);
        self::assertContains('App\\Baz', $sources);
    }

    #[Test]
    public function getClassCe_countsUniqueTargets(): void
    {
        $deps = [
            $this->dep('App\\Foo', 'Vendor\\Bar'),
            $this->dep('App\\Foo', 'Vendor\\Bar'), // duplicate
            $this->dep('App\\Foo', 'Vendor\\Baz'),
        ];

        $graph = $this->builder->build($deps);

        self::assertSame(2, $graph->getClassCe(SymbolPath::fromClassFqn('App\\Foo')));
    }

    #[Test]
    public function getClassCa_countsUniqueSources(): void
    {
        $deps = [
            $this->dep('App\\Foo', 'Vendor\\Bar'),
            $this->dep('App\\Foo', 'Vendor\\Bar'), // duplicate
            $this->dep('App\\Baz', 'Vendor\\Bar'),
        ];

        $graph = $this->builder->build($deps);

        self::assertSame(2, $graph->getClassCa(SymbolPath::fromClassFqn('Vendor\\Bar')));
    }

    #[Test]
    public function getNamespaceCe_countsExternalDependencies(): void
    {
        $deps = [
            // App -> Vendor (cross-namespace)
            $this->dep('App\\Foo', 'Vendor\\Bar'),
            $this->dep('App\\Foo', 'Vendor\\Baz'),
            $this->dep('App\\Baz', 'Vendor\\Bar'), // same target, different source
            // App -> App (internal, should not count)
            $this->dep('App\\Foo', 'App\\Internal'),
        ];

        $graph = $this->builder->build($deps);

        // App namespace has Ce = 2 (Vendor\Bar, Vendor\Baz)
        self::assertSame(2, $graph->getNamespaceCe(SymbolPath::fromNamespaceFqn('App')));
    }

    #[Test]
    public function getNamespaceCa_countsExternalDependents(): void
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

        $graph = $this->builder->build($deps);

        // Vendor namespace has Ca = 3 (App\Foo, App\Baz, Other\Service)
        self::assertSame(3, $graph->getNamespaceCa(SymbolPath::fromNamespaceFqn('Vendor')));
    }

    #[Test]
    public function getAllClasses_returnsAllUniqueClasses(): void
    {
        $deps = [
            $this->dep('App\\Foo', 'Vendor\\Bar'),
            $this->dep('App\\Foo', 'Vendor\\Baz'),
        ];

        $graph = $this->builder->build($deps);
        $classes = array_map(fn(SymbolPath $p) => $p->toString(), $graph->getAllClasses());

        self::assertCount(3, $classes);
        self::assertContains('App\\Foo', $classes);
        self::assertContains('Vendor\\Bar', $classes);
        self::assertContains('Vendor\\Baz', $classes);
    }

    #[Test]
    public function getAllNamespaces_returnsAllUniqueNamespaces(): void
    {
        $deps = [
            $this->dep('App\\Service\\Foo', 'Vendor\\Package\\Bar'),
            $this->dep('App\\Domain\\Baz', 'Vendor\\Other\\Qux'),
        ];

        $graph = $this->builder->build($deps);
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
    public function getAllDependencies_returnsAllDependencies(): void
    {
        $deps = [
            $this->dep('App\\Foo', 'Vendor\\Bar'),
            $this->dep('App\\Baz', 'Vendor\\Qux'),
        ];

        $graph = $this->builder->build($deps);

        self::assertCount(2, $graph->getAllDependencies());
    }

    #[Test]
    public function emptyGraph_returnsEmptyResults(): void
    {
        $graph = $this->builder->build([]);

        self::assertSame([], $graph->getAllClasses());
        self::assertSame([], $graph->getAllNamespaces());
        self::assertSame([], $graph->getAllDependencies());
        self::assertSame(0, $graph->getClassCe(SymbolPath::fromClassFqn('NonExistent')));
        self::assertSame(0, $graph->getClassCa(SymbolPath::fromClassFqn('NonExistent')));
        self::assertSame(0, $graph->getNamespaceCe(SymbolPath::fromNamespaceFqn('NonExistent')));
        self::assertSame(0, $graph->getNamespaceCa(SymbolPath::fromNamespaceFqn('NonExistent')));
    }

    #[Test]
    public function handlesGlobalNamespace(): void
    {
        $deps = [
            $this->dep('GlobalClass', 'App\\Foo'),
        ];

        $graph = $this->builder->build($deps);

        // Global namespace class should still be in classes list
        $classStrings = array_map(fn(SymbolPath $p) => $p->toString(), $graph->getAllClasses());
        self::assertContains('GlobalClass', $classStrings);

        // Global namespace should be included as a valid namespace
        $nsStrings = array_map(fn(SymbolPath $p) => $p->namespace, $graph->getAllNamespaces());
        self::assertContains('', $nsStrings);
    }

    #[Test]
    public function build_excludesNonStructuralBuiltinDependencies(): void
    {
        $deps = [
            $this->dep('App\\Foo', 'App\\Bar'),
            $this->dep('App\\Foo', 'Exception', DependencyType::Catch_),
            $this->dep('App\\Foo', 'DateTime', DependencyType::TypeHint),
            $this->dep('App\\Foo', 'Iterator', DependencyType::Instanceof_),
        ];

        $graph = $this->builder->build($deps);

        // Only App\Bar should remain (non-structural built-in deps filtered)
        $allDeps = $graph->getAllDependencies();
        self::assertCount(1, $allDeps);
        self::assertSame('App\\Bar', $allDeps[0]->target->toString());

        // Built-in classes should not appear in class list
        $classNames = array_map(fn(SymbolPath $p) => $p->toString(), $graph->getAllClasses());
        self::assertContains('App\\Foo', $classNames);
        self::assertContains('App\\Bar', $classNames);
        self::assertNotContains('Exception', $classNames);
        self::assertNotContains('DateTime', $classNames);
        self::assertNotContains('Iterator', $classNames);
    }

    #[Test]
    public function build_preservesExtendsDependencyToBuiltinClass(): void
    {
        $deps = [
            $this->dep('App\\MyException', 'RuntimeException', DependencyType::Extends),
            $this->dep('App\\MyException', 'Throwable', DependencyType::Instanceof_),
        ];

        $graph = $this->builder->build($deps);

        // extends preserved, instanceof filtered
        self::assertCount(1, $graph->getAllDependencies());
        self::assertSame(1, $graph->getClassCe(SymbolPath::fromClassFqn('App\\MyException')));

        $classNames = array_map(fn(SymbolPath $p) => $p->toString(), $graph->getAllClasses());
        self::assertContains('RuntimeException', $classNames);
        self::assertNotContains('Throwable', $classNames);
    }

    #[Test]
    public function build_excludesNamespacedPhpBuiltins(): void
    {
        $deps = [
            $this->dep('App\\Foo', 'App\\Bar'),
            $this->dep('App\\Foo', 'Random\\Randomizer', DependencyType::TypeHint),
            $this->dep('App\\Foo', 'Dom\\Document', DependencyType::New_),
            $this->dep('App\\Foo', 'Pdo\\Mysql', DependencyType::Extends),  // extends preserved
        ];

        $graph = $this->builder->build($deps);

        // App\Bar and Pdo\Mysql (extends) remain, Random\Randomizer and Dom\Document filtered
        self::assertCount(2, $graph->getAllDependencies());
        self::assertSame(2, $graph->getClassCe(SymbolPath::fromClassFqn('App\\Foo')));
    }

    #[Test]
    public function build_keepsUserClassesInGlobalNamespace(): void
    {
        // A class name that isn't a PHP built-in, even though it's in global namespace
        $deps = [
            $this->dep('App\\Foo', 'MyCustomGlobalClass'),
        ];

        $graph = $this->builder->build($deps);

        self::assertCount(1, $graph->getAllDependencies());
        self::assertSame(1, $graph->getClassCe(SymbolPath::fromClassFqn('App\\Foo')));
    }

    #[Test]
    public function build_keepsNamespacedClassesEvenIfNameMatchesBuiltin(): void
    {
        // App\Exception is a user class, not PHP's built-in \Exception
        $deps = [
            $this->dep('App\\Foo', 'App\\Exception'),
            $this->dep('App\\Foo', 'Vendor\\DateTime'),
        ];

        $graph = $this->builder->build($deps);

        self::assertCount(2, $graph->getAllDependencies());
        self::assertSame(2, $graph->getClassCe(SymbolPath::fromClassFqn('App\\Foo')));
    }

    #[Test]
    public function build_excludesBuiltinFromCeCount(): void
    {
        $deps = [
            $this->dep('App\\Foo', 'App\\Bar'),
            $this->dep('App\\Foo', 'Exception', DependencyType::Catch_),
            $this->dep('App\\Foo', 'RuntimeException', DependencyType::TypeHint),
        ];

        $graph = $this->builder->build($deps);

        // Only App\Bar counts toward Ce
        self::assertSame(1, $graph->getClassCe(SymbolPath::fromClassFqn('App\\Foo')));
    }

    #[Test]
    public function build_excludesBuiltinFromNamespaceCe(): void
    {
        $deps = [
            $this->dep('App\\Foo', 'Vendor\\Bar'),
            $this->dep('App\\Foo', 'Exception', DependencyType::Catch_),
            $this->dep('App\\Foo', 'Throwable', DependencyType::TypeHint),
        ];

        $graph = $this->builder->build($deps);

        // Only Vendor\Bar counts toward App namespace Ce
        self::assertSame(1, $graph->getNamespaceCe(SymbolPath::fromNamespaceFqn('App')));
    }

    #[Test]
    public function build_keepsImplementsForUserDefinedInterface(): void
    {
        $deps = [
            $this->dep('App\\Foo', 'App\\BarInterface', DependencyType::Implements),
            $this->dep('App\\Foo', 'Vendor\\ContractInterface', DependencyType::Implements),
        ];

        $graph = $this->builder->build($deps);

        self::assertCount(2, $graph->getAllDependencies());
        self::assertSame(2, $graph->getClassCe(SymbolPath::fromClassFqn('App\\Foo')));
    }

    #[Test]
    public function build_excludesBuiltinInterfaces(): void
    {
        $deps = [
            $this->dep('App\\Foo', 'App\\Bar'),
            $this->dep('App\\Foo', 'Countable', DependencyType::Implements),
            $this->dep('App\\Foo', 'Serializable', DependencyType::Implements),
            $this->dep('App\\Foo', 'Stringable', DependencyType::TypeHint),
        ];

        $graph = $this->builder->build($deps);

        self::assertCount(1, $graph->getAllDependencies());
        self::assertSame(1, $graph->getClassCe(SymbolPath::fromClassFqn('App\\Foo')));
    }

    // ---------------------------------------------------------------
    // Parent namespace Ce/Ca tests
    // ---------------------------------------------------------------

    #[Test]
    public function parent_namespace_ce_deps_between_children_are_internal(): void
    {
        $deps = [
            $this->dep('A\\X\\Foo', 'A\\Y\\Bar'),
        ];

        $graph = $this->builder->build($deps);

        // Both A\X and A\Y are children of A — dependency is internal to A
        self::assertSame(0, $graph->getNamespaceCe(SymbolPath::fromNamespaceFqn('A')));
    }

    #[Test]
    public function parent_namespace_ce_external_deps_cross_boundary(): void
    {
        $deps = [
            $this->dep('A\\X\\Foo', 'B\\Bar'),
        ];

        $graph = $this->builder->build($deps);

        // A\X\Foo depends on B\Bar — crosses A boundary
        self::assertSame(1, $graph->getNamespaceCe(SymbolPath::fromNamespaceFqn('A')));
    }

    #[Test]
    public function parent_namespace_ca_external_deps_cross_boundary(): void
    {
        $deps = [
            $this->dep('B\\Bar', 'A\\X\\Foo'),
        ];

        $graph = $this->builder->build($deps);

        // B\Bar depends on A\X\Foo — A gets Ca=1
        self::assertSame(1, $graph->getNamespaceCa(SymbolPath::fromNamespaceFqn('A')));
    }

    #[Test]
    public function parent_namespace_mixed_internal_and_external_deps(): void
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

        $graph = $this->builder->build($deps);

        // Ce(A) = 2 unique external targets: B\Qux, C\Quux
        self::assertSame(2, $graph->getNamespaceCe(SymbolPath::fromNamespaceFqn('A')));
        // Ca(A) = 1 unique external source: D\Service
        self::assertSame(1, $graph->getNamespaceCa(SymbolPath::fromNamespaceFqn('A')));
    }

    #[Test]
    public function parent_namespace_deep_nesting_propagates_ce(): void
    {
        $deps = [
            $this->dep('A\\B\\C\\Foo', 'D\\E\\Bar'),
        ];

        $graph = $this->builder->build($deps);

        // The dependency crosses boundaries for both parent A and parent A\B
        self::assertSame(1, $graph->getNamespaceCe(SymbolPath::fromNamespaceFqn('A')));
        self::assertSame(1, $graph->getNamespaceCe(SymbolPath::fromNamespaceFqn('A\\B')));
        // Leaf namespace A\B\C also gets Ce=1
        self::assertSame(1, $graph->getNamespaceCe(SymbolPath::fromNamespaceFqn('A\\B\\C')));
    }

    #[Test]
    public function leaf_namespace_ce_ca_not_affected_by_parent_ns_addition(): void
    {
        $deps = [
            // Cross-namespace leaf dep: A\X -> B\Y
            $this->dep('A\\X\\Foo', 'B\\Y\\Bar'),
            // Internal to A parent (but cross leaf namespaces)
            $this->dep('A\\X\\Baz', 'A\\Z\\Qux'),
        ];

        $graph = $this->builder->build($deps);

        // Leaf namespace Ce/Ca should reflect their own leaf-level semantics
        // A\X has 2 outgoing external deps (B\Y\Bar and A\Z\Qux are both outside A\X)
        self::assertSame(2, $graph->getNamespaceCe(SymbolPath::fromNamespaceFqn('A\\X')));
        // B\Y has Ca=1 (from A\X\Foo)
        self::assertSame(1, $graph->getNamespaceCa(SymbolPath::fromNamespaceFqn('B\\Y')));
        // A\Z has Ca=1 (from A\X\Baz)
        self::assertSame(1, $graph->getNamespaceCa(SymbolPath::fromNamespaceFqn('A\\Z')));
    }

    #[Test]
    public function parent_namespace_with_all_internal_deps_has_zero_coupling(): void
    {
        $deps = [
            $this->dep('A\\X\\Foo', 'A\\Y\\Bar'),
            $this->dep('A\\Y\\Bar', 'A\\Z\\Baz'),
            $this->dep('A\\Z\\Baz', 'A\\X\\Foo'),
        ];

        $graph = $this->builder->build($deps);

        // All deps are internal to A — no boundary crossing
        self::assertSame(0, $graph->getNamespaceCe(SymbolPath::fromNamespaceFqn('A')));
        self::assertSame(0, $graph->getNamespaceCa(SymbolPath::fromNamespaceFqn('A')));
    }

    private function dep(string $source, string $target, DependencyType $type = DependencyType::New_): Dependency
    {
        return new Dependency(
            SymbolPath::fromClassFqn($source),
            SymbolPath::fromClassFqn($target),
            $type,
            new Location('/test.php', 1),
        );
    }
}
