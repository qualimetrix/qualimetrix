<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Analysis\Run\Integration\Contract\Configuration;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Configuration\Contract\Refusal\ConfigurationOrigin;
use Qualimetrix\Analysis\Configuration\Contract\Refusal\ConfigurationSource;
use Qualimetrix\Analysis\Finding\Contract\ProjectScope\ExcludeSelectorOutcome;
use Qualimetrix\Analysis\Finding\Contract\ProjectScope\ExcludeSelectorVerdict;
use Qualimetrix\Analysis\Finding\Contract\ProjectScope\ProjectScopeDoor;
use Qualimetrix\Analysis\Run\Contract\Configuration\ProjectScopeMeasurement;
use Qualimetrix\Analysis\Run\Contract\Configuration\ProjectScopeState;
use Qualimetrix\Analysis\Run\Contract\Configuration\ProjectScopeUniverse;
use Qualimetrix\Analysis\Run\Contract\Discovery\DiscoveredProjectFiles;
use Qualimetrix\Analysis\Run\Discovery\ScopeFacts;
use Qualimetrix\Core\Path\AbsolutePath;
use Qualimetrix\Core\Path\RelativePath;
use Qualimetrix\Core\Pattern\PathPattern;
use Qualimetrix\Core\Pattern\SelectorDefinition;
use Qualimetrix\Core\Pattern\SelectorKind;

#[CoversClass(ProjectScopeMeasurement::class)]
final class ProjectScopeDoorsTest extends TestCase
{
    #[Test]
    public function itJudgesADeadSelectorWhileRemovedPhpClosesNamespaceClaims(): void
    {
        $source = ConfigurationOrigin::of(ConfigurationSource::ConfigFile, 'qmx.yaml');
        $removed = self::removed($source);
        $dead = ExcludeSelectorVerdict::fromMeasuredFacts(self::pattern(SelectorKind::Regex, '#Nope#'), [$source], [], [], null, null, [['directory' => 'src/Legacy', 'selector' => 'subtree:src/Legacy', 'sources' => [$source]]], true);
        $measurement = self::initial()->withDiscoveredFiles(self::files(new ScopeFacts([], [], [], false), [$removed, $dead]));

        self::assertSame(ProjectScopeState::Covered, $measurement->state());
        self::assertSame([ProjectScopeDoor::Exclude], $measurement->judgement()->withheldBy());
        self::assertTrue($measurement->judgement()->judgesExcludeSelectors());
        self::assertSame(ExcludeSelectorOutcome::CoveredBySameSource, $measurement->judgement()->excludeSelectors()[1]->outcome);
        self::assertSame($measurement->judgement(), $measurement->judgement());
    }

    #[Test]
    public function itWithholdsNamespaceClaimsForUnlistableRemovedCodeWithoutClosingSelectors(): void
    {
        $source = ConfigurationOrigin::of(ConfigurationSource::ConfigFile, 'qmx.yaml');
        $removed = ExcludeSelectorVerdict::fromMeasuredFacts(self::pattern(SelectorKind::Subtree, 'src/Legacy'), [$source], ['src/Legacy'], ['src/Legacy'], 'unlistable', null, [], true);
        $measurement = self::initial()->withDiscoveredFiles(self::files(new ScopeFacts([], [], [], false), [$removed]));

        self::assertSame([ProjectScopeDoor::Exclude], $measurement->judgement()->withheldBy());
        self::assertTrue($measurement->judgement()->judgesExcludeSelectors());
        self::assertSame(ExcludeSelectorOutcome::Removed, $measurement->judgement()->excludeSelectors()[0]->outcome);
        self::assertContains(
            ['kind' => 'exclude', 'selector' => 'subtree:src/Legacy', 'removedEntries' => 1, 'evidence' => 'unlistable'],
            array_map(static fn($reason): array => $reason->toArray(), $measurement->reasons()),
        );
    }

    #[Test]
    public function itKeepsBothQuestionsClosedForHiddenOutsideDenominatorButRetainsRemoved(): void
    {
        $source = ConfigurationOrigin::of(ConfigurationSource::ConfigFile, 'qmx.yaml');
        $removed = self::removed($source);
        $measurement = self::initial()->withDiscoveredFiles(self::files(new ScopeFacts([], [], [RelativePath::fromString('src/Legacy')], true), [$removed]));

        self::assertSame(ProjectScopeState::Unknown, $measurement->state());
        self::assertContains(ProjectScopeDoor::UnknownUniverse, $measurement->judgement()->withheldBy());
        self::assertFalse($measurement->judgement()->judgesExcludeSelectors());
        self::assertSame(ExcludeSelectorOutcome::Removed, $measurement->judgement()->excludeSelectors()[0]->outcome);
    }

    #[Test]
    public function itUsesObservedOutsidePhpToClosePathsEvenWhenInitialTargetsLookCovered(): void
    {
        $measurement = self::initial()->withDiscoveredFiles(self::files(new ScopeFacts([RelativePath::fromString('src/Other.php')], [], [], true)));

        self::assertSame(ProjectScopeState::Narrowed, $measurement->state());
        self::assertSame([ProjectScopeDoor::Paths], $measurement->judgement()->selectorDoors());
        self::assertSame(['src/Other.php'], $measurement->uncoveredRoots);
    }

    /** @param list<ExcludeSelectorVerdict> $verdicts */
    private static function files(ScopeFacts $facts, array $verdicts = []): DiscoveredProjectFiles
    {
        return new DiscoveredProjectFiles([], [], [], [], $verdicts, $facts, 0);
    }

    private static function initial(): ProjectScopeMeasurement
    {
        $root = AbsolutePath::fromString('/project');

        return new ProjectScopeMeasurement(
            new ProjectScopeUniverse($root, true, [], [], [], true, []),
            [$root],
            ProjectScopeState::Covered,
            [],
        );
    }

    private static function removed(ConfigurationOrigin $source): ExcludeSelectorVerdict
    {
        return ExcludeSelectorVerdict::fromMeasuredFacts(self::pattern(SelectorKind::Subtree, 'src/Legacy'), [$source], ['src/Legacy'], ['src/Legacy'], 'php-file', null, [], true);
    }

    private static function pattern(SelectorKind $kind, string $value): PathPattern
    {
        return new PathPattern(new SelectorDefinition($kind, $value));
    }
}
