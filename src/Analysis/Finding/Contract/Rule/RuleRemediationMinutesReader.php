<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Finding\Contract\Rule;

use LogicException;

/**
 * Reads the estimated remediation time (in minutes) a rule class declares
 * for itself.
 *
 * Reflection-based, on a class-string without instantiation — the same idiom
 * {@see RuleNameReader} and {@see RuleDocsPageReader} already establish, and
 * for the same reason: rules may take constructor dependencies beyond their
 * Options object, so instantiating one outside the DI container is never
 * safe. Ownership validation (declared on the class itself, not inherited)
 * is {@see RuleOwnConstantReader}'s job, shared with {@see RuleDocsPageReader}.
 *
 * The value used to live in a private table on
 * {@see \Qualimetrix\Analysis\Evidence\Prioritization\Debt\RemediationTimeRegistry},
 * keyed by rule name — a copy every rule-adding commit had to remember to
 * touch in a different capability, and one that drifted (it carried
 * `code-smell.god-class` and `code-smell.data-class` after both rules moved
 * to `design.*`). The estimate is calibration a rule already owns alongside
 * its default thresholds; the registry now only reads it, scaled by
 * overshoot.
 */
final class RuleRemediationMinutesReader
{
    private const string CONSTANT = 'REMEDIATION_MINUTES';

    /**
     * @throws LogicException when the class cannot be loaded, declares no
     *                        int `REMEDIATION_MINUTES` constant, the constant it
     *                        exposes is only inherited (from
     *                        {@see \Qualimetrix\Analysis\Evidence\CodeSmell\AbstractCodeSmellRule}
     *                        or {@see \Qualimetrix\Analysis\Evidence\Security\AbstractSecurityPatternRule})
     *                        rather than declared on the class itself, or the
     *                        declared value is not a positive number of minutes
     */
    public static function read(string $ruleClass): int
    {
        $constant = RuleOwnConstantReader::read(
            $ruleClass,
            self::CONSTANT,
            \sprintf(
                'an int %s constant naming its estimated remediation time in minutes (e.g. 30)',
                self::CONSTANT,
            ),
        );

        $value = $constant->getValue();

        if (!\is_int($value) || $value <= 0) {
            throw new LogicException(\sprintf(
                'Rule class %s must declare a positive int %s constant.',
                $ruleClass,
                self::CONSTANT,
            ));
        }

        return $value;
    }
}
