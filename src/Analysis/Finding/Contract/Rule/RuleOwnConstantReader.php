<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Finding\Contract\Rule;

use LogicException;
use ReflectionClass;
use ReflectionClassConstant;

/**
 * Resolves a named constant a rule class must declare **on itself** —
 * shared by {@see RuleDocsPageReader} and {@see RuleRemediationMinutesReader},
 * which differ only in the constant's name and how its value is validated
 * once resolved (a non-empty string versus a positive int).
 *
 * "On itself" is the check both readers exist for: a code-smell or security
 * rule that declares nothing of its own would otherwise silently inherit
 * {@see \Qualimetrix\Analysis\Evidence\CodeSmell\AbstractCodeSmellRule}'s or
 * {@see \Qualimetrix\Analysis\Evidence\Security\AbstractSecurityPatternRule}'s
 * placeholder, so this rejects a constant whose declaring class differs from
 * the class asked about, regardless of the placeholder's own value.
 */
final class RuleOwnConstantReader
{
    /**
     * @throws LogicException when the class cannot be loaded, declares no
     *                        `$constantName` constant, or the constant it
     *                        exposes is only inherited rather than declared
     *                        on the class itself
     */
    public static function read(string $ruleClass, string $constantName, string $declarationHint): ReflectionClassConstant
    {
        if (!class_exists($ruleClass)) {
            throw new LogicException(\sprintf(
                'Rule class %s does not exist or cannot be autoloaded.',
                $ruleClass,
            ));
        }

        $reflection = new ReflectionClass($ruleClass);

        if (!$reflection->hasConstant($constantName)) {
            throw new LogicException(\sprintf(
                'Rule class %s must declare %s.',
                $ruleClass,
                $declarationHint,
            ));
        }

        $constant = $reflection->getReflectionConstant($constantName);

        if ($constant === false || $constant->getDeclaringClass()->getName() !== $ruleClass) {
            throw new LogicException(\sprintf(
                'Rule class %s must declare its own %s constant rather than inherit one from %s.',
                $ruleClass,
                $constantName,
                $constant !== false ? $constant->getDeclaringClass()->getName() : '(unknown)',
            ));
        }

        return $constant;
    }
}
