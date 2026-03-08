<?php

declare(strict_types=1);

namespace AiMessDetector\Tests\Unit\Infrastructure\Git;

use AiMessDetector\Infrastructure\Git\GitClient;
use AiMessDetector\Infrastructure\Git\GitFileDiscovery;
use AiMessDetector\Infrastructure\Git\GitScope;
use Generator;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use SplFileInfo;
use Symfony\Component\Process\Process;

#[CoversClass(GitFileDiscovery::class)]
final class GitFileDiscoveryTest extends TestCase
{
    private string $tempDir;

    protected function setUp(): void
    {
        $dir = sys_get_temp_dir() . '/git-discovery-test-' . uniqid();
        mkdir($dir);
        // Use realpath to normalize the path (macOS /var vs /private/var)
        $realPath = realpath($dir);
        if ($realPath === false) {
            throw new RuntimeException('Failed to resolve path: ' . $dir);
        }
        $this->tempDir = $realPath;
    }

    protected function tearDown(): void
    {
        if (is_dir($this->tempDir)) {
            $this->removeDirectory($this->tempDir);
        }
    }

    #[Test]
    public function itThrowsExceptionWhenNotInGitRepository(): void
    {
        $gitClient = new GitClient($this->tempDir);
        $discovery = new GitFileDiscovery($gitClient, new GitScope('staged'));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Git integration requires a git repository');

        iterator_to_array($discovery->discover('src'));
    }

    #[Test]
    public function itDiscoversChangedPhpFiles(): void
    {
        $this->initGitRepo();

        // Create and stage PHP files
        $this->createPhpFile('src/Service.php');
        $this->createPhpFile('src/Controller.php');
        $this->exec('git add src/Service.php src/Controller.php');

        $gitClient = new GitClient($this->tempDir);
        $discovery = new GitFileDiscovery($gitClient, new GitScope('staged'));
        $files = iterator_to_array($discovery->discover('src'));

        $this->assertCount(2, $files);
        $this->assertContainsOnlyInstancesOf(SplFileInfo::class, $files);
    }

    #[Test]
    public function itSkipsDeletedFiles(): void
    {
        $this->initGitRepo();

        // Create and commit files
        $this->createPhpFile('src/Service.php');
        $this->createPhpFile('src/Deleted.php');
        $this->exec('git add .');
        $this->exec('git commit -m "Initial"');

        // Delete one file and stage deletion
        unlink($this->tempDir . '/src/Deleted.php');
        $this->exec('git add src/Deleted.php');

        // Modify the other
        file_put_contents($this->tempDir . '/src/Service.php', '<?php echo "modified";');
        $this->exec('git add src/Service.php');

        $gitClient = new GitClient($this->tempDir);
        $discovery = new GitFileDiscovery($gitClient, new GitScope('staged'));
        $files = iterator_to_array($discovery->discover('src'));

        $this->assertCount(1, $files);
    }

    #[Test]
    public function itSkipsNonPhpFiles(): void
    {
        $this->initGitRepo();

        $this->createPhpFile('src/Service.php');
        file_put_contents($this->tempDir . '/README.md', '# Test');
        file_put_contents($this->tempDir . '/config.json', '{}');
        $this->exec('git add .');

        $gitClient = new GitClient($this->tempDir);
        $discovery = new GitFileDiscovery($gitClient, new GitScope('staged'));
        $files = iterator_to_array($discovery->discover('.'));

        $this->assertCount(1, $files);
    }

    #[Test]
    public function itFiltersFilesByPath(): void
    {
        $this->initGitRepo();

        $this->createPhpFile('src/Service.php');
        $this->createPhpFile('tests/TestCase.php');
        $this->exec('git add .');

        $gitClient = new GitClient($this->tempDir);
        $discovery = new GitFileDiscovery($gitClient, new GitScope('staged'));
        $files = iterator_to_array($discovery->discover('src'));

        $this->assertCount(1, $files);
        $key = array_key_first($files);
        $this->assertIsString($key);
        $this->assertStringContainsString('src/Service.php', $key);
    }

    #[Test]
    public function itFiltersFilesByMultiplePaths(): void
    {
        $this->initGitRepo();

        $this->createPhpFile('src/Service.php');
        $this->createPhpFile('tests/TestCase.php');
        $this->createPhpFile('lib/Helper.php');
        $this->exec('git add .');

        $gitClient = new GitClient($this->tempDir);
        $discovery = new GitFileDiscovery($gitClient, new GitScope('staged'));
        $files = iterator_to_array($discovery->discover(['src', 'tests']));

        $this->assertCount(2, $files);
    }

    #[Test]
    public function itAcceptsAllFilesWhenNoPathsSpecified(): void
    {
        $this->initGitRepo();

        $this->createPhpFile('src/Service.php');
        $this->createPhpFile('tests/TestCase.php');
        $this->exec('git add .');

        $gitClient = new GitClient($this->tempDir);
        $discovery = new GitFileDiscovery($gitClient, new GitScope('staged'));
        $files = iterator_to_array($discovery->discover([]));

        $this->assertCount(2, $files);
    }

    #[Test]
    public function itSkipsNonExistentFiles(): void
    {
        $this->initGitRepo();

        // Create and stage a file
        $this->createPhpFile('src/Service.php');
        $this->exec('git add src/Service.php');

        // Now delete it (but keep it staged)
        unlink($this->tempDir . '/src/Service.php');

        $gitClient = new GitClient($this->tempDir);
        $discovery = new GitFileDiscovery($gitClient, new GitScope('staged'));
        $files = iterator_to_array($discovery->discover('src'));

        $this->assertCount(0, $files);
    }

    #[Test]
    public function itReturnsSortedFiles(): void
    {
        $this->initGitRepo();

        $this->createPhpFile('src/Zebra.php');
        $this->createPhpFile('src/Alpha.php');
        $this->createPhpFile('src/Beta.php');
        $this->exec('git add .');

        $gitClient = new GitClient($this->tempDir);
        $discovery = new GitFileDiscovery($gitClient, new GitScope('staged'));
        $files = iterator_to_array($discovery->discover('src'));

        $keys = array_keys($files);
        $sorted = $keys;
        sort($sorted);

        $this->assertSame($sorted, $keys);
    }

    #[Test]
    public function itHandlesRenamedFiles(): void
    {
        $this->initGitRepo();

        // Create and commit original file
        $this->createPhpFile('src/Old.php');
        $this->exec('git add src/Old.php');
        $this->exec('git commit -m "Initial"');

        // Rename it
        rename($this->tempDir . '/src/Old.php', $this->tempDir . '/src/New.php');
        $this->exec('git add src/Old.php src/New.php');

        $gitClient = new GitClient($this->tempDir);
        $discovery = new GitFileDiscovery($gitClient, new GitScope('staged'));
        $files = iterator_to_array($discovery->discover('src'));

        $this->assertCount(1, $files);
        $key = array_key_first($files);
        $this->assertIsString($key);
        $this->assertStringContainsString('src/New.php', $key);
    }

    #[Test]
    public function itNormalizesPathsWithLeadingDotSlash(): void
    {
        $this->initGitRepo();

        $this->createPhpFile('src/Service.php');
        $this->exec('git add src/Service.php');

        $gitClient = new GitClient($this->tempDir);
        $discovery = new GitFileDiscovery($gitClient, new GitScope('staged'));
        $files = iterator_to_array($discovery->discover('./src'));

        $this->assertCount(1, $files);
    }

    #[Test]
    public function itDoesNotMatchSimilarPrefixedDirectories(): void
    {
        $this->initGitRepo();

        $this->createPhpFile('src/Service.php');
        $this->createPhpFile('src2/Other.php');
        $this->exec('git add .');

        $gitClient = new GitClient($this->tempDir);
        $discovery = new GitFileDiscovery($gitClient, new GitScope('staged'));
        $files = iterator_to_array($discovery->discover('src'));

        $this->assertCount(1, $files);
        $key = array_key_first($files);
        $this->assertIsString($key);
        $this->assertStringContainsString('src/Service.php', $key);
        $this->assertStringNotContainsString('src2', $key);
    }

    #[Test]
    public function itMatchesExactFilePath(): void
    {
        $this->initGitRepo();

        $this->createPhpFile('src/Service.php');
        $this->createPhpFile('src/Controller.php');
        $this->exec('git add .');

        $gitClient = new GitClient($this->tempDir);
        $discovery = new GitFileDiscovery($gitClient, new GitScope('staged'));
        $files = iterator_to_array($discovery->discover('src/Service.php'));

        $this->assertCount(1, $files);
        $key = array_key_first($files);
        $this->assertIsString($key);
        $this->assertStringContainsString('src/Service.php', $key);
    }

    #[Test]
    public function itHandlesDifferentGitScopes(): void
    {
        $this->initGitRepo();

        // Initial commit
        $this->createPhpFile('src/Base.php');
        $this->exec('git add src/Base.php');
        $this->exec('git commit -m "Base"');

        // New file
        $this->createPhpFile('src/Service.php');
        $this->exec('git add src/Service.php');

        $gitClient = new GitClient($this->tempDir);
        $discovery = new GitFileDiscovery($gitClient, new GitScope('staged'));
        $files = iterator_to_array($discovery->discover('src'));

        $this->assertCount(1, $files);
        $key = array_key_first($files);
        $this->assertIsString($key);
        $this->assertStringContainsString('src/Service.php', $key);
    }

    #[Test]
    public function itYieldsFilesAsGenerator(): void
    {
        $this->initGitRepo();

        $this->createPhpFile('src/Service.php');
        $this->exec('git add src/Service.php');

        $gitClient = new GitClient($this->tempDir);
        $discovery = new GitFileDiscovery($gitClient, new GitScope('staged'));
        $result = $discovery->discover('src');

        $this->assertInstanceOf(Generator::class, $result);
    }

    private function initGitRepo(): void
    {
        $this->exec('git init');
        $this->exec('git config user.email "test@example.com"');
        $this->exec('git config user.name "Test User"');
        $this->exec('git checkout -b main');
    }

    private function createPhpFile(string $relativePath): void
    {
        $fullPath = $this->tempDir . '/' . $relativePath;
        $dir = \dirname($fullPath);

        if (!is_dir($dir)) {
            mkdir($dir, 0777, true);
        }

        file_put_contents($fullPath, '<?php');
    }

    private function exec(string $command): void
    {
        $process = Process::fromShellCommandline(
            $command,
            $this->tempDir,
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
