<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Finding\Exclusion;

use Qualimetrix\Core\Path\RelativePath;
use Qualimetrix\Core\Pattern\PathMatcher;
use Qualimetrix\Core\Pattern\PathPattern;

/**
 * Stores per-rule path suppressions as already-bound path selectors.
 *
 * @qmx-threshold design.data-class warning=24 error=10 -- This stateful provider deliberately exposes configuration, inspection, matching, and reset operations. Its two read operations are behavior over compiled matchers and authored selectors, not an anemic domain model. Current WOC 25% is accepted; the next drop is reported.
 */
final class RulePathExclusionProvider
{
    /** @var array<string, PathMatcher> */
    private array $matchers = [];

    /** @var array<string, list<PathPattern>> */
    private array $exclusions = [];

    /** @param list<PathPattern> $patterns */
    public function setExclusions(string $ruleName, array $patterns): void
    {
        if ($patterns === []) {
            return;
        }

        $this->exclusions[$ruleName] = $patterns;
        $this->matchers[$ruleName] = new PathMatcher($patterns);
    }

    /** @return list<PathPattern> */
    public function getExclusions(string $ruleName): array
    {
        return $this->exclusions[$ruleName] ?? [];
    }

    public function isExcluded(string $ruleName, RelativePath $filePath): bool
    {
        return isset($this->matchers[$ruleName]) && $this->matchers[$ruleName]->matches($filePath) !== null;
    }

    public function reset(): void
    {
        $this->matchers = [];
        $this->exclusions = [];
    }

}
