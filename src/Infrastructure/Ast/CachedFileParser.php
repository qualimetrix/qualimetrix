<?php

declare(strict_types=1);

namespace Qualimetrix\Infrastructure\Ast;

use PhpParser\Node;
use Qualimetrix\Core\Ast\FileParserInterface;
use Qualimetrix\Infrastructure\Cache\CacheFactory;
use Qualimetrix\Infrastructure\Cache\CacheInterface;
use Qualimetrix\Infrastructure\Cache\CacheKeyGenerator;
use Qualimetrix\Infrastructure\Cache\CacheWriteException;
use SplFileInfo;

/**
 * Decorator that caches parsed AST to avoid re-parsing unchanged files.
 *
 * Accepts either a CacheInterface directly or a CacheFactory for lazy resolution.
 * Lazy resolution ensures the cache directory reflects runtime configuration
 * (e.g., --cache-dir CLI option) rather than container build-time defaults.
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
    ) {}

    /**
     * @return Node[]
     */
    public function parse(SplFileInfo $file): array
    {
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
