<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Infrastructure\Ast\Unit;

use FilesystemIterator;
use PhpParser\Node\Stmt\Class_;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Core\Ast\FileParserInterface;
use Qualimetrix\Core\Path\AbsolutePath;
use Qualimetrix\Infrastructure\Ast\CachedFileParser;
use Qualimetrix\Infrastructure\Ast\FileParserFactory;
use Qualimetrix\Infrastructure\Ast\PhpFileParser;
use Qualimetrix\Infrastructure\Cache\CacheConfigurationStore;
use Qualimetrix\Infrastructure\Cache\CacheFactory;
use Qualimetrix\Infrastructure\Cache\CacheKeyGenerator;
use Qualimetrix\Infrastructure\Cache\Contract\CacheConfiguration;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * The parser is built once, when the container is built, and a run is
 * configured afterwards. These cases therefore always configure the store
 * **after** `create()` has already answered — the ordering under which
 * `--no-cache` used to be inert while `--cache-dir` worked.
 */
#[CoversClass(FileParserFactory::class)]
#[CoversClass(CachedFileParser::class)]
final class FileParserFactoryTest extends TestCase
{
    private string $tempFile;
    private string $cacheDir;

    protected function setUp(): void
    {
        $this->tempFile = sys_get_temp_dir() . '/qmx-factory-test-' . bin2hex(random_bytes(6)) . '.php';
        $this->cacheDir = sys_get_temp_dir() . '/qmx-factory-cache-' . bin2hex(random_bytes(6));
        file_put_contents($this->tempFile, '<?php class FactoryProbe {}');
    }

    protected function tearDown(): void
    {
        if (file_exists($this->tempFile)) {
            unlink($this->tempFile);
        }
        $this->removeDirectory($this->cacheDir);
    }

    #[Test]
    public function itCachesWhenTheRunEnablesCachingAfterTheParserWasBuilt(): void
    {
        $store = new CacheConfigurationStore();
        $parser = $this->createParser($store);

        $store->replace(new CacheConfiguration(AbsolutePath::fromString($this->cacheDir), true));
        $parser->parse(new SplFileInfo($this->tempFile));

        self::assertDirectoryExists($this->cacheDir);
        self::assertNotSame([], $this->cacheEntries());
    }

    #[Test]
    public function itWritesNothingWhenTheRunDisablesCachingAfterTheParserWasBuilt(): void
    {
        $store = new CacheConfigurationStore();
        $parser = $this->createParser($store);

        $store->replace(new CacheConfiguration(AbsolutePath::fromString($this->cacheDir), false));
        $parser->parse(new SplFileInfo($this->tempFile));

        self::assertDirectoryDoesNotExist($this->cacheDir);
    }

    #[Test]
    public function itStopsCachingTheMomentTheRunTurnsCachingOff(): void
    {
        $store = new CacheConfigurationStore();
        $parser = $this->createParser($store);

        $store->replace(new CacheConfiguration(AbsolutePath::fromString($this->cacheDir), true));
        $parser->parse(new SplFileInfo($this->tempFile));
        $warm = $this->cacheEntries();

        file_put_contents($this->tempFile, '<?php class FactoryProbeSecond {}');
        $store->replace(new CacheConfiguration(AbsolutePath::fromString($this->cacheDir), false));
        $parser->parse(new SplFileInfo($this->tempFile));

        self::assertSame($warm, $this->cacheEntries());
    }

    #[Test]
    public function itStillParsesWhenCachingIsOff(): void
    {
        $store = new CacheConfigurationStore();
        $parser = $this->createParser($store);

        $store->replace(new CacheConfiguration(AbsolutePath::fromString($this->cacheDir), false));
        $ast = $parser->parse(new SplFileInfo($this->tempFile));

        self::assertInstanceOf(Class_::class, $ast[0] ?? null);
    }

    private function createParser(CacheConfigurationStore $store): FileParserInterface
    {
        return (new FileParserFactory(
            new PhpFileParser(),
            new CacheFactory($store),
            new CacheKeyGenerator(),
            $store,
        ))->create();
    }

    /** @return list<string> */
    private function cacheEntries(): array
    {
        if (!is_dir($this->cacheDir)) {
            return [];
        }

        $entries = [];
        foreach ($this->directoryIterator($this->cacheDir) as $item) {
            if ($item->isFile()) {
                $entries[] = $item->getPathname();
            }
        }
        sort($entries);

        return $entries;
    }

    private function removeDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        foreach ($this->directoryIterator($dir) as $item) {
            if ($item->isDir()) {
                rmdir($item->getPathname());
            } else {
                unlink($item->getPathname());
            }
        }

        rmdir($dir);
    }

    /** @return RecursiveIteratorIterator<RecursiveDirectoryIterator> */
    private function directoryIterator(string $dir): RecursiveIteratorIterator
    {
        return new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        );
    }
}
