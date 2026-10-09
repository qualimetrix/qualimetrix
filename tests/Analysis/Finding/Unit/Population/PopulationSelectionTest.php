<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Analysis\Finding\Unit\Population;

use LogicException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\MetricBag;
use Qualimetrix\Analysis\Finding\Contract\ChannelDeclaration;
use Qualimetrix\Analysis\Finding\Contract\ChannelPublication;
use Qualimetrix\Analysis\Finding\Contract\ChannelSelectionRole;
use Qualimetrix\Analysis\Finding\Contract\EnablementDecision;
use Qualimetrix\Analysis\Finding\Contract\FindingChannel;
use Qualimetrix\Analysis\Finding\Contract\Population\ContextGuard;
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
use ReflectionMethod;
use stdClass;

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
    #[Test]
    public function itStopsBeforeAdvancingInputsPastTheFirstFailureInEveryAccountingMode(): void
    {
        foreach ([null, false, true] as $selected) {
            $context = new \Qualimetrix\Analysis\Finding\Contract\Rule\AnalysisContext(self::createStub(\Qualimetrix\Analysis\Evidence\Measurement\Contract\MetricRepositoryInterface::class));
            $session = $selected === null ? null : new \Qualimetrix\Analysis\Finding\Population\PopulationSession($this->publication($selected));
            $bound = $session === null ? $context : $context->withPopulationTrace($session);
            $inputs = (static function (): iterable {
                yield GateInput::metrics('value', new MetricBag());
                yield throw new LogicException('Inputs after the first failure were advanced.');
            })();
            self::assertFalse($bound->admit('fixture.population', new FindingChannel('fixture.population'), SymbolLevel::Project, PopulationIdentity::occurrence('missing', 0), $this->declaration(), $inputs));
            if ($session !== null) {
                self::assertSame($selected ? 1 : 0, $session->freeze()->unjudgedCount());
                self::assertSame(0, $session->freeze()->judgedCount());
            }
        }
    }

    #[Test]
    #[DataProvider('reachedInputFailures')]
    public function itRefusesReachedIncompleteOrExtraInputs(string $case): void
    {
        $inputs = match ($case) {
            'short' => [],
            'extra' => [GateInput::metrics('value', MetricBag::fromArray(['value' => 0])), GateInput::metrics('value', new MetricBag())],
            'source' => [GateInput::metrics('other', new MetricBag())],
            'variant' => [GateInput::context('graphAvailable', false)],
            default => throw new LogicException('Unknown input fixture.'),
        };
        $this->expectException(LogicException::class);
        $this->declaration()->populationFailure(new FindingChannel('fixture.population'), SymbolLevel::Project, PopulationIdentity::occurrence('member', 0), $inputs);
    }

    /** @return iterable<string, array{string}> */
    public static function reachedInputFailures(): iterable
    {
        foreach (['short', 'extra', 'source', 'variant'] as $case) {
            yield $case => [$case];
        }
    }

    #[Test]
    public function itRejectsAHealthyInvocationInEveryAccountingMode(): void
    {
        foreach ([null, false, true] as $selected) {
            $context = new \Qualimetrix\Analysis\Finding\Contract\Rule\AnalysisContext(self::createStub(\Qualimetrix\Analysis\Evidence\Measurement\Contract\MetricRepositoryInterface::class));
            if ($selected !== null) {
                $context = $context->withPopulationTrace(new \Qualimetrix\Analysis\Finding\Population\PopulationSession($this->publication($selected)));
            }
            try {
                $context->admit('fixture.population', new FindingChannel('fixture.population'), SymbolLevel::Project, PopulationIdentity::invocation('fixture.population'), $this->declaration(), [GateInput::metrics('value', MetricBag::fromArray(['value' => 0]))]);
                self::fail('Invocation must not be a healthy occurrence.');
            } catch (LogicException $failure) {
                self::assertStringContainsString('Healthy population identity', $failure->getMessage());
            }
        }
    }

    #[Test]
    public function itRejectsAnOrdinaryFailureWithAnInvocationIdentityInEveryAccountingMode(): void
    {
        foreach ([null, false, true] as $selected) {
            $context = new \Qualimetrix\Analysis\Finding\Contract\Rule\AnalysisContext(self::createStub(\Qualimetrix\Analysis\Evidence\Measurement\Contract\MetricRepositoryInterface::class));
            if ($selected !== null) {
                $context = $context->withPopulationTrace(new \Qualimetrix\Analysis\Finding\Population\PopulationSession($this->publication($selected)));
            }
            try {
                $context->admit('fixture.population', new FindingChannel('fixture.population'), SymbolLevel::Project, PopulationIdentity::invocation('fixture.population'), $this->declaration(), [GateInput::metrics('value', new MetricBag())]);
                self::fail('An ordinary absence must keep its declared occurrence unit.');
            } catch (LogicException $failure) {
                self::assertStringContainsString('failure identity', $failure->getMessage());
            }
        }
    }

    #[Test]
    public function itRefusesAReachedRuntimeValueOutsideTheGateInputContract(): void
    {
        $inputs = (static function (): iterable {
            yield new stdClass();
        })();
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('must be a GateInput');
        (new ReflectionMethod(ChannelDeclaration::class, 'populationFailure'))->invoke($this->declaration(), new FindingChannel('fixture.population'), SymbolLevel::Project, PopulationIdentity::occurrence('entry', 0), $inputs);
    }

    #[Test]
    public function itUsesTheFailedGateUnitAfterAPassingOrdinaryPrefix(): void
    {
        $channel = new FindingChannel('fixture.population');
        $declaration = ChannelDeclaration::occurrence(SymbolLevel::Project)->withGates(
            new PopulationGate('value', $channel, SymbolLevel::Project, 'occurrence', new KeyPresent('value', ['value']), 'Value missing.'),
            new PopulationGate('prepared', $channel, SymbolLevel::Project, 'occurrence', new ContextGuard('preparedEvidenceAvailable'), 'Prepared evidence unavailable.', 'invocation'),
        );
        $failure = $declaration->populationFailure($channel, SymbolLevel::Project, PopulationIdentity::invocation('fixture.population'), (static function (): iterable {
            yield GateInput::metrics('value', MetricBag::fromArray(['value' => 0]));
            yield GateInput::context('preparedEvidenceAvailable', false);
            yield throw new LogicException('A failed prepared input advanced.');
        })());
        self::assertNotNull($failure);
        self::assertSame('prepared', $failure['gate']);
        $population = JudgedPopulation::measure($this->publication(), 'fixture.population', $channel, SymbolLevel::Project, $declaration, [['identity' => PopulationIdentity::invocation('fixture.population'), 'inputs' => [GateInput::metrics('value', MetricBag::fromArray(['value' => 0])), GateInput::context('preparedEvidenceAvailable', false)]]]);
        self::assertSame('invocation', $population->abstentions()[0]->unit);
        self::assertSame(1, $population->unjudgedCount());
    }

    #[Test]
    public function itKeepsUngatedEmptyInputsWithTheirNativeClosedIdentity(): void
    {
        $declaration = ChannelDeclaration::occurrence(SymbolLevel::Project);
        self::assertNull($declaration->populationFailure(new FindingChannel('fixture.population'), SymbolLevel::Project, PopulationIdentity::occurrence('entry', 0), []));
        $this->expectException(LogicException::class);
        $declaration->populationFailure(new FindingChannel('fixture.population'), SymbolLevel::Project, PopulationIdentity::occurrence('entry', 0), [GateInput::metrics('value', new MetricBag())]);
    }

    #[Test]
    public function itRefusesMixedOrdinaryUnitsAndUnrelatedInvocationOverrides(): void
    {
        $channel = new FindingChannel('fixture.population');
        try {
            ChannelDeclaration::occurrence(SymbolLevel::Project)->withGates(
                new PopulationGate('first', $channel, SymbolLevel::Project, 'occurrence', new KeyPresent('first', ['value']), 'Missing.'),
                new PopulationGate('second', $channel, SymbolLevel::Project, 'declaration', new KeyPresent('second', ['value']), 'Missing.'),
            );
            self::fail('One coordinate cannot mix ordinary member units.');
        } catch (LogicException $failure) {
            self::assertStringContainsString('ordinary member unit', $failure->getMessage());
        }
        foreach ([
            ['occurrence', new KeyPresent('graphAvailable', ['value']), 'invocation'],
            ['occurrence', new ContextGuard('graphAvailable'), 'declaration'],
            ['invocation', new ContextGuard('graphAvailable'), 'invocation'],
            ['occurrence', new ContextGuard('namespaceClaimsJudged'), 'invocation'],
        ] as [$unit, $predicate, $failureUnit]) {
            try {
                new PopulationGate('value', $channel, SymbolLevel::Project, $unit, $predicate, 'Missing.', $failureUnit);
                self::fail('Only unavailable graph or prepared evidence can change a native member to invocation.');
            } catch (LogicException $failure) {
                self::assertStringContainsString('Only graph or prepared-evidence absence', $failure->getMessage());
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
