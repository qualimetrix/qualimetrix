<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Unit\Infrastructure\Console;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Infrastructure\Console\Application;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\NullOutput;
use Throwable;

#[CoversClass(Application::class)]
final class ApplicationTest extends TestCase
{
    private string $originalCwd;

    protected function setUp(): void
    {
        $cwd = getcwd();
        if ($cwd === false) {
            self::fail('Cannot get current directory');
        }
        $this->originalCwd = $cwd;
    }

    protected function tearDown(): void
    {
        chdir($this->originalCwd);
    }

    #[Test]
    public function workingDirChangesDirectory(): void
    {
        $tempDir = sys_get_temp_dir();
        $resolved = realpath($tempDir);
        self::assertNotFalse($resolved);

        $app = new Application();
        $app->setAutoExit(false);

        // doRun with --working-dir will chdir, then fail on missing command — that's fine
        try {
            $app->doRun(
                new ArrayInput(['--working-dir' => $tempDir, 'command' => 'list']),
                new NullOutput(),
            );
        } catch (Throwable) {
            // Command may fail, but chdir should have happened
        }

        self::assertSame($resolved, getcwd());
    }

    #[Test]
    public function invalidWorkingDirThrowsException(): void
    {
        $app = new Application();
        $app->setAutoExit(false);

        self::expectException(InvalidArgumentException::class);
        self::expectExceptionMessage('Invalid working directory');

        $app->doRun(
            new ArrayInput(['--working-dir' => '/nonexistent/path/xyz']),
            new NullOutput(),
        );
    }

    #[Test]
    public function noWorkingDirDoesNotChangeDirectory(): void
    {
        $before = getcwd();

        $app = new Application();
        $app->setAutoExit(false);

        try {
            $app->doRun(
                new ArrayInput(['command' => 'list']),
                new NullOutput(),
            );
        } catch (Throwable) {
            // Ignore
        }

        self::assertSame($before, getcwd());
    }
}
