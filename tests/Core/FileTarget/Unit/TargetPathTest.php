<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Core\FileTarget\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Core\FileTarget\FileTargetFailure;
use Qualimetrix\Core\FileTarget\FileTargetFailureKind;
use Qualimetrix\Core\FileTarget\TargetKind;
use Qualimetrix\Core\FileTarget\TargetPath;
use Qualimetrix\Subprocess\ChildProcess;

require_once \dirname(__DIR__, 4) . '/scripts/subprocess/ChildProcess.php';

#[CoversClass(TargetPath::class)]
final class TargetPathTest extends TestCase
{
    #[Test]
    public function itRecognizesDescriptorsBeforeFollowingProcLinks(): void
    {
        foreach (['php://stdout', '/dev/stdout', '/dev/fd/1', '/dev/fd/../fd/1'] as $spelling) {
            $target = TargetPath::resolve($spelling);
            self::assertSame(TargetKind::Descriptor, $target->kind, $spelling);
            self::assertSame(1, $target->descriptor, $spelling);
        }

        if (is_dir('/proc/self/fd')) {
            self::assertSame(1, TargetPath::resolve('/proc/self/fd/1')->descriptor);
            self::assertSame(1, TargetPath::resolve('/proc/' . getmypid() . '/fd/1')->descriptor);
        }
    }

    #[Test]
    public function itRefusesUnknownSchemesAndRelativeFileUrls(): void
    {
        foreach (['php://memory', 'file://relative', 'compress.zlib://file', 'nosuch://x'] as $spelling) {
            try {
                TargetPath::resolve($spelling);
                self::fail('Expected unsupported scheme: ' . $spelling);
            } catch (FileTargetFailure $failure) {
                self::assertSame(FileTargetFailureKind::UnsupportedScheme, $failure->kind);
            }
        }
    }

    #[Test]
    public function itAcceptsAbsoluteFileUrlsAndRefusesOverlongNamesBeforeCreation(): void
    {
        $base = realpath(sys_get_temp_dir()) . '/qmx-target-' . bin2hex(random_bytes(6));
        mkdir($base);

        try {
            self::assertSame(TargetKind::Absent, TargetPath::resolve('file://' . $base . '/new')->kind);
            self::assertSame(TargetKind::Absent, TargetPath::resolve('file://localhost' . $base . '/new')->kind);
            try {
                TargetPath::resolve($base . '/' . str_repeat('x', 300));
                self::fail('Overlong file name must be refused');
            } catch (FileTargetFailure $failure) {
                self::assertSame(FileTargetFailureKind::Unopenable, $failure->kind);
                self::assertStringContainsString('File name too long', $failure->getMessage());
            }
        } finally {
            rmdir($base);
        }
    }

    #[Test]
    public function itFollowsRelativeLinksBeforeApplyingParentComponents(): void
    {
        $base = realpath(sys_get_temp_dir()) . '/qmx-target-' . bin2hex(random_bytes(6));
        mkdir($base);
        mkdir($base . '/a');
        mkdir($base . '/b');
        file_put_contents($base . '/b/target', 'x');
        symlink('../b', $base . '/a/link');

        try {
            $target = TargetPath::resolve($base . '/a/link/../b/target');
            self::assertSame(TargetKind::Regular, $target->kind);
            self::assertSame($base . '/b/target', $target->path?->value());
        } finally {
            unlink($base . '/a/link');
            unlink($base . '/b/target');
            rmdir($base . '/a');
            rmdir($base . '/b');
            rmdir($base);
        }
    }

    #[Test]
    public function itRefusesAPlaceableLinkEvenWhenTheLinkIsOwned(): void
    {
        $base = realpath(sys_get_temp_dir()) . '/qmx-target-' . bin2hex(random_bytes(6));
        mkdir($base, 0777);
        chmod($base, 0777);
        file_put_contents($base . '/target', 'safe');
        symlink('target', $base . '/link');

        try {
            $this->expectException(FileTargetFailure::class);
            $this->expectExceptionMessage('symbolic link can be placed');
            TargetPath::resolve($base . '/link');
        } finally {
            unlink($base . '/link');
            unlink($base . '/target');
            rmdir($base);
        }
    }

    #[Test]
    public function itStopsAtFortySymbolicLinkHops(): void
    {
        $base = realpath(sys_get_temp_dir()) . '/qmx-target-' . bin2hex(random_bytes(6));
        mkdir($base);
        file_put_contents($base . '/target', 'safe');
        for ($index = 41; $index >= 1; --$index) {
            symlink($index === 41 ? 'target' : 'link' . ($index + 1), $base . '/link' . $index);
        }

        try {
            self::assertSame(TargetKind::Regular, TargetPath::resolve($base . '/link2')->kind);
            try {
                TargetPath::resolve($base . '/link1');
                self::fail('The forty-first symbolic link must be refused');
            } catch (FileTargetFailure $failure) {
                self::assertSame(FileTargetFailureKind::LinkLoop, $failure->kind);
            }
        } finally {
            for ($index = 1; $index <= 41; ++$index) {
                unlink($base . '/link' . $index);
            }
            unlink($base . '/target');
            rmdir($base);
        }
    }

    #[Test]
    public function itKeepsTheNativeWarningReasonAndRestoresThePreviousHandler(): void
    {
        $script = <<<'PHP'
namespace Qualimetrix\Core\FileTarget {
    function lstat(string $path): array|false
    {
        if ($path === $GLOBALS['probe_target']) {
            \trigger_error('Permission denied by qmx probe', \E_USER_WARNING);

            return false;
        }

        return \lstat($path);
    }
}

namespace {
    require $argv[1];
    $GLOBALS['probe_target'] = $argv[2];
    $seen = [];
    \set_error_handler(static function (int $severity, string $message) use (&$seen): bool {
        $seen[] = $message;

        return true;
    });
    $kind = null;
    $detail = null;
    try {
        \Qualimetrix\Core\FileTarget\TargetPath::resolve($argv[2]);
    } catch (\Qualimetrix\Core\FileTarget\FileTargetFailure $failure) {
        $kind = $failure->kind->name;
        $detail = $failure->detail;
    }
    \trigger_error('after native call', \E_USER_WARNING);
    \restore_error_handler();
    echo \json_encode(['kind' => $kind, 'detail' => $detail, 'seen' => $seen]);
}
PHP;

        $target = realpath(sys_get_temp_dir()) . '/qmx-warning-' . bin2hex(random_bytes(6));
        $root = \dirname(__DIR__, 4);
        $run = ChildProcess::run([\PHP_BINARY, '-r', $script, $root . '/vendor/autoload.php', $target]);
        self::assertSame(0, $run['exitCode'], $run['stderr']);
        $result = json_decode($run['stdout'], true, 512, \JSON_THROW_ON_ERROR);
        self::assertSame(FileTargetFailureKind::Unopenable->name, $result['kind']);
        self::assertStringContainsString('Permission denied by qmx probe', $result['detail']);
        self::assertSame(['after native call'], $result['seen']);
    }

    #[Test]
    public function itRestoresThePreviousHandlerWhenANativeCallThrows(): void
    {
        $script = <<<'PHP'
namespace Qualimetrix\Core\FileTarget {
    function lstat(string $path): array|false
    {
        if ($path === $GLOBALS['probe_target']) {
            throw new \RuntimeException('native probe threw');
        }

        return \lstat($path);
    }
}

namespace {
    require $argv[1];
    $GLOBALS['probe_target'] = $argv[2];
    $seen = [];
    \set_error_handler(static function (int $severity, string $message) use (&$seen): bool {
        $seen[] = $message;

        return true;
    });
    $thrown = null;
    try {
        \Qualimetrix\Core\FileTarget\TargetPath::resolve($argv[2]);
    } catch (\RuntimeException $failure) {
        $thrown = $failure->getMessage();
    }
    \trigger_error('after native exception', \E_USER_WARNING);
    \restore_error_handler();
    echo \json_encode(['thrown' => $thrown, 'seen' => $seen]);
}
PHP;

        $target = realpath(sys_get_temp_dir()) . '/qmx-warning-' . bin2hex(random_bytes(6));
        $root = \dirname(__DIR__, 4);
        $run = ChildProcess::run([\PHP_BINARY, '-r', $script, $root . '/vendor/autoload.php', $target]);
        self::assertSame(0, $run['exitCode'], $run['stderr']);
        $result = json_decode($run['stdout'], true, 512, \JSON_THROW_ON_ERROR);
        self::assertSame('native probe threw', $result['thrown']);
        self::assertSame(['after native exception'], $result['seen']);
    }
}
