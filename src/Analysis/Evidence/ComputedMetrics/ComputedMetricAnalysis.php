<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Evidence\ComputedMetrics;

use Qualimetrix\Analysis\Configuration\Contract\ConfigurationDocument;
use Qualimetrix\Analysis\Evidence\ComputedMetrics\Configuration\ComputedMetricContributionReader;
use Qualimetrix\Analysis\Evidence\ComputedMetrics\Contract\Configuration\ComputedMetricConfiguratorInterface;
use Qualimetrix\Analysis\Evidence\ComputedMetrics\Contract\Definition\ComputedMetricDefinition;
use Qualimetrix\Analysis\Evidence\ComputedMetrics\Contract\Definition\ComputedMetricDefinitionCatalogInterface;
use Qualimetrix\Analysis\Evidence\ComputedMetrics\Contract\Definition\ResolvedComputedMetricDefinitions;

final class ComputedMetricAnalysis implements
    ComputedMetricConfiguratorInterface,
    ComputedMetricDefinitionCatalogInterface
{
    private ResolvedComputedMetricDefinitions $definitions;

    public function __construct(
        private readonly ComputedMetricsConfigResolver $configResolver,
        private readonly ComputedMetricContributionReader $contributionReader,
    ) {
        $this->definitions = new ResolvedComputedMetricDefinitions([]);
    }

    public function resolve(ConfigurationDocument $document): ResolvedComputedMetricDefinitions
    {
        $contributions = $this->contributionReader->read($document);

        return new ResolvedComputedMetricDefinitions(
            $this->configResolver->resolve($contributions['computedMetrics'], $contributions['excludeHealth']),
        );
    }

    public function replace(ResolvedComputedMetricDefinitions $definitions): void
    {
        $this->definitions = $definitions;
    }

    public function all(): array
    {
        return $this->definitions->all();
    }

    public function find(string $name): ?ComputedMetricDefinition
    {
        return $this->definitions->find($name);
    }

}
