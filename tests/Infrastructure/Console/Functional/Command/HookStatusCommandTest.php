<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Infrastructure\Console\Functional\Command;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Core\ProductIdentity;
use Qualimetrix\Infrastructure\Console\Application as QualimetrixApplication;
use Qualimetrix\Infrastructure\Console\Command\HookStatusCommand;
use Qualimetrix\Infrastructure\Console\ErrorStream;
use Qualimetrix\Infrastructure\Console\Refusal\RefusalPresenter;
use Qualimetrix\Infrastructure\Console\RunningBinaryLocator;
use Qualimetrix\Infrastructure\Git\GitRepositoryLocator;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Tester\ApplicationTester;
use Symfony\Component\Console\Tester\CommandTester;

#[CoversClass(HookStatusCommand::class)]
final class HookStatusCommandTest extends TestCase
{
    private string $tempDir;
    private string $gitDir;
    private string $originalCwd;

    protected function setUp(): void
    {
        $this->originalCwd = (string) getcwd();

        // Create temporary directory with fake git structure
        $this->tempDir = sys_get_temp_dir() . '/qmx-test-' . bin2hex(random_bytes(6));
        mkdir($this->tempDir, 0777, true);

        // Create .git/hooks directory
        $this->gitDir = $this->tempDir . '/.git';
        mkdir($this->gitDir . '/hooks', 0777, true);

        // Change to temp directory for test
        chdir($this->tempDir);
    }

    protected function tearDown(): void
    {
        // Restored, not left behind: the command reads the working directory,
        // so a test that changes it and does not put it back decides what the
        // next test in the run measures.
        chdir($this->originalCwd);

        // Clean up temporary directory
        if (is_dir($this->tempDir)) {
            $this->removeDirectory($this->tempDir);
        }
    }

    #[Test]
    public function itReportsHookNotInstalled(): void
    {
        $command = new HookStatusCommand(new GitRepositoryLocator(), new RunningBinaryLocator());

        $application = new Application();
        $application->addCommand($command);

        $commandTester = new CommandTester($command);
        $commandTester->execute([]);

        // Assert success (status always succeeds)
        self::assertSame(0, $commandTester->getStatusCode());
        $output = $commandTester->getDisplay();
        self::assertStringContainsString('NOT INSTALLED', $output);
        self::assertStringContainsString('To install the hook', $output);
    }

    /**
     * The pointer comes from the shared `AbstractHookCommand::execute()`
     * wrapper, so it reaches this "not installed" exit exactly as it reaches
     * the "installed" ones exercised by the tests below.
     */
    #[Test]
    public function itPrintsTheDocsPointerAfterTheReport(): void
    {
        $command = new HookStatusCommand(new GitRepositoryLocator(), new RunningBinaryLocator());
        $commandTester = new CommandTester($command);
        $commandTester->execute([]);

        self::assertStringContainsString('Docs: ' . ProductIdentity::docsUrl(), $commandTester->getDisplay());
    }

    #[Test]
    public function itSuppressesTheDocsPointerUnderQuiet(): void
    {
        $command = new HookStatusCommand(new GitRepositoryLocator(), new RunningBinaryLocator());
        $commandTester = new CommandTester($command);
        $commandTester->execute([], ['verbosity' => OutputInterface::VERBOSITY_QUIET]);

        self::assertStringNotContainsString('Docs:', $commandTester->getDisplay());
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
        file_put_contents($tempScript, "#!/bin/bash\n# Qualimetrix pre-commit hook\necho 'test'\n");
        unlink($hookPath);
        symlink($tempScript, $hookPath);
        chmod($hookPath, 0755);

        $command = new HookStatusCommand(new GitRepositoryLocator(), new RunningBinaryLocator());

        $application = new Application();
        $application->addCommand($command);

        $commandTester = new CommandTester($command);
        $commandTester->execute([]);

        // Assert success
        self::assertSame(0, $commandTester->getStatusCode());
        $output = $commandTester->getDisplay();
        self::assertStringContainsString('INSTALLED', $output);
        self::assertStringContainsString('Symlink', $output);
        self::assertStringContainsString('Qualimetrix', $output);
    }

    #[Test]
    public function itReportsInstalledHookAsAFile(): void
    {
        // Create hook as regular file
        $hookPath = $this->gitDir . '/hooks/pre-commit';
        file_put_contents($hookPath, "#!/bin/bash\n# Qualimetrix pre-commit hook\necho 'Running hook'\n");
        chmod($hookPath, 0755);

        $command = new HookStatusCommand(new GitRepositoryLocator(), new RunningBinaryLocator());

        $application = new Application();
        $application->addCommand($command);

        $commandTester = new CommandTester($command);
        $commandTester->execute([]);

        // Assert success
        self::assertSame(0, $commandTester->getStatusCode());
        $output = $commandTester->getDisplay();
        self::assertStringContainsString('INSTALLED', $output);
        self::assertStringContainsString('Type: File', $output);
        self::assertStringContainsString('Qualimetrix', $output);
    }

    #[Test]
    public function itWarnsAboutThirdPartyHook(): void
    {
        // Create hook that's not ours
        $hookPath = $this->gitDir . '/hooks/pre-commit';
        file_put_contents($hookPath, "#!/bin/bash\necho 'Some other hook'\n");
        chmod($hookPath, 0755);

        $command = new HookStatusCommand(new GitRepositoryLocator(), new RunningBinaryLocator());

        $application = new Application();
        $application->addCommand($command);

        $commandTester = new CommandTester($command);
        $commandTester->execute([]);

        // Assert success
        self::assertSame(0, $commandTester->getStatusCode());
        $output = $commandTester->getDisplay();
        self::assertStringContainsString('INSTALLED', $output);
        self::assertStringContainsString('Third-party hook', $output);
        self::assertStringContainsString('Warning', $output);
    }

    #[Test]
    public function itWarnsAboutNonExecutableHook(): void
    {
        // Create non-executable hook
        $hookPath = $this->gitDir . '/hooks/pre-commit';
        file_put_contents($hookPath, "#!/bin/bash\n# Qualimetrix pre-commit hook\necho 'test'\n");
        chmod($hookPath, 0644); // Not executable

        $command = new HookStatusCommand(new GitRepositoryLocator(), new RunningBinaryLocator());

        $application = new Application();
        $application->addCommand($command);

        $commandTester = new CommandTester($command);
        $commandTester->execute([]);

        // Assert success
        self::assertSame(0, $commandTester->getStatusCode());
        $output = $commandTester->getDisplay();
        self::assertStringContainsString('INSTALLED', $output);
        self::assertStringContainsString('Executable: No', $output);
        self::assertStringContainsString('Warning: Hook is not executable', $output);
    }

    #[Test]
    public function itReportsBackupExists(): void
    {
        // Create hook and backup
        $hookPath = $this->gitDir . '/hooks/pre-commit';
        $backupPath = $hookPath . '.backup';

        file_put_contents($hookPath, "#!/bin/bash\n# Qualimetrix pre-commit hook\necho 'test'\n");
        chmod($hookPath, 0755);

        file_put_contents($backupPath, "#!/bin/bash\necho 'backup'\n");

        $command = new HookStatusCommand(new GitRepositoryLocator(), new RunningBinaryLocator());

        $application = new Application();
        $application->addCommand($command);

        $commandTester = new CommandTester($command);
        $commandTester->execute([]);

        // Assert success
        self::assertSame(0, $commandTester->getStatusCode());
        $output = $commandTester->getDisplay();
        self::assertStringContainsString('Backup: Yes', $output);
        self::assertStringContainsString('Backup path:', $output);
    }

    #[Test]
    public function itFailsWhenNotInGitRepository(): void
    {
        // Remove .git directory
        $this->removeDirectory($this->gitDir);

        $command = new HookStatusCommand(new GitRepositoryLocator(), new RunningBinaryLocator());

        $commandTester = self::throughLadder($command);

        // Assert failure
        self::assertSame(3, $commandTester->getStatusCode());
        $output = $commandTester->getErrorOutput();
        self::assertStringContainsString('Not a git repository', $output);
    }

    /**
     * What every hook installed by an earlier release became: a symlink whose
     * target this package no longer ships. `file_exists` follows the link
     * and answers false for it, so reporting on that alone would call a hook
     * git still executes "NOT INSTALLED".
     */
    #[Test]
    public function itReportsADanglingSymlinkRatherThanCallingItAbsent(): void
    {
        symlink($this->tempDir . '/scripts/pre-commit-hook.sh', $this->gitDir . '/hooks/pre-commit');

        $command = new HookStatusCommand(new GitRepositoryLocator(), new RunningBinaryLocator());

        $application = new Application();
        $application->addCommand($command);

        $commandTester = new CommandTester($command);
        $commandTester->execute([]);

        self::assertSame(0, $commandTester->getStatusCode());
        $output = $commandTester->getDisplay();
        self::assertStringContainsString('INSTALLED', $output);
        self::assertStringContainsString('Symlink', $output);
        self::assertStringContainsString('leads nowhere', $output);
        self::assertStringNotContainsString('NOT INSTALLED', $output);
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
            is_dir($path) && !is_link($path) ? $this->removeDirectory($path) : unlink($path);
        }
        rmdir($dir);
    }

    /**
     * A refusal is a throw, so a refusing case runs through the application's
     * exit ladder, which is what turns it into an exit code and a stderr line.
     */
    private static function throughLadder(Command $command): ApplicationTester
    {
        $errorStream = new ErrorStream();
        $application = new QualimetrixApplication($errorStream, new RefusalPresenter($errorStream));
        $application->setAutoExit(false);
        $application->addCommand($command);

        $tester = new ApplicationTester($application);
        $tester->run(['command' => (string) $command->getName()], ['capture_stderr_separately' => true]);

        return $tester;
    }
}
