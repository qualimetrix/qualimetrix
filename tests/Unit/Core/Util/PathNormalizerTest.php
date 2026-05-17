<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Unit\Core\Util;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Core\Util\PathNormalizer;

#[CoversClass(PathNormalizer::class)]
final class PathNormalizerTest extends TestCase
{
    #[Test]
    public function itStripsDotSlashPrefix(): void
    {
        self::assertSame('src/Foo.php', PathNormalizer::relativize('./src/Foo.php'));
    }

    #[Test]
    public function itDoesNotStripDotSlashFromMiddle(): void
    {
        self::assertSame('src/./Foo.php', PathNormalizer::relativize('src/./Foo.php'));
    }

    #[Test]
    public function itRelativizesAbsolutePathAgainstCwd(): void
    {
        $cwd = (string) getcwd();
        $absolutePath = $cwd . '/src/Foo.php';

        self::assertSame('src/Foo.php', PathNormalizer::relativize($absolutePath));
    }

    #[Test]
    public function itRelativePathPassesThroughUnchanged(): void
    {
        self::assertSame('src/Foo.php', PathNormalizer::relativize('src/Foo.php'));
    }

    #[Test]
    public function itAbsolutePathOutsideCwdPassesThrough(): void
    {
        // A path that is definitely not under CWD
        $path = '/tmp/completely/different/path.php';

        // Should remain unchanged since it doesn't start with CWD prefix
        self::assertSame($path, PathNormalizer::relativize($path));
    }

    #[Test]
    public function itDotSlashOnlyBecomesEmpty(): void
    {
        // Edge case: "./" becomes ""
        self::assertSame('', PathNormalizer::relativize('./'));
    }

    #[Test]
    public function itAbsoluteCwdPathWithTrailingSlash(): void
    {
        $cwd = (string) getcwd();
        // Path that exactly equals CWD + "/" — should strip the prefix
        $absolutePath = $cwd . '/a.php';

        self::assertSame('a.php', PathNormalizer::relativize($absolutePath));
    }
}
