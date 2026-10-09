<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Analysis\Finding\Unit\Population;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Configuration\Contract\Refusal\ConfigurationOrigin;
use Qualimetrix\Analysis\Configuration\Contract\Refusal\ConfigurationSource;
use Qualimetrix\Analysis\Finding\Contract\Filter\FindingFilterStage;
use Qualimetrix\Analysis\Finding\Contract\Filter\FindingFilterStageResult;
use Qualimetrix\Analysis\Finding\Contract\FindingChannel;
use Qualimetrix\Analysis\Finding\Contract\Population\JudgedPopulation;
use Qualimetrix\Analysis\Finding\Contract\ProjectScope\ExcludeSelectorVerdict;
use Qualimetrix\Analysis\Finding\Contract\ProjectScope\ProjectScopeDoor;
use Qualimetrix\Analysis\Finding\Contract\ProjectScope\ProjectScopeJudgement;
use Qualimetrix\Analysis\Finding\Contract\RuleExecutionInterface;
use Qualimetrix\Analysis\Policy\Baseline\BaselineIdentity;
use Qualimetrix\Analysis\Policy\Baseline\Contract\BaselineAuditChannels;
use Qualimetrix\Analysis\Policy\Baseline\Contract\CeilingOutcome;
use Qualimetrix\Analysis\Policy\Baseline\EntryBinding\UnusedEntryAudit;
use Qualimetrix\Analysis\Policy\Baseline\EntryBinding\UnusedEntryRule;
use Qualimetrix\Analysis\Policy\Baseline\InertBaselineEntry;
use Qualimetrix\Analysis\Policy\Baseline\InertEntryReason;
use Qualimetrix\Analysis\Run\ExcludeBinding\UnmatchedExcludeAudit;
use Qualimetrix\Analysis\Run\ExcludeBinding\UnmatchedExcludeOptions;
use Qualimetrix\Analysis\Run\ExcludeBinding\UnmatchedExcludeRule;
use Qualimetrix\Core\Pattern\PathPattern;
use Qualimetrix\Core\Pattern\SelectorDefinition;
use Qualimetrix\Core\Symbol\SymbolLevel;

#[CoversClass(UnmatchedExcludeAudit::class)]
#[CoversClass(UnusedEntryAudit::class)]
#[CoversClass(JudgedPopulation::class)]
final class HostedPopulationTest extends TestCase
{
    #[Test]
    public function itCreditsHealthyDiscoveryWithoutInventingJudgementForAnUnknownUniverse(): void
    {
        $origin = ConfigurationOrigin::of(ConfigurationSource::ConfigFile, 'qmx.yaml');
        $pattern = new PathPattern(SelectorDefinition::fromKindAndValue('subtree', 'vendor'));
        $healthy = ExcludeSelectorVerdict::fromMeasuredFacts($pattern, [$origin], ['vendor/A.php'], ['vendor/A.php'], 'php-file', null, [], true);
        $unknown = ExcludeSelectorVerdict::fromMeasuredFacts($pattern, [$origin], [], [], null, null, [], false);
        $publication = self::publication(UnmatchedExcludeRule::NAME, UnmatchedExcludeRule::channelDeclarations());
        $audit = new UnmatchedExcludeAudit(new UnmatchedExcludeOptions());
        $channel = new FindingChannel(UnmatchedExcludeRule::NAME);
        $declaration = UnmatchedExcludeRule::channelDeclarations()[UnmatchedExcludeRule::NAME];
        $judged = $audit->population(new ProjectScopeJudgement(excludeSelectors: [$healthy]), $publication, $channel, $declaration);
        $unjudged = $audit->population(new ProjectScopeJudgement(selectorsWithheldBy: [ProjectScopeDoor::UnknownUniverse], excludeSelectors: [$unknown]), $publication, $channel, $declaration);
        self::assertSame(1, $judged->judgedCount());
        self::assertSame([], $judged->abstentions());
        self::assertSame(1, $unjudged->unjudgedCount());
        self::assertSame('configured-discovery-selector', $unjudged->abstentions()[0]->unit);
        self::assertSame(1, $judged->merge($judged)->judgedCount());
        self::assertSame(1, $judged->merge($unjudged)->unjudgedCount());
    }

    #[Test]
    public function itCountsDiagnosticRecordsAfterOnlyTheNativeDuplicateCoalescing(): void
    {
        $identity = new BaselineIdentity('file:src/A.php', new FindingChannel('code-smell.goto'));
        $duplicate = InertBaselineEntry::forIdentity($identity, InertEntryReason::DuplicateIdentity, 'duplicate', ['count' => 1]);
        $malformed = InertBaselineEntry::forRaw('file:bad.php', null, InertEntryReason::Malformed, 'bad', ['count' => -1]);
        $execution = self::createStub(RuleExecutionInterface::class);
        $execution->method('publishable')->willReturnArgument(0);
        $result = (new UnusedEntryAudit($execution))->auditResult(new CeilingOutcome(
            new FindingFilterStageResult(FindingFilterStage::Baseline, [], []),
            [],
            [$duplicate, $duplicate, $malformed, $malformed],
        ), 'baseline.json', self::publication(UnusedEntryRule::NAME, UnusedEntryRule::channelDeclarations()));
        self::assertCount(3, $result['findings']);
        self::assertSame(3, $result['population']->judgedCount());
        self::assertSame([], $result['population']->abstentions());
        self::assertSame(SymbolLevel::Project, $result['population']->judgedCounts()[0]['level']);
        self::assertSame(BaselineAuditChannels::UNUSED_ENTRY, $result['population']->judgedCounts()[0]['channel']->code);
    }
    /** @param array<string, \Qualimetrix\Analysis\Finding\Contract\ChannelDeclaration> $declarations */
    private static function publication(string $producer, array $declarations): \Qualimetrix\Analysis\Finding\Contract\ChannelPublication
    {
        $decisions = [];
        foreach ($declarations as $name => $declaration) {
            foreach ($declaration->levels as $level) {
                $decisions[] = new \Qualimetrix\Analysis\Finding\Contract\EnablementDecision(
                    new \Qualimetrix\Analysis\Finding\Contract\Selection\SelectionCellAddress($producer, new \Qualimetrix\Analysis\Finding\Contract\FindingChannel($name), $level, \Qualimetrix\Analysis\Finding\Contract\ChannelSelectionRole::Selectable),
                    new \Qualimetrix\Analysis\Finding\Contract\Selection\AuthoredCellDecision(\Qualimetrix\Analysis\Finding\Contract\Selection\CellSwitch::On, \Qualimetrix\Analysis\Finding\Contract\Selection\CellAdmission::Direct),
                );
            }
        }
        return new \Qualimetrix\Analysis\Finding\Contract\ChannelPublication(new \Qualimetrix\Analysis\Finding\Contract\RuleEnablement($decisions, null));
    }
}
