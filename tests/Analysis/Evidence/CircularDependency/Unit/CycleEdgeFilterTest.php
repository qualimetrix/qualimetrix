<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Analysis\Evidence\CircularDependency\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Evidence\CircularDependency\CircularDependencyDetector;
use Qualimetrix\Analysis\Evidence\CircularDependency\Cycle;
use Qualimetrix\Analysis\Evidence\CircularDependency\CycleEdgeFilter;
use Qualimetrix\Analysis\Evidence\DependencyModel\Contract\DependencyGraphInterface;
use Qualimetrix\Analysis\Evidence\DependencyModel\Contract\DependencyType;
use Qualimetrix\Core\Symbol\SymbolPath;
use Qualimetrix\Tests\Analysis\Evidence\CircularDependency\Support\AdjacencyGraphBuilder;

#[CoversClass(CycleEdgeFilter::class)]
#[CoversClass(CircularDependencyDetector::class)]
final class CycleEdgeFilterTest extends TestCase
{
    #[Test]
    public function itAdmitsEveryKindWithoutAFilter(): void
    {
        $graph = AdjacencyGraphBuilder::typed(['A' => array_map(
            static fn(DependencyType $kind): array => ['B', $kind],
            DependencyType::cases(),
        )]);

        foreach ($graph->getClassDependencies(SymbolPath::fromClassFqn('A')) as $dependency) {
            self::assertTrue(CycleEdgeFilter::none()->admits($dependency), $dependency->type->value);
            self::assertTrue(CycleEdgeFilter::ignoring([])->admits($dependency), $dependency->type->value);
        }
    }

    #[Test]
    public function itRefusesOnlyTheIgnoredKinds(): void
    {
        $filter = CycleEdgeFilter::ignoring([DependencyType::AttributeArgument, DependencyType::New_]);
        $graph = AdjacencyGraphBuilder::typed(['A' => array_map(
            static fn(DependencyType $kind): array => ['B', $kind],
            DependencyType::cases(),
        )]);

        $refused = [];
        foreach ($graph->getClassDependencies(SymbolPath::fromClassFqn('A')) as $dependency) {
            if (!$filter->admits($dependency)) {
                $refused[] = $dependency->type;
            }
        }

        self::assertSame([DependencyType::New_, DependencyType::AttributeArgument], $refused);
    }

    #[Test]
    public function itRemovesACycleThatOnlyAnIgnoredKindCloses(): void
    {
        // The reproduction of the attribute-mapped repository: the entity names
        // its repository only inside an attribute argument.
        $graph = AdjacencyGraphBuilder::typed([
            'App\\E' => [['App\\Entity', DependencyType::Attribute], ['App\\Repo', DependencyType::AttributeArgument]],
            'App\\Repo' => [['App\\E', DependencyType::New_], ['App\\E', DependencyType::TypeHint]],
        ]);

        self::assertCount(1, (new CircularDependencyDetector())->detect($graph));
        self::assertSame([], $this->detectIgnoring($graph, [DependencyType::AttributeArgument]));
    }

    #[Test]
    public function itKeepsACycleWhoseIgnoredLinkIsAlsoAnOrdinaryDependency(): void
    {
        $graph = AdjacencyGraphBuilder::typed([
            'App\\E' => [['App\\Repo', DependencyType::AttributeArgument], ['App\\Repo', DependencyType::ClassConstFetch]],
            'App\\Repo' => [['App\\E', DependencyType::New_]],
        ]);

        $unfiltered = (new CircularDependencyDetector())->detect($graph);
        $filtered = $this->detectIgnoring($graph, [DependencyType::AttributeArgument]);

        self::assertCount(1, $filtered);
        self::assertSame($this->describe($unfiltered), $this->describe($filtered));
    }

    #[Test]
    public function itRemovesACycleOnlyOnceEveryKindClosingItIsIgnored(): void
    {
        $graph = AdjacencyGraphBuilder::typed([
            'App\\A' => [['App\\B', DependencyType::ClassConstFetch], ['App\\B', DependencyType::StaticCall]],
            'App\\B' => [['App\\A', DependencyType::TypeHint]],
        ]);

        self::assertCount(1, $this->detectIgnoring($graph, [DependencyType::ClassConstFetch]));
        self::assertSame([], $this->detectIgnoring($graph, [
            DependencyType::StaticCall,
            DependencyType::StaticPropertyFetch,
            DependencyType::ClassConstFetch,
        ]));
    }

    /**
     * The filter changes which cycles exist, never how a remaining one is
     * keyed: an untouched cycle keeps members, representative and path.
     */
    #[Test]
    public function itLeavesTheIdentityOfRemainingCyclesUnchanged(): void
    {
        $graph = AdjacencyGraphBuilder::typed([
            'App\\A' => [['App\\B', DependencyType::TypeHint]],
            'App\\B' => [['App\\C', DependencyType::New_]],
            'App\\C' => [['App\\A', DependencyType::StaticCall]],
            'App\\M' => [['App\\N', DependencyType::AttributeArgument]],
            'App\\N' => [['App\\M', DependencyType::TypeHint]],
        ]);

        $unfiltered = (new CircularDependencyDetector())->detect($graph);
        $filtered = $this->detectIgnoring($graph, [DependencyType::AttributeArgument]);

        self::assertCount(2, $unfiltered);
        self::assertSame([$this->describe($unfiltered)[0]], $this->describe($filtered));
    }

    /**
     * The displayed path reads the same admitted records as the partition. An
     * ignored `B → A` would close the loop through the representative in two
     * steps; with it removed the component is still cyclic, and the path has
     * to go the long way round.
     */
    #[Test]
    public function itDisplaysAPathThroughAdmittedRecordsOnly(): void
    {
        $graph = AdjacencyGraphBuilder::typed([
            'App\\A' => [['App\\B', DependencyType::TypeHint]],
            'App\\B' => [['App\\A', DependencyType::AttributeArgument], ['App\\C', DependencyType::TypeHint]],
            'App\\C' => [['App\\A', DependencyType::TypeHint]],
        ]);

        $unfiltered = (new CircularDependencyDetector())->detect($graph);
        $filtered = $this->detectIgnoring($graph, [DependencyType::AttributeArgument]);

        self::assertSame(['App\\A → App\\B → App\\A'], array_map(static fn(Cycle $cycle): string => $cycle->toString(), $unfiltered));
        self::assertCount(1, $filtered);
        self::assertSame(3, $filtered[0]->getSize());
        self::assertSame('App\\A → App\\B → App\\C → App\\A', $filtered[0]->toString());
    }

    #[Test]
    public function itLeavesTheSharedDetectorUnfiltered(): void
    {
        $graph = AdjacencyGraphBuilder::typed([
            'App\\A' => [['App\\B', DependencyType::AttributeArgument]],
            'App\\B' => [['App\\A', DependencyType::TypeHint]],
        ]);
        $detector = new CircularDependencyDetector();

        self::assertSame([], $detector->admitting(CycleEdgeFilter::ignoring([DependencyType::AttributeArgument]))->detect($graph));
        self::assertCount(1, $detector->detect($graph));
    }

    /**
     * @param list<DependencyType> $kinds
     *
     * @return list<Cycle>
     */
    private function detectIgnoring(DependencyGraphInterface $graph, array $kinds): array
    {
        return (new CircularDependencyDetector())->admitting(CycleEdgeFilter::ignoring($kinds))->detect($graph);
    }

    /**
     * @param list<Cycle> $cycles
     *
     * @return list<array{list<string>, string}>
     */
    private function describe(array $cycles): array
    {
        return array_map(
            static fn(Cycle $cycle): array => [
                array_map(static fn(SymbolPath $path): string => $path->toCanonical(), $cycle->getClasses()),
                $cycle->toString(),
            ],
            $cycles,
        );
    }
}
