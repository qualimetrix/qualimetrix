<?php

declare(strict_types=1);

namespace AiMessDetector\Tests\Unit\Analysis\Collection\Dependency;

use AiMessDetector\Analysis\Collection\Dependency\DependencyGraph;
use AiMessDetector\Analysis\Collection\Dependency\DependencyGraphBuilder;
use AiMessDetector\Core\Dependency\Dependency;
use AiMessDetector\Core\Dependency\DependencyType;
use AiMessDetector\Core\Symbol\SymbolPath;
use AiMessDetector\Core\Violation\Location;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

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

        self::assertCount(4, $namespaces);
        self::assertContains('App\\Service', $namespaces);
        self::assertContains('App\\Domain', $namespaces);
        self::assertContains('Vendor\\Package', $namespaces);
        self::assertContains('Vendor\\Other', $namespaces);
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

    private function dep(string $source, string $target): Dependency
    {
        return new Dependency(
            SymbolPath::fromClassFqn($source),
            SymbolPath::fromClassFqn($target),
            DependencyType::New_,
            new Location('/test.php', 1),
        );
    }
}
