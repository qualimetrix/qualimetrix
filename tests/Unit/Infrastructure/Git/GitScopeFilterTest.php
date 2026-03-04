<?php

declare(strict_types=1);

namespace AiMessDetector\Tests\Unit\Infrastructure\Git;

use AiMessDetector\Core\Violation\Location;
use AiMessDetector\Core\Violation\Severity;
use AiMessDetector\Core\Violation\SymbolPath;
use AiMessDetector\Core\Violation\Violation;
use AiMessDetector\Infrastructure\Git\GitClient;
use AiMessDetector\Infrastructure\Git\GitScope;
use AiMessDetector\Infrastructure\Git\GitScopeFilter;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\Process\Process;

#[CoversClass(GitScopeFilter::class)]
final class GitScopeFilterTest extends TestCase
{
    private string $tempDir;

    protected function setUp(): void
    {
        $dir = sys_get_temp_dir() . '/git-scope-filter-test-' . uniqid();
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
    public function itIncludesViolationsInChangedFiles(): void
    {
        $this->initGitRepo();

        $this->createPhpFileWithNamespace('src/Service.php', 'App\\Service');
        $this->exec('git add src/Service.php');

        $gitClient = new GitClient($this->tempDir);
        $filter = new GitScopeFilter($gitClient, new GitScope('staged'));

        $violation = new Violation(
            location: new Location($this->tempDir . '/src/Service.php', 10),
            symbolPath: SymbolPath::forClass('App\\Service', 'UserService'),
            ruleName: 'complexity',
            message: 'Too complex',
            severity: Severity::Warning,
        );

        $this->assertTrue($filter->shouldInclude($violation));
    }

    #[Test]
    public function itExcludesViolationsInUnchangedFiles(): void
    {
        $this->initGitRepo();

        $this->createPhpFileWithNamespace('src/Service.php', 'App\\Service');
        $this->exec('git add src/Service.php');

        $gitClient = new GitClient($this->tempDir);
        $filter = new GitScopeFilter($gitClient, new GitScope('staged'));

        $violation = new Violation(
            location: new Location($this->tempDir . '/src/Controller.php', 10),
            symbolPath: SymbolPath::forClass('App\\Controller', 'HomeController'),
            ruleName: 'complexity',
            message: 'Too complex',
            severity: Severity::Warning,
        );

        $this->assertFalse($filter->shouldInclude($violation));
    }

    #[Test]
    public function itIncludesParentNamespaceViolationsByDefault(): void
    {
        $this->initGitRepo();

        $this->createPhpFileWithNamespace('src/Service/User/UserService.php', 'App\\Service\\User');
        $this->exec('git add src/Service/User/UserService.php');

        $gitClient = new GitClient($this->tempDir);
        $filter = new GitScopeFilter($gitClient, new GitScope('staged'));

        // Violation for parent namespace
        $violation = new Violation(
            location: new Location($this->tempDir . '/src/Service/Aggregated.php', null),
            symbolPath: SymbolPath::forNamespace('App\\Service'),
            ruleName: 'size',
            message: 'Namespace too large',
            severity: Severity::Warning,
        );

        $this->assertTrue($filter->shouldInclude($violation));
    }

    #[Test]
    public function itExcludesParentNamespaceViolationsWhenStrictModeEnabled(): void
    {
        $this->initGitRepo();

        $this->createPhpFileWithNamespace('src/Service/User/UserService.php', 'App\\Service\\User');
        $this->exec('git add src/Service/User/UserService.php');

        $gitClient = new GitClient($this->tempDir);
        $filter = new GitScopeFilter(
            $gitClient,
            new GitScope('staged'),
            includeParentNamespaces: false,
        );

        // Violation for parent namespace
        $violation = new Violation(
            location: new Location($this->tempDir . '/src/Service/Aggregated.php', null),
            symbolPath: SymbolPath::forNamespace('App\\Service'),
            ruleName: 'size',
            message: 'Namespace too large',
            severity: Severity::Warning,
        );

        $this->assertFalse($filter->shouldInclude($violation));
    }

    #[Test]
    public function itSkipsDeletedFiles(): void
    {
        $this->initGitRepo();

        // Create and commit file
        $this->createPhpFileWithNamespace('src/Service.php', 'App\\Service');
        $this->exec('git add src/Service.php');
        $this->exec('git commit -m "Initial"');

        // Delete and stage
        unlink($this->tempDir . '/src/Service.php');
        $this->exec('git add src/Service.php');

        $gitClient = new GitClient($this->tempDir);
        $filter = new GitScopeFilter($gitClient, new GitScope('staged'));

        $violation = new Violation(
            location: new Location($this->tempDir . '/src/Service.php', 10),
            symbolPath: SymbolPath::forClass('App\\Service', 'UserService'),
            ruleName: 'complexity',
            message: 'Too complex',
            severity: Severity::Warning,
        );

        $this->assertFalse($filter->shouldInclude($violation));
    }

    #[Test]
    public function itSkipsNonPhpFiles(): void
    {
        $this->initGitRepo();

        file_put_contents($this->tempDir . '/README.md', '# Test');
        $this->exec('git add README.md');

        $gitClient = new GitClient($this->tempDir);
        $filter = new GitScopeFilter($gitClient, new GitScope('staged'));

        $violation = new Violation(
            location: new Location($this->tempDir . '/README.md', 10),
            symbolPath: SymbolPath::forFile($this->tempDir . '/README.md'),
            ruleName: 'complexity',
            message: 'Too complex',
            severity: Severity::Warning,
        );

        $this->assertFalse($filter->shouldInclude($violation));
    }

    #[Test]
    public function itBuildsNamespaceIndexIncludingAllParents(): void
    {
        $this->initGitRepo();

        $this->createPhpFileWithNamespace(
            'src/Service/User/Profile/ProfileService.php',
            'App\\Service\\User\\Profile',
        );
        $this->exec('git add src/Service/User/Profile/ProfileService.php');

        $gitClient = new GitClient($this->tempDir);
        $filter = new GitScopeFilter($gitClient, new GitScope('staged'));

        // All parent namespaces should be included
        $namespaces = [
            'App\\Service\\User\\Profile',
            'App\\Service\\User',
            'App\\Service',
            'App',
        ];

        foreach ($namespaces as $namespace) {
            $violation = new Violation(
                location: new Location($this->tempDir . '/some/file.php', null),
                symbolPath: SymbolPath::forNamespace($namespace),
                ruleName: 'size',
                message: 'Namespace too large',
                severity: Severity::Warning,
            );

            $this->assertTrue(
                $filter->shouldInclude($violation),
                "Expected namespace '$namespace' to be included",
            );
        }
    }

    #[Test]
    public function itHandlesFilesWithoutNamespace(): void
    {
        $this->initGitRepo();

        // File without namespace declaration - still should be included by file path match
        $this->createPhpFile('src/legacy.php');
        $this->exec('git add src/legacy.php');

        $gitClient = new GitClient($this->tempDir);
        $filter = new GitScopeFilter($gitClient, new GitScope('staged'));

        // Violation in the changed file should be included
        $violation = new Violation(
            location: new Location($this->tempDir . '/src/legacy.php', 10),
            symbolPath: SymbolPath::forClass('', 'LegacyClass'),
            ruleName: 'complexity',
            message: 'Too complex',
            severity: Severity::Warning,
        );

        // File path match should work even without namespace
        $this->assertTrue($filter->shouldInclude($violation));
    }

    #[Test]
    public function itHandlesMultipleChangedFiles(): void
    {
        $this->initGitRepo();

        $this->createPhpFileWithNamespace('src/Service.php', 'App');
        $this->createPhpFileWithNamespace('src/Controller.php', 'App');
        $this->exec('git add src/Service.php src/Controller.php');

        // Also create and commit a file that will be renamed
        $this->createPhpFileWithNamespace('src/OldRepository.php', 'App');
        $this->exec('git add src/OldRepository.php');
        $this->exec('git commit -m "Initial"');

        // Rename it
        rename($this->tempDir . '/src/OldRepository.php', $this->tempDir . '/src/Repository.php');
        $this->exec('git add src/OldRepository.php src/Repository.php');

        $gitClient = new GitClient($this->tempDir);
        $filter = new GitScopeFilter($gitClient, new GitScope('staged'));

        $violations = [
            new Violation(
                location: new Location($this->tempDir . '/src/Service.php', 10),
                symbolPath: SymbolPath::forClass('App', 'Service'),
                ruleName: 'complexity',
                message: 'Too complex',
                severity: Severity::Warning,
            ),
            new Violation(
                location: new Location($this->tempDir . '/src/Controller.php', 20),
                symbolPath: SymbolPath::forClass('App', 'Controller'),
                ruleName: 'size',
                message: 'Too large',
                severity: Severity::Warning,
            ),
            new Violation(
                location: new Location($this->tempDir . '/src/Repository.php', 30),
                symbolPath: SymbolPath::forClass('App', 'Repository'),
                ruleName: 'coupling',
                message: 'Too coupled',
                severity: Severity::Warning,
            ),
        ];

        foreach ($violations as $violation) {
            $this->assertTrue($filter->shouldInclude($violation));
        }
    }

    #[Test]
    public function itUsesSpecifiedGitScope(): void
    {
        $this->initGitRepo();

        // Initial commit on main
        $this->createPhpFileWithNamespace('src/Base.php', 'App');
        $this->exec('git add src/Base.php');
        $this->exec('git commit -m "Base"');

        // Create feature branch
        $this->exec('git checkout -b feature');

        // Add new file on feature branch
        $this->createPhpFileWithNamespace('src/Service.php', 'App\\Service');
        $this->exec('git add src/Service.php');
        $this->exec('git commit -m "Feature"');

        $gitClient = new GitClient($this->tempDir);
        $filter = new GitScopeFilter($gitClient, new GitScope('main..HEAD'));

        // Service.php was changed in main..HEAD, should be included
        $violation = new Violation(
            location: new Location($this->tempDir . '/src/Service.php', 10),
            symbolPath: SymbolPath::forClass('App\\Service', 'UserService'),
            ruleName: 'complexity',
            message: 'Too complex',
            severity: Severity::Warning,
        );

        $this->assertTrue($filter->shouldInclude($violation));

        // Base.php was NOT changed in main..HEAD (it's in both), should not be included
        // However, it shares namespace 'App' with Service.php which is in 'App\Service'
        // So we need to check with a different namespace
        $baseViolation = new Violation(
            location: new Location($this->tempDir . '/src/Base.php', 10),
            symbolPath: SymbolPath::forClass('App', 'Base'),
            ruleName: 'complexity',
            message: 'Too complex',
            severity: Severity::Warning,
        );

        // This should be false because Base.php file path is not in changed files
        // and namespace 'App' is a parent of 'App\Service' so it would be included
        // Let's use strict mode to test this properly
        $strictFilter = new GitScopeFilter($gitClient, new GitScope('main..HEAD'), includeParentNamespaces: false);
        $this->assertFalse($strictFilter->shouldInclude($baseViolation));
    }

    #[Test]
    public function itIncludesViolationsForStagedFilesEvenIfDeletedLocally(): void
    {
        $this->initGitRepo();

        // Stage a file and then delete it locally
        // This simulates a file that was added but deleted before commit
        $this->createPhpFileWithNamespace('src/Deleted.php', 'App');
        $this->exec('git add src/Deleted.php');
        unlink($this->tempDir . '/src/Deleted.php');

        $gitClient = new GitClient($this->tempDir);
        $filter = new GitScopeFilter($gitClient, new GitScope('staged'));

        $violation = new Violation(
            location: new Location($this->tempDir . '/src/Deleted.php', 10),
            symbolPath: SymbolPath::forClass('App', 'Deleted'),
            ruleName: 'complexity',
            message: 'Too complex',
            severity: Severity::Warning,
        );

        // File is still in Git's staged changes, so violations should be included
        // even though the file doesn't exist locally
        $this->assertTrue($filter->shouldInclude($violation));
    }

    #[Test]
    public function itHandlesViolationsWithNullNamespace(): void
    {
        $this->initGitRepo();

        $this->createPhpFileWithNamespace('src/Service.php', 'App\\Service');
        $this->exec('git add src/Service.php');

        $gitClient = new GitClient($this->tempDir);
        $filter = new GitScopeFilter($gitClient, new GitScope('staged'));

        // File-level violation - should match by file path
        $violation = new Violation(
            location: new Location($this->tempDir . '/src/Service.php', null),
            symbolPath: SymbolPath::forFile($this->tempDir . '/src/Service.php'),
            ruleName: 'size',
            message: 'File too large',
            severity: Severity::Warning,
        );

        // Should be included because file path matches
        $this->assertTrue($filter->shouldInclude($violation));
    }

    #[Test]
    public function itExtractsNamespaceFromFileContent(): void
    {
        $this->initGitRepo();

        $content = <<<'PHP'
<?php

declare(strict_types=1);

namespace App\Complex\Nested;

class Service
{
}
PHP;

        $this->createPhpFileWithContent('src/Complex/Nested/Service.php', $content);
        $this->exec('git add src/Complex/Nested/Service.php');

        $gitClient = new GitClient($this->tempDir);
        $filter = new GitScopeFilter($gitClient, new GitScope('staged'));

        $violation = new Violation(
            location: new Location($this->tempDir . '/some/file.php', null),
            symbolPath: SymbolPath::forNamespace('App\\Complex\\Nested'),
            ruleName: 'size',
            message: 'Namespace too large',
            severity: Severity::Warning,
        );

        $this->assertTrue($filter->shouldInclude($violation));
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
        $this->createPhpFileWithContent($relativePath, '<?php');
    }

    private function createPhpFileWithNamespace(string $relativePath, string $namespace): void
    {
        $content = "<?php\n\nnamespace {$namespace};";
        $this->createPhpFileWithContent($relativePath, $content);
    }

    private function createPhpFileWithContent(string $relativePath, string $content): void
    {
        $fullPath = $this->tempDir . '/' . $relativePath;
        $dir = \dirname($fullPath);

        if (!is_dir($dir)) {
            mkdir($dir, 0777, true);
        }

        file_put_contents($fullPath, $content);
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
