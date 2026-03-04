<?php

declare(strict_types=1);

namespace AiMessDetector\Configuration;

/**
 * Resolved paths for file discovery.
 */
final readonly class PathsConfiguration
{
    /**
     * @param list<string> $paths Paths to analyze
     * @param list<string> $excludes Excluded directories
     */
    public function __construct(
        public array $paths,
        public array $excludes = [],
    ) {}

    public static function defaults(): self
    {
        return new self(
            paths: ['.'],
            excludes: ['vendor', 'node_modules', '.git'],
        );
    }
}
