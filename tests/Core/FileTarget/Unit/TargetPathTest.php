<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Core\FileTarget\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Core\FileTarget\FileReplacement;
use Qualimetrix\Core\FileTarget\FileTargetFailure;
use Qualimetrix\Core\FileTarget\FileTargetFailureKind;
use Qualimetrix\Core\FileTarget\HeldLock;
use Qualimetrix\Core\FileTarget\HeldTarget;
use Qualimetrix\Core\FileTarget\NewName;
use Qualimetrix\Core\FileTarget\PrivateGroupMembership;
use Qualimetrix\Core\FileTarget\TargetKind;
use Qualimetrix\Core\FileTarget\TargetPath;
use Qualimetrix\Subprocess\ChildProcess;

require_once \dirname(__DIR__, 4) . '/scripts/subprocess/ChildProcess.php';

#[CoversClass(TargetPath::class)]
final class TargetPathTest extends TestCase
{
    #[Test]
    public function itCarriesPrivateGroupEvidenceThroughClaimReplacementAndLockRechecks(): void
    {
        $base = realpath(sys_get_temp_dir()) . '/qmx-target-' . bin2hex(random_bytes(6));
        mkdir($base);
        chmod($base, 0775);
        file_put_contents($base . '/target', 'old');
        file_put_contents($base . '/lock', '');
        symlink('target', $base . '/link');
        symlink('lock', $base . '/lock-link');
        $owner = fileowner($base);
        $group = filegroup($base);
        self::assertIsInt($owner);
        self::assertIsInt($group);
        $membership = self::membership($owner, $group, true);

        try {
            $target = TargetPath::resolve($base . '/link', $membership);
            $held = HeldTarget::claim($target);
            $held->release();
            FileReplacement::replace($target, 'new', null, NewName::LastWriterWins);
            self::assertSame('new', file_get_contents($base . '/target'));

            $lock = HeldLock::acquire(TargetPath::resolve($base . '/lock-link', $membership), 0.2);
            $lock->release();
        } finally {
            unlink($base . '/lock-link');
            unlink($base . '/link');
            unlink($base . '/lock');
            unlink($base . '/target');
            rmdir($base);
        }
    }

    #[Test]
    public function itStillRejectsAGroupWritableLinkWithoutPrivateMembershipEvidence(): void
    {
        $base = realpath(sys_get_temp_dir()) . '/qmx-target-' . bin2hex(random_bytes(6));
        mkdir($base);
        chmod($base, 0775);
        file_put_contents($base . '/target', 'safe');
        symlink('target', $base . '/link');
        $owner = fileowner($base);
        $group = filegroup($base);
        self::assertIsInt($owner);
        self::assertIsInt($group);

        try {
            $this->expectException(FileTargetFailure::class);
            $this->expectExceptionMessage('symbolic link can be placed');
            TargetPath::resolve($base . '/link', self::membership($owner, $group, false));
        } finally {
            unlink($base . '/link');
            unlink($base . '/target');
            rmdir($base);
        }
    }

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

    private static function membership(int $owner, int $group, bool $private): PrivateGroupMembership
    {
        return new class ($owner, $group, $private) implements PrivateGroupMembership {
            public function __construct(
                private readonly int $owner,
                private readonly int $group,
                private readonly bool $private,
            ) {}

            public function isPrivatePrimaryGroup(int $effectiveUid, int $groupId): bool
            {
                return $this->private && $effectiveUid === $this->owner && $groupId === $this->group;
            }
        };
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
    public function itDoesNotMistakeAnInaccessibleExistingFileForAnAbsentTarget(): void
    {
        $this->assertRefusesEntryBehindNonSearchableParent('file');
    }

    #[Test]
    public function itDoesNotMistakeAnInaccessibleOwnedLinkForAnAbsentTarget(): void
    {
        $this->assertRefusesEntryBehindNonSearchableParent('link');
    }

    #[Test]
    public function itRejudgesACreatedDirectoryAfterItsModeChanges(): void
    {
        $base = realpath(sys_get_temp_dir()) . '/qmx-created-' . bin2hex(random_bytes(6));
        mkdir($base, 0700);
        file_put_contents($base . '/report.json', '{}');
        try {
            TargetPath::rememberCreatedDirectory($base);
            self::assertSame([], TargetPath::resolve($base . '/report.json')->exposure);
            chmod($base, 0777);
            $changed = TargetPath::resolve($base . '/report.json');
            self::assertNotEmpty($changed->exposure);
            self::assertSame($base, $changed->exposure[array_key_last($changed->exposure)]->directory);
        } finally {
            unlink($base . '/report.json');
            rmdir($base);
        }
    }

    #[Test]
    public function itRejudgesACreatedDirectoryAfterTheEffectiveOwnerChanges(): void
    {
        if (!\function_exists('posix_geteuid')) {
            self::markTestSkipped('POSIX owner lookup is unavailable');
        }
        $base = realpath(sys_get_temp_dir()) . '/qmx-owner-change-' . bin2hex(random_bytes(6));
        mkdir($base, 0700);
        file_put_contents($base . '/report.json', '{}');
        $root = \dirname(__DIR__, 4);
        $script = <<<'PHP'
            function posix_geteuid(): int { return $GLOBALS['effectiveUid']; }
            require $argv[1];
            $GLOBALS['effectiveUid'] = posix_getuid();
            \Qualimetrix\Core\FileTarget\TargetPath::rememberCreatedDirectory($argv[2]);
            $before = \Qualimetrix\Core\FileTarget\TargetPath::resolve($argv[2] . '/report.json');
            ++$GLOBALS['effectiveUid'];
            $after = \Qualimetrix\Core\FileTarget\TargetPath::resolve($argv[2] . '/report.json');
            echo json_encode([count($before->exposure), array_column($after->exposure, 'directory')], JSON_THROW_ON_ERROR);
            PHP;
        try {
            $result = ChildProcess::run([\PHP_BINARY, '-d', 'disable_functions=posix_geteuid', '-r', $script, $root . '/vendor/autoload.php', $base]);
            self::assertSame(0, $result['exitCode'], $result['stderr']);
            [$before, $after] = json_decode($result['stdout'], true, flags: \JSON_THROW_ON_ERROR);
            self::assertSame(0, $before);
            self::assertContains($base, $after);
        } finally {
            unlink($base . '/report.json');
            rmdir($base);
        }
    }

    #[Test]
    public function itClassifiesARealUnsearchableIntermediateParentAsUnopenable(): void
    {
        $this->assertRefusesEntryBehindNonSearchableParent('inner/report.json');
    }

    #[Test]
    public function itClassifiesAnUninspectableIntermediateParentAsUnopenable(): void
    {
        $base = realpath(sys_get_temp_dir()) . '/qmx-uninspectable-' . bin2hex(random_bytes(6));
        mkdir($base);
        $parent = $base . '/present-parent';
        mkdir($parent);
        $root = \dirname(__DIR__, 4);
        $script = <<<'PHP'
namespace Qualimetrix\Core\FileTarget {
    function lstat(string $path): array|false
    {
        if ($path === $GLOBALS['probe_parent']) {
            \trigger_error('Input/output error from qmx probe', \E_USER_WARNING);

            return false;
        }

        return \lstat($path);
    }
}
namespace {
    require $argv[1];
    $GLOBALS['probe_parent'] = $argv[2];
    $kind = null;
    $detail = null;
    try {
        \Qualimetrix\Core\FileTarget\TargetPath::resolve($argv[2] . '/child.php');
    } catch (\Qualimetrix\Core\FileTarget\FileTargetFailure $failure) {
        $kind = $failure->kind->name;
        $detail = $failure->detail;
    }
    echo \json_encode(['kind' => $kind, 'detail' => $detail]);
}
PHP;

        try {
            $run = ChildProcess::run([\PHP_BINARY, '-r', $script, $root . '/vendor/autoload.php', $parent]);
            self::assertSame(0, $run['exitCode'], $run['stderr']);
            $result = json_decode($run['stdout'], true, 512, \JSON_THROW_ON_ERROR);
            self::assertSame(FileTargetFailureKind::Unopenable->name, $result['kind']);
            self::assertStringContainsString($parent, $result['detail']);
            self::assertStringContainsString('Input/output error from qmx probe', $result['detail']);
        } finally {
            rmdir($parent);
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

    private function assertRefusesEntryBehindNonSearchableParent(string $entry): void
    {
        $base = realpath(sys_get_temp_dir()) . '/qmx-target-' . bin2hex(random_bytes(6));
        $sealed = $base . '/sealed';
        mkdir($base);
        mkdir($sealed);
        mkdir($sealed . '/inner');
        file_put_contents($sealed . '/file', 'KEEP');
        symlink('file', $sealed . '/link');
        chmod($sealed, 0000);

        try {
            if (is_executable($sealed)) {
                self::markTestSkipped('Permission bits do not block directory search in this process');
            }

            $path = $sealed . '/' . $entry;
            $nativeWarning = null;
            set_error_handler(static function (int $severity, string $message) use (&$nativeWarning): bool {
                $nativeWarning = $message;

                return true;
            });
            try {
                $nativeEntry = lstat($path);
            } finally {
                restore_error_handler();
            }
            self::assertFalse($nativeEntry);
            try {
                $resolved = TargetPath::resolve($path);
                self::fail('An inaccessible existing ' . $entry . ' was classified as ' . $resolved->kind->name);
            } catch (FileTargetFailure $failure) {
                self::assertSame(FileTargetFailureKind::Unopenable, $failure->kind);
                self::assertSame($path, $failure->spelling);
                self::assertStringContainsString($sealed, $failure->detail);
                if ($nativeWarning !== null) {
                    $failedComponent = $sealed . '/' . explode('/', $entry)[0];
                    self::assertStringContainsString(str_replace($path, $failedComponent, $nativeWarning), $failure->detail);
                }
            }
        } finally {
            chmod($sealed, 0700);
            self::assertSame('KEEP', file_get_contents($sealed . '/file'));
            self::assertTrue(is_link($sealed . '/link'));
            unlink($sealed . '/link');
            unlink($sealed . '/file');
            rmdir($sealed . '/inner');
            rmdir($sealed);
            rmdir($base);
        }
    }
}
