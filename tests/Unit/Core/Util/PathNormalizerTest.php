<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Unit\Core\Util;

use InvalidArgumentException;
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
    public function itStripsDotSegmentsFromMiddle(): void
    {
        // Lexical normalization: "src/./Foo.php" and "src/Foo.php" are
        // semantically the same path. Collapsing the no-op "./" keeps this
        // normalizer in sync with RelativePath::normalize so suppression keys
        // and violation paths compare equal.
        self::assertSame('src/Foo.php', PathNormalizer::relativize('src/./Foo.php'));
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
    public function itAbsolutePathOutsideCwdHasLeadingSlashStripped(): void
    {
        // A path that is definitely not under CWD: cannot be relativized against
        // the project root. The fallback strips the leading "/" so downstream
        // RelativePath VOs (ADR 0015) can construct from the result.
        $path = '/tmp/completely/different/path.php';

        self::assertSame('tmp/completely/different/path.php', PathNormalizer::relativize($path));
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

    #[Test]
    public function itRejectsLeadingDotDotThatEscapesProjectRoot(): void
    {
        // Mirrors RelativePath::normalize's invariant: a path that resolves to
        // a leading ".." after relativization is outside the project and would
        // otherwise be silently coerced into a same-named in-project path
        // (e.g. "../secret.php" → "secret.php"), masking the out-of-project
        // signal.
        self::expectException(InvalidArgumentException::class);
        self::expectExceptionMessage('escapes the project root');

        PathNormalizer::relativize('../outside.php');
    }

    #[Test]
    public function itRejectsLeadingDotDotAfterAbsoluteStrip(): void
    {
        // `/../secret.php` → ltrim → `../secret.php` → still escapes; must throw
        // rather than silently produce `secret.php`.
        self::expectException(InvalidArgumentException::class);

        PathNormalizer::relativize('/../secret.php');
    }
}
