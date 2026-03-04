<?php

declare(strict_types=1);

namespace AiMessDetector\Infrastructure\Git;

/**
 * Represents a git scope reference.
 *
 * Examples:
 * - staged
 * - HEAD
 * - main
 * - main..HEAD
 * - main...HEAD
 * - HEAD~3
 */
final readonly class GitScope
{
    public function __construct(
        public string $ref,
    ) {}
}
