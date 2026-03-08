<?php

declare(strict_types=1);

namespace AiMessDetector\Tests\Unit\Infrastructure\Ast;

use AiMessDetector\Core\Ast\FileParserInterface;
use AiMessDetector\Infrastructure\Ast\CachedFileParser;
use AiMessDetector\Infrastructure\Cache\CacheInterface;
use AiMessDetector\Infrastructure\Cache\CacheKeyGenerator;
use AiMessDetector\Infrastructure\Cache\FileCache;
use FilesystemIterator;
use PhpParser\Node\Stmt\Class_;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

#[CoversClass(CachedFileParser::class)]
final class CachedFileParserTest extends TestCase
{
    private string $tempFile;
    private string $cacheDir;

    protected function setUp(): void
    {
        $this->tempFile = sys_get_temp_dir() . '/aimd-parser-test-' . uniqid() . '.php';
        $this->cacheDir = sys_get_temp_dir() . '/aimd-cache-test-' . uniqid();
        file_put_contents($this->tempFile, '<?php class Test {}');
    }

    protected function tearDown(): void
    {
        if (file_exists($this->tempFile)) {
            unlink($this->tempFile);
        }
        $this->removeDirectory($this->cacheDir);
    }

    #[Test]
    public function itReturnsCachedAstOnHit(): void
    {
        $file = new SplFileInfo($this->tempFile);
        $cachedAst = [new Class_('CachedTest')];
        $keyGenerator = new CacheKeyGenerator();
        $key = $keyGenerator->generate($file);

        $inner = $this->createMock(FileParserInterface::class);
        $inner->expects(self::never())->method('parse');

        $cache = $this->createMock(CacheInterface::class);
        $cache->method('get')->with($key)->willReturn($cachedAst);

        $parser = new CachedFileParser($inner, $cache, $keyGenerator);

        $result = $parser->parse($file);

        self::assertSame($cachedAst, $result);
    }

    #[Test]
    public function itParsesAndCachesOnMiss(): void
    {
        $file = new SplFileInfo($this->tempFile);
        $freshAst = [new Class_('FreshTest')];
        $keyGenerator = new CacheKeyGenerator();
        $key = $keyGenerator->generate($file);

        $inner = $this->createMock(FileParserInterface::class);
        $inner->expects(self::once())->method('parse')->willReturn($freshAst);

        $cache = $this->createMock(CacheInterface::class);
        $cache->method('get')->with($key)->willReturn(null);
        $cache->expects(self::once())->method('set')->with($key, $freshAst);

        $parser = new CachedFileParser($inner, $cache, $keyGenerator);

        $result = $parser->parse($file);

        self::assertSame($freshAst, $result);
    }

    #[Test]
    public function itDelegatesForNonExistentFileWithCacheMiss(): void
    {
        // Non-existent file now gets a valid cache key (with 'unresolved:' prefix internally)
        // but cache returns null, so it falls through to inner parser
        $file = new SplFileInfo('/non/existent/file.php');
        $ast = [new Class_('Test')];
        $keyGenerator = new CacheKeyGenerator();
        $key = $keyGenerator->generate($file);

        $inner = $this->createMock(FileParserInterface::class);
        $inner->expects(self::once())->method('parse')->willReturn($ast);

        $cache = $this->createMock(CacheInterface::class);
        $cache->expects(self::once())->method('get')->with($key)->willReturn(null);
        $cache->expects(self::once())->method('set')->with($key, $ast);

        $parser = new CachedFileParser($inner, $cache, $keyGenerator);

        $result = $parser->parse($file);

        self::assertSame($ast, $result);
    }

    #[Test]
    public function itSkipsInvalidCachedValue(): void
    {
        $file = new SplFileInfo($this->tempFile);
        $freshAst = [new Class_('FreshTest')];
        $keyGenerator = new CacheKeyGenerator();
        $key = $keyGenerator->generate($file);

        $inner = $this->createMock(FileParserInterface::class);
        $inner->expects(self::once())->method('parse')->willReturn($freshAst);

        $cache = $this->createMock(CacheInterface::class);
        $cache->method('get')->with($key)->willReturn('not an array');
        $cache->expects(self::once())->method('set');

        $parser = new CachedFileParser($inner, $cache, $keyGenerator);

        $result = $parser->parse($file);

        self::assertSame($freshAst, $result);
    }

    #[Test]
    public function itWorksWithRealCache(): void
    {
        $file = new SplFileInfo($this->tempFile);
        $freshAst = [new Class_('RealTest')];
        $keyGenerator = new CacheKeyGenerator();
        $cache = new FileCache($this->cacheDir);

        $inner = $this->createMock(FileParserInterface::class);
        // First call: parse and cache
        $inner->expects(self::once())->method('parse')->willReturn($freshAst);

        $parser = new CachedFileParser($inner, $cache, $keyGenerator);

        // First parse - should call inner
        $result1 = $parser->parse($file);
        self::assertCount(1, $result1);

        // Second parse - should use cache
        $result2 = $parser->parse($file);
        self::assertCount(1, $result2);
    }

    private function removeDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        $items = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($items as $item) {
            if ($item->isDir()) {
                rmdir($item->getPathname());
            } else {
                unlink($item->getPathname());
            }
        }

        rmdir($dir);
    }
}
