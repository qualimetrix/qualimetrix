<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Analysis\Run\Unit\FileSetInspection;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Finding\Contract\FindingChannel;
use Qualimetrix\Analysis\Finding\Contract\Rule\RuleSelector;
use Qualimetrix\Analysis\Finding\Rule\InMemoryRuleChannelRegistry;
use Qualimetrix\Analysis\Run\FileSetInspection\RuleSelectorProducerGate;

/**
 * Two consumers ask this gate the same question and must get the same answer.
 * What makes that hold is one {@see RuleSelector} instance behind both, not
 * the gate carrying no fields of its own: the selector is mutable, and the
 * gate's whole answer is the selector's.
 *
 * Production wires one gate service to both consumers, so the condition holds
 * there. A test support that builds a second gate of its own has to hold it
 * deliberately — which is what this states, so the justification is not
 * resting on a property that would not carry it.
 */
#[CoversClass(RuleSelectorProducerGate::class)]
final class RuleSelectorProducerGateStateTest extends TestCase
{
    private const string PRODUCER = 'evidence.example';

    private const string CHANNEL = 'evidence.example-channel';

    #[Test]
    public function itAnswersDifferentlyForTwoSelectorsCarryingDifferentChannels(): void
    {
        $unaware = new RuleSelectorProducerGate(new RuleSelector(new InMemoryRuleChannelRegistry()));
        $aware = new RuleSelectorProducerGate(new RuleSelector($this->registryKnowingTheChannel()));

        self::assertFalse($this->isEnabled($unaware));
        self::assertTrue($this->isEnabled($aware));
    }

    /** The same gate, before and after the selector behind it was mutated. */
    #[Test]
    public function itAnswersDifferentlyAfterTheSelectorBehindItIsMutated(): void
    {
        $selector = new RuleSelector(new InMemoryRuleChannelRegistry());
        $gate = new RuleSelectorProducerGate($selector);

        self::assertFalse($this->isEnabled($gate));

        $selector->replaceChannels($this->registryKnowingTheChannel());
        self::assertTrue($this->isEnabled($gate));

        $selector->resetChannels();
        self::assertFalse($this->isEnabled($gate));
    }

    private function isEnabled(RuleSelectorProducerGate $gate): bool
    {
        return $gate->isEnabled(self::PRODUCER, [self::CHANNEL], [], []);
    }

    private function registryKnowingTheChannel(): InMemoryRuleChannelRegistry
    {
        return new InMemoryRuleChannelRegistry([
            self::PRODUCER => [new FindingChannel(self::CHANNEL)],
        ]);
    }
}
