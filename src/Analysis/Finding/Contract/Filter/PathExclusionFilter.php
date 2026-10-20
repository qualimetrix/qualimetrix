<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Finding\Contract\Filter;

use Qualimetrix\Analysis\Finding\Contract\Finding;
use Qualimetrix\Core\Pattern\PathMatcher;

/**
 * Suppresses findings whose file path matches configured exclusion patterns.
 *
 * Findings without a file (e.g., namespace-level or architectural project-wide
 * diagnostics) are never filtered.
 *
 * Project-scoped channels are exempt through {@see ChannelFileScope}.
 * A cycle or declaration diagnostic concerns the project, even when it has
 * an example location. A layer violation instead belongs to its source
 * declaration and follows that source's path and namespace exclusions.
 */
final readonly class PathExclusionFilter implements FindingFilterInterface
{
    public function __construct(
        private PathMatcher $pathMatcher,
        private ChannelFileScope $fileScope,
    ) {}

    public function shouldInclude(Finding $finding): bool
    {
        if (!$this->fileScope->isFileScoped($finding->channel())) {
            return true;
        }

        $file = $finding->location->file;

        if ($file === null) {
            return true;
        }

        return $this->pathMatcher->matches($file) === null;
    }
}
