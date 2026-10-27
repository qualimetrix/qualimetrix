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
    public function itJudgesEveryLiteralWhenNoDoorWithholdsAndNothingWasRemoved(): void
    {
        $scope = new ProjectScopeJudgement(removedCode: RemovedCodeNamespaces::none());

        self::assertTrue($scope->judgesNamespaceSubtree('App'));
        self::assertTrue($scope->judgesExactNamespace('App'));
        self::assertTrue($scope->removedCodeIsKnown());
    }

    /**
     * A judgement built without the removed code's namespaces knows nothing
     * of them, door or no door: reading that absence as "nothing removed"
     * would judge a literal the uncounted code declares.
     */
    #[Test]
    public function itReadsAbsentRemovedNamespacesAsUnknownWithoutADoor(): void
    {
        $scope = new ProjectScopeJudgement();

        self::assertFalse($scope->judgesExactNamespace('App'));
        self::assertFalse($scope->judgesNamespaceSubtree('App'));
        self::assertFalse($scope->removedCodeIsKnown());
        self::assertTrue($scope->judgesNamespaceClaims());
    }

    /**
     * Code removed outside the measured universe opens no door, yet it still
     * leaves the namespace tree: a literal it may declare is withheld, its
     * neighbour stays judged, and the all-or-nothing claims stay open.
     */
    #[Test]
    public function itConsultsRemovedCodeWhenNoDoorWithholds(): void
    {
        $scope = new ProjectScopeJudgement([], [], [], RemovedCodeNamespaces::known(['App\\Tests\\Legacy']));

        self::assertFalse($scope->judgesExactNamespace('App\\Tests\\Legacy'));
        self::assertFalse($scope->judgesNamespaceSubtree('App\\Tests'));
        self::assertFalse($scope->judgesExactNamespace('App\\Tests'), 'the tree materialises the ancestor too');
        self::assertTrue($scope->judgesExactNamespace('App\\Other'));
        self::assertTrue($scope->judgesNamespaceClaims());
        self::assertTrue($scope->judgesBesideRemovedCode());
    }

    #[Test]
    public function itWithholdsEveryLiteralWhenUnreadRemovedCodeOpensNoDoor(): void
    {
        $scope = new ProjectScopeJudgement([], [], [], RemovedCodeNamespaces::unknown());

        self::assertFalse($scope->judgesExactNamespace('App\\Other'));
        self::assertFalse($scope->judgesNamespaceSubtree('App'));
        self::assertTrue($scope->judgesNamespaceClaims());
    }

    #[Test]
    public function itJudgesALiteralTheRemovedCodeCannotReach(): void
    {
        $scope = $this->excluded('php-file', RemovedCodeNamespaces::known(['App\\Foo\\Bar']));

        self::assertTrue($scope->judgesExactNamespace('App\\Does\\Not\\Exist'));
        self::assertTrue($scope->judgesNamespaceSubtree('App\\Baz'));
        self::assertFalse($scope->judgesNamespaceClaims());
    }

    #[Test]
    public function itWithholdsALiteralTheRemovedCodeCouldHaveDeclared(): void
    {
        $scope = $this->excluded('php-file', RemovedCodeNamespaces::known(['App\\Foo\\Bar']));

        self::assertFalse($scope->judgesNamespaceSubtree('App'));
        self::assertFalse($scope->judgesNamespaceSubtree('App\\Foo'));
        self::assertFalse($scope->judgesExactNamespace('App\\Foo'));
        self::assertFalse($scope->judgesExactNamespace('app\\foo\\bar'));
    }

    #[Test]
    public function itTreatsAbsentRemovedNamespacesAsUnknown(): void
    {
        $verdict = $this->verdict('php-file');
        $scope = new ProjectScopeJudgement([ProjectScopeDoor::Exclude], [], [$verdict]);

        self::assertFalse($scope->judgesExactNamespace('App\\Does\\Not\\Exist'));
        self::assertFalse((new ProjectScopeJudgement([ProjectScopeDoor::Generated]))->judgesExactNamespace('Zzz'));
    }

    #[Test]
    public function itWithholdsWhenRemovedNamespacesAreUnknown(): void
    {
        $scope = $this->excluded('php-file', RemovedCodeNamespaces::unknown());

        self::assertFalse($scope->judgesExactNamespace('App\\Does\\Not\\Exist'));
    }

    #[Test]
    public function itWithholdsWhenARemovedDirectoryCouldNotBeListed(): void
    {
        $scope = $this->excluded('unlistable', RemovedCodeNamespaces::none());

        self::assertFalse($scope->judgesExactNamespace('App\\Does\\Not\\Exist'));
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

        self::assertFalse($scope->judgesExactNamespace('Zzz'));
    }

    #[Test]
    public function itWithholdsAnExcludeDoorWithoutPhpEvidence(): void
    {
        $scope = new ProjectScopeJudgement([ProjectScopeDoor::Exclude], [], [], RemovedCodeNamespaces::none());

        self::assertFalse($scope->judgesExactNamespace('Zzz'));
    }

    #[Test]
    public function itSetsGeneratedRemovalAsideLikeExcludedPhpFiles(): void
    {
        $scope = new ProjectScopeJudgement([ProjectScopeDoor::Generated], [], [], RemovedCodeNamespaces::known(['Gen\\Proxy']));

        self::assertTrue($scope->judgesExactNamespace('App\\Gone'));
        self::assertFalse($scope->judgesNamespaceSubtree('Gen'));
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

        self::assertFalse($scope->judgesExactNamespace('App\\Gone'));
        self::assertFalse($withEvidence->judgesExactNamespace('App\\Gone'));
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
