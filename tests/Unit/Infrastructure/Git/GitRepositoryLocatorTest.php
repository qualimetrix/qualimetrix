<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Unit\Infrastructure\Git;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Core\Path\AbsolutePath;
use Qualimetrix\Infrastructure\Git\GitRepositoryLocator;
use RuntimeException;
use Symfony\Component\Process\Process;

#[CoversClass(GitRepositoryLocator::class)]
final class GitRepositoryLocatorTest extends TestCase
{
    private GitRepositoryLocator $locator;

    /** @var list<string> */
    private array $tempDirs = [];

    protected function setUp(): void
    {
        $this->locator = new GitRepositoryLocator();
    }

    protected function tearDown(): void
    {
        foreach ($this->tempDirs as $dir) {
            if (is_dir($dir)) {
                $this->removeRecursive($dir);
            }
        }
        $this->tempDirs = [];
    }

    #[Test]
    public function findsGitDirInCurrentRepository(): void
    {
        $result = $this->locator->findGitDir();

        self::assertNotNull($result, 'Expected to find .git directory (tests run inside a git repo)');
    }

    #[Test]
    public function returnsAbsolutePath(): void
    {
        $result = $this->locator->findGitDir();

        self::assertNotNull($result);
        self::assertStringStartsWith('/', $result->value(), 'Path should be absolute');
    }

    #[Test]
    public function pathContainsGitReference(): void
    {
        $result = $this->locator->findGitDir();

        self::assertNotNull($result);
        // Regular repos end with .git; worktrees may have .git in the path
        self::assertStringContainsString('.git', $result->value());
    }

    #[Test]
    public function acceptsExplicitWorkingDirectory(): void
    {
        // Use the project root as explicit working directory
        $projectRoot = AbsolutePath::fromString(\dirname(__DIR__, 4));
        $result = $this->locator->findGitDir($projectRoot);

        self::assertNotNull($result, 'Expected to find .git directory from project root');
        self::assertStringContainsString('.git', $result->value());
    }

    #[Test]
    public function returnsNullForNonGitDirectory(): void
    {
        // Use a path that is guaranteed not to be inside a git repository
        $result = $this->locator->findGitDir(AbsolutePath::fromString('/'));

        self::assertNull($result);
    }

    #[Test]
    public function returnsNullForNonExistentDirectory(): void
    {
        $result = $this->locator->findGitDir(AbsolutePath::fromString('/nonexistent/path/that/does/not/exist'));

        self::assertNull($result);
    }

    #[Test]
    public function brokenWorktreeLinkDoesNotFallBackToAncestorRepository(): void
    {
        // Regression: a `.git` file is a hard repository/worktree boundary.
        // If its `gitdir:` target is broken, the resolver must return null —
        // it must NOT keep walking up and pick the parent repository's .git.
        // Otherwise hook commands could write into the wrong (parent) repo.
        $parent = $this->makeTempDir('locator-parent-');
        $this->initGitRepo($parent);

        $child = $parent . '/child';
        mkdir($child, 0777, true);
        file_put_contents($child . '/.git', 'gitdir: /nonexistent/broken/target' . "\n");

        $result = $this->locator->findGitDir(AbsolutePath::fromString($child));

        self::assertNull(
            $result,
            'broken worktree link must yield null; otherwise findGitDir silently picks the parent repository .git',
        );
    }

    #[Test]
    public function resolvesWorktreeLinkWithRelativeGitDirPath(): void
    {
        // Submodule-style: `.git` is a file containing `gitdir: ../.git/modules/foo`.
        // The locator must resolve the relative target against the file's parent dir.
        $base = $this->makeTempDir('locator-relgitdir-');

        // Make the relative target a real directory so canonicalize() succeeds.
        mkdir($base . '/.git/modules/sub', 0777, true);

        $module = $base . '/sub';
        mkdir($module, 0777, true);
        file_put_contents($module . '/.git', 'gitdir: ../.git/modules/sub' . "\n");

        // Avoid `git rev-parse --git-dir` interfering: use a sub-path where the
        // primary strategy fails (no git in PATH context isn't easy to fake,
        // so we rely on rev-parse returning the same answer or null — either is
        // fine; if it returns the target, the traversal fallback is still
        // pinned by the assertion below).
        $result = $this->locator->findGitDir(AbsolutePath::fromString($module));

        self::assertNotNull($result, 'relative gitdir: target should resolve');
        // Path must end with the resolved real-disk dir, not the raw relative form.
        self::assertSame(
            realpath($base . '/.git/modules/sub'),
            $result->value(),
            'relative gitdir: must be resolved against the .git file location',
        );
    }

    private function makeTempDir(string $prefix): string
    {
        $dir = sys_get_temp_dir() . '/qmx-' . $prefix . uniqid();
        if (!mkdir($dir, 0777, true)) {
            throw new RuntimeException('Failed to create temp dir: ' . $dir);
        }
        $real = realpath($dir);
        if ($real === false) {
            throw new RuntimeException('Failed to resolve temp dir');
        }
        $this->tempDirs[] = $real;

        return $real;
    }

    private function initGitRepo(string $dir): void
    {
        (Process::fromShellCommandline('git init', $dir))->mustRun();
    }

    private function removeRecursive(string $dir): void
    {
        $items = scandir($dir);
        if ($items === false) {
            return;
        }
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $dir . '/' . $item;
            if (is_dir($path) && !is_link($path)) {
                $this->removeRecursive($path);
            } else {
                unlink($path);
            }
        }
        rmdir($dir);
    }
}
