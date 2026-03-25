<?php

declare(strict_types=1);

namespace Qualimetrix\Core\Violation\Filter;

use Qualimetrix\Core\Util\PathMatcher;
use Qualimetrix\Core\Violation\Violation;

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
