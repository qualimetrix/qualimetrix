<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Core\FileTarget\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Core\FileTarget\FileReplacement;
use Qualimetrix\Core\FileTarget\FileTargetFailure;
use Qualimetrix\Core\FileTarget\FileTargetFailureKind;
use Qualimetrix\Core\FileTarget\NewName;
use Qualimetrix\Core\FileTarget\TargetPath;

#[CoversClass(FileReplacement::class)]
final class FileReplacementTest extends TestCase
{
    #[Test]
    public function itPublishesACompleteReplacementWithTheOldMode(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'qmx-replace-');
        self::assertIsString($path);
        file_put_contents($path, 'old');
        chmod($path, 0600);

        try {
            FileReplacement::replace(TargetPath::resolve($path), 'new', null, NewName::Exclusive);
            self::assertSame('new', file_get_contents($path));
            self::assertSame(0600, fileperms($path) & 0777);
        } finally {
            unlink($path);
        }
    }

    #[Test]
    public function itRefusesAnAppearedNameWithoutOverwritingIt(): void
    {
        $base = realpath(sys_get_temp_dir()) . '/qmx-replace-' . bin2hex(random_bytes(6));
        mkdir($base);
        $path = $base . '/new';
        $judged = TargetPath::resolve($path);
        file_put_contents($path, 'other writer');

        try {
            try {
                FileReplacement::replace($judged, 'ours', null, NewName::Exclusive);
                self::fail('Appeared target must be refused');
            } catch (FileTargetFailure $failure) {
                self::assertSame(FileTargetFailureKind::IdentityChanged, $failure->kind);
            }
            self::assertSame('other writer', file_get_contents($path));
        } finally {
            unlink($path);
            rmdir($base);
        }
    }

    #[Test]
    public function itUsesAFixedLengthTemporaryNameBesideALongTargetName(): void
    {
        $base = realpath(sys_get_temp_dir()) . '/qmx-replace-' . bin2hex(random_bytes(6));
        mkdir($base);
        $path = $base . '/' . str_repeat('a', 245);

        try {
            FileReplacement::replace(TargetPath::resolve($path), 'content', null, NewName::Exclusive);
            self::assertSame('content', file_get_contents($path));
        } finally {
            unlink($path);
            rmdir($base);
        }
    }

    #[Test]
    public function itNamesTheRequestedTargetWhenTheReadOnlyParentRefusesATemporarySibling(): void
    {
        if (\function_exists('posix_geteuid') && posix_geteuid() === 0) {
            self::markTestSkipped('Permission bits do not refuse root');
        }

        $base = realpath(sys_get_temp_dir()) . '/qmx-replace-' . bin2hex(random_bytes(6));
        mkdir($base);
        $path = $base . '/report.json';
        $target = TargetPath::resolve($path);
        chmod($base, 0500);

        try {
            try {
                FileReplacement::replace($target, 'report', null, NewName::Exclusive);
                self::fail('A read-only parent accepted a temporary sibling.');
            } catch (FileTargetFailure $failure) {
                self::assertSame(FileTargetFailureKind::Unopenable, $failure->kind);
                self::assertSame($path, $failure->spelling);
                self::assertStringContainsString('Permission denied', $failure->detail);
                self::assertStringContainsString('.qmx-', $failure->detail);
            }

            self::assertFileDoesNotExist($path);
            self::assertSame(['.', '..'], scandir($base));
        } finally {
            chmod($base, 0700);
            rmdir($base);
        }
    }
}
