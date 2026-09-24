<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Infrastructure\Console\Functional\Command;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Infrastructure\Console\Application;
use Qualimetrix\Infrastructure\Console\Command\AbstractHookCommand;
use Qualimetrix\Infrastructure\Console\Command\HookInstallCommand;
use Qualimetrix\Infrastructure\Console\Command\HookStatusCommand;
use Qualimetrix\Infrastructure\Console\Command\HookUninstallCommand;
use Qualimetrix\Infrastructure\Console\ErrorStream;
use Qualimetrix\Infrastructure\Console\Hook\PreCommitHook;
use Qualimetrix\Infrastructure\Console\Refusal\RefusalPresenter;
use Qualimetrix\Infrastructure\Console\RunningBinaryLocatorInterface;
use Qualimetrix\Infrastructure\Git\GitRepositoryLocator;
use Symfony\Component\Console\Tester\ApplicationTester;

/**
 * The hook commands refuse the way every other command does: through the
 * application's exit ladder, with code 3 and the message on stderr.
 *
 * Every case here runs the real {@see Application}, not a bare command: a
 * refusal is a throw, and only the ladder turns a throw into an exit code.
 */
#[CoversClass(AbstractHookCommand::class)]
final class HookRefusalLadderTest extends TestCase
{
    private const string BINARY = '/opt/qualimetrix/bin/qmx';

    private string $tempDir;
    private string $originalCwd;

    protected function setUp(): void
    {
        $this->originalCwd = (string) getcwd();
        $this->tempDir = sys_get_temp_dir() . '/qmx-hook-ladder-' . bin2hex(random_bytes(6));
        mkdir($this->tempDir . '/.git/hooks', 0777, true);
        // The spelling git reports and the refusals quote (`/private/var` on macOS).
        $this->tempDir = (string) realpath($this->tempDir);
        chdir($this->tempDir);
    }

    protected function tearDown(): void
    {
        if (is_dir($this->tempDir . '/.git/hooks')) {
            chmod($this->tempDir . '/.git/hooks', 0755);
        }
        chdir($this->originalCwd);
        self::removeDirectory($this->tempDir);
    }

    #[Test]
    public function itRefusesOutsideARepositoryWithTheRefusalCodeOnStderr(): void
    {
        self::removeDirectory($this->tempDir . '/.git');

        foreach (['hook:install', 'hook:uninstall', 'hook:status'] as $command) {
            $tester = $this->runHookCommand($command, []);

            self::assertSame(3, $tester->getStatusCode(), $command);
            self::assertStringContainsString('Not a git repository', $tester->getErrorOutput(), $command);
            self::assertStringNotContainsString('Not a git repository', $tester->getDisplay(), $command);
        }
    }

    #[Test]
    public function itRefusesAnExistingHookWithoutForce(): void
    {
        file_put_contents($this->hookPath(), "#!/bin/sh\necho foreign\n");

        $tester = $this->runHookCommand('hook:install', []);

        self::assertSame(3, $tester->getStatusCode());
        self::assertStringContainsString('Pre-commit hook already exists', $tester->getErrorOutput());
        self::assertStringContainsString('--force', $tester->getErrorOutput());
        self::assertSame("#!/bin/sh\necho foreign\n", file_get_contents($this->hookPath()));
    }

    #[Test]
    public function itRefusesWhenTheRunningBinaryCannotBeNamed(): void
    {
        $tester = $this->runHookCommand('hook:install', [], null);

        self::assertSame(3, $tester->getStatusCode());
        self::assertStringContainsString('Could not determine the path of the running qmx binary', $tester->getErrorOutput());
        self::assertFileDoesNotExist($this->hookPath());
    }

    /**
     * The backup is one slot. A second foreign hook forced over the first used
     * to overwrite the slot, so the hook saved first was lost with the same
     * success line both times.
     */
    #[Test]
    public function itRefusesToOverwriteABackupHoldingADifferentForeignHook(): void
    {
        file_put_contents($this->hookPath(), "#!/bin/sh\necho first\n");
        self::assertSame(0, $this->runHookCommand('hook:install', ['--force' => true])->getStatusCode());
        self::assertSame("#!/bin/sh\necho first\n", file_get_contents($this->hookPath() . '.backup'));

        file_put_contents($this->hookPath(), "#!/bin/sh\necho second\n");
        $tester = $this->runHookCommand('hook:install', ['--force' => true]);

        self::assertSame(3, $tester->getStatusCode());
        self::assertStringContainsString($this->hookPath() . '.backup', $tester->getErrorOutput());
        self::assertSame("#!/bin/sh\necho first\n", file_get_contents($this->hookPath() . '.backup'));
        self::assertSame("#!/bin/sh\necho second\n", file_get_contents($this->hookPath()));
    }

    /** The lawful neighbour: the same foreign hook forced again finds its own copy in the slot. */
    #[Test]
    public function itReusesABackupThatAlreadyHoldsTheSameHook(): void
    {
        file_put_contents($this->hookPath() . '.backup', "#!/bin/sh\necho same\n");
        file_put_contents($this->hookPath(), "#!/bin/sh\necho same\n");

        $tester = $this->runHookCommand('hook:install', ['--force' => true]);

        self::assertSame(0, $tester->getStatusCode(), $tester->getErrorOutput());
        self::assertStringContainsString('already holds', $tester->getDisplay());
        self::assertTrue(PreCommitHook::isOurs((string) file_get_contents($this->hookPath())));
    }

    #[Test]
    public function itRefusesToUninstallAThirdPartyHook(): void
    {
        file_put_contents($this->hookPath(), "#!/bin/sh\necho foreign\n");

        $tester = $this->runHookCommand('hook:uninstall', []);

        self::assertSame(3, $tester->getStatusCode());
        self::assertStringContainsString('not a Qualimetrix hook', $tester->getErrorOutput());
        self::assertFileExists($this->hookPath());
    }

    #[Test]
    public function itRefusesToUninstallADanglingSymlink(): void
    {
        symlink($this->tempDir . '/nowhere', $this->hookPath());

        $tester = $this->runHookCommand('hook:uninstall', []);

        self::assertSame(3, $tester->getStatusCode());
        self::assertStringContainsString('symlink that leads nowhere', $tester->getErrorOutput());
        self::assertTrue(is_link($this->hookPath()));
    }

    /**
     * A hook file that cannot be changed is refused with the reason the system
     * gave, and without the PHP warning that `display_errors=1` printed into
     * stdout ahead of the refusal. One case per filesystem call a user can
     * make fail.
     */
    #[Test]
    public function itRefusesAHookItCannotRemoveWithTheSystemsReason(): void
    {
        self::skipAsRoot();
        self::assertSame(0, $this->runHookCommand('hook:install', [])->getStatusCode());
        chmod($this->tempDir . '/.git/hooks', 0555);

        $tester = $this->runWithoutDiagnostics('hook:uninstall', []);

        self::assertSame(3, $tester->getStatusCode());
        self::assertStringContainsString(
            \sprintf('Failed to remove hook file: %s: Permission denied', $this->hookPath()),
            $tester->getErrorOutput(),
        );
        self::assertFileExists($this->hookPath());
    }

    #[Test]
    public function itRefusesADanglingHookLinkItCannotReplaceWithTheSystemsReason(): void
    {
        self::skipAsRoot();
        symlink($this->tempDir . '/nowhere', $this->hookPath());
        chmod($this->tempDir . '/.git/hooks', 0555);

        $tester = $this->runWithoutDiagnostics('hook:install', ['--force' => true]);

        self::assertSame(3, $tester->getStatusCode());
        self::assertStringContainsString(
            \sprintf('Failed to remove the existing hook: %s: Permission denied', $this->hookPath()),
            $tester->getErrorOutput(),
        );
    }

    #[Test]
    public function itRefusesABackupItCannotWriteWithTheSystemsReason(): void
    {
        self::skipAsRoot();
        file_put_contents($this->hookPath(), "#!/bin/sh\necho foreign\n");
        chmod($this->tempDir . '/.git/hooks', 0555);

        $tester = $this->runWithoutDiagnostics('hook:install', ['--force' => true]);

        self::assertSame(3, $tester->getStatusCode());
        self::assertStringContainsString(
            \sprintf('Failed to back up the existing hook to %s: ', $this->hookPath() . '.backup'),
            $tester->getErrorOutput(),
        );
        self::assertStringContainsString('Permission denied', $tester->getErrorOutput());
        self::assertSame("#!/bin/sh\necho foreign\n", file_get_contents($this->hookPath()));
    }

    #[Test]
    public function itRefusesAHookItCannotWriteWithTheSystemsReason(): void
    {
        mkdir($this->hookPath() . '.tmp.' . getmypid());

        $tester = $this->runWithoutDiagnostics('hook:install', []);

        self::assertSame(3, $tester->getStatusCode());
        self::assertStringContainsString(
            \sprintf('Failed to write hook: %s: ', $this->hookPath()),
            $tester->getErrorOutput(),
        );
        self::assertStringContainsString('Is a directory', $tester->getErrorOutput());
        self::assertFileDoesNotExist($this->hookPath());
    }

    /**
     * The move into place, which the temporary-file case above never reaches.
     *
     * A directory at the hook path is read as an empty hook, and an empty
     * backup beside it counts as already holding it, so `--force` goes on to
     * the write and only `rename()` over the directory can fail.
     */
    #[Test]
    public function itRefusesAHookItCannotMoveIntoPlaceWithTheSystemsReason(): void
    {
        mkdir($this->hookPath());
        touch($this->hookPath() . '/keep-me');
        touch($this->hookPath() . '.backup');

        $tester = $this->runWithoutDiagnostics('hook:install', ['--force' => true]);

        self::assertSame(3, $tester->getStatusCode());
        self::assertStringContainsString(
            \sprintf('Failed to write hook: %s: ', $this->hookPath()),
            $tester->getErrorOutput(),
        );
        self::assertMatchesRegularExpression('/: (Is a directory|Directory not empty)/', $tester->getErrorOutput());
        self::assertFileExists($this->hookPath() . '/keep-me');
        self::assertSame([], glob($this->hookPath() . '.tmp.*'), 'the temporary file is removed');
    }

    #[Test]
    public function itRefusesABackupItCannotRestoreWithTheSystemsReason(): void
    {
        self::assertSame(0, $this->runHookCommand('hook:install', [])->getStatusCode());
        mkdir($this->hookPath() . '.backup');

        $tester = $this->runWithoutDiagnostics('hook:uninstall', ['--restore-backup' => true]);

        self::assertSame(3, $tester->getStatusCode());
        self::assertStringContainsString(
            \sprintf('Failed to restore backup %s to %s: ', $this->hookPath() . '.backup', $this->hookPath()),
            $tester->getErrorOutput(),
        );
        self::assertStringContainsString('cannot be a directory', $tester->getErrorOutput());
    }

    /** @param array<string, mixed> $options */
    private function runWithoutDiagnostics(string $command, array $options): ApplicationTester
    {
        $diagnostics = [];
        // What `display_errors` would print: a diagnostic the code silenced
        // with `@` is out of `error_reporting()` and never reaches a stream.
        // PHPUnit narrows the level to fatal errors while its own handler is
        // installed, which would hide every warning from the filter below,
        // so the run gets the level a real process has.
        $level = error_reporting(\E_ALL);
        set_error_handler(static function (int $level, string $message) use (&$diagnostics): bool {
            if ((error_reporting() & $level) !== 0) {
                $diagnostics[] = $message;
            }

            return true;
        });

        try {
            $tester = $this->runHookCommand($command, $options);
        } finally {
            restore_error_handler();
            error_reporting($level);
        }

        self::assertSame([], $diagnostics);
        self::assertStringNotContainsString('Warning', $tester->getDisplay());

        return $tester;
    }

    private static function skipAsRoot(): void
    {
        if (posix_getuid() === 0) {
            self::markTestSkipped('Root ignores permission bits, so nothing here is refused to run as root.');
        }
    }

    /** @param array<string, mixed> $options */
    private function runHookCommand(string $command, array $options, ?string $binary = self::BINARY): ApplicationTester
    {
        $locator = new class ($binary) implements RunningBinaryLocatorInterface {
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

        $errorStream = new ErrorStream();
        $application = new Application($errorStream, new RefusalPresenter($errorStream));
        $application->setAutoExit(false);
        foreach ([HookInstallCommand::class, HookUninstallCommand::class, HookStatusCommand::class] as $class) {
            $application->addCommand(new $class(new GitRepositoryLocator(), $locator));
        }

        $tester = new ApplicationTester($application);
        $tester->run(['command' => $command, ...$options], ['capture_stderr_separately' => true]);

        return $tester;
    }

    private function hookPath(): string
    {
        return $this->tempDir . '/.git/hooks/pre-commit';
    }

    private static function removeDirectory(string $dir): void
    {
        if (!is_dir($dir) || is_link($dir)) {
            return;
        }

        $entries = scandir($dir);

        foreach ($entries === false ? [] : $entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $path = $dir . '/' . $entry;
            is_dir($path) && !is_link($path) ? self::removeDirectory($path) : unlink($path);
        }

        rmdir($dir);
    }
}
