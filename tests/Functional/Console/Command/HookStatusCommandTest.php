<?php

declare(strict_types=1);

namespace AiMessDetector\Tests\Functional\Console\Command;

use AiMessDetector\Infrastructure\Console\Command\HookStatusCommand;
use AiMessDetector\Infrastructure\Git\GitRepositoryLocator;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;

#[CoversClass(HookStatusCommand::class)]
final class HookStatusCommandTest extends TestCase
{
    private string $tempDir;
    private string $gitDir;

    protected function setUp(): void
    {
        // Create temporary directory with fake git structure
        $this->tempDir = sys_get_temp_dir() . '/aimd-test-' . uniqid();
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
    public function itReportsHookNotInstalled(): void
    {
        $command = new HookStatusCommand(new GitRepositoryLocator());

        $application = new Application();
        $application->addCommand($command);

        $commandTester = new CommandTester($command);
        $commandTester->execute([]);

        // Assert success (status always succeeds)
        $this->assertSame(0, $commandTester->getStatusCode());
        $output = $commandTester->getDisplay();
        $this->assertStringContainsString('NOT INSTALLED', $output);
        $this->assertStringContainsString('To install the hook', $output);
    }

    #[Test]
    public function itReportsInstalledHookAsSymlink(): void
    {
        // Create hook as symlink
        $hookPath = $this->gitDir . '/hooks/pre-commit';
        $targetPath = '/fake/target/pre-commit-hook.sh';
        symlink($targetPath, $hookPath);

        // Add marker to indicate it's our hook
        $tempScript = $this->tempDir . '/temp-script.sh';
        file_put_contents($tempScript, "#!/bin/bash\n# AI Mess Detector pre-commit hook\necho 'test'\n");
        unlink($hookPath);
        symlink($tempScript, $hookPath);
        chmod($hookPath, 0755);

        $command = new HookStatusCommand(new GitRepositoryLocator());

        $application = new Application();
        $application->addCommand($command);

        $commandTester = new CommandTester($command);
        $commandTester->execute([]);

        // Assert success
        $this->assertSame(0, $commandTester->getStatusCode());
        $output = $commandTester->getDisplay();
        $this->assertStringContainsString('INSTALLED', $output);
        $this->assertStringContainsString('Symlink', $output);
        $this->assertStringContainsString('AI Mess Detector', $output);
    }

    #[Test]
    public function itReportsInstalledHookAsCopy(): void
    {
        // Create hook as regular file
        $hookPath = $this->gitDir . '/hooks/pre-commit';
        file_put_contents($hookPath, "#!/bin/bash\n# AI Mess Detector pre-commit hook\necho 'Running hook'\n");
        chmod($hookPath, 0755);

        $command = new HookStatusCommand(new GitRepositoryLocator());

        $application = new Application();
        $application->addCommand($command);

        $commandTester = new CommandTester($command);
        $commandTester->execute([]);

        // Assert success
        $this->assertSame(0, $commandTester->getStatusCode());
        $output = $commandTester->getDisplay();
        $this->assertStringContainsString('INSTALLED', $output);
        $this->assertStringContainsString('Copy', $output);
        $this->assertStringContainsString('AI Mess Detector', $output);
    }

    #[Test]
    public function itWarnsAboutThirdPartyHook(): void
    {
        // Create hook that's not ours
        $hookPath = $this->gitDir . '/hooks/pre-commit';
        file_put_contents($hookPath, "#!/bin/bash\necho 'Some other hook'\n");
        chmod($hookPath, 0755);

        $command = new HookStatusCommand(new GitRepositoryLocator());

        $application = new Application();
        $application->addCommand($command);

        $commandTester = new CommandTester($command);
        $commandTester->execute([]);

        // Assert success
        $this->assertSame(0, $commandTester->getStatusCode());
        $output = $commandTester->getDisplay();
        $this->assertStringContainsString('INSTALLED', $output);
        $this->assertStringContainsString('Third-party hook', $output);
        $this->assertStringContainsString('Warning', $output);
    }

    #[Test]
    public function itWarnsAboutNonExecutableHook(): void
    {
        // Create non-executable hook
        $hookPath = $this->gitDir . '/hooks/pre-commit';
        file_put_contents($hookPath, "#!/bin/bash\n# AI Mess Detector pre-commit hook\necho 'test'\n");
        chmod($hookPath, 0644); // Not executable

        $command = new HookStatusCommand(new GitRepositoryLocator());

        $application = new Application();
        $application->addCommand($command);

        $commandTester = new CommandTester($command);
        $commandTester->execute([]);

        // Assert success
        $this->assertSame(0, $commandTester->getStatusCode());
        $output = $commandTester->getDisplay();
        $this->assertStringContainsString('INSTALLED', $output);
        $this->assertStringContainsString('Executable: No', $output);
        $this->assertStringContainsString('Warning: Hook is not executable', $output);
    }

    #[Test]
    public function itReportsBackupExists(): void
    {
        // Create hook and backup
        $hookPath = $this->gitDir . '/hooks/pre-commit';
        $backupPath = $hookPath . '.backup';

        file_put_contents($hookPath, "#!/bin/bash\n# AI Mess Detector pre-commit hook\necho 'test'\n");
        chmod($hookPath, 0755);

        file_put_contents($backupPath, "#!/bin/bash\necho 'backup'\n");

        $command = new HookStatusCommand(new GitRepositoryLocator());

        $application = new Application();
        $application->addCommand($command);

        $commandTester = new CommandTester($command);
        $commandTester->execute([]);

        // Assert success
        $this->assertSame(0, $commandTester->getStatusCode());
        $output = $commandTester->getDisplay();
        $this->assertStringContainsString('Backup: Yes', $output);
        $this->assertStringContainsString('Backup path:', $output);
    }

    #[Test]
    public function itFailsWhenNotInGitRepository(): void
    {
        // Remove .git directory
        $this->removeDirectory($this->gitDir);

        $command = new HookStatusCommand(new GitRepositoryLocator());

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
