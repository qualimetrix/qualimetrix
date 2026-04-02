<?php

declare(strict_types=1);

namespace Qualimetrix\Configuration;

use Qualimetrix\Core\Util\PathMatcher;

/**
 * Stores per-rule path exclusions and provides glob-based matching.
 *
 * Extracted from config during RuleOptionsFactory::create() and consumed
 * by RuleExecutor to filter violations at framework level.
 */
final class RulePathExclusionProvider
{
    /** @var array<string, PathMatcher> */
    private array $matchers = [];

    /**
     * @param list<string> $patterns Glob patterns (fnmatch)
     */
    public function setExclusions(string $ruleName, array $patterns): void
    {
        if ($patterns === []) {
            return;
        }

        $this->matchers[$ruleName] = new PathMatcher($patterns);
    }

    public function isExcluded(string $ruleName, string $filePath): bool
    {
        if (!isset($this->matchers[$ruleName])) {
            return false;
        }

        return $this->matchers[$ruleName]->matches($filePath);
    }

    public function reset(): void
    {
        $this->matchers = [];
    }
}
