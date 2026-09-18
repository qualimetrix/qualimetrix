<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Analysis\Evidence\DependencyModel\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Evidence\DependencyModel\Contract\DependencyGraphInterface;
use Qualimetrix\Analysis\Evidence\DependencyModel\EmptyDependencyGraph;
use Qualimetrix\Core\Symbol\SymbolPath;
use ReflectionClass;
use ReflectionParameter;

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

    /**
     * The roll-call, read off the contract rather than restated here: every
     * method the graph promises answers emptily, so a method added to the
     * interface cannot arrive with a non-empty answer on the null object.
     */
    #[Test]
    public function itAnswersEveryMethodTheContractDeclaresWithNothing(): void
    {
        $answered = [];
        $implementation = new ReflectionClass($this->graph);

        foreach ((new ReflectionClass(DependencyGraphInterface::class))->getMethods() as $declared) {
            $arguments = array_map(
                static fn(ReflectionParameter $parameter): SymbolPath => str_contains($parameter->getName(), 'namespace')
                    ? SymbolPath::fromNamespaceFqn('App\\Service')
                    : SymbolPath::fromClassFqn('App\\Service\\UserService'),
                $declared->getParameters(),
            );

            $answered[$declared->getName()] = $implementation
                ->getMethod($declared->getName())
                ->invokeArgs($this->graph, $arguments);
        }

        self::assertNotSame([], $answered, 'The contract declared no methods, so this case checked nothing.');

        foreach ($answered as $name => $answer) {
            self::assertContains($answer, [[], 0], \sprintf('%s() is not empty on the empty graph', $name));
        }
    }
}
