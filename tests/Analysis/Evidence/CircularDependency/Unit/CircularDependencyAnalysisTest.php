<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Analysis\Evidence\CircularDependency\Unit;

use ArrayObject;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Evidence\CircularDependency\CircularDependencyAnalysis;
use Qualimetrix\Analysis\Evidence\CircularDependency\CircularDependencyDetector;
use Qualimetrix\Analysis\Evidence\CircularDependency\CircularDependencyRule;
use Qualimetrix\Analysis\Evidence\DependencyModel\Contract\DependencyGraphInterface;
use Qualimetrix\Analysis\Evidence\DependencyModel\Contract\DependencyType;
use Qualimetrix\Analysis\Evidence\Measurement\Repository\InMemoryMetricRepository;
use Qualimetrix\Analysis\Finding\Contract\Rule\AnalysisContext;
use Qualimetrix\Analysis\Finding\Contract\Severity;
use Qualimetrix\Tests\Analysis\Evidence\CircularDependency\Support\AdjacencyGraphBuilder;
use Qualimetrix\Tests\Analysis\Evidence\CircularDependency\Support\CycleRuleConfiguration;
use ReflectionClass;
use ReflectionMethod;

#[CoversClass(CircularDependencyAnalysis::class)]
final class CircularDependencyAnalysisTest extends TestCase
{
    #[Test]
    public function itResetsPreparedCyclesWithoutTraversingTheGraph(): void
    {
        // Shared rather than a field of the double: preparation detects on a
        // configured copy of the detector.
        $calls = new ArrayObject();
        $detector = new class ($calls) extends CircularDependencyDetector {
            /** @param ArrayObject<int, true> $calls */
            public function __construct(private readonly ArrayObject $calls) {}

            public function detect(DependencyGraphInterface $graph): array
            {
                $this->calls->append(true);

                return parent::detect($graph);
            }
        };
        $analysis = new CircularDependencyAnalysis($detector, CycleRuleConfiguration::authored());

        $analysis->prepare(AdjacencyGraphBuilder::build([
            'App\\A' => ['App\\B'],
            'App\\B' => ['App\\A'],
        ]));
        self::assertCount(1, $analysis->all());
        self::assertCount(1, $calls);

        $analysis->reset();

        self::assertSame([], $analysis->all());
        self::assertCount(1, $calls);
    }

    /**
     * The evidence has one source, the graph `prepare()` is given. A public
     * setter beside it would let anything swap the evidence after preparation,
     * and `all()` could not tell the two apart.
     */
    #[Test]
    public function itTakesEvidenceOnlyFromPreparation(): void
    {
        $public = array_map(
            static fn(ReflectionMethod $method): string => $method->getName(),
            (new ReflectionClass(CircularDependencyAnalysis::class))->getMethods(ReflectionMethod::IS_PUBLIC),
        );
        sort($public);

        self::assertSame(['__construct', 'all', 'prepare', 'reset'], $public);
    }

    #[Test]
    public function itReplacesResultsAcrossSequentialPrepares(): void
    {
        $analysis = new CircularDependencyAnalysis(new CircularDependencyDetector(), CycleRuleConfiguration::authored());

        $analysis->prepare(AdjacencyGraphBuilder::build([
            'App\\A' => ['App\\B'],
            'App\\B' => ['App\\A'],
        ]));
        self::assertCount(1, $analysis->all());

        $analysis->prepare(AdjacencyGraphBuilder::build([
            'App\\C' => ['App\\D'],
            'App\\D' => [],
        ]));

        self::assertSame([], $analysis->all());
    }

    #[Test]
    public function itFiltersByTheIgnoredRelationsOfTheRunsResolvedOptions(): void
    {
        $graph = self::attributeMappedRepository();

        $absent = new CircularDependencyAnalysis(new CircularDependencyDetector(), CycleRuleConfiguration::authored());
        $absent->prepare($graph);
        $empty = new CircularDependencyAnalysis(new CircularDependencyDetector(), CycleRuleConfiguration::authored(['ignore_relations' => []]));
        $empty->prepare($graph);
        $ignoring = new CircularDependencyAnalysis(
            new CircularDependencyDetector(),
            CycleRuleConfiguration::authored(['ignore_relations' => ['attribute_argument']]),
        );
        $ignoring->prepare($graph);

        self::assertCount(1, $absent->all());
        self::assertEquals($absent->all(), $empty->all());
        self::assertSame([], $ignoring->all());
    }

    /**
     * Severity and the size ceiling judge the cycle the filter leaves: the
     * three-class component shrinks to a direct cycle once `C → A`, an
     * attribute-argument record, stops counting.
     */
    #[Test]
    public function itJudgesSizeCeilingAndDirectSeverityOnTheFilteredGraph(): void
    {
        $graph = AdjacencyGraphBuilder::typed([
            'App\\A' => [['App\\B', DependencyType::TypeHint]],
            'App\\B' => [['App\\A', DependencyType::TypeHint], ['App\\C', DependencyType::TypeHint]],
            'App\\C' => [['App\\A', DependencyType::AttributeArgument]],
        ]);

        foreach ([[], ['attribute_argument']] as $ignored) {
            foreach ([0, 2] as $ceiling) {
                $configuration = CycleRuleConfiguration::authored([
                    'ignore_relations' => $ignored,
                    'max_cycle_size' => $ceiling,
                    'direct_as_error' => true,
                ]);
                $analysis = new CircularDependencyAnalysis(new CircularDependencyDetector(), $configuration);
                $analysis->prepare($graph);
                $findings = (new CircularDependencyRule(CycleRuleConfiguration::options($configuration), $analysis))
                    ->analyze(new AnalysisContext(new InMemoryMetricRepository()));
                $case = \sprintf('ignored [%s], ceiling %d', implode(', ', $ignored), $ceiling);

                if ($ignored === []) {
                    self::assertCount($ceiling === 0 ? 1 : 0, $findings, $case);
                    if ($ceiling === 0) {
                        self::assertSame(3, $findings[0]->metricValue, $case);
                        self::assertSame(Severity::Warning, $findings[0]->severity, $case);
                    }

                    continue;
                }

                self::assertCount(1, $findings, $case);
                self::assertSame(2, $findings[0]->metricValue, $case);
                self::assertSame(Severity::Error, $findings[0]->severity, $case);
            }
        }
    }

    private static function attributeMappedRepository(): DependencyGraphInterface
    {
        return AdjacencyGraphBuilder::typed([
            'App\\E' => [['App\\Entity', DependencyType::Attribute], ['App\\Repo', DependencyType::AttributeArgument]],
            'App\\Repo' => [['App\\E', DependencyType::New_], ['App\\E', DependencyType::TypeHint]],
        ]);
    }
}
