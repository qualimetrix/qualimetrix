<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Unit\Rules\Architecture;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Collection\Dependency\Cycle;
use Qualimetrix\Analysis\Repository\InMemoryMetricRepository;
use Qualimetrix\Core\Rule\AnalysisContext;
use Qualimetrix\Core\Rule\RuleCategory;
use Qualimetrix\Core\Symbol\SymbolPath;
use Qualimetrix\Core\Violation\Severity;
use Qualimetrix\Rules\Architecture\CircularDependencyOptions;
use Qualimetrix\Rules\Architecture\CircularDependencyRule;

#[CoversClass(CircularDependencyRule::class)]
final class CircularDependencyRuleTest extends TestCase
{
    public function testGetName(): void
    {
        $rule = new CircularDependencyRule(new CircularDependencyOptions());

        $this->assertSame('architecture.circular-dependency', $rule->getName());
    }

    public function testGetDescription(): void
    {
        $rule = new CircularDependencyRule(new CircularDependencyOptions());

        $this->assertStringContainsString('circular', strtolower($rule->getDescription()));
    }

    public function testGetCategory(): void
    {
        $rule = new CircularDependencyRule(new CircularDependencyOptions());

        $this->assertSame(RuleCategory::Architecture, $rule->getCategory());
    }

    public function testGeneratesViolationForCycle(): void
    {
        $cycles = [
            new Cycle($this->paths(['A', 'B']), $this->paths(['A', 'B', 'A'])),
        ];

        $rule = new CircularDependencyRule(
            new CircularDependencyOptions(),
        );

        $context = new AnalysisContext(
            metrics: new InMemoryMetricRepository(),
            cycles: $cycles,
        );

        $violations = $rule->analyze($context);

        $this->assertCount(1, $violations);
        $this->assertSame('architecture.circular-dependency', $violations[0]->ruleName);
        $this->assertStringContainsString('Circular dependency (2 classes)', $violations[0]->message);
    }

    public function testErrorSeverityForDirectCycle(): void
    {
        $cycles = [
            new Cycle($this->paths(['A', 'B']), $this->paths(['A', 'B', 'A'])), // Size 2
        ];

        $rule = new CircularDependencyRule(
            new CircularDependencyOptions(directAsError: true),
        );

        $context = new AnalysisContext(
            metrics: new InMemoryMetricRepository(),
            cycles: $cycles,
        );

        $violations = $rule->analyze($context);

        $this->assertCount(1, $violations);
        $this->assertSame(Severity::Error, $violations[0]->severity);
    }

    public function testWarningSeverityForTransitiveCycle(): void
    {
        $cycles = [
            new Cycle($this->paths(['A', 'B', 'C']), $this->paths(['A', 'B', 'C', 'A'])), // Size 3
        ];

        $rule = new CircularDependencyRule(
            new CircularDependencyOptions(directAsError: true),
        );

        $context = new AnalysisContext(
            metrics: new InMemoryMetricRepository(),
            cycles: $cycles,
        );

        $violations = $rule->analyze($context);

        $this->assertCount(1, $violations);
        $this->assertSame(Severity::Warning, $violations[0]->severity);
    }

    public function testRespectsMaxCycleSize(): void
    {
        $cycles = [
            new Cycle($this->paths(['A', 'B']), $this->paths(['A', 'B', 'A'])), // Size 2
            new Cycle($this->paths(['C', 'D', 'E', 'F', 'G']), $this->paths(['C', 'D', 'E', 'F', 'G', 'C'])), // Size 5
        ];

        $rule = new CircularDependencyRule(
            new CircularDependencyOptions(maxCycleSize: 3),
        );

        $context = new AnalysisContext(
            metrics: new InMemoryMetricRepository(),
            cycles: $cycles,
        );

        $violations = $rule->analyze($context);

        // Only the cycle with size 2 should be reported (size 5 exceeds max)
        $this->assertCount(1, $violations);
    }

    public function testDisabledReturnsEmpty(): void
    {
        $cycles = [
            new Cycle($this->paths(['A', 'B']), $this->paths(['A', 'B', 'A'])),
        ];

        $rule = new CircularDependencyRule(
            new CircularDependencyOptions(enabled: false),
        );

        $context = new AnalysisContext(
            metrics: new InMemoryMetricRepository(),
            cycles: $cycles,
        );

        $violations = $rule->analyze($context);

        $this->assertEmpty($violations);
    }

    public function testReturnsEmptyWhenNoCycles(): void
    {
        $rule = new CircularDependencyRule(new CircularDependencyOptions());

        $context = new AnalysisContext(
            metrics: new InMemoryMetricRepository(),
            cycles: [],
        );

        $violations = $rule->analyze($context);

        $this->assertEmpty($violations);
    }

    public function testMetricValueIsCycleSize(): void
    {
        $cycles = [
            new Cycle($this->paths(['A', 'B', 'C']), $this->paths(['A', 'B', 'C', 'A'])),
        ];

        $rule = new CircularDependencyRule(
            new CircularDependencyOptions(),
        );

        $context = new AnalysisContext(
            metrics: new InMemoryMetricRepository(),
            cycles: $cycles,
        );

        $violations = $rule->analyze($context);

        $this->assertCount(1, $violations);
        $this->assertSame(3, $violations[0]->metricValue);
    }

    public function testOptionsFromArrayWithSnakeCase(): void
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

    public function testOptionsFromArrayWithCamelCase(): void
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

    public function testOptionsFromArraySnakeCaseTakesPrecedence(): void
    {
        $options = CircularDependencyOptions::fromArray([
            'max_cycle_size' => 5,
            'maxCycleSize' => 3,
        ]);

        self::assertSame(5, $options->maxCycleSize);
    }

    public function testSmallCycleRecommendationIncludesInterfaceGuidance(): void
    {
        $cycles = [
            new Cycle($this->paths(['A', 'B']), $this->paths(['A', 'B', 'A'])),
        ];

        $rule = new CircularDependencyRule(new CircularDependencyOptions());

        $context = new AnalysisContext(
            metrics: new InMemoryMetricRepository(),
            cycles: $cycles,
        );

        $violations = $rule->analyze($context);

        self::assertCount(1, $violations);
        self::assertNotNull($violations[0]->recommendation);
        self::assertStringContainsString('Break by introducing an interface', $violations[0]->recommendation);
    }

    public function testMediumCycleRecommendationIncludesAbstractionGuidance(): void
    {
        // 10 classes → medium category (6-20)
        $classNames = array_map(static fn(int $i): string => "Class{$i}", range(1, 10));
        $pathNames = [...$classNames, $classNames[0]];

        $cycles = [
            new Cycle($this->paths($classNames), $this->paths($pathNames)),
        ];

        $rule = new CircularDependencyRule(new CircularDependencyOptions());

        $context = new AnalysisContext(
            metrics: new InMemoryMetricRepository(),
            cycles: $cycles,
        );

        $violations = $rule->analyze($context);

        self::assertCount(1, $violations);
        self::assertNotNull($violations[0]->recommendation);
        self::assertStringContainsString('extracting a shared abstraction layer', $violations[0]->recommendation);
    }

    public function testLargeCycleHasWarningSeverityAndEntryPointGuidance(): void
    {
        // 30 classes → large category (>20)
        $classNames = array_map(static fn(int $i): string => "Class{$i}", range(1, 30));
        $pathNames = [...$classNames, $classNames[0]];

        $cycles = [
            new Cycle($this->paths($classNames), $this->paths($pathNames)),
        ];

        $rule = new CircularDependencyRule(new CircularDependencyOptions());

        $context = new AnalysisContext(
            metrics: new InMemoryMetricRepository(),
            cycles: $cycles,
        );

        $violations = $rule->analyze($context);

        self::assertCount(1, $violations);
        self::assertSame(Severity::Warning, $violations[0]->severity);
        self::assertNotNull($violations[0]->recommendation);
        self::assertStringContainsString('focus on the entry-point classes', $violations[0]->recommendation);
    }

    public function testRecommendationContainsStructuredJsonData(): void
    {
        $cycles = [
            new Cycle($this->paths(['A', 'B', 'C']), $this->paths(['A', 'B', 'C', 'A'])),
        ];

        $rule = new CircularDependencyRule(new CircularDependencyOptions());

        $context = new AnalysisContext(
            metrics: new InMemoryMetricRepository(),
            cycles: $cycles,
        );

        $violations = $rule->analyze($context);

        self::assertCount(1, $violations);
        self::assertNotNull($violations[0]->recommendation);

        $recommendation = $violations[0]->recommendation;
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

    public function testLargeCycleStructuredDataHasLargeCategory(): void
    {
        // 30 classes → large category (>20)
        $classNames = array_map(static fn(int $i): string => "Class{$i}", range(1, 30));
        $pathNames = [...$classNames, $classNames[0]];

        $cycles = [
            new Cycle($this->paths($classNames), $this->paths($pathNames)),
        ];

        $rule = new CircularDependencyRule(new CircularDependencyOptions());

        $context = new AnalysisContext(
            metrics: new InMemoryMetricRepository(),
            cycles: $cycles,
        );

        $violations = $rule->analyze($context);

        self::assertCount(1, $violations);
        self::assertNotNull($violations[0]->recommendation);

        $recommendation = $violations[0]->recommendation;
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
}
