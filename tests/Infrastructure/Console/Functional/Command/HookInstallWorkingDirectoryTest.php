<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Infrastructure\Console\Functional\Command;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Infrastructure\Console\Command\HookInstallCommand;
use Qualimetrix\Infrastructure\Console\Hook\PreCommitHook;
use Qualimetrix\Subprocess\ChildProcess;

require_once \dirname(__DIR__, 5) . '/scripts/subprocess/ChildProcess.php';

/**
 * `hook:install --working-dir <repo>` started by a relative path to the binary.
 *
 * `--working-dir` changes directory before the command runs, and the binary's
 * own path was resolved afterwards — so `php bin/qmx` resolved `bin/qmx`
 * against the target repository and found nothing there. Only a real process
 * shows this: the path the process was started with is the thing under test.
 */
#[CoversClass(HookInstallCommand::class)]
final class HookInstallWorkingDirectoryTest extends TestCase
{
    private string $repository;

    protected function setUp(): void
    {
        $this->repository = sys_get_temp_dir() . '/qmx-hook-wd-' . bin2hex(random_bytes(6));
        mkdir($this->repository, 0777, true);
        self::assertSame(0, ChildProcess::run(['git', 'init', '-q', $this->repository])['exitCode']);
    }

    protected function tearDown(): void
    {
        ChildProcess::run(['rm', '-rf', $this->repository]);
    }

    #[Test]
    public function itNamesTheRunningBinaryWhenStartedByARelativePathFromAnotherDirectory(): void
    {
        $projectRoot = \dirname(__DIR__, 5);
        $binary = realpath($projectRoot . '/bin/qmx');
        self::assertIsString($binary);

        $run = ChildProcess::run(
            [\PHP_BINARY, 'bin/qmx', 'hook:install', '--working-dir', $this->repository],
            $projectRoot,
        );

        self::assertSame(0, $run['exitCode'], $run['stdout'] . $run['stderr']);

        $hook = file_get_contents($this->repository . '/.git/hooks/pre-commit');
        self::assertIsString($hook);
        self::assertTrue(PreCommitHook::isOurs($hook));
        self::assertStringContainsString($binary, $hook);
    }
}
