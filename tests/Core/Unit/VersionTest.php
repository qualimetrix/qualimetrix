<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Core\Unit;

use Composer\InstalledVersions;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Core\Version;

final class VersionTest extends TestCase
{
    #[Test]
    public function itReportsTheVersionOfTheQualimetrixPackage(): void
    {
        self::assertSame(
            InstalledVersions::getPrettyVersion('qualimetrix/qualimetrix'),
            Version::get(),
        );
    }

    #[Test]
    public function itReturnsANonEmptyVersionString(): void
    {
        self::assertNotSame('', Version::get());
    }
}
