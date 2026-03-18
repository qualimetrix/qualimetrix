<?php

declare(strict_types=1);

namespace AiMessDetector\Configuration;

/**
 * Stores per-rule namespace exclusions and provides prefix-based matching.
 *
 * Extracted from config during RuleOptionsFactory::create() and consumed
 * by RuleExecutor to filter violations at framework level.
 */
final class RuleNamespaceExclusionProvider
{
    /** @var array<string, list<string>> rule name => list of namespace prefixes */
    private array $exclusions = [];

    /**
     * @param list<string> $prefixes
     */
    public function setExclusions(string $ruleName, array $prefixes): void
    {
        if ($prefixes === []) {
            return;
        }

        $this->exclusions[$ruleName] = $prefixes;
    }

    /**
     * Returns the exclusion prefixes for a given rule.
     *
     * @return list<string>
     */
    public function getExclusions(string $ruleName): array
    {
        return $this->exclusions[$ruleName] ?? [];
    }

    public function isExcluded(string $ruleName, string $namespace): bool
    {
        if (!isset($this->exclusions[$ruleName])) {
            return false;
        }

        foreach ($this->exclusions[$ruleName] as $prefix) {
            $prefix = rtrim($prefix, '\\');

            if ($namespace === $prefix || str_starts_with($namespace, $prefix . '\\')) {
                return true;
            }
        }

        return false;
    }

    public function reset(): void
    {
        $this->exclusions = [];
    }
}
