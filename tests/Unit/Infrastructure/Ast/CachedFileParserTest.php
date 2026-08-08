<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Unit\Infrastructure\Ast;

use FilesystemIterator;
use PhpParser\Node\Stmt\Class_;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Core\Ast\FileParserInterface;
use Qualimetrix\Core\Exception\ParseException;
use Qualimetrix\Core\Path\AbsolutePath;
use Qualimetrix\Infrastructure\Ast\CachedFileParser;
use Qualimetrix\Infrastructure\Ast\PhpFileParser;
use Qualimetrix\Infrastructure\Cache\CacheInterface;
use Qualimetrix\Infrastructure\Cache\CacheKeyGenerator;
use Qualimetrix\Infrastructure\Cache\FileCache;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use SplFileInfo;

#[CoversClass(CachedFileParser::class)]
final class CachedFileParserTest extends TestCase
{
    private string $tempFile;
    private string $cacheDir;

    protected function setUp(): void
    {
        $this->tempFile = sys_get_temp_dir() . '/qmx-parser-test-' . uniqid() . '.php';
        $this->cacheDir = sys_get_temp_dir() . '/qmx-cache-test-' . uniqid();
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

        $cache = self::createStub(CacheInterface::class);
        $cache->method('get')->willReturn($cachedAst);

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
        $inner->expects(self::once())->method('parseContent')->willReturn($freshAst);

        $cache = $this->createMock(CacheInterface::class);
        $cache->method('get')->willReturn(null);
        $cache->expects(self::once())->method('set')->with($key, $freshAst);

        $parser = new CachedFileParser($inner, $cache, $keyGenerator);

        $result = $parser->parse($file);

        self::assertSame($freshAst, $result);
    }

    #[Test]
    public function itDelegatesForNonExistentFileWithoutUsingCache(): void
    {
        // A missing file has no content hash, so CachedFileParser bypasses cache.
        $file = new SplFileInfo('/non/existent/file.php');
        $ast = [new Class_('Test')];
        $keyGenerator = new CacheKeyGenerator();

        $inner = $this->createMock(FileParserInterface::class);
        $inner->expects(self::once())->method('parse')->willReturn($ast);

        $cache = $this->createMock(CacheInterface::class);
        $cache->expects(self::never())->method('get');
        $cache->expects(self::never())->method('set');

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
        $inner->expects(self::once())->method('parseContent')->willReturn($freshAst);

        $cache = $this->createMock(CacheInterface::class);
        $cache->method('get')->willReturn('not an array');
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
        $cache = new FileCache(AbsolutePath::fromString($this->cacheDir));

        $inner = $this->createMock(FileParserInterface::class);
        // First call: parse and cache
        $inner->expects(self::once())->method('parseContent')->willReturn($freshAst);

        $parser = new CachedFileParser($inner, $cache, $keyGenerator);

        // First parse - should call inner
        $result1 = $parser->parse($file);
        self::assertCount(1, $result1);

        // Second parse - should use cache
        $result2 = $parser->parse($file);
        self::assertCount(1, $result2);
    }

    #[Test]
    public function itCachesAstFromTheSameSnapshotUsedForKeyGeneration(): void
    {
        $sourceA = '<?php class SnapshotA {}';
        $sourceB = '<?php class SnapshotB {}';
        file_put_contents($this->tempFile, $sourceA);

        $inner = new class ($this->tempFile, $sourceA, $sourceB) implements FileParserInterface {
            public int $calls = 0;

            public function __construct(
                private readonly string $sourcePath,
                private readonly string $sourceA,
                private readonly string $sourceB,
            ) {}

            public function parse(SplFileInfo $file): array
            {
                $content = file_get_contents($file->getPathname());
                if ($content === false) {
                    throw new RuntimeException('Unable to read test fixture');
                }

                return $this->parseContent($file, $content);
            }

            public function parseContent(SplFileInfo $file, string $content): array
            {
                ++$this->calls;
                file_put_contents($this->sourcePath, $this->sourceB);

                return [new Class_($content === $this->sourceA ? 'SnapshotA' : 'SnapshotB')];
            }
        };

        $parser = new CachedFileParser(
            $inner,
            new FileCache(AbsolutePath::fromString($this->cacheDir)),
            new CacheKeyGenerator(),
        );

        $first = $parser->parse(new SplFileInfo($this->tempFile));
        file_put_contents($this->tempFile, $sourceA);
        $second = $parser->parse(new SplFileInfo($this->tempFile));

        self::assertInstanceOf(Class_::class, $first[0] ?? null);
        self::assertInstanceOf(Class_::class, $second[0] ?? null);
        self::assertSame('SnapshotA', $first[0]->name?->toString());
        self::assertSame('SnapshotA', $second[0]->name?->toString());
        self::assertSame(1, $inner->calls);
    }

    #[Test]
    public function itPreservesOriginalPathForCachedSyntaxErrors(): void
    {
        file_put_contents($this->tempFile, '<?php function broken( {');
        $file = new SplFileInfo($this->tempFile);
        $directParser = new PhpFileParser();
        $cachedParser = new CachedFileParser(
            new PhpFileParser(),
            new FileCache(AbsolutePath::fromString($this->cacheDir)),
            new CacheKeyGenerator(),
        );

        try {
            $directParser->parse($file);
            self::fail('Expected direct parser to throw ParseException');
        } catch (ParseException $directError) {
            // Captured below for comparison with the cached path.
        }

        try {
            $cachedParser->parse($file);
            self::fail('Expected cached parser to throw ParseException');
        } catch (ParseException $cachedError) {
            // Captured below for assertions.
        }

        self::assertSame($directError->filePath->value(), $cachedError->filePath->value());
        self::assertStringContainsString($directError->filePath->value(), $cachedError->getMessage());
        self::assertStringNotContainsString('qmx-ast-', $cachedError->getMessage());
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
