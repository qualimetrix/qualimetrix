<?php

declare(strict_types=1);

namespace AiMessDetector\Tests\Unit\Baseline;

use AiMessDetector\Baseline\BaselineLoader;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use RuntimeException;

#[CoversClass(BaselineLoader::class)]
final class BaselineLoaderTest extends TestCase
{
    private BaselineLoader $loader;
    private string $tempDir;

    protected function setUp(): void
    {
        $this->loader = new BaselineLoader();
        $this->tempDir = sys_get_temp_dir() . '/aimd_baseline_test_' . uniqid();
        mkdir($this->tempDir, 0755, true);
    }

    protected function tearDown(): void
    {
        if (is_dir($this->tempDir)) {
            $files = glob($this->tempDir . '/*');
            if ($files !== false) {
                foreach ($files as $file) {
                    unlink($file);
                }
            }
            rmdir($this->tempDir);
        }
    }

    public function testLoadsValidBaseline(): void
    {
        $json = <<<'JSON'
        {
            "version": 2,
            "generated": "2025-12-08T10:00:00+00:00",
            "count": 2,
            "violations": {
                "method:App\\Foo::bar": [
                    {
                        "rule": "complexity",
                        "hash": "a1b2c3d4"
                    }
                ],
                "class:App\\Foo": [
                    {
                        "rule": "size",
                        "hash": "e5f6g7h8"
                    }
                ]
            }
        }
        JSON;

        $path = $this->tempDir . '/baseline.json';
        file_put_contents($path, $json);

        $baseline = $this->loader->load($path);

        self::assertSame(2, $baseline->version);
        self::assertSame('2025-12-08T10:00:00+00:00', $baseline->generated->format('c'));
        self::assertCount(2, $baseline->entries);
        self::assertCount(1, $baseline->entries['method:App\Foo::bar']);
        self::assertCount(1, $baseline->entries['class:App\Foo']);
    }

    public function testThrowsWhenFileNotFound(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Baseline file not found');

        $this->loader->load($this->tempDir . '/nonexistent.json');
    }

    public function testThrowsOnInvalidJson(): void
    {
        $path = $this->tempDir . '/invalid.json';
        file_put_contents($path, '{invalid json}');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Invalid JSON in baseline file');

        $this->loader->load($path);
    }

    public function testThrowsWhenMissingVersionField(): void
    {
        $json = <<<'JSON'
        {
            "generated": "2025-12-08T10:00:00+00:00",
            "violations": {}
        }
        JSON;

        $path = $this->tempDir . '/baseline.json';
        file_put_contents($path, $json);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Baseline file must contain "version" field');

        $this->loader->load($path);
    }

    public function testThrowsOnUnsupportedVersion(): void
    {
        $json = <<<'JSON'
        {
            "version": 99,
            "generated": "2025-12-08T10:00:00+00:00",
            "violations": {}
        }
        JSON;

        $path = $this->tempDir . '/baseline.json';
        file_put_contents($path, $json);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Unsupported baseline version: 99');

        $this->loader->load($path);
    }

    public function testThrowsWhenMissingGeneratedField(): void
    {
        $json = <<<'JSON'
        {
            "version": 2,
            "violations": {}
        }
        JSON;

        $path = $this->tempDir . '/baseline.json';
        file_put_contents($path, $json);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Baseline file must contain "generated" field');

        $this->loader->load($path);
    }

    public function testThrowsWhenMissingViolationsField(): void
    {
        $json = <<<'JSON'
        {
            "version": 2,
            "generated": "2025-12-08T10:00:00+00:00"
        }
        JSON;

        $path = $this->tempDir . '/baseline.json';
        file_put_contents($path, $json);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Baseline file must contain "violations" field');

        $this->loader->load($path);
    }

    public function testLoadsEmptyBaseline(): void
    {
        $json = <<<'JSON'
        {
            "version": 2,
            "generated": "2025-12-08T10:00:00+00:00",
            "count": 0,
            "violations": {}
        }
        JSON;

        $path = $this->tempDir . '/baseline.json';
        file_put_contents($path, $json);

        $baseline = $this->loader->load($path);

        self::assertSame(0, $baseline->count());
        self::assertEmpty($baseline->entries);
    }
}
