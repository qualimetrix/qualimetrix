<?php

declare(strict_types=1);

namespace AiMessDetector\Tests\Unit\Core\Dependency;

use AiMessDetector\Core\Dependency\DependencyGraphInterface;
use AiMessDetector\Core\Dependency\EmptyDependencyGraph;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

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
        self::assertSame([], $this->graph->getClassDependencies('App\Service\UserService'));
        self::assertSame([], $this->graph->getClassDependencies('NonExistent'));
        self::assertSame([], $this->graph->getClassDependencies(''));
    }

    public function testGetClassDependentsReturnsEmptyArray(): void
    {
        self::assertSame([], $this->graph->getClassDependents('App\Service\UserService'));
        self::assertSame([], $this->graph->getClassDependents('NonExistent'));
        self::assertSame([], $this->graph->getClassDependents(''));
    }

    public function testGetClassCeReturnsZero(): void
    {
        self::assertSame(0, $this->graph->getClassCe('App\Service\UserService'));
        self::assertSame(0, $this->graph->getClassCe('NonExistent'));
        self::assertSame(0, $this->graph->getClassCe(''));
    }

    public function testGetClassCaReturnsZero(): void
    {
        self::assertSame(0, $this->graph->getClassCa('App\Service\UserService'));
        self::assertSame(0, $this->graph->getClassCa('NonExistent'));
        self::assertSame(0, $this->graph->getClassCa(''));
    }

    public function testGetNamespaceCeReturnsZero(): void
    {
        self::assertSame(0, $this->graph->getNamespaceCe('App\Service'));
        self::assertSame(0, $this->graph->getNamespaceCe('NonExistent'));
        self::assertSame(0, $this->graph->getNamespaceCe(''));
    }

    public function testGetNamespaceCaReturnsZero(): void
    {
        self::assertSame(0, $this->graph->getNamespaceCa('App\Service'));
        self::assertSame(0, $this->graph->getNamespaceCa('NonExistent'));
        self::assertSame(0, $this->graph->getNamespaceCa(''));
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
        // First calls
        self::assertSame([], $this->graph->getClassDependencies('App\Test'));
        self::assertSame(0, $this->graph->getClassCe('App\Test'));
        self::assertSame([], $this->graph->getAllClasses());

        // Second calls - should return same results
        self::assertSame([], $this->graph->getClassDependencies('App\Test'));
        self::assertSame(0, $this->graph->getClassCe('App\Test'));
        self::assertSame([], $this->graph->getAllClasses());
    }

    public function testDifferentInstancesReturnSameResults(): void
    {
        $graph1 = new EmptyDependencyGraph();
        $graph2 = new EmptyDependencyGraph();

        self::assertEquals($graph1->getAllClasses(), $graph2->getAllClasses());
        self::assertEquals($graph1->getClassCe('Test'), $graph2->getClassCe('Test'));
        self::assertEquals($graph1->getAllDependencies(), $graph2->getAllDependencies());
    }
}
