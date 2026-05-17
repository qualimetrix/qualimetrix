<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Unit\Rules\ComputedMetric;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Core\ComputedMetric\ComputedMetricDefinition;
use Qualimetrix\Core\ComputedMetric\ComputedMetricDefinitionHolder;
use Qualimetrix\Core\Symbol\SymbolType;
use Qualimetrix\Rules\ComputedMetric\ComputedMetricRuleOptions;

#[CoversClass(ComputedMetricRuleOptions::class)]
final class ComputedMetricRuleOptionsTest extends TestCase
{
    protected function tearDown(): void
    {
        ComputedMetricDefinitionHolder::reset();
    }

    #[Test]
    public function itLoadsDefinitionsFromHolderWhenPopulated(): void
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

    #[Test]
    public function itDisablesWhenEnabledFalse(): void
    {
        $options = ComputedMetricRuleOptions::fromArray(['enabled' => false]);

        self::assertFalse($options->isEnabled());
    }

    #[Test]
    public function itIsEnabledByDefault(): void
    {
        $options = ComputedMetricRuleOptions::fromArray([]);

        self::assertTrue($options->isEnabled());
    }

    #[Test]
    public function itAlwaysReturnsNullSeverity(): void
    {
        $options = new ComputedMetricRuleOptions();

        self::assertNull($options->getSeverity(0));
        self::assertNull($options->getSeverity(100));
        self::assertNull($options->getSeverity(-50.5));
    }

    #[Test]
    public function itReturnsHolderDefinitions(): void
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

    #[Test]
    public function itHasCorrectConstructorDefaults(): void
    {
        $options = new ComputedMetricRuleOptions();

        self::assertTrue($options->isEnabled());
        self::assertSame([], $options->getDefinitions());
    }
}
