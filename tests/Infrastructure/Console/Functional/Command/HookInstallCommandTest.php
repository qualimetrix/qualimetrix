<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Infrastructure\Console\Functional\Command;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Infrastructure\Console\Command\HookInstallCommand;
use Qualimetrix\Infrastructure\Console\Hook\PreCommitHook;
use Qualimetrix\Infrastructure\Console\RunningBinaryLocatorInterface;
use Qualimetrix\Infrastructure\Git\GitRepositoryLocator;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * These cases cannot see the defect that motivated the change, and saying so
 * is worth more than a case that pretends otherwise.
 *
 * The command used to look for `scripts/pre-commit-hook.sh` in two places:
 * relative to the working directory, and relative to the package root. The
 * second one found the file in any checkout of this repository no matter what
 * the working directory was, so nothing running inside the source tree could
 * ever observe the miss. What a consumer receives has no `scripts/` at all,
 * and that tree is judged by
 * {@see \Qualimetrix\Governance\DistributedPackage\HookInstallWorksFromTheDistPackageTest}.
 *
 * What is checked here is the shape of the installed hook — a regular file
 * naming the binary that wrote it — and the states an earlier release can
 * leave behind.
 */
#[CoversClass(HookInstallCommand::class)]
final class HookInstallCommandTest extends TestCase
{
    private const string BINARY = '/opt/qualimetrix/bin/qmx';

    private string $tempDir;
    private string $gitDir;
    private string $originalCwd;

    protected function setUp(): void
    {
        $this->originalCwd = (string) getcwd();

        $this->tempDir = sys_get_temp_dir() . '/qmx-test-' . bin2hex(random_bytes(6));
        mkdir($this->tempDir, 0777, true);

        $this->gitDir = $this->tempDir . '/.git';
        mkdir($this->gitDir . '/hooks', 0777, true);

        chdir($this->tempDir);
    }

    protected function tearDown(): void
    {
        // Restored, not left behind: the command reads the working directory,
        // so a test that changes it and does not put it back decides what the
        // next test in the run measures.
        chdir($this->originalCwd);

        if (is_dir($this->tempDir)) {
            $this->removeDirectory($this->tempDir);
        }
    }

    #[Test]
    public function itInstallsPreCommitHookAsAnExecutableFile(): void
    {
        $tester = $this->install([]);

        self::assertSame(0, $tester->getStatusCode());
        self::assertStringContainsString('Pre-commit hook installed', $tester->getDisplay());

        $hookPath = $this->hookPath();
        self::assertFileExists($hookPath);
        self::assertFalse(is_link($hookPath));
        self::assertTrue(is_executable($hookPath));
    }

    #[Test]
    public function itWritesAHookThatNamesTheBinaryThatInstalledIt(): void
    {
        $this->install([]);

        $contents = (string) file_get_contents($this->hookPath());

        self::assertTrue(PreCommitHook::isOurs($contents));
        self::assertStringContainsString(self::BINARY, $contents);
    }

    /**
     * Installing reads nothing and writes nothing outside the hooks
     * directory.
     */
    #[Test]
    public function itReadsNoScriptAndLeavesNoneBehind(): void
    {
        self::assertDirectoryDoesNotExist($this->tempDir . '/scripts');

        self::assertSame(0, $this->install([])->getStatusCode());

        self::assertFileExists($this->hookPath());
        self::assertDirectoryDoesNotExist($this->tempDir . '/scripts');
    }

    #[Test]
    public function itRefusesWhenTheRunningBinaryCannotBeNamed(): void
    {
        $tester = $this->install([], null);

        self::assertSame(1, $tester->getStatusCode());
        self::assertStringContainsString('Could not determine the path', $tester->getDisplay());
        self::assertFileDoesNotExist($this->hookPath());
    }

    #[Test]
    public function itFailsWhenHookExistsWithoutForceFlag(): void
    {
        file_put_contents($this->hookPath(), "#!/bin/bash\necho 'Existing hook'\n");

        $tester = $this->install([]);

        self::assertSame(1, $tester->getStatusCode());
        self::assertStringContainsString('Pre-commit hook already exists', $tester->getDisplay());
        self::assertStringContainsString('Use --force to overwrite', $tester->getDisplay());
    }

    #[Test]
    public function itOverwritesExistingHookWithForceFlag(): void
    {
        file_put_contents($this->hookPath(), "#!/bin/bash\necho 'Old hook'\n");

        $tester = $this->install(['--force' => true]);

        self::assertSame(0, $tester->getStatusCode());
        self::assertStringContainsString('backed up', $tester->getDisplay());

        $backup = (string) file_get_contents($this->hookPath() . '.backup');
        self::assertStringContainsString('Old hook', $backup);
    }

    /**
     * The state this change creates for everyone who installed the hook
     * before it: the symlink is still there and its target is gone.
     *
     * `file_exists` follows the link and answers false, so a command testing
     * only that treats the hook as absent — and then writes *through* the
     * link, creating the target file outside the hooks directory instead of
     * replacing the hook.
     */
    #[Test]
    public function itReplacesADanglingSymlinkInsteadOfWritingThroughIt(): void
    {
        $vanishedTarget = $this->tempDir . '/scripts/pre-commit-hook.sh';
        symlink($vanishedTarget, $this->hookPath());

        $tester = $this->install(['--force' => true]);

        self::assertSame(0, $tester->getStatusCode());
        self::assertFileDoesNotExist($vanishedTarget);
        self::assertFalse(is_link($this->hookPath()));
        self::assertTrue(PreCommitHook::isOurs((string) file_get_contents($this->hookPath())));
    }

    #[Test]
    public function itRefusesADanglingSymlinkWithoutForce(): void
    {
        symlink($this->tempDir . '/scripts/pre-commit-hook.sh', $this->hookPath());

        $tester = $this->install([]);

        self::assertSame(1, $tester->getStatusCode());
        self::assertStringContainsString('Pre-commit hook already exists', $tester->getDisplay());
        self::assertTrue(is_link($this->hookPath()));
    }

    #[Test]
    public function itFailsWhenNotInGitRepository(): void
    {
        $this->removeDirectory($this->gitDir);

        $tester = $this->install([]);

        self::assertSame(1, $tester->getStatusCode());
        self::assertStringContainsString('Not a git repository', $tester->getDisplay());
    }

    /** @param array<string, mixed> $input */
    private function install(array $input, ?string $binary = self::BINARY): CommandTester
    {
        $command = new HookInstallCommand(
            new GitRepositoryLocator(),
            $this->locator($binary),
        );

        $application = new Application();
        $application->addCommand($command);

        $tester = new CommandTester($command);
        $tester->execute($input);

        return $tester;
    }

    /**
     * Injected rather than read from the process: under `CommandTester` the
     * running binary is phpunit, so a command reading `$_SERVER` itself would
     * bake phpunit's path and every assertion about that path would be true
     * of a hook no consumer could ever receive.
     */
    private function locator(?string $binary): RunningBinaryLocatorInterface
    {
        return new class ($binary) implements RunningBinaryLocatorInterface {
            public function __construct(private readonly ?string $binary) {}

            public function path(): ?string
            {
                return $this->binary;
            }

            public function hint(): string
            {
                return $this->binary ?? 'qmx';
            }
        };
    }

    private function hookPath(): string
    {
        return $this->gitDir . '/hooks/pre-commit';
    }

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
}
