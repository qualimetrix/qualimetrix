<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Infrastructure\Logging\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\LogLevel;
use Qualimetrix\Analysis\Configuration\Contract\Refusal\RefusalInterface;
use Qualimetrix\Core\FileTarget\HeldTarget;
use Qualimetrix\Core\FileTarget\TargetPath;
use Qualimetrix\Infrastructure\Console\RunTarget\RunTargets;
use Qualimetrix\Infrastructure\Logging\FileLogger;
use Qualimetrix\Subprocess\ChildProcess;

require_once \dirname(__DIR__, 4) . '/scripts/subprocess/ChildProcess.php';

#[CoversClass(FileLogger::class)]
final class FileLoggerTest extends TestCase
{
    private string $tempDir;

    /** @var list<HeldTarget> */
    private array $targets = [];

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/qmx_test_' . bin2hex(random_bytes(6));
        if (!is_dir($this->tempDir)) {
            mkdir($this->tempDir, 0755, true);
        }
    }

    protected function tearDown(): void
    {
        foreach ($this->targets as $target) {
            $target->release();
        }
        $this->targets = [];
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
        self::assertFileDoesNotExist($path);
        $this->attach($logger, $path);

        self::assertFileExists($path);
        $content = file_get_contents($path);
        self::assertIsString($content);
        self::assertStringContainsString('Test message', $content);
    }

    #[Test]
    public function itLeavesMissingDirectoriesUntouchedUntilTheTargetIsJudged(): void
    {
        $path = $this->tempDir . '/nested/dir/log.log';
        $logger = new FileLogger($path);

        $logger->info('Test');
        self::assertDirectoryDoesNotExist(\dirname($path));
        $this->expectException(\Qualimetrix\Analysis\Configuration\Contract\Refusal\ConfigurationRefusal::class);
        RunTargets::judgement('--log-file', $path);
    }

    #[Test]
    public function itWritesJsonLines(): void
    {
        $path = $this->tempDir . '/test.log';
        $logger = new FileLogger($path);
        $this->attach($logger, $path);

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
        $this->attach($logger, $path);

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
        $this->attach($logger, $path);

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
        $this->attach($logger, $path);

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
        $this->attach($logger1, $path);
        $logger1->info('First');
        unset($logger1);

        // Second logger appends another entry
        $logger2 = new FileLogger($path);
        $this->attach($logger2, $path);
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
        $this->attach($logger, $path);

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
        $this->attach($logger, $path);

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

        $refusal = self::refusalWithoutDiagnostics(static fn() => RunTargets::judgement('--log-file', $path));

        self::assertStringContainsString($path, $refusal->summary());
        self::assertStringContainsString('--log-file', $refusal->summary());
        self::assertFileDoesNotExist($path);
    }

    #[Test]
    public function itRefusesAFileItCannotOpenWithoutAPhpDiagnostic(): void
    {
        self::skipAsRoot();
        $path = $this->tempDir . '/qmx.log';
        touch($path);
        chmod($path, 0444);

        $refusal = self::refusalWithoutDiagnostics(static fn() => RunTargets::judgement('--log-file', $path));

        self::assertStringContainsString($path, $refusal->summary());
        self::assertStringContainsString('not writable', $refusal->summary());
        self::assertSame('', file_get_contents($path));
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
        $this->attach($logger, $path);

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
        $this->attach($logger, $path);

        $logger->info('Measured {what}', ['what' => 'ratio', 'value' => \NAN]);

        $records = self::records($path);
        self::assertCount(1, $records);
        self::assertSame('Measured ratio', $records[0]['message']);
        self::assertNull($records[0]['context']);
        self::assertSame('Inf and NaN cannot be JSON encoded', $records[0]['context_error']);
    }

    /** A short native append is latched; later log calls count loss without throwing into collection. */
    #[Test]
    public function itRefusesToLeaveATruncatedRecord(): void
    {
        $path = $this->tempDir . '/partial.log';
        file_put_contents($path, '');
        $script = <<<'PHP'
            namespace Qualimetrix\Core\FileTarget {
                function fwrite($stream, string $bytes): int|false
                {
                    ++$GLOBALS['qmx_native_hits'];
                    return $GLOBALS['qmx_native_hits'] === 1
                        ? \fwrite($stream, substr($bytes, 0, 10))
                        : 0;
                }
            }
            namespace {
                require $argv[1];
                $GLOBALS['qmx_native_hits'] = 0;
                $held = \Qualimetrix\Core\FileTarget\HeldTarget::claim(
                    \Qualimetrix\Core\FileTarget\TargetPath::resolve($argv[2]),
                );
                $logger = new \Qualimetrix\Infrastructure\Logging\FileLogger($argv[2]);
                $logger->attach($held);
                $logger->info('a record longer than ten bytes');
                $logger->info('later record');
                try {
                    $logger->settle();
                    $failure = null;
                } catch (\Qualimetrix\Core\FileTarget\FileTargetFailure $caught) {
                    $failure = [$caught->kind->name, $caught->spelling, $caught->getMessage()];
                }
                $held->release();
                echo json_encode(['hits' => $GLOBALS['qmx_native_hits'], 'failure' => $failure, 'bytes' => file_get_contents($argv[2])]);
            }
            PHP;
        $run = ChildProcess::run([\PHP_BINARY, '-r', $script, \dirname(__DIR__, 4) . '/vendor/autoload.php', $path]);

        self::assertSame(0, $run['exitCode'], $run['stderr']);
        $result = json_decode($run['stdout'], true, flags: \JSON_THROW_ON_ERROR);
        self::assertSame(2, $result['hits']);
        self::assertSame('PartialWrite', $result['failure'][0]);
        self::assertSame($path, $result['failure'][1]);
        self::assertStringContainsString('2 log record(s) lost', $result['failure'][2]);
        self::assertSame(10, \strlen($result['bytes']));
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

    /** @param callable(): mixed $operation */
    private static function refusalWithoutDiagnostics(callable $operation): RefusalInterface
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
            $operation();
        } catch (RefusalInterface $refusal) {
            return $refusal;
        } finally {
            restore_error_handler();
            error_reporting($reporting);
            self::assertSame([], $diagnostics);
        }

        self::fail('The log target accepted a path it cannot write.');
    }

    private function attach(FileLogger $logger, string $path): void
    {
        $target = HeldTarget::claim(TargetPath::resolve($path));
        $this->targets[] = $target;
        $logger->attach($target);
    }

    private static function skipAsRoot(): void
    {
        if (posix_getuid() === 0) {
            self::markTestSkipped('Root ignores permission bits, so nothing here is refused to run as root.');
        }
    }
}
