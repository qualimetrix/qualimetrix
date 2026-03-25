<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Functional\Console\Command;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Infrastructure\Console\Command\HookUninstallCommand;
use Qualimetrix\Infrastructure\Git\GitRepositoryLocator;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;

#[CoversClass(HookUninstallCommand::class)]
final class HookUninstallCommandTest extends TestCase
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
    public function itRemovesInstalledHook(): void
    {
        // Create our hook
        $hookPath = $this->gitDir . '/hooks/pre-commit';
        file_put_contents($hookPath, "#!/bin/bash\n# Qualimetrix pre-commit hook\necho 'Running hook'\n");
        chmod($hookPath, 0755);

        $command = new HookUninstallCommand(new GitRepositoryLocator());

        $application = new Application();
        $application->addCommand($command);

        $commandTester = new CommandTester($command);
        $commandTester->execute([]);

        // Assert success
        $this->assertSame(0, $commandTester->getStatusCode());
        $output = $commandTester->getDisplay();
        $this->assertStringContainsString('Pre-commit hook removed', $output);

        // Verify hook was removed
        $this->assertFileDoesNotExist($hookPath);
    }

    #[Test]
    public function itReportsNothingToUninstallWhenHookNotFound(): void
    {
        $command = new HookUninstallCommand(new GitRepositoryLocator());

        $application = new Application();
        $application->addCommand($command);

        $commandTester = new CommandTester($command);
        $commandTester->execute([]);

        // Assert success
        $this->assertSame(0, $commandTester->getStatusCode());
        $output = $commandTester->getDisplay();
        $this->assertStringContainsString('Pre-commit hook not found', $output);
        $this->assertStringContainsString('Nothing to uninstall', $output);
    }

    #[Test]
    public function itRefusesToRemoveThirdPartyHook(): void
    {
        // Create third-party hook (without our marker)
        $hookPath = $this->gitDir . '/hooks/pre-commit';
        file_put_contents($hookPath, "#!/bin/bash\necho 'Some other hook'\n");
        chmod($hookPath, 0755);

        $command = new HookUninstallCommand(new GitRepositoryLocator());

        $application = new Application();
        $application->addCommand($command);

        $commandTester = new CommandTester($command);
        $commandTester->execute([]);

        // Assert failure
        $this->assertSame(1, $commandTester->getStatusCode());
        $output = $commandTester->getDisplay();
        $this->assertStringContainsString('not an Qualimetrix hook', $output);
        $this->assertStringContainsString('Will not remove third-party hook', $output);

        // Verify hook still exists
        $this->assertFileExists($hookPath);
    }

    #[Test]
    public function itRestoresBackupWhenRequested(): void
    {
        // Create our hook
        $hookPath = $this->gitDir . '/hooks/pre-commit';
        file_put_contents($hookPath, "#!/bin/bash\n# Qualimetrix pre-commit hook\necho 'Running hook'\n");
        chmod($hookPath, 0755);

        // Create backup
        $backupPath = $hookPath . '.backup';
        file_put_contents($backupPath, "#!/bin/bash\necho 'Backup hook'\n");

        $command = new HookUninstallCommand(new GitRepositoryLocator());

        $application = new Application();
        $application->addCommand($command);

        $commandTester = new CommandTester($command);
        $commandTester->execute(['--restore-backup' => true]);

        // Assert success
        $this->assertSame(0, $commandTester->getStatusCode());
        $output = $commandTester->getDisplay();
        $this->assertStringContainsString('Pre-commit hook removed', $output);
        $this->assertStringContainsString('Backup restored', $output);

        // Verify hook was restored from backup
        $this->assertFileExists($hookPath);
        $content = file_get_contents($hookPath);
        $this->assertIsString($content);
        $this->assertStringContainsString('Backup hook', $content);

        // Verify restored hook is executable
        $this->assertTrue(is_executable($hookPath));
    }

    #[Test]
    public function itReportsNoBackupToRestore(): void
    {
        // Create our hook without backup
        $hookPath = $this->gitDir . '/hooks/pre-commit';
        file_put_contents($hookPath, "#!/bin/bash\n# Qualimetrix pre-commit hook\necho 'Running hook'\n");
        chmod($hookPath, 0755);

        $command = new HookUninstallCommand(new GitRepositoryLocator());

        $application = new Application();
        $application->addCommand($command);

        $commandTester = new CommandTester($command);
        $commandTester->execute(['--restore-backup' => true]);

        // Assert success (hook removed successfully)
        $this->assertSame(0, $commandTester->getStatusCode());
        $output = $commandTester->getDisplay();
        $this->assertStringContainsString('Pre-commit hook removed', $output);
        $this->assertStringContainsString('No backup found to restore', $output);
    }

    #[Test]
    public function itInformsAboutBackupWithoutRestoringIt(): void
    {
        // Create our hook
        $hookPath = $this->gitDir . '/hooks/pre-commit';
        file_put_contents($hookPath, "#!/bin/bash\n# Qualimetrix pre-commit hook\necho 'Running hook'\n");
        chmod($hookPath, 0755);

        // Create backup
        $backupPath = $hookPath . '.backup';
        file_put_contents($backupPath, "#!/bin/bash\necho 'Backup hook'\n");

        $command = new HookUninstallCommand(new GitRepositoryLocator());

        $application = new Application();
        $application->addCommand($command);

        $commandTester = new CommandTester($command);
        $commandTester->execute([]); // Without --restore-backup flag

        // Assert success
        $this->assertSame(0, $commandTester->getStatusCode());
        $output = $commandTester->getDisplay();
        $this->assertStringContainsString('Pre-commit hook removed', $output);
        $this->assertStringContainsString('Backup file exists', $output);
        $this->assertStringContainsString('Use --restore-backup to restore it', $output);

        // Verify hook was removed but backup still exists
        $this->assertFileDoesNotExist($hookPath);
        $this->assertFileExists($backupPath);
    }

    #[Test]
    public function itFailsWhenNotInGitRepository(): void
    {
        // Remove .git directory
        $this->removeDirectory($this->gitDir);

        $command = new HookUninstallCommand(new GitRepositoryLocator());

        $application = new Application();
        $application->addCommand($command);

        $commandTester = new CommandTester($command);
        $commandTester->execute([]);

        // Assert failure
        $this->assertSame(1, $commandTester->getStatusCode());
        $output = $commandTester->getDisplay();
        $this->assertStringContainsString('Not a git repository', $output);
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
