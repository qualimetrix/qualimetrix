<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Finding\Contract\Filter;

use Qualimetrix\Analysis\Finding\Contract\Violation;
use Qualimetrix\Core\Util\PathMatcher;

/**
 * Suppresses violations whose file path matches configured exclusion patterns.
 *
 * Violations without a file (e.g., namespace-level or architectural project-wide
 * diagnostics) are never filtered.
 *
 * Violations on a channel its owner declared **project-scoped** are exempt for
 * the same reason as in {@see NamespaceExclusionFilter}: `exclude_paths` means
 * "I don't want metrics for this code", but a project-level finding such as an
 * architecture boundary violation is not a metric — silently dropping it would
 * let a noisy-metric exclusion double as an undocumented way to disable
 * layer-policy enforcement. Which channels those are is declared, not read off
 * the rule name's spelling; see {@see ChannelFileScope}. Users who need to
 * suppress a specific architecture finding still have `@qmx-ignore`, baseline,
 * or the architecture configuration's own `exclude:` block.
 */
final readonly class PathExclusionFilter implements ViolationFilterInterface
{
    public function __construct(
        private PathMatcher $pathMatcher,
        private ChannelFileScope $fileScope,
    ) {}

    public function shouldInclude(Violation $violation): bool
    {
        if (!$this->fileScope->isFileScoped($violation->channel())) {
            return true;
        }

        $file = $violation->location->file;

        if ($file === null) {
            return true;
        }

        return !$this->pathMatcher->matches($file);
    }
}
