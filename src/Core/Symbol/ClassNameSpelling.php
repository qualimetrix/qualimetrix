<?php

declare(strict_types=1);

namespace Qualimetrix\Core\Symbol;

use InvalidArgumentException;

final class ClassNameSpelling
{
    private const string ASCII_UPPER = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ';
    private const string ASCII_LOWER = 'abcdefghijklmnopqrstuvwxyz';

    public static function fold(string $name): string
    {
        return strtr($name, self::ASCII_UPPER, self::ASCII_LOWER);
    }

    /**
     * @param list<string> $spellings
     */
    public static function canonical(array $spellings): string
    {
        if ($spellings === []) {
            throw new InvalidArgumentException('At least one class-name spelling is required');
        }

        sort($spellings, \SORT_STRING);

        return $spellings[0];
    }
}
