<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Unit\Core\Dependency;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Core\Dependency\DependencyGraphInterface;
use Qualimetrix\Core\Dependency\EmptyDependencyGraph;
use Qualimetrix\Core\Symbol\SymbolPath;

#[CoversClass(EmptyDependencyGraph::class)]
final class EmptyDependencyGraphTest extends TestCase
{
    private EmptyDependencyGraph $graph;

    protected function setUp(): void
    {
        $this->graph = new EmptyDependencyGraph();
    }

    #[Test]
    public function itImplementsDependencyGraphInterface(): void
    {
        self::assertInstanceOf(DependencyGraphInterface::class, $this->graph); // @phpstan-ignore staticMethod.alreadyNarrowedType
    }

    #[Test]
    public function itGetClassDependenciesReturnsEmptyArray(): void
    {
        self::assertSame([], $this->graph->getClassDependencies(SymbolPath::fromClassFqn('App\Service\UserService')));
        self::assertSame([], $this->graph->getClassDependencies(SymbolPath::fromClassFqn('NonExistent')));
    }

    #[Test]
    public function itGetClassDependentsReturnsEmptyArray(): void
    {
        self::assertSame([], $this->graph->getClassDependents(SymbolPath::fromClassFqn('App\Service\UserService')));
        self::assertSame([], $this->graph->getClassDependents(SymbolPath::fromClassFqn('NonExistent')));
    }

    #[Test]
    public function itGetClassCeReturnsZero(): void
    {
        self::assertSame(0, $this->graph->getClassCe(SymbolPath::fromClassFqn('App\Service\UserService')));
        self::assertSame(0, $this->graph->getClassCe(SymbolPath::fromClassFqn('NonExistent')));
    }

    #[Test]
    public function itGetClassCaReturnsZero(): void
    {
        self::assertSame(0, $this->graph->getClassCa(SymbolPath::fromClassFqn('App\Service\UserService')));
        self::assertSame(0, $this->graph->getClassCa(SymbolPath::fromClassFqn('NonExistent')));
    }

    #[Test]
    public function itGetNamespaceCeReturnsZero(): void
    {
        self::assertSame(0, $this->graph->getNamespaceCe(SymbolPath::fromNamespaceFqn('App\Service')));
        self::assertSame(0, $this->graph->getNamespaceCe(SymbolPath::fromNamespaceFqn('NonExistent')));
    }

    #[Test]
    public function itGetNamespaceCaReturnsZero(): void
    {
        self::assertSame(0, $this->graph->getNamespaceCa(SymbolPath::fromNamespaceFqn('App\Service')));
        self::assertSame(0, $this->graph->getNamespaceCa(SymbolPath::fromNamespaceFqn('NonExistent')));
    }

    #[Test]
    public function itGetAllClassesReturnsEmptyArray(): void
    {
        self::assertSame([], $this->graph->getAllClasses());
    }

    #[Test]
    public function itGetAllNamespacesReturnsEmptyArray(): void
    {
        self::assertSame([], $this->graph->getAllNamespaces());
    }

    #[Test]
    public function itGetAllDependenciesReturnsEmptyArray(): void
    {
        self::assertSame([], $this->graph->getAllDependencies());
    }

    #[Test]
    public function itMultipleCallsReturnConsistentResults(): void
    {
        $classPath = SymbolPath::fromClassFqn('App\Test');
        // First calls
        self::assertSame([], $this->graph->getClassDependencies($classPath));
        self::assertSame(0, $this->graph->getClassCe($classPath));
        self::assertSame([], $this->graph->getAllClasses());

        // Second calls - should return same results
        self::assertSame([], $this->graph->getClassDependencies($classPath));
        self::assertSame(0, $this->graph->getClassCe($classPath));
        self::assertSame([], $this->graph->getAllClasses());
    }

    #[Test]
    public function itDifferentInstancesReturnSameResults(): void
    {
        $graph1 = new EmptyDependencyGraph();
        $graph2 = new EmptyDependencyGraph();

        self::assertEquals($graph1->getAllClasses(), $graph2->getAllClasses());
        self::assertEquals(
            $graph1->getClassCe(SymbolPath::fromClassFqn('Test')),
            $graph2->getClassCe(SymbolPath::fromClassFqn('Test')),
        );
        self::assertEquals($graph1->getAllDependencies(), $graph2->getAllDependencies());
    }
}
