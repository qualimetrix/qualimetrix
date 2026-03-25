<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Functional\Console\Command;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Infrastructure\Console\Command\HookInstallCommand;
use Qualimetrix\Infrastructure\Git\GitRepositoryLocator;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;

#[CoversClass(HookInstallCommand::class)]
final class HookInstallCommandTest extends TestCase
{
    private string $tempDir;
    private string $gitDir;

    protected function setUp(): void
    {
        // Create temporary directory with fake git structure
        $this->tempDir = sys_get_temp_dir() . '/qmx-test-' . uniqid();
        mkdir($this->tempDir, 0777, true);

        // Create .git/hooks directory
        $this->gitDir = $this->tempDir . '/.git';
        mkdir($this->gitDir . '/hooks', 0777, true);

        // Create fake pre-commit-hook.sh script
        $scriptsDir = $this->tempDir . '/scripts';
        mkdir($scriptsDir, 0777, true);
        file_put_contents(
            $scriptsDir . '/pre-commit-hook.sh',
            "#!/bin/bash\n# Qualimetrix pre-commit hook\necho 'Running Qualimetrix'\n",
        );

        // Change to temp directory for test
        chdir($this->tempDir);
    }

    protected function tearDown(): void
    {
        // Clean up temporary directory
        if (is_dir($this->tempDir)) {
            $this->removeDirectory($this->tempDir);
        }
    }

    #[Test]
    public function itInstallsPreCommitHook(): void
    {
        $command = new HookInstallCommand(new GitRepositoryLocator());

        $application = new Application();
        $application->addCommand($command);

        $commandTester = new CommandTester($command);
        $commandTester->execute([]);

        // Assert success
        $this->assertSame(0, $commandTester->getStatusCode());
        $output = $commandTester->getDisplay();
        $this->assertStringContainsString('Pre-commit hook installed', $output);

        // Verify hook was created
        $hookPath = $this->gitDir . '/hooks/pre-commit';
        $this->assertFileExists($hookPath);

        // Verify it's a symlink
        $this->assertTrue(is_link($hookPath));
    }

    #[Test]
    public function itFailsWhenHookExistsWithoutForceFlag(): void
    {
        // Create existing hook
        $hookPath = $this->gitDir . '/hooks/pre-commit';
        file_put_contents($hookPath, "#!/bin/bash\necho 'Existing hook'\n");

        $command = new HookInstallCommand(new GitRepositoryLocator());

        $application = new Application();
        $application->addCommand($command);

        $commandTester = new CommandTester($command);
        $commandTester->execute([]);

        // Assert failure
        $this->assertSame(1, $commandTester->getStatusCode());
        $output = $commandTester->getDisplay();
        $this->assertStringContainsString('Pre-commit hook already exists', $output);
        $this->assertStringContainsString('Use --force to overwrite', $output);
    }

    #[Test]
    public function itOverwritesExistingHookWithForceFlag(): void
    {
        // Create existing hook
        $hookPath = $this->gitDir . '/hooks/pre-commit';
        file_put_contents($hookPath, "#!/bin/bash\necho 'Old hook'\n");

        $command = new HookInstallCommand(new GitRepositoryLocator());

        $application = new Application();
        $application->addCommand($command);

        $commandTester = new CommandTester($command);
        $commandTester->execute(['--force' => true]);

        // Assert success
        $this->assertSame(0, $commandTester->getStatusCode());
        $output = $commandTester->getDisplay();
        $this->assertStringContainsString('Pre-commit hook installed', $output);
        $this->assertStringContainsString('backed up', $output);

        // Verify backup was created
        $backupPath = $hookPath . '.backup';
        $this->assertFileExists($backupPath);

        // Verify old hook content in backup
        $backupContent = file_get_contents($backupPath);
        $this->assertIsString($backupContent);
        $this->assertStringContainsString('Old hook', $backupContent);
    }

    #[Test]
    public function itFailsWhenNotInGitRepository(): void
    {
        // Remove .git directory
        $this->removeDirectory($this->gitDir);

        $command = new HookInstallCommand(new GitRepositoryLocator());

        $application = new Application();
        $application->addCommand($command);

        $commandTester = new CommandTester($command);
        $commandTester->execute([]);

        // Assert failure
        $this->assertSame(1, $commandTester->getStatusCode());
        $output = $commandTester->getDisplay();
        $this->assertStringContainsString('Not a git repository', $output);
    }

    #[Test]
    public function itMakesHookExecutable(): void
    {
        $command = new HookInstallCommand(new GitRepositoryLocator());

        $application = new Application();
        $application->addCommand($command);

        $commandTester = new CommandTester($command);
        $commandTester->execute([]);

        // Assert success
        $this->assertSame(0, $commandTester->getStatusCode());

        // Verify hook is executable
        $hookPath = $this->gitDir . '/hooks/pre-commit';
        $this->assertFileExists($hookPath);
        $this->assertTrue(is_executable($hookPath));
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
