<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Infrastructure\Git\Integration;

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

    private string $originalPath = '';

    protected function setUp(): void
    {
        $this->locator = new GitRepositoryLocator();

        $path = getenv('PATH');
        $this->originalPath = $path === false ? '' : $path;
    }

    protected function tearDown(): void
    {
        // PATH is process-global: a shadowed `git` left behind would answer
        // for every test that runs after this one.
        putenv('PATH=' . $this->originalPath);

        foreach ($this->tempDirs as $dir) {
            if (is_dir($dir)) {
                $this->removeRecursive($dir);
            }
        }
        $this->tempDirs = [];
    }

    #[Test]
    public function itFindsTheGitDirInTheCurrentRepository(): void
    {
        $result = $this->locator->findGitDir();

        self::assertNotNull($result, 'Expected to find .git directory (tests run inside a git repo)');
    }

    #[Test]
    public function itReturnsAnAbsolutePath(): void
    {
        $result = $this->locator->findGitDir();

        self::assertNotNull($result);
        self::assertStringStartsWith('/', $result->value(), 'Path should be absolute');
    }

    #[Test]
    public function itReturnsAPathContainingAGitReference(): void
    {
        $result = $this->locator->findGitDir();

        self::assertNotNull($result);
        // Regular repos end with .git; worktrees may have .git in the path
        self::assertStringContainsString('.git', $result->value());
    }

    #[Test]
    public function itAcceptsAnExplicitWorkingDirectory(): void
    {
        // Use the project root as explicit working directory
        $projectRoot = AbsolutePath::fromString(\dirname(__DIR__, 4));
        $result = $this->locator->findGitDir($projectRoot);

        self::assertNotNull($result, 'Expected to find .git directory from project root');
        self::assertStringContainsString('.git', $result->value());
    }

    #[Test]
    public function itReturnsNullForANonGitDirectory(): void
    {
        // Use a path that is guaranteed not to be inside a git repository
        $result = $this->locator->findGitDir(AbsolutePath::fromString('/'));

        self::assertNull($result);
    }

    #[Test]
    public function itReturnsNullForANonExistentDirectory(): void
    {
        $result = $this->locator->findGitDir(AbsolutePath::fromString('/nonexistent/path/that/does/not/exist'));

        self::assertNull($result);
    }

    #[Test]
    public function itDoesNotFallBackToTheAncestorRepositoryOnABrokenWorktreeLink(): void
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
    public function itResolvesAWorktreeLinkWithARelativeGitDirPath(): void
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

    #[Test]
    public function itAnswersWhenGitFloodsItsErrorStream(): void
    {
        // Regression: git's error stream must not be a pipe nobody reads. A
        // child writing more than the pipe buffer holds blocks on that write,
        // so it never closes its output stream, and the parent waits forever
        // for an end-of-output that cannot arrive. The `hook:*` commands reach
        // this through findHooksDir and would hang with neither output nor an
        // exit code rather than failing.
        $sandbox = $this->makeTempDir('locator-flood-');
        $answer = $this->shadowGitAnsweringPastAFloodedErrorStream($sandbox);

        $result = $this->locator->findGitDir(AbsolutePath::fromString($sandbox));

        self::assertNotNull($result, 'findGitDir must outlive a git that floods its error stream');
        self::assertSame($answer, $result->value());
    }

    #[Test]
    public function itAnswersForTheHooksDirWhenGitFloodsItsErrorStream(): void
    {
        // findHooksDir reaches the same descriptor set through its own call.
        // Asserted separately so splitting that helper cannot leave one of the
        // two callers with an undrained stream.
        $sandbox = $this->makeTempDir('locator-flood-hooks-');
        $answer = $this->shadowGitAnsweringPastAFloodedErrorStream($sandbox);

        $result = $this->locator->findHooksDir(AbsolutePath::fromString($sandbox));

        self::assertNotNull($result, 'findHooksDir must outlive a git that floods its error stream');
        self::assertSame($answer, $result->value());
    }

    /**
     * Puts a `git` on PATH that writes half a megabyte to its error stream
     * before answering on stdout. It kills itself after a few seconds, so a
     * parent that never drains that stream reddens this test instead of
     * hanging the suite forever.
     *
     * What refuses a traversal answer is asserting the returned path, not
     * where the sandbox sits: a locator that gave up on the command still
     * finds a real .git from inside a repository, and only the exact path
     * tells the two apart. The sandbox sits outside one as a second line.
     *
     * @return string the path this git answers with
     */
    private function shadowGitAnsweringPastAFloodedErrorStream(string $sandbox): string
    {
        $answer = $sandbox . '/flooded-git-dir';
        $bin = $sandbox . '/bin';

        foreach ([$answer, $bin] as $dir) {
            if (!mkdir($dir, 0777, true)) {
                throw new RuntimeException('Failed to create directory: ' . $dir);
            }
        }

        $script = <<<'SH'
            #!/bin/sh
            self=$$
            # The redirect is load-bearing: without it this watchdog keeps a
            # copy of the output stream, and the reader waits out the sleep
            # for an end-of-output no living writer is going to send.
            (sleep 3; kill -9 $self) >/dev/null 2>&1 &
            i=0
            while [ $i -lt 512 ]; do
                printf '%01023d\n' "$i" >&2
                i=$((i + 1))
            done
            printf '%s\n' 'QMX_ANSWER'
            SH;

        $written = file_put_contents($bin . '/git', str_replace('QMX_ANSWER', $answer, $script) . "\n");
        if ($written === false) {
            throw new RuntimeException('Failed to write the shadowed git');
        }
        if (!chmod($bin . '/git', 0755)) {
            throw new RuntimeException('Failed to make the shadowed git executable');
        }

        putenv('PATH=' . $bin . ':' . $this->originalPath);

        return $answer;
    }

    private function makeTempDir(string $prefix): string
    {
        $dir = sys_get_temp_dir() . '/qmx-' . $prefix . bin2hex(random_bytes(6));
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
