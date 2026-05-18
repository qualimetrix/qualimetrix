<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Unit\Core\Path;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Core\Path\AbsolutePath;
use Qualimetrix\Core\Path\PathFactory;

#[CoversClass(PathFactory::class)]
final class PathFactoryTest extends TestCase
{
    #[Test]
    public function itResolvesAbsolutePathUnderProjectRoot(): void
    {
        $root = AbsolutePath::fromString('/project');

        self::assertSame(
            'src/Foo.php',
            PathFactory::projectRelative('/project/src/Foo.php', $root)->value(),
        );
    }

    #[Test]
    public function itPassesRelativePathThrough(): void
    {
        $root = AbsolutePath::fromString('/project');

        self::assertSame('src/Foo.php', PathFactory::projectRelative('src/Foo.php', $root)->value());
    }

    #[Test]
    public function itThrowsWhenAbsoluteIsOutsideProjectRoot(): void
    {
        $this->expectException(InvalidArgumentException::class);
        PathFactory::projectRelative('/elsewhere/Foo.php', AbsolutePath::fromString('/project'));
    }

    #[Test]
    public function itReturnsNullFromTryProjectRelativeWhenOutOfBase(): void
    {
        self::assertNull(
            PathFactory::tryProjectRelative('/elsewhere/Foo.php', AbsolutePath::fromString('/project')),
        );
    }

    #[Test]
    public function itTranslatesGitPathInsideProjectRoot(): void
    {
        $gitToplevel = AbsolutePath::fromString('/repo');
        $projectRoot = AbsolutePath::fromString('/repo/sub-project');

        self::assertSame(
            'src/Foo.php',
            PathFactory::gitRelative('sub-project/src/Foo.php', $gitToplevel, $projectRoot)?->value(),
        );
    }

    #[Test]
    public function itReturnsNullFromGitRelativeForOutOfProjectPath(): void
    {
        $gitToplevel = AbsolutePath::fromString('/repo');
        $projectRoot = AbsolutePath::fromString('/repo/sub-project');

        self::assertNull(PathFactory::gitRelative('other/Foo.php', $gitToplevel, $projectRoot));
    }

    #[Test]
    public function itReturnsNullFromGitRelativeWhenPathEqualsProjectRoot(): void
    {
        // Project root maps to no project-relative path; equivalent to "the root itself".
        $gitToplevel = AbsolutePath::fromString('/repo');
        $projectRoot = AbsolutePath::fromString('/repo/sub-project');

        self::assertNull(PathFactory::gitRelative('sub-project', $gitToplevel, $projectRoot));
    }

    #[Test]
    public function itReturnsNullFromGitRelativeOnEmptyInput(): void
    {
        self::assertNull(
            PathFactory::gitRelative(
                '',
                AbsolutePath::fromString('/repo'),
                AbsolutePath::fromString('/repo'),
            ),
        );
    }

    #[Test]
    public function itPassesAbsoluteCliArgumentThrough(): void
    {
        $cwd = AbsolutePath::fromString('/cwd');

        self::assertSame('/abs/foo', PathFactory::fromCliArgument('/abs/foo', $cwd)->value());
    }

    #[Test]
    public function itResolvesRelativeCliArgumentAgainstCwd(): void
    {
        $cwd = AbsolutePath::fromString('/project');

        self::assertSame('/project/src/Foo', PathFactory::fromCliArgument('src/Foo', $cwd)->value());
    }

    #[Test]
    public function itResolvesDotCliArgumentToCwd(): void
    {
        $cwd = AbsolutePath::fromString('/project');

        self::assertSame('/project', PathFactory::fromCliArgument('.', $cwd)->value());
        self::assertSame('/project', PathFactory::fromCliArgument('./', $cwd)->value());
    }

    #[Test]
    public function itResolvesParentDirCliArgument(): void
    {
        // Regression: `qmx check ..` and `qmx check ../sibling` must work from a
        // subdir. RelativePath would reject `..` as out-of-base, so PathFactory
        // routes non-absolute CLI input through AbsolutePath's lexical resolver.
        $cwd = AbsolutePath::fromString('/project/subdir');

        self::assertSame('/project', PathFactory::fromCliArgument('..', $cwd)->value());
        self::assertSame('/project/sibling', PathFactory::fromCliArgument('../sibling', $cwd)->value());
    }

    #[Test]
    public function itRejectsEmptyCliArgument(): void
    {
        $this->expectException(InvalidArgumentException::class);
        PathFactory::fromCliArgument('', AbsolutePath::fromString('/project'));
    }

    #[Test]
    public function itPreservesSymlinkInResolvedCliArgument(): void
    {
        // fromCliArgument does NOT call realpath(); symlink resolution is opt-in via canonicalize().
        // realpath() the temp base first so macOS's /var → /private/var symlink doesn't skew comparisons.
        $tmpBase = realpath(sys_get_temp_dir());
        self::assertIsString($tmpBase);

        $linkPath = $tmpBase . '/qmx-test-' . uniqid('', true);
        $target = $linkPath . '-target';

        mkdir($target);
        symlink($target, $linkPath);

        try {
            $tmpDir = AbsolutePath::fromString($tmpBase);
            $resolved = PathFactory::fromCliArgument(basename($linkPath), $tmpDir);

            self::assertSame($linkPath, $resolved->value());
            self::assertSame($target, $resolved->canonicalize()->value());
        } finally {
            unlink($linkPath);
            rmdir($target);
        }
    }
}
