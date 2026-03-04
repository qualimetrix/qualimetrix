<?php

declare(strict_types=1);

namespace AiMessDetector\Tests\Unit\Infrastructure\Cache;

use AiMessDetector\Infrastructure\Cache\CacheKeyGenerator;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use SplFileInfo;

#[CoversClass(CacheKeyGenerator::class)]
final class CacheKeyGeneratorTest extends TestCase
{
    private CacheKeyGenerator $generator;
    private string $tempFile;

    protected function setUp(): void
    {
        $this->generator = new CacheKeyGenerator();
        $this->tempFile = sys_get_temp_dir() . '/aimd-cache-test-' . uniqid() . '.php';
        file_put_contents($this->tempFile, '<?php class Test {}');
    }

    protected function tearDown(): void
    {
        if (file_exists($this->tempFile)) {
            unlink($this->tempFile);
        }
    }

    #[Test]
    public function itGeneratesConsistentKey(): void
    {
        $file = new SplFileInfo($this->tempFile);

        $key1 = $this->generator->generate($file);
        $key2 = $this->generator->generate($file);

        self::assertSame($key1, $key2);
        self::assertNotEmpty($key1);
    }

    #[Test]
    public function itGeneratesDifferentKeyWhenFileChanges(): void
    {
        $file = new SplFileInfo($this->tempFile);
        $key1 = $this->generator->generate($file);

        // Touch the file to change mtime
        sleep(1);
        touch($this->tempFile);
        clearstatcache(true, $this->tempFile);

        $key2 = $this->generator->generate(new SplFileInfo($this->tempFile));

        self::assertNotSame($key1, $key2);
    }

    #[Test]
    public function itGeneratesDifferentKeyWhenContentChanges(): void
    {
        $file = new SplFileInfo($this->tempFile);
        $key1 = $this->generator->generate($file);

        // Change file content (which changes size and mtime)
        file_put_contents($this->tempFile, '<?php class Test { public function foo() {} }');
        clearstatcache(true, $this->tempFile);

        $key2 = $this->generator->generate(new SplFileInfo($this->tempFile));

        self::assertNotSame($key1, $key2);
    }

    #[Test]
    public function itReturnsEmptyKeyForNonExistentFile(): void
    {
        $file = new SplFileInfo('/non/existent/file.php');

        $key = $this->generator->generate($file);

        self::assertSame('', $key);
    }

    #[Test]
    public function itReturnsCacheVersion(): void
    {
        $version = $this->generator->getCacheVersion();

        self::assertStringContainsString('php', $version);
        self::assertStringContainsString('parser', $version);
    }

    #[Test]
    public function itGeneratesKeyOfExpectedLength(): void
    {
        $file = new SplFileInfo($this->tempFile);

        $key = $this->generator->generate($file);

        // xxh128 produces 32 hex characters
        self::assertSame(32, \strlen($key));
    }
}
