<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Unit\Infrastructure\Rule;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Core\ComputedMetric\ComputedMetricDefinition;
use Qualimetrix\Core\ComputedMetric\ComputedMetricDefinitionHolder;
use Qualimetrix\Core\Symbol\SymbolType;
use Qualimetrix\Infrastructure\Rule\RuleChannelRegistry;
use Qualimetrix\Rules\ComputedMetric\ComputedMetricRule;

#[CoversClass(RuleChannelRegistry::class)]
final class RuleChannelRegistryTest extends TestCase
{
    protected function tearDown(): void
    {
        ComputedMetricDefinitionHolder::reset();
    }

    #[Test]
    public function itPreservesTheProducerOfStaticChannelsWithDifferentRuleNames(): void
    {
        $registry = new RuleChannelRegistry([
            'architecture.layer-violation' => [
                'architecture.layer-violation#architecture.layer-violation',
                'architecture.coverage#architecture.coverage',
            ],
        ], ComputedMetricRule::NAME);

        $channels = $registry->channelsProducedBy('architecture.layer-violation');

        self::assertSame(
            ['architecture.layer-violation#architecture.layer-violation', 'architecture.coverage#architecture.coverage'],
            array_map(static fn($channel): string => $channel->toKey(), $channels),
        );
    }

    #[Test]
    public function itAddsRuntimeComputedMetricChannelsToTheirProducer(): void
    {
        ComputedMetricDefinitionHolder::setDefinitions([
            new ComputedMetricDefinition(
                name: 'health.complexity',
                formulas: ['class' => 'ccn__avg'],
                description: 'Complexity health',
                levels: [SymbolType::Class_],
                inverted: true,
            ),
        ]);
        $registry = new RuleChannelRegistry([], ComputedMetricRule::NAME);

        $channels = $registry->channelsProducedBy(ComputedMetricRule::NAME);

        self::assertCount(1, $channels);
        self::assertSame('computed.health#health.complexity', $channels[0]->toKey());
    }
}
