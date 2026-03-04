<?php

declare(strict_types=1);

namespace AiMessDetector\Infrastructure\Ast;

use AiMessDetector\Configuration\ConfigurationProviderInterface;
use AiMessDetector\Core\Ast\FileParserInterface;
use AiMessDetector\Infrastructure\Cache\CacheInterface;
use AiMessDetector\Infrastructure\Cache\CacheKeyGenerator;

/**
 * Factory for creating file parsers based on runtime configuration.
 */
final class FileParserFactory
{
    public function __construct(
        private readonly PhpFileParser $parser,
        private readonly CacheInterface $cache,
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
                $this->cache,
                $this->keyGenerator,
            );
        }

        return $this->parser;
    }
}
