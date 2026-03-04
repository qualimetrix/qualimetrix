<?php

declare(strict_types=1);

namespace AiMessDetector\Tests\Unit\Baseline;

use AiMessDetector\Baseline\Baseline;
use AiMessDetector\Baseline\BaselineEntry;
use AiMessDetector\Baseline\BaselineWriter;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(BaselineWriter::class)]
final class BaselineWriterTest extends TestCase
{
    private BaselineWriter $writer;
    private string $tempDir;

    protected function setUp(): void
    {
        $this->writer = new BaselineWriter();
        $this->tempDir = sys_get_temp_dir() . '/aimd_baseline_test_' . uniqid();
        mkdir($this->tempDir, 0755, true);
    }

    protected function tearDown(): void
    {
        if (is_dir($this->tempDir)) {
            $this->recursiveDelete($this->tempDir);
        }
    }

    public function testWritesValidJson(): void
    {
        $baseline = new Baseline(
            version: 1,
            generated: new DateTimeImmutable('2025-12-08T10:00:00+00:00'),
            entries: [
                'method:App\Foo::bar' => [
                    new BaselineEntry('complexity', 'a1b2c3d4'),
                ],
            ],
        );

        $path = $this->tempDir . '/baseline.json';
        $this->writer->write($baseline, $path);

        self::assertFileExists($path);

        $content = file_get_contents($path);
        self::assertNotFalse($content);

        $data = json_decode($content, true);
        self::assertIsArray($data);
        self::assertSame(1, $data['version']);
        self::assertSame('2025-12-08T10:00:00+00:00', $data['generated']);
        self::assertSame(1, $data['count']);
        self::assertArrayHasKey('method:App\Foo::bar', $data['violations']);
    }

    public function testCreatesDirectoryIfNotExists(): void
    {
        $baseline = new Baseline(
            version: 1,
            generated: new DateTimeImmutable(),
            entries: [],
        );

        $path = $this->tempDir . '/subdir/baseline.json';
        $this->writer->write($baseline, $path);

        self::assertFileExists($path);
        self::assertDirectoryExists($this->tempDir . '/subdir');
    }

    public function testAtomicWrite(): void
    {
        $baseline = new Baseline(
            version: 1,
            generated: new DateTimeImmutable(),
            entries: [
                'method:App\Foo::bar' => [
                    new BaselineEntry('complexity', 'a1b2c3d4'),
                ],
            ],
        );

        $path = $this->tempDir . '/baseline.json';
        $this->writer->write($baseline, $path);

        // Verify no temp files left behind
        $files = glob($this->tempDir . '/*.tmp.*');
        self::assertEmpty($files, 'Temporary files should be cleaned up');
    }

    public function testOverwritesExistingFile(): void
    {
        $path = $this->tempDir . '/baseline.json';

        // Write first baseline
        $baseline1 = new Baseline(
            version: 1,
            generated: new DateTimeImmutable('2025-12-08T10:00:00+00:00'),
            entries: [
                'method:App\Foo::bar' => [
                    new BaselineEntry('complexity', 'a1b2c3d4'),
                ],
            ],
        );
        $this->writer->write($baseline1, $path);

        // Write second baseline (overwrite)
        $baseline2 = new Baseline(
            version: 1,
            generated: new DateTimeImmutable('2025-12-08T11:00:00+00:00'),
            entries: [
                'class:App\Bar' => [
                    new BaselineEntry('size', 'e5f6g7h8'),
                ],
            ],
        );
        $this->writer->write($baseline2, $path);

        $content = file_get_contents($path);
        self::assertNotFalse($content);

        $data = json_decode($content, true);
        self::assertSame('2025-12-08T11:00:00+00:00', $data['generated']);
        self::assertArrayHasKey('class:App\Bar', $data['violations']);
        self::assertArrayNotHasKey('method:App\Foo::bar', $data['violations']);
    }

    public function testWritesEmptyBaseline(): void
    {
        $baseline = new Baseline(
            version: 1,
            generated: new DateTimeImmutable(),
            entries: [],
        );

        $path = $this->tempDir . '/baseline.json';
        $this->writer->write($baseline, $path);

        $content = file_get_contents($path);
        self::assertNotFalse($content);

        $data = json_decode($content, true);
        self::assertSame(0, $data['count']);
        self::assertEmpty($data['violations']);
    }

    private function recursiveDelete(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        $files = glob($dir . '/*');
        if ($files !== false) {
            foreach ($files as $file) {
                if (is_dir($file)) {
                    $this->recursiveDelete($file);
                } else {
                    unlink($file);
                }
            }
        }

        rmdir($dir);
    }
}
