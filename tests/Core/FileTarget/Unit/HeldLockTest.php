<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Core\FileTarget\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Core\FileTarget\FileTargetFailure;
use Qualimetrix\Core\FileTarget\FileTargetFailureKind;
use Qualimetrix\Core\FileTarget\HeldLock;
use Qualimetrix\Core\FileTarget\TargetPath;
use Qualimetrix\Subprocess\ChildProcess;

require_once \dirname(__DIR__, 4) . '/scripts/subprocess/ChildProcess.php';

#[CoversClass(HeldLock::class)]
final class HeldLockTest extends TestCase
{
    #[Test]
    public function itCreatesAnAbsentLockAndLeavesItForFutureOwners(): void
    {
        $base = realpath(sys_get_temp_dir()) . '/qmx-lock-' . bin2hex(random_bytes(6));
        mkdir($base);
        $path = $base . '/lock';

        try {
            $lock = HeldLock::acquire(TargetPath::resolve($path), 0.2);
            self::assertFileExists($path);
            $lock->release();
            self::assertFileExists($path);
        } finally {
            unlink($path);
            rmdir($base);
        }
    }

    #[Test]
    public function itOpensAnExistingLockWithoutChangingItsContent(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'qmx-lock-');
        self::assertIsString($path);
        file_put_contents($path, 'sentinel');

        try {
            $lock = HeldLock::acquire(TargetPath::resolve($path), 0.2);
            $lock->release();
            self::assertSame('sentinel', file_get_contents($path));
        } finally {
            unlink($path);
        }
    }

    #[Test]
    public function itRefusesAChangedLockIdentityBeforeOpeningIt(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'qmx-lock-');
        self::assertIsString($path);
        $judged = TargetPath::resolve($path);
        unlink($path);
        file_put_contents($path, 'different lock');

        try {
            try {
                HeldLock::acquire($judged, 0.2);
                self::fail('Changed lock must be refused');
            } catch (FileTargetFailure $failure) {
                self::assertSame(FileTargetFailureKind::IdentityChanged, $failure->kind);
            }
        } finally {
            unlink($path);
        }
    }

    #[Test]
    public function itKeepsWaitingForTheLockWhenWallClockJumpsForward(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'qmx-lock-');
        self::assertIsString($path);
        file_put_contents($path, 'sentinel');
        $root = \dirname(__DIR__, 4);
        $script = <<<'PHP'
namespace Qualimetrix\Core\FileTarget {
    function microtime(bool $asFloat = false): float
    {
        $GLOBALS['wall_calls'] = ($GLOBALS['wall_calls'] ?? 0) + 1;

        return $GLOBALS['wall_calls'] === 1 ? 100.0 : 10000.0;
    }

    function hrtime(bool $asNumber = false): int
    {
        $GLOBALS['mono_calls'] = ($GLOBALS['mono_calls'] ?? 0) + 1;

        return $GLOBALS['mono_calls'] * 1000000;
    }

    function flock($handle, int $operation): bool
    {
        if ($operation === (\LOCK_EX | \LOCK_NB)) {
            $GLOBALS['attempts'] = ($GLOBALS['attempts'] ?? 0) + 1;

            return $GLOBALS['attempts'] >= 3;
        }

        return true;
    }
}

namespace {
    require $argv[1];
    $lock = null;
    $kind = null;
    try {
        $lock = \Qualimetrix\Core\FileTarget\HeldLock::acquire(
            \Qualimetrix\Core\FileTarget\TargetPath::resolve($argv[2]),
            0.05,
        );
    } catch (\Qualimetrix\Core\FileTarget\FileTargetFailure $failure) {
        $kind = $failure->kind->name;
    } finally {
        $lock?->release();
    }

    echo \json_encode(['acquired' => $lock !== null, 'attempts' => $GLOBALS['attempts'] ?? 0, 'kind' => $kind]);
}
PHP;

        try {
            $run = ChildProcess::run([\PHP_BINARY, '-r', $script, $root . '/vendor/autoload.php', $path]);
            self::assertSame(0, $run['exitCode'], $run['stderr']);
            $result = json_decode($run['stdout'], true, 512, \JSON_THROW_ON_ERROR);
            self::assertTrue($result['acquired'], (string) $result['kind']);
            self::assertSame(3, $result['attempts']);
            self::assertSame('sentinel', file_get_contents($path));
        } finally {
            unlink($path);
        }
    }
}
