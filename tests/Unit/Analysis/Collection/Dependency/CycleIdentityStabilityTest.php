<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Unit\Analysis\Collection\Dependency;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Collection\Dependency\CircularDependencyDetector;
use Qualimetrix\Analysis\Collection\Dependency\DependencyGraph;
use Qualimetrix\Analysis\Repository\InMemoryMetricRepository;
use Qualimetrix\Architecture\Rules\CircularDependencyOptions;
use Qualimetrix\Architecture\Rules\CircularDependencyRule;
use Qualimetrix\Baseline\BaselineIdentity;
use Qualimetrix\Core\Rule\AnalysisContext;
use Qualimetrix\Core\Symbol\SymbolPath;
use Qualimetrix\Core\Violation\Violation;
use Qualimetrix\Tests\Support\Dependency\AdjacencyGraphBuilder;

/**
 * Regression tests: a cycle's identity must be a function of the graph structure only.
 *
 * The detected SCC partition is unique, but the order of members inside an SCC
 * used to fall out of the traversal order — which follows file discovery order.
 * Since the first member becomes the violation's symbol path, and the symbol
 * path feeds {@see BaselineIdentity::forViolation()}, adding an unrelated file
 * could silently re-key an existing baseline entry: the recorded violation
 * would look resolved and a "new" one would appear in its place for the very
 * same cycle.
 */
#[CoversClass(CircularDependencyDetector::class)]
#[CoversClass(CircularDependencyRule::class)]
final class CycleIdentityStabilityTest extends TestCase
{
    /**
     * Every entry describes the same graph: Alpha → Beta → Gamma → Alpha.
     *
     * The first three are rotations, which preserve relative order; the last two
     * are a full reversal and an arbitrary permutation, so the set does not just
     * probe one shift of the same sequence.
     *
     * @var list<array<string, list<string>>>
     */
    private const array CYCLE_PERMUTATIONS = [
        ['App\Alpha' => ['App\Beta'], 'App\Beta' => ['App\Gamma'], 'App\Gamma' => ['App\Alpha']],
        ['App\Beta' => ['App\Gamma'], 'App\Gamma' => ['App\Alpha'], 'App\Alpha' => ['App\Beta']],
        ['App\Gamma' => ['App\Alpha'], 'App\Alpha' => ['App\Beta'], 'App\Beta' => ['App\Gamma']],
        ['App\Gamma' => ['App\Alpha'], 'App\Beta' => ['App\Gamma'], 'App\Alpha' => ['App\Beta']],
        ['App\Beta' => ['App\Gamma'], 'App\Alpha' => ['App\Beta'], 'App\Gamma' => ['App\Alpha']],
    ];

    #[Test]
    public function itKeepsTheViolationSymbolPathStableAcrossNodeInsertionOrders(): void
    {
        $symbolPaths = [];

        foreach (self::CYCLE_PERMUTATIONS as $adjacencyList) {
            $symbolPaths[] = $this->violationFor($adjacencyList)->symbolPath->toCanonical();
        }

        self::assertSame(
            array_fill(0, \count(self::CYCLE_PERMUTATIONS), 'class:App\Alpha'),
            $symbolPaths,
            'The cycle representative must be the smallest canonical key, whatever the insertion order',
        );
    }

    #[Test]
    public function itKeepsTheCyclePathStableAcrossNodeInsertionOrders(): void
    {
        $messages = [];

        foreach (self::CYCLE_PERMUTATIONS as $adjacencyList) {
            $messages[] = $this->violationFor($adjacencyList)->message;
        }

        self::assertSame(
            array_fill(
                0,
                \count(self::CYCLE_PERMUTATIONS),
                'Circular dependency (3 classes): Alpha → Beta → Gamma → Alpha',
            ),
            $messages,
        );
    }

    #[Test]
    public function itKeepsTheCyclePathStableAcrossEdgeInsertionOrders(): void
    {
        // Alpha sits on two cycles of equal length: Alpha → Beta → Alpha and
        // Alpha → Gamma → Alpha. Which one the search reports must follow from
        // the class names, not from the order the edges happened to be recorded.
        $forward = $this->violationFor([
            'App\Alpha' => ['App\Beta', 'App\Gamma'],
            'App\Beta' => ['App\Alpha'],
            'App\Gamma' => ['App\Alpha'],
        ]);
        $reversed = $this->violationFor([
            'App\Alpha' => ['App\Gamma', 'App\Beta'],
            'App\Beta' => ['App\Alpha'],
            'App\Gamma' => ['App\Alpha'],
        ]);

        self::assertSame($forward->message, $reversed->message);
        self::assertSame('Circular dependency (3 classes): Alpha → Beta → Alpha', $forward->message);
    }

    #[Test]
    public function itKeepsTheBaselineIdentityStableWhenAnUnrelatedClassIsAdded(): void
    {
        $before = BaselineIdentity::forViolation($this->violationFor([
            'App\Alpha' => ['App\Beta'],
            'App\Beta' => ['App\Gamma'],
            'App\Gamma' => ['App\Alpha'],
        ]))->key();

        // A newly discovered file that merely points into the cycle. It joins no
        // cycle itself, but it is visited first and thus reorders the traversal.
        $after = BaselineIdentity::forViolation($this->violationFor([
            'App\Newcomer' => ['App\Beta'],
            'App\Alpha' => ['App\Beta'],
            'App\Beta' => ['App\Gamma'],
            'App\Gamma' => ['App\Alpha'],
        ]))->key();

        self::assertSame($before, $after, 'An unrelated file must not re-key an existing baseline entry');
    }

    #[Test]
    public function itOrdersDetectedCyclesByRepresentative(): void
    {
        $detector = new CircularDependencyDetector();

        $cycles = $detector->detect($this->buildGraph([
            'App\Yankee' => ['App\Zulu'],
            'App\Zulu' => ['App\Yankee'],
            'App\Alpha' => ['App\Beta'],
            'App\Beta' => ['App\Alpha'],
        ]));

        $representatives = array_map(
            static fn($cycle): string => $cycle->getClasses()[0]->toCanonical(),
            $cycles,
        );

        self::assertSame(['class:App\Alpha', 'class:App\Yankee'], $representatives);
    }

    #[Test]
    public function itSortsAllCycleMembersByCanonicalKey(): void
    {
        $detector = new CircularDependencyDetector();

        $cycles = $detector->detect($this->buildGraph([
            'App\Alpha' => ['App\Beta'],
            'App\Beta' => ['App\Gamma'],
            'App\Gamma' => ['App\Alpha'],
        ]));

        self::assertCount(1, $cycles);

        $members = array_map(
            static fn(SymbolPath $path): string => $path->toCanonical(),
            $cycles[0]->getClasses(),
        );

        self::assertSame(['class:App\Alpha', 'class:App\Beta', 'class:App\Gamma'], $members);
    }

    #[Test]
    public function itReportsAPathThatIsAWalkThroughTheGraph(): void
    {
        // A five-member SCC with several routes back to Alpha, so the reported
        // path is a genuine choice rather than the only option.
        $adjacencyList = [
            'App\Alpha' => ['App\Beta', 'App\Delta'],
            'App\Beta' => ['App\Gamma'],
            'App\Gamma' => ['App\Alpha', 'App\Epsilon'],
            'App\Delta' => ['App\Epsilon'],
            'App\Epsilon' => ['App\Alpha'],
        ];

        $cycles = (new CircularDependencyDetector())->detect(AdjacencyGraphBuilder::build($adjacencyList));

        self::assertCount(1, $cycles);
        $path = $cycles[0]->getPath();

        $first = $path[0]->toCanonical();
        self::assertSame('class:App\Alpha', $first, 'The path must start at the representative');
        self::assertSame($first, $path[\count($path) - 1]->toCanonical(), 'The path must return to the representative');

        // Every consecutive pair must be a real edge — the fallback branch of
        // findPath() would return a sorted member list, which is not a walk.
        for ($i = 0; $i < \count($path) - 1; $i++) {
            $from = $path[$i]->toString();
            $to = $path[$i + 1]->toString();

            self::assertContains(
                $to,
                $adjacencyList[$from],
                \sprintf('%s → %s is not an edge of the graph', $from, $to),
            );
        }
    }

    #[Test]
    public function itReportsTheShortestLoopThroughTheRepresentativeNotTheWholeComponent(): void
    {
        // Alpha ↔ Beta ↔ Gamma: all three are one SCC, but the shortest loop
        // through Alpha only covers Alpha and Beta. Documented on the website —
        // the (N classes) counter, not the path, is the authoritative size.
        $violation = $this->violationFor([
            'App\Alpha' => ['App\Beta'],
            'App\Beta' => ['App\Alpha', 'App\Gamma'],
            'App\Gamma' => ['App\Beta'],
        ]);

        self::assertSame('Circular dependency (3 classes): Alpha → Beta → Alpha', $violation->message);
    }

    #[Test]
    public function itIgnoresASelfLoopOnTheRepresentativeWhenBuildingThePath(): void
    {
        // A self-loop must not short-circuit the search into the degenerate
        // Alpha → Alpha → Alpha, which is not a walk through the actual cycle.
        $violation = $this->violationFor([
            'App\Alpha' => ['App\Alpha', 'App\Beta'],
            'App\Beta' => ['App\Alpha'],
        ]);

        self::assertSame('Circular dependency (2 classes): Alpha → Beta → Alpha', $violation->message);
    }

    #[Test]
    public function itIsUnaffectedByRepeatedEdgesBetweenTheSameClasses(): void
    {
        $single = $this->violationFor([
            'App\Alpha' => ['App\Beta'],
            'App\Beta' => ['App\Alpha'],
        ]);
        $repeated = $this->violationFor([
            'App\Alpha' => ['App\Beta', 'App\Beta', 'App\Beta'],
            'App\Beta' => ['App\Alpha', 'App\Alpha'],
        ]);

        self::assertSame($single->message, $repeated->message);
        self::assertSame($single->symbolPath->toCanonical(), $repeated->symbolPath->toCanonical());
    }

    /**
     * Runs the full detector → rule chain and returns the single expected violation.
     *
     * @param array<string, list<string>> $adjacencyList
     */
    private function violationFor(array $adjacencyList): Violation
    {
        $cycles = (new CircularDependencyDetector())->detect($this->buildGraph($adjacencyList));

        $rule = new CircularDependencyRule(new CircularDependencyOptions());
        $violations = $rule->analyze(new AnalysisContext(
            metrics: new InMemoryMetricRepository(),
            cycles: $cycles,
        ));

        self::assertCount(1, $violations);

        return $violations[0];
    }

    /**
     * Builds a dependency graph, preserving the given insertion order of nodes and edges.
     *
     * @param array<string, list<string>> $adjacencyList
     */
    private function buildGraph(array $adjacencyList): DependencyGraph
    {
        return AdjacencyGraphBuilder::build($adjacencyList);
    }
}
