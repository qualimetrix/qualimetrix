<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Analysis\Finding\Unit\Contract\ProjectScope;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Configuration\Contract\Refusal\ConfigurationOrigin;
use Qualimetrix\Analysis\Configuration\Contract\Refusal\ConfigurationSource;
use Qualimetrix\Analysis\Finding\Contract\ProjectScope\ConfiguredValueWriters;
use Qualimetrix\Analysis\Finding\Contract\ProjectScope\ExcludeSelectorOutcome;
use Qualimetrix\Analysis\Finding\Contract\ProjectScope\ExcludeSelectorVerdict;
use Qualimetrix\Analysis\Finding\Contract\ProjectScope\ProjectScopeDoor;
use Qualimetrix\Analysis\Finding\Contract\ProjectScope\ProjectScopeJudgement;
use Qualimetrix\Core\Pattern\PathPattern;
use Qualimetrix\Core\Pattern\SelectorDefinition;
use Qualimetrix\Core\Pattern\SelectorKind;

#[CoversClass(ProjectScopeJudgement::class)]
#[CoversClass(ExcludeSelectorVerdict::class)]
final class InstalledWriterJudgementTest extends TestCase
{
    private const string INSTALLED = 'configuration file "vendor/acme/std/base.yaml" (imported by configuration file "qmx.yaml")';

    #[Test]
    public function itJudgesEveryValueWhenNothingIsEstablishedAsInstalled(): void
    {
        self::assertTrue((new ProjectScopeJudgement())->judgesValueWrittenBy(self::writers(self::installed())));
    }

    #[Test]
    public function itDoesNotJudgeAValueEveryWriterOfWhichIsInstalled(): void
    {
        $scope = new ProjectScopeJudgement(installedConfigurationSources: [self::INSTALLED]);

        self::assertFalse($scope->judgesValueWrittenBy(self::writers(self::installed())));
    }

    #[Test]
    public function itJudgesAValueAProjectFileAlsoWrote(): void
    {
        $scope = new ProjectScopeJudgement(installedConfigurationSources: [self::INSTALLED]);

        self::assertTrue($scope->judgesValueWrittenBy(self::writers(self::installed(), self::project())));
    }

    /** @return iterable<string, array{ExcludeSelectorOutcome}> */
    public static function provideConvertedOutcomes(): iterable
    {
        yield 'unmatched' => [ExcludeSelectorOutcome::Unmatched];
        yield 'covered by the same source' => [ExcludeSelectorOutcome::CoveredBySameSource];
    }

    #[Test]
    #[DataProvider('provideConvertedOutcomes')]
    public function itTurnsAnUnansweredSelectorIntoAnInstalledWriterOne(ExcludeSelectorOutcome $outcome): void
    {
        $verdict = self::verdict($outcome)->withInstalledWriter();

        self::assertSame(ExcludeSelectorOutcome::InstalledWriter, $verdict->outcome);
        self::assertSame('installed-writer', $verdict->outcome->value);
        self::assertEquals(self::verdict($outcome)->sources, $verdict->sources);
    }

    /** @return iterable<string, array{ExcludeSelectorOutcome}> */
    public static function provideKeptOutcomes(): iterable
    {
        yield 'removed' => [ExcludeSelectorOutcome::Removed];
        yield 'unjudgeable' => [ExcludeSelectorOutcome::Unjudgeable];
        yield 'not judged' => [ExcludeSelectorOutcome::NotJudged];
        yield 'covered by another source' => [ExcludeSelectorOutcome::CoveredByOtherSource];
    }

    #[Test]
    #[DataProvider('provideKeptOutcomes')]
    public function itKeepsEveryOtherConclusion(ExcludeSelectorOutcome $outcome): void
    {
        $verdict = self::verdict($outcome);

        self::assertSame($verdict, $verdict->withInstalledWriter());
    }

    #[Test]
    public function itReportsTheSourcesOfASelectorAsItsWriters(): void
    {
        self::assertEquals([self::installed()], self::verdict(ExcludeSelectorOutcome::Unmatched)->writers()->origins);
    }

    #[Test]
    public function itAcceptsAnInstalledWriterSelectorWhetherTheQuestionIsOpenOrClosed(): void
    {
        $verdict = self::verdict(ExcludeSelectorOutcome::Unmatched)->withInstalledWriter();

        $open = new ProjectScopeJudgement([], [], [$verdict]);
        $closed = new ProjectScopeJudgement([ProjectScopeDoor::Paths], [ProjectScopeDoor::Paths], [$verdict]);

        self::assertSame($verdict, $open->excludeSelectors()[0]);
        self::assertSame($verdict, $closed->excludeSelectors()[0]);
    }

    private static function verdict(ExcludeSelectorOutcome $outcome): ExcludeSelectorVerdict
    {
        $source = self::installed();
        $pattern = new PathPattern(new SelectorDefinition(SelectorKind::Subtree, 'src/Legacy'));

        return match ($outcome) {
            ExcludeSelectorOutcome::Removed => ExcludeSelectorVerdict::fromMeasuredFacts($pattern, [$source], ['src/Legacy'], ['src/Legacy'], null, null, [], true),
            ExcludeSelectorOutcome::Unmatched => ExcludeSelectorVerdict::fromMeasuredFacts($pattern, [$source], [], [], null, null, [], true),
            ExcludeSelectorOutcome::CoveredBySameSource => ExcludeSelectorVerdict::fromMeasuredFacts($pattern, [$source], [], [], null, null, self::hiddenBy($source), true),
            ExcludeSelectorOutcome::CoveredByOtherSource => ExcludeSelectorVerdict::fromMeasuredFacts($pattern, [$source], [], [], null, null, self::hiddenBy(self::project()), true),
            ExcludeSelectorOutcome::Unjudgeable => ExcludeSelectorVerdict::fromMeasuredFacts($pattern, [$source], [], [], null, 'src/Legacy', [], false),
            ExcludeSelectorOutcome::NotJudged => ExcludeSelectorVerdict::fromMeasuredFacts($pattern, [$source], [], [], null, null, [], false),
            ExcludeSelectorOutcome::InstalledWriter => self::verdict(ExcludeSelectorOutcome::Unmatched)->withInstalledWriter(),
        };
    }

    /** @return list<array{directory: string, selector: string, sources: non-empty-list<ConfigurationOrigin>}> */
    private static function hiddenBy(ConfigurationOrigin $source): array
    {
        return [['directory' => 'src', 'selector' => 'subtree:src', 'sources' => [$source]]];
    }

    private static function writers(ConfigurationOrigin $first, ConfigurationOrigin ...$rest): ConfiguredValueWriters
    {
        return new ConfiguredValueWriters([$first, ...array_values($rest)]);
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
