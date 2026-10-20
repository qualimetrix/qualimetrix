<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Configuration\Contract\Document\Schema;

/** A type a scalar leaf may be written as. */
enum ScalarForm: string
{
    case String = 'string';
    case Integer = 'integer';

    /** An integer or a float. */
    case Number = 'number';
    case Boolean = 'boolean';

    public function accepts(mixed $value): bool
    {
        return match ($this) {
            self::String => \is_string($value),
            self::Integer => \is_int($value),
            self::Number => \is_int($value) || \is_float($value),
            self::Boolean => \is_bool($value),
        };
    }
}
