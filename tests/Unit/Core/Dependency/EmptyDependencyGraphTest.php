<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Unit\Core\Dependency;

use PHPUnit\Framework\Attributes\CoversClass;
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

    public function testImplementsDependencyGraphInterface(): void
    {
        self::assertInstanceOf(DependencyGraphInterface::class, $this->graph);
    }

    public function testGetClassDependenciesReturnsEmptyArray(): void
    {
        self::assertSame([], $this->graph->getClassDependencies(SymbolPath::fromClassFqn('App\Service\UserService')));
        self::assertSame([], $this->graph->getClassDependencies(SymbolPath::fromClassFqn('NonExistent')));
    }

    public function testGetClassDependentsReturnsEmptyArray(): void
    {
        self::assertSame([], $this->graph->getClassDependents(SymbolPath::fromClassFqn('App\Service\UserService')));
        self::assertSame([], $this->graph->getClassDependents(SymbolPath::fromClassFqn('NonExistent')));
    }

    public function testGetClassCeReturnsZero(): void
    {
        self::assertSame(0, $this->graph->getClassCe(SymbolPath::fromClassFqn('App\Service\UserService')));
        self::assertSame(0, $this->graph->getClassCe(SymbolPath::fromClassFqn('NonExistent')));
    }

    public function testGetClassCaReturnsZero(): void
    {
        self::assertSame(0, $this->graph->getClassCa(SymbolPath::fromClassFqn('App\Service\UserService')));
        self::assertSame(0, $this->graph->getClassCa(SymbolPath::fromClassFqn('NonExistent')));
    }

    public function testGetNamespaceCeReturnsZero(): void
    {
        self::assertSame(0, $this->graph->getNamespaceCe(SymbolPath::fromNamespaceFqn('App\Service')));
        self::assertSame(0, $this->graph->getNamespaceCe(SymbolPath::fromNamespaceFqn('NonExistent')));
    }

    public function testGetNamespaceCaReturnsZero(): void
    {
        self::assertSame(0, $this->graph->getNamespaceCa(SymbolPath::fromNamespaceFqn('App\Service')));
        self::assertSame(0, $this->graph->getNamespaceCa(SymbolPath::fromNamespaceFqn('NonExistent')));
    }

    public function testGetAllClassesReturnsEmptyArray(): void
    {
        self::assertSame([], $this->graph->getAllClasses());
    }

    public function testGetAllNamespacesReturnsEmptyArray(): void
    {
        self::assertSame([], $this->graph->getAllNamespaces());
    }

    public function testGetAllDependenciesReturnsEmptyArray(): void
    {
        self::assertSame([], $this->graph->getAllDependencies());
    }

    public function testMultipleCallsReturnConsistentResults(): void
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

    public function testDifferentInstancesReturnSameResults(): void
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
