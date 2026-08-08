<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Unit\Infrastructure\Cache;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Infrastructure\Cache\CacheKeyGenerator;
use SplFileInfo;

#[CoversClass(CacheKeyGenerator::class)]
final class CacheKeyGeneratorTest extends TestCase
{
    private CacheKeyGenerator $generator;
    private string $tempFile;

    protected function setUp(): void
    {
        $this->generator = new CacheKeyGenerator();
        $this->tempFile = sys_get_temp_dir() . '/qmx-cache-test-' . uniqid() . '.php';
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
    public function itGeneratesTheSameKeyForAlreadyReadContent(): void
    {
        $file = new SplFileInfo($this->tempFile);
        $content = file_get_contents($this->tempFile);
        self::assertNotFalse($content);

        self::assertSame(
            $this->generator->generate($file),
            $this->generator->generateForContent($content),
        );
    }

    #[Test]
    public function itGeneratesSameKeyWhenOnlyMtimeChanges(): void
    {
        $file = new SplFileInfo($this->tempFile);
        $key1 = $this->generator->generate($file);

        // A metadata-only timestamp change must not invalidate the AST cache.
        sleep(1);
        touch($this->tempFile);
        clearstatcache(true, $this->tempFile);

        $key2 = $this->generator->generate(new SplFileInfo($this->tempFile));

        self::assertSame($key1, $key2);
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
    public function itGeneratesDifferentKeyForSameSizeContentWithRestoredMtime(): void
    {
        $firstContent = '<?php class First {}';
        $secondContent = '<?php class Other {}';
        self::assertSame(\strlen($firstContent), \strlen($secondContent));

        file_put_contents($this->tempFile, $firstContent);
        $originalMtime = filemtime($this->tempFile);
        self::assertNotFalse($originalMtime);
        $key1 = $this->generator->generate(new SplFileInfo($this->tempFile));

        file_put_contents($this->tempFile, $secondContent);
        touch($this->tempFile, $originalMtime);
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
    public function itReturnsEmptyKeyForNonFileTarget(): void
    {
        $directory = new SplFileInfo(\dirname($this->tempFile));

        $key = $this->generator->generate($directory);

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
