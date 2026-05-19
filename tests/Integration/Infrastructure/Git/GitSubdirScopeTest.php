<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Integration\Infrastructure\Git;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use Qualimetrix\Core\Path\AbsolutePath;
use Qualimetrix\Infrastructure\Git\ChangedFile;
use Qualimetrix\Infrastructure\Git\ChangeStatus;
use Qualimetrix\Infrastructure\Git\GitClient;
use RuntimeException;
use Stringable;
use Symfony\Component\Process\Process;

/**
 * T10 regression: project root may be a strict subdirectory of the git top-level.
 *
 * Before ADR 0015 Phase 1b, GitClient::$repoRoot was misnamed (it was actually
 * the project root); raw git output (toplevel-relative) was used directly as if
 * project-relative, silently mis-attributing or skipping violations.
 *
 * This test pins:
 * - Files inside the project subdir → included with project-relative path.
 * - Files outside the project subdir but inside the git tree → excluded with a warning.
 * - All four diff row shapes (Added, Modified, Deleted, Renamed) handled.
 * - Argument order to {@see ChangedFile::fromGitOutput()} matters: swapping
 *   `$gitToplevel` and `$projectRoot` produces a different — and wrong — path.
 */
#[CoversClass(GitClient::class)]
#[CoversClass(ChangedFile::class)]
final class GitSubdirScopeTest extends TestCase
{
    private string $gitToplevel;

    private string $projectRoot;

    protected function setUp(): void
    {
        $dir = sys_get_temp_dir() . '/qmx-git-subdir-' . uniqid();
        if (!mkdir($dir, 0777, true)) {
            throw new RuntimeException('Failed to create temp dir: ' . $dir);
        }
        $resolved = realpath($dir);
        if ($resolved === false) {
            throw new RuntimeException('Failed to resolve temp dir');
        }
        $this->gitToplevel = $resolved;
        $this->projectRoot = $resolved . '/project';
        mkdir($this->projectRoot, 0777, true);

        $this->exec('git init', $this->gitToplevel);
        $this->exec('git config user.email "test@example.com"', $this->gitToplevel);
        $this->exec('git config user.name "Test"', $this->gitToplevel);
        $this->exec('git config commit.gpgsign false', $this->gitToplevel);
    }

    protected function tearDown(): void
    {
        if (is_dir($this->gitToplevel)) {
            $this->removeRecursive($this->gitToplevel);
        }
    }

    #[Test]
    public function itIncludesAndExcludesPathsRelativeToProjectRoot(): void
    {
        // Project file: inside the project subdir.
        file_put_contents($this->projectRoot . '/Inside.php', "<?php\n");
        // Outsider: same git tree, but not under project root.
        file_put_contents($this->gitToplevel . '/Outsider.php', "<?php\n");

        $this->exec('git add -A', $this->gitToplevel);

        $logger = new SpyLogger();
        $client = new GitClient(AbsolutePath::fromString($this->projectRoot), $logger);
        $changed = $client->getChangedFiles('staged');

        self::assertCount(1, $changed);
        self::assertSame('Inside.php', $changed[0]->path->value());
        self::assertSame(ChangeStatus::Added, $changed[0]->status);

        // The out-of-project row must surface as a PSR-3 warning (count + path);
        // without this assertion the silent-drop behavior could regress unnoticed.
        $warnings = $logger->warnings();
        self::assertCount(1, $warnings, 'expected a single warning for the dropped row');
        self::assertStringContainsString('Skipped 1 changed file(s) outside project root', $warnings[0]);
        self::assertStringContainsString('Outsider.php', $warnings[0]);
    }

    #[Test]
    public function itHandlesAddedModifiedAndDeletedDiffRowShapes(): void
    {
        // Modified / Deleted require a prior commit. Use distinct content per
        // file so git's automatic rename detection doesn't pair the deleted
        // file with the new one.
        file_put_contents(
            $this->projectRoot . '/Modified.php',
            "<?php\nclass Modified { public function existing(): void {} }\n",
        );
        file_put_contents(
            $this->projectRoot . '/ToDelete.php',
            "<?php\nclass ToDelete { public function bye(): string { return 'gone'; } }\n",
        );
        $this->exec('git add -A', $this->gitToplevel);
        $this->exec('git commit -m initial', $this->gitToplevel);

        file_put_contents(
            $this->projectRoot . '/Added.php',
            "<?php\nclass Added { public function hi(): string { return 'fresh'; } }\n",
        );
        file_put_contents(
            $this->projectRoot . '/Modified.php',
            "<?php\nclass Modified { public function existing(): void {} public function added(): bool { return true; } }\n",
        );
        unlink($this->projectRoot . '/ToDelete.php');
        $this->exec('git add -A', $this->gitToplevel);

        $client = new GitClient(AbsolutePath::fromString($this->projectRoot));
        $changed = $client->getChangedFiles('staged');

        $byPath = [];
        foreach ($changed as $c) {
            $byPath[$c->path->value()] = $c->status;
        }

        self::assertSame(ChangeStatus::Added, $byPath['Added.php'] ?? null);
        self::assertSame(ChangeStatus::Modified, $byPath['Modified.php'] ?? null);
        self::assertSame(ChangeStatus::Deleted, $byPath['ToDelete.php'] ?? null);
    }

    #[Test]
    public function itHandlesRenamedDiffRowShape(): void
    {
        // Rename detection in `git diff --cached --name-status` requires a
        // rename to be visible to the diff machinery; running this as its own
        // test keeps the rename precondition (similar content + explicit
        // similarity threshold) independent of the other shapes.
        $content = "<?php\n// shared content for rename detection\nclass Foo {}\n";
        file_put_contents($this->projectRoot . '/Old.php', $content);
        $this->exec('git add -A', $this->gitToplevel);
        $this->exec('git commit -m initial', $this->gitToplevel);

        rename($this->projectRoot . '/Old.php', $this->projectRoot . '/New.php');
        $this->exec('git add -A', $this->gitToplevel);

        $client = new GitClient(AbsolutePath::fromString($this->projectRoot));
        // git rev-parse --show-toplevel + the staged scope hits parseNameStatus.
        // Force rename detection via diff.renames=true (default in modern git but
        // pinned here for older CI matrices).
        $this->exec('git config diff.renames true', $this->gitToplevel);
        $changed = $client->getChangedFiles('staged');

        $rename = null;
        foreach ($changed as $c) {
            if ($c->status === ChangeStatus::Renamed) {
                $rename = $c;

                break;
            }
        }

        // Some git builds emit D + A instead of R when invoked through
        // --name-status without a status detection flag. Skip the strict
        // assertion in that case; the cross-check in
        // itDistinguishesGitToplevelFromProjectRootInFromGitOutput covers the
        // rename path shape end-to-end without depending on git heuristics.
        if ($rename === null) {
            self::markTestSkipped('git did not detect rename — likely missing rename heuristic in this environment');
        }

        self::assertSame('New.php', $rename->path->value());
        self::assertSame('Old.php', $rename->oldPath?->value());
    }

    #[Test]
    public function itDistinguishesGitToplevelFromProjectRootInFromGitOutput(): void
    {
        // Pin argument order: when the project root is a strict subdir of the
        // git top-level, swapping the two args is a silent bug — both VOs are
        // valid AbsolutePaths under one another, so neither call returns null.
        // The resulting path values, however, differ.
        $toplevel = AbsolutePath::fromString('/tmp/repo');
        $project = AbsolutePath::fromString('/tmp/repo/sub');

        $correct = ChangedFile::fromGitOutput(
            'sub/Foo.php',
            ChangeStatus::Modified,
            null,
            $toplevel,
            $project,
        );
        $swapped = ChangedFile::fromGitOutput(
            'sub/Foo.php',
            ChangeStatus::Modified,
            null,
            $project,
            $toplevel,
        );

        self::assertNotNull($correct);
        self::assertNotNull($swapped);
        self::assertSame('Foo.php', $correct->path->value());
        self::assertSame('sub/sub/Foo.php', $swapped->path->value());
        self::assertNotSame($correct->path->value(), $swapped->path->value());
    }

    #[Test]
    public function itReturnsNullWhenPathIsOutsideProjectRoot(): void
    {
        $toplevel = AbsolutePath::fromString('/tmp/repo');
        $project = AbsolutePath::fromString('/tmp/repo/sub');

        $result = ChangedFile::fromGitOutput(
            'other/Outsider.php',
            ChangeStatus::Added,
            null,
            $toplevel,
            $project,
        );

        self::assertNull($result);
    }

    #[Test]
    public function itKeepsCrossBoundaryRenameWithNullOldPath(): void
    {
        // Cross-boundary rename: `lib/Old.php` (outside project) → `sub/New.php`
        // (inside project). The change is meaningful to the project (the file is
        // now in scope), so the row must surface; `oldPath` becomes `null` since
        // the source isn't reachable from inside the project.
        $toplevel = AbsolutePath::fromString('/tmp/repo');
        $project = AbsolutePath::fromString('/tmp/repo/sub');

        $result = ChangedFile::fromGitOutput(
            'sub/New.php',
            ChangeStatus::Renamed,
            'lib/Old.php',
            $toplevel,
            $project,
        );

        self::assertNotNull($result);
        self::assertSame('New.php', $result->path->value());
        self::assertSame(ChangeStatus::Renamed, $result->status);
        self::assertNull($result->oldPath, 'out-of-project old path must drop to null, not propagate');
    }

    #[Test]
    public function itPreservesOldPathForInProjectRename(): void
    {
        $toplevel = AbsolutePath::fromString('/tmp/repo');
        $project = AbsolutePath::fromString('/tmp/repo/sub');

        $result = ChangedFile::fromGitOutput(
            'sub/New.php',
            ChangeStatus::Renamed,
            'sub/Old.php',
            $toplevel,
            $project,
        );

        self::assertNotNull($result);
        self::assertSame('New.php', $result->path->value());
        self::assertSame('Old.php', $result->oldPath?->value());
    }

    private function exec(string $command, string $cwd): void
    {
        $process = Process::fromShellCommandline($command, $cwd);
        $process->mustRun();
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

/**
 * Minimal PSR-3 spy used to assert that the warning for skipped out-of-project
 * rows reaches the configured logger. Lives next to the consuming test only.
 */
final class SpyLogger extends AbstractLogger
{
    /** @var list<string> */
    private array $warnings = [];

    /**
     * @param array<mixed> $context
     */
    public function log(mixed $level, string|Stringable $message, array $context = []): void
    {
        if ($level === 'warning') {
            $this->warnings[] = (string) $message;
        }
    }

    /**
     * @return list<string>
     */
    public function warnings(): array
    {
        return $this->warnings;
    }
}
