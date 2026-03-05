<?php

declare(strict_types=1);

namespace AiMessDetector\Core\Rule;

/**
 * Matches rule names and violation codes using prefix matching.
 *
 * Pattern matching rules:
 * - Exact match: 'complexity.cyclomatic' matches 'complexity.cyclomatic'
 * - Prefix match: 'complexity' matches 'complexity.cyclomatic' (pattern + '.' is prefix of subject)
 * - No reverse: 'complexity.cyclomatic' does NOT match 'complexity'
 */
final class RuleMatcher
{
    /**
     * Checks if a pattern matches a subject.
     *
     * Returns true if:
     * - pattern === subject (exact match)
     * - subject starts with pattern + '.' (prefix match)
     */
    public static function matches(string $pattern, string $subject): bool
    {
        if ($pattern === $subject) {
            return true;
        }

        return str_starts_with($subject, $pattern . '.');
    }

    /**
     * Checks if any of the patterns matches the subject.
     *
     * @param list<string> $patterns
     */
    public static function anyMatches(array $patterns, string $subject): bool
    {
        foreach ($patterns as $pattern) {
            if (self::matches($pattern, $subject)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Checks if subject is a prefix of any pattern (reverse prefix match).
     *
     * Used by isRuleEnabled: if onlyRules=['complexity.method'],
     * the rule 'complexity' should still run so its violations can be filtered.
     *
     * @param list<string> $patterns
     */
    public static function anyReverseMatches(array $patterns, string $subject): bool
    {
        foreach ($patterns as $pattern) {
            if (self::matches($subject, $pattern)) {
                return true;
            }
        }

        return false;
    }
}
