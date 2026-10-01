<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Analysis\Run\Unit\FileSetInspection;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Finding\Contract\ChannelSelectionRole;
use Qualimetrix\Analysis\Finding\Contract\EnablementDecision;
use Qualimetrix\Analysis\Finding\Contract\FindingChannel;
use Qualimetrix\Analysis\Finding\Contract\RuleConfigurationInterface;
use Qualimetrix\Analysis\Finding\Contract\RuleEnablement;
use Qualimetrix\Analysis\Finding\Contract\Selection\AuthoredCellDecision;
use Qualimetrix\Analysis\Finding\Contract\Selection\CellAdmission;
use Qualimetrix\Analysis\Finding\Contract\Selection\CellSwitch;
use Qualimetrix\Analysis\Finding\Contract\Selection\SelectionCellAddress;
use Qualimetrix\Analysis\Run\FileSetInspection\RuleSelectorProducerGate;

/**
 * Two consumers ask this gate the same question and must get the same answer.
 * What makes that hold is one runtime configuration behind both. Its completed
 * enablement can be replaced between runs, and the gate must read that current
 * answer rather than retain an earlier snapshot.
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
    public function itAnswersDifferentlyForTwoConfigurationsCarryingDifferentChannels(): void
    {
        $unawareConfiguration = self::createStub(RuleConfigurationInterface::class);
        $unawareConfiguration->method('enablement')->willReturn(new RuleEnablement([], null));
        $awareConfiguration = self::createStub(RuleConfigurationInterface::class);
        $awareConfiguration->method('enablement')->willReturn($this->enablementKnowingTheChannel());
        $unaware = new RuleSelectorProducerGate($unawareConfiguration);
        $aware = new RuleSelectorProducerGate($awareConfiguration);

        self::assertFalse($this->isEnabled($unaware));
        self::assertTrue($this->isEnabled($aware));
    }

    /** The same gate, before and after its runtime configuration was replaced. */
    #[Test]
    public function itAnswersDifferentlyAfterTheConfigurationBehindItIsReplaced(): void
    {
        $enablement = new RuleEnablement([], null);
        $configuration = self::createStub(RuleConfigurationInterface::class);
        $configuration->method('enablement')->willReturnCallback(static function () use (&$enablement): RuleEnablement {
            return $enablement;
        });
        $gate = new RuleSelectorProducerGate($configuration);

        self::assertFalse($this->isEnabled($gate));

        $enablement = $this->enablementKnowingTheChannel();
        self::assertTrue($this->isEnabled($gate));

        $enablement = new RuleEnablement([], null);
        self::assertFalse($this->isEnabled($gate));
    }

    private function isEnabled(RuleSelectorProducerGate $gate): bool
    {
        return $gate->isEnabled(self::PRODUCER);
    }

    private function enablementKnowingTheChannel(): RuleEnablement
    {
        return new RuleEnablement([new EnablementDecision(
            new SelectionCellAddress(self::PRODUCER, new FindingChannel(self::CHANNEL), null, ChannelSelectionRole::Selectable),
            new AuthoredCellDecision(CellSwitch::On, CellAdmission::Direct),
        )], null);
    }
}
