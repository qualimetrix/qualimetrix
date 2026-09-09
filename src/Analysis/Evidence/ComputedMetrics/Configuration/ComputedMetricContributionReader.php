<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Evidence\ComputedMetrics\Configuration;

use Qualimetrix\Analysis\Configuration\Contract\ConfigurationDocument;
use Qualimetrix\Analysis\Configuration\Contract\Refusal\ConfigurationOrigin;
use Qualimetrix\Analysis\Configuration\Contract\Refusal\ConfigurationRefusal;
use Qualimetrix\Analysis\Configuration\Contract\Refusal\ConfigurationSource;
use Qualimetrix\Analysis\Configuration\Contract\Refusal\RefusedPosition;

final class ComputedMetricContributionReader
{
    /**
     * @throws ConfigurationRefusal
     *
     * @return array{computedMetrics: array<string, mixed>, excludeHealth: list<string>}
     */
    public function read(ConfigurationDocument $document): array
    {
        return [
            'computedMetrics' => $this->readComputedMetrics($document),
            'excludeHealth' => $this->readExcludedHealthDimensions($document),
        ];
    }

    /**
     * @throws ConfigurationRefusal
     *
     * @return array<string, mixed>
     */
    private function readComputedMetrics(ConfigurationDocument $document): array
    {
        $computedMetrics = [];
        foreach ($document->contributions('computedMetrics') as $contribution) {
            if (!\is_array($contribution) || ($contribution !== [] && array_is_list($contribution))) {
                throw ConfigurationRefusal::at(
                    ConfigurationOrigin::of(ConfigurationSource::Resolved),
                    RefusedPosition::open(['computed_metrics'], 'computed_metrics'),
                    ComputedMetricRefusalWording::computedMetricsSectionNotAMap(),
                );
            }

            $computedMetrics = $contribution;
        }

        return $computedMetrics;
    }

    /**
     * @throws ConfigurationRefusal
     *
     * @return list<string>
     */
    private function readExcludedHealthDimensions(ConfigurationDocument $document): array
    {
        $excludeHealth = [];
        foreach ($document->contributions('excludeHealth') as $contribution) {
            if (!\is_array($contribution) || !array_is_list($contribution)) {
                throw ConfigurationRefusal::at(
                    ConfigurationOrigin::of(ConfigurationSource::Resolved),
                    RefusedPosition::open(['exclude_health'], 'exclude_health'),
                    ComputedMetricRefusalWording::excludeHealthNotAList(),
                );
            }

            foreach ($contribution as $dimension) {
                if (!\is_string($dimension)) {
                    throw ConfigurationRefusal::at(
                        ConfigurationOrigin::of(ConfigurationSource::Resolved),
                        RefusedPosition::open(['exclude_health'], 'exclude_health'),
                        ComputedMetricRefusalWording::excludeHealthEntryNotAString(),
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
