<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Unit\Core\Util;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Core\Util\PathNormalizer;

#[CoversClass(PathNormalizer::class)]
final class PathNormalizerTest extends TestCase
{
    public function testStripsDotSlashPrefix(): void
    {
        self::assertSame('src/Foo.php', PathNormalizer::relativize('./src/Foo.php'));
    }

    public function testDoesNotStripDotSlashFromMiddle(): void
    {
        self::assertSame('src/./Foo.php', PathNormalizer::relativize('src/./Foo.php'));
    }

    public function testRelativizesAbsolutePathAgainstCwd(): void
    {
        $cwd = (string) getcwd();
        $absolutePath = $cwd . '/src/Foo.php';

        self::assertSame('src/Foo.php', PathNormalizer::relativize($absolutePath));
    }

    public function testRelativePathPassesThroughUnchanged(): void
    {
        self::assertSame('src/Foo.php', PathNormalizer::relativize('src/Foo.php'));
    }

    public function testAbsolutePathOutsideCwdPassesThrough(): void
    {
        // A path that is definitely not under CWD
        $path = '/tmp/completely/different/path.php';

        // Should remain unchanged since it doesn't start with CWD prefix
        self::assertSame($path, PathNormalizer::relativize($path));
    }

    public function testDotSlashOnlyBecomesEmpty(): void
    {
        // Edge case: "./" becomes ""
        self::assertSame('', PathNormalizer::relativize('./'));
    }

    public function testAbsoluteCwdPathWithTrailingSlash(): void
    {
        $cwd = (string) getcwd();
        // Path that exactly equals CWD + "/" — should strip the prefix
        $absolutePath = $cwd . '/a.php';

        self::assertSame('a.php', PathNormalizer::relativize($absolutePath));
    }
}
