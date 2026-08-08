<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Unit\Core\Rule;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Core\Rule\RuleChannelRegistryInterface;
use Qualimetrix\Core\Rule\RuleSelector;
use Qualimetrix\Core\Violation\ViolationChannel;

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
                        new ViolationChannel('computed.health', 'health.complexity'),
                        new ViolationChannel('computed.health', 'health.cohesion'),
                    ],
                    'architecture.layer-violation' => [
                        new ViolationChannel('architecture.layer-violation', 'architecture.layer-violation'),
                        new ViolationChannel('architecture.coverage', 'architecture.coverage'),
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
            new ViolationChannel('computed.health', 'health.complexity'),
            ['computed.health'],
            [],
        ));
    }

    #[Test]
    public function itSelectsTheProducerAndOnlyTheAddressedViolationCode(): void
    {
        self::assertTrue($this->selector->isProducerEnabled('computed.health', ['health.complexity'], []));
        self::assertTrue($this->selector->isChannelEnabled(
            'computed.health',
            new ViolationChannel('computed.health', 'health.complexity'),
            ['health.complexity'],
            [],
        ));
        self::assertFalse($this->selector->isChannelEnabled(
            'computed.health',
            new ViolationChannel('computed.health', 'health.cohesion'),
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
            new ViolationChannel('architecture.coverage', 'architecture.coverage'),
            ['architecture.coverage'],
            [],
        ));
    }

    #[Test]
    public function itSupportsAnExplicitFullChannelSelector(): void
    {
        $fullSelector = 'computed.health#health.complexity';

        self::assertTrue($this->selector->isProducerEnabled('computed.health', [$fullSelector], []));
        self::assertTrue($this->selector->isChannelEnabled(
            'computed.health',
            new ViolationChannel('computed.health', 'health.complexity'),
            [$fullSelector],
            [],
        ));
        self::assertFalse($this->selector->isChannelEnabled(
            'computed.health',
            new ViolationChannel('computed.health', 'health.cohesion'),
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
            new ViolationChannel('computed.health', 'health.complexity'),
            [],
            ['health.complexity'],
        ));
        self::assertTrue($this->selector->isChannelEnabled(
            'computed.health',
            new ViolationChannel('computed.health', 'health.cohesion'),
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
        self::assertTrue($this->selector->matchesKnown('computed.health#health.complexity', $producers));
        self::assertFalse($this->selector->matchesKnownProducer('health.complexity', $producers));
    }
}
