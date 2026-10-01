<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Finding\Contract\Rule;

/** Reads the two declared leaves after document shorthand expansion. */
final class ThresholdParser
{
    /** @return array{warning: int|float, error: int|float} */
    public static function parse(
        ResolvedRuleOptionValues $values,
        RuleOptionBand $band,
        int|float $defaultWarning,
        int|float $defaultError,
    ): array {
        return [
            'warning' => $values->number($band->warning, $defaultWarning),
            'error' => $values->number($band->error, $defaultError),
        ];
    }
}
