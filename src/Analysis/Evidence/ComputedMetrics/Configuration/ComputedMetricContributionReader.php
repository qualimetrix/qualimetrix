<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Evidence\ComputedMetrics\Configuration;

use InvalidArgumentException;
use Qualimetrix\Analysis\Configuration\Contract\ConfigurationDocument;

final class ComputedMetricContributionReader
{
    /**
     * @return array{computedMetrics: array<string, mixed>, excludeHealth: list<string>}
     */
    public function read(ConfigurationDocument $document): array
    {
        return [
            'computedMetrics' => $this->readComputedMetrics($document),
            'excludeHealth' => $this->readExcludedHealthDimensions($document),
        ];
    }

    /** @return array<string, mixed> */
    private function readComputedMetrics(ConfigurationDocument $document): array
    {
        $computedMetrics = [];
        foreach ($document->contributions('computedMetrics') as $contribution) {
            if (!\is_array($contribution) || ($contribution !== [] && array_is_list($contribution))) {
                throw new InvalidArgumentException('computed_metrics must be an associative map.');
            }

            $computedMetrics = $contribution;
        }

        return $computedMetrics;
    }

    /** @return list<string> */
    private function readExcludedHealthDimensions(ConfigurationDocument $document): array
    {
        $excludeHealth = [];
        foreach ($document->contributions('excludeHealth') as $contribution) {
            if (!\is_array($contribution) || !array_is_list($contribution)) {
                throw new InvalidArgumentException('exclude_health must be a list.');
            }

            foreach ($contribution as $dimension) {
                if (!\is_string($dimension)) {
                    throw new InvalidArgumentException('exclude_health entries must be strings.');
                }

                if (!\in_array($dimension, $excludeHealth, true)) {
                    $excludeHealth[] = $dimension;
                }
            }
        }

        return $excludeHealth;
    }
}
