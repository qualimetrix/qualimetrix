<?php

declare(strict_types=1);

namespace Qualimetrix\Infrastructure\Ast;

use Qualimetrix\Core\Ast\FileParserInterface;
use Qualimetrix\Infrastructure\Cache\CacheFactory;
use Qualimetrix\Infrastructure\Cache\CacheKeyGenerator;
use Qualimetrix\Infrastructure\Cache\Contract\CacheConfigurationStoreInterface;

/**
 * Factory for creating file parsers based on runtime configuration.
 *
 * The factory hands over the store rather than reading it. Everything the
 * container builds is built before a run is configured, so a decision taken
 * here is a decision taken against the defaults: `--no-cache` and
 * `cache.enabled: false` arrive later and would never be seen. The parser
 * asks the same store the cache directory is read from, at the moment it
 * parses.
 */
final class FileParserFactory
{
    public function __construct(
        private readonly PhpFileParser $parser,
        private readonly CacheFactory $cacheFactory,
        private readonly CacheKeyGenerator $keyGenerator,
        private readonly CacheConfigurationStoreInterface $configurationStore,
    ) {}

    /**
     * Create the file parser. Whether it caches is decided per parse.
     */
    public function create(): FileParserInterface
    {
        return new CachedFileParser(
            $this->parser,
            $this->cacheFactory,
            $this->keyGenerator,
            $this->configurationStore,
        );
    }
}
