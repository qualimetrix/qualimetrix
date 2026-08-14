<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Infrastructure\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Evidence\ComputedMetrics\ComputedMetricRule;
use Qualimetrix\Analysis\Evidence\ComputedMetrics\Contract\Definition\ComputedMetricDefinition;
use Qualimetrix\Analysis\Evidence\ComputedMetrics\Contract\Definition\ComputedMetricDefinitionCatalogInterface;
use Qualimetrix\Core\Symbol\SymbolType;
use Qualimetrix\Infrastructure\Rule\RuleChannelRegistry;

#[CoversClass(RuleChannelRegistry::class)]
final class RuleChannelRegistryTest extends TestCase
{
    /** @var list<ComputedMetricDefinition> */
    private array $definitions = [];

    #[Test]
    public function itPreservesTheProducerOfStaticChannelsWithDifferentRuleNames(): void
    {
        $registry = new RuleChannelRegistry([
            'architecture.layer-violation' => [
                'architecture.layer-violation#architecture.layer-violation',
                'architecture.coverage#architecture.coverage',
            ],
        ], ComputedMetricRule::NAME, $this->catalog());

        $channels = $registry->channelsProducedBy('architecture.layer-violation');

        self::assertSame(
            ['architecture.layer-violation#architecture.layer-violation', 'architecture.coverage#architecture.coverage'],
            array_map(static fn($channel): string => $channel->toKey(), $channels),
        );
    }

    #[Test]
    public function itAddsRuntimeComputedMetricChannelsToTheirProducer(): void
    {
        $this->definitions = ([
            new ComputedMetricDefinition(
                name: 'health.complexity',
                formulas: ['class' => 'ccn__avg'],
                description: 'Complexity health',
                levels: [SymbolType::Class_],
                inverted: true,
            ),
        ]);
        $registry = new RuleChannelRegistry([], ComputedMetricRule::NAME, $this->catalog());

        $channels = $registry->channelsProducedBy(ComputedMetricRule::NAME);

        self::assertCount(1, $channels);
        self::assertSame('computed.health#health.complexity', $channels[0]->toKey());
    }

    private function catalog(): ComputedMetricDefinitionCatalogInterface
    {
        $catalog = self::createStub(ComputedMetricDefinitionCatalogInterface::class);
        $catalog->method('all')->willReturnCallback(fn(): array => $this->definitions);

        return $catalog;
    }
}
