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
    public function itBestEffortRelativeResolvesInsideProjectRoot(): void
    {
        $root = AbsolutePath::fromString('/project');

        self::assertSame(
            'src/Foo.php',
            PathFactory::bestEffortRelative('/project/src/Foo.php', $root)->value(),
        );
    }

    #[Test]
    public function itBestEffortRelativePreservesStructureForOutOfRootFiles(): void
    {
        // Phase 6 review HIGH: distinct files outside projectRoot must not collide
        // to the same key. Old basename() fallback would have collapsed both to
        // 'Foo.php'; structure-preserving fallback keeps them disambiguated.
        $root = AbsolutePath::fromString('/project');

        $a = PathFactory::bestEffortRelative('/elsewhere/lib/Foo.php', $root)->value();
        $b = PathFactory::bestEffortRelative('/elsewhere/api/Foo.php', $root)->value();

        self::assertSame('elsewhere/lib/Foo.php', $a);
        self::assertSame('elsewhere/api/Foo.php', $b);
    }

    #[Test]
    public function itBestEffortRelativeCollapsesEscapeSegments(): void
    {
        // ".." segments are resolved lexically and any unresolvable leading
        // ".." drops away so RelativePath::fromString never throws. Out-of-root
        // structure is preserved, with the in-line ".." collapsing one level.
        $root = AbsolutePath::fromString('/project');

        self::assertSame(
            'elsewhere/lib/Foo.php',
            PathFactory::bestEffortRelative('/elsewhere/lib/sub/../Foo.php', $root)->value(),
        );
    }

    #[Test]
    public function itBestEffortRelativeDropsLeadingEscapesAfterStrip(): void
    {
        // A path that resolves to leading ".." after strip can't be a valid
        // RelativePath; the helper drops the unresolvable head and keeps the
        // structural remainder.
        $root = AbsolutePath::fromString('/project');

        self::assertSame(
            'Foo.php',
            PathFactory::bestEffortRelative('/../Foo.php', $root)->value(),
        );
    }

    #[Test]
    public function itBestEffortRelativeUsesPlaceholderForFullyCollapsedPath(): void
    {
        // Edge case the helper has to handle for the "never throws" contract:
        // path that collapses to empty after segment resolution.
        $root = AbsolutePath::fromString('/project');

        self::assertSame('unknown', PathFactory::bestEffortRelative('/', $root)->value());
    }

    #[Test]
    public function itBestEffortRelativeCanonicalizesSymlinkedInput(): void
    {
        // Phase 6 review HIGH (symlink asymmetry): StrategySelector canonicalizes
        // $projectRoot via realpath() for cache stability, so a symlinked source
        // file must also canonicalize before relativizing — otherwise it falls
        // into the out-of-root fallback and loses its in-project identity.
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
                PathFactory::bestEffortRelative($linkedFile, $root)->value(),
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
