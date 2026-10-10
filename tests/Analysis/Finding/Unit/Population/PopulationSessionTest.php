<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Analysis\Finding\Unit\Population;

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
use WeakReference;

#[CoversClass(JudgedPopulation::class)]
#[CoversClass(\Qualimetrix\Analysis\Finding\Population\PopulationSession::class)]
#[CoversClass(\Qualimetrix\Analysis\Finding\Population\PopulationTrace::class)]
final class PopulationSessionTest extends TestCase
{
    #[Test]
    public function itCountsHealthyMembersWithoutRetainingTheirIdentities(): void
    {
        $population = $this->measured(20, true);
        self::assertSame(20, $population->judgedCount());
        self::assertSame([], $population->abstentions());
        $session = new \Qualimetrix\Analysis\Finding\Population\PopulationSession(($this->publication())->publishes(...));
        $context = (new \Qualimetrix\Analysis\Finding\Contract\Rule\AnalysisContext(new \Qualimetrix\Analysis\Evidence\Measurement\Repository\InMemoryMetricRepository()))->withPopulationTrace($session);
        $weakContext = WeakReference::create($context);
        $weakInputs = [];
        for ($ordinal = 0; $ordinal < 20; ++$ordinal) {
            $bag = MetricBag::fromArray(['value' => 0]);
            $identity = PopulationIdentity::occurrence('healthy', $ordinal);
            $weakInputs[] = [WeakReference::create($bag), WeakReference::create($identity)];
            self::assertTrue($context->admit('fixture.population', new FindingChannel('fixture.population'), SymbolLevel::Project, $identity, $this->declaration(), [GateInput::metrics('value', $bag)]));
            unset($bag, $identity);
        }
        unset($context);
        gc_collect_cycles();
        self::assertNull($weakContext->get());
        foreach ($weakInputs as [$weakBag, $weakIdentity]) {
            self::assertNull($weakBag->get());
            self::assertNull($weakIdentity->get());
        }
        self::assertSame(20, $session->freeze()->judgedCount());
    }

    #[Test]
    public function itBoundsDistinctCanonicalExamplesAndKeepsOccurrenceCounts(): void
    {
        $population = $this->measured(20, false);
        self::assertSame(20, $population->unjudgedCount());
        self::assertCount(5, $population->abstentions()[0]->examples);
        self::assertSame(0, $population->judgedCount());
    }

    #[Test]
    public function itAdoptsTheSamePartitionOnceAndSumsIndependentCalls(): void
    {
        $first = $this->measured(2, false);
        self::assertSame(2, $first->merge($first)->unjudgedCount());
        self::assertSame(4, $first->merge($this->measured(2, false))->unjudgedCount());
    }

    #[Test]
    public function itReleasesTransientMetricInputsAfterFreezing(): void
    {
        $bag = MetricBag::fromArray(['value' => 0]);
        $weak = WeakReference::create($bag);
        $members = [['identity' => PopulationIdentity::occurrence('healthy', 0), 'inputs' => [GateInput::metrics('value', $bag)]]];
        $population = ($this->publication())->measure('fixture.population', new FindingChannel('fixture.population'), SymbolLevel::Project, $this->declaration(), $members);
        unset($bag, $members);
        gc_collect_cycles();
        self::assertNull($weak->get());
        self::assertSame(1, $population->judgedCount());
        $members = (static function (): iterable {
            $weakInputs = [];
            for ($ordinal = 0; $ordinal < 20; ++$ordinal) {
                if ($ordinal >= 2) {
                    self::assertNull($weakInputs[$ordinal - 2][0]->get());
                    self::assertNull($weakInputs[$ordinal - 2][1]->get());
                }
                $bag = MetricBag::fromArray(['value' => 0]);
                $identity = PopulationIdentity::occurrence('streaming', $ordinal);
                $weakInputs[] = [WeakReference::create($bag), WeakReference::create($identity)];
                yield ['identity' => $identity, 'inputs' => [GateInput::metrics('value', $bag)]];
                unset($bag, $identity);
            }
        })();
        $streamed = ($this->publication())->measure('fixture.population', new FindingChannel('fixture.population'), SymbolLevel::Project, $this->declaration(), $members);
        self::assertSame(20, $streamed->judgedCount());
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

    private function measured(int $count, bool $present): JudgedPopulation
    {
        $members = (static function () use ($count, $present): iterable {
            for ($ordinal = 0; $ordinal < $count; ++$ordinal) {
                yield ['identity' => PopulationIdentity::occurrence('member', $ordinal), 'inputs' => [GateInput::metrics('value', MetricBag::fromArray($present ? ['value' => 0] : []))]];
            }
        })();
        return ($this->publication())->measure('fixture.population', new FindingChannel('fixture.population'), SymbolLevel::Project, $this->declaration(), $members);
    }
}
