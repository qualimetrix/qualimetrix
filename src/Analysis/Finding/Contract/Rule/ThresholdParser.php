<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Finding\Contract\Rule;

use LogicException;
use Qualimetrix\Analysis\Configuration\Contract\Document\ResolvedWriteHistoryInterface;

/** Reads and judges the two declared leaves after document shorthand expansion. */
final class ThresholdParser
{
    /** @return array{warning: int|float, error: int|float} */
    public static function parse(
        ResolvedRuleOptionValues $values,
        RuleOptionBand $band,
        int|float $defaultWarning,
        int|float $defaultError,
    ): array {
        $warning = $values->number($band->warning, $defaultWarning);
        $error = $values->number($band->error, $defaultError);
        $valid = match ($band->direction) {
            BandDirection::Rising => $warning <= $error,
            BandDirection::Falling => $warning >= $error,
        };
        if (!$valid) {
            $warningPath = $values->node($band->warning) === null ? null : [$band->warning];
            $errorPath = $values->node($band->error) === null ? null : [$band->error];
            $path = $warningPath ?? $errorPath
                ?? throw new LogicException('The owning threshold defaults must form a valid band.');
            throw new RuleOptionRefusal($path, \sprintf(
                'Warning threshold %s must be %s error threshold %s.',
                $warning,
                $band->direction === BandDirection::Rising ? 'less than or equal to' : 'greater than or equal to',
                $error,
            ), [
                'warning' => ['path' => $warningPath, 'value' => $warning],
                'error' => ['path' => $errorPath, 'value' => $error],
            ]);
        }
        return ['warning' => $warning, 'error' => $error];
    }

    public static function wasWritten(ResolvedRuleOptionValues $values, RuleOptionBand $band): bool
    {
        foreach ([$band->warning, $band->error] as $key) {
            $node = $values->node($key);
            if ($node === null) {
                continue;
            }
            if (!$node instanceof ResolvedWriteHistoryInterface) {
                throw new LogicException('A threshold leaf must expose its authored writes.');
            }
            foreach ($node->writes() as $write) {
                if ($write['value'] !== null) {
                    return true;
                }
            }
        }
        return false;
    }
}
