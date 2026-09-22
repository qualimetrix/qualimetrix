<?php

declare(strict_types=1);

namespace Qualimetrix\Core\Pattern;

use InvalidArgumentException;
use Qualimetrix\Core\Path\RelativePath;

/**
 * Matches paths against ordered separator-bound path patterns.
 */
final readonly class PathMatcher
{
    /**
     * @param list<PathPattern> $patterns
     *
     * @throws InvalidArgumentException when a selector list exceeds its fixed budget
     */
    public function __construct(private array $patterns)
    {
        if (\count($patterns) > SelectorDefinition::MAX_SELECTOR_COUNT) {
            throw new InvalidArgumentException(\sprintf(
                'Selector lists must not contain more than %d definitions',
                SelectorDefinition::MAX_SELECTOR_COUNT,
            ));
        }
    }

    public function matches(RelativePath $path): ?PatternMatch
    {
        foreach ($this->patterns as $pattern) {
            if ($pattern->matches($path)) {
                return new PatternMatch($pattern->definition);
            }
        }

        return null;
    }

    public function isEmpty(): bool
    {
        return $this->patterns === [];
    }
}
