<?php

declare(strict_types=1);

namespace Qualimetrix\Core\Util;

/**
 * The pattern that matched, returned by {@see PathMatcher::matches()} and
 * {@see NamespaceMatcher::matches()} alongside the yes/no answer so a caller
 * never has to re-scan the pattern list to learn what fired.
 */
final readonly class PatternMatch
{
    public function __construct(
        public string $pattern,
    ) {}
}
