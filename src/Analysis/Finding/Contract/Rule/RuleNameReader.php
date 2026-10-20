<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Finding\Contract\Rule;

use LogicException;

use Qualimetrix\Analysis\Finding\Rule\AbstractRule;
use ReflectionClass;

/**
 * Reads the rule slug from a rule class.
 *
 * Reflection-based — works on a class-string without instantiation. Rules may
 * declare constructor dependencies beyond their Options object (see
 * {@see \Qualimetrix\Analysis\Policy\Architecture\LayerViolation\LayerViolationRule}), so instantiating a
 * rule outside the DI container is never safe; the `NAME` constant is the only
 * supported source of a rule's name.
 */
final class RuleNameReader
{
    /**
     * @throws LogicException when the class cannot be loaded or declares no
     *                        string `NAME` constant; the shared getter requires
     *                        an own public non-empty declaration
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
            if ($reflection->isSubclassOf(AbstractRule::class)
                && $reflection->getMethod('getName')->getDeclaringClass()->getName() === AbstractRule::class
            ) {
                return self::readOwnName($ruleClass);
            }

            $name = $reflection->getConstant('NAME');

            if (\is_string($name)) {
                return $name;
            }
        }

        throw new LogicException(\sprintf(
            'Rule class %s must declare a string NAME constant holding its rule slug (e.g. "complexity.ccn").',
            $ruleClass,
        ));
    }

    private static function readOwnName(string $ruleClass): string
    {
        $constant = RuleOwnConstantReader::read(
            $ruleClass,
            'NAME',
            'a public non-empty string NAME constant holding its rule slug',
        );
        $name = $constant->getValue();
        if (!$constant->isPublic() || !\is_string($name) || $name === '') {
            throw new LogicException(\sprintf(
                'Rule class %s must declare a public non-empty string NAME constant holding its rule slug.',
                $ruleClass,
            ));
        }

        return $name;
    }
}
