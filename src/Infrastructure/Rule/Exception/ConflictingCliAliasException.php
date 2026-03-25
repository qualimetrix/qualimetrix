<?php

declare(strict_types=1);

namespace Qualimetrix\Infrastructure\Rule\Exception;

use RuntimeException;

/**
 * Thrown when two rules define the same CLI alias.
 */
final class ConflictingCliAliasException extends RuntimeException
{
    public function __construct(
        public readonly string $alias,
        public readonly string $firstRule,
        public readonly string $secondRule,
    ) {
        parent::__construct(\sprintf(
            'CLI alias "%s" is defined by both "%s" and "%s" rules',
            $alias,
            $firstRule,
            $secondRule,
        ));
    }
}
