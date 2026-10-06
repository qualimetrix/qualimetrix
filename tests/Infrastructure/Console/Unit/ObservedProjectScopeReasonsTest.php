<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Infrastructure\Console\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\ProjectManifest\Contract\ManifestReadState;
use Qualimetrix\Analysis\ProjectManifest\Contract\ManifestSnapshotControlInterface;
use Qualimetrix\Core\Path\AbsolutePath;
use Qualimetrix\Infrastructure\Composer\Contract\AnalysedInstallAnchorInterface;
use Qualimetrix\Infrastructure\Composer\Contract\ComposerRootOmission;
use Qualimetrix\Infrastructure\Console\ObservedProjectScopeReasons;

#[CoversClass(ObservedProjectScopeReasons::class)]
final class ObservedProjectScopeReasonsTest extends TestCase
{
    #[Test]
    public function itDoesNotPublishAnAbsoluteCandidateOutsideTheProject(): void
    {
        $snapshot = $this->createMock(ManifestSnapshotControlInterface::class);
        $snapshot->method('observedIssues')->willReturn([]);
        $anchor = $this->createMock(AnalysedInstallAnchorInterface::class);
        $anchor->method('observedRootOmissions')->willReturn([
            ComposerRootOmission::unresolvable('/private/tmp/secret-external/src'),
        ]);
        $reasons = (new ObservedProjectScopeReasons($snapshot, $anchor))->forMainSource('/project/composer.json', ManifestReadState::Read, AbsolutePath::fromString('/project'));
        self::assertCount(1, $reasons);
        self::assertStringNotContainsString('/private/tmp/secret-external', json_encode($reasons[0]->toArray(), \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES));
    }

    #[Test]
    public function itPublishesOnlyProjectRelativeOmissionLocations(): void
    {
        $snapshot = $this->createMock(ManifestSnapshotControlInterface::class);
        $snapshot->method('observedIssues')->willReturn([]);
        $anchor = $this->createMock(AnalysedInstallAnchorInterface::class);
        $anchor->method('observedRootOmissions')->willReturn([
            ComposerRootOmission::walkLimitReached('/project/src', '/project/src', '/private/tmp/outside', 12),
        ]);
        $reasons = (new ObservedProjectScopeReasons($snapshot, $anchor))->forMainSource('/project/composer.json', ManifestReadState::Read, AbsolutePath::fromString('/project'));
        self::assertSame(['kind' => 'omitted-composer-root', 'cause' => 'walk-limit', 'visitedLevels' => 12, 'candidate' => 'src', 'startDirectory' => 'src'], $reasons[0]->toArray());
    }

    #[Test]
    public function itOmitsRootSearchReasonsWhenTheManifestIsAbsent(): void
    {
        $snapshot = $this->createMock(ManifestSnapshotControlInterface::class);
        $snapshot->method('observedIssues')->willReturn([]);
        $anchor = $this->createMock(AnalysedInstallAnchorInterface::class);
        $anchor->method('observedRootOmissions')->willReturn([ComposerRootOmission::unresolvable('/project/src')]);
        self::assertSame([], (new ObservedProjectScopeReasons($snapshot, $anchor))->forMainSource('/project/composer.json', ManifestReadState::Absent, AbsolutePath::fromString('/project')));
    }
}
