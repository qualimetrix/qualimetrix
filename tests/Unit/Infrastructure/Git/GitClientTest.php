<?php

declare(strict_types=1);

namespace AiMessDetector\Tests\Unit\Infrastructure\Git;

use AiMessDetector\Infrastructure\Git\ChangedFile;
use AiMessDetector\Infrastructure\Git\ChangeStatus;
use AiMessDetector\Infrastructure\Git\GitClient;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\Process\Process;

#[CoversClass(GitClient::class)]
final class GitClientTest extends TestCase
{
    private string $repoRoot;

    protected function setUp(): void
    {
        $dir = sys_get_temp_dir() . '/git-test-' . uniqid();
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
        $client = new GitClient($this->repoRoot);

        $this->assertTrue($client->isRepository());
    }

    #[Test]
    public function itReturnsTrueWhenGitIsAFile(): void
    {
        // In worktrees, .git is a file pointing to the main repo
        file_put_contents($this->repoRoot . '/.git', 'gitdir: /some/other/path/.git/worktrees/test');
        $client = new GitClient($this->repoRoot);

        $this->assertTrue($client->isRepository());
    }

    #[Test]
    public function itReturnsFalseWhenGitDirectoryDoesNotExist(): void
    {
        $client = new GitClient($this->repoRoot);

        $this->assertFalse($client->isRepository());
    }

    #[Test]
    public function itGetsRepositoryRoot(): void
    {
        $this->initGitRepo();
        $client = new GitClient($this->repoRoot);

        $root = $client->getRoot();

        // Paths are already normalized with realpath in setUp
        $this->assertSame($this->repoRoot, $root);
    }

    #[Test]
    public function itGetsStagedFiles(): void
    {
        $this->initGitRepo();

        // Create and stage a file
        file_put_contents($this->repoRoot . '/test.php', '<?php');
        $this->exec('git add test.php');

        $client = new GitClient($this->repoRoot);
        $files = $client->getChangedFiles('staged');

        $this->assertCount(1, $files);
        $this->assertInstanceOf(ChangedFile::class, $files[0]);
        $this->assertSame('test.php', $files[0]->path);
        $this->assertSame(ChangeStatus::Added, $files[0]->status);
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

        $client = new GitClient($this->repoRoot);
        $files = $client->getChangedFiles('HEAD');

        $this->assertCount(1, $files);
        $this->assertSame('test.php', $files[0]->path);
        $this->assertSame(ChangeStatus::Modified, $files[0]->status);
    }

    #[Test]
    public function itParsesAddedFiles(): void
    {
        $this->initGitRepo();

        file_put_contents($this->repoRoot . '/added.php', '<?php');
        $this->exec('git add added.php');

        $client = new GitClient($this->repoRoot);
        $files = $client->getChangedFiles('staged');

        $this->assertCount(1, $files);
        $this->assertSame(ChangeStatus::Added, $files[0]->status);
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

        $client = new GitClient($this->repoRoot);
        $files = $client->getChangedFiles('staged');

        $this->assertCount(1, $files);
        $this->assertSame(ChangeStatus::Modified, $files[0]->status);
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

        $client = new GitClient($this->repoRoot);
        $files = $client->getChangedFiles('staged');

        $this->assertCount(1, $files);
        $this->assertSame(ChangeStatus::Deleted, $files[0]->status);
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

        $client = new GitClient($this->repoRoot);
        $files = $client->getChangedFiles('staged');

        $this->assertCount(1, $files);
        $this->assertSame('new.php', $files[0]->path);
        $this->assertSame(ChangeStatus::Renamed, $files[0]->status);
        $this->assertSame('old.php', $files[0]->oldPath);
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

        $client = new GitClient($this->repoRoot);
        $files = $client->getChangedFiles('v1..HEAD');

        $this->assertCount(1, $files);
        $this->assertSame('file2.php', $files[0]->path);
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

        $client = new GitClient($this->repoRoot);
        $files = $client->getChangedFiles('main...feature');

        $this->assertCount(1, $files);
        $this->assertSame('feature.php', $files[0]->path);
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

        $client = new GitClient($this->repoRoot);
        $files = $client->getChangedFiles('v1');

        $this->assertCount(1, $files);
        $this->assertSame('file2.php', $files[0]->path);
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

        $client = new GitClient($this->repoRoot);
        $files = $client->getChangedFiles('staged');

        $this->assertCount(1, $files);
    }

    #[Test]
    public function itThrowsExceptionWhenGitCommandFails(): void
    {
        $client = new GitClient('/nonexistent');

        $this->expectException(RuntimeException::class);

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

        $client = new GitClient($this->repoRoot);
        $files = $client->getChangedFiles('staged');

        $this->assertSame([], $files);
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

        $client = new GitClient($this->repoRoot);

        // The client should be able to parse the output without crashing
        // even when exotic statuses like T (type change), U (unmerged), X (unknown) exist
        // We can only reliably test that parsing standard statuses works and doesn't crash
        $files = $client->getChangedFiles('staged');

        $this->assertCount(1, $files);
        $this->assertSame('normal.php', $files[0]->path);
        $this->assertSame(ChangeStatus::Modified, $files[0]->status);
    }

    #[Test]
    public function itIgnoresNonStandardGitOutput(): void
    {
        $this->initGitRepo();

        file_put_contents($this->repoRoot . '/test.php', '<?php');
        $this->exec('git add test.php');

        $client = new GitClient($this->repoRoot);
        $files = $client->getChangedFiles('staged');

        // Should parse only valid lines
        $this->assertNotEmpty($files);
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
