<?php

declare(strict_types=1);

namespace Qualimetrix\Configuration;

use Qualimetrix\Core\Util\NamespaceMatcher;

/**
 * Stores per-rule namespace exclusions and provides namespace matching.
 *
 * Extracted from config during RuleOptionsFactory::create() and consumed
 * by RuleExecutor to filter violations at framework level.
 */
final class RuleNamespaceExclusionProvider
{
    /** @var array<string, NamespaceMatcher> */
    private array $matchers = [];

    /** @var array<string, list<string>> raw patterns for getExclusions() */
    private array $exclusions = [];

    /**
     * @param list<string> $patterns Namespace patterns (prefixes or globs)
     */
    public function setExclusions(string $ruleName, array $patterns): void
    {
        if ($patterns === []) {
            return;
        }

        $this->exclusions[$ruleName] = $patterns;
        $this->matchers[$ruleName] = new NamespaceMatcher($patterns);
    }

    /**
     * Returns the exclusion patterns for a given rule.
     *
     * @return list<string>
     */
    public function getExclusions(string $ruleName): array
    {
        return $this->exclusions[$ruleName] ?? [];
    }

    public function isExcluded(string $ruleName, string $namespace): bool
    {
        if (!isset($this->matchers[$ruleName])) {
            return false;
        }

        return $this->matchers[$ruleName]->matches($namespace);
    }

    public function reset(): void
    {
        $this->matchers = [];
        $this->exclusions = [];
    }
}
