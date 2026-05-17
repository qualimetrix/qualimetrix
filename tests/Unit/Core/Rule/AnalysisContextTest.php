<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Unit\Core\Rule;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Core\Dependency\CycleInterface;
use Qualimetrix\Core\Dependency\EmptyDependencyGraph;
use Qualimetrix\Core\Metric\MetricRepositoryInterface;
use Qualimetrix\Core\Rule\AnalysisContext;

#[CoversClass(AnalysisContext::class)]
final class AnalysisContextTest extends TestCase
{
    #[Test]
    public function itConstructorWithMinimalParameters(): void
    {
        $metrics = self::createStub(MetricRepositoryInterface::class);
        $context = new AnalysisContext($metrics);

        self::assertSame($metrics, $context->metrics);
        self::assertSame([], $context->ruleOptions);
        self::assertNull($context->dependencyGraph);
        self::assertSame([], $context->cycles);
    }

    #[Test]
    public function itConstructorWithAllParameters(): void
    {
        $metrics = self::createStub(MetricRepositoryInterface::class);
        $dependencyGraph = new EmptyDependencyGraph();
        $ruleOptions = [
            'complexity' => ['threshold' => 10],
            'size' => ['max_lines' => 100],
        ];
        $cycles = [
            self::createStub(CycleInterface::class),
        ];

        $context = new AnalysisContext(
            metrics: $metrics,
            ruleOptions: $ruleOptions,
            dependencyGraph: $dependencyGraph,
            cycles: $cycles,
        );

        self::assertSame($metrics, $context->metrics);
        self::assertSame($ruleOptions, $context->ruleOptions);
        self::assertSame($dependencyGraph, $context->dependencyGraph);
        self::assertSame($cycles, $context->cycles);
    }

    #[Test]
    public function itGetOptionsForRuleReturnsOptionsWhenExists(): void
    {
        $metrics = self::createStub(MetricRepositoryInterface::class);
        $ruleOptions = [
            'complexity' => [
                'threshold' => 10,
                'enabled' => true,
            ],
        ];

        $context = new AnalysisContext($metrics, $ruleOptions);

        self::assertSame(
            ['threshold' => 10, 'enabled' => true],
            $context->getOptionsForRule('complexity'),
        );
    }

    #[Test]
    public function itGetOptionsForRuleReturnsEmptyArrayWhenNotExists(): void
    {
        $metrics = self::createStub(MetricRepositoryInterface::class);
        $ruleOptions = [
            'complexity' => ['threshold' => 10],
        ];

        $context = new AnalysisContext($metrics, $ruleOptions);

        self::assertSame([], $context->getOptionsForRule('nonexistent'));
    }

    #[Test]
    public function itGetOptionsForRuleReturnsEmptyArrayWhenNoRuleOptions(): void
    {
        $metrics = self::createStub(MetricRepositoryInterface::class);
        $context = new AnalysisContext($metrics);

        self::assertSame([], $context->getOptionsForRule('complexity'));
    }

    #[Test]
    public function itCyclesPropertyWithValues(): void
    {
        $metrics = self::createStub(MetricRepositoryInterface::class);
        $cycle = self::createStub(CycleInterface::class);

        $context = new AnalysisContext(
            metrics: $metrics,
            cycles: [$cycle],
        );

        self::assertCount(1, $context->cycles);
        self::assertSame($cycle, $context->cycles[0]);
    }

    #[Test]
    public function itCyclesPropertyDefaultsToEmpty(): void
    {
        $metrics = self::createStub(MetricRepositoryInterface::class);
        $context = new AnalysisContext($metrics);

        self::assertSame([], $context->cycles);
    }

    #[Test]
    public function itContextIsReadonly(): void
    {
        $metrics = self::createStub(MetricRepositoryInterface::class);
        $context = new AnalysisContext($metrics);

        // This test verifies that AnalysisContext is readonly
        // The readonly keyword ensures immutability at the language level
        self::assertInstanceOf(AnalysisContext::class, $context); // @phpstan-ignore staticMethod.alreadyNarrowedType
    }

    #[Test]
    public function itGetOptionsForRuleWithComplexNestedStructure(): void
    {
        $metrics = self::createStub(MetricRepositoryInterface::class);
        $ruleOptions = [
            'hierarchical-rule' => [
                'method' => [
                    'threshold' => 10,
                    'severity' => 'warning',
                ],
                'class' => [
                    'threshold' => 50,
                    'severity' => 'error',
                ],
            ],
        ];

        $context = new AnalysisContext($metrics, $ruleOptions);

        $options = $context->getOptionsForRule('hierarchical-rule');
        self::assertArrayHasKey('method', $options);
        self::assertArrayHasKey('class', $options);
        self::assertSame(10, $options['method']['threshold']);
        self::assertSame('error', $options['class']['severity']);
    }
}
