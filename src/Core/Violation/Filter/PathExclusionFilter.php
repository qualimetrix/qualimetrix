<?php

declare(strict_types=1);

namespace AiMessDetector\Core\Violation\Filter;

use AiMessDetector\Core\Util\PathMatcher;
use AiMessDetector\Core\Violation\Violation;

/**
 * Suppresses violations whose file path matches configured exclusion patterns.
 *
 * Violations without a file (e.g., namespace-level or architectural) are never filtered.
 */
final readonly class PathExclusionFilter implements ViolationFilterInterface
{
    public function __construct(
        private PathMatcher $pathMatcher,
    ) {}

    public function shouldInclude(Violation $violation): bool
    {
        if ($violation->location->file === '') {
            return true;
        }

        return !$this->pathMatcher->matches($violation->location->file);
    }
}
