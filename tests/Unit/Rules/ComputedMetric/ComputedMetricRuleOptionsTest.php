<?php

declare(strict_types=1);

namespace AiMessDetector\Tests\Unit\Rules\ComputedMetric;

use AiMessDetector\Core\ComputedMetric\ComputedMetricDefinition;
use AiMessDetector\Core\ComputedMetric\ComputedMetricDefinitionHolder;
use AiMessDetector\Core\Symbol\SymbolType;
use AiMessDetector\Rules\ComputedMetric\ComputedMetricRuleOptions;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ComputedMetricRuleOptions::class)]
final class ComputedMetricRuleOptionsTest extends TestCase
{
    protected function tearDown(): void
    {
        ComputedMetricDefinitionHolder::reset();
    }

    public function testFromArrayWithHolderPopulated(): void
    {
        $definition = new ComputedMetricDefinition(
            name: 'health.score',
            formulas: ['class' => 'mi * 0.5'],
            description: 'Test metric',
            levels: [SymbolType::Class_],
            warningThreshold: 50.0,
            errorThreshold: 30.0,
        );
        ComputedMetricDefinitionHolder::setDefinitions([$definition]);

        $options = ComputedMetricRuleOptions::fromArray([]);

        self::assertTrue($options->isEnabled());
        self::assertCount(1, $options->getDefinitions());
        self::assertSame('health.score', $options->getDefinitions()[0]->name);
    }

    public function testFromArrayWithEnabledFalse(): void
    {
        $options = ComputedMetricRuleOptions::fromArray(['enabled' => false]);

        self::assertFalse($options->isEnabled());
    }

    public function testFromArrayDefaultEnabled(): void
    {
        $options = ComputedMetricRuleOptions::fromArray([]);

        self::assertTrue($options->isEnabled());
    }

    public function testGetSeverityAlwaysReturnsNull(): void
    {
        $options = new ComputedMetricRuleOptions();

        self::assertNull($options->getSeverity(0));
        self::assertNull($options->getSeverity(100));
        self::assertNull($options->getSeverity(-50.5));
    }

    public function testGetDefinitionsReturnsHolderDefinitions(): void
    {
        $def1 = new ComputedMetricDefinition(
            name: 'health.alpha',
            formulas: ['class' => 'ccn'],
            description: 'Alpha',
            levels: [SymbolType::Class_],
        );
        $def2 = new ComputedMetricDefinition(
            name: 'health.beta',
            formulas: ['namespace' => 'loc'],
            description: 'Beta',
            levels: [SymbolType::Namespace_],
        );
        ComputedMetricDefinitionHolder::setDefinitions([$def1, $def2]);

        $options = ComputedMetricRuleOptions::fromArray([]);

        self::assertCount(2, $options->getDefinitions());
        self::assertSame('health.alpha', $options->getDefinitions()[0]->name);
        self::assertSame('health.beta', $options->getDefinitions()[1]->name);
    }

    public function testConstructorDefaults(): void
    {
        $options = new ComputedMetricRuleOptions();

        self::assertTrue($options->isEnabled());
        self::assertSame([], $options->getDefinitions());
    }
}
