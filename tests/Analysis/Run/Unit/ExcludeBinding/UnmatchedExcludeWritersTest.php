<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Analysis\Run\Unit\ExcludeBinding;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Configuration\Contract\Refusal\ConfigurationOrigin;
use Qualimetrix\Analysis\Configuration\Contract\Refusal\ConfigurationSource;
use Qualimetrix\Analysis\Finding\Contract\Channel\ChannelPublication;
use Qualimetrix\Analysis\Finding\Contract\Channel\ChannelSelectionRole;
use Qualimetrix\Analysis\Finding\Contract\Channel\FindingChannel;
use Qualimetrix\Analysis\Finding\Contract\EnablementDecision;
use Qualimetrix\Analysis\Finding\Contract\Finding;
use Qualimetrix\Analysis\Finding\Contract\ProjectScope\ExcludeSelectorVerdict;
use Qualimetrix\Analysis\Finding\Contract\ProjectScope\ProjectScopeJudgement;
use Qualimetrix\Analysis\Finding\Contract\RuleEnablement;
use Qualimetrix\Analysis\Finding\Contract\Selection\AuthoredCellDecision;
use Qualimetrix\Analysis\Finding\Contract\Selection\CellAdmission;
use Qualimetrix\Analysis\Finding\Contract\Selection\CellSwitch;
use Qualimetrix\Analysis\Finding\Contract\Selection\SelectionCellAddress;
use Qualimetrix\Analysis\Run\ExcludeBinding\UnjudgedExcludeFinding;
use Qualimetrix\Analysis\Run\ExcludeBinding\UnmatchedExcludeAudit;
use Qualimetrix\Analysis\Run\ExcludeBinding\UnmatchedExcludeFinding;
use Qualimetrix\Analysis\Run\ExcludeBinding\UnmatchedExcludeOptions;
use Qualimetrix\Analysis\Run\ExcludeBinding\UnmatchedExcludeRule;
use Qualimetrix\Core\Path\AbsolutePath;
use Qualimetrix\Core\Pattern\PathPattern;
use Qualimetrix\Core\Pattern\SelectorDefinition;
use Qualimetrix\Core\Pattern\SelectorKind;

#[CoversClass(UnmatchedExcludeAudit::class)]
#[CoversClass(UnmatchedExcludeFinding::class)]
#[CoversClass(UnjudgedExcludeFinding::class)]
#[CoversClass(UnmatchedExcludeRule::class)]
final class UnmatchedExcludeWritersTest extends TestCase
{
    private const string SENTENCE = 'Written by configuration file "vendor/acme/std/base.yaml" (imported by configuration file "qmx.yaml").';

    #[Test]
    public function itEndsAnUnmatchedFindingWithItsWriters(): void
    {
        $finding = $this->singleFinding(self::verdict(self::project(), [], 'Gone'));

        self::assertStringEndsWith('so it leaves nothing out. Written by configuration file "qmx.yaml".', $finding->message);
    }

    #[Test]
    public function itEndsASameSourceCoveredFindingWithItsWriters(): void
    {
        $verdict = self::verdict(self::installed(), [['directory' => 'src', 'selector' => 'subtree:src', 'sources' => [self::installed()]]], 'src/Gone');

        $finding = $this->singleFinding($verdict);

        self::assertStringEndsWith('already removes. ' . self::SENTENCE, $finding->message);
    }

    #[Test]
    public function itEndsAnUnjudgedFindingWithItsWriters(): void
    {
        $verdict = ExcludeSelectorVerdict::fromMeasuredFacts(
            new PathPattern(new SelectorDefinition(SelectorKind::Exact, 'blocked/Inner')),
            [self::installed()],
            [],
            [],
            null,
            'blocked',
            [],
            true,
        );

        $findings = (new UnmatchedExcludeAudit(new UnmatchedExcludeOptions()))->findings(
            new ProjectScopeJudgement([], [], [$verdict]),
            AbsolutePath::fromString('/project'),
        );

        self::assertCount(1, $findings);
        self::assertStringEndsWith('still needed. ' . self::SENTENCE, $findings[0]->message);
    }

    #[Test]
    public function itKeepsTheOccurrenceKeyWhoeverWroteTheValue(): void
    {
        $imported = $this->singleFinding(self::verdict(self::installed(), [], 'Gone'));
        $root = $this->singleFinding(self::verdict(self::project(), [], 'Gone'));

        self::assertNotSame($root->message, $imported->message);
        self::assertSame($root->occurrenceKey?->value, $imported->occurrenceKey?->value);
    }

    #[Test]
    public function itReportsNothingForASelectorOnlyAnInstalledFileWrote(): void
    {
        $verdict = self::verdict(self::installed(), [], 'Gone')->withInstalledWriter();
        $audit = new UnmatchedExcludeAudit(new UnmatchedExcludeOptions());
        $scope = new ProjectScopeJudgement(excludeSelectors: [$verdict]);

        self::assertSame([], $audit->findings($scope, AbsolutePath::fromString('/project')));
    }

    #[Test]
    public function itFailsTheInstalledWriterGateOfAnInstalledSelectorAsABoundaryNotADataGap(): void
    {
        $verdict = self::verdict(self::installed(), [], 'Gone')->withInstalledWriter();
        $declaration = UnmatchedExcludeRule::channelDeclarations()[UnmatchedExcludeRule::NAME];
        $population = (new UnmatchedExcludeAudit(new UnmatchedExcludeOptions()))->population(
            new ProjectScopeJudgement(excludeSelectors: [$verdict]),
            self::publication(),
            new FindingChannel(UnmatchedExcludeRule::NAME),
            $declaration,
        );

        self::assertSame(0, $population->judgedCount());
        self::assertSame(1, $population->unjudgedCount());
        self::assertSame('installed-writer', $population->abstentions()[0]->gate);
        self::assertFalse($population->abstentions()[0]->dataGap);
    }

    #[Test]
    public function itStillJudgesTheSelectorOfAProjectFile(): void
    {
        $verdict = self::verdict(self::project(), [], 'Gone');
        $declaration = UnmatchedExcludeRule::channelDeclarations()[UnmatchedExcludeRule::NAME];
        $population = (new UnmatchedExcludeAudit(new UnmatchedExcludeOptions()))->population(
            new ProjectScopeJudgement(excludeSelectors: [$verdict]),
            self::publication(),
            new FindingChannel(UnmatchedExcludeRule::NAME),
            $declaration,
        );

        self::assertSame(1, $population->judgedCount());
        self::assertSame([], $population->abstentions());
    }

    private function singleFinding(ExcludeSelectorVerdict $verdict): Finding
    {
        $findings = (new UnmatchedExcludeAudit(new UnmatchedExcludeOptions()))->findings(
            new ProjectScopeJudgement(excludeSelectors: [$verdict]),
            AbsolutePath::fromString('/project'),
        );
        self::assertCount(1, $findings);

        return $findings[0];
    }

    /** @param list<array{directory: string, selector: string, sources: non-empty-list<ConfigurationOrigin>}> $hidden */
    private static function verdict(ConfigurationOrigin $source, array $hidden, string $value): ExcludeSelectorVerdict
    {
        return ExcludeSelectorVerdict::fromMeasuredFacts(
            new PathPattern(new SelectorDefinition(SelectorKind::Exact, $value)),
            [$source],
            [],
            [],
            null,
            null,
            $hidden,
            true,
        );
    }

    private static function publication(): ChannelPublication
    {
        $decisions = [];
        foreach (UnmatchedExcludeRule::channelDeclarations() as $name => $declaration) {
            foreach ($declaration->levels as $level) {
                $decisions[] = new EnablementDecision(
                    new SelectionCellAddress(UnmatchedExcludeRule::NAME, new FindingChannel($name), $level, ChannelSelectionRole::Selectable),
                    new AuthoredCellDecision(CellSwitch::On, CellAdmission::Direct),
                );
            }
        }

        return new ChannelPublication(new RuleEnablement($decisions, null));
    }

    private static function installed(): ConfigurationOrigin
    {
        return ConfigurationOrigin::of(ConfigurationSource::ConfigFile, 'vendor/acme/std/base.yaml')
            ->importedThrough(self::project());
    }

    private static function project(): ConfigurationOrigin
    {
        return ConfigurationOrigin::of(ConfigurationSource::ConfigFile, 'qmx.yaml');
    }
}
