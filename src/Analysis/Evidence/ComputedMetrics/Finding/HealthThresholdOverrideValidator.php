<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Evidence\ComputedMetrics\Finding;

use Qualimetrix\Analysis\Evidence\ComputedMetrics\Contract\Finding\ComputedMetricChannelFamily;

use Qualimetrix\Analysis\Finding\Contract\Rule\Override\OverrideValidationFailure;
use Qualimetrix\Analysis\Finding\Contract\Rule\Override\OverrideValidatorInterface;
use Qualimetrix\Analysis\Finding\Contract\Rule\Override\ThresholdOverrideRequest;

/** One finite health boundary, independent of the configured definition's direction. */
final class HealthThresholdOverrideValidator implements OverrideValidatorInterface
{
    /** @return array<string, OverrideValidatorInterface> */
    public static function forBuiltinHealthProducers(): array
    {
        return array_fill_keys(ComputedMetricChannelFamily::HEALTH_PRODUCER_RULE_NAMES, new self());
    }

    public function validate(ThresholdOverrideRequest $request): ?OverrideValidationFailure
    {
        if ($request->warning === null || $request->error === null || ($request->warning <=> $request->error) !== 0) {
            return new OverrideValidationFailure(
                'health_single_boundary',
                'A health threshold override requires one boundary; use a number or equal warning and error values.',
            );
        }
        if ($request->warning < 0) {
            return new OverrideValidationFailure('negative_warning', 'A health threshold boundary must be non-negative.');
        }
        return null;
    }
}
