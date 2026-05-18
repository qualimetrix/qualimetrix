<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Unit\Core\Path;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Core\Path\RelativePath;

#[CoversClass(RelativePath::class)]
final class RelativePathTest extends TestCase
{
    #[Test]
    public function itAcceptsValidRelativePath(): void
    {
        $path = RelativePath::fromString('src/Foo.php');

        self::assertSame('src/Foo.php', $path->value());
        self::assertSame('src/Foo.php', (string) $path);
    }

    #[Test]
    public function itStripsLeadingDotSlash(): void
    {
        self::assertSame('src/Foo.php', RelativePath::fromString('./src/Foo.php')->value());
    }

    #[Test]
    public function itReplacesWindowsSeparators(): void
    {
        self::assertSame('src/Foo.php', RelativePath::fromString('src\\Foo.php')->value());
    }

    #[Test]
    public function itResolvesInteriorParentSegments(): void
    {
        self::assertSame('b', RelativePath::fromString('a/../b')->value());
    }

    #[Test]
    public function itRejectsEmptyString(): void
    {
        $this->expectException(InvalidArgumentException::class);
        RelativePath::fromString('');
    }

    #[Test]
    public function itRejectsAbsolutePath(): void
    {
        $this->expectException(InvalidArgumentException::class);
        RelativePath::fromString('/abs/path');
    }

    #[Test]
    public function itRejectsPureDot(): void
    {
        $this->expectException(InvalidArgumentException::class);
        RelativePath::fromString('.');
    }

    #[Test]
    public function itRejectsLeadingParent(): void
    {
        $this->expectException(InvalidArgumentException::class);
        RelativePath::fromString('../escape');
    }

    #[Test]
    public function itRejectsParentNetEscape(): void
    {
        // After lexical resolution this still escapes: a/../.. → ..
        $this->expectException(InvalidArgumentException::class);
        RelativePath::fromString('a/../..');
    }

    #[Test]
    public function itComparesEqualPathsStructurally(): void
    {
        self::assertTrue(
            RelativePath::fromString('src/Foo.php')->equals(RelativePath::fromString('./src/Foo.php')),
        );
        self::assertFalse(
            RelativePath::fromString('src/Foo.php')->equals(RelativePath::fromString('src/Bar.php')),
        );
    }

    #[Test]
    public function itSplitsIntoSegments(): void
    {
        self::assertSame(['src', 'Sub', 'Foo.php'], RelativePath::fromString('src/Sub/Foo.php')->segments());
    }

    #[Test]
    public function itReturnsParentDirectory(): void
    {
        $parent = RelativePath::fromString('src/Sub/Foo.php')->parent();

        self::assertNotNull($parent);
        self::assertSame('src/Sub', $parent->value());
    }

    #[Test]
    public function itReturnsNullParentForSingleSegment(): void
    {
        self::assertNull(RelativePath::fromString('Foo.php')->parent());
    }

    #[Test]
    public function itReturnsBasename(): void
    {
        self::assertSame('Foo.php', RelativePath::fromString('src/Sub/Foo.php')->basename());
        self::assertSame('Foo', RelativePath::fromString('Foo')->basename());
    }

    #[Test]
    public function itReturnsExtensionOrNull(): void
    {
        self::assertSame('php', RelativePath::fromString('src/Foo.php')->extension());
        self::assertSame('gz', RelativePath::fromString('archive.tar.gz')->extension());
        self::assertNull(RelativePath::fromString('Makefile')->extension());
        // Leading dot — not an extension, the file is a dotfile.
        self::assertNull(RelativePath::fromString('.env')->extension());
    }

    #[Test]
    public function itMatchesPrefixBySegment(): void
    {
        $path = RelativePath::fromString('foo/bar/baz.php');

        self::assertTrue($path->startsWith(RelativePath::fromString('foo')));
        self::assertTrue($path->startsWith(RelativePath::fromString('foo/bar')));
        self::assertTrue($path->startsWith($path));
    }

    #[Test]
    public function itRejectsCharacterLevelPrefix(): void
    {
        // segment-based: 'foobar' must not match prefix 'foo'
        self::assertFalse(
            RelativePath::fromString('foobar/x.php')->startsWith(RelativePath::fromString('foo')),
        );
    }

    #[Test]
    public function itStripsPrefix(): void
    {
        $path = RelativePath::fromString('src/Sub/Foo.php');
        $prefix = RelativePath::fromString('src');

        self::assertSame('Sub/Foo.php', $path->withoutPrefix($prefix)->value());
    }

    #[Test]
    public function itThrowsWhenStrippingNonMatchingPrefix(): void
    {
        $this->expectException(InvalidArgumentException::class);
        RelativePath::fromString('foo/bar')->withoutPrefix(RelativePath::fromString('baz'));
    }

    #[Test]
    public function itReturnsNullFromTryWithoutPrefixOnNoMatch(): void
    {
        self::assertNull(
            RelativePath::fromString('foo/bar')->tryWithoutPrefix(RelativePath::fromString('baz')),
        );
    }

    #[Test]
    public function itReturnsNullFromTryWithoutPrefixWhenEqualToPrefix(): void
    {
        $path = RelativePath::fromString('foo/bar');

        self::assertNull($path->tryWithoutPrefix($path));
    }

    #[Test]
    public function itJoinsAnotherRelativePath(): void
    {
        $a = RelativePath::fromString('src');
        $b = RelativePath::fromString('Sub/Foo.php');

        self::assertSame('src/Sub/Foo.php', $a->join($b)->value());
    }
}
