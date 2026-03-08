<?php

declare(strict_types=1);

namespace AiMessDetector\Tests\Unit\Rules\Architecture;

use AiMessDetector\Analysis\Collection\Dependency\Cycle;
use AiMessDetector\Analysis\Repository\InMemoryMetricRepository;
use AiMessDetector\Core\Rule\AnalysisContext;
use AiMessDetector\Core\Rule\RuleCategory;
use AiMessDetector\Core\Violation\Severity;
use AiMessDetector\Core\Violation\SymbolPath;
use AiMessDetector\Rules\Architecture\CircularDependencyOptions;
use AiMessDetector\Rules\Architecture\CircularDependencyRule;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

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
            additionalData: ['cycles' => $cycles],
        );

        $violations = $rule->analyze($context);

        $this->assertCount(1, $violations);
        $this->assertSame('architecture.circular-dependency', $violations[0]->ruleName);
        $this->assertStringContainsString('Circular dependency (2 classes)', $violations[0]->message);
        $this->assertStringContainsString('Break the cycle by introducing interfaces or restructuring', $violations[0]->message);
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
            additionalData: ['cycles' => $cycles],
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
            additionalData: ['cycles' => $cycles],
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
            additionalData: ['cycles' => $cycles],
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
            additionalData: ['cycles' => $cycles],
        );

        $violations = $rule->analyze($context);

        $this->assertEmpty($violations);
    }

    public function testReturnsEmptyWhenNoCycles(): void
    {
        $rule = new CircularDependencyRule(new CircularDependencyOptions());

        $context = new AnalysisContext(
            metrics: new InMemoryMetricRepository(),
            additionalData: [],
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
            additionalData: ['cycles' => $cycles],
        );

        $violations = $rule->analyze($context);

        $this->assertCount(1, $violations);
        $this->assertSame(3, $violations[0]->metricValue);
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
