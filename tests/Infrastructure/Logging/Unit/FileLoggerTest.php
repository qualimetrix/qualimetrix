<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Infrastructure\Logging\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\LogLevel;
use Qualimetrix\Infrastructure\Logging\Contract\LogFileUnavailable;
use Qualimetrix\Infrastructure\Logging\FileLogger;
use RuntimeException;

#[CoversClass(FileLogger::class)]
final class FileLoggerTest extends TestCase
{
    private string $tempDir;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/qmx_test_' . bin2hex(random_bytes(6));
        if (!is_dir($this->tempDir)) {
            mkdir($this->tempDir, 0755, true);
        }
    }

    protected function tearDown(): void
    {
        // Cleanup temp directory recursively
        if (is_dir($this->tempDir)) {
            chmod($this->tempDir, 0755);
            $this->removeDirectory($this->tempDir);
        }
    }

    private function removeDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        $files = array_diff((scandir($dir) !== false ? scandir($dir) : []), ['.', '..']);
        foreach ($files as $file) {
            $path = $dir . '/' . $file;
            if (is_dir($path)) {
                $this->removeDirectory($path);
            } else {
                unlink($path);
            }
        }
        rmdir($dir);
    }

    #[Test]
    public function itWritesToFile(): void
    {
        $path = $this->tempDir . '/test.log';
        $logger = new FileLogger($path);

        $logger->info('Test message');

        self::assertFileExists($path);
        $content = file_get_contents($path);
        self::assertIsString($content);
        self::assertStringContainsString('Test message', $content);
    }

    #[Test]
    public function itCreatesDirectory(): void
    {
        $path = $this->tempDir . '/nested/dir/log.log';
        $logger = new FileLogger($path);

        $logger->info('Test');

        self::assertFileExists($path);
    }

    #[Test]
    public function itWritesJsonLines(): void
    {
        $path = $this->tempDir . '/test.log';
        $logger = new FileLogger($path);

        $logger->info('Test message', ['key' => 'value']);

        $content = file_get_contents($path);
        self::assertIsString($content);

        $line = trim($content);
        $data = json_decode($line, true);

        self::assertIsArray($data);
        self::assertSame('info', $data['level']);
        self::assertSame('Test message', $data['message']);
        self::assertSame(['key' => 'value'], $data['context']);
        self::assertArrayHasKey('timestamp', $data);
    }

    #[Test]
    public function itInterpolatesPlaceholders(): void
    {
        $path = $this->tempDir . '/test.log';
        $logger = new FileLogger($path);

        $logger->info('Processing {file}', ['file' => 'test.php', 'extra' => 'data']);

        $content = file_get_contents($path);
        self::assertIsString($content);

        $data = json_decode(trim($content), true);
        self::assertIsArray($data);
        // Message should have placeholders interpolated
        self::assertSame('Processing test.php', $data['message']);
        // Full context preserved in structured data
        self::assertSame(['file' => 'test.php', 'extra' => 'data'], $data['context']);
    }

    #[Test]
    public function itRespectsMinLevel(): void
    {
        $path = $this->tempDir . '/test.log';
        $logger = new FileLogger($path, LogLevel::WARNING);

        $logger->debug('Debug message');
        $logger->info('Info message');
        $logger->warning('Warning message');
        $logger->error('Error message');

        $content = file_get_contents($path);
        self::assertIsString($content);

        self::assertStringNotContainsString('Debug message', $content);
        self::assertStringNotContainsString('Info message', $content);
        self::assertStringContainsString('Warning message', $content);
        self::assertStringContainsString('Error message', $content);
    }

    #[Test]
    public function itWritesMultipleLogEntries(): void
    {
        $path = $this->tempDir . '/test.log';
        $logger = new FileLogger($path);

        $logger->info('First');
        $logger->info('Second');
        $logger->info('Third');

        $content = file_get_contents($path);
        self::assertIsString($content);

        $lines = explode("\n", trim($content));
        self::assertCount(3, $lines);

        foreach ($lines as $line) {
            $data = json_decode($line, true);
            self::assertIsArray($data);
            self::assertArrayHasKey('level', $data);
            self::assertArrayHasKey('message', $data);
            self::assertArrayHasKey('timestamp', $data);
        }
    }

    #[Test]
    public function itAppendsToExistingFile(): void
    {
        $path = $this->tempDir . '/test.log';

        // First logger writes one entry
        $logger1 = new FileLogger($path);
        $logger1->info('First');
        unset($logger1);

        // Second logger appends another entry
        $logger2 = new FileLogger($path);
        $logger2->info('Second');
        unset($logger2);

        $content = file_get_contents($path);
        self::assertIsString($content);

        $lines = explode("\n", trim($content));
        self::assertCount(2, $lines);

        self::assertStringContainsString('First', $lines[0]);
        self::assertStringContainsString('Second', $lines[1]);
    }

    #[Test]
    public function itHandlesEmptyContext(): void
    {
        $path = $this->tempDir . '/test.log';
        $logger = new FileLogger($path);

        $logger->info('No context');

        $content = file_get_contents($path);
        self::assertIsString($content);

        $data = json_decode(trim($content), true);
        self::assertIsArray($data);
        self::assertSame([], $data['context']);
    }

    #[Test]
    public function itWritesTimestampInIso8601Format(): void
    {
        $path = $this->tempDir . '/test.log';
        $logger = new FileLogger($path);

        $logger->info('Test');

        $content = file_get_contents($path);
        self::assertIsString($content);

        $data = json_decode(trim($content), true);
        self::assertIsArray($data);

        // Timestamp should be in ISO 8601 format (date('c'))
        self::assertMatchesRegularExpression(
            '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}[+-]\d{2}:\d{2}$/',
            $data['timestamp'],
        );
    }

    /**
     * A path the user cannot write is reported once, as a typed failure the
     * console answers as `--log-file` input: a PHP warning from the failed
     * `mkdir()` reached stdout under `display_errors=1` ahead of the JSON
     * envelope and left a machine-format stdout unparseable.
     */
    #[Test]
    public function itRefusesADirectoryItCannotCreateWithoutAPhpDiagnostic(): void
    {
        self::skipAsRoot();
        chmod($this->tempDir, 0555);
        $path = $this->tempDir . '/sub/qmx.log';

        $refusal = self::refusalWithoutDiagnostics(static fn() => new FileLogger($path));

        self::assertSame($path, $refusal->path);
        self::assertSame(\sprintf('whose directory "%s" cannot be created: Permission denied', $this->tempDir . '/sub'), $refusal->reason);
    }

    #[Test]
    public function itRefusesAFileItCannotOpenWithoutAPhpDiagnostic(): void
    {
        self::skipAsRoot();
        $path = $this->tempDir . '/qmx.log';
        touch($path);
        chmod($path, 0444);

        $refusal = self::refusalWithoutDiagnostics(static fn() => new FileLogger($path));

        self::assertSame($path, $refusal->path);
        self::assertSame('which cannot be opened for appending: Permission denied', $refusal->reason);
    }

    /**
     * A context value with bytes that are not UTF-8 (a file path on a Linux
     * file system can carry them) made `json_encode()` return `false`, and
     * the record became an empty line in a format where every line is a
     * document.
     */
    #[Test]
    public function itKeepsARecordWhoseContextIsNotValidUtf8(): void
    {
        $path = $this->tempDir . '/test.log';
        $logger = new FileLogger($path);

        $logger->info('before');
        $logger->warning('Failed to parse file', ['file' => "src/\xB1\x31.php"]);
        $logger->info('after');

        $records = self::records($path);
        self::assertCount(3, $records);
        self::assertSame('Failed to parse file', $records[1]['message']);
        self::assertSame(['file' => "src/\u{FFFD}1.php"], $records[1]['context']);
    }

    /**
     * What UTF-8 substitution cannot save — a non-finite float — keeps the
     * record and says the context was lost, rather than dropping either.
     */
    #[Test]
    public function itSaysSoWhenAContextCannotBeEncoded(): void
    {
        $path = $this->tempDir . '/test.log';
        $logger = new FileLogger($path);

        $logger->info('Measured {what}', ['what' => 'ratio', 'value' => \NAN]);

        $records = self::records($path);
        self::assertCount(1, $records);
        self::assertSame('Measured ratio', $records[0]['message']);
        self::assertNull($records[0]['context']);
        self::assertSame('Inf and NaN cannot be JSON encoded', $records[0]['context_error']);
    }

    /**
     * A full disk takes part of a record; the rest of the run would append
     * after a truncated line, which no JSON Lines reader can split again.
     */
    #[Test]
    public function itRefusesToLeaveATruncatedRecord(): void
    {
        $device = new class {
            public static int $room = 10;

            /** @var resource|null */
            public $context;

            public function stream_open(string $path, string $mode, int $options, ?string &$openedPath): bool
            {
                return true;
            }

            public function stream_write(string $data): int
            {
                $taken = min(\strlen($data), self::$room);
                self::$room -= $taken;

                return $taken;
            }

            /** @return array{mode: int} */
            public function url_stat(string $path, int $flags): array
            {
                return ['mode' => 0o040755];
            }
        };
        stream_wrapper_register('qmx-full-disk', $device::class);

        try {
            $logger = new FileLogger('qmx-full-disk://volume/qmx.log');

            $this->expectException(RuntimeException::class);
            $this->expectExceptionMessageMatches('~^Failed to write the log file qmx-full-disk://volume/qmx\.log: 10 of \d+ bytes of a record were written\.$~');

            $logger->info('a record longer than the room left');
        } finally {
            stream_wrapper_unregister('qmx-full-disk');
        }
    }

    /** @return list<array<string, mixed>> */
    private static function records(string $path): array
    {
        $content = file_get_contents($path);
        self::assertIsString($content);

        $records = [];
        foreach (explode("\n", rtrim($content, "\n")) as $line) {
            $record = json_decode($line, true, flags: \JSON_THROW_ON_ERROR);
            self::assertIsArray($record);
            $records[] = $record;
        }

        return $records;
    }

    /** @param callable(): mixed $construct */
    private static function refusalWithoutDiagnostics(callable $construct): LogFileUnavailable
    {
        $diagnostics = [];
        // PHPUnit runs tests with `E_WARNING` outside `error_reporting()`, so
        // the filter below would drop the very warning this guards against.
        $reporting = error_reporting(\E_ALL);
        // What `display_errors` would print: a diagnostic the code silenced
        // with `@` is out of `error_reporting()` and never reaches a stream.
        set_error_handler(static function (int $level, string $message) use (&$diagnostics): bool {
            if ((error_reporting() & $level) !== 0) {
                $diagnostics[] = $message;
            }

            return true;
        });

        try {
            $construct();
        } catch (LogFileUnavailable $refusal) {
            return $refusal;
        } finally {
            restore_error_handler();
            error_reporting($reporting);
            self::assertSame([], $diagnostics);
        }

        self::fail('The logger accepted a path it cannot write.');
    }

    private static function skipAsRoot(): void
    {
        if (posix_getuid() === 0) {
            self::markTestSkipped('Root ignores permission bits, so nothing here is refused to run as root.');
        }
    }
}
