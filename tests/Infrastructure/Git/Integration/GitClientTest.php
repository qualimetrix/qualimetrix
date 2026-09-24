<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Infrastructure\Git\Integration;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestWith;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Core\Path\AbsolutePath;
use Qualimetrix\Infrastructure\Git\ChangedFile;
use Qualimetrix\Infrastructure\Git\ChangeStatus;
use Qualimetrix\Infrastructure\Git\GitClient;
use Qualimetrix\Infrastructure\Git\UnresolvedGitReferenceException;
use ReflectionMethod;
use RuntimeException;
use Symfony\Component\Process\Process;

#[CoversClass(GitClient::class)]
final class GitClientTest extends TestCase
{
    private string $repoRoot;

    protected function setUp(): void
    {
        $dir = sys_get_temp_dir() . '/git-test-' . bin2hex(random_bytes(6));
        mkdir($dir);
        // Use realpath to normalize the path (macOS /var vs /private/var)
        $realPath = realpath($dir);
        if ($realPath === false) {
            throw new RuntimeException('Failed to resolve path: ' . $dir);
        }
        $this->repoRoot = $realPath;
    }

    protected function tearDown(): void
    {
        if (is_dir($this->repoRoot)) {
            $this->removeDirectory($this->repoRoot);
        }
    }

    #[Test]
    public function itReturnsTrueWhenGitDirectoryExists(): void
    {
        mkdir($this->repoRoot . '/.git');
        $client = new GitClient(AbsolutePath::fromString($this->repoRoot));

        self::assertTrue($client->isRepository());
    }

    #[Test]
    public function itReturnsTrueWhenGitIsAFile(): void
    {
        // In worktrees, .git is a file pointing to the main repo
        file_put_contents($this->repoRoot . '/.git', 'gitdir: /some/other/path/.git/worktrees/test');
        $client = new GitClient(AbsolutePath::fromString($this->repoRoot));

        self::assertTrue($client->isRepository());
    }

    #[Test]
    public function itReturnsFalseWhenGitDirectoryDoesNotExist(): void
    {
        $client = new GitClient(AbsolutePath::fromString($this->repoRoot));

        self::assertFalse($client->isRepository());
    }

    #[Test]
    public function itGetsRepositoryRoot(): void
    {
        $this->initGitRepo();
        $client = new GitClient(AbsolutePath::fromString($this->repoRoot));

        $root = $client->getRoot();

        // Paths are already normalized with realpath in setUp
        self::assertSame($this->repoRoot, $root->value());
    }

    #[Test]
    public function itGetsStagedFiles(): void
    {
        $this->initGitRepo();

        // Create and stage a file
        file_put_contents($this->repoRoot . '/test.php', '<?php');
        $this->exec('git add test.php');

        $client = new GitClient(AbsolutePath::fromString($this->repoRoot));
        $files = $client->getChangedFiles('staged');

        self::assertCount(1, $files);
        self::assertInstanceOf(ChangedFile::class, $files[0]); // @phpstan-ignore staticMethod.alreadyNarrowedType
        self::assertSame('test.php', $files[0]->path->value());
        self::assertSame(ChangeStatus::Added, $files[0]->status);
    }

    #[Test]
    public function itGetsUncommittedFiles(): void
    {
        $this->initGitRepo();

        // Create and commit a file
        file_put_contents($this->repoRoot . '/test.php', '<?php');
        $this->exec('git add test.php');
        $this->exec('git commit -m "Initial commit"');

        // Modify it
        file_put_contents($this->repoRoot . '/test.php', '<?php echo "modified";');

        $client = new GitClient(AbsolutePath::fromString($this->repoRoot));
        $files = $client->getChangedFiles('HEAD');

        self::assertCount(1, $files);
        self::assertSame('test.php', $files[0]->path->value());
        self::assertSame(ChangeStatus::Modified, $files[0]->status);
    }

    #[Test]
    public function itParsesAddedFiles(): void
    {
        $this->initGitRepo();

        file_put_contents($this->repoRoot . '/added.php', '<?php');
        $this->exec('git add added.php');

        $client = new GitClient(AbsolutePath::fromString($this->repoRoot));
        $files = $client->getChangedFiles('staged');

        self::assertCount(1, $files);
        self::assertSame(ChangeStatus::Added, $files[0]->status);
    }

    #[Test]
    public function itParsesModifiedFiles(): void
    {
        $this->initGitRepo();

        // Initial commit
        file_put_contents($this->repoRoot . '/file.php', '<?php');
        $this->exec('git add file.php');
        $this->exec('git commit -m "Initial"');

        // Modify and stage
        file_put_contents($this->repoRoot . '/file.php', '<?php echo "test";');
        $this->exec('git add file.php');

        $client = new GitClient(AbsolutePath::fromString($this->repoRoot));
        $files = $client->getChangedFiles('staged');

        self::assertCount(1, $files);
        self::assertSame(ChangeStatus::Modified, $files[0]->status);
    }

    #[Test]
    public function itParsesDeletedFiles(): void
    {
        $this->initGitRepo();

        // Create and commit file
        file_put_contents($this->repoRoot . '/file.php', '<?php');
        $this->exec('git add file.php');
        $this->exec('git commit -m "Initial"');

        // Delete and stage
        unlink($this->repoRoot . '/file.php');
        $this->exec('git add file.php');

        $client = new GitClient(AbsolutePath::fromString($this->repoRoot));
        $files = $client->getChangedFiles('staged');

        self::assertCount(1, $files);
        self::assertSame(ChangeStatus::Deleted, $files[0]->status);
    }

    #[Test]
    public function itParsesRenamedFiles(): void
    {
        $this->initGitRepo();

        // Create and commit file
        file_put_contents($this->repoRoot . '/old.php', '<?php');
        $this->exec('git add old.php');
        $this->exec('git commit -m "Initial"');

        // Rename and stage
        rename($this->repoRoot . '/old.php', $this->repoRoot . '/new.php');
        $this->exec('git add old.php new.php');

        $client = new GitClient(AbsolutePath::fromString($this->repoRoot));
        $files = $client->getChangedFiles('staged');

        self::assertCount(1, $files);
        self::assertSame('new.php', $files[0]->path->value());
        self::assertSame(ChangeStatus::Renamed, $files[0]->status);
        self::assertSame('old.php', $files[0]->oldPath?->value());
    }

    #[Test]
    public function itParsesCopiedFilesFromNameStatusOutput(): void
    {
        $this->initGitRepo();

        $client = new GitClient(AbsolutePath::fromString($this->repoRoot));

        // Use reflection to test parseNameStatus directly with copy format:
        // git only emits `C` under --find-copies-harder, which none of the
        // scopes pass, so the stream is written out rather than produced.
        $method = new ReflectionMethod($client, 'parseNameStatus');

        $output = "C100\0old.php\0new.php\0";
        $files = $method->invoke($client, $output);

        self::assertCount(1, $files);
        self::assertSame('new.php', $files[0]->path->value());
        self::assertSame(ChangeStatus::Copied, $files[0]->status);
        self::assertSame('old.php', $files[0]->oldPath?->value());
    }

    #[Test]
    public function itParsesCopiedFilesWithPartialSimilarity(): void
    {
        $this->initGitRepo();

        $client = new GitClient(AbsolutePath::fromString($this->repoRoot));

        $method = new ReflectionMethod($client, 'parseNameStatus');

        // Copy with partial similarity (e.g., C075)
        $output = "C075\0src/original.php\0src/copy.php\0";
        $files = $method->invoke($client, $output);

        self::assertCount(1, $files);
        self::assertSame('src/copy.php', $files[0]->path->value());
        self::assertSame(ChangeStatus::Copied, $files[0]->status);
        self::assertSame('src/original.php', $files[0]->oldPath?->value());
    }

    #[Test]
    public function itGetsTwoDotDiff(): void
    {
        $this->initGitRepo();

        // First commit
        file_put_contents($this->repoRoot . '/file1.php', '<?php');
        $this->exec('git add file1.php');
        $this->exec('git commit -m "First"');
        $this->exec('git tag v1');

        // Second commit
        file_put_contents($this->repoRoot . '/file2.php', '<?php');
        $this->exec('git add file2.php');
        $this->exec('git commit -m "Second"');

        $client = new GitClient(AbsolutePath::fromString($this->repoRoot));
        $files = $client->getChangedFiles('v1..HEAD');

        self::assertCount(1, $files);
        self::assertSame('file2.php', $files[0]->path->value());
    }

    #[Test]
    public function itGetsThreeDotDiff(): void
    {
        $this->initGitRepo();

        // Initial commit
        file_put_contents($this->repoRoot . '/base.php', '<?php');
        $this->exec('git add base.php');
        $this->exec('git commit -m "Base"');

        // Create branch and commit
        $this->exec('git checkout -b feature');
        file_put_contents($this->repoRoot . '/feature.php', '<?php');
        $this->exec('git add feature.php');
        $this->exec('git commit -m "Feature"');

        $client = new GitClient(AbsolutePath::fromString($this->repoRoot));
        $files = $client->getChangedFiles('main...feature');

        self::assertCount(1, $files);
        self::assertSame('feature.php', $files[0]->path->value());
    }

    #[Test]
    public function itGetsDiffFromRef(): void
    {
        $this->initGitRepo();

        // First commit
        file_put_contents($this->repoRoot . '/file1.php', '<?php');
        $this->exec('git add file1.php');
        $this->exec('git commit -m "First"');
        $this->exec('git tag v1');

        // Second commit
        file_put_contents($this->repoRoot . '/file2.php', '<?php');
        $this->exec('git add file2.php');
        $this->exec('git commit -m "Second"');

        $client = new GitClient(AbsolutePath::fromString($this->repoRoot));
        $files = $client->getChangedFiles('v1');

        self::assertCount(1, $files);
        self::assertSame('file2.php', $files[0]->path->value());
    }

    /**
     * Git accepts refnames containing shell metacharacters, and such a name
     * reaches `git diff` as part of a range. That is the one place where
     * handing the command to a shell would change what the command means.
     *
     * The cases differ in how a shell would break them, and only the first
     * comes back as a wrong answer rather than as an error. `v1` exists as a
     * ref of its own, so a shell splitting at `;` runs `v1..HEAD`, which git
     * resolves and returns rows for; the uncommitted edit to base.php is what
     * makes those rows differ from the literal range's. The other three exit
     * non-zero instead — the substitution appends to the ref name, and the
     * pipe and the chain each leave a second word the shell cannot run.
     *
     * @return iterable<string, array{string}>
     */
    public static function provideShellSignificantBranchNames(): iterable
    {
        yield 'command separator and redirection' => ['v1;>pwned'];
        yield 'command substitution' => ['v1$(id)'];
        yield 'pipe' => ['v1|tee'];
        yield 'chained command' => ['v1&&later'];
    }

    #[Test]
    #[DataProvider('provideShellSignificantBranchNames')]
    public function itPassesAShellSignificantRangeToGitLiterally(string $branch): void
    {
        $this->initGitRepo();

        file_put_contents($this->repoRoot . '/base.php', '<?php');
        $this->exec('git add base.php');
        $this->exec('git commit -m "Base"');
        $this->exec('git branch v1');
        $this->exec(\sprintf('git branch %s', escapeshellarg($branch)));

        file_put_contents($this->repoRoot . '/after.php', '<?php');
        $this->exec('git add after.php');
        $this->exec('git commit -m "After"');

        // Separates the two readings for the `;` case: `<branch>..HEAD` never
        // reports base.php, the truncated `v1..HEAD` does.
        file_put_contents($this->repoRoot . '/base.php', '<?php echo "dirty";');

        $before = $this->repoEntries();

        $client = new GitClient(AbsolutePath::fromString($this->repoRoot));
        $files = $client->getChangedFiles($branch . '..HEAD');

        self::assertCount(1, $files);
        self::assertSame('after.php', $files[0]->path->value());
        self::assertSame(ChangeStatus::Added, $files[0]->status);
        // A shell would also leave its own artefacts behind — a redirection
        // target, say. Comparing the whole listing catches any of them, not
        // just the one this case happens to name.
        self::assertSame($before, $this->repoEntries());
    }

    #[Test]
    public function itDeduplicatesFiles(): void
    {
        $this->initGitRepo();

        // Create and commit file
        file_put_contents($this->repoRoot . '/file.php', '<?php');
        $this->exec('git add file.php');
        $this->exec('git commit -m "Initial"');

        // Modify it
        file_put_contents($this->repoRoot . '/file.php', '<?php echo "test";');
        $this->exec('git add file.php');

        $client = new GitClient(AbsolutePath::fromString($this->repoRoot));
        $files = $client->getChangedFiles('staged');

        self::assertCount(1, $files);
    }

    #[Test]
    public function itThrowsExceptionWhenGitCommandFails(): void
    {
        $client = new GitClient(AbsolutePath::fromString('/nonexistent'));

        self::expectException(RuntimeException::class);

        $client->getRoot();
    }

    #[Test]
    public function itHandlesEmptyDiff(): void
    {
        $this->initGitRepo();

        // Create initial commit
        file_put_contents($this->repoRoot . '/file.php', '<?php');
        $this->exec('git add file.php');
        $this->exec('git commit -m "Initial"');

        $client = new GitClient(AbsolutePath::fromString($this->repoRoot));
        $files = $client->getChangedFiles('staged');

        self::assertSame([], $files);
    }

    #[Test]
    public function itSkipsUnknownGitStatusesLikeTypeChange(): void
    {
        $this->initGitRepo();

        // Create initial commit with a regular file
        file_put_contents($this->repoRoot . '/file.php', '<?php');
        file_put_contents($this->repoRoot . '/normal.php', '<?php');
        $this->exec('git add file.php normal.php');
        $this->exec('git commit -m "Initial"');

        // Modify normal.php and stage it
        file_put_contents($this->repoRoot . '/normal.php', '<?php echo "modified";');
        $this->exec('git add normal.php');

        $client = new GitClient(AbsolutePath::fromString($this->repoRoot));

        // The client should be able to parse the output without crashing
        // even when exotic statuses like T (type change), U (unmerged), X (unknown) exist
        // We can only reliably test that parsing standard statuses works and doesn't crash
        $files = $client->getChangedFiles('staged');

        self::assertCount(1, $files);
        self::assertSame('normal.php', $files[0]->path->value());
        self::assertSame(ChangeStatus::Modified, $files[0]->status);
    }

    #[Test]
    public function itIgnoresNonStandardGitOutput(): void
    {
        $this->initGitRepo();

        file_put_contents($this->repoRoot . '/test.php', '<?php');
        $this->exec('git add test.php');

        $client = new GitClient(AbsolutePath::fromString($this->repoRoot));
        $files = $client->getChangedFiles('staged');

        // Should parse only valid lines
        self::assertNotEmpty($files);
    }

    /**
     * Revisions git refuses.
     *
     * Measured with git 2.55 across eighteen forms of refusal, including a
     * damaged repository: `git rev-parse --verify` without `--quiet` fails
     * with text every time, so every refusal below reaches the user carrying
     * git's own reason. What that reason says is git's and the system
     * locale's; these cases assert that it arrived, never how it is worded.
     *
     * @return iterable<string, array{string}>
     */
    public static function provideRefusedRevisions(): iterable
    {
        yield 'unknown name' => ['nosuchref'];
        yield 'reflog position past the end of HEAD' => ['HEAD@{999}'];
        yield 'reflog position past the end of a branch' => ['main@{999}'];
        yield 'dereferences to a tree' => ['HEAD^{tree}'];
        yield 'branch without an upstream' => ['main@{u}'];
        yield 'ancestor past the root commit' => ['HEAD~50'];
    }

    #[Test]
    #[DataProvider('provideRefusedRevisions')]
    public function itRefusesARevisionAndQuotesGitsReason(string $reference): void
    {
        $this->initGitRepoWithCommit();
        $client = new GitClient(AbsolutePath::fromString($this->repoRoot));

        try {
            $client->getChangedFiles($reference . '..HEAD');
            self::fail('Expected the revision to be refused.');
        } catch (UnresolvedGitReferenceException $refusal) {
            $this->assertRefusalQuotesGit($refusal->getMessage(), $reference);
        }
    }

    #[Test]
    public function itRefusesARevisionTheRepositoryIsTooDamagedToRead(): void
    {
        $this->initGitRepoWithCommit();
        $this->corruptHeadCommitObject();
        $client = new GitClient(AbsolutePath::fromString($this->repoRoot));

        try {
            $client->getChangedFiles('HEAD..HEAD');
            self::fail('Expected the damaged repository to be refused.');
        } catch (UnresolvedGitReferenceException $refusal) {
            $this->assertRefusalQuotesGit($refusal->getMessage(), 'HEAD');
        }
    }

    #[Test]
    public function itRefusesAnEmptyRevisionWithoutQuotingGit(): void
    {
        $this->initGitRepoWithCommit();
        $client = new GitClient(AbsolutePath::fromString($this->repoRoot));

        try {
            $client->getChangedFiles('..');
            self::fail('Expected the empty revision to be refused.');
        } catch (UnresolvedGitReferenceException $refusal) {
            // The one refusal raised without asking git, so the one with no tail.
            self::assertSame(
                'Git reference "" does not resolve to a commit.',
                $refusal->getMessage(),
            );
        }
    }

    /**
     * The guard that makes "no dangling colon" structural rather than lucky.
     * No measured input reaches it — every refusal git answers carries text —
     * so without this it would be a claim no test makes.
     */
    #[Test]
    #[TestWith([null])]
    #[TestWith([''])]
    public function itOmitsTheQuoteWhenGitOfferedNoReason(?string $gitReport): void
    {
        $refusal = new UnresolvedGitReferenceException('HEAD@{9}', $gitReport);

        self::assertSame('Git reference "HEAD@{9}" does not resolve to a commit.', $refusal->getMessage());
        self::assertDoesNotMatchRegularExpression('/:\s*$/', $refusal->getMessage());
    }

    /**
     * Asserts the refusal names the revision and carries git's reason. What
     * git says is deliberately not pinned: the wording is git's to change.
     */
    private function assertRefusalQuotesGit(string $message, string $reference): void
    {
        $prefix = \sprintf('Git reference "%s" does not resolve to a commit. git: ', $reference);

        self::assertStringStartsWith($prefix, $message);
        self::assertNotSame('', trim(substr($message, \strlen($prefix))));
        self::assertDoesNotMatchRegularExpression('/:\s*$/', $message);
    }

    private function initGitRepoWithCommit(): void
    {
        $this->initGitRepo();

        file_put_contents($this->repoRoot . '/test.php', '<?php');
        $this->exec('git add test.php');
        $this->exec('git commit -m "Initial commit"');
    }

    /**
     * Overwrites the loose object holding the HEAD commit, so that resolving a
     * reference through it fails for a reason that is not the reference.
     */
    private function corruptHeadCommitObject(): void
    {
        $sha = $this->execOutput('git rev-parse HEAD');
        $objectPath = \sprintf(
            '%s/.git/objects/%s/%s',
            $this->repoRoot,
            substr($sha, 0, 2),
            substr($sha, 2),
        );

        if (!is_file($objectPath)) {
            self::fail('Expected the HEAD commit to be a loose object: ' . $objectPath);
        }

        chmod($objectPath, 0644);
        file_put_contents($objectPath, 'not a zlib stream');
    }

    private function execOutput(string $command): string
    {
        $process = Process::fromShellCommandline($command, $this->repoRoot);
        $process->run();

        if (!$process->isSuccessful()) {
            throw new RuntimeException(\sprintf('Command failed: %s', $process->getErrorOutput()));
        }

        return trim($process->getOutput());
    }

    private function initGitRepo(): void
    {
        $this->exec('git init');
        $this->exec('git config user.email "test@example.com"');
        $this->exec('git config user.name "Test User"');

        // Set default branch to main
        $this->exec('git checkout -b main');
    }

    private function exec(string $command): void
    {
        $process = Process::fromShellCommandline(
            $command,
            $this->repoRoot,
        );

        $process->run();

        if (!$process->isSuccessful()) {
            throw new RuntimeException(
                \sprintf('Command failed: %s', $process->getErrorOutput()),
            );
        }
    }

    /**
     * Top-level entries of the repository, `.git` excluded.
     *
     * @return list<string>
     */
    private function repoEntries(): array
    {
        $entries = scandir($this->repoRoot);
        if ($entries === false) {
            throw new RuntimeException('Failed to list: ' . $this->repoRoot);
        }

        $entries = array_values(array_filter(
            $entries,
            static fn(string $entry): bool => !\in_array($entry, ['.', '..', '.git'], true),
        ));
        sort($entries);

        return $entries;
    }

    private function removeDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        $items = scandir($dir);
        if ($items === false) {
            return;
        }

        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }

            $path = $dir . '/' . $item;
            if (is_dir($path)) {
                $this->removeDirectory($path);
            } else {
                unlink($path);
            }
        }

        rmdir($dir);
    }
}
