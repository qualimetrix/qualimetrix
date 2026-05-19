<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Functional\Console\Command;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Baseline\BaselineGenerator;
use Qualimetrix\Baseline\BaselineLoader;
use Qualimetrix\Baseline\BaselineWriter;
use Qualimetrix\Baseline\ViolationHasher;
use Qualimetrix\Core\Path\RelativePath;
use Qualimetrix\Core\Symbol\SymbolPath;
use Qualimetrix\Core\Violation\Location;
use Qualimetrix\Core\Violation\Severity;
use Qualimetrix\Core\Violation\Violation;
use Qualimetrix\Infrastructure\Console\Command\BaselineCleanupCommand;
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
        $this->tempDir = sys_get_temp_dir() . '/qmx-test-' . uniqid();
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
        // Create a test PHP file. SymbolPath canonical keys carry project-relative paths
        // (RelativePath VO after ADR 0015 Phase 1c); the cleanup command resolves them
        // via file_exists() against the current working directory, so chdir into tempDir
        // for the test scope.
        $testRel = 'TestClass.php';
        $nonExistingRel = 'NonExisting.php';
        $testFile = $this->tempDir . '/' . $testRel;
        $nonExistingFile = $this->tempDir . '/' . $nonExistingRel;
        file_put_contents($testFile, '<?php class TestClass {}');

        $previousCwd = getcwd();
        chdir($this->tempDir);

        try {
            $violations = [
                new Violation(
                    location: new Location(RelativePath::fromString($testRel), 1),
                    symbolPath: SymbolPath::forFile(RelativePath::fromString($testRel)),
                    ruleName: 'test-rule',
                    violationCode: 'test-rule',
                    message: 'Test violation',
                    severity: Severity::Warning,
                ),
                new Violation(
                    location: new Location(RelativePath::fromString($nonExistingRel), 1),
                    symbolPath: SymbolPath::forFile(RelativePath::fromString($nonExistingRel)),
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

            self::assertSame(0, $commandTester->getStatusCode());
            $output = $commandTester->getDisplay();
            self::assertStringContainsString('Removed 1 stale entries from 1 symbols', $output);

            $loader = new BaselineLoader();
            $cleanedBaseline = $loader->load($baselinePath);
            self::assertSame(1, $cleanedBaseline->count());
            self::assertArrayHasKey('file:' . $testRel, $cleanedBaseline->entries);
            self::assertArrayNotHasKey('file:' . $nonExistingRel, $cleanedBaseline->entries);
        } finally {
            if ($previousCwd !== false) {
                chdir($previousCwd);
            }
        }
    }

    #[Test]
    public function itReportsNoStaleEntriesWhenBaselineIsClean(): void
    {
        $testRel = 'TestClass.php';
        $testFile = $this->tempDir . '/' . $testRel;
        file_put_contents($testFile, '<?php class TestClass {}');

        $previousCwd = getcwd();
        chdir($this->tempDir);

        try {
            $violations = [
                new Violation(
                    location: new Location(RelativePath::fromString($testRel), 1),
                    symbolPath: SymbolPath::forFile(RelativePath::fromString($testRel)),
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

            self::assertSame(0, $commandTester->getStatusCode());
            $output = $commandTester->getDisplay();
            self::assertStringContainsString('No stale entries found', $output);
        } finally {
            if ($previousCwd !== false) {
                chdir($previousCwd);
            }
        }
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
        self::assertSame(1, $commandTester->getStatusCode());
        $output = $commandTester->getDisplay();
        self::assertStringContainsString('Baseline file not found', $output);
    }

    #[Test]
    public function itShowsVerboseOutputWithRemovedSymbols(): void
    {
        $nonExistingRel = 'NonExisting.php';

        $previousCwd = getcwd();
        chdir($this->tempDir);

        $violations = [
            new Violation(
                location: new Location(RelativePath::fromString($nonExistingRel), 1),
                symbolPath: SymbolPath::forFile(RelativePath::fromString($nonExistingRel)),
                ruleName: 'test-rule',
                violationCode: 'test-rule',
                message: 'Test violation',
                severity: Severity::Warning,
            ),
        ];

        try {
            $baselineGenerator = new BaselineGenerator(new ViolationHasher());
            $baselineWriter = new BaselineWriter();
            $baselinePath = $this->tempDir . '/baseline.json';

            $baseline = $baselineGenerator->generate($violations);
            $baselineWriter->write($baseline, $baselinePath);

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

            self::assertSame(0, $commandTester->getStatusCode());
            $output = $commandTester->getDisplay();
            self::assertStringContainsString('Removed symbols:', $output);
            self::assertStringContainsString('NonExisting.php', $output);
        } finally {
            if ($previousCwd !== false) {
                chdir($previousCwd);
            }
        }
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
            'version' => 5,
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

        $files = array_diff((scandir($dir) !== false ? scandir($dir) : []), ['.', '..']);
        foreach ($files as $file) {
            $path = $dir . '/' . $file;
            is_dir($path) ? $this->removeDirectory($path) : unlink($path);
        }
        rmdir($dir);
    }
}
