<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Analysis\Finding\Unit\Contract\ProjectScope;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Configuration\Contract\Refusal\ConfigurationOrigin;
use Qualimetrix\Analysis\Configuration\Contract\Refusal\ConfigurationSource;
use Qualimetrix\Analysis\Finding\Contract\ProjectScope\ExcludeSelectorVerdict;
use Qualimetrix\Analysis\Finding\Contract\ProjectScope\ProjectScopeDoor;
use Qualimetrix\Analysis\Finding\Contract\ProjectScope\ProjectScopeJudgement;
use Qualimetrix\Analysis\Finding\Contract\ProjectScope\RemovedCodeNamespaces;
use Qualimetrix\Core\Pattern\PathPattern;
use Qualimetrix\Core\Pattern\SelectorDefinition;
use Qualimetrix\Core\Pattern\SelectorKind;

#[CoversClass(ProjectScopeJudgement::class)]
final class ProjectScopeJudgementLiteralTest extends TestCase
{
    #[Test]
    public function itJudgesEveryLiteralWhenNoDoorWithholds(): void
    {
        $scope = new ProjectScopeJudgement();

        self::assertTrue($scope->judgesNamespaceLiteral('App', true));
        self::assertTrue($scope->judgesNamespaceLiteral('App', false));
    }

    #[Test]
    public function itJudgesALiteralTheRemovedCodeCannotReach(): void
    {
        $scope = $this->excluded('php-file', RemovedCodeNamespaces::known(['App\\Foo\\Bar']));

        self::assertTrue($scope->judgesNamespaceLiteral('App\\Does\\Not\\Exist', false));
        self::assertTrue($scope->judgesNamespaceLiteral('App\\Baz', true));
        self::assertFalse($scope->judgesNamespaceClaims());
    }

    #[Test]
    public function itWithholdsALiteralTheRemovedCodeCouldHaveDeclared(): void
    {
        $scope = $this->excluded('php-file', RemovedCodeNamespaces::known(['App\\Foo\\Bar']));

        self::assertFalse($scope->judgesNamespaceLiteral('App', true));
        self::assertFalse($scope->judgesNamespaceLiteral('App\\Foo', true));
        self::assertFalse($scope->judgesNamespaceLiteral('App\\Foo', false));
        self::assertFalse($scope->judgesNamespaceLiteral('app\\foo\\bar', false));
    }

    #[Test]
    public function itTreatsAbsentRemovedNamespacesAsUnknown(): void
    {
        $verdict = $this->verdict('php-file');
        $scope = new ProjectScopeJudgement([ProjectScopeDoor::Exclude], [], [$verdict]);

        self::assertFalse($scope->judgesNamespaceLiteral('App\\Does\\Not\\Exist', false));
        self::assertFalse((new ProjectScopeJudgement([ProjectScopeDoor::Generated]))->judgesNamespaceLiteral('Zzz', false));
    }

    #[Test]
    public function itWithholdsWhenRemovedNamespacesAreUnknown(): void
    {
        $scope = $this->excluded('php-file', RemovedCodeNamespaces::unknown());

        self::assertFalse($scope->judgesNamespaceLiteral('App\\Does\\Not\\Exist', false));
    }

    #[Test]
    public function itWithholdsWhenARemovedDirectoryCouldNotBeListed(): void
    {
        $scope = $this->excluded('unlistable', RemovedCodeNamespaces::none());

        self::assertFalse($scope->judgesNamespaceLiteral('App\\Does\\Not\\Exist', false));
    }

    #[Test]
    public function itWithholdsWhenAnUnlistableSelectorSitsBesideAPhpFileOne(): void
    {
        $scope = new ProjectScopeJudgement(
            [ProjectScopeDoor::Exclude],
            [],
            [$this->verdict('php-file', 'src/A'), $this->verdict('unlistable', 'src/B')],
            RemovedCodeNamespaces::none(),
        );

        self::assertFalse($scope->judgesNamespaceLiteral('Zzz', false));
    }

    #[Test]
    public function itWithholdsAnExcludeDoorWithoutPhpEvidence(): void
    {
        $scope = new ProjectScopeJudgement([ProjectScopeDoor::Exclude], [], [], RemovedCodeNamespaces::none());

        self::assertFalse($scope->judgesNamespaceLiteral('Zzz', false));
    }

    #[Test]
    public function itSetsGeneratedRemovalAsideLikeExcludedPhpFiles(): void
    {
        $scope = new ProjectScopeJudgement([ProjectScopeDoor::Generated], [], [], RemovedCodeNamespaces::known(['Gen\\Proxy']));

        self::assertTrue($scope->judgesNamespaceLiteral('App\\Gone', false));
        self::assertFalse($scope->judgesNamespaceLiteral('Gen', true));
    }

    /** @return iterable<string, array{list<ProjectScopeDoor>}> */
    public static function provideWithholdingDoors(): iterable
    {
        yield 'paths' => [[ProjectScopeDoor::Paths]];
        yield 'unknown universe' => [[ProjectScopeDoor::UnknownUniverse]];
        yield 'paths beside exclude' => [[ProjectScopeDoor::Paths, ProjectScopeDoor::Exclude]];
        yield 'unknown universe beside generated' => [[ProjectScopeDoor::UnknownUniverse, ProjectScopeDoor::Generated]];
    }

    /** @param list<ProjectScopeDoor> $doors */
    #[Test]
    #[DataProvider('provideWithholdingDoors')]
    public function itWithholdsUnderAnyDoorBeyondExcludedOrGeneratedPhp(array $doors): void
    {
        $selectorDoors = array_values(array_filter(
            $doors,
            static fn(ProjectScopeDoor $door): bool => $door === ProjectScopeDoor::Paths || $door === ProjectScopeDoor::UnknownUniverse,
        ));
        $scope = new ProjectScopeJudgement($doors, $selectorDoors, [], RemovedCodeNamespaces::known(['Other']));
        $withEvidence = new ProjectScopeJudgement($doors, $selectorDoors, [$this->verdict('php-file')], RemovedCodeNamespaces::known(['Other']));

        self::assertFalse($scope->judgesNamespaceLiteral('App\\Gone', false));
        self::assertFalse($withEvidence->judgesNamespaceLiteral('App\\Gone', false));
    }

    private function excluded(string $evidence, RemovedCodeNamespaces $removed): ProjectScopeJudgement
    {
        return new ProjectScopeJudgement([ProjectScopeDoor::Exclude], [], [$this->verdict($evidence)], $removed);
    }

    private function verdict(string $evidence, string $entry = 'src/Legacy'): ExcludeSelectorVerdict
    {
        return ExcludeSelectorVerdict::fromMeasuredFacts(
            new PathPattern(new SelectorDefinition(SelectorKind::Subtree, $entry)),
            [ConfigurationOrigin::of(ConfigurationSource::ConfigFile, 'qmx.yaml')],
            [$entry],
            [$entry],
            $evidence,
            null,
            [],
            true,
        );
    }
}
