<?php

declare(strict_types=1);

namespace Qualimetrix\Infrastructure\Ast;

use PhpParser\Node;
use Qualimetrix\Core\Ast\FileParserInterface;
use Qualimetrix\Infrastructure\Cache\CacheInterface;
use Qualimetrix\Infrastructure\Cache\CacheKeyGenerator;
use Qualimetrix\Infrastructure\Cache\CacheWriteException;
use SplFileInfo;

/**
 * Decorator that caches parsed AST to avoid re-parsing unchanged files.
 *
 * @qmx-ignore-file code-smell.empty-catch Cache write failures are intentionally ignored (best-effort caching)
 */
final class CachedFileParser implements FileParserInterface
{
    public function __construct(
        private readonly FileParserInterface $inner,
        private readonly CacheInterface $cache,
        private readonly CacheKeyGenerator $keyGenerator,
    ) {}

    /**
     * @return Node[]
     */
    public function parse(SplFileInfo $file): array
    {
        $key = $this->keyGenerator->generate($file);

        // Empty key means file doesn't exist or can't be read
        if ($key === '') {
            return $this->inner->parse($file);
        }

        // Try cache first
        $cached = $this->cache->get($key);

        if ($cached !== null && \is_array($cached)) {
            return $cached;
        }

        // Parse and cache
        $ast = $this->inner->parse($file);

        // Cache failure should not break parsing - caching is best-effort
        try {
            $this->cache->set($key, $ast);
        } catch (CacheWriteException) {
            // Intentionally ignored: cache write failure is non-critical
        }

        return $ast;
    }
}
