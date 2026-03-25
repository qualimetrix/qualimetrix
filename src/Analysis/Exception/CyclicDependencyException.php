<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Exception;

use Exception;

/**
 * Thrown when a cyclic dependency is detected between collectors.
 */
final class CyclicDependencyException extends Exception
{
    /**
     * @param list<string> $cycle The collectors forming the cycle
     */
    public function __construct(
        public readonly array $cycle,
    ) {
        parent::__construct(
            \sprintf(
                'Cyclic dependency detected between collectors: %s',
                implode(' -> ', $cycle),
            ),
        );
    }
}
