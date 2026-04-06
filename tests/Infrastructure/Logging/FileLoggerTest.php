<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Infrastructure\Logging;

use PHPUnit\Framework\TestCase;
use Psr\Log\LogLevel;
use Qualimetrix\Infrastructure\Logging\FileLogger;

final class FileLoggerTest extends TestCase
{
    private string $tempDir;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/qmx_test_' . uniqid();
        if (!is_dir($this->tempDir)) {
            mkdir($this->tempDir, 0755, true);
        }
    }

    protected function tearDown(): void
    {
        // Cleanup temp directory recursively
        if (is_dir($this->tempDir)) {
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

    public function testWritesToFile(): void
    {
        $path = $this->tempDir . '/test.log';
        $logger = new FileLogger($path);

        $logger->info('Test message');

        self::assertFileExists($path);
        $content = file_get_contents($path);
        self::assertIsString($content);
        self::assertStringContainsString('Test message', $content);
    }

    public function testCreatesDirectory(): void
    {
        $path = $this->tempDir . '/nested/dir/log.log';
        $logger = new FileLogger($path);

        $logger->info('Test');

        self::assertFileExists($path);
    }

    public function testWritesJsonLines(): void
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

    public function testInterpolatesPlaceholders(): void
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

    public function testRespectsMinLevel(): void
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

    public function testMultipleLogEntries(): void
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

    public function testAppendsToExistingFile(): void
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

    public function testHandlesEmptyContext(): void
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

    public function testTimestampFormat(): void
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
}
