<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Core\FileTarget\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Core\FileTarget\FileTargetFailure;
use Qualimetrix\Core\FileTarget\FileTargetFailureKind;
use Qualimetrix\Core\FileTarget\HeldTarget;
use Qualimetrix\Core\FileTarget\TargetPath;
use Qualimetrix\Subprocess\ChildProcess;

require_once \dirname(__DIR__, 4) . '/scripts/subprocess/ChildProcess.php';

#[CoversClass(HeldTarget::class)]
final class HeldTargetTest extends TestCase
{
    #[Test]
    public function itPreservesExistingBytesUntilTheClaimedHandleWrites(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'qmx-held-');
        self::assertIsString($path);
        file_put_contents($path, 'before');

        try {
            $held = HeldTarget::claim(TargetPath::resolve($path));
            self::assertSame('before', file_get_contents($path));
            $held->write('after');
            $held->release();
            self::assertSame('after', file_get_contents($path));
        } finally {
            unlink($path);
        }
    }

    #[Test]
    public function itRefusesAnIdentityChangeBeforeOpeningWithoutTruncatingEitherFile(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'qmx-held-');
        self::assertIsString($path);
        file_put_contents($path, 'original');
        $judged = TargetPath::resolve($path);
        unlink($path);
        file_put_contents($path, 'replacement');

        try {
            try {
                HeldTarget::claim($judged);
                self::fail('Changed identity must be refused');
            } catch (FileTargetFailure $failure) {
                self::assertSame(FileTargetFailureKind::IdentityChanged, $failure->kind);
            }
            self::assertSame('replacement', file_get_contents($path));
        } finally {
            unlink($path);
        }
    }

    #[Test]
    public function itRefusesAReplacementInstalledImmediatelyBeforeOpening(): void
    {
        $base = realpath(sys_get_temp_dir()) . '/qmx-held-' . bin2hex(random_bytes(6));
        mkdir($base);
        $path = $base . '/target';
        file_put_contents($path, 'original');
        $root = \dirname(__DIR__, 4);
        $script = <<<'PHP'
namespace Qualimetrix\Core\FileTarget {
    function fopen(string $filename, string $mode)
    {
        if ($mode === 'r+e' && $filename === $GLOBALS['qmx_race_path'] && !isset($GLOBALS['qmx_race_done'])) {
            $GLOBALS['qmx_race_done'] = true;
            \rename($filename, $filename . '.original');
            \file_put_contents($filename, 'replacement');
        }

        return \fopen($filename, $mode);
    }
}

namespace {
    require $argv[1];
    $GLOBALS['qmx_race_path'] = $argv[2];
    $judged = \Qualimetrix\Core\FileTarget\TargetPath::resolve($argv[2]);
    $kind = null;
    try {
        $held = \Qualimetrix\Core\FileTarget\HeldTarget::claim($judged);
        $held->write('WRITTEN');
        $held->release();
    } catch (\Qualimetrix\Core\FileTarget\FileTargetFailure $failure) {
        $kind = $failure->kind->name;
    }
    echo \json_encode([
        'kind' => $kind,
        'swapped' => isset($GLOBALS['qmx_race_done']),
        'original' => \file_get_contents($argv[2] . '.original'),
        'replacement' => \file_get_contents($argv[2]),
    ]);
}
PHP;

        try {
            $run = ChildProcess::run([\PHP_BINARY, '-r', $script, $root . '/vendor/autoload.php', $path]);
            self::assertSame(0, $run['exitCode'], $run['stderr']);
            $result = json_decode($run['stdout'], true, 512, \JSON_THROW_ON_ERROR);
            self::assertTrue($result['swapped']);
            self::assertSame('IdentityChanged', $result['kind']);
            self::assertSame('original', $result['original']);
            self::assertSame('replacement', $result['replacement']);
        } finally {
            if (file_exists($path . '.original')) {
                unlink($path . '.original');
            }
            unlink($path);
            rmdir($base);
        }
    }

    #[Test]
    public function itRemovesAnUnwrittenExclusiveNameOnRelease(): void
    {
        $base = realpath(sys_get_temp_dir()) . '/qmx-held-' . bin2hex(random_bytes(6));
        mkdir($base);
        $path = $base . '/new';

        try {
            $held = HeldTarget::claim(TargetPath::resolve($path));
            self::assertSame('', file_get_contents($path));
            $held->release();
            self::assertFileDoesNotExist($path);
        } finally {
            if (file_exists($path)) {
                unlink($path);
            }
            rmdir($base);
        }
    }

    #[Test]
    public function itRefusesANameThatBecameADanglingLinkBeforeClaim(): void
    {
        $base = realpath(sys_get_temp_dir()) . '/qmx-held-' . bin2hex(random_bytes(6));
        mkdir($base);
        $path = $base . '/new';
        $judged = TargetPath::resolve($path);
        symlink('outside', $path);

        try {
            try {
                HeldTarget::claim($judged);
                self::fail('A dangling link must not be followed during exclusive creation');
            } catch (FileTargetFailure $failure) {
                self::assertSame(FileTargetFailureKind::IdentityChanged, $failure->kind);
            }
            self::assertFileDoesNotExist($base . '/outside');
        } finally {
            unlink($path);
            rmdir($base);
        }
    }

    #[Test]
    public function itDoesNotDeleteAnotherEntryWhenAnUnwrittenNameIsReplaced(): void
    {
        $base = realpath(sys_get_temp_dir()) . '/qmx-held-' . bin2hex(random_bytes(6));
        mkdir($base);
        $path = $base . '/new';

        try {
            $held = HeldTarget::claim(TargetPath::resolve($path));
            unlink($path);
            file_put_contents($path, 'other writer');
            $held->release();
            self::assertSame('other writer', file_get_contents($path));
        } finally {
            unlink($path);
            rmdir($base);
        }
    }

    #[Test]
    public function itAppendsWholeRecordsThroughTheHeldHandle(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'qmx-held-');
        self::assertIsString($path);
        file_put_contents($path, "one\n");

        try {
            $held = HeldTarget::claim(TargetPath::resolve($path));
            $held->append("two\n");
            $held->release();
            self::assertSame("one\ntwo\n", file_get_contents($path));
        } finally {
            unlink($path);
        }
    }

    #[Test]
    public function itWritesThroughADuplicatedDescriptorWithoutTruncatingItsFile(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'qmx-held-');
        self::assertIsString($path);
        file_put_contents($path, 'before');
        $root = \dirname(__DIR__, 4);
        $script = <<<'PHP'
require $argv[1];
$target = \Qualimetrix\Core\FileTarget\TargetPath::resolve('php://stdout');
$held = \Qualimetrix\Core\FileTarget\HeldTarget::claim($target);
$held->write('after');
$held->release();
PHP;

        try {
            $process = proc_open(
                [\PHP_BINARY, '-r', $script, $root . '/vendor/autoload.php'],
                [1 => ['file', $path, 'a'], 2 => ['pipe', 'w']],
                $pipes,
            );
            self::assertIsResource($process);
            $error = stream_get_contents($pipes[2]);
            fclose($pipes[2]);
            self::assertSame(0, proc_close($process), (string) $error);
            self::assertSame('beforeafter', file_get_contents($path));
        } finally {
            unlink($path);
        }
    }

    #[Test]
    public function itCompletesShortWritesForReplacementAndAppend(): void
    {
        $replacement = $this->writeProbe('short', 'write');
        self::assertNull($replacement['kind']);
        self::assertSame('abcdef', $replacement['content']);

        $append = $this->writeProbe('short', 'append');
        self::assertNull($append['kind']);
        self::assertSame('beforeabcdef', $append['content']);
    }

    #[Test]
    public function itReportsPartialWriteWhenTheWriterStopsAfterTwoBytes(): void
    {
        $result = $this->writeProbe('zero', 'write');

        self::assertSame(FileTargetFailureKind::PartialWrite->name, $result['kind']);
        self::assertIsString($result['message']);
        self::assertStringContainsString('wrote 2 of 6 bytes', $result['message']);
        self::assertSame('ab', $result['content']);
    }

    #[Test]
    public function itReportsPartialWriteWhenFlushFails(): void
    {
        $result = $this->writeProbe('flush', 'append');

        self::assertSame(FileTargetFailureKind::PartialWrite->name, $result['kind']);
        self::assertIsString($result['message']);
        self::assertStringContainsString('wrote 6 of 6 bytes but flush failed', $result['message']);
        self::assertSame('beforeabcdef', $result['content']);
    }

    /** @return array{kind: string|null, message: string|null, content: string} */
    private function writeProbe(string $mode, string $operation): array
    {
        $path = tempnam(sys_get_temp_dir(), 'qmx-held-');
        self::assertIsString($path);
        file_put_contents($path, 'before');
        $root = \dirname(__DIR__, 4);
        $script = <<<'PHP'
namespace Qualimetrix\Core\FileTarget {
    function fwrite($stream, string $bytes)
    {
        if ($GLOBALS['qmx_probe_mode'] === 'short') {
            return \fwrite($stream, \substr($bytes, 0, 2));
        }
        if ($GLOBALS['qmx_probe_mode'] === 'zero') {
            if (($GLOBALS['qmx_probe_writes']++) === 0) {
                return \fwrite($stream, \substr($bytes, 0, 2));
            }
            return 0;
        }

        return \fwrite($stream, $bytes);
    }

    function fflush($stream): bool
    {
        return $GLOBALS['qmx_probe_mode'] === 'flush' ? false : \fflush($stream);
    }
}

namespace {
    require $argv[1];
    $GLOBALS['qmx_probe_mode'] = $argv[3];
    $GLOBALS['qmx_probe_writes'] = 0;
    $target = \Qualimetrix\Core\FileTarget\TargetPath::resolve($argv[2]);
    $held = \Qualimetrix\Core\FileTarget\HeldTarget::claim($target);
    $kind = null;
    $message = null;
    try {
        if ($argv[4] === 'write') {
            $held->write('abcdef');
        } else {
            $held->append('abcdef');
        }
    } catch (\Qualimetrix\Core\FileTarget\FileTargetFailure $failure) {
        $kind = $failure->kind->name;
        $message = $failure->getMessage();
    }
    $held->release();
    echo \json_encode(['kind' => $kind, 'message' => $message]);
}
PHP;

        try {
            $run = ChildProcess::run([\PHP_BINARY, '-r', $script, $root . '/vendor/autoload.php', $path, $mode, $operation]);
            self::assertSame(0, $run['exitCode'], $run['stderr']);
            $result = json_decode($run['stdout'], true, 512, \JSON_THROW_ON_ERROR);
            self::assertIsArray($result);
            $content = file_get_contents($path);
            self::assertIsString($content);

            return ['kind' => $result['kind'], 'message' => $result['message'], 'content' => $content];
        } finally {
            unlink($path);
        }
    }
}
