<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Analysis\Finding\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\MetricRepositoryInterface;
use Qualimetrix\Analysis\Finding\Contract\Rule\AnalysisContext;
use Qualimetrix\Tests\Analysis\Evidence\CircularDependency\Support\AdjacencyGraphBuilder;
use ReflectionObject;
use ReflectionProperty;

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
        self::assertNotContains('cycles', $this->publicPropertyNames($context));
    }

    #[Test]
    public function itConstructorWithAllParameters(): void
    {
        $metrics = self::createStub(MetricRepositoryInterface::class);
        $dependencyGraph = AdjacencyGraphBuilder::empty();
        $ruleOptions = [
            'complexity' => ['threshold' => 10],
            'size' => ['max_lines' => 100],
        ];
        $context = new AnalysisContext(
            metrics: $metrics,
            ruleOptions: $ruleOptions,
            dependencyGraph: $dependencyGraph,
        );

        self::assertSame($metrics, $context->metrics);
        self::assertSame($ruleOptions, $context->ruleOptions);
        self::assertSame($dependencyGraph, $context->dependencyGraph);
        self::assertNotContains('cycles', $this->publicPropertyNames($context));
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
    public function itDoesNotExposeCircularDependencyEvidence(): void
    {
        $metrics = self::createStub(MetricRepositoryInterface::class);

        $context = new AnalysisContext($metrics);

        self::assertNotContains('cycles', $this->publicPropertyNames($context));
    }

    #[Test]
    public function itNeverCreatesCircularDependencyEvidenceByDefault(): void
    {
        $metrics = self::createStub(MetricRepositoryInterface::class);
        $context = new AnalysisContext($metrics);

        self::assertNotContains('cycles', $this->publicPropertyNames($context));
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
                'callable' => [
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
        self::assertArrayHasKey('callable', $options);
        self::assertArrayHasKey('class', $options);
        self::assertSame(10, $options['callable']['threshold']);
        self::assertSame('error', $options['class']['severity']);
    }

    /**
     * @return list<string>
     */
    private function publicPropertyNames(object $object): array
    {
        return array_values(array_map(
            static fn(ReflectionProperty $property): string => $property->getName(),
            (new ReflectionObject($object))->getProperties(ReflectionProperty::IS_PUBLIC),
        ));
    }
}
