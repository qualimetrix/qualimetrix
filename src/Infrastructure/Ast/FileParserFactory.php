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
 * Uses CacheFactory (not CacheInterface) to ensure cache directory
 * reflects runtime configuration (e.g., --cache-dir CLI option).
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
     * Create the appropriate file parser based on configuration.
     */
    public function create(): FileParserInterface
    {
        if ($this->configurationStore->current()->enabled) {
            return new CachedFileParser(
                $this->parser,
                $this->cacheFactory,
                $this->keyGenerator,
            );
        }

        return $this->parser;
    }
}
