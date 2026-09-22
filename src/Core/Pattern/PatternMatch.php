<?php

declare(strict_types=1);

namespace Qualimetrix\Core\Pattern;

/**
 * The authored selector definition that fired in an ordered matcher set.
 */
final readonly class PatternMatch
{
    public function __construct(
        public SelectorDefinition $definition,
    ) {}
}
