<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Finding\Contract\Rule;

use LogicException;
use Qualimetrix\Analysis\Finding\Contract\ChannelShape;
use ReflectionClass;

/**
 * Reads a rule's declared {@see ChannelShape} (ADR 0031) — `$ruleClass::shape()`,
 * guarded against the one way that call can lie.
 *
 * {@see AbstractRule::shape()} answers every concrete rule's `shape()` through
 * one shared implementation reading `static::SHAPE`, so a rule states its
 * answer through a `SHAPE` constant alone, without repeating the same
 * three-line method on every class — the exact duplication
 * `duplication.code-duplication` measured before that method existed. That
 * convenience is also the one hole a required interface method would not
 * otherwise have: a rule that extends {@see AbstractRule} and never declares
 * its own `SHAPE` still compiles, and silently reports `AbstractRule::SHAPE`'s
 * own placeholder value instead of failing to build at all. This reader
 * closes it.
 *
 * Deliberately not {@see RuleOwnConstantReader}: that reader requires the
 * constant declared on `$ruleClass` itself, which is correct for `DOCS_PAGE`
 * and `REMEDIATION_MINUTES` (every rule states its own, even when it shares
 * a base with siblings) but wrong here — {@see \Qualimetrix\Analysis\Evidence\CodeSmell\AbstractCodeSmellRule},
 * {@see \Qualimetrix\Analysis\Evidence\Security\AbstractSecurityPatternRule}
 * and {@see \Qualimetrix\Analysis\Evidence\Design\TypeCoverage\AbstractTypeCoverageRule}
 * each declare `SHAPE` once for every leaf that shares it, and none of those
 * leaves redeclares it. What must be refused is a class whose `SHAPE`
 * resolves all the way back to `AbstractRule`'s own placeholder — nothing
 * shallower.
 *
 * A class that answers `shape()` through its own override instead of through
 * `AbstractRule` (every fixture implementing {@see \Qualimetrix\Analysis\Finding\Rule\RuleInterface}
 * directly, and any future rule that chooses to) has no `SHAPE` constant to
 * check at all, and none is required of it: the method it declares itself is
 * what {@see \Qualimetrix\Analysis\Finding\Rule\RuleInterface} actually
 * promises.
 */
final class RuleShapeReader
{
    /**
     * @throws LogicException when the class cannot be loaded, or its
     *                        `shape()` resolves through {@see AbstractRule}
     *                        without any subclass declaring its own `SHAPE`
     */
    public static function read(string $ruleClass): ChannelShape
    {
        if (!class_exists($ruleClass)) {
            throw new LogicException(\sprintf(
                'Rule class %s does not exist or cannot be autoloaded.',
                $ruleClass,
            ));
        }

        $reflection = new ReflectionClass($ruleClass);
        $method = $reflection->getMethod('shape');

        if ($method->getDeclaringClass()->getName() === AbstractRule::class) {
            $constant = $reflection->getReflectionConstant('SHAPE');

            if ($constant === false || $constant->getDeclaringClass()->getName() === AbstractRule::class) {
                throw new LogicException(\sprintf(
                    'Rule class %s answers shape() through AbstractRule\'s own placeholder SHAPE constant — it'
                    . ' never declared one of its own. Add "public const ChannelShape SHAPE = ChannelShape::..."'
                    . ' to the class (or to a shared abstract base that already does).',
                    $ruleClass,
                ));
            }
        }

        return $ruleClass::shape();
    }
}
