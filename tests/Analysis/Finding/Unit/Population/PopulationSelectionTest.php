<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Analysis\Finding\Unit\Population;

use LogicException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\MetricBag;
use Qualimetrix\Analysis\Finding\Contract\ChannelDeclaration;
use Qualimetrix\Analysis\Finding\Contract\ChannelPublication;
use Qualimetrix\Analysis\Finding\Contract\ChannelSelectionRole;
use Qualimetrix\Analysis\Finding\Contract\EnablementDecision;
use Qualimetrix\Analysis\Finding\Contract\FindingChannel;
use Qualimetrix\Analysis\Finding\Contract\Population\GateInput;
use Qualimetrix\Analysis\Finding\Contract\Population\JudgedPopulation;
use Qualimetrix\Analysis\Finding\Contract\Population\KeyPresent;
use Qualimetrix\Analysis\Finding\Contract\Population\PopulationGate;
use Qualimetrix\Analysis\Finding\Contract\Population\PopulationIdentity;
use Qualimetrix\Analysis\Finding\Contract\RuleEnablement;
use Qualimetrix\Analysis\Finding\Contract\Selection\AuthoredCellDecision;
use Qualimetrix\Analysis\Finding\Contract\Selection\CellAdmission;
use Qualimetrix\Analysis\Finding\Contract\Selection\CellSwitch;
use Qualimetrix\Analysis\Finding\Contract\Selection\SelectionCellAddress;
use Qualimetrix\Core\Symbol\SymbolLevel;

#[CoversClass(JudgedPopulation::class)]
#[CoversClass(\Qualimetrix\Analysis\Finding\Contract\Rule\AnalysisContext::class)]
final class PopulationSelectionTest extends TestCase
{
    #[Test]
    public function itDoesNotAdvanceANotSelectedHostedRoster(): void
    {
        $members = (static function (): iterable {
            yield throw new LogicException('Disabled roster was advanced.');
        })();
        $population = JudgedPopulation::measure($this->publication(false), 'fixture.population', new FindingChannel('fixture.population'), SymbolLevel::Project, $this->declaration(), $members);
        self::assertTrue($population->isEmpty());
    }

    #[Test]
    public function itEvaluatesTheSameEligibilityWithAndWithoutSelectedAccounting(): void
    {
        $context = new \Qualimetrix\Analysis\Finding\Contract\Rule\AnalysisContext(self::createStub(\Qualimetrix\Analysis\Evidence\Measurement\Contract\MetricRepositoryInterface::class));
        foreach ([null, false, true] as $selected) {
            $session = $selected === null ? null : new \Qualimetrix\Analysis\Finding\Population\PopulationSession($this->publication($selected));
            $bound = $session === null ? $context : $context->withPopulationTrace($session);
            foreach ([false, true] as $present) {
                self::assertSame($present, $bound->admit('fixture.population', new FindingChannel('fixture.population'), SymbolLevel::Project, PopulationIdentity::occurrence('member', (int) $present), $this->declaration(), [GateInput::metrics('value', MetricBag::fromArray($present ? ['value' => 0] : []))]));
            }
            if ($session !== null) {
                self::assertSame($selected ? 1 : 0, $session->freeze()->judgedCount());
                self::assertSame($selected ? 1 : 0, $session->freeze()->unjudgedCount());
                self::assertSame($session->freeze(), $session->freeze());
            }
        }
    }
    private function publication(bool $selected = true): ChannelPublication
    {
        $channel = new FindingChannel('fixture.population');
        return new ChannelPublication(new RuleEnablement([new EnablementDecision(
            new SelectionCellAddress('fixture.population', $channel, SymbolLevel::Project, ChannelSelectionRole::Selectable),
            new AuthoredCellDecision($selected ? CellSwitch::On : CellSwitch::Off, CellAdmission::Direct),
        )], null));
    }

    private function declaration(): ChannelDeclaration
    {
        return ChannelDeclaration::occurrence(SymbolLevel::Project)->withGates(new PopulationGate(
            'value',
            new FindingChannel('fixture.population'),
            SymbolLevel::Project,
            'occurrence',
            new KeyPresent('value', ['value']),
            'The selected member requires value.',
        ));
    }

}
