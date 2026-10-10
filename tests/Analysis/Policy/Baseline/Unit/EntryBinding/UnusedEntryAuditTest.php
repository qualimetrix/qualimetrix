<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Analysis\Policy\Baseline\Unit\EntryBinding;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\MetricRepositoryInterface;
use Qualimetrix\Analysis\Finding\Contract\ChannelShape;
use Qualimetrix\Analysis\Finding\Contract\Filter\FindingFilterStage;
use Qualimetrix\Analysis\Finding\Contract\Filter\FindingFilterStageResult;
use Qualimetrix\Analysis\Finding\Contract\FindingChannel;
use Qualimetrix\Analysis\Finding\Contract\OccurrenceKey;
use Qualimetrix\Analysis\Finding\Contract\Rule\AnalysisContext;
use Qualimetrix\Analysis\Finding\Contract\RuleExecutionInterface;
use Qualimetrix\Analysis\Finding\Contract\Severity;
use Qualimetrix\Analysis\Policy\Baseline\BaselineEntry;
use Qualimetrix\Analysis\Policy\Baseline\BaselineIdentity;
use Qualimetrix\Analysis\Policy\Baseline\Contract\BaselineAuditChannels;
use Qualimetrix\Analysis\Policy\Baseline\Contract\CeilingOutcome;
use Qualimetrix\Analysis\Policy\Baseline\EntryBinding\UnusedEntryAudit;
use Qualimetrix\Analysis\Policy\Baseline\EntryBinding\UnusedEntryOptions;
use Qualimetrix\Analysis\Policy\Baseline\EntryBinding\UnusedEntryRule;
use Qualimetrix\Analysis\Policy\Baseline\InertBaselineEntry;
use Qualimetrix\Analysis\Policy\Baseline\InertEntryReason;
use Qualimetrix\Core\Symbol\SymbolLevel;
use Qualimetrix\Tests\Analysis\Finding\Support\ResolvedOptionsFixture;

#[CoversClass(UnusedEntryAudit::class)]
#[CoversClass(UnusedEntryRule::class)]
#[CoversClass(UnusedEntryOptions::class)]
final class UnusedEntryAuditTest extends TestCase
{
    #[Test]
    public function itReportsOneFindingForAllInertContendersOfADuplicateIdentity(): void
    {
        $identity = new BaselineIdentity('file:src/Legacy.php', new FindingChannel('code-smell.goto'));
        $first = InertBaselineEntry::forIdentity($identity, InertEntryReason::DuplicateIdentity, 'first contender', ['count' => 1]);
        $second = InertBaselineEntry::forIdentity($identity, InertEntryReason::DuplicateIdentity, 'second contender', ['count' => 2]);
        $execution = self::createMock(RuleExecutionInterface::class);
        $execution->expects(self::once())->method('publishable')->willReturnCallback(static fn(array $findings): array => $findings);

        $findings = (new UnusedEntryAudit($execution))->auditResult(new CeilingOutcome(
            new FindingFilterStageResult(FindingFilterStage::Baseline, [], []),
            [],
            [$first, $second],
        ), 'baseline.json', self::populationPublication())['findings'];

        self::assertCount(1, $findings);
        self::assertStringContainsString($identity->describe(), $findings[0]->message);
        self::assertStringContainsString('2 contenders', $findings[0]->message);
        self::assertStringContainsString('--remove=' . $first->selector->value, $findings[0]->recommendation ?? '');
    }

    #[Test]
    public function itReportsOnlyStaleAndInertEntriesWithTheirOwnReasons(): void
    {
        $stale = self::entry('file:src/Gone.php');
        $inert = InertBaselineEntry::forRaw('file:tests/Old.php', null, InertEntryReason::Malformed, 'invalid count', ['count' => -1]);
        $unmeasured = self::entry('file:src/Disabled.php');
        $outside = self::entry('file:tests/Outside.php');
        $uncompared = self::entry('project:');
        $execution = self::createMock(RuleExecutionInterface::class);
        $execution->expects(self::once())->method('publishable')->willReturnCallback(static fn(array $findings): array => $findings);
        $findings = (new UnusedEntryAudit($execution))->auditResult(new CeilingOutcome(
            new FindingFilterStageResult(FindingFilterStage::Baseline, [], []),
            [$stale],
            [$inert],
            [$unmeasured],
            [$outside],
            [$uncompared],
        ), "baseline's file.json", self::populationPublication())['findings'];

        self::assertCount(2, $findings);
        foreach ($findings as $finding) {
            self::assertSame(BaselineAuditChannels::UNUSED_ENTRY, $finding->channel()->code);
            self::assertSame(SymbolLevel::Project, $finding->level());
            self::assertSame(Severity::Warning, $finding->severity);
            self::assertNull($finding->acceptedLevel);
            self::assertTrue($finding->location->isNone());
        }
        self::assertStringContainsString($stale->identity->describe(), $findings[0]->message);
        self::assertStringContainsString($stale->selector()->value, $findings[0]->message);
        self::assertStringContainsString('complete comparable measured set', $findings[0]->message);
        self::assertSame(OccurrenceKey::semantic('baseline-unused-entry', ['cause' => 'stale', 'selector' => $stale->selector()->value])->value, $findings[0]->occurrenceKey?->value);
        self::assertStringContainsString($inert->selector->value, $findings[1]->message);
        self::assertStringContainsString('invalid count', $findings[1]->message);
        self::assertStringContainsString($inert->reason->description(), $findings[1]->message);
        self::assertStringContainsString(escapeshellarg("baseline's file.json"), $findings[1]->recommendation ?? '');
        self::assertStringContainsString('--remove=' . $inert->selector->value, $findings[1]->recommendation ?? '');
        self::assertStringNotContainsString('baseline:update', $findings[1]->recommendation ?? '');
    }

    #[Test]
    public function itHonoursTheCommittedAuditSelection(): void
    {
        $execution = self::createMock(RuleExecutionInterface::class);
        $execution->expects(self::once())->method('publishable')->with(self::callback(static fn(array $findings): bool => \count($findings) === 1))->willReturn([]);
        self::assertSame([], (new UnusedEntryAudit($execution))->auditResult(new CeilingOutcome(
            new FindingFilterStageResult(FindingFilterStage::Baseline, [], []),
            [self::entry('file:src/Gone.php')],
            [],
        ), 'baseline.json', self::populationPublication())['findings']);
    }

    #[Test]
    public function itDeclaresAnEnabledWarningProjectRuleThatEmitsNothingDuringMeasurement(): void
    {
        $options = UnusedEntryOptions::fromResolved(ResolvedOptionsFixture::values(UnusedEntryOptions::class, []));
        self::assertTrue($options->isEnabled());
        self::assertFalse(UnusedEntryOptions::fromResolved(ResolvedOptionsFixture::values(UnusedEntryOptions::class, ['enabled' => false]))->isEnabled());
        self::assertSame(Severity::Warning, $options->getSeverity(1));
        self::assertSame(UnusedEntryOptions::class, UnusedEntryRule::getOptionsClass());
        self::assertSame(ChannelShape::Occurrence, UnusedEntryRule::shape());
        self::assertSame([SymbolLevel::Project], UnusedEntryRule::channelDeclarations()[BaselineAuditChannels::UNUSED_ENTRY]->levels);
        $rule = new UnusedEntryRule($options);
        self::assertSame(BaselineAuditChannels::UNUSED_ENTRY, $rule->getName());
        self::assertNotSame('', $rule::getDescription());
        self::assertSame([], $rule->analyze(new AnalysisContext(self::createStub(MetricRepositoryInterface::class))));
    }

    private static function entry(string $subject): BaselineEntry
    {
        return new BaselineEntry(new BaselineIdentity($subject, new FindingChannel('code-smell.goto')), null, 1);
    }

    private static function populationPublication(): \Qualimetrix\Analysis\Finding\Contract\ChannelPublication
    {
        $decisions = [];
        foreach (\Qualimetrix\Analysis\Policy\Baseline\EntryBinding\UnusedEntryRule::channelDeclarations() as $name => $declaration) {
            foreach ($declaration->levels as $level) {
                $decisions[] = new \Qualimetrix\Analysis\Finding\Contract\EnablementDecision(
                    new \Qualimetrix\Analysis\Finding\Contract\Selection\SelectionCellAddress(\Qualimetrix\Analysis\Policy\Baseline\EntryBinding\UnusedEntryRule::NAME, new \Qualimetrix\Analysis\Finding\Contract\FindingChannel($name), $level, \Qualimetrix\Analysis\Finding\Contract\ChannelSelectionRole::Selectable),
                    new \Qualimetrix\Analysis\Finding\Contract\Selection\AuthoredCellDecision(\Qualimetrix\Analysis\Finding\Contract\Selection\CellSwitch::On, \Qualimetrix\Analysis\Finding\Contract\Selection\CellAdmission::Direct),
                );
            }
        }
        return new \Qualimetrix\Analysis\Finding\Contract\ChannelPublication(new \Qualimetrix\Analysis\Finding\Contract\RuleEnablement($decisions, null));
    }
}
