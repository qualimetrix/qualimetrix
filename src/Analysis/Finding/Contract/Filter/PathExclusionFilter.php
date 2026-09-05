<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Finding\Contract\Filter;

use Qualimetrix\Analysis\Finding\Contract\Finding;
use Qualimetrix\Core\Util\PathMatcher;

/**
 * Suppresses findings whose file path matches configured exclusion patterns.
 *
 * Findings without a file (e.g., namespace-level or architectural project-wide
 * diagnostics) are never filtered.
 *
 * Findings on a channel its owner declared **project-scoped** are exempt for
 * the same reason as in {@see NamespaceExclusionFilter}: `suppress_paths` means
 * "I don't want metrics for this code", but a project-level finding such as an
 * architecture boundary violation is not a metric — silently dropping it would
 * let a noisy-metric exclusion double as an undocumented way to disable
 * layer-policy enforcement. Which channels those are is declared, not read off
 * the rule name's spelling; see {@see ChannelFileScope}. What remains available
 * for suppressing such a finding also matches {@see NamespaceExclusionFilter}:
 * `@qmx-ignore` and a baseline entry still apply to
 * `architecture.layer-violation`, but not to the layer-policy diagnostics
 * beside it, which are declared configuration errors and answer only to the
 * architecture configuration's `exclude:` block (and `coverage: ignore` for the
 * coverage diagnostic).
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
