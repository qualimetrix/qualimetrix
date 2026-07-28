<?php

declare(strict_types=1);

namespace Qualimetrix\Core\Violation\Filter;

use Qualimetrix\Core\Rule\RuleCategory;
use Qualimetrix\Core\Util\PathMatcher;
use Qualimetrix\Core\Violation\Violation;

/**
 * Suppresses violations whose file path matches configured exclusion patterns.
 *
 * Violations without a file (e.g., namespace-level or architectural project-wide
 * diagnostics) are never filtered.
 *
 * `architecture.*` rule violations are exempt for the same reason as in
 * {@see NamespaceExclusionFilter}: `exclude_paths` means "I don't want metrics for
 * this code", but an architecture boundary violation is not a metric — silently
 * dropping it would let a noisy-metric exclusion double as an undocumented way to
 * disable layer-policy enforcement. Users who need to suppress a specific
 * architecture finding still have `@qmx-ignore`, baseline, or the architecture
 * configuration's own `exclude:` block.
 */
final readonly class PathExclusionFilter implements ViolationFilterInterface
{
    public function __construct(
        private PathMatcher $pathMatcher,
    ) {}

    public function shouldInclude(Violation $violation): bool
    {
        if (RuleCategory::Architecture->matches($violation->ruleName)) {
            return true;
        }

        $file = $violation->location->file;

        if ($file === null) {
            return true;
        }

        return !$this->pathMatcher->matches($file);
    }
}
