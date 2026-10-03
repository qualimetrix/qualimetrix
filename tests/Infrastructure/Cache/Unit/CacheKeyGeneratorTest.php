<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Infrastructure\Cache\Unit;

use Composer\InstalledVersions;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Infrastructure\Cache\CacheKeyGenerator;

#[CoversClass(CacheKeyGenerator::class)]
final class CacheKeyGeneratorTest extends TestCase
{
    private CacheKeyGenerator $generator;
    private string $tempFile;

    protected function setUp(): void
    {
        $this->generator = new CacheKeyGenerator();
        $this->tempFile = sys_get_temp_dir() . '/qmx-cache-test-' . bin2hex(random_bytes(6)) . '.php';
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

        $key1 = $this->key();
        $key2 = $this->key();

        self::assertSame($key1, $key2);
        self::assertNotEmpty($key1);
    }

    #[Test]
    public function itGeneratesTheSameKeyForAlreadyReadContent(): void
    {
        $content = file_get_contents($this->tempFile);
        self::assertNotFalse($content);

        self::assertSame(
            $this->key(),
            $this->generator->generateForContent($content),
        );
    }

    #[Test]
    public function itGeneratesSameKeyWhenOnlyMtimeChanges(): void
    {
        $key1 = $this->key();

        // A metadata-only timestamp change must not invalidate the AST cache.
        sleep(1);
        touch($this->tempFile);
        clearstatcache(true, $this->tempFile);

        $key2 = $this->key();

        self::assertSame($key1, $key2);
    }

    #[Test]
    public function itGeneratesDifferentKeyWhenContentChanges(): void
    {
        $key1 = $this->key();

        // Change file content (which changes size and mtime)
        file_put_contents($this->tempFile, '<?php class Test { public function foo() {} }');
        clearstatcache(true, $this->tempFile);

        $key2 = $this->key();

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
        $key1 = $this->key();

        file_put_contents($this->tempFile, $secondContent);
        touch($this->tempFile, $originalMtime);
        clearstatcache(true, $this->tempFile);

        $key2 = $this->key();

        self::assertNotSame($key1, $key2);
    }

    private function key(): string
    {
        $content = file_get_contents($this->tempFile);
        self::assertIsString($content);

        return $this->generator->generateForContent($content);
    }

    #[Test]
    public function itReturnsCacheVersion(): void
    {
        $version = $this->generator->getCacheVersion();

        self::assertStringContainsString('php', $version);
        self::assertStringContainsString('parser', $version);
    }

    /**
     * The exact installed version, not the major.
     *
     * The version used to be read from a `vendor/composer/installed.php` path
     * computed from `__DIR__`, which exists only while Qualimetrix is the root
     * package. Installed the documented way the file is absent and the
     * fallback answered `5.x` for every release in the major, so upgrading
     * php-parser left every key — and every warmed AST — in place.
     */
    #[Test]
    public function itKeysOnTheExactInstalledParserVersion(): void
    {
        $installed = InstalledVersions::getVersion('nikic/php-parser');

        self::assertNotNull($installed);
        self::assertSame(
            \sprintf('php%d.%d-parser%s', \PHP_MAJOR_VERSION, \PHP_MINOR_VERSION, $installed),
            $this->generator->getCacheVersion(),
        );
    }

    #[Test]
    public function itGeneratesKeyOfExpectedLength(): void
    {

        $key = $this->key();

        // xxh128 produces 32 hex characters
        self::assertSame(32, \strlen($key));
    }
}
