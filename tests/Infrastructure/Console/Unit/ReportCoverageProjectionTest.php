<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Infrastructure\Console\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Core\Path\AbsolutePath;
use Qualimetrix\Infrastructure\Console\ReportCoverageProjection;

#[CoversClass(ReportCoverageProjection::class)]
final class ReportCoverageProjectionTest extends TestCase
{
    /**
     * A file inside the project is shown relative, because the rest of the
     * report is; a dependency outside it has no relative form and keeps the
     * absolute one.
     */
    #[Test]
    public function itRelativizesProjectPathsInAFailureMessageAndKeepsOutsidersAbsolute(): void
    {
        $projectRoot = '/project';
        $outsider = '/elsewhere/vendor/Dependency.php';

        $relativized = ReportCoverageProjection::failureMessage(
            'Parse error in ' . $projectRoot . '/src/Broken.php; dependency ' . $outsider,
            AbsolutePath::fromString($projectRoot),
        );

        self::assertStringNotContainsString($projectRoot . '/', $relativized);
        self::assertStringContainsString('src/Broken.php', $relativized);
        self::assertStringContainsString($outsider, $relativized);
    }
}
