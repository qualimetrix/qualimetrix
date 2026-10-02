<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Analysis\Finding\Unit\ProjectScope;

use LogicException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Configuration\Contract\Refusal\ConfigurationOrigin;
use Qualimetrix\Analysis\Configuration\Contract\Refusal\ConfigurationSource;
use Qualimetrix\Analysis\Finding\Contract\ProjectScope\ExcludeSelectorOutcome;
use Qualimetrix\Analysis\Finding\Contract\ProjectScope\ExcludeSelectorVerdict;
use Qualimetrix\Analysis\Finding\Contract\ProjectScope\ProjectScopeDoor;
use Qualimetrix\Analysis\Finding\Contract\ProjectScope\ProjectScopeJudgement;
use Qualimetrix\Core\Pattern\PathPattern;
use Qualimetrix\Core\Pattern\SelectorDefinition;
use Qualimetrix\Core\Pattern\SelectorKind;

#[CoversClass(ProjectScopeJudgement::class)]
#[CoversClass(ExcludeSelectorVerdict::class)]
final class ProjectScopeJudgementTest extends TestCase
{
    #[Test]
    public function itKeepsTheSelectorQuestionOpenWhenExcludedCodeWithholdsNamespaceClaims(): void
    {
        $scope = new ProjectScopeJudgement([ProjectScopeDoor::Exclude], [], [self::selector(ExcludeSelectorOutcome::Unmatched)]);

        self::assertFalse($scope->judgesNamespaceClaims());
        self::assertTrue($scope->judgesExcludeSelectors());
        self::assertSame(ExcludeSelectorOutcome::Unmatched, $scope->excludeSelectors()[0]->outcome);
    }

    #[Test]
    public function itRefusesNotJudgedWhileTheSelectorQuestionIsOpen(): void
    {
        $this->expectException(LogicException::class);

        new ProjectScopeJudgement([], [], [self::selector(ExcludeSelectorOutcome::NotJudged)]);
    }

    #[Test]
    public function itRefusesAnExcludeDoorOnTheSelectorQuestion(): void
    {
        $this->expectException(LogicException::class);

        new ProjectScopeJudgement([], [ProjectScopeDoor::Exclude]);
    }

    #[Test]
    public function itRetainsARemovedSelectorWhenPathsAreNarrowed(): void
    {
        $removed = ExcludeSelectorVerdict::fromMeasuredFacts(self::pattern(SelectorKind::Subtree, 'src/Legacy'), self::sources(), ['src/Legacy'], ['src/Legacy'], 'php-file', null, [], true);
        $scope = new ProjectScopeJudgement([ProjectScopeDoor::Paths], [ProjectScopeDoor::Paths], [$removed]);

        self::assertSame($removed, $scope->excludeSelectors()[0]);
    }

    #[Test]
    public function itDerivesSameAndOtherFromHiddenDirectorySources(): void
    {
        $file = self::sources()[0];
        $cli = ConfigurationOrigin::of(ConfigurationSource::CommandLine, '--exclude');
        $pattern = self::pattern(SelectorKind::Regex, '#Legacy/.*#');
        $same = ExcludeSelectorVerdict::fromMeasuredFacts($pattern, [$file], [], [], null, null, [['directory' => 'src/Legacy', 'selector' => 'subtree:src/Legacy', 'sources' => [$file]]], true);
        $mixed = ExcludeSelectorVerdict::fromMeasuredFacts($pattern, [$file], [], [], null, null, [['directory' => 'src/Legacy', 'selector' => 'subtree:src/Legacy', 'sources' => [$file, $cli]]], true);

        self::assertSame(ExcludeSelectorOutcome::CoveredBySameSource, $same->outcome);
        self::assertSame(ExcludeSelectorOutcome::CoveredByOtherSource, $mixed->outcome);
        self::assertSame([$cli], $mixed->coveredBySources);
    }

    #[Test]
    public function itRefusesContradictoryPhpEvidence(): void
    {
        $this->expectException(LogicException::class);

        ExcludeSelectorVerdict::fromMeasuredFacts(self::pattern(SelectorKind::Subtree, 'src/Legacy'), self::sources(), [], [], 'php-file', null, [], true);
    }

    #[Test]
    public function itKeepsBlockedEvidenceWhenTheQuestionCloses(): void
    {
        $blocked = ExcludeSelectorVerdict::fromMeasuredFacts(self::pattern(SelectorKind::Regex, '#Legacy#'), self::sources(), [], [], null, 'src/Legacy', [], false);

        self::assertSame(ExcludeSelectorOutcome::Unjudgeable, $blocked->outcome);
        self::assertSame($blocked, $blocked->withoutSelectorJudgement());
    }

    private static function selector(ExcludeSelectorOutcome $outcome): ExcludeSelectorVerdict
    {
        return ExcludeSelectorVerdict::fromMeasuredFacts(self::pattern(SelectorKind::Regex, '#Nope#'), self::sources(), [], [], null, null, [], $outcome !== ExcludeSelectorOutcome::NotJudged);
    }

    private static function pattern(SelectorKind $kind, string $value): PathPattern
    {
        return new PathPattern(new SelectorDefinition($kind, $value));
    }

    /** @return non-empty-list<ConfigurationOrigin> */
    private static function sources(): array
    {
        return [ConfigurationOrigin::of(ConfigurationSource::ConfigFile, 'qmx.yaml')];
    }
}
