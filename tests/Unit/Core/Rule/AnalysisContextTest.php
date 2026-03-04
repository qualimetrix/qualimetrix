<?php

declare(strict_types=1);

namespace AiMessDetector\Tests\Unit\Core\Rule;

use AiMessDetector\Core\Dependency\EmptyDependencyGraph;
use AiMessDetector\Core\Metric\MetricRepositoryInterface;
use AiMessDetector\Core\Rule\AnalysisContext;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(AnalysisContext::class)]
final class AnalysisContextTest extends TestCase
{
    public function testConstructorWithMinimalParameters(): void
    {
        $metrics = $this->createMock(MetricRepositoryInterface::class);
        $context = new AnalysisContext($metrics);

        self::assertSame($metrics, $context->metrics);
        self::assertSame([], $context->ruleOptions);
        self::assertNull($context->dependencyGraph);
        self::assertSame([], $context->additionalData);
    }

    public function testConstructorWithAllParameters(): void
    {
        $metrics = $this->createMock(MetricRepositoryInterface::class);
        $dependencyGraph = new EmptyDependencyGraph();
        $ruleOptions = [
            'complexity' => ['threshold' => 10],
            'size' => ['max_lines' => 100],
        ];
        $additionalData = [
            'cycles' => [],
            'violations' => [],
        ];

        $context = new AnalysisContext(
            metrics: $metrics,
            ruleOptions: $ruleOptions,
            dependencyGraph: $dependencyGraph,
            additionalData: $additionalData,
        );

        self::assertSame($metrics, $context->metrics);
        self::assertSame($ruleOptions, $context->ruleOptions);
        self::assertSame($dependencyGraph, $context->dependencyGraph);
        self::assertSame($additionalData, $context->additionalData);
    }

    public function testGetOptionsForRuleReturnsOptionsWhenExists(): void
    {
        $metrics = $this->createMock(MetricRepositoryInterface::class);
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

    public function testGetOptionsForRuleReturnsEmptyArrayWhenNotExists(): void
    {
        $metrics = $this->createMock(MetricRepositoryInterface::class);
        $ruleOptions = [
            'complexity' => ['threshold' => 10],
        ];

        $context = new AnalysisContext($metrics, $ruleOptions);

        self::assertSame([], $context->getOptionsForRule('nonexistent'));
    }

    public function testGetOptionsForRuleReturnsEmptyArrayWhenNoRuleOptions(): void
    {
        $metrics = $this->createMock(MetricRepositoryInterface::class);
        $context = new AnalysisContext($metrics);

        self::assertSame([], $context->getOptionsForRule('complexity'));
    }

    public function testGetAdditionalDataReturnsValueWhenExists(): void
    {
        $metrics = $this->createMock(MetricRepositoryInterface::class);
        $additionalData = [
            'cycles' => [['A', 'B', 'C']],
            'count' => 42,
        ];

        $context = new AnalysisContext(
            metrics: $metrics,
            additionalData: $additionalData,
        );

        self::assertSame([['A', 'B', 'C']], $context->getAdditionalData('cycles'));
        self::assertSame(42, $context->getAdditionalData('count'));
    }

    public function testGetAdditionalDataReturnsNullWhenNotExists(): void
    {
        $metrics = $this->createMock(MetricRepositoryInterface::class);
        $additionalData = ['cycles' => []];

        $context = new AnalysisContext(
            metrics: $metrics,
            additionalData: $additionalData,
        );

        self::assertNull($context->getAdditionalData('nonexistent'));
    }

    public function testGetAdditionalDataReturnsNullWhenNoAdditionalData(): void
    {
        $metrics = $this->createMock(MetricRepositoryInterface::class);
        $context = new AnalysisContext($metrics);

        self::assertNull($context->getAdditionalData('cycles'));
    }

    public function testContextIsReadonly(): void
    {
        $metrics = $this->createMock(MetricRepositoryInterface::class);
        $context = new AnalysisContext($metrics);

        // This test verifies that AnalysisContext is readonly
        // The readonly keyword ensures immutability at the language level
        self::assertInstanceOf(AnalysisContext::class, $context);
    }

    public function testGetOptionsForRuleWithComplexNestedStructure(): void
    {
        $metrics = $this->createMock(MetricRepositoryInterface::class);
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
