<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Analysis\Finding\Unit\Population;

use LogicException;
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
    #[Test]
    public function itCountsInlineUsedUnusedAndProducerDisabledSitesWithoutInferringFromFindings(): void
    {
        $container = (new \Qualimetrix\Infrastructure\DependencyInjection\ContainerFactory())->create();
        $execution = $container->get(RuleExecutionInterface::class);
        $factory = $container->get(\Qualimetrix\Analysis\Finding\Contract\ChannelUniverseInterface::class);
        if (!$execution instanceof RuleExecutionInterface || !$factory instanceof \Qualimetrix\Infrastructure\Rule\Contract\RuleChannelSnapshotFactoryInterface) {
            throw new LogicException('Native inline fixture requires execution and channel snapshot services.');
        }
        $universe = $factory->snapshot(new \Qualimetrix\Analysis\Evidence\ComputedMetrics\Contract\Definition\ResolvedComputedMetricDefinitions([]));
        $registry = new \Qualimetrix\Analysis\Finding\RuleConfiguration\RuleOptionsRegistry();
        $registry->replace(\Qualimetrix\Tests\Analysis\Finding\Support\ResolvedOptionsFixture::ready(\Qualimetrix\Analysis\Finding\Contract\Configuration\FindingConfiguration::none(), $execution->allRules(), channels: $universe, disabled: ['code-smell.eval']));
        $usage = new \Qualimetrix\Analysis\Policy\Inline\Directive\Audit\DirectiveUsage($universe, $registry, $universe, new \Qualimetrix\Analysis\Policy\Inline\Directive\RefusedDirectives($universe));
        $file = \Qualimetrix\Core\Path\RelativePath::fromString('src/Inline.php');
        $directives = [];
        foreach (['code-smell.goto', 'code-smell.exit', 'code-smell.eval'] as $ordinal => $channel) {
            $directives[] = new \Qualimetrix\Analysis\Policy\Inline\Contract\Suppression\Suppression($channel, 'reason', $ordinal + 1, \Qualimetrix\Analysis\Policy\Inline\Contract\Suppression\SuppressionType::File, position: $ordinal);
        }
        $subject = \Qualimetrix\Core\Symbol\MetricSubject::aggregate(\Qualimetrix\Core\Symbol\SymbolPath::forFile($file));
        $finding = new \Qualimetrix\Analysis\Finding\Contract\Finding(location: new \Qualimetrix\Analysis\Finding\Contract\Location($file, 4), subject: $subject, symbolPath: $subject->toSymbolPath(), ruleName: 'code-smell.goto', message: 'used', severity: \Qualimetrix\Analysis\Finding\Contract\Severity::Warning, code: 'code-smell.goto');
        $coverage = \Qualimetrix\Analysis\Finding\Contract\ProjectScope\SubjectCoverageFacts::fromMeasured(new ProjectScopeJudgement(), [$file], []);
        $declarations = \Qualimetrix\Analysis\Policy\Inline\Directive\UnusedDirectiveRule::channelDeclarations();
        $result = $usage->usageResult([$file->value() => $directives], [$finding], \Qualimetrix\Analysis\Finding\Contract\Severity::Warning, $registry->enablement()?->levelActivity() ?? throw new LogicException('Fixture requires enablement.'), $coverage, self::publication(\Qualimetrix\Analysis\Policy\Inline\Directive\UnusedDirectiveRule::NAME, $declarations));
        self::assertCount(1, $result['findings']);
        self::assertSame(2, $result['population']->judgedCount());
        self::assertSame(1, $result['population']->unjudgedCount());
        self::assertSame('directive-site', $result['population']->abstentions()[0]->unit);
        $disabled = $usage->usageResult([$file->value() => $directives], [$finding], \Qualimetrix\Analysis\Finding\Contract\Severity::Warning, \Qualimetrix\Analysis\Finding\Contract\LevelActivity::empty(), $coverage, self::publication(\Qualimetrix\Analysis\Policy\Inline\Directive\UnusedDirectiveRule::NAME, $declarations, false));
        self::assertSame([], $disabled['findings']);
        self::assertTrue($disabled['population']->isEmpty());
    }

    #[Test]
    public function itKeepsDuplicateSuppressionOccurrencesAndSeparatesNullNamespaceFromKnownEmpty(): void
    {
        $producer = \Qualimetrix\Analysis\Finding\SuppressionBinding\UnboundSuppressionRule::NAME;
        $path = new PathPattern(SelectorDefinition::fromKindAndValue('regex', 'src/.*'));
        $namespace = new \Qualimetrix\Core\Pattern\NamespacePattern(SelectorDefinition::fromKindAndValue('regex', 'Gone.*'));
        $configuration = self::createStub(\Qualimetrix\Analysis\Finding\Contract\RuleConfigurationInterface::class);
        $configuration->method('resolvedOptions')->willReturn(new \Qualimetrix\Analysis\Finding\Contract\ResolvedRuleOptions([$producer => new \Qualimetrix\Analysis\Finding\SuppressionBinding\UnboundSuppressionOptions(), 'code-smell.goto' => new \Qualimetrix\Analysis\Evidence\CodeSmell\CodeSmellOptions()], [$producer => new \Qualimetrix\Analysis\Finding\Contract\RuleSuppression(), 'code-smell.goto' => new \Qualimetrix\Analysis\Finding\Contract\RuleSuppression(namespaces: [$namespace], paths: [$path, $path])]));
        $execution = self::createStub(RuleExecutionInterface::class);
        $execution->method('publishable')->willReturnArgument(0);
        $audit = new \Qualimetrix\Analysis\Finding\SuppressionBinding\UnboundSuppressionAudit($execution, $configuration);
        $scope = new \Qualimetrix\Analysis\Finding\SuppressionBinding\ValueScopeJudgement(__DIR__, [], [__DIR__], true, new ProjectScopeJudgement());
        $declarations = \Qualimetrix\Analysis\Finding\SuppressionBinding\UnboundSuppressionRule::channelDeclarations();
        $publication = self::publication($producer, $declarations);
        $unknown = $audit->auditResult([$path, $path], [$namespace], [\Qualimetrix\Core\Path\RelativePath::fromString('src/Healthy.php')], null, $scope, $publication);
        self::assertSame(4, $unknown['population']->judgedCount());
        self::assertSame(2, $unknown['population']->unjudgedCount());
        self::assertSame([], $unknown['findings']);
        self::assertSame([1, 1], array_column($unknown['population']->abstentions(), 'count'));
        $known = $audit->auditResult([$path, $path], [$namespace], [], [], $scope, $publication);
        self::assertSame(6, $known['population']->judgedCount());
        self::assertSame(0, $known['population']->unjudgedCount());
        self::assertCount(6, $known['findings']);
        self::assertSame(4, $unknown['population']->merge($unknown['population'])->judgedCount());
        $disabled = $audit->auditResult([$path], [$namespace], [], null, $scope, self::publication($producer, $declarations, false));
        self::assertTrue($disabled['population']->isEmpty());
        self::assertSame([], $disabled['findings']);
    }

    /** @param array<string, \Qualimetrix\Analysis\Finding\Contract\ChannelDeclaration> $declarations */
    private static function publication(string $producer, array $declarations, bool $selected = true): \Qualimetrix\Analysis\Finding\Contract\ChannelPublication
    {
        $decisions = [];
        foreach ($declarations as $name => $declaration) {
            foreach ($declaration->levels as $level) {
                $decisions[] = new \Qualimetrix\Analysis\Finding\Contract\EnablementDecision(
                    new \Qualimetrix\Analysis\Finding\Contract\Selection\SelectionCellAddress($producer, new \Qualimetrix\Analysis\Finding\Contract\FindingChannel($name), $level, \Qualimetrix\Analysis\Finding\Contract\ChannelSelectionRole::Selectable),
                    new \Qualimetrix\Analysis\Finding\Contract\Selection\AuthoredCellDecision($selected ? \Qualimetrix\Analysis\Finding\Contract\Selection\CellSwitch::On : \Qualimetrix\Analysis\Finding\Contract\Selection\CellSwitch::Off, \Qualimetrix\Analysis\Finding\Contract\Selection\CellAdmission::Direct),
                );
            }
        }
        return new \Qualimetrix\Analysis\Finding\Contract\ChannelPublication(new \Qualimetrix\Analysis\Finding\Contract\RuleEnablement($decisions, null));
    }
}
