<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Analysis\Finding\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Finding\Contract\FindingChannel;
use Qualimetrix\Analysis\Finding\Contract\Rule\RuleChannelRegistryInterface;
use Qualimetrix\Analysis\Finding\Contract\Rule\RuleSelector;

#[CoversClass(RuleSelector::class)]
final class RuleSelectorTest extends TestCase
{
    private RuleSelector $selector;

    protected function setUp(): void
    {
        $registry = new class implements RuleChannelRegistryInterface {
            public function channelsProducedBy(string $producerRuleName): array
            {
                return match ($producerRuleName) {
                    'computed.health' => [
                        new FindingChannel('health.complexity'),
                        new FindingChannel('health.cohesion'),
                    ],
                    'architecture.layer-violation' => [
                        new FindingChannel('architecture.layer-violation'),
                        new FindingChannel('architecture.coverage'),
                    ],
                    default => [],
                };
            }
        };

        $this->selector = new RuleSelector($registry);
    }

    #[Test]
    public function itSelectsEveryChannelThroughTheProducerName(): void
    {
        self::assertTrue($this->selector->isProducerEnabled('computed.health', ['computed.health'], []));
        self::assertTrue($this->selector->isChannelEnabled(
            'computed.health',
            new FindingChannel('health.complexity'),
            ['computed.health'],
            [],
        ));
    }

    #[Test]
    public function itSelectsTheProducerAndOnlyTheAddressedCode(): void
    {
        self::assertTrue($this->selector->isProducerEnabled('computed.health', ['health.complexity'], []));
        self::assertTrue($this->selector->isChannelEnabled(
            'computed.health',
            new FindingChannel('health.complexity'),
            ['health.complexity'],
            [],
        ));
        self::assertFalse($this->selector->isChannelEnabled(
            'computed.health',
            new FindingChannel('health.cohesion'),
            ['health.complexity'],
            [],
        ));
    }

    #[Test]
    public function itSelectsAChannelWhoseRuleNameDiffersFromItsProducer(): void
    {
        self::assertTrue($this->selector->isProducerEnabled(
            'architecture.layer-violation',
            ['architecture.coverage'],
            [],
        ));
        self::assertTrue($this->selector->isChannelEnabled(
            'architecture.layer-violation',
            new FindingChannel('architecture.coverage'),
            ['architecture.coverage'],
            [],
        ));
    }

    #[Test]
    public function itSupportsAnExplicitFullChannelSelector(): void
    {
        $fullSelector = 'health.complexity';

        self::assertTrue($this->selector->isProducerEnabled('computed.health', [$fullSelector], []));
        self::assertTrue($this->selector->isChannelEnabled(
            'computed.health',
            new FindingChannel('health.complexity'),
            [$fullSelector],
            [],
        ));
        self::assertFalse($this->selector->isChannelEnabled(
            'computed.health',
            new FindingChannel('health.cohesion'),
            [$fullSelector],
            [],
        ));
    }

    #[Test]
    public function itLetsDisabledSelectorsOverrideOnlySelectors(): void
    {
        self::assertFalse($this->selector->isProducerEnabled(
            'computed.health',
            ['computed.health'],
            ['computed.health'],
        ));
    }

    #[Test]
    public function itKeepsAProducerActiveWhenOnlyOneOfItsChannelsIsDisabled(): void
    {
        self::assertTrue($this->selector->isProducerEnabled(
            'computed.health',
            [],
            ['health.complexity'],
        ));
        self::assertFalse($this->selector->isChannelEnabled(
            'computed.health',
            new FindingChannel('health.complexity'),
            [],
            ['health.complexity'],
        ));
        self::assertTrue($this->selector->isChannelEnabled(
            'computed.health',
            new FindingChannel('health.cohesion'),
            [],
            ['health.complexity'],
        ));
    }

    #[Test]
    public function itRecognizesRegisteredChannelSelectorsWithoutTreatingThemAsRuleOptionNames(): void
    {
        $producers = ['computed.health', 'architecture.layer-violation'];

        self::assertTrue($this->selector->matchesKnown('health.complexity', $producers));
        self::assertTrue($this->selector->matchesKnown('architecture.coverage', $producers));
        self::assertTrue($this->selector->matchesKnown('health.complexity', $producers));
        self::assertFalse($this->selector->matchesKnownProducer('health.complexity', $producers));
    }

    #[Test]
    public function itValidatesAgainstAnExplicitSnapshotAndResetsRunChannels(): void
    {
        $static = self::registry([]);
        $snapshotA = self::registry(['health.complexity']);
        $snapshotB = self::registry(['health.cohesion']);
        $selector = new RuleSelector($static);
        $producers = ['computed.health'];

        self::assertTrue($selector->matchesKnownIn('computed.health', $producers, $snapshotA));
        self::assertTrue($selector->matchesKnownIn('health.complexity', $producers, $snapshotA));
        self::assertTrue($selector->matchesKnownIn('health.complexity', $producers, $snapshotA));
        self::assertFalse($selector->matchesKnownIn('health.unknown', $producers, $snapshotA));

        $selector->replaceChannels($snapshotA);
        self::assertTrue($selector->matchesKnown('health.complexity', $producers));
        $selector->replaceChannels($snapshotB);
        self::assertFalse($selector->matchesKnown('health.complexity', $producers));
        self::assertTrue($selector->matchesKnown('health.cohesion', $producers));
        $selector->resetChannels();
        self::assertFalse($selector->matchesKnown('health.cohesion', $producers));
    }

    /** @param list<string> $channelKeys */
    private static function registry(array $channelKeys): RuleChannelRegistryInterface
    {
        return new class ($channelKeys) implements RuleChannelRegistryInterface {
            /** @param list<string> $channelKeys */
            public function __construct(private readonly array $channelKeys) {}

            public function channelsProducedBy(string $producerRuleName): array
            {
                return array_map(static fn(string $code): FindingChannel => new FindingChannel($code), $this->channelKeys);
            }
        };
    }
}
