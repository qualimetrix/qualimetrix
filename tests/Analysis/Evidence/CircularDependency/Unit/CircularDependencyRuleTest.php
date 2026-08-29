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
use Qualimetrix\Analysis\Evidence\CircularDependency\Cycle;
use Qualimetrix\Analysis\Evidence\Measurement\Repository\InMemoryMetricRepository;
use Qualimetrix\Analysis\Finding\Contract\Rule\AnalysisContext;
use Qualimetrix\Analysis\Finding\Contract\Severity;
use Qualimetrix\Core\Symbol\MetricSubject;
use Qualimetrix\Core\Symbol\SymbolPath;

#[CoversClass(CircularDependencyRule::class)]
final class CircularDependencyRuleTest extends TestCase
{
    private CircularDependencyAnalysis $analysis;

    protected function setUp(): void
    {
        $this->analysis = new CircularDependencyAnalysis(new CircularDependencyDetector());
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

        self::assertStringContainsString('circular', strtolower($rule->getDescription()));
    }

    #[Test]
    public function itGeneratesFindingForCycle(): void
    {
        $cycles = [
            new Cycle($this->paths(['A', 'B']), $this->paths(['A', 'B', 'A'])),
        ];

        $this->analysis->replace($cycles);
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

    #[Test]
    public function itKeepsCycleIdentityStableWhenMemberOrderChanges(): void
    {
        $rule = $this->rule(new CircularDependencyOptions());

        $this->analysis->replace([new Cycle($this->paths(['App\\A', 'App\\B', 'App\\C']), $this->paths(['App\\A', 'App\\B', 'App\\C', 'App\\A']))]);
        $first = $rule->analyze(new AnalysisContext(
            metrics: new InMemoryMetricRepository(),
        ));
        $this->analysis->replace([new Cycle($this->paths(['App\\C', 'App\\A', 'App\\B']), $this->paths(['App\\C', 'App\\A', 'App\\B', 'App\\C']))]);
        $second = $rule->analyze(new AnalysisContext(
            metrics: new InMemoryMetricRepository(),
        ));

        self::assertSame($first[0]->occurrenceKey?->value, $second[0]->occurrenceKey?->value);
        self::assertSame($first[0]->getFingerprint(), $second[0]->getFingerprint());
    }

    #[Test]
    public function itDistinguishesCyclesWithDifferentCompleteMemberSets(): void
    {
        $this->analysis->replace([
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

        $this->analysis->replace($cycles);
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

        $this->analysis->replace($cycles);
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

        $this->analysis->replace($cycles);
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

        $this->analysis->replace($cycles);
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

        $this->analysis->replace($cycles);
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
        $options = CircularDependencyOptions::fromArray([
            'enabled' => true,
            'max_cycle_size' => 5,
            'direct_as_error' => false,
        ]);

        self::assertTrue($options->enabled);
        self::assertSame(5, $options->maxCycleSize);
        self::assertFalse($options->directAsError);
    }

    #[Test]
    public function itCreatesOptionsFromArrayWithCamelCase(): void
    {
        $options = CircularDependencyOptions::fromArray([
            'enabled' => true,
            'maxCycleSize' => 3,
            'directAsError' => true,
        ]);

        self::assertTrue($options->enabled);
        self::assertSame(3, $options->maxCycleSize);
        self::assertTrue($options->directAsError);
    }

    #[Test]
    public function itGivesSnakeCasePrecedenceOverCamelCase(): void
    {
        $options = CircularDependencyOptions::fromArray([
            'max_cycle_size' => 5,
            'maxCycleSize' => 3,
        ]);

        self::assertSame(5, $options->maxCycleSize);
    }

    #[Test]
    public function itIncludesInterfaceGuidanceForSmallCycles(): void
    {
        $cycles = [
            new Cycle($this->paths(['A', 'B']), $this->paths(['A', 'B', 'A'])),
        ];

        $this->analysis->replace($cycles);
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

        $this->analysis->replace($cycles);
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

        $this->analysis->replace($cycles);
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
    public function itContainsStructuredJsonDataInRecommendation(): void
    {
        $cycles = [
            new Cycle($this->paths(['A', 'B', 'C']), $this->paths(['A', 'B', 'C', 'A'])),
        ];

        $this->analysis->replace($cycles);
        $rule = $this->rule(new CircularDependencyOptions());

        $context = new AnalysisContext(
            metrics: new InMemoryMetricRepository(),
        );

        $findings = $rule->analyze($context);

        self::assertCount(1, $findings);
        self::assertNotNull($findings[0]->recommendation);

        $recommendation = $findings[0]->recommendation;
        self::assertStringContainsString('Cycle data: {', $recommendation);

        // Extract JSON from recommendation
        $jsonStart = strpos($recommendation, 'Cycle data: ');
        self::assertIsInt($jsonStart);
        $jsonString = substr($recommendation, $jsonStart + \strlen('Cycle data: '));
        $decoded = json_decode($jsonString, true);

        self::assertIsArray($decoded);
        self::assertArrayHasKey('cycle', $decoded);
        self::assertArrayHasKey('length', $decoded);
        self::assertArrayHasKey('category', $decoded);
        self::assertSame(3, $decoded['length']);
        self::assertSame('small', $decoded['category']);
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

        $this->analysis->replace($cycles);
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

        $jsonStart = strpos($recommendation, 'Cycle data: ');
        self::assertIsInt($jsonStart);
        $decoded = json_decode(substr($recommendation, $jsonStart + \strlen('Cycle data: ')), true);

        self::assertIsArray($decoded);
        self::assertSame(
            ['App\\Billing\\Service', 'App\\Orders\\Service', 'App\\Billing\\Service'],
            $decoded['cycle'],
        );
    }

    #[Test]
    public function itLabelsCategoryAsLargeForBigCycles(): void
    {
        // 30 classes → large category (>20)
        $classNames = array_map(static fn(int $i): string => "Class{$i}", range(1, 30));
        $pathNames = [...$classNames, $classNames[0]];

        $cycles = [
            new Cycle($this->paths($classNames), $this->paths($pathNames)),
        ];

        $this->analysis->replace($cycles);
        $rule = $this->rule(new CircularDependencyOptions());

        $context = new AnalysisContext(
            metrics: new InMemoryMetricRepository(),
        );

        $findings = $rule->analyze($context);

        self::assertCount(1, $findings);
        self::assertNotNull($findings[0]->recommendation);

        $recommendation = $findings[0]->recommendation;
        $jsonStart = strpos($recommendation, 'Cycle data: ');
        self::assertIsInt($jsonStart);
        $jsonString = substr($recommendation, $jsonStart + \strlen('Cycle data: '));
        $decoded = json_decode($jsonString, true);

        self::assertIsArray($decoded);
        self::assertSame('large', $decoded['category']);
        self::assertSame(30, $decoded['length']);
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

    private function rule(CircularDependencyOptions $options): CircularDependencyRule
    {
        return new CircularDependencyRule($options, $this->analysis);
    }
}
