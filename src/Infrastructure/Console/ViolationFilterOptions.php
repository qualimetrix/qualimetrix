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
     * @param list<string>|null $scopeFilePaths Relative file paths for analyze scope filtering (null = no scope filter)
     */
    public function __construct(
        public ?string $baselinePath,
        public bool $ignoreStaleBaseline,
        public bool $disableSuppression,
        public array $excludePaths,
        public ?GitScopeFilterConfig $gitScope,
        public ?array $scopeFilePaths = null,
    ) {}
}
