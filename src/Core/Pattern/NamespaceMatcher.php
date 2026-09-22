<?php

declare(strict_types=1);

namespace Qualimetrix\Core\Pattern;

use InvalidArgumentException;

/**
 * Matches namespaces against ordered separator-bound namespace patterns.
 */
final readonly class NamespaceMatcher
{
    /**
     * @param list<NamespacePattern> $patterns
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

    public function matches(string $namespace): ?PatternMatch
    {
        foreach ($this->patterns as $pattern) {
            if ($pattern->matches($namespace)) {
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
