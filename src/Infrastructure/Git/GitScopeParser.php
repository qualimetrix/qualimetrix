<?php

declare(strict_types=1);

namespace AiMessDetector\Infrastructure\Git;

/**
 * Parses git scope strings.
 *
 * Accepts scope strings in format: git:<ref>
 * Examples:
 * - git:staged
 * - git:HEAD
 * - git:main
 * - git:main..HEAD
 * - git:main...HEAD
 */
final class GitScopeParser
{
    private const PATTERN = '/^git:(.+)$/';

    /**
     * Parses a scope string into GitScope.
     *
     * Returns null if the scope is not a git scope.
     */
    public function parse(string $scope): ?GitScope
    {
        if (!preg_match(self::PATTERN, $scope, $matches)) {
            return null;
        }

        $ref = $matches[1];

        return new GitScope($ref);
    }

    /**
     * Returns true if the scope string is a valid git scope.
     */
    public function isValid(string $scope): bool
    {
        return preg_match(self::PATTERN, $scope) === 1;
    }
}
