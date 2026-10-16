<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Policy\Inline\Threshold;

use Qualimetrix\Analysis\Finding\Contract\Rule\Override\OverrideAxis;
use Qualimetrix\Analysis\Finding\Contract\Rule\Override\OverrideSyntax;
use Qualimetrix\Analysis\Finding\Contract\Rule\Override\ThresholdOverrideRequest;

/** A threshold value is one number or one or two distinct authored axis tokens. */
final class ThresholdOverrideValueParser
{
    public function parse(string $valueString): ?ThresholdOverrideRequest
    {
        $values = self::beforeReason($valueString);
        if ($values === null) {
            return null;
        }

        return self::shorthand($values) ?? self::explicitAxes($values);
    }

    private static function beforeReason(string $valueString): ?string
    {
        $valueString = trim($valueString);
        if ($valueString === '') {
            return null;
        }
        $parts = preg_split('/\s+(?:--|—)\s*/u', $valueString, 2);
        if ($parts === false) {
            return null;
        }
        $values = trim($parts[0]);
        if ($values === '' || (isset($parts[1]) && trim($parts[1]) === '')) {
            return null;
        }
        return $values;
    }

    private static function shorthand(string $values): ?ThresholdOverrideRequest
    {
        if (preg_match('/^(\d+(?:\.\d+)?)$/', $values, $match) !== 1) {
            return null;
        }
        $value = self::number($match[1]);
        return new ThresholdOverrideRequest($value, $value, OverrideSyntax::Shorthand, []);
    }

    private static function explicitAxes(string $values): ?ThresholdOverrideRequest
    {
        $tokens = preg_split('/\s+/', $values);
        if ($tokens === false || \count($tokens) < 1 || \count($tokens) > 2) {
            return null;
        }

        /** @var array<string, int|float> $thresholds */
        $thresholds = [];
        $axes = [];
        foreach ($tokens as $token) {
            if (preg_match('/^(warning|error)=(\d+(?:\.\d+)?)$/', $token, $match) !== 1 || isset($thresholds[$match[1]])) {
                return null;
            }
            $axis = OverrideAxis::from($match[1]);
            $axes[] = $axis;
            $thresholds[$axis->value] = self::number($match[2]);
        }
        return new ThresholdOverrideRequest(
            $thresholds['warning'] ?? null,
            $thresholds['error'] ?? null,
            OverrideSyntax::ExplicitAxes,
            $axes,
        );
    }

    private static function number(string $value): int|float
    {
        return str_contains($value, '.') ? (float) $value : (int) $value;
    }
}
