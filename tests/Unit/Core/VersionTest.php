<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Unit\Core;

use Composer\InstalledVersions;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Core\Version;
use ReflectionClass;

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

    /**
     * Guards the actual defect, which no value assertion can catch here.
     *
     * `InstalledVersions::getRootPackage()` returns the *consuming project*,
     * so a dependent installation reported that project's version — observed
     * in the wild as "1.0.0+no-version-set" while the package was v0.20.0.
     * Inside this repository the root package IS qualimetrix/qualimetrix, so
     * both the correct and the broken implementation return `dev-main` and the
     * assertions above pass either way. Pinning the source is what makes the
     * regression detectable without installing the package elsewhere.
     */
    #[Test]
    public function itDoesNotResolveTheVersionThroughTheRootPackage(): void
    {
        $file = (new ReflectionClass(Version::class))->getFileName();
        self::assertIsString($file);

        $source = file_get_contents($file);
        self::assertIsString($source);

        self::assertStringNotContainsString(
            'getRootPackage',
            $source,
            'Version must resolve by package name; the root package is the consuming project.',
        );
    }
}
