<?php

declare(strict_types=1);

namespace Qualimetrix\Infrastructure\Ast;

use PhpParser\Node;
use Qualimetrix\Core\Ast\FileParserInterface;
use Qualimetrix\Infrastructure\Cache\CacheFactory;
use Qualimetrix\Infrastructure\Cache\CacheInterface;
use Qualimetrix\Infrastructure\Cache\CacheKeyGenerator;
use Qualimetrix\Infrastructure\Cache\CacheWriteException;
use Qualimetrix\Infrastructure\Cache\Contract\CacheConfigurationStoreInterface;
use SplFileInfo;

/**
 * Decorator that caches parsed AST to avoid re-parsing unchanged files.
 *
 * Accepts either a CacheInterface directly or a CacheFactory for lazy resolution.
 *
 * Both halves of the cache decision are taken at parse time, not at
 * construction time: the directory (through {@see CacheFactory}) and whether to
 * cache at all (through the configuration store). A run is configured after the
 * container has already built this service, so a decorator that answered
 * "caching enabled?" in its constructor answered it from the defaults and
 * `--no-cache` never reached the parse. Asking per file costs one array read.
 *
 * @qmx-ignore code-smell.empty-catch Cache write failures are intentionally ignored (best-effort caching)
 */
final class CachedFileParser implements FileParserInterface
{
    private ?CacheInterface $resolvedCache = null;

    public function __construct(
        private readonly FileParserInterface $inner,
        private readonly CacheFactory|CacheInterface $cache,
        private readonly CacheKeyGenerator $keyGenerator,
        private readonly CacheConfigurationStoreInterface $configurationStore,
    ) {}

    /**
     * @return Node[]
     */
    public function parse(SplFileInfo $file): array
    {
        // A non-regular entry (directory, FIFO, dangling symlink) must never
        // become a successfully parsed empty AST: `file_get_contents()` on a
        // directory returns an empty string rather than `false`, so reading
        // first and asking questions later reports a phantom analyzed file.
        // The inner parser owns the typed refusal for that case.
        if (!$this->cachingEnabled() || !$file->isFile() || !$file->isReadable()) {
            return $this->inner->parse($file);
        }

        $content = @file_get_contents($file->getPathname());

        if ($content === false) {
            return $this->inner->parse($file);
        }

        return $this->parseContent($file, $content);
    }

    /**
     * @return Node[]
     */
    public function parseContent(SplFileInfo $file, string $content): array
    {
        if (!$this->cachingEnabled()) {
            return $this->inner->parseContent($file, $content);
        }

        $key = $this->keyGenerator->generateForContent($content);

        if ($key === '') {
            return $this->inner->parseContent($file, $content);
        }

        // Try cache first
        $cache = $this->getCache();
        $cached = $cache->get($key);

        if ($cached !== null && \is_array($cached)) {
            return $cached;
        }

        // Parse and cache the same immutable bytes used for the key.
        $ast = $this->inner->parseContent($file, $content);

        // Cache failure should not break parsing - caching is best-effort
        try {
            $cache->set($key, $ast);
        } catch (CacheWriteException) {
            // Intentionally ignored: cache write failure is non-critical
        }

        return $ast;
    }

    private function cachingEnabled(): bool
    {
        return $this->configurationStore->current()->enabled;
    }

    private function getCache(): CacheInterface
    {
        if ($this->resolvedCache === null) {
            $this->resolvedCache = $this->cache instanceof CacheFactory
                ? $this->cache->create()
                : $this->cache;
        }

        return $this->resolvedCache;
    }
}
