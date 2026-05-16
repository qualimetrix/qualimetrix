<?php

declare(strict_types=1);

namespace Qualimetrix\Core\Rule;

use Qualimetrix\Core\Rule\Attribute\CliAlias;
use ReflectionClass;

/**
 * Reads CliAlias attributes from a rule class.
 *
 * Reflection-based — works on class-string without instantiation.
 */
final class CliAliasReader
{
    /**
     * @param class-string $ruleClass
     *
     * @return array<string, string> map of alias → option name
     */
    public static function read(string $ruleClass): array
    {
        $reflection = new ReflectionClass($ruleClass);
        $aliases = [];

        foreach ($reflection->getAttributes(CliAlias::class) as $attribute) {
            $instance = $attribute->newInstance();
            $aliases[$instance->alias] = $instance->optionName;
        }

        return $aliases;
    }
}
