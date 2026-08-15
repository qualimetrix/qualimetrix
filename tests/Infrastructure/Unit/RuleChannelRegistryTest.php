<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Infrastructure\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Evidence\ComputedMetrics\ComputedMetricRule;
use Qualimetrix\Analysis\Evidence\ComputedMetrics\Contract\Definition\ComputedMetricDefinition;
use Qualimetrix\Analysis\Evidence\ComputedMetrics\Contract\Definition\ResolvedComputedMetricDefinitions;
use Qualimetrix\Core\Symbol\SymbolType;
use Qualimetrix\Infrastructure\Rule\RuleChannelRegistry;
use ReflectionClass;

#[CoversClass(RuleChannelRegistry::class)]
final class RuleChannelRegistryTest extends TestCase
{
    #[Test]
    public function itPreservesTheProducerOfStaticChannelsWithDifferentRuleNames(): void
    {
        $registry = new RuleChannelRegistry([
            'architecture.layer-violation' => [
                'architecture.layer-violation#architecture.layer-violation',
                'architecture.coverage#architecture.coverage',
            ],
        ], ComputedMetricRule::NAME, new ResolvedComputedMetricDefinitions([]));

        $channels = $registry->channelsProducedBy('architecture.layer-violation');

        self::assertSame(
            ['architecture.layer-violation#architecture.layer-violation', 'architecture.coverage#architecture.coverage'],
            array_map(static fn($channel): string => $channel->toKey(), $channels),
        );
    }

    #[Test]
    public function itAddsRuntimeComputedMetricChannelsToTheirProducer(): void
    {
        $definitions = new ResolvedComputedMetricDefinitions([
            new ComputedMetricDefinition(
                name: 'health.complexity',
                formulas: ['class' => 'ccn__avg'],
                description: 'Complexity health',
                levels: [SymbolType::Class_],
                inverted: true,
            ),
        ]);
        $registry = new RuleChannelRegistry([], ComputedMetricRule::NAME, $definitions);

        $channels = $registry->channelsProducedBy(ComputedMetricRule::NAME);

        self::assertCount(1, $channels);
        self::assertSame('computed.health#health.complexity', $channels[0]->toKey());
    }

    #[Test]
    public function itCreatesAnImmutableSnapshotWithoutReadingLaterDefinitions(): void
    {
        $empty = new ResolvedComputedMetricDefinitions([]);
        $definition = new ComputedMetricDefinition(
            name: 'health.complexity',
            formulas: ['class' => 'ccn__avg'],
            description: 'Complexity health',
            levels: [SymbolType::Class_],
            inverted: true,
        );
        $factory = new RuleChannelRegistry([], ComputedMetricRule::NAME, $empty);
        $definitionsA = new ResolvedComputedMetricDefinitions([$definition]);
        $snapshotA = $factory->snapshot($definitionsA);
        $snapshotB = $factory->snapshot($empty);

        self::assertSame(
            ['computed.health#health.complexity'],
            array_map(static fn($channel): string => $channel->toKey(), $snapshotA->channelsProducedBy(ComputedMetricRule::NAME)),
        );
        self::assertSame([], $snapshotB->channelsProducedBy(ComputedMetricRule::NAME));
        self::assertSame(
            $definitionsA,
            (new ReflectionClass($snapshotA))->getProperty('definitions')->getValue($snapshotA),
        );
    }
}
