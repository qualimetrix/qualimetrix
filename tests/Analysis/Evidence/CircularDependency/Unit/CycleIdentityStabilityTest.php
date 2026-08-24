<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Analysis\Evidence\CircularDependency\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Evidence\CircularDependency\CircularDependencyAnalysis;
use Qualimetrix\Analysis\Evidence\CircularDependency\CircularDependencyDetector;
use Qualimetrix\Analysis\Evidence\CircularDependency\CircularDependencyOptions;
use Qualimetrix\Analysis\Evidence\CircularDependency\CircularDependencyRule;
use Qualimetrix\Analysis\Evidence\DependencyModel\Contract\DependencyGraphInterface;
use Qualimetrix\Analysis\Evidence\Measurement\Repository\InMemoryMetricRepository;
use Qualimetrix\Analysis\Finding\Contract\Finding;
use Qualimetrix\Analysis\Finding\Contract\Rule\AnalysisContext;
use Qualimetrix\Analysis\Policy\Baseline\BaselineIdentity;
use Qualimetrix\Core\Symbol\MetricSubject;
use Qualimetrix\Core\Symbol\SymbolPath;
use Qualimetrix\Tests\Analysis\Evidence\CircularDependency\Support\AdjacencyGraphBuilder;

/**
 * Regression tests: a cycle's identity must be a function of the graph structure only.
 *
 * The detected SCC partition is unique, but the order of members inside an SCC
 * used to fall out of the traversal order — which follows file discovery order.
 * Findings use the project aggregate for their subject/display and a sorted
 * complete member-list occurrence key. Adding an unrelated file must not
 * re-key the existing baseline identity, while a different member set must.
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
    public function itKeepsTheProjectSubjectAndOccurrenceStableAcrossNodeInsertionOrders(): void
    {
        $symbolPaths = [];
        $occurrences = [];
        $fingerprints = [];

        foreach (self::CYCLE_PERMUTATIONS as $adjacencyList) {
            $finding = $this->findingFor($adjacencyList);
            $symbolPaths[] = $finding->symbolPath->toCanonical();
            $occurrences[] = $finding->occurrenceKey?->value;
            $fingerprints[] = $finding->getFingerprint();
        }

        self::assertSame(
            array_fill(0, \count(self::CYCLE_PERMUTATIONS), SymbolPath::forProject()->toCanonical()),
            $symbolPaths,
            'Cycle findings use the project aggregate as their display projection.',
        );
        self::assertSame(array_fill(0, \count(self::CYCLE_PERMUTATIONS), $occurrences[0]), $occurrences);
        self::assertSame(array_fill(0, \count(self::CYCLE_PERMUTATIONS), $fingerprints[0]), $fingerprints);
    }

    #[Test]
    public function itDistinguishesFindingsWithDifferentCompleteMemberSets(): void
    {
        $twoMembers = $this->findingFor([
            'App\Alpha' => ['App\Beta'],
            'App\Beta' => ['App\Alpha'],
        ]);
        $threeMembers = $this->findingFor([
            'App\Alpha' => ['App\Beta'],
            'App\Beta' => ['App\Gamma'],
            'App\Gamma' => ['App\Alpha'],
        ]);

        self::assertSame(MetricSubject::aggregate(SymbolPath::forProject())->toCanonical(), $twoMembers->subject->toCanonical());
        self::assertSame($twoMembers->subject->toCanonical(), $threeMembers->subject->toCanonical());
        self::assertNotSame($twoMembers->occurrenceKey?->value, $threeMembers->occurrenceKey?->value);
        self::assertNotSame($twoMembers->getFingerprint(), $threeMembers->getFingerprint());
    }

    #[Test]
    public function itKeepsTheCyclePathStableAcrossNodeInsertionOrders(): void
    {
        $messages = [];

        foreach (self::CYCLE_PERMUTATIONS as $adjacencyList) {
            $messages[] = $this->findingFor($adjacencyList)->message;
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
        $forward = $this->findingFor([
            'App\Alpha' => ['App\Beta', 'App\Gamma'],
            'App\Beta' => ['App\Alpha'],
            'App\Gamma' => ['App\Alpha'],
        ]);
        $reversed = $this->findingFor([
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
        $before = BaselineIdentity::forFinding($this->findingFor([
            'App\Alpha' => ['App\Beta'],
            'App\Beta' => ['App\Gamma'],
            'App\Gamma' => ['App\Alpha'],
        ]))->key();

        // A newly discovered file that merely points into the cycle. It joins no
        // cycle itself, but it is visited first and thus reorders the traversal.
        $after = BaselineIdentity::forFinding($this->findingFor([
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
        $finding = $this->findingFor([
            'App\Alpha' => ['App\Beta'],
            'App\Beta' => ['App\Alpha', 'App\Gamma'],
            'App\Gamma' => ['App\Beta'],
        ]);

        self::assertSame('Circular dependency (3 classes): Alpha → Beta → Alpha', $finding->message);
    }

    #[Test]
    public function itIgnoresASelfLoopOnTheRepresentativeWhenBuildingThePath(): void
    {
        // A self-loop must not short-circuit the search into the degenerate
        // Alpha → Alpha → Alpha, which is not a walk through the actual cycle.
        $finding = $this->findingFor([
            'App\Alpha' => ['App\Alpha', 'App\Beta'],
            'App\Beta' => ['App\Alpha'],
        ]);

        self::assertSame('Circular dependency (2 classes): Alpha → Beta → Alpha', $finding->message);
    }

    #[Test]
    public function itIsUnaffectedByRepeatedEdgesBetweenTheSameClasses(): void
    {
        $single = $this->findingFor([
            'App\Alpha' => ['App\Beta'],
            'App\Beta' => ['App\Alpha'],
        ]);
        $repeated = $this->findingFor([
            'App\Alpha' => ['App\Beta', 'App\Beta', 'App\Beta'],
            'App\Beta' => ['App\Alpha', 'App\Alpha'],
        ]);

        self::assertSame($single->message, $repeated->message);
        self::assertSame($single->symbolPath->toCanonical(), $repeated->symbolPath->toCanonical());
        self::assertSame($single->occurrenceKey?->value, $repeated->occurrenceKey?->value);
    }

    /**
     * Runs the full detector → rule chain and returns the single expected finding.
     *
     * @param array<string, list<string>> $adjacencyList
     */
    private function findingFor(array $adjacencyList): Finding
    {
        $cycles = (new CircularDependencyDetector())->detect($this->buildGraph($adjacencyList));

        $analysis = new CircularDependencyAnalysis(new CircularDependencyDetector());
        $analysis->replace($cycles);
        $rule = new CircularDependencyRule(new CircularDependencyOptions(), $analysis);
        $findings = $rule->analyze(new AnalysisContext(
            metrics: new InMemoryMetricRepository(),
        ));

        self::assertCount(1, $findings);

        return $findings[0];
    }

    /**
     * Builds a dependency graph, preserving the given insertion order of nodes and edges.
     *
     * @param array<string, list<string>> $adjacencyList
     */
    private function buildGraph(array $adjacencyList): DependencyGraphInterface
    {
        return AdjacencyGraphBuilder::build($adjacencyList);
    }
}
