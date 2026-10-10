<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Analysis\Evidence\CircularDependency\Unit;

use PHPUnit\Framework\Attributes\CoversClass;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Evidence\CircularDependency\CircularDependencyAnalysis;
use Qualimetrix\Analysis\Evidence\CircularDependency\CircularDependencyOptions;
use Qualimetrix\Analysis\Evidence\CircularDependency\CircularDependencyRule;
use Qualimetrix\Analysis\Evidence\CircularDependency\Cycle;
use Qualimetrix\Analysis\Evidence\Measurement\Repository\InMemoryMetricRepository;
use Qualimetrix\Analysis\Finding\Contract\OccurrenceKey;
use Qualimetrix\Analysis\Finding\Contract\Rule\AnalysisContext;
use Qualimetrix\Analysis\Finding\Contract\Severity;
use Qualimetrix\Core\Symbol\MetricSubject;
use Qualimetrix\Core\Symbol\SymbolPath;
use Qualimetrix\Tests\Analysis\Evidence\CircularDependency\Support\AdjacencyGraphBuilder;
use Qualimetrix\Tests\Analysis\Evidence\CircularDependency\Support\FixedCycleDetector;
use Qualimetrix\Tests\Analysis\Finding\Support\ResolvedOptionsFixture;

#[CoversClass(CircularDependencyRule::class)]
final class CircularDependencyRuleTest extends TestCase
{
    private CircularDependencyAnalysis $analysis;

    private FixedCycleDetector $detector;

    protected function setUp(): void
    {
        $this->detector = new FixedCycleDetector();
        $this->analysis = new CircularDependencyAnalysis($this->detector);
    }

    #[Test]
    public function itCountsCeilingExclusionsBeforeSeverityAndBypassesNonpositiveCeilings(): void
    {
        $this->prepare([
            new Cycle($this->paths(['A', 'B']), $this->paths(['A', 'B', 'A'])),
            new Cycle($this->paths(['A', 'B', 'C']), $this->paths(['A', 'B', 'C', 'A'])),
        ]);
        $channel = new \Qualimetrix\Analysis\Finding\Contract\FindingChannel(CircularDependencyRule::NAME);
        $publication = new \Qualimetrix\Analysis\Finding\Contract\ChannelPublication(new \Qualimetrix\Analysis\Finding\Contract\RuleEnablement([
            new \Qualimetrix\Analysis\Finding\Contract\EnablementDecision(
                new \Qualimetrix\Analysis\Finding\Contract\Selection\SelectionCellAddress(CircularDependencyRule::NAME, $channel, \Qualimetrix\Core\Symbol\SymbolLevel::Project, \Qualimetrix\Analysis\Finding\Contract\ChannelSelectionRole::Selectable),
                new \Qualimetrix\Analysis\Finding\Contract\Selection\AuthoredCellDecision(\Qualimetrix\Analysis\Finding\Contract\Selection\CellSwitch::On, \Qualimetrix\Analysis\Finding\Contract\Selection\CellAdmission::Direct),
            ),
        ], null));
        foreach ([2, 0, -1] as $ceiling) {
            $session = new \Qualimetrix\Analysis\Finding\Population\PopulationSession(($publication)->publishes(...));
            $findings = $this->rule(new CircularDependencyOptions(maxCycleSize: $ceiling))->analyze(
                (new AnalysisContext(new InMemoryMetricRepository()))->withPopulationTrace($session),
            );
            $population = $session->freeze();
            self::assertCount($ceiling > 0 ? 1 : 2, $findings);
            self::assertSame($ceiling > 0 ? 1 : 2, $population->judgedCount());
            self::assertSame($ceiling > 0 ? 1 : 0, $population->unjudgedCount());
            if ($ceiling > 0) {
                self::assertSame('cycle', $population->abstentions()[0]->unit);
                self::assertSame('max-cycle-size', $population->abstentions()[0]->gate);
            }
        }
    }

    #[Test]
    public function itReturnsCorrectName(): void
    {
        $rule = $this->rule(new CircularDependencyOptions());

        self::assertSame('architecture.circular-dependency', $rule->getName());
    }

    #[Test]
    public function itReturnsDescriptionContainingCircular(): void
    {
        $rule = $this->rule(new CircularDependencyOptions());

        self::assertStringContainsString('circular', strtolower($rule::getDescription()));
    }

    #[Test]
    public function itGeneratesFindingForCycle(): void
    {
        $cycles = [
            new Cycle($this->paths(['A', 'B']), $this->paths(['A', 'B', 'A'])),
        ];

        $this->prepare($cycles);
        $rule = $this->rule(new CircularDependencyOptions());

        $context = new AnalysisContext(
            metrics: new InMemoryMetricRepository(),
        );

        $findings = $rule->analyze($context);

        self::assertCount(1, $findings);
        self::assertSame('architecture.circular-dependency', $findings[0]->ruleName);
        self::assertSame(MetricSubject::aggregate(SymbolPath::forProject())->toCanonical(), $findings[0]->subject->toCanonical());
        self::assertNotNull($findings[0]->occurrenceKey);
        self::assertStringContainsString('Circular dependency (2 classes)', $findings[0]->message);
    }

    /**
     * Pins `occurrence` to the channel's frozen spelling read off a finding
     * produced by {@see CircularDependencyRule::analyze()} itself, so a
     * future regression that swaps the occurrence call site's argument back
     * to `self::NAME` reddens this test once `NAME` and the frozen constant
     * diverge (they will, once the channel is renamed).
     */
    #[Test]
    public function itKeysOccurrenceToTheFrozenChannelSpellingNotToName(): void
    {
        $this->prepare([
            new Cycle($this->paths(['App\\A', 'App\\B']), $this->paths(['App\\A', 'App\\B', 'App\\A'])),
        ]);
        $rule = $this->rule(new CircularDependencyOptions());

        $findings = $rule->analyze(new AnalysisContext(
            metrics: new InMemoryMetricRepository(),
        ));

        self::assertCount(1, $findings);
        self::assertSame(
            OccurrenceKey::semantic('architecture.circular-dependency', [
                'members' => 'class:App\\A,class:App\\B',
            ])->value,
            $findings[0]->occurrenceKey?->value,
        );
    }

    #[Test]
    public function itKeepsCycleIdentityStableWhenMemberOrderChanges(): void
    {
        $rule = $this->rule(new CircularDependencyOptions());

        $this->prepare([new Cycle($this->paths(['App\\A', 'App\\B', 'App\\C']), $this->paths(['App\\A', 'App\\B', 'App\\C', 'App\\A']))]);
        $first = $rule->analyze(new AnalysisContext(
            metrics: new InMemoryMetricRepository(),
        ));
        $this->prepare([new Cycle($this->paths(['App\\C', 'App\\A', 'App\\B']), $this->paths(['App\\C', 'App\\A', 'App\\B', 'App\\C']))]);
        $second = $rule->analyze(new AnalysisContext(
            metrics: new InMemoryMetricRepository(),
        ));

        self::assertSame($first[0]->occurrenceKey?->value, $second[0]->occurrenceKey?->value);
        self::assertSame($first[0]->getFingerprint(), $second[0]->getFingerprint());
    }

    #[Test]
    public function itDistinguishesCyclesWithDifferentCompleteMemberSets(): void
    {
        $this->prepare([
            new Cycle($this->paths(['App\\A', 'App\\B']), $this->paths(['App\\A', 'App\\B', 'App\\A'])),
            new Cycle($this->paths(['App\\A', 'App\\C']), $this->paths(['App\\A', 'App\\C', 'App\\A'])),
        ]);
        $rule = $this->rule(new CircularDependencyOptions());
        $findings = $rule->analyze(new AnalysisContext(
            metrics: new InMemoryMetricRepository(),
        ));

        self::assertCount(2, $findings);
        self::assertNotSame($findings[0]->occurrenceKey?->value, $findings[1]->occurrenceKey?->value);
    }

    #[Test]
    public function itAssignsErrorSeverityForDirectCycle(): void
    {
        $cycles = [
            new Cycle($this->paths(['A', 'B']), $this->paths(['A', 'B', 'A'])), // Size 2
        ];

        $this->prepare($cycles);
        $rule = $this->rule(new CircularDependencyOptions(directAsError: true));

        $context = new AnalysisContext(
            metrics: new InMemoryMetricRepository(),
        );

        $findings = $rule->analyze($context);

        self::assertCount(1, $findings);
        self::assertSame(Severity::Error, $findings[0]->severity);
    }

    #[Test]
    public function itAssignsWarningSeverityForTransitiveCycle(): void
    {
        $cycles = [
            new Cycle($this->paths(['A', 'B', 'C']), $this->paths(['A', 'B', 'C', 'A'])), // Size 3
        ];

        $this->prepare($cycles);
        $rule = $this->rule(new CircularDependencyOptions(directAsError: true));

        $context = new AnalysisContext(
            metrics: new InMemoryMetricRepository(),
        );

        $findings = $rule->analyze($context);

        self::assertCount(1, $findings);
        self::assertSame(Severity::Warning, $findings[0]->severity);
    }

    #[Test]
    public function itRespectsMaxCycleSize(): void
    {
        $cycles = [
            new Cycle($this->paths(['A', 'B']), $this->paths(['A', 'B', 'A'])), // Size 2
            new Cycle($this->paths(['C', 'D', 'E', 'F', 'G']), $this->paths(['C', 'D', 'E', 'F', 'G', 'C'])), // Size 5
        ];

        $this->prepare($cycles);
        $rule = $this->rule(new CircularDependencyOptions(maxCycleSize: 3));

        $context = new AnalysisContext(
            metrics: new InMemoryMetricRepository(),
        );

        $findings = $rule->analyze($context);

        // Only the cycle with size 2 should be reported (size 5 exceeds max)
        self::assertCount(1, $findings);
    }

    #[Test]
    public function itReturnsEmptyWhenDisabled(): void
    {
        $cycles = [
            new Cycle($this->paths(['A', 'B']), $this->paths(['A', 'B', 'A'])),
        ];

        $this->prepare($cycles);
        $rule = $this->rule(new CircularDependencyOptions(enabled: false));

        $context = new AnalysisContext(
            metrics: new InMemoryMetricRepository(),
        );

        $findings = $rule->analyze($context);

        self::assertEmpty($findings);
    }

    #[Test]
    public function itReturnsEmptyWhenNoCycles(): void
    {
        $rule = $this->rule(new CircularDependencyOptions());

        $context = new AnalysisContext(
            metrics: new InMemoryMetricRepository(),
        );

        $findings = $rule->analyze($context);

        self::assertEmpty($findings);
    }

    #[Test]
    public function itSetsMetricValueToCycleSize(): void
    {
        $cycles = [
            new Cycle($this->paths(['A', 'B', 'C']), $this->paths(['A', 'B', 'C', 'A'])),
        ];

        $this->prepare($cycles);
        $rule = $this->rule(new CircularDependencyOptions());

        $context = new AnalysisContext(
            metrics: new InMemoryMetricRepository(),
        );

        $findings = $rule->analyze($context);

        self::assertCount(1, $findings);
        self::assertSame(3, $findings[0]->metricValue);
    }

    #[Test]
    public function itCreatesOptionsFromArrayWithSnakeCase(): void
    {
        $options = CircularDependencyOptions::fromResolved(ResolvedOptionsFixture::values(CircularDependencyOptions::class, [
            'enabled' => true,
            'max_cycle_size' => 5,
            'direct_as_error' => false,
        ]));

        self::assertTrue($options->enabled);
        self::assertSame(5, $options->maxCycleSize);
        self::assertFalse($options->directAsError);
    }

    #[Test]
    public function itCreatesOptionsFromArrayWithCamelCase(): void
    {
        $options = CircularDependencyOptions::fromResolved(ResolvedOptionsFixture::values(CircularDependencyOptions::class, [
            'enabled' => true,
            'maxCycleSize' => 3,
            'directAsError' => true,
        ]));

        self::assertTrue($options->enabled);
        self::assertSame(3, $options->maxCycleSize);
        self::assertTrue($options->directAsError);
    }

    #[Test]
    public function itRefusesTwoAuthoredSpellingsOfTheCycleLimit(): void
    {
        self::expectException(\Qualimetrix\Analysis\Configuration\Contract\Refusal\ConfigurationRefusal::class);
        self::expectExceptionMessage('Keys "max_cycle_size" and "maxCycleSize" in "rules.fixture" in configuration file "/project/qmx.yaml" are two spellings of one key, and a layer may set it only once. Keep one of them.');
        CircularDependencyOptions::fromResolved(ResolvedOptionsFixture::values(CircularDependencyOptions::class, [
            'max_cycle_size' => 5,
            'maxCycleSize' => 3,
        ]));
    }

    #[Test]
    public function itIncludesInterfaceGuidanceForSmallCycles(): void
    {
        $cycles = [
            new Cycle($this->paths(['A', 'B']), $this->paths(['A', 'B', 'A'])),
        ];

        $this->prepare($cycles);
        $rule = $this->rule(new CircularDependencyOptions());

        $context = new AnalysisContext(
            metrics: new InMemoryMetricRepository(),
        );

        $findings = $rule->analyze($context);

        self::assertCount(1, $findings);
        self::assertNotNull($findings[0]->recommendation);
        self::assertStringContainsString('Break by introducing an interface', $findings[0]->recommendation);
    }

    #[Test]
    public function itIncludesAbstractionGuidanceForMediumCycles(): void
    {
        // 10 classes → medium category (6-20)
        $classNames = array_map(static fn(int $i): string => "Class{$i}", range(1, 10));
        $pathNames = [...$classNames, $classNames[0]];

        $cycles = [
            new Cycle($this->paths($classNames), $this->paths($pathNames)),
        ];

        $this->prepare($cycles);
        $rule = $this->rule(new CircularDependencyOptions());

        $context = new AnalysisContext(
            metrics: new InMemoryMetricRepository(),
        );

        $findings = $rule->analyze($context);

        self::assertCount(1, $findings);
        self::assertNotNull($findings[0]->recommendation);
        self::assertStringContainsString('extracting a shared abstraction layer', $findings[0]->recommendation);
    }

    #[Test]
    public function itHasWarningSeverityAndEntryPointGuidanceForLargeCycles(): void
    {
        // 30 classes → large category (>20)
        $classNames = array_map(static fn(int $i): string => "Class{$i}", range(1, 30));
        $pathNames = [...$classNames, $classNames[0]];

        $cycles = [
            new Cycle($this->paths($classNames), $this->paths($pathNames)),
        ];

        $this->prepare($cycles);
        $rule = $this->rule(new CircularDependencyOptions());

        $context = new AnalysisContext(
            metrics: new InMemoryMetricRepository(),
        );

        $findings = $rule->analyze($context);

        self::assertCount(1, $findings);
        self::assertSame(Severity::Warning, $findings[0]->severity);
        self::assertNotNull($findings[0]->recommendation);
        self::assertStringContainsString('focus on the entry-point classes', $findings[0]->recommendation);
    }

    #[Test]
    public function itKeepsRoutingAdviceWithoutEmbeddingCycleData(): void
    {
        $cycles = [
            new Cycle($this->paths(['A', 'B', 'C']), $this->paths(['A', 'B', 'C', 'A'])),
        ];

        $this->prepare($cycles);
        $rule = $this->rule(new CircularDependencyOptions());

        $context = new AnalysisContext(
            metrics: new InMemoryMetricRepository(),
        );

        $findings = $rule->analyze($context);

        self::assertCount(1, $findings);
        self::assertNotNull($findings[0]->recommendation);

        $recommendation = $findings[0]->recommendation;
        self::assertStringNotContainsString('Cycle data:', $recommendation);
        self::assertStringContainsString('invert one dependency', $recommendation);
    }

    #[Test]
    public function itIdentifiesEveryMemberOfACycleWhoseClassNamesCollide(): void
    {
        $classes = ['App\\Billing\\Service', 'App\\Orders\\Service'];
        $cycles = [
            new Cycle(
                $this->paths($classes),
                $this->paths(['App\\Billing\\Service', 'App\\Orders\\Service', 'App\\Billing\\Service']),
            ),
        ];

        $this->prepare($cycles);
        $rule = $this->rule(new CircularDependencyOptions());

        $context = new AnalysisContext(
            metrics: new InMemoryMetricRepository(),
        );

        $findings = $rule->analyze($context);

        self::assertCount(1, $findings);

        // The message used to read "Service → Service → Service".
        self::assertSame(
            'Circular dependency (2 classes): Billing\\Service → Orders\\Service → Billing\\Service',
            $findings[0]->message,
        );

        $recommendation = $findings[0]->recommendation;
        self::assertNotNull($recommendation);

        self::assertStringNotContainsString('Cycle data:', $recommendation);
        self::assertStringContainsString('Billing\\Service → Orders\\Service → Billing\\Service', $recommendation);
    }

    #[Test]
    public function itKeepsLargeCycleAdviceWithoutEmbeddingCycleData(): void
    {
        // 30 classes → large category (>20)
        $classNames = array_map(static fn(int $i): string => "Class{$i}", range(1, 30));
        $pathNames = [...$classNames, $classNames[0]];

        $cycles = [
            new Cycle($this->paths($classNames), $this->paths($pathNames)),
        ];

        $this->prepare($cycles);
        $rule = $this->rule(new CircularDependencyOptions());

        $context = new AnalysisContext(
            metrics: new InMemoryMetricRepository(),
        );

        $findings = $rule->analyze($context);

        self::assertCount(1, $findings);
        self::assertNotNull($findings[0]->recommendation);

        $recommendation = $findings[0]->recommendation;
        self::assertStringNotContainsString('Cycle data:', $recommendation);
        self::assertStringContainsString('entry-point classes', $recommendation);
        self::assertSame(30, $findings[0]->metricValue);
    }

    /**
     * @param list<string> $fqns
     *
     * @return list<SymbolPath>
     */
    private function paths(array $fqns): array
    {
        return array_map(
            static fn(string $fqn): SymbolPath => SymbolPath::fromClassFqn($fqn),
            $fqns,
        );
    }

    /** @param list<Cycle> $cycles */
    private function prepare(array $cycles): void
    {
        $this->detector->cycles = $cycles;
        $this->analysis->prepare(AdjacencyGraphBuilder::empty());
    }

    private function rule(CircularDependencyOptions $options): CircularDependencyRule
    {
        return new CircularDependencyRule($options, $this->analysis);
    }
}
