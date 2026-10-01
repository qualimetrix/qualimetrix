<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Unit\Core\Path;

use InvalidArgumentException;
use LogicException;
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
    public function itPublishesTheNamedFileWithoutResolvingTheFinalSymlink(): void
    {
        $root = realpath(sys_get_temp_dir()) . '/qmx-published-' . bin2hex(random_bytes(6));
        mkdir($root . '/src', 0777, true);
        mkdir($root . '/target');
        file_put_contents($root . '/target/Actual.php', '<?php');
        symlink('../target/Actual.php', $root . '/src/Named.php');

        try {
            self::assertSame(
                'src/Named.php',
                PathFactory::published(AbsolutePath::fromString($root . '/src/Named.php'), AbsolutePath::fromString($root))->value(),
            );
        } finally {
            unlink($root . '/src/Named.php');
            unlink($root . '/target/Actual.php');
            rmdir($root . '/src');
            rmdir($root . '/target');
            rmdir($root);
        }
    }

    #[Test]
    public function itPublishesALiteralBackslashInTheFinalFilename(): void
    {
        $root = realpath(sys_get_temp_dir()) . '/qmx-published-' . bin2hex(random_bytes(6));
        mkdir($root . '/src', 0777, true);
        file_put_contents($root . '/src/a\\b.php', '<?php');

        try {
            self::assertSame(
                'src/a\\b.php',
                PathFactory::published(AbsolutePath::fromString($root . '/src/a\\b.php'), AbsolutePath::fromString($root))->value(),
            );
        } finally {
            unlink($root . '/src/a\\b.php');
            rmdir($root . '/src');
            rmdir($root);
        }
    }

    #[Test]
    public function itPublishesAFileLinkWhoseTargetLiesOutsideTheRoot(): void
    {
        $base = realpath(sys_get_temp_dir()) . '/qmx-published-' . bin2hex(random_bytes(6));
        mkdir($base . '/root/src', 0777, true);
        mkdir($base . '/outside');
        file_put_contents($base . '/outside/Actual.php', '<?php');
        symlink('../../outside/Actual.php', $base . '/root/src/Named.php');

        try {
            self::assertSame(
                'src/Named.php',
                PathFactory::published(
                    AbsolutePath::fromString($base . '/root/src/Named.php'),
                    AbsolutePath::fromString($base . '/root'),
                )->value(),
            );
        } finally {
            unlink($base . '/root/src/Named.php');
            unlink($base . '/outside/Actual.php');
            rmdir($base . '/root/src');
            rmdir($base . '/root');
            rmdir($base . '/outside');
            rmdir($base);
        }
    }

    #[Test]
    public function itRefusesToPublishAFileWhoseParentResolvesOutsideTheRoot(): void
    {
        $base = realpath(sys_get_temp_dir()) . '/qmx-published-' . bin2hex(random_bytes(6));
        mkdir($base . '/root', 0777, true);
        mkdir($base . '/outside');
        symlink($base . '/outside', $base . '/root/escape');

        try {
            $this->expectException(LogicException::class);
            PathFactory::published(
                AbsolutePath::fromString($base . '/root/escape/Named.php'),
                AbsolutePath::fromString($base . '/root'),
            );
        } finally {
            unlink($base . '/root/escape');
            rmdir($base . '/root');
            rmdir($base . '/outside');
            rmdir($base);
        }
    }

    #[Test]
    public function itRechecksADirectoryLinkAfterItChangesTarget(): void
    {
        $base = realpath(sys_get_temp_dir()) . '/qmx-published-' . bin2hex(random_bytes(6));
        mkdir($base . '/root/inside', 0777, true);
        mkdir($base . '/outside');
        symlink($base . '/root/inside', $base . '/root/alias');
        $file = AbsolutePath::fromString($base . '/root/alias/Named.php');
        $root = AbsolutePath::fromString($base . '/root');

        try {
            self::assertSame('inside/Named.php', PathFactory::published($file, $root)->value());
            unlink($base . '/root/alias');
            symlink($base . '/outside', $base . '/root/alias');
            clearstatcache(true);

            $this->expectException(LogicException::class);
            PathFactory::published($file, $root);
        } finally {
            unlink($base . '/root/alias');
            rmdir($base . '/root/inside');
            rmdir($base . '/root');
            rmdir($base . '/outside');
            rmdir($base);
        }
    }

    #[Test]
    public function itTryProjectRelativeReturnsNullForRelativeWithLeadingDotDot(): void
    {
        // Phase 6 review MEDIUM: tryProjectRelative was asymmetric — returned null for
        // absolute paths outside the base but threw for relative inputs that would
        // escape via leading "..". Now uniform: returns null in both cases.
        self::assertNull(
            PathFactory::tryProjectRelative('../escapes/Foo.php', AbsolutePath::fromString('/project')),
        );
    }

    #[Test]
    public function itPublishesFileInsideProjectRoot(): void
    {
        $root = realpath(sys_get_temp_dir()) . '/qmx-published-' . bin2hex(random_bytes(6));
        mkdir($root . '/src', 0777, true);

        try {
            self::assertSame(
                'src/Foo.php',
                PathFactory::published(AbsolutePath::fromString($root . '/src/Foo.php'), AbsolutePath::fromString($root))->value(),
            );
        } finally {
            rmdir($root . '/src');
            rmdir($root);
        }
    }

    #[Test]
    public function itRefusesDistinctFilesOutsideTheRoot(): void
    {
        $base = realpath(sys_get_temp_dir()) . '/qmx-published-' . bin2hex(random_bytes(6));
        mkdir($base . '/root', 0777, true);
        mkdir($base . '/outside/lib', 0777, true);
        mkdir($base . '/outside/api');
        $root = AbsolutePath::fromString($base . '/root');

        try {
            foreach (['lib', 'api'] as $directory) {
                try {
                    PathFactory::published(AbsolutePath::fromString($base . '/outside/' . $directory . '/Foo.php'), $root);
                    self::fail('Outside files must be refused.');
                } catch (LogicException $e) {
                    self::assertStringContainsString('outside project root', $e->getMessage());
                }
            }
        } finally {
            rmdir($base . '/outside/lib');
            rmdir($base . '/outside/api');
            rmdir($base . '/outside');
            rmdir($base . '/root');
            rmdir($base);
        }
    }

    #[Test]
    public function itRefusesAnOutsideFileAfterLexicalParentCollapse(): void
    {
        $base = realpath(sys_get_temp_dir()) . '/qmx-published-' . bin2hex(random_bytes(6));
        mkdir($base . '/root', 0777, true);
        mkdir($base . '/outside/lib/sub', 0777, true);

        try {
            $this->expectException(LogicException::class);
            PathFactory::published(
                AbsolutePath::fromString($base . '/outside/lib/sub/../Foo.php'),
                AbsolutePath::fromString($base . '/root'),
            );
        } finally {
            rmdir($base . '/outside/lib/sub');
            rmdir($base . '/outside/lib');
            rmdir($base . '/outside');
            rmdir($base . '/root');
            rmdir($base);
        }
    }

    #[Test]
    public function itRefusesAFileThatEscapesTheRootLexically(): void
    {
        $base = realpath(sys_get_temp_dir()) . '/qmx-published-' . bin2hex(random_bytes(6));
        mkdir($base . '/root', 0777, true);

        try {
            $this->expectException(LogicException::class);
            PathFactory::published(
                AbsolutePath::fromString($base . '/root/../Foo.php'),
                AbsolutePath::fromString($base . '/root'),
            );
        } finally {
            rmdir($base . '/root');
            rmdir($base);
        }
    }

    #[Test]
    public function itRefusesAFileWithAnUnresolvableParent(): void
    {
        $root = realpath(sys_get_temp_dir()) . '/qmx-published-' . bin2hex(random_bytes(6));
        mkdir($root);

        try {
            $this->expectException(LogicException::class);
            PathFactory::published(
                AbsolutePath::fromString($root . '/missing/Foo.php'),
                AbsolutePath::fromString($root),
            );
        } finally {
            rmdir($root);
        }
    }

    #[Test]
    public function itPublishesThroughASymlinkedDirectory(): void
    {
        $tmpBase = realpath(sys_get_temp_dir());
        self::assertIsString($tmpBase);

        $target = $tmpBase . '/qmx-besteffort-target-' . bin2hex(random_bytes(6));
        $link = $tmpBase . '/qmx-besteffort-link-' . bin2hex(random_bytes(6));

        mkdir($target);
        mkdir($target . '/src');
        $realFile = $target . '/src/Foo.php';
        file_put_contents($realFile, '<?php');
        symlink($target, $link);

        try {
            $linkedFile = $link . '/src/Foo.php';
            $root = AbsolutePath::fromString($target); // canonicalized projectRoot

            self::assertSame(
                'src/Foo.php',
                PathFactory::published(AbsolutePath::fromString($linkedFile), $root)->value(),
            );
        } finally {
            unlink($realFile);
            unlink($link);
            rmdir($target . '/src');
            rmdir($target);
        }
    }

    #[Test]
    public function itPreservesSymlinkInResolvedCliArgument(): void
    {
        // fromCliArgument does NOT call realpath(); symlink resolution is opt-in via canonicalize().
        // realpath() the temp base first so macOS's /var → /private/var symlink doesn't skew comparisons.
        $tmpBase = realpath(sys_get_temp_dir());
        self::assertIsString($tmpBase);

        $linkPath = $tmpBase . '/qmx-test-' . bin2hex(random_bytes(6));
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
