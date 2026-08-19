<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Finding\Contract\Rule;

use LogicException;
use ReflectionClass;

/**
 * Reads a rule class's **declared** answer to "can `@qmx-threshold` retune
 * me?", without instantiating it.
 *
 * The property used to be inferred: a rule supported overrides if its options
 * class implemented {@see ThresholdAwareOptionsInterface}, or — for a
 * hierarchical options class — if any of its per-level options did. Two things
 * were wrong with that. The inference cannot be queried in reverse, so a
 * directive naming something that is not a rule at all could not be answered
 * with the rules it should have named; and it derives a promise made to the
 * user from an implementation detail two types away, which can change without
 * anyone deciding that the promise changed.
 *
 * A rule now says so itself, with a `SUPPORTS_THRESHOLD_OVERRIDE` constant.
 * Reflection over a plain constant is the idiom {@see RuleNameReader} already
 * establishes for rule metadata that must be readable without building the
 * rule — a rule may take constructor dependencies beyond its Options object,
 * so only the DI container may instantiate one.
 *
 * A constant rather than a static method, and declared last in the class,
 * for one reason each. A method would add to the declaring class's weighted
 * method count and dilute its cohesion — a rule that answers one more question
 * about itself has not become less cohesive, and letting a metric say it has
 * would be teaching the metric to lie. Declared last because the ratchet keys
 * its entries by the byte offset of a declaration, so a member inserted
 * anywhere else rewrites the key of every declaration below it.
 *
 * The constant is optional and its absence means `false`: most rules have no
 * threshold to retune, and a mandatory constant would make them all say so.
 *
 * Declaring is not the same as being believed blindly. A rule that declares
 * `true` while nothing in its options can carry an override is a lie, and the
 * suite pins every declaration against the mechanism that would have to
 * honour it.
 */
final class ThresholdOverrideSupportReader
{
    private const string CONSTANT = 'SUPPORTS_THRESHOLD_OVERRIDE';

    /**
     * @param class-string $ruleClass
     *
     * @throws LogicException when the class cannot be loaded, or declares the
     *                        constant with a value that is not a bool
     */
    public static function read(string $ruleClass): bool
    {
        if (!class_exists($ruleClass)) {
            throw new LogicException(\sprintf(
                'Rule class %s does not exist or cannot be autoloaded.',
                $ruleClass,
            ));
        }

        $reflection = new ReflectionClass($ruleClass);

        if (!$reflection->hasConstant(self::CONSTANT)) {
            return false;
        }

        $declared = $reflection->getConstant(self::CONSTANT);

        if (!\is_bool($declared)) {
            throw new LogicException(\sprintf(
                'Rule class %s must declare %s as a bool — it states whether @qmx-threshold can retune the rule.',
                $ruleClass,
                self::CONSTANT,
            ));
        }

        return $declared;
    }
}
