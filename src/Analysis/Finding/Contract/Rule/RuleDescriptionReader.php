<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Finding\Contract\Rule;

use LogicException;

use Qualimetrix\Analysis\Finding\Rule\AbstractRule;
use ReflectionClass;

/** Validates description declarations used by AbstractRule's shared method. */
final class RuleDescriptionReader
{
    /**
     * @param class-string $ruleClass
     */
    public static function assertDeclared(string $ruleClass): void
    {
        if (!class_exists($ruleClass)) {
            throw new LogicException(\sprintf(
                'Rule class %s does not exist or cannot be autoloaded.',
                $ruleClass,
            ));
        }

        $reflection = new ReflectionClass($ruleClass);
        if (!$reflection->isSubclassOf(AbstractRule::class)
            || $reflection->getMethod('getDescription')->getDeclaringClass()->getName() !== AbstractRule::class
        ) {
            return;
        }

        $constant = RuleOwnConstantReader::read(
            $ruleClass,
            'DESCRIPTION',
            'a public non-empty string DESCRIPTION constant',
        );
        if (!$constant->isPublic() || !\is_string($constant->getValue()) || $constant->getValue() === '') {
            throw new LogicException(\sprintf(
                'Rule class %s must declare a public non-empty string DESCRIPTION constant.',
                $ruleClass,
            ));
        }
    }
}
