<?php

declare(strict_types=1);

namespace AiMessDetector\Tests\Unit\Rules\Architecture;

use AiMessDetector\Analysis\Collection\Dependency\Cycle;
use AiMessDetector\Analysis\Repository\InMemoryMetricRepository;
use AiMessDetector\Core\Rule\AnalysisContext;
use AiMessDetector\Core\Rule\RuleCategory;
use AiMessDetector\Core\Violation\Severity;
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

        $this->assertSame('circular-dependency', $rule->getName());
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
            new Cycle(['A', 'B'], ['A', 'B', 'A']),
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
        $this->assertSame('circular-dependency', $violations[0]->ruleName);
        $this->assertStringContainsString('Circular dependency detected', $violations[0]->message);
    }

    public function testErrorSeverityForDirectCycle(): void
    {
        $cycles = [
            new Cycle(['A', 'B'], ['A', 'B', 'A']), // Size 2
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
            new Cycle(['A', 'B', 'C'], ['A', 'B', 'C', 'A']), // Size 3
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
            new Cycle(['A', 'B'], ['A', 'B', 'A']), // Size 2
            new Cycle(['C', 'D', 'E', 'F', 'G'], ['C', 'D', 'E', 'F', 'G', 'C']), // Size 5
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
            new Cycle(['A', 'B'], ['A', 'B', 'A']),
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
            new Cycle(['A', 'B', 'C'], ['A', 'B', 'C', 'A']),
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
}
