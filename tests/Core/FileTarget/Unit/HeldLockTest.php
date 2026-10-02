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
}
