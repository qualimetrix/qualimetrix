<?php

declare(strict_types=1);

namespace AiMessDetector\Tests\Unit\Core\ComputedMetric;

use AiMessDetector\Core\ComputedMetric\ComputedMetricDefinition;
use AiMessDetector\Core\ComputedMetric\ComputedMetricDefinitionHolder;
use AiMessDetector\Core\Symbol\SymbolType;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ComputedMetricDefinitionHolder::class)]
final class ComputedMetricDefinitionHolderTest extends TestCase
{
    protected function tearDown(): void
    {
        ComputedMetricDefinitionHolder::reset();
    }

    public function testDefaultReturnsEmptyArray(): void
    {
        ComputedMetricDefinitionHolder::reset();

        self::assertSame([], ComputedMetricDefinitionHolder::getDefinitions());
    }

    public function testSetAndGetDefinitions(): void
    {
        $definition = new ComputedMetricDefinition(
            name: 'health.test',
            formulas: ['class' => 'formula'],
            description: 'Test',
            levels: [SymbolType::Class_],
        );

        ComputedMetricDefinitionHolder::setDefinitions([$definition]);

        $result = ComputedMetricDefinitionHolder::getDefinitions();
        self::assertCount(1, $result);
        self::assertSame($definition, $result[0]);
    }

    public function testReset(): void
    {
        $definition = new ComputedMetricDefinition(
            name: 'health.test',
            formulas: ['class' => 'formula'],
            description: 'Test',
            levels: [SymbolType::Class_],
        );

        ComputedMetricDefinitionHolder::setDefinitions([$definition]);
        self::assertCount(1, ComputedMetricDefinitionHolder::getDefinitions());

        ComputedMetricDefinitionHolder::reset();
        self::assertSame([], ComputedMetricDefinitionHolder::getDefinitions());
    }

    public function testSetDefinitionsReplacePrevious(): void
    {
        $definition1 = new ComputedMetricDefinition(
            name: 'health.first',
            formulas: ['class' => 'formula1'],
            description: 'First',
            levels: [SymbolType::Class_],
        );

        $definition2 = new ComputedMetricDefinition(
            name: 'health.second',
            formulas: ['class' => 'formula2'],
            description: 'Second',
            levels: [SymbolType::Class_],
        );

        ComputedMetricDefinitionHolder::setDefinitions([$definition1]);
        ComputedMetricDefinitionHolder::setDefinitions([$definition2]);

        $result = ComputedMetricDefinitionHolder::getDefinitions();
        self::assertCount(1, $result);
        self::assertSame($definition2, $result[0]);
    }
}
