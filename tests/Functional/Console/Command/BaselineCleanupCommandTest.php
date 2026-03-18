<?php

declare(strict_types=1);

namespace AiMessDetector\Tests\Functional\Console\Command;

use AiMessDetector\Baseline\BaselineGenerator;
use AiMessDetector\Baseline\BaselineLoader;
use AiMessDetector\Baseline\BaselineWriter;
use AiMessDetector\Baseline\ViolationHasher;
use AiMessDetector\Core\Symbol\SymbolPath;
use AiMessDetector\Core\Violation\Location;
use AiMessDetector\Core\Violation\Severity;
use AiMessDetector\Core\Violation\Violation;
use AiMessDetector\Infrastructure\Console\Command\BaselineCleanupCommand;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Tester\CommandTester;

#[CoversClass(BaselineCleanupCommand::class)]
final class BaselineCleanupCommandTest extends TestCase
{
    private string $tempDir;

    protected function setUp(): void
    {
        // Create temporary directory for test files
        $this->tempDir = sys_get_temp_dir() . '/aimd-test-' . uniqid();
        mkdir($this->tempDir, 0777, true);
    }

    protected function tearDown(): void
    {
        // Clean up temporary directory
        if (is_dir($this->tempDir)) {
            $this->removeDirectory($this->tempDir);
        }
    }

    #[Test]
    public function itRemovesStaleEntriesFromBaseline(): void
    {
        // Create a test PHP file
        $testFile = $this->tempDir . '/TestClass.php';
        file_put_contents($testFile, '<?php class TestClass {}');

        $nonExistingFile = $this->tempDir . '/NonExisting.php';

        // Create baseline with entry for existing and non-existing file
        // Use SymbolPath::forFile() which produces "file:path" canonical format
        // that the cleanup command can verify via file_exists()
        $violations = [
            new Violation(
                location: new Location($testFile, 1),
                symbolPath: SymbolPath::forFile($testFile),
                ruleName: 'test-rule',
                violationCode: 'test-rule',
                message: 'Test violation',
                severity: Severity::Warning,
            ),
            new Violation(
                location: new Location($nonExistingFile, 1),
                symbolPath: SymbolPath::forFile($nonExistingFile),
                ruleName: 'test-rule',
                violationCode: 'test-rule',
                message: 'Test violation',
                severity: Severity::Warning,
            ),
        ];

        $baselineGenerator = new BaselineGenerator(new ViolationHasher());
        $baselineWriter = new BaselineWriter();
        $baselinePath = $this->tempDir . '/baseline.json';

        $baseline = $baselineGenerator->generate($violations);
        $baselineWriter->write($baseline, $baselinePath);

        // Run cleanup command
        $command = new BaselineCleanupCommand(
            new BaselineLoader(),
            $baselineWriter,
        );

        $application = new Application();
        $application->addCommand($command);

        $commandTester = new CommandTester($command);
        $commandTester->execute([
            'baseline' => $baselinePath,
        ]);

        // Assert success
        $this->assertSame(0, $commandTester->getStatusCode());
        $output = $commandTester->getDisplay();
        $this->assertStringContainsString('Removed 1 stale entries from 1 symbols', $output);

        // Verify baseline was cleaned
        $loader = new BaselineLoader();
        $cleanedBaseline = $loader->load($baselinePath);
        $this->assertSame(1, $cleanedBaseline->count());
        $this->assertArrayHasKey('file:' . $testFile, $cleanedBaseline->entries);
        $this->assertArrayNotHasKey('file:' . $nonExistingFile, $cleanedBaseline->entries);
    }

    #[Test]
    public function itReportsNoStaleEntriesWhenBaselineIsClean(): void
    {
        // Create a test PHP file
        $testFile = $this->tempDir . '/TestClass.php';
        file_put_contents($testFile, '<?php class TestClass {}');

        // Create baseline with only existing file
        // Use SymbolPath::forFile() which produces "file:path" canonical format
        $violations = [
            new Violation(
                location: new Location($testFile, 1),
                symbolPath: SymbolPath::forFile($testFile),
                ruleName: 'test-rule',
                violationCode: 'test-rule',
                message: 'Test violation',
                severity: Severity::Warning,
            ),
        ];

        $baselineGenerator = new BaselineGenerator(new ViolationHasher());
        $baselineWriter = new BaselineWriter();
        $baselinePath = $this->tempDir . '/baseline.json';

        $baseline = $baselineGenerator->generate($violations);
        $baselineWriter->write($baseline, $baselinePath);

        // Run cleanup command
        $command = new BaselineCleanupCommand(
            new BaselineLoader(),
            $baselineWriter,
        );

        $application = new Application();
        $application->addCommand($command);

        $commandTester = new CommandTester($command);
        $commandTester->execute([
            'baseline' => $baselinePath,
        ]);

        // Assert success
        $this->assertSame(0, $commandTester->getStatusCode());
        $output = $commandTester->getDisplay();
        $this->assertStringContainsString('No stale entries found', $output);
    }

    #[Test]
    public function itFailsWhenBaselineFileDoesNotExist(): void
    {
        $baselinePath = $this->tempDir . '/non-existing-baseline.json';

        $command = new BaselineCleanupCommand(
            new BaselineLoader(),
            new BaselineWriter(),
        );

        $application = new Application();
        $application->addCommand($command);

        $commandTester = new CommandTester($command);
        $commandTester->execute([
            'baseline' => $baselinePath,
        ]);

        // Assert failure
        $this->assertSame(1, $commandTester->getStatusCode());
        $output = $commandTester->getDisplay();
        $this->assertStringContainsString('Baseline file not found', $output);
    }

    #[Test]
    public function itShowsVerboseOutputWithRemovedSymbols(): void
    {
        $nonExistingFile = $this->tempDir . '/NonExisting.php';

        // Create baseline with non-existing file
        // Use SymbolPath::forFile() which produces "file:path" canonical format
        $violations = [
            new Violation(
                location: new Location($nonExistingFile, 1),
                symbolPath: SymbolPath::forFile($nonExistingFile),
                ruleName: 'test-rule',
                violationCode: 'test-rule',
                message: 'Test violation',
                severity: Severity::Warning,
            ),
        ];

        $baselineGenerator = new BaselineGenerator(new ViolationHasher());
        $baselineWriter = new BaselineWriter();
        $baselinePath = $this->tempDir . '/baseline.json';

        $baseline = $baselineGenerator->generate($violations);
        $baselineWriter->write($baseline, $baselinePath);

        // Run cleanup command with verbose flag
        $command = new BaselineCleanupCommand(
            new BaselineLoader(),
            $baselineWriter,
        );

        $application = new Application();
        $application->addCommand($command);

        $commandTester = new CommandTester($command);
        $commandTester->execute(
            ['baseline' => $baselinePath],
            ['verbosity' => OutputInterface::VERBOSITY_VERBOSE],
        );

        // Assert success and verbose output
        $this->assertSame(0, $commandTester->getStatusCode());
        $output = $commandTester->getDisplay();
        $this->assertStringContainsString('Removed symbols:', $output);
        $this->assertStringContainsString('NonExisting.php', $output);
    }

    #[Test]
    public function itHandlesPortableRelativePathsInBaseline(): void
    {
        // Create a file structure simulating a project
        $projectDir = $this->tempDir . '/project';
        mkdir($projectDir . '/src', 0777, true);
        $existingFile = $projectDir . '/src/Existing.php';
        file_put_contents($existingFile, '<?php class Existing {}');

        // Write a baseline with relative file: paths (portable format)
        $baselinePath = $projectDir . '/baseline.json';
        $json = json_encode([
            'version' => 4,
            'generated' => '2025-12-08T10:00:00+00:00',
            'count' => 2,
            'violationCount' => 2,
            'symbolCount' => 2,
            'violations' => [
                'file:src/Existing.php' => [
                    ['rule' => 'size.loc', 'hash' => 'abc123'],
                ],
                'file:src/Removed.php' => [
                    ['rule' => 'size.loc', 'hash' => 'def456'],
                ],
            ],
        ], \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES);
        file_put_contents($baselinePath, $json);

        // Run cleanup from the project directory
        $originalDir = getcwd();
        chdir($projectDir);

        try {
            $command = new BaselineCleanupCommand(
                new BaselineLoader(),
                new BaselineWriter(),
            );

            $application = new Application();
            $application->addCommand($command);

            $commandTester = new CommandTester($command);
            $commandTester->execute(['baseline' => $baselinePath]);

            self::assertSame(0, $commandTester->getStatusCode());
            self::assertStringContainsString('Removed 1 stale entries', $commandTester->getDisplay());

            // Verify the cleaned baseline still has relative paths
            $data = json_decode((string) file_get_contents($baselinePath), true);
            self::assertArrayHasKey('file:src/Existing.php', $data['violations']);
            self::assertArrayNotHasKey('file:src/Removed.php', $data['violations']);
        } finally {
            chdir((string) $originalDir);
        }
    }

    /**
     * Recursively remove a directory.
     */
    private function removeDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        $files = array_diff(scandir($dir) ?: [], ['.', '..']);
        foreach ($files as $file) {
            $path = $dir . '/' . $file;
            is_dir($path) ? $this->removeDirectory($path) : unlink($path);
        }
        rmdir($dir);
    }
}
