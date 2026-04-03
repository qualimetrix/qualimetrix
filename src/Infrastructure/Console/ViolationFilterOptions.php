<?php

declare(strict_types=1);

namespace Qualimetrix\Infrastructure\Console;

/**
 * Options for the violation filter pipeline.
 */
final readonly class ViolationFilterOptions
{
    /**
     * @param list<string> $excludePaths
     * @param list<string> $excludeNamespaces
     */
    public function __construct(
        public ?string $baselinePath,
        public bool $ignoreStaleBaseline,
        public bool $disableSuppression,
        public array $excludePaths,
        public array $excludeNamespaces,
        public ?GitScopeFilterConfig $gitScope,
    ) {}
}
