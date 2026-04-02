<?php

declare(strict_types=1);

namespace Qualimetrix\Infrastructure\Ast;

use Qualimetrix\Configuration\ConfigurationProviderInterface;
use Qualimetrix\Core\Ast\FileParserInterface;
use Qualimetrix\Infrastructure\Cache\CacheFactory;
use Qualimetrix\Infrastructure\Cache\CacheKeyGenerator;

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
        private readonly ConfigurationProviderInterface $configurationProvider,
    ) {}

    /**
     * Create the appropriate file parser based on configuration.
     */
    public function create(): FileParserInterface
    {
        $config = $this->configurationProvider->getConfiguration();

        if ($config->cacheEnabled) {
            return new CachedFileParser(
                $this->parser,
                $this->cacheFactory,
                $this->keyGenerator,
            );
        }

        return $this->parser;
    }
}
