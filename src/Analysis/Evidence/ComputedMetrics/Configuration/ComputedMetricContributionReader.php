<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Evidence\ComputedMetrics\Configuration;

use Qualimetrix\Analysis\Configuration\ConfigSchema;
use Qualimetrix\Analysis\Configuration\Contract\ConfigurationDocument;
use Qualimetrix\Analysis\Configuration\Contract\Refusal\ConfigurationRefusal;
use Qualimetrix\Analysis\Configuration\Contract\Refusal\RefusedPosition;

final class ComputedMetricContributionReader
{
    /**
     * The last `computed_metrics` map any source wrote.
     *
     * @throws ConfigurationRefusal
     *
     * @return array<string, mixed>
     */
    public function computedMetrics(ConfigurationDocument $document): array
    {
        $computedMetrics = [];
        foreach ($document->contributions(ConfigSchema::COMPUTED_METRICS) as $contribution) {
            if (!\is_array($contribution) || ($contribution !== [] && array_is_list($contribution))) {
                throw ConfigurationRefusal::atResolvedKey(
                    RefusedPosition::open(['computed_metrics'], 'computed_metrics'),
                    ComputedMetricShapeRefusalWording::computedMetricsSectionNotAMap(),
                );
            }

            $computedMetrics = $contribution;
        }

        return $computedMetrics;
    }

    /**
     * Every `exclude_health` dimension any source wrote, first spelling kept.
     *
     * @throws ConfigurationRefusal
     *
     * @return list<string>
     */
    public function excludedHealthDimensions(ConfigurationDocument $document): array
    {
        $excludeHealth = [];
        foreach ($document->contributions(ConfigSchema::EXCLUDE_HEALTH) as $contribution) {
            if (!\is_array($contribution) || !array_is_list($contribution)) {
                throw ConfigurationRefusal::atResolvedKey(
                    RefusedPosition::open(['exclude_health'], 'exclude_health'),
                    ComputedMetricShapeRefusalWording::excludeHealthNotAList(),
                );
            }

            foreach ($contribution as $dimension) {
                if (!\is_string($dimension)) {
                    throw ConfigurationRefusal::atResolvedKey(
                        RefusedPosition::open(['exclude_health'], 'exclude_health'),
                        ComputedMetricShapeRefusalWording::excludeHealthEntryNotAString(),
                    );
                }

                if (!\in_array($dimension, $excludeHealth, true)) {
                    $excludeHealth[] = $dimension;
                }
            }
        }

        return $excludeHealth;
    }
}
