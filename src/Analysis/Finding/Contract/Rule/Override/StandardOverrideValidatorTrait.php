<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Finding\Contract\Rule\Override;

use Qualimetrix\Analysis\Finding\Rule\Override\StandardOverrideValidator;

/**
 * Default `getOverrideValidator()` implementation for the majority of rules.
 *
 * Options classes whose `withOverride()` follows the standard "exceeding
 * the threshold is bad, so warning ≤ error" semantics opt into the
 * validator via `use StandardOverrideValidatorTrait;`. Rules with
 * inverted, multi-axis, or warning-only semantics return the appropriate
 * validator from a per-class implementation instead.
 */
trait StandardOverrideValidatorTrait
{
    public static function getOverrideValidator(): OverrideValidatorInterface
    {
        return StandardOverrideValidator::instance();
    }
}
