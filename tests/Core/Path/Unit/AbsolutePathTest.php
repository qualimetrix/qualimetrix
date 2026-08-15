<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Unit\Core\Path;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Core\Path\AbsolutePath;
use Qualimetrix\Core\Path\RelativePath;
use RuntimeException;

#[CoversClass(AbsolutePath::class)]
final class AbsolutePathTest extends TestCase
{
    #[Test]
    public function itAcceptsValidPosixPath(): void
    {
        $path = AbsolutePath::fromString('/usr/local/bin');

        self::assertSame('/usr/local/bin', $path->value());
        self::assertSame('/usr/local/bin', (string) $path);
    }

    #[Test]
    public function itPreservesRootPath(): void
    {
        self::assertSame('/', AbsolutePath::fromString('/')->value());
    }

    #[Test]
    public function itCollapsesDoubleSlashes(): void
    {
        self::assertSame('/a/b', AbsolutePath::fromString('/a//b')->value());
    }

    #[Test]
    public function itResolvesCurrentDirSegments(): void
    {
        self::assertSame('/a/b', AbsolutePath::fromString('/a/./b')->value());
    }

    #[Test]
    public function itResolvesParentSegmentsLexically(): void
    {
        self::assertSame('/a/c', AbsolutePath::fromString('/a/b/../c')->value());
    }

    #[Test]
    public function itStripsTrailingSlash(): void
    {
        self::assertSame('/a/b', AbsolutePath::fromString('/a/b/')->value());
    }

    #[Test]
    public function itCollapsesDotOnlyPathToRoot(): void
    {
        self::assertSame('/', AbsolutePath::fromString('/.')->value());
    }

    #[Test]
    public function itRejectsEmptyString(): void
    {
        $this->expectException(InvalidArgumentException::class);
        AbsolutePath::fromString('');
    }

    #[Test]
    public function itRejectsRelativePath(): void
    {
        $this->expectException(InvalidArgumentException::class);
        AbsolutePath::fromString('relative/path');
    }

    #[Test]
    public function itRejectsWindowsPath(): void
    {
        $this->expectException(InvalidArgumentException::class);
        AbsolutePath::fromString('C:\\src');
    }

    #[Test]
    public function itRejectsParentEscapingRoot(): void
    {
        $this->expectException(InvalidArgumentException::class);
        AbsolutePath::fromString('/..');
    }

    #[Test]
    public function itRejectsDeepParentEscape(): void
    {
        $this->expectException(InvalidArgumentException::class);
        AbsolutePath::fromString('/a/../..');
    }

    #[Test]
    public function itComparesValuesStructurally(): void
    {
        self::assertTrue(
            AbsolutePath::fromString('/a/b')->equals(AbsolutePath::fromString('/a/./b')),
        );
        self::assertFalse(
            AbsolutePath::fromString('/a/b')->equals(AbsolutePath::fromString('/a/c')),
        );
    }

    #[Test]
    public function itRelativizesPathUnderBase(): void
    {
        $base = AbsolutePath::fromString('/project');
        $path = AbsolutePath::fromString('/project/src/Foo.php');

        self::assertSame('src/Foo.php', $path->relativizeTo($base)->value());
    }

    #[Test]
    public function itRelativizesAgainstRoot(): void
    {
        $base = AbsolutePath::fromString('/');
        $path = AbsolutePath::fromString('/usr/bin');

        self::assertSame('usr/bin', $path->relativizeTo($base)->value());
    }

    #[Test]
    public function itThrowsWhenRelativizingOutOfBase(): void
    {
        $this->expectException(InvalidArgumentException::class);
        AbsolutePath::fromString('/elsewhere/file')->relativizeTo(AbsolutePath::fromString('/project'));
    }

    #[Test]
    public function itReturnsNullFromTryRelativizeWhenOutOfBase(): void
    {
        self::assertNull(
            AbsolutePath::fromString('/elsewhere/file')
                ->tryRelativizeTo(AbsolutePath::fromString('/project')),
        );
    }

    #[Test]
    public function itReturnsNullFromTryRelativizeWhenEqualToBase(): void
    {
        $base = AbsolutePath::fromString('/project');

        self::assertNull($base->tryRelativizeTo($base));
    }

    #[Test]
    public function itDoesNotMatchPrefixBoundary(): void
    {
        // /projects must not be treated as under /project
        $base = AbsolutePath::fromString('/project');
        $path = AbsolutePath::fromString('/projects/x.php');

        self::assertNull($path->tryRelativizeTo($base));
    }

    #[Test]
    public function itJoinsRelativeOnto(): void
    {
        $base = AbsolutePath::fromString('/project');
        $tail = RelativePath::fromString('src/Foo.php');

        self::assertSame('/project/src/Foo.php', $base->joinRelative($tail)->value());
    }

    #[Test]
    public function itJoinsRelativeOntoRoot(): void
    {
        $root = AbsolutePath::fromString('/');
        $tail = RelativePath::fromString('usr/bin');

        self::assertSame('/usr/bin', $root->joinRelative($tail)->value());
    }

    #[Test]
    public function itCanonicalizesExistingPath(): void
    {
        $tmp = sys_get_temp_dir();
        $real = realpath($tmp);
        self::assertIsString($real);

        self::assertSame($real, AbsolutePath::fromString($tmp)->canonicalize()->value());
    }

    #[Test]
    public function itThrowsWhenCanonicalizingMissingPath(): void
    {
        $missing = '/this/path/should/not/exist/' . uniqid('qmx-test-', true);

        $this->expectException(RuntimeException::class);
        AbsolutePath::fromString($missing)->canonicalize();
    }

    #[Test]
    public function itReflectsFilesystemPredicates(): void
    {
        $tmp = AbsolutePath::fromString(sys_get_temp_dir());

        self::assertTrue($tmp->exists());
        self::assertFalse($tmp->isFile());
        self::assertTrue($tmp->isDirectory());
    }

    #[Test]
    public function itReportsNonexistentPathAsAbsent(): void
    {
        $missing = AbsolutePath::fromString('/this/path/should/not/exist/' . uniqid('qmx-test-', true));

        self::assertFalse($missing->exists());
        self::assertFalse($missing->isFile());
        self::assertFalse($missing->isDirectory());
    }

    #[Test]
    public function itSerializesToJsonAsBarePathString(): void
    {
        $path = AbsolutePath::fromString('/var/www/project/src/Foo.php');

        self::assertSame('"\/var\/www\/project\/src\/Foo.php"', json_encode($path, \JSON_THROW_ON_ERROR));
        self::assertSame('/var/www/project/src/Foo.php', $path->jsonSerialize());
    }
}
