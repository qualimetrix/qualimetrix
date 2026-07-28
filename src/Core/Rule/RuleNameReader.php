<?php

declare(strict_types=1);

namespace Qualimetrix\Core\Rule;

use LogicException;
use ReflectionClass;

/**
 * Reads the rule slug from a rule class.
 *
 * Reflection-based — works on a class-string without instantiation. Rules may
 * declare constructor dependencies beyond their Options object (see
 * {@see \Qualimetrix\Architecture\Rules\LayerViolationRule}), so instantiating a
 * rule outside the DI container is never safe; the `NAME` constant is the only
 * supported source of a rule's name.
 */
final class RuleNameReader
{
    /**
     * @param class-string<RuleInterface> $ruleClass
     *
     * @throws LogicException when the class cannot be loaded or declares no
     *                        string `NAME` constant
     */
    public static function read(string $ruleClass): string
    {
        if (!class_exists($ruleClass)) {
            throw new LogicException(\sprintf(
                'Rule class %s does not exist or cannot be autoloaded.',
                $ruleClass,
            ));
        }

        $reflection = new ReflectionClass($ruleClass);

        if ($reflection->hasConstant('NAME')) {
            $name = $reflection->getConstant('NAME');

            if (\is_string($name)) {
                return $name;
            }
        }

        throw new LogicException(\sprintf(
            'Rule class %s must declare a string NAME constant holding its rule slug (e.g. "complexity.cyclomatic").',
            $ruleClass,
        ));
    }
}
